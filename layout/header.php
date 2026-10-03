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

    <a href="index.php" class="brand">
      <span class="brand-mark">◆</span>
      <span>Market</span>
    </a>

   <form class="searchbar" action="index.php" method="get" role="search">
  <span class="searchbar-icon">🔍</span>
  <input type="search" name="q"
         placeholder="Search items or shops…"
         value="<?= e($__q) ?>" autocomplete="off">
  <div id="searchResults" class="search-dropdown" hidden></div>
</form>

    <nav class="topbar-nav">
      <a href="cart.php" class="cart-btn" aria-label="Cart">
        🛒 <span class="cart-count"><?= $__cartCount ?></span>
      </a>

      <?php if (!$__user): ?>
          <a href="login.php">Log in</a>
          <a href="sell.php" class="btn btn-accent btn-sm">Start selling</a>
      <?php else: ?>
          <a href="dashboard.php">Dashboard</a>
          <span class="who">Hi, <?= e($__user['name']) ?></span>
          <a href="logout.php" class="btn btn-ghost btn-sm">Log out</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="page">