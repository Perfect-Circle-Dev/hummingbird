# Holy Frijoles

Ordering and bulk-share site for the Holy Frijoles buying group. See
[docs/BUILD-PLAN.md](docs/BUILD-PLAN.md) for what it does and what is finished.

## Layout

| Path | What it is |
|---|---|
| `public/` | The only folder the web server shows. `index.php` handles every page |
| `src/` | The code behind the pages (database, sign-in, email, catalog) |
| `bin/` | Scripts run from the command line or cron |
| `schema.sql` | Database tables |
| `config.sample.php` | Settings template. Copy to `config.php`, which git ignores |
| `data/` | The SQLite database file lives here. Git ignores its contents |

## Run it on your own computer

```
cp config.sample.php config.php      # then set base_url to http://localhost:8000
php bin/migrate.php
php bin/add-member.php "Brent" brent@schefley.net "Schefley" --admin
php bin/sync-catalog.php
php bin/open-cycle.php "December 2026" "2026-12-14 19:00" "Pickup from Wednesday, Dec 16"
php -S localhost:8000 -t public
```

With `mail_driver` set to `log`, emails are saved in the `email_log` table and not sent.
To read the latest sign-in link:

```
sqlite3 data/hummingbird.sqlite "select body from email_log order by id desc limit 1"
```

## Server setup (Debian)

One time, on a fresh server:

1. Give the server read access to this private repo. On the server:
   `ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519 -N ""`, then `cat ~/.ssh/id_ed25519.pub`.
   Paste that line into GitHub under this repo's Settings → Deploy keys (read-only).
2. `sudo apt-get update && sudo apt-get install -y git`
3. `sudo mkdir -p /var/www/hummingbird && sudo chown $USER /var/www/hummingbird`
4. `git clone git@github.com:Perfect-Circle-Dev/hummingbird.git /var/www/hummingbird`
5. `cd /var/www/hummingbird && sudo bash deploy/setup-server.sh`
6. Follow the four steps the script prints at the end (HTTPS, add yourself, visit the site,
   and run `deploy/setup-auto.sh` for everything the server does by itself).

The script installs nginx and PHP, creates `config.php` and the database, sets up the
web server and downloads the catalog once.

## What the server does by itself

Run once on the server (and again any time; it is safe to repeat):

```
cd /var/www/hummingbird && sudo bash deploy/setup-auto.sh
```

After that:

- **The site follows GitHub.** Every 2 minutes the server checks the `main` branch. When a
  pull request is merged, it runs `deploy/update.sh` by itself, so the change is live within
  about 2 minutes. Work branches never deploy. If an update fails you get one email, and the
  server waits for a newer change before trying again. The log is `/var/lib/hummingbird/deploy.log`.
- **Debian keeps itself up to date** with stable and security updates each day. It never
  restarts by itself.
- **Queued emails go out every minute.** Group emails and pickup emails wait in a queue and
  `bin/mail-jobs.php` sends a few at a time, under the daily limit set on the Settings page.
  The same job sends the last-call reminder a day before a deadline, and share-expiry warnings.
- **The catalog is refreshed each night** from Hummingbird, at 11:15 UTC (3:15 am Pacific in
  winter, 4:15 am in summer). Open orders are repriced and old shares expire then.
- **The database is backed up each night**, half an hour later. The last 30 nights are kept in
  `data/backups/`, and once a week the newest is emailed to `admin_email`. See below.
- **The member list is tidied on December 31.** Members whose household had nothing on any order
  that year are archived and sent a note saying they can come back by emailing you. Organizers and
  anyone added since July are left alone, and you are emailed the list. To see who it would
  archive, any day: `sudo -u www-data php bin/yearly-archive.php --dry-run`.
- **You get an email when a restart is needed** (usually a new kernel), with a reminder each
  week until it is done. To restart: `sudo reboot`. The site is offline for about a minute.

These notices go to `admin_email` in `config.php` (or `mail_from` if that line is missing).

## Backups, and restoring one

Each backup is one file, `data/backups/hummingbird-YYYY-MM-DD.sqlite.gz`. It holds members,
households, orders, shares and settings. It leaves out device sessions, sign-in links and the
text of past emails, so a copy that leaks can't be used to sign in as anyone. If a backup fails,
you get an email saying so. To make one right now: `sudo -u www-data php bin/backup.php`.

To put a backup back (this replaces everything entered since that night):

```
cd /var/www/hummingbird/data
ls backups/                                   # choose a date
sudo systemctl stop nginx cron                # nothing can change the database now
sudo -u www-data sqlite3 hummingbird.sqlite "VACUUM INTO 'before-restore.sqlite'"   # keep today's, in case
sudo -u www-data sh -c 'gunzip -c backups/hummingbird-2026-12-01.sqlite.gz > hummingbird.sqlite'
sudo rm -f hummingbird.sqlite-wal hummingbird.sqlite-shm    # leftovers that belong to the old file
sudo systemctl start nginx cron
```

Everyone then signs in again with a fresh link. To restore from an emailed copy, first send
it up from the laptop: `scp hummingbird-2026-12-01.sqlite.gz hummingbird:/tmp/`, then use
`/tmp/hummingbird-2026-12-01.sqlite.gz` in the `gunzip` line.

## Reading the emails

To see every email the site sends to members and organizers, as it arrives in a mail program:

```
sudo -u www-data php bin/sample-emails.php you@example.com
```

It sends about 19 emails, one of each kind, to that address, which must be an organizer's. They
are written by the site's own code, so the wording is exactly what goes out, but from a throwaway
database of made-up people and orders: nobody else is emailed and nothing real is changed. The
links in them lead nowhere. They count toward Resend's 100 emails a day.

`php bin/sample-emails.php --dry-run` prints them in the terminal and sends nothing.

The notices the server sends about itself (weekly backup, restart needed, failed update) are not
in the set.

## The logo

Put a file named `logo.svg`, `logo.webp` or `logo.png` in `public/` and it appears beside the
group name on every page, and as the browser tab icon. It is shown 48 pixels tall, so an image
about 100 to 200 pixels tall stays sharp on phones. Nothing else needs editing.

To check GitHub and update right away, without waiting for the next automatic check, type
this anywhere on the server:

```
Gitupdate
```

It says what is new, runs the update and shows the result, or tells you the site is
already up to date.

To change how often the server checks by itself, edit `CHECK_EVERY_MINUTES` at the top of
`deploy/setup-auto.sh` (0 means never: the site then updates only when someone types
`Gitupdate`) and run `sudo bash deploy/setup-auto.sh` on the server again.
