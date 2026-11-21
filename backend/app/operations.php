<?php
declare(strict_types=1);
// Stage06: explicit synthetic CRUD sandbox. This file performs ZERO WebDAV mutations.
// Config guard operates independently of reverse proxy. Never use for patient content.
$mode = getenv('FILEHUB_DAV_MODE') ?: 'fixture';
if ($mode === 'test_crm_ivan0082') {
    require __DIR__ . '/webdav_ops_test.php';
}
if ($mode !== 'fixture') respond(503, ['error' => 'writes_disabled_in_mail_mode']);
$dir = getenv('FILEHUB_FIXTURE_SCRATCH') ?: '/var/www/scratch';
if (!is_dir($dir) || !is_writable($dir)) respond(503, ['error' => 'fixture_scratch_unavailable']);
$lead = (string)($_POST['leadId'] ?? $_GET['leadId'] ?? '');
$category = (string)($_POST['category'] ?? $_GET['category'] ?? '');
$payload = [];
if ($route === 'rename') {
    $raw = file_get_contents('php://input');
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($payload)) respond(400, ['error' => 'invalid_json']);
    $lead = (string)($payload['leadId'] ?? '');
    $category = (string)($payload['category'] ?? '');
}
if (!preg_match('/^[1-9][0-9]{0,17}$/D', $lead)) respond(400, ['error' => 'invalid_lead_id']);
if ($lead !== '123') respond(403, ['error' => 'fixture_lead_only']);
if ($category !== 'other') respond(403, ['error' => 'fixture_category_only']);
function fixture_filename(mixed $name): bool {
    return is_string($name) && preg_match('/^fixture-[A-Za-z0-9_-]{1,64}\.txt$/D', $name) === 1;
}
if ($route === 'upload') {
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) respond(400, ['error' => 'no_file']);
    $up = $_FILES['file'];
    $name = $up['name'] ?? null;
    if (!fixture_filename($name)) respond(400, ['error' => 'invalid_fixture_filename']);
    if (($up['error'] ?? null) !== UPLOAD_ERR_OK) respond(400, ['error' => 'upload_failed']);
    if (!is_int($up['size'] ?? null) || $up['size'] < 1 || $up['size'] > 131072) respond(413, ['error' => 'fixture_too_large']);
    if (!is_string($up['tmp_name'] ?? null) || !is_uploaded_file($up['tmp_name'])) respond(400, ['error' => 'invalid_upload']);
    $dest = $dir . '/' . $name;
    $out = @fopen($dest, 'xb'); // fails atomically if destination exists
    if ($out === false) respond(409, ['error' => 'fixture_already_exists']);
    $input = @fopen($up['tmp_name'], 'rb');
    if ($input === false) { fclose($out); unlink($dest); respond(503, ['error' => 'fixture_read_failed']); }
    $written = stream_copy_to_stream($input, $out, 131073);
    fclose($input);
    fclose($out);
    if ($written !== $up['size']) { unlink($dest); respond(503, ['error' => 'fixture_write_failed']); }
    respond(200, ['ok' => true]);
}
if ($route === 'rename') {
    $old = $payload['name'] ?? null;
    $new = $payload['new_name'] ?? null;
    if (!fixture_filename($old) || !fixture_filename($new)) respond(400, ['error' => 'invalid_fixture_filename']);
    if ($old === $new) respond(400, ['error' => 'same_name']);
    $source = $dir . '/' . $old;
    $dest = $dir . '/' . $new;
    if (!is_file($source)) respond(404, ['error' => 'fixture_file_not_found']);
    if (file_exists($dest)) respond(409, ['error' => 'fixture_already_exists']);
    if (!@rename($source, $dest)) respond(503, ['error' => 'fixture_rename_failed']);
    respond(200, ['ok' => true]);
}
if ($route === 'file' && $method === 'DELETE') {
    $name = $_GET['name'] ?? null;
    if (!fixture_filename($name)) respond(400, ['error' => 'invalid_fixture_filename']);
    $file = $dir . '/' . $name;
    if (!is_file($file)) respond(404, ['error' => 'fixture_file_not_found']);
    if (!@unlink($file)) respond(503, ['error' => 'fixture_delete_failed']);
    respond(200, ['ok' => true]);
}
respond(503, ['error' => 'fixture_operation_not_supported']);
