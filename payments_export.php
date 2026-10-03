<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'seller') { http_response_code(403); exit('Sellers only'); }

$format = $_GET['format'] ?? 'csv';
$range  = $_GET['range']  ?? '30';
$status = $_GET['status'] ?? '';
$from   = $_GET['from']   ?? '';
$to     = $_GET['to']     ?? '';

// ── Build WHERE clauses ──
$where  = ["o.seller_id = ?"];
$args   = [$u['id']];

if ($range === 'today') {
    $where[] = "DATE(pay.created_at) = CURDATE()";
} elseif (ctype_digit($range)) {
    $where[] = "pay.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $args[]  = (int)$range;
} elseif ($range === 'custom' && $from && $to) {
    $where[] = "pay.created_at BETWEEN ? AND ?";
    $args[]  = $from . ' 00:00:00';
    $args[]  = $to   . ' 23:59:59';
}

if (in_array($status, ['completed','pending','failed'], true)) {
    $where[] = "pay.status = ?";
    $args[]  = $status;
}

// ── Query ──
$sql = "
    SELECT pay.payment_code, pay.amount, pay.method, pay.reference,
           pay.status, pay.paid_at, pay.created_at,
           o.order_code, o.total_amount AS order_total,
           b.full_name AS buyer_name, b.email AS buyer_email
    FROM payments pay
    JOIN orders o ON o.id = pay.order_id
    JOIN users  b ON b.id = pay.user_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY pay.created_at DESC
";
$stmt = db()->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

// ── CSV output ──
if ($format === 'csv') {
    $filename = 'payments-' . date('Y-m-d_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // UTF-8 BOM so Excel opens it correctly
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Header row
    fputcsv($out, [
        'Payment Code',
        'Order Code',
        'Buyer Name',
        'Buyer Email',
        'Amount',
        'Method',
        'Reference',
        'Status',
        'Paid At',
        'Created At',
    ]);

    // Data rows
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['payment_code'],
            $r['order_code'],
            $r['buyer_name'],
            $r['buyer_email'],
            number_format((float)$r['amount'], 2, '.', ''),
            ucfirst(str_replace('_', ' ', $r['method'])),
            $r['reference'] ?? '',
            ucfirst($r['status']),
            $r['paid_at'] ?? '',
            $r['created_at'],
        ]);
    }

    fclose($out);
    exit;
}

// ── PDF: fallback — a printable HTML view ──
// Real PDF generation requires a library (dompdf, mpdf). This route
// serves a print-optimized page that the browser can "Save as PDF".
if ($format === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>Payments — <?= date('M j, Y') ?></title>
      <style>
        body{font-family:system-ui,sans-serif;color:#111;padding:32px;max-width:1100px;margin:0 auto}
        h1{font-size:1.4rem;margin-bottom:4px}
        p.sub{color:#666;font-size:.9rem;margin-bottom:20px}
        table{width:100%;border-collapse:collapse;font-size:.82rem}
        th,td{padding:8px 10px;border-bottom:1px solid #e5e7eb;text-align:left}
        th{background:#f3f4f6;font-weight:700;text-transform:uppercase;font-size:.7rem;letter-spacing:.04em}
        td.num,th.num{text-align:right}
        tr.total td{font-weight:700;border-top:2px solid #111}
        @media print{
          body{padding:0}
          button{display:none}
        }
      </style>
    </head>
    <body>
      <button onclick="window.print()"
              style="padding:10px 18px;margin-bottom:16px;cursor:pointer">
        🖨️ Print / Save as PDF
      </button>

      <h1>Payments Report</h1>
      <p class="sub">
        Generated <?= date('M j, Y g:ia') ?> ·
        <?= count($rows) ?> record(s) ·
        Range: <?= e($range) ?>
        <?php if ($status): ?> · Status: <?= e($status) ?><?php endif; ?>
      </p>

      <table>
        <thead>
          <tr>
            <th>Code</th><th>Order</th><th>Buyer</th>
            <th class="num">Amount</th><th>Method</th><th>Status</th><th>Date</th>
          </tr>
        </thead>
        <tbody>
        <?php $sum = 0; foreach ($rows as $r):
          if ($r['status']==='completed') $sum += (float)$r['amount'];
        ?>
          <tr>
            <td><?= e($r['payment_code']) ?></td>
            <td><?= e($r['order_code']) ?></td>
            <td><?= e($r['buyer_name']) ?></td>
            <td class="num"><?= number_format((float)$r['amount'], 2) ?></td>
            <td><?= e(ucfirst(str_replace('_',' ',$r['method']))) ?></td>
            <td><?= e(ucfirst($r['status'])) ?></td>
            <td><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
          <tr class="total">
            <td colspan="3">Total completed</td>
            <td class="num"><?= number_format($sum, 2) ?></td>
            <td colspan="3"></td>
          </tr>
        </tbody>
      </table>

      <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
    </body>
    </html>
    <?php
    exit;
}

http_response_code(400);
exit('Unknown format');