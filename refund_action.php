<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
if ($u['role'] !== 'buyer') json_out(['ok'=>false,'error'=>'Buyers only'], 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'POST only']);
if (!csrf_ok($_POST['csrf'] ?? null)) json_out(['ok'=>false,'error'=>'CSRF'], 419);
if (($_POST['action'] ?? '') !== 'cancel') json_out(['ok'=>false,'error'=>'Invalid action']);

$refundId = (int)($_POST['refund_id'] ?? 0);
if (!$refundId) json_out(['ok'=>false,'error'=>'Missing refund_id']);

$pdo = db();

try {
    $pdo->beginTransaction();

    // Lock + verify ownership
    $stmt = $pdo->prepare("
        SELECT r.id, r.refund_code, r.seller_id, r.status
        FROM refunds r
        WHERE r.id = ? AND r.requested_by = ?
        FOR UPDATE
    ");
    $stmt->execute([$refundId, $u['id']]);
    $refund = $stmt->fetch();
    if (!$refund) throw new Exception('Refund request not found');

    if ($refund['status'] !== 'requested') {
        throw new Exception('Only pending refund requests can be cancelled.');
    }

    // Mark it as cancelled by deleting it (or use a status).
    // Cleaner: delete the row so history stays clean.
    $pdo->prepare("DELETE FROM refunds WHERE id = ?")->execute([$refundId]);

    // Notify the seller
    $pdo->prepare("INSERT INTO notifications (user_id,type,title,message,link)
                   VALUES (?,?,?,?,?)")
        ->execute([
            (int)$refund['seller_id'],
            'refund_status',
            'Refund request withdrawn',
            "Buyer withdrew refund request {$refund['refund_code']}.",
            'dashboard.php?tab=refunds',
        ]);

    $pdo->commit();
    json_out(['ok'=>true]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[refund_action] ' . $e->getMessage());
    json_out([
        'ok'=>false,
        'error'=> APP_ENV === 'development' ? $e->getMessage() : 'Could not cancel request.',
    ]);
}