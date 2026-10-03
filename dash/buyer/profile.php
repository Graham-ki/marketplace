<?php
require_once __DIR__ . '/../_helpers.php';

$u = current_user();
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        flash('dash_err', 'Session expired.');
    } else {
        db()->prepare("UPDATE users SET full_name=?, phone=? WHERE id=?")
            ->execute([
                trim($_POST['full_name'] ?? ''),
                trim($_POST['phone'] ?? ''),
                $u['id'],
            ]);
        $_SESSION['name'] = trim($_POST['full_name'] ?? $u['name']);
        $saved = true;
    }
}

$stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$u['id']]);
$me = $stmt->fetch();

dash_header('My profile', 'Update your name and contact number');
?>

<?php if ($saved): ?>
  <div class="alert alert-success">Profile updated.</div>
<?php endif; ?>

<form method="post" class="settings-form">
  <?= csrf_field() ?>

  <section class="settings-block">
    <h3>Personal details</h3>
    <label class="field"><span>Full name</span>
      <input type="text" name="full_name" value="<?= e($me['full_name']) ?>" required>
    </label>
    <label class="field"><span>Phone</span>
      <input type="text" name="phone" value="<?= e($me['phone'] ?? '') ?>">
    </label>
    <label class="field"><span>Email <em>(read-only)</em></span>
      <input type="email" value="<?= e($me['email']) ?>" disabled>
    </label>
  </section>

  <button class="btn btn-accent btn-lg">Save profile</button>
</form>