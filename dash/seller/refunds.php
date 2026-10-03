<?php
require_once __DIR__ . '/../_helpers.php';

$u = current_user();
$stmt = db()->prepare("
    SELECT r.*, o.order_code, b.full_name AS buyer_name
    FROM refunds r
    JOIN orders o ON o.id = r.order_id
    JOIN users  b ON b.id = r.requested_by
    WHERE r.seller_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$u['id']]);
$rows = $stmt->fetchAll();

dash_header('Refunds', count($rows) . ' requests');
?>

<?php if (!$rows): ?>
  <div class="empty-state small"><p>No refund requests.</p></div>
<?php else: ?>
  <div class="table-card">
    <table class="data-table">
      <thead>
        <tr>
          <th>Code</th><th>Order</th><th>Buyer</th>
          <th class="num">Amount</th><th>Reason</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><code><?= e($r['refund_code']) ?></code></td>
          <td><code><?= e($r['order_code']) ?></code></td>
          <td><?= e($r['buyer_name']) ?></td>
          <td class="num"><?= money((float)$r['amount']) ?></td>
          <td><small><?= e(mb_strimwidth($r['reason'] ?? '', 0, 40, '…')) ?></small></td>
          <td><?= status_pill($r['status']) ?></td>
          <td class="row-actions">
            <?php if ($r['status'] === 'requested'): ?>
              <form method="post" action="refund_decide.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="refund_id" value="<?= (int)$r['id'] ?>">
                <button name="decision" value="approved" class="btn-link">Approve</button>
                <button name="decision" value="rejected" class="btn-link">Reject</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>