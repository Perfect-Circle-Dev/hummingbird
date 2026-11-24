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
php -S localhost:8000 -t public
```

With `mail_driver` set to `log`, emails are saved in the `email_log` table and not sent.
To read the latest sign-in link:

```
sqlite3 data/hummingbird.sqlite "select body from email_log order by id desc limit 1"
```

## Server setup (Debian)

1. Install: `apt install nginx php-fpm php-sqlite3 php-curl php-mbstring composer sqlite3 git certbot python3-certbot-nginx`
2. Clone this repo to `/var/www/hummingbird`, then `composer install --no-dev`.
3. Copy `config.sample.php` to `config.php` and fill it in.
4. Make `data/` writable by the web server: `chown -R www-data:www-data data`.
5. Run `php bin/migrate.php` as `www-data`.
6. Point nginx at `/var/www/hummingbird/public`, sending every request to `index.php`.
7. Get a certificate: `certbot --nginx -d hummingbird.schefley.net`.
8. Add the nightly catalog sync to `www-data`'s crontab:
   `15 3 * * * php /var/www/hummingbird/bin/sync-catalog.php`

To deploy a change: `git pull`, then `php bin/migrate.php`.
