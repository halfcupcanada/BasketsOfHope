<?php
/**
 * Baskets of Hope — theme bootstrap + shortcodes + custom footer + animations.
 * Standalone theme (no parent). All design lives in style.css + this file.
 *
 * @package BoH
 */

// --- Enqueue our stylesheet (no parent dependency) -----------------------
// Version from the file's mtime so every deploy busts the browser and CDN
// cache. A fixed string ("5.00") meant edited CSS kept being served stale
// from Cloudflare long after the file on disk had changed.
add_action('wp_enqueue_scripts', function () {
    $css = get_stylesheet_directory() . '/style.css';
    $ver = file_exists($css) ? (string) filemtime($css) : '5.00';
    wp_enqueue_style('boh-style', get_stylesheet_uri(), [], $ver);
});

function boh_logo_url($size = 'full') {
    // A logo chosen in BoH Content -> Brand & images replaces the shipped one
    // everywhere it appears: header, footer and browser-tab icon. The stored
    // value is already a sized URL from the media picker, so the icon and
    // full variants resolve to the same file in that case.
    $shipped = home_url('/wp-content/uploads/2026/06/boh-logo.png');
    $chosen  = function_exists('boh_content') ? boh_content('brand.logo', $shipped) : '';
    if ($chosen === $shipped) {
        // Nothing chosen: fall through to the size-aware paths below.
        $chosen = '';
    }
    if ($chosen) {
        return $chosen;
    }
    $file = $size === 'icon' ? 'boh-logo-150x150.png' : 'boh-logo.png';
    return home_url('/wp-content/uploads/2026/06/' . $file);
}

add_action('wp_head', function () {
    $icon = esc_url(boh_logo_url('icon'));
    $logo = esc_url(boh_logo_url());
    echo '<link rel="icon" type="image/png" sizes="150x150" href="' . $icon . '">' . "\n";
    echo '<link rel="shortcut icon" type="image/png" href="' . $icon . '">' . "\n";
    echo '<link rel="apple-touch-icon" href="' . $logo . '">' . "\n";
    // Android home-screen icon; without a 192px entry Chrome falls back to a
    // screenshot of the page rather than the mark.
    echo '<link rel="icon" type="image/png" sizes="192x192" href="' . esc_url(home_url('/wp-content/uploads/2026/06/boh-logo-275x300.png')) . '">' . "\n";
}, 1);

// --- Theme supports ------------------------------------------------------
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo', [
        'height'      => 100,
        'width'       => 300,
        'flex-height' => true,
        'flex-width'  => true,
    ]);
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'script', 'style']);
    add_theme_support('automatic-feed-links');
    add_theme_support('responsive-embeds');
    add_theme_support('align-wide');
    add_theme_support('editor-styles');
    add_theme_support('wp-block-styles');
    register_nav_menus(['primary' => __('Primary Menu', 'boh')]);
});

// Register an outline (light) button style on the wp:button block
add_action('init', function () {
    register_block_style('core/button', [
        'name'  => 'outline',
        'label' => __('Outline', 'boh'),
    ]);
    register_block_style('core/button', [
        'name'  => 'ember',
        'label' => __('Magenta (primary CTA)', 'boh'),
    ]);
    register_block_style('core/button', [
        'name'  => 'yellow',
        'label' => __('Yellow', 'boh'),
    ]);
});

// --- Site config ---------------------------------------------------------
// The event runs 6:00-9:00 PM local time in Edmonton. Resolve the UTC offset
// from the timezone database instead of hardcoding one: Alberta moves to
// permanent UTC-6 on 1 Nov 2026, so the -07:00 previously written here put the
// countdown, the Google Calendar link and the .ics file an hour late for an
// event held on 3 Nov. Naming the zone keeps this correct through any future
// rule change too.
if (!defined('BOH_EVENT_TZ'))    define('BOH_EVENT_TZ',    'America/Edmonton');
if (!defined('BOH_EVENT_ISO')) {
    define('BOH_EVENT_ISO', (new DateTimeImmutable('2026-11-03 18:00:00', new DateTimeZone(BOH_EVENT_TZ)))->format('c'));
}
if (!defined('BOH_EVENT_END')) {
    define('BOH_EVENT_END', (new DateTimeImmutable('2026-11-03 21:00:00', new DateTimeZone(BOH_EVENT_TZ)))->format('c'));
}
if (!defined('BOH_EVENT_TITLE')) define('BOH_EVENT_TITLE', "Rohit's Baskets of Hope — A Night of Giving");
if (!defined('BOH_EVENT_LOC'))   define('BOH_EVENT_LOC',   "Rohit Group Office, 10130 112 St NW, Edmonton, AB T5K 2K4");
// RSVP form ID — auto-discover by title so the theme works regardless of
// install order. Cached for an hour to avoid repeating the lookup. Override
// by defining BOH_RSVP_FORM_ID in wp-config.php if you want a specific form.
if (!defined('BOH_RSVP_FORM_ID')) {
    $boh_rsvp_id = get_transient('boh_rsvp_form_id');
    if (false === $boh_rsvp_id) {
        $boh_rsvp_id = 0;
        foreach (['RSVP — Baskets of Hope 2026', 'RSVP — Baskets of Hope 2025'] as $boh_title) {
            $boh_match = get_posts([
                'post_type'      => 'wpcf7_contact_form',
                'title'          => $boh_title,
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'post_status'    => 'any',
            ]);
            if (!empty($boh_match)) { $boh_rsvp_id = (int) $boh_match[0]; break; }
        }
        set_transient('boh_rsvp_form_id', $boh_rsvp_id, HOUR_IN_SECONDS);
    }
    define('BOH_RSVP_FORM_ID', (int) $boh_rsvp_id);
    unset($boh_rsvp_id, $boh_match, $boh_title);
}
if (!defined('BOH_TOTAL_SPOTS')) define('BOH_TOTAL_SPOTS', 120);
if (!defined('BOH_SPOTS_LEFT'))  define('BOH_SPOTS_LEFT',  14);

// Multi-page site (as of 2026-07 feedback): About / Donate / Sponsor / FAQs /
// Gallery / RSVP are their own pages, no one-page anchor redirects.
// The old /contact/ and /stories/ pages still exist but are unlinked; redirect
// them to the home page so any historical links don't 404.
add_action('template_redirect', function () {
    if (!is_page() || is_front_page()) return;
    $slug = get_post()->post_name ?? '';
    if (in_array($slug, ['contact', 'stories'], true)) {
        wp_safe_redirect(home_url('/'), 301);
        exit;
    }
});

// --- [boh_countdown] -----------------------------------------------------
add_shortcode('boh_countdown', function ($atts) {
    $a = shortcode_atts(['to' => BOH_EVENT_ISO, 'label' => ''], $atts);
    $target_ms = strtotime($a['to']) * 1000;
    ob_start(); ?>
    <div class="boh-countdown" data-target="<?php echo esc_attr($target_ms); ?>">
      <div class="boh-countdown__unit"><span class="boh-countdown__num" data-unit="d">--</span><span class="boh-countdown__label">Days</span></div>
      <div class="boh-countdown__unit"><span class="boh-countdown__num" data-unit="h">--</span><span class="boh-countdown__label">Hours</span></div>
      <div class="boh-countdown__unit"><span class="boh-countdown__num" data-unit="m">--</span><span class="boh-countdown__label">Minutes</span></div>
      <div class="boh-countdown__unit"><span class="boh-countdown__num" data-unit="s">--</span><span class="boh-countdown__label">Seconds</span></div>
    </div>
    <script>
    (function(){
      const root = document.currentScript.previousElementSibling;
      const target = parseInt(root.dataset.target, 10);
      const pad = n => String(n).padStart(2,'0');
      function tick(){
        const diff = target - Date.now();
        if (diff <= 0) { root.classList.add('boh-countdown--live'); root.querySelectorAll('[data-unit]').forEach(e => e.textContent = '00'); return; }
        const s = Math.floor(diff/1000)%60;
        const m = Math.floor(diff/60000)%60;
        const h = Math.floor(diff/3600000)%24;
        const d = Math.floor(diff/86400000);
        root.querySelector('[data-unit="d"]').textContent = pad(d);
        root.querySelector('[data-unit="h"]').textContent = pad(h);
        root.querySelector('[data-unit="m"]').textContent = pad(m);
        root.querySelector('[data-unit="s"]').textContent = pad(s);
      }
      tick(); setInterval(tick, 1000);
    })();
    </script>
    <?php
    return ob_get_clean();
});

// --- [boh_impact] — donation impact calculator ---------------------------
add_shortcode('boh_impact', function ($atts) {
    $a = shortcode_atts([
        'cta_url'  => '/donate',
        'cta_text' => 'Give now',
    ], $atts);

    // Each tier: amount => [headline, items[], beneficiaries]
    $tiers = [
        25  => ['Comfort essentials for 1 woman', ['Journal', 'Tea blend', 'Hand cream', 'Wellness card'], 1],
        50  => ['A care basket for 1 family',     ['Cozy blanket', 'Self-care kit', 'Grocery card', 'Welcome note'], 1],
        100 => ['Two complete baskets',           ['2 blankets', 'Toiletry kits', 'Spa gift cards', 'Care notes'], 2],
        250 => ['Five families supported',        ['5 baskets', 'Bath sets', 'Journals', 'Household essentials'], 5],
        500 => ['A full evening of giving',       ['10 baskets', 'Refreshments', 'Workshop supplies', 'Volunteer support'], 10],
    ];

    $json = wp_json_encode($tiers);
    ob_start(); ?>
    <div class="boh-impact" data-tiers='<?php echo esc_attr($json); ?>'>
      <div class="boh-impact__eyebrow">Where your gift goes</div>
      <h3 class="boh-impact__title">See what your contribution becomes.</h3>
      <div class="boh-impact__amounts">
        <?php $first = true; foreach ($tiers as $amt => $_) : ?>
          <button type="button" class="boh-impact__btn<?php echo $amt === 50 ? ' is-active' : ''; ?>" data-amt="<?php echo esc_attr($amt); ?>">$<?php echo esc_html($amt); ?></button>
        <?php endforeach; ?>
      </div>
      <div class="boh-impact__result">
        <span class="boh-impact__result-num" data-out="num">$50</span>
        <div class="boh-impact__result-text" data-out="text">A care basket for 1 family</div>
        <div class="boh-impact__items" data-out="items"></div>
      </div>
      <a class="boh-impact__cta" href="<?php echo esc_url($a['cta_url']); ?>"><?php echo esc_html($a['cta_text']); ?> →</a>
    </div>
    <script>
    (function(){
      const root = document.currentScript.previousElementSibling;
      const tiers = JSON.parse(root.dataset.tiers);
      const btns = root.querySelectorAll('.boh-impact__btn');
      const num  = root.querySelector('[data-out="num"]');
      const txt  = root.querySelector('[data-out="text"]');
      const items= root.querySelector('[data-out="items"]');
      const cta  = root.querySelector('.boh-impact__cta');
      // CTA is just a scroll-anchor — passing ?give-amount auto-opens the
      // GiveWP form (defeating reveal mode), so don't append params.
      function render(amt){
        const t = tiers[amt];
        if (!t) return;
        num.textContent = '$' + amt;
        txt.textContent = t[0];
        items.innerHTML = t[1].map(i => '<span class="boh-impact__chip">' + i + '</span>').join('');
      }
      btns.forEach(b => b.addEventListener('click', () => {
        btns.forEach(x => x.classList.remove('is-active'));
        b.classList.add('is-active');
        render(b.dataset.amt);
      }));
      render(50);
    })();
    </script>
    <?php
    return ob_get_clean();
});

// --- [boh_transparency] --------------------------------------------------
add_shortcode('boh_transparency', function ($atts) {
    $a = shortcode_atts([
        'program'   => 84,
        'logistics' => 11,
        'overhead'  => 5,
    ], $atts);
    ob_start(); ?>
    <div class="boh-transparency">
      <h3 class="boh-transparency__title">Every dollar, traced.</h3>
      <p class="boh-transparency__sub">Of every $1 donated to Baskets of Hope, here is exactly where it goes:</p>
      <div class="boh-transparency__bar" role="img" aria-label="Breakdown of where donations go">
        <div class="boh-transparency__seg boh-transparency__seg--program"   style="width:<?php echo (int)$a['program']; ?>%"></div>
        <div class="boh-transparency__seg boh-transparency__seg--logistics" style="width:<?php echo (int)$a['logistics']; ?>%"></div>
        <div class="boh-transparency__seg boh-transparency__seg--overhead"  style="width:<?php echo (int)$a['overhead']; ?>%"></div>
      </div>
      <div class="boh-transparency__legend">
        <div class="boh-transparency__item">
          <span class="boh-transparency__dot" style="background:var(--boh-magenta)"></span>
          <div>
            <span class="boh-transparency__pct"><?php echo (int)$a['program']; ?>¢</span>
            <div class="boh-transparency__label">Baskets &amp; programs to WIN House</div>
          </div>
        </div>
        <div class="boh-transparency__item">
          <span class="boh-transparency__dot" style="background:var(--boh-yellow)"></span>
          <div>
            <span class="boh-transparency__pct"><?php echo (int)$a['logistics']; ?>¢</span>
            <div class="boh-transparency__label">Event &amp; delivery logistics</div>
          </div>
        </div>
        <div class="boh-transparency__item">
          <span class="boh-transparency__dot" style="background:var(--boh-ink)"></span>
          <div>
            <span class="boh-transparency__pct"><?php echo (int)$a['overhead']; ?>¢</span>
            <div class="boh-transparency__label">Payment fees &amp; admin</div>
          </div>
        </div>
      </div>
      <div class="boh-trust">
        <div class="boh-trust__item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M5 13l4 4L19 7"/></svg>
          Tax-receipt issued within minutes
        </div>
        <div class="boh-trust__item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 2l9 4v6c0 5-3.8 9.7-9 10-5.2-.3-9-5-9-10V6l9-4z"/></svg>
          Bank-grade encryption (PCI DSS)
        </div>
        <div class="boh-trust__item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M3 7h18M3 12h18M3 17h12"/></svg>
          Annual public reporting
        </div>
        <div class="boh-trust__item">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/></svg>
          100% goes to WIN House programs above overhead
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
});

// --- [boh_event_meta] ----------------------------------------------------
add_shortcode('boh_event_meta', function () {
    // Every value is editable in BoH Content -> RSVP & event details.
    $cells = [
        ['When',     boh_content('event.when',     'Tue, Nov 3, 2026'),   boh_content('event.when_sub',     '6:00 PM MT')],
        ['Where',    boh_content('event.where',    'Rohit Group Office'), boh_content('event.where_sub',    '10130 112 St NW, Edmonton')],
        ['Benefits', boh_content('event.benefits', 'WIN House'),          boh_content('event.benefits_sub', "Edmonton's shelter for survivors")],
        ['Bring',    boh_content('event.bring',    '12 comfort items'),   boh_content('event.bring_sub',    'Or contribute online')],
    ];
    ob_start(); ?>
    <div class="boh-event-meta">
      <?php foreach ($cells as [$label, $val, $sub]) : ?>
        <div class="boh-event-meta__cell">
          <div class="boh-event-meta__label"><?php echo esc_html($label); ?></div>
          <div class="boh-event-meta__val"><?php echo esc_html($val); ?></div>
          <?php if ($sub !== '') : ?>
            <div class="boh-event-meta__sub"><?php echo esc_html($sub); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
});

// --- [boh_calendar] — Google/iCal "add to calendar" ---------------------
add_shortcode('boh_calendar', function () {
    $start_gcal = gmdate('Ymd\THis\Z', strtotime(BOH_EVENT_ISO));
    $end_gcal   = gmdate('Ymd\THis\Z', strtotime(BOH_EVENT_END));
    $gcal = add_query_arg([
        'action'   => 'TEMPLATE',
        'text'     => rawurlencode(BOH_EVENT_TITLE),
        'dates'    => $start_gcal . '/' . $end_gcal,
        'details'  => rawurlencode('Join us for an evening of community and giving in support of WIN House.'),
        'location' => rawurlencode(BOH_EVENT_LOC),
    ], 'https://calendar.google.com/calendar/render');

    $ics_url = home_url('/?boh_ics=1');

    ob_start(); ?>
    <div class="boh-cal">
      <a href="<?php echo esc_url($gcal); ?>" target="_blank" rel="noopener">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Add to Google Calendar
      </a>
      <a href="<?php echo esc_url($ics_url); ?>">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/></svg>
        Download .ics (Apple / Outlook)
      </a>
    </div>
    <?php
    return ob_get_clean();
});

// ICS endpoint
add_action('init', function () {
    if (empty($_GET['boh_ics'])) return;
    $uid = 'boh-' . md5(BOH_EVENT_ISO . BOH_EVENT_TITLE) . '@rohitgroup.com';
    $start = gmdate('Ymd\THis\Z', strtotime(BOH_EVENT_ISO));
    $end   = gmdate('Ymd\THis\Z', strtotime(BOH_EVENT_END));
    $now   = gmdate('Ymd\THis\Z');
    $ics  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//BoH//EN\r\nMETHOD:PUBLISH\r\n";
    $ics .= "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:{$now}\r\nDTSTART:{$start}\r\nDTEND:{$end}\r\n";
    $ics .= 'SUMMARY:' . BOH_EVENT_TITLE . "\r\n";
    $ics .= 'LOCATION:' . BOH_EVENT_LOC . "\r\n";
    $ics .= "DESCRIPTION:An evening of community and giving in support of WIN House.\r\n";
    $ics .= "END:VEVENT\r\nEND:VCALENDAR\r\n";
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="baskets-of-hope.ics"');
    echo $ics;
    exit;
});

// --- [boh_rsvp] — embedded RSVP form ------------------------------------
add_shortcode('boh_rsvp', function ($atts) {
    $a = shortcode_atts(['form_id' => BOH_RSVP_FORM_ID], $atts);

    ob_start(); ?>
    <?php
    // Heading and the optional line beneath it come from
    // BoH Content -> RSVP & event details.
    $rsvp_title = boh_content('rsvp.title', 'Let us know if you can join us');
    $rsvp_intro = boh_content('rsvp.intro', '');
    ?>
    <div class="boh-rsvp">
      <div class="boh-rsvp__header">
        <h3 class="boh-rsvp__title"><?php echo wp_kses_post($rsvp_title); ?></h3>
        <?php if (trim(wp_strip_all_tags((string) $rsvp_intro)) !== '') : ?>
          <div class="boh-rsvp__intro"><?php echo wp_kses_post($rsvp_intro); ?></div>
        <?php endif; ?>
      </div>
      <?php
      if (!empty($a['form_id']) && function_exists('wpcf7_contact_form')) {
          echo do_shortcode('[contact-form-7 id="' . (int)$a['form_id'] . '" title="RSVP"]');
      } else {
          // Fallback: simple mailto if CF7 form isn't wired up yet
          ?>
          <p style="margin-top:0;color:var(--boh-ink-soft)">
            To reserve your place, email <a href="mailto:BoH@rohitgroup.com">BoH@rohitgroup.com</a> with your name, party size, and any dietary needs.
          </p>
          <a class="boh-impact__cta" href="mailto:BoH@rohitgroup.com?subject=RSVP%20-%20Baskets%20of%20Hope%202026">Email to RSVP →</a>
          <?php
      }
      ?>
    </div>
    <?php
    return ob_get_clean();
});

// --- [boh_sticky] — sticky bottom CTA bar -------------------------------
add_shortcode('boh_sticky', function ($atts) {
    $a = shortcode_atts([
        'text'      => 'Be part of the next basket.',
        'cta'       => 'Give',
        'cta_url'   => '/donate',
        'cta2'      => 'RSVP',
        'cta2_url'  => '/rsvp',
    ], $atts);
    ob_start(); ?>
    <div class="boh-sticky" id="bohSticky" role="complementary" aria-label="Quick actions">
      <div class="boh-sticky__text"><?php echo wp_kses_post($a['text']); ?></div>
      <div class="boh-sticky__btns">
        <a class="boh-sticky__btn" href="<?php echo esc_url($a['cta_url']); ?>"><?php echo esc_html($a['cta']); ?></a>
        <a class="boh-sticky__btn boh-sticky__btn--ghost" href="<?php echo esc_url($a['cta2_url']); ?>"><?php echo esc_html($a['cta2']); ?></a>
      </div>
      <button type="button" class="boh-sticky__close" aria-label="Dismiss">×</button>
    </div>
    <script>
    (function(){
      const bar = document.getElementById('bohSticky');
      if (!bar || sessionStorage.getItem('bohStickyDismissed') === '1') { if(bar) bar.remove(); return; }
      function onScroll(){
        if (window.scrollY > 480) bar.classList.add('is-visible');
        else bar.classList.remove('is-visible');
      }
      window.addEventListener('scroll', onScroll, { passive: true });
      onScroll();
      bar.querySelector('.boh-sticky__close').addEventListener('click', () => {
        sessionStorage.setItem('bohStickyDismissed', '1');
        bar.remove();
      });
    })();
    </script>
    <?php
    return ob_get_clean();
});

// Sticky bottom CTA bar removed per July-2026 feedback. The [boh_sticky]
// shortcode still exists if it's ever wanted on a specific page.

/**
 * Custom site footer — 4 columns: brand, quick links, contact, event CTA.
 * Replaces Astra's minimal "Powered by" small footer (which we hide in CSS).
 */
add_action('wp_footer', function () {
    $year = date('Y');
    // TODO: replace with the Baskets-of-Hope-specific LinkedIn URL when provided.
    $linkedin_url = 'https://www.linkedin.com/company/rohit-group-of-companies/';
    ?>
    <footer class="boh-footer" role="contentinfo">
      <div class="boh-footer__inner">

        <div class="boh-footer__brand">
          <h3>Rohit's Baskets <span>of Hope</span></h3>
          <p class="boh-footer__initiative">
            A <a href="https://www.rohitgroup.com" target="_blank" rel="noopener" class="boh-rohit-brand">Rohit Group</a> initiative.
          </p>
          <p>Since 2010, delivering dignity and care to women and families escaping violence — one basket at a time, in partnership with WIN House Edmonton.</p>
          <div class="boh-footer__social" aria-label="Follow Rohit's Baskets of Hope">
            <a href="<?php echo esc_url( $linkedin_url ); ?>" target="_blank" rel="noopener" aria-label="LinkedIn">
              <svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 4h4v4H4zM4 10h4v10H4zM10 10h4v1.5c.7-1 1.9-1.8 3.5-1.8 2.8 0 4.5 1.7 4.5 5V20h-4v-4.5c0-1.5-.5-2.5-2-2.5s-2 1-2 2.5V20h-4V10z"/></svg>
            </a>
          </div>
        </div>

        <div class="boh-footer__col">
          <h4>Quick links</h4>
          <ul>
            <li><a href="/about/">About</a></li>
            <li><a href="/donate/">Donate</a></li>
            <li><a href="/sponsor/">Sponsor</a></li>
            <li><a href="/faqs/">FAQs</a></li>
            <li><a href="/gallery/">Gallery</a></li>
          </ul>
        </div>

        <div class="boh-footer__col">
          <h4>Contact</h4>
          <ul>
            <li><a href="mailto:BoH@rohitgroup.com">BoH@rohitgroup.com</a></li>
            <li><p>10130 112 St NW<br>Edmonton, AB T5K 2K4</p></li>
            <li><p>Mon – Fri · 8 AM – 5 PM MT</p></li>
          </ul>
        </div>

        <div class="boh-footer__cta">
          <span class="boh-eyebrow">Save the date</span>
          <strong>A Night of Giving</strong>
          <p>Tuesday, Nov 3 2026 · 6:00 PM<br>Rohit Group Office, Edmonton</p>
          <a class="boh-btn-cta" href="/rsvp/">RSVP →</a>
        </div>

      </div>

      <div class="boh-footer__bottom">
        <div>&copy; <?php echo esc_html($year); ?> Rohit Group. All rights reserved.</div>
        <div>
          <?php
          // Only link the privacy policy once it is actually published —
          // the draft is still WordPress's boilerplate, and linking it
          // 404'd on every page of the site.
          $boh_privacy_id = (int) get_option('wp_page_for_privacy_policy');
          if ($boh_privacy_id && get_post_status($boh_privacy_id) === 'publish') : ?>
            <a href="<?php echo esc_url(get_permalink($boh_privacy_id)); ?>">Privacy</a> &nbsp;|&nbsp;
          <?php endif; ?>
          Developed by
          <a class="boh-halfcup" href="https://halfcup.ca" target="_blank" rel="noopener" aria-label="HalfCup — opens in a new tab">
            <svg class="boh-halfcup__mark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" fill="none" width="20" height="20" aria-hidden="true" focusable="false">
              <defs>
                <linearGradient id="bohHalfcupGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                  <stop offset="0%" stop-color="#2563EB"></stop>
                  <stop offset="100%" stop-color="#F97316"></stop>
                </linearGradient>
                <clipPath id="bohHalfcupClip">
                  <path d="M49 55 L49 140 Q49 166 75 166 L125 166 Q151 166 151 140 L151 55 Z"></path>
                </clipPath>
              </defs>
              <path d="M45 55 L45 140 Q45 170 75 170 L125 170 Q155 170 155 140 L155 55" stroke="url(#bohHalfcupGrad)" stroke-width="8" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>
              <path d="M155 75 Q185 75 185 105 Q185 135 155 135" stroke="url(#bohHalfcupGrad)" stroke-width="8" fill="none" stroke-linecap="round"></path>
              <g clip-path="url(#bohHalfcupClip)">
                <rect x="49" y="112.5" width="102" height="57.5" fill="url(#bohHalfcupGrad)" opacity="0.15"></rect>
              </g>
              <line x1="49" y1="112.5" x2="151" y2="112.5" stroke="url(#bohHalfcupGrad)" stroke-width="3" stroke-dasharray="6 4" opacity="0.6"></line>
            </svg><span class="boh-halfcup__label">HalfCup</span>
          </a>
        </div>
      </div>
    </footer>
    <?php
}, 50);

/**
 * Inline JS for: scroll-reveal (IntersectionObserver), scroll-spy (active nav
 * link), header elevation on scroll, and stat counter count-up animation.
 * All vanilla, no jQuery. Respects prefers-reduced-motion.
 */
/**
 * Social sharing metadata (Open Graph + Twitter Card) and the site icon.
 *
 * No SEO plugin is active — Yoast is installed but switched off — so nothing
 * was emitting og: tags at all. Pasting a link into WhatsApp, iMessage,
 * Slack, Facebook or LinkedIn produced a bare URL with no image.
 *
 * Every page shares the same brand card (assets/share-card.png, 1200x630)
 * so the logo is what people see, which is what was asked for. Titles and
 * descriptions are still per-page.
 */
function boh_share_description(): string
{
    if (is_front_page() || !is_singular()) {
        $desc = get_bloginfo('description');
    } else {
        $post = get_queried_object();
        $desc = has_excerpt($post) ? get_the_excerpt($post) : '';
        if ($desc === '' && $post instanceof WP_Post) {
            // Shortcodes and blocks would otherwise leak markup into the
            // preview text, so strip them before trimming.
            $raw  = strip_shortcodes((string) $post->post_content);
            $raw  = wp_strip_all_tags(do_blocks($raw));
            $desc = trim(preg_replace('/\s+/', ' ', $raw));
        }
        if ($desc === '') {
            $desc = get_bloginfo('description');
        }
    }
    return wp_html_excerpt(html_entity_decode($desc, ENT_QUOTES, 'UTF-8'), 200, '…');
}

add_action('wp_head', function () {
    $card  = boh_content('brand.share_card', get_stylesheet_directory_uri() . '/assets/share-card.png');
    $title = is_front_page()
        ? get_bloginfo('name') . ' — ' . get_bloginfo('description')
        : wp_get_document_title();
    $url   = is_singular() ? get_permalink() : home_url(add_query_arg([], $GLOBALS['wp']->request ?? ''));
    $desc  = boh_share_description();

    $tags = [
        ['property', 'og:site_name',   get_bloginfo('name')],
        ['property', 'og:type',        is_front_page() ? 'website' : 'article'],
        ['property', 'og:title',       $title],
        ['property', 'og:description', $desc],
        ['property', 'og:url',         $url],
        ['property', 'og:locale',      get_locale()],
        ['property', 'og:image',       $card],
        // Facebook and LinkedIn render the preview from these without having
        // to fetch the file first, which is what stops the first share of a
        // link showing no image.
        ['property', 'og:image:width',  '1200'],
        ['property', 'og:image:height', '630'],
        ['property', 'og:image:type',   'image/png'],
        ['property', 'og:image:alt',    get_bloginfo('name') . ' logo'],
        ['name',     'twitter:card',        'summary_large_image'],
        ['name',     'twitter:title',       $title],
        ['name',     'twitter:description', $desc],
        ['name',     'twitter:image',       $card],
        ['name',     'twitter:image:alt',   get_bloginfo('name') . ' logo'],
    ];

    foreach ($tags as [$attr, $key, $value]) {
        if ($value === '' || $value === null) {
            continue;
        }
        printf(
            "<meta %s=\"%s\" content=\"%s\">\n",
            esc_attr($attr),
            esc_attr($key),
            esc_attr($value)
        );
    }

    // A plain description tag too, for search results and for the clients
    // that read it instead of og:description.
    printf("<meta name=\"description\" content=\"%s\">\n", esc_attr($desc));
}, 4);

/**
 * Mark the document as JS-capable before anything paints.
 *
 * The scroll-reveal hides content until JS reveals it. If the script never
 * runs — an error earlier on the page, a blocked file — that content stays
 * invisible for good. Gating the hidden state on this class means the
 * no-JS path renders everything instead of nothing.
 */
add_action('wp_head', function () {
    echo "<script>document.documentElement.classList.add('boh-js');</script>\n";
}, 1);

/**
 * Decorative artwork lives in CSS backgrounds, so a chosen image is handed to
 * the stylesheet as a custom property rather than by rewriting the CSS file.
 * Nothing is printed unless something was actually chosen — the stylesheet
 * keeps its own url() as the fallback inside each var().
 */
add_action('wp_head', function () {
    if (!function_exists('boh_content')) {
        return;
    }
    // The defaults here match the url() fallbacks inside style.css, so the
    // admin shows the artwork actually in use rather than an empty box.
    $ship_flourish = home_url('/wp-content/uploads/2026/07/boh-flower-main-697x1024.jpg');
    $ship_pattern  = home_url('/wp-content/uploads/2026/07/boh-flower-pattern.jpg');
    $map = [
        '--boh-img-flourish' => [boh_content('brand.hero_flourish', $ship_flourish), $ship_flourish],
        '--boh-img-pattern'  => [boh_content('brand.flower_pattern', $ship_pattern), $ship_pattern],
    ];
    $out = '';
    foreach ($map as $prop => [$url, $ship]) {
        // Only emit an override when it differs from what the CSS already uses.
        if ($url && $url !== $ship) {
            $out .= sprintf('%s:url(\'%s\');', $prop, esc_url($url));
        }
    }
    if ($out) {
        printf("<style id=\"boh-brand-images\">:root{%s}</style>\n", $out);
    }
}, 2);

add_action('wp_footer', function () {
    ?>
    <script>
    (function () {
      const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

      // ── 0. Scroll cue → reparent to the full-height hero cover ──
      // The cue is authored inside the content-height inner container;
      // it must anchor to the cover itself to sit at the hero's bottom.
      // (This footer script runs after the full DOM, unlike the inline
      // slideshow script which runs before the cue exists.)
      const cueHero = document.querySelector('.wp-block-cover.boh-hero');
      const cue = document.querySelector('.boh-scroll-cue');
      if (cueHero && cue && cue.parentElement !== cueHero) {
        cueHero.appendChild(cue);
      }

      // ── 1. Auto-tag elements for scroll-reveal ──────────────────
      document.querySelectorAll(
        '.entry-content > .wp-block-group, ' +
        '.entry-content > .wp-block-cover + *, ' +
        '.entry-content .wp-block-columns, ' +
        '.entry-content .wp-block-quote, ' +
        '.boh-impact, .boh-transparency, .boh-rsvp, ' +
        '.boh-event-meta, .boh-countdown'
      ).forEach(el => {
        if (el.classList.contains('boh-hero')) return;
        el.classList.add('boh-reveal');
      });
      document.querySelectorAll(
        '.boh-steps, .boh-stats, ' +
        '.boh-how-it-works--compact .boh-how-it-works__grid, ' +
        '.boh-quick-links'
      ).forEach(el => {
        el.classList.add('boh-reveal-stagger');
      });

      // ── 2. IntersectionObserver to toggle .is-in ────────────────
      const revealSel = '.boh-reveal, .boh-reveal-stagger, .boh-stats';
      function revealAll() {
        document.querySelectorAll(revealSel).forEach(el => el.classList.add('is-in'));
      }

      if (!reduced && 'IntersectionObserver' in window) {
        // threshold 0, NOT a ratio. A ratio threshold is unreachable for any
        // element taller than the viewport: the gallery page wraps every year
        // in one 8600px group, whose maximum possible intersectionRatio in a
        // 900px window is 0.105 — under the old 0.12 it never fired, so the
        // whole gallery stayed at opacity 0 and looked like a white screen.
        // Firing on first pixel, with the root shortened from the bottom,
        // behaves identically for small blocks and correctly for huge ones.
        const io = new IntersectionObserver((entries) => {
          entries.forEach(e => {
            if (e.isIntersecting) {
              e.target.classList.add('is-in');
              io.unobserve(e.target);
            }
          });
        }, { threshold: 0, rootMargin: '0px 0px -12% 0px' });
        document.querySelectorAll(revealSel).forEach(el => io.observe(el));

        // Failsafe. Content that is hidden until JS says otherwise must never
        // be able to stay hidden: anything still unrevealed once the page has
        // settled is shown regardless of what the observer did.
        window.addEventListener('load', () => {
          setTimeout(() => {
            document.querySelectorAll(revealSel).forEach(el => {
              if (el.classList.contains('is-in')) return;
              const r = el.getBoundingClientRect();
              if (r.top < window.innerHeight && r.bottom > 0) el.classList.add('is-in');
            });
          }, 1200);
        });
      } else {
        revealAll();
      }

      // ── 3. Stat counter — count up from 0 to target ─────────────
      function animateCount(el, target) {
        if (reduced) { el.textContent = target.toLocaleString(); return; }
        const duration = 1400;
        const start = performance.now();
        const isCurrency = /\$/.test(el.textContent);
        const hasPlus    = /\+/.test(el.textContent);
        function step(now) {
          const p = Math.min(1, (now - start) / duration);
          const eased = 1 - Math.pow(1 - p, 3); // easeOutCubic
          const val = Math.floor(target * eased);
          el.textContent = (isCurrency ? '$' : '') + val.toLocaleString() + (hasPlus ? '+' : '');
          if (p < 1) requestAnimationFrame(step);
          else el.textContent = (isCurrency ? '$' : '') + target.toLocaleString() + (hasPlus ? '+' : '');
        }
        requestAnimationFrame(step);
      }
      document.querySelectorAll('.boh-stats h2').forEach(h2 => {
        const raw = h2.textContent.replace(/[^\d]/g, '');
        const target = parseInt(raw, 10);
        if (!target || target < 5) return; // don't animate "15" → too short to notice
        h2.dataset.target = target;
        h2.textContent = '0';
      });
      const statsSection = document.querySelector('.boh-stats');
      if (statsSection) {
        const statObs = new IntersectionObserver((entries) => {
          entries.forEach(e => {
            if (e.isIntersecting) {
              e.target.querySelectorAll('h2[data-target]').forEach(h2 => {
                animateCount(h2, parseInt(h2.dataset.target, 10));
              });
              statObs.unobserve(e.target);
            }
          });
        }, { threshold: 0.3 });
        statObs.observe(statsSection);
      }

      // ── 4. Header background — proportional to scroll ─────────
      // Starts at 10% white over the hero; ramps up to fully white as the
      // hero scrolls out of view. Body still gets `.boh-scrolled` past the
      // hero so nav-link colors / other components can flip in a step.
      const header = document.getElementById('masthead');
      const hero   = document.querySelector('.entry-content > .wp-block-cover:first-child');
      const MIN_ALPHA = 0.10;
      let scrolled = false;
      function rampSpan() {
        // Alpha reaches 1.0 by the time we've scrolled ~80% of the hero.
        return hero ? Math.max(120, hero.offsetHeight * 0.80) : 120;
      }
      function onScroll() {
        const y   = window.scrollY || 0;
        const raw = Math.min(1, Math.max(0, y / rampSpan()));
        const a   = MIN_ALPHA + raw * (1 - MIN_ALPHA);
        if (header) header.style.setProperty('--boh-header-alpha', a.toFixed(3));

        const isScrolled = raw >= 0.98;
        if (isScrolled !== scrolled) {
          scrolled = isScrolled;
          document.body.classList.toggle('boh-scrolled', isScrolled);
        }

        // Separate, earlier trigger for the header mark. `boh-scrolled` only
        // flips at 80% of the hero, which is most of a screen — too late for
        // the logo to feel like it belongs to the scroll.
        document.body.classList.toggle('boh-past-top', y > window.innerHeight * 0.22);
      }
      window.addEventListener('scroll', onScroll, { passive: true });
      window.addEventListener('resize', onScroll);
      onScroll();

      // ── 4b. Mobile menu toggle ─────────────────────────────────
      const toggle = document.querySelector('.boh-nav__toggle');
      const nav = document.querySelector('.boh-nav');
      if (toggle && nav) {
        toggle.addEventListener('click', () => {
          const open = nav.classList.toggle('is-open');
          toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        // Close mobile nav after clicking a link
        nav.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
          nav.classList.remove('is-open');
          toggle.setAttribute('aria-expanded', 'false');
        }));
      }

      // ── 5. Scroll-spy — highlight current section in nav ───────
      const navLinks = Array.from(document.querySelectorAll('.boh-nav__list a[href*="#"]'));
      const navMap = {};
      navLinks.forEach(a => {
        const m = a.getAttribute('href').match(/#([\w-]+)/);
        if (m) navMap[m[1]] = a;
      });
      const sections = Object.keys(navMap)
        .map(id => document.getElementById(id))
        .filter(Boolean);
      if (sections.length) {
        const spyObs = new IntersectionObserver((entries) => {
          entries.forEach(e => {
            const id = e.target.id;
            if (e.isIntersecting && navMap[id]) {
              navLinks.forEach(a => a.classList.remove('is-active'));
              navMap[id].classList.add('is-active');
            }
          });
        }, { rootMargin: '-30% 0px -55% 0px', threshold: 0 });
        sections.forEach(s => spyObs.observe(s));
      }
    })();
    </script>
    <?php
}, 60);

// --- [boh_eyebrow text="…"] inline tag ---------------------------------
add_shortcode('boh_eyebrow', function ($atts, $content = '') {
    $a = shortcode_atts(['text' => $content], $atts);
    return '<span class="boh-eyebrow">' . esc_html($a['text']) . '</span>';
});

// --- [boh_sponsor_tiers] — 8-tier sponsorship cards (per PDF) -----------
add_shortcode('boh_sponsor_tiers', function () {
    $tiers = [
        [
            'level' => 'Platinum Basket 1', 'price' => '$2,000', 'tone' => 'platinum',
            'title' => 'Chef-created dish sponsor',
            'copy'  => 'The most exclusive and limited offering. Covers a chef-curated dining experience for all attendees.',
            'benefits' => ['Logo on table signage', 'Logo on sponsors board', 'Recognition on social media', 'Honourable mention during the event'],
        ],
        [
            'level' => 'Platinum Basket 2', 'price' => '$2,000', 'tone' => 'platinum',
            'title' => 'Hot food sponsor',
            'copy'  => 'Provides a catering station serving all guests throughout the evening.',
            'benefits' => ['Logo on table signage', 'Logo on sponsors board', 'Recognition on social media', 'Honourable mention during the event'],
        ],
        [
            'level' => 'Gold Basket', 'price' => '$1,000 – $1,500', 'tone' => 'gold',
            'title' => 'Wine & beverage sponsor',
            'copy'  => 'Celebrate generosity with a toast — your gift keeps the evening flowing.',
            'benefits' => ['Logo on table signage', 'Logo on sponsors board', 'Recognition on social media'],
        ],
        [
            'level' => 'Silver Basket', 'price' => '$1,000', 'tone' => 'silver',
            'title' => 'Charcuterie sponsor',
            'copy'  => 'Support the elegant charcuterie boards that encourage mingling and conversation.',
            'benefits' => ['Logo on table signage', 'Logo on sponsors board', 'Recognition on social media'],
        ],
        [
            'level' => 'Bronze Basket', 'price' => '$750 – $1,000', 'tone' => 'bronze',
            'title' => 'Signature / welcome drink sponsor',
            'copy'  => 'Provides the ingredients and materials for a signature cocktail and mocktail welcome.',
            'benefits' => ['Logo on table signage', 'Logo on sponsors board', 'Recognition on social media'],
        ],
        [
            'level' => 'Supporting Basket', 'price' => '$500', 'tone' => 'support',
            'title' => 'Candy sponsor',
            'copy'  => 'End the evening on a sweet note — a candy bar display that leaves a lasting impression.',
            'benefits' => ['Logo on table signage', 'Logo on sponsors board', 'Recognition on social media'],
        ],
        [
            'level' => 'The Supporting Strand', 'price' => '$200', 'tone' => 'support',
            'title' => 'Friendship bracelets station',
            'copy'  => 'Supports a dedicated space where attendees craft bracelets for basket recipients and each other.',
            'benefits' => ['Logo on table signage', 'Logo on sponsors board', 'Recognition on social media'],
        ],
        [
            'level' => 'The Woven Handle', 'price' => 'Custom / TBD', 'tone' => 'custom',
            'title' => 'Propose your own package',
            'copy'  => "If none of these levels quite fit, let's weave something together. Propose a package and we'll tailor it to your goals.",
            'benefits' => ['Benefits tailored to your commitment'],
        ],
    ];
    ob_start(); ?>
    <div class="boh-sponsor-tiers">
      <?php foreach ($tiers as $t) : ?>
        <article class="boh-tier boh-tier--<?php echo esc_attr($t['tone']); ?>">
          <div class="boh-tier__band"></div>
          <div class="boh-tier__eyebrow"><?php echo esc_html($t['level']); ?></div>
          <h3 class="boh-tier__title"><?php echo esc_html($t['title']); ?></h3>
          <p class="boh-tier__copy"><?php echo esc_html($t['copy']); ?></p>
          <ul class="boh-tier__benefits">
            <?php foreach ($t['benefits'] as $b) : ?>
              <li><?php echo esc_html($b); ?></li>
            <?php endforeach; ?>
          </ul>
          <div class="boh-tier__price"><?php echo esc_html($t['price']); ?></div>
        </article>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
});

// --- [boh_page_hero] — reusable sub-page header banner --------------
// Used at the top of About / Donate / Sponsor / FAQs / Gallery / RSVP.
// Image sits at top with a soft magenta wash; a floral flourish
// peeks in from the top-right corner; centered eyebrow + heading
// + optional subhead below the image.
add_shortcode('boh_page_hero', function ($atts) {
    $a = shortcode_atts([
        'image'   => '',
        'eyebrow' => '',
        'title'   => '',
        'sub'     => '',
        'align'   => 'center', // center | left
    ], $atts);
    // A photo chosen in BoH Content -> Page headers wins over the one written
    // into the page, so the header image can be swapped from the media
    // library without editing the page markup. Keyed by page slug:
    // /about/ -> hero.about, /50-50/ -> hero.5050.
    $slug  = '';
    $queried = get_queried_object();
    if ( $queried instanceof WP_Post ) {
        $slug = preg_replace( '/[^a-z0-9]/', '', strtolower( $queried->post_name ) );
    }
    // Pass the page's own image as the default, so BoH Content -> Page headers
    // shows the photograph currently in use instead of an empty picker.
    $image = $slug ? boh_content( 'hero.' . $slug, (string) $a['image'] ) : (string) $a['image'];
    if ( ! $image ) {
        $image = $a['image'];
    }
    ob_start(); ?>
    <section class="boh-page-hero boh-page-hero--<?php echo esc_attr( $a['align'] ); ?>">
      <?php if ( $image ) : ?>
        <div class="boh-page-hero__image" role="img" aria-label="<?php echo esc_attr( wp_strip_all_tags( $a['title'] ) ); ?>"
             style="background-image:url('<?php echo esc_url( $image ); ?>')"></div>
      <?php endif; ?>
      <div class="boh-page-hero__flourish" aria-hidden="true"></div>
      <div class="boh-page-hero__body">
        <?php if ( $a['eyebrow'] ) : ?>
          <p class="boh-page-hero__eyebrow"><span class="boh-eyebrow"><?php echo wp_kses_post( $a['eyebrow'] ); ?></span></p>
        <?php endif; ?>
        <?php if ( $a['title'] ) : ?>
          <h1 class="boh-page-hero__title"><?php echo wp_kses_post( $a['title'] ); ?></h1>
        <?php endif; ?>
        <?php if ( $a['sub'] ) : ?>
          <p class="boh-page-hero__sub"><?php echo wp_kses_post( $a['sub'] ); ?></p>
        <?php endif; ?>
      </div>
    </section>
    <?php
    return ob_get_clean();
});

// --- [boh_hero_slideshow] — rotating background images + pagination dots -
// Renders the slideshow markup (slides + dot controls) inside the cover
// block. Auto-rotates every 6s; dots let visitors jump. Pauses on hover.
add_shortcode('boh_hero_slideshow', function () {
    // Brand-guideline flower artwork drives the hero (light + airy). The
    // first two are the brand PDF's "Main" and "Secondary" flower images;
    // rest are event photos for variety. Upload the flower JPGs to
    // Media library as exactly these filenames (or update paths here).
    // Lead with edge-to-edge event photos so the hero fills the viewport with
    // no pink margin bleed. The brand-flower artwork has soft pink borders
    // baked in and reads as empty space at the sides — use it later in the
    // rotation, not as the first frame.
    // Slides are managed in Appearance → Hero Images. The hardcoded list
    // below is only the fallback for a site that has never set them, so the
    // hero is never empty on a fresh install.
    $slides = boh_hero_slide_urls();
    ob_start(); ?>
    <div class="boh-hero-slideshow" aria-hidden="true">
      <?php foreach ($slides as $i => $url) : ?>
        <div class="boh-hero-slide boh-hero-slide--<?php echo $i + 1; ?><?php echo $i === 0 ? ' is-active' : ''; ?>"
             style="background-image: url('<?php echo esc_url($url); ?>')"></div>
      <?php endforeach; ?>
    </div>
    <div class="boh-hero-dots" role="tablist" aria-label="Hero image">
      <?php foreach ($slides as $i => $url) : ?>
        <button type="button" class="boh-hero-dot<?php echo $i === 0 ? ' is-active' : ''; ?>"
                data-slide="<?php echo $i; ?>"
                aria-label="Show hero image <?php echo $i + 1; ?>"
                role="tab" aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"></button>
      <?php endforeach; ?>
    </div>
    <button type="button" class="boh-hero-pause" aria-label="Pause slideshow" aria-pressed="false">
      <span class="boh-hero-pause__icon" aria-hidden="true"></span>
    </button>
    <script>
    (function () {
      // Reparent the slideshow + dots + pause button so they're direct
      // children of the hero cover block. Otherwise they position relative
      // to the block's inner container (which sizes to its text content)
      // and end up outside the visible hero area.
      const container = document.querySelector('.wp-block-cover.boh-hero');
      const slideshow = document.querySelector('.boh-hero-slideshow');
      const dotStrip  = document.querySelector('.boh-hero-dots');
      const pauseBtn  = document.querySelector('.boh-hero-pause');
      if (container && slideshow && slideshow.parentElement !== container) {
        container.insertBefore(slideshow, container.firstChild);
      }
      if (container && dotStrip && dotStrip.parentElement !== container) {
        container.appendChild(dotStrip);
      }
      if (container && pauseBtn && pauseBtn.parentElement !== container) {
        container.appendChild(pauseBtn);
      }

      const dots   = document.querySelectorAll('.boh-hero-dots .boh-hero-dot');
      const slides = document.querySelectorAll('.boh-hero-slideshow .boh-hero-slide');
      if (!dots.length || !slides.length) return;
      const total = slides.length;
      let idx = 0;
      let timer = null;
      let userPaused = false; // explicit pause via the button wins over hover

      function go(next) {
        idx = ((next % total) + total) % total;
        slides.forEach((s, i) => s.classList.toggle('is-active', i === idx));
        dots.forEach((d, i) => {
          const active = i === idx;
          d.classList.toggle('is-active', active);
          d.setAttribute('aria-selected', active ? 'true' : 'false');
        });
      }

      function tick() { go(idx + 1); }

      function start() {
        if (userPaused) return;
        stop();
        timer = setInterval(tick, 6000);
      }
      function stop() {
        if (timer) { clearInterval(timer); timer = null; }
      }

      dots.forEach((d) => {
        d.addEventListener('click', () => {
          go(parseInt(d.dataset.slide, 10));
          start(); // reset the countdown after manual pick
        });
      });

      if (pauseBtn) {
        pauseBtn.addEventListener('click', () => {
          userPaused = !userPaused;
          pauseBtn.classList.toggle('is-paused', userPaused);
          pauseBtn.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
          pauseBtn.setAttribute('aria-label', userPaused ? 'Play slideshow' : 'Pause slideshow');
          if (userPaused) stop();
          else start();
        });
      }

      if (container) {
        container.addEventListener('mouseenter', stop);
        container.addEventListener('mouseleave', start);
      }

      if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        start();
      }
    })();
    </script>
    <?php
    return ob_get_clean();
});

// --- [boh_donate_cards] — two-card layout on /donate/ --------------------
// Left card = external donation to WIN House (new tab). Right card = the
// on-site GiveWP flow ("sponsor a basket") — scrolls to #give-form.
add_shortcode('boh_donate_cards', function () {
    // WIN House's DonorPerfect form dedicated to this event, so gifts made
    // here are attributed to Baskets of Hope rather than the general fund.
    $winhouse_url = 'https://form-renderer-app.donorperfect.io/give/win-house-edmonton-women-shelter-ltd/3p---rohit-baskets-of-hope-2026';
    ob_start(); ?>
    <div class="boh-donate-cards">
      <?php
      // Both cards are editable in BoH Content -> Donate. The bodies are rich
      // text, so bullet lists and links can be set there; the defaults below
      // reproduce the original markup exactly.
      $card1_url = boh_content('donate.card1_url', $winhouse_url);
      ?>
      <article class="boh-donate-card boh-donate-card--winhouse">
        <div class="boh-donate-card__eyebrow"><?php echo esc_html(boh_content('donate.card1_eyebrow', 'Donate')); ?></div>
        <h3 class="boh-donate-card__title"><?php echo wp_kses_post(boh_content('donate.card1_title', 'Donate directly to WIN&nbsp;House')); ?></h3>
        <div class="boh-donate-card__lede"><?php echo wp_kses_post(boh_content('donate.card1_body',
            '<p>Make a one-time or monthly gift directly to WIN House. Donations support shelter programs, services, and ongoing work with women, non-binary individuals, and children. Donations are tax-deductible.</p>'
            . '<ul class="boh-donate-card__list"><li>Tax receipt</li><li>Monthly or one-time gift</li><li>Used toward WIN House programming</li></ul>')); ?></div>
        <a class="boh-donate-card__cta" href="<?php echo esc_url( $card1_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html(boh_content('donate.card1_button', 'Donate to WIN House ↗')); ?></a>
      </article>

      <article class="boh-donate-card boh-donate-card--sponsor">
        <div class="boh-donate-card__eyebrow"><?php echo esc_html(boh_content('donate.card2_eyebrow', 'Sponsor a basket')); ?></div>
        <h3 class="boh-donate-card__title"><?php echo wp_kses_post(boh_content('donate.card2_title', 'Sponsor a basket')); ?></h3>
        <div class="boh-donate-card__lede"><?php echo wp_kses_post(boh_content('donate.card2_body',
            "<p>Can't attend the event or prefer to contribute financially? Sponsor a basket and we'll use your gift to purchase thoughtful comfort items for recipients.</p>"
            . "<ul class=\"boh-donate-card__list\"><li>If you can't join us at the event, feel free to get in touch to drop off your basket items.</li><li>You can also sponsor a basket by making a cash donation.</li></ul>")); ?></div>
        <a class="boh-donate-card__cta boh-donate-card__cta--primary" href="#give-form"><?php echo esc_html(boh_content('donate.card2_button', 'Sponsor a basket →')); ?></a>
      </article>
    </div>
    <?php
    return ob_get_clean();
});

// --- [boh_how_it_works variant="compact|full"] 4-step process module ---
// full    — full body text (About page)
// compact — number + title only, for the home summary page
add_shortcode('boh_how_it_works', function ($atts) {
    $atts = shortcode_atts([
        'variant' => 'full',
        'intro'   => '1',
    ], $atts, 'boh_how_it_works');
    $compact = ($atts['variant'] === 'compact');
    // Each step carries a photograph from a past event and a one-line
    // detail, so the compact home-page version says something concrete
    // rather than showing four bare headings.
    $up = '/wp-content/uploads/2026/06/';
    // Editable in BoH Content -> Home ("How it works — steps"). Columns are
    // number / title / full text / short text / image; the alt text below is
    // kept in code because it is accessibility copy tied to each photograph.
    $alts = [
        'Volunteers selecting toiletries and self-care items from organized donation bins',
        'Donation station at Rohit headquarters with comfort items ready to pack',
        'Volunteers gathered at a past Baskets of Hope event',
        'A basket being delivered to WIN House',
    ];
    $steps = boh_content('home.steps', [
        [
            '01', 'Choose items',
            'Guests select and purchase 12 new comfort items that recipients can use and enjoy.',
            'Cozy socks, journals, body care, a small blanket — comfort, not necessities.',
            $up . 'get-involved3-768x539.jpg',
            'Volunteers selecting toiletries and self-care items from organized donation bins',
        ],
        [
            '02', 'Bring or contribute',
            'Bring your items to the event, partner with a friend to shop, or sponsor a basket with a financial gift.',
            'Come with a friend, or sponsor a basket if you cannot attend on the night.',
            $up . 'Edmonton-768x539.jpg',
            'Donation station at Rohit headquarters with comfort items ready to pack',
        ],
        [
            '03', 'Pack with care',
            'After the event, the Rohit team transforms the donated items to baskets packed with care and intention.',
            'Every basket is assembled by hand and finished with a written note.',
            $up . 'RC_BasketofHope_111123_Inital_20171108-15-1024x681.jpg',
            'Volunteers gathered at a past Baskets of Hope event',
        ],
        [
            '04', 'Deliver hope',
            'The Rohit team delivers the baskets to WIN House, who distributes them to the women as they embark on their next chapter.',
            'Delivered privately to WIN House, with respect for residents\' safety.',
            $up . 'IMG_5843-1024x768.jpg',
            'A basket being delivered to WIN House',
        ],
    ]);
    $classes = 'boh-how-it-works' . ($compact ? ' boh-how-it-works--compact' : '');
    ob_start(); ?>
    <div class="<?php echo esc_attr($classes); ?>">
      <?php if ($atts['intro'] === '1') : ?>
      <div class="boh-how-it-works__intro">
        <span class="boh-eyebrow">How it works</span>
        <h2><?php echo wp_kses_post(boh_content('home.steps_heading', 'From your hands to a family in <em>need</em>.')); ?></h2>
        <?php
        // Rich text, so the team can link words in this paragraph — the
        // Donate link below is the default rather than something that has to
        // be re-added by hand after every edit.
        echo wp_kses_post(boh_content('home.steps_lede',
            '<p>Four simple steps. One basket. A real moment of comfort for someone starting over. '
            . 'Prefer to give financially? <a href="/donate/">Make a donation</a> instead.</p>'));
        ?>
      </div>
      <?php endif; ?>
      <div class="boh-how-it-works__grid">
        <?php foreach ($steps as $si => $step) :
          // Saved rows carry five columns; the shipped defaults carry six.
          [$num, $title, $body, $detail, $img] = array_pad(array_slice((array) $step, 0, 5), 5, '');
          $alt = $step[5] ?? ($alts[$si] ?? '');
        ?>
          <div class="boh-how-it-works__step">
            <figure class="boh-how-it-works__media">
              <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($alt); ?>"
                   loading="lazy" decoding="async" width="768" height="539">
              <span class="boh-how-it-works__num"><?php echo esc_html($num); ?></span>
            </figure>
            <h3 class="boh-how-it-works__title"><?php echo esc_html($title); ?></h3>
            <?php if ($compact) : ?>
              <p class="boh-how-it-works__detail"><?php echo esc_html($detail); ?></p>
            <?php else : ?>
              <p class="boh-how-it-works__body"><?php echo esc_html($body); ?></p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
});

// --- [boh_quick_links] — grid of section-nav cards for the home page ---
add_shortcode('boh_quick_links', function () {
    $links = boh_content('home.quick_links', [
        ['/about/',   'About',    'Learn how Baskets of Hope began and read about the history of the event.'],
        ['/donate/',  'Donate',   'Make a financial contribution to Baskets of Hope or donate directly to WIN House.'],
        ['/sponsor/', 'Corporate Sponsorship', 'Explore sponsorship opportunities for this year’s event.'],
        ['/faqs/',    'FAQs',     'Find answers about the event, what to bring, donations, and more.'],
        ['/gallery/', 'Gallery',  'See photos and highlights from past Baskets of Hope events.'],
        ['/rsvp/',    'RSVP',     'Join us for this year’s Baskets of Hope event on November 3, 2026.'],
    ]);
    ob_start(); ?>
    <div class="boh-quick-links" role="list">
      <?php foreach ($links as [$href, $title, $desc]) : ?>
        <a class="boh-quick-links__card" href="<?php echo esc_url($href); ?>" role="listitem">
          <div class="boh-quick-links__title"><?php echo esc_html($title); ?><span aria-hidden="true" class="boh-quick-links__arrow">→</span></div>
          <div class="boh-quick-links__desc"><?php echo esc_html($desc); ?></div>
        </a>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
});

// --- [boh_stats] — moved from home to about ----------------------------
add_shortcode('boh_stats', function () {
    // Editable in BoH Content -> Home. The figures below are only the
    // fallback shown before anyone has saved real numbers.
    $stats = boh_content('home.stats', [
        ['2,847', 'Baskets delivered'],
        ['1,156', 'Families supported'],
        ['3,421', 'Volunteers engaged'],
        ['15',    'Years of service'],
    ]);
    ob_start(); ?>
    <div class="boh-stats">
      <?php foreach ($stats as [$num, $label]) : ?>
        <div class="boh-stats__cell">
          <h2><?php echo esc_html($num); ?></h2>
          <div class="boh-stats__label"><?php echo esc_html($label); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
});

// --- [boh_faqs] — accordion-style FAQ list -----------------------------
add_shortcode('boh_faqs', function () {
    // Editable in BoH Content -> FAQs. Add, reorder or delete questions there.
    $faqs = boh_content('faqs.items', [
        ['What is Rohit\'s Baskets of Hope?',
         'Rohit\'s Baskets of Hope is an annual community giving event that collects monetary and in-kind donations for people supported by WIN House in Edmonton. Guests bring comfort items, help fill baskets, and support an evening dedicated to care, dignity, and hope.'],
        ['Who does Baskets of Hope support?',
         'In Edmonton, Baskets of Hope supports WIN House and the women, non-binary individuals, and children they serve while fleeing domestic violence.'],
        ['When and where is this year\'s event?',
         'This year\'s event is scheduled for Tuesday, November 3, 2026 at 6:00 PM at the Rohit Group Office, 10130 112 St NW, Edmonton, AB T5K 2K4. Please confirm the final date and time before publishing.'],
        ['What should I bring?',
         'Guests are encouraged to bring 12 new comfort items for the baskets. Suggested items include cozy socks or slippers, journals, books, body care, hand lotion, bath products, shampoo, conditioner, toothbrushes, reusable water bottles, small blankets, gift cards, or other thoughtful self-care items.'],
        ['Do I need to bring all 12 items myself?',
         'No. You are welcome to partner with a friend, family member, or colleague to contribute the 12 items together. The goal is to make giving feel accessible and shared.'],
        ['Can I attend if I cannot bring items?',
         'Yes. You can still attend, make a monetary gift, sponsor a basket, or contribute in another way. Every form of support helps.'],
        ['Can I donate if I cannot attend the event?',
         'Yes. You can donate directly to WIN House or sponsor a basket through Baskets of Hope. The Donate page includes both options.'],
        ['Where do the baskets go?',
         'Baskets are delivered to WIN House with care and respect for the privacy of the people receiving them.'],
        ['Can my company sponsor the event?',
         'Yes. Sponsorship opportunities are available for businesses and community partners. Visit the Sponsor page to download the sponsorship package or contact us to discuss a custom contribution.'],
        ['Can I donate a silent auction item?',
         'Yes. High-value items, services, and experiences can be contributed to the silent auction. Please contact the Baskets of Hope team to coordinate details.'],
        ['Will I receive a tax receipt?',
         'Donations made directly to WIN House are handled through WIN House, including receipting. Please confirm receipt details for basket sponsorships or other event contributions before making a gift.'],
        ['Who can I contact with questions?',
         'Email <a href="mailto:BoH@rohitgroup.com">BoH@rohitgroup.com</a> and a member of the team will follow up.'],
    ]);
    ob_start(); ?>
    <div class="boh-faqs">
      <?php foreach ($faqs as $i => [$q, $a]) : ?>
        <details class="boh-faq"<?php echo $i === 0 ? ' open' : ''; ?>>
          <summary class="boh-faq__q"><?php echo esc_html($q); ?><span class="boh-faq__icon" aria-hidden="true">+</span></summary>
          <div class="boh-faq__a"><?php echo wp_kses_post($a); ?></div>
        </details>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
});

// --- [boh_gallery year="2025"] — event photo/video gallery -------------
// Reads from a filter so image URLs live outside code. Empty state shows
// a friendly "coming soon" until albums are populated.
/**
 * How many of the most recent years get a section of their own. Everything
 * older is folded into a single "Past Events" section.
 */
if (!defined('BOH_GALLERY_RECENT_YEARS')) {
    define('BOH_GALLERY_RECENT_YEARS', 3);
}

/**
 * Render one gallery block: the photo grid, its lightbox, and the small
 * script that wires them together.
 *
 * Each block is a self-contained `.boh-gallery`, and the viewer script scopes
 * itself with `closest('.boh-gallery')`, so several can sit on one page and
 * each carousel pages only through its own photos.
 *
 * @param array  $items  Gallery items, in display order.
 * @param string $label  Used for the viewer's accessible name.
 */
function boh_gallery_block(array $items, string $label): string
{
    // Only the first block on the page is above the fold. Every later section
    // (2024, 2023, Past Events) starts thousands of pixels down, so eager
    // loading there would fetch a hundred photos nobody has scrolled to.
    static $block_index = 0;
    $boh_gallery_eager = ($block_index === 0);
    $block_index++;

    ob_start(); ?>
    <div class="boh-gallery" data-selected-year="<?php echo esc_attr($label); ?>">
      <?php if (empty($items)) : ?>
        <div class="boh-gallery__empty">
          <p><strong>Photos coming soon.</strong></p>
          <p>Once <?php echo esc_html($label); ?> event photos and videos are ready, they'll appear here. Have media to share? Email <a href="mailto:BoH@rohitgroup.com">BoH@rohitgroup.com</a>.</p>
        </div>
      <?php else : ?>
        <div class="boh-gallery__grid">
          <?php foreach ($items as $i => $item) : ?>
            <?php
            // Real aspect ratio drives the tile's shape, so the masonry is made
            // of the photographs themselves rather than arbitrary spans — and
            // nothing is cropped. Falls back to 4:3 when dimensions are unknown.
            $g_ar = (!empty($item['w']) && !empty($item['h']))
                ? (int) $item['w'] . ' / ' . (int) $item['h']
                : '4 / 3';
            ?>
            <figure class="boh-gallery__cell boh-gallery__cell--<?php echo esc_attr($item['type']); ?>"
                    style="--boh-ar: <?php echo esc_attr($g_ar); ?>">
              <button type="button" class="boh-gallery__open" data-boh-index="<?php echo (int) $i; ?>"
                      aria-label="<?php echo esc_attr(
                          ($item['caption'] ?? '') !== ''
                              ? sprintf('Open "%s" (%d of %d)', $item['caption'], $i + 1, count($items))
                              : sprintf('Open %s %d of %d', $item['type'], $i + 1, count($items))
                      ); ?>">
                <?php if ($item['type'] === 'video') : ?>
                  <video preload="metadata" muted playsinline<?php if (!empty($item['poster'])) echo ' poster="' . esc_url($item['poster']) . '"'; ?>>
                    <source src="<?php echo esc_url($item['url']); ?>">
                  </video>
                  <span class="boh-gallery__play" aria-hidden="true"></span>
                <?php else : ?>
                  <?php
                  // Serve the resized copies, never the original: these files are
                  // camera masters up to 15MB and the tile is a few hundred pixels.
                  // `small`/`thumb`/`large` fall back to the original when the
                  // derivatives have not been built for that photo yet.
                  $g_small = $item['small'] ?? $item['url'];
                  $g_thumb = $item['thumb'] ?? $item['url'];
                  // Only the opening tiles of the first section are worth fetching
                  // eagerly; everything else waits until it is near the viewport.
                  $g_eager = $boh_gallery_eager && $i < 4;
                  ?>
                  <img src="<?php echo esc_url($g_thumb); ?>"
                       <?php if ($g_small !== $g_thumb) : ?>srcset="<?php echo esc_attr(esc_url($g_small) . ' 480w, ' . esc_url($g_thumb) . ' 960w'); ?>"
                       sizes="(max-width: 600px) 50vw, (max-width: 1000px) 33vw, 320px"<?php endif; ?>
                       alt="<?php echo esc_attr($item['caption'] ?? ''); ?>"
                       <?php if (!empty($item['w']) && !empty($item['h'])) : ?>width="<?php echo (int) $item['w']; ?>" height="<?php echo (int) $item['h']; ?>"<?php endif; ?>
                       loading="<?php echo $g_eager ? 'eager' : 'lazy'; ?>"
                       <?php if (!$g_eager) : ?>fetchpriority="low" <?php endif; ?>
                       decoding="<?php echo $g_eager ? 'sync' : 'async'; ?>">
                <?php endif; ?>
              </button>
              <?php if (!empty($item['caption'])) : ?>
                <figcaption><?php echo esc_html($item['caption']); ?></figcaption>
              <?php endif; ?>
            </figure>
          <?php endforeach; ?>
        </div>

        <?php
        // Payload for the viewer: the same items, in grid order.
        $payload = array_map(function ($it) {
            return [
                'type'    => $it['type'],
                // The viewer gets the 1800px copy. Opening a 15MB master to fill
                // a ~1000px box is what made tapping a photo feel broken.
                'url'     => $it['large'] ?? $it['url'],
                'thumb'   => $it['small'] ?? ($it['thumb'] ?? $it['url']),
                'caption' => $it['caption'] ?? '',
                'poster'  => $it['poster'] ?? '',
            ];
        }, array_values($items));
        ?>
        <div class="boh-lightbox" hidden data-boh-items="<?php echo esc_attr(wp_json_encode($payload)); ?>"
             role="dialog" aria-modal="true" aria-label="<?php echo esc_attr($label); ?> gallery viewer">
          <button type="button" class="boh-lightbox__close" aria-label="Close viewer">&times;</button>
          <button type="button" class="boh-lightbox__nav boh-lightbox__nav--prev" aria-label="Previous">&#8249;</button>
          <button type="button" class="boh-lightbox__nav boh-lightbox__nav--next" aria-label="Next">&#8250;</button>
          <figure class="boh-lightbox__stage">
            <div class="boh-lightbox__media" data-boh-media></div>
            <figcaption class="boh-lightbox__meta">
              <span class="boh-lightbox__caption" data-boh-caption></span>
              <span class="boh-lightbox__counter" data-boh-counter></span>
            </figcaption>
          </figure>
        </div>

        <script>
        (function () {
          var root = document.currentScript.closest('.boh-gallery');
          if (!root) return;
          var box = root.querySelector('.boh-lightbox');
          if (!box) return;

          var items   = JSON.parse(box.dataset.bohItems || '[]');
          var media   = box.querySelector('[data-boh-media]');
          var capEl   = box.querySelector('[data-boh-caption]');
          var cntEl   = box.querySelector('[data-boh-counter]');
          var prevBtn = box.querySelector('.boh-lightbox__nav--prev');
          var nextBtn = box.querySelector('.boh-lightbox__nav--next');
          var closeBtn= box.querySelector('.boh-lightbox__close');
          var index   = 0;
          var opener  = null;

          function render() {
            var it = items[index];
            if (!it) return;
            media.innerHTML = '';
            var node;
            if (it.type === 'video') {
              node = document.createElement('video');
              node.src = it.url;
              node.controls = true;
              node.autoplay = true;
              node.playsInline = true;
              if (it.poster) node.poster = it.poster;
            } else {
              node = document.createElement('img');
              node.src = it.url;
              node.alt = it.caption || '';
            }
            media.appendChild(node);
            capEl.textContent = it.caption || '';
            cntEl.textContent = (index + 1) + ' / ' + items.length;
            // A single-item gallery has nothing to page through.
            var many = items.length > 1;
            prevBtn.hidden = !many;
            nextBtn.hidden = !many;
          }

          function open(i, trigger) {
            index = i;
            opener = trigger || null;
            // The viewer is position:fixed, but an ancestor of the gallery
            // carries a transform (the scroll-reveal), and a transformed
            // ancestor becomes the containing block for fixed descendants —
            // so the viewer was positioning itself against that element and
            // opening thousands of pixels down the page instead of over the
            // screen. Re-parent it to <body> once, where nothing traps it.
            if (box.parentNode !== document.body) {
              document.body.appendChild(box);
            }
            box.hidden = false;
            document.body.style.overflow = 'hidden';
            render();
            closeBtn.focus();
          }
          function close() {
            box.hidden = true;
            media.innerHTML = '';           // stops any playing video
            document.body.style.overflow = '';
            if (opener) opener.focus();     // return focus where it came from
          }
          function step(delta) {
            index = (index + delta + items.length) % items.length;
            render();
          }

          root.querySelectorAll('.boh-gallery__open').forEach(function (btn) {
            btn.addEventListener('click', function () {
              open(parseInt(btn.dataset.bohIndex, 10) || 0, btn);
            });
          });

          closeBtn.addEventListener('click', close);
          prevBtn.addEventListener('click', function () { step(-1); });
          nextBtn.addEventListener('click', function () { step(1); });
          // Click the backdrop (but not the media or controls) to dismiss.
          box.addEventListener('click', function (e) { if (e.target === box) close(); });

          document.addEventListener('keydown', function (e) {
            if (box.hidden) return;
            if (e.key === 'Escape')     { close(); }
            else if (e.key === 'ArrowLeft')  { step(-1); }
            else if (e.key === 'ArrowRight') { step(1); }
            else if (e.key === 'Tab')   { e.preventDefault(); closeBtn.focus(); }
          });

          // Swipe on touch devices.
          var x0 = null;
          box.addEventListener('touchstart', function (e) { x0 = e.changedTouches[0].clientX; }, {passive: true});
          box.addEventListener('touchend', function (e) {
            if (x0 === null) return;
            var dx = e.changedTouches[0].clientX - x0;
            if (Math.abs(dx) > 50) step(dx < 0 ? 1 : -1);
            x0 = null;
          }, {passive: true});
        })();
        </script>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

add_shortcode('boh_gallery', function ($atts) {
    $a = shortcode_atts(['year' => date('Y')], $atts);
    /**
     * Filter: boh_gallery_items
     * Return array keyed by year: [ 2024 => [ ['type'=>'image','url'=>'...','caption'=>'...'], ... ], ... ]
     * Populate via a mu-plugin, or upload via wp-admin Media and hook in.
     */
    $all = apply_filters('boh_gallery_items', []);

    if (empty($all)) {
        return boh_gallery_block([], (string) (int) $a['year']);
    }

    $years = array_map('intval', array_keys($all));
    rsort($years);

    $recent = array_slice($years, 0, BOH_GALLERY_RECENT_YEARS);
    $older  = array_slice($years, BOH_GALLERY_RECENT_YEARS);

    ob_start(); ?>
    <div class="boh-gallery-sections">
      <?php foreach ($recent as $y) : ?>
        <section class="boh-gallery-section" id="gallery-<?php echo (int) $y; ?>">
          <h3 class="boh-gallery-section__title"><?php echo (int) $y; ?></h3>
          <?php echo boh_gallery_block((array) ($all[$y] ?? []), (string) $y); ?>
        </section>
      <?php endforeach; ?>

      <?php
      if (!empty($older)) :
          // Everything before the three most recent years is one section, so
          // the page stays a short read no matter how many years accumulate.
          $past = [];
          foreach ($older as $y) {
              foreach ((array) ($all[$y] ?? []) as $item) {
                  $past[] = $item;
              }
          }
          $oldest = (int) end($older);
          $newest = (int) reset($older);
          $range  = $oldest === $newest ? (string) $oldest : $oldest . ' – ' . $newest;
          if (!empty($past)) : ?>
        <section class="boh-gallery-section" id="gallery-past">
          <h3 class="boh-gallery-section__title">Past Events</h3>
          <p class="boh-gallery-section__note"><?php echo esc_html($range); ?></p>
          <?php echo boh_gallery_block($past, 'Past events'); ?>
        </section>
      <?php endif; endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
});

// --- Relabel GiveWP's built-in "Donation" / "Donate" strings to
//     "Sponsorship" / "Sponsor" — the BoH team frames every gift as
//     sponsoring a basket, so the checkout should read consistently.
add_filter('gettext', function ($translated, $original, $domain) {
    if ($domain !== 'give') return $translated;
    static $map = [
        'Donate Now'                => 'Sponsor Now',
        'Donation Total'            => 'Sponsorship Total',
        'Donation Total:'           => 'Sponsorship Total:',
        'Donation Amount'           => 'Sponsorship Amount',
        'Donation Amount:'          => 'Sponsorship Amount:',
        'Donation Processing...'    => 'Sponsorship Processing...',
        'Choose Your Donation Amount' => 'Choose Your Sponsorship Amount',
        'Custom Donation Amount'    => 'Custom Sponsorship Amount',
    ];
    return $map[$original] ?? $translated;
}, 10, 3);

// Celebratory success view for the RSVP form. Listens for CF7's
// wpcf7mailsent event and swaps the form for a confetti-scattered
// "You're in!" panel with the event details.
add_action( 'wp_footer', function () {
    if ( ! is_page( 'rsvp' ) ) return;
    $event_when_full = 'Tuesday, November 3, 2026 · 6:00 PM';
    $event_where     = 'Rohit Group Office, 10130 112 St NW, Edmonton';
    ?>
    <script>
    (function () {
        // Compact confetti — no dependencies. Bursts brand-colored bits
        // from the top-center of the viewport for ~2.5s.
        function boh_confetti(duration) {
            duration = duration || 2500;
            const colors = ['#d01482', '#f9d135', '#4A1C68', '#ffffff', '#ffb2d6'];
            const layer = document.createElement('div');
            layer.setAttribute('aria-hidden', 'true');
            layer.style.cssText = 'position:fixed;inset:0;pointer-events:none;overflow:hidden;z-index:9999';
            document.body.appendChild(layer);

            const count = 140;
            const w = window.innerWidth;
            for (let i = 0; i < count; i++) {
                const p = document.createElement('span');
                const size = 6 + Math.random() * 10;
                const color = colors[Math.floor(Math.random() * colors.length)];
                const startX = Math.random() * w;
                const endX = startX + (Math.random() - 0.5) * 480;
                const endY = window.innerHeight + 80;
                const rot = (Math.random() - 0.5) * 720;
                const delay = Math.random() * 400;
                const dur = duration + Math.random() * 800;
                const round = Math.random() < 0.35;
                p.style.cssText =
                    'position:absolute;top:-24px;left:' + startX + 'px;' +
                    'width:' + size + 'px;height:' + (size * (round ? 1 : 0.5)) + 'px;' +
                    'background:' + color + ';' +
                    (round ? 'border-radius:50%;' : 'border-radius:2px;') +
                    'opacity:0.95;' +
                    'transform:translateY(0) rotate(0deg);' +
                    'transition:transform ' + dur + 'ms cubic-bezier(.22,.61,.36,1) ' + delay + 'ms, opacity 700ms ease-in ' + (dur - 400 + delay) + 'ms;';
                layer.appendChild(p);
                // Kick off animation next frame
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    p.style.transform = 'translate(' + (endX - startX) + 'px, ' + endY + 'px) rotate(' + rot + 'deg)';
                    p.style.opacity = '0';
                }));
            }
            setTimeout(() => layer.remove(), duration + 1600);
        }

        function boh_show_success() {
            const rsvp = document.querySelector('.boh-rsvp');
            if (!rsvp) return;

            // Read submitted values for personalization
            const form = rsvp.querySelector('form');
            const firstEl = form && form.querySelector('input[name="first-name"]');
            const partyEl = form && form.querySelector('select[name="party-size"]');
            const emailEl = form && form.querySelector('input[name="your-email"]');
            const first = (firstEl && firstEl.value || '').trim().split(/\s+/)[0] || 'friend';
            const party = (partyEl && partyEl.value || '').trim();
            const email = (emailEl && emailEl.value || '').trim();

            const gcal = 'https://calendar.google.com/calendar/render' +
                '?action=TEMPLATE' +
                '&text=' + encodeURIComponent("Rohit's Baskets of Hope 2026") +
                <?php // Derived from BOH_EVENT_ISO/END, not written out by hand:
                      // a second copy of the date drifts the moment the first
                      // one changes, and this one had already gone an hour
                      // stale relative to the "Add to calendar" button above. ?>
                '&dates=<?php echo esc_js( gmdate('Ymd\THis\Z', strtotime(BOH_EVENT_ISO)) . '/' . gmdate('Ymd\THis\Z', strtotime(BOH_EVENT_END)) ); ?>' +
                '&details=' + encodeURIComponent("An evening of community and giving in support of WIN House.") +
                '&location=' + encodeURIComponent(<?php echo wp_json_encode( $event_where ); ?>);
            const ics = '/?boh_ics=1';

            const html = ''
              + '<div class="boh-rsvp-success">'
              +   '<div class="boh-rsvp-success__eyebrow">You\'re in</div>'
              +   '<h3 class="boh-rsvp-success__title">See you November 3, ' + first.replace(/[<>&]/g, '') + '.</h3>'
              +   '<p class="boh-rsvp-success__lede">We\'ve saved you a seat at Rohit\'s Baskets of Hope 2026. A confirmation is on its way to <strong>' + (email.replace(/[<>&]/g, '') || 'your inbox') + '</strong>.</p>'
              +   '<div class="boh-rsvp-success__grid">'
              +     '<div class="boh-rsvp-success__cell">'
              +       '<div class="boh-rsvp-success__label">When</div>'
              +       '<div class="boh-rsvp-success__val">' + <?php echo wp_json_encode( $event_when_full ); ?> + '</div>'
              +     '</div>'
              +     '<div class="boh-rsvp-success__cell">'
              +       '<div class="boh-rsvp-success__label">Where</div>'
              +       '<div class="boh-rsvp-success__val">' + <?php echo wp_json_encode( $event_where ); ?> + '</div>'
              +     '</div>'
              +     '<div class="boh-rsvp-success__cell">'
              +       '<div class="boh-rsvp-success__label">Party of</div>'
              +       '<div class="boh-rsvp-success__val">' + (party.replace(/[<>&]/g, '') || '—') + '</div>'
              +     '</div>'
              +     '<div class="boh-rsvp-success__cell">'
              +       '<div class="boh-rsvp-success__label">Bring</div>'
              +       '<div class="boh-rsvp-success__val">12 comfort items<br><span class="boh-rsvp-success__sub">Or partner with a friend</span></div>'
              +     '</div>'
              +   '</div>'
              +   '<div class="boh-rsvp-success__ctas">'
              +     '<a class="boh-rsvp-success__cta boh-rsvp-success__cta--primary" href="' + gcal + '" target="_blank" rel="noopener">Add to Google Calendar</a>'
              +     '<a class="boh-rsvp-success__cta" href="' + ics + '">Download .ics (Apple / Outlook)</a>'
              +   '</div>'
              +   '<p class="boh-rsvp-success__share">Know someone who\'d love this? <a href="mailto:?subject=' + encodeURIComponent("You should come — Rohit's Baskets of Hope 2026") + '&body=' + encodeURIComponent("I just RSVPed for Rohit's Baskets of Hope on Nov 3. Great cause — free evening, WIN House support. Sign up at https://boh.halfcup.ca/rsvp/") + '">Forward the invitation.</a></p>'
              + '</div>';

            // Hide the form + old spots row + welcome banner
            const toHide = rsvp.querySelectorAll('form, .boh-rsvp__header, .boh-rsvp-welcome');
            toHide.forEach(function (el) {
                el.style.transition = 'opacity .3s ease';
                el.style.opacity = '0';
                setTimeout(() => { el.style.display = 'none'; }, 300);
            });

            setTimeout(function () {
                rsvp.insertAdjacentHTML('afterbegin', html);
                boh_confetti(2600);
                // Smooth-scroll to success
                const t = rsvp.querySelector('.boh-rsvp-success');
                if (t) {
                    const y = t.getBoundingClientRect().top + window.scrollY - 100;
                    window.scrollTo({ top: y, behavior: 'smooth' });
                }
            }, 320);
        }

        document.addEventListener('wpcf7mailsent', function (e) {
            // Only celebrate for the RSVP form (form id 19)
            if (e && e.detail && e.detail.contactFormId && parseInt(e.detail.contactFormId, 10) !== 19) return;
            boh_show_success();
        });
    })();
    </script>
    <style>
    .boh-rsvp-success {
        background: linear-gradient(135deg, rgba(208,20,130,0.09), rgba(249,209,53,0.14));
        border: 1px solid rgba(208,20,130,0.28);
        border-radius: 18px;
        padding: 32px 32px 30px;
        margin: 0 0 12px;
        animation: bohRsvpPop .5s cubic-bezier(.2,.9,.3,1.2) both;
    }
    @keyframes bohRsvpPop {
        from { opacity: 0; transform: translateY(12px) scale(.98); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .boh-rsvp-success__eyebrow {
        display: inline-block;
        background: var(--boh-magenta);
        color: #fff;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        padding: 5px 12px;
        border-radius: 999px;
        margin-bottom: 14px;
    }
    .boh-rsvp-success__title {
        font-family: 'Montserrat', sans-serif !important;
        font-size: clamp(28px, 4vw, 40px) !important;
        font-weight: 800 !important;
        letter-spacing: -0.02em;
        margin: 0 0 10px !important;
        color: var(--boh-ink) !important;
        line-height: 1.15 !important;
    }
    .boh-rsvp-success__lede {
        color: var(--boh-ink-soft);
        font-size: 16px;
        line-height: 1.55;
        margin: 0 0 24px !important;
        max-width: 640px;
    }
    .boh-rsvp-success__grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin: 0 0 26px;
    }
    @media (max-width: 720px) { .boh-rsvp-success__grid { grid-template-columns: repeat(2, 1fr); } }
    .boh-rsvp-success__cell {
        background: #fff;
        border: 1px solid rgba(208,20,130,0.14);
        border-radius: 12px;
        padding: 14px 16px 15px;
    }
    .boh-rsvp-success__label {
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: var(--boh-magenta);
        margin-bottom: 5px;
    }
    .boh-rsvp-success__val {
        font-size: 15px;
        font-weight: 700;
        color: var(--boh-ink);
        line-height: 1.35;
    }
    .boh-rsvp-success__sub {
        display: block;
        color: var(--boh-ink-soft);
        font-weight: 500;
        font-size: 13px;
        margin-top: 2px;
    }
    .boh-rsvp-success__ctas {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        margin: 0 0 20px;
    }
    .boh-rsvp-success__cta {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 11px 20px;
        background: #fff;
        border: 1.5px solid var(--boh-ink);
        color: var(--boh-ink) !important;
        text-decoration: none !important;
        font-weight: 700;
        font-size: 14px;
        border-radius: 999px;
        transition: transform .18s ease, background .18s ease, color .18s ease;
    }
    .boh-rsvp-success__cta:hover {
        transform: translateY(-1px);
        background: var(--boh-ink);
        color: #fff !important;
    }
    .boh-rsvp-success__cta--primary {
        background: var(--boh-magenta);
        border-color: var(--boh-magenta);
        color: #fff !important;
    }
    .boh-rsvp-success__cta--primary:hover {
        background: var(--boh-magenta-deep, #a20e63);
        border-color: var(--boh-magenta-deep, #a20e63);
    }
    .boh-rsvp-success__share {
        color: var(--boh-ink-soft) !important;
        font-size: 14px;
        margin: 0 !important;
    }
    .boh-rsvp-success__share a {
        color: var(--boh-magenta);
        font-weight: 700;
        text-decoration: underline;
    }
    </style>
    <?php
}, 65 );

// Pre-fill the RSVP CF7 form + show a welcome banner when someone lands
// on /rsvp/ from an invitation email (URL contains ?boh_n / ?boh_e).
// The banner tells them their invite is recognised and their info is
// pre-filled — replaces the previous silent pre-fill.
add_action( 'wp_footer', function () {
    if ( ! is_page( 'rsvp' ) ) return;
    ?>
    <script>
    (function () {
        const p = new URLSearchParams(window.location.search);
        const name = p.get('boh_n');
        const email = p.get('boh_e');
        if (!name && !email) return;

        function fill(sel, val) {
            if (!val) return;
            document.querySelectorAll(sel).forEach(function (el) {
                if (!el.value) el.value = val;
            });
        }
        // Split "First Last" into first/last if the invitee name is stored as one string
        const nameParts = (name || '').trim().split(/\s+/);
        const firstName = nameParts.shift() || '';
        const lastName  = nameParts.join(' ');
        fill('input[name="first-name"]', firstName);
        fill('input[name="last-name"]',  lastName);
        fill('input[name="your-email"]', email);

        // Insert the welcome banner just above the RSVP form. Retry a few
        // times in case the CF7 form finishes rendering async.
        const first = (name || '').split(/\s+/)[0] || 'friend';
        function insertBanner() {
            if (document.querySelector('.boh-rsvp-welcome')) return true;
            const rsvp = document.querySelector('.boh-rsvp');
            if (!rsvp) return false;
            const b = document.createElement('div');
            b.className = 'boh-rsvp-welcome';
            b.innerHTML = ''
              + '<div class="boh-rsvp-welcome__eyebrow">Invitation confirmed</div>'
              + '<h3 class="boh-rsvp-welcome__title">Welcome, ' + first.replace(/[<>&]/g, '') + '.</h3>'
              + '<p class="boh-rsvp-welcome__body">We\'ve pre-filled your name and email below. Confirm your party size, tick the terms, and hit Reserve to save your seat.</p>';
            rsvp.insertBefore(b, rsvp.firstChild);
            return true;
        }
        let tries = 0;
        (function try_() {
            const ok = insertBanner();
            if (ok) {
                // Scroll to the welcome banner so the invitee lands
                // right at their invitation instead of on the countdown.
                const target = document.querySelector('.boh-rsvp-welcome');
                if (target) {
                    // Offset for the sticky header (~90px)
                    const y = target.getBoundingClientRect().top + window.scrollY - 90;
                    window.scrollTo({ top: y, behavior: 'smooth' });
                }
                return;
            }
            if (++tries > 20) return;
            setTimeout(try_, 150);
        })();
    })();
    </script>
    <style>
    .boh-rsvp-welcome {
        background: linear-gradient(135deg, rgba(208,20,130,0.08), rgba(249,209,53,0.10));
        border: 1px solid rgba(208,20,130,0.25);
        border-left: 4px solid var(--boh-magenta);
        border-radius: 12px;
        padding: 18px 22px 16px;
        margin: 0 0 24px;
    }
    .boh-rsvp-welcome__eyebrow {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: var(--boh-magenta);
        margin-bottom: 4px;
    }
    .boh-rsvp-welcome__title {
        font-family: 'Montserrat', sans-serif;
        font-weight: 800;
        font-size: 22px;
        margin: 0 0 6px !important;
        color: var(--boh-ink);
        letter-spacing: -0.01em;
    }
    .boh-rsvp-welcome__body {
        margin: 0 !important;
        color: var(--boh-ink-soft);
        line-height: 1.55;
        font-size: 14px;
    }
    </style>
    <?php
}, 70 );

// --- Suppress GiveWP's false-positive "must enable a payment gateway" --
// Manual gateway IS enabled site-wide AND per-form (see _give_gateways),
// but the v3-form-block-with-legacy-template combo sometimes prints this
// error anyway. Strip it from the notice bag before render.
add_filter('give_print_errors', function ($errors) {
    if (!is_array($errors)) return $errors;
    $needles = [
        'You must enable a payment gateway',
        'The selected payment gateway is not enabled',
    ];
    foreach ($errors as $key => $msg) {
        if (!is_string($msg)) continue;
        foreach ($needles as $n) {
            if (stripos($msg, $n) !== false) {
                unset($errors[$key]);
                break;
            }
        }
    }
    return $errors;
}, 5);
// Same filter applied to v3 frontend error dispatch
add_filter('give_get_errors', function ($errors) {
    if (!is_array($errors)) return $errors;
    foreach ($errors as $key => $msg) {
        if (is_string($msg) && (
            stripos($msg, 'You must enable a payment gateway') !== false ||
            stripos($msg, 'The selected payment gateway is not enabled') !== false
        )) {
            unset($errors[$key]);
        }
    }
    return $errors;
}, 5);

// --- Clear stale GiveWP session errors on fresh donate-page GETs --------
// Symptom: after one failed checkout, GiveWP retains validation errors in
// $_SESSION and renders them at the top of the form on every subsequent
// page-load until the user submits successfully. We treat any plain GET
// (no give_action POST and no donation in flight) as a fresh visit and
// flush the error bag before the page renders.
add_action('template_redirect', function () {
    if (!function_exists('give_get_errors')) return;
    if (!is_page('donate')) return;
    if (!empty($_POST['give_action']) || !empty($_POST['give-form-id'])) return;
    if (function_exists('give_clear_errors')) {
        give_clear_errors();
    }
}, 1);

// GiveWP's legacy on-page template can render without donor fields in this
// sandbox setup. Provide test donor data and keep the selected gateway synced
// so both "Test Donation" and "Offline Donation" submit reliably.
add_action('wp_footer', function () {
    if (!is_page('donate')) return;
    ?>
    <script>
    (function(){
      function ready(fn){
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
      }
      ready(function(){
        document.querySelectorAll('#give-form .give-form-wrap form').forEach(function(form){
          function hidden(name, value){
            let input = form.querySelector('input[name="' + name + '"]');
            if (!input) {
              input = document.createElement('input');
              input.type = 'hidden';
              input.name = name;
              form.appendChild(input);
            }
            input.value = value;
            return input;
          }
          hidden('give_first', 'Sandbox');
          hidden('give_last', 'Donor');
          hidden('give_email', 'sandbox-donor@example.com');

          const hiddenGateway = form.querySelector('input[name="give-gateway"]');
          const radios = Array.from(form.querySelectorAll('input.give-gateway[name="payment-mode"]'));

          function syncGateway(value){
            const selected = value
              ? radios.find(r => r.value === value)
              : (radios.find(r => r.checked) || radios[0]);
            if (!selected) return;
            selected.checked = true;
            radios.forEach(r => {
              const li = r.closest('li');
              if (li) li.classList.toggle('give-gateway-option-selected', r === selected);
            });
            if (hiddenGateway) hiddenGateway.value = selected.value;
            try {
              const url = new URL(form.action, window.location.href);
              url.searchParams.set('payment-mode', selected.value);
              form.action = url.toString();
            } catch (e) {}
          }

          radios.forEach(function(radio){
            radio.addEventListener('change', function(){ syncGateway(radio.value); });
            const label = form.querySelector('label[for="' + radio.id + '"]');
            if (label) {
              label.addEventListener('click', function(){
                setTimeout(function(){ syncGateway(radio.value); }, 0);
              });
            }
          });

          form.addEventListener('submit', function(){ syncGateway(); });
          syncGateway();
        });
      });
    })();
    </script>
    <?php
}, 80);

/**
 * [boh_hero_mark] — big animated brand mark, dropped into the home hero
 * above the "Since 2010 · Edmonton…" tagline. Reuses the .boh-mark CSS
 * (see style.css) so it stays in sync with the header logo animation.
 * Attributes: size (px, default 260), class (extra classes).
 */
add_shortcode('boh_hero_mark', function ($atts) {
    $atts = shortcode_atts([
        'size'  => '260',
        'class' => '',
    ], $atts, 'boh_hero_mark');
    $size = max(60, intval($atts['size']));
    $extra = trim($atts['class']);
    ob_start(); ?>
    <div class="boh-hero-mark<?php echo $extra ? ' ' . esc_attr($extra) : ''; ?>" style="--boh-mark-size: <?php echo $size; ?>px">
      <svg class="boh-mark" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg"
           role="img" aria-label="Rohit's Baskets of Hope" data-play-intro>
        <path class="arc arc-y" pathLength="100" fill="none" stroke="#F9D135" stroke-width="7" stroke-linecap="round"
              d="M 34.18 54 A 76 92 0 0 1 173.42 76.18"/>
        <path class="arc arc-m" pathLength="100" fill="none" stroke="#d01482" stroke-width="7" stroke-linecap="round"
              d="M 173.42 123.82 A 76 92 0 1 1 34.18 54"/>
        <line class="tick tick-1" stroke="#F9D135" stroke-width="4" stroke-linecap="round" x1="164" y1="87"  x2="192" y2="87"/>
        <line class="tick tick-2" stroke="#F9D135" stroke-width="4" stroke-linecap="round" x1="164" y1="96"  x2="192" y2="96"/>
        <line class="tick tick-3" stroke="#F9D135" stroke-width="4" stroke-linecap="round" x1="164" y1="105" x2="192" y2="105"/>
        <line class="tick tick-4" stroke="#F9D135" stroke-width="4" stroke-linecap="round" x1="164" y1="114" x2="192" y2="114"/>
        <g class="wm-row wm-1"><text class="wm" x="88" y="86"  font-size="19" font-family="Montserrat, 'Arial Black', sans-serif" font-weight="900" fill="#0A0A0A" text-anchor="middle">ROHIT'S</text></g>
        <g class="wm-row wm-2"><text class="wm" x="88" y="107" font-size="19" font-family="Montserrat, 'Arial Black', sans-serif" font-weight="900" fill="#0A0A0A" text-anchor="middle">BASKETS</text></g>
        <g class="wm-row wm-3"><text class="wm" x="88" y="128" font-size="19" font-family="Montserrat, 'Arial Black', sans-serif" font-weight="900" fill="#0A0A0A" text-anchor="middle">OF <tspan class="wm-hope" fill="#d01482">HOPE</tspan></text></g>
      </svg>
    </div>
    <?php
    return ob_get_clean();
});

/**
 * Animated brand mark — play the intro once per session for every mark
 * carrying the `data-play-intro` attribute (header logo + any hero marks).
 * The finished mark is the CSS default; adding .is-playing triggers the
 * draw-in animation. The header mark also replays on hover.
 */
add_action('wp_footer', function () {
    ?>
    <script>
    (function(){
      const KEY = 'boh_logo_played';
      const intros = document.querySelectorAll('.boh-mark[data-play-intro]');
      if (!intros.length) return;

      function play(el) {
        el.classList.remove('is-playing');
        void el.getBoundingClientRect();
        el.classList.add('is-playing');
      }

      // First visit this session: play all intro-marked marks once.
      try {
        if (!sessionStorage.getItem(KEY)) {
          intros.forEach(play);
          sessionStorage.setItem(KEY, '1');
        }
      } catch (e) { /* privacy mode — just skip */ }

      // Every intro-marked mark replays on hover — hover the SVG itself
      // OR the wrapper (so the header link's whole hit area triggers).
      // Debounced 2.6s so mousing across mid-animation doesn't restart it.
      intros.forEach(function(mark){
        let hoverArmed = true;
        const targets = [mark, mark.parentElement].filter(Boolean);
        targets.forEach(function(target){
          target.addEventListener('mouseenter', function(){
            if (!hoverArmed) return;
            hoverArmed = false;
            play(mark);
            setTimeout(function(){ hoverArmed = true; }, 2600);
          });
        });
      });
    })();
    </script>
    <?php
}, 90);

// --- Front-page tile navigation -----------------------------------------
// Scrolling is left entirely to the browser. An earlier version hijacked the
// wheel to glide panel-to-panel, which fought the browser's own scrolling and
// then broke outright: converting the page's copy to native blocks introduced
// zero-height marker paragraphs between the panels, so consecutive entries in
// the panel list shared a top. "Next panel" resolved to the current position,
// the glide saw a zero-length move and bailed, and the page sat stuck on the
// first tile.
//
// Instead there are explicit up/down controls. They glide smoothly, they can
// only ever land on a real panel, and nothing intercepts an ordinary scroll.
add_action('wp_footer', function () {
    if (!is_front_page()) {
        return;
    }
    ?>
    <div class="boh-tilenav" hidden>
      <button type="button" class="boh-tilenav__btn boh-tilenav__btn--prev" aria-label="Previous section">
        <span aria-hidden="true"></span>
      </button>
      <button type="button" class="boh-tilenav__btn boh-tilenav__btn--next" aria-label="Next section">
        <span aria-hidden="true"></span>
      </button>
    </div>
    <script>
    (function () {
      var mq = window.matchMedia('(min-width: 901px) and (min-height: 700px)');
      var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
      var nav = document.querySelector('.boh-tilenav');
      if (!nav) return;

      var DURATION = 780;
      var panels = [];
      var animating = false;
      var root = document.documentElement;

      // Only real panels. Marker paragraphs the editor leaves behind have no
      // height, and two entries at the same offset would make "next" a no-op.
      function collect() {
        var content = document.querySelector('.entry-content');
        if (!content) { panels = []; return; }
        var seen = {};
        panels = [];
        var kids = Array.prototype.slice.call(content.children);
        var footer = document.querySelector('.boh-footer');
        if (footer) kids.push(footer);
        kids.forEach(function (el) {
          if (el.getBoundingClientRect().height < 200) return;
          var top = docTop(el);
          if (seen[top]) return;
          seen[top] = 1;
          panels.push(el);
        });
      }

      // offsetTop, not getBoundingClientRect: panels elsewhere on the site
      // carry a scroll-reveal transform, and a rect includes it.
      function docTop(el) {
        var y = 0;
        for (var n = el; n; n = n.offsetParent) { y += n.offsetTop; }
        return Math.round(y);
      }

      function tops() { return panels.map(docTop); }

      function ease(t) {
        return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
      }

      function jumpTo(y) {
        // `html` carries scroll-behavior:smooth for anchor links; without
        // asking for instant here, every frame would start its own smooth
        // scroll on top of this animation.
        try { window.scrollTo({ top: y, left: 0, behavior: 'instant' }); }
        catch (err) { window.scrollTo(0, y); }
      }

      function glideTo(index) {
        var t = tops();
        if (index < 0 || index >= t.length) return;
        var from = window.scrollY || root.scrollTop;
        var to = t[index];
        if (Math.abs(to - from) < 4) return;
        if (reduce.matches) { jumpTo(to); update(); return; }

        animating = true;
        var start = null;
        function step(ts) {
          if (start === null) start = ts;
          var p = Math.min(1, (ts - start) / DURATION);
          jumpTo(from + (to - from) * ease(p));
          if (p < 1) { requestAnimationFrame(step); }
          else { jumpTo(to); animating = false; update(); }
        }
        requestAnimationFrame(step);
      }

      // Which panel fills most of the screen right now.
      function currentIndex() {
        var y = (window.scrollY || root.scrollTop) + window.innerHeight * 0.35;
        var t = tops(), best = 0;
        for (var i = 0; i < t.length; i++) { if (t[i] <= y) best = i; }
        return best;
      }

      var prev = nav.querySelector('.boh-tilenav__btn--prev');
      var next = nav.querySelector('.boh-tilenav__btn--next');

      function update() {
        if (!mq.matches || panels.length < 2) { nav.hidden = true; return; }
        nav.hidden = false;
        var i = currentIndex();
        var y = window.scrollY || root.scrollTop;
        var maxScroll = Math.max(0, root.scrollHeight - window.innerHeight);
        var atTop = y < 8;
        // The last panel is the footer, which is shorter than the viewport —
        // its top sits past the furthest the page can scroll, so it never
        // becomes "current". Being at the bottom of the document is the
        // honest end condition.
        var atEnd = i >= panels.length - 1 || y >= maxScroll - 8;
        prev.disabled = atTop;
        next.disabled = atEnd;
      }

      prev.addEventListener('click', function () { if (!animating) glideTo(currentIndex() - 1); });
      next.addEventListener('click', function () {
        if (!animating) glideTo(currentIndex() + 1);
      });

      var ticking = false;
      window.addEventListener('scroll', function () {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function () { ticking = false; if (!animating) update(); });
      }, { passive: true });

      window.addEventListener('resize', function () { collect(); update(); });
      window.addEventListener('load', function () { collect(); update(); });
      mq.addEventListener && mq.addEventListener('change', function () { collect(); update(); });

      collect();
      update();

      // The guided tour used to reuse this scroller; keep the same surface.
      window.bohTiles = {
        glideTo: glideTo,
        panelCount: function () { return panels.length; },
        setDuration: function (ms) { DURATION = ms; },
        isAnimating: function () { return animating; }
      };
    })();
    </script>
    <?php
}, 60);


// --- Hero images: admin-managed slideshow --------------------------------
// The hero slides used to be a hardcoded array, which meant a code change
// and a deploy to swap a photograph. They are now attachment IDs in an
// option, chosen through the standard WordPress media picker, so the team
// can add, remove and reorder them without a developer.

const BOH_HERO_SLIDES_OPTION = 'boh_hero_slides';

/** Fallback used only when nothing has been chosen yet. */
function boh_hero_slide_fallback(): array
{
    return [
        '/wp-content/uploads/2026/06/RC_BoH_112722_181108_LR-13-1024x683.jpg',
        // 1395x2048 (250KB) rather than the 5.8MB original — this is a
        // background image, not a print asset.
        '/wp-content/uploads/2026/07/boh-flower-main-1395x2048.jpg',
    ];
}

/** Attachment IDs currently selected for the hero, in display order. */
function boh_hero_slide_ids(): array
{
    $ids = get_option(BOH_HERO_SLIDES_OPTION, []);
    return is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
}

/**
 * Resolved slide URLs. Attachments that have since been deleted are skipped
 * rather than emitting a broken image, and an empty result falls back to the
 * bundled defaults so the hero always has something to show.
 *
 * @return string[]
 */
function boh_hero_slide_urls(): array
{
    $urls = [];
    foreach (boh_hero_slide_ids() as $id) {
        // 'full' would ship a multi-megabyte original into a background image.
        $src = wp_get_attachment_image_url($id, 'large');
        if ($src) {
            $urls[] = $src;
        }
    }
    return $urls ?: boh_hero_slide_fallback();
}

add_action('admin_menu', function () {
    add_theme_page(
        'Hero Images', 'Hero Images', 'edit_theme_options',
        'boh-hero-images', 'boh_hero_images_screen'
    );
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook === 'appearance_page_boh-hero-images') {
        wp_enqueue_media();   // required for wp.media to exist
    }
});

function boh_hero_images_screen(): void
{
    if (!current_user_can('edit_theme_options')) {
        wp_die('You do not have permission to manage hero images.', 403);
    }

    if (isset($_POST['boh_hero_nonce']) && wp_verify_nonce($_POST['boh_hero_nonce'], 'boh_hero_save')) {
        $raw = isset($_POST['boh_hero_ids']) ? (string) wp_unslash($_POST['boh_hero_ids']) : '';
        $ids = array_values(array_filter(array_map('intval', explode(',', $raw))));
        update_option(BOH_HERO_SLIDES_OPTION, $ids, false);
        echo '<div class="notice notice-success is-dismissible"><p>Hero images saved.</p></div>';
    }

    $ids = boh_hero_slide_ids();
    ?>
    <div class="wrap">
      <h1>Hero Images</h1>
      <p>These images rotate behind the headline on the home page. Add as many
         as you like, drag to reorder, and remove any you no longer want.
         Landscape photographs at least 1600px wide work best.</p>

      <?php if (!$ids) : ?>
        <div class="notice notice-info inline"><p>
          No images selected yet, so the hero is showing its built-in defaults.
          Choosing any image here replaces them.
        </p></div>
      <?php endif; ?>

      <form method="post">
        <?php wp_nonce_field('boh_hero_save', 'boh_hero_nonce'); ?>
        <input type="hidden" name="boh_hero_ids" id="boh-hero-ids"
               value="<?php echo esc_attr(implode(',', $ids)); ?>">

        <p>
          <button type="button" class="button button-primary" id="boh-hero-add">Add images</button>
          <button type="button" class="button" id="boh-hero-clear">Remove all</button>
        </p>

        <ul id="boh-hero-list" class="boh-hero-admin-list">
          <?php foreach ($ids as $id) :
              $thumb = wp_get_attachment_image_url($id, 'medium');
              if (!$thumb) { continue; }   // attachment deleted since selection
          ?>
            <li data-id="<?php echo (int) $id; ?>" draggable="true">
              <img src="<?php echo esc_url($thumb); ?>" alt="">
              <button type="button" class="boh-hero-remove" aria-label="Remove image">&times;</button>
            </li>
          <?php endforeach; ?>
        </ul>

        <?php submit_button('Save hero images'); ?>
      </form>

      <style>
        .boh-hero-admin-list { display:flex; flex-wrap:wrap; gap:12px; padding:0; margin:18px 0; list-style:none; }
        .boh-hero-admin-list li { position:relative; width:190px; height:130px; border-radius:10px;
          overflow:hidden; border:1px solid #dcdcde; background:#f6f7f7; cursor:grab; }
        .boh-hero-admin-list img { width:100%; height:100%; object-fit:cover; display:block; }
        .boh-hero-admin-list li.dragging { opacity:.4; }
        .boh-hero-remove { position:absolute; top:6px; right:6px; width:26px; height:26px;
          border:0; border-radius:50%; background:rgba(0,0,0,.65); color:#fff; font-size:16px;
          line-height:1; cursor:pointer; }
        .boh-hero-remove:hover { background:#d63638; }
      </style>

      <script>
      jQuery(function ($) {
        var list  = document.getElementById('boh-hero-list');
        var field = document.getElementById('boh-hero-ids');
        var frame;

        function sync() {
          field.value = Array.prototype.map.call(list.children, function (li) {
            return li.dataset.id;
          }).join(',');
        }

        $('#boh-hero-add').on('click', function (e) {
          e.preventDefault();
          // Reuse one frame so repeated opens do not stack listeners.
          if (!frame) {
            frame = wp.media({
              title: 'Choose hero images',
              button: { text: 'Use these images' },
              library: { type: 'image' },
              multiple: 'add'
            });
            frame.on('select', function () {
              frame.state().get('selection').each(function (att) {
                var id = att.id;
                if (list.querySelector('[data-id="' + id + '"]')) return;  // no duplicates
                var sizes = att.get('sizes') || {};
                var src = (sizes.medium && sizes.medium.url) || att.get('url');
                var li = document.createElement('li');
                li.dataset.id = id;
                li.draggable = true;
                li.innerHTML = '<img src="' + src + '" alt="">'
                             + '<button type="button" class="boh-hero-remove" aria-label="Remove image">&times;</button>';
                list.appendChild(li);
              });
              sync();
            });
          }
          frame.open();
        });

        $('#boh-hero-clear').on('click', function (e) {
          e.preventDefault();
          if (!confirm('Remove all hero images? The hero will fall back to its built-in defaults.')) return;
          list.innerHTML = '';
          sync();
        });

        $(list).on('click', '.boh-hero-remove', function (e) {
          e.preventDefault();
          this.closest('li').remove();
          sync();
        });

        // Lightweight drag-to-reorder; avoids pulling in jQuery UI sortable.
        var dragged = null;
        $(list).on('dragstart', 'li', function () { dragged = this; this.classList.add('dragging'); });
        $(list).on('dragend', 'li', function () { this.classList.remove('dragging'); sync(); });
        $(list).on('dragover', 'li', function (e) {
          e.preventDefault();
          if (!dragged || dragged === this) return;
          var r = this.getBoundingClientRect();
          var after = (e.originalEvent.clientX - r.left) > r.width / 2;
          list.insertBefore(dragged, after ? this.nextSibling : this);
        });
      });
      </script>
    </div>
    <?php
}

// --- [boh_about_modules] — the About page's alternating image/copy blocks
// Was raw HTML inside the page, which meant the team could not touch it
// without editing markup. Renders from BoH Content -> About instead; the
// stored rows carry image, heading, body (rich text) and alt text.
add_shortcode('boh_about_modules', function () {
    $mods = boh_content('about.modules', []);
    if (!is_array($mods) || !$mods) {
        return '';
    }
    ob_start(); ?>
    <div class="boh-about-modules">
      <?php foreach ($mods as $mod) :
        $mod  = array_values((array) $mod);
        $img  = $mod[0] ?? '';
        $head = $mod[1] ?? '';
        $body = $mod[2] ?? '';
        $alt  = $mod[3] ?? '';
        if ($head === '' && $body === '') { continue; }
      ?>
        <div class="boh-about-module">
          <?php if ($img) : ?>
            <div class="boh-about-module__image">
              <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($alt); ?>" loading="lazy" decoding="async">
            </div>
          <?php endif; ?>
          <div class="boh-about-module__copy">
            <?php if ($head !== '') : ?>
              <h3><?php echo wp_kses_post($head); ?></h3>
            <?php endif; ?>
            <?php echo wp_kses_post($body); ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
});

// --- Hero logo badge on small screens ------------------------------------
// The hero's headrow was built to carry a circular logo badge beside the
// headline — the styling for it is all still in the stylesheet — but the
// markup was removed from the page at some point. On a phone the copy is
// centred in a full-height hero, which left an obvious empty band above the
// headline. Putting the badge back fills it.
//
// Inserted here rather than in the page content so the desktop hero, which
// is signed off as it is, keeps its current composition: the badge is hidden
// above the stacked breakpoint.
add_action('wp_footer', function () {
    if (!is_front_page()) {
        return;
    }
    $logo = esc_url(boh_logo_url());
    $alt  = esc_attr(get_bloginfo('name'));
    ?>
    <script>
    (function () {
      var row = document.querySelector('.wp-block-cover.boh-hero .boh-hero-headrow');
      if (!row || row.querySelector('.boh-hero-mark')) return;
      var badge = document.createElement('div');
      badge.className = 'boh-hero-mark boh-hero-mark--auto';
      var img = document.createElement('img');
      img.className = 'boh-hero-mark__img';
      img.src = <?php echo wp_json_encode($logo); ?>;
      img.alt = <?php echo wp_json_encode($alt); ?>;
      img.decoding = 'async';
      badge.appendChild(img);
      row.insertBefore(badge, row.firstChild);
    })();
    </script>
    <?php
}, 62);

// --- In-page popup for the address and the WIN House link ----------------
// Both used to open a new tab, which pushes a visitor off the site mid-read.
// They now open in a dialog over the page. Every link keeps its real href and
// target, so a middle-click, a long-press or a blocked script still behaves
// exactly as before — the dialog is an enhancement, not the only route.
add_action('wp_footer', function () {
    ?>
    <div class="boh-embed" hidden role="dialog" aria-modal="true" aria-labelledby="boh-embed-title">
      <div class="boh-embed__panel">
        <header class="boh-embed__bar">
          <h2 class="boh-embed__title" id="boh-embed-title"></h2>
          <div class="boh-embed__actions">
            <a class="boh-embed__open" href="#" target="_blank" rel="noopener">Open in a new tab ↗</a>
            <button type="button" class="boh-embed__close" aria-label="Close">&times;</button>
          </div>
        </header>
        <div class="boh-embed__body">
          <div class="boh-embed__spinner" aria-hidden="true"></div>
          <iframe class="boh-embed__frame" title="" loading="lazy"
                  referrerpolicy="no-referrer-when-downgrade"
                  allow="fullscreen"></iframe>
        </div>
      </div>
    </div>
    <script>
    (function () {
      var box = document.querySelector('.boh-embed');
      if (!box) return;
      var frame = box.querySelector('.boh-embed__frame');
      var title = box.querySelector('.boh-embed__title');
      var openA = box.querySelector('.boh-embed__open');
      var closeB = box.querySelector('.boh-embed__close');
      var spin  = box.querySelector('.boh-embed__spinner');
      var opener = null;

      function open(src, label, href) {
        title.textContent = label;
        frame.title = label;
        openA.href = href;
        spin.hidden = false;
        frame.src = src;
        box.hidden = false;
        document.body.style.overflow = 'hidden';
        closeB.focus();
      }
      function close() {
        box.hidden = true;
        frame.src = 'about:blank';      // stop whatever is playing inside
        document.body.style.overflow = '';
        if (opener) { opener.focus(); opener = null; }
      }
      frame.addEventListener('load', function () { spin.hidden = true; });
      closeB.addEventListener('click', close);
      box.addEventListener('click', function (e) { if (e.target === box) close(); });
      document.addEventListener('keydown', function (e) {
        if (!box.hidden && e.key === 'Escape') close();
      });

      // Google's own embed endpoint. Building it from the link's query keeps
      // the pin on whatever address the link already pointed at.
      function mapEmbed(href) {
        var q = '';
        try {
          var u = new URL(href, location.href);
          q = u.searchParams.get('q') || u.searchParams.get('query') || '';
          if (!q) {
            var m = u.pathname.match(/\/place\/([^\/]+)/);
            if (m) q = decodeURIComponent(m[1]);
          }
        } catch (err) { return null; }
        if (!q) return null;
        return 'https://maps.google.com/maps?q=' + encodeURIComponent(q) + '&output=embed';
      }

      document.addEventListener('click', function (e) {
        var a = e.target.closest('a[href]');
        if (!a) return;
        // Let people who deliberately ask for a new tab have one.
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;

        var href = a.getAttribute('href') || '';
        var isMap = /(^|\.)google\.[a-z.]+\/maps|maps\.google\./i.test(href);
        var isWin = /(^|\/\/|\.)winhouse\.org/i.test(href);
        if (!isMap && !isWin) return;

        var src = isMap ? mapEmbed(href) : href;
        if (!src) return;                 // unparseable — leave the link alone

        e.preventDefault();
        opener = a;
        open(src, isMap ? 'Rohit Group Office — map' : 'WIN House Edmonton', href);
      });
    })();
    </script>
    <?php
}, 63);
