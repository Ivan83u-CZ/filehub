<?php
declare(strict_types=1);
require __DIR__.'/fh_client_events.php';

function fhct_assert(bool $condition, string $name): void {
    if (!$condition) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}

function fhct_rejects(callable $callback): bool {
    try { $callback(); return false; }
    catch (Throwable) { return true; }
}

$event = fh_telemetry_normalize_event([
    'schema_version' => 1,
    'widget_version' => '2.8.15',
    'event' => 'list_failed',
    'feature' => 'files',
    'event_id' => 'cbb27917-303c-454d-95e1-6694cb6bc736',
    'error_code' => 'session_reauth_blocked',
    'http_status' => 0,
    'duration_ms' => 0,
    'corr_id' => 'a190dfd9-399a-40aa-a362-885c1a47a8d0',
    'operator_id' => '12202974',
    'lead_id' => '33942619',
    'category' => 'photos',
    'client_time_ms' => 1795047998000,
]);
fhct_assert($event['operator_id'] === '12202974', 'operator id is normalized');
fhct_assert($event['lead_id'] === '33942619', 'lead id is preserved');
fhct_assert($event['category'] === 'photos' && $event['error_code'] === 'session_reauth_blocked',
    'error details are preserved');
fhct_assert($event['client_time_ms'] === 1795047998000, 'client occurrence time is preserved');
fhct_assert($event['event_id'] === 'cbb27917-303c-454d-95e1-6694cb6bc736', 'retry id is preserved');
fh_telemetry_assert_operator($event, 12202974);
fhct_assert(fhct_rejects(fn() => fh_telemetry_assert_operator($event, 7)),
    'operator spoofing is rejected');
$eventIndex = fopen('php://temp', 'w+');
fwrite($eventIndex, json_encode([
    'account_id' => 'account-1', 'operator_id' => 12202974, 'event_id' => $event['event_id'],
]) . "\n");
$seenEventIds = fh_telemetry_existing_event_ids($eventIndex);
$freshRows = fh_telemetry_filter_fresh_rows([
    ['event' => ['event_id' => $event['event_id']]],
    ['event' => ['event_id' => 'c80c1365-e03b-4e0b-9acf-36a72cb52772']],
    ['event' => ['event_id' => 'c80c1365-e03b-4e0b-9acf-36a72cb52772']],
], $seenEventIds, 'account-1:12202974');
fclose($eventIndex);
fhct_assert(count($freshRows) === 1, 'replayed and same-batch event ids are deduplicated');
$otherActorRows = fh_telemetry_filter_fresh_rows([
    ['event' => ['event_id' => $event['event_id']]],
], $seenEventIds, 'account-1:7');
fhct_assert(count($otherActorRows) === 1, 'event ids are isolated by authenticated actor');
fhct_assert(fh_telemetry_operator_name(12202974) === 'Оксана',
    'operator name comes from authenticated id map');
fhct_assert(fh_telemetry_operator_name(99999999) === null,
    'unknown operator ids remain identifiable without a guessed name');
fhct_assert(fhct_rejects(fn() => fh_telemetry_normalize_event([
    'schema_version' => 1, 'widget_version' => '2.8.15', 'event' => 'list_failed',
    'feature' => 'files', 'lead_id' => '33942619/other',
])), 'malformed lead id is rejected');
fhct_assert(fhct_rejects(fn() => fh_telemetry_normalize_event([
    'schema_version' => 1, 'widget_version' => '2.8.15', 'event' => 'list_failed',
    'feature' => 'files', 'event_id' => 'duplicate-or-invalid',
])), 'malformed retry id is rejected');
fhct_assert(fh_telemetry_normalize_event([
    'schema_version' => 1, 'widget_version' => '2.8.15', 'event' => 'list_failed',
    'feature' => 'files', 'event_id' => 'evt_m8x2_a31_2',
])['event_id'] === 'evt_m8x2_a31_2', 'fallback retry id is accepted');
fhct_assert(fhct_rejects(fn() => fh_telemetry_normalize_event([
    'schema_version' => 1, 'widget_version' => '2.8.15', 'event' => 'list_failed',
    'feature' => 'files', 'unexpected_stack' => 'sensitive details',
])), 'unapproved error details are rejected');

echo "PASS: client telemetry tests complete.\n";
