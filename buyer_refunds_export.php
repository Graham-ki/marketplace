<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'buyer') { http_response_code(403); exit('Buyers only'); }

$format = $_GET['format'] ?? 'csv';
$range  = $_GET['range']  ?? '30';
$status = $_GET['status'] ?? '';
$from   = $_GET['from']   ?? '';
$to     = $_GET['to']     ?? '';

// ─── WHERE ───
$where = ["r.requested_by = ?"];
$args  = [$u['id']];

if ($range === 'today') {
    $where[] = "DATE(r.created_at) = CURDATE()";
} elseif (ctype_digit((string)$range)) {
    $where[] = "r.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $args[]  = (int)$range;
} elseif ($range === 'custom' && $from && $to) {
    $where[] = "r.created_at BETWEEN ? AND ?";
    $args[]  = $from . ' 00:00:00';
    $args[]  = $to   . ' 23:59:59';
}

if (in_array($status, ['requested','approved','rejected','refunded'], true)) {
    $where[] = "r.status = ?";
    $args[]  = $status;
}

// ─── Query ───
$sql = "
    SELECT r.refund_code, r.amount, r.reason, r.status,
           r.decided_at, r.created_at,
           o.order_code, o.total_amount AS order_total,
           s.full_name AS seller_name,
           COALESCE(NULLIF(sp.business_name,''), s.full_name) AS seller_shop
    FROM refunds r
    JOIN orders o ON o.id = r.order_id
    JOIN users  s ON s.id = r.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = s.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY r.created_at DESC
";
$stmt = db()->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

/* ═══════════════════════════════════════════════
   CSV
   ═══════════════════════════════════════════════ */
if ($format === 'csv') {
    $filename = 'my-refunds-' . date('Y-m-d_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, [
        'Refund Code','Order Code','Seller','Amount','Reason',
        'Status','Decided At','Requested At',
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['refund_code'],
            $r['order_code'],
            $r['seller_shop'],
            number_format((float)$r['amount'], 2, '.', ''),
            $r['reason'] ?? '',
            ucfirst($r['status']),
            $r['decided_at'] ?? '',
            $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

/* ═══════════════════════════════════════════════
   PDF (printable page)
   ═══════════════════════════════════════════════ */
if ($format === 'pdf') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>My refunds — <?= date('M j, Y') ?></title>
      <style>
        body{font-family:system-ui,sans-serif;color:#111;padding:32px;max-width:1100px;margin:0 auto}
        h1{font-size:1.4rem;margin-bottom:4px}
        p.sub{color:#666;font-size:.9rem;margin-bottom:20px}
        table{width:100%;border-collapse:collapse;font-size:.82rem}
        th,td{padding:8px 10px;border-bottom:1px solid #e5e7eb;text-align:left}
        th{background:#f3f4f6;font-weight:700;text-transform:uppercase;font-size:.7rem;letter-spacing:.04em}
        td.num,th.num{text-align:right}
        tr.total td{font-weight:700;border-top:2px solid #111}
        @media print{body{padding:0}button{display:none}}
      </style>
    </head>
    <body>
      <button onclick="window.print()"
              style="padding:10px 18px;margin-bottom:16px;cursor:pointer">
        🖨️ Print / Save as PDF
      </button>

      <h1>My Refund Requests</h1>
      <p class="sub">
        Generated <?= date('M j, Y g:ia') ?> ·
        <?= count($rows) ?> record(s) ·
        Range: <?= e($range) ?>
        <?php if ($status): ?> · Status: <?= e($status) ?><?php endif; ?>
      </p>

      <table>
        <thead>
          <tr>
            <th>Code</th><th>Order</th><th>Seller</th>
            <th class="num">Amount</th><th>Reason</th>
            <th>Status</th><th>Requested</th>
          </tr>
        </thead>
        <tbody>
        <?php
          $sum = 0;
          foreach ($rows as $r):
            if ($r['status'] === 'refunded') $sum += (float)$r['amount'];
        ?>
          <tr>
            <td><?= e($r['refund_code']) ?></td>
            <td><?= e($r['order_code']) ?></td>
            <td><?= e($r['seller_shop']) ?></td>
            <td class="num"><?= number_format((float)$r['amount'], 2) ?></td>
            <td><?= e($r['reason'] ?: '—') ?></td>
            <td><?= e(ucfirst($r['status'])) ?></td>
            <td><?= e(date('M j, Y', strtotime($r['created_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
          <tr class="total">
            <td colspan="3">Total refunded</td>
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