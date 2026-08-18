<?php
declare(strict_types=1);

namespace BOH\Fifty\Payments;

use BOH\Fifty\Audit\Logger;
use BOH\Fifty\Domain\Money;

/**
 * Raffle webhook endpoint — its own route and its own signing secret, kept
 * separate from GiveWP's so raffle traffic can never disturb donations.
 *
 * Payment is confirmed here and nowhere else. The Stripe success redirect is
 * treated as navigation only.
 */
final class WebhookController
{
    public static function boot(): void
    {
        add_action('rest_api_init', static function (): void {
            register_rest_route('boh-5050/v1', '/stripe', [
                'methods'             => 'POST',
                // Authentication is the Stripe signature, verified below;
                // there is no cookie or nonce on a server-to-server call.
                'permission_callback' => '__return_true',
                'callback'            => [self::class, 'handle'],
            ]);
        });
    }

    public static function handle(\WP_REST_Request $request)
    {
        $payload = $request->get_body();
        $sig     = (string) $request->get_header('stripe_signature');

        // Live secret if the raffle is live; test otherwise. Verification uses
        // the shared webhook secret from wp-config, never an option.
        $client = new StripeClient(false);
        if (!$client->verifySignature($payload, $sig)) {
            Logger::log('webhook_rejected', ['reason' => 'bad signature or stale timestamp']);
            return new \WP_REST_Response(['error' => 'invalid signature'], 400);
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
            return new \WP_REST_Response(['error' => 'malformed event'], 400);
        }

        // Replay guard: the unique index on stripe_event_id means a duplicate
        // delivery cannot be processed twice even under concurrency.
        if (self::alreadyProcessed((string) $event['id'])) {
            return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
        }

        $obj = $event['data']['object'] ?? [];

        switch ($event['type']) {
            case 'checkout.session.completed':
            case 'payment_intent.succeeded':
                self::markPaid($event, $obj);
                break;
            case 'payment_intent.payment_failed':
                self::markFailed($event, $obj);
                break;
            case 'charge.refunded':
                self::applyRefund($event, $obj);
                break;
            case 'charge.dispute.created':
                self::flagDispute($event, $obj, 'dispute_open');
                break;
            case 'charge.dispute.closed':
                self::flagDispute($event, $obj, ($obj['status'] ?? '') === 'lost' ? 'dispute_lost' : 'dispute_won');
                break;
            default:
                // Recorded but not acted on, so the event id is still consumed.
                self::recordEvent($event, 0, 'ignored', 0);
        }

        return new \WP_REST_Response(['received' => true], 200);
    }

    private static function alreadyProcessed(string $eventId): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}boh5050_payments WHERE stripe_event_id = %s", $eventId
        ));
    }

    private static function orderIdFrom(array $obj): int
    {
        $meta = $obj['metadata'] ?? [];
        return (int) ($meta['internal_order_id'] ?? $obj['client_reference_id'] ?? 0);
    }

    /** Confirm payment, then issue tickets exactly once. */
    private static function markPaid(array $event, array $obj): void
    {
        global $wpdb;
        $orderId = self::orderIdFrom($obj);
        if (!$orderId) {
            self::recordEvent($event, 0, 'orphan', 0);
            return;
        }

        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}boh5050_orders WHERE id = %d", $orderId
        ), ARRAY_A);
        if (!$order) {
            self::recordEvent($event, 0, 'orphan', 0);
            return;
        }

        // Trust the amount Stripe reports, not the order row.
        $amount = (int) ($obj['amount_total'] ?? $obj['amount_received'] ?? $order['amount_cents']);

        self::recordEvent($event, (int) $order['raffle_id'], 'succeeded', $amount, $orderId, [
            'stripe_payment_intent' => (string) ($obj['payment_intent'] ?? $obj['id'] ?? ''),
            'settled_at'            => current_time('mysql', true),
        ]);

        $wpdb->update($wpdb->prefix . 'boh5050_orders',
            ['status' => 'paid', 'updated_at' => current_time('mysql', true)],
            ['id' => $orderId]
        );

        // 13. Tickets only now, after verified payment. The issuer is
        //     idempotent on order id, so a replay returns the same numbers.
        $raffle = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}boh5050_raffles WHERE id = %d", (int) $order['raffle_id']
        ), ARRAY_A) ?: [];

        try {
            $issuer  = (new CheckoutService())->issuerFor($raffle);
            $numbers = $issuer->issue((int) $order['raffle_id'], $orderId, (int) $order['ticket_quantity']);
            Logger::log('tickets_issued', [
                'raffle_id' => (int) $order['raffle_id'], 'object_type' => 'order', 'object_id' => $orderId,
                'new' => ['count' => count($numbers), 'issuer' => $issuer->name()],
                'correlation_id' => (string) $order['correlation_id'],
            ]);
            self::sendTickets($order, $numbers, $amount, $raffle);
        } catch (\Throwable $e) {
            // Payment stands; issuance is flagged for staff rather than lost.
            Logger::log('ticket_issue_failed', [
                'raffle_id' => (int) $order['raffle_id'], 'object_type' => 'order', 'object_id' => $orderId,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private static function markFailed(array $event, array $obj): void
    {
        global $wpdb;
        $orderId = self::orderIdFrom($obj);
        self::recordEvent($event, 0, 'failed', (int) ($obj['amount'] ?? 0), $orderId);
        if ($orderId) {
            $wpdb->update($wpdb->prefix . 'boh5050_orders',
                ['status' => 'failed', 'updated_at' => current_time('mysql', true)],
                ['id' => $orderId]
            );
        }
    }

    /** Refunds reduce the public total only once Stripe confirms them. */
    private static function applyRefund(array $event, array $obj): void
    {
        global $wpdb;
        $pi       = (string) ($obj['payment_intent'] ?? '');
        $refunded = (int) ($obj['amount_refunded'] ?? 0);

        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}boh5050_payments
             WHERE stripe_payment_intent = %s ORDER BY id LIMIT 1", $pi
        ), ARRAY_A);

        self::recordEvent($event, (int) ($payment['raffle_id'] ?? 0), 'refund_event', 0,
            (int) ($payment['order_id'] ?? 0));

        if (!$payment) {
            return;
        }

        $full = $refunded >= (int) $payment['amount_cents'];
        $wpdb->update($wpdb->prefix . 'boh5050_payments', [
            'refunded_cents' => $refunded,
            'status'         => $full ? 'refunded' : 'partially_refunded',
            'stripe_refund_id' => (string) ($obj['refunds']['data'][0]['id'] ?? ''),
            'updated_at'     => current_time('mysql', true),
        ], ['id' => (int) $payment['id']]);

        // A fully refunded order's tickets must not be able to win.
        if ($full) {
            $wpdb->update($wpdb->prefix . 'boh5050_tickets',
                ['status' => 'void', 'voided_at' => current_time('mysql', true)],
                ['order_id' => (int) $payment['order_id']]
            );
        }

        Logger::log('refund_applied', [
            'raffle_id' => (int) $payment['raffle_id'], 'object_type' => 'payment',
            'object_id' => (int) $payment['id'],
            'new' => ['refunded_cents' => $refunded, 'voided_tickets' => $full],
        ]);
    }

    private static function flagDispute(array $event, array $obj, string $status): void
    {
        global $wpdb;
        $charge = (string) ($obj['charge'] ?? '');
        $payment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}boh5050_payments WHERE stripe_charge_id = %s LIMIT 1", $charge
        ), ARRAY_A);

        self::recordEvent($event, (int) ($payment['raffle_id'] ?? 0), $status, 0,
            (int) ($payment['order_id'] ?? 0));

        if ($payment && $status === 'dispute_lost') {
            $wpdb->update($wpdb->prefix . 'boh5050_payments',
                ['status' => 'dispute_lost', 'updated_at' => current_time('mysql', true)],
                ['id' => (int) $payment['id']]
            );
            $wpdb->update($wpdb->prefix . 'boh5050_tickets',
                ['status' => 'void', 'voided_at' => current_time('mysql', true)],
                ['order_id' => (int) $payment['order_id']]
            );
        }
        Logger::log('dispute_' . $status, [
            'raffle_id' => (int) ($payment['raffle_id'] ?? 0),
            'object_type' => 'payment', 'object_id' => (int) ($payment['id'] ?? 0),
        ]);
    }

    /**
     * Insert the payment row keyed by Stripe event id. The unique index makes
     * this the idempotency boundary for the whole handler.
     */
    private static function recordEvent(
        array $event, int $raffleId, string $status, int $amount,
        int $orderId = 0, array $extra = []
    ): void {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert($wpdb->prefix . 'boh5050_payments', array_merge([
            'raffle_id'       => $raffleId,
            'order_id'        => $orderId,
            'status'          => $status,
            'amount_cents'    => $amount,
            'stripe_event_id' => (string) $event['id'],
            'is_test'         => empty($event['livemode']) ? 1 : 0,
            'created_at'      => $now,
            'updated_at'      => $now,
        ], $extra));
    }

    /** Ticket + receipt email. States plainly that this is not a tax receipt. */
    private static function sendTickets(array $order, array $numbers, int $amount, array $raffle): void
    {
        if (!$numbers) {
            return;
        }
        $subject = sprintf('Your %s tickets', $raffle['name'] ?? '50/50');
        $lines = [
            sprintf('Thank you, %s.', $order['purchaser_name'] ?: 'friend'),
            '',
            sprintf('Ticket numbers: %s', implode(', ', $numbers)),
            sprintf('Amount paid: %s CAD', Money::format($amount)),
            sprintf('Licence number: %s', $raffle['licence_number'] ?? ''),
            sprintf('Draw: %s %s', $raffle['draw_utc'] ?? 'TBC', $raffle['draw_location'] ?? ''),
            '',
            'This is a raffle ticket purchase, not a donation. It is not eligible',
            'for a charitable tax receipt.',
        ];
        wp_mail($order['purchaser_email'], $subject, implode("\n", $lines));
    }
}
