<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$health = ['service'=>'filehub-unified','phase'=>'rc02','status'=>'ok'];
$pool = getenv('FILEHUB_HEALTH_POOL');
if ($pool !== false) $health['pool'] = $pool;
echo json_encode($health,JSON_UNESCAPED_SLASHES);
