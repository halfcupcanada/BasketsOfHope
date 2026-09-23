<?php
/**
 * Plugin Name: BoH - Sponsorship on the donation form
 * Description: A "Sponsorship" choice in the donation form's amount dropdown. It takes any figure, like Custom Amount, and marks the donation as a sponsorship so the receipt, the donations list and the export say so.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const BOH_GIVE_SPONSORSHIP_META = '_boh_designation';

function boh_give_sponsorship_label(): string {
	$label = function_exists( 'boh_content' ) ? boh_content( 'donate.sponsorship_label', 'Sponsorship' ) : 'Sponsorship';
	return trim( wp_strip_all_tags( (string) $label ) ) ?: 'Sponsorship';
}

/**
 * The extra choice, and a hidden field that says it was chosen.
 *
 * It rides on Custom Amount: the value is "custom", so GiveWP's own script
 * opens the amount box and records the price id it already knows how to
 * handle. It sits after Custom Amount and without that option's class, so
 * everything GiveWP does to "the custom option" still lands on Custom Amount
 * - a donor typing an unusual figure is not turned into a sponsor. The
 * script below keeps a sponsor a sponsor in the other direction.
 */
add_filter( 'give_form_level_output', function ( $output, $form_id ) {
	if ( strpos( (string) $output, '<select' ) === false || strpos( (string) $output, 'give-donation-level-custom' ) === false ) {
		return $output;
	}
	$option = '<option data-price-id="custom" class="boh-give-sponsorship" value="custom" data-boh-designation="sponsorship">'
		. esc_html( boh_give_sponsorship_label() ) . '</option>';
	$output = preg_replace( '~(<option[^>]*give-donation-level-custom[^>]*>.*?</option>)~s', '$1' . $option, (string) $output, 1 );
	$output .= '<input type="hidden" name="boh_designation" class="boh-give-designation" value="">';
	return $output;
}, 10, 2 );

add_action( 'wp_footer', function () {
	if ( ! function_exists( 'give_get_option' ) ) {
		return;
	}
	?>
	<script>
	(function () {
	  // Which option is chosen - GiveWP only sees "custom" for both.
	  document.addEventListener('change', function (e) {
	    var sel = e.target;
	    if (!sel || !sel.classList || !sel.classList.contains('give-donation-levels-wrap')) { return; }
	    var form = sel.closest('form');
	    var hid = form && form.querySelector('.boh-give-designation');
	    if (!hid) { return; }
	    var opt = sel.options[sel.selectedIndex];
	    hid.value = (opt && opt.getAttribute('data-boh-designation')) || '';
	  });
	  // After any hand-typed figure GiveWP flips the dropdown back to
	  // "Custom Amount". A sponsor who has just typed their level's price
	  // stays a sponsor.
	  document.addEventListener('blur', function (e) {
	    var inp = e.target;
	    if (!inp || !inp.classList || !inp.classList.contains('give-donation-amount')) { return; }
	    var form = inp.closest('form');
	    var hid = form && form.querySelector('.boh-give-designation');
	    if (!hid || hid.value !== 'sponsorship') { return; }
	    window.setTimeout(function () {
	      var opt = form.querySelector('.boh-give-sponsorship');
	      if (opt) { opt.selected = true; }
	    }, 60);
	  }, true);
	})();
	</script>
	<?php
}, 70 );

/** Remember the choice on the donation itself. */
add_action( 'give_insert_payment', function ( $payment_id ) {
	if ( isset( $_POST['boh_designation'] ) && $_POST['boh_designation'] === 'sponsorship' ) {
		give_update_meta( (int) $payment_id, BOH_GIVE_SPONSORSHIP_META, 'sponsorship' );
	}
}, 10, 1 );

/**
 * Wherever GiveWP names the level - the receipt's "Designation", the
 * donations list, the export, the emails - a sponsorship says so instead
 * of "Custom Amount".
 */
add_filter( 'give_get_price_option_name', function ( $text, $form_id, $payment_id ) {
	if ( $payment_id && give_get_meta( (int) $payment_id, BOH_GIVE_SPONSORSHIP_META, true ) === 'sponsorship' ) {
		return boh_give_sponsorship_label();
	}
	return $text;
}, 10, 3 );
