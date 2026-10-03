<?php
require __DIR__ . '/db.php';
header('Content-Type: application/json');

if (empty($_FILES['image'])) json_out(['ok'=>false,'error'=>'No file']);

$f = $_FILES['image'];
$allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
$mime = @mime_content_type($f['tmp_name']);

if (!isset($allowed[$mime]) || $f['size'] > 5*1024*1024) {
    json_out(['ok'=>false,'error'=>'Invalid file']);
}

$sid = session_id();
$dir = __DIR__ . '/uploads/temp/' . $sid;
if (!is_dir($dir)) mkdir($dir, 0755, true);

$name = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) {
    json_out(['ok'=>false,'error'=>'Upload failed']);
}

json_out(['ok'=>true, 'path'=>"temp/$sid/$name"]);