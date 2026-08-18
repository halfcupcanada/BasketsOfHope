<?php
declare(strict_types=1);

namespace BOH\Fifty\Reports;

use BOH\Fifty\Audit\Logger;
use BOH\Fifty\Install\Capabilities as Caps;

/** CSV exports, all sourced from the same tables the Ledger sums. */
final class Csv
{
    /** @return array<string,string> */
    public static function available(): array
    {
        return [
            'orders'          => 'Orders',
            'payments'        => 'Payments',
            'tickets'         => 'Issued tickets',
            'inventory'       => 'Ticket inventory',
            'sales_by_package'=> 'Sales by package',
            'refunds'         => 'Refunds',
            'voids'           => 'Voids',
            'disputes'        => 'Disputes',
            'reconciliation'  => 'Reconciliation',
            'audit'           => 'Audit log',
        ];
    }

    public static function boot(): void
    {
        add_action('admin_post_boh5050_export', [self::class, 'handle']);
    }

    public static function handle(): void
    {
        check_admin_referer('boh5050_export');
        if (!current_user_can(Caps::EXPORT_REPORTS)) {
            wp_die('You do not have permission to export reports.', 403);
        }

        global $wpdb;
        $report = sanitize_key((string) ($_GET['report'] ?? ''));
        $raffle = (int) ($_GET['raffle_id'] ?? 0);
        if (!isset(self::available()[$report])) {
            wp_die('Unknown report.', 400);
        }

        $p = $wpdb->prefix . 'boh5050_';
        $sql = match ($report) {
            'orders'   => "SELECT * FROM {$p}orders WHERE raffle_id = %d ORDER BY id",
            'payments' => "SELECT * FROM {$p}payments WHERE raffle_id = %d ORDER BY id",
            'tickets'  => "SELECT * FROM {$p}tickets WHERE raffle_id = %d ORDER BY id",
            'inventory'=> "SELECT status, COUNT(*) AS tickets FROM {$p}tickets WHERE raffle_id = %d GROUP BY status",
            'sales_by_package' => "SELECT k.id, k.label, k.ticket_count, k.price_cents,
                                     COUNT(o.id) AS orders_paid, COALESCE(SUM(o.amount_cents),0) AS gross_cents
                                   FROM {$p}packages k
                                   LEFT JOIN {$p}orders o ON o.package_id = k.id AND o.status = 'paid'
                                   WHERE k.raffle_id = %d GROUP BY k.id ORDER BY k.sort_order, k.id",
            'refunds'  => "SELECT * FROM {$p}payments WHERE raffle_id = %d AND refunded_cents > 0 ORDER BY id",
            'voids'    => "SELECT * FROM {$p}tickets WHERE raffle_id = %d AND status = 'void' ORDER BY id",
            'disputes' => "SELECT * FROM {$p}payments WHERE raffle_id = %d AND status LIKE 'dispute%%' ORDER BY id",
            'reconciliation' => "SELECT * FROM {$p}reconciliations WHERE raffle_id = %d ORDER BY period_date",
            'audit'    => "SELECT id, created_at, actor_login, action, object_type, object_id, reason, correlation_id
                           FROM {$p}audit_events WHERE raffle_id = %d ORDER BY id",
        };

        $rows = $wpdb->get_results($wpdb->prepare($sql, $raffle), ARRAY_A) ?: [];

        Logger::log('report_exported', [
            'raffle_id' => $raffle, 'new' => ['report' => $report, 'rows' => count($rows)],
        ]);

        $file = sprintf('boh-5050-%s-%s.csv', $report, gmdate('Ymd-His'));
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');

        $out = fopen('php://output', 'w');
        if ($rows) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                // IPs are stored packed; render them readable in the export.
                if (isset($row['ip_address']) && $row['ip_address'] !== null && $row['ip_address'] !== '') {
                    $row['ip_address'] = @inet_ntop($row['ip_address']) ?: '';
                }
                fputcsv($out, $row);
            }
        } else {
            fputcsv($out, ['no rows']);
        }
        fclose($out);
        exit;
    }
}
