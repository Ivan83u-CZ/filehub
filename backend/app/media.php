<?php
declare(strict_types=1);
// Stage05 protected media contract test. mTLS identity already checked in api.php; X-API-KEY not required for browser media GETs.
// Fixture mode only: NEVER proxies patient content from a real Mail.ru account.
$mode = getenv('FILEHUB_DAV_MODE') ?: 'fixture';
if ($mode === 'test_crm_ivan0082') {
    require __DIR__ . '/webdav_media_test.php';
}
if ($mode !== 'fixture') respond(403, ['error'=>'real_patient_media_disabled']);
$leadId = $_GET['leadId'] ?? '';
$cat = $_GET['category'] ?? '';
$name = $_GET['name'] ?? '';
if (!is_string($leadId) || !preg_match('/^[1-9][0-9]{0,17}$/D', $leadId)) respond(400, ['error'=>'invalid_lead_id']);
if (!is_string($cat) || !in_array($cat, ['photos','calls'], true)) respond(400, ['error'=>'invalid_category']);
if (!is_string($name) || $name === '' || strlen($name) > 160 || preg_match('/[\x00-\x1f\x7f]/', $name) || strpbrk($name, '/\\') !== false || $name === '.' || $name === '..' || str_contains($name, '..')) {
    respond(400, ['error'=>'invalid_name']);
}
if ($leadId !== '123') respond(403, ['error'=>'fixture_lead_only']);
$allowed = [
    'photos' => [
        'fixture-test.png' => ['png', 'image/png'],
        'fixture-test.txt' => ['txt', 'text/plain; charset=utf-8'],
    ],
    'calls' => [
        'fixture-test.wav' => ['wav', 'audio/wav'],
    ],
];
if (!isset($allowed[$cat][$name])) respond(404, ['error'=>'fixture_file_not_found']);
if ($route === 'thumb') {
    if ($cat !== 'photos' || $name !== 'fixture-test.png') respond(415, ['error'=>'thumbnail_requires_image']);
    foreach (['w','h'] as $dim) {
        $v = $_GET[$dim] ?? '160';
        if (!is_string($v) || !ctype_digit($v) || (int)$v < 1 || (int)$v > 1024) respond(400, ['error'=>'invalid_thumbnail_dimension']);
    }
}
[$extension, $mime] = $allowed[$cat][$name];
$filePath = '/var/www/fixtures/media/' . $name;
if (!is_file($filePath) || !is_readable($filePath)) respond(503, ['error'=>'fixture_missing']);
$size = filesize($filePath);
if ($size === false || $size < 1 || $size > 2097152) respond(503, ['error'=>'fixture_invalid_size']);
$start = 0;
$end = $size - 1;
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range !== '') {
    if (!is_string($range) || !preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $m) || ($m[1] === '' && $m[2] === '')) {
        header('Content-Range: bytes */' . $size);
        respond(416, ['error'=>'invalid_range']);
    }
    if ($m[1] === '') {
        $suffix = (int)$m[2];
        if ($suffix < 1) { header('Content-Range: bytes */' . $size); respond(416, ['error'=>'invalid_range']); }
        $start = max(0, $size - $suffix);
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') $end = min($size - 1, (int)$m[2]);
    }
    if ($start >= $size || $start > $end) { header('Content-Range: bytes */' . $size); respond(416, ['error'=>'invalid_range']); }
}
$handle = fopen($filePath, 'rb');
if ($handle === false) respond(503, ['error'=>'fixture_unreadable']);
if (fseek($handle, $start) !== 0) { fclose($handle); respond(503, ['error'=>'fixture_seek_failed']); }
http_response_code($range === '' ? 200 : 206);
header_remove('Content-Type');
header('Content-Type: ' . $mime);
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
if ($range !== '') header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
// Legacy widget uses ?download=1. No public link is created.
$download = $route === 'file' && ($_GET['download'] ?? '') === '1';
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"');
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($handle)) {
    $part = fread($handle, min(8192, $remaining));
    if ($part === false || $part === '') break;
    echo $part;
    $remaining -= strlen($part);
}
fclose($handle);
exit;
