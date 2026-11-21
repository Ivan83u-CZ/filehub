#!/usr/bin/env python3
"""Read-only FileHub client telemetry summary.

Default source: /opt/filehub-unified-rc02/state/ops10/telemetry/raw/events-YYYY-MM-DD.jsonl
The script prints only allowlisted diagnostic fields; lead_id and operator_name are omitted.
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from collections import Counter, defaultdict
from datetime import datetime, timedelta, timezone
from pathlib import Path

DEFAULT_DIR = "/opt/filehub-unified-rc02/state/ops10/telemetry"
TARGET_VERSION = "2.8.21"


def utc_day(days_ago: int = 0) -> str:
    return (datetime.now(timezone.utc) - timedelta(days=days_ago)).strftime("%Y-%m-%d")


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Summarize FileHub client telemetry JSONL")
    p.add_argument("--dir", default=os.environ.get("FILEHUB_TELEMETRY_DIR", DEFAULT_DIR))
    p.add_argument("--date", help="single UTC day, YYYY-MM-DD")
    p.add_argument("--days", type=int, default=2, help="days back from today (default: 2; 0 = all files)")
    p.add_argument("--limit", type=int, default=10, help="number of latest events to print")
    p.add_argument("--target", default=TARGET_VERSION, help="widget version to look for")
    return p.parse_args()


def dates_to_scan(args: argparse.Namespace) -> list[str]:
    if args.date:
        return [args.date]
    return [utc_day(i) for i in range(max(args.days, 1))]


def load_jsonl(path: Path) -> tuple[list[dict], int]:
    rows: list[dict] = []
    bad = 0
    with path.open("r", encoding="utf-8", errors="replace") as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            try:
                obj = json.loads(line)
            except json.JSONDecodeError:
                bad += 1
                continue
            if isinstance(obj, dict):
                rows.append(obj)
            else:
                bad += 1
    return rows, bad


def ts(value) -> str:
    if not isinstance(value, (int, float)):
        return "-"
    try:
        return datetime.fromtimestamp(value, timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    except (OverflowError, OSError, ValueError):
        return "-"


def counter_text(counter: Counter, empty: str = "none") -> str:
    if not counter:
        return empty
    return ", ".join(f"{k}={v}" for k, v in counter.most_common())


def summarize_file(path: Path, rows: list[dict], bad: int, target: str, limit: int) -> Counter:
    versions = Counter()
    events = Counter()
    features = Counter()
    errors = Counter()
    statuses = Counter()
    operators: set[str] = set()
    version_first: dict[str, str] = {}
    version_last: dict[str, str] = {}
    ordered = sorted(rows, key=lambda r: r.get("received_at", 0) if isinstance(r.get("received_at"), (int, float)) else 0)

    for row in ordered:
        version = row.get("widget_version")
        if isinstance(version, str):
            versions[version] += 1
        event = row.get("event")
        if isinstance(event, str):
            events[event] += 1
        feature = row.get("feature")
        if isinstance(feature, str):
            features[feature] += 1
        error = row.get("error_code")
        if isinstance(error, str) and error:
            errors[error] += 1
        status = row.get("http_status")
        if isinstance(status, int) and status:
            statuses[str(status)] += 1
        operator = row.get("operator_id", row.get("user_id"))
        if operator not in (None, "", 0):
            operators.add(str(operator))
        if isinstance(version, str):
            seen = ts(row.get("received_at"))
            version_first.setdefault(version, seen)
            version_last[version] = seen

    print(f"\n== {path.name} ==")
    print(f"path:      {path}")
    print(f"size:      {path.stat().st_size} bytes")
    print(f"rows:      {len(rows)} valid, {bad} malformed")
    print(f"versions:  {counter_text(versions)}")
    for version in versions:
        print(f"  {version}: first={version_first.get(version, '-')} last={version_last.get(version, '-')}")
    print(f"events:    {counter_text(events)}")
    print(f"features:  {counter_text(features)}")
    print(f"errors:    {counter_text(errors)}")
    print(f"statuses:  {counter_text(statuses)}")
    print(f"operators: {len(operators)} unique")

    if ordered and limit > 0:
        print(f"latest {min(limit, len(ordered))}:")
        for row in ordered[-limit:]:
            print(
                "  {time} v={version} event={event} feature={feature} "
                "status={status} error={error} req={req}".format(
                    time=ts(row.get("received_at")),
                    version=row.get("widget_version", "-"),
                    event=row.get("event", "-"),
                    feature=row.get("feature", "-"),
                    status=row.get("http_status", "-"),
                    error=row.get("error_code") or "-",
                    req=row.get("request_id", "-"),
                )
            )

    agg = path.parent.parent / "agg" / f"agg-{path.stem.removeprefix('events-')}.json"
    if agg.is_file():
        try:
            data = json.loads(agg.read_text(encoding="utf-8"))
            print(
                "aggregate: events={events} statuses={statuses} errors={errors} updated={updated}".format(
                    events=counter_text(Counter(data.get("events", {}))),
                    statuses=counter_text(Counter({str(k): v for k, v in (data.get("http_status") or {}).items()})),
                    errors=counter_text(Counter(data.get("error_codes", {}))),
                    updated=ts(data.get("updated_at")),
                )
            )
        except (OSError, json.JSONDecodeError):
            print("aggregate: unreadable")
    return versions


def main() -> int:
    args = parse_args()
    root = Path(args.dir)
    raw = root / "raw"
    if not raw.is_dir():
        print(f"ERROR: telemetry raw directory not found: {raw}", file=sys.stderr)
        print("Run this script on the host as root, or pass --dir.", file=sys.stderr)
        return 1

    files: list[Path] = []
    if args.days == 0 and not args.date:
        files = sorted(raw.glob("events-*.jsonl"))
    else:
        for day in dates_to_scan(args):
            p = raw / f"events-{day}.jsonl"
            if p.is_file():
                files.append(p)

    if not files:
        print(f"ERROR: no telemetry JSONL found under {raw}", file=sys.stderr)
        print("Expected events-YYYY-MM-DD.jsonl. Check the date or use --days 0.", file=sys.stderr)
        return 1

    combined = Counter()
    for path in files:
        try:
            rows, bad = load_jsonl(path)
        except PermissionError:
            print(f"ERROR: permission denied: {path}", file=sys.stderr)
            print("Run this script as root (sudo).", file=sys.stderr)
            return 1
        except OSError as exc:
            print(f"ERROR: cannot read {path}: {exc}", file=sys.stderr)
            return 1
        combined.update(summarize_file(path, rows, bad, args.target, args.limit))

    print("\n== combined ==")
    print(f"versions: {counter_text(combined)}")
    if combined.get(args.target, 0) > 0:
        print(f"RESULT: OK - {args.target} telemetry is present ({combined[args.target]} rows)")
        return 0

    print(f"RESULT: NO {args.target} rows yet")
    if combined:
        print("Versions seen: " + ", ".join(sorted(combined)))
        print("Old tabs may keep sending the previous version until the card is reloaded.")
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
