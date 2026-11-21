#!/usr/bin/env bash
set -euo pipefail
BASE="${FILEHUB_BASE_DIR:-/opt/filehub-unified-rc02}"
SRC="$(cd "$(dirname "$0")/../backend/app" && pwd)"
DST="$BASE/app"
APPLY="${APPLY:-0}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="$BASE/backups/backend-$STAMP"
FILES=(fh12_prod.php fh_client_events.php fh_session_refresh.php api.php)
PHP_USER="${FILEHUB_PHP_USER:-www-data}"

if [[ $EUID -ne 0 ]]; then echo 'run as root (sudo)' >&2; exit 1; fi
for f in "${FILES[@]}"; do
  [[ -f "$SRC/$f" ]] || { echo "source missing: $SRC/$f" >&2; exit 1; }
  echo "$f sha256=$(sha256sum "$SRC/$f" | awk '{print $1}')"
done
if [[ "$APPLY" != "1" ]]; then
  echo 'DRY RUN: set APPLY=1 to install. No files changed.'
  echo "Install mode: 0644 root:root; PHP runtime user: $PHP_USER"
  echo 'Keep FILEHUB_LEAD_ACL_MODE=shadow for the first 24 hours.'
  exit 0
fi

install -d -m 0755 "$BACKUP"
for f in "${FILES[@]}"; do
  [[ -f "$DST/$f" ]] && cp -a "$DST/$f" "$BACKUP/$f"
  tmp="$DST/.$f.tmp.$$"
  # The app tree is bind-mounted read-only into PHP-FPM, which runs as
  # www-data. Keep the same world-readable mode as the live files; a
  # root-only 0640 file would make every API request fail with 500.
  install -m 0644 -o root -g root "$SRC/$f" "$tmp"
  mv -f "$tmp" "$DST/$f"
done

container="${FILEHUB_PHP_CONTAINER:-filehub-unified-rc02-php-1}"
if command -v docker >/dev/null 2>&1 && docker inspect "$container" >/dev/null 2>&1; then
  for f in "${FILES[@]}"; do
    if ! docker exec -u "$PHP_USER" "$container" sh -lc "test -r '/var/www/html/$f'"; then
      echo "installed file is not readable by PHP runtime user $PHP_USER; restoring backend files" >&2
      for r in "${FILES[@]}"; do [[ -f "$BACKUP/$r" ]] && cp -a "$BACKUP/$r" "$DST/$r"; done
      exit 1
    fi
    if ! docker exec "$container" php -l "/var/www/html/$f"; then
      echo 'PHP lint failed; restoring backend files' >&2
      for r in "${FILES[@]}"; do [[ -f "$BACKUP/$r" ]] && cp -a "$BACKUP/$r" "$DST/$r"; done
      exit 1
    fi
  done
else
  echo 'WARN: docker/PHP unavailable for php -l; verify on the server before traffic.' >&2
fi
echo "backend installed; backup=$BACKUP"
