# Holy Frijoles buying group site

Ordering and bulk-share site for Holy Frijoles, a buying group of 30 to 40 members in
Eugene, Oregon, run by Brent (brent@schefley.net). Once a month the group places one
combined order with Hummingbird Wholesale. Live at https://hummingbird.schefley.net.
Goal: every member uses it for the December 2026 order (due Monday, Dec 14 at 7 pm).

Read `docs/BUILD-PLAN.md` first. It holds the decisions, the monthly flow and the
checklist of what is built and what is left. Keep its checklist current as work lands.

## Who you are working with

Brent owns the project and deploys it. He reads HTML and PHP comfortably and JavaScript
a little, and wants to be able to follow and lightly edit the code. So:

- Plain PHP 8.2+, no framework. Keep JavaScript minimal and optional (pages must work without it).
- Comment the "why" in plain language. Prefer clear code over clever code.
- Explain changes to him in plain language, and say what was and was not tested.
- He likes to be asked when a decision is his, and told when a request leaves something out.

## How the code is laid out

- `public/index.php` is the only entry point. `?a=` picks the page: `login`, `verify`,
  `logout`, `unsubscribe` and `confirm-email` (no sign-in), `order`, `shares`, `household`,
  `organizer` (admins only), default home. `public/site.css`, `public/site.js`.
- `public/site.js` is three small add-ons, and every page works without it: search as you type;
  on My order, buttons update the page in place (the server still answers with the whole page,
  and the script swaps in the parts with ids `results`, `favorites` and `order-lists`); and the
  organizer's "out of stock for everyone" box ticking the others. It also remembers whether
  "My favorites" was left folded away (the folding itself is a plain `<details>`), turns
  "Add" and "Save for next month" into "Added" and "Saved" for a moment after they work (the
  `done` class), and makes the Clear button inside the search box empty it on the spot (without
  the script Clear is a link that reloads the page with no search). Clear shows only when
  there is text in the box or a category on show. On every page it marks
  the header `stuck` once the page has scrolled. `page_end()` in `src/layout.php` loads it, so
  pages do not add it themselves.
- `src/bootstrap.php`: config, `db()`, `q($sql, $params)`, `setting()`, `e()`, `money()`, `now_utc()`,
  and the name helpers `first_name()`, `full_name()`, `split_name()`.
- `src/auth.php`: emailed sign-in links, device sessions, CSRF helpers.
- `src/mailer.php`: `send_email()` sends now; every message is written to `email_log`.
- `src/mailings.php`: the email queue (`queue_email()`, `queue_send()`), the group emails
  (`time_to_order_queue()`, `last_call_queue()`, `share_expiry_warnings()`), `month_login_link()`,
  unsubscribe links and the group footer. `bin/mail-jobs.php` runs every minute from cron.
- `src/catalog.php`: sync from Hummingbird's product feed, size and deposit rules, member price.
- `src/orders.php`: months ("cycles"), household orders, search, favorites, totals, submit email.
- `src/shares.php`: share board logic, `shares_on_order()`, `share_split_cents()`.
- `src/organizer.php`: open, change, lock and close a month (`cycle_open`, `cycle_update`,
  `cycle_lock`, `cycle_reopen`, `cycle_close`), `combined_order()`, `unfinished_orders()`,
  `stock_and_prices_save()`, and the report by household (`month_report()`, `month_report_text()`,
  `month_report_email()`; page at `/?a=organizer&month=ID&view=report`).
- `src/payments.php`: pickup day. Split sheets (`split_sheets()`, `scale_text()`), marking orders
  paid (`order_set_paid()`), the pickup email (`pickup_emails_send()`, `venmo_link()`).
- `src/members.php`: members and households (`member_save()`, `member_delete()`,
  `members_import()`, `household_rename()`), `settings_save()`, and what members do for their own
  household (`household_person_save()`, `household_person_add()`, `household_person_remove()`,
  `email_change_request()`,
  `email_change_confirm()`).
- `src/layout.php`: the page frame. Link the stylesheet and script through `asset()`, which adds
  a version number so browsers fetch a changed file at once. `site_logo()` shows `public/logo.svg`, `.webp` or `.png` in
  the header and as the tab icon when such a file exists.
- `src/pages/*.php`: one function per page (`page_order`, `page_shares`, `page_organizer`).
  Organizer addresses: `/?a=organizer` months; `&view=members`; `&view=settings`;
  `&month=ID` that month's combined order; `&month=ID&view=report` report and payments;
  `&month=ID&view=shares` split sheets. Month pages are in `pages/organizer.php`, members and
  settings in `pages/organizer_people.php`. Every organizer form posts to `/?a=organizer` with
  a `do` value; messages come back as short codes looked up in `ORGANIZER_DONE` / `ORGANIZER_PROBLEMS`.
- `bin/`: command-line scripts (`migrate`, `add-member`, `open-cycle`, `sync-catalog`, `list-deposits`,
  `notify-admin`, `check-reboot`, `mail-jobs`, `backup`, `yearly-archive`, `sample-emails`).
  `sample-emails` sends one of every member and organizer email to an organizer's address, made
  by the site's own code from a throwaway database of made-up people. A new email needs a step
  added there, so the set stays complete.
- `schema.sql`: all tables. `bin/migrate.php` runs it; it must stay safe to re-run.
  To change an existing table, add a guarded `ALTER TABLE` step to `bin/migrate.php`.
- `deploy/`: server setup, nginx template, `update.sh`, and `setup-auto.sh` / `auto-update.sh`
  (the server polls GitHub and deploys `main` by itself; see README). `gitupdate.sh` is the
  by-hand version, installed on the server as the `Gitupdate` command. The polling interval is
  `CHECK_EVERY_MINUTES` in `setup-auto.sh`; Brent plans to lower or switch it off once things
  are stable and rely on `Gitupdate`.

## Conventions

- SQLite database at `data/hummingbird.sqlite` (ignored by git). `config.php` is ignored too
  and holds the Resend API key. Never commit either, and never print the key.
- Money is integer cents. Times are stored as UTC strings; show them with `fmt_local()`.
- Always escape output with `e()`. Always use `q()` with `?` placeholders.
- Every POST from a signed-in member is CSRF-checked in `index.php`; forms include `csrf_field()`.
  After handling a POST, redirect (no page is rendered from a POST).
- A member has a first name and a last name (`members.first_name`, `last_name`; the last may be
  empty). `members.name` holds the two joined and is what lists show; every place that saves a
  member keeps it in step. Speak to a member by first name, with `first_name($member)`: the
  greeting of every email, the name beside "Sign out" and the hello on the home page. Use the full name wherever people are
  listed or identified (share claims, My household, organizer pages, the report, notices).
- Email wording is Brent's (set 2026-11-25); change it only when he asks. Emails about an order
  call it the "Hummingbird order" and name the month alone ("Your December Hummingbird order"),
  with the year only in subjects. An email that goes to a whole household through
  `email_household()` can say `{first_name}`, which is filled in for each person. The sums in
  `order_text()` take their labels from the caller ("Share items", "December Total").
  `pickup_when()` is the pickup note without its opening "Pickup from", and `fmt_time_short()`
  writes "7pm".
- Email text has no line breaks inside a paragraph: a paragraph is one long line and mail
  programs wrap it to fit. Break the line only between paragraphs (a blank line), before a link
  on its own line, and in lists of items or amounts. In the code a long paragraph is several
  quoted pieces joined with dots; each piece but the last ends in a space, not `\n`.
- Orders belong to a household, not a person. Check `household_id` / `order_id` on every
  change so one household can never edit another's order.
- Any change to an order sets it back to `draft`; the member must submit again.
- Interface text: plain, friendly, sentence case, no jargon. Touch targets at least 48px
  (the header's menu pills and its "Sign out" on a phone are 44px, to keep the header small).
  Pages must work on a phone.
- The header stays at the top of the window as the page scrolls. On a screen under 960px only
  its menu row stays: the header is parked above the window by the height of its first row,
  which is why that row has a set height (`--brand-row` in `site.css`). A signed-out page has
  no menu and its header scrolls away. On a wide screen the stuck header is about a third
  shorter (76px down to 53px: no tagline, smaller name, 40px pills). The height it gives up
  comes back as a margin under it, so the page does not jump; `--head-full` and
  `--head-small` in `site.css` hold the two heights and must match what is in the header
  (they will need raising when the logo is added). The menu sits in the exact middle of the
  page.
- On a phone (under 520px) the header menu shows short names, "Order", "Shares" and "House",
  so three pills, or an organizer's four, fit on one line. Both names are set in
  `src/layout.php`; page headings keep the full names.
- The look is the Talavera theme (`docs/THEME.md`), light and dark, following the device's
  setting. Every colour in `public/site.css` is a variable set in the two blocks at the top of
  the file (light, then dark); never write a colour straight into a rule. Body text is 17px and
  nothing is under 15px. The three fonts are in `public/fonts/` and nothing loads from another
  site. The star and the icons in messages are drawn (SVG or a CSS mask), because the fonts
  have no star or check mark.
- Messages: `notice` is good news (with a check mark), `notice warn` a caution, `notice error`
  a problem (add `role="alert"`), `notice info` something neutral.
- The two tables on My order (`table.order`) have seven columns on a wide screen and three on a
  narrow one (under 940px): item, quantity, total, with the name across the top of each row.
  Cells marked `wide-only` are left out on a narrow screen and `narrow-only` text is shown only
  there (see "Order tables on a narrow screen" in `site.css`). A new column needs one of those
  marks or it will break the narrow layout.
  The sums under each table (regular items, deposits, amount due) are rows in its `<tfoot>`
  with the amount in the Total column, so they line up with the line totals.
- The cards on Share items sit in a grid (`.cards`): equal columns at least 300px wide, three
  across at full width, each row as tall as its tallest card, with the claim box and
  "started by" line (`.card-foot`) at the bottom of the card.
  My household uses the same grid held to two across (`.cards.pair`), one on a phone, with
  "Add someone to your household" as its last card.
- "Browse by category" on My order, a card of its own under My favorites: each chip lists
  everything in that category, in the results under the search box (the page moves to them if
  they are out of view). A category is a name and the letters its item numbers begin with (L is beans, N is
  nuts); the whole list is `CATEGORIES` in `src/orders.php`, and adding or renaming one means
  changing only that list. A category with nothing in the catalog is not shown. A search and a
  category are never shown together: typing empties the category, and a chip empties the box.
  The address carries `&cat=beans`, as it carries `&q=` for a search.
- Members see the share page as "Share items". The code and these notes still say shares and
  the share board.

## Business rules (already decided)

- Member price = Hummingbird list price less the group discount (`settings.discount_percent`,
  30.2163, the figure Brent gave on 2026-11-24). Prices follow the catalog while a month is
  open and are fixed when the organizer locks it.
- Deposits go by what the container is made of, read from Hummingbird's wording in
  `deposit_for($size, $product)`:
  - Glass, $1.50 each (`settings.deposit_glass_cents`): half-gallon and one-gallon glass jugs,
    64 oz jars, and 5.5 lb honey jars.
  - Plastic, $3.00 each (`settings.deposit_plastic_cents`): jugs, cubes, buckets and pails.
  - A case is charged for each container in it: "Case of 6 x 1/2 gal Glass Jugs" is $9.00.
  - None on smaller jars and bottles, barrels, totes, bag-in-a-box, or empty jugs, jars, buckets
    and lids sold as products.
  Added to regular order lines only, never to shares (whoever takes the container handles it).
  `deposit_override_cents` is there for exceptions. When Hummingbird adds a new kind of
  container, check it with `php bin/list-deposits.php`.
- Shares: claimed by weight or volume in any amount; names and amounts are visible to all
  members. A member can change or cancel their own claim while the share is `open`.
  A fully claimed share goes on the next order. Unfilled shares stay listed and expire
  after 3 months. A share that fills after a month is locked waits for the next month.
  A full share's parts always add up to its price: each part is rounded to the cent and any
  odd cent goes to whoever started the share (the earliest claim, if the starter has
  cancelled theirs). Parts are saved in
  `share_claims.cost_cents` at lock.
- Only one month at a time is open or locked. Beside it there is always a "next month" list
  (a `cycles` row with status `next`, placeholder name, empty `due_at`; see `next_cycle()`).
  Members can save items on it at any time, including while the current month is open or
  locked. Opening a month turns that row into the month, so saved items are already on each
  household's order as `draft`; the member still has to submit. Never show `due_at` for it.
- Out of stock and price corrections happen on the combined order while a month is locked
  (`stock_and_prices_save`). One price per item per month, applied to every household's line and
  to shares of that item (share parts are worked out again). Out of stock is per household line
  or per share; the item's "for everyone" box is a shortcut that sets them all. Each household
  affected gets one email per save, unless the organizer unticks the email box. Out-of-stock
  lines and shares are not charged. At close they carry over: lines to the household's next-month
  list, shares back to the board as open and fully claimed (`carry_over_out_of_stock`).
- The report by household is never stored: the page works it out from the current figures, so
  it is an estimate while a month is open and final once closed. A plain-text copy is emailed to
  every active organizer (`is_admin = 1`) when a month is locked and again when it is closed,
  and on request from a button. The close-time copy is written before out-of-stock shares move
  back to the board, so it still lists them; the page for a closed month no longer does.
- A locked month can be reopened (`cycle_reopen`), which also clears out-of-stock ticks and
  price corrections: members can change orders again, prices
  follow the catalog again, and its shares go back to the board fully claimed. Closing can't
  be undone. Lock, reopen and close each need a ticked box.
- Deadline is soft: members see Monday 7 pm, but nothing closes until the organizer locks
  the month, around 11 am Tuesday. Late orders on Tuesday morning are welcome.
- Only a submitted order is ordered. At lock, a household that never pressed "Submit my order"
  has its items moved to its next-month list and left off the order (`roll_over_unsubmitted()`);
  nobody is emailed about it, since the last-call email and My order both say so beforehand. A
  household that submitted and then changed something without submitting again is different:
  its order goes in as it stands. So after a lock, "unfinished" means only that second kind.
  `household_order_state()` counts an order as submitted if it was submitted at least once.
- Several logins can share one household order.
- Email: anything sent to many people at once goes into `email_queue` (`queue_email()`, or
  `email_household(..., true)`), and `bin/mail-jobs.php` sends a few a minute, stopping at
  `settings.email_daily_cap` (90; Resend allows 100 a day) so the rest go out the next day.
  Sign-in links, order confirmations and the organizer's report use `send_email()` and go at once.
- Group emails are "time to order" (a button on an open month, with an optional note from the
  organizer), last call (automatic, 24 hours before the deadline, only to members whose
  household has not submitted) and the share-expiry warning (automatic, 7 days before, to the
  people on an unfilled share). Each ends with `group_footer()` and the member's own
  unsubscribe link. Unsubscribing (`members.email_opt_out`) stops only these; emails about a
  member's own order still arrive. Organizers can flip it on the Members page.
- Three kinds of sign-in link (`check_login_token`): the one requested on the sign-in page works
  once for 30 minutes; the one an organizer sends from the Members page works once within 7 days;
  the one in a group email (`login_tokens.cycle_id` set) works as often as needed until that
  month is closed: while it is open and while it is locked, so members can use the same email
  to see their confirmed order (Brent's choice, 2026-11-25; it used to stop at lock). It does
  not work forever, so that an old email is not a permanent way in.
- Scheduled jobs all live in `/etc/cron.d/hummingbird-auto`, written by `deploy/setup-auto.sh`.
  The server's clock is UTC, so times there are UTC: catalog refresh 11:15 (3:15 am Pacific in
  winter), backup 11:45, end-of-year tidy-up 12:30 (acts only on December 31), restart check 15:00,
  GitHub check every 2 minutes, mail jobs every minute.
  A change to that schedule needs `sudo bash deploy/setup-auto.sh` run on the server again.
- Backups (`bin/backup.php`): nightly, gzipped, 30 kept in `data/backups/`, with the newest
  emailed to `admin_email` once a week. Sessions, sign-in links, queued emails and the text of
  logged emails are stripped from every copy. Restore steps are in README.md.
- The "Fill my Hummingbird cart" link on the combined order (`hummingbird_cart_link()`) is a
  Shopify cart permalink built from variant ids and in-stock quantities.
- Sign-in is by emailed link only. No passwords. Organizers add members on the Members page,
  one at a time or by pasting a list (first name, last name, email, household, phone). Adding
  sends no email.
  An archived member (`members.active = 0`) can't sign in and gets no emails, and is signed out
  at once; past orders keep their name. Each member on the Members page has Edit, Send login and
  Archive (or Restore) buttons. Only a member who has never used the site can be deleted. An
  organizer can't archive or delete their own account. Renaming a household is on the member's
  Edit page.
- Members look after their own household on the My household page (`page_household`). Anyone in
  a household can correct the name and phone of anyone in it, and add a partner or friend (up to
  `HOUSEHOLD_MAX_MEMBERS` active people); the new person is sent a 7-day sign-in link and the
  organizers are emailed. Each person can change only their own email address, and only by
  confirming a link sent to the new address (`members.pending_email`, good for 48 hours); the
  old address is told. Anyone in a household can also remove someone else from it, behind a
  ticked box (`household_person_remove()`): that archives the person, exactly as Archive on the
  Members page does, and emails them and the organizers. Nobody can remove themselves, and a
  member can't remove an organizer. Restoring, moving between households and making organizers
  stay with the organizer. Every one of these checks the target is in the acting member's
  household.
- End-of-year tidy-up (`bin/yearly-archive.php`, `yearly_archive_candidates()`): on December 31
  the site archives members whose household had nothing on any order that year (no order line
  in a month with a deadline that year, and no claim on a share ordered that year; an unsubmitted
  line counts). Organizers are never archived by it, nor is anyone added in the last 6 months
  (`ARCHIVE_GRACE_MONTHS`, so from July on). Each gets a note saying they can come back by
  emailing Brent, and the organizers are emailed the list. The script runs nightly and acts only
  on December 31, or January 1 to 7 as a catch-up; `settings.archive_done_year` stops a repeat.
  `--dry-run` lists who would be archived without changing anything.
- Pickup is always after 4 pm: `pickup_text()` adds `PICKUP_TIME` to each month's pickup note
  wherever members see it (order page, emails). Show the note with `pickup_text()`, never raw.
- Payment happens at pickup by check, Venmo or cash. The organizer marks each household's order
  paid with the method (`orders.paid_at`, `paid_method`) on the report page, for a locked or a
  closed month. Paid or not, nothing is recorded about amounts.
- "Order placed" is the step between locking and pickup. The organizer places the order with
  Hummingbird, enters what they confirmed on the combined order (out of stock, changed prices),
  then presses "Order placed" there, with an optional note of their own (`order_placed_send()`,
  `order_placed_text()`; `cycles.placed_at`, `placed_note`). Every household with anything on
  the order is emailed its confirmed total, how to pay with the Venmo link, the pickup note, the
  organizer's note and its order in full. A second send needs a ticked box. Reopening the
  month clears it. Until it is pressed, saving out-of-stock ticks or prices emails nobody unless
  the box is ticked; afterwards the box starts ticked.
- The pickup email is now a reminder. It goes out when the organizer presses the button on the
  report page of a locked month: to every household that owes something and is not marked
  paid. A second send needs a ticked box.
  The Venmo pay link is built from `settings.venmo_handle`; with no handle there is no link.
- Settings (discount, the two deposit amounts, share expiry months, Venmo name) take effect at
  once: deposits are recomputed for the whole catalog, then open orders and saved lists are
  repriced. Locked and closed months keep their prices.

## What to build next: organizer pages (members with `is_admin = 1`)

Items 1 to 9 are built (months, combined order with unfinished flags, the lock step,
out of stock and price corrections with update emails, the report by household, split sheets,
paid marking, the "Order placed" email and pickup reminders, members, households and settings, group emails, the
Hummingbird cart link, the logo slot, nightly backups). All twelve items are built.

Still open from that list:

- The dancing bean logo file has not been supplied, and Brent is happy with the site without
  it (2026-11-25). The slot stays: the header shows a logo as soon as a `logo.*` file is added
  to `public/` (WebP preferred, per Brent's usual practice).

Brent tried the "Fill my Hummingbird cart" link from his wholesale login on 2026-11-25 and it
works; the warning that used to sit beside it is gone.

Members said no to a "reorder last month" button; favorites cover it.

## Known gaps

- The Venmo pay link (`https://venmo.com/HANDLE?txn=pay&amount=..&note=..`) has not been tried
  with a real Venmo account.
- No per-item deposit override on the Settings page yet (`variants.deposit_override_cents` exists).
- The theme was checked in Chrome on Brent's laptop (phone and desktop widths, light and dark),
  not in Firefox, Safari or on a real phone.
- Search-as-you-type and the server scripts were verified by Brent on the live site, not by automated tests. There is no test suite yet; `tests/catalog-sample.json` is a small catalog for local runs.

## Working agreement

- Branch per piece of work, pull request into `main` (open it with `gh pr create`), Brent merges.
- Merging is deploying: the server (Debian, nginx, php-fpm, user `admin`, site in
  `/var/www/hummingbird`, scripts run as `www-data`) checks `main` every 2 minutes and runs
  `deploy/update.sh` by itself, once `deploy/setup-auto.sh` has been run there. So `main` must
  always be safe to go live, and a change that needs a manual step on the server has to say so
  in its pull request. `bash deploy/update.sh` still works by hand.
- Brent's laptop signs in to the server with `ssh hummingbird` (user `admin`; the key is
  passphrase-protected and held by the Windows ssh-agent). Claude may use that connection, but
  `.claude/settings.json` makes every `ssh`, `scp` and `sftp` command wait for Brent's approval.
  Use it to look (logs, status, checking a deploy). Changes to the server still go in as scripts
  under `deploy/` that are reviewed in a pull request, so the repo stays the record of how the
  server is set up.
- Local run and server setup steps are in `README.md`. Use `mail_driver` `log` locally so
  no real email is sent, and never run test sign-ups against real member addresses.
