#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-/opt/filehub-unified-rc02}"
EDGE_CONF="${EDGE_CONF:-/etc/nginx/sites-available/filehub.ivan83u.ru.conf}"
PUBLIC_BASE="${FILEHUB_BASE:-https://filehub.ivan83u.ru/wp-json/czfh/v1}"
fail=0
say() { printf '%-5s %s\n' "$1" "$2"; }

say INFO "FileHub containment checks; this script makes no changes"
say INFO "root=$ROOT"

if [[ -f "$ROOT/widget/script.js" ]]; then
  widget_sha=$(sha256sum "$ROOT/widget/script.js" | awk '{print $1}')
  widget_ver=$(grep -o "widget_version:'[0-9.]*'" "$ROOT/widget/script.js" | head -1 || true)
  say OK "widget source sha256=$widget_sha ${widget_ver}"
else
  say WARN "widget source not found at $ROOT/widget/script.js"
fi

if [[ -f "$ROOT/app/fh12_prod.php" ]]; then
  say OK "backend fh12 sha256=$(sha256sum "$ROOT/app/fh12_prod.php" | awk '{print $1}')"
else
  say FAIL "backend fh12_prod.php missing"; fail=1
fi

if [[ -f "$EDGE_CONF" ]]; then
  if grep -q 'access_log off' "$EDGE_CONF"; then
    say WARN "edge access_log is off; 429 attribution will remain blind"
  else
    say OK "edge access log is configured"
  fi
  if grep -q 'limit_req zone=filehub_public' "$EDGE_CONF"; then
    say WARN "single 15 r/s zone still serves every endpoint, including OPTIONS"
  fi
else
  say FAIL "edge config not found: $EDGE_CONF"; fail=1
fi

if command -v curl >/dev/null 2>&1; then
  headers=$(curl -sS -m 10 -o /dev/null -D - -X OPTIONS "$PUBLIC_BASE/files" \
    -H 'Origin: https://chinazub.amocrm.ru' \
    -H 'Access-Control-Request-Method: GET' \
    -H 'Access-Control-Request-Headers: x-fh-session' 2>/dev/null || true)
  code=$(printf '%s\n' "$headers" | awk 'toupper($1) ~ /^HTTP\// {c=$2} END {print c}')
  if [[ "$code" == "429" ]]; then
    say WARN "CORS preflight currently receives 429; edge apply is expected to fix it"
  elif [[ -n "$code" ]]; then
    say OK "CORS preflight status=$code"
  else
    say WARN "CORS preflight could not be checked from this host"
  fi
fi

if command -v docker >/dev/null 2>&1; then
  if docker inspect filehub-unified-rc02-php-1 >/dev/null 2>&1; then
    running=$(docker exec filehub-unified-rc02-php-1 sh -lc "ps -eo args | grep '[p]hp-fpm' | wc -l" 2>/dev/null || true)
    say INFO "php-fpm processes in container: ${running:-unknown}"
  else
    say WARN "php container not found by expected name"
  fi
  for name in filehub-r2-staging-gateway-1 filehub-unified-rc02-gateway-1; do
    if docker inspect "$name" >/dev/null 2>&1; then
      restarts=$(docker inspect -f '{{.RestartCount}}' "$name")
      if (( restarts > 10 )) && [[ "$name" == 'filehub-unified-rc02-gateway-1' ]]; then
        say FAIL "$name restart count=$restarts"; fail=1
      elif (( restarts > 10 )); then
        say WARN "$name restart count=$restarts (staging only; does not block production rollout)"
      else
        say OK "$name restart count=$restarts"
      fi
    fi
  done
fi

if [[ -r "$ROOT/pki/server.crt" ]]; then
  end=$(openssl x509 -in "$ROOT/pki/server.crt" -noout -enddate 2>/dev/null | cut -d= -f2 || true)
  if [[ -n "$end" ]] && ! openssl x509 -in "$ROOT/pki/server.crt" -checkend 0 >/dev/null 2>&1; then
    say WARN "internal certificate expired: $end"
  else
    say OK "internal certificate end=$end"
  fi
fi

if [[ -r /var/log/auth.log ]]; then
  since=$(date -u -d '24 hours ago' '+%b %e %H:%M:%S')
  invalid=$(journalctl --since '24 hours ago' -u ssh --no-pager 2>/dev/null | grep -c 'Failed password' || true)
  accepted=$(journalctl --since '24 hours ago' -u ssh --no-pager 2>/dev/null | grep -c 'Accepted' || true)
  say INFO "SSH last 24h: failed=${invalid:-0} accepted=${accepted:-0}"
fi
if command -v fail2ban-client >/dev/null 2>&1; then
  say OK "fail2ban is installed"
else
  say WARN "fail2ban is not installed"
fi

exit "$fail"
