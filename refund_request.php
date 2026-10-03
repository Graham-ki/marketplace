<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'buyer' || $_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok($_POST['csrf'] ?? null)) {
    header('Location: dashboard.php'); exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM orders WHERE id=? AND buyer_id=?");
$stmt->execute([$orderId, $u['id']]);
$o = $stmt->fetch();
if (!$o) { header('Location: dashboard.php?tab=orders'); exit; }

$code = 'REF-' . strtoupper(bin2hex(random_bytes(4)));

db()->prepare("INSERT INTO refunds (refund_code,order_id,requested_by,seller_id,amount,reason)
               VALUES (?,?,?,?,?,?)")
    ->execute([$code, $orderId, $u['id'], $o['seller_id'], $o['total_amount'], $_POST['reason'] ?? 'Buyer requested refund']);

db()->prepare("INSERT INTO notifications (user_id,type,title,message,link)
               VALUES (?,?,?,?,?)")
    ->execute([$o['seller_id'], 'refund_request', 'New refund request',
               "A refund was requested for order {$o['order_code']}.", 'dashboard.php?tab=refunds']);

header('Location: dashboard.php?tab=refunds'); exit;