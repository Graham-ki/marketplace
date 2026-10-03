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
$c = $pdo->prepare("SELECT id FROM users WHERE email=?"); $c->execute([$email]);
if ($c->fetch()) json_out(['ok'=>false,'error'=>'Email already registered — log in.']);

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,?)")
        ->execute([$name, $email, password_hash($pwd, PASSWORD_BCRYPT, ['cost'=>12]), 'seller']);
    $userId = (int)$pdo->lastInsertId();

    $insert = $pdo->prepare("INSERT INTO products
        (seller_id,category_id,title,description,price,quantity,location,cover_image,status)
        VALUES (?,?,?,?,?,?,?,?, 'active')");

    foreach ($items as $it) {
        $cover = null;
        if (!empty($it['tempPath'])) {
            $tmp = __DIR__ . '/uploads/' . $it['tempPath'];
            if (is_file($tmp)) {
                $base = basename($tmp);
                @rename($tmp, __DIR__ . '/uploads/products/' . $base);
                $cover = 'uploads/products/' . $base;
            }
        }
        $insert->execute([
            $userId,
            $it['category_id'] ?? null,
            $it['title'] ?? 'Untitled',
            $it['description'] ?? '',
            $it['price'] ?? 0,
            $it['quantity'] ?? 1,
            $it['location'] ?? '',
            $cover
        ]);
    }

    // Adopt cart if any
    if (session_id()) {
        $pdo->prepare("UPDATE cart_items SET cart_key=? WHERE cart_key=?")
            ->execute(['u_' . $userId, session_id()]);
    }

    $pdo->commit();
    // Seed empty profile so the settings tab has something to update
    $pdo->prepare("INSERT IGNORE INTO seller_profiles (user_id) VALUES (?)")
    ->execute([$userId]);
    login_user(['id'=>$userId,'role'=>'seller','full_name'=>$name]);
    json_out(['ok'=>true, 'redirect' => 'dashboard.php']);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log($e->getMessage());
    json_out(['ok'=>false, 'error'=> APP_ENV === 'development' ? $e->getMessage() : 'Could not publish.']);
}