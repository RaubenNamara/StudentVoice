#!/bin/bash
# Deploys Student Voice from the cPanel Git clone to the live site.
#
#   cPanel > Git Version Control > Student Voice > "Update from Remote", then "Deploy HEAD Commit"
#   (.cpanel.yml runs this script), or over SSH:  bash ~/repositories/StudentVoice/deploy/deploy.sh
#
# What it does, in order (it stops at the first error, before live is touched where it can):
#   1. builds the frontend for the subdomain's root (the committed frontend/dist is built for
#      http://localhost/studentvoice/, so it is never copied to live)
#   2. backs up the database to ~/private_backups
#   3. copies backend/ and the new build to the live folder, keeping the server's own files:
#      backend/.env, backend/storage/ (sessions), php.ini, .user.ini, .well-known
#   4. rebuilds the root .htaccess: the repo's rules + cPanel's PHP handler block
#   5. runs the database migrations
#
# Settings (environment variables): LIVE_DIR, BACKUP_DIR.

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LIVE="${LIVE_DIR:-$HOME/public_html/studentvoice}"
BACKUPS="${BACKUP_DIR:-$HOME/private_backups}"
CPANEL_HTACCESS="$BACKUPS/studentvoice.htaccess.cpanel-orig"
STAMP="$(date +%Y%m%d-%H%M%S)"
BUILD="$(mktemp -d "${TMPDIR:-/tmp}/studentvoice-build.XXXXXX")"
trap 'rm -rf "$BUILD"' EXIT

say() { printf '\n== %s\n' "$*"; }

[ -f "$LIVE/backend/.env" ] || { echo "No $LIVE/backend/.env: set up the live site first (see README, Deploying)."; exit 1; }
[ -f "$CPANEL_HTACCESS" ] || { echo "Missing $CPANEL_HTACCESS (cPanel's PHP handler block)."; exit 1; }
mkdir -p "$BACKUPS"

say "Building the frontend ($(git -C "$REPO" log -1 --format='%h %s' 2>/dev/null || echo 'unknown commit'))"
cd "$REPO/frontend"
npm ci --no-audit --no-fund --loglevel=error
npx vite build --base=/ --outDir "$BUILD/dist" --emptyOutDir --logLevel warn
[ -f "$BUILD/dist/index.html" ] || { echo "The build made no index.html; live was not changed."; exit 1; }

say "Backing up the database"
env_get() { grep -E "^$1=" "$LIVE/backend/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_NAME="$(env_get DB_NAME)"
MYSQL_PWD="$(env_get DB_PASS)" mysqldump --single-transaction --no-tablespaces -h "$(env_get DB_HOST)" -u "$(env_get DB_USER)" "$DB_NAME" \
  | gzip > "$BACKUPS/studentvoice-db-$STAMP.sql.gz"
chmod 600 "$BACKUPS/studentvoice-db-$STAMP.sql.gz"
echo "$BACKUPS/studentvoice-db-$STAMP.sql.gz"

say "Copying to $LIVE"
rsync -a --delete --exclude='/.env' --exclude='/storage/' "$REPO/backend/" "$LIVE/backend/"
mkdir -p "$LIVE/backend/storage/sessions"
chmod 700 "$LIVE/backend/storage" "$LIVE/backend/storage/sessions"
chmod 600 "$LIVE/backend/.env"
mkdir -p "$LIVE/frontend"
rsync -a --delete "$BUILD/dist/" "$LIVE/frontend/dist/"
cp "$REPO/README.md" "$LIVE/README.md"
cat "$REPO/.htaccess" "$CPANEL_HTACCESS" > "$LIVE/.htaccess.new"
mv "$LIVE/.htaccess.new" "$LIVE/.htaccess"
find "$LIVE/backend" "$LIVE/frontend" -path "$LIVE/backend/storage" -prune -o -type d -exec chmod 755 {} +
find "$LIVE/backend" "$LIVE/frontend" -path "$LIVE/backend/storage" -prune -o -type f ! -name .env -exec chmod 644 {} +
chmod 644 "$LIVE/.htaccess" "$LIVE/README.md"

say "Database migrations"
php "$LIVE/backend/bin/migrate.php"

say "Done: https://studentvoice.stmark.sc.ug"
