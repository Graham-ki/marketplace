<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'seller') json_out(['ok'=>false,'error'=>'Sellers only'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'POST only']);
if (!csrf_ok($_POST['csrf'] ?? null)) json_out(['ok'=>false,'error'=>'CSRF'], 419);

$pdo = db();

$productId = (int)($_POST['product_id'] ?? 0);
$qty       = max(1, (int)($_POST['quantity'] ?? 1));
$custName  = trim($_POST['customer_name'] ?? '');
$custEmail = trim($_POST['customer_email'] ?? '');
$custPhone = trim($_POST['customer_phone'] ?? '');
$address   = trim($_POST['delivery_address'] ?? '');
$notes     = trim($_POST['notes'] ?? '');
$method    = $_POST['payment_method'] ?? 'cash';
$payStatus = $_POST['payment_status'] ?? 'completed';
$reference = trim($_POST['payment_reference'] ?? '');

if ($productId <= 0 || !$custName) json_out(['ok'=>false,'error'=>'Missing product or customer name']);
if (!in_array($method, ['cash','card','mobile_money','bank','other'], true)) $method = 'cash';
if (!in_array($payStatus, ['pending','completed'], true)) $payStatus = 'pending';

try {
    $pdo->beginTransaction();

    // ── Product ownership + stock ──
    $ps = $pdo->prepare("SELECT * FROM products WHERE id=? AND seller_id=? FOR UPDATE");
    $ps->execute([$productId, $u['id']]);
    $product = $ps->fetch();
    if (!$product) throw new Exception('Product not found');
    if ($product['quantity'] < $qty) throw new Exception("Only {$product['quantity']} in stock");

    // ── Match or stub buyer ──
    $buyerId = null;
    if ($custEmail && filter_var($custEmail, FILTER_VALIDATE_EMAIL)) {
        $bs = $pdo->prepare("SELECT id FROM users WHERE email=?");
        $bs->execute([$custEmail]);
        $buyerId = $bs->fetchColumn() ?: null;
    }

    // If no matching user, create a lightweight walk-in account
    if (!$buyerId) {
        $stubEmail = $custEmail && filter_var($custEmail, FILTER_VALIDATE_EMAIL)
            ? $custEmail
            : 'walkin-' . bin2hex(random_bytes(4)) . '@local';

        $pdo->prepare("INSERT INTO users (full_name,email,phone,password_hash,role,is_active)
                       VALUES (?,?,?,?, 'buyer', 0)")
            ->execute([
                $custName,
                $stubEmail,
                $custPhone,
                password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost'=>10])
            ]);
        $buyerId = (int)$pdo->lastInsertId();
    }

    // ── Seller snapshot for tax/discount ──
    $sp = $pdo->prepare("SELECT tax_percent, default_discount_percent, currency
                         FROM seller_profiles WHERE user_id=?");
    $sp->execute([$u['id']]);
    $sellerProfile = $sp->fetch() ?: ['tax_percent'=>0,'default_discount_percent'=>0,'currency'=>'USD'];

    $unitPrice = isset($_POST['unit_price']) && $_POST['unit_price'] !== ''
        ? (float)$_POST['unit_price']
        : (float)$product['price'];

    $subtotal    = round($unitPrice * $qty, 2);
    $discPct     = (float)$sellerProfile['default_discount_percent'];
    $discAmt     = round($subtotal * $discPct / 100, 2);
    $taxPct      = (float)$sellerProfile['tax_percent'];
    $taxAmt      = round(($subtotal - $discAmt) * $taxPct / 100, 2);
    $total       = round($subtotal - $discAmt + $taxAmt, 2);

    // ── Create order ──
    $orderCode = 'ORD-' . strtoupper(bin2hex(random_bytes(4)));
    $groupCode = 'MAN-' . strtoupper(bin2hex(random_bytes(4)));

    $pdo->prepare("INSERT INTO orders
        (order_code, order_group, buyer_id, seller_id, product_id,
         quantity, unit_price, subtotal,
         discount_percent, discount_amount,
         tax_percent, tax_amount,
         total_amount, currency,
         delivery_address, delivery_phone, notes, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'confirmed')")
        ->execute([
            $orderCode, $groupCode, $buyerId, $u['id'], $productId,
            $qty, $unitPrice, $subtotal,
            $discPct, $discAmt,
            $taxPct, $taxAmt,
            $total, $sellerProfile['currency'],
            $address ?: 'Walk-in / manual', $custPhone,
            $notes, // notes
        ]);

    $orderId = (int)$pdo->lastInsertId();

    // ── Create payment ──
    $payCode = 'PAY-' . strtoupper(bin2hex(random_bytes(4)));
    $pdo->prepare("INSERT INTO payments
        (payment_code, order_id, user_id, amount, method, reference, status, paid_at)
        VALUES (?,?,?,?,?,?,?, " . ($payStatus==='completed' ? "NOW()" : "NULL") . ")")
        ->execute([$payCode, $orderId, $buyerId, $total, $method, $reference, $payStatus]);

    // ── Decrement stock ──
    $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id=?")
        ->execute([$qty, $productId]);

    $pdo->prepare("INSERT INTO stock_movements (product_id, delta, reason, note, user_id)
                   VALUES (?, ?, 'sale', ?, ?)")
        ->execute([$productId, -$qty, "Manual order $orderCode", $u['id']]);

    // ── Log payment received notification for buyer if registered ──
    if ($payStatus === 'completed') {
        $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,link)
                       VALUES (?,?,?,?,?)")
            ->execute([
                $buyerId, 'order_status', 'Order confirmed',
                "Order {$orderCode} confirmed by seller.", 'dashboard.php?tab=orders'
            ]);
    }

    $pdo->commit();

    json_out(['ok'=>true, 'order_code'=>$orderCode, 'order_id'=>$orderId]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[manual_order] ' . $e->getMessage());
    json_out([
        'ok'=>false,
        'error'=> APP_ENV === 'development' ? $e->getMessage() : 'Could not save order.',
    ]);
}