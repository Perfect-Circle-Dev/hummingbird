#!/usr/bin/env bash
# Keeps the live site in step with GitHub. Cron runs this every 2 minutes
# (deploy/setup-auto.sh sets that up). Most runs find nothing new and stop at once.
# When the main branch on GitHub has moved, it runs deploy/update.sh, the same script
# you would run by hand. If the update fails it emails the organizer once, then waits
# for a newer commit before trying again.
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
# A folder this user can write to: the log, a lock, and a note of the last failure.
STATE_DIR="${HUMMINGBIRD_STATE_DIR:-/var/lib/hummingbird}"
LOG="$STATE_DIR/deploy.log"
FAILED="$STATE_DIR/failed-commit"
cd "$APP_DIR"

# Never run two updates at once (a slow one may still be going when cron fires again).
exec 9> "$STATE_DIR/lock"
flock -n 9 || exit 0

# Only the main branch deploys. If the server has been switched to another branch
# by hand, leave it alone.
[ "$(git rev-parse --abbrev-ref HEAD)" = "main" ] || exit 0

# Ask GitHub what is newest. If GitHub can't be reached, try again in 2 minutes.
git fetch --quiet origin main || exit 0
LIVE="$(git rev-parse HEAD)"
NEWEST="$(git rev-parse origin/main)"
[ "$LIVE" = "$NEWEST" ] && exit 0

# A commit that already failed is not retried (or emailed about) every 2 minutes.
if [ -f "$FAILED" ] && [ "$(cat "$FAILED")" = "$NEWEST" ]; then
    exit 0
fi

stamp() { date '+%Y-%m-%d %H:%M:%S'; }
echo "$(stamp) Updating ${LIVE:0:7} -> ${NEWEST:0:7}: $(git log -1 --format=%s origin/main)" >> "$LOG"

if OUTPUT="$(bash "$APP_DIR/deploy/update.sh" 2>&1)"; then
    echo "$OUTPUT" >> "$LOG"
    rm -f "$FAILED"
else
    echo "$OUTPUT" >> "$LOG"
    echo "$(stamp) UPDATE FAILED" >> "$LOG"
    echo "$NEWEST" > "$FAILED"
    # The email goes through the site's own mailer, which has to run as www-data
    # (it records every message in the database).
    {
        echo "The Holy Frijoles server tried to update itself from GitHub and the update failed."
        echo "The site may be running a mix of old and new code until this is sorted out."
        echo
        echo "Change being installed: $(git log -1 --format='%h %s' origin/main)"
        echo
        echo "Last lines of the update:"
        echo "$OUTPUT" | tail -n 25
        echo
        echo "To see the problem, sign in to the server and run:"
        echo "  cd $APP_DIR && bash deploy/update.sh"
        echo
        echo "The server will not try this change again by itself. It will try again when"
        echo "a newer change is merged. The full log is $LOG"
    } | sudo -u www-data php "$APP_DIR/bin/notify-admin.php" "Holy Frijoles site update failed" || true
fi
