# Holy Frijoles build plan

The ordering and bulk-share site for the Holy Frijoles buying group (monthly orders with
Hummingbird Wholesale, Eugene). Target: live for the December 2026 order.

## Decisions

| Topic | Decision |
|---|---|
| Address | hummingbird.schefley.net, on its own AWS Debian server |
| Workflow | VS Code → GitHub (this repo) → server. A pull request merged into `main` goes live by itself |
| Stack | PHP 8.2+, SQLite database (one file, easy to back up), no framework |
| Sign-in | Emailed link, no passwords. Devices stay signed in for 180 days |
| Households | Several logins can share one household order |
| Email | Sent by the site from brent@schefley.net through Resend (SMTP), replacing Mailchimp. Free plan: 100 emails a day |
| Catalog | Copied nightly from Hummingbird's public product feed; prices fixed when the order is locked |
| Member price | List price less the group discount, 30.2163% |
| Deposits | By container material: $1.50 for each glass jug or jar (half gallon, 64 oz, one gallon, 5.5 lb honey jar); $3.00 for each plastic jug, cube, bucket or pail. A case is charged per container. Added to regular orders only, not to shares |
| Shares | Claimed by weight or volume, any amount. Names and amounts visible to all members. Unfilled shares stay listed and expire after 3 months |
| Deadline | Members see Monday 7 pm. Nothing closes until the organizer locks the order (about 11 am Tuesday). A lock can be undone with Reopen |
| Next month | Members can save items for next month at any time. When that month opens, the saved items are already on their order, and they still submit it |
| Payment | At pickup by check, Venmo or cash; organizer marks each order paid |

## Monthly flow

1. Organizer opens the month and sends the "time to order" email, which lists open shares and carries each member's sign-in link.
2. Members sign in, add items (search and favorites), claim or start shares, and submit.
   Each submit sends a confirmation email. Changes after submitting need a resubmit.
3. Last-call email goes out by itself a day before the deadline, to households that have not submitted.
4. Tuesday: organizer reviews the combined order (unfinished orders are flagged), locks it,
   and places it with Hummingbird.
5. Organizer ticks anything out of stock and corrects any price. Saving emails the members affected.
   Out-of-stock items are not charged and are carried over to next month when the month is closed.
6. Final report is saved on the site for the month and emailed to the organizer.
7. Pickup email goes to each household with the amount due and a Venmo link.
8. Organizer marks orders paid.

## Build checklist

- [x] Database tables
- [x] Sign-in by emailed link, sign out
- [x] Catalog sync from Hummingbird, with size and deposit rules
- [x] Add members from the command line
- [x] Server live at hummingbird.schefley.net with HTTPS; email sending through Resend
- [x] Search ranked by best match, with results while typing
- [x] Order page: search, favorites, quantities, submit, confirmation email
- [x] Share board: start, claim, change, cancel; filled shares show on household totals
- [x] Open a month from the command line (`bin/open-cycle.php`), until the organizer page exists
- [x] Organizer: months (open, change, lock, close), combined order, unfinished flags
- [x] At lock: fix prices, move filled shares onto the order, and settle the odd cent when a share's parts don't add up exactly (it goes to whoever started the share)
- [x] Reopen a locked month
- [x] "Save for next month" list, always available to members
- [x] Organizer: out-of-stock and price edits, with update emails; out-of-stock items and shares carry over to next month at close
- [x] Organizer: report by household, on the site for every month and emailed to the organizers at lock, at close and on request
- [x] Organizer: split sheets for shared items, with amounts as read on a scale
- [x] Organizer: mark each order paid (check, Venmo or cash); pickup emails with the amount due and a Venmo link
- [x] Organizer: members and households (add, paste a list, edit, switch off), settings (discount, deposits, share expiry, Venmo name)
- [x] Emails: time to order (button, with a note), last call (automatic), pickup notice, share expiry warning (automatic)
- [x] Each member's personal sign-in link in the "time to order" and last-call emails, good until the month is locked
- [x] Email queue with a daily limit, so a big mailing can't go over Resend's cap
- [x] Deposits on a case of several jugs: one deposit for each container in the case
- [x] Unsubscribe link and footer on group emails
- [x] Fill-the-Hummingbird-cart link on the combined order (still needs a test from the organizer's login)
- [x] Group name on every page, and a slot for the dancing bean logo (waiting for the file)
- [x] Nightly jobs: catalog sync, share expiry, database backup (30 kept, one emailed weekly)
- [x] Server follows GitHub: merging into `main` deploys within about 2 minutes (`deploy/setup-auto.sh`)
- [x] Debian updates itself daily; email to the organizer when a restart is needed

## Waiting on

- Venmo name for the pickup email: Brent types it in under Organizer, Settings. The pay link needs one try with a real account
- Dancing bean logo file: add it to `public/` as `logo.webp`, `logo.png` or `logo.svg`
- A try of the "Fill my Hummingbird cart" link from Brent's wholesale login
- Member list (names, emails, households) exported from Mailchimp: paste it in under Organizer, Members
