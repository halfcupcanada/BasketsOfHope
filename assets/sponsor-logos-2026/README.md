# 2026 sponsor logos

Web-ready PNGs of the 2026 Baskets of Hope sponsors, processed from the
originals in `~/Downloads/BOH_logos` (TIFF/JFIF/CMYK sources normalised to
sRGB PNG, oversized files downscaled).

## How the Sponsorship page shows them

The Sponsorship page (page 17) presents sponsors in **two year tabs**:

- **2026 tab** (shown by default) — all 13 of this year's sponsors:
  Akash Homes, Burke Media, DES Engineering, FKA, Guru Kitchen + Bar, Hamm,
  HSEA, KorAlta Construction, Qualico Communities, Select Engineering
  Consultants, Sran Law, Stallion Moving and Storage, Synergy Projects Ltd.
- **2025 tab** — the 16 sponsors from last year (unchanged).

The 5 sponsors returning from 2025 (Akash, Burke, Guru, KorAlta, Select
Engineering) appear in both tabs and reuse their existing Media Library
attachments; only the 8 new sponsors were uploaded. All 13 processed 2026
PNGs are kept here regardless, so returning-sponsor artwork can be refreshed
later if wanted.

## Pieces

- `_build-sponsor-tabs.php` — rebuilds the gallery region of the page into the
  two tabbed galleries (2026 = 13, 2025 = 16) from attachments looked up by
  slug, and sets the heading to the year-neutral "Thank you to our Sponsors".
  Run: `wp eval-file _build-sponsor-tabs.php 17`.
- `wp-content/mu-plugins/boh-sponsor-tabs.php` — the tab CSS + vanilla-JS
  toggle, loaded only on the Sponsorship page. No-JS safe (both panels show
  without JavaScript), uses the brand magenta (#d01482) and gold (#f9d135).

The 8 new PNGs were first staged in `/tmp/boh-logos-2026` on the server and
uploaded via a sideload step; the build script then assembled the tabs.
