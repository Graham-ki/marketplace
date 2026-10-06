<?php
require __DIR__ . '/db.php';

$u = current_user();
if (!$u) { header('Location: login.php'); exit; }

$pdo = db();

/* ═══════════════════════════════════════════════
   Inputs
   ═══════════════════════════════════════════════ */
$group  = trim($_GET['group'] ?? '');
$idsCsv = trim($_GET['ids']   ?? '');

$ids = array_filter(array_map('intval', explode(',', $idsCsv)));
$ids = array_slice($ids, 0, 100);   // cap

if (!$group && !$ids) {
    http_response_code(400);
    die('Provide ?group=... or ?ids=1,2,3');
}

/* ═══════════════════════════════════════════════
   Fetch orders + authorize
   ═══════════════════════════════════════════════ */
$where = [];
$args  = [];

if ($group) {
    $where[] = "o.order_group = ?";
    $args[]  = $group;
} else {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where[] = "o.id IN ($placeholders)";
    $args    = array_merge($args, $ids);
}

// Restrict by role
if ($u['role'] === 'buyer') {
    $where[] = "o.buyer_id = ?";
    $args[]  = $u['id'];
} elseif ($u['role'] === 'seller') {
    $where[] = "o.seller_id = ?";
    $args[]  = $u['id'];
}
// admins: unrestricted

$sql = "
    SELECT o.*,
           p.title AS product_title,
           u_b.full_name AS buyer_name, u_b.email AS buyer_email, u_b.phone AS buyer_phone,
           u_s.full_name AS seller_name,
           COALESCE(NULLIF(sp.business_name,''), u_s.full_name) AS seller_shop,
           sp.tin     AS seller_tin,
           sp.phone   AS seller_phone,
           sp.address AS seller_address,
           p2.payment_code, p2.method AS payment_method,
           p2.reference  AS payment_reference,
           p2.status     AS payment_status,
           p2.paid_at
    FROM orders o
    JOIN products p ON p.id = o.product_id
    JOIN users u_b ON u_b.id = o.buyer_id
    JOIN users u_s ON u_s.id = o.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = o.seller_id
    LEFT JOIN payments p2 ON p2.order_id = o.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY o.seller_id, o.created_at
";

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$orders = $stmt->fetchAll();

if (!$orders) { http_response_code(404); die('No orders found'); }

/* ═══════════════════════════════════════════════
   Determine overall document type
   Combined docs mix paid and unpaid. We label the
   whole thing STATEMENT and indicate per-line status.
   ═══════════════════════════════════════════════ */
$allPaid = true;
$anyPaid = false;
foreach ($orders as $o) {
    if ($o['payment_status'] === 'completed') $anyPaid = true;
    else $allPaid = false;
}

$docLabel = $allPaid ? 'Receipt (combined)' : ($anyPaid ? 'Statement' : 'Invoice (combined)');
$docColor = $allPaid ? '#166534' : '#92400e';
$docBg    = $allPaid ? '#f0fdf4' : '#fef3c7';
$docBorder= $allPaid ? '#bbf7d0' : '#fde68a';

/* ═══════════════════════════════════════════════
   Ensure each order has a receipt_code
   ═══════════════════════════════════════════════ */
$prefix = $allPaid ? 'RCP' : 'INV';
foreach ($orders as &$o) {
    if (empty($o['receipt_code'])) {
        $code = $prefix . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $pdo->prepare("UPDATE orders SET receipt_code = ?, receipt_issued_at = NOW() WHERE id = ?")
            ->execute([$code, $o['id']]);
        $o['receipt_code']      = $code;
        $o['receipt_issued_at'] = date('Y-m-d H:i:s');
    }
}
unset($o);

/* ═══════════════════════════════════════════════
   Totals
   ═══════════════════════════════════════════════ */
$grandSubtotal = 0;
$grandDiscount = 0;
$grandTax      = 0;
$grandTotal    = 0;
$grandPaid     = 0;
$grandDue      = 0;
foreach ($orders as $o) {
    $grandSubtotal += (float)$o['subtotal'];
    $grandDiscount += (float)$o['discount_amount'];
    $grandTax      += (float)$o['tax_amount'];
    $grandTotal    += (float)$o['total_amount'];
    if ($o['payment_status'] === 'completed') $grandPaid += (float)$o['total_amount'];
    else $grandDue += (float)$o['total_amount'];
}

$groupId = $group ?: ('MULTI-' . strtoupper(bin2hex(random_bytes(4))));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($groupId) ?> · Statement</title>
<style>
  /* Same base styles as receipt.php but with table-of-orders */
  * { box-sizing: border-box; }
  body {
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    color: #111; background: #f8fafc; padding: 24px; margin: 0;
  }
  .actions {
    max-width: 900px; margin: 0 auto 16px;
    display: flex; gap: 8px; justify-content: flex-end;
  }
  .actions button, .actions a {
    padding: 10px 18px; border-radius: 8px; border: 1px solid #cbd5e1;
    background: #fff; cursor: pointer; font-size: .9rem;
    text-decoration: none; color: #111; font-family: inherit;
  }
  .actions button.primary {
    background: #22a06b; color: #fff; border-color: #22a06b; font-weight: 600;
  }
  .doc {
    max-width: 900px; margin: 0 auto; background: #fff;
    border: 1px solid #e5e7eb; border-radius: 12px; padding: 40px;
  }
  .head {
    display: flex; justify-content: space-between; gap: 32px;
    flex-wrap: wrap; border-bottom: 1px solid #e5e7eb;
    padding-bottom: 24px; margin-bottom: 24px;
  }
  .head-brand {
    display: flex; align-items: center; gap: 12px;
    font-size: 1.3rem; font-weight: 800; letter-spacing: -.02em;
  }
  .head-brand .mark {
    width: 36px; height: 36px; border-radius: 10px;
    background: linear-gradient(135deg, #22a06b, #84d99c);
    color: #fff; display: grid; place-items: center; font-size: 1rem;
  }
  .head-meta { text-align: right; color: #64748b; font-size: .88rem; line-height: 1.55; }
  .doc-title {
    display: inline-block; padding: 6px 14px; border-radius: 999px;
    font-size: .78rem; font-weight: 700; letter-spacing: .08em;
    text-transform: uppercase; background: <?= $docBg ?>;
    color: <?= $docColor ?>; border: 1px solid <?= $docBorder ?>;
  }
  .doc-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 1.05rem; font-weight: 700; margin-top: 6px;
  }
  .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; margin-bottom: 32px; }
  .party h4 {
    font-size: .72rem; text-transform: uppercase; letter-spacing: .08em;
    color: #64748b; margin-bottom: 8px; font-weight: 700;
  }
  .party strong { display: block; font-size: 1rem; margin-bottom: 2px; }
  .party div { color: #475569; font-size: .88rem; line-height: 1.5; }
  table { width: 100%; border-collapse: collapse; font-size: .88rem; margin-bottom: 24px; }
  th {
    text-align: left; font-size: .72rem; text-transform: uppercase;
    letter-spacing: .06em; color: #64748b; font-weight: 700;
    padding: 10px 12px; border-bottom: 2px solid #e5e7eb;
    background: #f8fafc;
  }
  td { padding: 12px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .status-pill {
    display: inline-block; padding: 3px 10px; border-radius: 999px;
    font-size: .72rem; font-weight: 700; text-transform: capitalize;
  }
  .status-completed { background: #dcfce7; color: #166534; }
  .status-pending   { background: #fef3c7; color: #92400e; }
  .status-failed    { background: #fee2e2; color: #991b1b; }
  .totals { margin-left: auto; max-width: 340px; font-size: .92rem; margin-top: 12px; }
  .totals .row { display: flex; justify-content: space-between; padding: 6px 0; color: #475569; }
  .totals .row.total {
    border-top: 2px solid #111; margin-top: 8px; padding-top: 12px;
    font-size: 1.1rem; font-weight: 800; color: #111;
  }
  .totals .row.total strong { font-size: 1.3rem; color: #166534; }
  .totals .row.due strong { color: #92400e; }
  .footer-note {
    text-align: center; color: #94a3b8; font-size: .78rem;
    margin-top: 32px; padding-top: 20px; border-top: 1px solid #e5e7eb;
  }
  @media (max-width: 640px) {
    body { padding: 12px; }
    .doc { padding: 24px 18px; }
    .parties { grid-template-columns: 1fr; gap: 20px; }
    .totals { max-width: 100%; }
  }
  @media print {
    body { padding: 0; background: #fff; }
    .actions { display: none; }
    .doc { border: none; border-radius: 0; padding: 24px; max-width: 100%; }
  }
</style>
</head>
<body>

<div class="actions">
  <a href="javascript:history.back()">← Back</a>
  <button class="primary" onclick="window.print()">🖨️ Print / Save as PDF</button>
</div>

<div class="doc">

  <div class="head">
    <div>
      <div class="head-brand">
        <span class="mark">◆</span>
        <span>Market</span>
      </div>
      <div style="margin-top:14px">
        <span class="doc-title"><?= e($docLabel) ?></span>
        <div class="doc-code"><?= e($groupId) ?></div>
      </div>
    </div>
    <div class="head-meta">
      <div><strong>Issued:</strong> <?= e(date('M j, Y g:ia')) ?></div>
      <div><strong>Orders:</strong> <?= count($orders) ?></div>
    </div>
  </div>

  <?php
    // Buyer name is same across the whole doc; seller may vary
    $firstOrder = $orders[0];
  ?>
  <div class="parties">
    <div class="party">
      <h4>Bill to</h4>
      <strong><?= e($firstOrder['buyer_name']) ?></strong>
      <div><?= e($firstOrder['buyer_email']) ?></div>
      <?php if (!empty($firstOrder['buyer_phone'])): ?>
        <div><?= e($firstOrder['buyer_phone']) ?></div>
      <?php endif; ?>
    </div>
    <div class="party">
      <h4>Statement covers</h4>
      <?php
        // Unique seller shops in this document
        $shops = [];
        foreach ($orders as $o) $shops[$o['seller_shop']] = true;
      ?>
      <div>
        <?= count($orders) ?> order<?= count($orders) === 1 ? '' : 's' ?>
        <?php if (count($shops) > 1): ?>
          · <?= count($shops) ?> seller<?= count($shops) === 1 ? '' : 's' ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Orders table -->
  <table>
    <thead>
      <tr>
        <th>Order</th>
        <th>Date</th>
        <th>Item</th>
        <th class="num">Qty</th>
        <th class="num">Total</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td>
            <code style="font-family:ui-monospace,monospace;font-size:.82rem">
              <?= e($o['order_code']) ?>
            </code>
          </td>
          <td><?= e(date('M j, Y', strtotime($o['created_at']))) ?></td>
          <td><?= e($o['product_title']) ?></td>
          <td class="num"><?= (int)$o['quantity'] ?></td>
          <td class="num"><?= number_format((float)$o['total_amount'], 2) ?></td>
          <td>
            <span class="status-pill status-<?= e($o['payment_status'] ?: 'pending') ?>">
              <?= e($o['payment_status'] ?: 'pending') ?>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="totals">
    <div class="row">
      <span>Subtotal</span>
      <span><?= number_format($grandSubtotal, 2) ?></span>
    </div>
    <?php if ($grandDiscount > 0): ?>
      <div class="row">
        <span>Discounts</span>
        <span>− <?= number_format($grandDiscount, 2) ?></span>
      </div>
    <?php endif; ?>
    <?php if ($grandTax > 0): ?>
      <div class="row">
        <span>Taxes</span>
        <span>+ <?= number_format($grandTax, 2) ?></span>
      </div>
    <?php endif; ?>
    <div class="row total">
      <span>Total</span>
      <strong><?= number_format($grandTotal, 2) ?></strong>
    </div>
    <?php if ($grandPaid > 0): ?>
      <div class="row">
        <span>Already paid</span>
        <span><?= number_format($grandPaid, 2) ?></span>
      </div>
    <?php endif; ?>
    <?php if ($grandDue > 0): ?>
      <div class="row due">
        <span><strong>Amount due</strong></span>
        <strong><?= number_format($grandDue, 2) ?></strong>
      </div>
    <?php endif; ?>
  </div>

  <div class="footer-note">
    Thank you for using Market.<br>
    This is a computer-generated document — no signature required.
  </div>
</div>

</body>
</html>