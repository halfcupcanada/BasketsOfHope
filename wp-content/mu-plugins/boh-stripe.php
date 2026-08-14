<?php
/**
 * Plugin Name: BoH Stripe wiring
 * Description: Bridges Stripe API keys from wp-config.php constants into
 *              GiveWP's settings + enables the Stripe gateway. Keeps keys
 *              out of the DB so they live alongside the SMTP creds.
 *              Constants (any subset can be defined):
 *                BOH_STRIPE_TEST_PK / BOH_STRIPE_TEST_SK
 *                BOH_STRIPE_LIVE_PK / BOH_STRIPE_LIVE_SK
 */

defined( 'ABSPATH' ) || exit;

// Expose constants as Give "options" via the give_get_option_{key} filter.
$boh_stripe_map = [
	'stripe_test_publishable_key' => 'BOH_STRIPE_TEST_PK',
	'stripe_test_secret_key'      => 'BOH_STRIPE_TEST_SK',
	'stripe_published_key'        => 'BOH_STRIPE_LIVE_PK', // legacy alias some flows read
	'live_secret_key'             => 'BOH_STRIPE_LIVE_SK', // legacy alias
	'stripe_live_publishable_key' => 'BOH_STRIPE_LIVE_PK',
	'stripe_live_secret_key'      => 'BOH_STRIPE_LIVE_SK',
];
foreach ( $boh_stripe_map as $opt => $const ) {
	add_filter(
		"give_get_option_{$opt}",
		function ( $value ) use ( $const ) {
			return defined( $const ) ? constant( $const ) : $value;
		},
		10,
		1
	);
}

// Enable the 'stripe' gateway in Give's globally-enabled list whenever a
// secret key is present. The filter merges into whatever's already there.
add_filter( 'give_get_option_gateways', function ( $gateways ) {
	if ( ! is_array( $gateways ) ) { $gateways = []; }
	$has_test = defined( 'BOH_STRIPE_TEST_SK' ) && BOH_STRIPE_TEST_SK !== '';
	$has_live = defined( 'BOH_STRIPE_LIVE_SK' ) && BOH_STRIPE_LIVE_SK !== '';
	if ( $has_test || $has_live ) {
		$gateways['stripe'] = 1;
	}
	return $gateways;
} );

// Same on the per-form gateway override stored as post meta. The form's
// _give_gateways shape is nested (label + enabled) rather than flat.
add_filter( 'get_post_metadata', function ( $value, $object_id, $meta_key ) {
	if ( $meta_key !== '_give_gateways' ) return $value;
	$has_key = ( defined( 'BOH_STRIPE_TEST_SK' ) && BOH_STRIPE_TEST_SK !== '' )
	        || ( defined( 'BOH_STRIPE_LIVE_SK' ) && BOH_STRIPE_LIVE_SK !== '' );
	if ( ! $has_key ) return $value;

	// Pull the raw stored value (avoid our own filter to dodge recursion).
	remove_filter( 'get_post_metadata', __FUNCTION__, 10 );
	$stored = get_post_meta( $object_id, '_give_gateways', true );
	add_filter( 'get_post_metadata', __FUNCTION__, 10, 3 );

	if ( ! is_array( $stored ) ) $stored = [];
	if ( empty( $stored['stripe'] ) ) {
		$stored['stripe'] = [ 'label' => 'Credit Card', 'enabled' => '1' ];
	}
	// get_post_metadata expects a single-value lookup to return [ $val ]
	return [ $stored ];
}, 10, 3 );
