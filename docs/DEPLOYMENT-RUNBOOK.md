# FileHub 2.8.21 rollout runbook

## Artifacts

- `widget/` and `dist/FileHub_Widget_2.8.21.zip` — client fixes, including the
  telemetry cross-tab lock and jitter.
- `backend/app/` — ACL seam, telemetry `Retry-After`, refresh interval.
- `deploy/nginx-filehub.proposed.conf` + `deploy/filehub-proxy.inc` — edge limits/logging.
- `deploy/php-www.proposed.conf` — `www` pool 4 -> 8; media/auth pools unchanged.
- `deploy/filehub_alert_check.py` and `deploy/install-filehub-alerts.sh` —
  read-only alerts for 5xx, slow requests, FPM `max_children` and `media_denied`.
- `deploy/apply-*.sh` — dry-run by default; `APPLY=1` required.
- `deploy/rollout-all.sh` — optional host orchestrator for preflight + backend + edge
  (+ FPM with approval). It deliberately excludes SSH, certificates and amoCRM upload.
- `deploy/verify-live.sh` — read-only post-apply check: hashes/modes, edge limits,
  proxy snippet, access log, media workers, container readability and local widget.
- `deploy/inspect-telemetry.py` — read-only summary of raw client telemetry:
  widget versions, event/feature/error counts and latest rows. It never prints
  `lead_id` or `operator_name`.
- `deploy/sync-telemetry-read.sh` — creates a root-only (`0700`/`0600`) snapshot of
  today/yesterday raw telemetry under `/tmp/filehub-telemetry-read`, leaving the
  live `0700 nobody` directory and PHP permissions untouched.
- `deploy/check-stage0.sh` — read-only preflight.
- `deploy/STAGE0-ACTIONS.md` — manual live containment commands.
- `deploy/logrotate-filehub` — 14-day rotation for the new edge log.
- `deploy/SECURITY-RUNBOOK.md` — SSH/certificate containment.
- `deploy/lead-acl.example.json` — synthetic manifest shape.

## Required order

1. Run `deploy/check-stage0.sh` and save its output.
   Staging restart counts and a current CORS 429 are warnings; a production gateway
   restart count above 10 is still a hard failure.
2. Back up widget, backend, nginx and FPM config (`apply-*` scripts do this).
3. Apply backend first with `FILEHUB_LEAD_ACL_MODE=shadow`. The script installs
   `api.php`, `fh12_prod.php`, `fh_client_events.php` and `fh_session_refresh.php` as
   `0644 root:root`, then verifies each file is readable by `www-data` and passes
   `php -l`. Both the readability check and lint restore the backup on failure.
4. Apply nginx config + snippet, run `nginx -t`, then reload. Auth is `10 r/s` with
   burst `30`; telemetry is `5 r/s` with burst `10`; media/API are `30 r/s` with burst
   `60`. A 429 carries `Retry-After: 5`, `X-Request-ID` and CORS headers. The access log
   includes `upstream_status` and `upstream_response_time` but no query string.
5. Install widget canary for one operator. Upload `dist/FileHub_Widget_2.8.21.zip` through
   the amoCRM widget installer; local `/opt/.../widget` is not proof of the version
   installed in amoCRM.
6. Fully reload every already-open amoCRM card/tab. A tab keeps the old script until
   it is reloaded, so mixed 2.8.17/2.8.18 traffic will continue otherwise.
7. Observe the canary for 24 hours. Watch `session_reauth_blocked` with
   `http_status=0`; 2.8.21 keeps the cross-tab telemetry lock until the fetch settles,
   so many open cards should produce fewer duplicate `client-events` requests. Count
   `session_issued` rows carrying `error_code=peer_recovery` as successful adoption,
   not as a new server-side JWT exchange. Thumbnail diagnostics are split into
   `thumb_url_missing` (no signed URL in the list response) and `thumb_image_failed`
   (the signed URL did not load). BMP/AVIF files intentionally fall back to a file tile
   because the thumbnailer supports JPEG/PNG/WebP/GIF only.
8. Only then widen the widget rollout.
9. Raise `www` to 8, `filehub_media` to 4 and `filehub_auth` to 2 only with
   `FPM_SCALE_APPROVED=1` after reviewing `docker stats` and the container memory
   limit. `www.conf` is a single-file bind
   mount, so the script writes it in place and refuses to continue if the host inode
   changes. If an older `install`/`mv` already changed the inode, the container keeps
   reading the old file; rerun with `FPM_RESTART_ON_STALE=1` to restart the PHP
   container and re-resolve the mount. The script restores the old host config if the
   restart does not expose `pm.max_children = 8`, and tells you to recreate the service
   if a restart is not enough. It also saves the config actually mounted in the
   container as `container-before.conf` and restores that last running pool if the
   restart or `php-fpm -t` fails. If Docker is unreachable, the script exits before
   writing anything; an FPM change is never reported as applied without a verified
   reload.
10. Keep ACL in shadow until sampled `acl_would_deny` is understood and the manifest
   includes all active operator/lead pairs. `FILEHUB_LEAD_ACL_LOG_SAMPLE_PCT` defaults
   to `10`; use `0` to stop shadow logging and `100` while validating the manifest.
   Then switch to `enforce` during a maintenance window. An unknown mode returns
   `lead_acl_mode_invalid`/503 instead of silently falling back to shadow. A replaced
   manifest is picked up without an FPM restart; a malformed or unreadable file fails
   closed in enforce and is logged as a shadow source failure otherwise.
11. Run `deploy/verify-live.sh` after every apply. It must exit 0 before canary.
12. Install the alert timer on the host with
    `APPLY=1 bash deploy/install-filehub-alerts.sh`. It scans the last 10 minutes
    every minute, writes to journal and `/var/log/filehub-alerts.log`, and can
    deliver to `FILEHUB_ALERT_WEBHOOK` if configured in `/etc/filehub-alerts.env`.

## Metrics and alert thresholds

- `client-events`: 401, 429, 5xx, `queue_remaining`, `widget_version`.
- Edge: 429 by route, OPTIONS 429 (must be zero), p95 request time.
- Backend: `lead_acl_denied`, `acl_would_deny`, session refresh 429.
- FPM pools: `server reached max_children` for `www`, `filehub_media` or
  `filehub_auth`; `media_denied`, thumb 5xx, p95/p99.
- Telemetry age: oldest persisted event older than 15 minutes should alert.
- Gateway restart count must stop increasing.

Implemented alerts cover any `5xx`, any FPM `max_children`, any `media_denied`
minute and any request slower than 5 seconds. The state file prevents the same
event from being emitted on every one-minute scan.

## Rollback

- Widget: `deploy/rollback-widget.sh <backup-dir>`, then reinstall the previous package
  in amoCRM.
- Backend: `deploy/rollback-backend.sh <backup-dir>`.
- Nginx: `deploy/rollback-nginx.sh <backup-dir>`; script runs `nginx -t` before reload.
- FPM: restore `backup/www.conf` in place, then send `USR2`; if the container is bound
  to an older inode, restart the PHP container after restoring the file.
- ACL: set `FILEHUB_LEAD_ACL_MODE=shadow` or `off` and reload PHP. Never delete the
  manifest while mode is `enforce`.

## Verification already performed

- Node suites cover telemetry restore, 429 retention, batch limits, oversized/rejected
  quarantine, direct pagehide keepalive, multi-tab discovery, cooldown separation,
  lock timeout, retry-timer cleanup and legacy behavior.
- Static contracts require `api.php` in backend deploy and rollback, validated request-ID
  propagation, strict ACL mode, sampled shadow logging, the new edge limits and the
  `0644 root:root` plus runtime-user readability checks.
- Widget source passes `node --check`; JS/JSON artifacts parse.
- PHP 8.3 lint passes for all four deployed files; the ACL, session-refresh and
  review-fix unit suites pass. `validate/php-run.sh` provides the local interpreter.
- Nginx 1.24 reports `syntax is ok`; the sandbox blocks socket binding, so full
  `nginx -t` must be repeated on the host.
- `tests/fpm_apply_test.sh` verifies inode preservation, stale-mount fail-closed
  behavior, restart recovery and rollback after an unsuccessful recovery.
- Live inspection shows the patch is not installed: the edge is still one `15 r/s`
  zone, backend hashes match the incident baseline, and the local widget directory is
  `2.8.0`. The amoCRM-installed version still must be checked separately.

## Request correlation

Edge `request_id` values are sent as `X-Request-ID` upstream. The backend accepts only a
32-hex or UUID value, generates a UUID otherwise, returns it in the response header and
stores it on telemetry rows. Search the same ID in `filehub_access.log`,
`/var/log/nginx/error.log` and the client-events rows when an operator reports a
connection error.
