<?php
require_once __DIR__ . '/../app/core/Session.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/helpers/Security.php';
require_once __DIR__ . '/../app/config/config.php';
Session::start();

header('Content-Type: application/json');

if (!Security::verifyCsrf($_POST['csrf'] ?? null)) {
    echo json_encode(['ok'=>false,'error'=>'CSRF failed']); exit;
}

$token = $_POST['draft_token'] ?? '';
$name  = trim($_POST['full_name'] ?? '');
$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$pwd   = $_POST['password'] ?? '';
$role  = in_array($_POST['role'] ?? '', ['buyer','seller'], true) ? $_POST['role'] : 'seller';

if (!$name || !$email || strlen($pwd) < 8) {
    echo json_encode(['ok'=>false,'error'=>'Please fill all fields correctly']); exit;
}

$pdo = Database::getInstance()->pdo();

// Email uniqueness
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    echo json_encode(['ok'=>false,'error'=>'Email already registered — please log in']); exit;
}

// Fetch draft
$stmt = $pdo->prepare("SELECT * FROM draft_sessions WHERE session_token = ?");
$stmt->execute([$token]);
$draft = $stmt->fetch();
if (!$draft) { echo json_encode(['ok'=>false,'error'=>'Draft expired']); exit; }

$payload = json_decode($draft['payload'], true);
$items   = $payload['data'] ?? [];

try {
    $pdo->beginTransaction();

    // 1. Create user
    $pdo->prepare("INSERT INTO users (full_name,email,password_hash,role)
                   VALUES (?,?,?,?)")
        ->execute([$name, $email, Security::hashPassword($pwd), $role]);
    $userId = (int)$pdo->lastInsertId();

    // 2. Act on the payload
    if ($role === 'seller') {
        $cover = null;
        if (!empty($items['images'][0]['tempPath'])) {
            $rel = $items['images'][0]['tempPath']; // "temp/<sid>/xxx.jpg"
            $tmp = UPLOAD_PATH . '/' . $rel;
            if (is_file($tmp)) {
                $base = basename($tmp);
                $dest = UPLOAD_PATH . '/products/' . $base;
                @rename($tmp, $dest);
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
                $cover
            ]);
    } elseif ($role === 'buyer') {
        $productId = (int)($items['product_id'] ?? 0);
        $qty       = max(1, (int)($items['quantity'] ?? 1));
        if (!$productId) throw new Exception('No product selected');

        // Lock product, get seller & price
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
                $items['address'] ?? '',
                $items['phone']   ?? '',
                $items['notes']   ?? ''
            ]);

        $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?")
            ->execute([$qty, $product['id']]);

        // Notify seller
        $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,link)
                       VALUES (?,?,?,?,?)")
            ->execute([
                $product['seller_id'],
                'new_order',
                'New Order Received',
                "You have a new order $code for: {$product['title']}",
                "/dashboard.php"
            ]);
    }

    // 3. Mark draft converted
    $pdo->prepare("UPDATE draft_sessions
                   SET converted_user_id=?, converted_at=NOW()
                   WHERE session_token=?")
        ->execute([$userId, $token]);

    $pdo->commit();

    // 4. Log in
    session_regenerate_id(true);
    Session::set('user_id', $userId);
    Session::set('role',    $role);
    Session::set('name',    $name);

    echo json_encode(['ok'=>true,'user_id'=>$userId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[commit_draft] ' . $e->getMessage());
    echo json_encode([
        'ok'=>false,
        'error'=> APP_ENV === 'development' ? $e->getMessage() : 'Could not save. Try again.'
    ]);
}