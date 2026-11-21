#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APPLY="$ROOT/deploy/apply-fpm.sh"
LIVE="$ROOT/deploy/php-www.live.conf"

if [[ $EUID -ne 0 ]]; then
  echo 'SKIP: fpm apply test needs root because apply-fpm.sh refuses non-root'
  exit 0
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/bin" "$TMP/run"

cat > "$TMP/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
sub="${1:-}"
shift || true
case "$sub" in
  inspect)
    exit 0
    ;;
  stats)
    echo 'container=mock-php mem=26MiB / 192MiB pct=14% cpu=0.1%'
    ;;
  exec)
    printf 'exec %s\n' "$*" >> "$MOCK_STATE/calls"
    case "$*" in
      *'/proc/meminfo'*) printf 'MemTotal: 196608 kB\nMemAvailable: 150000 kB\n' ;;
      *' cat '*)
        if [[ "$(cat "$MOCK_STATE/stale")" == '0' ]]; then
          cat "$MOCK_HOST_CONF"
        else
          cat "$MOCK_CONTAINER_CONF"
        fi
        ;;
      *'php-fpm -t'*) echo 'configuration file test is successful' ;;
      *) : ;;
    esac
    ;;
  kill)
    printf 'kill %s\n' "$*" >> "$MOCK_STATE/calls"
    ;;
  restart)
    printf 'restart %s\n' "$*" >> "$MOCK_STATE/calls"
    if [[ -f "$MOCK_STATE/restart_sync" ]]; then
      echo 0 > "$MOCK_STATE/stale"
    fi
    ;;
  *)
    echo "mock docker: unsupported subcommand: $sub" >&2
    exit 2
    ;;
esac
MOCK
chmod +x "$TMP/bin/docker"

reset_case() {
  rm -rf "$TMP/run"
  mkdir -p "$TMP/run/state" "$TMP/run/backup"
  cp "$LIVE" "$TMP/run/host.conf"
  cp "$LIVE" "$TMP/run/container.conf"
  : > "$TMP/run/state/calls"
  echo 0 > "$TMP/run/state/stale"
  export MOCK_STATE="$TMP/run/state"
  export MOCK_HOST_CONF="$TMP/run/host.conf"
  export MOCK_CONTAINER_CONF="$TMP/run/container.conf"
}

run_apply() {
  local extra_env=("$@")
  set +e
  env PATH="$TMP/bin:$PATH" \
    FILEHUB_FPM_CONF="$TMP/run/host.conf" \
    FILEHUB_FPM_BACKUP="$TMP/run/backup" \
    APPLY=1 FPM_SCALE_APPROVED=1 "${extra_env[@]}" \
    bash "$APPLY" >"$TMP/run/out" 2>"$TMP/run/err"
  local rc=$?
  set -e
  printf '%s' "$rc"
}

pool_workers() {
  awk -v p="[$2]" '$0==p{f=1;next} /^\[/{f=0} f&&/^pm.max_children/{print $3;exit}' "$1"
}

inode_of() { stat -c '%i' "$1"; }

use_proposed_host() {
  cp "$ROOT/deploy/php-www.proposed.conf" "$TMP/run/host.conf"
}

[[ "$(pool_workers "$ROOT/deploy/php-www.proposed.conf" www)" == '8' ]] || {
  echo 'FAIL: proposed www pool is not 8' >&2; exit 1;
}
[[ "$(pool_workers "$ROOT/deploy/php-www.proposed.conf" filehub_media)" == '4' ]] || {
  echo 'FAIL: proposed media pool is not 4' >&2; exit 1;
}
[[ "$(pool_workers "$ROOT/deploy/php-www.proposed.conf" filehub_auth)" == '2' ]] || {
  echo 'FAIL: proposed auth pool is not 2' >&2; exit 1;
}

# 1. In-place write keeps the inode and reloads without a restart.
reset_case
before_inode=$(inode_of "$TMP/run/host.conf")
rc=$(run_apply)
[[ "$rc" == '0' ]] || { cat "$TMP/run/err" >&2; echo 'FAIL: normal apply rc' >&2; exit 1; }
[[ "$(inode_of "$TMP/run/host.conf")" == "$before_inode" ]] || { echo 'FAIL: inode changed' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" www)" == '8' ]] || { echo 'FAIL: host www workers != 8' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" filehub_media)" == '4' ]] || { echo 'FAIL: host media workers != 4' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" filehub_auth)" == '2' ]] || { echo 'FAIL: host auth workers != 2' >&2; exit 1; }
if grep -q '^restart ' "$TMP/run/state/calls"; then echo 'FAIL: unexpected restart' >&2; exit 1; fi
if ! grep -q '^kill .*USR2' "$TMP/run/state/calls"; then echo 'FAIL: USR2 not sent' >&2; exit 1; fi

# 2. Stale mount without approval fails closed and restores the host file.
reset_case
echo 1 > "$TMP/run/state/stale"
before_inode=$(inode_of "$TMP/run/host.conf")
rc=$(run_apply)
[[ "$rc" == '1' ]] || { echo "FAIL: stale apply rc=$rc, want 1" >&2; exit 1; }
[[ "$(inode_of "$TMP/run/host.conf")" == "$before_inode" ]] || { echo 'FAIL: restore changed inode' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" www)" == '2' ]] || { echo 'FAIL: host www not restored' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" filehub_media)" == '2' ]] || { echo 'FAIL: host media not restored' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" filehub_auth)" == '1' ]] || { echo 'FAIL: host auth not restored' >&2; exit 1; }
if grep -q '^restart ' "$TMP/run/state/calls"; then echo 'FAIL: restart without approval' >&2; exit 1; fi
grep -q 'FPM_RESTART_ON_STALE=1' "$TMP/run/err" || { echo 'FAIL: missing recovery instruction' >&2; exit 1; }

# 3. Stale mount with approval restarts, re-resolves and verifies workers=8.
reset_case
echo 1 > "$TMP/run/state/stale"
touch "$TMP/run/state/restart_sync"
before_inode=$(inode_of "$TMP/run/host.conf")
rc=$(run_apply FPM_RESTART_ON_STALE=1)
[[ "$rc" == '0' ]] || { cat "$TMP/run/err" >&2; echo 'FAIL: stale recovery rc' >&2; exit 1; }
[[ "$(inode_of "$TMP/run/host.conf")" == "$before_inode" ]] || { echo 'FAIL: recovery changed inode' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" www)" == '8' ]] || { echo 'FAIL: host www workers != 8 after recovery' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" filehub_media)" == '4' ]] || { echo 'FAIL: host media workers != 4 after recovery' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" filehub_auth)" == '2' ]] || { echo 'FAIL: host auth workers != 2 after recovery' >&2; exit 1; }
[[ "$(cat "$TMP/run/state/stale")" == '0' ]] || { echo 'FAIL: mock mount still stale' >&2; exit 1; }
grep -q '^restart ' "$TMP/run/state/calls" || { echo 'FAIL: container not restarted' >&2; exit 1; }

# 4. A restart that does not fix the mount restores the old host config.
reset_case
echo 1 > "$TMP/run/state/stale"
rc=$(run_apply FPM_RESTART_ON_STALE=1)
[[ "$rc" == '1' ]] || { echo "FAIL: unrecovered stale rc=$rc, want 1" >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" filehub_media)" == '2' ]] || { echo 'FAIL: unrecovered stale host not restored' >&2; exit 1; }
grep -q '^restart ' "$TMP/run/state/calls" || { echo 'FAIL: recovery restart missing' >&2; exit 1; }
grep -q -- '--force-recreate php' "$TMP/run/err" || { echo 'FAIL: missing recreate instruction' >&2; exit 1; }

# 5. Live-like state: host already has 8, container still sees 2. A failed
#    recovery must restore the config the container was actually running (2).
reset_case
use_proposed_host
echo 1 > "$TMP/run/state/stale"
before_inode=$(inode_of "$TMP/run/host.conf")
rc=$(run_apply FPM_RESTART_ON_STALE=1)
[[ "$rc" == '1' ]] || { echo "FAIL: live-like failed recovery rc=$rc, want 1" >&2; exit 1; }
[[ "$(inode_of "$TMP/run/host.conf")" == "$before_inode" ]] || { echo 'FAIL: live-like restore changed inode' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" www)" == '2' ]] || { echo 'FAIL: live-like recovery did not restore running www pool' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" filehub_media)" == '2' ]] || { echo 'FAIL: live-like recovery did not restore running media pool' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" filehub_auth)" == '1' ]] || { echo 'FAIL: live-like recovery did not restore running auth pool' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/backup/container-before.conf" filehub_media)" == '2' ]] || { echo 'FAIL: container-before.conf missing old media pool' >&2; exit 1; }

# 6. Live-like state with a restart that re-resolves the mount succeeds.
reset_case
use_proposed_host
echo 1 > "$TMP/run/state/stale"
touch "$TMP/run/state/restart_sync"
rc=$(run_apply FPM_RESTART_ON_STALE=1)
[[ "$rc" == '0' ]] || { cat "$TMP/run/err" >&2; echo 'FAIL: live-like recovery rc' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" www)" == '8' ]] || { echo 'FAIL: live-like host www workers != 8' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" filehub_media)" == '4' ]] || { echo 'FAIL: live-like host media workers != 4' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" filehub_auth)" == '2' ]] || { echo 'FAIL: live-like host auth workers != 2' >&2; exit 1; }
[[ "$(cat "$TMP/run/state/stale")" == '0' ]] || { echo 'FAIL: live-like mount still stale' >&2; exit 1; }

# 7. No reachable Docker means no verified reload, so apply must fail before writing.
reset_case
mkdir -p "$TMP/bin-fail"
cat > "$TMP/bin-fail/docker" <<'MOCK'
#!/usr/bin/env bash
exit 1
MOCK
chmod +x "$TMP/bin-fail/docker"
before_inode=$(inode_of "$TMP/run/host.conf")
set +e
env PATH="$TMP/bin-fail:/usr/bin:/bin" \
  FILEHUB_FPM_CONF="$TMP/run/host.conf" \
  FILEHUB_FPM_BACKUP="$TMP/run/backup" \
  APPLY=1 FPM_SCALE_APPROVED=1 \
  bash "$APPLY" >"$TMP/run/out" 2>"$TMP/run/err"
rc=$?
set -e
[[ "$rc" == '1' ]] || { echo "FAIL: docker-unavailable rc=$rc, want 1" >&2; exit 1; }
[[ "$(inode_of "$TMP/run/host.conf")" == "$before_inode" ]] || { echo 'FAIL: docker-unavailable changed inode' >&2; exit 1; }
[[ "$(pool_workers "$TMP/run/host.conf" filehub_media)" == '2' ]] || { echo 'FAIL: docker-unavailable changed config' >&2; exit 1; }
grep -q 'cannot reach PHP container' "$TMP/run/err" || { echo 'FAIL: missing docker-unavailable error' >&2; exit 1; }

echo 'PASS: FPM inode preservation and stale-mount recovery'
