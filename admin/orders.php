<?php
$__pageTitle = 'Orders';
$__activeNav = 'orders';
require __DIR__ . '/_layout.php';

$pdo    = db();
$status = $_GET['status'] ?? '';
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;

if (!in_array($status, ['', 'pending','confirmed','shipped','delivered','cancelled'], true)) $status = '';

/* ═══ KPIs ═══ */
$kpi = $pdo->query("
    SELECT
        COUNT(*)                                                             AS total_orders,
        COALESCE(SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END), 0)     AS pending,
        COALESCE(SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END), 0)     AS delivered,
        COALESCE(SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END), 0)     AS cancelled,
        COALESCE(SUM(CASE WHEN status!='cancelled'
                          THEN total_amount ELSE 0 END), 0)                  AS revenue,
        COALESCE(SUM(CASE WHEN DATE(created_at)=CURDATE()
                          THEN 1 ELSE 0 END), 0)                             AS today_orders
    FROM orders
")->fetch();

/* ═══ Filters ═══ */
$where = [];
$args  = [];

if ($status !== '') { $where[] = "o.status = ?"; $args[] = $status; }
if ($q !== '') {
    $where[] = "(o.order_code LIKE ? OR b.full_name LIKE ? OR s.full_name LIKE ? OR p.title LIKE ?)";
    $like = "%$q%";
    array_push($args, $like, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ═══ Pagination ═══ */
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM orders o
    JOIN products p ON p.id = o.product_id
    JOIN users b ON b.id = o.buyer_id
    JOIN users s ON s.id = o.seller_id
    $whereSql
");
$countStmt->execute($args);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $per));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $per;

/* ═══ Fetch ═══ */
$stmt = $pdo->prepare("
    SELECT o.*, p.title AS product_title,
           b.id AS buyer_id, b.full_name AS buyer_name, b.email AS buyer_email,
           s.id AS seller_id, s.full_name AS seller_name,
           COALESCE(NULLIF(sp.business_name,''), s.full_name) AS seller_shop,
           pay.status AS payment_status
    FROM orders o
    JOIN products p ON p.id = o.product_id
    JOIN users b ON b.id = o.buyer_id
    JOIN users s ON s.id = o.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = s.id
    LEFT JOIN payments pay ON pay.order_id = o.id
    $whereSql
    ORDER BY o.created_at DESC
    LIMIT $per OFFSET $offset
");
$stmt->execute($args);
$rows = $stmt->fetchAll();

function orders_admin_url(array $over = []): string {
    $base = [
        'q'      => $_GET['q']      ?? '',
        'status' => $_GET['status'] ?? '',
        'page'   => $_GET['page']   ?? '',
    ];
    $params = array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null);
    return 'orders.php' . ($params ? '?' . http_build_query($params) : '');
}

dash_header('Orders', $total . ' total');
?>

<!-- ═══ KPIs ═══ -->
<div class="kpi-grid">
  <div class="kpi-card gradient decorated">
    <div class="kpi-icon">💰</div>
    <span class="kpi-label">Lifetime revenue</span>
    <span class="kpi-value"><?= money((float)$kpi['revenue']) ?></span>
    <span class="kpi-delta"><?= (int)$kpi['total_orders'] ?> orders</span>
  </div>

  <div class="kpi-card accent">
    <div class="kpi-icon">📅</div>
    <span class="kpi-label">Today</span>
    <span class="kpi-value"><?= (int)$kpi['today_orders'] ?></span>
    <span class="kpi-delta">orders placed</span>
  </div>

  <div class="kpi-card <?= (int)$kpi['pending'] > 0 ? 'warn' : 'neutral' ?>">
    <div class="kpi-icon">⏳</div>
    <span class="kpi-label">Pending</span>
    <span class="kpi-value"><?= (int)$kpi['pending'] ?></span>
    <span class="kpi-delta"><?= (int)$kpi['pending'] > 0 ? 'Need confirmation' : 'All confirmed' ?></span>
  </div>

  <div class="kpi-card success">
    <div class="kpi-icon">✅</div>
    <span class="kpi-label">Delivered</span>
    <span class="kpi-value"><?= (int)$kpi['delivered'] ?></span>
    <span class="kpi-delta"><?= (int)$kpi['cancelled'] ?> cancelled</span>
  </div>
</div>

<!-- ═══ Filters ═══ -->
<form method="get" class="list-toolbar">
  <div class="search-mini">
    <span class="search-mini-icon">🔍</span>
    <input type="search" name="q" value="<?= e($q) ?>"
           placeholder="Search order, buyer, seller, item…" autocomplete="off">
  </div>
  <select name="status" class="mini-select" onchange="this.form.submit()">
    <option value="">All statuses</option>
    <?php foreach (['pending','confirmed','shipped','delivered','cancelled'] as $s): ?>
      <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($q || $status): ?>
    <a href="orders.php" class="btn-link">Clear</a>
  <?php endif; ?>
</form>

<?php if (!$rows): ?>
  <div class="empty-state small"><p>No orders match.</p></div>
<?php else: ?>
  <div class="table-scroll">
    <table class="data-table data-table-wide">
      <thead>
        <tr>
          <th>Order</th>
          <th>Item</th>
          <th>Buyer</th>
          <th>Seller</th>
          <th class="num">Total</th>
          <th>Status</th>
          <th>Payment</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $o): ?>
        <tr>
          <td><code><?= e($o['order_code']) ?></code></td>
          <td><?= e($o['product_title']) ?></td>
          <td>
            <a href="user.php?id=<?= (int)$o['buyer_id'] ?>">
              <?= e($o['buyer_name']) ?>
            </a>
          </td>
          <td>
            <a href="user.php?id=<?= (int)$o['seller_id'] ?>">
              <?= e($o['seller_shop']) ?>
            </a>
          </td>
          <td class="num"><strong><?= money((float)$o['total_amount']) ?></strong></td>
          <td><?= status_pill($o['status']) ?></td>
          <td>
            <?php if ($o['payment_status']): ?>
              <span class="status-pill status-<?= e($o['payment_status']) ?>">
                <?= e($o['payment_status']) ?>
              </span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><small><?= e(date('M j, Y', strtotime($o['created_at']))) ?></small></td>
          <td class="row-actions">
            <a href="../receipt.php?id=<?= (int)$o['id'] ?>" target="_blank" class="btn-link">📄</a>
            <form method="post" action="order_action.php" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
              <select name="status" onchange="this.form.submit()" class="mini-select">
                <?php foreach (['pending','confirmed','shipped','delivered','cancelled'] as $s): ?>
                  <option value="<?= $s ?>" <?= $o['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
            <button class="btn-link danger"
                    data-delete-order="<?= (int)$o['id'] ?>"
                    data-delete-code="<?= e($o['order_code']) ?>">Delete</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav class="pagination">
      <?php if ($page > 1): ?>
        <a href="<?= e(orders_admin_url(['page' => $page - 1])) ?>" class="page-btn">‹ Prev</a>
      <?php else: ?>
        <span class="page-btn disabled">‹ Prev</span>
      <?php endif; ?>
      <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="<?= e(orders_admin_url(['page' => $i])) ?>"
           class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?>
        <a href="<?= e(orders_admin_url(['page' => $page + 1])) ?>" class="page-btn">Next ›</a>
      <?php else: ?>
        <span class="page-btn disabled">Next ›</span>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<!-- ═══ Delete modal ═══ -->
<div class="modal" id="deleteOrderModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>
    <div class="confirm-hero">
      <div class="confirm-icon">🗑️</div>
      <h2>Delete order?</h2>
      <p class="step-sub" id="delete-order-sub">This cannot be undone.</p>
      <div class="confirm-warning">
        ⚠️ This permanently removes the order and its payment record.
      </div>
      <form id="deleteOrderForm" class="settings-form" style="margin-top:20px">
        <?= csrf_field() ?>
        <input type="hidden" name="order_id" id="delete-order-id">
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-danger">Delete order</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  document.querySelectorAll('[data-close-modal]').forEach(el => {
    el.addEventListener('click', () => el.closest('.modal').hidden = true);
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
      document.querySelectorAll('.modal:not([hidden])').forEach(m => m.hidden = true);
  });

  document.querySelectorAll('[data-delete-order]').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('delete-order-id').value = btn.dataset.deleteOrder;
      document.getElementById('delete-order-sub').textContent =
        `Order ${btn.dataset.deleteCode} will be permanently removed.`;
      document.getElementById('deleteOrderModal').hidden = false;
    });
  });

  document.getElementById('deleteOrderForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const btn  = e.target.querySelector('button[type=submit]');
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Deleting…';
    try {
      const fd = new FormData(e.target);
      fd.append('action', 'delete');
      const r = await fetch('order_action.php', { method:'POST', body: fd });
      const j = await r.json();
      if (j.ok) location.reload();
      else { alert(j.error || 'Failed'); btn.disabled = false; btn.textContent = orig; }
    } catch {
      alert('Network error');
      btn.disabled = false; btn.textContent = orig;
    }
  });
})();
</script>

<?php require __DIR__ . '/_layout_end.php'; ?>