<?php
/**
 * Plugin Name: BoH form anti-spam
 * Description: Stops the bot submissions flooding the Contact Form 7 forms (the
 *   sponsorship form especially) by marking obvious spam so Contact Form 7 sends
 *   no notification email. Four free, no-key layers, all low-false-positive:
 *     1. Honeypot  - a field no human fills.
 *     2. URL check - a web address in a field that never holds one (company,
 *        contact, phone, auction item).
 *     3. Time-trap - a signed timestamp planted in the form; a submission with
 *        no token, a forged token, or one returned in under a few seconds is a
 *        bot. Humans cannot fill a multi-field form that fast, and the signature
 *        stops a bot minting its own token.
 *     4. Rate limit - more than a handful of submits from one IP in ten minutes.
 *   Flagged submissions still land in Flamingo's spam bucket, so a mistaken flag
 *   is recoverable; old spam there is pruned daily (see the cron below).
 *   For an even harder wall, add Cloudflare Turnstile / reCAPTCHA or an Akismet
 *   key - those need keys from the site owner and are not wired in here.
 */
defined( 'ABSPATH' ) || exit;

const BOH_AS_MIN_SECONDS = 3;      // a real person takes longer than this
const BOH_AS_TOKEN_TTL   = 604800; // a planted token older than 7 days is stale (cache-safe)
const BOH_AS_RL_MAX      = 6;      // submissions allowed per IP per window
const BOH_AS_RL_WINDOW   = 600;    // the window, in seconds (10 minutes)
const BOH_AS_PRUNE_DAYS  = 7;      // delete Flamingo spam older than this

function boh_antispam_secret(): string {
	return defined( 'AUTH_SALT' ) && AUTH_SALT ? AUTH_SALT : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'boh-fallback' );
}
function boh_antispam_make_token(): string {
	$ts  = (string) time();
	$sig = hash_hmac( 'sha256', $ts, boh_antispam_secret() );
	return $ts . '.' . substr( $sig, 0, 32 );
}
/** Returns the token's age in seconds, or null if missing/forged. */
function boh_antispam_token_age( $token ) {
	$token = (string) $token;
	if ( strpos( $token, '.' ) === false ) {
		return null;
	}
	[ $ts, $sig ] = explode( '.', $token, 2 );
	$expect = substr( hash_hmac( 'sha256', $ts, boh_antispam_secret() ), 0, 32 );
	if ( ! hash_equals( $expect, (string) $sig ) || ! ctype_digit( (string) $ts ) ) {
		return null;
	}
	return time() - (int) $ts;
}

// Plant the honeypot and the signed timestamp on every CF7 form.
add_filter( 'wpcf7_form_elements', function ( $elements ) {
	$hidden = '<div class="bohx-field" aria-hidden="true" '
		. 'style="position:absolute!important;left:-99999px!important;top:-99999px!important;width:1px;height:1px;overflow:hidden">'
		. '<label>Leave this field blank'
		. '<input type="text" name="boh_hp" value="" tabindex="-1" autocomplete="off"></label>'
		. '<input type="hidden" name="boh_ts" value="' . esc_attr( boh_antispam_make_token() ) . '">'
		. '</div>';
	return $elements . $hidden;
} );

function boh_antispam_no_url_fields(): array {
	return [ 'company-name', 'contact-person', 'name', 'your-name', 'first-name',
	         'last-name', 'phone', 'tel', 'auction-item' ];
}
function boh_antispam_has_url( $value ): bool {
	$value = is_array( $value ) ? implode( ' ', $value ) : (string) $value;
	return (bool) preg_match( '~https?://|www\.[a-z0-9-]+\.|\[url|<a\s~i', $value );
}
function boh_antispam_client_ip(): string {
	$ip = $_SERVER['REMOTE_ADDR'] ?? '';
	return is_string( $ip ) ? $ip : '';
}

add_filter( 'wpcf7_spam', function ( $spam, $submission ) {
	if ( $spam ) {
		return $spam;
	}
	$log = function ( $agent, $reason ) use ( $submission ) {
		if ( is_object( $submission ) && method_exists( $submission, 'add_spam_log' ) ) {
			$submission->add_spam_log( [ 'agent' => $agent, 'reason' => $reason ] );
		}
	};

	// 1. Honeypot.
	if ( isset( $_POST['boh_hp'] ) && trim( (string) $_POST['boh_hp'] ) !== '' ) {
		$log( 'boh_honeypot', 'honeypot field was filled' );
		return true;
	}

	// 3. Time-trap: no token, a forged token, too fast, or stale.
	$age = boh_antispam_token_age( $_POST['boh_ts'] ?? '' );
	if ( $age === null ) {
		$log( 'boh_timetrap', 'form token missing or forged' );
		return true;
	}
	if ( $age < BOH_AS_MIN_SECONDS ) {
		$log( 'boh_timetrap', "submitted in {$age}s (under " . BOH_AS_MIN_SECONDS . 's)' );
		return true;
	}
	if ( $age > BOH_AS_TOKEN_TTL ) {
		$log( 'boh_timetrap', 'form token stale' );
		return true;
	}

	// 2. URL where one never belongs.
	$data = is_object( $submission ) && method_exists( $submission, 'get_posted_data' )
		? (array) $submission->get_posted_data() : [];
	foreach ( boh_antispam_no_url_fields() as $f ) {
		if ( isset( $data[ $f ] ) && boh_antispam_has_url( $data[ $f ] ) ) {
			$log( 'boh_urlcheck', "web address in field '{$f}'" );
			return true;
		}
	}

	// 4. Rate limit per IP.
	$ip = boh_antispam_client_ip();
	if ( $ip !== '' ) {
		$key   = 'boh_as_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= BOH_AS_RL_MAX ) {
			$log( 'boh_ratelimit', "more than " . BOH_AS_RL_MAX . ' submits from one IP in ' . ( BOH_AS_RL_WINDOW / 60 ) . ' min' );
			return true;
		}
		set_transient( $key, $count + 1, BOH_AS_RL_WINDOW );
	}

	return $spam;
}, 9, 2 );

// ── Daily pruning of the Flamingo spam bucket ──────────────────────
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'boh_antispam_prune' ) ) {
		wp_schedule_event( time() + 300, 'daily', 'boh_antispam_prune' );
	}
} );
add_action( 'boh_antispam_prune', function () {
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - BOH_AS_PRUNE_DAYS * DAY_IN_SECONDS );
	$q = new WP_Query( [
		'post_type'      => 'flamingo_inbound',
		'post_status'    => 'flamingo-spam',
		'posts_per_page' => 1000,
		'fields'         => 'ids',
		'date_query'     => [ [ 'column' => 'post_date_gmt', 'before' => $cutoff ] ],
		'no_found_rows'  => true,
	] );
	foreach ( $q->posts as $id ) {
		wp_delete_post( $id, true );
	}
} );
