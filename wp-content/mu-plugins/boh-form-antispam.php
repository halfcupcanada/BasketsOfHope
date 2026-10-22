<?php
/**
 * Plugin Name: BoH form anti-spam
 * Description: Stops the bot submissions flooding the Contact Form 7 forms (the
 *   sponsorship form especially) by marking obvious spam so Contact Form 7 sends
 *   no notification email. Two signals, both low-false-positive: a honeypot field
 *   no human ever fills, and a web address in a field that never legitimately
 *   holds one (company, contact name, phone, auction item). Flagged submissions
 *   still land in Flamingo's spam bucket, so nothing is lost if a rule is ever
 *   wrong. This is a floor, not a wall - pair it with Turnstile/reCAPTCHA or an
 *   Akismet key for stronger coverage.
 */
defined( 'ABSPATH' ) || exit;

/**
 * A honeypot on every CF7 form: a field hidden from people but visible to bots,
 * which fill every input they find. Kept off-screen (not display:none, which
 * better bots skip) and out of the tab order.
 */
add_filter( 'wpcf7_form_elements', function ( $elements ) {
	$hp = '<div class="bohx-field" aria-hidden="true" '
		. 'style="position:absolute!important;left:-99999px!important;top:-99999px!important;width:1px;height:1px;overflow:hidden">'
		. '<label>Leave this field blank'
		. '<input type="text" name="boh_hp" value="" tabindex="-1" autocomplete="off"></label>'
		. '</div>';
	return $elements . $hp;
} );

/** Fields that never legitimately contain a web address. */
function boh_antispam_no_url_fields(): array {
	return [ 'company-name', 'contact-person', 'name', 'your-name', 'first-name',
	         'last-name', 'phone', 'tel', 'auction-item' ];
}

function boh_antispam_has_url( $value ): bool {
	$value = is_array( $value ) ? implode( ' ', $value ) : (string) $value;
	return (bool) preg_match( '~https?://|www\.[a-z0-9-]+\.|\[url|<a\s~i', $value );
}

add_filter( 'wpcf7_spam', function ( $spam, $submission ) {
	if ( $spam ) {
		return $spam; // something already flagged it
	}
	$log = function ( $agent, $reason ) use ( $submission ) {
		if ( is_object( $submission ) && method_exists( $submission, 'add_spam_log' ) ) {
			$submission->add_spam_log( [ 'agent' => $agent, 'reason' => $reason ] );
		}
	};

	// 1. Honeypot filled => bot.
	if ( isset( $_POST['boh_hp'] ) && trim( (string) $_POST['boh_hp'] ) !== '' ) {
		$log( 'boh_honeypot', 'honeypot field was filled' );
		return true;
	}

	// 2. A web address where one never belongs.
	$data = is_object( $submission ) && method_exists( $submission, 'get_posted_data' )
		? (array) $submission->get_posted_data() : [];
	foreach ( boh_antispam_no_url_fields() as $f ) {
		if ( isset( $data[ $f ] ) && boh_antispam_has_url( $data[ $f ] ) ) {
			$log( 'boh_urlcheck', "web address in field '{$f}'" );
			return true;
		}
	}
	return $spam;
}, 9, 2 );
