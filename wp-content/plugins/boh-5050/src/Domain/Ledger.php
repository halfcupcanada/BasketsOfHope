<?php
declare(strict_types=1);

namespace BOH\Fifty\Domain;

/**
 * The single source of truth for raffle money.
 *
 * Every surface — public hero, admin dashboard, emails, CSV exports,
 * reconciliation — reads totals from here. Nothing recomputes them locally,
 * which is what keeps the public jackpot and the finance report from ever
 * disagreeing.
 *
 * Only payments in a settled state count. Pending, failed and disputed
 * payments are excluded by the SQL, not by a later filter, so a new caller
 * cannot accidentally include them.
 */
final class Ledger
{
    /** Payment rows that represent money actually received. */
    private const SETTLED = ['succeeded'];

    /** Rows that reduce gross once confirmed by Stripe. */
    private const REDUCING = ['refunded', 'partially_refunded', 'voided', 'dispute_lost'];

    public function __construct(private int $raffleId) {}

    /**
     * @return array{
     *   gross_cents:int, winner_cents:int, charity_gross_cents:int,
     *   expenses_cents:int, net_proceeds_cents:int,
     *   tickets_issued:int, orders_paid:int, updated_at:string
     * }
     */
    public function totals(): array
    {
        global $wpdb;
        $p = $wpdb->prefix . 'boh5050_payments';
        $t = $wpdb->prefix . 'boh5050_tickets';

        $settled = $this->inList(self::SETTLED);
        $reducing = $this->inList(self::REDUCING);

        // Gross = settled captured amount, less confirmed refunds/voids. Both
        // sides come from the same table so they cannot drift apart.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN status IN ($settled) THEN amount_cents ELSE 0 END), 0) AS captured,
                    COALESCE(SUM(refunded_cents), 0) AS refunded,
                    COALESCE(SUM(CASE WHEN status IN ($reducing) AND refunded_cents = 0
                                      THEN amount_cents ELSE 0 END), 0) AS voided,
                    COUNT(DISTINCT CASE WHEN status IN ($settled) THEN order_id END) AS orders_paid
                 FROM {$p} WHERE raffle_id = %d",
                $this->raffleId
            ),
            ARRAY_A
        ) ?: ['captured' => 0, 'refunded' => 0, 'voided' => 0, 'orders_paid' => 0];

        $gross = max(0, (int) $row['captured'] - (int) $row['refunded'] - (int) $row['voided']);

        [$winner, $charityGross] = Money::splitFiftyFifty($gross);

        $expenses = $this->approvedExpensesCents();

        $tickets = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$t} WHERE raffle_id = %d AND status = 'issued'",
                $this->raffleId
            )
        );

        return [
            'gross_cents'         => $gross,
            'winner_cents'        => $winner,
            'charity_gross_cents' => $charityGross,
            'expenses_cents'      => $expenses,
            // Net can legitimately go negative if expenses exceed the share;
            // surfacing that is more useful than clamping it to zero.
            'net_proceeds_cents'  => $charityGross - $expenses,
            'tickets_issued'      => $tickets,
            'orders_paid'         => (int) $row['orders_paid'],
            'updated_at'          => gmdate('c'),
        ];
    }

    /** Tickets sold vs licensed inventory. */
    public function inventory(): array
    {
        global $wpdb;
        $r = $wpdb->prefix . 'boh5050_raffles';
        $licensed = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT inventory_total FROM {$r} WHERE id = %d", $this->raffleId)
        );
        $issued = $this->totals()['tickets_issued'];
        return [
            'licensed'  => $licensed,
            'issued'    => $issued,
            'remaining' => max(0, $licensed - $issued),
            'sold_out'  => $licensed > 0 && $issued >= $licensed,
        ];
    }

    private function approvedExpensesCents(): int
    {
        global $wpdb;
        $e = $wpdb->prefix . 'boh5050_reconciliations';
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(expense_cents), 0) FROM {$e}
                 WHERE raffle_id = %d AND expense_approved = 1",
                $this->raffleId
            )
        );
    }

    /** Build a safely-quoted IN list from a constant array. */
    private function inList(array $values): string
    {
        global $wpdb;
        return implode(',', array_map(static fn($v) => $wpdb->prepare('%s', $v), $values));
    }
}
