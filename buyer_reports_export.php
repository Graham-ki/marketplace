<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'buyer') { http_response_code(403); exit('Buyers only'); }

$format = $_GET['format'] ?? 'csv';
$days   = max(7, min(365, (int)($_GET['days'] ?? 90)));

/* ─── KPI ─── */
$kpi = db()->prepare("
    SELECT COUNT(*) AS orders_count,
           COALESCE(SUM(total_amount),0) AS spent,
           COALESCE(AVG(total_amount),0) AS avg_order,
           COALESCE(SUM(quantity),0)     AS items_bought
    FROM orders
    WHERE buyer_id = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND status != 'cancelled'
");
$kpi->execute([$u['id'], $days]);
$kpi = $kpi->fetch();

/* ─── Top sellers ─── */
$sellers = db()->prepare("
    SELECT COALESCE(NULLIF(sp.business_name,''), s.full_name) AS shop_name,
           COUNT(o.id)         AS orders_count,
           SUM(o.total_amount) AS spent
    FROM orders o
    JOIN users s ON s.id = o.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = s.id
    WHERE o.buyer_id = ?
      AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND o.status != 'cancelled'
    GROUP BY s.id
    ORDER BY spent DESC
    LIMIT 20
");
$sellers->execute([$u['id'], $days]);
$topSellers = $sellers->fetchAll();

/* ─── Top items ─── */
$items = db()->prepare("
    SELECT p.title, SUM(o.quantity) AS qty, SUM(o.total_amount) AS spent
    FROM orders o
    JOIN products p ON p.id = o.product_id
    WHERE o.buyer_id = ?
      AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND o.status != 'cancelled'
    GROUP BY p.id
    ORDER BY qty DESC
    LIMIT 20
");
$items->execute([$u['id'], $days]);
$topItems = $items->fetchAll();

/* ─── Top categories ─── */
$categories = db()->prepare("
    SELECT c.name AS cat_name,
           SUM(o.total_amount) AS spent
    FROM orders o
    JOIN products p ON p.id = o.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE o.buyer_id = ?
      AND o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND o.status != 'cancelled'
      AND c.id IS NOT NULL
    GROUP BY c.id
    ORDER BY spent DESC
");
$categories->execute([$u['id'], $days]);
$topCategories = $categories->fetchAll();

/* ─── Monthly ─── */
$monthly = db()->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS m,
           SUM(total_amount) AS spent,
           COUNT(*)          AS orders
    FROM orders
    WHERE buyer_id = ?
      AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
      AND status != 'cancelled'
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY m ASC
");
$monthly->execute([$u['id']]);
$monthlyRows = $monthly->fetchAll();

$periodLabel = "Last $days days";

/* ═══════════════════════════════════════════════
   CSV
   ═══════════════════════════════════════════════ */
if ($format === 'csv') {
    $filename = 'my-report-' . date('Y-m-d_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, ['Buyer Report', $periodLabel, 'Generated ' . date('M j, Y g:ia')]);
    fputcsv($out, []);

    fputcsv($out, ['SUMMARY']);
    fputcsv($out, ['Total spent',    number_format((float)$kpi['spent'], 2, '.', '')]);
    fputcsv($out, ['Orders',         (int)$kpi['orders_count']]);
    fputcsv($out, ['Avg order',      number_format((float)$kpi['avg_order'], 2, '.', '')]);
    fputcsv($out, ['Items bought',   (int)$kpi['items_bought']]);
    fputcsv($out, []);

    fputcsv($out, ['TOP SELLERS']);
    fputcsv($out, ['Seller', 'Orders', 'Spent']);
    foreach ($topSellers as $s) {
        fputcsv($out, [
            $s['shop_name'],
            (int)$s['orders_count'],
            number_format((float)$s['spent'], 2, '.', ''),
        ]);
    }
    fputcsv($out, []);

    fputcsv($out, ['TOP ITEMS']);
    fputcsv($out, ['Item', 'Qty', 'Spent']);
    foreach ($topItems as $r) {
        fputcsv($out, [
            $r['title'],
            (int)$r['qty'],
            number_format((float)$r['spent'], 2, '.', ''),
        ]);
    }
    fputcsv($out, []);

    fputcsv($out, ['TOP CATEGORIES']);
    fputcsv($out, ['Category', 'Spent']);
    foreach ($topCategories as $c) {
        fputcsv($out, [
            $c['cat_name'] ?? 'Uncategorised',
            number_format((float)$c['spent'], 2, '.', ''),
        ]);
    }
    fputcsv($out, []);

    fputcsv($out, ['MONTHLY SPEND (6 months)']);
    fputcsv($out, ['Month', 'Spent', 'Orders']);
    foreach ($monthlyRows as $r) {
        fputcsv($out, [
            $r['m'],
            number_format((float)$r['spent'], 2, '.', ''),
            (int)$r['orders'],
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
      <title>My report — <?= date('M j, Y') ?></title>
      <style>
        body{font-family:system-ui,sans-serif;color:#111;padding:32px;max-width:900px;margin:0 auto}
        h1{font-size:1.4rem;margin-bottom:4px}
        h2{font-size:1.05rem;margin:28px 0 10px;color:#166534;letter-spacing:-.01em}
        p.sub{color:#666;font-size:.9rem;margin-bottom:20px}
        table{width:100%;border-collapse:collapse;font-size:.85rem;margin-bottom:16px}
        th,td{padding:8px 10px;border-bottom:1px solid #e5e7eb;text-align:left}
        th{background:#f3f4f6;font-weight:700;text-transform:uppercase;font-size:.7rem;letter-spacing:.04em}
        td.num,th.num{text-align:right}
        .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
        .kpi{padding:14px;border:1px solid #e5e7eb;border-radius:12px;background:#f9fafb}
        .kpi span{display:block;color:#666;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em}
        .kpi strong{display:block;font-size:1.4rem;color:#166534;margin-top:4px}
        @media print{body{padding:0}button{display:none}}
      </style>
    </head>
    <body>
      <button onclick="window.print()"
              style="padding:10px 18px;margin-bottom:16px;cursor:pointer">
        🖨️ Print / Save as PDF
      </button>

      <h1>Buyer Report</h1>
      <p class="sub">
        <?= e($periodLabel) ?> · Generated <?= date('M j, Y g:ia') ?>
      </p>

      <div class="kpis">
        <div class="kpi"><span>Total spent</span><strong><?= number_format((float)$kpi['spent'], 2) ?></strong></div>
        <div class="kpi"><span>Orders</span><strong><?= (int)$kpi['orders_count'] ?></strong></div>
        <div class="kpi"><span>Avg order</span><strong><?= number_format((float)$kpi['avg_order'], 2) ?></strong></div>
        <div class="kpi"><span>Items bought</span><strong><?= (int)$kpi['items_bought'] ?></strong></div>
      </div>

      <h2>Top sellers</h2>
      <table>
        <thead><tr><th>Seller</th><th class="num">Orders</th><th class="num">Spent</th></tr></thead>
        <tbody>
          <?php foreach ($topSellers as $s): ?>
            <tr>
              <td><?= e($s['shop_name']) ?></td>
              <td class="num"><?= (int)$s['orders_count'] ?></td>
              <td class="num"><?= number_format((float)$s['spent'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <h2>Top items</h2>
      <table>
        <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Spent</th></tr></thead>
        <tbody>
          <?php foreach ($topItems as $r): ?>
            <tr>
              <td><?= e($r['title']) ?></td>
              <td class="num"><?= (int)$r['qty'] ?></td>
              <td class="num"><?= number_format((float)$r['spent'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <h2>Top categories</h2>
      <table>
        <thead><tr><th>Category</th><th class="num">Spent</th></tr></thead>
        <tbody>
          <?php foreach ($topCategories as $c): ?>
            <tr>
              <td><?= e($c['cat_name'] ?? 'Uncategorised') ?></td>
              <td class="num"><?= number_format((float)$c['spent'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <h2>Monthly spend</h2>
      <table>
        <thead><tr><th>Month</th><th class="num">Spent</th><th class="num">Orders</th></tr></thead>
        <tbody>
          <?php foreach ($monthlyRows as $r): ?>
            <tr>
              <td><?= e($r['m']) ?></td>
              <td class="num"><?= number_format((float)$r['spent'], 2) ?></td>
              <td class="num"><?= (int)$r['orders'] ?></td>
            </tr>
          <?php endforeach; ?>
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