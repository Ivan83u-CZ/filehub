#!/usr/bin/env bash
set -euo pipefail

BASE="${FILEHUB_BASE_DIR:-/opt/filehub-unified-rc02}"
APPLY="${APPLY:-0}"
KEEP_BACKUPS="${KEEP_BACKUPS:-3}"

if [[ $EUID -ne 0 ]]; then
  echo 'ERROR: run as root (sudo).' >&2
  exit 1
fi
[[ "$KEEP_BACKUPS" =~ ^[0-9]+$ ]] || { echo 'ERROR: KEEP_BACKUPS must be an integer' >&2; exit 64; }

echo "base: $BASE"
echo "keep latest backup directories: $KEEP_BACKUPS"
echo 'will remove: app/*.before-*, .env.before-*, backup directories beyond the keep count'

if [[ "$APPLY" != '1' ]]; then
  echo 'DRY RUN: set APPLY=1 to archive data first and remove obsolete files.'
  find "$BASE" -maxdepth 1 -type f -name '.env.before-*' -printf '  %p\n'
  find "$BASE/app" -maxdepth 1 -type f -name '*.before-*' -printf '  %p\n'
  find "$BASE/backups" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' 2>/dev/null \
    | sort -nr | tail -n +$((KEEP_BACKUPS + 1)) | cut -d' ' -f2- | sed 's/^/  /'
  exit 0
fi

STAMP="$(date -u +%Y%m%dT%H%M%SZ)" OUT="${OUT:-/tmp/filehub-artifacts}" \
  "$(cd "$(dirname "$0")" && pwd)/backup-live-data.sh"

find "$BASE" -maxdepth 1 -type f -name '.env.before-*' -delete
find "$BASE/app" -maxdepth 1 -type f -name '*.before-*' -delete

mapfile -t OLD_BACKUPS < <(
  find "$BASE/backups" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' 2>/dev/null \
    | sort -nr | tail -n +$((KEEP_BACKUPS + 1)) | cut -d' ' -f2-
)
for path in "${OLD_BACKUPS[@]}"; do
  rm -rf -- "$path"
done

echo "cleanup complete; deleted old backup directories: ${#OLD_BACKUPS[@]}"

