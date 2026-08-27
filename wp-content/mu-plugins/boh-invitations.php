<?php
/**
 * Plugin Name: BoH Invitations
 * Description: Invitee management for Baskets of Hope. Import a CSV of
 *              invitees, send invitation emails, track RSVP status by
 *              matching Flamingo's inbound messages, send reminders.
 *              Rate-limited so it plays nicely with Brevo's 300/day
 *              free-tier throttle (raise the cap in Settings when
 *              you're on a paid plan).
 */

defined( 'ABSPATH' ) || exit;

// ── Config ─────────────────────────────────────────────────────
const BOH_INV_TABLE       = 'boh_invitees';
const BOH_INV_MENU_SLUG   = 'boh-invitations';
const BOH_INV_CRON_HOOK   = 'boh_invitations_send_batch';
const BOH_INV_OPT_TEMPLATES = 'boh_invitations_templates';
const BOH_INV_OPT_LIMITS  = 'boh_invitations_limits';
const BOH_INV_CAP         = 'manage_options';

// ── Activation-style: install table on load if missing ─────────
add_action( 'plugins_loaded', 'boh_invitations_maybe_install' );
function boh_invitations_maybe_install() {
	global $wpdb;
	$table = $wpdb->prefix . BOH_INV_TABLE;
	$installed_version = get_option( 'boh_invitations_schema_version' );
	if ( $installed_version === '1.1' ) return;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE $table (
		id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
		name VARCHAR(255) NOT NULL DEFAULT '',
		email VARCHAR(255) NOT NULL,
		company VARCHAR(255) NOT NULL DEFAULT '',
		notes TEXT NULL,
		invitation_sent_at DATETIME NULL,
		reminder_sent_at DATETIME NULL,
		responded_at DATETIME NULL,
		party_size VARCHAR(64) NULL,
		source VARCHAR(20) NOT NULL DEFAULT 'invited',
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		UNIQUE KEY email (email),
		KEY status (invitation_sent_at, responded_at)
	) $charset";
	dbDelta( $sql );
	update_option( 'boh_invitations_schema_version', '1.1' );

	// Seed default templates
	if ( ! get_option( BOH_INV_OPT_TEMPLATES ) ) {
		update_option( BOH_INV_OPT_TEMPLATES, [
			'invitation_subject' => "You're invited — Rohit's Baskets of Hope 2026",
			'invitation_body'    => "Hi {{first_name}},\n\nYou're invited to Rohit's Baskets of Hope 2026 — an evening of community, comfort, and giving in support of WIN House.\n\nWhen:  Tuesday, November 3, 2026 · 6:00 PM\nWhere: Rohit Group Office, 10130 112 St NW, Edmonton\n\nEach guest brings 12 comfort items that we transform into gift baskets for women and families rebuilding after violence. If you can't bring items, you're welcome to partner with a friend or sponsor a basket financially.\n\nPlease RSVP so we can save you a seat:\n{{rsvp_url}}\n\nWith gratitude,\nRohit's Baskets of Hope team\nBoH@rohitgroup.com",
			'reminder_subject'   => "Save your seat — Baskets of Hope 2026",
			'reminder_body'      => "Hi {{first_name}},\n\nA quick reminder: Rohit's Baskets of Hope 2026 is on Tuesday, November 3 at 6:00 PM. We haven't heard back from you yet — would you like to join us?\n\nRSVP here:\n{{rsvp_url}}\n\nIf now isn't the right time, no worries. Reply to this email and we'll follow up next year.\n\nWith gratitude,\nRohit's Baskets of Hope team",
		] );
	}
	if ( ! get_option( BOH_INV_OPT_LIMITS ) ) {
		update_option( BOH_INV_OPT_LIMITS, [
			'per_day'   => 250, // Leaves headroom under Brevo's 300/day free cap
			'per_batch' => 15,  // How many to send each cron tick
		] );
	}
}

// ── Admin menu ─────────────────────────────────────────────────
add_action( 'admin_menu', function () {
	add_menu_page(
		'Invitations',
		'Invitations',
		BOH_INV_CAP,
		BOH_INV_MENU_SLUG,
		'boh_invitations_render_list',
		'dashicons-email',
		25
	);
	add_submenu_page(
		BOH_INV_MENU_SLUG,
		'All Invitees',
		'All Invitees',
		BOH_INV_CAP,
		BOH_INV_MENU_SLUG,
		'boh_invitations_render_list'
	);
	add_submenu_page(
		BOH_INV_MENU_SLUG,
		'Add Invitees',
		'Add Invitees',
		BOH_INV_CAP,
		BOH_INV_MENU_SLUG . '-import',
		'boh_invitations_render_import'
	);
	add_submenu_page(
		BOH_INV_MENU_SLUG,
		'Import from Flamingo',
		'From Flamingo',
		BOH_INV_CAP,
		BOH_INV_MENU_SLUG . '-flamingo',
		'boh_invitations_render_flamingo'
	);
	add_submenu_page(
		BOH_INV_MENU_SLUG,
		'Email Templates',
		'Email Templates',
		BOH_INV_CAP,
		BOH_INV_MENU_SLUG . '-templates',
		'boh_invitations_render_templates'
	);
	add_submenu_page(
		BOH_INV_MENU_SLUG,
		'Settings',
		'Settings',
		BOH_INV_CAP,
		BOH_INV_MENU_SLUG . '-settings',
		'boh_invitations_render_settings'
	);
} );

// ── Helpers ────────────────────────────────────────────────────
function boh_invitations_table() {
	global $wpdb;
	return $wpdb->prefix . BOH_INV_TABLE;
}

function boh_invitations_counts() {
	global $wpdb;
	$t = boh_invitations_table();
	return [
		'total'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ),
		'not_sent'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE invitation_sent_at IS NULL" ),
		'awaiting'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE invitation_sent_at IS NOT NULL AND responded_at IS NULL" ),
		'responded'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE responded_at IS NOT NULL" ),
		'reminded'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE reminder_sent_at IS NOT NULL" ),
	];
}

function boh_invitations_first_name( $full_name ) {
	$parts = preg_split( '/\s+/', trim( $full_name ) );
	return $parts && $parts[0] !== '' ? $parts[0] : 'friend';
}

function boh_invitations_render_email( $tpl_key, $invitee ) {
	$templates = get_option( BOH_INV_OPT_TEMPLATES, [] );
	$subject   = $templates[ $tpl_key . '_subject' ] ?? '';
	$body      = $templates[ $tpl_key . '_body' ] ?? '';
	// URL params are prefixed with `boh_` so they don't collide with
	// WordPress's reserved query vars — especially `name`, which WP
	// treats as a post-slug lookup and returns 404 when no match.
	$vars = [
		'{{name}}'       => $invitee->name ?: 'friend',
		'{{first_name}}' => boh_invitations_first_name( $invitee->name ),
		'{{email}}'      => $invitee->email,
		'{{company}}'    => $invitee->company,
		'{{rsvp_url}}'   => add_query_arg( [
			'boh_e'   => rawurlencode( $invitee->email ),
			'boh_n'   => rawurlencode( $invitee->name ),
			'boh_inv' => $invitee->id,
		], home_url( '/rsvp/' ) ),
	];
	return [
		'subject' => strtr( $subject, $vars ),
		'body'    => strtr( $body, $vars ),
	];
}

function boh_invitations_send_email( $invitee, $tpl_key ) {
	$mail = boh_invitations_render_email( $tpl_key, $invitee );
	if ( ! $mail['subject'] || ! $mail['body'] ) return false;
	$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
	return wp_mail( $invitee->email, $mail['subject'], $mail['body'], $headers );
}

function boh_invitations_send_count_today() {
	global $wpdb;
	$t = boh_invitations_table();
	$today = current_time( 'Y-m-d' );
	// Anyone whose invite OR reminder went out today
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $t WHERE DATE(invitation_sent_at) = %s OR DATE(reminder_sent_at) = %s",
		$today, $today
	) );
}

// ── Cron: send queued invitations in small batches ─────────────
add_filter( 'cron_schedules', function ( $s ) {
	$s['boh_10min'] = [ 'interval' => 600, 'display' => 'Every 10 minutes' ];
	return $s;
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( BOH_INV_CRON_HOOK ) ) {
		wp_schedule_event( time() + 60, 'boh_10min', BOH_INV_CRON_HOOK );
	}
} );
add_action( BOH_INV_CRON_HOOK, 'boh_invitations_process_queue' );

function boh_invitations_process_queue() {
	global $wpdb;
	$t = boh_invitations_table();
	$limits = get_option( BOH_INV_OPT_LIMITS );
	$per_day   = max( 1, (int) ( $limits['per_day']   ?? 250 ) );
	$per_batch = max( 1, (int) ( $limits['per_batch'] ?? 15 ) );

	$today_count = boh_invitations_send_count_today();
	$budget = max( 0, min( $per_batch, $per_day - $today_count ) );
	if ( $budget < 1 ) return;

	// Priority 1: queued invitations
	$to_invite = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM $t
		 WHERE invitation_sent_at IS NULL
		 ORDER BY id ASC
		 LIMIT %d",
		$budget
	) );
	$sent = 0;
	foreach ( $to_invite as $inv ) {
		if ( boh_invitations_send_email( $inv, 'invitation' ) ) {
			$wpdb->update( $t,
				[ 'invitation_sent_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $inv->id ]
			);
			$sent++;
		}
	}
	if ( $sent >= $budget ) return;

	// Priority 2: queued reminders (marked via a scheduled flag using notes prefix, or simpler:
	// any invitee where invitation_sent_at is > 7 days old, no response, no reminder yet).
	$budget -= $sent;
	if ( $budget < 1 ) return;
	// This "auto-remind after 7 days" behavior triggers only when the admin has enabled
	// automatic reminders by setting invitees' reminder_sent_at to NULL AND clicking "Queue reminders".
	// For simplicity in v1, reminders are only sent when manually queued via the admin action
	// (see bulk action handler). So the cron won't auto-remind — leave this as-is.
}

// (List view + admin actions continue in a separate include for readability.)
require_once __DIR__ . '/boh-invitations-admin.php';

// ── Flamingo → auto-mark responded ─────────────────────────────
// When Flamingo saves an inbound message from the RSVP form (id 19), find
// the matching invitee by email and stamp responded_at + party_size.
add_action( 'wpcf7_submit', function ( $contact_form, $result ) {
	if ( ! $contact_form || $contact_form->id() !== 19 ) return;
	if ( empty( $result ) || ! is_array( $result ) ) return;
	if ( ( $result['status'] ?? '' ) !== 'mail_sent' ) return;

	$submission = WPCF7_Submission::get_instance();
	if ( ! $submission ) return;
	$posted = $submission->get_posted_data();
	// Simplified RSVP form uses your-email; older form used the same key.
	$email  = sanitize_email( $posted['your-email'] ?? '' );
	if ( ! $email ) return;

	global $wpdb;
	$t = boh_invitations_table();
	$inv   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE email = %s", $email ) );
	$party = sanitize_text_field( $posted['party-size'] ?? '' );
	$now   = current_time( 'mysql', true );

	// Somebody who was never on the invite list can still RSVP from the site.
	// Previously that response was dropped here and survived only inside
	// Flamingo, so the guest list was quietly incomplete. Record them as a
	// walk-up so one screen shows every RSVP.
	if ( ! $inv ) {
		$name = trim(
			sanitize_text_field( $posted['first-name'] ?? '' ) . ' ' .
			sanitize_text_field( $posted['last-name'] ?? '' )
		);
		$wpdb->insert( $t, [
			'name'         => $name,
			'email'        => $email,
			'responded_at' => $now,
			'party_size'   => $party,
			'source'       => 'website',
			'created_at'   => $now,
			'updated_at'   => $now,
		] );
		return;
	}

	if ( $inv->responded_at ) return;

	$wpdb->update( $t,
		[
			'responded_at' => $now,
			'party_size'   => $party,
			'updated_at'   => $now,
		],
		[ 'id' => $inv->id ]
	);
}, 20, 2 );

/**
 * Guest-list export.
 *
 * "How many people are actually coming" could not be answered from the admin
 * screens: party size is stored as the form's own label ("1 — Just me", "2",
 * "10+"), so it needed interpreting, and walk-up RSVPs were not in the table
 * at all until the handler above started recording them.
 */
function boh_invitations_party_count( string $party ): int {
	$party = trim( $party );
	if ( $party === '' ) {
		// Someone who replied but left the party field blank is still one
		// person attending. Counting zero would understate the room. New
		// RSVPs always carry a value — the field defaults to "1 — Just me" —
		// so this only covers responses taken before that change.
		return 1;
	}
	// Labels look like "1 — Just me", "4", or "10+"; the leading number is
	// the guest count in every case.
	if ( preg_match( '/(\d+)/', $party, $m ) ) {
		return max( 0, (int) $m[1] );
	}
	return 0;
}

add_action( 'admin_post_boh_invitations_export', function () {
	if ( ! current_user_can( BOH_INV_CAP ) ) {
		wp_die( 'You do not have permission to export the guest list.' );
	}
	check_admin_referer( 'boh_invitations_export' );

	global $wpdb;
	$t    = boh_invitations_table();
	$rows = $wpdb->get_results(
		"SELECT name, email, company, party_size, source, invitation_sent_at, responded_at
		 FROM $t ORDER BY responded_at IS NULL, responded_at DESC, name ASC",
		ARRAY_A
	);

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=boh-rsvps-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, [ 'Name', 'Email', 'Company', 'Party size', 'Guests', 'Source', 'Invited at', 'Responded at' ] );

	$guests = 0;
	$yes    = 0;
	foreach ( (array) $rows as $r ) {
		$n = $r['responded_at'] ? boh_invitations_party_count( (string) $r['party_size'] ) : 0;
		$guests += $n;
		if ( $r['responded_at'] ) {
			$yes++;
		}
		fputcsv( $out, [
			$r['name'], $r['email'], $r['company'], $r['party_size'], $n ?: '',
			$r['source'] === 'website' ? 'Website RSVP' : 'Invited',
			$r['invitation_sent_at'], $r['responded_at'],
		] );
	}
	fputcsv( $out, [] );
	fputcsv( $out, [ 'Responses', $yes ] );
	fputcsv( $out, [ 'Total guests expected', $guests ] );
	fclose( $out );
	exit;
} );

/** Totals for the list screen, so the number is visible without exporting. */
function boh_invitations_guest_total(): array {
	global $wpdb;
	$t    = boh_invitations_table();
	$rows = $wpdb->get_results( "SELECT party_size, source FROM $t WHERE responded_at IS NOT NULL", ARRAY_A );
	$guests = 0;
	$walkup = 0;
	foreach ( (array) $rows as $r ) {
		$guests += boh_invitations_party_count( (string) $r['party_size'] );
		if ( ( $r['source'] ?? '' ) === 'website' ) {
			$walkup++;
		}
	}
	return [ 'responses' => count( (array) $rows ), 'guests' => $guests, 'walkup' => $walkup ];
}
