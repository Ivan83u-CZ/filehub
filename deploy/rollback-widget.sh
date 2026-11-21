#!/usr/bin/env bash
set -euo pipefail
BACKUP="${1:?usage: rollback-widget.sh /opt/filehub-unified-rc02/backups/widget-YYYY...}"
DST="${FILEHUB_WIDGET_DIR:-/opt/filehub-unified-rc02/widget}"
[[ $EUID -eq 0 ]] || { echo 'run as root (sudo)' >&2; exit 1; }
[[ -d "$BACKUP" ]] || { echo "backup not found: $BACKUP" >&2; exit 1; }
cp -a "$BACKUP/." "$DST/"
echo "widget restored from $BACKUP"
echo "rollback script sha256: $(sha256sum "$DST/script.js" | awk '{print $1}')"
