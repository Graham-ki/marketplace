<?php
require_once __DIR__ . '/../db.php';

$__user      = current_user();
$__cartCount = cart_count();
$__q         = $_GET['q'] ?? '';
$__pageTitle = $__pageTitle ?? 'Market';

function cart_count(): int {
    $key = current_user() ? 'u_' . current_user()['id'] : session_id();
    $s = db()->prepare("SELECT COALESCE(SUM(quantity),0) FROM cart_items WHERE cart_key=?");
    $s->execute([$key]);
    return (int)$s->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($__pageTitle) ?> · Market</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/app.css">
</head>
<body>

<header class="topbar">
  <div class="container topbar-inner">

    <!-- Brand -->
    <a href="index.php" class="brand" aria-label="Market home">
      <span class="brand-mark">◆</span>
      <span class="brand-text">Market</span>
    </a>

    <!-- Desktop / tablet search -->
    <form class="searchbar" action="index.php" method="get" role="search">
      <span class="searchbar-icon" aria-hidden="true">🔍</span>
      <input type="search" name="q"
             placeholder="Search items or shops…"
             value="<?= e($__q) ?>" autocomplete="off"
             aria-label="Search items or shops">
      <div id="searchResults" class="search-dropdown" hidden></div>
    </form>

    <!-- Mobile search toggle -->
    <button type="button" class="mobile-search-toggle"
            aria-label="Open search" data-open-mobile-search>
      🔍
    </button>

    <!-- Nav -->
    <nav class="topbar-nav">

      <a href="cart.php" class="nav-link cart-btn" aria-label="Cart">
        <span class="nav-icon" aria-hidden="true">🛒</span>
        <span class="nav-label">Cart</span>
        <span class="cart-count"><?= (int)$__cartCount ?></span>
      </a>

      <?php if (!$__user): ?>

        <a href="login.php" class="nav-link" aria-label="Log in">
          <span class="nav-icon" aria-hidden="true">🔑</span>
          <span class="nav-label">Log in</span>
        </a>

        <a href="sell.php" class="nav-link nav-link-accent" aria-label="Start selling">
          <span class="nav-icon" aria-hidden="true">🏷️</span>
          <span class="nav-label">Start selling</span>
        </a>

      <?php else: ?>

        <?php if (($__user['role'] ?? '') === 'admin'): ?>
          <a href="admin/index.php" class="nav-link nav-link-admin" aria-label="Admin panel">
            <span class="nav-icon" aria-hidden="true">🛡️</span>
            <span class="nav-label">Admin</span>
          </a>
        <?php endif; ?>

        <a href="dashboard.php" class="nav-link" aria-label="Dashboard">
          <span class="nav-icon" aria-hidden="true">📊</span>
          <span class="nav-label">Dashboard</span>
        </a>

        <a href="logout.php" class="nav-link nav-link-ghost nav-link-danger" aria-label="Log out">
          <span class="nav-icon" aria-hidden="true">⛔</span>
          <span class="nav-label">Log out</span>
        </a>

      <?php endif; ?>
    </nav>
  </div>

  <!-- Mobile search overlay -->
  <div class="mobile-search" hidden id="mobileSearch">
    <form action="index.php" method="get" role="search" class="mobile-search-form">
      <span class="searchbar-icon" aria-hidden="true">🔍</span>
      <input type="search" name="q" placeholder="Search items or shops…"
             value="<?= e($__q) ?>" autocomplete="off" autofocus>
      <button type="button" class="mobile-search-close"
              aria-label="Close search" data-close-mobile-search>×</button>
    </form>
  </div>
</header>

<main class="page">