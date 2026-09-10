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

	// A message that already carries its own document - GiveWP's receipts, for
	// one - is a finished email. Wrapping it would put a page inside a page.
	if ( preg_match( '/<(!doctype|html|body)\b/i', $message ) ) {
		return $args;
	}

	// A subject line is plain text, not markup. The site title is stored with
	// its apostrophe encoded, so anything built from it - Contact Form 7's
	// [_site_title] among them - arrives in the inbox reading "Rohit&#039;s".
	// Decoding here fixes it wherever it came from.
	if ( isset( $args['subject'] ) ) {
		$args['subject'] = html_entity_decode( (string) $args['subject'], ENT_QUOTES, 'UTF-8' );
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
 * The site's name, readable.
 *
 * The stored blogname is "Rohit&#039;s Baskets of Hope" - an encoded
 * apostrophe. Escaping that for HTML gives "Rohit&amp;#039;s", which is what
 * the emails have been printing. Decode before use.
 */
function boh_email_site_name(): string {
	return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
}

/**
 * Brand pink on every link in the header, footer and small print.
 *
 * The document carries `a { color: #D01482 }` in a <style> block, and enough
 * clients drop embedded styles that the footer's own link was arriving in
 * default browser blue. Links typed into the admin screen get the colour
 * inlined here instead, so nobody has to write HTML to stay on brand.
 */
function boh_email_pinkify_links( string $html ): string {
	return (string) preg_replace_callback(
		'~<a\b([^>]*)>~i',
		function ( $m ) {
			$attrs = $m[1];
			if ( preg_match( '~\bstyle\s*=\s*(["\'])(.*?)\1~is', $attrs, $sm ) ) {
				if ( stripos( $sm[2], 'color:' ) !== false ) {
					return $m[0];
				}
				$style = rtrim( trim( $sm[2] ), ';' );
				$style = ( $style === '' ? '' : $style . ';' ) . 'color:#D01482;font-weight:700';
				return '<a' . str_replace( $sm[0], 'style="' . esc_attr( $style ) . '"', $attrs ) . '>';
			}
			return '<a' . $attrs . ' style="color:#D01482;font-weight:700">';
		},
		$html
	);
}

/** The same, in white, for links that sit on the black footer band. */
function boh_email_whiten_links( string $html ): string {
	return (string) preg_replace_callback(
		'~<a\b([^>]*)>~i',
		function ( $m ) {
			$attrs = $m[1];
			if ( preg_match( '~\bstyle\s*=\s*(["\'])(.*?)\1~is', $attrs, $sm ) ) {
				$style = rtrim( trim( preg_replace( '~color\s*:[^;]*;?~i', '', $sm[2] ) ), '; ' );
				$style = ( $style === '' ? '' : $style . ';' ) . 'color:#ffffff;font-weight:700';
				return '<a' . str_replace( $sm[0], 'style="' . esc_attr( $style ) . '"', $attrs ) . '>';
			}
			return '<a' . $attrs . ' style="color:#ffffff;font-weight:700">';
		},
		$html
	);
}

/**
 * A pink button, built the way email buttons have to be built: a table cell
 * carrying the colour so Outlook's Word engine still shows a filled block
 * when it throws away the padding and the rounded corners.
 */
function boh_email_button( string $url, string $label ): string {
	$font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
	return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 20px">'
		. '<tr><td align="center" bgcolor="#D01482" style="border-radius:999px">'
		. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:13px 32px;' . $font
		. ';font-size:15px;font-weight:700;letter-spacing:0.02em;color:#ffffff;text-decoration:none;border-radius:999px">'
		. esc_html( $label ) . '</a>'
		. '</td></tr></table>';
}

/**
 * Plain text to HTML: escape it, keep the paragraph breaks the author put in,
 * and make the links tappable - a bare "RSVP here: https://…" is the one
 * thing people actually need to reach.
 *
 * Two links get treated as what they are rather than as addresses: an RSVP
 * link becomes a button, because a 60-character URL is not an invitation to
 * press anything; and the site's own address reads as its name.
 */
function boh_email_textify( string $text ): string {
	$home  = home_url( '/' );
	$name  = boh_email_site_name();
	$text  = str_replace( [ "\r\n", "\r" ], "\n", $text );
	$parts = preg_split( '/\n{2,}/', trim( $text ) );
	$out   = '';

	foreach ( $parts as $para ) {
		$para = trim( $para );

		// A paragraph ending in the RSVP link becomes the button. Anything in
		// front of it stays, unless it is only there to introduce the link -
		// "RSVP here:" above a button that says RSVP is saying it twice.
		if ( preg_match( '~^(.*?)(https?://[^\s<]*/rsvp/?)$~is', $para, $m ) ) {
			$lead = rtrim( trim( $m[1] ), ":- " );
			if ( $lead !== '' && ! preg_match( '~^(rsvp|rsvp here|reserve (your |my )?(seat|place|spot)s?|you can rsvp( here)?)$~i', $lead ) ) {
				$out .= '<p style="margin:0 0 12px">' . nl2br( esc_html( $lead ) ) . "</p>\n";
			}
			$out .= boh_email_button( $m[2], 'RSVP' );
			continue;
		}

		$safe = esc_html( $para );
		$safe = preg_replace_callback(
			'~(https?://[^\s<]+[^\s<.,:;"\')\]])~i',
			function ( $m ) use ( $home, $name ) {
				$url   = html_entity_decode( $m[1], ENT_QUOTES );
				$label = untrailingslashit( $url ) === untrailingslashit( $home ) ? $name : $url;
				return '<a href="' . esc_url( $url ) . '" style="color:#D01482;text-decoration:underline">'
					. esc_html( $label ) . '</a>';
			},
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
	$site  = boh_email_site_name();
	$when  = defined( 'BOH_EVENT_ISO' ) ? wp_date( 'l, F j, Y \a\t g:i a', strtotime( BOH_EVENT_ISO ) ) : '';
	$where = defined( 'BOH_EVENT_LOC' ) ? BOH_EVENT_LOC : '';
	$foot  = '<p>' . esc_html( $site ) . ( $when ? ' &middot; ' . esc_html( $when ) : '' ) . '</p>';
	if ( $where ) {
		$foot .= '<p>' . esc_html( $where ) . '</p>';
	}
	$foot .= '<p><a href="' . esc_url( home_url( '/' ) ) . '" style="text-decoration:none">'
		. esc_html( boh_email_site_name() ) . '</a></p>';

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
	$name   = boh_email_site_name();
	$pre    = boh_email_setting( 'email.preheader', $d['email.preheader'] );
	$header = boh_email_pinkify_links( boh_email_setting( 'email.header', $d['email.header'] ) );
	// The footer sits on black now, where brand pink on near-black fails
	// contrast; its links go white and keep the weight instead.
	$footer = boh_email_whiten_links( boh_email_setting( 'email.footer', $d['email.footer'] ) );
	$small  = boh_email_pinkify_links( boh_email_setting( 'email.smallprint', $d['email.smallprint'] ) );

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
          <a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="display:inline-block;text-decoration:none;border:0">
            <img src="<?php echo esc_url( $logo ); ?>" width="76" alt="<?php echo esc_attr( $name ); ?>" style="display:block;width:76px;height:auto;border:0">
          </a>
        <?php else : ?>
          <a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="<?php echo $font; ?>;font-size:18px;font-weight:700;color:#1F1A24;text-decoration:none"><?php echo esc_html( $name ); ?></a>
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

      <tr><td style="padding:0 0 8px"></td></tr>

    </table>

    <?php
    /**
     * The site's black footer, in mail.
     *
     * The message used to end on the same white card it started in, with the
     * details in grey under a hairline. This is the footer people have already
     * seen on the site: black ground, the wordmark with "of Hope" in magenta,
     * the details, and the same links.
     *
     * One column, centred, links separated by a middot - a two-column footer
     * needs floats or media queries, and neither survives Outlook.
     */
    $foot_links = [
      'About'   => home_url( '/about/' ),
      'Donate'  => home_url( '/donate/' ),
      'Sponsor' => home_url( '/sponsor/' ),
      'FAQs'    => home_url( '/faqs/' ),
      'Gallery' => home_url( '/gallery/' ),
    ];
    $wordmark = $name;
    $split    = strrpos( $wordmark, ' of ' );
    ?>
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#0A0A0A;border-radius:16px;margin-top:14px">
      <tr><td class="boh-pad" align="center" style="padding:30px 40px 26px">

        <div style="<?php echo $font; ?>;font-size:17px;font-weight:700;color:#ffffff;letter-spacing:-0.01em;padding-bottom:4px">
          <?php if ( $split !== false ) : ?>
            <?php echo esc_html( substr( $wordmark, 0, $split ) ); ?><span style="color:#D01482"><?php echo esc_html( substr( $wordmark, $split ) ); ?></span>
          <?php else : ?>
            <?php echo esc_html( $wordmark ); ?>
          <?php endif; ?>
        </div>

        <?php if ( trim( (string) $footer ) !== '' ) : ?>
        <div class="boh-foot" style="<?php echo $font; ?>;font-size:13px;line-height:1.7;color:rgba(255,255,255,0.72);padding-top:6px">
          <?php echo wp_kses_post( $footer ); ?>
        </div>
        <?php endif; ?>

        <div style="border-top:1px solid rgba(255,255,255,0.14);margin:18px 0 14px;font-size:0;line-height:0">&nbsp;</div>

        <div style="<?php echo $font; ?>;font-size:13px;line-height:1.9;color:rgba(255,255,255,0.72)">
          <?php $first = true; foreach ( $foot_links as $label => $url ) : ?>
            <?php if ( ! $first ) : ?><span style="color:rgba(255,255,255,0.32)">&nbsp;&middot;&nbsp;</span><?php endif; $first = false; ?>
            <a href="<?php echo esc_url( $url ); ?>" style="color:#ffffff;text-decoration:none"><?php echo esc_html( $label ); ?></a>
          <?php endforeach; ?>
        </div>

        <div style="<?php echo $font; ?>;font-size:12px;line-height:1.6;color:rgba(255,255,255,0.45);padding-top:14px">
          &copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> Rohit Group. All rights reserved.
        </div>

      </td></tr>
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
   arrived in a real client - the preview shows the shape, the test send
   shows the truth.
   ───────────────────────────────────────────────────────────────────── */

add_action( 'boh_content_after_screen_emails', 'boh_email_admin_tools' );

function boh_email_admin_tools(): void {
	$sent = boh_email_handle_test_send();
	$demo = boh_email_document( boh_email_textify( boh_email_sample_text() ), 'A test from the website' );
	?>
	<h2 style="margin:34px 0 6px">Preview</h2>
	<p class="description" style="margin:0 0 12px">A sample message inside the current header and footer. Save your changes first - this shows what is stored, not what is typed.</p>
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
		'A test from ' . boh_email_site_name(),
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
		. "Thank you for reserving your seat at " . boh_email_site_name() . ". We cannot wait to share the evening with you.\n\n"
		. "When:  " . $when . "\n"
		. "Where: " . $where . "\n"
		. "Bring: 12 comfort items (or partner with a friend)\n\n"
		. "The full running order is on the website: " . home_url( '/' ) . "\n\n"
		. "Reserve your seat:\n" . home_url( '/rsvp/' ) . "\n\n"
		. "Questions? Just reply to this email.\n\n"
		. "With gratitude,\n"
		. boh_email_site_name();
}
