<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'seller') json_out(['ok'=>false,'error'=>'Sellers only'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'POST only']);
if (!csrf_ok($_POST['csrf'] ?? null)) json_out(['ok'=>false,'error'=>'CSRF'], 419);
if (($_POST['action'] ?? '') !== 'delete') json_out(['ok'=>false,'error'=>'Invalid action']);

$orderId = (int)($_POST['order_id'] ?? 0);
if (!$orderId) json_out(['ok'=>false,'error'=>'Missing order_id']);

$pdo = db();

try {
    $pdo->beginTransaction();

    // Lock the order + verify ownership
    $stmt = $pdo->prepare("
        SELECT o.id, o.order_code, o.buyer_id, o.status,
               o.product_id, o.quantity
        FROM orders o
        WHERE o.id = ? AND o.seller_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$orderId, $u['id']]);
    $order = $stmt->fetch();
    if (!$order) throw new Exception('Order not found');

    // Optional: return stock to inventory
    // Uncomment if you want deletions to restore stock.
    /*
    $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")
        ->execute([(int)$order['quantity'], (int)$order['product_id']]);

    $pdo->prepare("INSERT INTO stock_movements (product_id, delta, reason, note, user_id)
                   VALUES (?, ?, 'adjustment', ?, ?)")
        ->execute([
            (int)$order['product_id'],
            (int)$order['quantity'],
            "Stock restored from deleted order {$order['order_code']}",
            $u['id'],
        ]);
    */

    // Delete payment(s) linked to this order first (FK safety)
    $pdo->prepare("DELETE FROM payments WHERE order_id = ?")->execute([$orderId]);

    // Delete the order
    $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$orderId]);

    // Notify buyer
    $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,link)
                   VALUES (?,?,?,?,?)")
        ->execute([
            (int)$order['buyer_id'],
            'order',
            'Order removed',
            "Order {$order['order_code']} has been removed by the seller.",
            'dashboard.php?tab=orders',
        ]);

    $pdo->commit();

    json_out(['ok'=>true]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[order_delete] ' . $e->getMessage());
    json_out([
        'ok'=>false,
        'error'=> APP_ENV === 'development' ? $e->getMessage() : 'Could not delete order.',
    ]);
}