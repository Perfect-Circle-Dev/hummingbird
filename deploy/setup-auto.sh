#!/usr/bin/env bash
# Sets up the things the server does by itself. Run once from the repo:
#   sudo bash deploy/setup-auto.sh
# Safe to run again.
#
#   1. Debian installs its own stable and security updates every day.
#      It never restarts the server by itself.
#   2. The organizer gets an email when an update needs a restart (usually a new kernel).
#   3. The site follows GitHub: a change merged into main is live within about 2 minutes.
set -euo pipefail

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
cat > /etc/cron.d/hummingbird-auto <<CRON
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# Follow GitHub: look every 2 minutes, update when the main branch has moved.
*/2 * * * * $OWNER bash $APP_DIR/deploy/auto-update.sh
# Each morning, email the organizer if Debian is waiting for a restart.
0 15 * * * www-data php $APP_DIR/bin/check-reboot.php >> /var/log/hummingbird-sync.log 2>&1
CRON
touch /var/log/hummingbird-sync.log
chown www-data /var/log/hummingbird-sync.log
if systemctl is-active --quiet cron; then
    echo "    cron is running."
else
    echo "    PROBLEM: cron is not running, so none of the scheduled jobs will happen."
    READY=0
fi

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
    echo "All set. From now on, merging into main on GitHub updates the site within about 2 minutes."
    echo "The first check happens within 2 minutes. If GitHub is ahead, the update log will show it."
else
    echo "Set up, but fix the PROBLEM lines above and run this script again."
fi
cat <<DONE

Useful to know:
  Update log:            /var/lib/hummingbird/deploy.log
  Update by hand:        cd $APP_DIR && bash deploy/update.sh
  Pause auto-updates:    sudo rm /etc/cron.d/hummingbird-auto   (run this script again to resume)
  Is a restart pending?  sudo -u www-data php $APP_DIR/bin/check-reboot.php
DONE
