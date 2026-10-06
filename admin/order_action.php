<?php
require __DIR__ . '/../db.php';

$admin = current_user();
if (!$admin || $admin['role'] !== 'admin') {
    http_response_code(403); exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok($_POST['csrf'] ?? null)) {
    http_response_code(419); exit('CSRF');
}

$id     = (int)($_POST['order_id'] ?? 0);
$action = $_POST['action'] ?? '';
$status = $_POST['status'] ?? '';

if (!$id) { header('Location: orders.php'); exit; }

$pdo = db();
$log = $pdo->prepare("
    INSERT INTO admin_log (admin_id, action, target_user_id, details)
    VALUES (?, ?, NULL, ?)
");

if ($action === 'delete') {
    $pdo->prepare("DELETE FROM payments WHERE order_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$id]);
    $log->execute([$admin['id'], 'delete_order', "order_id={$id}"]);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true]); exit;
}

if (in_array($status, ['pending','confirmed','shipped','delivered','cancelled'], true)) {
    $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?")->execute([$status, $id]);
    $log->execute([$admin['id'], 'order_status', "order_id={$id} -> {$status}"]);
}

header('Location: orders.php');