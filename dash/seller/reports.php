<?php
require_once __DIR__ . '/../_helpers.php';

$u = current_user();
$days = max(7, min(365, (int)($_GET['days'] ?? 30)));

// ═══ KPIs ═══
$kpi = db()->prepare("
    SELECT
      COUNT(*) AS orders_count,
      COALESCE(SUM(total_amount), 0) AS revenue,
      COALESCE(AVG(total_amount), 0) AS avg_order
    FROM orders
    WHERE seller_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND status != 'cancelled'
");
$kpi->execute([$u['id'], $days]);
$kpi = $kpi->fetch();

// ═══ Most viewed (last N days) ═══
$viewed = db()->prepare("
    SELECT p.id, p.title, p.cover_image, p.price,
           COUNT(v.id) AS views
    FROM products p
    LEFT JOIN product_views v ON v.product_id = p.id
        AND v.viewed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    WHERE p.seller_id = ?
    GROUP BY p.id
    ORDER BY views DESC
    LIMIT 10
");
$viewed->execute([$days, $u['id']]);
$mostViewed = $viewed->fetchAll();

// ═══ Best selling (last N days) ═══
$selling = db()->prepare("
    SELECT p.id, p.title, p.cover_image,
           SUM(o.quantity) AS units_sold,
           SUM(o.total_amount) AS revenue
    FROM orders o
    JOIN products p ON p.id = o.product_id
    WHERE o.seller_id = ?
      AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND o.status != 'cancelled'
    GROUP BY p.id
    ORDER BY units_sold DESC
    LIMIT 10
");
$selling->execute([$u['id'], $days]);
$bestSelling = $selling->fetchAll();

// ═══ Frequent customers ═══
$customers = db()->prepare("
    SELECT b.id, b.full_name, b.email,
           COUNT(o.id) AS orders_count,
           SUM(o.total_amount) AS spent,
           MAX(o.created_at) AS last_order
    FROM orders o
    JOIN users b ON b.id = o.buyer_id
    WHERE o.seller_id = ?
      AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND o.status != 'cancelled'
    GROUP BY b.id
    ORDER BY orders_count DESC, spent DESC
    LIMIT 10
");
$customers->execute([$u['id'], $days]);
$topCustomers = $customers->fetchAll();

// ═══ Daily revenue spark (last 14 days) ═══
$daily = db()->prepare("
    SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount),0) AS revenue, COUNT(*) AS orders
    FROM orders
    WHERE seller_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
      AND status != 'cancelled'
    GROUP BY DATE(created_at)
    ORDER BY d ASC
");
$daily->execute([$u['id']]);
$dailyRows = $daily->fetchAll();

$actions = '<form method="get" class="inline-form">
              <input type="hidden" name="tab" value="reports">
              <select name="days" onchange="this.form.submit()" class="mini-select">
                ' . implode('', array_map(function($d) use ($days) {
                  return "<option value=\"$d\"" . ($days==$d?' selected':'') . ">Last $d days</option>";
                }, [7, 30, 90, 180, 365])) . '
              </select>
            </form>';

dash_header('Reports', "Performance for the last $days days", $actions);
?>

<!-- KPI cards -->
<div class="kpi-grid">
  <div class="kpi-card gradient decorated">
    <span class="kpi-label">Revenue</span>
    <span class="kpi-value"><?= money((float)$kpi['revenue']) ?></span>
  </div>
  <div class="kpi-card warn decorated">
    <span class="kpi-label">Orders</span>
    <span class="kpi-value"><?= (int)$kpi['orders_count'] ?></span>
  </div>
  <div class="kpi-card info decorated">
    <span class="kpi-label">Avg order value</span>
    <span class="kpi-value"><?= money((float)$kpi['avg_order']) ?></span>
  </div>
</div>

<!-- Daily revenue spark -->
<div class="report-card">
  <h3>Last 14 days</h3>
  <?php if (!$dailyRows): ?>
    <p class="step-sub">No orders in this window.</p>
  <?php else:
    $max = max(array_map(fn($r) => (float)$r['revenue'], $dailyRows)) ?: 1;
  ?>
    <div class="spark">
      <?php foreach ($dailyRows as $r): ?>
        <div class="spark-bar"
             style="height:<?= max(4, ((float)$r['revenue'] / $max) * 100) ?>%"
             title="<?= e(date('M j', strtotime($r['d']))) ?>: <?= money((float)$r['revenue']) ?> · <?= (int)$r['orders'] ?> orders">
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="report-grid">
  <!-- Most viewed -->
  <div class="report-card">
    <h3>Most viewed items</h3>
    <?php if (!$mostViewed): ?>
      <p class="step-sub">No data yet.</p>
    <?php else: ?>
      <ol class="report-list">
        <?php foreach ($mostViewed as $r): ?>
          <li>
            <div class="cell-thumb" style="background-image:url('<?= e($r['cover_image'] ?: '') ?>')"></div>
            <div class="report-item-body">
              <strong><?= e($r['title']) ?></strong>
              <small><?= (int)$r['views'] ?> views · <?= money((float)$r['price']) ?></small>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>

  <!-- Best selling -->
  <div class="report-card">
    <h3>Best selling items</h3>
    <?php if (!$bestSelling): ?>
      <p class="step-sub">No sales yet.</p>
    <?php else: ?>
      <ol class="report-list">
        <?php foreach ($bestSelling as $r): ?>
          <li>
            <div class="cell-thumb" style="background-image:url('<?= e($r['cover_image'] ?: '') ?>')"></div>
            <div class="report-item-body">
              <strong><?= e($r['title']) ?></strong>
              <small><?= (int)$r['units_sold'] ?> sold · <?= money((float)$r['revenue']) ?></small>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
</div>

<!-- Frequent customers -->
<div class="report-card">
  <h3>Frequent customers</h3>
  <?php if (!$topCustomers): ?>
    <p class="step-sub">No repeat buyers yet.</p>
  <?php else: ?>
    <div class="table-card">
      <table class="data-table">
        <thead>
          <tr><th>Buyer</th><th class="num">Orders</th><th class="num">Spent</th><th>Last order</th></tr>
        </thead>
        <tbody>
          <?php foreach ($topCustomers as $c): ?>
            <tr>
              <td><strong><?= e($c['full_name']) ?></strong><br><small><?= e($c['email']) ?></small></td>
              <td class="num"><?= (int)$c['orders_count'] ?></td>
              <td class="num"><?= money((float)$c['spent']) ?></td>
              <td><small><?= e(date('M j, Y', strtotime($c['last_order']))) ?></small></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>