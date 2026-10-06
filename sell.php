<?php
require __DIR__ . '/db.php';

// Logged-in sellers should manage stock from the dashboard
if (current_user() && current_user()['role'] === 'seller') {
    header('Location: dashboard.php?tab=stock');
    exit;
}
// Admins go to their own panel
if (current_user() && current_user()['role'] === 'admin') {
    header('Location: admin/index.php');
    exit;
}

$cats = db()->query("SELECT id,name,icon FROM categories ORDER BY name")->fetchAll();

$__pageTitle = 'Build your catalog';
require __DIR__ . '/layout/header.php';
?>

<div class="container">
  <div class="sell-header">
    <div>
      <h1>Build your catalog</h1>
      <p class="step-sub">Add as many items as you want. Preview them all, then claim your shop.</p>
    </div>
    <button class="btn btn-primary" id="addItemBtn">+ Add item</button>
  </div>

  <div id="catalog" class="catalog-grid"></div>

  <div class="sell-footer">
    <div class="sell-footer-info">
      <strong><span id="itemCount">0</span> items ready</strong>
      <small>Preview them side by side — then publish.</small>
    </div>
    <button class="btn btn-accent btn-lg" id="publishBtn" disabled>
      <span class="hide-sm">Preview &amp; publish</span>
      <span class="show-sm">Publish</span>
      <span aria-hidden="true">→</span>
    </button>
  </div>
</div>

<!-- Hidden item template -->
<template id="itemTpl">
  <div class="catalog-item" data-id="">
    <div class="catalog-item-head">
      <span class="catalog-item-num">#<span data-num>1</span></span>
      <button type="button" class="btn-link danger" data-remove>Remove</button>
    </div>

    <label class="field"><span>Title</span>
      <input type="text" data-field="title" maxlength="120" placeholder="e.g. iPhone 13 Pro">
    </label>

    <div class="upload-zone small" data-dropzone>
      <input type="file" accept="image/jpeg,image/png,image/webp" data-field="image" hidden>
      <div class="upload-preview" data-preview></div>
      <div class="upload-hint">
        <span class="drop-icon-sm">📸</span>
        <span>Add photo</span>
      </div>
    </div>

    <div class="two-col">
      <label class="field"><span>Current price</span>
        <input type="number" min="0" step="0.01" data-field="price" placeholder="0.00">
      </label>
      <label class="field"><span>Quantity</span>
        <input type="number" min="1" value="1" data-field="quantity">
      </label>
    </div>

    <label class="field"><span>Discount % <em>(0 = no discount)</em></span>
      <input type="number" min="0" max="99" step="0.01" value="0"
             data-field="discount_percent" placeholder="0">
    </label>

    <p class="discount-preview" data-discount-preview hidden>
      Original: <s data-original-display></s> ·
      <span data-save-display></span>
    </p>

    <label class="field"><span>Category</span>
      <select data-field="category_id">
        <option value="">— choose —</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= (int)$c['id'] ?>">
            <?= e(($c['icon'] ?? '') . ' ' . $c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="field"><span>Description <em>(optional)</em></span>
      <textarea rows="2" data-field="description"></textarea>
    </label>

    <label class="field"><span>Location</span>
      <input type="text" data-field="location" placeholder="City">
    </label>
  </div>
</template>

<!-- Publish modal -->
<div id="publishModal" class="modal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel">
    <button class="modal-close" data-close-modal>×</button>

    <div class="modal-step" data-step="1">
      <span class="step-tag success">🎉 Your catalog is ready</span>
      <h2>Here's your shop preview.</h2>
      <p class="step-sub">Looks good? Create an account to publish.</p>
      <div id="previewGrid" class="product-grid"></div>
      <div class="modal-actions">
        <button class="btn btn-ghost" data-close-modal>← Keep editing</button>
        <button class="btn btn-accent" data-goto-step="2">I love it — continue →</button>
      </div>
    </div>

    <div class="modal-step" data-step="2" hidden>
      <div class="commit-hero">
        <div class="commit-icon">🔒</div>
        <h2>Claim your shop.</h2>
        <p class="step-sub">Free account. Publish instantly. No fees.</p>
        <form id="publishForm" class="signup-form">
          <?= csrf_field() ?>
          <input type="hidden" name="role" value="seller">
          <label class="field"><span>Full name</span><input type="text" name="full_name" required></label>
          <label class="field"><span>Email</span><input type="email" name="email" required></label>
          <label class="field"><span>Password</span><input type="password" name="password" minlength="8" required></label>
          <button type="submit" class="btn btn-accent btn-block btn-lg">Publish my catalog 🚀</button>
        </form>
        <div class="escape"><button type="button" class="btn-link" data-goto-step="1">← Back to preview</button></div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/layout/footer.php'; ?>