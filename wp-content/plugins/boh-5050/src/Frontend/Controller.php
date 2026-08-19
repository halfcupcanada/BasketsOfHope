<?php
declare(strict_types=1);

namespace BOH\Fifty\Frontend;

use BOH\Fifty\Domain\Ledger;
use BOH\Fifty\Domain\Money;
use BOH\Fifty\Domain\RaffleStatus;
use BOH\Fifty\Payments\CheckoutService;

/**
 * Public surface: the /50-50 page, announcement bar, REST summary and checkout
 * submission. Reuses the theme's existing design tokens rather than shipping a
 * competing visual language.
 */
final class Controller
{
    public static function boot(): void
    {
        add_shortcode('boh_5050', [self::class, 'renderPage']);
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('admin_post_nopriv_boh5050_checkout', [self::class, 'handleCheckout']);
        add_action('admin_post_boh5050_checkout', [self::class, 'handleCheckout']);
        add_action('wp_body_open', [self::class, 'announcementBar']);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
        \BOH\Fifty\Reports\Csv::boot();
    }

    private static function raffle(): array
    {
        global $wpdb;
        return $wpdb->get_row("SELECT * FROM {$wpdb->prefix}boh5050_raffles ORDER BY id DESC LIMIT 1", ARRAY_A) ?: [];
    }

    /**
     * Load the stylesheet only where it is used, and version it by file mtime
     * so a deploy busts the CDN cache — the theme learned that lesson the hard
     * way with a hardcoded ver string.
     */
    public static function assets(): void
    {
        $path = BOH_5050_DIR . '/assets/public.css';
        wp_register_style(
            'boh-5050',
            plugins_url('assets/public.css', BOH_5050_FILE),
            [],
            file_exists($path) ? (string) filemtime($path) : \BOH\Fifty\VERSION
        );
        wp_enqueue_style('boh-5050');
    }

    /** Public totals. Deliberately aggregate-only — no purchaser data. */
    public static function routes(): void
    {
        register_rest_route('boh-5050/v1', '/summary', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => static function () {
                $r = self::raffle();
                if (!$r || !RaffleStatus::isPubliclyVisible($r['status'])) {
                    return new \WP_REST_Response(['visible' => false], 200);
                }
                $l = new Ledger((int) $r['id']);
                $t = $l->totals();
                $inv = $l->inventory();
                return new \WP_REST_Response([
                    'visible'        => true,
                    'status'         => $r['status'],
                    'gross'          => $t['gross_cents'],
                    'winner'         => $t['winner_cents'],
                    'charity'        => $t['charity_gross_cents'],
                    'tickets_issued' => $t['tickets_issued'],
                    'remaining'      => $inv['remaining'],
                    'sold_out'       => $inv['sold_out'],
                    'sales_close'    => $r['sales_close_utc'],
                    'draw'           => $r['draw_utc'],
                    'updated_at'     => $t['updated_at'],
                ], 200);
            },
        ]);
    }

    public static function announcementBar(): void
    {
        $r = self::raffle();
        if (!$r || !RaffleStatus::isSelling($r['status'])) {
            return;
        }
        $t = (new Ledger((int) $r['id']))->totals();
        printf(
            '<div class="boh-5050-bar"><a href="%s">50/50 Jackpot: <strong>%s</strong> — Buy Tickets</a></div>',
            esc_url(home_url('/50-50/')),
            esc_html(Money::format($t['winner_cents']))
        );
    }

    public static function renderPage(): string
    {
        $r = self::raffle();
        if (!$r) {
            return '';
        }

        // Disabled: show only the configurable inactive message.
        if ($r['status'] === RaffleStatus::DISABLED) {
            return '<div class="boh-5050"><p>' . esc_html(
                (string) (json_decode((string) $r['settings'], true)['inactive_message']
                    ?? 'Our 50/50 raffle is not running right now. Please check back soon.')
            ) . '</p></div>';
        }

        // Preview is visible only to staff, and is loudly labelled.
        if ($r['status'] === RaffleStatus::PREVIEW && !current_user_can(\BOH\Fifty\Install\Capabilities::VIEW_DASHBOARD)) {
            return '<div class="boh-5050"><p>This page is not available yet.</p></div>';
        }

        $l   = new Ledger((int) $r['id']);
        $t   = $l->totals();
        $inv = $l->inventory();

        global $wpdb;
        $packages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}boh5050_packages WHERE raffle_id = %d AND active = 1 ORDER BY sort_order, id",
            (int) $r['id']
        ), ARRAY_A) ?: [];

        ob_start();
        if ($r['status'] === RaffleStatus::PREVIEW) {
            echo '<div class="boh-5050-testbanner" role="status">TEST MODE — no official tickets are issued</div>';
        }
        ?>
        <section class="boh-5050">
          <p class="boh-5050__eyebrow"><span class="boh-eyebrow">50/50 Raffle</span></p>
          <h1 class="boh-5050__headline">One pot. <em>Two winners.</em></h1>
          <p class="boh-5050__lede">Half the gross ticket sales could be yours. The other half supports
            WIN House and its work helping women, children and non-binary people find safety from
            gender-based violence.</p>

          <?php // Echoes the printed ticket: magenta header, soft-pink stub,
                // perforation between them. Decorative only — every fact in it
                // is repeated as real text elsewhere on the page. ?>
          <div class="boh-5050__ticket" aria-hidden="true">
            <div class="boh-5050__ticket-head">
              <span class="boh-5050__ticket-brand">Rohit's Baskets<br>of Hope</span>
              <span class="boh-5050__ticket-year"><?php echo esc_html((string) $r['campaign_year']); ?></span>
            </div>
            <div class="boh-5050__ticket-stub">
              <span class="boh-5050__ticket-title">50/50 TICKETS</span>
              <span class="boh-5050__ticket-prices">
                <?php
                $bits = [];
                foreach ($packages as $p) {
                    $bits[] = esc_html(Money::format((int) $p['price_cents'], true) . ' — ' . $p['label']);
                }
                echo $bits ? implode(' &nbsp;·&nbsp; ', $bits) : 'Packages to be announced';
                ?>
              </span>
              <?php if (!empty($r['draw_utc'])) : ?>
                <span class="boh-5050__ticket-draw">DRAWING ON <?php
                  echo esc_html(strtoupper(date_i18n('F j', strtotime((string) $r['draw_utc'] . ' UTC')))); ?></span>
              <?php endif; ?>
            </div>
          </div>

          <div class="boh-5050__totals">
            <div class="boh-5050__stat"><b><?php echo esc_html(Money::format($t['gross_cents'])); ?></b><span>Total collected</span></div>
            <div class="boh-5050__stat boh-5050__stat--prize"><b><?php echo esc_html(Money::format($t['winner_cents'])); ?></b><span>Winner receives</span></div>
            <div class="boh-5050__stat"><b><?php echo esc_html(Money::format($t['charity_gross_cents'])); ?></b><span>For WIN House</span></div>
          </div>
          <p class="boh-5050__updated">Last updated <?php echo esc_html($t['updated_at']); ?></p>

          <?php if (RaffleStatus::isSelling($r['status']) && !$inv['sold_out']) : ?>
            <form class="boh-5050__buy" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <?php wp_nonce_field('boh5050_checkout'); ?>
              <input type="hidden" name="action" value="boh5050_checkout">
              <input type="hidden" name="raffle_id" value="<?php echo (int) $r['id']; ?>">

              <fieldset class="boh-5050__packages">
                <legend>Choose your tickets</legend>
                <?php foreach ($packages as $i => $p) : ?>
                  <label class="boh-5050__package">
                    <input type="radio" name="package_id" value="<?php echo (int) $p['id']; ?>" <?php checked($i, 0); ?> required>
                    <span class="boh-5050__package-label"><?php echo esc_html($p['label']); ?></span>
                    <span class="boh-5050__package-price"><?php echo esc_html(Money::format((int) $p['price_cents'])); ?></span>
                  </label>
                <?php endforeach; ?>
              </fieldset>

              <p><label>Your name<br><input type="text" name="name" required></label></p>
              <p><label>Email (your tickets are sent here)<br><input type="email" name="email" required></label></p>
              <p><label>Phone (optional)<br><input type="tel" name="phone"></label></p>
              <p><label><input type="checkbox" name="attest_age" value="1" required> I am 18 years of age or older.</label></p>
              <p><label><input type="checkbox" name="attest_alberta" value="1" required> I am physically located in Alberta.</label></p>

              <button type="submit" class="boh-5050__cta">Buy 50/50 Tickets</button>
              <p class="boh-5050__fineprint">
                Ticket purchases are not donations and are not eligible for a charitable tax receipt.
                Licence <?php echo esc_html($r['licence_number'] ?: 'pending'); ?>.
                Sales close <?php echo esc_html($r['sales_close_utc'] ?: 'TBC'); ?>.
                Draw <?php echo esc_html($r['draw_utc'] ?: 'TBC'); ?> at <?php echo esc_html($r['draw_location'] ?: 'TBC'); ?>.
                18+, Alberta only. Please play responsibly.
                <?php if ($r['rules_url']) : ?>
                  <a href="<?php echo esc_url($r['rules_url']); ?>">View Raffle Rules</a>
                <?php endif; ?>
              </p>
            </form>
          <?php elseif ($inv['sold_out']) : ?>
            <p class="boh-5050__closed">Tickets are sold out. Thank you for your support.</p>
          <?php elseif ($r['status'] === RaffleStatus::SALES_PAUSED) : ?>
            <p class="boh-5050__closed">Ticket sales are briefly paused. Please check back shortly.</p>
          <?php else : ?>
            <p class="boh-5050__closed">Ticket sales are closed. Draw:
              <?php echo esc_html($r['draw_utc'] ?: 'to be announced'); ?>.</p>
          <?php endif; ?>

          <div class="boh-5050__impact">
            <h2>Your ticket helps create a path to safety.</h2>
            <p>Your participation supports WIN House and the work behind these outcomes.</p>
            <ul>
              <li><b>312</b> residents sheltered</li>
              <li><b>4,231</b> crisis calls answered</li>
              <li><b>844</b> hours of childcare provided</li>
            </ul>
          </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public static function handleCheckout(): void
    {
        check_admin_referer('boh5050_checkout');

        // Rate limit per IP: a raffle checkout endpoint is an attractive target
        // for automated abuse, and Stripe session creation is not free.
        $ip  = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $key = 'boh5050_rl_' . md5($ip);
        $hits = (int) get_transient($key);
        if ($hits > 10) {
            wp_die('Too many attempts. Please wait a minute and try again.', 429);
        }
        set_transient($key, $hits + 1, 60);

        $res = (new CheckoutService())->create(
            (int) ($_POST['raffle_id'] ?? 0),
            (int) ($_POST['package_id'] ?? 0),
            [
                'name'            => (string) ($_POST['name'] ?? ''),
                'email'           => (string) ($_POST['email'] ?? ''),
                'phone'           => (string) ($_POST['phone'] ?? ''),
                'attest_age'      => !empty($_POST['attest_age']),
                'attest_alberta'  => !empty($_POST['attest_alberta']),
            ]
        );

        if (!$res['ok']) {
            wp_safe_redirect(add_query_arg('boh5050_error', rawurlencode($res['error'] ?? 'Unavailable'), home_url('/50-50/')));
            exit;
        }
        // Off-site to Stripe. Payment is confirmed by webhook, never by return.
        wp_redirect($res['url']);
        exit;
    }
}
