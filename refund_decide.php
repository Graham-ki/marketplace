<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'seller' || $_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok($_POST['csrf'] ?? null)) {
    header('Location: dashboard.php'); exit;
}

$refundId = (int)($_POST['refund_id'] ?? 0);
$decision = $_POST['decision'] ?? '';
if (!in_array($decision, ['approved','rejected'], true)) { header('Location: dashboard.php?tab=refunds'); exit; }

$stmt = db()->prepare("SELECT * FROM refunds WHERE id=? AND seller_id=?");
$stmt->execute([$refundId, $u['id']]);
$r = $stmt->fetch();
if (!$r) { header('Location: dashboard.php?tab=refunds'); exit; }

db()->prepare("UPDATE refunds SET status=?, decided_at=NOW() WHERE id=?")
    ->execute([$decision, $refundId]);

db()->prepare("INSERT INTO notifications (user_id,type,title,message,link)
               VALUES (?,?,?,?,?)")
    ->execute([$r['requested_by'], 'refund_status', 'Refund ' . $decision,
               "Your refund {$r['refund_code']} was {$decision}.", 'dashboard.php?tab=refunds']);

header('Location: dashboard.php?tab=refunds'); exit;