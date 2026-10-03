<?php
require __DIR__ . '/db.php';

$user = current_user();
$key  = $user ? 'u_' . $user['id'] : session_id();

$stmt = db()->prepare("
    SELECT ci.quantity AS cart_qty,
           p.id AS product_id, p.title, p.price, p.cover_image,
           p.seller_id, u.full_name AS seller_name,
           COALESCE(sp.tax_percent, 0)              AS tax_percent,
           COALESCE(sp.default_discount_percent, 0) AS discount_percent,
           COALESCE(sp.currency, 'USD')             AS currency,
           sp.business_name
    FROM cart_items ci
    JOIN products p ON p.id = ci.product_id
    JOIN users u   ON u.id = p.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = p.seller_id
    WHERE ci.cart_key = ?
    ORDER BY p.seller_id, ci.added_at
");
$stmt->execute([$key]);
$items = $stmt->fetchAll();

if (!$items) { header('Location: cart.php'); exit; }

// Group by seller and compute per-seller totals
$bySeller = [];
foreach ($items as $it) {
    $bySeller[$it['seller_id']][] = $it;
}

$sellers = [];
$grandTotal = 0.0;
$grandSubtotal = 0.0;
$grandDiscount = 0.0;
$grandTax = 0.0;
$currency = 'USD';

foreach ($bySeller as $sellerId => $sellerItems) {
    $first = $sellerItems[0];
    $currency = $first['currency'];

    $sub = 0.0;
    foreach ($sellerItems as $it) $sub += (float)$it['price'] * (int)$it['cart_qty'];

    $discountPct = (float)$first['discount_percent'];
    $discount    = round($sub * $discountPct / 100, 2);
    $taxPct      = (float)$first['tax_percent'];
    $tax         = round(($sub - $discount) * $taxPct / 100, 2);
    $total       = round($sub - $discount + $tax, 2);

    $sellers[$sellerId] = [
        'name'      => $first['business_name'] ?: $first['seller_name'],
        'items'     => $sellerItems,
        'subtotal'  => $sub,
        'discount'  => $discount,
        'discount_pct' => $discountPct,
        'tax'       => $tax,
        'tax_pct'   => $taxPct,
        'total'     => $total,
        'currency'  => $currency,
    ];

    $grandSubtotal += $sub;
    $grandDiscount += $discount;
    $grandTax      += $tax;
    $grandTotal    += $total;
}

$__pageTitle = 'Checkout';
require __DIR__ . '/layout/header.php';
?>

<div class="container">
  <h1 class="page-title">Checkout</h1>

  <?php if ($err = flash('checkout_error')): ?>
    <div class="alert alert-error" style="margin-bottom: 1rem; color: #991b1b;"><?= e($err) ?><a href="cart.php" class="btn-link">Edit cart</a></div>
  <?php endif; ?>

  <div class="cart-layout">
    <div>
      <form id="checkoutForm" class="checkout-form" method="POST" action="checkout_commit.php">
        <?= csrf_field() ?>

        <section class="checkout-block">
          <h3>1 · Delivery details</h3>
          <div class="two-col">
            <label class="field"><span>Full name</span>
              <input type="text" name="full_name" required
                     value="<?= e($user['name'] ?? '') ?>">
            </label>
            <label class="field"><span>Phone</span>
              <input type="text" name="phone" required>
            </label>
          </div>
          <label class="field"><span>Delivery address</span>
            <textarea name="address" rows="3" required></textarea>
          </label>
          <label class="field"><span>Notes <em>(optional)</em></span>
            <textarea name="notes" rows="2"></textarea>
          </label>
        </section>

        <?php if (!$user): ?>
        <section class="checkout-block">
          <h3>2 · Create your account</h3>
          <p class="step-sub">We'll send order updates to this email.</p>
          <div class="two-col">
            <label class="field"><span>Email</span>
              <input type="email" name="email" required>
            </label>
            <label class="field"><span>Password</span>
              <input type="password" name="password" minlength="8" required>
            </label>
          </div>
          <input type="hidden" name="role" value="buyer">
        </section>
        <?php endif; ?>

        <button type="submit" class="btn btn-accent btn-block btn-lg">
          Place order · <?= e($currency) ?> <?= number_format($grandTotal, 2) ?>
        </button>
      </form>
    </div>

    <aside class="cart-summary">
      <h3>Order summary</h3>

      <?php foreach ($sellers as $s): ?>
        <div class="seller-group">
          <div class="seller-group-head">
            <strong><?= e($s['name']) ?></strong>
            <small><?= count($s['items']) ?> item<?= count($s['items']) !== 1 ? 's' : '' ?></small>
          </div>

          <?php foreach ($s['items'] as $it): ?>
            <div class="summary-item">
              <div class="summary-thumb" style="background-image:url('<?= e($it['cover_image'] ?: '') ?>')"></div>
              <div>
                <strong><?= e($it['title']) ?></strong>
                <small>× <?= (int)$it['cart_qty'] ?></small>
              </div>
              <span><?= e($s['currency']) ?> <?= number_format((float)$it['price'] * (int)$it['cart_qty'], 2) ?></span>
            </div>
          <?php endforeach; ?>

          <div class="seller-totals">
            <div class="summary-row">
              <span>Subtotal</span>
              <strong><?= e($s['currency']) ?> <?= number_format($s['subtotal'], 2) ?></strong>
            </div>
            <?php if ($s['discount'] > 0): ?>
              <div class="summary-row discount">
                <span>Discount (<?= number_format($s['discount_pct'], 2) ?>%)</span>
                <strong>− <?= e($s['currency']) ?> <?= number_format($s['discount'], 2) ?></strong>
              </div>
            <?php endif; ?>
            <?php if ($s['tax'] > 0): ?>
              <div class="summary-row tax">
                <span>Tax (<?= number_format($s['tax_pct'], 2) ?>%)</span>
                <strong>+ <?= e($s['currency']) ?> <?= number_format($s['tax'], 2) ?></strong>
              </div>
            <?php endif; ?>
            <div class="summary-row group-total">
              <span>Seller total</span>
              <strong><?= e($s['currency']) ?> <?= number_format($s['total'], 2) ?></strong>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="grand-totals">
        <?php if (count($sellers) > 1): ?>
          <div class="summary-row"><span>Subtotal (all sellers)</span>
            <strong><?= e($currency) ?> <?= number_format($grandSubtotal, 2) ?></strong></div>
          <?php if ($grandDiscount > 0): ?>
            <div class="summary-row discount"><span>Discounts</span>
              <strong>− <?= e($currency) ?> <?= number_format($grandDiscount, 2) ?></strong></div>
          <?php endif; ?>
          <?php if ($grandTax > 0): ?>
            <div class="summary-row tax"><span>Taxes</span>
              <strong>+ <?= e($currency) ?> <?= number_format($grandTax, 2) ?></strong></div>
          <?php endif; ?>
        <?php endif; ?>
        <div class="summary-row total">
          <span>Total</span>
          <strong><?= e($currency) ?> <?= number_format($grandTotal, 2) ?></strong>
        </div>
      </div>
    </aside>
  </div>
</div>

<?php require __DIR__ . '/layout/footer.php'; ?>