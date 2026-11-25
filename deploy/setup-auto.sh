#!/usr/bin/env bash
# Sets up the things the server does by itself. Run once from the repo:
#   sudo bash deploy/setup-auto.sh
# Safe to run again.
#
#   1. Debian installs its own stable and security updates every day.
#      It never restarts the server by itself.
#   2. The organizer gets an email when an update needs a restart (usually a new kernel).
#   3. The "Gitupdate" command: type it on the server to bring the site up to date with
#      the main branch on GitHub. This is how changes go live.
#   4. If CHECK_EVERY_MINUTES below is set above 0, the server also checks GitHub by itself
#      that often and updates when main has moved. It is off (0).
#   5. Each night: the catalog refresh, then a database backup (a copy is emailed weekly).
#   6. Every minute: emails waiting in the queue are sent.
set -euo pipefail

# How often the server looks at GitHub by itself, in minutes (1 to 30).
# 0 switches the automatic checks off; the site then updates only when someone
# types "Gitupdate" on the server. After changing this, run this script again.
# Switched off on 2026-11-25, once the site was stable: updates now go live with Gitupdate.
CHECK_EVERY_MINUTES=0

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"

if [ "$(id -u)" -ne 0 ]; then
    echo "Please run with sudo." >&2
    exit 1
fi
# Whoever owns the code is the one who can "git pull", so updates run as that user.
OWNER="$(stat -c %U "$APP_DIR")"

echo "==> Packages"
# cron runs the scheduled jobs below. Debian's cloud images no longer include it,
# and without it the schedule files are written but nothing ever runs them.
apt-get update
apt-get install -y unattended-upgrades
# --no-install-recommends: on its own, cron also brings in a mail server (exim4) to
# post job output to local mailboxes. Nothing here needs one; the site sends its own email.
apt-get install -y --no-install-recommends cron
systemctl enable --now cron

echo "==> Automatic Debian updates"
# Debian's own settings (in 50unattended-upgrades) already cover the stable release and
# its security updates. These two lines switch the daily check and install on.
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'APT'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT
systemctl enable --now apt-daily.timer apt-daily-upgrade.timer

echo "==> Folder for the update log"
install -d -o "$OWNER" -g "$(id -gn "$OWNER")" -m 755 /var/lib/hummingbird

echo "==> Checking that $OWNER can run an update unattended"
READY=1
if ! sudo -H -u "$OWNER" sudo -n true 2>/dev/null; then
    echo "    PROBLEM: $OWNER can't use sudo without a password, which deploy/update.sh needs."
    READY=0
fi
if ! sudo -H -u "$OWNER" git -C "$APP_DIR" fetch --quiet origin main; then
    echo "    PROBLEM: $OWNER could not reach GitHub. Check the deploy key (see README.md)."
    READY=0
fi
BRANCH="$(sudo -H -u "$OWNER" git -C "$APP_DIR" rev-parse --abbrev-ref HEAD)"
if [ "$BRANCH" != "main" ]; then
    echo "    NOTE: the server is on the '$BRANCH' branch. Only 'main' updates by itself."
fi

echo "==> Scheduled jobs"
# The server's clock is UTC: 15:00 is 7 am in winter and 8 am in summer, Pacific time.
{
    echo "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
    if [ "$CHECK_EVERY_MINUTES" -gt 0 ]; then
        echo "# Follow GitHub: look every $CHECK_EVERY_MINUTES minutes, update when the main branch has moved."
        echo "*/$CHECK_EVERY_MINUTES * * * * $OWNER bash $APP_DIR/deploy/auto-update.sh"
    fi
    echo "# Each night: refresh the catalog from Hummingbird (which also reprices open orders and"
    echo "# expires old shares), then back up the database. 11:15 UTC is 3:15 am Pacific in"
    echo "# winter and 4:15 am in summer."
    echo "15 11 * * * www-data php $APP_DIR/bin/sync-catalog.php >> /var/log/hummingbird-sync.log 2>&1"
    echo "45 11 * * * www-data php $APP_DIR/bin/backup.php >> /var/log/hummingbird-sync.log 2>&1"
    echo "# Each night, the end-of-year tidy-up of the member list. It does nothing except on"
    echo "# December 31 (or the first week of January, to catch up if the server was off)."
    echo "30 12 * * * www-data php $APP_DIR/bin/yearly-archive.php >> /var/log/hummingbird-sync.log 2>&1"
    echo "# Each morning, email the organizer if Debian is waiting for a restart."
    echo "0 15 * * * www-data php $APP_DIR/bin/check-reboot.php >> /var/log/hummingbird-sync.log 2>&1"
    echo "# Every minute: send emails waiting in the queue, and the ones the site sends by"
    echo "# itself (last call before a deadline, share-expiry warnings)."
    echo "* * * * * www-data php $APP_DIR/bin/mail-jobs.php >> /var/log/hummingbird-sync.log 2>&1"
} > /etc/cron.d/hummingbird-auto
# The catalog refresh used to have a schedule file of its own, set for 3:15 by the
# server's clock. That clock is UTC, so it ran at 7:15 in the evening, Pacific time.
# It is in the file above now, so the old one goes.
rm -f /etc/cron.d/hummingbird
touch /var/log/hummingbird-sync.log
chown www-data /var/log/hummingbird-sync.log
if [ "$CHECK_EVERY_MINUTES" -gt 0 ]; then
    echo "    The server looks at GitHub every $CHECK_EVERY_MINUTES minutes."
else
    echo "    Automatic checks are off. Update with the Gitupdate command."
fi
echo "    Queued emails are sent every minute."
echo "    Each night: catalog refresh at 11:15 UTC, database backup at 11:45 UTC."
echo "    Each December 31: members with no order that year are archived."
if systemctl is-active --quiet cron; then
    echo "    cron is running."
else
    echo "    PROBLEM: cron is not running, so none of the scheduled jobs will happen."
    READY=0
fi

echo "==> Web server: let browsers keep the fonts"
# The site file is not rewritten once HTTPS is set up (certbot keeps its settings there), so
# this one addition is slipped in if it is missing. The old file is kept beside it, and put
# back if nginx does not like the result.
SITE=/etc/nginx/sites-available/hummingbird
if [ ! -f "$SITE" ]; then
    echo "    No site file yet; deploy/setup-server.sh writes it."
elif grep -q 'location /fonts/' "$SITE"; then
    echo "    Already set."
else
    cp "$SITE" "$SITE.before-fonts"
    # Puts the rule in just above the "location ~ /\." block (the one that hides dot-files).
    perl -0pi -e 's|(\n[ \t]*location ~ /\\\. \{)|\n    # Fonts never change once published, so browsers may keep them for a year.\n    location /fonts/ {\n        expires 1y;\n        add_header Cache-Control "public, immutable";\n    }\n$1|' "$SITE"
    if grep -q 'location /fonts/' "$SITE" && nginx -t 2>/dev/null; then
        systemctl reload nginx
        echo "    Fonts may now be kept by browsers for a year."
    else
        cp "$SITE.before-fonts" "$SITE"
        echo "    PROBLEM: could not add the fonts rule to $SITE; it has been left as it was."
        READY=0
    fi
fi

echo "==> The Gitupdate command"
# A tiny launcher, so typing "Gitupdate" anywhere on the server runs deploy/gitupdate.sh.
# "gitupdate" in small letters works too.
cat > /usr/local/bin/Gitupdate <<CMD
#!/bin/sh
# Check GitHub now and update the site if needed. Installed by deploy/setup-auto.sh.
exec bash $APP_DIR/deploy/gitupdate.sh "\$@"
CMD
chmod 755 /usr/local/bin/Gitupdate
ln -sf Gitupdate /usr/local/bin/gitupdate

echo "==> Test email"
if echo "This is a test from deploy/setup-auto.sh. Notices about failed updates and pending restarts will come to this address." \
    | sudo -u www-data php "$APP_DIR/bin/notify-admin.php" "Holy Frijoles server notices are set up"; then
    echo "    Sent. Check your inbox."
else
    echo "    PROBLEM: the test email was not sent. Check the mail settings in config.php."
    READY=0
fi

echo
if [ "$READY" -eq 1 ]; then
    if [ "$CHECK_EVERY_MINUTES" -gt 0 ]; then
        echo "All set. Merging into main on GitHub updates the site within about $CHECK_EVERY_MINUTES minutes."
    else
        echo "All set. Automatic checks are off: after merging into main, type Gitupdate here to update the site."
    fi
else
    echo "Set up, but fix the PROBLEM lines above and run this script again."
fi
cat <<DONE

Useful to know:
  Update log:            /var/lib/hummingbird/deploy.log
  Check GitHub now:      Gitupdate
  How often it checks:   CHECK_EVERY_MINUTES at the top of deploy/setup-auto.sh (0 = only by hand)
  Is a restart pending?  sudo -u www-data php $APP_DIR/bin/check-reboot.php
DONE
