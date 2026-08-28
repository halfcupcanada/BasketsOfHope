<?php
/**
 * Plugin Name: BoH forward-the-invitation
 * Description: Lets someone who has just RSVPed forward the invitation to
 *              friends from the site itself, instead of a mailto: link that
 *              opens whatever mail client the browser happens to guess at.
 *              Each friend is recorded as a lead marked with who invited
 *              them.
 *
 *              This is a public endpoint that sends email, so it is treated
 *              as one: nonce, honeypot, per-IP throttles, a hard cap on
 *              recipients, and a fixed template — the sender supplies names
 *              and an optional short note, never the body of the message.
 */

defined( 'ABSPATH' ) || exit;

const BOH_REFER_MAX_RECIPIENTS = 5;    // per submission
const BOH_REFER_MAX_PER_HOUR   = 3;    // submissions per IP
const BOH_REFER_MAX_PER_DAY    = 20;   // recipients per IP
const BOH_REFER_NOTE_MAX       = 300;  // characters

/* ── Storage ────────────────────────────────────────────────────────── */

add_action( 'plugins_loaded', function () {
	global $wpdb;
	if ( get_option( 'boh_refer_schema' ) === '1' ) {
		return;
	}
	$table = $wpdb->prefix . 'boh_invitees';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return; // invitations plugin has not installed its table yet
	}
	if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'referred_by'" ) ) {
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN referred_by VARCHAR(255) NOT NULL DEFAULT ''" );
	}
	update_option( 'boh_refer_schema', '1', false );
}, 11 );

/* ── The drafted message ────────────────────────────────────────────── */

/**
 * The email that gets sent. Deliberately not composed by the browser: the
 * visitor supplies a name and an optional note, and nothing else reaches the
 * body, so this cannot be used to post arbitrary mail through the site.
 */
function boh_refer_message( string $from_name, string $note ): array {
	$when  = defined( 'BOH_EVENT_ISO' ) ? wp_date( 'l, F j, Y \a\t g:i a', strtotime( BOH_EVENT_ISO ) ) : '';
	$where = defined( 'BOH_EVENT_LOC' ) ? BOH_EVENT_LOC : '';
	$rsvp  = home_url( '/rsvp/' );

	$subject = $from_name
		? sprintf( '%s thought you would like this — Rohit\'s Baskets of Hope', $from_name )
		: 'An invitation — Rohit\'s Baskets of Hope';

	$lines = [];
	$lines[] = $from_name
		? sprintf( 'Hi — %s has just RSVPed for Rohit\'s Baskets of Hope and thought you might like to come along.', $from_name )
		: 'Hi — someone thought you might like to come along to Rohit\'s Baskets of Hope.';
	$lines[] = '';
	if ( $note !== '' ) {
		$lines[] = '"' . $note . '"';
		$lines[] = '';
	}
	$lines[] = 'It is an evening of community and giving in support of WIN House. Guests bring comfort items that are packed into baskets for women and families rebuilding after violence.';
	$lines[] = '';
	if ( $when )  { $lines[] = 'When:  ' . $when; }
	if ( $where ) { $lines[] = 'Where: ' . $where; }
	$lines[] = '';
	$lines[] = 'RSVP here: ' . $rsvp;
	$lines[] = '';
	$lines[] = 'Rohit\'s Baskets of Hope';
	$lines[] = home_url( '/' );

	return [ 'subject' => $subject, 'body' => implode( "\n", $lines ) ];
}

/* ── Endpoint ───────────────────────────────────────────────────────── */

add_action( 'rest_api_init', function () {
	register_rest_route( 'boh/v1', '/refer', [
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // public by design; guarded below
		'callback'            => 'boh_refer_handle',
	] );
} );

function boh_refer_client_ip(): string {
	return (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
}

function boh_refer_handle( WP_REST_Request $req ) {
	// A nonce ties the request to a page we served, which stops the simplest
	// scripted abuse. It is not a permission check.
	if ( ! wp_verify_nonce( (string) $req->get_param( 'nonce' ), 'wp_rest' ) ) {
		return new WP_REST_Response( [ 'ok' => false, 'error' => 'Please reload the page and try again.' ], 400 );
	}
	// Honeypot: a real person never fills this in.
	if ( trim( (string) $req->get_param( 'website' ) ) !== '' ) {
		return new WP_REST_Response( [ 'ok' => true, 'sent' => 0 ], 200 );
	}

	$ip        = boh_refer_client_ip();
	$hour_key  = 'boh_refer_h_' . md5( $ip );
	$day_key   = 'boh_refer_d_' . md5( $ip );
	$this_hour = (int) get_transient( $hour_key );
	$today     = (int) get_transient( $day_key );

	if ( $this_hour >= BOH_REFER_MAX_PER_HOUR ) {
		return new WP_REST_Response( [ 'ok' => false, 'error' => 'That is a few invitations in a short time. Please try again a little later.' ], 429 );
	}

	$from_name  = sanitize_text_field( (string) $req->get_param( 'from_name' ) );
	$from_email = sanitize_email( (string) $req->get_param( 'from_email' ) );
	$note       = sanitize_textarea_field( (string) $req->get_param( 'note' ) );
	if ( mb_strlen( $note ) > BOH_REFER_NOTE_MAX ) {
		$note = mb_substr( $note, 0, BOH_REFER_NOTE_MAX );
	}

	$raw = (array) $req->get_param( 'emails' );
	$emails = [];
	foreach ( $raw as $one ) {
		$one = sanitize_email( trim( (string) $one ) );
		if ( $one && is_email( $one ) && ! in_array( $one, $emails, true ) ) {
			$emails[] = $one;
		}
	}
	if ( ! $emails ) {
		return new WP_REST_Response( [ 'ok' => false, 'error' => 'Please add at least one valid email address.' ], 400 );
	}
	$emails = array_slice( $emails, 0, BOH_REFER_MAX_RECIPIENTS );

	if ( $today + count( $emails ) > BOH_REFER_MAX_PER_DAY ) {
		return new WP_REST_Response( [ 'ok' => false, 'error' => 'Daily invitation limit reached. Please try again tomorrow.' ], 429 );
	}

	$msg     = boh_refer_message( $from_name, $note );
	$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
	if ( $from_email && is_email( $from_email ) ) {
		// Reply-To, not From: sending as the visitor's address would fail SPF
		// and land the whole batch in spam.
		$headers[] = 'Reply-To: ' . $from_email;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'boh_invitees';
	$has_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	$now = current_time( 'mysql', true );
	$sent = 0;

	foreach ( $emails as $to ) {
		$ok = wp_mail( $to, $msg['subject'], $msg['body'], $headers );
		if ( ! $ok ) {
			continue;
		}
		$sent++;

		if ( ! $has_table ) {
			continue;
		}
		$referrer = $from_name && $from_email ? $from_name . ' <' . $from_email . '>' : ( $from_email ?: $from_name );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $to ) );
		if ( $exists ) {
			// Already known — record who referred them if nobody has yet,
			// but never overwrite an existing relationship or their reply.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET referred_by = %s, updated_at = %s WHERE id = %d AND referred_by = ''",
				$referrer, $now, $exists
			) );
			continue;
		}
		$wpdb->insert( $table, [
			'name'        => '',
			'email'       => $to,
			'notes'       => $note,
			'source'      => 'referral',
			'referred_by' => $referrer,
			'created_at'  => $now,
			'updated_at'  => $now,
		] );
	}

	set_transient( $hour_key, $this_hour + 1, HOUR_IN_SECONDS );
	set_transient( $day_key, $today + $sent, DAY_IN_SECONDS );

	if ( ! $sent ) {
		return new WP_REST_Response( [ 'ok' => false, 'error' => 'The invitations could not be sent just now. Please try again shortly.' ], 500 );
	}
	return new WP_REST_Response( [ 'ok' => true, 'sent' => $sent ], 200 );
}

/* ── The modal ──────────────────────────────────────────────────────── */

add_action( 'wp_footer', function () {
	if ( ! is_page( 'rsvp' ) ) {
		return;
	}
	$msg = boh_refer_message( '', '' );
	?>
	<div class="boh-refer" hidden role="dialog" aria-modal="true" aria-labelledby="boh-refer-title">
	  <div class="boh-refer__panel">
	    <button type="button" class="boh-refer__close" aria-label="Close">&times;</button>
	    <h2 class="boh-refer__title" id="boh-refer-title">Forward the invitation</h2>
	    <p class="boh-refer__lede">We&rsquo;ll send this from Baskets of Hope, with your name on it and your address for replies.</p>

	    <form class="boh-refer__form" novalidate>
	      <label class="boh-refer__label" for="boh-refer-emails">Your friends&rsquo; email addresses</label>
	      <textarea id="boh-refer-emails" rows="3" placeholder="one per line, or separated by commas"></textarea>
	      <p class="boh-refer__hint">Up to <?php echo (int) BOH_REFER_MAX_RECIPIENTS; ?> at a time.</p>

	      <label class="boh-refer__label" for="boh-refer-note">Add a line of your own <span>(optional)</span></label>
	      <textarea id="boh-refer-note" rows="2" maxlength="<?php echo (int) BOH_REFER_NOTE_MAX; ?>" placeholder="Come with me?"></textarea>

	      <details class="boh-refer__preview">
	        <summary>See what they&rsquo;ll receive</summary>
	        <div class="boh-refer__draft">
	          <strong class="boh-refer__subject"><?php echo esc_html( $msg['subject'] ); ?></strong>
	          <pre class="boh-refer__body"><?php echo esc_html( $msg['body'] ); ?></pre>
	        </div>
	      </details>

	      <?php // Honeypot — hidden from people, tempting to bots. ?>
	      <div class="boh-refer__hp" aria-hidden="true">
	        <label>Website<input type="text" tabindex="-1" autocomplete="off" id="boh-refer-hp"></label>
	      </div>

	      <p class="boh-refer__msg" role="status" aria-live="polite"></p>
	      <div class="boh-refer__actions">
	        <button type="submit" class="boh-refer__send">Send invitations</button>
	        <button type="button" class="boh-refer__cancel">Cancel</button>
	      </div>
	    </form>
	  </div>
	</div>
	<script>
	(function () {
	  var box = document.querySelector('.boh-refer');
	  if (!box) return;
	  var form = box.querySelector('.boh-refer__form');
	  var emails = box.querySelector('#boh-refer-emails');
	  var note = box.querySelector('#boh-refer-note');
	  var hp = box.querySelector('#boh-refer-hp');
	  var msg = box.querySelector('.boh-refer__msg');
	  var send = box.querySelector('.boh-refer__send');
	  var subjectEl = box.querySelector('.boh-refer__subject');
	  var bodyEl = box.querySelector('.boh-refer__body');
	  var baseSubject = subjectEl.textContent;
	  var baseBody = bodyEl.textContent;
	  var opener = null;

	  var REST = <?php echo wp_json_encode( esc_url_raw( rest_url( 'boh/v1/refer' ) ) ); ?>;
	  var NONCE = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
	  var MAX = <?php echo (int) BOH_REFER_MAX_RECIPIENTS; ?>;

	  function referrer() {
	    return window.BOH_REFERRER || { name: '', email: '' };
	  }

	  // Keep the preview honest: it shows the message as it will actually go
	  // out, with the sender's name and any note already in it.
	  function refreshDraft() {
	    var r = referrer();
	    var s = r.name
	      ? r.name + ' thought you would like this — Rohit\'s Baskets of Hope'
	      : baseSubject;
	    subjectEl.textContent = s;
	    var b = baseBody;
	    if (r.name) {
	      b = b.replace('Hi — someone thought you might like to come along to Rohit\'s Baskets of Hope.',
	                    'Hi — ' + r.name + ' has just RSVPed for Rohit\'s Baskets of Hope and thought you might like to come along.');
	    }
	    var n = note.value.trim();
	    if (n) {
	      b = b.replace(/\n\nIt is an evening/, '\n\n"' + n + '"\n\nIt is an evening');
	    }
	    bodyEl.textContent = b;
	  }
	  note.addEventListener('input', refreshDraft);

	  function open(trigger) {
	    opener = trigger || null;
	    msg.textContent = ''; msg.className = 'boh-refer__msg';
	    refreshDraft();
	    box.hidden = false;
	    document.body.style.overflow = 'hidden';
	    emails.focus();
	  }
	  function close() {
	    box.hidden = true;
	    document.body.style.overflow = '';
	    if (opener) { opener.focus(); opener = null; }
	  }

	  document.addEventListener('click', function (e) {
	    var t = e.target.closest('[data-boh-forward]');
	    if (t) { e.preventDefault(); open(t); return; }
	    if (e.target.closest('.boh-refer__close, .boh-refer__cancel')) { e.preventDefault(); close(); }
	    if (e.target === box) { close(); }
	  });
	  document.addEventListener('keydown', function (e) {
	    if (!box.hidden && e.key === 'Escape') close();
	  });

	  form.addEventListener('submit', function (e) {
	    e.preventDefault();
	    var list = emails.value.split(/[\s,;]+/).map(function (x) { return x.trim(); }).filter(Boolean);
	    if (!list.length) {
	      msg.textContent = 'Add at least one email address.';
	      msg.className = 'boh-refer__msg is-error';
	      return;
	    }
	    if (list.length > MAX) {
	      msg.textContent = 'Please send to no more than ' + MAX + ' people at a time.';
	      msg.className = 'boh-refer__msg is-error';
	      return;
	    }
	    var r = referrer();
	    send.disabled = true;
	    msg.textContent = 'Sending…';
	    msg.className = 'boh-refer__msg';

	    fetch(REST, {
	      method: 'POST',
	      headers: { 'Content-Type': 'application/json' },
	      body: JSON.stringify({
	        nonce: NONCE, emails: list, note: note.value.trim(),
	        from_name: r.name || '', from_email: r.email || '',
	        website: hp.value
	      })
	    })
	    .then(function (res) { return res.json().then(function (d) { return { ok: res.ok, d: d }; }); })
	    .then(function (out) {
	      send.disabled = false;
	      if (out.ok && out.d && out.d.ok) {
	        msg.textContent = out.d.sent === 1 ? 'Invitation sent. Thank you.' : out.d.sent + ' invitations sent. Thank you.';
	        msg.className = 'boh-refer__msg is-ok';
	        emails.value = ''; note.value = '';
	        setTimeout(close, 1800);
	      } else {
	        msg.textContent = (out.d && out.d.error) || 'Something went wrong. Please try again.';
	        msg.className = 'boh-refer__msg is-error';
	      }
	    })
	    .catch(function () {
	      send.disabled = false;
	      msg.textContent = 'Could not reach the server. Please try again.';
	      msg.className = 'boh-refer__msg is-error';
	    });
	  });
	})();
	</script>
	<?php
}, 70 );
