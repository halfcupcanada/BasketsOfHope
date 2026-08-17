# Baskets of Hope — where to change each piece of content

Site: <https://boh.halfcup.ca> · Admin: <https://boh.halfcup.ca/wp-admin>

This guide covers every visible section of the website: what it is, and where
to change it. Read the two categories first — they decide whether you can edit
something yourself or need a developer.

- **Editable in wp-admin** — open the page, edit, Update. No code.
- **Code-only (needs a developer)** — the text lives in a PHP file in the
  theme. Editing it means a code change and a deploy. These are listed
  explicitly at the bottom so nobody wastes time hunting for them in wp-admin.

---

## 1. Photos (Gallery) — Media → Gallery Upload

The public **Gallery** page is driven by folders on the server, one per event
year. It does **not** use the normal Media Library.

1. In wp-admin go to **Media → Gallery Upload**.
2. Pick the **Event year** (2022–2026). Changing the year reloads the page.
3. Click **Files**, select photos or videos (hold Cmd/Ctrl to pick several).
4. Click **Upload to \<year\>**.

Accepted: JPG, PNG, WEBP, GIF for photos; MP4, WEBM for video. The screen shows
the current server limits — currently 64 MB per file, 512 MB per batch, 60 files
at once. If a batch is too big, it now says so instead of failing silently.

Other things on that screen:

- Each uploaded file appears as a thumbnail with a red **delete** button.
- **Display order** is filename order. To control the sequence, prefix names:
  `01_arrival.jpg`, `02_packing.jpg`, and so on.
- **Captions** are optional: upload a text file with the same name as the photo
  (`01_arrival.jpg` → `01_arrival.txt`) and its contents become the caption.
- **Videos** can have a poster image: name it the same as the video
  (`clip.mp4` → `clip.jpg`).
- The public page caches for 15 minutes. To see changes immediately, visit
  `https://boh.halfcup.ca/?boh_gallery_flush=1` while logged in as an admin.
- Year tabs only appear on the public page when more than one year has photos.

**Heads up:** the 2026 folder currently holds two brand flower graphics
(`boh-flower-main.jpg`, `boh-flower-pattern.jpg`) rather than event photos.
Delete them from this screen before adding the real ones. They are also very
large (~6 MB each) — resize photos to roughly 2000 px wide before uploading so
the Gallery page stays fast.

---

## 2. Page text — Pages → (page) → Edit

All the headings and paragraphs written directly into a page are normal
WordPress content. Open **Pages**, click the page, edit, **Update**.

| Page | What you can edit here |
|---|---|
| **Home** | Hero headline and intro line; the "A community event, 15 years running" section; the "More than practical" section and its three mini-stats; the event date/location heading above the countdown; all section headings |
| **About** | Every heading and paragraph; the four alternating image + copy blocks; the three testimonial quotes; the WIN House section |
| **Donate** | Headings and intro copy; the whole "Your questions, answered" FAQ list (these are native accordion blocks — click one and type) |
| **Sponsorship** | Intro paragraph; the "Choose the right fit" heading and copy; the silent-auction section; the commitment-form intro; the "Thank you to our sponsors" copy |
| **RSVP** | Hero title and subtitle |
| **FAQs** | Only the page heading (the questions themselves are code-only — see §6) |
| **Gallery** | The heading and intro line (the photos come from §1) |

### Editing a page banner (the photo strip at the top of every sub-page)

Each sub-page begins with a single line in the editor that looks like this:

```
[boh_page_hero image="/wp-content/uploads/2026/06/PHOTO.jpg" eyebrow="About" title="A community <em>tradition</em> of care." sub="Short sentence underneath."]
```

Change the text inside the quotes:

- `eyebrow` — the small pill label above the title
- `title` — the big heading. Wrap a word in `<em>…</em>` to make it pink.
- `sub` — the sentence underneath
- `image` — to swap the photo, upload it via **Media → Library**, copy its file
  URL, and paste the part starting at `/wp-content/…`

Keep the square brackets and the quotes exactly as they are.

---

## 3. Sponsor logos — Pages → Sponsorship

Scroll to **"Thank you to our sponsors."** Directly under it is an empty
**Gallery block**. Click it, choose **Add**, and upload each sponsor logo. The
grid, sizing, white cards, and hover effect are automatic, and the row stays
centered no matter how many logos there are.

Use PNG with a transparent background where possible. Delete the grey line
"Sponsor logos will appear here…" once the first logos are in.

---

## 4. Menu — Appearance → Menus

The header navigation is the **Main Menu**. Drag items to reorder, click one to
rename it, or use **Add menu items** to add a page. The current items are Home,
About, Donate, Sponsorship, FAQs, Gallery, RSVP.

---

## 5. Forms and submissions

| Form | Where to edit it | Where submissions go |
|---|---|---|
| **RSVP** (RSVP page) | Contact → Contact Forms → "RSVP — Baskets of Hope 2026" | Emailed to BoH@rohitgroup.com, and stored under **Flamingo** |
| **Sponsorship commitment** (Sponsorship page) | Contact → Contact Forms → "Sponsorship Commitment" | Emailed to BoH@rohitgroup.com, and stored under **Flamingo** |
| **Donations** (Donate page) | GiveWP → Donation Forms | GiveWP → Donations |

Note: GiveWP currently shows **Test Mode Active** in the admin bar — real cards
will not be charged until that is switched off in GiveWP → Settings → Payment
Gateways.

---

## 6. Code-only sections (a developer must change these)

These render from PHP in `wp-content/themes/astra-boh/functions.php`. There is
no wp-admin screen for them — this is the honest list so nobody goes looking.

| Section | Where it appears | File location |
|---|---|---|
| **Event date, time, location** | Countdown, RSVP details, calendar links | `functions.php` lines 68–71 |
| **Sponsorship tiers** (all 8 cards: names, prices, benefit bullets) | Sponsorship page | `functions.php` line 653 |
| **FAQ questions and answers** | FAQs page | `functions.php` line 997 |
| **Stats infographic** (2,847 / 1,156 / 3,421 / 15) | Home page dark band | `functions.php` line 975 |
| **"Four steps" how-it-works** | Home and About | `functions.php` line 913 |
| **Donation impact calculator** ($25–$500 tiers and their item lists) | Donate page | `functions.php` line 146 |
| **The two donate cards** | Donate page | `functions.php` line 879 |
| **Quick links cards** | Home, bottom | `functions.php` line 952 |
| **Hero slideshow photos** | Home hero | `functions.php` line 764 |

Two of these are worth flagging as content problems, not just code locations:

- The **stats numbers** carry a `// TODO: confirm final numbers with the team`
  comment — they may never have been verified. The Home page mini-stats quote
  2,847 baskets and 1,156 families, while the About page says 2,100+ baskets
  since 2010. Those two claims disagree; one of them is wrong.
- The **event date** is defined in code as Tuesday, November 3, 2026, but the
  Sponsorship page copy says Wednesday, November 4. Also worth reconciling.

If you want these editable without a developer, the fix is to move each one
into either page content or a small "Baskets of Hope" settings screen. The FAQs
are the easiest and most valuable to convert first — the Donate page already
uses native accordion blocks, so the same pattern works on the FAQs page.

---

## 7. Useful housekeeping

- **Updates:** the dashboard shows WordPress 7.0.4 available and 4 plugin
  updates, plus a failed automatic update. Take a backup first, then update
  from **Dashboard → Updates**.
- **Site Health** reports issues under **Tools → Site Health**.
- **Cache:** the site sits behind Cloudflare. Page text changes appear
  immediately; if a change seems not to show, hard-refresh with Cmd/Ctrl+Shift+R.
- **Users:** there are currently 30 administrator accounts, only one of which
  (`boh-admin`) looks intentional. See the security note handed over separately
  before adding anyone new.
