#!/usr/bin/env bash
# One-time setup for a fresh Debian server. Run from the cloned repo:
#   sudo bash deploy/setup-server.sh
# Safe to run again; it will not overwrite config.php, the database or the
# web server settings (which hold the HTTPS setup once certbot has run).
set -euo pipefail

DOMAIN="hummingbird.schefley.net"
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"

if [ "$(id -u)" -ne 0 ]; then
    echo "Please run with sudo." >&2
    exit 1
fi
# The person who ran sudo keeps ownership of the code, so "git pull" keeps working.
OWNER="${SUDO_USER:-root}"

echo "==> Installing packages"
apt-get update
apt-get install -y nginx php-fpm php-cli php-sqlite3 php-curl php-mbstring \
    composer sqlite3 git unzip certbot python3-certbot-nginx

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
echo "==> PHP $PHP_VER"

echo "==> Installing PHP libraries"
sudo -u "$OWNER" composer install --no-dev --working-dir="$APP_DIR"

echo "==> Settings file"
if [ ! -f "$APP_DIR/config.php" ]; then
    cp "$APP_DIR/config.sample.php" "$APP_DIR/config.php"
    echo "    Created config.php from the sample."
fi
chown "$OWNER":www-data "$APP_DIR/config.php"
chmod 640 "$APP_DIR/config.php"

echo "==> Database folder"
chown -R www-data:www-data "$APP_DIR/data"
chmod 750 "$APP_DIR/data"
sudo -u www-data php "$APP_DIR/bin/migrate.php"

echo "==> Web server"
if [ -f /etc/nginx/sites-available/hummingbird ]; then
    # Certbot writes the HTTPS settings into this file, so writing it again
    # would switch HTTPS off. To start over, delete the file and run this script again.
    echo "    Already set up; left as it is."
else
    sed -e "s#__DOMAIN__#$DOMAIN#g" -e "s#__APP_DIR__#$APP_DIR#g" -e "s#__PHP_VER__#$PHP_VER#g" \
        "$APP_DIR/deploy/nginx.conf" > /etc/nginx/sites-available/hummingbird
fi
ln -sf /etc/nginx/sites-available/hummingbird /etc/nginx/sites-enabled/hummingbird
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

echo "==> Nightly catalog refresh (3:15 am)"
cat > /etc/cron.d/hummingbird <<CRON
15 3 * * * www-data php $APP_DIR/bin/sync-catalog.php >> /var/log/hummingbird-sync.log 2>&1
CRON
touch /var/log/hummingbird-sync.log
chown www-data /var/log/hummingbird-sync.log

echo "==> First catalog download"
sudo -u www-data php "$APP_DIR/bin/sync-catalog.php" || echo "    Catalog download failed; see the message above."

cat <<DONE

Setup finished. Still to do by hand:

1. Turn on HTTPS (needs ports 80 and 443 open in the Lightsail firewall):
     sudo certbot --nginx -d $DOMAIN

2. Add yourself as the organizer:
     sudo -u www-data php $APP_DIR/bin/add-member.php "Brent" brent@schefley.net "Schefley" --admin

3. Visit https://$DOMAIN

4. Switch on automatic updates (Debian's own, and the site following GitHub):
     sudo bash $APP_DIR/deploy/setup-auto.sh
DONE
