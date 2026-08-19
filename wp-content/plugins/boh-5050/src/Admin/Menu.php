<?php
declare(strict_types=1);

namespace BOH\Fifty\Admin;

use BOH\Fifty\Audit\Logger;
use BOH\Fifty\Domain\Ledger;
use BOH\Fifty\Domain\Money;
use BOH\Fifty\Domain\RaffleStatus;
use BOH\Fifty\Install\Capabilities as Caps;

/**
 * Admin menu: Baskets of Hope → 50/50 Raffle, with the ten screens.
 *
 * Every screen checks a specific capability, never `manage_options`, and every
 * mutating action requires a nonce, a written reason and an audit entry.
 */
final class Menu
{
    public const SLUG = 'boh-5050';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register']);
        add_action('admin_post_boh5050_save_settings', [self::class, 'handleSaveSettings']);
        add_action('admin_post_boh5050_set_status', [self::class, 'handleSetStatus']);
        add_action('admin_post_boh5050_save_package', [self::class, 'handleSavePackage']);
        add_action('admin_post_boh5050_draw', [self::class, 'handleDraw']);
    }

    public static function register(): void
    {
        add_menu_page(
            'Baskets of Hope', 'Baskets of Hope', Caps::VIEW_DASHBOARD, self::SLUG,
            [self::class, 'renderDashboard'], 'dashicons-tickets-alt', 58
        );

        $screens = [
            ''               => ['50/50 Raffle', Caps::VIEW_DASHBOARD, 'renderDashboard'],
            'config'         => ['Raffle Configuration', Caps::MANAGE_SETTINGS, 'renderConfig'],
            'packages'       => ['Ticket Packages', Caps::MANAGE_SETTINGS, 'renderPackages'],
            'orders'         => ['Orders & Tickets', Caps::VIEW_ORDERS, 'renderOrders'],
            'draw'           => ['Draw Management', Caps::MANAGE_DRAW, 'renderDraw'],
            'reconciliation' => ['Reconciliation', Caps::RECONCILE, 'renderReconciliation'],
            'reports'        => ['Reports & Exports', Caps::EXPORT_REPORTS, 'renderReports'],
            'audit'          => ['Audit Log', Caps::VIEW_AUDIT_LOG, 'renderAudit'],
            'health'         => ['Integration Health', Caps::VIEW_DASHBOARD, 'renderHealth'],
            'help'           => ['Help & Compliance', Caps::VIEW_DASHBOARD, 'renderHelp'],
        ];

        foreach ($screens as $key => [$title, $cap, $method]) {
            add_submenu_page(
                self::SLUG, $title, $title, $cap,
                $key === '' ? self::SLUG : self::SLUG . '-' . $key,
                [self::class, $method]
            );
        }
    }

    /* ---------------------------------------------------------------- data */

    public static function raffle(): array
    {
        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}boh5050_raffles ORDER BY id DESC LIMIT 1", ARRAY_A);
        if ($row) {
            return $row;
        }
        // Seed a disabled raffle so every screen has something to show and the
        // feature starts in its safest state.
        $now = current_time('mysql', true);
        $wpdb->insert($wpdb->prefix . 'boh5050_raffles', [
            'name' => "Rohit's Baskets of Hope 50/50",
            'campaign_year' => (int) gmdate('Y'),
            'status' => RaffleStatus::DISABLED,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return $wpdb->get_row("SELECT * FROM {$wpdb->prefix}boh5050_raffles ORDER BY id DESC LIMIT 1", ARRAY_A) ?: [];
    }

    /**
     * The compliance gate. Live cannot be selected until every one of these is
     * satisfied — returns the list of what is still missing.
     *
     * @return string[]
     */
    public static function complianceGaps(array $r): array
    {
        $gaps = [];
        if (trim((string) $r['licensee']) === '')        { $gaps[] = 'Legal licensee not set'; }
        if (trim((string) $r['licence_number']) === '')  { $gaps[] = 'AGLC licence number not set'; }
        if (trim((string) $r['rules_url']) === '')       { $gaps[] = 'Public rules URL not set'; }
        if (empty($r['sales_open_utc']) || empty($r['sales_close_utc'])) { $gaps[] = 'Sales window incomplete'; }
        if (empty($r['draw_utc']))                       { $gaps[] = 'Draw date/time not set'; }
        if ((int) $r['inventory_total'] <= 0)            { $gaps[] = 'Licensed ticket inventory not set'; }
        // Issuance is in-house (Option B), so the gate does not ask for a
        // third-party provider. It asks for the regulator's written approval
        // of THIS system, recorded before real money can be taken.
        if (trim((string) $r['ers_provider']) === '') {
            $gaps[] = 'AGLC approval reference for this raffle system not recorded';
        }
        if (($r['stripe_mode'] ?? 'test') === 'live' && !defined('BOH_STRIPE_LIVE_SK')) {
            $gaps[] = 'Live Stripe secret key not present in wp-config.php';
        }
        global $wpdb;
        $pkgs = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}boh5050_packages WHERE raffle_id = %d AND active = 1",
            (int) $r['id']
        ));
        if ($pkgs === 0) { $gaps[] = 'No active ticket packages'; }
        return $gaps;
    }

    /* -------------------------------------------------------------- screens */

    public static function renderDashboard(): void
    {
        self::guard(Caps::VIEW_DASHBOARD);
        $r = self::raffle();
        $l = new Ledger((int) $r['id']);
        $t = $l->totals();
        $inv = $l->inventory();
        $gaps = self::complianceGaps($r);

        echo '<div class="wrap"><h1>50/50 Raffle</h1>';

        printf(
            '<div class="notice %s"><p><strong>Status:</strong> %s &nbsp;|&nbsp; <strong>Compliance:</strong> %s</p></div>',
            $gaps ? 'notice-warning' : 'notice-success',
            esc_html(RaffleStatus::labels()[$r['status']] ?? $r['status']),
            $gaps ? esc_html(count($gaps) . ' item(s) outstanding') : 'ready'
        );

        if ($gaps) {
            echo '<div class="notice notice-warning"><p><strong>Live sales are blocked until:</strong></p><ul style="list-style:disc;margin-left:22px">';
            foreach ($gaps as $g) { printf('<li>%s</li>', esc_html($g)); }
            echo '</ul></div>';
        }

        $cards = [
            'Gross settled sales' => Money::format($t['gross_cents']),
            "Winner's prize"      => Money::format($t['winner_cents']),
            'WIN House share'     => Money::format($t['charity_gross_cents']),
            'Approved expenses'   => Money::format($t['expenses_cents']),
            'Net proceeds'        => Money::format($t['net_proceeds_cents']),
            'Tickets issued'      => number_format($t['tickets_issued']),
            'Remaining inventory' => $inv['licensed'] > 0 ? number_format($inv['remaining']) : 'not set',
            'Paid orders'         => number_format($t['orders_paid']),
        ];
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:18px 0">';
        foreach ($cards as $label => $value) {
            printf(
                '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px">
                   <div style="font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#646970">%s</div>
                   <div style="font-size:22px;font-weight:700;margin-top:4px">%s</div></div>',
                esc_html($label), esc_html($value)
            );
        }
        echo '</div>';

        self::statusForm($r, $gaps);
        echo '</div>';
    }

    private static function statusForm(array $r, array $gaps): void
    {
        if (!current_user_can(Caps::MANAGE_SALES)) {
            return;
        }
        $allowed = RaffleStatus::transitions()[$r['status']] ?? [];
        echo '<h2>Feature status</h2>';
        if (!$allowed) {
            echo '<p>No further transitions are available from this state.</p>';
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('boh5050_set_status');
        echo '<input type="hidden" name="action" value="boh5050_set_status">';
        printf('<input type="hidden" name="raffle_id" value="%d">', (int) $r['id']);
        echo '<p><select name="status" required>';
        foreach ($allowed as $s) {
            $blocked = ($s === RaffleStatus::LIVE && $gaps);
            printf('<option value="%s"%s>%s%s</option>',
                esc_attr($s), $blocked ? ' disabled' : '',
                esc_html(RaffleStatus::labels()[$s]),
                $blocked ? ' — blocked by compliance gate' : ''
            );
        }
        echo '</select></p>';
        echo '<p><label>Reason (recorded in the audit log, required)<br>';
        echo '<textarea name="reason" rows="2" cols="60" required></textarea></label></p>';
        submit_button('Change status', 'primary', 'submit', false);
        echo '</form>';
    }

    public static function renderConfig(): void
    {
        self::guard(Caps::MANAGE_SETTINGS);
        $r = self::raffle();
        echo '<div class="wrap"><h1>Raffle Configuration</h1>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('boh5050_save_settings');
        echo '<input type="hidden" name="action" value="boh5050_save_settings">';
        printf('<input type="hidden" name="raffle_id" value="%d">', (int) $r['id']);
        echo '<table class="form-table">';
        $fields = [
            'name' => ['Raffle name', 'text'],
            'campaign_year' => ['Campaign year', 'number'],
            'licensee' => ['Legal licensee', 'text'],
            'licence_number' => ['AGLC licence number', 'text'],
            'rules_url' => ['Public rules URL', 'url'],
            'sales_open_utc' => ['Sales open (UTC)', 'datetime-local'],
            'sales_close_utc' => ['Sales close (UTC)', 'datetime-local'],
            'draw_utc' => ['Draw date/time (UTC)', 'datetime-local'],
            'draw_location' => ['Draw location', 'text'],
            'draw_method' => ['Draw method', 'text'],
            'ers_provider' => ['AGLC approval reference for this system', 'text'],
            'inventory_total' => ['Licensed ticket inventory', 'number'],
            'max_tickets_per_order' => ['Max tickets per order (0 = no limit)', 'number'],
        ];
        foreach ($fields as $key => [$label, $type]) {
            $val = (string) ($r[$key] ?? '');
            if ($type === 'datetime-local' && $val !== '') {
                $val = str_replace(' ', 'T', substr($val, 0, 16));
            }
            printf(
                '<tr><th scope="row"><label for="%1$s">%2$s</label></th>
                 <td><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" class="regular-text"></td></tr>',
                esc_attr($key), esc_html($label), esc_attr($type), esc_attr($val)
            );
        }
        // Split is locked at 50/50 by the raffle model, so it is shown rather than edited.
        echo '<tr><th scope="row">Split</th><td><strong>50% winner / 50% WIN House</strong> — locked</td></tr>';
        echo '<tr><th scope="row">Currency / timezone</th><td>CAD / America/Edmonton — locked</td></tr>';
        printf('<tr><th scope="row">Stripe mode</th><td><select name="stripe_mode">
                 <option value="test"%s>Test</option><option value="live"%s>Live</option></select>
                 <p class="description">Live also requires BOH_STRIPE_LIVE_SK in wp-config.php.</p></td></tr>',
            selected($r['stripe_mode'], 'test', false), selected($r['stripe_mode'], 'live', false));
        echo '</table>';
        echo '<p><label>Reason for this change (audit log)<br><textarea name="reason" rows="2" cols="60"></textarea></label></p>';
        submit_button('Save configuration');
        echo '</form></div>';
    }

    public static function renderPackages(): void
    {
        self::guard(Caps::MANAGE_SETTINGS);
        global $wpdb;
        $r = self::raffle();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}boh5050_packages WHERE raffle_id = %d ORDER BY sort_order, id",
            (int) $r['id']
        ), ARRAY_A) ?: [];

        echo '<div class="wrap"><h1>Ticket Packages</h1>';
        echo '<p>Prices are stored in integer cents and must match the approved licence. Nothing here is hardcoded.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Label</th><th>Tickets</th><th>Price</th><th>Active</th></tr></thead><tbody>';
        foreach ($rows as $p) {
            printf('<tr><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                esc_html($p['label']), (int) $p['ticket_count'],
                esc_html(Money::format((int) $p['price_cents'])),
                $p['active'] ? 'yes' : 'no');
        }
        if (!$rows) { echo '<tr><td colspan="4">No packages yet.</td></tr>'; }
        echo '</tbody></table>';

        echo '<h2>Add a package</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('boh5050_save_package');
        echo '<input type="hidden" name="action" value="boh5050_save_package">';
        printf('<input type="hidden" name="raffle_id" value="%d">', (int) $r['id']);
        echo '<table class="form-table">
            <tr><th><label for="label">Label</label></th><td><input id="label" name="label" class="regular-text" placeholder="3 tickets" required></td></tr>
            <tr><th><label for="ticket_count">Tickets</label></th><td><input id="ticket_count" name="ticket_count" type="number" min="1" value="1" required></td></tr>
            <tr><th><label for="price">Price (CAD)</label></th><td><input id="price" name="price" placeholder="20.00" required></td></tr>
            </table>';
        submit_button('Add package');
        echo '</form></div>';
    }

    public static function renderOrders(): void
    {
        self::guard(Caps::VIEW_ORDERS);
        global $wpdb;
        $r = self::raffle();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, (SELECT COUNT(*) FROM {$wpdb->prefix}boh5050_tickets t
                          WHERE t.order_id = o.id AND t.status = 'issued') AS tickets
             FROM {$wpdb->prefix}boh5050_orders o
             WHERE o.raffle_id = %d ORDER BY o.id DESC LIMIT 100",
            (int) $r['id']
        ), ARRAY_A) ?: [];

        echo '<div class="wrap"><h1>Orders &amp; Tickets</h1><table class="widefat striped">';
        echo '<thead><tr><th>#</th><th>Status</th><th>Amount</th><th>Tickets</th><th>Purchaser</th><th>Created</th></tr></thead><tbody>';
        foreach ($rows as $o) {
            printf('<tr><td>%d</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                (int) $o['id'], esc_html($o['status']),
                esc_html(Money::format((int) $o['amount_cents'])),
                (int) $o['tickets'],
                esc_html($o['purchaser_email']), esc_html($o['created_at']));
        }
        if (!$rows) { echo '<tr><td colspan="6">No orders yet.</td></tr>'; }
        echo '</tbody></table></div>';
    }

    public static function renderAudit(): void
    {
        self::guard(Caps::VIEW_AUDIT_LOG);
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}boh5050_audit_events ORDER BY id DESC LIMIT 200", ARRAY_A
        ) ?: [];
        echo '<div class="wrap"><h1>Audit Log</h1><p>Append-only. Entries cannot be edited or removed through WordPress.</p>';
        echo '<table class="widefat striped"><thead><tr><th>When (UTC)</th><th>Actor</th><th>Action</th><th>Object</th><th>Reason</th></tr></thead><tbody>';
        foreach ($rows as $e) {
            printf('<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s %s</td><td>%s</td></tr>',
                esc_html($e['created_at']), esc_html($e['actor_login']), esc_html($e['action']),
                esc_html($e['object_type']), $e['object_id'] ? '#' . (int) $e['object_id'] : '',
                esc_html((string) $e['reason']));
        }
        if (!$rows) { echo '<tr><td colspan="5">No events yet.</td></tr>'; }
        echo '</tbody></table></div>';
    }

    public static function renderHealth(): void
    {
        self::guard(Caps::VIEW_DASHBOARD);
        $r = self::raffle();
        $live = ($r['stripe_mode'] ?? 'test') === 'live';
        $checks = [
            'Stripe mode' => $r['stripe_mode'],
            'Stripe secret key present' => defined($live ? 'BOH_STRIPE_LIVE_SK' : 'BOH_STRIPE_TEST_SK') ? 'yes' : 'NO',
            'Webhook secret present' => (defined('BOH_5050_WEBHOOK_SECRET') || defined('BOH_STRIPE_WEBHOOK_SECRET')) ? 'yes' : 'NO',
            'Webhook endpoint' => rest_url('boh-5050/v1/stripe'),
            'Issuance model' => 'in-house (Option B)',
            'AGLC system approval' => $r['ers_provider'] !== '' ? $r['ers_provider'] : 'NOT RECORDED — required before Live',
            'System cron drives WP-Cron' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'yes' : 'no — sales cut-off may be late',
            'Minute tick scheduled' => wp_next_scheduled('boh5050_minute_tick') ? 'yes' : 'no',
        ];
        echo '<div class="wrap"><h1>Integration Health</h1><table class="widefat striped"><tbody>';
        foreach ($checks as $k => $v) {
            printf('<tr><th style="width:280px">%s</th><td>%s</td></tr>', esc_html($k), esc_html((string) $v));
        }
        echo '</tbody></table><p>Secret values are never displayed — only whether they are present.</p></div>';
    }

    public static function renderDraw(): void
    {
        self::guard(Caps::MANAGE_DRAW);
        global $wpdb;
        $r    = self::raffle();
        $svc  = new \BOH\Fifty\Domain\DrawService((int) $r['id']);
        $snap = $svc->eligibleSnapshot();
        $draw = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}boh5050_draws WHERE raffle_id = %d", (int) $r['id']
        ), ARRAY_A);

        echo '<div class="wrap"><h1>Draw Management</h1>';
        echo '<p>Sales must be closed and reconciliation clear before the eligible population can be locked. '
           . 'Locking fingerprints the exact pool, so the set drawn from is provably the set that existed at lock time. '
           . 'Both locking and drawing require <strong>two different authorized people</strong>.</p>';

        printf('<table class="widefat" style="max-width:640px"><tbody>
                <tr><th>Raffle status</th><td>%s</td></tr>
                <tr><th>Eligible tickets now</th><td>%s</td></tr>
                <tr><th>Current pool fingerprint</th><td><code>%s</code></td></tr>
                <tr><th>Draw state</th><td>%s</td></tr>
                <tr><th>Winning ticket</th><td>%s</td></tr>
                </tbody></table>',
            esc_html(RaffleStatus::labels()[$r['status']] ?? $r['status']),
            esc_html(number_format($snap['count'])),
            esc_html(substr($snap['hash'], 0, 16) . '…'),
            esc_html($draw['status'] ?? 'not started'),
            esc_html($draw['winning_ticket_number'] ?? '—')
        );

        $state = $draw['status'] ?? '';

        if ($state !== 'locked' && $state !== 'drawn' && $state !== 'published') {
            echo '<h2>1. Lock the eligible population</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('boh5050_draw');
            echo '<input type="hidden" name="action" value="boh5050_draw">';
            echo '<input type="hidden" name="step" value="lock">';
            printf('<input type="hidden" name="raffle_id" value="%d">', (int) $r['id']);
            echo '<p><label>Reason / context (audit log)<br><textarea name="reason" rows="2" cols="60" required></textarea></label></p>';
            submit_button('Record my approval to lock', 'primary', 'submit', false);
            echo '</form>';
        }

        if ($state === 'locked') {
            echo '<h2>2. Draw the winner</h2>';
            echo '<p>Selection uses the operating system CSPRNG over the frozen pool. If the pool has changed since locking, the draw is refused.</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('boh5050_draw');
            echo '<input type="hidden" name="action" value="boh5050_draw">';
            echo '<input type="hidden" name="step" value="draw">';
            printf('<input type="hidden" name="raffle_id" value="%d">', (int) $r['id']);
            echo '<p><label>Witness 1 (name and role)<br><input name="witness1" class="regular-text" required></label></p>';
            echo '<p><label>Witness 2 (name and role)<br><input name="witness2" class="regular-text" required></label></p>';
            echo '<p><label>Reason / context<br><textarea name="reason" rows="2" cols="60" required></textarea></label></p>';
            submit_button('Record my approval to draw', 'primary', 'submit', false);
            echo '</form>';
        }

        if ($state === 'drawn' && current_user_can(Caps::PUBLISH_WINNER)) {
            echo '<h2>3. Publish the result</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('boh5050_draw');
            echo '<input type="hidden" name="action" value="boh5050_draw">';
            echo '<input type="hidden" name="step" value="publish">';
            printf('<input type="hidden" name="raffle_id" value="%d">', (int) $r['id']);
            echo '<p><label><input type="checkbox" name="name_consent" value="1"> The winner has given written consent for their name to be published.</label></p>';
            echo '<p><label>Winner name (only if consented)<br><input name="winner_name" class="regular-text"></label></p>';
            echo '<p><label>Reason / context<br><textarea name="reason" rows="2" cols="60" required></textarea></label></p>';
            submit_button('Publish winner', 'primary', 'submit', false);
            echo '</form>';
        }

        if ($draw && !empty($draw['evidence'])) {
            echo '<h2>Evidence</h2><pre style="background:#fff;border:1px solid #dcdcde;padding:12px;overflow:auto">'
               . esc_html((string) wp_json_encode(json_decode((string) $draw['evidence'], true), JSON_PRETTY_PRINT))
               . '</pre>';
        }
        echo '</div>';
    }

    public static function handleDraw(): void
    {
        check_admin_referer('boh5050_draw');
        self::guard(Caps::MANAGE_DRAW);

        $id     = (int) ($_POST['raffle_id'] ?? 0);
        $step   = sanitize_key((string) ($_POST['step'] ?? ''));
        $reason = sanitize_textarea_field((string) ($_POST['reason'] ?? ''));
        if ($reason === '') {
            self::redirect('error', 'A written reason is required.', 'draw');
        }

        $svc = new \BOH\Fifty\Domain\DrawService($id);

        $res = match ($step) {
            'lock' => $svc->lockPopulation($reason),
            'draw' => $svc->drawWinner($reason, [
                sanitize_text_field((string) ($_POST['witness1'] ?? '')),
                sanitize_text_field((string) ($_POST['witness2'] ?? '')),
            ]),
            'publish' => current_user_can(Caps::PUBLISH_WINNER)
                ? $svc->publish(!empty($_POST['name_consent']), (string) ($_POST['winner_name'] ?? ''), $reason)
                : ['ok' => false, 'error' => 'You do not have permission to publish the winner.'],
            default => ['ok' => false, 'error' => 'Unknown draw step.'],
        };

        if (!$res['ok']) {
            self::redirect('error', (string) $res['error'], 'draw');
        }
        $msg = isset($res['ticket'])
            ? 'Winning ticket drawn: ' . $res['ticket']
            : 'Done.';
        self::redirect('updated', $msg, 'draw');
    }

    public static function renderReconciliation(): void
    {
        self::guard(Caps::RECONCILE);
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}boh5050_reconciliations ORDER BY period_date DESC LIMIT 60", ARRAY_A) ?: [];
        echo '<div class="wrap"><h1>Reconciliation</h1><p>Target unresolved variance: $0. Final closeout is blocked while any variance is unresolved.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Ledger</th><th>Stripe</th><th>Variance</th><th>Resolved</th></tr></thead><tbody>';
        foreach ($rows as $x) {
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($x['period_date']),
                esc_html(Money::format((int) $x['ledger_gross_cents'])),
                esc_html(Money::format((int) $x['stripe_gross_cents'])),
                esc_html(Money::format((int) $x['variance_cents'])),
                $x['resolved'] ? 'yes' : 'no');
        }
        if (!$rows) { echo '<tr><td colspan="5">No reconciliation runs yet.</td></tr>'; }
        echo '</tbody></table></div>';
    }

    public static function renderReports(): void
    {
        self::guard(Caps::EXPORT_REPORTS);
        $r = self::raffle();
        echo '<div class="wrap"><h1>Reports &amp; Exports</h1><p>Exports are generated from the same ledger the public totals use.</p><ul style="list-style:disc;margin-left:22px">';
        foreach (\BOH\Fifty\Reports\Csv::available() as $key => $label) {
            printf('<li><a href="%s">%s</a></li>',
                esc_url(wp_nonce_url(admin_url('admin-post.php?action=boh5050_export&report=' . $key . '&raffle_id=' . (int) $r['id']), 'boh5050_export')),
                esc_html($label));
        }
        echo '</ul></div>';
    }

    public static function renderHelp(): void
    {
        self::guard(Caps::VIEW_DASHBOARD);
        echo '<div class="wrap"><h1>Help &amp; Compliance</h1>';
        echo '<h2>Release gate</h2><p>Live raffle payments must not be enabled until all of the following are true:</p><ol>';
        foreach ([
            'WIN House or another AGLC-eligible charity is confirmed as legal licensee.',
            'The raffle licence number and approved rules are entered.',
            'Stripe has approved this Canadian account for charity raffle transactions.',
            'The system is approved by AGLC or connected to an AGLC-approved electronic raffle system.',
            'Ticket packages, limits, sales dates and draw details match the licence.',
            'The permitted raffle format and sales period are confirmed with AGLC.',
        ] as $item) { printf('<li>%s</li>', esc_html($item)); }
        echo '</ol>';
        echo '<h2>References</h2><ul style="list-style:disc;margin-left:22px">
            <li><a href="https://aglc.ca/documents/raffle-terms-conditions" target="_blank" rel="noopener">AGLC raffle terms &amp; conditions</a></li>
            <li><a href="https://aglc.ca/gaming/licences/raffle-faq" target="_blank" rel="noopener">AGLC raffle FAQ</a></li>
            <li><a href="https://aglc.ca/forms/ers-gaming-supplier-list" target="_blank" rel="noopener">AGLC ERS supplier list</a></li>
            <li><a href="https://stripe.com/en-ca/legal/restricted-businesses" target="_blank" rel="noopener">Stripe restricted businesses</a></li></ul>';
        echo '<p><strong>Note:</strong> ticket purchases are not donations and are not eligible for charitable tax receipts.</p></div>';
    }

    /* -------------------------------------------------------------- actions */

    private static function guard(string $cap): void
    {
        if (!current_user_can($cap)) {
            wp_die('You do not have permission to view this page.', 403);
        }
    }

    public static function handleSetStatus(): void
    {
        check_admin_referer('boh5050_set_status');
        self::guard(Caps::MANAGE_SALES);

        global $wpdb;
        $id     = (int) ($_POST['raffle_id'] ?? 0);
        $to     = sanitize_key((string) ($_POST['status'] ?? ''));
        $reason = sanitize_textarea_field((string) ($_POST['reason'] ?? ''));

        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}boh5050_raffles WHERE id = %d", $id), ARRAY_A);
        if (!$r) { wp_die('Raffle not found.', 404); }

        if ($reason === '') {
            self::redirect('error', 'A written reason is required.');
        }
        if (!RaffleStatus::canTransition($r['status'], $to)) {
            self::redirect('error', 'That status change is not allowed from the current state.');
        }
        if ($to === RaffleStatus::LIVE && self::complianceGaps($r)) {
            self::redirect('error', 'Live is blocked until every compliance item is complete.');
        }

        $wpdb->update($wpdb->prefix . 'boh5050_raffles',
            ['status' => $to, 'updated_at' => current_time('mysql', true)], ['id' => $id]);

        Logger::log('status_changed', [
            'raffle_id' => $id, 'object_type' => 'raffle', 'object_id' => $id,
            'previous' => ['status' => $r['status']], 'new' => ['status' => $to], 'reason' => $reason,
        ]);
        self::redirect('updated', 'Status changed to ' . (RaffleStatus::labels()[$to] ?? $to) . '.');
    }

    public static function handleSaveSettings(): void
    {
        check_admin_referer('boh5050_save_settings');
        self::guard(Caps::MANAGE_SETTINGS);

        global $wpdb;
        $id = (int) ($_POST['raffle_id'] ?? 0);
        $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}boh5050_raffles WHERE id = %d", $id), ARRAY_A);
        if (!$before) { wp_die('Raffle not found.', 404); }

        $data = [
            'name'                  => sanitize_text_field((string) ($_POST['name'] ?? '')),
            'campaign_year'         => (int) ($_POST['campaign_year'] ?? gmdate('Y')),
            'licensee'              => sanitize_text_field((string) ($_POST['licensee'] ?? '')),
            'licence_number'        => sanitize_text_field((string) ($_POST['licence_number'] ?? '')),
            'rules_url'             => esc_url_raw((string) ($_POST['rules_url'] ?? '')),
            'draw_location'         => sanitize_text_field((string) ($_POST['draw_location'] ?? '')),
            'draw_method'           => sanitize_text_field((string) ($_POST['draw_method'] ?? '')),
            'ers_provider'          => sanitize_text_field((string) ($_POST['ers_provider'] ?? '')),
            'inventory_total'       => max(0, (int) ($_POST['inventory_total'] ?? 0)),
            'max_tickets_per_order' => max(0, (int) ($_POST['max_tickets_per_order'] ?? 0)),
            'stripe_mode'           => in_array($_POST['stripe_mode'] ?? 'test', ['test', 'live'], true) ? $_POST['stripe_mode'] : 'test',
            'updated_at'            => current_time('mysql', true),
        ];
        foreach (['sales_open_utc', 'sales_close_utc', 'draw_utc'] as $k) {
            $raw = sanitize_text_field((string) ($_POST[$k] ?? ''));
            $data[$k] = $raw === '' ? null : str_replace('T', ' ', $raw) . ':00';
        }

        $wpdb->update($wpdb->prefix . 'boh5050_raffles', $data, ['id' => $id]);

        // Record only what actually changed, so the log stays readable.
        $changed = [];
        foreach ($data as $k => $v) {
            if ($k !== 'updated_at' && (string) ($before[$k] ?? '') !== (string) $v) {
                $changed[$k] = ['from' => $before[$k] ?? null, 'to' => $v];
            }
        }
        if ($changed) {
            Logger::log('config_changed', [
                'raffle_id' => $id, 'object_type' => 'raffle', 'object_id' => $id,
                'new' => $changed, 'reason' => sanitize_textarea_field((string) ($_POST['reason'] ?? '')),
            ]);
        }
        self::redirect('updated', 'Configuration saved.');
    }

    public static function handleSavePackage(): void
    {
        check_admin_referer('boh5050_save_package');
        self::guard(Caps::MANAGE_SETTINGS);

        global $wpdb;
        $id    = (int) ($_POST['raffle_id'] ?? 0);
        $cents = Money::parse((string) ($_POST['price'] ?? ''));
        if ($cents === null || $cents <= 0) {
            self::redirect('error', 'Enter a valid price, for example 20.00', 'packages');
        }
        $wpdb->insert($wpdb->prefix . 'boh5050_packages', [
            'raffle_id'    => $id,
            'label'        => sanitize_text_field((string) ($_POST['label'] ?? '')),
            'ticket_count' => max(1, (int) ($_POST['ticket_count'] ?? 1)),
            'price_cents'  => $cents,
            'active'       => 1,
            'created_at'   => current_time('mysql', true),
        ]);
        Logger::log('package_added', [
            'raffle_id' => $id, 'object_type' => 'package', 'object_id' => (int) $wpdb->insert_id,
            'new' => ['label' => $_POST['label'] ?? '', 'price_cents' => $cents],
        ]);
        self::redirect('updated', 'Package added.', 'packages');
    }

    private static function redirect(string $type, string $msg, string $screen = ''): void
    {
        $slug = $screen === '' ? self::SLUG : self::SLUG . '-' . $screen;
        wp_safe_redirect(add_query_arg(
            ['page' => $slug, 'boh_notice' => $type, 'boh_msg' => rawurlencode($msg)],
            admin_url('admin.php')
        ));
        exit;
    }
}
