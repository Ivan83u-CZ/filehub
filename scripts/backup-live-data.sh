#!/usr/bin/env bash
set -euo pipefail

BASE="${FILEHUB_BASE_DIR:-/opt/filehub-unified-rc02}"
OUT="${OUT:-/tmp/filehub-artifacts}"
JTI_VOLUME="${JTI_VOLUME:-/var/lib/docker/volumes/filehub-unified-rc02_filehub_jti/_data}"
STAMP="${STAMP:-$(date -u +%Y%m%dT%H%M%SZ)}"

if [[ $EUID -ne 0 ]]; then
  echo 'ERROR: run as root (sudo).' >&2
  exit 1
fi
for p in "$BASE/state/ops10" "$BASE/state/upload_tmp" "$BASE/.env" "$JTI_VOLUME"; do
  [[ -e "$p" ]] || { echo "ERROR: missing $p" >&2; exit 1; }
done

install -d -m 0700 "$OUT"
umask 077

echo "backup source: $BASE"
echo "backup output: $OUT"
echo "timestamp:     $STAMP"

tar --numeric-owner -C "$BASE" -czf "$OUT/filehub-state-$STAMP.tar.gz" state
tar --numeric-owner -C "$BASE" -czf "$OUT/filehub-secrets-$STAMP.tar.gz" \
  .env pki/server.key pki/server.crt
tar --numeric-owner -C "$JTI_VOLUME" -czf "$OUT/filehub-jti-$STAMP.tar.gz" .

chmod 600 "$OUT"/filehub-state-"$STAMP".tar.gz \
  "$OUT"/filehub-secrets-"$STAMP".tar.gz \
  "$OUT"/filehub-jti-"$STAMP".tar.gz

(
  cd "$OUT"
  sha256sum filehub-state-"$STAMP".tar.gz \
    filehub-secrets-"$STAMP".tar.gz \
    filehub-jti-"$STAMP".tar.gz > "filehub-data-$STAMP.sha256"
  chmod 600 "filehub-data-$STAMP.sha256"
)

echo 'created:'
ls -lh "$OUT"/filehub-{state,secrets,jti}-"$STAMP".tar.gz "$OUT"/filehub-data-"$STAMP".sha256
echo
echo 'SQL DB is not configured for FileHub; the state and JTI archives are the database equivalent.'

