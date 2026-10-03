<?php
require_once __DIR__ . '/../_helpers.php';

$u    = current_user();
$days = max(7, min(365, (int)($_GET['days'] ?? 90)));

/* ═══════════════════════════════════════════════════
   KPI BLOCK — expanded
   ═══════════════════════════════════════════════════ */
$kpi = db()->prepare("
    SELECT
        COUNT(*)                                          AS orders_count,
        COALESCE(SUM(total_amount), 0)                    AS spent,
        COALESCE(AVG(total_amount), 0)                    AS avg_order,
        COALESCE(SUM(quantity), 0)                        AS items_bought
    FROM orders
    WHERE buyer_id = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND status != 'cancelled'
");
$kpi->execute([$u['id'], $days]);
$kpi = $kpi->fetch();

$ordersCount = (int)$kpi['orders_count'];
$spent       = (float)$kpi['spent'];
$avgOrder    = (float)$kpi['avg_order'];
$itemsBought = (int)$kpi['items_bought'];
$avgItems    = $ordersCount > 0 ? round($itemsBought / $ordersCount, 1) : 0;

// Refund totals in the same window
$refundStmt = db()->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN status='refunded' THEN amount ELSE 0 END), 0) AS refunded,
        COUNT(*)                                                              AS refund_count
    FROM refunds
    WHERE requested_by = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
");
$refundStmt->execute([$u['id'], $days]);
$refundInfo = $refundStmt->fetch();

$refundedTotal = (float)$refundInfo['refunded'];
$refundCount   = (int)$refundInfo['refund_count'];

// Best month within the window
$bestMonthStmt = db()->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS m,
           SUM(total_amount) AS spent
    FROM orders
    WHERE buyer_id = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND status != 'cancelled'
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY spent DESC
    LIMIT 1
");
$bestMonthStmt->execute([$u['id'], $days]);
$bestMonth = $bestMonthStmt->fetch();

/* ═══════════════════════════════════════════════════
   TOP SELLERS
   ═══════════════════════════════════════════════════ */
$sellers = db()->prepare("
    SELECT s.id, s.full_name AS seller_name,
           COALESCE(NULLIF(sp.business_name,''), s.full_name) AS shop_name,
           COUNT(o.id)            AS orders_count,
           SUM(o.total_amount)    AS spent
    FROM orders o
    JOIN users s ON s.id = o.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = s.id
    WHERE o.buyer_id = ?
      AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND o.status != 'cancelled'
    GROUP BY s.id
    ORDER BY spent DESC
    LIMIT 10
");
$sellers->execute([$u['id'], $days]);
$topSellers = $sellers->fetchAll();

/* ═══════════════════════════════════════════════════
   TOP ITEMS
   ═══════════════════════════════════════════════════ */
$items = db()->prepare("
    SELECT p.title, p.cover_image,
           SUM(o.quantity)      AS qty,
           SUM(o.total_amount)  AS spent
    FROM orders o
    JOIN products p ON p.id = o.product_id
    WHERE o.buyer_id = ?
      AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND o.status != 'cancelled'
    GROUP BY p.id
    ORDER BY qty DESC
    LIMIT 10
");
$items->execute([$u['id'], $days]);
$topItems = $items->fetchAll();

/* ═══════════════════════════════════════════════════
   TOP CATEGORIES
   ═══════════════════════════════════════════════════ */
$categories = db()->prepare("
    SELECT c.name AS cat_name, c.icon,
           SUM(o.total_amount) AS spent,
           SUM(o.quantity)     AS qty
    FROM orders o
    JOIN products p ON p.id = o.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE o.buyer_id = ?
      AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND o.status != 'cancelled'
      AND c.id IS NOT NULL
    GROUP BY c.id
    ORDER BY spent DESC
    LIMIT 6
");
$categories->execute([$u['id'], $days]);
$topCategories = $categories->fetchAll();

/* ═══════════════════════════════════════════════════
   MONTHLY SPEND (last 6 months, always fixed window)
   ═══════════════════════════════════════════════════ */
$monthly = db()->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS m,
           SUM(total_amount) AS spent,
           COUNT(*)          AS orders
    FROM orders
    WHERE buyer_id = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
      AND status != 'cancelled'
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY m ASC
");
$monthly->execute([$u['id']]);
$monthlyRows = $monthly->fetchAll();

/* ═══════════════════════════════════════════════════
   HEADER ACTIONS
   ═══════════════════════════════════════════════════ */
$actions = '
  <div class="btn-group">
    <button class="btn btn-ghost" data-open-modal="exportModal">⬇ Download</button>
    <form method="get" class="inline-form">
      <input type="hidden" name="tab" value="reports">
      <select name="days" onchange="this.form.submit()" class="mini-select">
        ' . implode('', array_map(function($d) use ($days) {
          return "<option value=\"$d\"" . ($days == $d ? ' selected' : '') . ">Last $d days</option>";
        }, [30, 90, 180, 365])) . '
      </select>
    </form>
  </div>
';

dash_header('My reports', "Your activity for the last $days days", $actions);
?>

<!-- ═══════════════════════════════════════════════════
     KPI CARDS
     ═══════════════════════════════════════════════════ -->
<div class="kpi-grid">
  <div class="kpi-card gradient decorated">
    <div class="kpi-icon">💰</div>
    <span class="kpi-label">Total spent</span>
    <span class="kpi-value"><?= money($spent) ?></span>
    <span class="kpi-delta">
      <?php if ($bestMonth): ?>
        Best month: <?= e(date('M Y', strtotime($bestMonth['m'] . '-01'))) ?>
      <?php else: ?>
        No activity yet
      <?php endif; ?>
    </span>
  </div>

  <div class="kpi-card info decorated">
    <div class="kpi-icon">🧾</div>
    <span class="kpi-label">Orders</span>
    <span class="kpi-value"><?= $ordersCount ?></span>
    <span class="kpi-delta">
      <?= $itemsBought ?> item<?= $itemsBought === 1 ? '' : 's' ?> ·
      <?= $avgItems ?> per order
    </span>
  </div>

  <div class="kpi-card accent">
    <div class="kpi-icon">📊</div>
    <span class="kpi-label">Avg order value</span>
    <span class="kpi-value"><?= money($avgOrder) ?></span>
    <span class="kpi-delta">Across <?= $ordersCount ?> order<?= $ordersCount === 1 ? '' : 's' ?></span>
  </div>

  <div class="kpi-card <?= $refundedTotal > 0 ? 'warn' : 'neutral' ?>">
    <div class="kpi-icon">↩️</div>
    <span class="kpi-label">Refunded</span>
    <span class="kpi-value"><?= money($refundedTotal) ?></span>
    <span class="kpi-delta">
      <?= $refundCount ?> refund<?= $refundCount === 1 ? '' : 's' ?> in this period
    </span>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════
     MONTHLY SPEND CHART
     ═══════════════════════════════════════════════════ -->
<div class="report-card">
  <div class="report-head">
    <h3>Monthly spending</h3>
    <small class="text-muted">Last 6 months</small>
  </div>

  <?php if (!$monthlyRows): ?>
    <div class="empty-state small">
      <p>No spending yet in the last 6 months.</p>
    </div>
  <?php else:
    $max = max(array_map(fn($r) => (float)$r['spent'], $monthlyRows)) ?: 1;
  ?>
    <div class="spark spark-labeled">
      <?php foreach ($monthlyRows as $r):
        $h = max(4, ((float)$r['spent'] / $max) * 100);
      ?>
        <div class="spark-col">
          <div class="spark-bar"
               style="height:<?= $h ?>%"
               title="<?= e(date('M Y', strtotime($r['m'] . '-01'))) ?>: <?= money((float)$r['spent']) ?> · <?= (int)$r['orders'] ?> orders">
          </div>
          <span class="spark-label">
            <?= e(date('M', strtotime($r['m'] . '-01'))) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>


<!-- ═══════════════════════════════════════════════════
     TOP LISTS GRID
     ═══════════════════════════════════════════════════ -->
<div class="report-grid">

  <!-- Top sellers -->
  <div class="report-card">
    <div class="report-head">
      <h3>Sellers you buy from most</h3>
      <small class="text-muted">By spend</small>
    </div>

    <?php if (!$topSellers): ?>
      <div class="empty-state small"><p>No sellers yet.</p></div>
    <?php else: ?>
      <ol class="report-list">
        <?php foreach ($topSellers as $s): ?>
          <li>
            <div class="report-avatar"><?= strtoupper(substr($s['shop_name'], 0, 1)) ?></div>
            <div class="report-item-body">
              <strong><?= e($s['shop_name']) ?></strong>
              <small>
                <?= (int)$s['orders_count'] ?> order<?= (int)$s['orders_count'] === 1 ? '' : 's' ?> ·
                <?= money((float)$s['spent']) ?>
              </small>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>

  <!-- Top items -->
  <div class="report-card">
    <div class="report-head">
      <h3>Items you buy most</h3>
      <small class="text-muted">By quantity</small>
    </div>

    <?php if (!$topItems): ?>
      <div class="empty-state small"><p>No items yet.</p></div>
    <?php else: ?>
      <ol class="report-list">
        <?php foreach ($topItems as $r): ?>
          <li>
            <div class="cell-thumb" style="background-image:url('<?= e($r['cover_image'] ?: '') ?>')"></div>
            <div class="report-item-body">
              <strong><?= e($r['title']) ?></strong>
              <small>
                <?= (int)$r['qty'] ?> bought ·
                <?= money((float)$r['spent']) ?>
              </small>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════
     TOP CATEGORIES
     ═══════════════════════════════════════════════════ -->
<?php if ($topCategories): ?>
<div class="report-card">
  <div class="report-head">
    <h3>Where your money goes</h3>
    <small class="text-muted">By category</small>
  </div>

  <?php
    $catTotal = array_sum(array_map(fn($c) => (float)$c['spent'], $topCategories)) ?: 1;
  ?>
  <div class="category-bars">
    <?php foreach ($topCategories as $c):
      $pct = round(((float)$c['spent'] / $catTotal) * 100);
    ?>
      <div class="category-row">
        <span class="category-label">
          <?= e(($c['icon'] ?? '📦') . ' ' . ($c['cat_name'] ?? 'Uncategorised')) ?>
        </span>
        <div class="category-track">
          <div class="category-fill" style="width:<?= $pct ?>%"></div>
        </div>
        <span class="category-amount">
          <?= money((float)$c['spent']) ?>
          <small><?= $pct ?>%</small>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════════════
     EXPORT MODAL
     ═══════════════════════════════════════════════════ -->
<div class="modal" id="exportModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>
    <h2>Download my report</h2>
    <p class="step-sub">A snapshot of your buying activity.</p>

    <form method="GET" action="buyer_reports_export.php" target="_blank">
      <label class="field"><span>Format</span>
        <select name="format">
          <option value="csv">CSV (Excel / Sheets)</option>
          <option value="pdf">PDF (print / save as PDF)</option>
        </select>
      </label>

      <label class="field"><span>Period</span>
        <select name="days">
          <option value="30"  <?= $days === 30  ? 'selected' : '' ?>>Last 30 days</option>
          <option value="90"  <?= $days === 90  ? 'selected' : '' ?>>Last 90 days</option>
          <option value="180" <?= $days === 180 ? 'selected' : '' ?>>Last 180 days</option>
          <option value="365" <?= $days === 365 ? 'selected' : '' ?>>Last 365 days</option>
        </select>
      </label>

      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-accent">Download</button>
      </div>
    </form>
  </div>
</div>


<script>
(() => {
  document.querySelectorAll('[data-open-modal]').forEach(btn => {
    btn.addEventListener('click', () => {
      const m = document.getElementById(btn.dataset.openModal);
      if (m) m.hidden = false;
    });
  });
  document.querySelectorAll('[data-close-modal]').forEach(el => {
    el.addEventListener('click', () => el.closest('.modal').hidden = true);
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
      document.querySelectorAll('.modal:not([hidden])').forEach(m => m.hidden = true);
  });
})();
</script>