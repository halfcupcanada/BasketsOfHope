# Baskets of Hope 50/50 — architecture review and staged plan

From a live inspection of boh.halfcup.ca on 2026-08-18.
Status: **design pending two decisions. No raffle code written yet — reasons below.**

---

## 1. Blockers before any raffle code ships

### 1.1 The site is not fit to take money (hard stop)

A webshell (`hur.php`) and a trojaned theme `index.php` ran on this server as
`www-data` from 2026-08-11 to 2026-08-17, with read access to `wp-config.php`
for six days. As of today:

- **Stripe, database and SMTP credentials are still not rotated.**
- GiveWP is still 4.15.3 (4.16.6.1 available) — the most likely entry vector.
- Caddy access logging is off, so there is no record of what was done.

Adding a feature that issues financial instruments and stores purchaser PII on
top of credentials that should be treated as compromised is not defensible.
Rotate first.

### 1.2 Alberta requires a certified ERS — a custom build is likely not licensable

AGLC requires online raffle sales and the draw to run on an **approved
Electronic Raffle System** from its gaming supplier list. A bespoke
ticket-issuance and draw engine, however well written, is not a registered ERS.

This is design-determining and is the decision I need:

| Option | What the plugin becomes | Effort |
|---|---|---|
| **A — integrate an approved ERS** (recommended) | Payments, presentation, compliance gating, audit, reconciliation here; ticket numbering and draw delegated to the certified provider | Moderate |
| **B — build issuance in-house** | Everything here | High, and probably not licensable |
| **C — off-platform sales** | Site markets the raffle, links to the licensee's ERS purchase page; no payments here | Low |

The spec's own "ERS integration boundary" points at A. The structure below puts
ticket issuance behind an interface with two implementations — an internal one
usable **only** in test mode, and an ERS adapter for anything real — so Stages
1–5 stay useful under either A or C.

### 1.3 Stripe will not approve this account as it stands

Raffles are on Stripe's restricted-business list and need written per-account
approval. Also, from the inspection:

- Only `BOH_STRIPE_TEST_PK` / `BOH_STRIPE_TEST_SK` exist in `wp-config.php`.
  **There are no live Stripe keys on this site.**
- GiveWP is configured `test_mode: enabled`, one account, `boh-test`.

So **the site cannot currently take a real payment of any kind**, donations
included. Worth confirming separately from the raffle: if you believed
donations were live, they are not.

---

## 2. Existing architecture

| Layer | Finding |
|---|---|
| Host | DigitalOcean droplet `137.184.169.98`, Caddy → php8.3-fpm → MySQL, docroot `/var/www/html` |
| WordPress | 6.9.4 (7.0.4 available), prefix `wp_`, DB ~5.7 MB, no object cache |
| Theme | Astra 4.13.4 + `astra-boh` child v5.00; ~5,400 lines CSS, ~1,700 lines `functions.php` |
| Plugins | GiveWP 4.15.3, Contact Form 7 6.1.6, Flamingo 2.6.3, The Events Calendar 6.16.3, Akismet 5.6 |
| mu-plugins | `boh-stripe.php` (maps `BOH_STRIPE_*` constants into GiveWP options), `boh-smtp.php`, gallery scanner + uploader, invitations, mail logger, `boh-donation-trace.php` (temporary debug logger, should be removed) |
| Payments | GiveWP owns Stripe. The Stripe PHP SDK exists **only** at `plugins/give/vendor/stripe/stripe-php` |
| Scheduling | **No Action Scheduler.** WP-Cron enabled but **no system cron drives it** — it fires on page loads only |
| Auth | Single administrator (`boh-admin`) after incident cleanup; no custom roles |

### Consequences for the design

1. **Do not depend on GiveWP's vendored Stripe SDK** — a GiveWP update or removal
   would break raffle payments. Use a thin internal REST client or a pinned
   copy owned by the plugin.
2. **Do not reuse GiveWP's webhook endpoint** — raffle events need their own
   route, signing secret and idempotency store so they can never disturb
   donation processing.
3. **Sales closure cannot rely on WP-Cron.** A licensed cut-off instant needs a
   system cron (`wp cron event run --due-now` every minute) *plus* a
   request-time guard that refuses checkout past the deadline regardless.
4. **There is no staging environment.** One is needed before this work lands.

---

## 3. Reusable components

Reuse:

- **Design system.** The child theme already defines the palette as custom
  properties (`--boh-magenta`, `--boh-magenta-deep`, `--boh-yellow`,
  `--boh-plum`, `--boh-ink`), Montserrat headings, pill buttons
  (`.is-style-ember`), `.boh-eyebrow` pills, card/board treatments and a
  reveal-on-scroll utility. The ticket artwork's magenta/soft-pink identity *is*
  this palette — no new visual language needed.
- **The `BOH_*` constant convention** for secrets in `wp-config.php`, never in
  options. Extend as `BOH_5050_*`.
- **`boh-smtp.php` + `boh-mail-logger.php`** so ticket and receipt mail inherits
  working SMTP and a delivery log.
- **The gallery mu-plugin** as the in-house pattern for a self-contained admin
  screen with capability checks and nonces.

Do **not** reuse GiveWP forms, its donation tables, or its Stripe SDK — raffle
sales are not donations, are not tax-receiptable, and must reconcile separately.

---

## 4. Proposed plugin structure

```
wp-content/plugins/boh-5050/
  boh-5050.php              bootstrap + guards
  uninstall.php             deliberately preserves financial tables
  src/
    Domain/
      Money.php             integer cents only, no floats
      RaffleStatus.php      9-state machine + legal transitions
      Ledger.php            the single calculation service
      TicketIssuer.php      INTERFACE — the ERS boundary
      InternalIssuer.php    test mode only; refuses in live
      ErsIssuer.php         approved-provider adapter
    Payments/
      StripeClient.php      own client + idempotency keys
      CheckoutService.php   order → session, all pre-flight gates
      WebhookController.php verify, reject replays, idempotent
    Install/{Migrator,Capabilities}.php
    Admin/ Public/ Audit/ Reconciliation/ Reports/
  templates/ assets/ tests/
```

Nine tables prefixed `wp_boh5050_`: `raffles`, `packages`, `orders`,
`payments`, `tickets`, `draws`, `audit_events`, `reconciliations`, `approvals`.
Money columns `BIGINT` cents. Unique index on Stripe event ID for replay
rejection; unique index on order idempotency key.

---

## 5. Staged plan

| Stage | Contents | Shippable before approvals? |
|---|---|---|
| **0** | Rotate credentials, patch plugins, access logs, system cron, staging | Prerequisite |
| **1** | Skeleton, migrations, capabilities + 4 roles, status machine, audit logger, `Money`/`Ledger` + unit tests | Yes — no payments, no public output |
| **2** | Admin shell: Dashboard, Configuration, Packages, Audit Log; compliance gate that refuses `Live` | Yes — admin only |
| **3** | Public page in Preview/Test only: hero, impact, packages, countdown, states, REST summary | Yes — gated |
| **4** | Test-mode checkout + webhooks + `InternalIssuer`, receipts, confirmation | Yes — test keys |
| **5** | Orders/Tickets admin, refunds/voids, reconciliation, CSV exports | Yes |
| **6** | ERS adapter, draw management, dual approval, winner publication | Needs provider contract |
| **7** | Go-live: live keys, gate satisfied, announcement bar, nav, homepage section | Needs AGLC + Stripe + licensee |

Stages 1–5 are most of the engineering and all testable behind test mode.
Stage 7 is configuration, not code — going live is a decision, not a deploy.

---

## 6. Assumptions needing external confirmation

**Licensee / WIN House**
1. Who is the legal licensee — WIN House, or Rohit Group on its behalf?
2. Written sign-off on the impact statistics (312 / 4,231 / 844) and their period.
3. Agreement that no ticket-to-service claim is made without approval.

**AGLC**
4. Is an online 50/50 permitted for this licence class, over what sales period?
5. Which ERS providers are acceptable; is a custom system categorically excluded?
6. Required rules wording, licence-number display, record retention.

**Stripe**
7. Written approval for charity raffle transactions on this Canadian account.
8. Whether raffle sales must sit on an account separate from donations.

**Confirmed by inspection**
9. The 50/50 split, integer-cent arithmetic and "pending never counts" rules are
   all implementable as specified; `Ledger` is the only place totals are
   computed, and public, admin, email and export paths all read it.
10. Ticket purchases are **not** tax-receiptable. Receipts must say so and must
    not resemble GiveWP donation receipts.

---

## 7. What I need to proceed

1. **Confirm Stage 0** — I can rotate credentials and patch plugins on your word.
2. **Pick A, B or C** from §1.2 — it decides whether `tickets` is a table I own
   or a mirror of the ERS's numbering.
3. Confirm campaign year and draw date to seed configuration (the reference
   ticket shows 2025 / November 5, which you've said not to reuse).

On 1 and 2 I start Stage 1 immediately.
