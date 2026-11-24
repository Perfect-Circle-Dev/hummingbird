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
6. Follow the three steps the script prints at the end (HTTPS, add yourself, visit the site).

The script installs nginx and PHP, creates `config.php` and the database, sets up the
web server, schedules the nightly catalog refresh and downloads the catalog once.

To deploy a change after pushing to GitHub: `bash deploy/update.sh` on the server.
