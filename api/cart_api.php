<?php
require __DIR__ . '/db.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$key    = current_user() ? 'u_' . current_user()['id'] : session_id();

function cart_count_key(string $key): int {
    $s = db()->prepare("SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE cart_key=?");
    $s->execute([$key]);
    return (int)$s->fetchColumn();
}

if ($action === 'add') {
    $pid = (int)($_POST['product_id'] ?? 0);
    $qty = max(1, (int)($_POST['quantity'] ?? 1));
    if (!$pid) json_out(['ok'=>false,'error'=>'Missing product']);

    // Verify product exists and is active
    $c = db()->prepare("SELECT id,quantity FROM products WHERE id=? AND status='active'");
    $c->execute([$pid]);
    $p = $c->fetch();
    if (!$p) json_out(['ok'=>false,'error'=>'Item unavailable']);

    db()->prepare("
        INSERT INTO cart_items (cart_key, product_id, quantity)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + VALUES(quantity), ?)
    ")->execute([$key, $pid, $qty, (int)$p['quantity']]);

    json_out(['ok'=>true, 'count'=>cart_count_key($key)]);
}

if ($action === 'update') {
    $cid = (int)($_POST['cart_id'] ?? 0);
    $qty = max(1, (int)($_POST['quantity'] ?? 1));
    db()->prepare("UPDATE cart_items SET quantity=? WHERE id=? AND cart_key=?")
        ->execute([$qty, $cid, $key]);
    json_out(['ok'=>true, 'count'=>cart_count_key($key)]);
}

if ($action === 'remove') {
    $cid = (int)($_POST['cart_id'] ?? 0);
    db()->prepare("DELETE FROM cart_items WHERE id=? AND cart_key=?")
        ->execute([$cid, $key]);
    json_out(['ok'=>true, 'count'=>cart_count_key($key)]);
}

if ($action === 'count') {
    json_out(['ok'=>true, 'count'=>cart_count_key($key)]);
}

json_out(['ok'=>false,'error'=>'Unknown action']);