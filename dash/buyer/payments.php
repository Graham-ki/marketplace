<?php
require_once __DIR__ . '/../_helpers.php';

$u = current_user();

// ═══════════════════════════════════════════════════
// FILTERS + PAGINATION
// ═══════════════════════════════════════════════════
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['', 'pending','completed','failed'], true)) {
    $statusFilter = '';
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

// ─── KPIs (whole set, not filtered) ───
$kpiStmt = db()->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN pay.status='completed' THEN pay.amount ELSE 0 END), 0) AS paid_total,
        COALESCE(SUM(CASE WHEN pay.status='pending'   THEN pay.amount ELSE 0 END), 0) AS pending_total,
        COALESCE(SUM(CASE WHEN pay.status='failed'    THEN pay.amount ELSE 0 END), 0) AS failed_total,
        COUNT(*)                                                                       AS total_count,
        COALESCE(SUM(CASE WHEN pay.status='completed'
                           AND pay.created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')
                          THEN pay.amount ELSE 0 END), 0)                              AS paid_this_month,
        COALESCE(SUM(CASE WHEN pay.status='completed'
                           AND DATE(pay.created_at) = CURDATE()
                          THEN pay.amount ELSE 0 END), 0)                              AS paid_today
    FROM payments pay
    JOIN orders o ON o.id = pay.order_id
    WHERE pay.user_id = ?
");
$kpiStmt->execute([$u['id']]);
$kpi = $kpiStmt->fetch();

$paidTotal     = (float)$kpi['paid_total'];
$pendingTotal  = (float)$kpi['pending_total'];
$failedTotal   = (float)$kpi['failed_total'];
$totalCount    = (int)$kpi['total_count'];
$paidThisMonth = (float)$kpi['paid_this_month'];
$paidToday     = (float)$kpi['paid_today'];

// ─── Count for current filter (pagination math) ───
$countSql  = "
    SELECT COUNT(*)
    FROM payments pay
    JOIN orders o ON o.id = pay.order_id
    WHERE pay.user_id = ?
";
$countArgs = [$u['id']];
if ($statusFilter) { $countSql .= " AND pay.status = ?"; $countArgs[] = $statusFilter; }

$countStmt = db()->prepare($countSql);
$countStmt->execute($countArgs);
$filteredTotal = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// ─── Fetch current page ───
$sql = "
    SELECT pay.*, o.order_code, s.full_name AS seller_name,
           COALESCE(NULLIF(sp.business_name,''), s.full_name) AS seller_shop
    FROM payments pay
    JOIN orders o ON o.id = pay.order_id
    JOIN users  s ON s.id = o.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = s.id
    WHERE pay.user_id = ?
";
$args = [$u['id']];
if ($statusFilter) { $sql .= " AND pay.status = ?"; $args[] = $statusFilter; }
$sql .= " ORDER BY pay.created_at DESC LIMIT $perPage OFFSET $offset";

$stmt = db()->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

// ─── URL helper: preserve filter across pages ───
function buyer_payments_url(array $overrides = []): string {
    $base = [
        'tab'    => 'payments',
        'status' => $_GET['status'] ?? '',
        'page'   => $_GET['page']   ?? '',
    ];
    $params = array_filter(array_merge($base, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'dashboard.php?' . http_build_query($params);
}

$actions = '<button class="btn btn-ghost" data-open-modal="exportModal">⬇ Download</button>';

dash_header(
    'My payments',
    $totalCount . ' transaction' . ($totalCount === 1 ? '' : 's') . ' · '
        . money($paidTotal) . ' paid lifetime',
    $actions
);
?>

<!-- ═══════════════════════════════════════════════════
     KPI CARDS
     ═══════════════════════════════════════════════════ -->
<div class="kpi-grid">
  <div class="kpi-card gradient decorated">
    <div class="kpi-icon">💳</div>
    <span class="kpi-label">Total paid</span>
    <span class="kpi-value"><?= money($paidTotal) ?></span>
    <span class="kpi-delta">
      <?= money($paidToday) ?> today · <?= money($paidThisMonth) ?> this month
    </span>
  </div>

  <div class="kpi-card <?= $pendingTotal > 0 ? 'warn' : 'neutral' ?>">
    <div class="kpi-icon">⏳</div>
    <span class="kpi-label">Pending</span>
    <span class="kpi-value"><?= money($pendingTotal) ?></span>
    <span class="kpi-delta">
      <?= $pendingTotal > 0 ? 'Awaiting confirmation' : 'All clear' ?>
    </span>
  </div>

  <div class="kpi-card <?= $failedTotal > 0 ? 'danger' : 'neutral' ?>">
    <div class="kpi-icon">❌</div>
    <span class="kpi-label">Failed</span>
    <span class="kpi-value"><?= money($failedTotal) ?></span>
    <span class="kpi-delta">
      <?= $failedTotal > 0 ? 'Contact seller' : 'No failed payments' ?>
    </span>
  </div>

  <div class="kpi-card info decorated">
    <div class="kpi-icon">📊</div>
    <span class="kpi-label">Transactions</span>
    <span class="kpi-value"><?= $totalCount ?></span>
    <span class="kpi-delta">All time</span>
  </div>
</div>


<!-- ═══════════ STATUS FILTER CHIPS ═══════════ -->
<div class="filter-row">
  <?php foreach ([''=>'All','completed'=>'Paid','pending'=>'Pending','failed'=>'Failed'] as $k=>$lbl): ?>
    <a href="<?= e(buyer_payments_url(['status' => $k, 'page' => ''])) ?>"
       class="chip <?= $statusFilter === $k ? 'active' : '' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>


<?php if (!$rows): ?>

  <div class="empty-state">
    <div class="empty-icon">💳</div>
    <h3>No payments here</h3>
    <p>
      <?= $statusFilter
          ? 'No ' . e($statusFilter) . ' payments found.'
          : "You haven't made any payments yet." ?>
    </p>
    <a href="index.php" class="btn btn-accent">Browse items →</a>
  </div>

<?php else: ?>

  <!-- ═══ SEARCH + COUNT ═══ -->
  <div class="list-toolbar">
    <div class="search-mini">
      <span class="search-mini-icon">🔍</span>
      <input type="search" id="buyerPaymentsSearch"
             placeholder="Search by code, order, seller, reference…"
             autocomplete="off">
    </div>
    <span class="list-count" id="buyerPaymentsCount">
      Showing <?= count($rows) ?> of <?= $filteredTotal ?> payment<?= $filteredTotal === 1 ? '' : 's' ?>
    </span>
  </div>

  <!-- ═══ TABLE ═══ -->
  <div class="table-scroll">
    <table class="data-table data-table-wide" id="buyerPaymentsTable">
      <thead>
        <tr>
          <th>Code</th>
          <th>Order</th>
          <th>Seller</th>
          <th class="num">Amount</th>
          <th>Method</th>
          <th>Reference</th>
          <th>Status</th>
          <th>Paid at</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><code><?= e($r['payment_code']) ?></code></td>
          <td><code><?= e($r['order_code']) ?></code></td>
          <td><?= e($r['seller_shop']) ?></td>
          <td class="num"><strong><?= money((float)$r['amount']) ?></strong></td>
          <td><?= e(ucfirst(str_replace('_',' ', $r['method']))) ?></td>
          <td><small><?= e($r['reference'] ?: '—') ?></small></td>
          <td><?= status_pill($r['status']) ?></td>
          <td>
            <small>
              <?= $r['paid_at']
                    ? e(date('M j, Y g:ia', strtotime($r['paid_at'])))
                    : '—' ?>
            </small>
          </td>
          <td><small><?= e(date('M j, Y', strtotime($r['created_at']))) ?></small></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="empty-state small" id="noResults" hidden>
    <p>No payments match your search on this page.</p>
  </div>

  <!-- ═══ PAGINATION ═══ -->
  <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Pagination">

      <?php if ($page > 1): ?>
        <a href="<?= e(buyer_payments_url(['page' => $page - 1])) ?>" class="page-btn">‹ Prev</a>
      <?php else: ?>
        <span class="page-btn disabled">‹ Prev</span>
      <?php endif; ?>

      <?php
        $window = 2;
        $start  = max(1, $page - $window);
        $end    = min($totalPages, $page + $window);

        if ($start > 1) {
            echo '<a href="' . e(buyer_payments_url(['page' => 1])) . '" class="page-btn">1</a>';
            if ($start > 2) echo '<span class="page-dots">…</span>';
        }

        for ($i = $start; $i <= $end; $i++):
      ?>
        <a href="<?= e(buyer_payments_url(['page' => $i])) ?>"
           class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php
        endfor;

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<span class="page-dots">…</span>';
            echo '<a href="' . e(buyer_payments_url(['page' => $totalPages])) . '" class="page-btn">' . $totalPages . '</a>';
        }
      ?>

      <?php if ($page < $totalPages): ?>
        <a href="<?= e(buyer_payments_url(['page' => $page + 1])) ?>" class="page-btn">Next ›</a>
      <?php else: ?>
        <span class="page-btn disabled">Next ›</span>
      <?php endif; ?>

    </nav>
  <?php endif; ?>

<?php endif; ?>


<!-- ═══════════════════════════════════════════════════
     EXPORT MODAL
     ═══════════════════════════════════════════════════ -->
<div class="modal" id="exportModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>
    <h2>Download my payments</h2>
    <p class="step-sub">Choose a format and a time range.</p>

    <form id="exportForm" method="GET" action="buyer_payments_export.php" target="_blank">
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
          <option value="completed">Completed</option>
          <option value="pending">Pending</option>
          <option value="failed">Failed</option>
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
  const searchEl = document.getElementById('buyerPaymentsSearch');
  const tableEl  = document.getElementById('buyerPaymentsTable');
  const countEl  = document.getElementById('buyerPaymentsCount');
  const noResEl  = document.getElementById('noResults');

  if (searchEl && tableEl) {
    const rows        = [...tableEl.querySelectorAll('tbody tr')];
    const onPageTotal = rows.length;
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
          : `Showing ${onPageTotal} of ${filteredTotal} payment${filteredTotal === 1 ? '' : 's'}`;
      }
      if (noResEl) noResEl.hidden = shown > 0;
    });
  }
})();
</script>