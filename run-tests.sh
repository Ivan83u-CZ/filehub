#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
node --check widget/script.js
for t in \
  widget/session_guard_test.js \
  widget/session_recovery_test.js \
  widget/session_fix_test.js \
  widget/telemetry_recovery_test.js \
  widget/thumbnail_contract_test.js \
  widget/session_multitab_test.js \
  widget/session_peer_recovery_test.js \
  widget/edge_retry_test.js; do
  echo "== $t =="
  node "$t"
done
python3 -m json.tool widget/manifest.json >/dev/null
python3 -m json.tool widget/i18n/ru.json >/dev/null
python3 -m json.tool widget/i18n/en.json >/dev/null
python3 -m json.tool deploy/lead-acl.example.json >/dev/null
for s in deploy/*.sh; do bash -n "$s"; done
python3 -c 'import ast,sys; ast.parse(open(sys.argv[1], encoding="utf-8").read(), sys.argv[1])' deploy/inspect-telemetry.py
python3 -c 'import ast,sys; ast.parse(open(sys.argv[1], encoding="utf-8").read(), sys.argv[1])' deploy/filehub_alert_check.py
python3 tests/alert_check_test.py
bash tests/fpm_apply_test.sh
node backend/tests/check_acl_static.js
node backend/tests/check_review_fixes_static.js
backend/tests/http_request_id.sh
PHP_RUN=()
if command -v php >/dev/null 2>&1; then
  PHP_RUN=(php)
elif [[ -x validate/php-run.sh ]] && validate/php-run.sh -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' >/dev/null 2>&1; then
  PHP_RUN=(validate/php-run.sh)
fi
if [[ ${#PHP_RUN[@]} -gt 0 ]]; then
  for f in backend/app/fh12_prod.php backend/app/fh_client_events.php backend/app/fh_session_refresh.php backend/app/api.php; do
    "${PHP_RUN[@]}" -l "$f"
  done
  "${PHP_RUN[@]}" backend/tests/unit_lead_acl.php
  "${PHP_RUN[@]}" backend/tests/unit_session_refresh.php
  "${PHP_RUN[@]}" backend/tests/unit_review_fixes.php
else
  echo 'SKIP: PHP unavailable; run php -l and PHP unit tests on the target host'
fi
echo 'PASS: all workspace checks'
