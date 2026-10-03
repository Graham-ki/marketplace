<?php
require_once __DIR__ . '/../_helpers.php';

$u = current_user();

// ═══════════════════════════════════════════════════
// 1. PAYMENT LIST
// ═══════════════════════════════════════════════════
$stmt = db()->prepare("
    SELECT pay.*, o.order_code, b.full_name AS buyer_name, b.email AS buyer_email
    FROM payments pay
    JOIN orders o ON o.id = pay.order_id
    JOIN users  b ON b.id = pay.user_id
    WHERE o.seller_id = ?
    ORDER BY pay.created_at DESC
");
$stmt->execute([$u['id']]);
$rows = $stmt->fetchAll();

// ═══════════════════════════════════════════════════
// 2. KPIs — computed directly from the DB
//    Always in sync, never stale, no PHP loops.
// ═══════════════════════════════════════════════════
$kpiStmt = db()->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN pay.status='completed' THEN pay.amount ELSE 0 END), 0) AS received,
        COALESCE(SUM(CASE WHEN pay.status='pending'   THEN pay.amount ELSE 0 END), 0) AS pending,
        COALESCE(SUM(CASE WHEN pay.status='failed'    THEN pay.amount ELSE 0 END), 0) AS failed,
        COUNT(*)                                                                        AS total_count,
        COALESCE(SUM(CASE WHEN pay.status='completed' AND DATE(pay.created_at)=CURDATE()
                          THEN pay.amount ELSE 0 END), 0)                              AS today_received,
        COALESCE(SUM(CASE WHEN pay.status='completed'
                           AND pay.created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')
                          THEN pay.amount ELSE 0 END), 0)                              AS month_received
    FROM payments pay
    JOIN orders o ON o.id = pay.order_id
    WHERE o.seller_id = ?
");
$kpiStmt->execute([$u['id']]);
$kpi = $kpiStmt->fetch();

$received = (float)$kpi['received'];
$pending  = (float)$kpi['pending'];
$failed   = (float)$kpi['failed'];
$today    = (float)$kpi['today_received'];
$month    = (float)$kpi['month_received'];
$totalCount = (int)$kpi['total_count'];

// ═══════════════════════════════════════════════════
// 3. HEADER
// ═══════════════════════════════════════════════════
$actions = '
  <div class="btn-group">
    <button class="btn btn-ghost" data-open-modal="exportModal">⬇ Download</button>
    <button class="btn btn-accent" data-open-modal="recordPaymentModal">+ Record payment</button>
  </div>
';

dash_header(
    'Payments',
    $totalCount . ' record' . ($totalCount === 1 ? '' : 's') . ' · '
        . money($received) . ' received · ' . money($pending) . ' pending',
    $actions
);
?>

<!-- ═══════════════════════════════════════════════════
     KPI CARDS
     ═══════════════════════════════════════════════════ -->
<div class="kpi-grid">
  <div class="kpi-card gradient decorated">
    <span class="kpi-label">Total received</span>
    <span class="kpi-value"><?= money($received) ?></span>
    <span class="kpi-delta">
      <?= money($today) ?> today · <?= money($month) ?> this month
    </span>
  </div>

  <div class="kpi-card warn decorated">
    <span class="kpi-label">Pending</span>
    <span class="kpi-value warn"><?= money($pending) ?></span>
    <span class="kpi-delta">
      <?= $pending > 0 ? 'Awaiting confirmation' : 'All clear' ?>
    </span>
  </div>

  <div class="kpi-card danger decorated">
    <span class="kpi-label">Failed</span>
    <span class="kpi-value <?= $failed > 0 ? 'danger' : '' ?>"><?= money($failed) ?></span>
    <span class="kpi-delta">
      <?= $failed > 0 ? 'Needs attention' : 'No failed payments' ?>
    </span>
  </div>

  <div class="kpi-card accent">
    <span class="kpi-label">Records</span>
    <span class="kpi-value"><?= $totalCount ?></span>
    <span class="kpi-delta">All time</span>
  </div>
</div>


<?php if (!$rows): ?>

  <div class="empty-state">
    <div class="empty-icon">💳</div>
    <h3>No payments yet</h3>
    <p>Payments appear here once orders are placed or recorded manually.</p>
    <button class="btn btn-accent" data-open-modal="recordPaymentModal">+ Record payment</button>
  </div>

<?php else: ?>

  <!-- ═══ SEARCH + COUNT ═══ -->
  <div class="list-toolbar">
    <div class="search-mini">
      <span class="search-mini-icon">🔍</span>
      <input type="search" id="paymentsSearch"
             placeholder="Search by code, order, buyer, reference…"
             autocomplete="off">
    </div>
    <span class="list-count" id="paymentsCount"><?= $totalCount ?> payments</span>
  </div>

  <!-- ═══ TABLE ═══ -->
  <div class="table-scroll">
    <table class="data-table data-table-wide" id="paymentsTable">
      <thead>
        <tr>
          <th>Code</th>
          <th>Order</th>
          <th>Buyer</th>
          <th class="num">Amount</th>
          <th>Method</th>
          <th>Reference</th>
          <th>Status</th>
          <th>Paid at</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><code><?= e($r['payment_code']) ?></code></td>
          <td><code><?= e($r['order_code']) ?></code></td>
          <td>
            <strong><?= e($r['buyer_name']) ?></strong><br>
            <small><?= e($r['buyer_email']) ?></small>
          </td>
          <td class="num"><strong><?= money((float)$r['amount']) ?></strong></td>
          <td>
            <form method="post" action="payment_update.php" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="payment_id" value="<?= (int)$r['id'] ?>">
              <select name="method" onchange="this.form.submit()" class="mini-select">
                <?php foreach (['cash','card','mobile_money','bank','other'] as $m): ?>
                  <option value="<?= $m ?>" <?= $r['method']===$m?'selected':'' ?>>
                    <?= ucfirst(str_replace('_',' ',$m)) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
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
          <td class="row-actions">
            <button class="btn-link"
                    data-edit-payment='<?= e(json_encode([
                        'id'        => (int)$r['id'],
                        'code'      => $r['payment_code'],
                        'amount'    => (float)$r['amount'],
                        'method'    => $r['method'],
                        'reference' => $r['reference'],
                        'status'    => $r['status'],
                    ])) ?>'>Edit</button>

            <?php if ($r['status'] === 'pending'): ?>
              <form method="post" action="payment_update.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="payment_id" value="<?= (int)$r['id'] ?>">
                <button name="status" value="completed" class="btn-link">Mark paid</button>
              </form>
            <?php elseif ($r['status'] === 'failed'): ?>
              <form method="post" action="payment_update.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="payment_id" value="<?= (int)$r['id'] ?>">
                <button name="status" value="completed" class="btn-link">Mark paid</button>
              </form>
            <?php endif; ?>

            <button class="btn-link danger"
                    data-delete-payment="<?= (int)$r['id'] ?>"
                    data-delete-code="<?= e($r['payment_code']) ?>"
                    data-delete-status="<?= e($r['status']) ?>">Delete</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="empty-state small" id="noResults" hidden>
    <p>No payments match your search.</p>
  </div>

<?php endif; ?>


<!-- ═══════════════════════════════════════════════════
     RECORD PAYMENT MODAL
     ═══════════════════════════════════════════════════ -->
<div class="modal" id="recordPaymentModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>
    <h2>Record a payment</h2>
    <p class="step-sub">Against a specific order.</p>

    <form id="recordPaymentForm" class="settings-form">
      <?= csrf_field() ?>

      <label class="field"><span>Order</span>
        <select name="order_id" id="pay-order" required>
          <option value="">— choose order —</option>
        </select>
      </label>

      <p class="field-hint" id="pay-hint"></p>

      <label class="field"><span>Amount</span>
        <input type="number" name="amount" id="pay-amount" step="0.01" min="0.01" required>
      </label>

      <div class="two-col">
        <label class="field"><span>Method</span>
          <select name="method" id="pay-method">
            <option value="cash">Cash</option>
            <option value="mobile_money">Mobile money</option>
            <option value="card">Card</option>
            <option value="bank">Bank</option>
            <option value="other">Other</option>
          </select>
        </label>
        <label class="field"><span>Status</span>
          <select name="status" id="pay-status">
            <option value="completed">Paid</option>
            <option value="pending">Pending</option>
            <option value="failed">Failed</option>
          </select>
        </label>
      </div>

      <label class="field"><span>Reference <em>(optional)</em></span>
        <input type="text" name="reference" id="pay-reference" placeholder="Txn ID, receipt no…">
      </label>

      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-accent">Save payment</button>
      </div>
    </form>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════
     EDIT PAYMENT MODAL
     ═══════════════════════════════════════════════════ -->
<div class="modal" id="editPaymentModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>
    <h2>Edit payment</h2>
    <p class="step-sub" id="edit-pay-sub"></p>

    <form id="editPaymentForm" class="settings-form">
      <?= csrf_field() ?>
      <input type="hidden" name="payment_id" id="edit-pay-id">
      <input type="hidden" name="mode" value="edit">

      <label class="field"><span>Amount</span>
        <input type="number" name="amount" id="edit-pay-amount"
               step="0.01" min="0.01" required>
      </label>

      <div class="two-col">
        <label class="field"><span>Method</span>
          <select name="method" id="edit-pay-method">
            <option value="cash">Cash</option>
            <option value="mobile_money">Mobile money</option>
            <option value="card">Card</option>
            <option value="bank">Bank</option>
            <option value="other">Other</option>
          </select>
        </label>
        <label class="field"><span>Status</span>
          <select name="status" id="edit-pay-status">
            <option value="completed">Paid</option>
            <option value="pending">Pending</option>
            <option value="failed">Failed</option>
          </select>
        </label>
      </div>

      <label class="field"><span>Reference <em>(optional)</em></span>
        <input type="text" name="reference" id="edit-pay-reference">
      </label>

      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-accent">Save changes</button>
      </div>
    </form>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════
     DELETE PAYMENT MODAL
     ═══════════════════════════════════════════════════ -->
<div class="modal" id="deletePaymentModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>

    <div class="confirm-hero">
      <div class="confirm-icon">🗑️</div>
      <h2>Delete payment?</h2>
      <p class="step-sub" id="delete-pay-sub">This cannot be undone.</p>

      <div class="confirm-warning" id="delete-pay-warning" hidden>
        ⚠️ This payment is <strong>completed</strong>. Deleting it removes the
        revenue record for this order. The order itself is not affected.
      </div>

      <form id="deletePaymentForm" class="settings-form" style="margin-top:20px">
        <?= csrf_field() ?>
        <input type="hidden" name="payment_id" id="delete-pay-id">

        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-danger">Delete payment</button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════
     EXPORT MODAL
     ═══════════════════════════════════════════════════ -->
<div class="modal" id="exportModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>
    <h2>Download payments</h2>
    <p class="step-sub">Choose a format and a time range.</p>

    <form id="exportForm" method="GET" action="payments_export.php" target="_blank">
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
     MODAL OPEN / CLOSE
     ═══════════════════════════════════════════════ */
  document.querySelectorAll('[data-open-modal]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.openModal;
      const m  = document.getElementById(id);
      if (!m) return;
      m.hidden = false;
      if (id === 'recordPaymentModal') await loadOrdersForPayment();
    });
  });

  document.querySelectorAll('[data-close-modal]').forEach(el => {
    el.addEventListener('click', () => {
      const m = el.closest('.modal');
      if (m) m.hidden = true;
    });
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal:not([hidden])').forEach(m => m.hidden = true);
    }
  });

  /* ═══════════════════════════════════════════════
     RECORD PAYMENT
     ═══════════════════════════════════════════════ */
  const selEl  = document.getElementById('pay-order');
  const amtEl  = document.getElementById('pay-amount');
  const methEl = document.getElementById('pay-method');
  const statEl = document.getElementById('pay-status');
  const refEl  = document.getElementById('pay-reference');
  const hintEl = document.getElementById('pay-hint');

  let ordersCache = [];

  async function loadOrdersForPayment() {
    selEl.innerHTML = '<option value="">— loading… —</option>';
    hintEl.textContent = '';
    hintEl.className = 'field-hint';

    try {
      const r = await fetch('seller_orders_api.php?unpaid=1');
      const j = await r.json();

      selEl.innerHTML = '<option value="">— choose order —</option>';
      if (!j.ok) return;

      ordersCache = j.orders;

      j.orders.forEach(o => {
        const hasPayment = o.payment_id !== null;
        const label = hasPayment
          ? `${o.order_code} · ${o.product_title} · ${o.payment_status}`
          : `${o.order_code} · ${o.product_title} · no payment yet`;

        const opt = document.createElement('option');
        opt.value = o.id;
        opt.textContent = label;
        selEl.appendChild(opt);
      });
    } catch (_) {
      selEl.innerHTML = '<option value="">— failed to load —</option>';
    }
  }

  selEl?.addEventListener('change', () => {
    const order = ordersCache.find(o => String(o.id) === selEl.value);
    if (!order) { hintEl.textContent = ''; hintEl.className = 'field-hint'; return; }

    if (order.payment_id) {
      amtEl.value  = order.payment_amount;
      methEl.value = order.payment_method;
      statEl.value = order.payment_status;
      refEl.value  = order.payment_reference || '';
      hintEl.textContent = `Existing payment ${order.payment_code} (${order.payment_status}) — this will be updated.`;
      hintEl.className = 'field-hint info';
    } else {
      amtEl.value  = order.total_amount;
      methEl.value = 'cash';
      statEl.value = 'completed';
      refEl.value  = '';
      hintEl.textContent = 'No payment yet — a new one will be created.';
      hintEl.className = 'field-hint warn';
    }
  });

  document.getElementById('recordPaymentForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const btn  = e.target.querySelector('button[type=submit]');
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Saving…';

    try {
      const r = await fetch('record_payment.php', {
        method: 'POST',
        body:   new FormData(e.target),
      });
      const j = await r.json();
      if (j.ok) location.reload();
      else { alert(j.error || 'Failed'); btn.disabled = false; btn.textContent = orig; }
    } catch (_) {
      alert('Network error');
      btn.disabled = false; btn.textContent = orig;
    }
  });

  /* ═══════════════════════════════════════════════
     EDIT PAYMENT
     ═══════════════════════════════════════════════ */
  document.querySelectorAll('[data-edit-payment]').forEach(btn => {
    btn.addEventListener('click', () => {
      const p = JSON.parse(btn.dataset.editPayment);
      document.getElementById('edit-pay-id').value        = p.id;
      document.getElementById('edit-pay-amount').value    = p.amount;
      document.getElementById('edit-pay-method').value    = p.method;
      document.getElementById('edit-pay-status').value    = p.status;
      document.getElementById('edit-pay-reference').value = p.reference || '';
      document.getElementById('edit-pay-sub').textContent = `Payment ${p.code}`;
      document.getElementById('editPaymentModal').hidden  = false;
    });
  });

  document.getElementById('editPaymentForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const btn  = e.target.querySelector('button[type=submit]');
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Saving…';

    try {
      const r = await fetch('payment_update.php', {
        method: 'POST',
        body:   new FormData(e.target),
      });
      const j = await r.json();
      if (j.ok) location.reload();
      else { alert(j.error || 'Failed'); btn.disabled = false; btn.textContent = orig; }
    } catch (_) {
      alert('Network error');
      btn.disabled = false; btn.textContent = orig;
    }
  });

  /* ═══════════════════════════════════════════════
     DELETE PAYMENT
     ═══════════════════════════════════════════════ */
  document.querySelectorAll('[data-delete-payment]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id     = btn.dataset.deletePayment;
      const code   = btn.dataset.deleteCode;
      const status = btn.dataset.deleteStatus;

      document.getElementById('delete-pay-id').value = id;
      document.getElementById('delete-pay-sub').textContent =
        `Payment ${code} will be permanently removed.`;
      document.getElementById('delete-pay-warning').hidden = status !== 'completed';
      document.getElementById('deletePaymentModal').hidden = false;
    });
  });

  document.getElementById('deletePaymentForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const btn  = e.target.querySelector('button[type=submit]');
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Deleting…';

    try {
      const fd = new FormData(e.target);
      fd.append('action', 'delete');
      const r = await fetch('payment_update.php', { method: 'POST', body: fd });
      const j = await r.json();
      if (j.ok) location.reload();
      else { alert(j.error || 'Failed'); btn.disabled = false; btn.textContent = orig; }
    } catch (_) {
      alert('Network error');
      btn.disabled = false; btn.textContent = orig;
    }
  });

  /* ═══════════════════════════════════════════════
     EXPORT MODAL
     ═══════════════════════════════════════════════ */
  const rangeSel = document.getElementById('export-range');
  const customEl = document.getElementById('export-custom');
  rangeSel?.addEventListener('change', () => {
    customEl.hidden = rangeSel.value !== 'custom';
  });

  document.getElementById('exportForm')?.addEventListener('submit', () => {
    setTimeout(() => document.getElementById('exportModal').hidden = true, 400);
  });

  /* ═══════════════════════════════════════════════
     LIVE SEARCH
     ═══════════════════════════════════════════════ */
  const searchEl = document.getElementById('paymentsSearch');
  const tableEl  = document.getElementById('paymentsTable');
  const countEl  = document.getElementById('paymentsCount');
  const noResEl  = document.getElementById('noResults');

  if (searchEl && tableEl) {
    const rows  = [...tableEl.querySelectorAll('tbody tr')];
    const total = rows.length;

    searchEl.addEventListener('input', () => {
      const q = searchEl.value.trim().toLowerCase();
      let shown = 0;
      rows.forEach(row => {
        const hit = !q || row.textContent.toLowerCase().includes(q);
        row.hidden = !hit;
        if (hit) shown++;
      });
      if (countEl) {
        countEl.textContent = q
          ? `${shown} of ${total} payments`
          : `${total} payments`;
      }
      if (noResEl) noResEl.hidden = shown > 0;
    });
  }
})();
</script>