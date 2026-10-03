<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/core/Session.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/helpers/Security.php';
Session::start();

if (!Security::verifyCsrf($_POST['csrf'] ?? null)) {
    Session::flash('error', 'Session expired. Try again.');
    header('Location: /login.php'); exit;
}

$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$pwd   = $_POST['password'] ?? '';

$pdo  = Database::getInstance()->pdo();
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !Security::verifyPassword($pwd, $user['password_hash'])) {
    Session::flash('error', 'Invalid email or password.');
    header('Location: /login.php'); exit;
}

session_regenerate_id(true);
Session::set('user_id', (int)$user['id']);
Session::set('role',    $user['role']);
Session::set('name',    $user['full_name']);

header('Location: /dashboard.php'); exit;