# Fix plan status

## Priorities 1, 2 and 5 — implementation — 2026-11-21

Scope: FPM capacity, cross-tab telemetry fan-out and operational alerts. SSH,
certificates and priority 4 are intentionally out of scope.

- [x] Audit and remediation plan written to
      `docs/REMEDIATION-PLAN-2026-11-21.md`.
- [x] Confirmed the 2026-11-20 09:21Z burst: 34 requests in one minute,
      `[pool www] server reached max_children setting (4)`, all HTTP 200/204 and
      maximum latency 0.430 s. This was pool saturation, not an HTTP 5xx outage.
- [x] FPM workspace config raised `www` from 4 to 8 workers; `filehub_media=4`,
      `filehub_operations=2` and `filehub_auth=2` remain unchanged.
- [x] `apply-fpm.sh` keeps the single-file bind-mount inode, verifies the mounted
      container config, fails closed on a stale mount and restores the previous
      pool on failure. The inode/stale-mount/rollback rehearsal passes.
- [x] Apply the FPM change on the host with root. Live host config matches the
      proposed file exactly: `www=8`, `filehub_media=4`,
      `filehub_operations=2`, `filehub_auth=2`.
- [x] Fixed telemetry fan-out: `sendTelemetryBatch()` now returns a Promise and
      the Web Lock callback keeps that Promise until the fetch settles and the
      acknowledged rows are removed. A regression test proves the lock is held
      during a pending request.
- [x] Added 0–1200 ms jitter to the normal 3.5 s flush and to delayed session
      reconciliation, while preserving the direct keepalive path on `pagehide`.
- [x] Widget source, manifest and both locales are versioned `2.8.21`.
- [x] Added a read-only alert scanner, systemd service/timer, logrotate config,
      state-file deduplication and installer for any nginx `5xx`, requests slower
      than 5 s, FPM `max_children` and per-minute `media_denied` events.
- [x] Alert output omits query strings, tokens, lead ids and file names; webhook
      failure diagnostics also omit the URL and its token.
- [x] Install and verify the alert timer on the host with root. The installed
      checker, service, timer and logrotate config match the workspace hashes;
      the timer runs every minute and updates its state file.
- [x] Build and install the `2.8.21` ZIP in amoCRM. Raw telemetry contains two
      `widget_version=2.8.21` rows at `2026-11-21T02:18:10Z`; both use request
      `7daa0b11e572188c037989f5dcab1f6f` and the corresponding `POST
      /client-events` returned HTTP 204.
- [x] Full `run-tests.sh` suite passes after the changes.

### Live verification — 2026-11-21T02:27Z

- FPM host config equals `deploy/php-www.proposed.conf`; all four pools match
  the reviewed values.
- `filehub-alert-check.service` completed scans at 02:21, 02:22, 02:23, 02:24,
  02:25 and 02:26 UTC. The 10-minute alert state is empty because there were no
  new threshold events; a two-day read-only replay found 26 `max_children`,
  8 `media_denied` minutes and 2 slow requests, proving log access and parsing.
- Nginx had no HTTP 5xx and no request slower than 5 seconds in the last two
  hours.
- The telemetry snapshot has 34 valid rows for today and no malformed rows.
  `2.8.21` is present, while already-open tabs still emit `2.8.20` (32 rows
  today), so a full reload of all open cards remains necessary for rollout.

## 0. Containment

- [x] Baseline checksums captured in `BASELINE.sha256`.
- [x] Read-only containment script prepared: `deploy/check-stage0.sh`.
- [x] Widget/backend/nginx/FPM backups are taken by every apply script.
- [x] Edge logging and preflight checks added to the proposed nginx config.
- [x] FPM pool config prepared (`www=4`, `filehub_media=4`, `filehub_auth=2`),
      gated by `FPM_SCALE_APPROVED=1`.
- [x] SSH/certificate containment runbook prepared.
- [x] Infinite-restart gateway and root-login review added to the preflight.
- [x] Backend and nginx applied live by the administrator on 2026-11-20T02:41Z.
- [x] PHP-FPM container restarted at 2026-11-20T02:57:42Z and loaded the host pool
      config (`www=4`, `filehub_media=4`, `filehub_operations=2`, `filehub_auth=2`).
- [x] Confirm the widget version actually installed in amoCRM: raw telemetry has
      72 rows with `widget_version=2.8.18` (first 02:25:03Z, last 04:17:57Z).
- [x] Install the reviewed `2.8.19` peer-recovery build through the amoCRM installer;
      telemetry shows 3 rows at 04:49:24–04:49:40Z and HTTP 204 delivery.

## 1. Telemetry

- [x] Load persistent queue on widget startup.
- [x] Load before the empty-array check in `flushTelemetry`.
- [x] Always schedule a flush after `saveCanarySession`.
- [x] `pagehide`, `visibilitychange`, `online`, and `destroy` delivery paths.
- [x] `fetch(..., {keepalive:true})`; no `sendBeacon`.
- [x] Cross-tab telemetry serialization with Web Locks.
- [x] 8 KiB/10-event batch sizing.
- [x] Edge/backend 429 parsing, `Retry-After`, and exponential backoff.
- [x] Oversized/rejected events moved to a bounded 14-day quarantine, never silently
      deleted; oversized rows no longer block the rest of the queue.
- [x] Direct keepalive flush on `pagehide` and widget destroy.
- [x] Tests: saved event -> new instance -> session -> upload; 429 retention; 2xx only.
- [x] Real delivery after the widget update: `POST /client-events` returned 204 at
      03:01:57Z; all 9 `client-events` requests today returned 204.
- [x] Confirm `widget_version=2.8.18` in the raw telemetry JSONL. The root-only
      snapshot contains 72 rows for 2.8.18 and no malformed rows; delivery via
      `POST /client-events` was accepted with HTTP 204.
- [ ] Canary verification against the real R2 endpoint and real `localStorage`.

## 2. Multi-tab session

- [x] Probe peer tabs five times inside the bounded 5-second window.
- [x] Separate 20-second transport backoff from five-minute confirmed denial.
- [x] Bound auth-lock waits and expose `auth_lock_timeout`.
- [x] Reset retry/inflight/marker state on manual recovery.
- [x] Refresh/resume on visible tab and network recovery.
- [x] Refresh minimum gap raised to 35 seconds.
- [x] Peer timeout clears the retry timer; backend floor is documented as 35 seconds.
- [x] A live donor answers peer discovery even when its widget state or amoCRM
      constants are temporarily stale; blocked tabs poll peer-only with bounded backoff.
- [x] Session issuance announces availability without broadcasting the bearer, and the
      receiving tab adopts the peer token and flushes its persistent telemetry queue.
- [x] Tests: peer retries, cooldown split, lock timeout, donor/availability recovery;
      legacy suites still pass.
- [ ] Real 10-tab/kill-leader/offline-online browser test.

## 3. Edge and capacity

- [x] Separate auth/events/media/API zones.
- [x] Exclude OPTIONS from rate limiting.
- [x] CORS-correct 429 with `Retry-After` and `X-Request-ID`.
- [x] Auth zone raised to 10 r/s, burst 30; 429 now sends `Retry-After: 5`.
- [x] Query-string-free access log.
- [x] Access log records upstream status/response time; `X-Request-ID` reaches backend.
- [x] Backend telemetry 429 sends `Retry-After`.
- [x] Safe GET-only retry; writes never auto-retried.
- [x] Apply nginx config and run host `nginx -t`/reload.
- [x] Raise FPM workers (`www=4`, `filehub_media=4`, `filehub_auth=2`) after memory
      approval. The apply script's post-restart pool comparison passed; its backup
      captured the old `2/2/2/1` container config.
- [x] Memory measured: 25.5 MiB current and 46.6 MiB peak against a 192 MiB limit.
- [ ] Run production load test with many office-NAT tabs.

## 4. Security

- [x] `fh_lead_acl_check(identity, leadId)` with `off/shadow/enforce`.
- [x] ACL checks on files, file, thumb, calls, trash, activity, upload, rename, restore.
- [x] Enforce mode fails closed when the manifest is missing.
- [x] Unknown ACL mode fails closed with 503 instead of silently using shadow.
- [x] Exact lead-scoped sessions skip the account-wide manifest requirement.
- [x] Shadow `acl_would_deny` logging is sampled (default 10%).
- [x] A replaced ACL manifest is reloaded without restarting PHP-FPM.
- [x] Manifest format and synthetic example supplied.
- [x] SSH/fail2ban hardening files and rollback-safe runbook.
- [x] SSH audit for 18-20 Nov: 3,441 failed passwords from 438 source IPs; all
      21 successful logins were root/password from 185.57.30.136.
- [x] Corrected drop-in priority: final key-only config must sort before
      `50-cloud-init.conf`; the old draft is marked deprecated.
- [x] Fail2ban policy prepared: five failures per ten minutes, one-hour ban with
      incremental growth and UFW action; dynamic office IP is not a permanent
      exemption.
- [x] Confirm that 185.57.30.136 is the approved office egress (dynamic lease;
      do not use it as the sole permanent firewall allowlist).
- [x] Install fail2ban live: package `1.0.2-3ubuntu0.1` is installed and the
      service is enabled/running.
- [x] Effective jail verified: `ssh.service` journal match, `maxretry=5`,
      `findtime=10m`, `bantime=1h`, incremental bans and `ufw` action.
- [x] fail2ban parses live SSH failures and records them in its database.
- [x] Run `verify-fail2ban.sh` as root: temporary ban/unban through UFW passed
      at 2026-11-20T07:09:38Z (the report is generated under `/tmp`).
- [ ] Apply the lockout-safe SSH limits phase.
- [ ] Create a key-only non-root administrator and verify a second session.
- [ ] Apply the keys-only phase, then restrict TCP/22 at the provider/UFW.
- [ ] Populate ACL manifest from amoCRM permissions.
- [ ] Observe shadow denials and switch to enforce.
- [ ] Review/revoke the 21 root logins; rotate credentials.
- [ ] Extend/replace the expired internal certificate.

## 5. Rollout

- [x] Widget package `FileHub_Widget_2.8.18.zip` built, installed and confirmed by telemetry.
- [x] `2.8.19` package built, installed and confirmed by telemetry.
- [x] `2.8.20` package built and verified: 11 entries, manifest/script match, SHA-256
      recorded in `FINAL.sha256`. It fixes the BMP/AVIF thumbnail contract and adds
      separate `thumb_url_missing` / `thumb_image_failed` telemetry codes.
- [ ] Install `FileHub_Widget_2.8.20.zip` through the amoCRM installer and fully
      reload every open card/tab.
- [x] Component-specific apply/rollback scripts.
- [x] `api.php` included in deploy and rollback; files are `0644 root:root` and checked
      for readability by the PHP runtime user before lint/traffic.
- [x] Apply/rollback rehearsed on a disposable copy; permissions, hashes and restore pass.
- [x] PHP lint, PHP unit tests and request-ID CGI integration pass locally.
- [x] Host orchestrator `deploy/rollout-all.sh` prepared; dry run passes.
- [x] Metrics/alerts/runbook drafted.
- [ ] Canary one operator for 24 hours.
- [ ] Widen rollout and keep backend/widget compatibility watch.

## Telemetry analysis — 2026-11-20T04:31Z

- Raw rows: 278 today and 387 yesterday, zero malformed.
- Today: 206 rows from 2.8.17 and 72 from 2.8.18. The old version still dominates
  because already-open amoCRM tabs keep their loaded script until a full reload.
- Events today: `list_failed=112`, `session_reauth_blocked=105`,
  `session_attempt=23`, `session_issued=22`, `upload_verified=10`.
- Only 8 rows have HTTP 401. Most blocked rows have `http_status=0`: the widget
  refuses locally before calling the backend, so this is not an nginx/FPM/auth 5xx flow.
- One operator with many open cards produced blocked events continuously while a
  session issued in another tab was still live. The valid session was not propagated
  reliably to the other tabs; the 2.8.19 peer-recovery path addresses that gap.
- Absolute 30-minute expiry is real but is handled: several operators received
  `session_absolute_expired` followed immediately by `session_attempt` and
  `session_issued`.

## Verification after the 2.8.19 install — 2026-11-20T04:52Z

- 2.8.19 is installed: 3 rows, first 04:49:24Z, last 04:49:40Z, all HTTP 204.
- All 3 rows are `thumb_failed` with `thumbnail_load_failed`, `http_status=0`,
  `duration_ms=0`; no 2.8.19 auth/session events were emitted in this sample.
- Nginx has no `GET /thumb` at those timestamps. The client failed before the network
  request, so this is not nginx, FPM, or the thumbnail worker.
- BMP/AVIF are previewable by the widget but unsupported by the thumbnailer. 2.8.20
  stops emitting false failures for them and distinguishes a missing signed URL from
  a real image load error.

## Test result

`run-tests.sh` passes all legacy and new Node suites, including peer recovery,
quarantine,
direct pagehide flush, retry-timer cleanup, request-ID propagation, deploy permissions,
strict ACL mode and edge contracts. PHP 8.3 lint and all local PHP unit suites pass via
`validate/php-run.sh`; only the ENOSPC rollback case is skipped. Nginx parsing reports
`syntax is ok`; the sandbox blocks socket creation, so only the host can complete
`nginx -t`. Live apply, container-side readability, canary and scale remain
administrator actions. The new `tests/fpm_apply_test.sh` covers inode preservation,
stale single-file mount detection, opt-in restart recovery and rollback when recovery
fails. See `validate/READINESS.md`.
