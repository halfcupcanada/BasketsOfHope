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
				foreach ( $rows as $inv ) {
					if ( boh_invitations_send_email( $inv, 'invitation' ) ) {
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
					if ( boh_invitations_send_email( $inv, 'reminder' ) ) {
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
					if ( boh_invitations_send_email( $inv, 'invitation' ) ) {
						$wpdb->update( $t,
							[ 'invitation_sent_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
							[ 'id' => $new_id ]
						);
						$notices[] = [ 'success', "Added <code>" . esc_html( $email ) . "</code> and sent invitation." ];
					} else {
						$notices[] = [ 'warning', "Added <code>" . esc_html( $email ) . "</code> but invitation email failed to send. Try Bulk Actions from the list." ];
					}
				} else {
					$notices[] = [ 'success', "Added <code>" . esc_html( $email ) . "</code> - will send on next cron tick, or send manually from All Invitees." ];
				}
			}
		}
	}

	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ( $_POST['boh_invitations_action'] ?? '' ) === 'bulk_import' ) {
		check_admin_referer( 'boh_invitations_import' );

		$rows = [];
		// CSV file upload
		if ( ! empty( $_FILES['csv']['tmp_name'] ) && is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			$fh = fopen( $_FILES['csv']['tmp_name'], 'r' );
			if ( $fh ) {
				$header = fgetcsv( $fh );
				$map = [];
				foreach ( (array) $header as $i => $col ) {
					$key = strtolower( trim( (string) $col ) );
					if ( in_array( $key, [ 'name', 'full name', 'contact', 'contact name' ], true ) ) $map['name']    = $i;
					if ( in_array( $key, [ 'email', 'e-mail', 'email address' ], true ) )              $map['email']   = $i;
					if ( in_array( $key, [ 'company', 'organization', 'org' ], true ) )                $map['company'] = $i;
					if ( in_array( $key, [ 'notes', 'note' ], true ) )                                 $map['notes']   = $i;
				}
				if ( ! isset( $map['email'] ) ) {
					$notices[] = [ 'error', 'CSV must have a column named "email" (case-insensitive). Optional columns: name, company, notes.' ];
					fclose( $fh );
				} else {
					while ( ( $line = fgetcsv( $fh ) ) !== false ) {
						$rows[] = [
							'name'    => isset( $map['name'] )    ? (string) ( $line[ $map['name'] ]    ?? '' ) : '',
							'email'   => isset( $map['email'] )   ? (string) ( $line[ $map['email'] ]   ?? '' ) : '',
							'company' => isset( $map['company'] ) ? (string) ( $line[ $map['company'] ] ?? '' ) : '',
							'notes'   => isset( $map['notes'] )   ? (string) ( $line[ $map['notes'] ]   ?? '' ) : '',
						];
					}
					fclose( $fh );
				}
			}
		}
		// Paste area (Name<TAB>Email or Name,Email per line)
		if ( ! empty( $_POST['paste'] ) ) {
			$paste = str_replace( "\r", "", (string) $_POST['paste'] );
			foreach ( explode( "\n", $paste ) as $line ) {
				$line = trim( $line );
				if ( ! $line ) continue;
				$parts = preg_split( '/[\t,;]/', $line, 3 );
				$rows[] = [
					'name'    => trim( $parts[0] ?? '' ),
					'email'   => trim( $parts[1] ?? '' ),
					'company' => trim( $parts[2] ?? '' ),
					'notes'   => '',
				];
			}
		}

		$added = 0; $updated = 0; $skipped = 0;
		foreach ( $rows as $r ) {
			$email = sanitize_email( $r['email'] );
			if ( ! $email || ! is_email( $email ) ) { $skipped++; continue; }
			$name    = sanitize_text_field( $r['name'] );
			$company = sanitize_text_field( $r['company'] );
			$notes   = sanitize_textarea_field( $r['notes'] );
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
				$updated++;
			} else {
				$wpdb->insert( $t, [
					'name'       => $name,
					'email'      => $email,
					'company'    => $company,
					'notes'      => $notes,
					'created_at' => current_time( 'mysql', true ),
					'updated_at' => current_time( 'mysql', true ),
				] );
				$added++;
			}
		}
		if ( $added || $updated || $skipped ) {
			$notices[] = [ 'success', "Added {$added}, updated {$updated}, skipped {$skipped} (invalid email)." ];
		}
	}

	?>
	<div class="wrap">
		<h1>Add Invitees</h1>
		<?php foreach ( $notices as [$type, $msg] ) : ?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo wp_kses_post( $msg ); ?></p></div>
		<?php endforeach; ?>

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
			<p>Upload a <code>.csv</code> file. Required column: <strong>email</strong>. Optional: <strong>name</strong>, <strong>company</strong>, <strong>notes</strong>. Duplicates (matched by email) update in place.</p>
			<p>Example:</p>
			<pre style="background:#f6f7f7;padding:12px;border-radius:4px">name,email,company
Sarah Chen,sarah@example.com,Acme Corp
Jamie Patel,jamie@example.com,Bluebird Labs</pre>
			<p><input type="file" name="csv" accept=".csv"></p>

			<h2>Or paste rows</h2>
			<p>One per line. Format: <code>Name, email@example.com, Company</code></p>
			<p><textarea name="paste" rows="8" style="width:100%;font-family:monospace;font-size:13px" placeholder="Sarah Chen, sarah@example.com, Acme Corp&#10;Jamie Patel, jamie@example.com, Bluebird Labs"></textarea></p>

			<p><button type="submit" class="button button-primary">Import</button></p>
		</form>
	</div>
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

	if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
		check_admin_referer( 'boh_invitations_settings' );
		$limits['per_day']   = max( 1, (int) ( $_POST['per_day']   ?? 250 ) );
		$limits['per_batch'] = max( 1, (int) ( $_POST['per_batch'] ?? 15 ) );
		update_option( BOH_INV_OPT_LIMITS, $limits );
		$notices[] = [ 'success', 'Settings saved.' ];
	}
	$today = boh_invitations_send_count_today();
	?>
	<div class="wrap">
		<h1>Invitations Settings</h1>
		<?php foreach ( $notices as [$type, $msg] ) : ?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endforeach; ?>

		<form method="post" style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:24px;max-width:700px">
			<?php wp_nonce_field( 'boh_invitations_settings' ); ?>
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
