#!/usr/bin/env python3
"""Read-only FileHub alert scanner.

Checks a bounded window for:
  * nginx 5xx responses;
  * nginx requests slower than --slow-seconds;
  * FPM `server reached max_children`;
  * FPM `media_denied` events.

The scanner never emits query strings, JWTs, headers, lead ids or file names.
It is intentionally safe to run every minute from a systemd timer.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request
from collections import Counter
from datetime import datetime, timedelta, timezone
from pathlib import Path


ACCESS_RE = re.compile(
    r'^\S+\s+-\s+\S+\s+\[([^\]]+)\]\s+"(.*?)"\s+(\d{3})\s+\S+\s+([0-9.]+)\s'
)
REQUEST_ID_RE = re.compile(r'(?:^|\s)request_id=([0-9a-fA-F-]{16,64})(?:\s|$)')
MAX_CHILDREN_RE = re.compile(
    r'\[pool\s+([^\]]+)\]\s+server reached max_children setting\s+\((\d+)\)'
)
SEEN_TTL_SECONDS = 7 * 24 * 60 * 60
DEFAULT_WINDOW = '10m'
DEFAULT_SLOW_SECONDS = 5.0


def parse_duration(value: str) -> int:
    match = re.fullmatch(r'\s*(\d+)\s*([smhd]?)\s*', value or '')
    if not match:
        raise argparse.ArgumentTypeError('duration must look like 600, 10m, 1h or 1d')
    amount = int(match.group(1))
    unit = match.group(2) or 's'
    multiplier = {'s': 1, 'm': 60, 'h': 3600, 'd': 86400}[unit]
    seconds = amount * multiplier
    if seconds < 30 or seconds > 7 * 86400:
        raise argparse.ArgumentTypeError('duration must be between 30 seconds and 7 days')
    return seconds


def utc_now() -> datetime:
    return datetime.now(timezone.utc)


def parse_iso(value: str) -> datetime | None:
    if not value:
        return None
    try:
        parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
        return parsed.astimezone(timezone.utc)
    except (TypeError, ValueError):
        return None


def parse_access_time(value: str) -> datetime | None:
    try:
        return datetime.strptime(value, '%d/%b/%Y:%H:%M:%S %z').astimezone(timezone.utc)
    except (TypeError, ValueError):
        return None


def read_tail(path: Path | None, max_bytes: int) -> list[str]:
    if path is None:
        return []
    try:
        with path.open('rb') as handle:
            handle.seek(0, os.SEEK_END)
            size = handle.tell()
            handle.seek(max(0, size - max_bytes))
            data = handle.read()
    except OSError:
        return []
    lines = data.decode('utf-8', errors='replace').splitlines()
    # A tail can start in the middle of a line. The first fragment is ignored
    # only when it is not a valid access or JSON line.
    return lines


def safe_route(request: str) -> str:
    parts = (request or '').split()
    route = parts[1] if len(parts) > 1 else '-'
    route = route.split('?', 1)[0]
    route = ''.join(ch if ch.isprintable() and ch not in '\r\n\t' else '?' for ch in route)
    return route[:120]


def request_id(line: str) -> str:
    match = REQUEST_ID_RE.search(line)
    return match.group(1) if match else '-'


def collect_access(path: Path | None, cutoff: datetime, slow_seconds: float,
                   max_bytes: int) -> tuple[list[dict], list[dict]]:
    errors: list[dict] = []
    slow: list[dict] = []
    for line in read_tail(path, max_bytes):
        match = ACCESS_RE.match(line)
        if not match:
            continue
        timestamp = parse_access_time(match.group(1))
        if timestamp is None or timestamp < cutoff:
            continue
        route = safe_route(match.group(2))
        status = int(match.group(3))
        duration = float(match.group(4))
        rid = request_id(line)
        if status >= 500:
            errors.append({'timestamp': timestamp, 'status': status, 'route': route, 'request_id': rid})
        if duration > slow_seconds:
            slow.append({'timestamp': timestamp, 'status': status, 'route': route,
                         'duration': duration, 'request_id': rid})
    return errors, slow


def discover_php_log(container: str) -> Path | None:
    configured = os.environ.get('FILEHUB_PHP_LOG', '').strip()
    if configured:
        return Path(configured)
    try:
        result = subprocess.run(
            ['docker', 'inspect', '--format', '{{.LogPath}}', container],
            check=True, capture_output=True, text=True, timeout=5,
        )
        value = result.stdout.strip()
        return Path(value) if value else None
    except (OSError, subprocess.SubprocessError):
        return None


def collect_php(path: Path | None, cutoff: datetime, max_bytes: int) -> tuple[list[dict], Counter]:
    max_children: list[dict] = []
    media_denied: Counter = Counter()
    for line in read_tail(path, max_bytes):
        try:
            row = json.loads(line)
        except (TypeError, json.JSONDecodeError):
            continue
        if not isinstance(row, dict):
            continue
        timestamp = parse_iso(str(row.get('time', '')))
        if timestamp is None or timestamp < cutoff:
            continue
        text = str(row.get('log', ''))
        match = MAX_CHILDREN_RE.search(text)
        if match:
            max_children.append({
                'timestamp': timestamp,
                'pool': match.group(1),
                'limit': int(match.group(2)),
            })
        if '"result":"media_denied"' in text:
            media_denied[timestamp.strftime('%Y-%m-%dT%H:%MZ')] += 1
    return max_children, media_denied


def load_state(path: Path) -> dict:
    try:
        data = json.loads(path.read_text(encoding='utf-8'))
        if isinstance(data, dict) and isinstance(data.get('seen'), dict):
            return data
    except (OSError, json.JSONDecodeError):
        pass
    return {'seen': {}}


def prune_state(state: dict, now_epoch: int) -> None:
    seen = state.setdefault('seen', {})
    for signature, previous in list(seen.items()):
        if not isinstance(previous, (int, float)) or now_epoch - previous >= SEEN_TTL_SECONDS:
            del seen[signature]


def save_state(path: Path, state: dict) -> None:
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        temporary = path.with_name(path.name + '.tmp')
        temporary.write_text(json.dumps(state, ensure_ascii=False, sort_keys=True), encoding='utf-8')
        os.replace(temporary, path)
    except OSError as exc:
        print(f'WARN: cannot write alert state {path}: {exc}', file=sys.stderr)


def emit_once(state: dict, signature: str, now_epoch: int, alerts: list[dict], message: str) -> None:
    seen = state.setdefault('seen', {})
    previous = seen.get(signature)
    if isinstance(previous, (int, float)) and now_epoch - previous < SEEN_TTL_SECONDS:
        return
    seen[signature] = now_epoch
    alerts.append({'signature': signature, 'message': message})


def append_output(path: Path | None, line: str) -> None:
    print(line)
    if path is None:
        return
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        with path.open('a', encoding='utf-8') as handle:
            handle.write(line + '\n')
    except OSError as exc:
        print(f'WARN: cannot write alert log {path}: {exc}', file=sys.stderr)


def send_webhook(url: str, alerts: list[dict]) -> None:
    if not url or not alerts:
        return
    body = json.dumps({
        'service': 'filehub',
        'generated_at': utc_now().strftime('%Y-%m-%dT%H:%M:%SZ'),
        'alerts': [{'message': item['message']} for item in alerts],
    }, ensure_ascii=False).encode('utf-8')
    request = urllib.request.Request(url, data=body, method='POST',
                                     headers={'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(request, timeout=5) as response:
            response.read(1024)
    except urllib.error.HTTPError as exc:
        # Never echo the webhook URL: it may contain a secret token.
        print(f'WARN: webhook delivery failed: http_status={exc.code}', file=sys.stderr)
    except urllib.error.URLError as exc:
        reason = type(exc.reason).__name__ if exc.reason is not None else type(exc).__name__
        print(f'WARN: webhook delivery failed: {reason}', file=sys.stderr)
    except Exception as exc:  # noqa: BLE001 - delivery must never break scanning
        print(f'WARN: webhook delivery failed: {type(exc).__name__}', file=sys.stderr)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description='Read-only FileHub alert scanner')
    parser.add_argument('--access-log', type=Path,
                        default=Path(os.environ.get('FILEHUB_ACCESS_LOG', '/var/log/nginx/filehub_access.log')))
    parser.add_argument('--php-log', type=Path,
                        help='PHP-FPM Docker JSON log path; auto-detected through docker inspect if omitted')
    parser.add_argument('--container',
                        default=os.environ.get('FILEHUB_PHP_CONTAINER', 'filehub-unified-rc02-php-1'))
    parser.add_argument('--window', type=parse_duration, default=parse_duration(DEFAULT_WINDOW),
                        help='scan window (default: 10m)')
    parser.add_argument('--slow-seconds', type=float, default=DEFAULT_SLOW_SECONDS,
                        help='slow request threshold (default: 5)')
    parser.add_argument('--state', type=Path,
                        default=Path(os.environ.get('FILEHUB_ALERT_STATE', '/var/lib/filehub-alerts/state.json')))
    parser.add_argument('--output', type=Path,
                        default=Path(os.environ.get('FILEHUB_ALERT_LOG', '/var/log/filehub-alerts.log')))
    parser.add_argument('--webhook', default=os.environ.get('FILEHUB_ALERT_WEBHOOK', ''))
    parser.add_argument('--max-bytes', type=int, default=8 * 1024 * 1024,
                        help='maximum tail read per log (default: 8 MiB)')
    parser.add_argument('--json', action='store_true', help='print a machine-readable summary')
    return parser


def main() -> int:
    args = build_parser().parse_args()
    now = utc_now()
    cutoff = now - timedelta(seconds=args.window)
    state = load_state(args.state)
    state_epoch = int(now.timestamp())
    prune_state(state, state_epoch)
    alerts: list[dict] = []

    access_errors, slow_requests = collect_access(
        args.access_log, cutoff, args.slow_seconds, args.max_bytes)
    php_log = args.php_log or discover_php_log(args.container)
    if php_log is None:
        print('WARN: PHP-FPM log unavailable; max_children/media_denied not checked', file=sys.stderr)
        max_children: list[dict] = []
        media_denied: Counter = Counter()
    else:
        max_children, media_denied = collect_php(php_log, cutoff, args.max_bytes)

    for event in access_errors:
        signature = '5xx|{timestamp}|{request_id}|{status}|{route}'.format(
            timestamp=event['timestamp'].isoformat(), request_id=event['request_id'],
            status=event['status'], route=event['route'])
        emit_once(state, signature, state_epoch, alerts,
                  'category=5xx count=1 sample="route={route} status={status} request_id={rid}"'.format(
                      route=event['route'], status=event['status'], rid=event['request_id']))

    for event in slow_requests:
        signature = 'slow|{timestamp}|{request_id}|{route}'.format(
            timestamp=event['timestamp'].isoformat(), request_id=event['request_id'],
            route=event['route'])
        emit_once(state, signature, state_epoch, alerts,
                  'category=slow count=1 sample="route={route} status={status} duration_s={duration:.3f} '
                  'request_id={rid}"'.format(route=event['route'], status=event['status'],
                                             duration=event['duration'], rid=event['request_id']))

    for event in max_children:
        signature = 'max_children|{timestamp}|{pool}|{limit}'.format(
            timestamp=event['timestamp'].isoformat(), pool=event['pool'], limit=event['limit'])
        emit_once(state, signature, state_epoch, alerts,
                  'category=max_children count=1 sample="pool={pool} limit={limit} at={at}"'.format(
                      pool=event['pool'], limit=event['limit'],
                      at=event['timestamp'].strftime('%Y-%m-%dT%H:%M:%SZ')))

    for minute, count in sorted(media_denied.items()):
        signature = f'media_denied|{minute}'
        emit_once(state, signature, state_epoch, alerts,
                  f'category=media_denied count={count} window_minute={minute}')

    for item in alerts:
        append_output(args.output, f"{now.strftime('%Y-%m-%dT%H:%M:%SZ')} ALERT {item['message']}")
    send_webhook(args.webhook, alerts)
    save_state(args.state, state)

    if args.json:
        print(json.dumps({'generated_at': now.strftime('%Y-%m-%dT%H:%M:%SZ'),
                          'window_seconds': args.window, 'alerts': alerts}, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
