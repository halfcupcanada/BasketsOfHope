<?php
/**
 * Plugin Name: BoH gallery — media library backed
 * Description: Moves the gallery from "scan a folder" to "choose from the
 *              media library". Photos are grouped by event year using a
 *              taxonomy, which is how WordPress does folders, and the gallery
 *              stores attachment IDs rather than file paths — so one library
 *              entry can appear in the gallery and anywhere else on the site
 *              without a second copy of the file.
 *
 *              Falls back to the old folder scan for any year that has no
 *              selection saved, so nothing changes until a year is curated.
 */

defined( 'ABSPATH' ) || exit;

const BOH_GALLERY_TAX       = 'boh_gallery_year';
const BOH_GALLERY_SELECTION = 'boh_gallery_selection';

/* ── Year grouping ──────────────────────────────────────────────────── */

add_action( 'init', function () {
	register_taxonomy( BOH_GALLERY_TAX, 'attachment', [
		'label'             => 'Event year',
		'labels'            => [
			'name'          => 'Event years',
			'singular_name' => 'Event year',
			'menu_name'     => 'Event years',
			'all_items'     => 'All event years',
		],
		'public'            => false,
		'show_ui'           => true,
		'show_admin_column' => true,
		'show_in_rest'      => true,
		'hierarchical'      => false,
		'rewrite'           => false,
	] );
} );

/** Year dropdown above the media library list, so it reads as folders. */
add_action( 'restrict_manage_posts', function ( $post_type ) {
	if ( $post_type !== 'attachment' ) {
		return;
	}
	$terms = get_terms( [ 'taxonomy' => BOH_GALLERY_TAX, 'hide_empty' => false ] );
	if ( is_wp_error( $terms ) || ! $terms ) {
		return;
	}
	$current = isset( $_GET[ BOH_GALLERY_TAX ] ) ? sanitize_text_field( wp_unslash( $_GET[ BOH_GALLERY_TAX ] ) ) : '';
	echo '<select name="' . esc_attr( BOH_GALLERY_TAX ) . '"><option value="">All event years</option>';
	foreach ( $terms as $t ) {
		printf(
			'<option value="%s"%s>%s (%d)</option>',
			esc_attr( $t->slug ),
			selected( $current, $t->slug, false ),
			esc_html( $t->name ),
			(int) $t->count
		);
	}
	echo '</select>';
} );

/**
 * Give an attachment its event year.
 */
function boh_gallery_set_year( int $attachment_id, int $year ): void {
	if ( $year ) {
		wp_set_object_terms( $attachment_id, (string) $year, BOH_GALLERY_TAX, false );
	}
}

/** Attachments tagged with a given year, newest-first by menu order then date. */
function boh_gallery_year_attachments( int $year ): array {
	$q = new WP_Query( [
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => [ 'menu_order' => 'ASC', 'date' => 'ASC' ],
		'tax_query'      => [ [
			'taxonomy' => BOH_GALLERY_TAX,
			'field'    => 'slug',
			'terms'    => (string) $year,
		] ],
	] );
	return array_map( 'intval', $q->posts );
}

/* ── Which images are in the gallery ────────────────────────────────── */

/** @return array<int, int[]> year => ordered attachment IDs */
function boh_gallery_selection(): array {
	$sel = get_option( BOH_GALLERY_SELECTION, [] );
	if ( ! is_array( $sel ) ) {
		return [];
	}
	$out = [];
	foreach ( $sel as $year => $ids ) {
		$year = (int) $year;
		$ids  = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		if ( $year && $ids ) {
			$out[ $year ] = $ids;
		}
	}
	return $out;
}

function boh_gallery_save_selection( int $year, array $ids ): void {
	$sel = get_option( BOH_GALLERY_SELECTION, [] );
	$sel = is_array( $sel ) ? $sel : [];
	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

	if ( $ids ) {
		$sel[ $year ] = $ids;
	} else {
		// An empty year means "not curated", which falls back to the folder
		// scan — rather than "show nothing", which would silently empty a
		// whole section of the public page.
		unset( $sel[ $year ] );
	}
	update_option( BOH_GALLERY_SELECTION, $sel, false );

	foreach ( $ids as $id ) {
		boh_gallery_set_year( $id, $year );
	}
	delete_transient( 'boh_gallery_items_v2' );
}

/* ── Turning an attachment into a gallery item ──────────────────────── */

/**
 * Build the item shape the gallery templates expect.
 *
 * Prefers the WebP copies built for the folder-based gallery when the file
 * still lives there — they are far smaller than anything WordPress generates
 * — and falls back to the attachment's own registered sizes otherwise, so an
 * image chosen from anywhere in the library works.
 */
function boh_gallery_item_from_attachment( int $id ): ?array {
	$file = get_post_meta( $id, '_wp_attached_file', true );
	if ( ! $file ) {
		return null;
	}
	$mime = (string) get_post_mime_type( $id );
	$url  = wp_get_attachment_url( $id );
	if ( ! $url ) {
		return null;
	}

	$post    = get_post( $id );
	$caption = $post ? trim( (string) $post->post_excerpt ) : '';
	$alt     = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );

	if ( strpos( $mime, 'video/' ) === 0 ) {
		$poster_id = (int) get_post_thumbnail_id( $id );
		return [
			'type'    => 'video',
			'url'     => $url,
			'caption' => $caption ?: $alt,
			'poster'  => $poster_id ? (string) wp_get_attachment_url( $poster_id ) : '',
		];
	}

	$item = [
		'type'    => 'image',
		'url'     => $url,
		'caption' => $caption ?: $alt,
	];

	// 1. The purpose-built WebP copies, when this file lives in a year folder.
	if ( preg_match( '#^gallery/(\d{4})/(.+)$#', $file, $m )
		&& function_exists( 'boh_gallery_item_sources' ) ) {
		$src = boh_gallery_item_sources( (int) $m[1], basename( $m[2] ), $url );
		if ( ! empty( $src['thumb'] ) && $src['thumb'] !== $url ) {
			return array_merge( $item, $src );
		}
	}

	// 2. Otherwise WordPress's own sizes.
	$meta   = wp_get_attachment_metadata( $id );
	$w      = (int) ( $meta['width'] ?? 0 );
	$h      = (int) ( $meta['height'] ?? 0 );
	$pick   = function ( array $names ) use ( $id, $url ) {
		foreach ( $names as $n ) {
			$s = wp_get_attachment_image_url( $id, $n );
			if ( $s ) {
				return $s;
			}
		}
		return $url;
	};

	return array_merge( $item, [
		'small' => $pick( [ 'medium', 'medium_large', 'large' ] ),
		'thumb' => $pick( [ 'medium_large', 'large', 'medium' ] ),
		'large' => $pick( [ 'large', 'full' ] ),
		'w'     => $w,
		'h'     => $h,
	] );
}

/**
 * Serve curated years from the library. Runs after the folder scanner so a
 * curated year replaces the scanned one, and an uncurated year keeps working
 * exactly as before.
 */
add_filter( 'boh_gallery_items', function ( $items ) {
	$selection = boh_gallery_selection();
	if ( ! $selection ) {
		return $items;
	}
	$items = is_array( $items ) ? $items : [];

	foreach ( $selection as $year => $ids ) {
		$built = [];
		foreach ( $ids as $id ) {
			$one = boh_gallery_item_from_attachment( $id );
			if ( $one ) {
				$built[] = $one;
			}
		}
		if ( $built ) {
			$items[ $year ] = $built;
		}
	}
	krsort( $items );
	return $items;
}, 20 );

/* ── Admin: choose which library images appear, per year ─────────────── */

add_action( 'admin_menu', function () {
	add_media_page(
		'Gallery Images', 'Gallery Images', 'upload_files',
		'boh-gallery-select', 'boh_gallery_select_screen'
	);
}, 11 );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( strpos( (string) $hook, 'boh-gallery-select' ) !== false ) {
		wp_enqueue_media();
	}
} );

function boh_gallery_select_screen(): void {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( 'You need permission to manage media.' );
	}
	$years = defined( 'BOH_GALLERY_UPLOADER_YEARS' ) ? BOH_GALLERY_UPLOADER_YEARS : [ 2026, 2025, 2024, 2023, 2022 ];
	$year  = isset( $_REQUEST['gyear'] ) ? (int) $_REQUEST['gyear'] : (int) $years[0];
	if ( ! in_array( $year, (array) $years, true ) ) {
		$year = (int) $years[0];
	}
	$notice = '';

	if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
		check_admin_referer( 'boh_gallery_select' );
		$ids = array_filter( array_map( 'intval', explode( ',', (string) ( $_POST['boh_gallery_ids'] ?? '' ) ) ) );
		boh_gallery_save_selection( $year, $ids );
		$notice = $ids
			? sprintf( '%d image%s now shown for %d.', count( $ids ), count( $ids ) === 1 ? '' : 's', $year )
			: sprintf( 'Selection cleared for %d — that year falls back to whatever is in its upload folder.', $year );
	}

	$selection = boh_gallery_selection();
	$current   = $selection[ $year ] ?? [];
	$scanned   = 0;
	if ( ! $current ) {
		$all     = apply_filters( 'boh_gallery_items', [] );
		$scanned = isset( $all[ $year ] ) ? count( $all[ $year ] ) : 0;
	}
	?>
	<div class="wrap">
		<h1>Gallery Images — <?php echo (int) $year; ?></h1>
		<p class="description" style="max-width:820px">
			Choose which photographs appear in the <a href="<?php echo esc_url( home_url( '/gallery/' ) ); ?>" target="_blank">public gallery</a> for this year.
			Images come from the media library, so the same file can be used here and elsewhere on the site without a second copy.
			Uploading from this screen adds to the library and tags it with the year.
		</p>

		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper">
			<?php foreach ( (array) $years as $y ) : ?>
				<a class="nav-tab <?php echo (int) $y === $year ? 'nav-tab-active' : ''; ?>"
				   href="<?php echo esc_url( admin_url( 'upload.php?page=boh-gallery-select&gyear=' . (int) $y ) ); ?>"><?php echo (int) $y; ?></a>
			<?php endforeach; ?>
		</h2>

		<?php if ( ! $current && $scanned ) : ?>
			<div class="notice notice-info" style="margin:16px 0"><p>
				<?php printf(
					'%d photo%s are currently shown for %d from its upload folder. Choose images below to curate the year instead — until you do, nothing changes.',
					(int) $scanned, $scanned === 1 ? '' : 's', (int) $year
				); ?>
			</p></div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'boh_gallery_select' ); ?>
			<input type="hidden" name="gyear" value="<?php echo (int) $year; ?>">
			<input type="hidden" name="boh_gallery_ids" id="boh_gallery_ids" value="<?php echo esc_attr( implode( ',', $current ) ); ?>">

			<p style="margin:16px 0">
				<button type="button" class="button button-primary" id="boh-pick">Choose from media library</button>
				<button type="button" class="button" id="boh-upload">Upload new images</button>
				<button type="button" class="button" id="boh-clear" style="color:#b32d2e">Remove all</button>
				<span class="description" style="margin-left:10px">Drag to reorder. Click ✕ on an image to remove it from the gallery — it stays in the media library.</span>
			</p>

			<div id="boh-grid" class="boh-pick-grid"></div>

			<p style="margin-top:18px">
				<button type="submit" class="button button-primary button-large">Save gallery for <?php echo (int) $year; ?></button>
			</p>
		</form>

		<style>
			.boh-pick-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; max-width:1200px; }
			.boh-pick-cell { position:relative; aspect-ratio:4/3; background:#f0f0f1; border:1px solid #dcdcde; border-radius:6px; overflow:hidden; cursor:grab; }
			.boh-pick-cell img { width:100%; height:100%; object-fit:cover; display:block; }
			.boh-pick-cell .rm { position:absolute; top:6px; right:6px; background:rgba(200,30,30,.92); color:#fff; border:0; border-radius:999px; width:24px; height:24px; cursor:pointer; line-height:1; }
			.boh-pick-cell .n { position:absolute; bottom:0; left:0; right:0; background:linear-gradient(transparent,rgba(0,0,0,.78)); color:#fff; font-size:11px; padding:10px 6px 4px; }
			.boh-pick-cell.dragging { opacity:.4; }
			.boh-pick-empty { color:#666; padding:22px 0; }
		</style>

		<script>
		(function () {
			var input = document.getElementById('boh_gallery_ids');
			var grid  = document.getElementById('boh-grid');
			var cache = {};

			function ids() {
				return input.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
			}
			function setIds(list) { input.value = list.join(','); render(); }

			function render() {
				var list = ids();
				grid.innerHTML = '';
				if (!list.length) {
					grid.innerHTML = '<p class="boh-pick-empty">No images chosen yet for this year.</p>';
					return;
				}
				list.forEach(function (id) {
					var cell = document.createElement('div');
					cell.className = 'boh-pick-cell';
					cell.draggable = true;
					cell.dataset.id = id;
					var src = cache[id] || '';
					cell.innerHTML = '<img src="' + src + '" alt="">' +
						'<button type="button" class="rm" title="Remove from gallery">&times;</button>' +
						'<span class="n">#' + id + '</span>';
					grid.appendChild(cell);
				});
			}

			// Look up thumbnails for anything we do not already know about.
			function hydrate(list, done) {
				var unknown = list.filter(function (id) { return !cache[id]; });
				if (!unknown.length) { done(); return; }
				wp.apiFetch({ path: '/wp/v2/media?include=' + unknown.join(',') + '&per_page=100&_fields=id,media_details,source_url' })
					.then(function (items) {
						items.forEach(function (m) {
							var s = (m.media_details && m.media_details.sizes) || {};
							cache[m.id] = (s.thumbnail && s.thumbnail.source_url) || (s.medium && s.medium.source_url) || m.source_url;
						});
						done();
					})
					.catch(function () { done(); });
			}

			document.getElementById('boh-pick').addEventListener('click', function () {
				var frame = wp.media({
					title: 'Choose gallery images',
					multiple: 'add',
					library: { type: 'image' },
					button: { text: 'Add to gallery' }
				});
				frame.on('select', function () {
					var chosen = frame.state().get('selection').toJSON();
					var list = ids();
					chosen.forEach(function (a) {
						cache[a.id] = (a.sizes && (a.sizes.thumbnail || a.sizes.medium) || {}).url || a.url;
						if (list.indexOf(String(a.id)) === -1) { list.push(String(a.id)); }
					});
					setIds(list);
				});
				frame.open();
			});

			// Upload goes through the same library, so nothing is duplicated.
			document.getElementById('boh-upload').addEventListener('click', function () {
				var frame = wp.media({
					title: 'Upload gallery images',
					multiple: 'add',
					library: { type: 'image' },
					button: { text: 'Add to gallery' }
				});
				frame.on('ready', function () {
					// Land on the upload tab rather than the browse tab.
					var router = frame.views.get('.media-frame-router')[0];
					if (router) { frame.content.mode('upload'); }
				});
				frame.on('select', function () {
					var chosen = frame.state().get('selection').toJSON();
					var list = ids();
					chosen.forEach(function (a) {
						cache[a.id] = (a.sizes && (a.sizes.thumbnail || a.sizes.medium) || {}).url || a.url;
						if (list.indexOf(String(a.id)) === -1) { list.push(String(a.id)); }
					});
					setIds(list);
				});
				frame.open();
			});

			document.getElementById('boh-clear').addEventListener('click', function () {
				if (confirm('Remove every image from this year’s gallery? The files stay in the media library.')) {
					setIds([]);
				}
			});

			grid.addEventListener('click', function (e) {
				var rm = e.target.closest('.rm');
				if (!rm) return;
				var id = rm.closest('.boh-pick-cell').dataset.id;
				setIds(ids().filter(function (x) { return x !== id; }));
			});

			// Drag to reorder.
			var dragging = null;
			grid.addEventListener('dragstart', function (e) {
				var cell = e.target.closest('.boh-pick-cell');
				if (!cell) return;
				dragging = cell; cell.classList.add('dragging');
			});
			grid.addEventListener('dragend', function () {
				if (dragging) { dragging.classList.remove('dragging'); dragging = null; }
				setIds([].map.call(grid.querySelectorAll('.boh-pick-cell'), function (c) { return c.dataset.id; }));
			});
			grid.addEventListener('dragover', function (e) {
				e.preventDefault();
				var over = e.target.closest('.boh-pick-cell');
				if (!over || !dragging || over === dragging) return;
				var cells = [].slice.call(grid.querySelectorAll('.boh-pick-cell'));
				var from = cells.indexOf(dragging), to = cells.indexOf(over);
				grid.insertBefore(dragging, from < to ? over.nextSibling : over);
			});

			hydrate(ids(), render);
		})();
		</script>
	</div>
	<?php
}
