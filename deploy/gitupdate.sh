#!/usr/bin/env bash
# Gitupdate: check GitHub right now and update the site if the main branch has moved.
# On the server, type:   Gitupdate
# (deploy/setup-auto.sh installs that command; it runs this script.)
#
# This is the by-hand version of deploy/auto-update.sh, which cron runs on a schedule.
# Unlike that one it says what it finds, and it always tries, even when the last
# automatic update failed.
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
STATE_DIR="${HUMMINGBIRD_STATE_DIR:-/var/lib/hummingbird}"
LOG="$STATE_DIR/deploy.log"
FAILED="$STATE_DIR/failed-commit"
cd "$APP_DIR"

# Only the owner of the code can "git pull". Running this with sudo would also leave
# files behind that the owner can no longer change.
OWNER="$(stat -c %U "$APP_DIR")"
if [ "$(id -un)" != "$OWNER" ]; then
    echo "Run this as $OWNER, without sudo. (You are $(id -un).)" >&2
    exit 1
fi

# If a scheduled update is running at this moment, wait for it instead of running two at once.
exec 9> "$STATE_DIR/lock"
if ! flock -w 120 9; then
    echo "Another update is still running. Try again in a minute." >&2
    exit 1
fi

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [ "$BRANCH" != "main" ]; then
    echo "The server is on the '$BRANCH' branch, not 'main', so there is nothing to update." >&2
    echo "To go back to main:  git checkout main" >&2
    exit 1
fi

echo "Checking GitHub..."
if ! git fetch --quiet origin main; then
    echo "Could not reach GitHub. Nothing was changed." >&2
    exit 1
fi
LIVE="$(git rev-parse HEAD)"
NEWEST="$(git rev-parse origin/main)"

if [ "$(git rev-list --count HEAD..origin/main)" -eq 0 ]; then
    echo "Already up to date. The site is running:"
    git log -1 --format='  %h  %s  (%cr)'
    if [ "$LIVE" != "$NEWEST" ]; then
        echo "Note: the server has changes of its own that are not on GitHub."
    fi
    exit 0
fi

echo "New on GitHub:"
git log --format='  %h  %s' HEAD..origin/main
echo

stamp() { date '+%Y-%m-%d %H:%M:%S'; }
echo "$(stamp) Updating by hand ${LIVE:0:7} -> ${NEWEST:0:7}: $(git log -1 --format=%s origin/main)" >> "$LOG"

# "tee" shows the update as it happens and also keeps a copy in the log.
if bash "$APP_DIR/deploy/update.sh" 2>&1 | tee -a "$LOG"; then
    rm -f "$FAILED"
    echo
    echo "Done. The site is up to date."
else
    echo "$(stamp) UPDATE FAILED" >> "$LOG"
    # Noted so the scheduled check doesn't retry this same change and email about it.
    echo "$NEWEST" > "$FAILED"
    echo >&2
    echo "The update failed. The messages above say why. Full log: $LOG" >&2
    exit 1
fi
