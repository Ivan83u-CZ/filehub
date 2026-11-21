#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="${HERE}/.."
BASE="${FILEHUB_BASE_DIR:-/opt/filehub-unified-rc02}"
EDGE_CONF="${FILEHUB_EDGE_CONF:-/etc/nginx/sites-available/filehub.ivan83u.ru.conf}"
SNIPPET="${FILEHUB_PROXY_SNIPPET:-/etc/nginx/snippets/filehub-proxy.inc}"
ACCESS_LOG="${FILEHUB_ACCESS_LOG:-/var/log/nginx/filehub_access.log}"
WIDGET_DIR="${FILEHUB_WIDGET_DIR:-$BASE/widget}"
REQUIRE_FPM_SCALE="${REQUIRE_FPM_SCALE:-0}"
CONTAINER="${FILEHUB_PHP_CONTAINER:-filehub-unified-rc02-php-1}"
CONTAINER_CONF="${FILEHUB_FPM_CONTAINER_CONF:-/usr/local/etc/php-fpm.d/zz-filehub.conf}"
PROPOSED_FPM="$ROOT/deploy/php-www.proposed.conf"
fail=0
say() { printf '%-5s %s\n' "$1" "$2"; }
pool_summary() {
  awk '/^\[/{p=substr($0,2,length($0)-2); next}
       p!="" && /^pm.max_children[[:space:]]*=/{printf "%s=%s ",p,$3}' "$1" 2>/dev/null || true
}

for f in api.php fh12_prod.php fh_client_events.php fh_session_refresh.php; do
  src="$ROOT/backend/app/$f"; dst="$BASE/app/$f"
  if [[ ! -f "$dst" ]]; then say FAIL "$f missing: $dst"; fail=1; continue; fi
  want=$(sha256sum "$src" | awk '{print $1}'); got=$(sha256sum "$dst" | awk '{print $1}')
  mode=$(stat -c '%a %U:%G' "$dst")
  if [[ "$want" == "$got" && "$mode" == '644 root:root' ]]; then
    say OK "$f hash+mode"
  else
    say FAIL "$f live=$got want=$want mode=$mode"; fail=1
  fi
done

if [[ -f "$EDGE_CONF" ]]; then
  if grep -q 'zone=filehub_auth:1m   rate=10r/s' "$EDGE_CONF" \
     && grep -q 'limit_req zone=filehub_auth burst=30 nodelay' "$EDGE_CONF" \
     && grep -q 'add_header Retry-After 5 always' "$EDGE_CONF" \
     && ! grep -q 'zone=filehub_public' "$EDGE_CONF"; then
    say OK 'edge limits and Retry-After'
  else
    say FAIL 'edge config still has old rate-limit/Retry-After settings'; fail=1
  fi
  if grep -q 'upstream_status=\$upstream_status' "$EDGE_CONF"; then
    say OK 'edge upstream logging'
  else
    say FAIL 'edge upstream logging missing'; fail=1
  fi
else
  say FAIL "edge config missing: $EDGE_CONF"; fail=1
fi

if [[ -f "$SNIPPET" ]] && cmp -s "$SNIPPET" "$ROOT/deploy/filehub-proxy.inc"; then
  say OK 'edge proxy snippet'
else
  say FAIL "edge proxy snippet missing or different: $SNIPPET"; fail=1
fi

if [[ -f "$ACCESS_LOG" ]]; then say OK "access log: $ACCESS_LOG"; else say WARN "access log not created yet: $ACCESS_LOG"; fi

if [[ -f "$BASE/php/www.conf" ]] && cmp -s "$BASE/php/www.conf" "$PROPOSED_FPM"; then
  say OK "host FPM config matches proposed ($(pool_summary "$PROPOSED_FPM"))"
elif [[ "$REQUIRE_FPM_SCALE" == '1' ]]; then
  say FAIL "host FPM config differs: $(pool_summary "$BASE/php/www.conf")want $(pool_summary "$PROPOSED_FPM")"; fail=1
else
  say WARN "host FPM config not applied: $(pool_summary "$BASE/php/www.conf")"
fi

if command -v docker >/dev/null 2>&1 && docker inspect "$CONTAINER" >/dev/null 2>&1; then
  for f in api.php fh12_prod.php fh_client_events.php fh_session_refresh.php; do
    if docker exec -u "${FILEHUB_PHP_USER:-www-data}" "$CONTAINER" \
      sh -lc "test -r '/var/www/html/$f'"; then
      say OK "container readable: $f"
    else
      say FAIL "container cannot read: $f"; fail=1
    fi
  done
  if docker exec "$CONTAINER" cat "$CONTAINER_CONF" 2>/dev/null | cmp -s - "$PROPOSED_FPM"; then
    say OK "container sees proposed FPM pools ($(pool_summary "$PROPOSED_FPM"))"
  elif [[ "$REQUIRE_FPM_SCALE" == '1' ]]; then
    say FAIL 'container still sees a different FPM config'; fail=1
  else
    say WARN 'container FPM config differs from proposed'
  fi
else
  if [[ "$REQUIRE_FPM_SCALE" == '1' ]]; then
    say FAIL 'docker unavailable; container FPM config cannot be verified'; fail=1
  else
    say WARN 'docker unavailable; container readability and FPM config not checked'
  fi
fi

ver=$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["widget"]["version"])' "$WIDGET_DIR/manifest.json" 2>/dev/null || true)
if [[ "$ver" == '2.8.21' ]] && cmp -s "$WIDGET_DIR/script.js" "$ROOT/widget/script.js"; then
  say OK 'local widget source 2.8.21'
else
  say WARN "local widget is ${ver:-missing}; amoCRM-installed version must be checked in the widget UI"
fi

exit "$fail"
