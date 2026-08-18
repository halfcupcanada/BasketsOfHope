<?php
declare(strict_types=1);

namespace BOH\Fifty\Payments;

/**
 * Thin Stripe REST client owned by this plugin.
 *
 * Deliberately does NOT use the Stripe SDK vendored inside GiveWP
 * (plugins/give/vendor/stripe/stripe-php): that copy disappears or changes
 * version whenever GiveWP updates, which would break raffle payments on a
 * plugin update we did not make.
 *
 * Secrets come from wp-config.php constants only — never from options, never
 * echoed to HTML or JS.
 */
final class StripeClient
{
    private const API = 'https://api.stripe.com/v1/';

    public function __construct(private bool $liveMode = false) {}

    /** Publishable key is safe to expose to the browser. */
    public function publishableKey(): string
    {
        $c = $this->liveMode ? 'BOH_STRIPE_LIVE_PK' : 'BOH_STRIPE_TEST_PK';
        return defined($c) ? (string) constant($c) : '';
    }

    public function isConfigured(): bool
    {
        return $this->secretKey() !== '';
    }

    private function secretKey(): string
    {
        $c = $this->liveMode ? 'BOH_STRIPE_LIVE_SK' : 'BOH_STRIPE_TEST_SK';
        return defined($c) ? (string) constant($c) : '';
    }

    /**
     * POST to Stripe with an idempotency key.
     *
     * @param array<string,mixed> $body
     * @return array{ok:bool,status:int,data:array}
     */
    public function post(string $path, array $body, string $idempotencyKey): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'data' => ['error' => ['message' => 'Stripe key not configured']]];
        }

        $res = wp_remote_post(self::API . $path, [
            'timeout' => 30,
            'headers' => [
                'Authorization'   => 'Bearer ' . $this->secretKey(),
                'Content-Type'    => 'application/x-www-form-urlencoded',
                'Idempotency-Key' => $idempotencyKey,
                'Stripe-Version'  => '2024-06-20',
            ],
            'body' => $this->encode($body),
        ]);

        if (is_wp_error($res)) {
            return ['ok' => false, 'status' => 0, 'data' => ['error' => ['message' => $res->get_error_message()]]];
        }

        $status = (int) wp_remote_retrieve_response_code($res);
        $data   = json_decode((string) wp_remote_retrieve_body($res), true) ?: [];
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => $data];
    }

    /**
     * Verify a webhook signature (Stripe scheme v1) in constant time, and
     * reject anything older than the tolerance to blunt replay attempts.
     */
    public function verifySignature(string $payload, string $sigHeader, int $tolerance = 300): bool
    {
        $secret = defined('BOH_5050_WEBHOOK_SECRET')
            ? (string) BOH_5050_WEBHOOK_SECRET
            : (defined('BOH_STRIPE_WEBHOOK_SECRET') ? (string) BOH_STRIPE_WEBHOOK_SECRET : '');
        if ($secret === '' || $sigHeader === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') { $timestamp = $v; }
            if ($k === 'v1') { $signatures[] = $v; }
        }
        if ($timestamp === null || !$signatures) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /** Stripe expects PHP-style bracket notation for nested params. */
    private function encode(array $body, string $prefix = ''): string
    {
        $pairs = [];
        foreach ($body as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '[' . $k . ']';
            if (is_array($v)) {
                $pairs[] = $this->encode($v, $key);
            } else {
                $pairs[] = rawurlencode($key) . '=' . rawurlencode(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
            }
        }
        return implode('&', array_filter($pairs));
    }
}
