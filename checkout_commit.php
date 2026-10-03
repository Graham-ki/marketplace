<?php
require __DIR__ . '/db.php';

// ═══════════════════════════════════════════════════════
// CHECKOUT COMMIT
// Handles:
//   • guest signup
//   • splitting cart per seller
//   • applying each seller's discount + tax
//   • creating one order per item, grouped by order_group
//   • recording payments (one per order, status pending)
//   • notifying each seller
//   • clearing the cart
// ═══════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: checkout.php'); exit; }
if (!csrf_ok($_POST['csrf'] ?? null)) {
    flash('checkout_error', 'Session expired, try again.');
    header('Location: checkout.php'); exit;
}

$name    = trim($_POST['full_name'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
$notes   = trim($_POST['notes'] ?? '');
$email   = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$pwd     = $_POST['password'] ?? '';

$user = current_user();
$pdo  = db();
$key  = $user ? 'u_' . $user['id'] : session_id();

$pdo->beginTransaction();

try {
    // ── 1. Ensure we have a user (guest signup inline) ──
    if (!$user) {
        if (!$name || !$email || strlen($pwd) < 8) {
            throw new Exception('Please fill in name, email, and a password of at least 8 characters.');
        }
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            throw new Exception('Email already registered — log in to continue.');
        }

        $pdo->prepare("INSERT INTO users (full_name,email,phone,password_hash,role)
                       VALUES (?,?,?,?,'buyer')")
            ->execute([$name, $email, $phone, password_hash($pwd, PASSWORD_BCRYPT, ['cost'=>12])]);
        $userId = (int)$pdo->lastInsertId();
    } else {
        $userId = (int)$user['id'];
    }

    // ── 2. Lock and load cart with product + seller info ──
    $cart = $pdo->prepare("
        SELECT ci.quantity AS cart_qty,
               p.id AS product_id, p.title, p.price, p.quantity AS stock,
               p.seller_id,
               u.full_name AS seller_name,
               COALESCE(sp.tax_percent, 0)              AS tax_percent,
               COALESCE(sp.default_discount_percent, 0) AS discount_percent,
               COALESCE(sp.currency, 'USD')             AS currency,
               sp.business_name
        FROM cart_items ci
        JOIN products p ON p.id = ci.product_id
        JOIN users u   ON u.id = p.seller_id
        LEFT JOIN seller_profiles sp ON sp.user_id = p.seller_id
        WHERE ci.cart_key = ?
        FOR UPDATE
    ");
    $cart->execute([$key]);
    $items = $cart->fetchAll();

    if (!$items) throw new Exception('Your cart is empty.');

    // ── 3. Group cart items by seller ──
    $bySeller = [];
    foreach ($items as $it) {
        $bySeller[$it['seller_id']][] = $it;
    }

    $groupCode   = 'GRP-' . strtoupper(bin2hex(random_bytes(5)));
    $grandTotal  = 0.0;
    $orderIds    = [];

    $insertOrder = $pdo->prepare("
        INSERT INTO orders
        (order_code, order_group, buyer_id, seller_id, product_id,
         quantity, unit_price, subtotal,
         discount_percent, discount_amount,
         tax_percent, tax_amount,
         total_amount, currency, tax_label,
         delivery_address, delivery_phone, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    $insertPayment = $pdo->prepare("
        INSERT INTO payments (payment_code, order_id, user_id, amount, method, status)
        VALUES (?,?,?,?,?,'pending')
    ");

    $insertNotif = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, link)
        VALUES (?,?,?,?,?)
    ");

    $decrement = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");

    // ── 4. For each seller, compute their subtotal, discount, tax ──
    foreach ($bySeller as $sellerId => $sellerItems) {

        // Pull rates from the first item (they're identical per seller)
        $discountPct = (float)$sellerItems[0]['discount_percent'];
        $taxPct      = (float)$sellerItems[0]['tax_percent'];
        $currency    = $sellerItems[0]['currency'];
        $taxLabel    = $taxPct > 0 ? number_format($taxPct, 2) . '%' : null;

        // Seller subtotal
        $sellerSubtotal = 0.0;
        foreach ($sellerItems as $it) {
            $sellerSubtotal += (float)$it['price'] * (int)$it['cart_qty'];
        }

        // Discount first
        $discountAmount = round($sellerSubtotal * $discountPct / 100, 2);
        $afterDiscount  = $sellerSubtotal - $discountAmount;

        // Then tax on the discounted amount
        $taxAmount = round($afterDiscount * $taxPct / 100, 2);

        // Seller total
        $sellerTotal = round($afterDiscount + $taxAmount, 2);
        $grandTotal += $sellerTotal;

        // Create one order row per item
        foreach ($sellerItems as $it) {
            $qty = (int)$it['cart_qty'];

            if ($it['stock'] < $qty) {
                throw new Exception("Unable to fulfill order for {$it['title']}.Item has only {$it['stock']} in stock, but you requested {$qty}. Please adjust your cart and try again!");
            }

            $unitPrice   = (float)$it['price'];
            $lineSub     = round($unitPrice * $qty, 2);

            // Proportional share of this seller's discount & tax,
            // so line items add up exactly to the seller total.
            $lineShareOfSellerSub = $sellerSubtotal > 0 ? $lineSub / $sellerSubtotal : 0;
            $lineDiscount = round($discountAmount * $lineShareOfSellerSub, 2);
            $lineTax      = round($taxAmount      * $lineShareOfSellerSub, 2);
            $lineTotal    = round($lineSub - $lineDiscount + $lineTax, 2);

            $orderCode = 'ORD-' . strtoupper(bin2hex(random_bytes(4)));

            $insertOrder->execute([
                $orderCode,
                $groupCode,
                $userId,
                $sellerId,
                $it['product_id'],
                $qty,
                $unitPrice,
                $lineSub,
                $discountPct,
                $lineDiscount,
                $taxPct,
                $lineTax,
                $lineTotal,
                $currency,
                $taxLabel,
                $address,
                $phone,
                $notes,
            ]);

            $orderId = (int)$pdo->lastInsertId();
            $orderIds[] = $orderId;

            // Payment placeholder per order
            $payCode = 'PAY-' . strtoupper(bin2hex(random_bytes(4)));
            $insertPayment->execute([$payCode, $orderId, $userId, $lineTotal, 'cash']);

            // Decrement stock
            $decrement->execute([$qty, $it['product_id']]);

            // Notify seller
            $insertNotif->execute([
                $sellerId,
                'new_order',
                'New Order Received',
                "Order {$orderCode} for {$it['title']} (× {$qty}) — total {$currency} " .
                    number_format($lineTotal, 2) . ".",
                'dashboard.php?tab=orders',
            ]);
        }
    }

    // ── 5. Clear cart & adopt guest cart if user was created ──
    $pdo->prepare("DELETE FROM cart_items WHERE cart_key = ?")->execute([$key]);

    $pdo->commit();

    // ── 6. Log in if guest became a user ──
    if (!$user) {
        login_user(['id' => $userId, 'role' => 'buyer', 'full_name' => $name]);
    }
    // Seed empty profile so the settings tab has something to update
    $pdo->prepare("INSERT IGNORE INTO seller_profiles (user_id) VALUES (?)")
    ->execute([$userId]);
    // Send to a confirmation page showing all orders in the group
    header('Location: order_success.php?group=' . urlencode($groupCode));
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[checkout_commit] ' . $e->getMessage());
    flash('checkout_error', APP_ENV === 'development' ? $e->getMessage() : 'Could not place order. Please try again.');
    header('Location: checkout.php');
    exit;
}