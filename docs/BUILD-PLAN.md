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
| Households | Several logins can share one household order. Members can add a partner or friend to their own household, fix names and phones, and change their own email (confirmed by a link to the new address). The organizer is told of each addition |
| Email | Sent by the site from brent@schefley.net through Resend (SMTP), replacing Mailchimp. Free plan: 100 emails a day |
| Catalog | Copied nightly from Hummingbird's public product feed; prices fixed when the order is locked |
| Member price | List price less the group discount, 30.2163% |
| Deposits | By container material: $1.50 for each glass jug or jar (half gallon, 64 oz, one gallon, 5.5 lb honey jar); $3.00 for each plastic jug, cube, bucket or pail. A case is charged per container. Added to regular orders only, not to shares |
| Shares | Claimed by weight or volume, any amount. Names and amounts visible to all members. Unfilled shares stay listed and expire after 3 months |
| Deadline | Members see Monday 7 pm. Nothing closes until the organizer locks the order (about 11 am Tuesday). A lock can be undone with Reopen |
| Next month | Members can save items for next month at any time. When that month opens, the saved items are already on their order, and they still submit it |
| Payment | At pickup by check, Venmo or cash; organizer marks each order paid |
| Pickup | Always after 4 pm; the site adds that to each month's pickup note |
| Inactive members | On December 31, members whose household placed no order that year are archived and told how to come back. Organizers and anyone added since July are left alone |

## Monthly flow

1. Organizer opens the month and sends the "time to order" email, which lists open shares and carries each member's sign-in link.
2. Members sign in, add items (search and favorites), claim or start shares, and submit.
   Each submit sends a confirmation email. Changes after submitting need a resubmit.
3. Last-call email goes out by itself a day before the deadline, to households that have not submitted.
4. Tuesday: organizer reviews the combined order (unfinished orders are flagged), locks it,
   and places it with Hummingbird. Items a household never submitted are left off and moved to
   its next-month list; an order changed after it was submitted goes in as it stands.
5. Hummingbird confirms the order. Organizer ticks anything out of stock and corrects any price
   on the combined order. Out-of-stock items are not charged and are carried over to next month
   when the month is closed.
6. Organizer presses "Order placed", with a note of their own if they like. Every household on
   the order is emailed its confirmed total, how to pay (with a Venmo link), the pickup note and
   the organizer's note. A change saved after this emails the households it affects.
7. Final report is saved on the site for the month and emailed to the organizer.
8. If wanted, a pickup reminder goes to each household not yet marked paid.
9. Organizer marks orders paid.

## Build checklist

- [x] Database tables
- [x] Sign-in by emailed link, sign out
- [x] Catalog sync from Hummingbird, with size and deposit rules
- [x] Add members from the command line
- [x] Server live at hummingbird.schefley.net with HTTPS; email sending through Resend
- [x] Search ranked by best match, with results while typing
- [x] Browse by category, in a card under My favorites, from the letters item numbers begin with
- [x] One card per product with a row for each size; double listings shown once; very big sizes left out
- [x] Product descriptions from Hummingbird, in a pop-up beside the name
- [x] Organic, raw and origin under each product's name, from Hummingbird's price sheet
- [x] Add and Save buttons show how many are on the order ("2 Added", "1 Saved"), with "View order" and "View saved" links
- [x] Order page: search, favorites, quantities, submit, confirmation email
- [x] Share board: start, claim, change, cancel; filled shares show on household totals
- [x] Open a month from the command line (`bin/open-cycle.php`), until the organizer page exists
- [x] Organizer: months (open, change, lock, close), combined order, unfinished flags
- [x] At lock: fix prices, move filled shares onto the order, and settle the odd cent when a share's parts don't add up exactly (it goes to whoever started the share)
- [x] My household page: members add people to their household, remove them, fix names and phones, change their own email
- [x] Reopen a locked month
- [x] "Save for next month" list, always available to members
- [x] Organizer: out-of-stock and price edits, with update emails; out-of-stock items and shares carry over to next month at close
- [x] Organizer: report by household, on the site for every month and emailed to the organizers at lock, at close and on request
- [x] Organizer: split sheets for shared items, with amounts as read on a scale
- [x] Organizer: "Order placed" step: every household is emailed its confirmed total, how to pay, the pickup note and a note from the organizer
- [x] Organizer: mark each order paid (check, Venmo or cash); pickup reminder with the amount due and a Venmo link
- [x] Organizer: adjust quantities on any household's order from the report page, and email that household its order
- [x] "Receives notification emails" box for each person on My household
- [x] Organizer: members and households (add, paste a list, edit, switch off), settings (discount, deposits, share expiry, Venmo name)
- [x] Emails: time to order (button, with a note), last call (automatic), pickup notice, share expiry warning (automatic)
- [x] Organizer: "Email everyone" page, to write and send a message to all members, with a test copy first; to everyone or to selected members, an optional "Hi <first name>," greeting, and a list of what was sent before
- [x] Previous orders on My household, by year and month
- [x] Organizer: Shares page, to change the amounts on any share item or delete it, with an email to the people on it
- [x] "(share)" on My order links to that item on Share items
- [x] Each member's personal sign-in link in the "time to order" and last-call emails, good until the month is closed
- [x] Email queue with a daily limit, so a big mailing can't go over Resend's cap
- [x] Deposits on a case of several jugs: one deposit for each container in the case
- [x] Unsubscribe link and footer on group emails
- [x] Fill-the-Hummingbird-cart link on the combined order (tried by Brent on 2026-11-25; it works)
- [x] Group name on every page, and a slot for the dancing bean logo (waiting for the file)
- [x] The Talavera theme (`docs/THEME.md`): light and dark, larger text and buttons, fonts kept on the site
- [x] Nightly jobs: catalog sync, share expiry, database backup (30 kept, one emailed weekly)
- [x] Updates go live with the `Gitupdate` command on the server (the automatic two-minute check was switched off on 2026-11-25)
- [x] Debian updates itself daily; email to the organizer when a restart is needed

## Waiting on

- Venmo name for the pickup email: Brent types it in under Organizer, Settings. The pay link needs one try with a real account
- Dancing bean logo file: add it to `public/` as `logo.webp`, `logo.png` or `logo.svg`
- Member list (names, emails, households) exported from Mailchimp: paste it in under Organizer, Members
