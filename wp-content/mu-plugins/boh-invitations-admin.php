<?php
/**
 * Admin views for boh-invitations.php. Split out for readability.
 */

defined( 'ABSPATH' ) || exit;

// ── List view ──────────────────────────────────────────────────
function boh_invitations_render_list() {
	if ( ! current_user_can( BOH_INV_CAP ) ) wp_die( 'nope' );
	global $wpdb;
	$t = boh_invitations_table();

	// Handle bulk actions
	$notices = [];

	// Saving edited headcounts. Separate from the bulk actions because it
	// applies to every row on screen, not to a checkbox selection.
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['boh_save_guests'] ) ) {
		check_admin_referer( 'boh_invitations_bulk' );
		$changed = 0;
		foreach ( (array) ( $_POST['guests'] ?? [] ) as $id => $n ) {
			$id = (int) $id;
			$n  = max( 0, min( 99, (int) $n ) );
			if ( ! $id ) {
				continue;
			}
			$changed += (int) $wpdb->update(
				$t,
				[ 'guest_count' => $n, 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $id ]
			);
		}
		$notices[] = [ 'success', $changed
			? sprintf( 'Updated %d headcount%s.', $changed, $changed === 1 ? '' : 's' )
			: 'No headcounts changed.' ];
	}

	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['action'] ) ) {
		check_admin_referer( 'boh_invitations_bulk' );
		$ids = array_map( 'intval', (array) ( $_POST['ids'] ?? [] ) );
		$ids = array_filter( $ids );
		$action = sanitize_text_field( $_POST['action'] );
		if ( $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			if ( $action === 'send_invitation' ) {
				$rows = $wpdb->get_results( "SELECT * FROM $t WHERE id IN ($in)" );
				$sent = $failed = 0;
				// Off switch on, more than a handful selected: refuse outright
				// and say why, rather than "0 sent, 40 failed".
				$manual = count( $rows ) <= BOH_INV_MANUAL_LIMIT;
				if ( ! boh_invitations_sending_enabled() && ! $manual ) {
					$notices[] = [ 'error', 'Automatic sending is off, so a hand-picked send is limited to ' . BOH_INV_MANUAL_LIMIT . ' people at a time. Select fewer, or turn sending on under Settings.' ];
					$rows = [];
				}
				foreach ( $rows as $inv ) {
					if ( boh_invitations_send_email( $inv, 'invitation', $manual ) ) {
						$wpdb->update( $t,
							[ 'invitation_sent_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
							[ 'id' => $inv->id ]
						);
						$sent++;
					} else {
						$failed++;
					}
				}
				$notices[] = [ 'success', "Sent {$sent} invitation(s)" . ( $failed ? ", {$failed} failed" : '' ) . '.' ];
			} elseif ( $action === 'send_reminder' ) {
				$rows = $wpdb->get_results( "SELECT * FROM $t WHERE id IN ($in) AND responded_at IS NULL" );
				$sent = $failed = 0;
				foreach ( $rows as $inv ) {
					if ( boh_invitations_send_email( $inv, 'reminder', count( $rows ) <= BOH_INV_MANUAL_LIMIT ) ) {
						$wpdb->update( $t,
							[ 'reminder_sent_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
							[ 'id' => $inv->id ]
						);
						$sent++;
					} else {
						$failed++;
					}
				}
				$notices[] = [ 'success', "Sent {$sent} reminder(s)" . ( $failed ? ", {$failed} failed" : '' ) . '.' ];
			} elseif ( $action === 'delete' ) {
				$wpdb->query( "DELETE FROM $t WHERE id IN ($in)" );
				$notices[] = [ 'success', 'Deleted ' . count( $ids ) . ' invitee(s).' ];
			} elseif ( $action === 'mark_responded' ) {
				$wpdb->query( $wpdb->prepare( "UPDATE $t SET responded_at = %s, updated_at = %s WHERE id IN ($in)", current_time( 'mysql', true ), current_time( 'mysql', true ) ) );
				$notices[] = [ 'success', 'Marked ' . count( $ids ) . ' as responded.' ];
			}
		}
	}

	// Filters
	$filter = sanitize_key( $_GET['status'] ?? 'all' );
	$search = sanitize_text_field( $_GET['s'] ?? '' );
	$where  = '1=1';
	$params = [];
	if ( $filter === 'not_sent' )  $where .= ' AND invitation_sent_at IS NULL';
	if ( $filter === 'awaiting' )  $where .= ' AND invitation_sent_at IS NOT NULL AND responded_at IS NULL';
	if ( $filter === 'responded' ) $where .= ' AND responded_at IS NOT NULL';
	if ( $filter === 'reminded' )  $where .= ' AND reminder_sent_at IS NOT NULL';
	if ( $search ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= ' AND (name LIKE %s OR email LIKE %s OR company LIKE %s)';
		array_push( $params, $like, $like, $like );
	}

	$per_page = 50;
	$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$offset   = ( $paged - 1 ) * $per_page;
	$total_sql   = "SELECT COUNT(*) FROM $t WHERE $where";
	$select_sql  = "SELECT * FROM $t WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d";
	if ( $params ) {
		$total    = (int) $wpdb->get_var( $wpdb->prepare( $total_sql, ...$params ) );
		$rows_sql = $wpdb->prepare( $select_sql, ...array_merge( $params, [ $per_page, $offset ] ) );
	} else {
		$total    = (int) $wpdb->get_var( $total_sql );
		$rows_sql = $wpdb->prepare( $select_sql, $per_page, $offset );
	}
	$rows = $wpdb->get_results( $rows_sql );

	$counts = boh_invitations_counts();
	$today  = boh_invitations_send_count_today();
	$limits = get_option( BOH_INV_OPT_LIMITS );

	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">Invitees <span class="count">(<?php echo $counts['total']; ?>)</span></h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG . '-import' ) ); ?>" class="page-title-action">Add invitees</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG . '-flamingo' ) ); ?>" class="page-title-action">From Flamingo</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG . '-templates' ) ); ?>" class="page-title-action">Templates</a>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=boh_invitations_export' ), 'boh_invitations_export' ) ); ?>" class="page-title-action">Export CSV</a>

		<?php
		// Headline numbers: how many said yes, and how many people that is
		// once party sizes are added up.
		$boh_totals = function_exists( 'boh_invitations_guest_total' ) ? boh_invitations_guest_total() : null;
		if ( $boh_totals ) : ?>
			<div class="notice notice-info" style="margin:14px 0;padding:10px 14px">
				<p style="margin:0;font-size:14px">
					<strong><?php echo (int) $boh_totals['responses']; ?></strong> RSVP<?php echo $boh_totals['responses'] === 1 ? '' : 's'; ?> ·
					<strong><?php echo (int) $boh_totals['guests']; ?></strong> guests expected
					<?php if ( $boh_totals['walkup'] ) : ?>
						· <strong><?php echo (int) $boh_totals['walkup']; ?></strong> from the website (not on the invite list)
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>

		<?php foreach ( $notices as [$type, $msg] ) : ?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endforeach; ?>

		<div class="tablenav top" style="display:flex;gap:12px;align-items:center;margin:16px 0">
			<ul class="subsubsub" style="margin:0">
				<?php
				$tabs = [
					'all'       => "All ({$counts['total']})",
					'not_sent'  => "Not invited yet ({$counts['not_sent']})",
					'awaiting'  => "Awaiting reply ({$counts['awaiting']})",
					'responded' => "Responded ({$counts['responded']})",
					'reminded'  => "Reminded ({$counts['reminded']})",
				];
				foreach ( $tabs as $key => $label ) : ?>
					<li><a class="<?php echo $filter === $key ? 'current' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG . '&status=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a> <?php echo array_key_last( $tabs ) === $key ? '' : ' |'; ?></li>
				<?php endforeach; ?>
			</ul>
			<form method="get" style="margin-left:auto">
				<input type="hidden" name="page" value="<?php echo BOH_INV_MENU_SLUG; ?>">
				<input type="hidden" name="status" value="<?php echo esc_attr( $filter ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search name, email, company">
				<button class="button">Search</button>
			</form>
		</div>

		<div class="notice notice-info" style="padding:8px 12px;margin:0 0 12px"><strong>Today's send budget:</strong> <?php echo (int) $today; ?> / <?php echo (int) ( $limits['per_day'] ?? 250 ); ?> used. Cron runs every 10 min and queues invitations automatically.</div>

		<form method="post">
			<?php wp_nonce_field( 'boh_invitations_bulk' ); ?>
			<div class="tablenav top">
				<select name="action">
					<option value="">Bulk actions</option>
					<option value="send_invitation">Send invitation email</option>
					<option value="send_reminder">Send reminder email</option>
					<option value="mark_responded">Mark as responded</option>
					<option value="delete">Delete</option>
				</select>
				<button class="button action">Apply</button>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('input[name=&quot;ids[]&quot;]').forEach(c=>c.checked=this.checked)"></td>
						<th>Name</th>
						<th>Email</th>
						<th>Company</th>
						<th style="width:110px">Invited</th>
						<th style="width:110px">Reminded</th>
						<th style="width:110px">Responded</th>
						<th style="width:150px">Party</th>
						<th style="width:96px">Guests</th>
						<th style="width:150px">Invited by</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="10" style="text-align:center;padding:32px;color:#666">No invitees match this filter.</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $r ) : ?>
					<tr>
						<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $r->id; ?>"></th>
						<td><strong><?php echo esc_html( $r->name ?: '-' ); ?></strong></td>
						<td><?php echo esc_html( $r->email ); ?></td>
						<td><?php echo esc_html( $r->company ); ?></td>
						<td><?php echo $r->invitation_sent_at ? esc_html( mysql2date( 'M j', $r->invitation_sent_at ) ) : '<span style="color:#999">-</span>'; ?></td>
						<td><?php echo $r->reminder_sent_at ? esc_html( mysql2date( 'M j', $r->reminder_sent_at ) ) : '<span style="color:#999">-</span>'; ?></td>
						<td>
							<?php if ( $r->responded_at ) : ?>
								<strong style="color:#0a7d0a">✓ <?php echo esc_html( mysql2date( 'M j', $r->responded_at ) ); ?></strong>
							<?php else : ?>
								<span style="color:#999">-</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $r->party_size ?: '-' ); ?></td>
						<?php
						// The form stores the RSVP's own wording ("1 - Just me",
						// "6+"), so the headcount is derived from it - and stays
						// editable, because a reply by email or at the door will
						// not match whatever the dropdown offered.
						$boh_guests = function_exists( 'boh_invitations_party_count' ) && $r->responded_at
							? boh_invitations_party_count( (string) $r->party_size )
							: 0;
						?>
						<td>
							<input type="number" min="0" max="99" name="guests[<?php echo (int) $r->id; ?>]"
							       value="<?php echo (int) $boh_guests; ?>"
							       style="width:64px" aria-label="Guests coming with <?php echo esc_attr( $r->name ?: $r->email ); ?>">
						</td>
						<td><?php echo esc_html( $r->referred_by ?? '' ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin:14px 0 0">
				<button type="submit" name="boh_save_guests" value="1" class="button button-primary">Save headcounts</button>
				<span class="description" style="margin-left:10px">Set how many people are coming with each guest. Blank rows count as their RSVP said.</span>
			</p>
			<?php
			$total_pages = (int) ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				$base = admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG . '&status=' . $filter );
				echo '<div class="tablenav bottom"><div class="tablenav-pages">';
				echo paginate_links( [
					'base'      => add_query_arg( 'paged', '%#%', $base ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => '‹',
					'next_text' => '›',
				] );
				echo '</div></div>';
			}
			?>
		</form>
	</div>
	<?php
}

// ── Import view ────────────────────────────────────────────────
function boh_invitations_render_import() {
	if ( ! current_user_can( BOH_INV_CAP ) ) wp_die( 'nope' );
	global $wpdb;
	$t = boh_invitations_table();
	$notices = [];

	// Handle add-one form
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['boh_invitations_action'] ?? '' ) === 'add_one' ) {
		check_admin_referer( 'boh_invitations_add_one' );
		$name    = sanitize_text_field( $_POST['name']    ?? '' );
		$email   = sanitize_email(      $_POST['email']   ?? '' );
		$company = sanitize_text_field( $_POST['company'] ?? '' );
		$notes   = sanitize_textarea_field( $_POST['notes'] ?? '' );

		if ( ! $email || ! is_email( $email ) ) {
			$notices[] = [ 'error', 'Please enter a valid email address.' ];
		} else {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $t WHERE email = %s", $email ) );
			if ( $existing ) {
				$wpdb->update( $t,
					[
						'name'       => $name,
						'company'    => $company,
						'notes'      => $notes,
						'updated_at' => current_time( 'mysql', true ),
					],
					[ 'id' => $existing->id ]
				);
				$notices[] = [ 'success', "Updated existing invitee <code>" . esc_html( $email ) . "</code>." ];
			} else {
				$wpdb->insert( $t, [
					'name'       => $name,
					'email'      => $email,
					'company'    => $company,
					'notes'      => $notes,
					'created_at' => current_time( 'mysql', true ),
					'updated_at' => current_time( 'mysql', true ),
				] );
				$new_id = (int) $wpdb->insert_id;
				$send_now = ! empty( $_POST['send_now'] );
				if ( $send_now && $new_id ) {
					$inv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $new_id ) );
					if ( boh_invitations_send_email( $inv, 'invitation', true ) ) {
						$wpdb->update( $t,
							[ 'invitation_sent_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
							[ 'id' => $new_id ]
						);
						$notices[] = [ 'success', "Added <code>" . esc_html( $email ) . "</code> and sent invitation." ];
					} else {
						$notices[] = [ 'warning', "Added <code>" . esc_html( $email ) . "</code> but the invitation email could not be sent - the mail relay refused it. Check WP Mail SMTP, then use Send invitation from the list." ];
					}
				} else {
					$notices[] = [ 'success', "Added <code>" . esc_html( $email ) . "</code> - will send on next cron tick, or send manually from All Invitees." ];
				}
			}
		}
	}

	// Step 1 of a bulk import: read the rows and show them for review.
	$preview = null;
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['boh_invitations_action'] ?? '' ) === 'bulk_import' ) {
		check_admin_referer( 'boh_invitations_import' );
		$rows   = [];
		$source = '';
		if ( ! empty( $_FILES['csv']['tmp_name'] ) && is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			[ $rows, $err ] = boh_invitations_import_parse_csv( $_FILES['csv']['tmp_name'] );
			$source = sanitize_file_name( (string) $_FILES['csv']['name'] );
			if ( $err ) {
				$notices[] = [ 'error', $err ];
			}
		} elseif ( trim( (string) ( $_POST['paste'] ?? '' ) ) !== '' ) {
			$rows   = boh_invitations_import_parse_paste( (string) $_POST['paste'] );
			$source = 'pasted rows';
		} else {
			$notices[] = [ 'error', 'Choose a CSV file or paste some rows first.' ];
		}
		if ( $rows ) {
			$data = boh_invitations_import_classify( $rows );
			$data['source'] = $source;
			$data['user']   = get_current_user_id();
			$token = wp_generate_password( 20, false );
			set_transient( 'boh_inv_import_' . $token, $data, BOH_INV_IMPORT_TTL );
			$preview = [ $token, $data ];
		} elseif ( $source !== '' && empty( $notices ) ) {
			$notices[] = [ 'error', 'No rows were found in ' . esc_html( $source ) . '.' ];
		}
	}

	// Step 2: the reviewed rows are written.
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['boh_invitations_action'] ?? '' ) === 'bulk_import_confirm' ) {
		check_admin_referer( 'boh_invitations_import_confirm' );
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $_POST['token'] ?? '' ) );
		$data  = $token ? get_transient( 'boh_inv_import_' . $token ) : false;
		if ( ! is_array( $data ) || (int) ( $data['user'] ?? 0 ) !== get_current_user_id() ) {
			$notices[] = [ 'error', 'That review has expired - reviews are kept for an hour. Upload the file again.' ];
		} else {
			$chosen = array_map( 'intval', (array) ( $_POST['rows'] ?? [] ) );
			[ $added, $updated ] = boh_invitations_import_apply( $data['rows'], $chosen );
			delete_transient( 'boh_inv_import_' . $token );
			$total = (int) boh_invitations_counts()['total'];
			$notices[] = [ 'success', sprintf(
				'Imported from %s: <strong>%d added</strong>, <strong>%d updated</strong>. The list now has <strong>%s</strong> invitees. <a href="%s">View all invitees</a>',
				esc_html( $data['source'] ), $added, $updated, number_format_i18n( $total ), esc_url( admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG ) )
			) ];
		}
	}

	?>
	<div class="wrap">
		<h1>Add Invitees</h1>
		<?php foreach ( $notices as [$type, $msg] ) : ?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo wp_kses_post( $msg ); ?></p></div>
		<?php endforeach; ?>

		<?php if ( $preview ) : boh_invitations_render_import_preview( $preview[0], $preview[1] ); ?>
		<?php else : ?>

		<form method="post" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px;max-width:820px;margin-top:16px">
			<?php wp_nonce_field( 'boh_invitations_add_one' ); ?>
			<input type="hidden" name="boh_invitations_action" value="add_one">
			<h2 style="margin-top:0">Add one invitee</h2>
			<p style="color:#666;margin:0 0 8px">Quickest way to add someone - name, email, company. Optional: notes and send invitation immediately.</p>
			<table class="form-table" role="presentation">
				<tr>
					<th style="width:160px"><label for="boh_inv_name">Name</label></th>
					<td><input id="boh_inv_name" type="text" name="name" placeholder="Full name" style="width:100%"></td>
				</tr>
				<tr>
					<th><label for="boh_inv_email">Email <span style="color:#d01482">*</span></label></th>
					<td><input id="boh_inv_email" type="email" name="email" placeholder="you@example.com" required style="width:100%"></td>
				</tr>
				<tr>
					<th><label for="boh_inv_company">Company</label></th>
					<td><input id="boh_inv_company" type="text" name="company" placeholder="Organization" style="width:100%"></td>
				</tr>
				<tr>
					<th><label for="boh_inv_notes">Notes</label></th>
					<td><input id="boh_inv_notes" type="text" name="notes" placeholder="Anything to remember about them" style="width:100%"></td>
				</tr>
				<tr>
					<th>Send now?</th>
					<td>
						<label style="display:inline-flex;align-items:center;gap:8px">
							<input type="checkbox" name="send_now" value="1">
							Send the invitation email immediately (counts toward today's send budget)
						</label>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary">Add invitee</button> <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG ) ); ?>" class="button">Back to list</a></p>
		</form>

		<form method="post" enctype="multipart/form-data" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px;max-width:820px;margin-top:16px">
			<?php wp_nonce_field( 'boh_invitations_import' ); ?>
			<input type="hidden" name="boh_invitations_action" value="bulk_import">
			<h2 style="margin-top:0">Bulk: CSV upload</h2>
			<p>Upload a <code>.csv</code> file. Required column: <strong>email</strong>. Optional: <strong>name</strong>, <strong>company</strong>, <strong>notes</strong>. Someone already on the list (matched by email) is updated, not added twice.</p>
			<p>Example:</p>
			<pre style="background:#f6f7f7;padding:12px;border-radius:4px">name,email,company
Sarah Chen,sarah@example.com,Acme Corp
Jamie Patel,jamie@example.com,Bluebird Labs</pre>
			<p><input type="file" name="csv" accept=".csv"></p>

			<h2>Or paste rows</h2>
			<p>One per line. Format: <code>Name, email@example.com, Company</code></p>
			<p><textarea name="paste" rows="8" style="width:100%;font-family:monospace;font-size:13px" placeholder="Sarah Chen, sarah@example.com, Acme Corp&#10;Jamie Patel, jamie@example.com, Bluebird Labs"></textarea></p>

			<p><button type="submit" class="button button-primary">Review import</button> <span style="color:#666">You will see every row and the totals before anything is saved.</span></p>
		</form>

		<?php endif; ?>
	</div>
	<?php
}

// ── Bulk import: parse, preview, confirm ───────────────────────
//
// Nothing is written on the first submit. The file or pasted rows are read,
// every row is matched against the list, and the result is shown for review:
// how many are new, how many already on the list and what would change,
// which lines have no usable email, which are repeated in the file. The
// rows wait in a transient under a token until Confirm - or an hour passes.

const BOH_INV_IMPORT_TTL = HOUR_IN_SECONDS;

function boh_invitations_import_key( string $col ): string {
	// "Full_Name", "E-Mail Address", "COMPANY " all become plain words.
	$col = preg_replace( '/^\xEF\xBB\xBF/', '', $col ); // Excel's BOM on the first header
	return strtolower( trim( preg_replace( '/[\s_\-]+/', ' ', $col ) ) );
}

/** Rows from an uploaded CSV. Returns [ rows, error-or-null ]. */
function boh_invitations_import_parse_csv( string $path ): array {
	$fh = fopen( $path, 'r' );
	if ( ! $fh ) {
		return [ [], 'The file could not be read.' ];
	}
	$header = fgetcsv( $fh );
	if ( ! is_array( $header ) ) {
		fclose( $fh );
		return [ [], 'The file is empty.' ];
	}
	$map = [];
	foreach ( $header as $i => $col ) {
		$key = boh_invitations_import_key( (string) $col );
		if ( in_array( $key, [ 'name', 'full name', 'contact', 'contact name', 'guest', 'guest name' ], true ) ) $map['name']    = $i;
		if ( in_array( $key, [ 'first name', 'first', 'given name' ], true ) )                                   $map['first']   = $i;
		if ( in_array( $key, [ 'last name', 'last', 'surname', 'family name' ], true ) )                          $map['last']    = $i;
		if ( in_array( $key, [ 'email', 'e mail', 'email address', 'e mail address', 'mail' ], true ) )           $map['email']   = $i;
		if ( in_array( $key, [ 'company', 'organization', 'organisation', 'org', 'business', 'employer' ], true ) ) $map['company'] = $i;
		if ( in_array( $key, [ 'notes', 'note', 'comments', 'comment' ], true ) )                                 $map['notes']   = $i;
	}
	if ( ! isset( $map['email'] ) ) {
		fclose( $fh );
		return [ [], 'The file needs a column named <strong>email</strong> (any capitalisation). Found: <code>' . esc_html( implode( ', ', array_map( 'trim', $header ) ) ) . '</code>.' ];
	}
	$rows = [];
	$line = 1;
	while ( ( $cells = fgetcsv( $fh ) ) !== false ) {
		$line++;
		if ( $cells === [ null ] || trim( implode( '', array_map( 'strval', $cells ) ) ) === '' ) {
			continue; // blank line
		}
		$get  = fn( $k ) => isset( $map[ $k ] ) ? trim( (string) ( $cells[ $map[ $k ] ] ?? '' ) ) : '';
		$name = $get( 'name' );
		if ( $name === '' && ( isset( $map['first'] ) || isset( $map['last'] ) ) ) {
			$name = trim( $get( 'first' ) . ' ' . $get( 'last' ) );
		}
		$rows[] = [
			'line'    => $line,
			'name'    => $name,
			'email'   => $get( 'email' ),
			'company' => $get( 'company' ),
			'notes'   => $get( 'notes' ),
		];
	}
	fclose( $fh );
	return [ $rows, null ];
}

/** Rows from the paste box: Name, email, Company - one per line. */
function boh_invitations_import_parse_paste( string $paste ): array {
	$rows  = [];
	$paste = str_replace( "\r", '', $paste );
	foreach ( explode( "\n", $paste ) as $i => $line ) {
		$line = trim( $line );
		if ( $line === '' ) {
			continue;
		}
		$parts = array_map( 'trim', preg_split( '/[\t,;]/', $line, 3 ) );
		// A line that is just an address still imports.
		if ( count( $parts ) === 1 && is_email( $parts[0] ) ) {
			$parts = [ '', $parts[0] ];
		}
		$rows[] = [
			'line'    => $i + 1,
			'name'    => $parts[0] ?? '',
			'email'   => $parts[1] ?? '',
			'company' => $parts[2] ?? '',
			'notes'   => '',
		];
	}
	return $rows;
}

/**
 * Decide what each row would do, without doing it.
 *
 * new     - not on the list
 * update  - on the list; one or more of name / company / notes would change
 * same    - on the list and nothing to change
 * invalid - no usable email
 * repeat  - the same email appears again further down; the last row wins
 *
 * An empty cell never wipes what the list already holds - a file with only
 * names and emails leaves existing companies and notes alone.
 */
function boh_invitations_import_classify( array $rows ): array {
	global $wpdb;
	$t = boh_invitations_table();

	$out = [];
	foreach ( $rows as $r ) {
		$email = sanitize_email( $r['email'] );
		$out[] = [
			'line'     => (int) $r['line'],
			'name'     => sanitize_text_field( $r['name'] ),
			'email'    => $email && is_email( $email ) ? strtolower( $email ) : '',
			'raw'      => $r['email'],
			'company'  => sanitize_text_field( $r['company'] ),
			'notes'    => sanitize_textarea_field( $r['notes'] ),
			'status'   => '',
			'existing' => null,
			'changes'  => [],
		];
	}

	// Last occurrence of an email wins; earlier ones are marked as repeats.
	$last = [];
	foreach ( $out as $i => $r ) {
		if ( $r['email'] !== '' ) {
			$last[ $r['email'] ] = $i;
		}
	}
	$emails = array_keys( $last );
	$existing = [];
	if ( $emails ) {
		$in = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, email, name, company, notes FROM $t WHERE LOWER(email) IN ($in)", $emails ) ) as $row ) {
			$existing[ strtolower( $row->email ) ] = $row;
		}
	}

	$counts = [ 'rows' => count( $out ), 'new' => 0, 'update' => 0, 'same' => 0, 'invalid' => 0, 'repeat' => 0 ];
	foreach ( $out as $i => &$r ) {
		if ( $r['email'] === '' ) {
			$r['status'] = 'invalid';
		} elseif ( $last[ $r['email'] ] !== $i ) {
			$r['status'] = 'repeat';
		} elseif ( isset( $existing[ $r['email'] ] ) ) {
			$e = $existing[ $r['email'] ];
			$r['existing'] = [ 'id' => (int) $e->id, 'name' => (string) $e->name, 'company' => (string) $e->company, 'notes' => (string) $e->notes ];
			foreach ( [ 'name', 'company', 'notes' ] as $f ) {
				if ( $r[ $f ] !== '' && $r[ $f ] !== $r['existing'][ $f ] ) {
					$r['changes'][] = $f;
				}
			}
			$r['status'] = $r['changes'] ? 'update' : 'same';
		} else {
			$r['status'] = 'new';
		}
		$counts[ $r['status'] ]++;
	}
	unset( $r );

	return [ 'rows' => $out, 'counts' => $counts ];
}

/** Write the chosen rows. Returns [ added, updated ]. */
function boh_invitations_import_apply( array $rows, array $chosen ): array {
	global $wpdb;
	$t = boh_invitations_table();
	$now = current_time( 'mysql', true );
	$added = 0;
	$updated = 0;
	foreach ( $chosen as $i ) {
		$r = $rows[ $i ] ?? null;
		if ( ! $r || ! in_array( $r['status'], [ 'new', 'update' ], true ) ) {
			continue;
		}
		if ( $r['status'] === 'update' ) {
			$data = [ 'updated_at' => $now ];
			foreach ( $r['changes'] as $f ) {
				$data[ $f ] = $r[ $f ];
			}
			$wpdb->update( $t, $data, [ 'id' => $r['existing']['id'] ] );
			$updated++;
		} else {
			$wpdb->insert( $t, [
				'name'       => $r['name'],
				'email'      => $r['email'],
				'company'    => $r['company'],
				'notes'      => $r['notes'],
				'created_at' => $now,
				'updated_at' => $now,
			] );
			$added++;
		}
	}
	return [ $added, $updated ];
}

/** The review screen: totals, then every row with what it would do. */
function boh_invitations_render_import_preview( string $token, array $data ): void {
	$c      = $data['counts'];
	$total  = (int) boh_invitations_counts()['total'];
	$after  = $total + $c['new'];
	$badge  = [
		'new'     => [ 'New',            '#1a7f37', '#dafbe1' ],
		'update'  => [ 'Update',         '#0b5cad', '#ddf0ff' ],
		'same'    => [ 'Already listed', '#57606a', '#eef1f4' ],
		'invalid' => [ 'No valid email', '#a40e26', '#ffe2e0' ],
		'repeat'  => [ 'Repeated below', '#7a5a00', '#fff3c4' ],
	];
	$can = $c['new'] + $c['update'];
	?>
	<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px;max-width:1100px;margin-top:16px">
		<h2 style="margin-top:0">Review before importing</h2>
		<p style="color:#666;margin:0 0 16px">
			Source: <strong><?php echo esc_html( $data['source'] ); ?></strong> &middot; nothing has been saved yet.
		</p>

		<div style="display:flex;flex-wrap:wrap;gap:12px;margin:0 0 20px">
			<?php
			$tiles = [
				[ 'On the list now', $total, '#1d2327' ],
				[ 'Rows in this import', $c['rows'], '#1d2327' ],
				[ 'New', $c['new'], '#1a7f37' ],
				[ 'Will be updated', $c['update'], '#0b5cad' ],
				[ 'Already listed, unchanged', $c['same'], '#57606a' ],
				[ 'No valid email', $c['invalid'], '#a40e26' ],
				[ 'Repeated in file', $c['repeat'], '#7a5a00' ],
				[ 'On the list after', $after, '#d01482' ],
			];
			foreach ( $tiles as [ $label, $n, $color ] ) : ?>
				<div style="flex:1 1 110px;min-width:110px;box-sizing:border-box;border:1px solid #e3e3e3;border-radius:6px;padding:10px 12px">
					<div style="font-size:12px;color:#666"><?php echo esc_html( $label ); ?></div>
					<div style="font-size:22px;font-weight:600;color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( number_format_i18n( $n ) ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $c['invalid'] ) : ?>
			<p style="color:#a40e26"><?php echo esc_html( $c['invalid'] ); ?> row<?php echo $c['invalid'] === 1 ? '' : 's'; ?> ha<?php echo $c['invalid'] === 1 ? 's' : 've'; ?> no usable email address and will be skipped - fix the file and import again if they matter.</p>
		<?php endif; ?>
		<?php if ( $c['repeat'] ) : ?>
			<p style="color:#7a5a00">Where an email appears more than once in the file, the last row is used.</p>
		<?php endif; ?>
		<?php if ( $c['update'] ) : ?>
			<p style="color:#0b5cad">For people already on the list, only cells with a value are applied - a blank cell never erases what the list already holds. The old value is shown struck through.</p>
		<?php endif; ?>

		<form method="post" id="boh-inv-confirm">
			<?php wp_nonce_field( 'boh_invitations_import_confirm' ); ?>
			<input type="hidden" name="boh_invitations_action" value="bulk_import_confirm">
			<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">

			<p style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
				<button type="submit" class="button button-primary" id="boh-inv-confirm-btn" <?php disabled( $can === 0 ); ?>>Import <span id="boh-inv-n"><?php echo esc_html( $can ); ?></span> selected</button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG . '-import' ) ); ?>" class="button">Cancel</a>
				<span style="color:#666"><a href="#" id="boh-inv-all">Select all</a> &middot; <a href="#" id="boh-inv-none">Select none</a></span>
			</p>

			<div style="overflow-x:auto">
			<table class="widefat striped" style="font-size:13px">
				<thead><tr>
					<th style="width:28px"></th>
					<th style="width:48px">Line</th>
					<th style="width:120px">Result</th>
					<th>Name</th>
					<th>Email</th>
					<th>Company</th>
					<th>Notes</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $data['rows'] as $i => $r ) :
					[ $label, $fg, $bg ] = $badge[ $r['status'] ];
					$selectable = in_array( $r['status'], [ 'new', 'update' ], true );
					$cell = function ( string $f ) use ( $r ): string {
						$v = esc_html( $r[ $f ] );
						if ( $r['status'] === 'update' && in_array( $f, $r['changes'], true ) ) {
							return '<span style="color:#999;text-decoration:line-through">' . esc_html( $r['existing'][ $f ] ) . '</span> <strong>' . $v . '</strong>';
						}
						if ( $r['status'] === 'update' && $r[ $f ] === '' && $r['existing'][ $f ] !== '' ) {
							return '<span style="color:#999">' . esc_html( $r['existing'][ $f ] ) . '</span> <span style="color:#bbb;font-size:11px">(kept)</span>';
						}
						return $v;
					};
					?>
					<tr style="<?php echo $selectable ? '' : 'color:#888'; ?>">
						<td><?php if ( $selectable ) : ?><input type="checkbox" name="rows[]" value="<?php echo (int) $i; ?>" checked class="boh-inv-row"><?php endif; ?></td>
						<td><?php echo (int) $r['line']; ?></td>
						<td><span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;color:<?php echo esc_attr( $fg ); ?>;background:<?php echo esc_attr( $bg ); ?>"><?php echo esc_html( $label ); ?></span></td>
						<td><?php echo $cell( 'name' ); ?></td>
						<td><?php echo $r['status'] === 'invalid' ? '<span style="color:#a40e26">' . esc_html( $r['raw'] !== '' ? $r['raw'] : '(empty)' ) . '</span>' : esc_html( $r['email'] ); ?></td>
						<td><?php echo $cell( 'company' ); ?></td>
						<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo esc_attr( $r['notes'] ); ?>"><?php echo $cell( 'notes' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<p style="margin-top:16px"><button type="submit" class="button button-primary" <?php disabled( $can === 0 ); ?>>Import selected</button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG . '-import' ) ); ?>" class="button">Cancel</a></p>
		</form>
	</div>
	<script>
	(function () {
		var boxes = document.querySelectorAll('.boh-inv-row'), n = document.getElementById('boh-inv-n'), btn = document.getElementById('boh-inv-confirm-btn');
		function count() { var c = 0; boxes.forEach(function (b) { if (b.checked) c++; }); n.textContent = c; btn.disabled = c === 0; }
		boxes.forEach(function (b) { b.addEventListener('change', count); });
		document.getElementById('boh-inv-all').addEventListener('click', function (e) { e.preventDefault(); boxes.forEach(function (b) { b.checked = true; }); count(); });
		document.getElementById('boh-inv-none').addEventListener('click', function (e) { e.preventDefault(); boxes.forEach(function (b) { b.checked = false; }); count(); });
	})();
	</script>
	<?php
}


// ── Flamingo import view ───────────────────────────────────────
function boh_invitations_render_flamingo() {
	if ( ! current_user_can( BOH_INV_CAP ) ) wp_die( 'nope' );
	global $wpdb;
	$t = boh_invitations_table();
	$notices = [];

	if ( ! post_type_exists( 'flamingo_contact' ) ) {
		echo '<div class="wrap"><h1>Import from Flamingo</h1><div class="notice notice-error"><p>Flamingo plugin isn\'t active. Install and activate it first.</p></div></div>';
		return;
	}

	// Handle import
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['action'] ) && $_POST['action'] === 'import_flamingo' ) {
		check_admin_referer( 'boh_invitations_flamingo' );
		$ids = array_map( 'intval', (array) ( $_POST['contact_ids'] ?? [] ) );
		$added = $updated = $skipped = 0;
		foreach ( $ids as $cid ) {
			$c = get_post( $cid );
			if ( ! $c || $c->post_type !== 'flamingo_contact' ) continue;
			// Flamingo stores email as post_title, name in _name meta (or vice-versa depending on version)
			$email = sanitize_email( get_post_meta( $c->ID, '_email', true ) ?: $c->post_title );
			if ( ! $email || ! is_email( $email ) ) { $skipped++; continue; }
			$name    = sanitize_text_field( get_post_meta( $c->ID, '_name', true ) ?: '' );
			$company = sanitize_text_field( get_post_meta( $c->ID, '_company', true ) ?: '' );

			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $t WHERE email = %s", $email ) );
			if ( $existing ) {
				$wpdb->update( $t, [
					'name'       => $name ?: $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $t WHERE id = %d", $existing->id ) ),
					'company'    => $company ?: $wpdb->get_var( $wpdb->prepare( "SELECT company FROM $t WHERE id = %d", $existing->id ) ),
					'updated_at' => current_time( 'mysql', true ),
				], [ 'id' => $existing->id ] );
				$updated++;
			} else {
				$wpdb->insert( $t, [
					'name'       => $name,
					'email'      => $email,
					'company'    => $company,
					'notes'      => 'Imported from Flamingo',
					'created_at' => current_time( 'mysql', true ),
					'updated_at' => current_time( 'mysql', true ),
				] );
				$added++;
			}
		}
		$notices[] = [ 'success', "Imported {$added} new, updated {$updated} existing" . ( $skipped ? ", skipped {$skipped} (invalid email)" : '' ) . '.' ];
	}

	// Filter: hide already-imported by default
	$hide_existing = ! isset( $_GET['show_all'] );
	$search = sanitize_text_field( $_GET['s'] ?? '' );
	$per_page = 100;
	$paged = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

	$args = [
		'post_type'      => 'flamingo_contact',
		'post_status'    => 'any',
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		'orderby'        => 'title',
		'order'          => 'ASC',
	];
	if ( $search ) $args['s'] = $search;
	$q = new WP_Query( $args );

	// Preload existing emails to mark rows already imported
	$existing_emails = array_flip( $wpdb->get_col( "SELECT email FROM $t" ) );

	?>
	<div class="wrap">
		<h1>Import from Flamingo</h1>
		<p>These are the contacts Flamingo has collected from every RSVP / Sponsorship / Contact form submission. Pick who you want to add to your invitee list; duplicates (by email) are updated in place.</p>

		<?php foreach ( $notices as [$type, $msg] ) : ?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endforeach; ?>

		<form method="get" style="margin:16px 0">
			<input type="hidden" name="page" value="<?php echo BOH_INV_MENU_SLUG; ?>-flamingo">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search name or email">
			<button class="button">Search</button>
			<?php if ( $hide_existing ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( 'show_all', 1 ) ); ?>">Show already-imported too</a>
			<?php else : ?>
				<a class="button" href="<?php echo esc_url( remove_query_arg( 'show_all' ) ); ?>">Hide already-imported</a>
			<?php endif; ?>
		</form>

		<form method="post">
			<?php wp_nonce_field( 'boh_invitations_flamingo' ); ?>
			<input type="hidden" name="action" value="import_flamingo">
			<div class="tablenav top">
				<button class="button button-primary">Import selected</button>
				<span style="margin-left:12px;color:#666"><?php echo (int) $q->found_posts; ?> Flamingo contact(s) total</span>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('input[name=&quot;contact_ids[]&quot;]:not(:disabled)').forEach(c=>c.checked=this.checked)"></td>
						<th>Name</th>
						<th>Email</th>
						<th style="width:120px">Status</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $q->have_posts() ) : ?>
						<tr><td colspan="4" style="padding:32px;text-align:center;color:#666">No Flamingo contacts yet. As people submit the RSVP / Contact / Sponsorship forms, they'll appear here automatically.</td></tr>
					<?php endif; ?>
					<?php while ( $q->have_posts() ) : $q->the_post();
						$c = get_post();
						$email = get_post_meta( $c->ID, '_email', true ) ?: $c->post_title;
						$name  = get_post_meta( $c->ID, '_name', true ) ?: '';
						$already = isset( $existing_emails[ strtolower( $email ) ] ) || isset( $existing_emails[ $email ] );
						if ( $hide_existing && $already ) continue;
					?>
					<tr>
						<th class="check-column"><input type="checkbox" name="contact_ids[]" value="<?php echo (int) $c->ID; ?>" <?php disabled( $already ); ?>></th>
						<td><strong><?php echo esc_html( $name ?: '-' ); ?></strong></td>
						<td><?php echo esc_html( $email ); ?></td>
						<td>
							<?php if ( $already ) : ?>
								<span style="color:#0a7d0a;font-weight:600">✓ Already imported</span>
							<?php else : ?>
								<span style="color:#666">Not yet</span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endwhile; wp_reset_postdata(); ?>
				</tbody>
			</table>

			<?php
			$total_pages = (int) $q->max_num_pages;
			if ( $total_pages > 1 ) {
				echo '<div class="tablenav bottom"><div class="tablenav-pages">';
				echo paginate_links( [
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $total_pages,
					'prev_text' => '‹',
					'next_text' => '›',
				] );
				echo '</div></div>';
			}
			?>

			<div class="tablenav bottom">
				<button class="button button-primary">Import selected</button>
			</div>
		</form>
	</div>
	<?php
}

// ── Templates view ─────────────────────────────────────────────
function boh_invitations_render_templates() {
	if ( ! current_user_can( BOH_INV_CAP ) ) wp_die( 'nope' );
	$templates = get_option( BOH_INV_OPT_TEMPLATES, [] );
	$notices = [];

	if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
		check_admin_referer( 'boh_invitations_tpl' );
		foreach ( [ 'invitation_subject', 'invitation_body', 'reminder_subject', 'reminder_body' ] as $k ) {
			$templates[ $k ] = wp_unslash( $_POST[ $k ] ?? '' );
		}
		update_option( BOH_INV_OPT_TEMPLATES, $templates );
		$notices[] = [ 'success', 'Templates saved.' ];
	}

	// Build a preview using a real invitee, else a demo one.
	global $wpdb;
	$sample_id = isset( $_GET['preview_id'] ) ? (int) $_GET['preview_id'] : 0;
	$sample = $sample_id
		? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . boh_invitations_table() . " WHERE id = %d", $sample_id ) )
		: $wpdb->get_row( "SELECT * FROM " . boh_invitations_table() . " ORDER BY id ASC LIMIT 1" );
	if ( ! $sample ) {
		$sample = (object) [
			'id'      => 0,
			'name'    => 'Sarah Chen',
			'email'   => 'sarah@example.com',
			'company' => 'Example Ltd',
		];
	}
	$invitation_preview = boh_invitations_render_email( 'invitation', $sample );
	$reminder_preview   = boh_invitations_render_email( 'reminder',   $sample );

	// Sender info (what will show in the recipient's inbox)
	$from_addr = defined( 'BOH_MAIL_FROM' )      ? BOH_MAIL_FROM      : '(fallback wp_mail)';
	$from_name = defined( 'BOH_MAIL_FROM_NAME' ) ? BOH_MAIL_FROM_NAME : get_bloginfo( 'name' );
	$reply_to  = defined( 'BOH_MAIL_REPLY_TO' )  ? BOH_MAIL_REPLY_TO  : $from_addr;

	// Pick-invitee dropdown for the preview
	$candidates = $wpdb->get_results( "SELECT id, name, email FROM " . boh_invitations_table() . " ORDER BY name ASC LIMIT 200" );

	?>
	<div class="wrap">
		<h1>Email Templates</h1>
		<?php foreach ( $notices as [$type, $msg] ) : ?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endforeach; ?>

		<p>Available placeholders: <code>{{name}}</code> · <code>{{first_name}}</code> · <code>{{email}}</code> · <code>{{company}}</code> · <code>{{rsvp_url}}</code></p>

		<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:1400px">
			<form method="post" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px">
				<?php wp_nonce_field( 'boh_invitations_tpl' ); ?>

				<h2 style="margin-top:0">Invitation</h2>
				<p><label>Subject<br><input type="text" name="invitation_subject" value="<?php echo esc_attr( $templates['invitation_subject'] ?? '' ); ?>" style="width:100%"></label></p>
				<p><label>Body<br><textarea name="invitation_body" rows="16" style="width:100%;font-family:monospace"><?php echo esc_textarea( $templates['invitation_body'] ?? '' ); ?></textarea></label></p>

				<h2>Reminder</h2>
				<p><label>Subject<br><input type="text" name="reminder_subject" value="<?php echo esc_attr( $templates['reminder_subject'] ?? '' ); ?>" style="width:100%"></label></p>
				<p><label>Body<br><textarea name="reminder_body" rows="12" style="width:100%;font-family:monospace"><?php echo esc_textarea( $templates['reminder_body'] ?? '' ); ?></textarea></label></p>

				<p><button class="button button-primary">Save templates</button></p>
			</form>

			<div>
				<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;margin-bottom:20px;position:sticky;top:32px">
					<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
						<h2 style="margin:0">Preview</h2>
						<form method="get" style="margin:0">
							<input type="hidden" name="page" value="<?php echo BOH_INV_MENU_SLUG; ?>-templates">
							<label style="font-size:12px;color:#666">Preview as:
								<select name="preview_id" onchange="this.form.submit()">
									<?php foreach ( $candidates as $c ) : ?>
										<option value="<?php echo (int) $c->id; ?>" <?php selected( $sample->id, $c->id ); ?>>
											<?php echo esc_html( ( $c->name ?: '-' ) . ' <' . $c->email . '>' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>
						</form>
					</div>

					<?php foreach ( [
						[ 'invitation', 'Invitation email', $invitation_preview ],
						[ 'reminder',   'Reminder email',   $reminder_preview ],
					] as [ $key, $label, $preview ] ) : ?>
					<div style="border:1px solid #e0e0e0;border-radius:4px;overflow:hidden;margin-bottom:20px">
						<div style="background:#f6f7f7;padding:10px 14px;border-bottom:1px solid #e0e0e0;font-size:12px;color:#666">
							<div><strong style="color:#1d2327"><?php echo esc_html( $label ); ?></strong></div>
							<div style="margin-top:6px">From: <strong><?php echo esc_html( $from_name ); ?></strong> &lt;<?php echo esc_html( $from_addr ); ?>&gt;</div>
							<div>Reply-To: <?php echo esc_html( $reply_to ); ?></div>
							<div>To: <?php echo esc_html( ( $sample->name ? $sample->name . ' <' : '<' ) . $sample->email . '>' ); ?></div>
							<div style="margin-top:6px">Subject: <strong style="color:#1d2327"><?php echo esc_html( $preview['subject'] ); ?></strong></div>
						</div>
						<div style="padding:16px 18px;white-space:pre-wrap;line-height:1.55;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:14px;color:#222;background:#fff;max-height:520px;overflow:auto"><?php
							// Render {{rsvp_url}} as a real clickable link in the preview
							$rendered = esc_html( $preview['body'] );
							$rendered = preg_replace(
								'#(https?://[^\s<]+)#',
								'<a href="$1" target="_blank" rel="noopener" style="color:#d01482">$1</a>',
								$rendered
							);
							echo $rendered;
						?></div>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
	<?php
}

// ── Settings view ──────────────────────────────────────────────
function boh_invitations_render_settings() {
	if ( ! current_user_can( BOH_INV_CAP ) ) wp_die( 'nope' );
	$limits = get_option( BOH_INV_OPT_LIMITS );
	$notices = [];

	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['boh_inv_rate'] ) ) {
		check_admin_referer( 'boh_invitations_settings' );
		$limits['per_day']   = max( 1, (int) ( $_POST['per_day']   ?? 250 ) );
		$limits['per_batch'] = max( 1, (int) ( $_POST['per_batch'] ?? 15 ) );
		update_option( BOH_INV_OPT_LIMITS, $limits );
		$notices[] = [ 'success', 'Settings saved.' ];
	}

	// Switching off needs no ceremony - it is a stop button. Switching on
	// asks you to tick a box first, because the last time this went wrong it
	// went wrong 250 times.
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['boh_inv_stop'] ) ) {
		check_admin_referer( 'boh_invitations_settings' );
		boh_invitations_set_sending( false );
		$notices[] = [ 'success', 'Automatic sending is OFF. Nothing further will be emailed.' ];
	}
	// Resending: clear the "invited" stamp on everyone who has not replied, so
	// the queue picks them up again. People who have already RSVP'd are left
	// alone - they do not need asking twice.
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['boh_inv_requeue'] ) ) {
		check_admin_referer( 'boh_invitations_settings' );
		if ( empty( $_POST['boh_inv_requeue_confirm'] ) ) {
			$notices[] = [ 'error', 'Tick the confirmation box first - nothing was changed.' ];
		} else {
			global $wpdb;
			$t = boh_invitations_table();
			$n = (int) $wpdb->query( "UPDATE $t SET invitation_sent_at = NULL, reminder_sent_at = NULL, updated_at = UTC_TIMESTAMP()
				WHERE responded_at IS NULL AND invitation_sent_at IS NOT NULL" );
			$notices[] = [ 'success', "{$n} people are queued to be invited again. They will go out once sending is on." ];
		}
	}

	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['boh_inv_start'] ) ) {
		check_admin_referer( 'boh_invitations_settings' );
		if ( empty( $_POST['boh_inv_confirm'] ) ) {
			$notices[] = [ 'error', 'Tick the confirmation box first - nothing was turned on.' ];
		} else {
			boh_invitations_set_sending( true );
			$notices[] = [ 'success', 'Automatic sending is ON. The queue will start within a minute.' ];
		}
	}
	$on      = boh_invitations_sending_enabled();
	$locked  = boh_invitations_sending_locked();
	$counts  = boh_invitations_counts();
	$waiting = (int) $counts['not_sent'];
	$per_day = max( 1, (int) ( $limits['per_day'] ?? 250 ) );
	$days    = $waiting > 0 ? (int) ceil( $waiting / $per_day ) : 0;
	$log     = array_reverse( (array) get_option( BOH_INV_OPT_SENDLOG, [] ) );

	// Progress, in the numbers people actually ask about.
	global $wpdb;
	$t        = boh_invitations_table();
	$sent     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE invitation_sent_at IS NOT NULL" );
	$total    = (int) $counts['total'];
	$resp     = (int) $counts['responded'];
	$today    = (int) boh_invitations_send_count_today();
	$last     = $wpdb->get_var( "SELECT MAX(invitation_sent_at) FROM $t" );
	$requeue  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE responded_at IS NULL AND invitation_sent_at IS NOT NULL" );
	$next     = wp_next_scheduled( BOH_INV_CRON_HOOK );
	$pct      = $total ? (int) round( 100 * $sent / $total ) : 0;
	$blocked  = (array) get_option( 'boh_inv_blocked_log', [] );
	$lastblk  = $blocked ? end( $blocked ) : null;
	?>
	<?php if ( $on && $waiting > 0 ) : ?>
		<meta http-equiv="refresh" content="30">
	<?php endif; ?>
	<div class="wrap">
		<h1>Invitations Settings</h1>

		<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 24px;max-width:700px;margin-bottom:24px">
			<h2 style="margin-top:0">Progress</h2>
			<div style="height:14px;background:#f0f0f1;border-radius:7px;overflow:hidden;margin:6px 0 10px">
				<div style="height:100%;width:<?php echo $pct; ?>%;background:#d01482;border-radius:7px"></div>
			</div>
			<p style="margin:0 0 14px;font-size:15px"><strong><?php echo number_format( $sent ); ?></strong> of
				<strong><?php echo number_format( $total ); ?></strong> invited (<?php echo $pct; ?>%) &middot;
				<strong><?php echo number_format( $waiting ); ?></strong> still to go &middot;
				<strong><?php echo number_format( $resp ); ?></strong> have replied</p>
			<table class="widefat striped" style="max-width:520px">
				<tbody>
					<tr><td>Sent today</td><td><?php echo number_format( $today ); ?> of <?php echo number_format( $per_day ); ?> allowed</td></tr>
					<tr><td>Last one went out</td><td><?php echo $last ? esc_html( get_date_from_gmt( $last, 'j M Y, g:i a' ) ) . ' (site time)' : 'never'; ?></td></tr>
					<tr><td>Queue</td><td><?php echo $on ? ( $next ? 'next batch in ' . max( 0, (int) ceil( ( $next - time() ) / 60 ) ) . ' min' : 'starting' ) : 'stopped'; ?></td></tr>
					<tr><td>At this rate</td><td><?php echo $waiting > 0 ? ( $days <= 1 ? 'done today' : 'about ' . (int) $days . ' days' ) : 'nothing waiting'; ?></td></tr>
					<?php if ( $lastblk ) : ?>
					<tr><td>Last refused send</td><td><?php echo esc_html( ( $lastblk['at'] ?? '?' ) . ' UTC to ' . ( $lastblk['to'] ?? '?' ) ); ?> <span style="color:#666">(sending was off)</span></td></tr>
					<?php endif; ?>
				</tbody>
			</table>
			<?php if ( $on && $waiting > 0 ) : ?>
				<p style="margin:12px 0 0;color:#666;font-size:12px">This page refreshes itself every 30 seconds while sending is on.</p>
			<?php endif; ?>
			<p style="margin:10px 0 0;color:#666;font-size:13px">Per person: the <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG ) ); ?>">All Invitees</a> list shows exactly when each invitation went out and who has replied.</p>
		</div>

		<?php if ( $requeue > 0 ) : ?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px 24px;max-width:700px;margin-bottom:24px">
			<h2 style="margin-top:0">Send again</h2>
			<p><strong><?php echo number_format( $requeue ); ?></strong> people were invited earlier but have not replied. Queuing them again puts them
			   back in line behind the <?php echo number_format( $waiting ); ?> who have never been contacted, and the
			   <?php echo number_format( $resp ); ?> who already replied are left alone.</p>
			<form method="post">
				<?php wp_nonce_field( 'boh_invitations_settings' ); ?>
				<p style="margin:0 0 12px">
					<label style="display:flex;gap:8px;align-items:flex-start;max-width:56ch">
						<input type="checkbox" name="boh_inv_requeue_confirm" value="1" style="margin-top:3px">
						<span>Yes, invite these <strong><?php echo number_format( $requeue ); ?></strong> people again.</span>
					</label>
				</p>
				<button name="boh_inv_requeue" value="1" class="button button-secondary">Queue them again</button>
			</form>
		</div>
		<?php endif; ?>
		<?php foreach ( $notices as [$type, $msg] ) : ?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endforeach; ?>

		<div style="background:#fff;border:1px solid #ddd;border-left:6px solid <?php echo $on ? '#d63638' : '#00a32a'; ?>;border-radius:6px;padding:20px 24px;max-width:700px;margin-bottom:24px">
			<h2 style="margin-top:0">Automatic sending is
				<span style="color:<?php echo $on ? '#d63638' : '#00a32a'; ?>"><?php echo $on ? 'ON' : 'OFF'; ?></span>
			</h2>

			<?php if ( $on ) : ?>
				<p><strong><?php echo number_format( $waiting ); ?></strong> people on the list have never been invited.
				   While this is on they will be emailed automatically, up to <?php echo number_format( $per_day ); ?> a day
				   <?php if ( $days > 1 ) : ?>&mdash; about <?php echo (int) $days; ?> days to work through them<?php endif; ?>.</p>
			<?php else : ?>
				<p>Nothing is being emailed. The queue is not running, and the Send buttons on the invitee list
				   will refuse. <strong><?php echo number_format( $waiting ); ?></strong> people have never been contacted.</p>
			<?php endif; ?>

			<?php if ( $locked ) : ?>
				<p style="background:#f6f7f7;border-left:4px solid #72aee6;padding:10px 14px;margin:16px 0">
					This switch is currently overridden in <code>wp-config.php</code>
					(<code>BOH_INV_SENDING</code> is defined as <code><?php echo BOH_INV_SENDING ? 'true' : 'false'; ?></code>),
					so the buttons below will not change anything until that line is removed.
				</p>
			<?php endif; ?>

			<form method="post" style="margin-top:18px">
				<?php wp_nonce_field( 'boh_invitations_settings' ); ?>
				<?php if ( $on ) : ?>
					<button name="boh_inv_stop" value="1" class="button button-primary button-large"
					        style="background:#d63638;border-color:#d63638">Stop sending now</button>
					<span style="color:#666;margin-left:10px">Takes effect immediately.</span>
				<?php else : ?>
					<p style="margin:0 0 12px">
						<label style="display:flex;gap:8px;align-items:flex-start;max-width:56ch">
							<input type="checkbox" name="boh_inv_confirm" value="1" style="margin-top:3px">
							<span>I want <strong><?php echo number_format( $waiting ); ?></strong> people
							      to start receiving invitation emails.</span>
						</label>
					</p>
					<button name="boh_inv_start" value="1" class="button button-primary button-large">Turn sending on</button>
				<?php endif; ?>
			</form>

			<?php if ( $log ) : ?>
				<p style="margin:18px 0 6px;color:#666;font-size:12px;text-transform:uppercase;letter-spacing:.08em">Recent changes</p>
				<ul style="margin:0;color:#666;font-size:13px">
					<?php foreach ( array_slice( $log, 0, 5 ) as $entry ) : ?>
						<li>Turned <strong><?php echo esc_html( $entry['state'] ?? '?' ); ?></strong>
							by <?php echo esc_html( $entry['who'] ?? '?' ); ?>
							on <?php echo esc_html( $entry['at'] ?? '?' ); ?> UTC</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<form method="post" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px;max-width:700px">
			<?php wp_nonce_field( 'boh_invitations_settings' ); ?>
			<input type="hidden" name="boh_inv_rate" value="1">
			<h2 style="margin-top:0">Sending rate</h2>
			<p>Brevo's free tier allows 300/day. Keep <strong>per_day</strong> under that to avoid hitting the throttle.</p>
			<table class="form-table">
				<tr>
					<th><label for="per_day">Emails per day</label></th>
					<td><input id="per_day" name="per_day" type="number" min="1" max="10000" value="<?php echo (int) ( $limits['per_day'] ?? 250 ); ?>"> <span style="color:#666">Currently used today: <?php echo (int) $today; ?></span></td>
				</tr>
				<tr>
					<th><label for="per_batch">Emails per cron batch</label></th>
					<td><input id="per_batch" name="per_batch" type="number" min="1" max="500" value="<?php echo (int) ( $limits['per_batch'] ?? 15 ); ?>"> <span style="color:#666">Cron runs every 10 minutes.</span></td>
				</tr>
			</table>
			<p><button class="button button-primary">Save settings</button></p>
		</form>

		<p style="margin-top:24px;color:#666">Bulk actions in the list view send immediately (respecting <em>per_day</em>). The cron drips queued invitations without you clicking anything.</p>
	</div>
	<?php
}
