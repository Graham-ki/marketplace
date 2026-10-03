<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/core/Session.php';
require_once __DIR__ . '/../app/helpers/Security.php';
Session::start();

if (Session::has('user_id')) { header('Location: /dashboard.php'); exit; }

$pageTitle = 'Log in';
$extraCss  = ['/css/onboarding.css'];
require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="wizard">
  <div class="commit-hero">
    <div class="commit-icon">👋</div>
    <h2>Welcome back.</h2>
    <p class="step-sub">Log in to manage your listings and orders.</p>

    <?php if ($err = Session::flash('error')): ?>
      <div class="alert alert-error"><?= Security::sanitize($err) ?></div>
    <?php endif; ?>

    <form method="POST" action="/login_handler.php" class="signup-form">
      <?= Security::csrfField() ?>
      <label class="field">
        <span>Email</span>
        <input type="email" name="email" required autofocus>
      </label>
      <label class="field">
        <span>Password</span>
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="btn btn-accent btn-block btn-lg">
        Log in <span class="arrow">→</span>
      </button>
      <p class="fine-print">
        New here? <a href="/">Start by listing something</a> — no account needed.
      </p>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>