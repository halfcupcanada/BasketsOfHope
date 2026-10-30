<?php
/**
 * Rebuild the Sponsorship page gallery region into two year tabs:
 *   2026 tab (default) = 13 sponsors; 2025 tab = 16 sponsors.
 * Reuses existing attachments by slug (no new uploads). Also makes the
 * "Thank you to our ... sponsors" heading year-neutral.
 *
 * Run:  wp eval-file build-sponsor-tabs.php <page-id>
 */
$page_id = isset($args[0]) ? (int) $args[0] : 0;
if (!$page_id) { WP_CLI::error('Pass the sponsor page ID.'); }

$labels = [
  'akash-homes'=>'Akash Homes','burke-media'=>'Burke Media',
  'cdk-hospitality-consulting'=>'CDK Hospitality Consulting','city-lumber'=>'City Lumber Corporation',
  'delnor-construction-managers'=>'Delnor Construction Managers','great-canadian-exteriors'=>'Great Canadian Exteriors',
  'guru-kitchen-bar'=>'Guru Kitchen + Bar','homes-by-new-era'=>'Homes by New Era',
  'koralta-construction'=>'KorAlta Construction','north-west-paving'=>'North West Paving Ltd.',
  'paramount-flooring'=>'Paramount Flooring','pcl-construction'=>'PCL Construction',
  'realty-focus'=>'Realty Focus','royce-and-oak'=>'Royce & Oak',
  'select-engineering-consultants'=>'Select Engineering Consultants','wine-cru'=>'Wine Cru',
  'des-engineering'=>'DES Engineering','fka'=>'FKA','hsea'=>'HSEA','hamm'=>'Hamm',
  'qualico-communities'=>'Qualico Communities','sran-law'=>'Sran Law',
  'stallion-moving-and-storage'=>'Stallion Moving and Storage','synergy-projects'=>'Synergy Projects Ltd.',
];

// 2025 = last year's 16, in the original order.
$set2025 = ['akash-homes','burke-media','cdk-hospitality-consulting','city-lumber',
  'delnor-construction-managers','great-canadian-exteriors','guru-kitchen-bar','homes-by-new-era',
  'koralta-construction','north-west-paving','paramount-flooring','pcl-construction',
  'realty-focus','royce-and-oak','select-engineering-consultants','wine-cru'];

// 2026 = all 13 this year (5 returning + 8 new), alphabetical by label.
$set2026 = ['akash-homes','burke-media','des-engineering','fka','guru-kitchen-bar','hamm','hsea',
  'koralta-construction','qualico-communities','select-engineering-consultants','sran-law',
  'stallion-moving-and-storage','synergy-projects'];

function boh_att_id(string $slug): int {
  $p = get_posts(['post_type'=>'attachment','name'=>$slug,'numberposts'=>1,'post_status'=>'inherit','fields'=>'ids']);
  return $p ? (int)$p[0] : 0;
}
function boh_img_block(int $id, string $alt): string {
  $url = wp_get_attachment_url($id);
  return "<!-- wp:image {\"id\":$id,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n"
       . "<figure class=\"wp-block-image size-large\"><img src=\"".esc_url($url)."\" alt=\"".esc_attr($alt)."\" class=\"wp-image-$id\"/></figure>\n"
       . "<!-- /wp:image -->";
}
function boh_gallery(array $slugs, string $yearclass, array $labels): string {
  $blocks = [];
  foreach ($slugs as $slug) {
    $id = boh_att_id($slug);
    if (!$id) { WP_CLI::warning("no attachment for $slug"); continue; }
    $label = $labels[$slug] ?? $slug;
    $blocks[] = boh_img_block($id, "$label logo");
  }
  return "<!-- wp:gallery {\"columns\":4,\"linkTo\":\"none\",\"className\":\"boh-sponsor-logos $yearclass\"} -->\n"
       . "<figure class=\"wp-block-gallery has-nested-images columns-4 boh-sponsor-logos $yearclass\">\n"
       . implode("\n", $blocks) . "\n"
       . "</figure>\n<!-- /wp:gallery -->";
}

$g2026 = boh_gallery($set2026, 'boh-sponsor-logos-2026', $labels);
$g2025 = boh_gallery($set2025, 'boh-sponsor-logos-2025', $labels);

$tabs = <<<HTML
<!-- wp:group {"className":"boh-sponsor-tabwrap"} -->
<div class="wp-block-group boh-sponsor-tabwrap">
<!-- wp:html -->
<div class="boh-sponsor-tabs" role="tablist" aria-label="Sponsor years">
<button type="button" class="boh-tab-btn is-active" data-year="2026" role="tab" aria-selected="true">2026 Sponsors</button>
<button type="button" class="boh-tab-btn" data-year="2025" role="tab" aria-selected="false">2025 Sponsors</button>
</div>
<!-- /wp:html -->

<!-- wp:group {"className":"boh-year-panel boh-year-2026 is-active"} -->
<div class="wp-block-group boh-year-panel boh-year-2026 is-active">
$g2026
</div>
<!-- /wp:group -->

<!-- wp:group {"className":"boh-year-panel boh-year-2025"} -->
<div class="wp-block-group boh-year-panel boh-year-2025">
$g2025
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
HTML;

$post = get_post($page_id);
if (!$post) WP_CLI::error("page $page_id not found");
$content = $post->post_content;

// Replace the existing boh-sponsor-logos gallery block (whatever it now holds)
// with the tabbed structure. Match from the gallery open to its first close.
$start = strpos($content, '<!-- wp:gallery');
if ($start === false) WP_CLI::error('no wp:gallery block found');
// ensure this gallery is the sponsor-logos one
$galopen_end = strpos($content, '-->', $start);
$firstline = substr($content, $start, $galopen_end - $start);
if (strpos($firstline, 'boh-sponsor-logos') === false) WP_CLI::error('first gallery is not boh-sponsor-logos');
$endmark = '<!-- /wp:gallery -->';
$end = strpos($content, $endmark, $start);
if ($end === false) WP_CLI::error('no gallery close found');
$end += strlen($endmark);

$content = substr($content, 0, $start) . $tabs . substr($content, $end);

wp_update_post(['ID'=>$page_id, 'post_content'=>$content]);

// Make the heading year-neutral (tabs now show the year).
$opt = get_option('boh_content', []);
$old = isset($opt['sponsor']['logos_heading']) ? $opt['sponsor']['logos_heading'] : '(unset)';
if (!is_array($opt)) $opt = [];
if (!isset($opt['sponsor']) || !is_array($opt['sponsor'])) $opt['sponsor'] = [];
$opt['sponsor']['logos_heading'] = 'Thank you to our Sponsors';
update_option('boh_content', $opt);
if (function_exists('wp_cache_flush')) wp_cache_flush();

WP_CLI::success(sprintf('Tabs built: 2026=%d, 2025=%d logos. Heading was "%s" -> "Thank you to our Sponsors". Page %d updated.',
  count($set2026), count($set2025), $old, $page_id));
