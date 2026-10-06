<?php
$__pageTitle = 'Overview';
$__activeNav = 'home';
require __DIR__ . '/_layout.php';

$pdo = db();

/* ─── KPIs ─── */
$kpi = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM users WHERE role='buyer')                            AS buyers,
        (SELECT COUNT(*) FROM users WHERE role='seller')                           AS sellers,
        (SELECT COUNT(*) FROM users WHERE is_active=0)                             AS inactive,
        (SELECT COUNT(*) FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW()) AS locked,
        (SELECT COUNT(*) FROM products WHERE status='active')                      AS products,
        (SELECT COUNT(*) FROM orders)                                              AS orders,
        (SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status!='cancelled') AS revenue,
        (SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE())             AS orders_today,
        (SELECT COALESCE(SUM(total_amount),0) FROM orders
            WHERE DATE(created_at)=CURDATE() AND status!='cancelled')              AS revenue_today
")->fetch();

/* ─── Recent signups ─── */
$recentUsers = $pdo->query("
    SELECT id, full_name, email, role, is_active, created_at
    FROM users
    ORDER BY created_at DESC
    LIMIT 8
")->fetchAll();

/* ─── Recent orders ─── */
$recentOrders = $pdo->query("
    SELECT o.order_code, o.total_amount, o.status, o.created_at,
           p.title AS product_title,
           b.full_name AS buyer_name,
           s.full_name AS seller_name
    FROM orders o
    JOIN products p ON p.id = o.product_id
    JOIN users b   ON b.id = o.buyer_id
    JOIN users s   ON s.id = o.seller_id
    ORDER BY o.created_at DESC
    LIMIT 8
")->fetchAll();

/* ─── Locked accounts ─── */
$locked = $pdo->query("
    SELECT id, full_name, email, locked_until
    FROM users
    WHERE locked_until IS NOT NULL AND locked_until > NOW()
    ORDER BY locked_until DESC
    LIMIT 5
")->fetchAll();

/* ─── Top sellers by revenue ─── */
$topSellers = $pdo->query("
    SELECT u.id, u.full_name,
           COALESCE(NULLIF(sp.business_name,''), u.full_name) AS shop,
           COUNT(o.id) AS orders_count,
           COALESCE(SUM(o.total_amount),0) AS revenue
    FROM orders o
    JOIN users u ON u.id = o.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = u.id
    WHERE o.status != 'cancelled'
    GROUP BY u.id
    ORDER BY revenue DESC
    LIMIT 5
")->fetchAll();
?>

<div class="dash-top">
  <div>
    <h1>Overview</h1>
    <p class="step-sub">Platform health at a glance.</p>
  </div>
</div>

<!-- ═══ KPI CARDS ═══ -->
<div class="kpi-grid">
  <div class="kpi-card gradient decorated">
    <div class="kpi-icon">💰</div>
    <span class="kpi-label">Lifetime revenue</span>
    <span class="kpi-value"><?= money((float)$kpi['revenue']) ?></span>
    <span class="kpi-delta"><?= (int)$kpi['orders'] ?> orders</span>
  </div>

  <div class="kpi-card accent decorated">
    <div class="kpi-icon">📅</div>
    <span class="kpi-label">Today</span>
    <span class="kpi-value"><?= money((float)$kpi['revenue_today']) ?></span>
    <span class="kpi-delta"><?= (int)$kpi['orders_today'] ?> orders</span>
  </div>

  <div class="kpi-card info">
    <div class="kpi-icon">👥</div>
    <span class="kpi-label">Buyers</span>
    <span class="kpi-value"><?= (int)$kpi['buyers'] ?></span>
    <span class="kpi-delta"><?= (int)$kpi['sellers'] ?> sellers</span>
  </div>

  <div class="kpi-card <?= (int)$kpi['inactive'] > 0 || (int)$kpi['locked'] > 0 ? 'warn' : 'neutral' ?>">
    <div class="kpi-icon">🚫</div>
    <span class="kpi-label">Inactive / Locked</span>
    <span class="kpi-value"><?= (int)$kpi['inactive'] ?> / <?= (int)$kpi['locked'] ?></span>
    <span class="kpi-delta">
      <?= (int)$kpi['products'] ?> active products
    </span>
  </div>
</div>

<!-- ═══ Actionable alerts ═══ -->
<?php if ($locked || (int)$kpi['inactive'] > 0): ?>
<div class="alert-row">
  <?php if ((int)$kpi['inactive'] > 0): ?>
    <a class="alert-pill warn" href="users.php?status=inactive">
      🚫 <?= (int)$kpi['inactive'] ?> inactive account<?= (int)$kpi['inactive'] === 1 ? '' : 's' ?>
    </a>
  <?php endif; ?>
  <?php if ((int)$kpi['locked'] > 0): ?>
    <a class="alert-pill danger" href="users.php?status=locked">
      🔒 <?= (int)$kpi['locked'] ?> locked account<?= (int)$kpi['locked'] === 1 ? '' : 's' ?>
    </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ═══ Locked accounts ═══ -->
<?php if ($locked): ?>
<div class="report-card">
  <div class="report-head">
    <h3>🔒 Locked accounts</h3>
    <a href="users.php?status=locked" class="btn-link">View all →</a>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>User</th><th>Email</th><th>Locked until</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($locked as $l): ?>
          <tr>
            <td><a href="user.php?id=<?= (int)$l['id'] ?>"><strong><?= e($l['full_name']) ?></strong></a></td>
            <td><?= e($l['email']) ?></td>
            <td><small><?= e(date('M j, Y g:ia', strtotime($l['locked_until']))) ?></small></td>
            <td class="row-actions">
              <form method="post" action="user_action.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$l['id'] ?>">
                <input type="hidden" name="action" value="unlock">
                <button class="btn-link">Unlock now</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ═══ Two-column reports ═══ -->
<div class="report-grid">

  <div class="report-card">
    <div class="report-head">
      <h3>Recent signups</h3>
      <a href="users.php" class="btn-link">All users →</a>
    </div>
    <div class="table-card">
      <table class="data-table">
        <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ($recentUsers as $ru): ?>
          <tr>
            <td>
              <a href="user.php?id=<?= (int)$ru['id'] ?>"><?= e($ru['full_name']) ?></a>
              <br><small><?= e($ru['email']) ?></small>
            </td>
            <td><span class="status-pill"><?= e($ru['role']) ?></span></td>
            <td>
              <?= $ru['is_active']
                    ? '<span class="status-pill status-completed">active</span>'
                    : '<span class="status-pill status-failed">inactive</span>' ?>
            </td>
            <td><small><?= e(date('M j', strtotime($ru['created_at']))) ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="report-card">
    <div class="report-head">
      <h3>Top sellers</h3>
      <small class="text-muted">By revenue</small>
    </div>
    <?php if (!$topSellers): ?>
      <p class="step-sub">No sales yet.</p>
    <?php else: ?>
      <ol class="report-list">
        <?php foreach ($topSellers as $s): ?>
          <li>
            <div class="report-avatar"><?= strtoupper(substr($s['shop'], 0, 1)) ?></div>
            <div class="report-item-body">
              <strong><?= e($s['shop']) ?></strong>
              <small><?= (int)$s['orders_count'] ?> orders · <?= money((float)$s['revenue']) ?></small>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
</div>

<div class="report-card">
  <div class="report-head">
    <h3>Recent orders</h3>
    <a href="orders.php" class="btn-link">All orders →</a>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Order</th><th>Item</th><th>Buyer → Seller</th><th class="num">Total</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentOrders as $ro): ?>
        <tr>
          <td><code><?= e($ro['order_code']) ?></code></td>
          <td><?= e($ro['product_title']) ?></td>
          <td><small><?= e($ro['buyer_name']) ?> → <?= e($ro['seller_name']) ?></small></td>
          <td class="num"><strong><?= money((float)$ro['total_amount']) ?></strong></td>
          <td><?= status_pill($ro['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/_layout_end.php'; ?>