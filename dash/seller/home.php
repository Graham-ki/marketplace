<?php
require_once __DIR__ . '/../_helpers.php';

$u = current_user();
$pdo = db();

// ═══ KPI: today ═══
$today = $pdo->prepare("
    SELECT COUNT(*) AS orders,
           COALESCE(SUM(total_amount),0) AS revenue
    FROM orders
    WHERE seller_id=? AND DATE(created_at)=CURDATE() AND status!='cancelled'
");
$today->execute([$u['id']]);
$today = $today->fetch();

// ═══ KPI: 7-day ═══
$week = $pdo->prepare("
    SELECT COUNT(*) AS orders,
           COALESCE(SUM(total_amount),0) AS revenue
    FROM orders
    WHERE seller_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND status!='cancelled'
");
$week->execute([$u['id']]);
$week = $week->fetch();

// ═══ KPI: 30-day ═══
$month = $pdo->prepare("
    SELECT COUNT(*) AS orders,
           COALESCE(SUM(total_amount),0) AS revenue
    FROM orders
    WHERE seller_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status!='cancelled'
");
$month->execute([$u['id']]);
$month = $month->fetch();

// ═══ Actionable counts ═══
$pending = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=? AND status='pending'");
$pending->execute([$u['id']]);
$pendingCount = (int)$pending->fetchColumn();

$lowStock = $pdo->prepare("SELECT COUNT(*) FROM products WHERE seller_id=? AND quantity<=3 AND status='active'");
$lowStock->execute([$u['id']]);
$lowStockCount = (int)$lowStock->fetchColumn();

$unpaid = $pdo->prepare("
    SELECT COUNT(*) FROM payments p
    JOIN orders o ON o.id=p.order_id
    WHERE o.seller_id=? AND p.status='pending'
");
$unpaid->execute([$u['id']]);
$unpaidCount = (int)$unpaid->fetchColumn();

$refundReq = $pdo->prepare("SELECT COUNT(*) FROM refunds WHERE seller_id=? AND status='requested'");
$refundReq->execute([$u['id']]);
$refundReqCount = (int)$refundReq->fetchColumn();

// ═══ 14-day revenue spark ═══
$daily = $pdo->prepare("
    SELECT DATE(created_at) AS d, COALESCE(SUM(total_amount),0) AS revenue
    FROM orders
    WHERE seller_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND status!='cancelled'
    GROUP BY DATE(created_at) ORDER BY d
");
$daily->execute([$u['id']]);
$dailyRows = $daily->fetchAll();

// ═══ Recent orders ═══
$recent = $pdo->prepare("
    SELECT o.id, o.order_code, o.total_amount, o.status, o.created_at,
           p.title AS product_title, p.cover_image,
           COALESCE(NULLIF(sp2.business_name,''), b.full_name) AS buyer_name
    FROM orders o
    JOIN products p ON p.id=o.product_id
    JOIN users b    ON b.id=o.buyer_id
    LEFT JOIN seller_profiles sp2 ON sp2.user_id = b.id
    WHERE o.seller_id=?
    ORDER BY o.created_at DESC
    LIMIT 6
");
$recent->execute([$u['id']]);
$recentOrders = $recent->fetchAll();

// ═══ Top product this week ═══
$top = $pdo->prepare("
    SELECT p.title, p.cover_image, SUM(o.quantity) AS sold
    FROM orders o JOIN products p ON p.id=o.product_id
    WHERE o.seller_id=? AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      AND o.status!='cancelled'
    GROUP BY p.id ORDER BY sold DESC LIMIT 1
");
$top->execute([$u['id']]);
$topProduct = $top->fetch();

// ═══ Greeting ═══
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$actions = '<button class="btn btn-accent" data-open-modal="manualOrderModal">+ New order</button>';
dash_header("$greeting, " . explode(' ', trim($displayName))[0] . ' 👋', 'Here\'s what\'s happening with your shop today.', $actions);
?>

<!-- ═══════════ ACTIONABLE ALERTS ═══════════ -->
<?php if ($pendingCount || $lowStockCount || $unpaidCount || $refundReqCount): ?>
<div class="alert-row">
  <?php if ($pendingCount): ?>
    <a class="alert-pill warn" href="dashboard.php?tab=orders&status=pending">
      ⏳ <?= $pendingCount ?> order<?= $pendingCount>1?'s':'' ?> awaiting confirmation
    </a>
  <?php endif; ?>
  <?php if ($lowStockCount): ?>
    <a class="alert-pill warn" href="dashboard.php?tab=stock">
      ⚠️ <?= $lowStockCount ?> item<?= $lowStockCount>1?'s':'' ?> low on stock
    </a>
  <?php endif; ?>
  <?php if ($unpaidCount): ?>
    <a class="alert-pill info" href="dashboard.php?tab=payments">
      💳 <?= $unpaidCount ?> payment<?= $unpaidCount>1?'s':'' ?> pending
    </a>
  <?php endif; ?>
  <?php if ($refundReqCount): ?>
    <a class="alert-pill danger" href="dashboard.php?tab=refunds">
      ↩️ <?= $refundReqCount ?> refund request<?= $refundReqCount>1?'s':'' ?>
    </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ═══════════ KPI CARDS ═══════════ -->
<div class="kpi-grid">
  <div class="kpi-card gradient decorated">
    <span class="kpi-label">Today</span>
    <span class="kpi-value"><?= money((float)$today['revenue']) ?></span>
    <span class="kpi-delta">
      <?= (int)$today['orders'] ?> order<?= (int)$today['orders']===1?'':'s' ?>
    </span>
  </div>
  <div class="kpi-card warn decorated">
    <span class="kpi-label">Last 7 days</span>
    <span class="kpi-value"><?= money((float)$week['revenue']) ?></span>
    <span class="kpi-delta"><?= (int)$week['orders'] ?> orders</span>
  </div>
  <div class="kpi-card info decorated">
    <span class="kpi-label">Last 30 days</span>
    <span class="kpi-value"><?= money((float)$month['revenue']) ?></span>
    <span class="kpi-delta"><?= (int)$month['orders'] ?> orders</span>
  </div>
  <?php if ($topProduct): ?>
  <div class="kpi-card accent">
    <span class="kpi-label">Top seller this week</span>
    <span class="kpi-value small"><?= e($topProduct['title']) ?></span>
    <span class="kpi-delta"><?= (int)$topProduct['sold'] ?> sold</span>
  </div>
  <?php endif; ?>
</div>

<!-- ═══════════ SPARK ═══════════ -->
<div class="report-card">
  <div class="report-head">
    <h3>Last 14 days</h3>
    <a href="dashboard.php?tab=reports" class="btn-link">Full report →</a>
  </div>
  <?php if (!$dailyRows): ?>
    <p class="step-sub">No orders in the last 14 days.</p>
  <?php else:
    $max = max(array_map(fn($r) => (float)$r['revenue'], $dailyRows)) ?: 1;
  ?>
    <div class="spark">
      <?php foreach ($dailyRows as $r): ?>
        <div class="spark-bar" style="height:<?= max(4, ((float)$r['revenue']/$max)*100) ?>%"
             title="<?= e(date('M j', strtotime($r['d']))) ?> · <?= money((float)$r['revenue']) ?>"></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ═══════════ RECENT ACTIVITY ═══════════ -->
<div class="report-card">
  <div class="report-head">
    <h3>Recent orders</h3>
    <a href="dashboard.php?tab=orders" class="btn-link">View all →</a>
  </div>
  <?php if (!$recentOrders): ?>
    <p class="step-sub">No orders yet. When a buyer places one, it'll appear here.</p>
  <?php else: ?>
    <div class="table-card">
      <table class="data-table">
        <thead>
          <tr><th>Order</th><th>Item</th><th>Buyer</th>
              <th class="num">Total</th><th>Status</th><th>When</th></tr>
        </thead>
        <tbody>
        <?php foreach ($recentOrders as $o): ?>
          <tr>
            <td><code><?= e($o['order_code']) ?></code></td>
            <td>
              <div class="cell-product">
                <div class="cell-thumb" style="background-image:url('<?= e($o['cover_image'] ?: '') ?>')"></div>
                <strong><?= e($o['product_title']) ?></strong>
              </div>
            </td>
            <td><?= e($o['buyer_name']) ?></td>
            <td class="num"><?= money((float)$o['total_amount']) ?></td>
            <td><?= status_pill($o['status']) ?></td>
            <td><small><?= e(time_ago($o['created_at'])) ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>


<!-- ═══════════ MANUAL ORDER MODAL ═══════════ -->
<div class="modal" id="manualOrderModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel">
    <button class="modal-close" data-close-modal>×</button>
    <h2>Record a new order</h2>
    <p class="step-sub">For walk-in customers, phone orders, or anything sold off-platform.</p>

    <form id="manualOrderForm" class="settings-form">
      <?= csrf_field() ?>

      <section class="settings-block">
        <h3>1 · Customer</h3>
        <p class="step-sub">
          Type an email to link to a registered buyer, or leave blank to save as a
          walk-in customer.
        </p>
        <label class="field"><span>Customer name</span>
          <input type="text" name="customer_name" required maxlength="100">
        </label>
        <div class="two-col">
          <label class="field"><span>Email <em>(optional)</em></span>
            <input type="email" name="customer_email" placeholder="buyer@example.com">
          </label>
          <label class="field"><span>Phone <em>(optional)</em></span>
            <input type="text" name="customer_phone">
          </label>
        </div>
      </section>

      <section class="settings-block">
        <h3>2 · Item</h3>
        <label class="field"><span>Product</span>
          <select name="product_id" id="manual-product" required>
            <option value="">— choose item —</option>
          </select>
        </label>
        <div class="two-col">
          <label class="field"><span>Quantity</span>
            <input type="number" name="quantity" id="manual-qty" min="1" value="1" required>
          </label>
          <label class="field"><span>Unit price override <em>(optional)</em></span>
            <input type="number" name="unit_price" id="manual-price" step="0.01" min="0">
          </label>
        </div>
        <p class="step-sub" id="manual-total-preview">Total will appear here.</p>
      </section>

      <section class="settings-block">
        <h3>3 · Delivery</h3>
        <label class="field"><span>Address <em>(optional for walk-ins)</em></span>
          <textarea name="delivery_address" rows="2"
                    placeholder="Leave blank if sold in person"></textarea>
        </label>
        <label class="field"><span>Notes</span>
          <textarea name="notes" rows="2"></textarea>
        </label>
      </section>

      <section class="settings-block">
        <h3>4 · Payment</h3>
        <div class="two-col">
          <label class="field"><span>Method</span>
            <select name="payment_method">
              <option value="cash">Cash</option>
              <option value="mobile_money">Mobile money</option>
              <option value="card">Card</option>
              <option value="bank">Bank</option>
              <option value="other">Other</option>
            </select>
          </label>
          <label class="field"><span>Status</span>
            <select name="payment_status">
              <option value="completed">Paid</option>
              <option value="pending">Unpaid</option>
            </select>
          </label>
        </div>
        <label class="field"><span>Reference <em>(optional)</em></span>
          <input type="text" name="payment_reference" placeholder="Txn ID, cheque no, etc.">
        </label>
      </section>

      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-accent">Record order</button>
      </div>
    </form>
  </div>
</div>


<script>
(() => {
  // ── Populate product dropdown ──
  fetch('seller_products_api.php')
    .then(r => r.json())
    .then(j => {
      if (!j.ok) return;
      const sel = document.getElementById('manual-product');
      j.products.forEach(p => {
        sel.insertAdjacentHTML('beforeend',
          `<option value="${p.id}" data-price="${p.price}" data-stock="${p.quantity}">
             ${escapeHtml(p.title)} · $${Number(p.price).toFixed(2)} (${p.quantity} in stock)
           </option>`);
      });
    });

  const sel = document.getElementById('manual-product');
  const qty = document.getElementById('manual-qty');
  const price = document.getElementById('manual-price');
  const preview = document.getElementById('manual-total-preview');

  function recalc() {
    const opt = sel.selectedOptions[0];
    if (!opt || !opt.value) { preview.textContent = 'Total will appear here.'; return; }
    const unit = +(price.value || opt.dataset.price || 0);
    const q = +qty.value || 1;
    preview.innerHTML = `Total: <strong>$${(unit*q).toFixed(2)}</strong> · Stock available: ${opt.dataset.stock}`;
  }
  sel.addEventListener('change', () => {
    const opt = sel.selectedOptions[0];
    if (opt?.value) price.value = opt.dataset.price || '';
    recalc();
  });
  qty.addEventListener('input', recalc);
  price.addEventListener('input', recalc);

  // ── Submit ──
  document.getElementById('manualOrderForm').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = e.submitter || e.target.querySelector('button[type=submit]');
    btn.disabled = true;
    const r = await fetch('manual_order.php', { method:'POST', body: new FormData(e.target) });
    const j = await r.json();
    if (j.ok) location.href = 'dashboard.php?tab=orders';
    else { alert(j.error || 'Failed'); btn.disabled = false; }
  });

  document.querySelectorAll('[data-close-modal]').forEach(el =>
    el.addEventListener('click', () => el.closest('.modal').hidden = true));
  document.querySelectorAll('[data-open-modal]').forEach(btn =>
    btn.addEventListener('click', () => document.getElementById(btn.dataset.openModal).hidden = false));

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, c =>
      ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
})();
</script>