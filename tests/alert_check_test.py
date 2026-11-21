#!/usr/bin/env python3
"""CLI tests for the FileHub alert scanner."""
from __future__ import annotations

import json
import contextlib
import importlib.util
import io
import subprocess
import sys
import tempfile
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.error import HTTPError


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'deploy' / 'filehub_alert_check.py'


def load_checker_module():
    spec = importlib.util.spec_from_file_location('filehub_alert_check', SCRIPT)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def access_line(when: datetime, status: int, duration: float, request_id_value: str) -> str:
    stamp = when.strftime('%d/%b/%Y:%H:%M:%S %z')
    return (
        f'203.0.113.10 - - [{stamp}] "GET /wp-json/czfh/v1/files?token=SECRET HTTP/1.1" '
        f'{status} 123 {duration:.3f} upstream_status={status} '
        f'upstream_time={duration:.3f} request_id={request_id_value}'
    )


def php_line(when: datetime, message: str) -> str:
    return json.dumps({
        'log': message,
        'stream': 'stderr',
        'time': when.strftime('%Y-%m-%dT%H:%M:%S.%fZ'),
    })


def run_checker(*extra: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [sys.executable, str(SCRIPT), *extra],
        capture_output=True, text=True, check=False,
    )


def main() -> int:
    now = datetime.now(timezone.utc)
    with tempfile.TemporaryDirectory(prefix='filehub-alert-test-') as tmp:
        base = Path(tmp)
        access = base / 'access.log'
        php = base / 'php.jsonl'
        state = base / 'state.json'
        output = base / 'alerts.log'

        access.write_text('\n'.join([
            access_line(now - timedelta(minutes=2), 502, 5.500, 'a' * 32),
            access_line(now - timedelta(minutes=2), 200, 6.100, 'b' * 32),
            access_line(now - timedelta(minutes=2), 200, 0.100, 'c' * 32),
            '203.0.113.10 - - [21/Nov/2026:02:00:00 +0000] "GET /robots.txt HTTP/1.1" '
            '404 134 0.000 upstream_status=- upstream_time=- request_id=' + 'd' * 32,
        ]) + '\n', encoding='utf-8')
        php.write_text('\n'.join([
            php_line(now - timedelta(minutes=1),
                     '[21-Nov-2026 02:00:00] WARNING: [pool www] server reached max_children setting (8), consider raising it'),
            php_line(now - timedelta(minutes=1),
                     '[FH12] {"tag":"FH12","result":"media_denied","actor":0}'),
        ]) + '\n', encoding='utf-8')

        args = ('--access-log', str(access), '--php-log', str(php),
                '--state', str(state), '--output', str(output), '--window', '1h')
        first = run_checker(*args)
        assert first.returncode == 0, first.stderr
        text = first.stdout
        assert 'category=5xx count=1' in text, text
        assert text.count('category=slow count=1') == 2, text
        assert 'category=max_children count=1' in text, text
        assert 'pool=www limit=8' in text, text
        assert 'category=media_denied count=1' in text, text
        assert 'SECRET' not in text + first.stderr, 'query string leaked into alert output'
        assert output.read_text(encoding='utf-8').count('ALERT') == 5

        second = run_checker(*args)
        assert second.returncode == 0, second.stderr
        assert second.stdout == '', f'duplicate alerts on second scan: {second.stdout!r}'

        with access.open('a', encoding='utf-8') as handle:
            handle.write(access_line(now - timedelta(seconds=30), 503, 0.200, 'e' * 32) + '\n')
        third = run_checker(*args)
        assert third.returncode == 0, third.stderr
        assert 'category=5xx count=1' in third.stdout, third.stdout
        assert 'category=slow' not in third.stdout, third.stdout

        state_doc = json.loads(state.read_text(encoding='utf-8'))
        assert isinstance(state_doc.get('seen'), dict) and state_doc['seen'], 'state was not written'

        checker = load_checker_module()
        original_urlopen = checker.urllib.request.urlopen
        secret_url = 'https://hooks.example.test/secret?token=SECRET'

        def failing_urlopen(*_args, **_kwargs):
            raise HTTPError(secret_url, 503, 'unavailable', {}, None)

        checker.urllib.request.urlopen = failing_urlopen
        stderr = io.StringIO()
        try:
            with contextlib.redirect_stderr(stderr):
                checker.send_webhook(secret_url, [{'message': 'test'}])
        finally:
            checker.urllib.request.urlopen = original_urlopen
        assert 'SECRET' not in stderr.getvalue(), 'webhook URL leaked into diagnostics'
        assert 'http_status=503' in stderr.getvalue(), stderr.getvalue()

    print('PASS: alert scanner detects 5xx, slow, max_children and media_denied')
    print('PASS: alert scanner deduplicates scans and never emits query strings')
    print('PASS: webhook failures do not leak the webhook URL or token')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
