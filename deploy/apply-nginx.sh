#!/usr/bin/env bash
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
SITE_SRC="$HERE/nginx-filehub.proposed.conf"
SNIP_SRC="$HERE/filehub-proxy.inc"
LOGROTATE_SRC="$HERE/logrotate-filehub"
SITE_DST="${FILEHUB_EDGE_CONF:-/etc/nginx/sites-available/filehub.ivan83u.ru.conf}"
SNIP_DST="${FILEHUB_PROXY_SNIPPET:-/etc/nginx/snippets/filehub-proxy.inc}"
LOGROTATE_DST="${FILEHUB_LOGROTATE_CONF:-/etc/logrotate.d/filehub}"
APPLY="${APPLY:-0}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP="${FILEHUB_NGINX_BACKUP:-/var/backups/filehub/nginx-$STAMP}"

if [[ $EUID -ne 0 ]]; then echo 'run as root (sudo)' >&2; exit 1; fi
echo "site: $SITE_SRC -> $SITE_DST"
echo "snippet: $SNIP_SRC -> $SNIP_DST"
if [[ "$APPLY" != "1" ]]; then
  echo 'DRY RUN: set APPLY=1 to install + nginx -t + reload. No files changed.'
  exit 0
fi

install -d -m 0750 "$BACKUP"
[[ -f "$SITE_DST" ]] && cp -a "$SITE_DST" "$BACKUP/site.conf"
[[ -f "$SNIP_DST" ]] && cp -a "$SNIP_DST" "$BACKUP/proxy.inc"
[[ -f "$LOGROTATE_DST" ]] && cp -a "$LOGROTATE_DST" "$BACKUP/logrotate"
install -d -m 0755 "$(dirname "$SNIP_DST")"
install -m 0644 "$SNIP_SRC" "$SNIP_DST"
install -m 0644 "$SITE_SRC" "$SITE_DST"
install -m 0644 "$LOGROTATE_SRC" "$LOGROTATE_DST"

if ! nginx -t; then
  echo 'nginx -t failed; restoring previous config' >&2
  [[ -f "$BACKUP/site.conf" ]] && cp -a "$BACKUP/site.conf" "$SITE_DST"
  [[ -f "$BACKUP/proxy.inc" ]] && cp -a "$BACKUP/proxy.inc" "$SNIP_DST"
  [[ -f "$BACKUP/logrotate" ]] && cp -a "$BACKUP/logrotate" "$LOGROTATE_DST"
  exit 1
fi
systemctl reload nginx
echo "nginx reloaded; backup=$BACKUP"
