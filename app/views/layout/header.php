<?php
require_once __DIR__ . '/../../core/Session.php';
require_once __DIR__ . '/../../helpers/Security.php';
Session::start();
$user = Session::user();
$pageTitle = $pageTitle ?? 'Marketplace';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Security::sanitize($pageTitle) ?> · Market</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<!-- Shared styles — always root-relative -->
<link rel="stylesheet" href="../public/css/base.css">

<!-- Page-specific styles (landing.css, onboarding.css, …) -->
<?php if (!empty($extraCss)) foreach ((array)$extraCss as $css): ?>
<link rel="stylesheet" href="<?= Security::sanitize($css) ?>">
<?php endforeach; ?>
</head>
<body>

<header class="topbar">
  <a href="/" class="brand">
    <span class="brand-mark">◆</span>
    <span>Market</span>
  </a>

  <nav class="topbar-nav">
    <?php if ($user): ?>
      <a href="/dashboard.php">Dashboard</a>
      <span class="who">Hi, <?= Security::sanitize($user['name']) ?></span>
      <a href="/logout.php" class="btn btn-ghost btn-sm">Log out</a>
    <?php else: ?>
      <a href="/login.php">Log in</a>
      <a href="/onboarding.php?role=seller" class="btn btn-accent btn-sm">
        Start selling
      </a>
    <?php endif; ?>
  </nav>
</header>

<main class="page">