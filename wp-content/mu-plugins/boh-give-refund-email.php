<?php
/**
 * Plugin Name: BoH refund notification
 * Description: Tells a donor when their donation has been refunded. GiveWP
 *              has no email for this; without it a refund is silent on our
 *              side and the donor's only sign is a line on a statement.
 *
 * Sent when a donation's status becomes "refunded" - from the GiveWP admin,
 * from a Stripe webhook, or from code. Wording lives in BoH Content > Donate.
 */

defined( 'ABSPATH' ) || exit;

function boh_refund_email_defaults(): array {
	$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	return [
		'subject' => 'Your donation has been refunded',
		'body'    => "Dear {name},\n\n"
			. "Your donation of {amount} to " . $site . " on {date} has been refunded in full.\n\n"
			. "The money goes back to the card you paid with. Banks usually show it within 5 to 10 business days, and it may appear as a reversal of the original charge rather than a new line.\n\n"
			. "Reference: {reference}\n\n"
			. "If you were not expecting this, or anything looks wrong, just reply to this email.\n\n"
			. "With gratitude,\n" . $site,
	];
}

/** Send the notice for one donation. Returns true when the relay accepted it. */
function boh_send_refund_email( int $donation_id ): bool {
	// Only GiveWP functions that exist in this version - the first draft leaned
	// on a helper that does not, and fell over the moment it was called.
	$email = (string) give_get_payment_user_email( $donation_id );
	if ( ! is_email( $email ) ) {
		return false;
	}
	$d       = boh_refund_email_defaults();
	$subject = (string) boh_content( 'donate.refund_subject', $d['subject'] );
	$body    = (string) boh_content( 'donate.refund_body', $d['body'] );

	$first = '';
	$donor_id = (int) give_get_payment_donor_id( $donation_id );
	if ( $donor_id && class_exists( 'Give_Donor' ) ) {
		$donor = new Give_Donor( $donor_id );
		$first = trim( (string) $donor->get_first_name() );
	}
	if ( $first === '' ) {
		$first = trim( (string) give_get_meta( $donation_id, '_give_donor_billing_first_name', true ) );
	}
	$first = $first !== '' ? ucfirst( strtolower( $first ) ) : 'friend';

	$vars = [
		'{name}'      => $first,
		'{amount}'    => wp_strip_all_tags( (string) give_donation_amount( $donation_id, true ) ),
		'{date}'      => wp_date( 'F j, Y', strtotime( (string) get_post_field( 'post_date', $donation_id ) ) ),
		'{reference}' => (string) give_get_payment_number( $donation_id ),
	];
	return (bool) wp_mail( $email, strtr( $subject, $vars ), strtr( $body, $vars ), [ 'Content-Type: text/plain; charset=UTF-8' ] );
}

// Fires for every status change; act only on the move into "refunded".
add_action( 'give_update_payment_status', function ( $donation_id, $new_status, $old_status ) {
	if ( $new_status !== 'refunded' || $old_status === 'refunded' ) {
		return;
	}
	if ( boh_send_refund_email( (int) $donation_id ) ) {
		give_insert_payment_note( $donation_id, 'Refund notification emailed to the donor.' );
	}
}, 20, 3 );
