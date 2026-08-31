<?php
declare(strict_types=1);

namespace BOH\Fifty\Domain;

/**
 * In-house sequential ticket issuance (Option B).
 *
 * This is the production issuance path, so it has to be safe under
 * concurrency: two webhooks arriving at once must never mint the same ticket
 * number, and a replayed webhook must never mint a second block.
 *
 * Numbers are allocated as one contiguous block per order in a single INSERT,
 * guarded by the unique index on (raffle_id, ticket_number). If a concurrent
 * order takes the range first the INSERT fails as a whole and we retry from a
 * fresh high-water mark — so a partial block can never be left behind.
 *
 * isLicensed() reflects configuration, not code: it is true only once an AGLC
 * approval reference has been recorded for this system.
 */
final class InternalIssuer implements TicketIssuer
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private string $approvalReference = '') {}

    public function name(): string { return 'internal'; }

    /**
     * In-house issuance is only permitted for real sales once the operator has
     * recorded the regulator's approval of this system.
     */
    public function isLicensed(): bool
    {
        $ref = trim($this->approvalReference);
        if (strlen($ref) < 4) {
            return false;
        }
        // A single repeated character - "a", "1111" - is somebody getting past
        // a required field, not a regulator's approval. This is the last thing
        // standing between a placeholder and real tickets sold under a licence
        // number that does not exist, so it does not accept one.
        return count(array_unique(str_split(preg_replace('/\s+/', '', $ref)))) > 1;
    }

    public function issue(int $raffleId, int $orderId, int $quantity): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'boh5050_tickets';

        if ($quantity < 1) {
            return [];
        }

        // Idempotency: a replayed webhook returns the block already issued.
        $existing = $wpdb->get_col($wpdb->prepare(
            "SELECT ticket_number FROM {$t} WHERE order_id = %d ORDER BY id", $orderId
        ));
        if ($existing) {
            return $existing;
        }

        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT is_test FROM {$wpdb->prefix}boh5050_orders WHERE id = %d", $orderId
        ), ARRAY_A);
        $isTest = (int) ($order['is_test'] ?? 1);
        $now    = current_time('mysql', true);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            // High-water mark for this raffle. CAST is safe because we only
            // ever write zero-padded numeric strings.
            $start = 1 + (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(MAX(CAST(ticket_number AS UNSIGNED)), 0)
                 FROM {$t} WHERE raffle_id = %d",
                $raffleId
            ));

            $values = [];
            $params = [];
            $numbers = [];
            for ($i = 0; $i < $quantity; $i++) {
                $number    = str_pad((string) ($start + $i), 6, '0', STR_PAD_LEFT);
                $numbers[] = $number;
                $values[]  = '(%d, %d, %s, %s, %s, %d, %s)';
                array_push($params, $raffleId, $orderId, $number, 'issued', $this->name(), $isTest, $now);
            }

            // One statement: either the whole block lands or none of it does.
            $sql = "INSERT INTO {$t}
                    (raffle_id, order_id, ticket_number, status, issuer, is_test, issued_at)
                    VALUES " . implode(',', $values);

            // Suppress the duplicate-key warning; a collision is expected under
            // concurrency and is handled by retrying, not by reporting.
            $prior = $wpdb->suppress_errors(true);
            $ok = $wpdb->query($wpdb->prepare($sql, $params));
            $wpdb->suppress_errors($prior);

            if ($ok !== false) {
                return $numbers;
            }
        }

        throw new \RuntimeException(
            "Could not allocate {$quantity} ticket numbers for order {$orderId} after "
            . self::MAX_ATTEMPTS . ' attempts.'
        );
    }
}
