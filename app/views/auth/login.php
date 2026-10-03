<?php require __DIR__ . '/../layout/header.php'; ?>

<div class="wizard">
  <div class="auth-box">
    <h1 class="auth-title">Welcome back 👋</h1>
    <p class="step-sub">Log in to manage your listings and orders.</p>

    <form method="POST" action="/login_handler.php" class="signup-form">
      <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>">

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