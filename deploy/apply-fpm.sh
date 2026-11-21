#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="$HERE/php-www.proposed.conf"
DST="${FILEHUB_FPM_CONF:-/opt/filehub-unified-rc02/php/www.conf}"
APPLY="${APPLY:-0}"
SCALE_APPROVED="${FPM_SCALE_APPROVED:-0}"
RESTART_ON_STALE="${FPM_RESTART_ON_STALE:-0}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="${FILEHUB_FPM_BACKUP:-/opt/filehub-unified-rc02/backups/fpm-$STAMP}"
CONTAINER="${FILEHUB_PHP_CONTAINER:-filehub-unified-rc02-php-1}"
CONTAINER_CONF="${FILEHUB_FPM_CONTAINER_CONF:-/usr/local/etc/php-fpm.d/zz-filehub.conf}"
RESTORE_SRC="$BACKUP/www.conf"

pool_workers_stream() {
  awk '/^\[/{p=substr($0,2,length($0)-2); next}
       p!="" && /^pm.max_children[[:space:]]*=/{print p"="$3}'
}

pool_workers_file() {
  pool_workers_stream < "$1"
}

container_workers() {
  docker exec "$CONTAINER" cat "$CONTAINER_CONF" 2>/dev/null | pool_workers_stream
}

format_pools() {
  tr '\n' ' ' <<< "$1"
}

container_ready() {
  docker exec "$CONTAINER" sh -lc 'exit 0' >/dev/null 2>&1
}

restart_container() {
  echo "restarting $CONTAINER so Docker re-resolves the host bind mount" >&2
  docker restart "$CONTAINER" >/dev/null
  local i
  for i in $(seq 1 30); do
    container_ready && return 0
    sleep 1
  done
  return 1
}

restore_host_config() {
  if [[ -f "$RESTORE_SRC" ]]; then
    cat "$RESTORE_SRC" > "$DST"
    chmod 0644 "$DST"
    cmp -s "$RESTORE_SRC" "$DST" || echo 'WARN: host config restore verification failed' >&2
  fi
}

if [[ $EUID -ne 0 ]]; then echo 'run as root (sudo)' >&2; exit 1; fi
echo "fpm config: $SRC -> $DST"
if command -v docker >/dev/null 2>&1 && docker inspect "$CONTAINER" >/dev/null 2>&1; then
  docker stats --no-stream --format 'container={{.Name}} mem={{.MemUsage}} pct={{.MemPerc}} cpu={{.CPUPerc}}' "$CONTAINER" || true
  docker exec "$CONTAINER" sh -lc "grep -E '^(MemTotal|MemAvailable)' /proc/meminfo" || true
fi
if [[ "$SCALE_APPROVED" != "1" ]]; then
  echo 'Refusing FPM pool scale-up until memory is reviewed.'
  echo 'Review the stats above, then rerun with FPM_SCALE_APPROVED=1 APPLY=1.'
  exit 0
fi
if [[ "$APPLY" != "1" ]]; then
  echo 'DRY RUN: set APPLY=1 to install. No files changed.'
  exit 0
fi
if ! command -v docker >/dev/null 2>&1 || ! docker inspect "$CONTAINER" >/dev/null 2>&1; then
  echo "ERROR: cannot reach PHP container $CONTAINER; refusing to change www.conf without a verified reload." >&2
  exit 1
fi

install -d -m 0750 "$BACKUP"
[[ -f "$DST" ]] && cp -a "$DST" "$BACKUP/www.conf"
if command -v docker >/dev/null 2>&1 && docker inspect "$CONTAINER" >/dev/null 2>&1; then
  docker exec "$CONTAINER" cat "$CONTAINER_CONF" > "$BACKUP/container-before.conf" 2>/dev/null || true
fi
if [[ -f "$DST" ]]; then
  # www.conf is a single-file bind mount. Atomic mv would change the host inode
  # while the container keeps the old one, so php-fpm would reload the old pool.
  before_inode=$(stat -c '%i' "$DST")
  cat "$SRC" > "$DST"
  chmod 0644 "$DST"
  after_inode=$(stat -c '%i' "$DST")
  [[ "$before_inode" == "$after_inode" ]] || {
    echo "fpm config inode changed ($before_inode -> $after_inode); restoring" >&2
    [[ -f "$BACKUP/www.conf" ]] && cp -a "$BACKUP/www.conf" "$DST"
    exit 1
  }
  echo "fpm config inode preserved: $after_inode"
else
  install -m 0644 "$SRC" "$DST"
fi
cmp -s "$SRC" "$DST" || { echo 'fpm config write verification failed' >&2; exit 1; }
if command -v docker >/dev/null 2>&1 && docker inspect "$CONTAINER" >/dev/null 2>&1; then
  restarted=0
  want=$(pool_workers_file "$SRC")
  workers=$(container_workers || true)
  if [[ "$workers" != "$want" ]]; then
    echo "container FPM pools: $(format_pools "${workers:-unknown}")" >&2
    echo "wanted FPM pools:    $(format_pools "$want")" >&2
    [[ -s "$BACKUP/container-before.conf" ]] && RESTORE_SRC="$BACKUP/container-before.conf"
    if [[ "$RESTART_ON_STALE" != '1' ]]; then
      restore_host_config
      echo 'The container is still bound to the old www.conf inode (stale single-file mount).' >&2
      echo 'Host config was restored. Rerun with FPM_RESTART_ON_STALE=1 to let the' >&2
      echo 'script restart the PHP container and re-resolve the bind mount.' >&2
      exit 1
    fi
    if ! restart_container; then
      restore_host_config
      echo 'PHP container did not become ready after restart; host config restored.' >&2
      exit 1
    fi
    restarted=1
    workers=$(container_workers || true)
    if [[ "$workers" != "$want" ]]; then
      restore_host_config
      echo "container still sees $(format_pools "${workers:-unknown}") after restart; host config restored." >&2
      echo 'Recreate the service, for example: docker compose up -d --force-recreate php' >&2
      exit 1
    fi
    echo "container sees $(format_pools "$workers") after restart"
  fi
  if ! docker exec "$CONTAINER" php-fpm -t; then
    restore_host_config
    if [[ "$restarted" == '1' ]]; then
      echo 'restarting the container with the restored config' >&2
      docker restart "$CONTAINER" >/dev/null || true
    fi
    exit 1
  fi
  if [[ "$restarted" == '1' ]]; then
    echo "php-fpm loaded the new config on restart; backup=$BACKUP"
  else
    docker kill --signal=USR2 "$CONTAINER" >/dev/null
    echo "php-fpm reloaded; backup=$BACKUP"
  fi
else
  restore_host_config
  echo 'ERROR: PHP container became unavailable during apply; host config restored.' >&2
  exit 1
fi
