#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
APPLY="${APPLY:-0}"
JAIL_SRC="$HERE/fail2ban-sshd.local"
JAIL_DST="${FAIL2BAN_DST:-/etc/fail2ban/jail.d/sshd-filehub.local}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="${FAIL2BAN_BACKUP:-/var/backups/filehub/fail2ban-$STAMP}"

die() { echo "ERROR: $*" >&2; exit 1; }
say() { printf '%s\n' "$*"; }

wait_ready() {
  local i
  for i in $(seq 1 20); do
    if fail2ban-client ping >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  return 1
}

if [[ $EUID -ne 0 ]]; then
  die 'run as root on the host, not inside the restricted Codex sandbox'
fi

say "fail2ban jail: $JAIL_SRC -> $JAIL_DST"
say 'office IP is dynamic: SSH is not IP-restricted and no IP gets a permanent fail2ban exemption'
if [[ "$APPLY" != '1' ]]; then
  say 'DRY RUN: set APPLY=1 to install and configure fail2ban.'
  exit 0
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y fail2ban

command -v fail2ban-client >/dev/null 2>&1 || die 'fail2ban-client is missing after install'
command -v ufw >/dev/null 2>&1 || die 'ufw is missing'
[[ -r /etc/fail2ban/action.d/ufw.conf ]] || die 'fail2ban ufw action is missing'

ufw status | grep -q '^Status: active' || die 'UFW is not active; refusing to install a ufw-based jail'

install -d -m 0750 "$BACKUP"
if [[ -e "$JAIL_DST" ]]; then
  cp -a "$JAIL_DST" "$BACKUP/sshd-filehub.local"
fi
install -d -m 0755 "$(dirname "$JAIL_DST")"
install -m 0644 "$JAIL_SRC" "$JAIL_DST"

if ! fail2ban-client -t; then
  if [[ -e "$BACKUP/sshd-filehub.local" ]]; then
    cp -a "$BACKUP/sshd-filehub.local" "$JAIL_DST"
  else
    rm -f "$JAIL_DST"
  fi
  die 'fail2ban configuration test failed; jail file restored'
fi

systemctl enable fail2ban
if ! systemctl restart fail2ban; then
  if [[ -e "$BACKUP/sshd-filehub.local" ]]; then
    cp -a "$BACKUP/sshd-filehub.local" "$JAIL_DST"
  else
    rm -f "$JAIL_DST"
  fi
  systemctl restart fail2ban >/dev/null 2>&1 || true
  die 'fail2ban failed to restart; jail file restored'
fi

if ! wait_ready; then
  systemctl status fail2ban --no-pager -n 30 || true
  die 'fail2ban did not become ready within 20 seconds; config was left in place for inspection'
fi
fail2ban-client status
fail2ban-client status sshd
say "fail2ban installed and enabled; backup=$BACKUP"
say "verify journal matching: journalctl -u fail2ban -n 50 --no-pager"
