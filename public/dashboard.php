<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/core/Session.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/helpers/Security.php';
Session::start();
Session::requireLogin();

$user = Session::user();
$pdo  = Database::getInstance()->pdo();

if ($user['role'] === 'seller') {
    $stmt = $pdo->prepare("
        SELECT id,title,price,quantity,cover_image,status,views,created_at
        FROM products WHERE seller_id = ? ORDER BY created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $items = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT o.order_code, o.total_amount, o.status, o.created_at,
               p.title AS product_title, p.cover_image
        FROM orders o
        JOIN products p ON p.id = o.product_id
        WHERE o.buyer_id = ?
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $orders = $stmt->fetchAll();
}

$pageTitle = 'Dashboard';
$extraCss  = ['/css/onboarding.css'];
require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="wizard">
  <h2>Hi, <?= Security::sanitize($user['name']) ?> 👋</h2>
  <p class="step-sub">
    <?= $user['role'] === 'seller'
        ? 'Here are your listed items.'
        : 'Here are your orders.' ?>
  </p>

  <?php if ($user['role'] === 'seller'): ?>
    <a href="/onboarding.php?role=seller" class="btn btn-accent">+ List new item</a>
    <div class="product-grid" style="margin-top:24px">
      <?php if (!$items): ?>
        <p class="step-sub">No listings yet.</p>
      <?php endif; ?>
      <?php foreach ($items as $it): ?>
        <div class="product-card" style="cursor:default">
          <div class="product-thumb"
               style="background-image:url('<?= Security::sanitize($it['cover_image'] ?: '') ?>')"></div>
          <div class="product-info">
            <strong><?= Security::sanitize($it['title']) ?></strong>
            <span class="price">$<?= number_format((float)$it['price'], 2) ?></span>
            <small>Stock: <?= (int)$it['quantity'] ?> · <?= Security::sanitize($it['status']) ?></small>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <?php if (!$orders): ?>
      <p class="step-sub">No orders yet. <a href="/onboarding.php?role=buyer">Browse items →</a></p>
    <?php endif; ?>
    <?php foreach ($orders as $o): ?>
      <div class="mini-preview">
        <img src="<?= Security::sanitize($o['cover_image'] ?: '') ?>" alt="">
        <div>
          <strong><?= Security::sanitize($o['product_title']) ?></strong>
          <span>$<?= number_format((float)$o['total_amount'], 2) ?></span>
        </div>
        <span class="pill"><?= Security::sanitize($o['status']) ?></span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>