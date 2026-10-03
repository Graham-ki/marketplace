<?php
require_once __DIR__ . '/../app/core/Session.php';
require_once __DIR__ . '/../app/config/config.php';
Session::start();

header('Content-Type: application/json');

if (empty($_FILES['image'])) {
    echo json_encode(['ok'=>false,'error'=>'No file']); exit;
}
$f = $_FILES['image'];
$allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];

$mime = @mime_content_type($f['tmp_name']);
if (!isset($allowed[$mime]) || $f['size'] > 5*1024*1024) {
    echo json_encode(['ok'=>false,'error'=>'Invalid file type or size']); exit;
}

$sid = session_id();
$dir = UPLOAD_PATH . '/temp/' . $sid;
if (!is_dir($dir)) mkdir($dir, 0755, true);

$name = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
$dest = "$dir/$name";

if (!move_uploaded_file($f['tmp_name'], $dest)) {
    echo json_encode(['ok'=>false,'error'=>'Upload failed']); exit;
}

echo json_encode([
    'ok'   => true,
    'path' => "temp/$sid/$name"
]);