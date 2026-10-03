<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'seller') json_out(['ok'=>false,'error'=>'Sellers only'], 403);

$unpaidOnly = isset($_GET['unpaid']);

// ─────────────────────────────────────────────
// Return each order together with its (single) payment row.
// LEFT JOIN so orders without a payment row still appear.
// ─────────────────────────────────────────────
$sql = "
    SELECT o.id, o.order_code, o.total_amount, o.status,
           p.title AS product_title,
           pay.id            AS payment_id,
           pay.payment_code  AS payment_code,
           pay.amount        AS payment_amount,
           pay.method        AS payment_method,
           pay.reference     AS payment_reference,
           pay.status        AS payment_status
    FROM orders o
    JOIN products p ON p.id = o.product_id
    LEFT JOIN payments pay ON pay.order_id = o.id
    WHERE o.seller_id = ?
      AND o.status NOT IN ('cancelled')
";

if ($unpaidOnly) {
    // Show orders whose payment is missing OR not yet completed
    $sql .= " AND (pay.id IS NULL OR pay.status <> 'completed')";
}

$sql .= " ORDER BY o.created_at DESC LIMIT 100";

$stmt = db()->prepare($sql);
$stmt->execute([$u['id']]);

json_out(['ok'=>true, 'orders'=>$stmt->fetchAll()]);