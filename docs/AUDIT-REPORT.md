# FileHub audit report — 19–20 Nov 2026 UTC

## Executive summary

The backend telemetry endpoint is healthy: 64 `POST /client-events` returned 204 and
only one returned 401. The loss happens in the widget before upload. The widget stores
an error event in `localStorage`, but a new card/tab starts with an empty in-memory
array and schedules delivery only when that array is already non-empty. `flushTelemetry`
also checks the array length before reading persistent storage. Therefore a recovered
session does not send the old queue until some unrelated new event happens.

The connection error is a separate edge-capacity problem: one 15 r/s nginx zone covers
all users behind the office NAT, including CORS `OPTIONS`. Several amoCRM windows can
exhaust the burst, preflight or a read gets 429, and the widget displays the generic
"Ошибка подключения к бэкенду" instead of a rate-limit explanation.

## Post-rollout status — 2026-11-20T04:31Z

The earlier telemetry-loss and edge-capacity causes are fixed live: 2.8.18 telemetry is
present (72 rows), client-event delivery is 204, FPM is scaled to `4/4/2/2`, and the
new edge limits/logging are active. The remaining dominant failure is client-side
`session_reauth_blocked` with `http_status=0`. Today's snapshot has 112 `list_failed`
and 105 `session_reauth_blocked` events, but only 8 HTTP 401 rows. Another open tab
can hold a valid account-wide session while a different tab still refuses for tens of
minutes.

Widget 2.8.19 now fixes that propagation gap: a tab with a live session announces it
without broadcasting the bearer, answers a nonce-bound peer request even if its local
widget state is stale, and a blocked tab retries peer discovery with bounded backoff.
Peer recovery never spends a new amoCRM JWT. The new `session_peer_recovery_test.js`
covers donor response, availability wake-up, session adoption and peer-only backoff.

The 2.8.19 package was installed at 04:49Z. Its first three telemetry rows arrived
through HTTP 204, confirming installation and delivery, and none was an auth/session
failure. All three were photo thumbnails with `http_status=0`, `duration_ms=0` and no
corresponding `GET /thumb` in nginx. The cause is a client/server format mismatch:
the widget treated BMP/AVIF as thumbnail images, while the server supports only
JPEG/PNG/WebP/GIF and correctly omitted `thumbUrl`. Widget 2.8.20 renders BMP/AVIF as
a neutral file tile and separates `thumb_url_missing` from `thumb_image_failed`.

## Evidence

- 19–20 Nov window: 711 requests; 314x200, 367x204, 8x401, 21x403, 1x405, no 5xx.
- `/client-events`: 64 successful + 1 session-missing 401.
- `07:26:09` shows one session failure fanning out into 401s for `/files`, `/session`,
  `/session/refresh` and `/client-events`.
- 20 `media_denied` thumb failures; 25 `max_children` warnings: 21 for
  `filehub_media` (limit 2), 3 for `www` (limit 2) and 1 for `filehub_auth` (limit 1).
- Staging gateway restarted 348 times because upstream `php` was unavailable.
- All live FPM pools are undersized for office concurrency: `www=2`, `filehub_media=2`,
  `filehub_auth=1`; `filehub_operations=2` has not warned yet.
- Internal certificate expired 2026-11-07; public certificate is valid to 2027-02-03.
- SSH 18-20 Nov: 3,441 failed passwords, 1,376 invalid-user events, 438 failing
  source IPs, and 21 successful root password logins from 185.57.30.136. Password
  and root login remain enabled; no authorized key exists; fail2ban is absent and
  UFW allows TCP/22 from anywhere. The detailed SSH audit is kept out of git as
  an operational security log.
- Production sessions use `lead_id='*'`; the backend has no per-lead ACL.

## Root causes

1. Telemetry `flushTelemetry` read localStorage only after the early empty check.
2. Startup/render and `saveCanarySession` scheduled a flush only for an already
   populated in-memory array.
3. No `pagehide`, `visibilitychange` or `online` delivery; `destroy` cancelled the timer.
4. Five-minute auth cooldown conflated transport errors with confirmed denials.
5. One tab could hold the auth lock while others waited without a bound.
6. Single edge rate-limit zone and 429/OPTIONS handling produced generic UI errors.
7. Account-wide backend authorization made every authenticated user equal.
8. Non-2xx telemetry responses and oversized events removed rows from the delivery
   queue without any operator-visible record, so rejected diagnostics disappeared.
9. Edge access logs lacked upstream status/time, and the backend discarded the edge
   `X-Request-ID`; one failed request could not be followed across both layers.
10. Backend deployment installed files as `0640 root:root`, while PHP-FPM runs as
    `www-data`; an apply could therefore turn every API call into a generic 500.
11. The interactive `www` pool also hit its two-worker ceiling three times on 20 Nov,
    and the one-worker `filehub_auth` pool hit its ceiling on 19 Nov. Media sizing was
    not the only capacity bottleneck behind slow or failed operator requests.

## Implemented in this workspace

- Widget 2.8.18: persistent-queue reconciliation before every empty check, startup load,
  unconditional flush after session issuance, keepalive pagehide/destroy, visibility and
  online recovery, cross-tab telemetry lock, 8 KiB-aware batches, 429 parsing/backoff,
  safe GET retry only, retry probes for peer tabs, split auth cooldowns, bounded auth lock,
  bounded 14-day telemetry quarantine for oversized/rejected rows, and synchronous
  direct keepalive flush on pagehide.
- Widget 2.8.19: five-probe/5-second peer discovery, proactive session-availability
  notification, donor response independent of transient stale state, automatic
  peer-only recovery for blocked/background tabs, clock-safe peer adoption and
  `peer_recovery` diagnostic accounting.
- Backend: `fh_lead_acl_check` with `off/shadow/enforce`; calls on files, file, thumb,
  calls, trash, activity, upload, rename, restore. Invalid mode fails closed with 503;
  enforce fails closed without a manifest; exact lead-scoped sessions do not require an
  account-wide manifest; shadow denials are sampled (default 10%); a replaced manifest
  is reloaded without an FPM restart.
- Backend: telemetry 429 gets `Retry-After: 60`; refresh minimum gap 35 seconds; the
  incoming edge `X-Request-ID` is validated and preserved in the response and telemetry
  rows.
- Edge: separate auth/events/media/API zones, OPTIONS excluded, safe access-log format,
  CORS-correct 429 with `Retry-After: 5` and `X-Request-ID`, auth 10 r/s with burst 30,
  and upstream status/time in the access log.
- Deploy: dry-run-by-default apply scripts, rollback scripts, containment checks,
  SSH/security runbook, synthetic ACL manifest, `0644 root:root` install plus a
  runtime-user readability check before PHP lint; `api.php` is included in deploy and
  rollback.

## Not changed live

No file in `/opt`, `/etc`, `/var`, amoCRM or the running containers was modified. The
workspace artifacts must be reviewed and deployed by an administrator. The version
actually installed in amoCRM was not locally provable and must be checked in the widget
installer before canary rollout.

## Residual risks after review

- The rate-limit key is still the office NAT address. Raising auth to 10 r/s and burst
  30 substantially reduces start-of-shift 429s, but many simultaneous thumbnails can
  still exhaust the shared media burst. Load-test with real office concurrency before
  scaling the widget rollout.
- Quarantined telemetry is bounded diagnostic storage, not a second delivery queue. A
  rejected/oversized row is preserved for support inspection but is not resent, because
  the backend would reject the same payload again.
- ACL mode and manifest behavior have local PHP unit coverage; PHP lint and all three
  PHP suites pass locally. Full host `nginx -t`, container readability and a real
  10-tab browser test remain target-host actions.
- The FPM scale-up (`www=4`, `filehub_media=4`, `filehub_auth=2`) still awaits the
  container restart. The host file alone does not change the running pool.
