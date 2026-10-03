<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'seller') json_out(['ok'=>false,'error'=>'Sellers only'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'POST only']);
if (!csrf_ok($_POST['csrf'] ?? null)) json_out(['ok'=>false,'error'=>'CSRF'], 419);

$pdo = db();

$paymentId = (int)($_POST['payment_id'] ?? 0);
if (!$paymentId) json_out(['ok'=>false,'error'=>'Missing payment_id']);

// ─── Ownership: payment must belong to an order sold by this seller ───
$own = $pdo->prepare("
    SELECT pay.id, pay.status, pay.amount, pay.method, pay.reference,
           pay.user_id, o.id AS order_id, o.order_code
    FROM payments pay
    JOIN orders o ON o.id = pay.order_id
    WHERE pay.id = ? AND o.seller_id = ?
");
$own->execute([$paymentId, $u['id']]);
$payment = $own->fetch();
if (!$payment) json_out(['ok'=>false,'error'=>'Payment not found'], 404);

/* ═══════════════════════════════════════════════
   DELETE
   ═══════════════════════════════════════════════ */
if (($_POST['action'] ?? '') === 'delete') {
    $pdo->prepare("DELETE FROM payments WHERE id = ?")->execute([$paymentId]);

    // Notify buyer (only if we had a completed payment — otherwise it's noise)
    if ($payment['status'] === 'completed') {
        $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,link)
                       VALUES (?,?,?,?,?)")
            ->execute([
                $payment['user_id'],
                'payment',
                'Payment record removed',
                "Payment for order {$payment['order_code']} was removed by the seller.",
                'dashboard.php?tab=payments',
            ]);
    }

    json_out(['ok'=>true, 'mode'=>'deleted']);
}

/* ═══════════════════════════════════════════════
   EDIT (full form) — amount, method, reference, status
   ═══════════════════════════════════════════════ */
if (($_POST['mode'] ?? '') === 'edit') {
    $amount = (float)($_POST['amount'] ?? 0);
    $method = $_POST['method'] ?? 'cash';
    $status = $_POST['status'] ?? 'pending';
    $ref    = trim($_POST['reference'] ?? '');

    if ($amount <= 0) json_out(['ok'=>false,'error'=>'Amount must be greater than 0']);
    if (!in_array($method, ['cash','card','mobile_money','bank','other'], true)) $method = 'cash';
    if (!in_array($status, ['pending','completed','failed'], true)) $status = 'pending';

    // Preserve paid_at if it was already set and status is still completed;
    // set it now if transitioning to completed for the first time.
    $paidAtSql = $status === 'completed'
        ? 'COALESCE(paid_at, NOW())'
        : 'NULL';

    $pdo->prepare("
        UPDATE payments
        SET amount = ?, method = ?, reference = ?, status = ?,
            paid_at = $paidAtSql
        WHERE id = ?
    ")->execute([$amount, $method, $ref, $status, $paymentId]);

    // Notify buyer if status changed to completed
    if ($status === 'completed' && $payment['status'] !== 'completed') {
        $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,link)
                       VALUES (?,?,?,?,?)")
            ->execute([
                $payment['user_id'],
                'payment',
                'Payment received',
                "Payment for order {$payment['order_code']} is now completed.",
                'dashboard.php?tab=payments',
            ]);
    }

    json_out(['ok'=>true, 'mode'=>'edited']);
}

/* ═══════════════════════════════════════════════
   SIMPLE METHOD CHANGE (inline select)
   ═══════════════════════════════════════════════ */
if (isset($_POST['method']) && !isset($_POST['mode'])) {
    $method = in_array($_POST['method'], ['cash','card','mobile_money','bank','other'], true)
        ? $_POST['method'] : 'cash';
    $pdo->prepare("UPDATE payments SET method = ? WHERE id = ?")
        ->execute([$method, $paymentId]);

    // If it was a classic form POST, redirect; if fetch, return JSON
    if (isset($_POST['return_json'])) {
        json_out(['ok'=>true]);
    }
    header('Location: dashboard.php?tab=payments');
    exit;
}

/* ═══════════════════════════════════════════════
   SIMPLE STATUS CHANGE (Mark paid / Mark failed)
   ═══════════════════════════════════════════════ */
if (isset($_POST['status']) && !isset($_POST['mode'])) {
    $status = in_array($_POST['status'], ['pending','completed','failed'], true)
        ? $_POST['status'] : 'pending';

    $paidAtSql = $status === 'completed'
        ? 'COALESCE(paid_at, NOW())'
        : 'NULL';

    $pdo->prepare("UPDATE payments SET status = ?, paid_at = $paidAtSql WHERE id = ?")
        ->execute([$status, $paymentId]);

    // Notify buyer on transitions
    if ($status === 'completed' && $payment['status'] !== 'completed') {
        $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,link)
                       VALUES (?,?,?,?,?)")
            ->execute([
                $payment['user_id'],
                'payment',
                'Payment received',
                "Payment for order {$payment['order_code']} is now completed.",
                'dashboard.php?tab=payments',
            ]);
    }

    // Classic form POST → redirect
    header('Location: dashboard.php?tab=payments');
    exit;
}

json_out(['ok'=>false, 'error'=>'No action specified']);