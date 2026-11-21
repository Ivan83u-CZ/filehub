#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CGI="$ROOT/validate/php-cgi-run.sh"
API="$ROOT/backend/app/api.php"

if ! "$CGI" -v >/dev/null 2>&1; then
  echo 'SKIP: PHP CGI unavailable for request-ID integration check'
  exit 0
fi

request() {
  local method="$1" uri="$2" rid="${3:-}"
  if [[ -n "$rid" ]]; then
    env -i PATH=/usr/bin:/bin REDIRECT_STATUS=1 SERVER_PROTOCOL=HTTP/1.1 \
      REQUEST_METHOD="$method" REQUEST_URI="$uri" SCRIPT_FILENAME="$API" \
      HTTP_X_REQUEST_ID="$rid" "$CGI"
  else
    env -i PATH=/usr/bin:/bin REDIRECT_STATUS=1 SERVER_PROTOCOL=HTTP/1.1 \
      REQUEST_METHOD="$method" REQUEST_URI="$uri" SCRIPT_FILENAME="$API" "$CGI"
  fi
}

header() { printf '%s\n' "$1" | awk -F': ' 'tolower($1)=="x-request-id"{gsub("\r","");print $2;exit}'; }
status() { printf '%s\n' "$1" | awk '/^Status:/{gsub("\r","");print $2;exit}'; }

RID=0123456789abcdef0123456789abcdef
out="$(request GET /wp-json/czfh/v1/not-found "$RID")"
[[ "$(status "$out")" == '404' ]] || { echo 'FAIL: expected 404' >&2; exit 1; }
[[ "$(header "$out")" == "$RID" ]] || { echo 'FAIL: incoming request ID not preserved' >&2; exit 1; }

out="$(request POST /wp-json/czfh/v1/ping)"
[[ "$(status "$out")" == '405' ]] || { echo 'FAIL: expected 405' >&2; exit 1; }
[[ "$(header "$out")" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$ ]] || {
  echo 'FAIL: generated request ID is not UUID v4' >&2; exit 1;
}

out="$(request GET /wp-json/czfh/v1/not-found 'bad value')"
[[ "$(header "$out")" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$ ]] || {
  echo 'FAIL: invalid incoming ID was not replaced' >&2; exit 1;
}
echo 'PASS: request-ID preserved, generated and validated'
