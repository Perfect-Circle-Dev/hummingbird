#!/usr/bin/env bash
# Brings the server up to date after changes are pushed to GitHub. Run from the repo:
#   bash deploy/update.sh
set -euo pipefail
APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR"
git pull --ff-only
composer install --no-dev
sudo -u www-data php bin/migrate.php
echo "Updated to $(git log -1 --format='%h %s')"
