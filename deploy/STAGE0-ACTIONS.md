# Stage 0 manual actions

These actions are deliberately not automated because they require live-system approval.

## Backups and version identity

```bash
install -d /opt/filehub-unified-rc02/backups/$(date -u +%Y%m%dT%H%M%SZ)
sha256sum /opt/filehub-unified-rc02/widget/script.js
unzip -p dist/FileHub_Widget_2.8.21.zip manifest.json   # compare in amoCRM installer
```

## 429 visibility and preflight

1. Run `deploy/check-stage0.sh`.
2. Confirm `OPTIONS /wp-json/czfh/v1/files` is not 429.
3. Apply the proposed nginx config only after `nginx -t` succeeds.
4. Search the new log for `status=429`; it contains `method`, path and request id, not query strings.

## Staging gateway restart loop

```bash
docker inspect -f '{{.RestartCount}}' filehub-r2-staging-gateway-1
docker logs --tail 100 filehub-r2-staging-gateway-1
```

If staging is not actively used, stop that compose project. Do not leave a crash-looping
gateway consuming memory. Fix the `php` upstream DNS/name before restarting it.

## FPM pools and memory

```bash
docker stats --no-stream filehub-unified-rc02-php-1
docker inspect -f '{{.HostConfig.Memory}}' filehub-unified-rc02-php-1
FPM_SCALE_APPROVED=1 APPLY=1 deploy/apply-fpm.sh
```

The proposed pools are `www=8`, `filehub_media=4`, `filehub_operations=2` and
`filehub_auth=2`. Do not use `FPM_SCALE_APPROVED=1` unless the extra workers fit with
at least 25% memory headroom. Validate a thumbnail and multi-tab burst afterwards and
watch `server reached max_children` for every pool.

## Root logins and SSH

Follow `deploy/SECURITY-RUNBOOK.md`. Review every successful root login from
`185.57.30.136`; rotate credentials if it is not an approved administrator. Do not
disable password authentication until a second key-based session is proven.

## Production writes

Keep production write acknowledgement off until the lead ACL has been populated,
observed in shadow, and switched to enforce. A production session with `lead_id='*'`
is account authentication, not proof of access to every lead.
