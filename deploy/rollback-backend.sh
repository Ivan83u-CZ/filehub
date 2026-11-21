#!/usr/bin/env bash
set -euo pipefail
BACKUP="${1:?usage: rollback-backend.sh /opt/filehub-unified-rc02/backups/backend-YYYY...}"
DST="${FILEHUB_APP_DIR:-/opt/filehub-unified-rc02/app}"
[[ $EUID -eq 0 ]] || { echo 'run as root (sudo)' >&2; exit 1; }
[[ -d "$BACKUP" ]] || { echo "backup not found: $BACKUP" >&2; exit 1; }
for f in fh12_prod.php fh_client_events.php fh_session_refresh.php api.php; do
  [[ -f "$BACKUP/$f" ]] && cp -a "$BACKUP/$f" "$DST/$f"
done
echo "backend restored from $BACKUP"
