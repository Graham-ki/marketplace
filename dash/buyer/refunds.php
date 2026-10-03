<?php
require_once __DIR__ . '/../_helpers.php';

$u = current_user();

// ═══════════════════════════════════════════════════
// FILTERS + PAGINATION
// ═══════════════════════════════════════════════════
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['', 'requested','approved','rejected','refunded'], true)) {
    $statusFilter = '';
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

// ─── KPIs (whole set) ───
$kpiStmt = db()->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN r.status='requested' THEN r.amount ELSE 0 END), 0) AS requested_total,
        COALESCE(SUM(CASE WHEN r.status='approved'  THEN r.amount ELSE 0 END), 0) AS approved_total,
        COALESCE(SUM(CASE WHEN r.status='refunded'  THEN r.amount ELSE 0 END), 0) AS refunded_total,
        COALESCE(SUM(CASE WHEN r.status='rejected'  THEN r.amount ELSE 0 END), 0) AS rejected_total,
        COUNT(*)                                                                   AS total_count,
        COALESCE(SUM(CASE WHEN r.status='requested' THEN 1 ELSE 0 END), 0)         AS requested_count,
        COALESCE(SUM(CASE WHEN r.status='refunded'  THEN 1 ELSE 0 END), 0)         AS refunded_count
    FROM refunds r
    WHERE r.requested_by = ?
");
$kpiStmt->execute([$u['id']]);
$kpi = $kpiStmt->fetch();

$requestedTotal = (float)$kpi['requested_total'];
$approvedTotal  = (float)$kpi['approved_total'];
$refundedTotal  = (float)$kpi['refunded_total'];
$rejectedTotal  = (float)$kpi['rejected_total'];
$totalCount     = (int)$kpi['total_count'];
$requestedCount = (int)$kpi['requested_count'];
$refundedCount  = (int)$kpi['refunded_count'];

// ─── Count for current filter ───
$countSql  = "SELECT COUNT(*) FROM refunds WHERE requested_by = ?";
$countArgs = [$u['id']];
if ($statusFilter) { $countSql .= " AND status = ?"; $countArgs[] = $statusFilter; }

$countStmt = db()->prepare($countSql);
$countStmt->execute($countArgs);
$filteredTotal = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// ─── Fetch current page ───
$sql = "
    SELECT r.*, o.order_code,
           s.full_name AS seller_name,
           COALESCE(NULLIF(sp.business_name,''), s.full_name) AS seller_shop
    FROM refunds r
    JOIN orders o ON o.id = r.order_id
    JOIN users  s ON s.id = r.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = s.id
    WHERE r.requested_by = ?
";
$args = [$u['id']];
if ($statusFilter) { $sql .= " AND r.status = ?"; $args[] = $statusFilter; }
$sql .= " ORDER BY r.created_at DESC LIMIT $perPage OFFSET $offset";

$stmt = db()->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

// ─── URL helper ───
function buyer_refunds_url(array $overrides = []): string {
    $base = [
        'tab'    => 'refunds',
        'status' => $_GET['status'] ?? '',
        'page'   => $_GET['page']   ?? '',
    ];
    $params = array_filter(array_merge($base, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'dashboard.php?' . http_build_query($params);
}

$actions = '<button class="btn btn-ghost" data-open-modal="exportModal">⬇ Download</button>';

dash_header(
    'My refunds',
    $totalCount . ' request' . ($totalCount === 1 ? '' : 's') . ' · '
        . money($refundedTotal) . ' refunded lifetime',
    $actions
);
?>

<!-- ═══════════════════════════════════════════════════
     KPI CARDS
     ═══════════════════════════════════════════════════ -->
<div class="kpi-grid">
  <div class="kpi-card gradient decorated">
    <div class="kpi-icon">💸</div>
    <span class="kpi-label">Total refunded</span>
    <span class="kpi-value"><?= money($refundedTotal) ?></span>
    <span class="kpi-delta"><?= $refundedCount ?> completed refund<?= $refundedCount === 1 ? '' : 's' ?></span>
  </div>

  <div class="kpi-card <?= $requestedTotal > 0 ? 'warn' : 'neutral' ?>">
    <div class="kpi-icon">⏳</div>
    <span class="kpi-label">In review</span>
    <span class="kpi-value"><?= money($requestedTotal) ?></span>
    <span class="kpi-delta">
      <?= $requestedCount ?> pending seller decision
    </span>
  </div>

  <div class="kpi-card <?= $approvedTotal > 0 ? 'info' : 'neutral' ?>">
    <div class="kpi-icon">✅</div>
    <span class="kpi-label">Approved</span>
    <span class="kpi-value"><?= money($approvedTotal) ?></span>
    <span class="kpi-delta">Awaiting payout</span>
  </div>

  <div class="kpi-card <?= $rejectedTotal > 0 ? 'danger' : 'neutral' ?>">
    <div class="kpi-icon">❌</div>
    <span class="kpi-label">Rejected</span>
    <span class="kpi-value"><?= money($rejectedTotal) ?></span>
    <span class="kpi-delta">
      <?= $rejectedTotal > 0 ? 'Declined by seller' : 'None rejected' ?>
    </span>
  </div>
</div>


<!-- ═══════════ STATUS FILTER CHIPS ═══════════ -->
<div class="filter-row">
  <?php foreach (
    [
      ''          => 'All',
      'requested' => 'Requested',
      'approved'  => 'Approved',
      'rejected'  => 'Rejected',
      'refunded'  => 'Refunded',
    ] as $k => $lbl
  ): ?>
    <a href="<?= e(buyer_refunds_url(['status' => $k, 'page' => ''])) ?>"
       class="chip <?= $statusFilter === $k ? 'active' : '' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>


<?php if (!$rows): ?>

  <div class="empty-state">
    <div class="empty-icon">↩️</div>
    <h3>No refunds here</h3>
    <p>
      <?= $statusFilter
          ? 'No ' . e($statusFilter) . ' refund requests found.'
          : "You haven't requested any refunds yet." ?>
    </p>
    <a href="dashboard.php?tab=orders" class="btn btn-accent">View my orders →</a>
  </div>

<?php else: ?>

  <!-- ═══ SEARCH + COUNT ═══ -->
  <div class="list-toolbar">
    <div class="search-mini">
      <span class="search-mini-icon">🔍</span>
      <input type="search" id="buyerRefundsSearch"
             placeholder="Search by code, order, seller, reason…"
             autocomplete="off">
    </div>
    <span class="list-count" id="buyerRefundsCount">
      Showing <?= count($rows) ?> of <?= $filteredTotal ?> request<?= $filteredTotal === 1 ? '' : 's' ?>
    </span>
  </div>

  <!-- ═══ TABLE ═══ -->
  <div class="table-scroll">
    <table class="data-table data-table-wide" id="buyerRefundsTable">
      <thead>
        <tr>
          <th>Code</th>
          <th>Order</th>
          <th>Seller</th>
          <th class="num">Amount</th>
          <th>Reason</th>
          <th>Status</th>
          <th>Decided</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-refund-row="<?= (int)$r['id'] ?>">
          <td><code><?= e($r['refund_code']) ?></code></td>
          <td><code><?= e($r['order_code']) ?></code></td>
          <td><?= e($r['seller_shop']) ?></td>
          <td class="num"><strong><?= money((float)$r['amount']) ?></strong></td>
          <td>
            <small title="<?= e($r['reason'] ?? '') ?>">
              <?= e($r['reason']
                    ? mb_strimwidth($r['reason'], 0, 40, '…')
                    : '—') ?>
            </small>
          </td>
          <td><?= status_pill($r['status']) ?></td>
          <td>
            <small>
              <?= $r['decided_at']
                    ? e(date('M j, Y', strtotime($r['decided_at'])))
                    : '—' ?>
            </small>
          </td>
          <td><small><?= e(date('M j, Y', strtotime($r['created_at']))) ?></small></td>
          <td class="row-actions">
            <?php if ($r['status'] === 'requested'): ?>
              <button class="btn-link danger"
                      data-cancel-refund="<?= (int)$r['id'] ?>"
                      data-cancel-code="<?= e($r['refund_code']) ?>">Cancel request</button>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="empty-state small" id="noResults" hidden>
    <p>No refunds match your search on this page.</p>
  </div>

  <!-- ═══ PAGINATION ═══ -->
  <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Pagination">

      <?php if ($page > 1): ?>
        <a href="<?= e(buyer_refunds_url(['page' => $page - 1])) ?>" class="page-btn">‹ Prev</a>
      <?php else: ?>
        <span class="page-btn disabled">‹ Prev</span>
      <?php endif; ?>

      <?php
        $window = 2;
        $start  = max(1, $page - $window);
        $end    = min($totalPages, $page + $window);

        if ($start > 1) {
            echo '<a href="' . e(buyer_refunds_url(['page' => 1])) . '" class="page-btn">1</a>';
            if ($start > 2) echo '<span class="page-dots">…</span>';
        }

        for ($i = $start; $i <= $end; $i++):
      ?>
        <a href="<?= e(buyer_refunds_url(['page' => $i])) ?>"
           class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php
        endfor;

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<span class="page-dots">…</span>';
            echo '<a href="' . e(buyer_refunds_url(['page' => $totalPages])) . '" class="page-btn">' . $totalPages . '</a>';
        }
      ?>

      <?php if ($page < $totalPages): ?>
        <a href="<?= e(buyer_refunds_url(['page' => $page + 1])) ?>" class="page-btn">Next ›</a>
      <?php else: ?>
        <span class="page-btn disabled">Next ›</span>
      <?php endif; ?>

    </nav>
  <?php endif; ?>

<?php endif; ?>


<!-- ═══════════ CANCEL REFUND REQUEST MODAL ═══════════ -->
<div class="modal" id="cancelRefundModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>

    <div class="confirm-hero">
      <div class="confirm-icon">✖️</div>
      <h2>Cancel this request?</h2>
      <p class="step-sub" id="cancel-refund-sub">The seller will be notified.</p>

      <div class="confirm-warning">
        The refund request will be withdrawn. If you still need a refund later,
        you'll have to request it again.
      </div>

      <form id="cancelRefundForm" class="settings-form" style="margin-top:20px">
        <?= csrf_field() ?>
        <input type="hidden" name="refund_id" id="cancel-refund-id">

        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" data-close-modal>Keep request</button>
          <button type="submit" class="btn btn-danger">Cancel request</button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ═══════════ EXPORT MODAL ═══════════ -->
<div class="modal" id="exportModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>
    <h2>Download my refunds</h2>
    <p class="step-sub">Choose a format and a time range.</p>

    <form id="exportForm" method="GET" action="buyer_refunds_export.php" target="_blank">
      <label class="field"><span>Format</span>
        <select name="format">
          <option value="csv">CSV (Excel / Sheets)</option>
          <option value="pdf">PDF (print / save as PDF)</option>
        </select>
      </label>

      <label class="field"><span>Range</span>
        <select name="range" id="export-range">
          <option value="all">All time</option>
          <option value="today">Today</option>
          <option value="7">Last 7 days</option>
          <option value="30" selected>Last 30 days</option>
          <option value="90">Last 90 days</option>
          <option value="custom">Custom range…</option>
        </select>
      </label>

      <div class="two-col" id="export-custom" hidden>
        <label class="field"><span>From</span>
          <input type="date" name="from">
        </label>
        <label class="field"><span>To</span>
          <input type="date" name="to">
        </label>
      </div>

      <label class="field"><span>Status filter</span>
        <select name="status">
          <option value="">All statuses</option>
          <option value="requested">Requested</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="refunded">Refunded</option>
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
  /* ═══════════════════════════════════════════════
     MODAL OPEN/CLOSE
     ═══════════════════════════════════════════════ */
  document.querySelectorAll('[data-open-modal]').forEach(btn => {
    btn.addEventListener('click', () => {
      const m = document.getElementById(btn.dataset.openModal);
      if (m) m.hidden = false;
    });
  });
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
     CANCEL REFUND REQUEST
     ═══════════════════════════════════════════════ */
  document.querySelectorAll('[data-cancel-refund]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id   = btn.dataset.cancelRefund;
      const code = btn.dataset.cancelCode;
      document.getElementById('cancel-refund-id').value = id;
      document.getElementById('cancel-refund-sub').textContent =
        `Request ${code} will be withdrawn.`;
      document.getElementById('cancelRefundModal').hidden = false;
    });
  });

  document.getElementById('cancelRefundForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const btn  = e.target.querySelector('button[type=submit]');
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Cancelling…';

    try {
      const fd = new FormData(e.target);
      fd.append('action', 'cancel');
      const r = await fetch('refund_action.php', { method:'POST', body: fd });
      const j = await r.json();
      if (j.ok) {
        location.reload();
      } else {
        alert(j.error || 'Failed to cancel refund');
        btn.disabled = false; btn.textContent = orig;
      }
    } catch (_) {
      alert('Network error');
      btn.disabled = false; btn.textContent = orig;
    }
  });

  /* ═══════════════════════════════════════════════
     EXPORT — custom range toggle
     ═══════════════════════════════════════════════ */
  const rangeSel = document.getElementById('export-range');
  const customEl = document.getElementById('export-custom');
  rangeSel?.addEventListener('change', () => {
    customEl.hidden = rangeSel.value !== 'custom';
  });
  document.getElementById('exportForm')?.addEventListener('submit', () => {
    setTimeout(() => {
      const m = document.getElementById('exportModal');
      if (m) m.hidden = true;
    }, 400);
  });

  /* ═══════════════════════════════════════════════
     LIVE SEARCH
     ═══════════════════════════════════════════════ */
  const searchEl = document.getElementById('buyerRefundsSearch');
  const tableEl  = document.getElementById('buyerRefundsTable');
  const countEl  = document.getElementById('buyerRefundsCount');
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
          : `Showing ${onPageTotal} of ${filteredTotal} request${filteredTotal === 1 ? '' : 's'}`;
      }
      if (noResEl) noResEl.hidden = shown > 0;
    });
  }
})();
</script>