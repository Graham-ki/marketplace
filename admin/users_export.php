<?php
require __DIR__ . '/../db.php';

$admin = current_user();
if (!$admin || $admin['role'] !== 'admin') {
    http_response_code(403); exit('Forbidden');
}

$idsCsv = trim($_GET['ids'] ?? '');
$ids = array_filter(array_map('intval', explode(',', $idsCsv)));
$ids = array_slice($ids, 0, 500);

if (!$ids) { http_response_code(400); exit('No IDs'); }

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = db()->prepare("
    SELECT id, full_name, email, phone, role, is_active, created_at,
           failed_login_attempts, locked_until
    FROM users
    WHERE id IN ($placeholders)
    ORDER BY created_at DESC
");
$stmt->execute($ids);
$rows = $stmt->fetchAll();

$filename = 'users-export-' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, ['ID','Full Name','Email','Phone','Role','Active','Failed Logins','Locked Until','Created At']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['id'], $r['full_name'], $r['email'], $r['phone'] ?? '',
        $r['role'], $r['is_active'] ? 'yes' : 'no',
        $r['failed_login_attempts'],
        $r['locked_until'] ?? '',
        $r['created_at'],
    ]);
}
fclose($out);