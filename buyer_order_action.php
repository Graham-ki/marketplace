<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'buyer') json_out(['ok'=>false,'error'=>'Buyers only'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'POST only']);
if (!csrf_ok($_POST['csrf'] ?? null)) json_out(['ok'=>false,'error'=>'CSRF'], 419);

$action  = $_POST['action'] ?? '';
$orderId = (int)($_POST['order_id'] ?? 0);
if (!$orderId) json_out(['ok'=>false,'error'=>'Missing order_id']);

$pdo = db();

try {
    $pdo->beginTransaction();

    // Lock + verify ownership
    $stmt = $pdo->prepare("
        SELECT id, order_code, seller_id, status
        FROM orders
        WHERE id = ? AND buyer_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$orderId, $u['id']]);
    $order = $stmt->fetch();
    if (!$order) throw new Exception('Order not found');

    /* ═══════════════════════════════════════════════
       CANCEL
       Only allowed while pending or confirmed.
       ═══════════════════════════════════════════════ */
    if ($action === 'cancel') {
        if (!in_array($order['status'], ['pending','confirmed'], true)) {
            throw new Exception('This order can no longer be cancelled.');
        }

        $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")
            ->execute([$orderId]);

        // Notify the seller
        $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,link)
                       VALUES (?,?,?,?,?)")
            ->execute([
                (int)$order['seller_id'],
                'order_status',
                'Order cancelled by buyer',
                "Buyer cancelled order {$order['order_code']}.",
                'dashboard.php?tab=orders',
            ]);

        $pdo->commit();
        json_out(['ok'=>true, 'status'=>'cancelled']);
    }

    /* ═══════════════════════════════════════════════
       DELETE
       Only allowed on cancelled orders (buyer's own history).
       ═══════════════════════════════════════════════ */
    if ($action === 'delete') {
        if ($order['status'] !== 'cancelled') {
            throw new Exception('Only cancelled orders can be deleted. Cancel it first.');
        }

        // Remove any payment rows first (FK safety)
        $pdo->prepare("DELETE FROM payments WHERE order_id = ?")->execute([$orderId]);

        // Remove the order
        $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$orderId]);

        // Notify the seller
        $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,link)
                       VALUES (?,?,?,?,?)")
            ->execute([
                (int)$order['seller_id'],
                'order_status',
                'Order removed by buyer',
                "Order {$order['order_code']} was deleted by the buyer.",
                'dashboard.php?tab=orders',
            ]);

        $pdo->commit();
        json_out(['ok'=>true, 'status'=>'deleted']);
    }

    throw new Exception('Unknown action');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[buyer_order_action] ' . $e->getMessage());
    json_out([
        'ok'    => false,
        'error' => APP_ENV === 'development' ? $e->getMessage() : 'Could not complete action.',
    ]);
}