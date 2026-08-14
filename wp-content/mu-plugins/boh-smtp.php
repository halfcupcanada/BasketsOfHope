<?php
/**
 * Plugin Name: BoH SMTP (production mail delivery)
 * Description: Routes wp_mail through an external SMTP relay. Reads creds
 *              from wp-config.php constants so they never hit the DB or git.
 *              Also forces a stable From: address and a reachable Reply-To.
 *              No-ops if BOH_SMTP_HOST is not defined.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BOH_SMTP_HOST' ) ) {
	return; // not configured — let WP's default mailer run (or the dev mail logger).
}

add_action( 'phpmailer_init', function ( $mailer ) {
	/** @var \PHPMailer\PHPMailer\PHPMailer $mailer */
	$mailer->isSMTP();
	$mailer->Host       = BOH_SMTP_HOST;
	$mailer->Port       = defined( 'BOH_SMTP_PORT' ) ? (int) BOH_SMTP_PORT : 587;
	$mailer->SMTPAuth   = true;
	$mailer->Username   = defined( 'BOH_SMTP_USER' ) ? BOH_SMTP_USER : '';
	$mailer->Password   = defined( 'BOH_SMTP_PASS' ) ? BOH_SMTP_PASS : '';
	$mailer->SMTPSecure = defined( 'BOH_SMTP_SECURE' ) ? BOH_SMTP_SECURE : 'tls';
	$mailer->CharSet    = 'UTF-8';
	$mailer->AuthType   = 'LOGIN'; // Brevo advertises CRAM-MD5 but rejects it
	$mailer->Timeout    = 15;

	// HARD-OVERRIDE From: Contact Form 7 and GiveWP both inject a "From:"
	// header (e.g. BoH@rohitgroup.com) that bypasses the wp_mail_from
	// filter. If the SMTP relay (Brevo) hasn't verified that domain, every
	// form submission silently dies. Force the From to the one address we
	// know is authorised at the relay; Reply-To headers stay untouched so
	// admins can still reply directly to the submitter.
	if ( defined( 'BOH_MAIL_FROM' ) ) {
		$from_addr = BOH_MAIL_FROM;
		$from_name = defined( 'BOH_MAIL_FROM_NAME' ) ? BOH_MAIL_FROM_NAME : '';
		// setFrom(addr, name, auto): auto=false prevents PHPMailer from also
		// rewriting Message-ID / Sender headers we don't need to touch.
		$mailer->setFrom( $from_addr, $from_name, false );
	}
}, 99 );

// Pin From: address to one the SMTP relay actually authorizes. CF7 and GiveWP
// each set their own From, but if the domain doesn't match the relay's
// verified sender, delivery fails. This filter wins.
add_filter( 'wp_mail_from', function ( $original ) {
	return defined( 'BOH_MAIL_FROM' ) ? BOH_MAIL_FROM : $original;
}, 99 );

add_filter( 'wp_mail_from_name', function ( $original ) {
	return defined( 'BOH_MAIL_FROM_NAME' ) ? BOH_MAIL_FROM_NAME : $original;
}, 99 );

// If From is the noreply mailbox, set a Reply-To recipients can actually
// reach. Skip when the message already has a Reply-To header.
add_filter( 'wp_mail', function ( $args ) {
	if ( ! defined( 'BOH_MAIL_REPLY_TO' ) || empty( BOH_MAIL_REPLY_TO ) ) {
		return $args;
	}

	$headers = $args['headers'] ?? '';
	if ( is_array( $headers ) ) {
		foreach ( $headers as $h ) {
			if ( stripos( (string) $h, 'reply-to:' ) === 0 ) {
				return $args; // caller already set one
			}
		}
		$headers[] = 'Reply-To: ' . BOH_MAIL_REPLY_TO;
	} else {
		if ( stripos( (string) $headers, 'reply-to:' ) !== false ) {
			return $args;
		}
		$headers = trim( (string) $headers );
		$headers .= ( $headers === '' ? '' : "\n" ) . 'Reply-To: ' . BOH_MAIL_REPLY_TO;
	}
	$args['headers'] = $headers;
	return $args;
}, 99 );

// Log failures so we notice when the relay rejects us.
add_action( 'wp_mail_failed', function ( $err ) {
	if ( ! function_exists( 'error_log' ) ) return;
	$msg = is_wp_error( $err ) ? $err->get_error_message() : (string) $err;
	error_log( '[BoH SMTP] wp_mail failed: ' . $msg );
} );
