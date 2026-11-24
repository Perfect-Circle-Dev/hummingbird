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
  `logout`, `order`, `shares`, `organizer` (admins only), default home. `public/site.css`, `public/site.js`.
- `src/bootstrap.php`: config, `db()`, `q($sql, $params)`, `setting()`, `e()`, `money()`, `now_utc()`.
- `src/auth.php`: emailed sign-in links, device sessions, CSRF helpers.
- `src/mailer.php`: `send_email()`; every message is written to `email_log`.
- `src/catalog.php`: sync from Hummingbird's product feed, size and deposit rules, member price.
- `src/orders.php`: months ("cycles"), household orders, search, favorites, totals, submit email.
- `src/shares.php`: share board logic, `shares_on_order()`, `share_split_cents()`.
- `src/organizer.php`: open, change, lock and close a month (`cycle_open`, `cycle_update`,
  `cycle_lock`, `cycle_reopen`, `cycle_close`), `combined_order()`, `unfinished_orders()`.
- `src/pages/*.php`: one function per page (`page_order`, `page_shares`, `page_organizer`).
  `/?a=organizer` is the months page; `/?a=organizer&month=ID` is that month's combined order.
- `bin/`: command-line scripts (`migrate`, `add-member`, `open-cycle`, `sync-catalog`, `list-deposits`).
- `schema.sql`: all tables. `bin/migrate.php` runs it; it must stay safe to re-run.
  To change an existing table, add a guarded `ALTER TABLE` step to `bin/migrate.php`.
- `deploy/`: server setup, nginx template, `update.sh`.

## Conventions

- SQLite database at `data/hummingbird.sqlite` (ignored by git). `config.php` is ignored too
  and holds the Resend API key. Never commit either, and never print the key.
- Money is integer cents. Times are stored as UTC strings; show them with `fmt_local()`.
- Always escape output with `e()`. Always use `q()` with `?` placeholders.
- Every POST from a signed-in member is CSRF-checked in `index.php`; forms include `csrf_field()`.
  After handling a POST, redirect (no page is rendered from a POST).
- Orders belong to a household, not a person. Check `household_id` / `order_id` on every
  change so one household can never edit another's order.
- Any change to an order sets it back to `draft`; the member must submit again.
- Interface text: plain, friendly, sentence case, no jargon. Touch targets at least 44px.
  Pages must work on a phone.

## Business rules (already decided)

- Member price = Hummingbird list price less the group discount (`settings.discount_percent`,
  30 until Brent confirms it from an invoice). Prices follow the catalog while a month is
  open and are fixed when the organizer locks it.
- Deposits: $1.50 for a half-gallon glass container, $3.00 for one gallon and larger.
  Added to regular order lines only, never to shares (whoever takes the container handles it).
  Worked out from the size text in `deposit_for()`; `deposit_override_cents` is there for exceptions.
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
- A locked month can be reopened (`cycle_reopen`): members can change orders again, prices
  follow the catalog again, and its shares go back to the board fully claimed. Closing can't
  be undone. Lock, reopen and close each need a ticked box.
- Deadline is soft: members see Monday 7 pm, but nothing closes until the organizer locks
  the month, around 11 am Tuesday. Late orders on Tuesday morning are welcome.
- Several logins can share one household order.
- Sign-in is by emailed link only. No passwords. Brent adds members by hand.
- Payment happens at pickup by check, Venmo or cash.

## What to build next: organizer pages (members with `is_admin = 1`)

Items 1 to 3 are built (months, combined order with unfinished flags, the lock step).
Numbering is kept so older notes still match.

4. After placing the order: per line and per share, an "out of stock" checkbox and an
   editable price. Saving emails an update to each household affected (for a share, everyone on it).
5. Final report: every ordering household with its full list, prices, deposits, share parts
   and total. Saved on the site per month and emailed to Brent when the month is locked.
   He never prints.
6. Split sheet per shared item (who gets how much) for divide-up day.
7. Mark each order paid, with the method. Pickup email per household with the amount due
   and a Venmo link (handle still needed from Brent).
8. Members and households: add, edit, deactivate. Settings: discount and deposit amounts.
9. Group emails, replacing Mailchimp: "time to order" (listing open shares), last call,
   pickup notice, share-expiry warning. Each needs a footer and an unsubscribe link.
   Resend's free plan allows 100 emails a day, shared with any other site on the same
   account, so put each member's personal sign-in link in the "time to order" email
   instead of making them request one.
10. "Fill my Hummingbird cart" link built from variant ids (Shopify cart permalink:
    `/cart/VARIANT_ID:QTY,VARIANT_ID:QTY`). Untested; needs a try from Brent's wholesale login.
11. Group name and the dancing bean logo on every page (Brent will supply the file).
12. Nightly: database backup, alongside the existing catalog sync and share expiry.

Members said no to a "reorder last month" button; favorites cover it.

## Known gaps

- A case of several jugs is charged one deposit.
- The wholesale discount is assumed, not confirmed.
- Search-as-you-type and the server scripts were verified by Brent on the live site, not by automated tests. There is no test suite yet; `tests/catalog-sample.json` is a small catalog for local runs.

## Working agreement

- Branch per piece of work, pull request into `main`, Brent merges.
- He deploys with `bash deploy/update.sh` on the server (Debian, nginx, php-fpm, user `admin`,
  site in `/var/www/hummingbird`, scripts run as `www-data`).
- Local run and server setup steps are in `README.md`. Use `mail_driver` `log` locally so
  no real email is sent, and never run test sign-ups against real member addresses.
