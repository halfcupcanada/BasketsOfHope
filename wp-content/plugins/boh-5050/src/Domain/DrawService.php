<?php
declare(strict_types=1);

namespace BOH\Fifty\Domain;

use BOH\Fifty\Audit\Logger;
use BOH\Fifty\Install\Capabilities as Caps;

/**
 * Draw execution for in-house issuance (Option B).
 *
 * The order is deliberate and enforced: sales must be closed, then the
 * eligible population is locked and fingerprinted, and only then can a winner
 * be drawn. Locking first is what makes the result defensible — the hash
 * proves the pool that was drawn from is the pool that existed at lock time,
 * so nobody can add or void a ticket afterwards without it being detectable.
 *
 * Both the lock and the draw need two different approvers.
 */
final class DrawService
{
    public function __construct(private int $raffleId) {}

    /** Tickets that may win: issued, not voided, not from refunded orders. */
    public function eligibleQuery(): string
    {
        global $wpdb;
        return $wpdb->prepare(
            "SELECT t.id, t.ticket_number
               FROM {$wpdb->prefix}boh5050_tickets t
               JOIN {$wpdb->prefix}boh5050_orders o ON o.id = t.order_id
              WHERE t.raffle_id = %d
                AND t.status = 'issued'
                AND o.status = 'paid'
              ORDER BY t.id",
            $this->raffleId
        );
    }

    /** @return array{count:int,hash:string,numbers:string[]} */
    public function eligibleSnapshot(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($this->eligibleQuery(), ARRAY_A) ?: [];
        $numbers = array_map(static fn($r) => (string) $r['ticket_number'], $rows);
        return [
            'count'   => count($numbers),
            // Fingerprint of the exact pool, in order. Any later addition,
            // void or renumbering changes this.
            'hash'    => hash('sha256', implode(',', $numbers)),
            'numbers' => $numbers,
        ];
    }

    /**
     * Freeze the population. Requires sales closed and two approvals.
     *
     * @return array{ok:bool,error?:string,count?:int,hash?:string}
     */
    public function lockPopulation(string $reason): array
    {
        global $wpdb;

        $raffle = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}boh5050_raffles WHERE id = %d", $this->raffleId
        ), ARRAY_A);
        if (!$raffle) {
            return ['ok' => false, 'error' => 'Raffle not found.'];
        }
        if (!in_array($raffle['status'], [RaffleStatus::SALES_CLOSED, RaffleStatus::DRAW_PENDING], true)) {
            return ['ok' => false, 'error' => 'Sales must be closed before the population can be locked.'];
        }
        if ($this->unresolvedVariance()) {
            return ['ok' => false, 'error' => 'Reconciliation has unresolved variance; resolve it before locking.'];
        }

        $snap = $this->eligibleSnapshot();
        if ($snap['count'] < 1) {
            return ['ok' => false, 'error' => 'There are no eligible tickets to draw from.'];
        }

        $gate = $this->requireDualApproval('lock_ticket_population', $snap['hash'], $reason);
        if (!$gate['ok']) {
            return $gate;
        }

        $now = current_time('mysql', true);
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}boh5050_draws WHERE raffle_id = %d", $this->raffleId
        ), ARRAY_A);

        $data = [
            'raffle_id'             => $this->raffleId,
            'status'                => 'locked',
            'eligible_locked_at'    => $now,
            'eligible_ticket_count' => $snap['count'],
            'evidence'              => wp_json_encode(['population_hash' => $snap['hash']]),
        ];
        if ($existing) {
            $wpdb->update($wpdb->prefix . 'boh5050_draws', $data, ['id' => (int) $existing['id']]);
        } else {
            $wpdb->insert($wpdb->prefix . 'boh5050_draws', $data);
        }

        Logger::log('draw_population_locked', [
            'raffle_id' => $this->raffleId, 'object_type' => 'draw',
            'new' => ['count' => $snap['count'], 'population_hash' => $snap['hash']],
            'reason' => $reason,
        ]);

        return ['ok' => true, 'count' => $snap['count'], 'hash' => $snap['hash']];
    }

    /**
     * Draw a winner from the locked population.
     *
     * Re-derives the snapshot and compares it to the hash recorded at lock
     * time; if the pool changed in between, the draw is refused rather than
     * quietly drawing from a different set.
     *
     * @return array{ok:bool,error?:string,ticket?:string,prize_cents?:int}
     */
    public function drawWinner(string $reason, array $witnesses): array
    {
        global $wpdb;

        $draw = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}boh5050_draws WHERE raffle_id = %d", $this->raffleId
        ), ARRAY_A);
        if (!$draw || $draw['status'] !== 'locked') {
            return ['ok' => false, 'error' => 'Lock the eligible ticket population first.'];
        }
        if (count(array_filter($witnesses)) < 2) {
            return ['ok' => false, 'error' => 'At least two witnesses must be recorded.'];
        }

        $lockedHash = (string) (json_decode((string) $draw['evidence'], true)['population_hash'] ?? '');
        $snap = $this->eligibleSnapshot();
        if ($snap['hash'] !== $lockedHash) {
            Logger::log('draw_blocked_population_changed', [
                'raffle_id' => $this->raffleId, 'object_type' => 'draw', 'object_id' => (int) $draw['id'],
                'previous' => ['hash' => $lockedHash], 'new' => ['hash' => $snap['hash']],
            ]);
            return ['ok' => false, 'error' => 'The eligible ticket population changed after locking. Re-lock and investigate before drawing.'];
        }

        $gate = $this->requireDualApproval('perform_draw', $lockedHash, $reason);
        if (!$gate['ok']) {
            return $gate;
        }

        // Cryptographically secure selection over the frozen pool. random_int
        // draws from the OS CSPRNG, so the result is not predictable from a
        // seed an operator could have chosen.
        $index  = random_int(0, $snap['count'] - 1);
        $ticket = $snap['numbers'][$index];

        $prize = (new Ledger($this->raffleId))->totals()['winner_cents'];
        $now   = current_time('mysql', true);

        $wpdb->update($wpdb->prefix . 'boh5050_draws', [
            'status'                => 'drawn',
            'winning_ticket_number' => $ticket,
            'prize_cents'           => $prize,
            'drawn_at'              => $now,
            'witnesses'             => wp_json_encode(array_values(array_filter($witnesses))),
            'evidence'              => wp_json_encode([
                'population_hash'    => $lockedHash,
                'eligible_count'     => $snap['count'],
                'selection_method'   => 'PHP random_int (OS CSPRNG)',
                'selected_index'     => $index,
                'drawn_at_utc'       => $now,
            ]),
        ], ['id' => (int) $draw['id']]);

        Logger::log('draw_performed', [
            'raffle_id' => $this->raffleId, 'object_type' => 'draw', 'object_id' => (int) $draw['id'],
            'new' => [
                'winning_ticket' => $ticket,
                'eligible_count' => $snap['count'],
                'prize_cents'    => $prize,
                'witnesses'      => array_values(array_filter($witnesses)),
            ],
            'reason' => $reason,
        ]);

        return ['ok' => true, 'ticket' => $ticket, 'prize_cents' => $prize];
    }

    /** Publish the result. The winner's name appears only with consent. */
    public function publish(bool $nameConsent, string $winnerName, string $reason): array
    {
        global $wpdb;
        $draw = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}boh5050_draws WHERE raffle_id = %d", $this->raffleId
        ), ARRAY_A);
        if (!$draw || $draw['status'] !== 'drawn') {
            return ['ok' => false, 'error' => 'No completed draw to publish.'];
        }

        $wpdb->update($wpdb->prefix . 'boh5050_draws', [
            'status'                => 'published',
            'winner_name'           => $nameConsent ? sanitize_text_field($winnerName) : '',
            'winner_consent_public' => $nameConsent ? 1 : 0,
            'published_at'          => current_time('mysql', true),
        ], ['id' => (int) $draw['id']]);

        $wpdb->update($wpdb->prefix . 'boh5050_raffles',
            ['status' => RaffleStatus::WINNER_PUBLISHED, 'updated_at' => current_time('mysql', true)],
            ['id' => $this->raffleId]
        );

        Logger::log('winner_published', [
            'raffle_id' => $this->raffleId, 'object_type' => 'draw', 'object_id' => (int) $draw['id'],
            'new' => ['ticket' => $draw['winning_ticket_number'], 'name_published' => $nameConsent],
            'reason' => $reason,
        ]);
        return ['ok' => true];
    }

    /**
     * Two-person control. The current user's approval is recorded, then the
     * distinct approver count is checked — so one person clicking twice can
     * never satisfy it (the unique index makes the second vote a no-op).
     */
    private function requireDualApproval(string $action, string $payloadHash, string $reason): array
    {
        global $wpdb;
        $userId = get_current_user_id();
        if (!$userId) {
            return ['ok' => false, 'error' => 'You must be signed in to approve this.'];
        }

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}boh5050_approvals
             (raffle_id, action, payload_hash, approver_user_id, reason, created_at)
             VALUES (%d, %s, %s, %d, %s, %s)",
            $this->raffleId, $action, $payloadHash, $userId, $reason, current_time('mysql', true)
        ));

        $count = Caps::approvalCount($action, $payloadHash);
        if ($count < 2) {
            return ['ok' => false, 'error' => sprintf(
                'Recorded your approval. This action needs two different authorized people — %d of 2 so far.',
                $count
            )];
        }
        return ['ok' => true];
    }

    private function unresolvedVariance(): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}boh5050_reconciliations
             WHERE raffle_id = %d AND resolved = 0 AND variance_cents <> 0",
            $this->raffleId
        ));
    }
}
