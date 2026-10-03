<?php
require __DIR__ . '/db.php';

$key  = current_user() ? 'u_' . current_user()['id'] : session_id();
$stmt = db()->prepare("
    SELECT ci.id AS cart_id, ci.quantity,
           p.id, p.title, p.price, p.cover_image, p.quantity AS stock,
           COALESCE(NULLIF(sp.business_name,''), u.full_name) AS seller_name
    FROM cart_items ci
    JOIN products p             ON p.id = ci.product_id
    JOIN users u                ON u.id = p.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = p.seller_id
    WHERE ci.cart_key = ?
    ORDER BY ci.added_at DESC
");
$stmt->execute([$key]);
$items = $stmt->fetchAll();

$subtotal = 0;
foreach ($items as $it) { $subtotal += $it['price'] * $it['quantity']; }

$__pageTitle = 'Your cart';
require __DIR__ . '/layout/header.php';
?>

<div class="container">
  <h1 class="page-title">Your cart</h1>

  <?php if (!$items): ?>
    <div class="empty-state">
      <div class="empty-icon">🛒</div>
      <h3>Your cart is empty</h3>
      <p>Browse items and add something you like.</p>
      <a href="index.php" class="btn btn-accent">Browse items →</a>
    </div>
  <?php else: ?>

    <div class="cart-layout">
      <div class="cart-items">
        <?php foreach ($items as $it): ?>
          <div class="cart-row" data-cart-id="<?= (int)$it['cart_id'] ?>">
            <div class="cart-thumb"
                 style="background-image:url('<?= e($it['cover_image'] ?: '') ?>')"></div>
            <div class="cart-row-body">
              <a href="product.php?id=<?= (int)$it['id'] ?>" class="cart-row-title">
                <?= e($it['title']) ?>
              </a>
              <small class="cart-row-seller">by <?= e($it['seller_name']) ?></small>
              <div class="cart-row-controls">
                <div class="qty-control">
                  <button type="button" data-qty-dec>−</button>
                  <input type="number" min="1" max="<?= (int)$it['stock'] ?>"
                         value="<?= (int)$it['quantity'] ?>" data-qty>
                  <button type="button" data-qty-inc>+</button>
                </div>
                <button class="btn-link" data-remove>Remove</button>
              </div>
            </div>
            <div class="cart-row-price">UGX <?= number_format($it['price'] * $it['quantity'], 2) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <aside class="cart-summary">
        <h3>Order summary</h3>
        <div class="summary-row"><span>Subtotal</span><strong>UGX<?= number_format($subtotal, 2) ?></strong></div>
        <div class="summary-row"><span>Delivery</span><span>Calculated at checkout</span></div>
        <div class="summary-row total"><span>Total</span><strong>UGX <?= number_format($subtotal, 2) ?></strong></div>
        <a href="checkout.php" class="btn btn-accent btn-block btn-lg">
          Proceed to checkout →
        </a>
        <a href="index.php" class="btn-link block-center">Continue shopping</a>
      </aside>
    </div>

  <?php endif; ?>
</div>

<?php require __DIR__ . '/layout/footer.php'; ?>