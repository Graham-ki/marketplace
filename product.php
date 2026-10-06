<?php
require __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("
    SELECT p.*, c.name AS cat_name,
           u.id AS seller_id, u.full_name AS owner_name,
           COALESCE(NULLIF(sp.business_name,''), u.full_name) AS seller_name,
           sp.phone AS business_phone, sp.address AS business_address
    FROM products p
    LEFT JOIN categories c      ON c.id = p.category_id
    JOIN users u                ON u.id = p.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = p.seller_id
    WHERE p.id = ? AND p.status = 'active'
");
$stmt->execute([$id]);
$p = $stmt->fetch();

if (!$p) { http_response_code(404); die('Item not found'); }

db()->prepare("UPDATE products SET views = views + 1 WHERE id = ?")->execute([$id]);
db()->prepare("INSERT INTO product_views (product_id, viewer_id) VALUES (?, ?)")
    ->execute([$id, current_user()['id'] ?? null]);

$hasDiscount = (float)$p['discount_percent'] > 0 && $p['original_price'];

$__pageTitle = $p['title'];
require __DIR__ . '/layout/header.php';
?>

<div class="container">
  <a href="index.php" class="back-link">← Back to shop</a>

  <div class="product-detail">
    <div class="product-detail-media">
      <div class="product-detail-image"
           style="background-image:url('<?= e($p['cover_image'] ?: '') ?>')">
        <?php if (!$p['cover_image']): ?>
          <span class="thumb-placeholder big">📦</span>
        <?php endif; ?>
        <?php if ($hasDiscount): ?>
          <span class="product-badge">−<?= number_format((float)$p['discount_percent'], 0) ?>%</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="product-detail-info">
      <span class="product-cat"><?= e($p['cat_name'] ?? 'Uncategorised') ?></span>
      <h1><?= e($p['title']) ?></h1>

      <div class="product-detail-price">
        UGX <?= number_format((float)$p['price'], 0) ?>
        <?php if ($hasDiscount): ?>
          <s class="price-was">UGX <?= number_format((float)$p['original_price'], 0) ?></s>
          <span class="discount-tag">
            −<?= number_format((float)$p['discount_percent'], 0) ?>% off
          </span>
        <?php endif; ?>
      </div>

      <div class="product-detail-meta">
        <span>📍 <?= e($p['location'] ?: '—') ?></span>
        <span>👁️ <?= (int)$p['views'] ?> views</span>
        <span>📦 <?= (int)$p['quantity'] ?> in stock</span>
      </div>

      <p class="product-detail-desc"><?= nl2br(e($p['description'] ?: 'No description provided.')) ?></p>

      <div class="product-detail-seller">
        <div class="seller-avatar"><?= strtoupper(substr($p['seller_name'], 0, 1)) ?></div>
        <div>
          <strong><?= e($p['seller_name']) ?></strong>
          <small>
            Seller
            <?php if (!empty($p['business_address'])): ?>
              · <?= e($p['business_address']) ?>
            <?php endif; ?>
          </small>
        </div>
      </div>

      <div class="product-detail-actions">
        <button class="btn btn-primary btn-lg" data-add-to-cart="<?= (int)$p['id'] ?>">
          🛒 Add to cart
        </button>
        <button class="btn btn-accent btn-lg" data-buy-now="<?= (int)$p['id'] ?>">
          ⚡ Buy now
        </button>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/layout/footer.php'; ?>