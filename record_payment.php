<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'seller') json_out(['ok'=>false,'error'=>'Sellers only'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'POST only']);
if (!csrf_ok($_POST['csrf'] ?? null)) json_out(['ok'=>false,'error'=>'CSRF'], 419);

$orderId = (int)($_POST['order_id'] ?? 0);
$amount  = (float)($_POST['amount'] ?? 0);
$method  = $_POST['method'] ?? 'cash';
$status  = $_POST['status'] ?? 'completed';
$ref     = trim($_POST['reference'] ?? '');

if (!$orderId || $amount <= 0) json_out(['ok'=>false,'error'=>'Order and amount required']);
if (!in_array($method, ['cash','card','mobile_money','bank','other'], true)) $method = 'cash';
if (!in_array($status, ['pending','completed','failed'], true)) $status = 'pending';

$pdo = db();

try {
    $pdo->beginTransaction();

    // ── Verify ownership and lock the order row ──
    $chk = $pdo->prepare("
        SELECT o.id, o.order_code, o.buyer_id
        FROM orders o
        WHERE o.id = ? AND o.seller_id = ?
        FOR UPDATE
    ");
    $chk->execute([$orderId, $u['id']]);
    $order = $chk->fetch();
    if (!$order) throw new Exception('Order not found');

    // ── Is there already a payment row for this order? ──
    $pay = $pdo->prepare("
        SELECT id, payment_code, status
        FROM payments
        WHERE order_id = ?
        ORDER BY id ASC
        LIMIT 1
        FOR UPDATE
    ");
    $pay->execute([$orderId]);
    $existing = $pay->fetch();

    $paidAt = $status === 'completed' ? 'NOW()' : 'NULL';

    if ($existing) {
        // ── UPDATE the existing payment ──
        $pdo->prepare("
            UPDATE payments
            SET amount = ?, method = ?, reference = ?, status = ?,
                paid_at = " . ($status === 'completed' ? 'COALESCE(paid_at, NOW())' : 'NULL') . "
            WHERE id = ?
        ")->execute([$amount, $method, $ref, $status, (int)$existing['id']]);

        $paymentCode = $existing['payment_code'];
        $mode = 'updated';
    } else {
        // ── No payment row yet — insert one ──
        $paymentCode = 'PAY-' . strtoupper(bin2hex(random_bytes(4)));
        $pdo->prepare("
            INSERT INTO payments
                (payment_code, order_id, user_id, amount, method, reference, status, paid_at)
            VALUES (?,?,?,?,?,?,?, " . $paidAt . ")
        ")->execute([
            $paymentCode,
            $orderId,
            $order['buyer_id'],
            $amount,
            $method,
            $ref,
            $status,
        ]);

        $mode = 'created';
    }

    // ── Notify buyer on completion ──
    if ($status === 'completed') {
        $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, link)
            VALUES (?,?,?,?,?)
        ")->execute([
            $order['buyer_id'],
            'payment',
            'Payment received',
            "Payment of " . number_format($amount, 2)
                . " recorded for order {$order['order_code']}.",
            'dashboard.php?tab=payments',
        ]);
    }

    $pdo->commit();

    json_out([
        'ok'           => true,
        'mode'         => $mode,          // 'updated' | 'created'
        'payment_code' => $paymentCode,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[record_payment] ' . $e->getMessage());
    json_out([
        'ok'    => false,
        'error' => APP_ENV === 'development' ? $e->getMessage() : 'Could not record payment.',
    ]);
}