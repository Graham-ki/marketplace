<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../dash/_helpers.php';

$u = current_user();
if (!$u || $u['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$__pageTitle = $__pageTitle ?? 'Admin';

if (!isset($__activeNav)) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $__activeNav = match ($script) {
        'index.php'    => 'home',
        'users.php'    => 'users',
        'user.php'     => 'users',
        'products.php' => 'products',
        'orders.php'   => 'orders',
        default        => 'home',
    };
}

$navItems = [
    'home'     => ['label' => 'Overview',  'icon' => '📊', 'href' => 'index.php'],
    'users'    => ['label' => 'Users',     'icon' => '👥', 'href' => 'users.php'],
    'products' => ['label' => 'Products',  'icon' => '📦', 'href' => 'products.php'],
    'orders'   => ['label' => 'Orders',    'icon' => '🧾', 'href' => 'orders.php'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($__pageTitle) ?> · Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/base.css">
<link rel="stylesheet" href="../css/app.css">
</head>
<body>

<header class="topbar">
  <div class="container topbar-inner">
    <a href="index.php" class="brand">
      <span class="brand-mark">◆</span>
      <span class="brand-text">Market · Admin</span>
    </a>
    <nav class="topbar-nav">
      <span class="who"><?= e($u['name']) ?></span>
      <a href="../index.php" class="nav-link">
        <span class="nav-icon" aria-hidden="true">↩</span>
        <span class="nav-label">Shop</span>
      </a>
      <a href="../logout.php" class="nav-link nav-link-ghost nav-link-danger">
        <span class="nav-icon" aria-hidden="true">⛔</span>
        <span class="nav-label">Log out</span>
      </a>
    </nav>
  </div>
</header>

<div class="dash-shell">

  <aside class="sidebar">
    <div class="sidebar-user">
      <div class="avatar">A</div>
      <div>
        <strong>Admin</strong>
        <small><?= e($u['name']) ?></small>
      </div>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($navItems as $key => $item): ?>
        <a href="<?= e($item['href']) ?>"
           class="sidebar-link <?= $__activeNav === $key ? 'active' : '' ?>"
           <?= $__activeNav === $key ? 'aria-current="page"' : '' ?>>
          <span class="sidebar-icon"><?= $item['icon'] ?></span>
          <span><?= e($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
  </aside>

  <section class="dash-content">
    <?php if ($msg = flash('admin_msg')): ?>
      <div class="alert alert-success is-dismissable" role="alert">
        <span><?= e($msg) ?></span>
        <button type="button" class="alert-close" aria-label="Dismiss">×</button>
      </div>
    <?php endif; ?>
    <?php if ($err = flash('admin_err')): ?>
      <div class="alert alert-error is-dismissable" role="alert">
        <span><?= e($err) ?></span>
        <button type="button" class="alert-close" aria-label="Dismiss">×</button>
      </div>
    <?php endif; ?>