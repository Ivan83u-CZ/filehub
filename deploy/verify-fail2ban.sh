#!/usr/bin/env bash
set -uo pipefail

OUT="${OUT:-/tmp/filehub-fail2ban-check.txt}"
TEST_IP="${TEST_IP:-192.0.2.123}"
failures=0

mkdir -p "$(dirname "$OUT")"
exec > >(tee "$OUT") 2>&1

cleanup() {
  fail2ban-client set sshd unbanip "$TEST_IP" >/dev/null 2>&1 || true
  fail2ban-client unban "$TEST_IP" >/dev/null 2>&1 || true
}
trap cleanup EXIT

run() {
  echo
  echo "### $*"
  "$@"
  local rc=$?
  if [[ $rc -ne 0 ]]; then
    echo "EXIT=$rc"
    failures=$((failures + 1))
  fi
  return 0
}

echo "fail2ban live check: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "report: $OUT"
echo "test IP: $TEST_IP (TEST-NET-1, reserved; temporarily banned and removed)"

run systemctl is-active fail2ban
run systemctl show fail2ban -p ActiveState -p SubState -p MainPID -p ExecMainStatus
run fail2ban-client -t

ready=0
for _ in $(seq 1 20); do
  if fail2ban-client ping >/dev/null 2>&1; then ready=1; break; fi
  sleep 1
done
if [[ $ready -ne 1 ]]; then
  echo 'ERROR: fail2ban socket did not become ready'
  failures=$((failures + 1))
else
  run fail2ban-client ping
  run fail2ban-client status
  run fail2ban-client status sshd
  run fail2ban-client get sshd actions
  run fail2ban-client get sshd journalmatch
  run fail2ban-client get sshd ignoreip
  run fail2ban-client get sshd maxretry
  run fail2ban-client get sshd findtime
  run fail2ban-client get sshd bantime
fi

echo
echo '### UFW status'
ufw status numbered || { echo "EXIT=$?"; failures=$((failures + 1)); }

echo
echo '### temporary firewall action test'
if fail2ban-client set sshd banip "$TEST_IP"; then
  sleep 1
  fail2ban-client get sshd banned
  fail2ban-client banned "$TEST_IP"
  echo '--- ufw rule for test IP ---'
  ufw status numbered | grep -F "$TEST_IP" || { echo 'test IP not visible in ufw status'; failures=$((failures + 1)); }
  fail2ban-client set sshd unbanip "$TEST_IP"
  sleep 1
  echo '--- after unban ---'
  fail2ban-client banned "$TEST_IP" || true
  ufw status numbered | grep -F "$TEST_IP" && { echo 'ERROR: test IP still present after unban'; failures=$((failures + 1)); } || true
else
  echo 'ERROR: could not create test ban'
  failures=$((failures + 1))
fi

echo
echo '### recent fail2ban journal'
journalctl -u fail2ban -n 120 --no-pager || true

echo
echo '### recent fail2ban log'
tail -n 160 /var/log/fail2ban.log || true

echo
echo "RESULT: failures=$failures"
exit "$failures"
