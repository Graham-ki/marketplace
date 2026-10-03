<?php
require __DIR__ . '/db.php';

if (current_user()) { header('Location: /dashboard.php'); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'Session expired. Try again.';
    } else {
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $pwd   = $_POST['password'] ?? '';
        $stmt  = db()->prepare("SELECT * FROM users WHERE email=? AND is_active=1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($pwd, $user['password_hash'])) {
            login_user($user);
            header('Location: dashboard.php'); exit;
        }
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in · Market</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/app.css">
</head>
<body>

<header class="topbar">
  <a href="/" class="brand"><span class="brand-mark">◆</span><span>Market</span></a>
</header>

<main class="page">
<div class="wizard">
  <div class="commit-hero">
    <div class="commit-icon">👋</div>
    <h2>Welcome back.</h2>
    <p class="step-sub">Log in to manage your listings and orders.</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="signup-form">
      <?= csrf_field() ?>
      <label class="field"><span>Email</span>
        <input type="email" name="email" required autofocus>
      </label>
      <label class="field"><span>Password</span>
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="btn btn-accent btn-block btn-lg">Log in →</button>
      <p class="fine-print">
        New here? <a href="/">Start by listing something</a> — no account needed.
      </p>
    </form>
  </div>
</div>
</main>

</body>
</html>