# Baskets of Hope — Deployment Package (v4.32)

Hosting-agnostic bundle of the **Rohit's Baskets of Hope** WordPress redesign.
Drop this folder onto any host that runs WordPress + WP-CLI, run `./install.sh`,
and the site lights up.

## What's inside

```
deploy-boh-4.32/
├── install.sh                            # idempotent installer
├── README.md                             # you are here
├── theme/
│   └── astra-boh/                        # child theme (Astra parent required)
│       ├── style.css                     # warm/editorial design system
│       ├── functions.php                 # shortcodes: countdown, impact,
│       │                                 # transparency, RSVP, sticky CTA,
│       │                                 # event meta, calendar (+.ics)
│       └── inc/class-default-template.php
└── content/
    ├── pages/                            # block-editor markup, one .html per page
    │   ├── home.html      about.html     stories.html
    │   ├── rsvp.html      donate.html    contact.html   sponsor.html
    └── forms/
        ├── rsvp-form.txt                 # CF7 form template
        ├── rsvp-mail.json                # CF7 admin notification
        ├── rsvp-mail2.json               # CF7 guest confirmation
        ├── rsvp-messages.json            # CF7 user-facing strings
        └── give-levels.json              # GiveWP donation tiers
```

## Prerequisites on the target host

| Item | Why | Install command |
| --- | --- | --- |
| WordPress (latest) | base | host-specific |
| WP-CLI on `$PATH` | the installer is all WP-CLI | https://wp-cli.org/ |
| Astra parent theme | child theme depends on it | `wp theme install astra` |
| Contact Form 7 | RSVP form | `wp plugin install contact-form-7 --activate` |
| GiveWP | donations | `wp plugin install give --activate` |
| The Events Calendar | event-related URLs | `wp plugin install the-events-calendar --activate` |
| At least one GiveWP form | the installer wires levels onto the first form it finds — create one in `wp-admin → Donations → Forms` first if none exist | — |

The installer **detects missing plugins and skips the dependent steps with a
warning** rather than failing, so a partial install is fine.

## How to run

From the WordPress root (where `wp-config.php` lives):

```bash
unzip deploy-boh-4.32.zip
cd deploy-boh-4.32
./install.sh
```

Or from anywhere, pointing at the WP root explicitly:

```bash
WP_PATH=/var/www/html ./install.sh
```

The script is **idempotent** — pages are looked up by slug (not hard-coded
IDs), the CF7 form is looked up by title, the GiveWP form takes the first
form on the site. Re-running just refreshes content.

## What the installer does

1. Copies the child theme to `wp-content/themes/astra-boh/` and activates it.
2. Creates or updates these pages by slug: `home`, `about`, `stories`,
   `rsvp`, `donate`, `contact`, `sponsor`. (Page slug `rsvp` is used because
   The Events Calendar reserves `/event/` for single-event permalinks.)
3. Sets the `Home` page as the front page.
4. Sets `blogname` and `blogdescription`.
5. Clamps Astra's logo width (defensive — the CSS also caps the logo at
   52px tall, 40px on mobile).
6. Creates or updates the CF7 form **"RSVP — Baskets of Hope 2026"** and
   patches the discovered form ID into the child theme's `BOH_RSVP_FORM_ID`.
7. Pushes the 5-tier donation level array onto the first GiveWP form,
   switches it to `reveal` display style, sets the $14,000 goal, and patches
   the donate page block to reference that form ID.
8. Wires `success_page`, `failure_page`, and `history_page` in
   `give_settings` to any pages slugged `donation-confirmation`,
   `donation-failed`, `donor-dashboard` (created during normal GiveWP setup).
9. Builds a primary nav menu in the order: Home · About · Stories · RSVP ·
   Sponsor · Contact · Donate.
10. Flushes rewrite rules.

## After install — things to set manually

These are intentionally NOT scripted because they're per-site decisions:

- **Logo upload** — `Appearance → Customize → Site Identity → Select logo`.
- **GiveWP payment gateway** — `Donations → Settings → Payment Gateways`.
  In dev the "Test Donation" manual gateway is fine; for production add
  Stripe / PayPal live keys.
- **Event date** — edit `BOH_EVENT_ISO`, `BOH_EVENT_END`, `BOH_EVENT_TITLE`,
  `BOH_EVENT_LOC`, `BOH_SPOTS_LEFT` in
  `wp-content/themes/astra-boh/functions.php` (lines 13–18) when the event
  changes year-to-year.
- **Spots remaining** — same place, `BOH_SPOTS_LEFT`.
- **Email deliverability** — install an SMTP plugin (WP Mail SMTP, Brevo,
  etc.) so the CF7 confirmation emails actually leave the server.

## Shortcodes the theme provides

| Shortcode | Where it's used | Notes |
| --- | --- | --- |
| `[boh_eyebrow text="…"]` | section headers | small uppercase tag |
| `[boh_countdown to="ISO-date"]` | RSVP page | live JS countdown |
| `[boh_event_meta]` | RSVP page | when / where / benefits / bring tiles |
| `[boh_calendar]` | RSVP page | Google + .ics download |
| `[boh_rsvp form_id="…"]` | RSVP page | wraps the CF7 form with spots-left dots |
| `[boh_impact cta_url="…" cta_text="…"]` | Donate, Home | interactive amount → outcome calculator |
| `[boh_transparency program="84" logistics="11" overhead="5"]` | Donate | "where every dollar goes" bar |
| `[boh_sticky text="…" cta="…" cta_url="…" cta2="…" cta2_url="…"]` | auto-injected on every page except `/donate` and `/rsvp` | dismissible bottom bar |

## Rollback

```bash
wp theme activate astra                                       # back to parent
rm -rf wp-content/themes/astra-boh                            # remove child
```

Pages keep the new content (block markup is portable); to restore prior
content, restore from a DB backup taken before running `install.sh`.

## Version

`4.32` — matches the `wp_enqueue_style` version string in `functions.php`.
Bump when you ship significant CSS/JS changes so browsers re-fetch.
