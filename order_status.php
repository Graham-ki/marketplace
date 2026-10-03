<?php
require __DIR__ . '/db.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }
if (!csrf_ok($_POST['csrf'] ?? null))       { header('Location: dashboard.php'); exit; }

$u = current_user();
if ($u['role'] !== 'seller') { http_response_code(403); exit; }

$orderId = (int)($_POST['order_id'] ?? 0);
$status  = $_POST['status'] ?? '';
if (!in_array($status, ['pending','confirmed','shipped','delivered','cancelled'], true)) {
    header('Location: dashboard.php?tab=orders'); exit;
}

// Only the seller of that order may change it
$stmt = db()->prepare("SELECT id, buyer_id, order_code FROM orders WHERE id=? AND seller_id=?");
$stmt->execute([$orderId, $u['id']]);
$o = $stmt->fetch();
if (!$o) { http_response_code(403); exit; }

db()->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$status, $orderId]);

// Notify buyer
db()->prepare("INSERT INTO notifications (user_id,type,title,message,link)
               VALUES (?,?,?,?,?)")
    ->execute([
        $o['buyer_id'], 'order_status', 'Order updated',
        "Your order {$o['order_code']} is now {$status}.",
        'dashboard.php?tab=orders'
    ]);

header('Location: dashboard.php?tab=orders'); exit;