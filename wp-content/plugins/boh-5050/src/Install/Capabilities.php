<?php
declare(strict_types=1);

namespace BOH\Fifty\Install;

/**
 * Granular capabilities plus four operational roles.
 *
 * Nothing checks for `manage_options` alone: raffle money and purchaser data
 * should not be reachable simply because someone is a site administrator.
 * Administrators do get every capability, but through an explicit grant that is
 * auditable and revocable.
 */
final class Capabilities
{
    public const VIEW_DASHBOARD = 'view_5050_dashboard';
    public const MANAGE_SETTINGS = 'manage_5050_settings';
    public const MANAGE_SALES    = 'manage_5050_sales';
    public const VIEW_ORDERS     = 'view_5050_orders';
    public const MANAGE_REFUNDS  = 'manage_5050_refunds';
    public const RECONCILE       = 'reconcile_5050_finances';
    public const MANAGE_DRAW     = 'manage_5050_draw';
    public const PUBLISH_WINNER  = 'publish_5050_winner';
    public const EXPORT_REPORTS  = 'export_5050_reports';
    public const VIEW_AUDIT_LOG  = 'view_5050_audit_log';

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::VIEW_DASHBOARD, self::MANAGE_SETTINGS, self::MANAGE_SALES,
            self::VIEW_ORDERS, self::MANAGE_REFUNDS, self::RECONCILE,
            self::MANAGE_DRAW, self::PUBLISH_WINNER, self::EXPORT_REPORTS,
            self::VIEW_AUDIT_LOG,
        ];
    }

    /** @return array<string,array{name:string,caps:string[]}> */
    public static function roles(): array
    {
        return [
            'boh_raffle_admin' => [
                'name' => 'Raffle Administrator',
                'caps' => self::all(),
            ],
            'boh_finance_reconciler' => [
                'name' => 'Finance Reconciler',
                'caps' => [
                    self::VIEW_DASHBOARD, self::VIEW_ORDERS, self::RECONCILE,
                    self::EXPORT_REPORTS, self::VIEW_AUDIT_LOG,
                ],
            ],
            'boh_customer_support' => [
                // Can see an order to answer "where are my tickets", and can
                // request a refund, but cannot change configuration or draw.
                'name' => 'Raffle Customer Support',
                'caps' => [self::VIEW_DASHBOARD, self::VIEW_ORDERS, self::MANAGE_REFUNDS],
            ],
            'boh_readonly_auditor' => [
                'name' => 'Raffle Auditor (read-only)',
                'caps' => [
                    self::VIEW_DASHBOARD, self::VIEW_ORDERS,
                    self::VIEW_AUDIT_LOG, self::EXPORT_REPORTS,
                ],
            ],
        ];
    }

    /** Actions that require two different people to approve. */
    public const DUAL_APPROVAL = [
        'lock_ticket_population',
        'perform_draw',
        'change_winning_result',
        'financial_correction',
        'confirm_prize_payment',
        'change_licensed_config_after_sales',
    ];

    public static function install(): void
    {
        foreach (self::roles() as $slug => $def) {
            remove_role($slug); // idempotent: rebuild so cap changes apply
            add_role($slug, $def['name'], array_fill_keys($def['caps'], true));
        }
        if ($admin = get_role('administrator')) {
            foreach (self::all() as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    /** True when $userId has already approved this exact action+payload. */
    public static function hasApproved(string $action, string $payloadHash, int $userId): bool
    {
        global $wpdb;
        $t = $wpdb->prefix . 'boh5050_approvals';
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$t} WHERE action = %s AND payload_hash = %s AND approver_user_id = %d",
            $action, $payloadHash, $userId
        ));
    }

    /** Count of distinct approvers for an action+payload. */
    public static function approvalCount(string $action, string $payloadHash): int
    {
        global $wpdb;
        $t = $wpdb->prefix . 'boh5050_approvals';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT approver_user_id) FROM {$t}
             WHERE action = %s AND payload_hash = %s",
            $action, $payloadHash
        ));
    }
}
