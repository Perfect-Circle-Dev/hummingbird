# Theme: Talavera

The approved look for the site, chosen by Brent on 2026-11-24. Inspired by Mexican painted
tile: cobalt blue and marigold on warm white, deep navy in dark mode. Friendly and a little
funny, and above all easy to read, because a number of members are older.

Open `docs/theme-reference.html` in a browser to see it: one slice of the site, light and dark
side by side, with exact colours, sizes and spacing in its inline styles. That file is a picture
to match, not code to copy wholesale. Apply the theme to the site's existing markup and classes
in `public/site.css` and `src/layout.php`.

## Rules that matter most

1. **Light and dark both.** Follow the device setting with `@media (prefers-color-scheme: dark)`.
   No on-page toggle. Add `<meta name="color-scheme" content="light dark">` to the page head.
2. **Every colour comes from the variables below.** `site.css` currently has hex values written
   directly into rules (`#fff`, `#AEB8B1`, `#E4EFE7` and others). Replace all of them, or dark
   mode will have white boxes in it.
3. **Readable first.** Body text 17px minimum. Nothing below 15px anywhere, tables included.
   Buttons and other tap targets at least 48px tall.
4. **Contrast.** Every pairing listed here passes WCAG AA (4.5:1 for text, 3:1 for icons and
   borders). If you introduce a new pairing, check it.
5. **Never colour alone.** Status always has words or an icon as well (the share bar has its
   numbers beside it; the success message has a check mark).
6. **A visible keyboard focus ring** on every link, button and field:
   `outline: 3px solid var(--focus); outline-offset: 2px`.

## Colours

Define these on `:root`, then override them inside the dark media query. They replace the
current `--green`, `--ink`, `--muted`, `--line` and `--ground`.

| Variable | Used for | Light | Dark |
|---|---|---|---|
| `--ground` | Page background | `#FFFDF6` | `#0E1630` |
| `--surface` | Cards, header bar, table rows | `#FFFFFF` | `#18234A` |
| `--ink` | Main text | `#14213D` | `#F5F1E4` |
| `--muted` | Secondary text, field borders, unlit star | `#4A5673` | `#B9C2DE` |
| `--line` | Card borders, dividers | `#C9D2EA` | `#33407A` |
| `--primary` | Main buttons, active menu pill, outline of the other pill | `#1E40AF` | `#FFC53D` |
| `--on-primary` | Text on `--primary` | `#FFFFFF` | `#14213D` |
| `--accent` | "I'll take some" button, share bar fill | `#F2B01E` | `#7FA4FF` |
| `--on-accent` | Text on `--accent` | `#14213D` | `#0E1630` |
| `--bar-track` | Share bar background | `#42507A` | `#2A376B` |
| `--link` | Text links | `#1E40AF` | `#9DB7FF` |
| `--focus` | Focus ring | `#1E40AF` | `#FFC53D` |
| `--star` | Favorite star when on | `#B26A00` | `#FFC53D` |
| `--success-bg` | Success message background | `#1E40AF` | `#7FA4FF` |
| `--success-ink` | Success message text | `#FFFFFF` | `#0E1630` |
| `--success-icon` | Check mark in the success message | `#FFC53D` | `#0E1630` |
| `--warn-bg` | Warning message background (locked order, unfinished flags) | `#FFF1C7` | `#3A2F0B` |
| `--warn-ink` | Warning message text | `#5A3D00` | `#FFE08A` |
| `--danger` | Error text, destructive actions | `#B3261E` | `#FFA59A` |

Notice that the roles swap in dark mode: the main button is blue in light and marigold in dark.
Always use the role variable, never "the blue".

Not yet reviewed by Brent: the `--warn-*` and `--danger` colours. He disliked the pale yellow
box for the success message, which is why success is now solid blue. Show him a warning and an
error in both modes before calling the theme done.

## Type

Two families, both self-hosted (next section):

- **Atkinson Hyperlegible**, 400 and 700: all body text, labels, buttons, tables. Chosen because
  it was designed for readers with low vision.
- **Bricolage Grotesque**, 700: the site name and headings only.

```css
--font-body: "Atkinson Hyperlegible", system-ui, -apple-system, "Segoe UI", sans-serif;
--font-head: "Bricolage Grotesque", "Atkinson Hyperlegible", system-ui, sans-serif;
```

| Element | Size / line height | Family and weight |
|---|---|---|
| Body, buttons, fields | 17px / 1.45 | body 400 (buttons 700) |
| Secondary text (`.note`), field labels, table headers | 15px / 1.4 | body 400 (labels 700) |
| Table cells | 16px | body 400 |
| Site name (`.brand-name`) | 30px / 1.1 (24px under 480px wide) | head 700 |
| Page heading (`h1`) | 25px / 1.2 | head 700 |
| Card heading (`h2`), share item name | 21px / 1.2 | head 700 |

Use the weights 400 and 700 only; the current 600s become 700.

## Fonts: self-hosted, subset files

Do not load fonts from Google at run time. Put three WOFF2 files in `public/fonts/`:

- Atkinson Hyperlegible 400, Latin subset
- Atkinson Hyperlegible 700, Latin subset
- Bricolage Grotesque 700, Latin subset (a static 700 instance, not the full variable font)

**Which subset.** Use the standard "latin" subset, the same character set Google Fonts serves
under that name (Basic Latin, Latin-1 Supplement, and general punctuation). Do not cut it down
to only the characters in the templates: product names and member names come from data, and
the catalog already contains characters such as `®`, `é`, `·`, `×` and the true minus sign `−`
(U+2212, used on the quantity buttons). After adding the files, confirm those five render in
the web font and not a fallback.

Anything outside the subset (an unusual accent in a member's name) falls back to the system
font, which is acceptable.

**Getting the files.** Easiest is the Fontsource packages (`@fontsource/atkinson-hyperlegible`,
`@fontsource/bricolage-grotesque`), which ship ready-made `latin-400-normal.woff2` style files.
Otherwise subset the original font files with `pyftsubset` (from `fonttools`) using the latin
unicode range and `--flavor=woff2`. Check each family's licence file and keep a copy beside the
fonts in `public/fonts/`. Record the final file sizes in the pull request.

**Loading.**

```css
@font-face {
  font-family: "Atkinson Hyperlegible";
  src: url("/fonts/atkinson-hyperlegible-latin-400.woff2") format("woff2");
  font-weight: 400; font-style: normal; font-display: swap;
}
/* same for 700, and for Bricolage Grotesque 700 */
```

`font-display: swap` shows text immediately in the system font, then swaps. Preload the body
400 file only: `<link rel="preload" href="/fonts/atkinson-hyperlegible-latin-400.woff2" as="font" type="font/woff2" crossorigin>`.
Tell nginx to cache `/fonts/` for a year (`deploy/nginx.conf`).

Emails keep system fonts. Web fonts are unreliable in email programs.

## Components

- **Tile border.** A 36px-tall decorative strip across the very top of every page, above the
  header. Squares 40px wide alternate `--primary` and `--accent`; each has a diamond in
  `--ground` and a 5px-radius dot in the other colour. Build it as one inline SVG `<pattern>`
  (80px repeat) or a repeating CSS background; it must be `aria-hidden` and use the colour
  variables so it changes with the mode. The exact shapes are in `theme-reference.html`.
- **Header.** `--surface` background. Logo, then site name and tagline. The logo is whatever
  file is in `public/logo.*`. Check it on both grounds; if it disappears on dark navy, sit it
  on a light circle (`#FFFDF6`) in dark mode only.
- **Menu.** Pills, 48px tall, fully rounded. Current page: `--primary` fill, `--on-primary`
  text. Others: 2px `--primary` outline, `--ink` text. Apply the same to the organizer sub-menu.
- **Buttons (`.btn`).** 48px tall, 10px corners, `--primary` / `--on-primary`, weight 700.
  The share claim button uses `--accent` / `--on-accent`.
- **Text-style buttons (`.link`) and links.** `--link`, underlined.
- **Cards.** `--surface`, 1px `--line` border, 16px corners, 16px padding.
- **Fields.** 48px tall, 2px `--muted` border, 10px corners, `--ground` background, `--ink` text.
- **Favorite star.** Draw it as an inline SVG, not the `★` character, which is not in the font
  subset. On: filled `--star`. Off: outline in `--muted`. Keep the existing `aria-label`s.
- **Share bar.** 14px tall, fully rounded, `--bar-track` behind, `--accent` fill.
- **Success message.** `--success-bg` / `--success-ink`, weight 700, 12px corners, with a check
  mark icon in `--success-icon` on the left. Used for "order submitted" and other confirmations.
- **Warning message.** `--warn-bg` / `--warn-ink`, weight 700.
- **Tables.** `--surface` rows, `--line` dividers, header text `--muted`. Keep the horizontal
  scroll box on narrow screens.

## Wording

Approved by Brent:

| Where | Old | New |
|---|---|---|
| Menu, page title, page heading, and every sentence that mentions it (site and emails) | Share board | Share items |
| Heading while a month is open | The December order is open | Place your December order |
| Share claim button, when the member has no claim yet | Claim | I'll take some |
| Search field label | Search by name or item number | Spill the beans: what are you after? |
| After submitting | Order submitted. A summary has been emailed to your household. | Order submitted. Cool beans! A summary is on its way to your inbox. |

"Place your December order" uses the month name from the open month's label, without the year.
"Update" stays as the button text when a member is changing an existing claim. Keep the search
field's placeholder examples, since the new label no longer says what can be typed.

Shown in the mockup but not individually confirmed, so check with Brent: the tagline "Beans,
grains and good company", and the small label "Up for sharing" above each shared item.

Tone for any new text: plain and warm, with at most one light bean joke per screen. Never
joke in an error message or anything about money.

## Out of scope

No layout restructuring, no new pages, no change to what any page does. The organizer pages
get the same colours, type and components as the member pages.

## Done when

- Every page has been looked at in light and in dark, at phone width and desktop width.
- No hard-coded colours remain in `site.css`.
- Keyboard focus is visible on every control in both modes.
- The three font files load from `/fonts/` and nothing is requested from another domain.
- Brent has seen a warning and an error message in both modes.

## Where it stands

Applied on 2026-11-24. The first four points above were checked in Chrome at phone and desktop
widths, in both modes. The last is Brent's to do. Three things differ from the picture, each
for a reason:

- The header keeps "Hummingbird Wholesale buying group" under the name (Brent's choice).
- The label over a share reads "Up for sharing?" and shows only while the share still has room.
- On a phone the order table is wider than the screen and slides sideways inside its box, so
  that the item name and size keep enough room to be read.
