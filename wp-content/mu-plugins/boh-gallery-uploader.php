<?php
/**
 * Plugin Name: BoH Gallery Uploader
 * Description: Adds Media → Gallery Upload — a simple wp-admin page for
 *              uploading photos/videos directly into the per-year
 *              gallery folders that the [boh_gallery] shortcode reads.
 *              Bypasses the default Media Library so files land in
 *              /wp-content/uploads/gallery/YYYY/ instead of the
 *              date-based structure.
 */

defined( 'ABSPATH' ) || exit;

const BOH_GALLERY_UPLOADER_SLUG    = 'boh-gallery-upload';
const BOH_GALLERY_UPLOADER_YEARS   = [ 2026, 2025, 2024, 2023, 2022 ];
const BOH_GALLERY_ALLOWED_IMG_EXT  = [ 'jpg', 'jpeg', 'png', 'webp', 'gif' ];
const BOH_GALLERY_ALLOWED_VID_EXT  = [ 'mp4', 'webm' ];

add_action( 'admin_menu', function () {
	add_media_page(
		'BoH Gallery Upload',
		'Gallery Upload',
		'upload_files',
		BOH_GALLERY_UPLOADER_SLUG,
		'boh_gallery_uploader_render'
	);
} );

function boh_gallery_uploader_render() {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( 'You need permission to upload files.' );
	}

	$upload   = wp_get_upload_dir();
	$base_dir = rtrim( $upload['basedir'], '/' ) . '/gallery';
	$base_url = rtrim( $upload['baseurl'], '/' ) . '/gallery';
	$notices  = [];

	// Handle POSTs.
	// A batch larger than post_max_size makes PHP discard the whole request
	// body, so $_POST and $_FILES arrive empty — including the nonce, which
	// would otherwise fail as a misleading "Are you sure you want to do
	// this?" screen. Detect that case first and say what actually happened.
	if ( $_SERVER['REQUEST_METHOD'] === 'POST'
		&& empty( $_POST )
		&& (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 0 ) {
		$notices[] = [
			'error',
			sprintf(
				'That batch was too large to accept in one request (server limit: %s per upload, %s per file). Nothing was saved — try uploading fewer files at a time.',
				esc_html( ini_get( 'post_max_size' ) ),
				esc_html( ini_get( 'upload_max_filesize' ) )
			),
		];
	} elseif ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
		check_admin_referer( 'boh_gallery_action' );

		$year = isset( $_POST['gallery_year'] ) ? (int) $_POST['gallery_year'] : 0;
		if ( ! in_array( $year, BOH_GALLERY_UPLOADER_YEARS, true ) ) {
			$notices[] = [ 'error', 'Pick a valid year.' ];
		} else {
			$dest_dir = $base_dir . '/' . $year;
			if ( ! is_dir( $dest_dir ) ) {
				wp_mkdir_p( $dest_dir );
			}

			// Delete action
			if ( ! empty( $_POST['delete_file'] ) ) {
				$file = sanitize_file_name( wp_unslash( $_POST['delete_file'] ) );
				$path = $dest_dir . '/' . $file;
				// Only allow files within the year dir
				if ( $file && is_file( $path ) && strpos( realpath( $path ), realpath( $dest_dir ) ) === 0 ) {
					unlink( $path );
					// Also remove companion .txt caption if any
					$txt = $dest_dir . '/' . pathinfo( $file, PATHINFO_FILENAME ) . '.txt';
					if ( is_file( $txt ) ) unlink( $txt );
					// ...and the resized copies, or they linger as orphans.
					if ( function_exists( 'boh_gallery_deriv_dir' ) ) {
						$dv = boh_gallery_deriv_dir( $year );
						foreach ( BOH_GALLERY_DERIV_WIDTHS as $w ) {
							$d = $dv . '/' . boh_gallery_deriv_name( $file, $w );
							if ( is_file( $d ) ) unlink( $d );
						}
					}
					$notices[] = [ 'success', "Deleted <code>$file</code>." ];
				} else {
					$notices[] = [ 'error', 'File not found.' ];
				}
				delete_transient( 'boh_gallery_items_v2' );
			}

			// Upload action
			if ( ! empty( $_FILES['gallery_files']['name'][0] ) ) {
				$uploaded = 0;
				$skipped  = [];
				$new_dims = [];
				$files    = $_FILES['gallery_files'];
				$count    = count( $files['name'] );
				for ( $i = 0; $i < $count; $i++ ) {
					if ( $files['error'][ $i ] !== UPLOAD_ERR_OK ) {
						$skipped[] = $files['name'][ $i ] . ' (upload error)';
						continue;
					}
					$name = sanitize_file_name( $files['name'][ $i ] );
					$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
					$allowed = array_merge( BOH_GALLERY_ALLOWED_IMG_EXT, BOH_GALLERY_ALLOWED_VID_EXT );
					if ( ! in_array( $ext, $allowed, true ) ) {
						$skipped[] = $name . ' (unsupported type)';
						continue;
					}
					// Avoid overwrite: append -1, -2 etc.
					$target = $dest_dir . '/' . $name;
					if ( is_file( $target ) ) {
						$base = pathinfo( $name, PATHINFO_FILENAME );
						$n = 1;
						do {
							$name   = "$base-$n.$ext";
							$target = $dest_dir . '/' . $name;
							$n++;
						} while ( is_file( $target ) && $n < 999 );
					}
					if ( move_uploaded_file( $files['tmp_name'][ $i ], $target ) ) {
						@chmod( $target, 0644 );
						// Build the small/medium/large copies now so the public
						// grid never serves this camera original. Decoding a big
						// photo needs more than the 128M web limit allows.
						if ( function_exists( 'boh_gallery_build_one' ) ) {
							wp_raise_memory_limit( 'image' );
							$deriv = boh_gallery_build_one( $target, $year );
							if ( $deriv['error'] ) {
								$skipped[] = $name . ' (saved, but resizing failed: ' . $deriv['error'] . ')';
							} elseif ( $deriv['dims'] ) {
								$new_dims[ $name ] = $deriv['dims'];
							}
						}
						$uploaded++;
					} else {
						$skipped[] = $name . ' (move failed)';
					}
				}
					// Merge the new dimensions into the per-year index the front
					// end reads to reserve each tile's space.
					if ( $new_dims && function_exists( 'boh_gallery_deriv_dir' ) ) {
						$idx_dir = boh_gallery_deriv_dir( $year );
						if ( is_dir( $idx_dir ) ) {
							$idx_path = $idx_dir . '/index.json';
							$idx = is_file( $idx_path ) ? json_decode( (string) file_get_contents( $idx_path ), true ) : [];
							if ( ! is_array( $idx ) ) { $idx = []; }
							file_put_contents( $idx_path, wp_json_encode( array_merge( $idx, $new_dims ) ) );
						}
					}
				delete_transient( 'boh_gallery_items_v2' );
				if ( $uploaded ) {
					$notices[] = [ 'success', "Uploaded {$uploaded} file(s) to /gallery/{$year}/." ];
				}
				if ( $skipped ) {
					$notices[] = [ 'warning', 'Skipped: ' . implode( ', ', array_map( 'esc_html', $skipped ) ) ];
				}
			}
		}
	}

	// Default selected year
	$sel_year = isset( $_REQUEST['gallery_year'] ) ? (int) $_REQUEST['gallery_year'] : BOH_GALLERY_UPLOADER_YEARS[0];
	if ( ! in_array( $sel_year, BOH_GALLERY_UPLOADER_YEARS, true ) ) {
		$sel_year = BOH_GALLERY_UPLOADER_YEARS[0];
	}
	$sel_dir  = $base_dir . '/' . $sel_year;
	$existing = is_dir( $sel_dir ) ? array_values( array_filter( scandir( $sel_dir ), function ( $f ) use ( $sel_dir ) {
		if ( $f === '.' || $f === '..' ) return false;
		$ext = strtolower( pathinfo( $f, PATHINFO_EXTENSION ) );
		return in_array( $ext, array_merge( BOH_GALLERY_ALLOWED_IMG_EXT, BOH_GALLERY_ALLOWED_VID_EXT ), true );
	} ) ) : [];
	natcasesort( $existing );
	$existing = array_values( $existing );

	// Render
	?>
	<div class="wrap boh-gallery-uploader">
		<h1>Gallery Upload</h1>
		<p style="max-width:640px;color:#555">Drop photos or videos into a specific event year. Files go directly to <code>wp-content/uploads/gallery/&lt;year&gt;/</code> and appear in the public <a href="<?php echo esc_url( home_url( '/gallery/' ) ); ?>" target="_blank">/gallery/</a> page.</p>

		<?php foreach ( $notices as [$type, $msg] ) : ?>
			<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo wp_kses_post( $msg ); ?></p></div>
		<?php endforeach; ?>

		<style>
			.boh-gallery-uploader .card { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 24px; margin-top: 20px; max-width: 960px; }
			.boh-gallery-uploader h2 { margin-top: 0; }
			.boh-gallery-uploader label { display: block; font-weight: 600; margin-bottom: 6px; }
			.boh-gallery-uploader select { min-width: 180px; padding: 6px 10px; }
			.boh-gallery-uploader input[type=file] { padding: 8px 0; }
			.boh-gallery-uploader .drop-hint { color: #666; font-size: 12px; margin-top: 4px; }
			.boh-gallery-uploader .file-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; margin-top: 16px; }
			.boh-gallery-uploader .file-tile { position: relative; background: #f6f6f6; border-radius: 6px; overflow: hidden; aspect-ratio: 4/3; }
			.boh-gallery-uploader .file-tile img,
			.boh-gallery-uploader .file-tile video { width: 100%; height: 100%; object-fit: cover; display: block; }
			.boh-gallery-uploader .file-tile .name { position: absolute; bottom: 0; left: 0; right: 0; padding: 6px 8px; background: linear-gradient(transparent, rgba(0,0,0,0.85)); color: #fff; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; overflow: hidden; }
			.boh-gallery-uploader .file-tile .del { position: absolute; top: 6px; right: 6px; background: rgba(200,30,30,0.9); color: #fff; border: 0; border-radius: 999px; padding: 4px 10px; font-size: 11px; cursor: pointer; }
			.boh-gallery-uploader .file-tile .del:hover { background: #c81e1e; }
		</style>

		<div class="card">
			<h2>Upload files</h2>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'boh_gallery_action' ); ?>
				<p>
					<label for="gallery_year">Event year</label>
					<select name="gallery_year" id="gallery_year"
						onchange="window.location = '<?php echo esc_js( admin_url( 'upload.php?page=' . BOH_GALLERY_UPLOADER_SLUG . '&gallery_year=' ) ); ?>' + this.value;">
						<?php foreach ( BOH_GALLERY_UPLOADER_YEARS as $y ) : ?>
							<option value="<?php echo $y; ?>" <?php selected( $sel_year, $y ); ?>><?php echo $y; ?></option>
						<?php endforeach; ?>
					</select>
					<span class="drop-hint">Switching year reloads this page; anything not yet uploaded is cleared.</span>
				</p>
				<p>
					<label for="gallery_files">Files</label>
					<input type="file" name="gallery_files[]" id="gallery_files" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm">
					<span class="drop-hint">
						JPG / PNG / WEBP / GIF for images, MP4 / WEBM for videos. Select several at once (hold Cmd/Ctrl).<br>
						This server accepts up to <strong><?php echo esc_html( ini_get( 'upload_max_filesize' ) ); ?></strong> per file,
						<strong><?php echo esc_html( ini_get( 'post_max_size' ) ); ?></strong> per batch,
						and <strong><?php echo (int) ini_get( 'max_file_uploads' ); ?></strong> files at a time.
					</span>
				</p>
				<p>
					<button type="submit" class="button button-primary">Upload to <?php echo (int) $sel_year; ?></button>
				</p>
			</form>
		</div>

		<div class="card">
			<h2><?php echo (int) $sel_year; ?> — <?php echo count( $existing ); ?> file(s)</h2>
			<?php if ( empty( $existing ) ) : ?>
				<p style="color:#777">No files yet. Upload above.</p>
			<?php else : ?>
				<div class="file-grid">
					<?php foreach ( $existing as $file ) :
						$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
						$url = $base_url . '/' . $sel_year . '/' . rawurlencode( $file );
						$is_video = in_array( $ext, BOH_GALLERY_ALLOWED_VID_EXT, true );
					?>
					<div class="file-tile">
						<?php if ( $is_video ) : ?>
							<video src="<?php echo esc_url( $url ); ?>" muted preload="metadata"></video>
						<?php else : ?>
							<img src="<?php echo esc_url( $url ); ?>" alt="" loading="lazy">
						<?php endif; ?>
						<div class="name" title="<?php echo esc_attr( $file ); ?>"><?php echo esc_html( $file ); ?></div>
						<form method="post" style="display:inline" onsubmit="return confirm('Delete <?php echo esc_js( $file ); ?>?');">
							<?php wp_nonce_field( 'boh_gallery_action' ); ?>
							<input type="hidden" name="gallery_year" value="<?php echo (int) $sel_year; ?>">
							<input type="hidden" name="delete_file" value="<?php echo esc_attr( $file ); ?>">
							<button type="submit" class="del">Delete</button>
						</form>
					</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
