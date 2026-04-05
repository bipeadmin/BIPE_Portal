#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${1:-/var/www/html}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"
APACHE_SERVICE="${APACHE_SERVICE:-apache2}"
PHP_BIN="${PHP_BIN:-php}"

log() {
  printf '[portal-refresh] %s\n' "$1"
}

if [[ ${EUID} -ne 0 ]]; then
  log 'Run this script with sudo.'
  exit 1
fi

if [[ ! -d "$APP_DIR" ]]; then
  log "Project directory not found: $APP_DIR"
  exit 1
fi

cd "$APP_DIR"

if [[ -d .git ]]; then
  if git diff --quiet && git diff --cached --quiet; then
    log 'Pulling latest repository changes...'
    git pull --ff-only
  else
    log 'Git working tree has local changes, so git pull was skipped.'
  fi
else
  log 'No .git directory found, skipping git pull.'
fi

log 'Removing UTF-8 BOM from PHP files if present...'
while IFS= read -r -d '' file; do
  perl -i -pe 's/^\x{FEFF}//' "$file"
done < <(find . -type f -name '*.php' -print0)

log 'Ensuring runtime directories exist...'
mkdir -p storage/logs storage/cache
mkdir -p assets/uploads/profiles/admins
mkdir -p assets/uploads/profiles/teachers
mkdir -p assets/uploads/profiles/students

log 'Applying Apache ownership and writable permissions...'
chown -R "$WEB_USER:$WEB_GROUP" storage assets/uploads
find storage assets/uploads -type d -exec chmod 775 {} +
find storage assets/uploads -type f -exec chmod 664 {} +

log 'Running PHP syntax checks...'
for file in config/config.php app/bootstrap.php faculty/records.php faculty/students.php; do
  "$PHP_BIN" -l "$file"
done

log 'Restarting Apache...'
systemctl restart "$APACHE_SERVICE"

log 'Checking local HTTP response...'
curl -I http://127.0.0.1/ || true

PRIMARY_IP="$(hostname -I | awk '{print $1}')"
if [[ -n "$PRIMARY_IP" ]]; then
  log "Open the portal at: http://$PRIMARY_IP/"
fi

log 'Portal refresh completed.'
