<?php
require __DIR__ . '/db.php';

// Already logged in → dashboard
if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

/* ═══════════════════════════════════════════════════
   LOCKOUT CONFIG
   ═══════════════════════════════════════════════════ */
const MAX_ATTEMPTS  = 3;      // failures before lock
const LOCK_SECONDS  = 900;    // lock duration (15 minutes)

$error     = '';
$errorType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'Session expired. Try again.';
    } else {
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $pwd   = $_POST['password'] ?? '';
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $pdo   = db();

        if (!$email) {
            $error = 'Please enter a valid email.';
        } else {
            // Fetch user by email only
            $stmt = $pdo->prepare("
                SELECT id, full_name, role, email, password_hash, is_active,
                       failed_login_attempts, locked_until
                FROM users
                WHERE email = ?
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            /* ────────────────────────────────────────
               Case A: account currently locked
               ──────────────────────────────────────── */
            if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
                $remaining = strtotime($user['locked_until']) - time();
                $mins      = ceil($remaining / 60);
                $error     = "Too many failed attempts. Try again in {$mins} minute" . ($mins === 1 ? '' : 's') . ".";
                $errorType = 'warn';

                log_attempt($pdo, $email, $ip, false, 'locked');

            /* ────────────────────────────────────────
               Case B: no such user OR wrong password
               ──────────────────────────────────────── */
            } elseif (!$user || !password_verify($pwd, $user['password_hash'])) {
                if ($user) {
                    // Increment failures and possibly lock
                    $attempts = (int)$user['failed_login_attempts'] + 1;

                    if ($attempts >= MAX_ATTEMPTS) {
                        $lockedUntil = date('Y-m-d H:i:s', time() + LOCK_SECONDS);
                        $pdo->prepare("
                            UPDATE users
                            SET failed_login_attempts = ?, locked_until = ?
                            WHERE id = ?
                        ")->execute([$attempts, $lockedUntil, (int)$user['id']]);

                        $mins      = ceil(LOCK_SECONDS / 60);
                        $error     = "Too many failed attempts. Your account is locked for {$mins} minutes.";
                        $errorType = 'warn';

                        log_attempt($pdo, $email, $ip, false, 'locked');
                    } else {
                        $remaining = MAX_ATTEMPTS - $attempts;
                        $pdo->prepare("
                            UPDATE users
                            SET failed_login_attempts = ?
                            WHERE id = ?
                        ")->execute([$attempts, (int)$user['id']]);

                        $error = "Invalid email or password. "
                               . "{$remaining} attempt" . ($remaining === 1 ? '' : 's') . " remaining "
                               . "before your account is temporarily locked.";

                        log_attempt($pdo, $email, $ip, false, 'wrong_password');
                    }
                } else {
                    // Unknown email — generic message, no revealing
                    $error = 'Invalid email or password.';
                    log_attempt($pdo, $email, $ip, false, 'unknown_email');
                }

            /* ────────────────────────────────────────
               Case C: correct password but inactive
               ──────────────────────────────────────── */
            } elseif ((int)$user['is_active'] === 0) {
                $error     = 'Your account is inactive.';
                $errorType = 'warn';
                log_attempt($pdo, $email, $ip, false, 'inactive');

            /* ────────────────────────────────────────
               Case D: success
               ──────────────────────────────────────── */
            } else {
                // Reset lockout counters
                $pdo->prepare("
                    UPDATE users
                    SET failed_login_attempts = 0, locked_until = NULL
                    WHERE id = ?
                ")->execute([(int)$user['id']]);

                log_attempt($pdo, $email, $ip, true, 'success');

                login_user($user);
                header('Location: dashboard.php');
                exit;
            }
        }
    }
}

/* ─── Helper: record the attempt ─── */
function log_attempt(PDO $pdo, string $email, string $ip, bool $success, string $reason): void {
    // Optional: skip if the table doesn't exist so login still works
    try {
        $pdo->prepare("
            INSERT INTO login_attempts (email, ip, success, reason)
            VALUES (?, ?, ?, ?)
        ")->execute([$email, $ip, $success ? 1 : 0, $reason]);
    } catch (Throwable $e) {
        // swallow — logging must not break login
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
<body class="auth-body">

<header class="topbar">
  <div class="container topbar-inner">
    <a href="index.php" class="brand">
      <span class="brand-mark">◆</span>
      <span>Market</span>
    </a>
    <nav class="topbar-nav">
      <a href="index.php">Back to shop</a>
    </nav>
  </div>
</header>

<main class="auth-main">
  <div class="auth-card">

    <div class="auth-head">
      <div class="auth-icon">👋</div>
      <h1>Welcome back.</h1>
      <p>Log in to manage your orders and listings.</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-<?= e($errorType) ?> is-dismissable" role="alert">
        <span>
          <?= e($error) ?>
          <?php if ($errorType === 'warn' && str_contains($error, 'inactive')): ?>
            <br>
            <small>Contact
              <a href="mailto:grahamkimbugwe1738@gmail.com">Support </a>
              to reactivate your account.
            </small>
          <?php endif; ?>
        </span>
        <button type="button" class="alert-close" aria-label="Dismiss">×</button>
      </div>
    <?php endif; ?>

    <form method="POST" class="auth-form">
      <?= csrf_field() ?>

      <label class="field">
        <span>Email</span>
        <input type="email" name="email" required autofocus
               value="<?= e($_POST['email'] ?? '') ?>"
               placeholder="you@example.com">
      </label>

      <label class="field">
        <span>Password</span>
        <input type="password" name="password" required
               placeholder="••••••••">
      </label>

      <button type="submit" class="btn btn-accent btn-block btn-lg">
        Log in →
      </button>

      <p class="auth-foot">
        New here? <a href="index.php">Browse the shop</a>
        or <a href="sell.php">list your first item</a>.
      </p>
    </form>

  </div>
</main>

</body>
</html>