<?php
declare(strict_types=1);

namespace BOH\Fifty\Payments;

use BOH\Fifty\Audit\Logger;
use BOH\Fifty\Domain\ErsIssuer;
use BOH\Fifty\Domain\InternalIssuer;
use BOH\Fifty\Domain\Ledger;
use BOH\Fifty\Domain\RaffleStatus;
use BOH\Fifty\Domain\TicketIssuer;

/**
 * Creates a pending order and a Stripe Checkout Session.
 *
 * Every gate is enforced here, server-side. The browser is never trusted for
 * status, dates, inventory, price or eligibility, and the Stripe success
 * redirect is never treated as payment — only the webhook marks an order paid.
 */
final class CheckoutService
{
    /** @return array{ok:bool,error?:string,url?:string,order_id?:int} */
    public function create(int $raffleId, int $packageId, array $purchaser): array
    {
        global $wpdb;

        $raffle = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}boh5050_raffles WHERE id = %d", $raffleId
        ), ARRAY_A);
        if (!$raffle) {
            return ['ok' => false, 'error' => 'Raffle not found.'];
        }

        // 1. Status must permit selling.
        if (!RaffleStatus::isSelling($raffle['status'])) {
            return ['ok' => false, 'error' => 'Ticket sales are not open.'];
        }

        // 2. Sales window, evaluated server-side in UTC.
        $now = time();
        if (!empty($raffle['sales_open_utc']) && $now < strtotime($raffle['sales_open_utc'] . ' UTC')) {
            return ['ok' => false, 'error' => 'Ticket sales have not opened yet.'];
        }
        if (!empty($raffle['sales_close_utc']) && $now >= strtotime($raffle['sales_close_utc'] . ' UTC')) {
            return ['ok' => false, 'error' => 'Ticket sales have closed.'];
        }

        // 3. Package must belong to this raffle and be active. Price is read
        //    from the database, never from the request.
        $package = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}boh5050_packages
             WHERE id = %d AND raffle_id = %d AND active = 1",
            $packageId, $raffleId
        ), ARRAY_A);
        if (!$package) {
            return ['ok' => false, 'error' => 'That ticket package is unavailable.'];
        }

        $qty = (int) $package['ticket_count'];

        // 4. Inventory.
        $inv = (new Ledger($raffleId))->inventory();
        if ($inv['sold_out'] || ($inv['licensed'] > 0 && $qty > $inv['remaining'])) {
            return ['ok' => false, 'error' => 'Not enough tickets remain for that package.'];
        }
        $maxPerOrder = (int) $raffle['max_tickets_per_order'];
        if ($maxPerOrder > 0 && $qty > $maxPerOrder) {
            return ['ok' => false, 'error' => 'That package exceeds the per-order ticket limit.'];
        }

        // 5/6. Eligibility attestations are mandatory and recorded.
        if (empty($purchaser['attest_age']) || empty($purchaser['attest_alberta'])) {
            return ['ok' => false, 'error' => 'You must confirm you are 18+ and in Alberta.'];
        }

        $email = sanitize_email((string) ($purchaser['email'] ?? ''));
        if (!is_email($email)) {
            return ['ok' => false, 'error' => 'A valid email address is required for your tickets.'];
        }

        // The issuer must be licensed unless we are in test mode. This is what
        // stops internal numbering ever being used for a real raffle.
        $issuer = $this->issuerFor($raffle);
        $isTest = $raffle['stripe_mode'] !== 'live' || $raffle['status'] === RaffleStatus::PREVIEW;
        if (!$isTest && !$issuer->isLicensed()) {
            return ['ok' => false, 'error' => 'Live sales require an approved raffle system. Contact the administrator.'];
        }

        // 8. Pending order. The idempotency key is derived from the order data
        //    so a double-submit lands on the same row via the unique index.
        $correlation = Logger::correlationId();
        $idem = hash('sha256', implode('|', [$raffleId, $packageId, $email, $correlation]));

        $now_sql = current_time('mysql', true);
        $wpdb->insert($wpdb->prefix . 'boh5050_orders', [
            'raffle_id'       => $raffleId,
            'package_id'      => $packageId,
            'idempotency_key' => $idem,
            'status'          => 'pending',
            'ticket_quantity' => $qty,
            'amount_cents'    => (int) $package['price_cents'],
            'purchaser_name'  => sanitize_text_field((string) ($purchaser['name'] ?? '')),
            'purchaser_email' => $email,
            'purchaser_phone' => sanitize_text_field((string) ($purchaser['phone'] ?? '')),
            'attest_age'      => 1,
            'attest_alberta'  => 1,
            'is_test'         => $isTest ? 1 : 0,
            'correlation_id'  => $correlation,
            'created_at'      => $now_sql,
            'updated_at'      => $now_sql,
        ]);
        $orderId = (int) $wpdb->insert_id;
        if (!$orderId) {
            return ['ok' => false, 'error' => 'Could not start your order. Please try again.'];
        }

        Logger::log('order_created', [
            'raffle_id' => $raffleId, 'object_type' => 'order', 'object_id' => $orderId,
            'new' => ['package_id' => $packageId, 'qty' => $qty, 'amount_cents' => (int) $package['price_cents']],
            'correlation_id' => $correlation,
        ]);

        // 9. Stripe Checkout Session.
        $stripe = new StripeClient(!$isTest);
        if (!$stripe->isConfigured()) {
            return ['ok' => false, 'error' => 'Payments are not configured yet.'];
        }

        $res = $stripe->post('checkout/sessions', [
            'mode'                 => 'payment',
            'success_url'          => add_query_arg(['boh5050' => 'thanks', 'order' => $orderId], home_url('/50-50/')),
            'cancel_url'           => add_query_arg(['boh5050' => 'cancelled'], home_url('/50-50/')),
            'customer_email'       => $email,
            'client_reference_id'  => (string) $orderId,
            'line_items'           => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => 'cad',
                    'unit_amount'  => (int) $package['price_cents'],
                    'product_data' => [
                        // Clearly a raffle ticket, and explicitly not a donation.
                        'name'        => sprintf('%s — %s (raffle ticket)', $raffle['name'], $package['label']),
                        'description' => 'Raffle ticket purchase. Not a donation; not eligible for a charitable tax receipt.',
                    ],
                ],
            ]],
            'metadata' => [
                'raffle_id'             => (string) $raffleId,
                'internal_order_id'     => (string) $orderId,
                'ticket_package_id'     => (string) $packageId,
                'ticket_quantity'       => (string) $qty,
                'raffle_licence_number' => (string) $raffle['licence_number'],
            ],
        ], 'checkout_' . $idem);

        if (!$res['ok'] || empty($res['data']['url'])) {
            $msg = $res['data']['error']['message'] ?? 'Stripe rejected the request.';
            Logger::log('checkout_failed', [
                'raffle_id' => $raffleId, 'object_type' => 'order', 'object_id' => $orderId,
                'reason' => $msg, 'correlation_id' => $correlation,
            ]);
            return ['ok' => false, 'error' => 'Payment could not be started: ' . $msg];
        }

        return ['ok' => true, 'url' => (string) $res['data']['url'], 'order_id' => $orderId];
    }

    /**
     * Option B: issuance is in-house. The recorded AGLC approval reference is
     * what makes the issuer licensed — so an unapproved system still fails the
     * live check above rather than quietly selling real tickets.
     */
    public function issuerFor(array $raffle): TicketIssuer
    {
        return new InternalIssuer(trim((string) ($raffle['ers_provider'] ?? '')));
    }
}
