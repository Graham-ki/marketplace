<?php
require_once __DIR__ . '/../app/core/Session.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/config/config.php';
Session::start();

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { echo json_encode(['ok'=>false]); exit; }

$token = $_SESSION['draft_token'] ?? null;
if (!$token) { echo json_encode(['ok'=>false]); exit; }

$role = in_array($body['role'] ?? '', ['buyer','seller'], true) ? $body['role'] : 'seller';
$data = $body['data'] ?? [];

$pdo = Database::getInstance()->pdo();
$pdo->prepare("
    INSERT INTO draft_sessions (session_token, role, payload, expires_at)
    VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
    ON DUPLICATE KEY UPDATE payload = VALUES(payload), role = VALUES(role)
")->execute([$token, $role, json_encode($data)]);

echo json_encode(['ok'=>true]);