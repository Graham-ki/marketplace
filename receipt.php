<?php
require __DIR__ . '/db.php';

$u        = current_user();
if (!$u) { header('Location: login.php'); exit; }

$orderId  = (int)($_GET['id'] ?? 0);
if (!$orderId) { http_response_code(400); die('Missing order id'); }

$pdo = db();

/* ═══════════════════════════════════════════════
   Fetch the order + authorize
   ═══════════════════════════════════════════════ */
$stmt = $pdo->prepare("
    SELECT o.*,
           p.title AS product_title, p.cover_image,
           u_b.full_name AS buyer_name, u_b.email AS buyer_email,
           u_b.phone     AS buyer_phone,
           u_s.full_name AS seller_name,
           COALESCE(NULLIF(sp.business_name,''), u_s.full_name) AS seller_shop,
           sp.tin        AS seller_tin,
           sp.phone      AS seller_phone,
           sp.address    AS seller_address,
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
    WHERE o.id = ?
    LIMIT 1
");
$stmt->execute([$orderId]);
$o = $stmt->fetch();

if (!$o) { http_response_code(404); die('Order not found'); }

$isBuyer  = ($u['role'] === 'buyer'  && (int)$o['buyer_id']  === (int)$u['id']);
$isSeller = ($u['role'] === 'seller' && (int)$o['seller_id'] === (int)$u['id']);
$isAdmin  = ($u['role'] === 'admin');

if (!$isBuyer && !$isSeller && !$isAdmin) {
    http_response_code(403);
    die('You do not have access to this order.');
}

/* ═══════════════════════════════════════════════
   Determine document type
   ═══════════════════════════════════════════════ */
$isPaid    = ($o['payment_status'] === 'completed');
$docType   = $isPaid ? 'RECEIPT' : 'INVOICE';
$docLabel  = $isPaid ? 'Receipt' : 'Invoice';
$docColor  = $isPaid ? '#166534' : '#92400e';   // green / amber
$docBg     = $isPaid ? '#f0fdf4' : '#fef3c7';
$docBorder = $isPaid ? '#bbf7d0' : '#fde68a';

/* ═══════════════════════════════════════════════
   Ensure a document code exists (idempotent)
   ═══════════════════════════════════════════════ */
if (empty($o['receipt_code'])) {
    $prefix = $isPaid ? 'RCP' : 'INV';
    $code   = $prefix . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

    $pdo->prepare("
        UPDATE orders
        SET receipt_code = ?, receipt_issued_at = NOW()
        WHERE id = ?
    ")->execute([$code, $orderId]);

    $o['receipt_code']      = $code;
    $o['receipt_issued_at'] = date('Y-m-d H:i:s');
}

$docCode = $o['receipt_code'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($docCode) ?> · <?= e($docLabel) ?></title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    color: #111;
    background: #f8fafc;
    padding: 24px;
    margin: 0;
  }
  .actions {
    max-width: 760px;
    margin: 0 auto 16px;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
  }
  .actions button,
  .actions a {
    padding: 10px 18px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #fff;
    cursor: pointer;
    font-size: .9rem;
    text-decoration: none;
    color: #111;
    font-family: inherit;
  }
  .actions button.primary {
    background: #22a06b;
    color: #fff;
    border-color: #22a06b;
    font-weight: 600;
  }
  .doc {
    max-width: 760px;
    margin: 0 auto;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 40px;
  }
  .head {
    display: flex;
    justify-content: space-between;
    gap: 32px;
    flex-wrap: wrap;
    border-bottom: 1px solid #e5e7eb;
    padding-bottom: 24px;
    margin-bottom: 24px;
  }
  .head-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 1.3rem;
    font-weight: 800;
    letter-spacing: -.02em;
  }
  .head-brand .mark {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: linear-gradient(135deg, #22a06b, #84d99c);
    color: #fff;
    display: grid;
    place-items: center;
    font-size: 1rem;
  }
  .head-meta {
    text-align: right;
    color: #64748b;
    font-size: .88rem;
    line-height: 1.55;
  }
  .doc-title {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    background: <?= $docBg ?>;
    color: <?= $docColor ?>;
    border: 1px solid <?= $docBorder ?>;
  }
  .doc-code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 1.05rem;
    font-weight: 700;
    margin-top: 6px;
  }
  .parties {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
    margin-bottom: 32px;
  }
  .party h4 {
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #64748b;
    margin-bottom: 8px;
    font-weight: 700;
  }
  .party strong {
    display: block;
    font-size: 1rem;
    margin-bottom: 2px;
  }
  .party div {
    color: #475569;
    font-size: .88rem;
    line-height: 1.5;
  }
  table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
    margin-bottom: 24px;
  }
  th {
    text-align: left;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #64748b;
    font-weight: 700;
    padding: 10px 12px;
    border-bottom: 2px solid #e5e7eb;
  }
  td {
    padding: 12px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: top;
  }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .totals {
    margin-left: auto;
    max-width: 320px;
    font-size: .92rem;
  }
  .totals .row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    color: #475569;
  }
  .totals .row.total {
    border-top: 2px solid #111;
    margin-top: 8px;
    padding-top: 12px;
    font-size: 1.1rem;
    font-weight: 800;
    color: #111;
  }
  .totals .row.total strong {
    font-size: 1.3rem;
    color: #166534;
  }
  .payment-block {
    margin-top: 32px;
    padding: 16px 20px;
    border-radius: 10px;
    background: <?= $docBg ?>;
    border: 1px solid <?= $docBorder ?>;
    font-size: .9rem;
    color: <?= $docColor ?>;
  }
  .payment-block strong {
    display: block;
    margin-bottom: 4px;
  }
  .footer-note {
    text-align: center;
    color: #94a3b8;
    font-size: .78rem;
    margin-top: 32px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
  }
  .status-pill {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: .72rem;
    font-weight: 700;
    text-transform: capitalize;
  }
  .status-completed { background: #dcfce7; color: #166534; }
  .status-pending   { background: #fef3c7; color: #92400e; }
  .status-failed    { background: #fee2e2; color: #991b1b; }

  @media (max-width: 640px) {
    body { padding: 12px; }
    .doc { padding: 24px 18px; }
    .head { gap: 16px; }
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

  <!-- Head -->
  <div class="head">
    <div>
      <div class="head-brand">
        <span class="mark">◆</span>
        <span>Market</span>
      </div>
      <div style="margin-top:14px">
        <span class="doc-title"><?= e($docLabel) ?></span>
        <div class="doc-code"><?= e($docCode) ?></div>
      </div>
    </div>
    <div class="head-meta">
      <div><strong>Issued:</strong>
        <?= e(date('M j, Y g:ia', strtotime($o['receipt_issued_at']))) ?>
      </div>
      <div><strong>Order date:</strong>
        <?= e(date('M j, Y g:ia', strtotime($o['created_at']))) ?>
      </div>
      <div><strong>Order code:</strong>
        <span style="font-family:ui-monospace,monospace"><?= e($o['order_code']) ?></span>
      </div>
    </div>
  </div>

  <!-- Parties -->
  <div class="parties">
    <div class="party">
      <h4>From</h4>
      <strong><?= e($o['seller_shop']) ?></strong>
      <?php if (!empty($o['seller_tin'])): ?>
        <div>TIN: <?= e($o['seller_tin']) ?></div>
      <?php endif; ?>
      <?php if (!empty($o['seller_phone'])): ?>
        <div><?= e($o['seller_phone']) ?></div>
      <?php endif; ?>
      <?php if (!empty($o['seller_address'])): ?>
        <div><?= e($o['seller_address']) ?></div>
      <?php endif; ?>
    </div>
    <div class="party">
      <h4>Bill to</h4>
      <strong><?= e($o['buyer_name']) ?></strong>
      <div><?= e($o['buyer_email']) ?></div>
      <?php if (!empty($o['buyer_phone'])): ?>
        <div><?= e($o['buyer_phone']) ?></div>
      <?php endif; ?>
      <?php if (!empty($o['delivery_address'])): ?>
        <div style="margin-top:6px"><?= nl2br(e($o['delivery_address'])) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Line item -->
  <table>
    <thead>
      <tr>
        <th>Item</th>
        <th class="num">Qty</th>
        <th class="num">Unit</th>
        <th class="num">Line</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>
          <strong><?= e($o['product_title']) ?></strong>
        </td>
        <td class="num"><?= (int)$o['quantity'] ?></td>
        <td class="num"><?= number_format((float)$o['unit_price'], 2) ?></td>
        <td class="num"><?= number_format((float)$o['subtotal'], 2) ?></td>
      </tr>
    </tbody>
  </table>

  <!-- Totals -->
  <div class="totals">
    <div class="row">
      <span>Subtotal</span>
      <span><?= number_format((float)$o['subtotal'], 2) ?></span>
    </div>
    <?php if ((float)$o['discount_amount'] > 0): ?>
      <div class="row">
        <span>Discount (<?= number_format((float)$o['discount_percent'], 2) ?>%)</span>
        <span>− <?= number_format((float)$o['discount_amount'], 2) ?></span>
      </div>
    <?php endif; ?>
    <?php if ((float)$o['tax_amount'] > 0): ?>
      <div class="row">
        <span>Tax (<?= number_format((float)$o['tax_percent'], 2) ?>%)</span>
        <span>+ <?= number_format((float)$o['tax_amount'], 2) ?></span>
      </div>
    <?php endif; ?>
    <div class="row total">
      <span><?= $isPaid ? 'Paid' : 'Amount due' ?></span>
      <strong><?= number_format((float)$o['total_amount'], 2) ?></strong>
    </div>
  </div>

  <!-- Payment status block -->
  <div class="payment-block">
    <?php if ($isPaid): ?>
      <strong>✅ Paid on <?= e(date('M j, Y g:ia', strtotime($o['paid_at'] ?: $o['created_at']))) ?></strong>
      <?php if (!empty($o['payment_method'])): ?>
        Via <?= e(ucfirst(str_replace('_', ' ', $o['payment_method']))) ?>
      <?php endif; ?>
      <?php if (!empty($o['payment_reference'])): ?>
        · Reference: <span style="font-family:ui-monospace,monospace"><?= e($o['payment_reference']) ?></span>
      <?php endif; ?>
      <?php if (!empty($o['payment_code'])): ?>
        · Payment code: <span style="font-family:ui-monospace,monospace"><?= e($o['payment_code']) ?></span>
      <?php endif; ?>
    <?php else: ?>
      <strong>⏳ Payment not yet received</strong>
      This is an invoice. Amount due:
      <strong style="display:inline"><?= number_format((float)$o['total_amount'], 2) ?></strong>.
      Please arrange payment with the seller.
    <?php endif; ?>
  </div>

  <?php if (!empty($o['notes'])): ?>
    <div style="margin-top:20px;font-size:.88rem;color:#475569">
      <strong>Notes:</strong> <?= nl2br(e($o['notes'])) ?>
    </div>
  <?php endif; ?>

  <div class="footer-note">
    Thank you for using Market.<br>
    This is a computer-generated document — no signature required.
  </div>
</div>

</body>
</html>