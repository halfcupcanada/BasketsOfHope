<?php
/**
 * Plugin Name: BoH - Sponsorship form levels
 * Description: Keeps the "Sponsorship level" dropdown on the commitment form in step with the sponsorship cards edited in BoH Content, so the two can never drift apart again.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which Contact Form 7 form is the sponsorship commitment.
 *
 * Found by its field, not its id: the id differs between this site and any
 * copy of it.
 */
function boh_sponsor_form_id(): int {
	static $id = null;
	if ( $id !== null ) {
		return $id;
	}
	$id = 0;
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_form' WHERE p.post_type = 'wpcf7_contact_form' AND p.post_status = 'publish' AND m.meta_value LIKE '%sponsorship-level%' ORDER BY p.ID ASC" );
	if ( $rows ) {
		$id = (int) $rows[0]->ID;
	}
	return $id;
}

/** "Gold Basket - $2,000", one per active card, in card order. */
function boh_sponsor_form_level_options(): array {
	$rows = function_exists( 'boh_content' ) ? boh_content( 'sponsor.tiers', [] ) : [];
	$out  = [];
	foreach ( (array) $rows as $r ) {
		$r = array_pad( (array) $r, 7, '' );
		if ( (string) $r[6] === '0' ) {
			continue; // switched off in admin
		}
		$level = trim( wp_strip_all_tags( (string) $r[0] ) );
		$price = trim( wp_strip_all_tags( (string) $r[2] ) );
		if ( $level === '' ) {
			continue;
		}
		$out[] = $price !== '' ? "{$level} - {$price}" : $level;
	}
	// Choices that are not a card: "Silent auction item only" and the like.
	$extra = function_exists( 'boh_content' ) ? (string) boh_content( 'sponsor.form_extra_options', 'Silent auction item only' ) : 'Silent auction item only';
	foreach ( preg_split( '/\r\n|\r|\n/', $extra ) as $line ) {
		$line = trim( wp_strip_all_tags( $line ) );
		if ( $line !== '' ) {
			$out[] = $line;
		}
	}
	// A quote or a bracket would end the option early in CF7's template.
	return array_values( array_unique( array_map( fn( $o ) => str_replace( [ '"', '[', ']' ], '', $o ), $out ) ) );
}

/**
 * Rewrite the dropdown's choices inside the stored form, leaving the tag's
 * name and attributes (required, first_as_label...) exactly as they are.
 * Returns true when the form changed.
 */
function boh_sponsor_form_sync(): bool {
	if ( ! function_exists( 'wpcf7_contact_form' ) ) {
		return false;
	}
	$id = boh_sponsor_form_id();
	$cf = $id ? wpcf7_contact_form( $id ) : null;
	if ( ! $cf ) {
		return false;
	}
	$form = (string) $cf->prop( 'form' );
	if ( ! preg_match( '/\[select\*?\s+sponsorship-level\b([^\]]*)\]/', $form, $m, PREG_OFFSET_CAPTURE ) ) {
		return false;
	}
	$tag  = $m[0][0];
	$body = $m[1][0];
	// Everything before the first quoted choice is the tag's attributes.
	$attrs   = preg_match( '/^(.*?)(?="[^"]*")/s', $body, $a ) ? rtrim( $a[1] ) : rtrim( $body );
	$choices = preg_match_all( '/"([^"]*)"/', $body, $q ) ? $q[1] : [];
	$options = boh_sponsor_form_level_options();
	// A first choice used as the placeholder stays put.
	if ( $choices && stripos( $attrs, 'first_as_label' ) !== false ) {
		array_unshift( $options, $choices[0] );
	}
	$open    = strpos( $tag, '[select*' ) === 0 ? '[select*' : '[select';
	$new_tag = $open . ' sponsorship-level' . ( $attrs !== '' ? ' ' . $attrs : '' ) . ' ' . implode( ' ', array_map( fn( $o ) => '"' . $o . '"', $options ) ) . ']';
	if ( $new_tag === $tag ) {
		return false;
	}
	$cf->set_properties( [ 'form' => substr_replace( $form, $new_tag, $m[0][1], strlen( $tag ) ) ] );
	$cf->save();
	return true;
}

// Saving BoH Content rewrites the form; so does the first admin visit after
// a deploy, which is how a code change reaches a form that was saved earlier.
add_action( 'update_option_' . ( defined( 'BOH_CONTENT_OPTION' ) ? BOH_CONTENT_OPTION : 'boh_content' ), function () {
	boh_sponsor_form_sync();
}, 20 );
add_action( 'admin_init', function () {
	if ( current_user_can( 'manage_options' ) && get_transient( 'boh_sponsor_form_synced' ) === false ) {
		boh_sponsor_form_sync();
		set_transient( 'boh_sponsor_form_synced', 1, HOUR_IN_SECONDS );
	}
} );
