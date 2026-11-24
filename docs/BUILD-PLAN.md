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
| Member price | List price less the group discount (30% assumed until confirmed) |
| Deposits | $1.50 for a half-gallon glass jug; $3.00 for one gallon and larger. Added to regular orders only, not to shares |
| Shares | Claimed by weight or volume, any amount. Names and amounts visible to all members. Unfilled shares stay listed and expire after 3 months |
| Deadline | Members see Monday 7 pm. Nothing closes until the organizer locks the order (about 11 am Tuesday). A lock can be undone with Reopen |
| Next month | Members can save items for next month at any time. When that month opens, the saved items are already on their order, and they still submit it |
| Payment | At pickup by check, Venmo or cash; organizer marks each order paid |

## Monthly flow

1. Organizer opens the month. "Time to order" email goes out, listing open shares.
2. Members sign in, add items (search and favorites), claim or start shares, and submit.
   Each submit sends a confirmation email. Changes after submitting need a resubmit.
3. Last-call email before the deadline.
4. Tuesday: organizer reviews the combined order (unfinished orders are flagged), locks it,
   and places it with Hummingbird.
5. Organizer ticks anything out of stock and corrects any price. Saving emails the members affected.
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
- [ ] Organizer: out-of-stock and price edits, with update emails
- [ ] Organizer: split sheets, final report (saved and emailed), paid tracking
- [ ] Organizer: members and households, settings (discount, deposits)
- [ ] Emails: time to order, last call, pickup notice, share expiry warning
- [ ] Put each member's personal sign-in link in the "time to order" email, to stay under Resend's daily cap
- [ ] Deposits on a case of several jugs (currently charged once)
- [ ] Unsubscribe link and footer on group emails
- [ ] Fill-the-Hummingbird-cart link (needs a test from the organizer's login)
- [ ] Group name and dancing bean logo on every page
- [ ] Nightly jobs: catalog sync, share expiry, database backup
- [x] Server follows GitHub: merging into `main` deploys within about 2 minutes (`deploy/setup-auto.sh`)
- [x] Debian updates itself daily; email to the organizer when a restart is needed

## Waiting on

- Actual discount, checked against a past invoice
- Venmo handle for the pickup email
- Dancing bean logo file
- Member list (names, emails, households) exported from Mailchimp
