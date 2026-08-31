<?php
/**
 * Plugin Name: BoH email template
 * Description: Wraps every outgoing message in the site's own header and
 *              footer, so an RSVP confirmation looks like it came from the
 *              same people who made the website. Both are edited as rich text
 *              under BoH Content -> Emails.
 *
 * The messages themselves are written as plain text by the code that sends
 * them, which is the right way round: the words stay readable in the source,
 * and the presentation lives in one place. This wraps that text rather than
 * asking every caller to know about HTML.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plain-text original, kept so PHPMailer can carry it as the alternative
 * body. An HTML-only message is a spam signal and unreadable in the handful
 * of clients that still refuse HTML.
 */
$GLOBALS['boh_email_alt_body'] = '';

add_filter( 'wp_mail', 'boh_email_wrap', 100 );

function boh_email_wrap( array $args ): array {
	$message = (string) ( $args['message'] ?? '' );
	if ( trim( $message ) === '' ) {
		return $args;
	}

	$headers = $args['headers'] ?? '';
	$list    = is_array( $headers ) ? $headers : preg_split( '/\r\n|\r|\n/', (string) $headers );
	$list    = array_values( array_filter( array_map( 'trim', (array) $list ), 'strlen' ) );

	$is_html = false;
	foreach ( $list as $h ) {
		// An explicit opt-out, for anything that must go out exactly as written.
		if ( stripos( $h, 'x-boh-template:' ) === 0 && stripos( $h, 'none' ) !== false ) {
			return $args;
		}
		if ( stripos( $h, 'content-type:' ) === 0 && stripos( $h, 'text/html' ) !== false ) {
			$is_html = true;
		}
	}
	if ( ! $is_html && stripos( (string) apply_filters( 'wp_mail_content_type', 'text/plain' ), 'text/html' ) !== false ) {
		$is_html = true;
	}

	// A message that already carries its own document — GiveWP's receipts, for
	// one — is a finished email. Wrapping it would put a page inside a page.
	if ( preg_match( '/<(!doctype|html|body)\b/i', $message ) ) {
		return $args;
	}

	$GLOBALS['boh_email_alt_body'] = $is_html
		? trim( wp_strip_all_tags( $message ) )
		: $message;

	$args['message'] = boh_email_document(
		$is_html ? $message : boh_email_textify( $message ),
		(string) ( $args['subject'] ?? '' )
	);

	// Replace any existing Content-Type rather than adding a second one.
	$kept = [];
	foreach ( $list as $h ) {
		if ( stripos( $h, 'content-type:' ) !== 0 ) {
			$kept[] = $h;
		}
	}
	$kept[]          = 'Content-Type: text/html; charset=UTF-8';
	$args['headers'] = $kept;

	return $args;
}

/**
 * Carry the original wording as the plain-text alternative.
 */
add_action( 'phpmailer_init', function ( $mailer ) {
	if ( ! empty( $GLOBALS['boh_email_alt_body'] ) && $mailer->ContentType === 'text/html' ) {
		$mailer->AltBody = $GLOBALS['boh_email_alt_body'];
	}
	$GLOBALS['boh_email_alt_body'] = '';
}, 100 );

/**
 * Plain text to HTML: escape it, keep the paragraph breaks the author put in,
 * and make the URLs clickable — a bare "RSVP here: https://…" is the one thing
 * people actually need to tap.
 */
function boh_email_textify( string $text ): string {
	$text  = str_replace( [ "\r\n", "\r" ], "\n", $text );
	$parts = preg_split( '/\n{2,}/', trim( $text ) );
	$out   = '';
	foreach ( $parts as $para ) {
		$safe = esc_html( $para );
		$safe = preg_replace(
			'~(https?://[^\s<]+[^\s<.,:;"\')\]])~i',
			'<a href="$1" style="color:#D01482;text-decoration:underline">$1</a>',
			$safe
		);
		$out .= '<p style="margin:0 0 16px">' . nl2br( $safe ) . "</p>\n";
	}
	return $out;
}

/** One place to ask what the template says, defaults included. */
function boh_email_setting( string $key, string $default = '' ): string {
	return function_exists( 'boh_content' ) ? (string) boh_content( $key, $default ) : $default;
}

function boh_email_defaults(): array {
	$site  = get_bloginfo( 'name' );
	$when  = defined( 'BOH_EVENT_ISO' ) ? wp_date( 'l, F j, Y \a\t g:i a', strtotime( BOH_EVENT_ISO ) ) : '';
	$where = defined( 'BOH_EVENT_LOC' ) ? BOH_EVENT_LOC : '';
	$foot  = '<p>' . esc_html( $site ) . ( $when ? ' &middot; ' . esc_html( $when ) : '' ) . '</p>';
	if ( $where ) {
		$foot .= '<p>' . esc_html( $where ) . '</p>';
	}
	$foot .= '<p><a href="' . esc_url( home_url( '/' ) ) . '">boh.halfcup.ca</a></p>';

	return [
		'email.preheader' => '',
		'email.header'    => '',
		'email.footer'    => $foot,
		'email.smallprint' => 'You are receiving this because you asked to hear from ' . $site . '.',
	];
}

/**
 * The wrapper. Tables and inline styles, because email clients in 2026 are
 * still email clients: no flexbox, no grid, and embedded stylesheets that
 * several of them drop on the floor.
 */
function boh_email_document( string $body_html, string $subject = '' ): string {
	$d = boh_email_defaults();

	$logo = function_exists( 'boh_logo_url' ) ? boh_logo_url() : '';
	// Email is read on a metered connection more often than the site is.
	$logo = str_replace( 'boh-logo.png', 'boh-logo-275x300.png', $logo );
	$name   = get_bloginfo( 'name' );
	$pre    = boh_email_setting( 'email.preheader', $d['email.preheader'] );
	$header = boh_email_setting( 'email.header', $d['email.header'] );
	$footer = boh_email_setting( 'email.footer', $d['email.footer'] );
	$small  = boh_email_setting( 'email.smallprint', $d['email.smallprint'] );

	$font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";

	ob_start(); ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $subject !== '' ? $subject : $name ); ?></title>
<style>
  a { color: #D01482; }
  .boh-body p { margin: 0 0 16px; }
  .boh-body p:last-child { margin-bottom: 0; }
  .boh-foot p { margin: 0 0 4px; }
  @media (max-width: 620px) {
    .boh-pad { padding-left: 22px !important; padding-right: 22px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#FDF2F8;<?php echo $font; ?>">
<?php if ( $pre !== '' ) : ?>
<div style="display:none;max-height:0;overflow:hidden;opacity:0"><?php echo esc_html( $pre ); ?></div>
<?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FDF2F8">
  <tr><td align="center" style="padding:28px 12px">

    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px">

      <tr><td class="boh-pad" align="center" style="padding:32px 40px 0">
        <?php if ( $logo ) : ?>
          <img src="<?php echo esc_url( $logo ); ?>" width="76" alt="<?php echo esc_attr( $name ); ?>" style="display:block;width:76px;height:auto;border:0">
        <?php else : ?>
          <div style="<?php echo $font; ?>;font-size:18px;font-weight:700;color:#1F1A24"><?php echo esc_html( $name ); ?></div>
        <?php endif; ?>
        <?php if ( trim( (string) $header ) !== '' ) : ?>
          <div class="boh-body" style="<?php echo $font; ?>;font-size:15px;line-height:1.6;color:#6B6472;padding-top:14px">
            <?php echo wp_kses_post( $header ); ?>
          </div>
        <?php endif; ?>
      </td></tr>

      <tr><td class="boh-pad" style="padding:28px 40px 8px;<?php echo $font; ?>;font-size:16px;line-height:1.65;color:#1F1A24">
        <div class="boh-body"><?php echo $body_html; ?></div>
      </td></tr>

      <?php if ( trim( (string) $footer ) !== '' ) : ?>
      <tr><td class="boh-pad" style="padding:22px 40px 32px">
        <div style="border-top:1px solid #F3E3EC;padding-top:18px"></div>
        <div class="boh-foot" style="<?php echo $font; ?>;font-size:13px;line-height:1.6;color:#6B6472">
          <?php echo wp_kses_post( $footer ); ?>
        </div>
      </td></tr>
      <?php endif; ?>

    </table>

    <?php if ( trim( (string) $small ) !== '' ) : ?>
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px">
      <tr><td align="center" style="padding:16px 24px 4px;<?php echo $font; ?>;font-size:12px;line-height:1.6;color:#9A93A1">
        <?php echo wp_kses_post( $small ); ?>
      </td></tr>
    </table>
    <?php endif; ?>

  </td></tr>
</table>
</body></html>
<?php
	return (string) ob_get_clean();
}

/* ─────────────────────────────────────────────────────────────────────
   The Emails screen's own tools: a preview of the finished message, and
   a test send. A template can only really be judged as mail that has
   arrived in a real client — the preview shows the shape, the test send
   shows the truth.
   ───────────────────────────────────────────────────────────────────── */

add_action( 'boh_content_after_screen_emails', 'boh_email_admin_tools' );

function boh_email_admin_tools(): void {
	$sent = boh_email_handle_test_send();
	$demo = boh_email_document( boh_email_textify( boh_email_sample_text() ), 'A test from the website' );
	?>
	<h2 style="margin:34px 0 6px">Preview</h2>
	<p class="description" style="margin:0 0 12px">A sample message inside the current header and footer. Save your changes first — this shows what is stored, not what is typed.</p>
	<iframe title="Email preview" style="width:100%;max-width:1100px;height:640px;border:1px solid #dcdcde;border-radius:6px;background:#FDF2F8"
	        srcdoc="<?php echo esc_attr( $demo ); ?>"></iframe>

	<h2 style="margin:30px 0 6px">Send yourself a test</h2>
	<?php if ( $sent === true ) : ?>
		<div class="notice notice-success is-dismissible"><p>Test sent. If it does not arrive within a few minutes, check the spam folder.</p></div>
	<?php elseif ( is_string( $sent ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $sent ); ?></p></div>
	<?php endif; ?>
	<form method="post" style="display:flex;align-items:center;gap:10px;max-width:1100px">
		<?php wp_nonce_field( 'boh_email_test' ); ?>
		<input type="email" name="boh_email_test_to" required style="width:320px"
		       value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
		<button type="submit" name="boh_email_test" value="1" class="button">Send a test</button>
	</form>
	<?php
}

/** @return true|string|null true on success, a message on failure, null if not asked. */
function boh_email_handle_test_send() {
	if ( empty( $_POST['boh_email_test'] ) ) {
		return null;
	}
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'boh_email_test' ) ) {
		return 'That request could not be verified. Please try again.';
	}
	$to = sanitize_email( wp_unslash( $_POST['boh_email_test_to'] ?? '' ) );
	if ( ! $to || ! is_email( $to ) ) {
		return 'That does not look like an email address.';
	}
	$ok = wp_mail(
		$to,
		'A test from ' . get_bloginfo( 'name' ),
		boh_email_sample_text(),
		[ 'Content-Type: text/plain; charset=UTF-8' ]
	);
	return $ok ? true : 'The mail server refused the message. Nothing was sent.';
}

/**
 * Written as plain text on purpose: it goes through the same wrapping every
 * real message does, so a preview that looks right means the real thing will.
 */
function boh_email_sample_text(): string {
	$when  = defined( 'BOH_EVENT_ISO' ) ? wp_date( 'l, F j, Y \a\t g:i a', strtotime( BOH_EVENT_ISO ) ) : 'Tuesday, November 3, 2026 at 6:00 pm';
	$where = defined( 'BOH_EVENT_LOC' ) ? BOH_EVENT_LOC : 'Rohit Group Office, 10130 112 St NW, Edmonton';
	return "Dear Rahul,\n\n"
		. "Thank you for reserving your seat at " . get_bloginfo( 'name' ) . ". We cannot wait to share the evening with you.\n\n"
		. "When:  " . $when . "\n"
		. "Where: " . $where . "\n"
		. "Bring: 12 comfort items (or partner with a friend)\n\n"
		. "The full running order is on the website: " . home_url( '/' ) . "\n\n"
		. "Questions? Just reply to this email.\n\n"
		. "With gratitude,\n"
		. get_bloginfo( 'name' );
}
