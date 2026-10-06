<?php
require __DIR__ . '/../db.php';

header('Content-Type: application/json');

$admin = current_user();
if (!$admin || $admin['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Forbidden']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok($_POST['csrf'] ?? null)) {
    http_response_code(419);
    echo json_encode(['ok'=>false,'error'=>'CSRF failed']);
    exit;
}

$targetId = (int)($_POST['user_id'] ?? 0);
$action   = $_POST['action'] ?? '';
$redirect = $_POST['redirect'] ?? 'users.php';

if (!$targetId || !$action) {
    echo json_encode(['ok'=>false,'error'=>'Missing parameters']); exit;
}
if ($targetId === (int)$admin['id']) {
    echo json_encode(['ok'=>false,'error'=>'You cannot modify your own account here']); exit;
}

$pdo = db();

$s = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
$s->execute([$targetId]);
$target = $s->fetch();
if (!$target) {
    echo json_encode(['ok'=>false,'error'=>'User not found']); exit;
}

$log = $pdo->prepare("
    INSERT INTO admin_log (admin_id, action, target_user_id, details)
    VALUES (?, ?, ?, ?)
");

$isAjax = isset($_POST['action']) &&
          in_array($action, ['delete'], true);

switch ($action) {

    case 'activate':
        $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$targetId]);
        $log->execute([$admin['id'], 'activate_user', $targetId, null]);
        break;

    case 'deactivate':
        $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$targetId]);
        $log->execute([$admin['id'], 'deactivate_user', $targetId, null]);
        break;

    case 'unlock':
        $pdo->prepare("
            UPDATE users
            SET failed_login_attempts = 0, locked_until = NULL
            WHERE id = ?
        ")->execute([$targetId]);
        $log->execute([$admin['id'], 'unlock_user', $targetId, null]);
        break;

    case 'delete':
        // Cascade is enforced by FKs on products/orders/payments.
        // admin_log rows referencing this user are also cascaded.
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);
        try {
            $log->execute([$admin['id'], 'delete_user', null, "Deleted user id={$targetId}"]);
        } catch (Throwable $e) { /* ignore if FK blocks */ }

        echo json_encode(['ok'=>true, 'mode'=>'deleted']);
        exit;

    default:
        echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;
}

// For classic form submits (activate/deactivate/unlock), redirect
if (!$isAjax) {
    header('Location: ' . $redirect);
    exit;
}

echo json_encode(['ok'=>true]);