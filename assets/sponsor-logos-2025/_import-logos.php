<?php
/**
 * Import the 2025 sponsor logos into the Media Library and rebuild the
 * empty Gallery block on the Sponsorship page so they actually render.
 * Re-runnable: an existing attachment with the same slug is reused rather
 * than duplicated.
 *
 * Run with:  wp eval-file import-logos.php <sponsor-page-id>
 */
$src_dir  = '/tmp/boh-logos';
$page_id  = isset($args[0]) ? (int) $args[0] : 0;
if (!$page_id) { WP_CLI::error('Pass the sponsor page ID as an argument.'); }

$names = [
    'akash-homes'                    => 'Akash Homes',
    'burke-media'                    => 'Burke Media',
    'cdk-hospitality-consulting'     => 'CDK Hospitality Consulting',
    'city-lumber'                    => 'City Lumber Corporation',
    'delnor-construction-managers'   => 'Delnor Construction Managers',
    'great-canadian-exteriors'       => 'Great Canadian Exteriors',
    'guru-kitchen-bar'               => 'Guru Kitchen + Bar',
    'homes-by-new-era'               => 'Homes by New Era',
    'koralta-construction'           => 'KorAlta Construction',
    'north-west-paving'              => 'North West Paving Ltd.',
    'paramount-flooring'             => 'Paramount Flooring',
    'pcl-construction'               => 'PCL Construction',
    'realty-focus'                   => 'Realty Focus',
    'royce-and-oak'                  => 'Royce & Oak',
    'select-engineering-consultants' => 'Select Engineering Consultants',
    'wine-cru'                       => 'Wine Cru',
];

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$blocks = [];
$made = 0; $reused = 0;

foreach ($names as $slug => $label) {
    $file = "$src_dir/$slug.png";
    if (!file_exists($file)) { WP_CLI::warning("missing $file"); continue; }

    // Reuse an existing attachment with this slug so re-runs stay clean.
    $existing = get_posts([
        'post_type'   => 'attachment',
        'name'        => $slug,
        'numberposts' => 1,
        'post_status' => 'inherit',
        'fields'      => 'ids',
    ]);

    if ($existing) {
        $id = (int) $existing[0];
        $reused++;
    } else {
        $tmp = wp_tempnam($file);
        copy($file, $tmp);
        $id = media_handle_sideload(
            ['name' => "$slug.png", 'tmp_name' => $tmp],
            0,
            "$label logo"
        );
        if (is_wp_error($id)) { WP_CLI::warning("$slug: " . $id->get_error_message()); @unlink($tmp); continue; }
        wp_update_post(['ID' => $id, 'post_name' => $slug, 'post_title' => "$label logo"]);
        $made++;
    }

    update_post_meta($id, '_wp_attachment_image_alt', "$label logo");
    $url = wp_get_attachment_url($id);
    $blocks[] = "<!-- wp:image {\"id\":$id,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n"
              . "<figure class=\"wp-block-image size-large\"><img src=\"" . esc_url($url) . "\" alt=\"" . esc_attr("$label logo") . "\" class=\"wp-image-$id\"/></figure>\n"
              . "<!-- /wp:image -->";
}

if (!$blocks) { WP_CLI::error('No logos imported; page left untouched.'); }

$gallery = "<!-- wp:gallery {\"columns\":4,\"linkTo\":\"none\",\"className\":\"boh-sponsor-logos\"} -->\n"
         . "<figure class=\"wp-block-gallery has-nested-images columns-4 is-cropped boh-sponsor-logos\">\n"
         . implode("\n", $blocks) . "\n"
         . "</figure>\n<!-- /wp:gallery -->";

$post = get_post($page_id);
$content = $post->post_content;

// Replace whatever gallery block currently carries .boh-sponsor-logos.
$pattern = '/<!-- wp:gallery[^>]*boh-sponsor-logos.*?<!-- \/wp:gallery -->/s';
if (preg_match($pattern, $content)) {
    $content = preg_replace($pattern, $gallery, $content, 1);
} else {
    WP_CLI::error('Could not find the boh-sponsor-logos gallery block on that page.');
}

// The "logos will appear here" placeholder is now redundant.
$content = preg_replace('/<!-- wp:paragraph[^>]*boh-sponsor-logos__note.*?<!-- \/wp:paragraph -->\s*/s', '', $content, 1);

wp_update_post(['ID' => $page_id, 'post_content' => $content]);
WP_CLI::success(sprintf('%d logos in gallery (%d imported, %d reused); page %d updated.', count($blocks), $made, $reused, $page_id));
