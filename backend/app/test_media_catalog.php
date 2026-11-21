<?php
declare(strict_types=1);
// Explicit immutable allowlist of local synthetic test media. Never a real patient file.
const FH_MEDIA_TEST = [
  'photos' => [
    'fh-media-rc02-photo.png' => ['image/png', 'fh-media-rc02-photo-thumb.png'],
  ],
  'report' => [
    'fh-media-rc02-report.pdf' => ['application/pdf', null],
  ],
  'other' => [
    'fh-media-rc02-audio.wav' => ['audio/wav', null],
    'fh-media-rc02-video.mp4' => ['video/mp4', null],
  ],
];
function fh_media_test_entry(string $category,string $name): ?array {
    if (getenv('FILEHUB_DAV_MODE') !== 'test_crm_ivan0082'
      || getenv('FILEHUB_TEST_LEAD_ID') !== '34228264') return null;
    return FH_MEDIA_TEST[$category][$name] ?? null;
}
function fh_media_test_path(string $file): string {
    // $file is exclusively a hardcoded filename from FH_MEDIA_TEST, not query input.
    return '/var/www/fixtures/media/'.$file;
}
function fh_media_test_items(string $category): array {
    if (getenv('FILEHUB_DAV_MODE') !== 'test_crm_ivan0082'
      || getenv('FILEHUB_TEST_LEAD_ID') !== '34228264') return [];
    $results=[];
    foreach ((FH_MEDIA_TEST[$category] ?? []) as $filename=>$entry) {
        $file=fh_media_test_path($filename);
        if (!is_file($file) || !is_readable($file)) continue;
        $size=filesize($file);
        if (!is_int($size) || $size < 1 || $size > 2097152) continue;
        $mod=filemtime($file);
        $results[]=[
            'name'=>$filename,
            'size'=>$size,
            'contentType'=>$entry[0],
            'lastmod'=>is_int($mod)?gmdate('D, d M Y H:i:s',$mod).' GMT':'',
            'isSyntheticExample'=>true,
        ];
    }
    return $results;
}
