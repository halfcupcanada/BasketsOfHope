<?php
declare(strict_types=1);

namespace BOH\Fifty\Install;

use const BOH\Fifty\OPT_PREFIX;
use const BOH\Fifty\SCHEMA_VER;

/**
 * Versioned schema. Transactional raffle data lives in its own indexed tables
 * rather than in wp_posts/wp_postmeta: these rows are financial records that
 * get queried by range and summed, which post meta does neither well, and they
 * must not be editable or deletable through the normal WordPress UI.
 *
 * Money columns are BIGINT cents. No DECIMAL, no FLOAT.
 */
final class Migrator
{
    public static function migrate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'boh5050_';

        // Raffles — one row per campaign year.
        dbDelta("CREATE TABLE {$p}raffles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL DEFAULT '',
            campaign_year SMALLINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'disabled',
            licensee VARCHAR(190) NOT NULL DEFAULT '',
            licence_number VARCHAR(64) NOT NULL DEFAULT '',
            currency CHAR(3) NOT NULL DEFAULT 'CAD',
            timezone VARCHAR(64) NOT NULL DEFAULT 'America/Edmonton',
            sales_open_utc DATETIME NULL,
            sales_close_utc DATETIME NULL,
            draw_utc DATETIME NULL,
            draw_location VARCHAR(190) NOT NULL DEFAULT '',
            draw_method VARCHAR(190) NOT NULL DEFAULT '',
            ers_provider VARCHAR(190) NOT NULL DEFAULT '',
            rules_url VARCHAR(255) NOT NULL DEFAULT '',
            inventory_total INT UNSIGNED NOT NULL DEFAULT 0,
            max_tickets_per_order INT UNSIGNED NOT NULL DEFAULT 0,
            stripe_mode VARCHAR(8) NOT NULL DEFAULT 'test',
            expenses_sponsored TINYINT(1) NOT NULL DEFAULT 1,
            settings LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY campaign_year (campaign_year)
        ) {$c};");

        // Ticket packages — never hardcoded; priced in cents.
        dbDelta("CREATE TABLE {$p}packages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            raffle_id BIGINT UNSIGNED NOT NULL,
            label VARCHAR(190) NOT NULL DEFAULT '',
            ticket_count INT UNSIGNED NOT NULL DEFAULT 1,
            price_cents BIGINT NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY raffle_active (raffle_id, active),
            KEY sort_order (sort_order)
        ) {$c};");

        // Orders. idempotency_key is unique so a double-submitted checkout
        // cannot create two orders.
        dbDelta("CREATE TABLE {$p}orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            raffle_id BIGINT UNSIGNED NOT NULL,
            package_id BIGINT UNSIGNED NOT NULL,
            idempotency_key VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            ticket_quantity INT UNSIGNED NOT NULL DEFAULT 0,
            amount_cents BIGINT NOT NULL DEFAULT 0,
            purchaser_name VARCHAR(190) NOT NULL DEFAULT '',
            purchaser_email VARCHAR(190) NOT NULL DEFAULT '',
            purchaser_phone VARCHAR(64) NOT NULL DEFAULT '',
            attest_age TINYINT(1) NOT NULL DEFAULT 0,
            attest_alberta TINYINT(1) NOT NULL DEFAULT 0,
            is_test TINYINT(1) NOT NULL DEFAULT 1,
            correlation_id VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY raffle_status (raffle_id, status),
            KEY purchaser_email (purchaser_email),
            KEY created_at (created_at)
        ) {$c};");

        // Payments. stripe_event_id is unique — this is the replay guard.
        dbDelta("CREATE TABLE {$p}payments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            raffle_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            amount_cents BIGINT NOT NULL DEFAULT 0,
            refunded_cents BIGINT NOT NULL DEFAULT 0,
            fee_cents BIGINT NOT NULL DEFAULT 0,
            stripe_payment_intent VARCHAR(190) NOT NULL DEFAULT '',
            stripe_charge_id VARCHAR(190) NOT NULL DEFAULT '',
            stripe_refund_id VARCHAR(190) NOT NULL DEFAULT '',
            stripe_event_id VARCHAR(190) NOT NULL DEFAULT '',
            is_test TINYINT(1) NOT NULL DEFAULT 1,
            settled_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY stripe_event_id (stripe_event_id),
            KEY raffle_status (raffle_id, status),
            KEY order_id (order_id),
            KEY stripe_payment_intent (stripe_payment_intent)
        ) {$c};");

        // Tickets. ticket_number is unique per raffle. Under an approved ERS
        // these rows mirror the provider's numbering rather than owning it.
        dbDelta("CREATE TABLE {$p}tickets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            raffle_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            ticket_number VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'issued',
            issuer VARCHAR(32) NOT NULL DEFAULT 'internal',
            ers_reference VARCHAR(190) NOT NULL DEFAULT '',
            is_test TINYINT(1) NOT NULL DEFAULT 1,
            issued_at DATETIME NOT NULL,
            voided_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY raffle_ticket (raffle_id, ticket_number),
            KEY order_id (order_id),
            KEY raffle_status (raffle_id, status)
        ) {$c};");

        dbDelta("CREATE TABLE {$p}draws (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            raffle_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            eligible_locked_at DATETIME NULL,
            eligible_ticket_count INT UNSIGNED NOT NULL DEFAULT 0,
            winning_ticket_number VARCHAR(64) NOT NULL DEFAULT '',
            winner_name VARCHAR(190) NOT NULL DEFAULT '',
            winner_consent_public TINYINT(1) NOT NULL DEFAULT 0,
            prize_cents BIGINT NOT NULL DEFAULT 0,
            prize_paid_at DATETIME NULL,
            witnesses TEXT NULL,
            evidence LONGTEXT NULL,
            drawn_at DATETIME NULL,
            published_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY raffle_status (raffle_id, status)
        ) {$c};");

        // Append-only. No UPDATE or DELETE path exists in the plugin.
        dbDelta("CREATE TABLE {$p}audit_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            raffle_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            actor_login VARCHAR(190) NOT NULL DEFAULT '',
            action VARCHAR(64) NOT NULL,
            object_type VARCHAR(32) NOT NULL DEFAULT '',
            object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            previous_value LONGTEXT NULL,
            new_value LONGTEXT NULL,
            reason TEXT NULL,
            ip_address VARBINARY(16) NULL,
            correlation_id VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY raffle_action (raffle_id, action),
            KEY created_at (created_at),
            KEY object (object_type, object_id)
        ) {$c};");

        dbDelta("CREATE TABLE {$p}reconciliations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            raffle_id BIGINT UNSIGNED NOT NULL,
            period_date DATE NOT NULL,
            ledger_gross_cents BIGINT NOT NULL DEFAULT 0,
            stripe_gross_cents BIGINT NOT NULL DEFAULT 0,
            stripe_fee_cents BIGINT NOT NULL DEFAULT 0,
            payout_cents BIGINT NOT NULL DEFAULT 0,
            variance_cents BIGINT NOT NULL DEFAULT 0,
            expense_cents BIGINT NOT NULL DEFAULT 0,
            expense_category VARCHAR(190) NOT NULL DEFAULT '',
            expense_approved TINYINT(1) NOT NULL DEFAULT 0,
            resolved TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY raffle_period (raffle_id, period_date),
            KEY resolved (resolved)
        ) {$c};");

        // Two-person control: a dangerous action needs two distinct approvers.
        dbDelta("CREATE TABLE {$p}approvals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            raffle_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(64) NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            approver_user_id BIGINT UNSIGNED NOT NULL,
            reason TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY one_vote_each (action, payload_hash, approver_user_id),
            KEY raffle_action (raffle_id, action)
        ) {$c};");

        update_option(OPT_PREFIX . 'schema_version', SCHEMA_VER, false);
    }
}
