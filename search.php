<?php
require __DIR__ . '/db.php';
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q === '') json_out(['ok'=>true, 'products'=>[], 'sellers'=>[]]);

$like = "%$q%";

// Match items
$p = db()->prepare("
    SELECT p.id, p.title, p.price, p.cover_image,
           COALESCE(NULLIF(sp.business_name,''), u.full_name) AS seller_name
    FROM products p
    JOIN users u                ON u.id = p.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = p.seller_id
    WHERE p.status='active'
      AND (p.title LIKE ? OR p.description LIKE ?
           OR sp.business_name LIKE ? OR u.full_name LIKE ?)
    ORDER BY p.created_at DESC
    LIMIT 8
");
$p->execute([$like,$like,$like,$like]);
$products = $p->fetchAll();

// Match sellers (deduped)
$s = db()->prepare("
    SELECT u.id,
           COALESCE(NULLIF(sp.business_name,''), u.full_name) AS shop_name,
           COUNT(p.id) AS item_count
    FROM users u
    LEFT JOIN seller_profiles sp ON sp.user_id = u.id
    LEFT JOIN products p ON p.seller_id = u.id AND p.status='active'
    WHERE u.role='seller'
      AND (sp.business_name LIKE ? OR u.full_name LIKE ?)
    GROUP BY u.id
    ORDER BY item_count DESC
    LIMIT 5
");
$s->execute([$like, $like]);
$sellers = $s->fetchAll();

json_out(['ok'=>true, 'products'=>$products, 'sellers'=>$sellers]);