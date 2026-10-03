<?php
require __DIR__ . '/db.php';
require_login();

$u     = current_user();
$group = $_GET['group'] ?? '';
if (!$group) { header('Location: dashboard.php'); exit; }

$stmt = db()->prepare("
    SELECT o.*, p.title AS product_title, p.cover_image,
           s.full_name AS seller_name,
           COALESCE(sp.business_name, s.full_name) AS seller_shop
    FROM orders o
    JOIN products p ON p.id = o.product_id
    JOIN users s   ON s.id = o.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = s.id
    WHERE o.order_group = ? AND o.buyer_id = ?
    ORDER BY o.seller_id
");
$stmt->execute([$group, $u['id']]);
$orders = $stmt->fetchAll();

if (!$orders) { header('Location: dashboard.php'); exit; }

$grandTotal = array_sum(array_map(fn($o) => (float)$o['total_amount'], $orders));

$__pageTitle = 'Order placed';
require __DIR__ . '/layout/header.php';
?>

<div class="container">
  <div class="order-success">
    <div class="success-icon">🎉</div>
    <h1>Order placed</h1>
    <p class="step-sub">
      Group <code><?= e($group) ?></code> · <?= count($orders) ?> item(s) ·
      <?= e($orders[0]['currency']) ?> <?= number_format($grandTotal, 2) ?>
    </p>

    <div class="table-card" style="text-align:left">
      <table class="data-table">
        <thead>
          <tr>
            <th>Order</th><th>Item</th><th>Seller</th>
            <th class="num">Subtotal</th>
            <th class="num">Discount</th>
            <th class="num">Tax</th>
            <th class="num">Total</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
          <tr>
            <td><code><?= e($o['order_code']) ?></code></td>
            <td>
              <div class="cell-product">
                <div class="cell-thumb" style="background-image:url('<?= e($o['cover_image'] ?: '') ?>')"></div>
                <div><strong><?= e($o['product_title']) ?></strong><small>× <?= (int)$o['quantity'] ?></small></div>
              </div>
            </td>
            <td><?= e($o['seller_shop']) ?></td>
            <td class="num"><?= number_format((float)$o['subtotal'], 2) ?></td>
            <td class="num">
              <?php if ((float)$o['discount_amount'] > 0): ?>
                − <?= number_format((float)$o['discount_amount'], 2) ?>
                <small>(<?= number_format((float)$o['discount_percent'], 2) ?>%)</small>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="num">
              <?php if ((float)$o['tax_amount'] > 0): ?>
                + <?= number_format((float)$o['tax_amount'], 2) ?>
                <small>(<?= number_format((float)$o['tax_percent'], 2) ?>%)</small>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="num"><strong><?= number_format((float)$o['total_amount'], 2) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="order-success-actions">
      <a href="dashboard.php?tab=orders" class="btn btn-accent btn-lg">View my orders →</a>
      <a href="index.php" class="btn btn-ghost btn-lg">Keep shopping</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/layout/footer.php'; ?>    