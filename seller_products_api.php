<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'seller') json_out(['ok'=>false,'error'=>'Sellers only'], 403);

$stmt = db()->prepare("
    SELECT id, title, price, quantity
    FROM products
    WHERE seller_id=? AND status='active'
    ORDER BY title
");
$stmt->execute([$u['id']]);
$products = $stmt->fetchAll();

json_out(['ok'=>true, 'products'=>$products]);