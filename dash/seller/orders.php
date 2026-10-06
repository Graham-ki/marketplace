<?php
require_once __DIR__ . '/../_helpers.php';

$u = current_user();

$filter = $_GET['status'] ?? '';
if (!in_array($filter, ['', 'pending','confirmed','shipped','delivered','cancelled'], true)) {
    $filter = '';
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

/* ─── KPIs ─── */
$kpiStmt = db()->prepare("
    SELECT
        COUNT(*)                                                                   AS total_orders,
        COALESCE(SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END), 0)           AS pending_count,
        COALESCE(SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END), 0)           AS confirmed_count,
        COALESCE(SUM(CASE WHEN status='shipped'   THEN 1 ELSE 0 END), 0)           AS shipped_count,
        COALESCE(SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END), 0)           AS delivered_count,
        COALESCE(SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END), 0)           AS cancelled_count,
        COALESCE(SUM(CASE WHEN status!='cancelled' THEN total_amount ELSE 0 END), 0) AS revenue
    FROM orders
    WHERE seller_id = ?
");
$kpiStmt->execute([$u['id']]);
$kpi = $kpiStmt->fetch();

$totalOrders    = (int)$kpi['total_orders'];
$pendingCount   = (int)$kpi['pending_count'];
$confirmedCount = (int)$kpi['confirmed_count'];
$shippedCount   = (int)$kpi['shipped_count'];
$deliveredCount = (int)$kpi['delivered_count'];
$cancelledCount = (int)$kpi['cancelled_count'];
$revenue        = (float)$kpi['revenue'];

/* ─── Count for current filter ─── */
$countSql  = "SELECT COUNT(*) FROM orders WHERE seller_id = ?";
$countArgs = [$u['id']];
if ($filter) { $countSql .= " AND status = ?"; $countArgs[] = $filter; }
$countStmt = db()->prepare($countSql);
$countStmt->execute($countArgs);
$filteredTotal = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

/* ─── Fetch page ─── */
$sql = "
    SELECT o.*, p.title AS product_title, p.cover_image,
           b.full_name AS buyer_name, b.email AS buyer_email,
           pay.status AS payment_status
    FROM orders o
    JOIN products p ON p.id = o.product_id
    JOIN users b   ON b.id = o.buyer_id
    LEFT JOIN payments pay ON pay.order_id = o.id
    WHERE o.seller_id = ?";
$args = [$u['id']];
if ($filter) { $sql .= " AND o.status = ?"; $args[] = $filter; }
$sql .= " ORDER BY o.created_at DESC LIMIT $perPage OFFSET $offset";

$stmt = db()->prepare($sql);
$stmt->execute($args);
$orders = $stmt->fetchAll();

function orders_url(array $overrides = []): string {
    $base = [
        'tab'    => 'orders',
        'status' => $_GET['status'] ?? '',
        'page'   => $_GET['page']   ?? '',
    ];
    $params = array_filter(array_merge($base, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'dashboard.php?' . http_build_query($params);
}

$actions = '
  <div class="btn-group">
    <button type="button" class="btn btn-ghost" id="downloadSelected" disabled>
      ⬇ Download Statements
    </button>
  </div>
';

dash_header(
    'Orders',
    $totalOrders . ' order' . ($totalOrders === 1 ? '' : 's') . ' · '
        . money($revenue) . ' lifetime revenue',
    $actions
);
?>

<div class="kpi-grid">
  <div class="kpi-card gradient decorated">
    <div class="kpi-icon">💰</div>
    <span class="kpi-label">Lifetime revenue</span>
    <span class="kpi-value"><?= money($revenue) ?></span>
    <span class="kpi-delta"><?= $totalOrders ?> orders</span>
  </div>
  <div class="kpi-card <?= $pendingCount > 0 ? 'warn' : 'neutral' ?>">
    <div class="kpi-icon">⏳</div>
    <span class="kpi-label">Pending</span>
    <span class="kpi-value"><?= $pendingCount ?></span>
    <span class="kpi-delta"><?= $pendingCount > 0 ? 'Awaiting confirmation' : 'All clear' ?></span>
  </div>
  <div class="kpi-card info">
    <div class="kpi-icon">📦</div>
    <span class="kpi-label">In progress</span>
    <span class="kpi-value"><?= $confirmedCount + $shippedCount ?></span>
    <span class="kpi-delta"><?= $confirmedCount ?> confirmed · <?= $shippedCount ?> shipped</span>
  </div>
  <div class="kpi-card success">
    <div class="kpi-icon">✅</div>
    <span class="kpi-label">Delivered</span>
    <span class="kpi-value"><?= $deliveredCount ?></span>
    <span class="kpi-delta"><?= $cancelledCount ?> cancelled</span>
  </div>
</div>

<div class="filter-row">
  <?php foreach ([''=>'All','pending'=>'Pending','confirmed'=>'Confirmed','shipped'=>'Shipped','delivered'=>'Delivered','cancelled'=>'Cancelled'] as $k=>$lbl): ?>
    <a href="<?= e(orders_url(['status' => $k, 'page' => ''])) ?>"
       class="chip <?= $filter === $k ? 'active' : '' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$orders): ?>
  <div class="empty-state small"><p>No orders in this view.</p></div>
<?php else: ?>

  <div class="list-toolbar">
    <div class="search-mini">
      <span class="search-mini-icon">🔍</span>
      <input type="search" id="orderSearch"
             placeholder="Search by order code, buyer, or item…"
             autocomplete="off">
    </div>
    <span class="list-count" id="orderCount">
      Showing <?= count($orders) ?> of <?= $filteredTotal ?> order<?= $filteredTotal === 1 ? '' : 's' ?>
    </span>
  </div>

  <div class="table-scroll">
    <table class="data-table data-table-wide" id="ordersTable">
      <thead>
        <tr>
          <th style="width:36px">
            <input type="checkbox" id="selectAll" aria-label="Select all">
          </th>
          <th>Order</th>
          <th>Item</th>
          <th>Buyer</th>
          <th class="num">Subtotal</th>
          <th class="num">Discount</th>
          <th class="num">Tax</th>
          <th class="num">Total</th>
          <th>Status</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($orders as $o):
        $isPaid = ($o['payment_status'] === 'completed');
      ?>
        <tr data-order-row="<?= (int)$o['id'] ?>">
          <td>
            <input type="checkbox"
                   class="row-select"
                   data-order-id="<?= (int)$o['id'] ?>"
                   aria-label="Select order <?= e($o['order_code']) ?>">
          </td>
          <td><code><?= e($o['order_code']) ?></code></td>
          <td>
            <div class="cell-product">
              <div class="cell-thumb" style="background-image:url('<?= e($o['cover_image'] ?: '') ?>')"></div>
              <div><strong><?= e($o['product_title']) ?></strong><small>× <?= (int)$o['quantity'] ?></small></div>
            </div>
          </td>
          <td>
            <strong><?= e($o['buyer_name']) ?></strong><br>
            <small><?= e($o['buyer_email']) ?></small>
          </td>
          <td class="num">UGX <?= number_format((float)$o['subtotal'], 2) ?></td>
          <td class="num">
            <?php if ((float)$o['discount_amount'] > 0): ?>
              <span class="text-discount">− <?= number_format((float)$o['discount_amount'], 2) ?></span>
              <small>(<?= number_format((float)$o['discount_percent'], 2) ?>%)</small>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="num">
            <?php if ((float)$o['tax_amount'] > 0): ?>
              <span class="text-tax">+ <?= number_format((float)$o['tax_amount'], 2) ?></span>
              <small>(<?= number_format((float)$o['tax_percent'], 2) ?>%)</small>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="num"><strong>UGX <?= number_format((float)$o['total_amount'], 2) ?></strong></td>
          <td><?= status_pill($o['status']) ?></td>
          <td><small><?= e(date('M j, Y', strtotime($o['created_at']))) ?></small></td>
          <td class="row-actions">
            <a href="receipt.php?id=<?= (int)$o['id'] ?>" target="_blank"
               class="btn-link"
               title="<?= $isPaid ? 'Download receipt' : 'Download invoice' ?>">
              📄 <?= $isPaid ? 'Receipt' : 'Invoice' ?>
            </a>

            <form method="post" action="order_status.php" style="display:inline">
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
                    data-delete-code="<?= e($o['order_code']) ?>"
                    data-delete-status="<?= e($o['status']) ?>">Delete</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="empty-state small" id="noResults" hidden>
    <p>No orders match your search on this page.</p>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Pagination">
      <?php if ($page > 1): ?>
        <a href="<?= e(orders_url(['page' => $page - 1])) ?>" class="page-btn">‹ Prev</a>
      <?php else: ?>
        <span class="page-btn disabled">‹ Prev</span>
      <?php endif; ?>

      <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="<?= e(orders_url(['page' => $i])) ?>"
           class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>

      <?php if ($page < $totalPages): ?>
        <a href="<?= e(orders_url(['page' => $page + 1])) ?>" class="page-btn">Next ›</a>
      <?php else: ?>
        <span class="page-btn disabled">Next ›</span>
      <?php endif; ?>
    </nav>
  <?php endif; ?>

<?php endif; ?>


<!-- ═══ DELETE ORDER MODAL ═══ -->
<div class="modal" id="deleteOrderModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>
    <div class="confirm-hero">
      <div class="confirm-icon">🗑️</div>
      <h2>Delete order?</h2>
      <p class="step-sub" id="delete-order-sub">This cannot be undone.</p>
      <div class="confirm-warning" id="delete-order-warning" hidden>
        ⚠️ This order is <strong>not cancelled</strong>. Deleting it removes the
        record and any linked payment. Consider marking it
        <strong>cancelled</strong> instead to preserve history.
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
  /* ═══ Live search ═══ */
  const searchEl = document.getElementById('orderSearch');
  const tableEl  = document.getElementById('ordersTable');
  const countEl  = document.getElementById('orderCount');
  const noResEl  = document.getElementById('noResults');

  if (searchEl && tableEl) {
    const rows          = [...tableEl.querySelectorAll('tbody tr')];
    const onPageTotal   = rows.length;
    const filteredTotal = <?= (int)$filteredTotal ?>;

    searchEl.addEventListener('input', () => {
      const q = searchEl.value.trim().toLowerCase();
      let shown = 0;
      rows.forEach(r => {
        const hit = !q || r.textContent.toLowerCase().includes(q);
        r.hidden = !hit;
        if (hit) shown++;
      });
      if (countEl) {
        countEl.textContent = q
          ? `${shown} of ${onPageTotal} on this page (${filteredTotal} total)`
          : `Showing ${onPageTotal} of ${filteredTotal} order${filteredTotal === 1 ? '' : 's'}`;
      }
      if (noResEl) noResEl.hidden = shown > 0;
    });
  }

  /* ═══ Modal helpers ═══ */
  document.querySelectorAll('[data-close-modal]').forEach(el => {
    el.addEventListener('click', () => el.closest('.modal').hidden = true);
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
      document.querySelectorAll('.modal:not([hidden])').forEach(m => m.hidden = true);
  });

  /* ═══ Delete order ═══ */
  document.querySelectorAll('[data-delete-order]').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('delete-order-id').value = btn.dataset.deleteOrder;
      document.getElementById('delete-order-sub').textContent =
        `Order ${btn.dataset.deleteCode} will be permanently removed along with its payment records.`;
      document.getElementById('delete-order-warning').hidden = btn.dataset.deleteStatus === 'cancelled';
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
      const r = await fetch('order_delete.php', { method:'POST', body: fd });
      const j = await r.json();
      if (j.ok) {
        document.querySelector(`tr[data-order-row="${document.getElementById('delete-order-id').value}"]`)?.remove();
        document.getElementById('deleteOrderModal').hidden = true;
        if (!document.querySelector('#ordersTable tbody tr:not([hidden])')) {
          location.reload();
        }
      } else {
        alert(j.error || 'Failed'); btn.disabled = false; btn.textContent = orig;
      }
    } catch {
      alert('Network error');
      btn.disabled = false; btn.textContent = orig;
    }
  });

  /* ═══ Bulk download ═══ */
  const selectAll   = document.getElementById('selectAll');
  const downloadBtn = document.getElementById('downloadSelected');
  const checkboxes  = [...document.querySelectorAll('.row-select')];

  function updateDownloadButton() {
    const checked = checkboxes.filter(r => r.checked);
    downloadBtn.disabled = checked.length === 0;
    downloadBtn.textContent = checked.length === 0
      ? '⬇ Download Statements'
      : `⬇ Download ${checked.length} document${checked.length === 1 ? '' : 's'}`;
  }

  selectAll?.addEventListener('change', () => {
    checkboxes.forEach(r => r.checked = selectAll.checked);
    updateDownloadButton();
  });
  checkboxes.forEach(r => r.addEventListener('change', updateDownloadButton));
  updateDownloadButton();

  downloadBtn?.addEventListener('click', () => {
    const checked = checkboxes.filter(r => r.checked);
    if (!checked.length) return;

    const ids = checked.map(r => r.dataset.orderId).join(',');

    if (checked.length === 1) {
      window.open(`receipt.php?id=${ids}`, '_blank');
    } else {
      window.open(`receipt_bulk.php?ids=${ids}`, '_blank');
    }
  });
})();
</script>