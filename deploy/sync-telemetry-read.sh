#!/usr/bin/env bash
set -euo pipefail

SRC="${FILEHUB_TELEMETRY_DIR:-/opt/filehub-unified-rc02/state/ops10/telemetry}"
DEST="${FILEHUB_TELEMETRY_SNAPSHOT:-/tmp/filehub-telemetry-read}"
DAYS=2

while [[ $# -gt 0 ]]; do
  case "$1" in
    --days) DAYS="${2:-}"; shift 2 ;;
    --dest) DEST="${2:-}"; shift 2 ;;
    --src) SRC="${2:-}"; shift 2 ;;
    -h|--help)
      echo "usage: sudo $0 [--days N] [--dest DIR] [--src DIR]"
      echo "  N=2 (default): today and yesterday UTC; N=0: all raw files"
      exit 0
      ;;
    *) echo "unknown argument: $1" >&2; exit 64 ;;
  esac
done

if [[ $EUID -ne 0 ]]; then
  echo 'ERROR: run as root (sudo).' >&2
  exit 1
fi
if [[ ! "$DAYS" =~ ^[0-9]+$ ]]; then
  echo 'ERROR: --days must be a non-negative integer.' >&2
  exit 64
fi
if [[ ! -d "$SRC/raw" ]]; then
  echo "ERROR: telemetry raw directory not found: $SRC/raw" >&2
  exit 1
fi

install -d -m 0700 "$DEST" "$DEST/raw" "$DEST/agg"
copied=0

copy_day() {
  local day="$1"
  local raw="$SRC/raw/events-$day.jsonl"
  local agg="$SRC/agg/agg-$day.json"
  if [[ -f "$raw" ]]; then
    install -m 0600 "$raw" "$DEST/raw/events-$day.jsonl"
    copied=$((copied + 1))
  fi
  if [[ -f "$agg" ]]; then
    install -m 0600 "$agg" "$DEST/agg/agg-$day.json"
    copied=$((copied + 1))
  fi
}

if [[ "$DAYS" == '0' ]]; then
  while IFS= read -r -d '' f; do
    install -m 0600 "$f" "$DEST/raw/$(basename "$f")"
    copied=$((copied + 1))
  done < <(find "$SRC/raw" -maxdepth 1 -type f -name 'events-*.jsonl' -print0)
  while IFS= read -r -d '' f; do
    install -m 0600 "$f" "$DEST/agg/$(basename "$f")"
    copied=$((copied + 1))
  done < <(find "$SRC/agg" -maxdepth 1 -type f -name 'agg-*.json' -print0)
else
  for ((i=0; i<DAYS; i++)); do
    copy_day "$(date -u -d "-$i day" +%Y-%m-%d)"
  done
  # Keep the snapshot small when it is reused day after day.
  find "$DEST/raw" -maxdepth 1 -type f -name 'events-*.jsonl' -mtime +$((DAYS + 1)) -delete 2>/dev/null || true
  find "$DEST/agg" -maxdepth 1 -type f -name 'agg-*.json' -mtime +$((DAYS + 1)) -delete 2>/dev/null || true
fi

# Never leave a group/world-readable telemetry copy.
chmod -R go-rwx "$DEST"

echo "snapshot: $DEST"
echo "copied:   $copied files"
find "$DEST" -maxdepth 2 -type f -printf '%M %u:%g %s %p\n' | sort
if [[ "$copied" -eq 0 ]]; then
  echo 'WARN: no telemetry files matched; check the date or use --days 0.' >&2
  exit 3
fi
echo
SELF_DIR="$(cd "$(dirname "$0")" && pwd)"
echo "next: python3 $SELF_DIR/inspect-telemetry.py --dir $DEST"
