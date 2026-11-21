#!/usr/bin/env bash
set -euo pipefail
BASE="${FILEHUB_BASE_DIR:-/opt/filehub-unified-rc02}"
SRC="$(cd "$(dirname "$0")/../widget" && pwd)"
DST="$BASE/widget"
APPLY="${APPLY:-0}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="$BASE/backups/widget-$STAMP"

if [[ $EUID -ne 0 ]]; then echo 'run as root (sudo)' >&2; exit 1; fi
if [[ ! -f "$SRC/script.js" ]]; then echo "source missing: $SRC/script.js" >&2; exit 1; fi

echo "widget source: $SRC"
echo "widget target: $DST"
echo "script sha256: $(sha256sum "$SRC/script.js" | awk '{print $1}')"
if [[ "$APPLY" != "1" ]]; then
  echo 'DRY RUN: set APPLY=1 to install. No files changed.'
  exit 0
fi

install -d -m 0755 "$BACKUP"
if [[ -d "$DST" ]]; then cp -a "$DST/." "$BACKUP/"; fi
printf 'backup: %s\n' "$BACKUP"

install -d -m 0755 "$DST" "$DST/i18n" "$DST/images"
for f in script.js manifest.json style.css; do
  install -m 0644 "$SRC/$f" "$DST/$f"
done
cp -a "$SRC/i18n/." "$DST/i18n/"
cp -a "$SRC/images/." "$DST/images/"
printf 'installed script sha256: %s\n' "$(sha256sum "$DST/script.js" | awk '{print $1}')"
cat > "$BACKUP/RESTORE.txt" <<EOT
Rollback: FILEHUB_BASE_DIR=$BASE $BASE/deploy/rollback-widget.sh $BACKUP
or: cp -a $BACKUP/. $DST/
EOT
