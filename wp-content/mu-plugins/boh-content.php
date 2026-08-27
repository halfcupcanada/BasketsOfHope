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
	$value = $all[ $key ] ?? null;

	if ( $value === null || $value === '' ) {
		return $default;
	}
	// An empty repeater means "nothing saved", not "show nothing" — a blank
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
					'label' => 'How it works — steps',
					'help'  => 'Four steps on the home page. The image is used on both the home and About versions.',
					'type'  => 'repeater',
					'cols'  => [
						[ 'label' => 'Number',      'type' => 'text',     'width' => '8%'  ],
						[ 'label' => 'Title',       'type' => 'text',     'width' => '20%' ],
						[ 'label' => 'Full text',   'type' => 'textarea', 'width' => '28%' ],
						[ 'label' => 'Short text',  'type' => 'textarea', 'width' => '24%' ],
						[ 'label' => 'Image',       'type' => 'image',    'width' => '20%' ],
					],
				],
				[
					'key'   => 'home.steps_heading',
					'label' => 'How it works — heading',
					'type'  => 'text',
				],
				[
					'key'   => 'home.steps_lede',
					'label' => 'How it works — intro',
					'type'  => 'richtext',
					'help'  => 'Links are allowed here — use the link button to point at the Donate page.',
				],
			],
		],

		'heroes' => [
			'title'  => 'Page headers',
			'help'   => 'The photograph behind each sub-page title. Leave empty to keep the current one.',
			'fields' => [
				[ 'key' => 'hero.about',   'label' => 'About',       'type' => 'image' ],
				[ 'key' => 'hero.donate',  'label' => 'Donate',      'type' => 'image' ],
				[ 'key' => 'hero.sponsor', 'label' => 'Sponsorship', 'type' => 'image' ],
				[ 'key' => 'hero.faqs',    'label' => 'FAQs',        'type' => 'image' ],
				[ 'key' => 'hero.gallery', 'label' => 'Gallery',     'type' => 'image' ],
				[ 'key' => 'hero.rsvp',    'label' => 'RSVP',        'type' => 'image' ],
				[ 'key' => 'hero.5050',    'label' => '50/50 Raffle','type' => 'image' ],
			],
		],

		'donate' => [
			'title'  => 'Donate',
			'fields' => [
				[ 'key' => 'donate.card1_eyebrow', 'label' => 'Card 1 — eyebrow', 'type' => 'text' ],
				[ 'key' => 'donate.card1_title',   'label' => 'Card 1 — title',   'type' => 'text' ],
				[ 'key' => 'donate.card1_body',    'label' => 'Card 1 — body',    'type' => 'richtext' ],
				[ 'key' => 'donate.card1_button',  'label' => 'Card 1 — button',  'type' => 'text' ],
				[ 'key' => 'donate.card1_url',     'label' => 'Card 1 — button link', 'type' => 'url' ],
				[ 'key' => 'donate.card2_eyebrow', 'label' => 'Card 2 — eyebrow', 'type' => 'text' ],
				[ 'key' => 'donate.card2_title',   'label' => 'Card 2 — title',   'type' => 'text' ],
				[ 'key' => 'donate.card2_body',    'label' => 'Card 2 — body',    'type' => 'richtext' ],
				[ 'key' => 'donate.card2_button',  'label' => 'Card 2 — button',  'type' => 'text' ],
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
				[ 'key' => 'event.when_sub', 'label' => 'When — note',  'type' => 'text' ],
				[ 'key' => 'event.where',    'label' => 'Where',       'type' => 'text' ],
				[ 'key' => 'event.where_sub','label' => 'Where — note', 'type' => 'text' ],
				[ 'key' => 'event.benefits', 'label' => 'Benefits',    'type' => 'text' ],
				[ 'key' => 'event.benefits_sub', 'label' => 'Benefits — note', 'type' => 'text' ],
				[ 'key' => 'event.bring',    'label' => 'Bring',       'type' => 'text' ],
				[ 'key' => 'event.bring_sub','label' => 'Bring — note', 'type' => 'text' ],
				[ 'key' => 'rsvp.title',     'label' => 'RSVP form — heading', 'type' => 'text' ],
				[ 'key' => 'rsvp.intro',     'label' => 'RSVP form — intro',   'type' => 'richtext' ],
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

	if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
		check_admin_referer( 'boh_content_save' );
		boh_content_save( $group['fields'] );
		$saved = true;
	}

	$stored = get_option( BOH_CONTENT_OPTION, [] );
	$stored = is_array( $stored ) ? $stored : [];
	?>
	<div class="wrap boh-content-admin">
		<h1>BoH Content — <?php echo esc_html( $group['title'] ); ?></h1>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p>Saved. <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank">View the site</a>.</p></div>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper">
			<?php
			$first = array_key_first( $schema );
			foreach ( $schema as $slug => $g ) :
				$url = admin_url( 'admin.php?page=' . ( $slug === $first ? 'boh-content' : 'boh-content-' . $slug ) );
				?>
				<a href="<?php echo esc_url( $url ); ?>"
				   class="nav-tab <?php echo $slug === $current ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $g['title'] ); ?></a>
			<?php endforeach; ?>
		</h2>

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
		</style>

		<form method="post">
			<?php wp_nonce_field( 'boh_content_save' ); ?>
			<?php foreach ( $group['fields'] as $field ) : ?>
				<div class="boh-field">
					<label class="boh-lab"><?php echo esc_html( $field['label'] ); ?></label>
					<?php if ( ! empty( $field['help'] ) ) : ?>
						<p class="boh-help"><?php echo esc_html( $field['help'] ); ?></p>
					<?php endif; ?>
					<?php boh_content_render_field( $field, $stored[ $field['key'] ] ?? null ); ?>
				</div>
			<?php endforeach; ?>
			<p><button type="submit" class="button button-primary button-large">Save changes</button></p>
		</form>
	</div>
	<?php
	boh_content_admin_js();
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
			boh_content_image_control( $name, (string) $value );
			break;

		case 'url':
			printf(
				'<input type="url" name="%s" value="%s" placeholder="https://">',
				esc_attr( $name ),
				esc_attr( (string) $value )
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

/** Media-library picker. Stores the URL so shortcodes stay simple. */
function boh_content_image_control( string $name, string $value ): void {
	?>
	<div class="boh-img-wrap boh-img" data-boh-image>
		<img class="boh-thumb" src="<?php echo esc_url( $value ); ?>" alt=""
		     style="<?php echo $value ? '' : 'display:none'; ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
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
							<?php boh_content_image_control( $cname, (string) $cell ); ?>
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
			default:
				$stored[ $key ] = sanitize_text_field( $val );
		}
	}

	update_option( BOH_CONTENT_OPTION, $stored );
	// The gallery//front-page caches hold rendered copy.
	delete_transient( 'boh_gallery_items_v2' );
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
			var frame = wp.media({ title: 'Choose an image', multiple: false, library: { type: 'image' } });
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				// Prefer a sized copy: the original can be many megabytes and
				// these are all rendered small.
				var url = (a.sizes && (a.sizes.large || a.sizes.medium_large || a.sizes.medium))
					? (a.sizes.large || a.sizes.medium_large || a.sizes.medium).url
					: a.url;
				wrap.querySelector('input[type=hidden]').value = url;
				var img = wrap.querySelector('img');
				img.src = url; img.style.display = '';
			});
			frame.open();
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
