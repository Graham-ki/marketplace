<?php
require __DIR__ . '/_layout.php';
$__pageTitle = 'User detail';
$__activeNav = 'users';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: users.php'); exit; }

$pdo = db();
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$target = $stmt->fetch();
if (!$target) { http_response_code(404); die('User not found'); }

$isLocked = $target['locked_until'] && strtotime($target['locked_until']) > time();

// For sellers: their products
$products = [];
if ($target['role'] === 'seller') {
    $s = $pdo->prepare("
        SELECT id, title, price, quantity, status, created_at
        FROM products WHERE seller_id = ?
        ORDER BY created_at DESC LIMIT 20
    ");
    $s->execute([$id]);
    $products = $s->fetchAll();
}

// Orders as buyer
$sb = $pdo->prepare("
    SELECT o.order_code, o.total_amount, o.status, o.created_at, p.title AS product_title
    FROM orders o JOIN products p ON p.id = o.product_id
    WHERE o.buyer_id = ?
    ORDER BY o.created_at DESC LIMIT 20
");
$sb->execute([$id]);
$buyerOrders = $sb->fetchAll();

// Orders as seller
$ss = $pdo->prepare("
    SELECT o.order_code, o.total_amount, o.status, o.created_at, p.title AS product_title
    FROM orders o JOIN products p ON p.id = o.product_id
    WHERE o.seller_id = ?
    ORDER BY o.created_at DESC LIMIT 20
");
$ss->execute([$id]);
$sellerOrders = $ss->fetchAll();

// Admin log entries targeting this user
$log = $pdo->prepare("
    SELECT al.*, a.full_name AS admin_name
    FROM admin_log al
    JOIN users a ON a.id = al.admin_id
    WHERE al.target_user_id = ?
    ORDER BY al.created_at DESC LIMIT 20
");
$log->execute([$id]);
$adminLog = $log->fetchAll();
?>

<div class="dash-top">
  <div>
    <h1><?= e($target['full_name']) ?></h1>
    <p class="step-sub">
      <?= e($target['email']) ?> ·
      <span class="status-pill"><?= e($target['role']) ?></span>
      <?php if ($isLocked): ?>
        · <span class="status-pill status-failed">locked</span>
      <?php elseif ((int)$target['is_active'] === 1): ?>
        · <span class="status-pill status-completed">active</span>
      <?php else: ?>
        · <span class="status-pill status-cancelled">inactive</span>
      <?php endif; ?>
    </p>
  </div>
  <div class="dash-top-actions">
    <a href="users.php" class="btn btn-ghost">← All users</a>

    <form method="post" action="user_action.php" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="user_id" value="<?= (int)$target['id'] ?>">
      <?php if ((int)$target['is_active'] === 1): ?>
        <input type="hidden" name="action" value="deactivate">
        <button class="btn btn-danger">Deactivate</button>
      <?php else: ?>
        <input type="hidden" name="action" value="activate">
        <button class="btn btn-accent">Activate</button>
      <?php endif; ?>
    </form>

    <?php if ($isLocked): ?>
      <form method="post" action="user_action.php" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="user_id" value="<?= (int)$target['id'] ?>">
        <input type="hidden" name="action" value="unlock">
        <button class="btn btn-ghost">Unlock</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="report-card">
  <h3>Account info</h3>
  <div class="table-card">
    <table class="data-table">
      <tbody>
        <tr><td><strong>ID</strong></td><td><?= (int)$target['id'] ?></td></tr>
        <tr><td><strong>Full name</strong></td><td><?= e($target['full_name']) ?></td></tr>
        <tr><td><strong>Email</strong></td><td><?= e($target['email']) ?></td></tr>
        <tr><td><strong>Phone</strong></td><td><?= e($target['phone'] ?: '—') ?></td></tr>
        <tr><td><strong>Role</strong></td><td><?= e($target['role']) ?></td></tr>
        <tr><td><strong>Status</strong></td><td><?= (int)$target['is_active'] ? 'Active' : 'Inactive' ?></td></tr>
        <tr><td><strong>Joined</strong></td><td><?= e(date('M j, Y g:ia', strtotime($target['created_at']))) ?></td></tr>
        <tr><td><strong>Failed logins</strong></td><td><?= (int)($target['failed_login_attempts'] ?? 0) ?></td></tr>
        <?php if ($target['locked_until']): ?>
          <tr><td><strong>Locked until</strong></td><td><?= e(date('M j, Y g:ia', strtotime($target['locked_until']))) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($products): ?>
<div class="report-card">
  <h3>Products (<?= count($products) ?>)</h3>
  <div class="table-card">
    <table class="data-table">
      <thead><tr><th>Title</th><th class="num">Price</th><th class="num">Stock</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($products as $p): ?>
          <tr>
            <td><a href="../product.php?id=<?= (int)$p['id'] ?>"><?= e($p['title']) ?></a></td>
            <td class="num"><?= money((float)$p['price']) ?></td>
            <td class="num"><?= (int)$p['quantity'] ?></td>
            <td><?= status_pill($p['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($buyerOrders): ?>
<div class="report-card">
  <h3>Orders as buyer (<?= count($buyerOrders) ?>)</h3>
  <div class="table-card">
    <table class="data-table">
      <thead><tr><th>Order</th><th>Item</th><th class="num">Total</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($buyerOrders as $o): ?>
          <tr>
            <td><code><?= e($o['order_code']) ?></code></td>
            <td><?= e($o['product_title']) ?></td>
            <td class="num"><?= money((float)$o['total_amount']) ?></td>
            <td><?= status_pill($o['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($sellerOrders): ?>
<div class="report-card">
  <h3>Orders as seller (<?= count($sellerOrders) ?>)</h3>
  <div class="table-card">
    <table class="data-table">
      <thead><tr><th>Order</th><th>Item</th><th class="num">Total</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($sellerOrders as $o): ?>
          <tr>
            <td><code><?= e($o['order_code']) ?></code></td>
            <td><?= e($o['product_title']) ?></td>
            <td class="num"><?= money((float)$o['total_amount']) ?></td>
            <td><?= status_pill($o['status']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($adminLog): ?>
<div class="report-card">
  <h3>Admin history</h3>
  <div class="table-card">
    <table class="data-table">
      <thead><tr><th>When</th><th>Admin</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($adminLog as $l): ?>
          <tr>
            <td><small><?= e(date('M j, Y g:ia', strtotime($l['created_at']))) ?></small></td>
            <td><?= e($l['admin_name']) ?></td>
            <td><?= e($l['action']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_end.php'; ?>