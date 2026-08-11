#!/usr/bin/env bash
# install.sh — apply Baskets of Hope theme + content to a WordPress site.
# Idempotent: safe to run multiple times. Existing IDs are looked up by slug,
# not hard-coded, so this script works on any WordPress install.
#
# Prerequisites on the target host:
#   - WordPress installed and reachable
#   - WP-CLI installed (`wp` on PATH) — https://wp-cli.org/
#   - Plugins active: contact-form-7, give, the-events-calendar
#   - Parent theme active or installed: astra
#   - Run from a directory writable by the WP user, with PATH access to the
#     WP install (set $WP_PATH below if not).
#
# Usage:
#   cd deploy-boh-2.1
#   WP_PATH=/var/www/html ./install.sh        # explicit WP root
#   ./install.sh                              # current dir is WP root

set -euo pipefail

WP_PATH="${WP_PATH:-$(pwd)}"
HERE="$(cd "$(dirname "$0")" && pwd)"

# Resolve `wp` so we don't trip if it's not on PATH but we have a known location
WP="${WP_BIN:-wp}"
WPF=( "$WP" --path="$WP_PATH" --skip-themes --skip-plugins=give )
# Note: we DO need give-active for some steps; the wrapper toggles per-step.
WPA=( "$WP" --path="$WP_PATH" )

log()  { printf '\033[1;35m▶\033[0m %s\n' "$*"; }
ok()   { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[1;33m!\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31m✗\033[0m %s\n' "$*"; exit 1; }

# ── Sanity checks ──────────────────────────────────────────────────────────
command -v "$WP" >/dev/null 2>&1 || fail "WP-CLI not found. Install from https://wp-cli.org/"
"$WP" --path="$WP_PATH" core is-installed >/dev/null 2>&1 || fail "WordPress not found at $WP_PATH"
ok "WP-CLI ready, WordPress detected at $WP_PATH"

# Required plugins
for plug in contact-form-7 give the-events-calendar; do
  if ! "$WP" --path="$WP_PATH" plugin is-active "$plug" >/dev/null 2>&1; then
    warn "$plug is not active — some features will be skipped"
  fi
done

# ── 1. Install / refresh the standalone Baskets of Hope theme ─────────────
log "Installing Baskets of Hope theme (standalone, no parent dependency)"
THEMES_DIR="$("$WP" --path="$WP_PATH" theme path)"
DEST_THEME="$THEMES_DIR/astra-boh"
mkdir -p "$DEST_THEME/inc"
for f in style.css functions.php header.php footer.php index.php page.php single.php searchform.php; do
  if [ -f "$HERE/theme/astra-boh/$f" ]; then
    cp -f "$HERE/theme/astra-boh/$f" "$DEST_THEME/$f"
  fi
done
cp -f "$HERE/theme/astra-boh/inc/class-default-template.php" "$DEST_THEME/inc/class-default-template.php" 2>/dev/null || true
ok "Theme files copied to $DEST_THEME"

# Activate (theme dir is still "astra-boh" for backwards-compat; theme name is "Baskets of Hope")
if ! "$WP" --path="$WP_PATH" theme is-active astra-boh >/dev/null 2>&1; then
  "$WP" --path="$WP_PATH" theme activate astra-boh
  ok "Activated theme"
else
  ok "Theme already active"
fi

# ── 2. Ensure each page exists, then push content ──────────────────────────
log "Syncing page content"

# upsert_page <slug> <title> <content-file>
# Creates the page if missing, then writes content from <file>. Skips the
# content write for existing pages unless REFRESH_CONTENT=1 is set in the
# environment — this protects in-place admin edits across re-deploys.
upsert_page() {
  local slug="$1" title="$2" file="$3"
  local id
  id="$("$WP" --path="$WP_PATH" post list --post_type=page --name="$slug" --field=ID 2>/dev/null | head -1 || true)"
  if [ -z "${id:-}" ]; then
    id="$("$WP" --path="$WP_PATH" post create \
      --post_type=page --post_status=publish \
      --post_title="$title" --post_name="$slug" \
      --porcelain 2>/dev/null)"
    "$WP" --path="$WP_PATH" post update "$id" "$file" --post_title="$title" >/dev/null
    ok "Created /$slug from template (id $id)"
  elif [ "${REFRESH_CONTENT:-0}" = "1" ]; then
    "$WP" --path="$WP_PATH" post update "$id" "$file" --post_title="$title" >/dev/null
    ok "Refreshed /$slug content (id $id) — REFRESH_CONTENT=1"
  else
    ok "Kept /$slug as-is (id $id) — pass REFRESH_CONTENT=1 to overwrite"
  fi
}

upsert_page home    "Home"                       "$HERE/content/pages/home.html"
upsert_page about   "About"                      "$HERE/content/pages/about.html"
upsert_page stories "Stories"                    "$HERE/content/pages/stories.html"
upsert_page rsvp    "RSVP — A Night of Giving"   "$HERE/content/pages/rsvp.html"
upsert_page donate  "Donate"                     "$HERE/content/pages/donate.html"
upsert_page contact "Contact"                    "$HERE/content/pages/contact.html"
upsert_page sponsor "Sponsor"                    "$HERE/content/pages/sponsor.html"

# Set Home as front page
HOME_ID="$("$WP" --path="$WP_PATH" post list --post_type=page --name=home --field=ID | head -1)"
"$WP" --path="$WP_PATH" option update show_on_front page  >/dev/null
"$WP" --path="$WP_PATH" option update page_on_front "$HOME_ID" >/dev/null
ok "Home set as front page"

# ── 3. Site identity ───────────────────────────────────────────────────────
log "Updating site identity"
"$WP" --path="$WP_PATH" option update blogname "Rohit's Baskets of Hope" >/dev/null
"$WP" --path="$WP_PATH" option update blogdescription "Delivering dignity and hope to families in need" >/dev/null

# Astra: cap logo widths (defensive — also clamped in CSS)
"$WP" --path="$WP_PATH" option patch update astra-settings logo-width 48 >/dev/null 2>&1 || true
"$WP" --path="$WP_PATH" option patch update astra-settings mobile-header-logo-width 36 >/dev/null 2>&1 || true
ok "Logo widths clamped (52px cap via CSS, 48px via Astra)"

# ── 4. CF7 RSVP form ───────────────────────────────────────────────────────
if "$WP" --path="$WP_PATH" plugin is-active contact-form-7 >/dev/null 2>&1; then
  log "Installing Contact Form 7 RSVP form"
  RSVP_FORM_ID="$("$WP" --path="$WP_PATH" post list --post_type=wpcf7_contact_form --title='RSVP — Baskets of Hope 2026' --field=ID 2>/dev/null | head -1 || true)"
  if [ -z "${RSVP_FORM_ID:-}" ]; then
    # Fall back to the prior-year title and rename it forward, so re-deploys
    # don't create a duplicate form.
    RSVP_FORM_ID="$("$WP" --path="$WP_PATH" post list --post_type=wpcf7_contact_form --title='RSVP — Baskets of Hope 2025' --field=ID 2>/dev/null | head -1 || true)"
    if [ -n "${RSVP_FORM_ID:-}" ]; then
      "$WP" --path="$WP_PATH" post update "$RSVP_FORM_ID" --post_title="RSVP — Baskets of Hope 2026" >/dev/null
      ok "Renamed RSVP form 2025 → 2026 (id $RSVP_FORM_ID)"
    fi
  fi
  if [ -z "${RSVP_FORM_ID:-}" ]; then
    RSVP_FORM_ID="$("$WP" --path="$WP_PATH" post create \
      --post_type=wpcf7_contact_form --post_status=publish \
      --post_title="RSVP — Baskets of Hope 2026" --porcelain 2>/dev/null)"
    ok "Created RSVP form (id $RSVP_FORM_ID)"
  fi
  "$WP" --path="$WP_PATH" post meta update "$RSVP_FORM_ID" _form       "$(cat "$HERE/content/forms/rsvp-form.txt")" >/dev/null
  "$WP" --path="$WP_PATH" post meta update "$RSVP_FORM_ID" _mail       "$(cat "$HERE/content/forms/rsvp-mail.json")"     --format=json >/dev/null
  "$WP" --path="$WP_PATH" post meta update "$RSVP_FORM_ID" _mail_2     "$(cat "$HERE/content/forms/rsvp-mail2.json")"    --format=json >/dev/null
  "$WP" --path="$WP_PATH" post meta update "$RSVP_FORM_ID" _messages   "$(cat "$HERE/content/forms/rsvp-messages.json")" --format=json >/dev/null
  ok "RSVP form fields, mail, and messages synced"

  # Patch functions.php with the actual form ID on this install
  sed -i.bak "s/define('BOH_RSVP_FORM_ID', [0-9]\+)/define('BOH_RSVP_FORM_ID', $RSVP_FORM_ID)/" "$DEST_THEME/functions.php"
  rm -f "$DEST_THEME/functions.php.bak"
  ok "Wired RSVP_FORM_ID=$RSVP_FORM_ID into theme"

  # ── Contact form (CF7's default "Contact form 1") ──────────────────────
  # Update sender + activate autoreply so the contact page actually delivers.
  # Looks up by the CF7 default title; skips silently if it doesn't exist.
  CONTACT_FORM_ID="$("$WP" --path="$WP_PATH" post list --post_type=wpcf7_contact_form --title='Contact form 1' --field=ID 2>/dev/null | head -1 || true)"
  if [ -n "${CONTACT_FORM_ID:-}" ] && [ -f "$HERE/content/forms/contact-mail.json" ]; then
    "$WP" --path="$WP_PATH" post meta update "$CONTACT_FORM_ID" _mail   "$(cat "$HERE/content/forms/contact-mail.json")"  --format=json >/dev/null
    "$WP" --path="$WP_PATH" post meta update "$CONTACT_FORM_ID" _mail_2 "$(cat "$HERE/content/forms/contact-mail2.json")" --format=json >/dev/null
    ok "Contact form sender + autoreply synced (id $CONTACT_FORM_ID)"

    # Patch the contact page to reference this site's actual contact form ID.
    CONTACT_PAGE_ID="$("$WP" --path="$WP_PATH" post list --post_type=page --name=contact --field=ID | head -1)"
    if [ -n "${CONTACT_PAGE_ID:-}" ]; then
      CONTACT_CONTENT="$("$WP" --path="$WP_PATH" post get "$CONTACT_PAGE_ID" --field=post_content)"
      CONTACT_PATCHED="$(printf '%s' "$CONTACT_CONTENT" | sed -E "s/\[contact-form-7 id=\"[0-9]+\"/[contact-form-7 id=\"$CONTACT_FORM_ID\"/")"
      if [ "$CONTACT_PATCHED" != "$CONTACT_CONTENT" ]; then
        printf '%s' "$CONTACT_PATCHED" > /tmp/boh-contact-patched.html
        "$WP" --path="$WP_PATH" post update "$CONTACT_PAGE_ID" /tmp/boh-contact-patched.html >/dev/null
        rm -f /tmp/boh-contact-patched.html
        ok "Contact page wired to contact form $CONTACT_FORM_ID"
      fi
    fi
  fi
else
  warn "Contact Form 7 not active — skipping RSVP form (the page will fall back to mailto link)"
fi

# ── 5. GiveWP donation form ────────────────────────────────────────────────
if "$WP" --path="$WP_PATH" plugin is-active give >/dev/null 2>&1; then
  log "Configuring GiveWP donation form"
  GIVE_FORM_ID="$("$WP" --path="$WP_PATH" post list --post_type=give_forms --field=ID 2>/dev/null | head -1 || true)"
  if [ -z "${GIVE_FORM_ID:-}" ]; then
    warn "No GiveWP form found. Create one in wp-admin → Donations → Forms, then re-run this script."
  else
    "$WP" --path="$WP_PATH" post meta update "$GIVE_FORM_ID" _give_donation_levels \
      "$(cat "$HERE/content/forms/give-levels.json")" --format=json >/dev/null
    "$WP" --path="$WP_PATH" post meta update "$GIVE_FORM_ID" _give_display_style onpage >/dev/null
    "$WP" --path="$WP_PATH" post meta update "$GIVE_FORM_ID" _give_goal_option enabled >/dev/null
    "$WP" --path="$WP_PATH" post meta update "$GIVE_FORM_ID" _give_goal_format amount >/dev/null
    "$WP" --path="$WP_PATH" post meta update "$GIVE_FORM_ID" _give_set_goal 14000 >/dev/null
    "$WP" --path="$WP_PATH" post meta update "$GIVE_FORM_ID" _give_show_goal enabled >/dev/null
    "$WP" --path="$WP_PATH" post meta update "$GIVE_FORM_ID" _give_gateways \
      '{"manual":{"label":"Test Donation","enabled":"1"},"offline":{"label":"Offline Donation","enabled":"1"}}' --format=json >/dev/null
    "$WP" --path="$WP_PATH" post meta update "$GIVE_FORM_ID" _give_default_gateway manual >/dev/null
    # Without this, GiveWP's legacy template skips the donor name/email
    # fields, which then fail Stripe's client-side validation silently.
    "$WP" --path="$WP_PATH" post meta update "$GIVE_FORM_ID" _give_show_register_form none >/dev/null
    ok "Donation levels, on-page display, goal=\$14000 synced on form $GIVE_FORM_ID"

    # Patch the donate page to point at this site's give form id (in case it differs)
    DONATE_ID="$("$WP" --path="$WP_PATH" post list --post_type=page --name=donate --field=ID | head -1)"
    DONATE_CONTENT="$("$WP" --path="$WP_PATH" post get "$DONATE_ID" --field=post_content)"
    DONATE_PATCHED="$(printf '%s' "$DONATE_CONTENT" | sed -E "s/(wp:give\/donation-form \{\"id\":)[0-9]+/\1$GIVE_FORM_ID/")"
    if [ "$DONATE_PATCHED" != "$DONATE_CONTENT" ]; then
      printf '%s' "$DONATE_PATCHED" > /tmp/boh-donate-patched.html
      "$WP" --path="$WP_PATH" post update "$DONATE_ID" /tmp/boh-donate-patched.html >/dev/null
      rm -f /tmp/boh-donate-patched.html
      ok "Donate page wired to give form $GIVE_FORM_ID"
    fi
  fi

  # Wire success / failure / history pages if they exist
  for slug_pair in "donation-confirmation:success_page" "donation-failed:failure_page" "donor-dashboard:history_page"; do
    slug="${slug_pair%%:*}"; key="${slug_pair##*:}"
    pid="$("$WP" --path="$WP_PATH" post list --post_type=page --name="$slug" --field=ID 2>/dev/null | head -1 || true)"
    if [ -n "${pid:-}" ]; then
      "$WP" --path="$WP_PATH" option patch update give_settings "$key" "$pid" >/dev/null 2>&1 \
        || "$WP" --path="$WP_PATH" option patch insert give_settings "$key" "$pid" >/dev/null 2>&1 || true
      ok "give_settings.$key → /$slug (id $pid)"
    fi
  done
else
  warn "GiveWP not active — skipping donation configuration"
fi

# ── 6. Menu ────────────────────────────────────────────────────────────────
log "Building main menu"
MENU_NAME="Main Menu"
# --format=csv wraps values containing spaces in quotes ("Main Menu"), so we
# match either form. If create fails because the menu already exists, that's
# fine — keep going.
if ! "$WP" --path="$WP_PATH" menu list --fields=name --format=csv 2>/dev/null | grep -qE "^\"?${MENU_NAME}\"?$"; then
  "$WP" --path="$WP_PATH" menu create "$MENU_NAME" >/dev/null 2>&1 \
    && ok "Created menu '$MENU_NAME'" \
    || ok "Menu '$MENU_NAME' already exists"
else
  ok "Menu '$MENU_NAME' already exists"
fi

# This is a one-page site: nav links scroll to anchors on the home page
# (#top, #about, #stories, #rsvp, #sponsor, #contact, #donate) rather than
# navigating to separate /about/ /donate/ /rsvp/ pages. The standalone pages
# still exist as deep-link targets but aren't in the primary nav.
declare -a ANCHOR_NAV=("Home:/#top" "About:/#about" "Stories:/#stories" "RSVP:/#rsvp" "Sponsor:/#sponsor" "Contact:/#contact" "Donate:/#donate")
for entry in "${ANCHOR_NAV[@]}"; do
  label="${entry%%:*}"; href="${entry##*:}"
  if ! "$WP" --path="$WP_PATH" menu item list "$MENU_NAME" --fields=link --format=csv 2>/dev/null | grep -qE "\"?${href}\"?$"; then
    "$WP" --path="$WP_PATH" menu item add-custom "$MENU_NAME" "$label" "$href" >/dev/null 2>&1 || true
    ok "Added '$label' → $href"
  fi
done
"$WP" --path="$WP_PATH" menu location assign "$MENU_NAME" primary >/dev/null 2>&1 || true

# ── 7. Permalinks ──────────────────────────────────────────────────────────
log "Flushing rewrite rules"
"$WP" --path="$WP_PATH" rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
"$WP" --path="$WP_PATH" rewrite flush --hard >/dev/null
ok "Rewrites flushed"

# ── Done ───────────────────────────────────────────────────────────────────
echo ""
echo "─────────────────────────────────────────────────────────────"
ok "Baskets of Hope v4.32 installed."
echo "  Front page: $("$WP" --path="$WP_PATH" option get siteurl)/"
echo "  Donate:     $("$WP" --path="$WP_PATH" option get siteurl)/donate/"
echo "  RSVP:       $("$WP" --path="$WP_PATH" option get siteurl)/rsvp/"
echo ""
echo "  Next steps:"
echo "  • Upload a logo (Appearance → Customize → Site Identity)"
echo "  • Configure GiveWP payment gateway with live keys when ready"
echo "  • Update the event date in theme/astra-boh/functions.php (BOH_EVENT_ISO)"
echo "─────────────────────────────────────────────────────────────"
