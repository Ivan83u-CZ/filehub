#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
APPLY="${APPLY:-0}"

if [[ $EUID -ne 0 ]]; then echo 'run as root (sudo)' >&2; exit 1; fi

if [[ "$APPLY" != "1" ]]; then
  cat <<'EOT'
DRY RUN: set APPLY=1 to execute. Order:
  1. check-stage0.sh (read-only preflight)
  2. apply-backend.sh (backup, 0644, docker readability, php -l)
  3. apply-nginx.sh (backup, nginx -t, reload)
  4. optional apply-fpm.sh (requires FPM_SCALE_APPROVED=1)
     If the PHP container is bound to an old www.conf inode, also set
     FPM_RESTART_ON_STALE=1 to restart it and re-resolve the mount.

SSH and certificate changes are intentionally excluded.
Widget installation in amoCRM is separate and must use dist/FileHub_Widget_2.8.21.zip.
EOT
  exit 0
fi


if ! "$HERE/check-stage0.sh"; then
  echo 'PREFLIGHT FAILED: no backend/nginx/FPM files were changed.' >&2
  echo 'Review the FAIL lines above before continuing.' >&2
  if [[ "${ROLLOUT_IGNORE_PREFLIGHT:-0}" != "1" ]]; then
    echo 'Stopping. Rerun with ROLLOUT_IGNORE_PREFLIGHT=1 only after reviewing the warnings.' >&2
    exit 1
  fi
fi
"$HERE/apply-backend.sh"
"$HERE/apply-nginx.sh"

if [[ "${FPM_SCALE_APPROVED:-0}" == "1" ]]; then
  FPM_SCALE_APPROVED=1 FPM_RESTART_ON_STALE="${FPM_RESTART_ON_STALE:-0}" "$HERE/apply-fpm.sh"
else
  echo 'FPM scale skipped: set FPM_SCALE_APPROVED=1 after reviewing memory.'
fi

echo 'Backend and edge applied. Verify live hashes and the 429 rate before canary.'
echo 'Canary: install dist/FileHub_Widget_2.8.21.zip in amoCRM for one operator.'
REQUIRE_FPM_SCALE="$FPM_SCALE_APPROVED" "$HERE/verify-live.sh"
