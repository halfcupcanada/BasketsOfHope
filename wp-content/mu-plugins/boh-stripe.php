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

/**
 * GiveWP 2.7+ reads its keys from an "accounts" array, not from the flat
 * options above - so filling those in left live mode with no publishable key
 * at all and a donate form that rendered its card section empty.
 *
 * The account entries live in the database with their key fields blank; the
 * keys are put in here, from the constants, on the way out. Same bargain as
 * the rest of this file: GiveWP sees what it needs, the database never holds
 * a secret.
 *
 * Test and live are two different Stripe accounts here (acct_1TUzx0 and
 * acct_1UAvcF), which is why each entry gets only its own pair - crossing
 * them would send one account's key with the other's id.
 */
add_filter( 'give_get_option__give_stripe_get_all_accounts', function ( $accounts ) {
	if ( ! is_array( $accounts ) ) { $accounts = []; }

	$fill = [
		'boh-live' => [
			'live_publishable_key' => 'BOH_STRIPE_LIVE_PK',
			'live_secret_key'      => 'BOH_STRIPE_LIVE_SK',
		],
		'boh-test' => [
			'test_publishable_key' => 'BOH_STRIPE_TEST_PK',
			'test_secret_key'      => 'BOH_STRIPE_TEST_SK',
		],
	];

	foreach ( $fill as $slug => $fields ) {
		if ( empty( $accounts[ $slug ] ) || ! is_array( $accounts[ $slug ] ) ) { continue; }
		// A Connect account carries its own credentials; only ever fill in a
		// manually-keyed one.
		if ( ( $accounts[ $slug ]['type'] ?? '' ) !== 'manual' ) { continue; }
		foreach ( $fields as $field => $const ) {
			if ( defined( $const ) && constant( $const ) !== '' ) {
				$accounts[ $slug ][ $field ] = constant( $const );
			}
		}
	}

	return $accounts;
}, 20 );

/**
 * Which of those two accounts is the default follows the mode switch.
 *
 * Without this, turning test mode on would hand the live account's entry a
 * test key it does not have, and the form would go blank with nothing to say
 * why - the exact failure this whole block exists to fix.
 */
add_filter( 'give_get_option__give_stripe_default_account', function ( $slug ) {
	$accounts = give_get_option( '_give_stripe_get_all_accounts', [] );
	$wanted   = give_is_test_mode() ? 'boh-test' : 'boh-live';
	return isset( $accounts[ $wanted ] ) ? $wanted : $slug;
}, 20 );
