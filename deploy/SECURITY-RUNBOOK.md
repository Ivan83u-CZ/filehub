# SSH hardening and incident runbook

## Current evidence (18-20 Nov 2026, UTC)

Read-only sources: `journalctl -u ssh`, `last`, `lastb`, `sshd -T`, PAM and UFW
configuration. Window: 2026-11-18 00:00 through 2026-11-21 00:00.

- 21 accepted logins: all `root`, all with a password, all from
  `185.57.30.136`. There were no accepted logins from any brute-force source.
- 3,441 failed password events, 1,376 invalid-user events, 438 unique failing
  source IPs and 116 attempted usernames.
- Daily failed-password counts: 1,028 on 18 Nov, 1,546 on 19 Nov and 867 on
  20 Nov through 06:15 UTC.
- Top failing IPs: `193.95.2.161` (427), `103.90.67.3` (427),
  `183.242.200.142` (391), `8.208.71.57` (248), `160.30.192.42` (157).
- Top failing /24s: `45.135.141.0/24` (880), `45.135.142.0/24` (612),
  `193.95.2.0/24` (427), `103.90.67.0/24` (427),
  `183.242.200.0/24` (391), `8.208.71.0/24` (248).

`185.57.30.136` is confirmed by the owner as the office egress. It is dynamic, so
the prepared configuration deliberately does not hard-code it as an SSH
allowlist or a permanent fail2ban exemption. If a stable office VPN endpoint is
introduced later, that endpoint can be allowlisted.

At 2026-11-18 07:39:15 the same IP produced one invalid non-ASCII username and
then two successful root password logins nine seconds later. This is consistent
with a human typo followed by the real login, but it still needs owner
confirmation.

## Current weaknesses

- `Port 22`, `PermitRootLogin yes`, `PasswordAuthentication yes`,
  `MaxAuthTries 6`, `LoginGraceTime 120`, `X11Forwarding yes`, `MaxSessions 10`.
- `/etc/ssh/sshd_config.d/50-cloud-init.conf` also enables password auth.
  Because `Include` is before the main settings, the first value wins. A
  correctly working key-only drop-in must sort before this file, hence `00-`.
- Root has no `authorized_keys`; no other non-root interactive account exists
  and the `sudo` group is empty. Key-only migration is therefore mandatory
  before password login is disabled.
- `fail2ban` is not installed. UFW is enabled but allows TCP/22 from anywhere.
- PAM logs `PAM service(sshd) ignoring max retries; 6 > 3`, confirming that the
  configured authentication retry policy is not behaving as intended.
- Hourly `ssh-rsa` failures are rejected by modern OpenSSH algorithms. Determine
  whether these come from monitoring/backup automation before changing key
  algorithms.

## Prepared files

- `ssh/00-filehub-ssh-limits.conf`: lockout-safe phase 1. Lowers
  `MaxAuthTries`, `LoginGraceTime`, `MaxSessions` and disables X11/agent
  forwarding, but leaves password login enabled.
- `ssh/00-filehub-ssh-keys-only.conf`: phase 2. Requires public keys, disables
  password and keyboard-interactive login, and blocks password login for root.
- `fail2ban-sshd.local`: five failures in ten minutes cause a one-hour UFW ban;
  repeated bans grow to a maximum of four weeks. Only loopback is permanently
  excluded because the office address is dynamic.
- `apply-ssh-hardening.sh`: dry-run by default. Supports `PHASE=limits`,
  `PHASE=fail2ban` and the gated `PHASE=keys-only` migration.
- `install-fail2ban.sh`: root installer for the package, jail, validation and
  service enablement. SSH password/key settings are not changed.

## Safe order

1. Confirm who owns `185.57.30.136` and whether the 21 root logins are known
   (owner confirms this is the office; the address is dynamic).
2. Keep an existing SSH session open and take a second terminal for testing.
3. Install and test phase 1. It cannot remove password login:

   ```bash
   cd <repo>/deploy
   bash apply-ssh-hardening.sh
   PHASE=limits APPLY=1 bash apply-ssh-hardening.sh
   ```

4. Install `fail2ban`, then enable the prepared jail only after UFW status is
   healthy. This script does not change SSH authentication:

   ```bash
   cd <repo>/deploy
   APPLY=1 bash install-fail2ban.sh
   fail2ban-client status sshd
   ```

5. Create a non-root administrator and install an Ed25519 public key. Verify a
   second session using the key before touching password authentication:

   ```bash
   adduser --disabled-password --gecos '' deployadmin
   usermod -aG sudo deployadmin
   install -d -m 0700 -o deployadmin -g deployadmin /home/deployadmin/.ssh
   # Run ssh-copy-id from the administrator workstation, then verify:
   ssh -o PreferredAuthentications=publickey deployadmin@<host> true
   ```

6. Enable key-only login with the exact user whose second session was tested:

   ```bash
   ADMIN_USER=deployadmin SSH_KEYS_VERIFIED=1 PHASE=keys-only APPLY=1 \
     bash apply-ssh-hardening.sh
   ```

   Keep the old session open. If a new key-only session fails, restore the
   backup path printed by the script and reload SSH.

7. After key access is stable, restrict TCP/22 to the office/VPN and/or the
   provider firewall. Then consider `PermitRootLogin no`, `AllowUsers`, MFA,
   SSH certificates and a non-standard port. Port changes are noise reduction,
   not a security boundary.

## Rollback

Every phase copies the previous file under the printed `/var/backups/filehub/ssh-*`
directory. Restore the affected drop-in, run `sshd -t`, then `systemctl reload
ssh`. Never restart SSH while the only working session is the one being changed.
If fail2ban misbehaves, `systemctl stop fail2ban`, remove the new jail file and
flush its UFW chains before restarting it.

## Additional controls, in priority order

1. Cloud/provider firewall before the host firewall; allow SSH only from the
   known office/VPN.
2. Key-only non-root access, password auth disabled, root login disabled after
   migration.
3. fail2ban with incremental bans and a recidive jail; monitor banned counts.
4. Per-source connection limits: `MaxStartups`, `MaxAuthTries`,
   `LoginGraceTime`, `MaxSessions`.
5. UFW `limit 22/tcp` as a coarse connection-rate guard. It is not a substitute
   for fail2ban and an existing broad `allow 22/tcp` must be removed first.
6. TOTP MFA for SSH, or a bastion/VPN/Teleport-style access proxy.
7. Patch management, root password rotation and quarterly key review.
8. `pam_faillock` only after key access exists. It can lock accounts for all
   PAM services and must be tested for root and sudo recovery.
9. Disable X11, agent forwarding and TCP forwarding when no workflow needs them.
10. Alert on successful root/password logins, new authorized keys, new sudo
    users, firewall changes and fail2ban restarts.

## Optional emergency static blocks

Prefer fail2ban's dynamic per-IP bans. If the two largest /24 attack ranges must
be dropped immediately, apply them only after confirming they are not business
networks:

```bash
ufw deny from 45.135.141.0/24 to any port 22 proto tcp
ufw deny from 45.135.142.0/24 to any port 22 proto tcp
ufw status numbered
```

Do not add the trusted office/VPN IP to a static deny rule. If it is untrusted,
block it and start the incident checklist above before any other change.

Keep `FILEHUB_PROD_WRITES=1` disabled until the lead ACL has been observed in
shadow and then enforced. Do not treat `lead_id='*'` in a production session as
authorization for every lead.
