<?php
declare(strict_types=1);

namespace BOH\Fifty\Audit;

/**
 * Append-only audit writer. There is intentionally no update() or delete():
 * historical financial records must not be alterable through WordPress.
 */
final class Logger
{
    public static function log(
        string $action,
        array $args = []
    ): int {
        global $wpdb;

        $user = wp_get_current_user();
        $row  = [
            'raffle_id'      => (int) ($args['raffle_id'] ?? 0),
            'actor_user_id'  => (int) ($user->ID ?? 0),
            'actor_login'    => (string) ($user->user_login ?? 'system'),
            'action'         => $action,
            'object_type'    => (string) ($args['object_type'] ?? ''),
            'object_id'      => (int) ($args['object_id'] ?? 0),
            'previous_value' => isset($args['previous']) ? wp_json_encode($args['previous']) : null,
            'new_value'      => isset($args['new']) ? wp_json_encode($args['new']) : null,
            'reason'         => isset($args['reason']) ? (string) $args['reason'] : null,
            'ip_address'     => self::packedIp(),
            'correlation_id' => (string) ($args['correlation_id'] ?? self::correlationId()),
            'created_at'     => current_time('mysql', true),
        ];

        $wpdb->insert($wpdb->prefix . 'boh5050_audit_events', $row);
        return (int) $wpdb->insert_id;
    }

    /**
     * Store the IP in packed binary so it fits VARBINARY(16) for both v4 and
     * v6, and is not casually greppable in a dump.
     */
    private static function packedIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($ip) || $ip === '') {
            return null;
        }
        $packed = @inet_pton($ip);
        return $packed === false ? null : $packed;
    }

    /** One id per request, so a checkout and its webhook can be joined later. */
    public static function correlationId(): string
    {
        static $id = null;
        if ($id === null) {
            $id = bin2hex(random_bytes(8));
        }
        return $id;
    }
}
