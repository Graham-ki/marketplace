<?php
require_once __DIR__ . '/../_helpers.php';

$u = current_user();

// ═══════════════════════════════════════════════════
// STATUS FILTER
// ═══════════════════════════════════════════════════
$filter = $_GET['status'] ?? '';
if (!in_array($filter, ['', 'pending','confirmed','shipped','delivered','cancelled'], true)) {
    $filter = '';
}

// ═══════════════════════════════════════════════════
// PAGINATION SETUP
// ═══════════════════════════════════════════════════
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

// ─── KPIs (whole set, not filtered) ───
$kpiStmt = db()->prepare("
    SELECT
        COUNT(*)                                                                      AS total_orders,
        COALESCE(SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END), 0)              AS pending_count,
        COALESCE(SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END), 0)              AS confirmed_count,
        COALESCE(SUM(CASE WHEN status='shipped'   THEN 1 ELSE 0 END), 0)              AS shipped_count,
        COALESCE(SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END), 0)              AS delivered_count,
        COALESCE(SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END), 0)              AS cancelled_count,
        COALESCE(SUM(CASE WHEN status!='cancelled' THEN total_amount ELSE 0 END), 0)  AS spent,
        COALESCE(SUM(CASE WHEN status!='cancelled'
                           AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')
                          THEN total_amount ELSE 0 END), 0)                           AS spent_this_month
    FROM orders
    WHERE buyer_id = ?
");
$kpiStmt->execute([$u['id']]);
$kpi = $kpiStmt->fetch();

$totalOrders      = (int)$kpi['total_orders'];
$pendingCount     = (int)$kpi['pending_count'];
$confirmedCount   = (int)$kpi['confirmed_count'];
$shippedCount     = (int)$kpi['shipped_count'];
$deliveredCount   = (int)$kpi['delivered_count'];
$cancelledCount   = (int)$kpi['cancelled_count'];
$spent            = (float)$kpi['spent'];
$spentThisMonth   = (float)$kpi['spent_this_month'];

// ─── Count for current filter (pagination math) ───
$countSql  = "SELECT COUNT(*) FROM orders WHERE buyer_id = ?";
$countArgs = [$u['id']];
if ($filter) { $countSql .= " AND status = ?"; $countArgs[] = $filter; }

$countStmt = db()->prepare($countSql);
$countStmt->execute($countArgs);
$filteredTotal = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// ─── Fetch current page ───
$sql = "
    SELECT o.*, p.title AS product_title, p.cover_image,
           s.full_name AS seller_name,
           COALESCE(NULLIF(sp.business_name,''), s.full_name) AS seller_shop,
           o.created_at
    FROM orders o
    JOIN products p ON p.id = o.product_id
    JOIN users s   ON s.id = o.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = s.id
    WHERE o.buyer_id = ?
";
$args = [$u['id']];
if ($filter) { $sql .= " AND o.status = ?"; $args[] = $filter; }
$sql .= " ORDER BY o.created_at DESC LIMIT $perPage OFFSET $offset";

$stmt = db()->prepare($sql);
$stmt->execute($args);
$orders = $stmt->fetchAll();

// ─── URL helper: preserve filter across pages ───
function buyer_orders_url(array $overrides = []): string {
    $base = [
        'tab'    => 'orders',
        'status' => $_GET['status'] ?? '',
        'page'   => $_GET['page']   ?? '',
    ];
    $params = array_filter(array_merge($base, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'dashboard.php?' . http_build_query($params);
}

dash_header(
    'My orders',
    $totalOrders . ' order' . ($totalOrders === 1 ? '' : 's') . ' · '
        . money($spent) . ' spent lifetime'
);
?>

<!-- ═══════════════════════════════════════════════════
     KPI CARDS
     ═══════════════════════════════════════════════════ -->
<div class="kpi-grid">
  <div class="kpi-card gradient decorated">
    <div class="kpi-icon">💰</div>
    <span class="kpi-label">Total spent</span>
    <span class="kpi-value"><?= money($spent) ?></span>
    <span class="kpi-delta"><?= money($spentThisMonth) ?> this month</span>
  </div>

  <div class="kpi-card <?= $pendingCount > 0 ? 'warn' : 'neutral' ?>">
    <div class="kpi-icon">⏳</div>
    <span class="kpi-label">Pending</span>
    <span class="kpi-value"><?= $pendingCount ?></span>
    <span class="kpi-delta">
      <?= $pendingCount > 0 ? 'Awaiting confirmation' : 'All clear' ?>
    </span>
  </div>

  <div class="kpi-card info">
    <div class="kpi-icon">📦</div>
    <span class="kpi-label">In transit</span>
    <span class="kpi-value"><?= $confirmedCount + $shippedCount ?></span>
    <span class="kpi-delta">
      <?= $confirmedCount ?> confirmed · <?= $shippedCount ?> shipped
    </span>
  </div>

  <div class="kpi-card success">
    <div class="kpi-icon">✅</div>
    <span class="kpi-label">Delivered</span>
    <span class="kpi-value"><?= $deliveredCount ?></span>
    <span class="kpi-delta"><?= $cancelledCount ?> cancelled</span>
  </div>
</div>


<!-- ═══════════ STATUS FILTER CHIPS ═══════════ -->
<div class="filter-row">
  <?php foreach ([''=>'All','pending'=>'Pending','confirmed'=>'Confirmed','shipped'=>'Shipped','delivered'=>'Delivered','cancelled'=>'Cancelled'] as $k=>$lbl): ?>
    <a href="<?= e(buyer_orders_url(['status' => $k, 'page' => ''])) ?>"
       class="chip <?= $filter === $k ? 'active' : '' ?>">
      <?= $lbl ?>
    </a>
  <?php endforeach; ?>
</div>


<?php if (!$orders): ?>

  <div class="empty-state">
    <div class="empty-icon">🛍️</div>
    <h3>No orders here</h3>
    <p>
      <?= $filter
          ? 'No ' . e($filter) . ' orders yet.'
          : "You haven't placed any orders yet." ?>
    </p>
    <a href="index.php" class="btn btn-accent">Browse items →</a>
  </div>

<?php else: ?>

  <!-- ═══ SEARCH + COUNT ═══ -->
  <div class="list-toolbar">
    <div class="search-mini">
      <span class="search-mini-icon">🔍</span>
      <input type="search" id="buyerOrderSearch"
             placeholder="Search by order code, item, or seller…"
             autocomplete="off">
    </div>
    <span class="list-count" id="buyerOrderCount">
      Showing <?= count($orders) ?> of <?= $filteredTotal ?> order<?= $filteredTotal === 1 ? '' : 's' ?>
    </span>
  </div>

  <!-- ═══ TABLE ═══ -->
  <div class="table-scroll">
    <table class="data-table data-table-wide" id="buyerOrdersTable">
      <thead>
        <tr>
          <th>Order</th>
          <th>Item</th>
          <th>Seller</th>
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
      <?php foreach ($orders as $o): ?>
        <?php
          $canCancel = in_array($o['status'], ['pending','confirmed'], true);
          $canRefund = in_array($o['status'], ['pending','confirmed','shipped'], true);
          $canDelete = $o['status'] === 'cancelled';
        ?>
        <tr data-order-row="<?= (int)$o['id'] ?>">
          <td><code><?= e($o['order_code']) ?></code></td>
          <td>
            <div class="cell-product">
              <div class="cell-thumb" style="background-image:url('<?= e($o['cover_image'] ?: '') ?>')"></div>
              <div>
                <strong><?= e($o['product_title']) ?></strong>
                <small>× <?= (int)$o['quantity'] ?></small>
              </div>
            </div>
          </td>
          <td><?= e($o['seller_shop']) ?></td>
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
            <?php if ($canCancel): ?>
              <button class="btn-link danger"
                      data-cancel-order="<?= (int)$o['id'] ?>"
                      data-cancel-code="<?= e($o['order_code']) ?>">Cancel</button>
            <?php endif; ?>

            <?php if ($canRefund): ?>
              <form method="post" action="refund_request.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                <button class="btn-link">Request refund</button>
              </form>
            <?php endif; ?>

            <?php if ($canDelete): ?>
              <button class="btn-link danger"
                      data-delete-order="<?= (int)$o['id'] ?>"
                      data-delete-code="<?= e($o['order_code']) ?>">Delete</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="empty-state small" id="noResults" hidden>
    <p>No orders match your search on this page.</p>
  </div>

  <!-- ═══ PAGINATION ═══ -->
  <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Pagination">

      <?php if ($page > 1): ?>
        <a href="<?= e(buyer_orders_url(['page' => $page - 1])) ?>" class="page-btn">‹ Prev</a>
      <?php else: ?>
        <span class="page-btn disabled">‹ Prev</span>
      <?php endif; ?>

      <?php
        $window = 2;
        $start  = max(1, $page - $window);
        $end    = min($totalPages, $page + $window);

        if ($start > 1) {
            echo '<a href="' . e(buyer_orders_url(['page' => 1])) . '" class="page-btn">1</a>';
            if ($start > 2) echo '<span class="page-dots">…</span>';
        }

        for ($i = $start; $i <= $end; $i++):
      ?>
        <a href="<?= e(buyer_orders_url(['page' => $i])) ?>"
           class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php
        endfor;

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<span class="page-dots">…</span>';
            echo '<a href="' . e(buyer_orders_url(['page' => $totalPages])) . '" class="page-btn">' . $totalPages . '</a>';
        }
      ?>

      <?php if ($page < $totalPages): ?>
        <a href="<?= e(buyer_orders_url(['page' => $page + 1])) ?>" class="page-btn">Next ›</a>
      <?php else: ?>
        <span class="page-btn disabled">Next ›</span>
      <?php endif; ?>

    </nav>
  <?php endif; ?>

<?php endif; ?>


<!-- ═══════════ CANCEL ORDER MODAL ═══════════ -->
<div class="modal" id="cancelOrderModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>

    <div class="confirm-hero">
      <div class="confirm-icon">❌</div>
      <h2>Cancel this order?</h2>
      <p class="step-sub" id="cancel-order-sub">The seller will be notified.</p>

      <div class="confirm-warning">
        Cancelling stops the order from being fulfilled. It remains in your
        history and the seller can still issue a refund if a payment was made.
      </div>

      <form id="cancelOrderForm" class="settings-form" style="margin-top:20px">
        <?= csrf_field() ?>
        <input type="hidden" name="order_id" id="cancel-order-id">

        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" data-close-modal>Keep order</button>
          <button type="submit" class="btn btn-danger">Cancel order</button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ═══════════ DELETE ORDER MODAL ═══════════ -->
<div class="modal" id="deleteOrderModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>

    <div class="confirm-hero">
      <div class="confirm-icon">🗑️</div>
      <h2>Delete this order?</h2>
      <p class="step-sub" id="delete-order-sub">This cannot be undone.</p>

      <div class="confirm-warning">
        The order will be permanently removed from your history. Use this only
        for cancelled orders you no longer want to see.
      </div>

      <form id="deleteOrderForm" class="settings-form" style="margin-top:20px">
        <?= csrf_field() ?>
        <input type="hidden" name="order_id" id="delete-order-id">

        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" data-close-modal>Keep</button>
          <button type="submit" class="btn btn-danger">Delete order</button>
        </div>
      </form>
    </div>
  </div>
</div>


<script>
(() => {
  /* ═══════════════════════════════════════════════
     LIVE SEARCH
     ═══════════════════════════════════════════════ */
  const searchEl = document.getElementById('buyerOrderSearch');
  const tableEl  = document.getElementById('buyerOrdersTable');
  const countEl  = document.getElementById('buyerOrderCount');
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

  /* ═══════════════════════════════════════════════
     MODAL OPEN/CLOSE HELPERS
     ═══════════════════════════════════════════════ */
  document.querySelectorAll('[data-close-modal]').forEach(el => {
    el.addEventListener('click', () => {
      const m = el.closest('.modal');
      if (m) m.hidden = true;
    });
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
      document.querySelectorAll('.modal:not([hidden])').forEach(m => m.hidden = true);
  });

  /* ═══════════════════════════════════════════════
     CANCEL ORDER
     ═══════════════════════════════════════════════ */
  document.querySelectorAll('[data-cancel-order]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id   = btn.dataset.cancelOrder;
      const code = btn.dataset.cancelCode;
      document.getElementById('cancel-order-id').value = id;
      document.getElementById('cancel-order-sub').textContent =
        `Order ${code} will be marked as cancelled.`;
      document.getElementById('cancelOrderModal').hidden = false;
    });
  });

  document.getElementById('cancelOrderForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const btn  = e.target.querySelector('button[type=submit]');
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Cancelling…';

    try {
      const fd = new FormData(e.target);
      fd.append('action', 'cancel');
      const r = await fetch('buyer_order_action.php', { method:'POST', body: fd });
      const j = await r.json();
      if (j.ok) location.reload();
      else {
        alert(j.error || 'Failed to cancel order');
        btn.disabled = false; btn.textContent = orig;
      }
    } catch (_) {
      alert('Network error');
      btn.disabled = false; btn.textContent = orig;
    }
  });

  /* ═══════════════════════════════════════════════
     DELETE ORDER
     ═══════════════════════════════════════════════ */
  document.querySelectorAll('[data-delete-order]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id   = btn.dataset.deleteOrder;
      const code = btn.dataset.deleteCode;
      document.getElementById('delete-order-id').value = id;
      document.getElementById('delete-order-sub').textContent =
        `Order ${code} will be permanently removed.`;
      document.getElementById('deleteOrderModal').hidden = false;
    });
  });

  document.getElementById('deleteOrderForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const btn  = e.target.querySelector('button[type=submit]');
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Deleting…';

    try {
      const fd = new FormData(e.target);
      fd.append('action', 'delete');
      const r = await fetch('buyer_order_action.php', { method:'POST', body: fd });
      const j = await r.json();
      if (j.ok) {
        const row = document.querySelector(`tr[data-order-row="${document.getElementById('delete-order-id').value}"]`);
        row?.remove();
        document.getElementById('deleteOrderModal').hidden = true;

        // If we removed the last visible row, reload to refresh KPIs and pagination
        const remaining = document.querySelectorAll('#buyerOrdersTable tbody tr:not([hidden])').length;
        if (remaining === 0) location.reload();
      } else {
        alert(j.error || 'Failed to delete order');
        btn.disabled = false; btn.textContent = orig;
      }
    } catch (_) {
      alert('Network error');
      btn.disabled = false; btn.textContent = orig;
    }
  });
})();
</script>