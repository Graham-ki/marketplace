<?php
require __DIR__ . '/db.php';

// ── Draft save (JSON POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) json_out(['ok' => false]);

    db()->prepare("
        INSERT INTO draft_sessions (session_token, role, payload, expires_at)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
        ON DUPLICATE KEY UPDATE payload = VALUES(payload), role = VALUES(role)
    ")->execute([
        draft_token(),
        in_array($body['role'] ?? '', ['buyer','seller'], true) ? $body['role'] : 'seller',
        json_encode($body['data'] ?? []),
    ]);
    json_out(['ok' => true]);
}

// ── Signup (form POST) ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /'); exit; }

if (!csrf_ok($_POST['csrf'] ?? null)) json_out(['ok'=>false,'error'=>'CSRF failed'], 419);

$token = $_POST['draft_token'] ?? '';
$name  = trim($_POST['full_name'] ?? '');
$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$pwd   = $_POST['password'] ?? '';
$role  = in_array($_POST['role'] ?? '', ['buyer','seller'], true) ? $_POST['role'] : 'seller';

if (!$name || !$email || strlen($pwd) < 8) {
    json_out(['ok'=>false,'error'=>'Please fill all fields correctly']);
}

$pdo = db();

if ($pdo->prepare("SELECT id FROM users WHERE email=?")->execute([$email])
    || $pdo->query("SELECT id FROM users WHERE email=" . $pdo->quote($email))->fetch()) {
    json_out(['ok'=>false,'error'=>'Email already registered — please log in']);
}

$draft = $pdo->prepare("SELECT * FROM draft_sessions WHERE session_token=?");
$draft->execute([$token]);
$draft = $draft->fetch();
if (!$draft) json_out(['ok'=>false,'error'=>'Draft expired']);

$items = json_decode($draft['payload'], true)['data'] ?? [];

try {
    $pdo->beginTransaction();

    // Create user
    $pdo->prepare("INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,?)")
        ->execute([$name, $email, password_hash($pwd, PASSWORD_BCRYPT, ['cost'=>12]), $role]);
    $userId = (int)$pdo->lastInsertId();

    if ($role === 'seller') {
        // Move first temp image to products folder
        $cover = null;
        if (!empty($items['images'][0]['tempPath'])) {
            $tmp  = __DIR__ . '/uploads/' . $items['images'][0]['tempPath'];
            if (is_file($tmp)) {
                $base = basename($tmp);
                @rename($tmp, __DIR__ . '/uploads/products/' . $base);
                $cover = '/uploads/products/' . $base;
            }
        }
        $pdo->prepare("INSERT INTO products
            (seller_id,category_id,title,description,price,quantity,location,cover_image,status)
            VALUES (?,?,?,?,?,?,?,?, 'active')")
            ->execute([
                $userId,
                $items['category_id'] ?? null,
                $items['title'] ?? 'Untitled',
                $items['description'] ?? '',
                $items['price'] ?? 0,
                $items['quantity'] ?? 1,
                $items['location'] ?? '',
                $cover,
            ]);
    } else {
        // Buyer — create order
        $productId = (int)($items['product_id'] ?? 0);
        $qty       = max(1, (int)($items['quantity'] ?? 1));
        if (!$productId) throw new Exception('No product selected');

        $stmt = $pdo->prepare("SELECT * FROM products WHERE id=? AND status='active' FOR UPDATE");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) throw new Exception('Product unavailable');
        if ($product['quantity'] < $qty) throw new Exception('Insufficient stock');

        $total = $product['price'] * $qty;
        $code  = 'ORD-' . strtoupper(bin2hex(random_bytes(4)));

        $pdo->prepare("INSERT INTO orders
            (order_code,buyer_id,seller_id,product_id,quantity,unit_price,total_amount,
             delivery_address,delivery_phone,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $code, $userId, $product['seller_id'], $product['id'],
                $qty, $product['price'], $total,
                $items['address'] ?? '', $items['phone'] ?? '', $items['notes'] ?? '',
            ]);

        $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id=?")
            ->execute([$qty, $product['id']]);

        $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,link)
                       VALUES (?,?,?,?,?)")
            ->execute([
                $product['seller_id'], 'new_order', 'New Order Received',
                "You have a new order $code for: {$product['title']}", '/dashboard.php',
            ]);
    }

    $pdo->prepare("UPDATE draft_sessions SET converted_user_id=?, converted_at=NOW()
                   WHERE session_token=?")->execute([$userId, $token]);

    $pdo->commit();

    login_user(['id'=>$userId, 'role'=>$role, 'full_name'=>$name]);
    json_out(['ok'=>true, 'user_id'=>$userId]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[commit] ' . $e->getMessage());
    json_out([
        'ok' => false,
        'error' => APP_ENV === 'development' ? $e->getMessage() : 'Could not save. Try again.',
    ]);
}