#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
APPLY="${APPLY:-0}"
LIB_DIR="${FILEHUB_ALERT_LIB_DIR:-/usr/local/lib/filehub}"
STATE_DIR="${FILEHUB_ALERT_STATE_DIR:-/var/lib/filehub-alerts}"
LOG_FILE="${FILEHUB_ALERT_LOG:-/var/log/filehub-alerts.log}"
ENV_FILE="${FILEHUB_ALERT_ENV_FILE:-/etc/filehub-alerts.env}"
SERVICE_DST="/etc/systemd/system/filehub-alert-check.service"
TIMER_DST="/etc/systemd/system/filehub-alert-check.timer"
LOGROTATE_DST="/etc/logrotate.d/filehub-alerts"

if [[ $EUID -ne 0 ]]; then echo 'run as root (sudo)' >&2; exit 1; fi
if [[ ! -f "$HERE/filehub_alert_check.py" ]]; then
  echo "missing checker: $HERE/filehub_alert_check.py" >&2
  exit 1
fi

echo "checker:    $HERE/filehub_alert_check.py -> $LIB_DIR/filehub_alert_check.py"
echo "state:      $STATE_DIR"
echo "alert log:  $LOG_FILE"
echo "env file:   $ENV_FILE"
echo "service:    $SERVICE_DST"
echo "timer:      $TIMER_DST"

if [[ "$APPLY" != "1" ]]; then
  echo 'DRY RUN: set APPLY=1 to install the alert checker and timer.'
  exit 0
fi

install -d -m 0755 "$LIB_DIR" "$(dirname "$LOG_FILE")" "$(dirname "$SERVICE_DST")"
install -d -m 0750 "$STATE_DIR"
install -m 0755 "$HERE/filehub_alert_check.py" "$LIB_DIR/filehub_alert_check.py"
install -m 0644 "$HERE/filehub-alert-check.service" "$SERVICE_DST"
install -m 0644 "$HERE/filehub-alert-check.timer" "$TIMER_DST"
install -m 0644 "$HERE/logrotate-filehub-alerts" "$LOGROTATE_DST"

if [[ ! -e "$LOG_FILE" ]]; then
  install -m 0640 /dev/null "$LOG_FILE"
  if getent group adm >/dev/null 2>&1; then chgrp adm "$LOG_FILE"; fi
fi
if [[ ! -e "$ENV_FILE" ]]; then
  install -m 0640 "$HERE/filehub-alerts.env.example" "$ENV_FILE"
fi

python3 -c 'import ast,sys; ast.parse(open(sys.argv[1], encoding="utf-8").read(), sys.argv[1])' \
  "$LIB_DIR/filehub_alert_check.py"
systemctl daemon-reload
systemctl enable --now filehub-alert-check.timer
systemctl start filehub-alert-check.service
systemctl --no-pager --full status filehub-alert-check.timer filehub-alert-check.service | sed -n '1,80p'
echo 'Installed. Alerts are written to journal and the alert log.'
