<?php
declare(strict_types=1);

namespace BOH\Fifty\Domain;

/**
 * Sequential in-house issuance. Test mode only.
 *
 * isLicensed() returns false and CheckoutService refuses to use it outside
 * test mode, so this cannot become the live path by accident.
 */
final class InternalIssuer implements TicketIssuer
{
    public function name(): string { return 'internal'; }

    public function isLicensed(): bool { return false; }

    public function issue(int $raffleId, int $orderId, int $quantity): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'boh5050_tickets';

        // Idempotency: if this order already has tickets, return those.
        $existing = $wpdb->get_col($wpdb->prepare(
            "SELECT ticket_number FROM {$t} WHERE order_id = %d ORDER BY id", $orderId
        ));
        if ($existing) {
            return $existing;
        }

        $issued = [];
        $now    = current_time('mysql', true);

        for ($i = 0; $i < $quantity; $i++) {
            // Derive from the table's own max so numbering survives restarts.
            $next = 1 + (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(MAX(CAST(ticket_number AS UNSIGNED)), 0)
                 FROM {$t} WHERE raffle_id = %d",
                $raffleId
            ));
            $number = str_pad((string) $next, 6, '0', STR_PAD_LEFT);

            // The unique index on (raffle_id, ticket_number) is the real guard
            // against a race; a failed insert simply retries with a new max.
            $ok = $wpdb->insert($t, [
                'raffle_id'     => $raffleId,
                'order_id'      => $orderId,
                'ticket_number' => $number,
                'status'        => 'issued',
                'issuer'        => $this->name(),
                'is_test'       => 1,
                'issued_at'     => $now,
            ]);
            if ($ok) {
                $issued[] = $number;
            } else {
                $i--; // collided; try again
            }
        }
        return $issued;
    }
}
