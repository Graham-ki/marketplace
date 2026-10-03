<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/core/Session.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/helpers/Security.php';

Session::start();

$role = $_GET['role'] ?? 'seller';
if (!in_array($role, ['seller','buyer'], true)) $role = 'seller';

// Persistent draft token
if (empty($_SESSION['draft_token'])) {
    $_SESSION['draft_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['draft_token'];

$pdo = Database::getInstance()->pdo();

// Rebuild existing draft into the wizard (nice UX on refresh)
$stmt = $pdo->prepare("SELECT payload FROM draft_sessions WHERE session_token = ?");
$stmt->execute([$token]);
$row = $stmt->fetch();
$savedPayload = $row ? json_decode($row['payload'], true) : null;

if ($role === 'seller') {
    $categories = $pdo->query("SELECT id,name,icon FROM categories ORDER BY name")->fetchAll();
    require __DIR__ . '/../app/views/onboarding/seller.php';
} else {
    $products = $pdo->query("
        SELECT p.id,p.title,p.price,p.cover_image
        FROM products p
        WHERE p.status='active'
        ORDER BY p.created_at DESC
        LIMIT 12
    ")->fetchAll();
    require __DIR__ . '/../app/views/onboarding/buyer.php';
}