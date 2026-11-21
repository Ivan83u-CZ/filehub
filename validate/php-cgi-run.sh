#!/usr/bin/env bash
set -euo pipefail

if command -v php-cgi >/dev/null 2>&1; then
  exec php-cgi "$@"
fi

BASE="${FILEHUB_CONTAINERD_SNAPSHOTS:-/var/lib/containerd/io.containerd.snapshotter.v1.overlayfs/snapshots}"
PHP_BIN="$(find "$BASE" -maxdepth 7 -type f -path '*/fs/usr/local/bin/php-cgi' -perm -u+x -print -quit 2>/dev/null || true)"
LOADER="$(find "$BASE" -maxdepth 5 -type f -path '*/fs/lib/ld-musl-x86_64.so.1' -print -quit 2>/dev/null || true)"
if [[ -z "$PHP_BIN" || -z "$LOADER" ]]; then
  echo 'PHP CGI unavailable: no host binary and no readable containerd snapshot' >&2
  exit 127
fi

LIBS="$(find "$BASE" -maxdepth 7 -type d \( -path '*/fs/lib' -o -path '*/fs/usr/lib' -o -path '*/fs/usr/local/lib' \) -printf '%p:' 2>/dev/null || true)"
exec "$LOADER" --library-path "$LIBS" "$PHP_BIN" -n "$@"
