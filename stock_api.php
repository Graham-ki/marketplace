<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'seller') json_out(['ok'=>false,'error'=>'Sellers only'], 403);
if (!csrf_ok($_POST['csrf'] ?? null)) json_out(['ok'=>false,'error'=>'CSRF failed'], 419);

$action = $_POST['action'] ?? '';
$pdo    = db();

/* ─────────── Helper ─────────── */
function compute_discount(array $in): array {
    $price = (float)($in['price'] ?? 0);
    $pct   = max(0, min(99, (float)($in['discount_percent'] ?? 0)));
    $original = ($pct > 0 && $price > 0)
        ? round($price / (1 - $pct / 100), 2)
        : null;
    return [$price, $pct, $original];
}

/* ─────────── CREATE ─────────── */
if ($action === 'create') {
    $title = trim($_POST['title'] ?? '');
    if ($title === '') json_out(['ok'=>false,'error'=>'Title required']);

    $cover = null;
    if (!empty($_FILES['image']['tmp_name'])) {
        $cover = save_upload($_FILES['image']);
        if (!$cover) json_out(['ok'=>false,'error'=>'Image upload failed']);
    }

    [$price, $pct, $original] = compute_discount($_POST);

    $pdo->prepare("INSERT INTO products
        (seller_id,category_id,title,description,
         price,discount_percent,original_price,
         quantity,location,cover_image,status)
        VALUES (?,?,?,?,?,?,?,?,?,?, 'active')")
        ->execute([
            $u['id'],
            $_POST['category_id'] ?: null,
            $title,
            trim($_POST['description'] ?? ''),
            $price,
            $pct,
            $original,
            max(0, (int)($_POST['quantity'] ?? 1)),
            trim($_POST['location'] ?? ''),
            $cover,
        ]);

    $newId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO stock_movements (product_id, delta, reason, note, user_id)
                   VALUES (?, ?, 'restock', 'Initial listing', ?)")
        ->execute([$newId, (int)($_POST['quantity'] ?? 1), $u['id']]);

    json_out(['ok'=>true, 'id'=>$newId]);
}

/* ─────────── UPDATE ─────────── */
if ($action === 'update') {
    $pid = (int)($_POST['product_id'] ?? 0);

    $own = $pdo->prepare("SELECT cover_image, quantity FROM products WHERE id=? AND seller_id=?");
    $own->execute([$pid, $u['id']]);
    $existing = $own->fetch();
    if (!$existing) json_out(['ok'=>false,'error'=>'Not found']);

    $cover = $existing['cover_image'];
    if (!empty($_FILES['image']['tmp_name'])) {
        $newCover = save_upload($_FILES['image']);
        if ($newCover) $cover = $newCover;
    }

    $newQty = max(0, (int)($_POST['quantity'] ?? 0));
    $delta  = $newQty - (int)$existing['quantity'];

    [$price, $pct, $original] = compute_discount($_POST);

    $pdo->prepare("UPDATE products SET
        category_id=?, title=?, description=?,
        price=?, discount_percent=?, original_price=?,
        quantity=?, location=?, cover_image=?, status=?
        WHERE id=? AND seller_id=?")
        ->execute([
            $_POST['category_id'] ?: null,
            trim($_POST['title'] ?? ''),
            trim($_POST['description'] ?? ''),
            $price, $pct, $original,
            $newQty,
            trim($_POST['location'] ?? ''),
            $cover,
            $_POST['status'] ?? 'active',
            $pid,
            $u['id'],
        ]);

    if ($delta !== 0) {
        $pdo->prepare("INSERT INTO stock_movements (product_id, delta, reason, note, user_id)
                       VALUES (?, ?, 'adjustment', 'Edit', ?)")
            ->execute([$pid, $delta, $u['id']]);
    }

    json_out(['ok'=>true]);
}

/* ─────────── RESTOCK ─────────── */
if ($action === 'restock') {
    $pid   = (int)($_POST['product_id'] ?? 0);
    $delta = (int)($_POST['delta'] ?? 0);

    $own = $pdo->prepare("SELECT quantity FROM products WHERE id=? AND seller_id=?");
    $own->execute([$pid, $u['id']]);
    $p = $own->fetch();
    if (!$p) json_out(['ok'=>false,'error'=>'Not found']);

    $newQty = max(0, (int)$p['quantity'] + $delta);

    $pdo->prepare("UPDATE products SET quantity=? WHERE id=? AND seller_id=?")
        ->execute([$newQty, $pid, $u['id']]);

    $pdo->prepare("INSERT INTO stock_movements (product_id, delta, reason, note, user_id)
                   VALUES (?, ?, 'restock', ?, ?)")
        ->execute([$pid, $delta, trim($_POST['note'] ?? ''), $u['id']]);

    json_out(['ok'=>true, 'quantity'=>$newQty]);
}

/* ─────────── DELETE ─────────── */
if ($action === 'delete') {
    $pid = (int)($_POST['product_id'] ?? 0);

    $own = $pdo->prepare("SELECT id FROM products WHERE id=? AND seller_id=?");
    $own->execute([$pid, $u['id']]);
    if (!$own->fetch()) json_out(['ok'=>false,'error'=>'Not found']);

    $pdo->prepare("DELETE FROM products WHERE id=? AND seller_id=?")
        ->execute([$pid, $u['id']]);

    json_out(['ok'=>true]);
}

json_out(['ok'=>false,'error'=>'Unknown action']);


/* ─── Upload helper ─── */
function save_upload(array $file): ?string {
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $mime = @mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime]) || $file['size'] > 5*1024*1024) return null;

    $dir = __DIR__ . '/uploads/products';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $name = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) return null;

    return 'uploads/products/' . $name;
}