<?php
/**
 * Plugin Name: BoH event display (/tv/)
 * Description: A full-screen slideshow of the gallery with the 50/50 draw
 *              countdown, for running on a screen at the event. Deliberately
 *              not in the menu and not indexed — it is reached by typing the
 *              URL on the display machine.
 *
 *              Renders a standalone page rather than a theme template: the
 *              site header, footer, cookie chrome and section controls are
 *              all wrong for a screen nobody is going to interact with.
 */

defined( 'ABSPATH' ) || exit;

const BOH_TV_QUERY = 'boh_tv';
const BOH_TV_SLUG  = 'tv';

/* ── Routing ────────────────────────────────────────────────────────── */

add_action( 'init', function () {
	add_rewrite_rule( '^' . BOH_TV_SLUG . '/?$', 'index.php?' . BOH_TV_QUERY . '=1', 'top' );
} );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = BOH_TV_QUERY;
	return $vars;
} );

/**
 * Flush rewrite rules once, when this plugin's rule is not yet registered.
 * A mu-plugin has no activation hook to hang this on.
 */
add_action( 'wp_loaded', function () {
	if ( get_option( 'boh_tv_rules_version' ) === '1' ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( 'boh_tv_rules_version', '1', false );
} );

/* ── The display ────────────────────────────────────────────────────── */

add_action( 'template_redirect', function () {
	if ( ! get_query_var( BOH_TV_QUERY ) ) {
		return;
	}
	boh_tv_render();
	exit;
} );

/** Every gallery photo, newest year first, as {src, caption}. */
function boh_tv_slides(): array {
	$all = apply_filters( 'boh_gallery_items', [] );
	if ( ! is_array( $all ) ) {
		return [];
	}
	krsort( $all );

	$out = [];
	foreach ( $all as $year => $items ) {
		foreach ( (array) $items as $item ) {
			if ( ( $item['type'] ?? 'image' ) !== 'image' ) {
				continue; // videos need their own handling; photos only here
			}
			// The 1800px copy: sharp on a 1080p screen without shipping a
			// camera original to a machine that may be on venue wifi.
			$src = $item['large'] ?? ( $item['thumb'] ?? ( $item['url'] ?? '' ) );
			if ( ! $src ) {
				continue;
			}
			$out[] = [
				'src'     => $src,
				'caption' => (string) ( $item['caption'] ?? '' ),
				'year'    => (int) $year,
			];
		}
	}
	return $out;
}

/** Draw moment for the countdown, as a UTC timestamp. */
function boh_tv_draw_time(): array {
	global $wpdb;
	$table = $wpdb->prefix . 'boh5050_raffles';

	$row = null;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		$row = $wpdb->get_row( "SELECT name, draw_utc FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );
	}

	$draw = $row['draw_utc'] ?? '';
	$ts   = $draw ? strtotime( $draw . ' UTC' ) : false;

	if ( ! $ts && defined( 'BOH_EVENT_ISO' ) ) {
		// No raffle configured yet — count down to the event instead, which is
		// still the right thing for a screen in the room.
		$ts = strtotime( BOH_EVENT_ISO );
		return [ 'ts' => $ts, 'label' => 'Doors open in', 'is_draw' => false ];
	}

	return [ 'ts' => $ts ?: 0, 'label' => '50/50 draw in', 'is_draw' => true ];
}

function boh_tv_render(): void {
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, nofollow', true );

	$slides = boh_tv_slides();
	$draw   = boh_tv_draw_time();
	$logo   = function_exists( 'boh_logo_url' ) ? boh_logo_url() : '';
	$name   = get_bloginfo( 'name' );
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $name ); ?> — event display</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;800&display=swap" rel="stylesheet">
<style>
  :root {
    --magenta: #d01482;
    --yellow: #f9d135;
    --ink: #12100f;
  }
  * { box-sizing: border-box; }
  html, body {
    margin: 0; padding: 0; height: 100%; overflow: hidden;
    background: var(--ink); color: #fff;
    font-family: 'Montserrat', system-ui, -apple-system, sans-serif;
  }
  /* The cursor is a distraction on a screen nobody touches. */
  body { cursor: none; }

  .stage { position: fixed; inset: 0; }
  .slide {
    position: absolute; inset: 0;
    opacity: 0;
    transition: opacity 1.4s ease;
    will-change: opacity;
  }
  .slide.is-on { opacity: 1; }
  /* Two real elements rather than a pseudo-element behind the slide: the
     slide sets will-change, which makes it a stacking context, and a
     z-index:-1 pseudo-element cannot escape that — it painted the blurred
     copy on top of the photograph and made everything look soft. */
  .slide__fill,
  .slide__img {
    position: absolute; inset: 0;
    background-position: center;
    background-repeat: no-repeat;
  }
  /* Fills the letterbox that `contain` leaves, so there are no bare bars. */
  .slide__fill {
    background-size: cover;
    filter: blur(38px) saturate(1.1) brightness(0.5);
    transform: scale(1.18);
  }
  /* The photograph itself: whole, unblurred, never cropped to fill. */
  .slide__img {
    background-size: contain;
  }

  .veil {
    position: fixed; inset: 0;
    background:
      linear-gradient(180deg, rgba(18,16,15,0.72) 0%, rgba(18,16,15,0) 34%),
      linear-gradient(0deg, rgba(18,16,15,0.86) 0%, rgba(18,16,15,0) 46%);
    pointer-events: none;
  }

  .top {
    position: fixed; top: 0; left: 0; right: 0;
    display: flex; align-items: center; gap: 2.2vw;
    padding: 2.6vh 3vw;
  }
  .top img { height: 8.4vh; width: auto; display: block; }
  .top h1 {
    margin: 0; font-size: 2.6vh; font-weight: 800; letter-spacing: .01em;
    text-shadow: 0 2px 18px rgba(0,0,0,.5);
  }
  .top h1 em { font-style: normal; color: var(--magenta); }

  .bottom {
    position: fixed; left: 0; right: 0; bottom: 0;
    padding: 0 3vw 4vh;
    display: flex; align-items: flex-end; justify-content: space-between; gap: 3vw;
  }
  .caption {
    font-size: 2.4vh; font-weight: 600; max-width: 52vw; line-height: 1.35;
    text-shadow: 0 2px 16px rgba(0,0,0,.6);
    opacity: .92;
  }
  .count { text-align: right; flex: 0 0 auto; }
  .count__label {
    font-size: 1.9vh; letter-spacing: .18em; text-transform: uppercase;
    color: var(--yellow); margin-bottom: 1vh; font-weight: 800;
  }
  .count__row { display: flex; gap: 1.4vw; }
  .count__cell { min-width: 9vh; }
  .count__num {
    font-size: 8.6vh; font-weight: 800; line-height: 1;
    font-variant-numeric: tabular-nums;
    text-shadow: 0 4px 30px rgba(0,0,0,.6);
  }
  .count__unit {
    font-size: 1.6vh; letter-spacing: .16em; text-transform: uppercase;
    opacity: .72; margin-top: .6vh;
  }
  .count--live .count__num { color: var(--magenta); }

  .progress {
    position: fixed; left: 0; bottom: 0; height: 4px;
    background: linear-gradient(90deg, var(--yellow), var(--magenta));
    width: 0;
  }
  .empty {
    position: fixed; inset: 0; display: flex; align-items: center; justify-content: center;
    font-size: 3vh; opacity: .8; text-align: center; padding: 6vw;
  }
</style>
</head>
<body>
  <div class="stage" id="stage"></div>
  <div class="veil"></div>

  <header class="top">
    <?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt=""><?php endif; ?>
    <h1><?php echo esc_html( $name ); ?></h1>
  </header>

  <div class="bottom">
    <div class="caption" id="caption"></div>
    <div class="count" id="count">
      <div class="count__label" id="countLabel"><?php echo esc_html( $draw['label'] ); ?></div>
      <div class="count__row">
        <div class="count__cell"><div class="count__num" data-u="d">--</div><div class="count__unit">Days</div></div>
        <div class="count__cell"><div class="count__num" data-u="h">--</div><div class="count__unit">Hours</div></div>
        <div class="count__cell"><div class="count__num" data-u="m">--</div><div class="count__unit">Minutes</div></div>
        <div class="count__cell"><div class="count__num" data-u="s">--</div><div class="count__unit">Seconds</div></div>
      </div>
    </div>
  </div>

  <div class="progress" id="progress"></div>
  <?php if ( ! $slides ) : ?>
    <div class="empty">No gallery photographs are published yet.<br>Add them under Media &rarr; Gallery Images.</div>
  <?php endif; ?>

<script>
(function () {
  var SLIDES = <?php echo wp_json_encode( $slides ); ?>;
  var DRAW_MS = <?php echo (int) $draw['ts'] * 1000; ?>;
  var HOLD = 7000;              // ms per photograph
  var stage = document.getElementById('stage');
  var capEl = document.getElementById('caption');
  var bar = document.getElementById('progress');

  /* ── Slideshow ───────────────────────────────────────────────────
     Two layers that cross-fade, so only ever two images are decoded —
     a hundred-plus <img> tags would exhaust a cheap display stick. */
  var layers = [makeLayer(), makeLayer()];
  function makeLayer() {
    var l = document.createElement('div');
    l.className = 'slide';
    var fill = document.createElement('div');
    fill.className = 'slide__fill';
    var img = document.createElement('div');
    img.className = 'slide__img';
    l.appendChild(fill);
    l.appendChild(img);
    stage.appendChild(l);
    return l;
  }
  var front = 0, idx = -1, timer = null, paused = false;

  function preload(i) {
    if (!SLIDES.length) return;
    var im = new Image();
    im.src = SLIDES[(i + SLIDES.length) % SLIDES.length].src;
  }

  function show(i) {
    if (!SLIDES.length) return;
    idx = (i + SLIDES.length) % SLIDES.length;
    var s = SLIDES[idx];
    var back = layers[1 - front];
    var url = 'url("' + s.src.replace(/"/g, '\\"') + '")';
    back.querySelector('.slide__fill').style.backgroundImage = url;
    back.querySelector('.slide__img').style.backgroundImage = url;
    // Restart the drift on the incoming layer.
    back.classList.remove('is-on');
    void back.offsetWidth;
    back.classList.add('is-on');
    layers[front].classList.remove('is-on');
    front = 1 - front;
    capEl.textContent = s.caption || '';
    preload(idx + 1);
    runBar();
  }

  function runBar() {
    bar.style.transition = 'none';
    bar.style.width = '0%';
    void bar.offsetWidth;
    if (paused) return;
    bar.style.transition = 'width ' + HOLD + 'ms linear';
    bar.style.width = '100%';
  }

  function next() { show(idx + 1); }
  function prev() { show(idx - 1); }

  function play() {
    clearInterval(timer);
    timer = setInterval(function () { if (!paused) next(); }, HOLD);
  }

  if (SLIDES.length) { show(0); play(); }

  /* ── Countdown ───────────────────────────────────────────────────
     Driven off the browser clock against a fixed UTC target, so the
     display stays right even if it runs for days. */
  var cells = {};
  document.querySelectorAll('[data-u]').forEach(function (el) { cells[el.dataset.u] = el; });
  var countBox = document.getElementById('count');
  var label = document.getElementById('countLabel');

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function tick() {
    if (!DRAW_MS) return;
    var diff = DRAW_MS - Date.now();
    if (diff <= 0) {
      countBox.classList.add('count--live');
      label.textContent = 'Drawing now';
      ['d','h','m','s'].forEach(function (u) { cells[u].textContent = '00'; });
      return;
    }
    var s = Math.floor(diff / 1000);
    cells.d.textContent = pad(Math.floor(s / 86400));
    cells.h.textContent = pad(Math.floor(s % 86400 / 3600));
    cells.m.textContent = pad(Math.floor(s % 3600 / 60));
    cells.s.textContent = pad(s % 60);
  }
  tick();
  setInterval(tick, 1000);

  /* ── Staff controls ──────────────────────────────────────────────
     Nobody should need these, but a stuck display is worse than a
     keyboard shortcut nobody uses. */
  document.addEventListener('keydown', function (e) {
    if (e.key === ' ')          { e.preventDefault(); paused = !paused; runBar(); }
    else if (e.key === 'ArrowRight') { paused = true; next(); }
    else if (e.key === 'ArrowLeft')  { paused = true; prev(); }
    else if (e.key.toLowerCase() === 'f') {
      if (document.fullscreenElement) { document.exitFullscreen(); }
      else { document.documentElement.requestFullscreen && document.documentElement.requestFullscreen(); }
    }
  });

  /* Reload periodically so photographs added during the evening appear,
     and so a display left running for days cannot drift or leak. */
  setTimeout(function () { location.reload(); }, 30 * 60 * 1000);
})();
</script>
</body>
</html>
	<?php
}
