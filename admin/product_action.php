<?php
require __DIR__ . '/../db.php';

$admin = current_user();
if (!$admin || $admin['role'] !== 'admin') {
    http_response_code(403); exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok($_POST['csrf'] ?? null)) {
    http_response_code(419); exit('CSRF');
}

$id     = (int)($_POST['product_id'] ?? 0);
$action = $_POST['action'] ?? '';
if (!$id || !$action) { header('Location: products.php'); exit; }

$pdo = db();
$log = $pdo->prepare("
    INSERT INTO admin_log (admin_id, action, target_user_id, details)
    VALUES (?, ?, NULL, ?)
");

switch ($action) {
    case 'suspend':
        $pdo->prepare("UPDATE products SET status='suspended' WHERE id=?")->execute([$id]);
        $log->execute([$admin['id'], 'suspend_product', "product_id={$id}"]);
        break;
    case 'activate':
        $pdo->prepare("UPDATE products SET status='active' WHERE id=?")->execute([$id]);
        $log->execute([$admin['id'], 'activate_product', "product_id={$id}"]);
        break;
    case 'delete':
        $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
        $log->execute([$admin['id'], 'delete_product', "product_id={$id}"]);
        header('Content-Type: application/json');
        echo json_encode(['ok'=>true]); exit;
}

header('Location: products.php');