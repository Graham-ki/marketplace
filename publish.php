<?php
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: sell.php'); exit; }
if (!csrf_ok($_POST['csrf'] ?? null)) json_out(['ok'=>false,'error'=>'CSRF'], 419);

$name  = trim($_POST['full_name'] ?? '');
$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$pwd   = $_POST['password'] ?? '';
$items = json_decode($_POST['items_json'] ?? '[]', true);

if (!$name || !$email || strlen($pwd) < 8) json_out(['ok'=>false,'error'=>'Fill all fields.']);
if (!is_array($items) || !count($items))   json_out(['ok'=>false,'error'=>'Add at least one item.']);

$pdo = db();

// Email uniqueness
$c = $pdo->prepare("SELECT id FROM users WHERE email=?");
$c->execute([$email]);
if ($c->fetch()) json_out(['ok'=>false,'error'=>'Email already registered — log in.']);

$pdo->beginTransaction();
try {
    // 1. Create the seller account
    $pdo->prepare("INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?, 'seller')")
        ->execute([$name, $email, password_hash($pwd, PASSWORD_BCRYPT, ['cost'=>12])]);
    $userId = (int)$pdo->lastInsertId();

    // Seed an empty seller profile so the settings tab has something to update
    $pdo->prepare("INSERT IGNORE INTO seller_profiles (user_id) VALUES (?)")
        ->execute([$userId]);

    // 2. Insert each product with discount data
    $insert = $pdo->prepare("
        INSERT INTO products
          (seller_id, category_id, title, description,
           price, discount_percent, original_price,
           quantity, location, cover_image, status)
        VALUES (?,?,?,?,?,?,?,?,?,?, 'active')
    ");

    foreach ($items as $it) {
        // Move temp image into place
        $cover = null;
        if (!empty($it['tempPath'])) {
            $tmp = __DIR__ . '/uploads/' . $it['tempPath'];
            if (is_file($tmp)) {
                $base = basename($tmp);
                @rename($tmp, __DIR__ . '/uploads/products/' . $base);
                $cover = 'uploads/products/' . $base;
            }
        }

        // Recompute original on the server (never trust client-side math)
        $price = (float)($it['price'] ?? 0);
        $pct   = max(0, min(99, (float)($it['discount_percent'] ?? 0)));
        $original = ($pct > 0 && $price > 0)
            ? round($price / (1 - $pct / 100), 2)
            : null;

        $insert->execute([
            $userId,
            $it['category_id'] ?? null,
            $it['title'] ?? 'Untitled',
            $it['description'] ?? '',
            $price,
            $pct,
            $original,
            max(1, (int)($it['quantity'] ?? 1)),
            $it['location'] ?? '',
            $cover,
        ]);
    }

    // 3. Adopt the guest's cart if they had one
    if (session_id()) {
        $pdo->prepare("UPDATE cart_items SET cart_key=? WHERE cart_key=?")
            ->execute(['u_' . $userId, session_id()]);
    }

    $pdo->commit();

    login_user(['id'=>$userId, 'role'=>'seller', 'full_name'=>$name]);
    json_out(['ok'=>true, 'redirect' => 'dashboard.php?tab=stock']);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[publish] ' . $e->getMessage());
    json_out([
        'ok'=>false,
        'error'=> APP_ENV === 'development' ? $e->getMessage() : 'Could not publish.'
    ]);
}