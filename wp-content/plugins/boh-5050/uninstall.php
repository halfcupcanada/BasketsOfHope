<?php
/**
 * Uninstall deliberately preserves the financial tables.
 *
 * Orders, payments, tickets, draws, reconciliations and the audit log are
 * financial and regulatory records. Deleting them because a plugin was removed
 * from the admin screen would destroy exactly the evidence a raffle licence
 * requires to be retained. Only display-level options are dropped.
 */
declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'boh5050_display_%'");
