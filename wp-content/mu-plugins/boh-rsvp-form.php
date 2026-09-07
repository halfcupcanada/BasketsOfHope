<?php
/**
 * Plugin Name: BoH RSVP form copy
 * Description: The RSVP form's wording, editable in BoH Content rather than in Contact Form 7.
 *
 * The field *names* are structural - the confirmation email, the invitation
 * list and the guest-count logic all read first-name, last-name, your-email,
 * party-size and consent - so those stay in code. Everything a visitor reads
 * comes from BoH Content, and saving that screen writes the form back into
 * Contact Form 7, which stays the thing that renders and validates it.
 *
 * That direction is deliberate: CF7's own editor keeps working and keeps
 * showing the truth. The cost is that a hand-edit made there is overwritten
 * the next time somebody saves BoH Content, which the screen says out loud.
 */

defined( 'ABSPATH' ) || exit;

const BOH_RSVP_FORM_ID_OPTION = 'boh_rsvp_form_id';
const BOH_RSVP_FORM_SYNCED    = 'boh_rsvp_form_synced';

/** The shipped wording. Also what the admin fields show before a first save. */
function boh_rsvp_form_defaults(): array {
	return [
		'first_label'   => 'First name',
		'last_label'    => 'Last name',
		'email_label'   => 'Email',
		'guests_label'  => 'How many guests including yourself?',
		'guest_options' => "1 - Just me\n2\n3\n4\n5",
		'consent'       => 'I agree to receive email communication from Rohit Group regarding Baskets of Hope and similar events.',
		'submit'        => 'RSVP Now',
	];
}

/**
 * The current wording.
 *
 * Reads the option directly rather than through boh_content(): this runs on
 * update_option, where boh_content()'s static cache still holds the values
 * from before the save.
 */
function boh_rsvp_form_copy(): array {
	$stored = get_option( BOH_CONTENT_OPTION, [] );
	$stored = is_array( $stored ) ? $stored : [];
	$out    = [];
	foreach ( boh_rsvp_form_defaults() as $name => $default ) {
		$key = 'rsvp.form.' . $name;
		// So the admin field shows the shipped copy on a site that has never
		// edited it, the same way every other BoH Content field does.
		boh_content_note_default( $key, $default );
		$value = $stored[ $key ] ?? '';
		$out[ $name ] = ( is_string( $value ) && trim( $value ) !== '' ) ? $value : $default;
	}
	return $out;
}

/**
 * Text safe to drop into the form template.
 *
 * Square brackets are how CF7 marks a field, and a double quote is how a tag
 * value ends - so either one, typed into a label or a button, silently turns
 * into markup. A button reading 'RSVP "now"] [submit "x"' produced a second
 * submit tag and a broken form. They are removed rather than escaped: CF7's
 * template has no escape for them, and none of this copy needs them.
 */
function boh_rsvp_tag_value( string $s ): string {
	$s = wp_strip_all_tags( $s );
	$s = str_replace( [ '"', '[', ']' ], '', $s );
	return trim( (string) preg_replace( '/\s+/', ' ', $s ) );
}

/** The same, for text that sits in the form's HTML rather than inside a tag. */
function boh_rsvp_label( string $s ): string {
	return esc_html( boh_rsvp_tag_value( $s ) );
}

/** The form template CF7 stores, built from the editable copy. */
function boh_rsvp_form_template(): string {
	$c = boh_rsvp_form_copy();

	$options = [];
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $c['guest_options'] ) as $line ) {
		$line = boh_rsvp_tag_value( $line );
		if ( $line !== '' ) {
			$options[] = '"' . $line . '"';
		}
	}
	if ( ! $options ) {
		$options = [ '"1 - Just me"' ];
	}

	$first   = boh_rsvp_label( $c['first_label'] );
	$last    = boh_rsvp_label( $c['last_label'] );
	$email   = boh_rsvp_label( $c['email_label'] );
	$guests  = boh_rsvp_label( $c['guests_label'] );
	$consent = boh_rsvp_tag_value( $c['consent'] );
	$submit  = boh_rsvp_tag_value( $c['submit'] );

	return <<<FORM
<p class="boh-form-row boh-form-row--split"><label> {$first} <span class="req">*</span>
    [text* first-name placeholder "First name"] </label>
<label> {$last} <span class="req">*</span>
    [text* last-name placeholder "Last name"] </label></p>

<p class="boh-form-row"><label> {$email} <span class="req">*</span>
    [email* your-email placeholder "you@example.com"] </label></p>

<p class="boh-form-row"><label> {$guests}
    [select party-size 
FORM
	. implode( ' ', $options ) . <<<FORM
] </label></p>

<p class="boh-form-row boh-form-row--terms">[checkbox* consent use_label_element "{$consent}"]</p>

<p class="boh-form-submit">[submit "{$submit}"]</p>
FORM;
}

/**
 * Which Contact Form 7 form is the RSVP.
 *
 * Found by its field names rather than by id, because the ids differ between
 * this site and any copy of it - the same trap that has already bitten page
 * and form ids here once.
 */
function boh_rsvp_form_id(): int {
	$id = (int) get_option( BOH_RSVP_FORM_ID_OPTION, 0 );
	if ( $id && get_post_type( $id ) === 'wpcf7_contact_form' ) {
		return $id;
	}
	$ids = get_posts( [
		'post_type'   => 'wpcf7_contact_form',
		'post_status' => 'any',
		'numberposts' => 50,
		'fields'      => 'ids',
	] );
	foreach ( $ids as $candidate ) {
		$form = get_post_meta( $candidate, '_form', true );
		if ( is_string( $form ) && strpos( $form, 'party-size' ) !== false ) {
			update_option( BOH_RSVP_FORM_ID_OPTION, (int) $candidate, false );
			return (int) $candidate;
		}
	}
	return 0;
}

/** Write the current wording into the CF7 form. */
function boh_rsvp_form_sync(): bool {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return false;
	}
	$id = boh_rsvp_form_id();
	if ( ! $id ) {
		return false;
	}
	$cf = WPCF7_ContactForm::get_instance( $id );
	if ( ! $cf ) {
		return false;
	}
	$template = boh_rsvp_form_template();
	if ( trim( (string) $cf->prop( 'form' ) ) === trim( $template ) ) {
		update_option( BOH_RSVP_FORM_SYNCED, '1', false );
		return true;
	}
	$cf->set_properties( [ 'form' => $template ] );
	$cf->save();
	update_option( BOH_RSVP_FORM_SYNCED, '1', false );
	return true;
}

add_action( 'update_option_' . BOH_CONTENT_OPTION, function () {
	boh_rsvp_form_sync();
}, 20 );

// One first sync, so the form matches this file without waiting for somebody
// to save the screen. After that it only moves when the copy is edited.
add_action( 'admin_init', function () {
	if ( get_option( BOH_RSVP_FORM_SYNCED ) !== '1' ) {
		boh_rsvp_form_sync();
	}
} );
