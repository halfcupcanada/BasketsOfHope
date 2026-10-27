<?php
/**
 * Plugin Name: BoH - Event Details send & review
 * Description: A review-and-send screen for the "Event details" email that goes
 *   to guests who have RSVP'd yes. It shows the email exactly as attendees will
 *   receive it, the complete list of recipients with each person's sent status,
 *   running counts, a CSV export, a "send a test to yourself" button, and a
 *   guarded "send to everyone who hasn't received it yet" action. Built so the
 *   team can see what will go out, to whom, and what has already gone, before
 *   and after sending.
 */
defined( 'ABSPATH' ) || exit;

// Who gets this email: anyone who responded and is not marked "Not attending".
function boh_inv_details_attending_where(): string {
	return "responded_at IS NOT NULL AND ( party_size IS NULL OR party_size <> 'Not attending' )";
}
function boh_inv_details_recipients( $only_unsent = false ) {
	global $wpdb;
	$t = boh_invitations_table();
	$where = boh_inv_details_attending_where();
	if ( $only_unsent ) {
		$where .= ' AND details_sent_at IS NULL';
	}
	return $wpdb->get_results( "SELECT * FROM $t WHERE $where ORDER BY name ASC" );
}

add_action( 'admin_menu', function () {
	add_submenu_page(
		BOH_INV_MENU_SLUG,
		'Event Details',
		'Event Details',
		BOH_INV_CAP,
		BOH_INV_MENU_SLUG . '-details',
		'boh_inv_details_render'
	);
}, 11 );

// CSV export of the recipient list + status.
add_action( 'admin_post_boh_inv_details_export', function () {
	if ( ! current_user_can( BOH_INV_CAP ) ) wp_die( 'nope' );
	check_admin_referer( 'boh_inv_details_export' );
	$rows = boh_inv_details_recipients( false );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename=event-details-recipients-' . gmdate( 'Y-m-d' ) . '.csv' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, [ 'Name', 'Email', 'Company', 'Party size', 'Guests', 'Details email sent' ] );
	foreach ( $rows as $r ) {
		fputcsv( $out, [
			$r->name, $r->email, $r->company, $r->party_size, $r->guest_count,
			$r->details_sent_at ? mysql2date( 'Y-m-d H:i', $r->details_sent_at ) : 'not yet',
		] );
	}
	fclose( $out );
	exit;
} );

function boh_inv_details_render() {
	if ( ! current_user_can( BOH_INV_CAP ) ) wp_die( 'nope' );
	global $wpdb;
	$t = boh_invitations_table();
	$notices = [];

	// ── Handle a send (test, or to-all-unsent) ──────────────────
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['boh_details_action'] ) ) {
		check_admin_referer( 'boh_inv_details_send' );
		$action = sanitize_text_field( $_POST['boh_details_action'] );

		if ( $action === 'test' ) {
			$me = wp_get_current_user();
			$to = $me && $me->user_email ? $me->user_email : get_option( 'admin_email' );
			$sample = (object) [ 'id' => 0, 'name' => $me->display_name ?: 'Team member', 'email' => $to, 'company' => '' ];
			// A test to yourself is a hand-picked send of one, so it works even
			// while bulk sending is switched off.
			$ok = boh_invitations_send_email( $sample, 'details', true );
			$notices[] = $ok
				? [ 'success', 'Test event-details email sent to ' . esc_html( $to ) . '. Check that inbox to see exactly what attendees will get.' ]
				: [ 'error', 'The test email could not be sent. Check the mail settings.' ];

		} elseif ( $action === 'send_all' ) {
			if ( empty( $_POST['boh_details_confirm'] ) ) {
				$notices[] = [ 'error', 'Tick the confirmation box first - nothing was sent.' ];
			} else {
				$rows   = boh_inv_details_recipients( true ); // unsent attendees
				$total  = count( $rows );
				$manual = $total <= BOH_INV_MANUAL_LIMIT;
				if ( ! boh_invitations_sending_enabled() && ! $manual ) {
					$notices[] = [ 'error', sprintf(
						'Bulk sending is switched OFF, so a send is limited to %d people at a time. There are %d to send. Turn sending on under Settings to send them all, or send in smaller batches.',
						BOH_INV_MANUAL_LIMIT, $total
					) ];
				} else {
					$sent = $failed = 0;
					foreach ( $rows as $inv ) {
						if ( boh_invitations_send_email( $inv, 'details', $manual ) ) {
							$wpdb->update( $t,
								[ 'details_sent_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
								[ 'id' => $inv->id ]
							);
							$sent++;
						} else {
							$failed++;
						}
					}
					$notices[] = [ $failed ? 'warning' : 'success', sprintf(
						'Sent %d event-details email%s%s.', $sent, $sent === 1 ? '' : 's',
						$failed ? ", {$failed} failed" : ''
					) ];
				}
			}
		}
	}

	// ── Gather current state ────────────────────────────────────
	$all     = boh_inv_details_recipients( false );
	$total   = count( $all );
	$sent_n  = 0;
	foreach ( $all as $r ) { if ( $r->details_sent_at ) $sent_n++; }
	$remain  = $total - $sent_n;

	$sending_on = boh_invitations_sending_enabled();
	$from_addr = defined( 'BOH_MAIL_FROM' )      ? BOH_MAIL_FROM      : '(fallback wp_mail)';
	$from_name = defined( 'BOH_MAIL_FROM_NAME' ) ? BOH_MAIL_FROM_NAME : get_bloginfo( 'name' );
	$reply_to  = defined( 'BOH_MAIL_REPLY_TO' )  ? BOH_MAIL_REPLY_TO  : $from_addr;

	// Preview uses a real attendee if there is one, else a demo.
	$sample = $all ? $all[0] : (object) [ 'id' => 0, 'name' => 'Sarah Chen', 'email' => 'sarah@example.com', 'company' => '' ];
	$preview = boh_invitations_render_email( 'details', $sample );
	?>
	<div class="wrap">
		<h1>Event Details email</h1>
		<p style="font-size:13px;color:#555;max-width:820px">This is the email for guests who have <strong>RSVP'd yes</strong>. Below is exactly what it says and how it looks, the full list of who will receive it, and who has received it already. Send yourself a test first, then send to everyone who hasn't had it yet.</p>

		<?php foreach ( $notices as [$type, $msg] ) : ?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo wp_kses_post( $msg ); ?></p></div>
		<?php endforeach; ?>

		<div style="display:flex;gap:16px;margin:16px 0;flex-wrap:wrap">
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 20px;min-width:150px">
				<div style="font-size:28px;font-weight:700;color:#1d2327"><?php echo (int) $total; ?></div>
				<div style="color:#646970">attending (will receive)</div>
			</div>
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 20px;min-width:150px">
				<div style="font-size:28px;font-weight:700;color:#0a7d0a"><?php echo (int) $sent_n; ?></div>
				<div style="color:#646970">already sent</div>
			</div>
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 20px;min-width:150px">
				<div style="font-size:28px;font-weight:700;color:#d01482"><?php echo (int) $remain; ?></div>
				<div style="color:#646970">not yet sent</div>
			</div>
		</div>

		<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px;max-width:1400px">
			<!-- LEFT: the email as attendees see it -->
			<div>
				<h2>What attendees will receive</h2>
				<div style="border:1px solid #e0e0e0;border-radius:4px;overflow:hidden">
					<div style="background:#f6f7f7;padding:10px 14px;border-bottom:1px solid #e0e0e0;font-size:12px;color:#666">
						<div>From: <strong><?php echo esc_html( $from_name ); ?></strong> &lt;<?php echo esc_html( $from_addr ); ?>&gt;</div>
						<div>Reply-To: <?php echo esc_html( $reply_to ); ?></div>
						<div style="margin-top:6px">Subject: <strong style="color:#1d2327"><?php echo esc_html( $preview['subject'] ); ?></strong></div>
						<div style="margin-top:4px;color:#888">Preview personalised for: <?php echo esc_html( $sample->name ?: $sample->email ); ?></div>
					</div>
					<div style="padding:16px 18px;line-height:1.55;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:14px;color:#222;background:#fff;max-height:620px;overflow:auto"><?php
						echo function_exists( 'boh_email_textify' ) ? boh_email_textify( $preview['body'] ) : nl2br( esc_html( $preview['body'] ) );
					?></div>
				</div>
				<p style="margin-top:10px;color:#646970;font-size:13px">To change this wording, go to <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG . '-templates' ) ); ?>">Email Templates</a>.</p>
			</div>

			<!-- RIGHT: send controls -->
			<div>
				<h2>Send</h2>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px;margin-bottom:16px">
					<p style="margin-top:0"><strong>Bulk sending is currently
						<?php if ( $sending_on ) : ?><span style="color:#0a7d0a">ON</span><?php else : ?><span style="color:#b32d2e">OFF</span><?php endif; ?></strong>
						<?php if ( ! $sending_on ) : ?><br><span style="color:#646970;font-size:13px">While it is off you can still send a test, and send to up to <?php echo (int) BOH_INV_MANUAL_LIMIT; ?> people at once. To send to all <?php echo (int) $remain; ?>, turn it on under <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG . '-settings' ) ); ?>">Settings</a>.</span><?php endif; ?>
					</p>

					<form method="post" style="margin-bottom:18px">
						<?php wp_nonce_field( 'boh_inv_details_send' ); ?>
						<input type="hidden" name="boh_details_action" value="test">
						<button class="button">Send a test to me (<?php echo esc_html( wp_get_current_user()->user_email ); ?>)</button>
						<p style="color:#646970;font-size:12px;margin:8px 0 0">Sends one copy to your own address so you can see the real thing in an inbox. Safe to use any time.</p>
					</form>

					<hr style="border:none;border-top:1px solid #eee;margin:16px 0">

					<form method="post" onsubmit="return confirm('Send the event-details email to <?php echo (int) $remain; ?> attendee(s) who have not received it yet?');">
						<?php wp_nonce_field( 'boh_inv_details_send' ); ?>
						<input type="hidden" name="boh_details_action" value="send_all">
						<p style="margin-top:0"><label><input type="checkbox" name="boh_details_confirm" value="1"> I have previewed the email and the recipient list below, and I want to send it to the <strong><?php echo (int) $remain; ?></strong> attendee(s) who have not received it yet.</label></p>
						<button class="button button-primary" <?php disabled( $remain === 0 ); ?>>Send to <?php echo (int) $remain; ?> remaining attendee<?php echo $remain === 1 ? '' : 's'; ?></button>
						<?php if ( $remain === 0 && $total > 0 ) : ?><span style="color:#0a7d0a;margin-left:8px">Everyone attending has received it.</span><?php endif; ?>
					</form>
				</div>
			</div>
		</div>

		<!-- FULL recipient list -->
		<h2 style="margin-top:28px">Who will receive it (<?php echo (int) $total; ?>)
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=boh_inv_details_export' ), 'boh_inv_details_export' ) ); ?>" class="page-title-action">Export CSV</a>
		</h2>
		<table class="wp-list-table widefat fixed striped" style="max-width:1100px">
			<thead><tr>
				<th>Name</th><th>Email</th><th>Company</th><th style="width:140px">Party</th><th style="width:150px">Details email</th>
			</tr></thead>
			<tbody>
				<?php if ( ! $all ) : ?>
					<tr><td colspan="5" style="text-align:center;padding:28px;color:#666">No one has RSVP'd yes yet, so there is no one to send to.</td></tr>
				<?php endif; ?>
				<?php foreach ( $all as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r->name ?: '-' ); ?></strong></td>
						<td><?php echo esc_html( $r->email ); ?></td>
						<td><?php echo esc_html( $r->company ); ?></td>
						<td><?php echo esc_html( $r->party_size ?: '-' ); ?></td>
						<td><?php echo $r->details_sent_at
							? '<strong style="color:#0a7d0a">&#10003; ' . esc_html( mysql2date( 'M j, g:i a', $r->details_sent_at ) ) . '</strong>'
							: '<span style="color:#b32d2e">not yet</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
