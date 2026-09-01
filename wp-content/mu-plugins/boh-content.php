<?php
/**
 * Plugin Name: BoH editable content
 * Description: Puts the site's copy and images behind an admin screen instead
 *              of hardcoded PHP arrays. Every field falls back to the value
 *              the theme already shipped, so an unedited site renders exactly
 *              as before and a half-filled form can never blank a section.
 *
 *              Read with boh_content('home.stats', $default). Keys are
 *              dot-paths; the whole tree lives in one option so a page render
 *              costs a single lookup.
 *
 *              Field types: text, textarea, richtext (TinyMCE), image (media
 *              library picker), url, and repeater (a list of any of these).
 */

defined( 'ABSPATH' ) || exit;

const BOH_CONTENT_OPTION = 'boh_content';
const BOH_CONTENT_CAP    = 'edit_theme_options';

/**
 * Read one content value, falling back to the theme's own default.
 *
 * The fallback is what makes this safe to retrofit: wiring a shortcode to
 * boh_content() changes nothing until somebody actually saves that field.
 */
function boh_content( string $key, $default = '' ) {
	static $all = null;
	if ( $all === null ) {
		$stored = get_option( BOH_CONTENT_OPTION, [] );
		$all    = is_array( $stored ) ? $stored : [];
	}

	// Remember what the theme ships for this key. Without it the admin fields
	// render empty on a site that has never been edited - the copy is real,
	// but it lives as a fallback in code rather than in the database, so there
	// is nothing to put in the input. Capturing it here keeps one source of
	// truth: the value the front end actually used.
	boh_content_note_default( $key, $default );

	$value = $all[ $key ] ?? null;

	if ( $value === null || $value === '' ) {
		return $default;
	}
	// An empty repeater means "nothing saved", not "show nothing" - a blank
	// list would silently delete a whole section from the page.
	if ( is_array( $value ) && ! array_filter( $value, 'boh_content_row_has_value' ) ) {
		return $default;
	}
	return $value;
}

/** True when any cell in a repeater row carries content. */
function boh_content_row_has_value( $row ): bool {
	if ( ! is_array( $row ) ) {
		return trim( (string) $row ) !== '';
	}
	foreach ( $row as $cell ) {
		if ( trim( (string) $cell ) !== '' ) {
			return true;
		}
	}
	return false;
}

const BOH_CONTENT_DEFAULTS_OPTION = 'boh_content_defaults';

/**
 * Record the theme's shipped value for a key, so the admin can pre-fill.
 *
 * Accumulates in memory and writes once on shutdown, and only when something
 * new has appeared - so this costs a write the first time a page renders a
 * given field and nothing thereafter.
 */
function boh_content_note_default( string $key, $default ): void {
	if ( $default === '' || $default === null || $default === [] ) {
		return;
	}
	$known = boh_content_defaults();
	if ( array_key_exists( $key, $known ) && $known[ $key ] === $default ) {
		return;
	}
	$GLOBALS['boh_content_defaults_pending'][ $key ] = $default;

	static $hooked = false;
	if ( ! $hooked ) {
		$hooked = true;
		add_action( 'shutdown', 'boh_content_flush_defaults', 99 );
	}
}

/** All shipped defaults recorded so far. */
function boh_content_defaults(): array {
	static $cache = null;
	if ( $cache === null ) {
		$stored = get_option( BOH_CONTENT_DEFAULTS_OPTION, [] );
		$cache  = is_array( $stored ) ? $stored : [];
	}
	if ( ! empty( $GLOBALS['boh_content_defaults_pending'] ) ) {
		return array_merge( $cache, $GLOBALS['boh_content_defaults_pending'] );
	}
	return $cache;
}

function boh_content_flush_defaults(): void {
	if ( empty( $GLOBALS['boh_content_defaults_pending'] ) ) {
		return;
	}
	$stored = get_option( BOH_CONTENT_DEFAULTS_OPTION, [] );
	$stored = is_array( $stored ) ? $stored : [];
	update_option(
		BOH_CONTENT_DEFAULTS_OPTION,
		array_merge( $stored, $GLOBALS['boh_content_defaults_pending'] ),
		false
	);
	$GLOBALS['boh_content_defaults_pending'] = [];
}

/**
 * What an admin field should show: the saved value, or the theme's own value
 * when nothing has been saved yet.
 */
function boh_content_effective( string $key, array $stored ) {
	if ( array_key_exists( $key, $stored ) && $stored[ $key ] !== '' && $stored[ $key ] !== [] ) {
		return $stored[ $key ];
	}
	$defaults = boh_content_defaults();
	return $defaults[ $key ] ?? null;
}

/**
 * The editable surface, grouped into screens. Each field declares how it is
 * rendered and how it is sanitised; the shortcodes read the same keys.
 */
function boh_content_schema(): array {
	return [
		'home' => [
			'title'  => 'Home',
			'fields' => [
				[
					'key'   => 'home.stats',
					'label' => 'Impact numbers',
					'help'  => 'Shown on the home page. Leave the whole list empty to keep the built-in values.',
					'type'  => 'repeater',
					'cols'  => [
						[ 'label' => 'Number', 'type' => 'text', 'width' => '30%' ],
						[ 'label' => 'Label',  'type' => 'text', 'width' => '70%' ],
					],
				],
				[
					'key'   => 'home.stats_image',
					'label' => 'Impact numbers - background photograph',
					'type'  => 'image',
					'ratio' => '3:1',
					'px'    => '2400 x 800',
					'help'  => 'Sits behind the impact numbers on the home page, darkened so the figures stay readable. Leave empty for the plain dark band.',
				],
				[
					'key'   => 'home.about_image',
					'label' => '"A community event" - background photograph',
					'type'  => 'image',
					'ratio' => '3:1',
					'px'    => '2400 x 800',
					'help'  => 'Behind the paragraph about the event, washed pale so the words stay readable. Leave empty for a plain background.',
				],
				[
					'key'   => 'home.quick_links',
					'label' => 'Explore links',
					'help'  => 'The card grid near the foot of the home page.',
					'type'  => 'repeater',
					'cols'  => [
						[ 'label' => 'Link',        'type' => 'text',     'width' => '18%' ],
						[ 'label' => 'Title',       'type' => 'text',     'width' => '22%' ],
						[ 'label' => 'Description', 'type' => 'textarea', 'width' => '60%' ],
					],
				],
				[
					'key'   => 'home.steps',
					'label' => 'How it works - steps',
					'help'  => 'Four steps on the home page. The image is used on both the home and About versions.',
					'type'  => 'repeater',
					'cols'  => [
						[ 'label' => 'Number',      'type' => 'text',     'width' => '8%'  ],
						[ 'label' => 'Title',       'type' => 'text',     'width' => '20%' ],
						[ 'label' => 'Full text',   'type' => 'textarea', 'width' => '28%' ],
						[ 'label' => 'Short text',  'type' => 'textarea', 'width' => '24%' ],
						[ 'label' => 'Image',       'type' => 'image',    'width' => '20%', 'ratio' => '4:3', 'px' => '1200 x 900' ],
					],
				],
				[
					'key'   => 'home.steps_heading',
					'label' => 'How it works - heading',
					'type'  => 'text',
				],
				[
					'key'   => 'home.steps_lede',
					'label' => 'How it works - intro',
					'type'  => 'richtext',
					'help'  => 'Links are allowed here - use the link button to point at the Donate page.',
				],
			],
		],

		'agenda' => [
			'title'  => 'Event agenda',
			'help'   => "This year's running order, shown on the home page directly under the date and countdown. Somebody who came to the site last year said it never told them what actually happens on the night - this is that answer. The section stays hidden until you switch it on, so you can draft the evening here first and publish it when the times are settled.",
			'fields' => [
				[
					'key'      => 'agenda.enabled',
					'label'    => 'Show the agenda',
					'type'     => 'toggle',
					'on_label' => 'Show this section on the home page',
					'help'     => 'Off until the running order is confirmed. Nothing below appears on the site while this is unticked.',
				],
				[
					'key'   => 'agenda.eyebrow',
					'label' => 'Small label above the heading',
					'type'  => 'text',
				],
				[
					'key'   => 'agenda.heading',
					'label' => 'Heading',
					'type'  => 'text',
				],
				[
					'key'   => 'agenda.lede',
					'label' => 'Intro',
					'type'  => 'richtext',
					'help'  => 'One or two sentences under the heading. Links are allowed.',
				],
				[
					'key'   => 'agenda.items',
					'label' => 'The running order',
					'help'  => 'One row per moment in the evening, in order. Leave the speaker columns empty for anything that has no host - the line simply will not appear. Rows can be reordered with the arrows.',
					'type'  => 'repeater',
					'cols'  => [
						[ 'label' => 'Time',         'type' => 'text',     'width' => '11%' ],
						[ 'label' => 'What happens', 'type' => 'text',     'width' => '21%' ],
						[ 'label' => 'Details',      'type' => 'textarea', 'width' => '32%' ],
						[ 'label' => 'Speaker',      'type' => 'text',     'width' => '18%' ],
						[ 'label' => 'Their role',   'type' => 'text',     'width' => '18%' ],
					],
				],
				[
					'key'   => 'agenda.shortcut_open',
					'label' => 'Shortcut - closed',
					'type'  => 'text',
					'help'  => 'The line under the RSVP button that opens the running order.',
				],
				[
					'key'   => 'agenda.shortcut_close',
					'label' => 'Shortcut - open',
					'type'  => 'text',
					'help'  => 'What the same line says once the running order is showing.',
				],
				[
					'key'   => 'agenda.note',
					'label' => 'Closing note',
					'type'  => 'text',
					'help'  => 'A small line under the list - the place for "times are approximate".',
				],
			],
		],

		'emails' => [
			'title'  => 'Emails',
			'help'   => 'The header and footer wrapped around every message the site sends - RSVP confirmations, invitations, reminders, forwarded invitations. The words in the middle come from the message itself; everything here is the frame around them. Send yourself a test at the bottom of this screen before trusting it to a guest list.',
			'fields' => [
				[
					'key'   => 'email.preheader',
					'label' => 'Inbox preview line',
					'type'  => 'text',
					'help'  => 'The grey line inboxes show beside the subject. Left empty, the inbox shows the opening words of the message instead - which is usually fine.',
				],
				[
					'key'   => 'email.header',
					'label' => 'Header',
					'type'  => 'richtext',
					'help'  => 'Sits under the logo, above the message. Often best left empty - the logo alone is a clean opening.',
				],
				[
					'key'   => 'email.footer',
					'label' => 'Footer',
					'type'  => 'richtext',
					'help'  => 'Under a hairline at the foot of the card. The date, the address and a link back to the site belong here.',
				],
				[
					'key'   => 'email.smallprint',
					'label' => 'Small print',
					'type'  => 'text',
					'help'  => 'Outside the card, in grey - why this message arrived.',
				],
			],
		],

		'sponsor' => [
			'title'  => 'Sponsorship',
			'help'   => 'Everything written on the Sponsorship page. Each block below is the copy as it appears, in order down the page. The eight sponsorship levels are a list you can reorder, add to or delete from.',
			'fields' => [
				[ 'key' => 'sponsor.intro', 'label' => 'Opening paragraph', 'type' => 'richtext' ],
				[ 'key' => 'sponsor.cta_label', 'label' => 'Opening button - text', 'type' => 'text' ],
				[ 'key' => 'sponsor.tiers_eyebrow', 'label' => 'Levels - small label', 'type' => 'text' ],
				[ 'key' => 'sponsor.tiers_heading', 'label' => 'Levels - heading', 'type' => 'text',
				  'help' => 'Use <em>words</em> to set part of it in magenta.' ],
				[ 'key' => 'sponsor.tiers_intro', 'label' => 'Levels - intro', 'type' => 'richtext' ],
				[
					'key'   => 'sponsor.tiers',
					'label' => 'Sponsorship levels',
					'help'  => 'One row per level, in the order they appear. Benefits: one per line. Tone sets the card colour - platinum, gold, silver, bronze, support or custom.',
					'type'  => 'repeater',
					'cols'  => [
						[ 'label' => 'Level',       'type' => 'text',     'width' => '15%' ],
						[ 'label' => 'Title',       'type' => 'text',     'width' => '17%' ],
						[ 'label' => 'Price',       'type' => 'text',     'width' => '12%' ],
						[ 'label' => 'Description', 'type' => 'textarea', 'width' => '24%' ],
						[ 'label' => 'Benefits',    'type' => 'textarea', 'width' => '22%' ],
						[ 'label' => 'Tone',        'type' => 'text',     'width' => '10%' ],
					],
				],
				[ 'key' => 'sponsor.pdf_label', 'label' => 'Package button - text', 'type' => 'text' ],
				[ 'key' => 'sponsor.auction_eyebrow', 'label' => 'Silent auction - small label', 'type' => 'text' ],
				[ 'key' => 'sponsor.auction_heading', 'label' => 'Silent auction - heading', 'type' => 'text' ],
				[ 'key' => 'sponsor.auction_body', 'label' => 'Silent auction - copy', 'type' => 'richtext' ],
				[ 'key' => 'sponsor.auction_note', 'label' => 'Silent auction - delivery note', 'type' => 'richtext' ],
				[ 'key' => 'sponsor.form_eyebrow', 'label' => 'Commitment form - small label', 'type' => 'text' ],
				[ 'key' => 'sponsor.form_heading', 'label' => 'Commitment form - heading', 'type' => 'text' ],
				[ 'key' => 'sponsor.form_body', 'label' => 'Commitment form - copy above the form', 'type' => 'richtext' ],
				[ 'key' => 'sponsor.form_note', 'label' => 'Commitment form - small note below it', 'type' => 'richtext' ],
				[ 'key' => 'sponsor.logos_heading', 'label' => 'Thank-you - heading', 'type' => 'text' ],
				[ 'key' => 'sponsor.logos_body', 'label' => 'Thank-you - copy', 'type' => 'richtext' ],
			],
		],

		'brand' => [
			'title'  => 'Brand & images',
			'help'   => 'The images that are not part of any one page: the logo, the decorative flower artwork used across the sub-pages, and the card shown when a link is shared. Leave a field empty to keep the current image.',
			'fields' => [
				[
					'key'   => 'brand.logo',
					'ratio' => '1:1',
					'px'    => '512 x 512',
					'label' => 'Logo',
					'help'  => 'Used in the header, the footer and as the browser tab icon. A square-ish PNG with a transparent background works best.',
					'type'  => 'image',
				],
				[
					'key'   => 'brand.hero_flourish',
					'ratio' => '2:3',
					'px'    => '700 x 1050',
					'label' => 'Page-header flourish',
					'help'  => 'The flower artwork in the top corner of every sub-page header.',
					'type'  => 'image',
				],
				[
					'key'   => 'brand.flower_pattern',
					'ratio' => '1:1',
					'px'    => '900 x 900',
					'label' => 'Decorative flower pattern',
					'help'  => 'Used behind the event header, the call-to-action banner and flourished sections.',
					'type'  => 'image',
				],
				[
					'key'   => 'brand.share_card',
					'ratio' => '1200:630',
					'px'    => '1200 x 630',
					'label' => 'Link preview image',
					'help'  => 'Shown when someone shares a link in WhatsApp, Slack, Facebook or LinkedIn. 1200x630 works best.',
					'type'  => 'image',
				],
			],
		],

		'heroes' => [
			'title'  => 'Page headers',
			'help'   => 'The photograph behind each sub-page title. Leave empty to keep the current one.',
			'fields' => [
				[ 'key' => 'hero.about',   'label' => 'About',       'type' => 'image', 'ratio' => '3:1', 'px' => '1800 x 600' ],
				[ 'key' => 'hero.donate',  'label' => 'Donate',      'type' => 'image', 'ratio' => '3:1', 'px' => '1800 x 600' ],
				[ 'key' => 'hero.sponsor', 'label' => 'Sponsorship', 'type' => 'image', 'ratio' => '3:1', 'px' => '1800 x 600' ],
				[ 'key' => 'hero.faqs',    'label' => 'FAQs',        'type' => 'image', 'ratio' => '3:1', 'px' => '1800 x 600' ],
				[ 'key' => 'hero.gallery', 'label' => 'Gallery',     'type' => 'image', 'ratio' => '3:1', 'px' => '1800 x 600' ],
				[ 'key' => 'hero.rsvp',    'label' => 'RSVP',        'type' => 'image', 'ratio' => '3:1', 'px' => '1800 x 600' ],
				// No 50/50 entry: that page is rendered by the raffle plugin and
				// has no page-header block, so a picker here would silently do
				// nothing.
			],
		],

		'about' => [
			'title'  => 'About',
			'help'   => 'The alternating image-and-copy blocks on the About page. Reorder with the arrows; the body accepts links and formatting.',
			'fields' => [
				[
					'key'   => 'about.modules',
					'label' => 'About sections',
					'type'  => 'repeater',
					'cols'  => [
						[ 'label' => 'Image',    'type' => 'image',    'width' => '20%', 'ratio' => '4:3', 'px' => '1200 x 900' ],
						[ 'label' => 'Heading',  'type' => 'text',     'width' => '22%' ],
						[ 'label' => 'Body',     'type' => 'textarea', 'width' => '44%' ],
						[ 'label' => 'Image alt','type' => 'text',     'width' => '14%' ],
					],
				],
			],
		],

		'donate' => [
			'title'  => 'Donate',
			'fields' => [
				[ 'key' => 'donate.card1_eyebrow', 'label' => 'Card 1 - eyebrow', 'type' => 'text' ],
				[ 'key' => 'donate.card1_title',   'label' => 'Card 1 - title',   'type' => 'text' ],
				[ 'key' => 'donate.card1_body',    'label' => 'Card 1 - body',    'type' => 'richtext' ],
				[ 'key' => 'donate.card1_button',  'label' => 'Card 1 - button',  'type' => 'text' ],
				[ 'key' => 'donate.card1_url',     'label' => 'Card 1 - button link', 'type' => 'url' ],
				[ 'key' => 'donate.card2_eyebrow', 'label' => 'Card 2 - eyebrow', 'type' => 'text' ],
				[ 'key' => 'donate.card2_title',   'label' => 'Card 2 - title',   'type' => 'text' ],
				[ 'key' => 'donate.card2_body',    'label' => 'Card 2 - body',    'type' => 'richtext' ],
				[ 'key' => 'donate.card2_button',  'label' => 'Card 2 - button',  'type' => 'text' ],
			],
		],

		'faqs' => [
			'title'  => 'FAQs',
			'help'   => 'Add, edit, reorder or delete questions. Answers accept links and formatting.',
			'fields' => [
				[
					'key'   => 'faqs.items',
					'label' => 'Questions',
					'type'  => 'repeater',
					'cols'  => [
						[ 'label' => 'Question', 'type' => 'text',     'width' => '34%' ],
						[ 'label' => 'Answer',   'type' => 'textarea', 'width' => '66%' ],
					],
				],
			],
		],

		'rsvp' => [
			'title'  => 'RSVP & event details',
			'fields' => [
				[ 'key' => 'event.when',     'label' => 'When',        'type' => 'text' ],
				[ 'key' => 'event.when_sub', 'label' => 'When - note',  'type' => 'text' ],
				[ 'key' => 'event.where',    'label' => 'Where',       'type' => 'text' ],
				[ 'key' => 'event.where_sub','label' => 'Where - note', 'type' => 'text' ],
				[ 'key' => 'event.benefits', 'label' => 'Benefits',    'type' => 'text' ],
				[ 'key' => 'event.benefits_sub', 'label' => 'Benefits - note', 'type' => 'text' ],
				[ 'key' => 'event.bring',    'label' => 'Bring',       'type' => 'text' ],
				[ 'key' => 'event.bring_sub','label' => 'Bring - note', 'type' => 'text' ],
				[ 'key' => 'rsvp.title',     'label' => 'RSVP form - heading', 'type' => 'text' ],
				[ 'key' => 'rsvp.intro',     'label' => 'RSVP form - intro',   'type' => 'richtext' ],
			],
		],
	];
}

/* ── Admin screens ──────────────────────────────────────────────────── */

add_action( 'admin_menu', function () {
	add_menu_page(
		'BoH Content', 'BoH Content', BOH_CONTENT_CAP,
		'boh-content', 'boh_content_render_screen', 'dashicons-edit-page', 3
	);
	foreach ( boh_content_schema() as $slug => $group ) {
		add_submenu_page(
			'boh-content', $group['title'], $group['title'], BOH_CONTENT_CAP,
			$slug === array_key_first( boh_content_schema() ) ? 'boh-content' : 'boh-content-' . $slug,
			'boh_content_render_screen'
		);
	}
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( strpos( (string) $hook, 'boh-content' ) === false ) {
		return;
	}
	wp_enqueue_media();
} );

/** Which group is on screen, derived from the page slug. */
function boh_content_current_group(): string {
	$schema = boh_content_schema();
	$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'boh-content';
	if ( $page === 'boh-content' ) {
		return array_key_first( $schema );
	}
	$slug = substr( $page, strlen( 'boh-content-' ) );
	return isset( $schema[ $slug ] ) ? $slug : array_key_first( $schema );
}

function boh_content_render_screen(): void {
	if ( ! current_user_can( BOH_CONTENT_CAP ) ) {
		wp_die( 'You do not have permission to edit site content.' );
	}
	$schema  = boh_content_schema();
	$current = boh_content_current_group();
	$group   = $schema[ $current ];
	$saved   = false;

	$restored = false;
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
		check_admin_referer( 'boh_content_save' );
		if ( isset( $_POST['boh_restore'] ) ) {
			boh_content_restore( $group['fields'] );
			$restored = true;
		} else {
			boh_content_save( $group['fields'] );
			$saved = true;
		}
	}

	$stored = get_option( BOH_CONTENT_OPTION, [] );
	$stored = is_array( $stored ) ? $stored : [];
	?>
	<div class="wrap boh-content-admin">
		<h1>BoH Content - <?php echo esc_html( $group['title'] ); ?></h1>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p>Saved. <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank">View the site</a>.</p></div>
		<?php endif; ?>
		<?php if ( $restored ) : ?>
			<div class="notice notice-success is-dismissible"><p>This screen has been reset to the original content.</p></div>
		<?php endif; ?>

		<?php boh_content_tabs( $current ); ?>

		<?php if ( ! empty( $group['help'] ) ) : ?>
			<p class="description" style="margin:14px 0 0;max-width:760px"><?php echo esc_html( $group['help'] ); ?></p>
		<?php endif; ?>

		<style>
			.boh-content-admin .boh-field { background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:18px 20px; margin:18px 0; max-width:1100px; }
			.boh-content-admin .boh-field > label.boh-lab { display:block; font-weight:600; font-size:14px; margin-bottom:4px; }
			.boh-content-admin .boh-help { color:#646970; font-size:12px; margin:0 0 10px; }
			.boh-content-admin input[type=text], .boh-content-admin input[type=url], .boh-content-admin textarea { width:100%; }
			.boh-content-admin textarea { min-height:64px; }
			.boh-content-admin table.boh-rep { width:100%; border-collapse:collapse; }
			.boh-content-admin table.boh-rep th { text-align:left; font-size:12px; color:#646970; padding:4px 6px; }
			.boh-content-admin table.boh-rep td { padding:4px 6px; vertical-align:top; }
			.boh-content-admin .boh-thumb { display:block; width:100%; height:84px; object-fit:cover; background:#f0f0f1; border:1px solid #dcdcde; border-radius:4px; margin-bottom:6px; }
			.boh-content-admin .boh-rowbtns { white-space:nowrap; }
			.boh-content-admin .boh-img-wrap { max-width:280px; }
			.boh-content-admin .boh-img-spec {
				margin:0 0 8px; font-size:11px; color:#646970;
				display:flex; align-items:center; gap:8px;
			}
			.boh-content-admin .boh-switch { display:inline-flex; align-items:center; gap:9px; font-size:14px; }
			.boh-content-admin .boh-switch input { margin:0; }
			.boh-content-admin .boh-img-fit { margin:0 0 8px; font-size:11px; }
			.boh-content-admin .boh-fit-ok { color:#1c7c3f; font-weight:600; }
			.boh-content-admin .boh-fit-warn { color:#8a6d0b; }
			.boh-content-admin .boh-img-spec strong {
				background:#f0f0f1; border:1px solid #dcdcde; border-radius:999px;
				padding:1px 8px; font-size:11px; color:#1d2327; letter-spacing:.02em;
			}
		</style>

		<form method="post">
			<?php wp_nonce_field( 'boh_content_save' ); ?>
			<?php foreach ( $group['fields'] as $field ) : ?>
				<div class="boh-field">
					<label class="boh-lab"><?php echo esc_html( $field['label'] ); ?></label>
					<?php if ( ! empty( $field['help'] ) ) : ?>
						<p class="boh-help"><?php echo esc_html( $field['help'] ); ?></p>
					<?php endif; ?>
					<?php boh_content_render_field( $field, boh_content_effective( $field['key'], $stored ) ); ?>
				</div>
			<?php endforeach; ?>
			<p style="display:flex;align-items:center;gap:16px">
				<button type="submit" class="button button-primary button-large">Save changes</button>
				<button type="submit" name="boh_restore" value="1" class="button"
				        onclick="return confirm('Reset every field on this screen back to the original site content? Anything you have saved here will be replaced.');">Restore original content</button>
			</p>
		</form>

		<?php
		/**
		 * Anything a particular screen needs beyond its fields. The Emails
		 * screen hangs a preview and a test send here: a template can only
		 * really be judged as a message that has arrived.
		 */
		do_action( 'boh_content_after_screen', $current );
		do_action( 'boh_content_after_screen_' . $current );
		?>
	</div>
	<?php
	boh_content_admin_js();
}

/**
 * The tab strip, shared by every BoH Content screen and by the hero
 * slideshow page - which lives in the same menu and should not look like a
 * different part of the admin.
 *
 * $current is a schema key, or 'hero' for the slideshow screen.
 */
function boh_content_tabs( string $current ): void {
	$schema = boh_content_schema();
	$first  = array_key_first( $schema );

	$tabs = [
		'hero' => [
			'title' => 'Hero slideshow',
			'url'   => admin_url( 'admin.php?page=boh-hero-images' ),
		],
	];
	foreach ( $schema as $slug => $g ) {
		$tabs[ $slug ] = [
			'title' => $g['title'],
			'url'   => admin_url( 'admin.php?page=' . ( $slug === $first ? 'boh-content' : 'boh-content-' . $slug ) ),
		];
	}
	echo '<h2 class="nav-tab-wrapper">';
	foreach ( $tabs as $key => $tab ) {
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( $tab['url'] ),
			$key === $current ? 'nav-tab-active' : '',
			esc_html( $tab['title'] )
		);
	}
	echo '</h2>';
}

function boh_content_render_field( array $field, $value ): void {
	$name = 'boh[' . $field['key'] . ']';
	$id   = 'boh_' . preg_replace( '/[^a-z0-9]+/i', '_', $field['key'] );

	switch ( $field['type'] ) {
		case 'richtext':
			wp_editor(
				(string) $value,
				$id,
				[
					'textarea_name' => $name,
					'textarea_rows' => 6,
					'media_buttons' => true,
					'teeny'         => true,
				]
			);
			break;

		case 'textarea':
			printf(
				'<textarea name="%s" rows="3">%s</textarea>',
				esc_attr( $name ),
				esc_textarea( (string) $value )
			);
			break;

		case 'image':
			boh_content_image_control( $name, (string) $value, (string) ( $field['ratio'] ?? '' ), (string) ( $field['px'] ?? '' ) );
			break;

		case 'url':
			printf(
				'<input type="url" name="%s" value="%s" placeholder="https://">',
				esc_attr( $name ),
				esc_attr( (string) $value )
			);
			break;

		case 'toggle':
			// The hidden input is what makes "off" mean off. An unchecked box
			// posts nothing at all, and a missing value would fall back to the
			// shipped default - so switching the section off would silently
			// switch it back on.
			printf(
				'<input type="hidden" name="%s" value="0">'
				. '<label class="boh-switch"><input type="checkbox" name="%s" value="1" %s><span>%s</span></label>',
				esc_attr( $name ),
				esc_attr( $name ),
				checked( (string) $value, '1', false ),
				esc_html( $field['on_label'] ?? 'Show this section' )
			);
			break;

		case 'repeater':
			boh_content_repeater( $field, is_array( $value ) ? $value : [] );
			break;

		default:
			printf(
				'<input type="text" name="%s" value="%s">',
				esc_attr( $name ),
				esc_attr( (string) $value )
			);
	}
}

/**
 * Media-library picker. Stores the URL so shortcodes stay simple.
 *
 * $ratio ("3:1") and $px ("1800 x 600") describe the shape the image is
 * rendered in. They are shown to the editor and, when set, drive a crop step
 * after selection so an image can be made to fit rather than being silently
 * cropped by the browser.
 */
function boh_content_image_control( string $name, string $value, string $ratio = '', string $px = '' ): void {
	$w = $h = 0;
	if ( $ratio && strpos( $ratio, ':' ) !== false ) {
		[ $w, $h ] = array_map( 'intval', explode( ':', $ratio, 2 ) );
	}
	?>
	<?php
	// Resolve the attachment behind an already-saved URL, so the crop link
	// works for images chosen before now, not only ones picked this session.
	$existing_id = $value ? (int) attachment_url_to_postid( $value ) : 0;
	if ( ! $existing_id && $value ) {
		$existing_id = (int) attachment_url_to_postid( preg_replace( '/-\d+x\d+(\.[A-Za-z]+)$/', '$1', $value ) );
	}
	$dims = $existing_id ? wp_get_attachment_metadata( $existing_id ) : null;
	?>
	<div class="boh-img-wrap boh-img" data-boh-image
	     data-ratio-w="<?php echo (int) $w; ?>" data-ratio-h="<?php echo (int) $h; ?>"
	     data-attachment-id="<?php echo (int) $existing_id; ?>"
	     data-img-w="<?php echo (int) ( $dims['width'] ?? 0 ); ?>"
	     data-img-h="<?php echo (int) ( $dims['height'] ?? 0 ); ?>"
	     data-edit-base="<?php echo esc_attr( admin_url( 'post.php?post=__ID__&action=edit&image-editor=1' ) ); ?>">
		<img class="boh-thumb" src="<?php echo esc_url( $value ); ?>" alt=""
		     style="<?php echo $value ? '' : 'display:none'; ?><?php echo $w && $h ? ';aspect-ratio:' . (int) $w . '/' . (int) $h . ';height:auto' : ''; ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
		<?php if ( $ratio ) : ?>
			<p class="boh-img-spec">
				<strong><?php echo esc_html( $ratio ); ?></strong>
				<?php if ( $px ) : ?><span><?php echo esc_html( $px ); ?>px</span><?php endif; ?>
			</p>
		<?php endif; ?>
		<p class="boh-img-fit"></p>
		<button type="button" class="button boh-pick">Choose image</button>
		<button type="button" class="button-link boh-clear" style="margin-left:8px;color:#b32d2e">Remove</button>
	</div>
	<?php
}

function boh_content_repeater( array $field, array $rows ): void {
	$cols = $field['cols'];
	?>
	<table class="boh-rep" data-boh-repeater data-key="<?php echo esc_attr( $field['key'] ); ?>">
		<thead>
			<tr>
				<?php foreach ( $cols as $c ) : ?>
					<th style="width:<?php echo esc_attr( $c['width'] ?? 'auto' ); ?>"><?php echo esc_html( $c['label'] ); ?></th>
				<?php endforeach; ?>
				<th style="width:110px"></th>
			</tr>
		</thead>
		<tbody>
		<?php
		if ( ! $rows ) {
			$rows = [ array_fill( 0, count( $cols ), '' ) ];
		}
		foreach ( $rows as $ri => $row ) :
			$row = array_values( (array) $row );
			?>
			<tr>
				<?php foreach ( $cols as $ci => $c ) :
					$cell = $row[ $ci ] ?? '';
					$cname = 'boh[' . $field['key'] . '][' . $ri . '][' . $ci . ']';
					?>
					<td>
						<?php if ( ( $c['type'] ?? 'text' ) === 'textarea' ) : ?>
							<textarea name="<?php echo esc_attr( $cname ); ?>" rows="3"><?php echo esc_textarea( (string) $cell ); ?></textarea>
						<?php elseif ( ( $c['type'] ?? '' ) === 'image' ) : ?>
							<?php boh_content_image_control( $cname, (string) $cell, (string) ( $c['ratio'] ?? '' ), (string) ( $c['px'] ?? '' ) ); ?>
						<?php else : ?>
							<input type="text" name="<?php echo esc_attr( $cname ); ?>" value="<?php echo esc_attr( (string) $cell ); ?>">
						<?php endif; ?>
					</td>
				<?php endforeach; ?>
				<td class="boh-rowbtns">
					<button type="button" class="button boh-up" title="Move up">↑</button>
					<button type="button" class="button boh-down" title="Move down">↓</button>
					<button type="button" class="button boh-del" title="Delete row">✕</button>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p><button type="button" class="button boh-add">Add row</button></p>
	<?php
}

/**
 * Persist a screen's fields, merging into the shared option so the other
 * screens' values survive.
 */
function boh_content_save( array $fields ): void {
	$stored = get_option( BOH_CONTENT_OPTION, [] );
	$stored = is_array( $stored ) ? $stored : [];
	$raw    = isset( $_POST['boh'] ) && is_array( $_POST['boh'] ) ? wp_unslash( $_POST['boh'] ) : [];

	foreach ( $fields as $field ) {
		$key = $field['key'];
		$val = $raw[ $key ] ?? null;

		if ( $field['type'] === 'repeater' ) {
			$rows = [];
			foreach ( (array) $val as $row ) {
				$clean = [];
				foreach ( $field['cols'] as $ci => $c ) {
					$cell = (string) ( $row[ $ci ] ?? '' );
					$clean[] = ( $c['type'] ?? '' ) === 'image'
						? esc_url_raw( $cell )
						: sanitize_textarea_field( $cell );
				}
				if ( boh_content_row_has_value( $clean ) ) {
					$rows[] = $clean;
				}
			}
			$stored[ $key ] = $rows;
			continue;
		}

		$val = (string) $val;
		switch ( $field['type'] ) {
			case 'richtext':
				// wp_kses_post, not sanitize_text_field: this field exists so the
				// team can add links and emphasis.
				$stored[ $key ] = wp_kses_post( $val );
				break;
			case 'image':
			case 'url':
				$stored[ $key ] = esc_url_raw( $val );
				break;
			case 'textarea':
				$stored[ $key ] = sanitize_textarea_field( $val );
				break;
			case 'toggle':
				$stored[ $key ] = $val === '1' ? '1' : '0';
				break;
			default:
				$stored[ $key ] = sanitize_text_field( $val );
		}
	}

	// Keep the previous state so a mistaken save is recoverable. Twelve FAQs
	// were once replaced by two test rows this way, before the fields
	// pre-filled; the shipped copy is always recoverable via "Restore
	// original content", and this covers edits made after that.
	$prior = get_option( BOH_CONTENT_OPTION, [] );
	update_option( 'boh_content_previous', is_array( $prior ) ? $prior : [], false );

	update_option( BOH_CONTENT_OPTION, $stored );
	// The gallery//front-page caches hold rendered copy.
	delete_transient( 'boh_gallery_items_v2' );
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}

/**
 * Reset one screen's fields to the values the theme ships, discarding any
 * saved overrides for those keys only.
 */
function boh_content_restore( array $fields ): void {
	$stored   = get_option( BOH_CONTENT_OPTION, [] );
	$stored   = is_array( $stored ) ? $stored : [];
	$prior    = $stored;
	$defaults = boh_content_defaults();

	foreach ( $fields as $field ) {
		$key = $field['key'];
		if ( array_key_exists( $key, $defaults ) ) {
			$stored[ $key ] = $defaults[ $key ];
		} else {
			unset( $stored[ $key ] );
		}
	}

	update_option( 'boh_content_previous', $prior, false );
	update_option( BOH_CONTENT_OPTION, $stored );
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}

/** Repeater + media-picker behaviour. Vanilla JS; no jQuery UI dependency. */
function boh_content_admin_js(): void {
	?>
	<script>
	(function () {
		// --- media picker -------------------------------------------------
		document.addEventListener('click', function (e) {
			var pick = e.target.closest('[data-boh-image] .boh-pick');
			var clear = e.target.closest('[data-boh-image] .boh-clear');
			if (clear) {
				e.preventDefault();
				var w = clear.closest('[data-boh-image]');
				w.querySelector('input[type=hidden]').value = '';
				var im = w.querySelector('img'); im.src = ''; im.style.display = 'none';
				return;
			}
			if (!pick) return;
			e.preventDefault();
			var wrap = pick.closest('[data-boh-image]');
			var rw = parseInt(wrap.dataset.ratioW, 10) || 0;
			var rh = parseInt(wrap.dataset.ratioH, 10) || 0;

			function apply(url, id, w, h) {
				wrap.querySelector('input[type=hidden]').value = url;
				var img = wrap.querySelector('img');
				img.src = url; img.style.display = '';
				if (id) { wrap.dataset.attachmentId = id; }
				bohMarkFit(wrap, w, h);
			}
			// Prefer a sized copy: originals here can be many megabytes and
			// every one of these boxes renders small.
			function bestUrl(a) {
				var s = a.sizes || {};
				var pick = s.large || s.medium_large || s.medium;
				return pick ? pick.url : a.url;
			}

			var frame = wp.media({ title: 'Choose an image', multiple: false, library: { type: 'image' } });
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				apply(bestUrl(a), a.id, a.width, a.height);
			});
			frame.on('open', function () { frame.content.mode('browse'); });
			frame.open();
		});

		/* --- does the chosen image fit the box? ---------------------------
		   Rather than a bespoke cropper - the one tried here opened blank,
		   because core's Cropper state expects to be reached from a library
		   selection and CustomizeImageCropper does not exist outside the
		   customizer - the control says whether the image matches the shape
		   and links to WordPress's own image editor, which crops reliably. */
		function bohMarkFit(wrap, w, h) {
			var note = wrap.querySelector('.boh-img-fit');
			if (!note) return;
			var rw = parseInt(wrap.dataset.ratioW, 10) || 0;
			var rh = parseInt(wrap.dataset.ratioH, 10) || 0;
			var id = wrap.dataset.attachmentId;
			if (!rw || !rh || !w || !h) { note.innerHTML = ''; return; }
			var fits = Math.abs((rw / rh) - (w / h)) < 0.02;
			if (fits) {
				note.innerHTML = '<span class="boh-fit-ok">Fits this box</span>';
				return;
			}
			var href = wrap.dataset.editBase && id ? wrap.dataset.editBase.replace('__ID__', id) : '';
			note.innerHTML = '<span class="boh-fit-warn">' + w + ' x ' + h +
				' - will be cropped to fit</span>' +
				(href ? ' <a href="' + href + '" target="_blank" rel="noopener">Crop it</a>' : '');
		}

		// Assess whatever is already saved, so the notice is right on arrival.
		document.querySelectorAll('[data-boh-image]').forEach(function (wrap) {
			bohMarkFit(wrap, parseInt(wrap.dataset.imgW, 10) || 0, parseInt(wrap.dataset.imgH, 10) || 0);
		});

		// --- repeater rows ------------------------------------------------
		function reindex(table) {
			var key = table.dataset.key;
			table.querySelectorAll('tbody > tr').forEach(function (tr, ri) {
				tr.querySelectorAll('[name]').forEach(function (el) {
					var m = el.name.match(/\[(\d+)\]$/);
					if (!m) return;
					el.name = 'boh[' + key + '][' + ri + '][' + m[1] + ']';
				});
			});
		}
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.boh-add, .boh-del, .boh-up, .boh-down');
			if (!btn) return;
			e.preventDefault();
			var table = btn.classList.contains('boh-add')
				? btn.closest('p').previousElementSibling
				: btn.closest('table');
			if (!table || !table.matches('[data-boh-repeater]')) return;
			var body = table.querySelector('tbody');
			var tr = btn.closest('tr');

			if (btn.classList.contains('boh-add')) {
				var clone = body.lastElementChild.cloneNode(true);
				clone.querySelectorAll('input, textarea').forEach(function (el) {
					if (el.type === 'hidden') { el.value = ''; } else { el.value = ''; }
				});
				clone.querySelectorAll('img').forEach(function (im) { im.src = ''; im.style.display = 'none'; });
				body.appendChild(clone);
			} else if (btn.classList.contains('boh-del')) {
				if (body.children.length > 1) { tr.remove(); }
				else { tr.querySelectorAll('input, textarea').forEach(function (el) { el.value = ''; }); }
			} else if (btn.classList.contains('boh-up') && tr.previousElementSibling) {
				body.insertBefore(tr, tr.previousElementSibling);
			} else if (btn.classList.contains('boh-down') && tr.nextElementSibling) {
				body.insertBefore(tr.nextElementSibling, tr);
			}
			reindex(table);
		});
	})();
	</script>
	<?php
}
