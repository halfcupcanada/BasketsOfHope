<?php
/**
 * Plugin Name: BoH gallery image derivatives
 * Description: Builds small/medium/large copies of every gallery photo so the
 *              /gallery/ grid never ships a camera original. The gallery is a
 *              plain folder scan rather than the media library, so WordPress
 *              generates no sizes for these files at all — without this, a
 *              350px tile was loading a 15MB JPEG.
 *
 *              Derivatives live in  uploads/gallery/<year>/_resized/  and are
 *              named  <basename>-<width>.webp. A per-year index.json records
 *              each original's pixel dimensions so the front end can reserve
 *              space and avoid layout shift without stat-ing every file.
 *
 *              Generation happens on upload (see boh-gallery-uploader.php) and
 *              via boh_gallery_build_derivatives() for backfills. Nothing is
 *              generated during a page render.
 */

defined( 'ABSPATH' ) || exit;

const BOH_GALLERY_DERIV_SUBDIR = '_resized';

/**
 * Widths we keep, smallest first. `small` covers phones and the narrow tiles,
 * `thumb` covers desktop tiles at 2x, `large` is what the viewer opens.
 */
const BOH_GALLERY_DERIV_WIDTHS = [
	'small' => 480,
	'thumb' => 960,
	'large' => 1800,
];

/** Extensions we can resize. Videos and GIFs are passed through untouched. */
const BOH_GALLERY_RESIZABLE_EXT = [ 'jpg', 'jpeg', 'png', 'webp' ];

/**
 * Absolute path of a year's derivative directory.
 */
function boh_gallery_deriv_dir( int $year ): string {
	$upload = wp_get_upload_dir();
	return rtrim( $upload['basedir'], '/' ) . '/gallery/' . $year . '/' . BOH_GALLERY_DERIV_SUBDIR;
}

/**
 * Public URL of a year's derivative directory.
 */
function boh_gallery_deriv_url( int $year ): string {
	$upload = wp_get_upload_dir();
	return rtrim( $upload['baseurl'], '/' ) . '/gallery/' . $year . '/' . BOH_GALLERY_DERIV_SUBDIR;
}

/**
 * Derivative filename for one original at one width.
 */
function boh_gallery_deriv_name( string $file, int $width ): string {
	return pathinfo( $file, PATHINFO_FILENAME ) . '-' . $width . '.webp';
}

/**
 * Read a year's dimension index (originals' pixel sizes), or an empty array.
 */
function boh_gallery_deriv_index( int $year ): array {
	static $cache = [];
	if ( isset( $cache[ $year ] ) ) {
		return $cache[ $year ];
	}
	$path = boh_gallery_deriv_dir( $year ) . '/index.json';
	$data = [];
	if ( is_file( $path ) ) {
		$raw = @file_get_contents( $path );
		if ( $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$data = $decoded;
			}
		}
	}
	$cache[ $year ] = $data;
	return $data;
}

/**
 * Build every missing derivative for one original.
 *
 * Returns [ 'built' => int, 'dims' => [w,h]|null, 'error' => string ].
 * A derivative older than its original is rebuilt, so replacing a photo in
 * place refreshes the copies.
 */
function boh_gallery_build_one( string $abs_path, int $year, bool $force = false ): array {
	$file = basename( $abs_path );
	$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, BOH_GALLERY_RESIZABLE_EXT, true ) || ! is_file( $abs_path ) ) {
		return [ 'built' => 0, 'dims' => null, 'error' => '' ];
	}

	$dir = boh_gallery_deriv_dir( $year );
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return [ 'built' => 0, 'dims' => null, 'error' => 'cannot create ' . $dir ];
	}

	$size = @getimagesize( $abs_path );
	$dims = ( $size && ! empty( $size[0] ) ) ? [ (int) $size[0], (int) $size[1] ] : null;
	if ( ! $dims ) {
		return [ 'built' => 0, 'dims' => null, 'error' => 'unreadable: ' . $file ];
	}

	$src_mtime = (int) @filemtime( $abs_path );
	$built     = 0;

	foreach ( BOH_GALLERY_DERIV_WIDTHS as $width ) {
		// Never upscale: a 700px original has no business becoming 1800px.
		$target_w = min( $width, $dims[0] );
		$out      = $dir . '/' . boh_gallery_deriv_name( $file, $width );

		if ( ! $force && is_file( $out ) && (int) @filemtime( $out ) >= $src_mtime ) {
			continue;
		}

		// A fresh editor per size: reusing one leaves it holding the previously
		// resized (smaller) bitmap, so every later size would be upscaled from it.
		$editor = wp_get_image_editor( $abs_path );
		if ( is_wp_error( $editor ) ) {
			return [ 'built' => $built, 'dims' => $dims, 'error' => $editor->get_error_message() ];
		}
		$editor->set_quality( 82 );
		// Only resize when there is something to shrink. Asking WordPress to
		// "resize" an image to its own width fails with "Could not calculate
		// resized image dimensions" — for those we just re-encode to WebP,
		// which is still a big saving over a PNG or an unoptimised JPEG.
		if ( $dims[0] > $target_w ) {
			$resized = $editor->resize( $target_w, null, false );
			if ( is_wp_error( $resized ) ) {
				return [ 'built' => $built, 'dims' => $dims, 'error' => $resized->get_error_message() ];
			}
		}
		$saved = $editor->save( $out, 'image/webp' );
		unset( $editor );
		if ( is_wp_error( $saved ) ) {
			return [ 'built' => $built, 'dims' => $dims, 'error' => $saved->get_error_message() ];
		}
		$built++;
	}

	return [ 'built' => $built, 'dims' => $dims, 'error' => '' ];
}

/**
 * Backfill a whole year (or every year when $year is 0).
 *
 * $limit caps how many originals are processed in one call — this box has
 * under 200MB of RAM free and a 6000x4000 JPEG costs ~96MB to decode, so
 * large backfills are meant to be run in batches.
 */
function boh_gallery_build_derivatives( int $year = 0, int $limit = 0, bool $force = false ): array {
	$upload   = wp_get_upload_dir();
	$base_dir = rtrim( $upload['basedir'], '/' ) . '/gallery';
	$years    = [];

	if ( $year ) {
		$years[] = $year;
	} elseif ( is_dir( $base_dir ) ) {
		foreach ( scandir( $base_dir ) as $entry ) {
			if ( ctype_digit( $entry ) && is_dir( $base_dir . '/' . $entry ) ) {
				$years[] = (int) $entry;
			}
		}
	}
	rsort( $years );

	$report = [ 'processed' => 0, 'built' => 0, 'skipped' => 0, 'errors' => [] ];

	foreach ( $years as $y ) {
		$year_dir = $base_dir . '/' . $y;
		if ( ! is_dir( $year_dir ) ) {
			continue;
		}
		$index = boh_gallery_deriv_index( $y );

		foreach ( scandir( $year_dir ) as $file ) {
			if ( $file === '.' || $file === '..' ) {
				continue;
			}
			$abs = $year_dir . '/' . $file;
			if ( ! is_file( $abs ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, BOH_GALLERY_RESIZABLE_EXT, true ) ) {
				continue;
			}

			if ( $limit && $report['processed'] >= $limit ) {
				break 2;
			}

			$res = boh_gallery_build_one( $abs, $y, $force );
			$report['processed']++;
			$report['built'] += $res['built'];
			if ( ! $res['built'] ) {
				$report['skipped']++;
			}
			if ( $res['error'] ) {
				$report['errors'][] = $file . ': ' . $res['error'];
			}
			if ( $res['dims'] ) {
				$index[ $file ] = $res['dims'];
			}
			// Free the decoded bitmap before the next file rather than at the
			// end of the run — otherwise a long batch walks straight into the
			// memory ceiling.
			gc_collect_cycles();
		}

		$dir = boh_gallery_deriv_dir( $y );
		if ( is_dir( $dir ) ) {
			file_put_contents( $dir . '/index.json', wp_json_encode( $index ) );
		}
	}

	delete_transient( 'boh_gallery_items_v2' );
	return $report;
}

/**
 * Resolve the URLs a gallery item should use.
 *
 * Falls back to the original whenever a derivative is missing, so the page
 * still works before a backfill has run.
 */
function boh_gallery_item_sources( int $year, string $file, string $original_url ): array {
	$dir  = boh_gallery_deriv_dir( $year );
	$url  = boh_gallery_deriv_url( $year );
	$out  = [];

	foreach ( BOH_GALLERY_DERIV_WIDTHS as $key => $width ) {
		$name = boh_gallery_deriv_name( $file, $width );
		$out[ $key ] = is_file( $dir . '/' . $name )
			? $url . '/' . rawurlencode( $name )
			: $original_url;
	}

	$index = boh_gallery_deriv_index( $year );
	$dims  = $index[ $file ] ?? null;
	$out['w'] = is_array( $dims ) ? (int) $dims[0] : 0;
	$out['h'] = is_array( $dims ) ? (int) $dims[1] : 0;

	return $out;
}
