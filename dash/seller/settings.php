<?php
require_once __DIR__ . '/../_helpers.php';

$u = current_user();
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        flash('dash_err', 'Session expired.');
    } else {
        db()->prepare("
            INSERT INTO seller_profiles
              (user_id, business_name, tin, phone, address, tax_percent, default_discount_percent, currency)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              business_name=VALUES(business_name),
              tin=VALUES(tin),
              phone=VALUES(phone),
              address=VALUES(address),
              tax_percent=VALUES(tax_percent),
              default_discount_percent=VALUES(default_discount_percent),
              currency=VALUES(currency)
        ")->execute([
            $u['id'],
            trim($_POST['business_name'] ?? ''),
            trim($_POST['tin'] ?? ''),
            trim($_POST['phone'] ?? ''),
            trim($_POST['address'] ?? ''),
            (float)($_POST['tax_percent'] ?? 0),
            (float)($_POST['default_discount_percent'] ?? 0),
            $_POST['currency'] ?? 'USD',
        ]);
        $saved = true;
    }
}

$stmt = db()->prepare("SELECT * FROM seller_profiles WHERE user_id = ?");
$stmt->execute([$u['id']]);
$p = $stmt->fetch() ?: [];

dash_header('Business settings', 'These details appear on receipts and apply to new orders');
?>

<?php if ($saved): ?>
  <div class="alert alert-success">Settings saved.</div>
<?php endif; ?>

<form method="post" class="settings-form">
  <?= csrf_field() ?>

  <section class="settings-block">
    <h3>Business profile</h3>
    <div class="two-col">
      <label class="field"><span>Business name</span>
        <input type="text" name="business_name" value="<?= e($p['business_name'] ?? '') ?>">
      </label>
      <label class="field"><span>TIN</span>
        <input type="text" name="tin" value="<?= e($p['tin'] ?? '') ?>">
      </label>
    </div>
    <label class="field"><span>Phone</span>
      <input type="text" name="phone" value="<?= e($p['phone'] ?? '') ?>">
    </label>
    <label class="field"><span>Address</span>
      <input type="text" name="address" value="<?= e($p['address'] ?? '') ?>">
    </label>
  </section>

  <section class="settings-block">
    <h3>Checkout rules</h3>
    <div class="two-col">
      <label class="field"><span>Tax %</span>
        <input type="number" name="tax_percent" step="0.01" min="0" max="100"
               value="<?= e($p['tax_percent'] ?? '0') ?>">
      </label>
      <label class="field"><span>Default discount %</span>
        <input type="number" name="default_discount_percent" step="0.01" min="0" max="100"
               value="<?= e($p['default_discount_percent'] ?? '0') ?>">
      </label>
    </div>
    <label class="field"><span>Currency</span>
      <select name="currency">
        <?php foreach (['USD','UGX','KES','EUR','GBP'] as $c): ?>
          <option value="<?= $c ?>" <?= ($p['currency'] ?? 'USD')===$c?'selected':'' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </section>

  <button class="btn btn-accent btn-lg">Save settings</button>
</form>