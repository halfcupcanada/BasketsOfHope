<?php
/**
 * Plugin Name: BoH gallery folder scanner
 * Description: Populates the [boh_gallery] shortcode from a filesystem
 *              layout — one folder per event year under
 *              wp-content/uploads/gallery/YYYY/. Any .jpg/.jpeg/.png/
 *              .webp/.gif becomes a photo; .mp4/.webm becomes a video.
 *              Filename ordering (natural sort) drives display order —
 *              prefix `01_`, `02_` if you want a specific sequence.
 *              An optional companion `NAME.txt` next to a file becomes
 *              that item's caption.
 *              Results cached for 15 min via transient; delete the
 *              transient (or bump the theme CSS version) to refresh.
 */

defined( 'ABSPATH' ) || exit;

const BOH_GALLERY_DIR       = '/gallery';
const BOH_GALLERY_MIN_YEAR  = 2022;
const BOH_GALLERY_MAX_YEAR  = 2026;
const BOH_GALLERY_CACHE_KEY = 'boh_gallery_items_v1';

add_filter( 'boh_gallery_items', function ( $items ) {
	$cached = get_transient( BOH_GALLERY_CACHE_KEY );
	if ( is_array( $cached ) ) return $cached;

	$upload    = wp_get_upload_dir();
	$base_dir  = rtrim( $upload['basedir'], '/' ) . BOH_GALLERY_DIR;
	$base_url  = rtrim( $upload['baseurl'], '/' ) . BOH_GALLERY_DIR;
	$out       = [];

	if ( ! is_dir( $base_dir ) ) {
		set_transient( BOH_GALLERY_CACHE_KEY, $out, 15 * MINUTE_IN_SECONDS );
		return $out;
	}

	foreach ( scandir( $base_dir ) as $entry ) {
		if ( ! ctype_digit( $entry ) ) continue;
		$year = (int) $entry;
		if ( $year < BOH_GALLERY_MIN_YEAR || $year > BOH_GALLERY_MAX_YEAR ) continue;

		$year_dir = $base_dir . '/' . $year;
		if ( ! is_dir( $year_dir ) ) continue;

		$files = scandir( $year_dir );
		if ( ! $files ) continue;
		natcasesort( $files );

		$items_for_year = [];
		foreach ( $files as $file ) {
			if ( $file === '.' || $file === '..' ) continue;
			$path = $year_dir . '/' . $file;
			if ( ! is_file( $path ) ) continue;

			$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, [ 'jpg', 'jpeg', 'png', 'webp', 'gif' ], true ) ) {
				$type = 'image';
			} elseif ( in_array( $ext, [ 'mp4', 'webm' ], true ) ) {
				$type = 'video';
			} else {
				continue;
			}

			$url     = $base_url . '/' . $year . '/' . rawurlencode( $file );
			$caption = boh_gallery_read_caption( $year_dir, $file );

			$item = [
				'type'    => $type,
				'url'     => $url,
				'caption' => $caption,
			];
			// Optional matching poster image for videos:
			//   my-clip.mp4  → my-clip.jpg (if it exists)
			if ( $type === 'video' ) {
				foreach ( [ 'jpg', 'jpeg', 'png', 'webp' ] as $pex ) {
					$poster = pathinfo( $file, PATHINFO_FILENAME ) . '.' . $pex;
					if ( is_file( $year_dir . '/' . $poster ) ) {
						$item['poster'] = $base_url . '/' . $year . '/' . rawurlencode( $poster );
						break;
					}
				}
			}
			$items_for_year[] = $item;
		}

		if ( $items_for_year ) {
			$out[ $year ] = $items_for_year;
		}
	}

	// Newest years first
	krsort( $out );

	set_transient( BOH_GALLERY_CACHE_KEY, $out, 15 * MINUTE_IN_SECONDS );
	return $out;
} );

/**
 * Optional caption from a sibling text file. e.g. `IMG_001.jpg` will
 * pick up `IMG_001.txt` if present. Trimmed and safe-decoded.
 */
function boh_gallery_read_caption( $dir, $file ) {
	$txt = pathinfo( $file, PATHINFO_FILENAME ) . '.txt';
	$path = $dir . '/' . $txt;
	if ( ! is_file( $path ) ) return '';
	$raw = @file_get_contents( $path );
	if ( ! $raw ) return '';
	return sanitize_text_field( trim( $raw ) );
}

/**
 * Clear the gallery cache — hit /?boh_gallery_flush=1 while logged in
 * as an admin to refresh after adding photos.
 */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['boh_gallery_flush'] ) ) return;
	if ( ! current_user_can( 'manage_options' ) ) return;
	delete_transient( BOH_GALLERY_CACHE_KEY );
	wp_safe_redirect( home_url( '/gallery/?flushed=1' ), 302 );
	exit;
} );
