<?php
/**
 * Plugin Name:  Baskets of Hope 50/50
 * Description:  Licensed 50/50 raffle for Rohit's Baskets of Hope — payments,
 *               ticketing boundary, compliance gating, audit and reconciliation.
 *               Self-contained: survives a theme change.
 * Version:      0.1.0
 * Requires PHP: 8.1
 * Author:       HalfCup
 * Text Domain:  boh-5050
 *
 * Money is integer cents everywhere. No float arithmetic is permitted in this
 * plugin — see Domain\Money.
 */

declare(strict_types=1);

namespace BOH\Fifty;

defined('ABSPATH') || exit;

const VERSION    = '0.1.0';
const SCHEMA_VER = 4;
const OPT_PREFIX = 'boh5050_';

define('BOH_5050_FILE', __FILE__);
define('BOH_5050_DIR', __DIR__);

/**
 * Minimal PSR-4 autoloader — no Composer on this host, and vendoring one just
 * for class loading would be more moving parts than the plugin needs.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/src/' . $rel . '.php';
    if (is_readable($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, static function (): void {
    Install\Migrator::migrate();
    Install\Capabilities::install();
    // Sales-close enforcement cannot depend on page-load cron; a system cron
    // drives wp-cron on this host (see 5050-ARCHITECTURE-AND-PLAN.md §2).
    if (!wp_next_scheduled('boh5050_minute_tick')) {
        wp_schedule_event(time() + 60, 'boh5050_minute', 'boh5050_minute_tick');
    }
    if (!wp_next_scheduled('boh5050_daily_reconcile')) {
        wp_schedule_event(time() + 300, 'daily', 'boh5050_daily_reconcile');
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('boh5050_minute_tick');
    wp_clear_scheduled_hook('boh5050_daily_reconcile');
    // Capabilities and tables are deliberately left in place: financial
    // records must survive deactivation.
});

add_filter('cron_schedules', static function (array $s): array {
    $s['boh5050_minute'] = ['interval' => 60, 'display' => 'Every minute (BoH 50/50)'];
    return $s;
});

add_action('plugins_loaded', static function (): void {
    // Run pending migrations after an update without needing re-activation.
    if ((int) get_option(OPT_PREFIX . 'schema_version', 0) < SCHEMA_VER) {
        Install\Migrator::migrate();
    }
    Admin\Menu::boot();
    Frontend\Controller::boot();
    Payments\WebhookController::boot();
});
