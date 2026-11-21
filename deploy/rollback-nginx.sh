#!/usr/bin/env bash
set -euo pipefail
BACKUP="${1:?usage: rollback-nginx.sh /var/backups/filehub/nginx-YYYY...}"
SITE="${FILEHUB_EDGE_CONF:-/etc/nginx/sites-available/filehub.ivan83u.ru.conf}"
SNIP="${FILEHUB_PROXY_SNIPPET:-/etc/nginx/snippets/filehub-proxy.inc}"
LOGROTATE="${FILEHUB_LOGROTATE_CONF:-/etc/logrotate.d/filehub}"
[[ $EUID -eq 0 ]] || { echo 'run as root (sudo)' >&2; exit 1; }
[[ -f "$BACKUP/site.conf" ]] || { echo "missing $BACKUP/site.conf" >&2; exit 1; }
cp -a "$BACKUP/site.conf" "$SITE"
[[ -f "$BACKUP/proxy.inc" ]] && cp -a "$BACKUP/proxy.inc" "$SNIP"
[[ -f "$BACKUP/logrotate" ]] && cp -a "$BACKUP/logrotate" "$LOGROTATE"
nginx -t
systemctl reload nginx
echo "nginx restored from $BACKUP"
