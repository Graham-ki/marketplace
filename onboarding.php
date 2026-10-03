<?php
require __DIR__ . '/db.php';

$role = $_GET['role'] ?? 'seller';
if (!in_array($role, ['seller','buyer'], true)) $role = 'seller';
$token = draft_token();

if ($role === 'seller') {
    $categories = db()->query("SELECT id,name,icon FROM categories ORDER BY name")->fetchAll();
} else {
    $products = db()->query("
        SELECT id,title,price,cover_image
        FROM products WHERE status='active'
        ORDER BY created_at DESC LIMIT 12
    ")->fetchAll();
}

$pageTitle = $role === 'seller' ? 'List an item' : 'Place an order';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · Market</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/app.css">
</head>
<body>

<header class="topbar">
  <a href="/" class="brand"><span class="brand-mark">◆</span><span>Market</span></a>
  <nav class="topbar-nav">
    <a href="login.php">Log in</a>
  </nav>
</header>

<main class="page">
<div class="wizard" data-role="<?= e($role) ?>">

  <div class="progress">
    <div class="progress-bar"><span style="width:25%"></span></div>
    <div class="progress-steps">
      <?php if ($role === 'seller'): ?>
        <div class="p-step active" data-step="1">Item</div>
        <div class="p-step" data-step="2">Details</div>
        <div class="p-step" data-step="3">Preview</div>
        <div class="p-step" data-step="4">Claim</div>
      <?php else: ?>
        <div class="p-step active" data-step="1">Browse</div>
        <div class="p-step" data-step="2">Details</div>
        <div class="p-step" data-step="3">Preview</div>
        <div class="p-step" data-step="4">Confirm</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($role === 'seller'): /* ══════════ SELLER WIZARD ══════════ */ ?>

    <section class="step active" data-step="1">
      <span class="step-tag">Step 1 of 4 · 15 sec</span>
      <h2>What are you selling today?</h2>
      <p class="step-sub">Just the basics. We'll help with the rest.</p>
      <label class="field"><span>Item title</span>
        <input type="text" id="title" maxlength="120" placeholder="e.g. iPhone 13 Pro, 128GB">
      </label>
      <div class="field"><span>Category</span>
        <div class="cat-grid" id="categories">
          <?php foreach ($categories as $c): ?>
            <button type="button" class="cat" data-cat="<?= (int)$c['id'] ?>">
              <?= e(($c['icon'] ?? '📦') . ' ' . $c['name']) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="actions"><span></span>
        <button class="btn btn-primary" data-next="2" id="next1" disabled>Continue →</button>
      </div>
    </section>

    <section class="step" data-step="2">
      <span class="step-tag">Step 2 of 4</span>
      <h2>Make it irresistible.</h2>
      <p class="step-sub">Great photos sell 3× faster.</p>
      <div class="upload-zone" id="dropzone">
        <input type="file" id="images" accept="image/jpeg,image/png,image/webp" multiple hidden>
        <div class="drop-inner">
          <div class="drop-icon">📸</div>
          <p><strong>Click to upload</strong> or drag photos here</p>
          <small>JPG, PNG, WebP · max 5MB each</small>
        </div>
      </div>
      <div class="thumbs" id="thumbs"></div>
      <div class="two-col">
        <label class="field"><span>Price</span>
          <div class="price-wrap"><span class="currency">$</span>
            <input type="number" id="price" min="0" step="0.01" placeholder="0.00">
          </div>
        </label>
        <label class="field"><span>Quantity</span>
          <input type="number" id="quantity" min="1" value="1">
        </label>
      </div>
      <label class="field"><span>Description <em>(optional)</em></span>
        <textarea id="description" rows="4" placeholder="Condition, features…"></textarea>
      </label>
      <label class="field"><span>Location</span>
        <input type="text" id="location" placeholder="City, Country">
      </label>
      <div class="actions">
        <button class="btn btn-ghost" data-prev="1">← Back</button>
        <button class="btn btn-primary" data-next="3" id="next2" disabled>Preview →</button>
      </div>
    </section>

    <section class="step" data-step="3">
      <span class="step-tag success">🎉 Your listing is ready</span>
      <h2>This is exactly how buyers will see it.</h2>
      <div class="preview-card">
        <div class="preview-image" id="previewImage"><div class="image-placeholder">No image</div></div>
        <div class="preview-body">
          <div class="preview-cat" id="previewCat">Category</div>
          <h3 id="previewTitle">Title</h3>
          <div class="preview-price" id="previewPrice">$0.00</div>
          <p class="preview-desc" id="previewDesc"></p>
          <div class="preview-meta"><span id="previewLoc">📍 Location</span><span>👁️ 0 views</span></div>
          <button class="btn btn-accent btn-block" type="button">🛒 Buy Now</button>
        </div>
      </div>
      <div class="actions">
        <button class="btn btn-ghost" data-prev="2">← Edit</button>
        <button class="btn btn-primary" data-next="4" id="next3">I love it — continue →</button>
      </div>
    </section>

    <section class="step" data-step="4">
      <div class="commit-hero">
        <div class="commit-icon">🔒</div>
        <h2>Your listing is <span class="gradient">waiting for you</span>.</h2>
        <p class="step-sub">Create a free account to publish it.</p>
        <ul class="commit-perks">
          <li>✅ Publish instantly — no fees</li>
          <li>✅ Buyers contact you directly</li>
          <li>✅ Edit or remove anytime</li>
        </ul>
        <div class="mini-preview">
          <img id="miniImg" alt="">
          <div><strong id="miniTitle">Your item</strong><span id="miniPrice">$0.00</span></div>
          <span class="pill">Draft saved</span>
        </div>
        <form id="signupForm" class="signup-form">
          <?= csrf_field() ?>
          <input type="hidden" name="role" value="seller">
          <input type="hidden" name="draft_token" value="<?= e($token) ?>">
          <label class="field"><span>Full name</span><input type="text" name="full_name" required></label>
          <label class="field"><span>Email</span><input type="email" name="email" required></label>
          <label class="field"><span>Password</span><input type="password" name="password" minlength="8" required></label>
          <button type="submit" class="btn btn-accent btn-block btn-lg">Publish my listing 🚀</button>
        </form>
        <div class="escape"><button type="button" class="btn-link" data-prev="3">← Keep editing</button></div>
      </div>
    </section>

  <?php else: /* ══════════ BUYER WIZARD ══════════ */ ?>

    <section class="step active" data-step="1">
      <span class="step-tag">Step 1 of 4</span>
      <h2>Pick something you like.</h2>
      <p class="step-sub">Real items from real sellers. No account yet.</p>
      <div class="product-grid" id="productGrid">
        <?php foreach ($products as $p): ?>
          <button type="button" class="product-card"
                  data-id="<?= (int)$p['id'] ?>"
                  data-title="<?= e($p['title']) ?>"
                  data-price="<?= e($p['price']) ?>"
                  data-image="<?= e($p['cover_image'] ?? '') ?>">
            <div class="product-thumb" style="background-image:url('<?= e($p['cover_image'] ?: '') ?>')"></div>
            <div class="product-info">
              <strong><?= e($p['title']) ?></strong>
              <span class="price">$<?= number_format((float)$p['price'], 2) ?></span>
            </div>
          </button>
        <?php endforeach; ?>
      </div>
      <div class="actions"><span></span>
        <button class="btn btn-primary" data-next="2" id="next1" disabled>Continue →</button>
      </div>
    </section>

    <section class="step" data-step="2">
      <span class="step-tag">Step 2 of 4</span>
      <h2>Where should we deliver it?</h2>
      <div class="two-col">
        <label class="field"><span>Quantity</span><input type="number" id="quantity" min="1" value="1"></label>
        <label class="field"><span>Phone</span><input type="text" id="phone" placeholder="+256 …"></label>
      </div>
      <label class="field"><span>Delivery address</span>
        <textarea id="address" rows="3" placeholder="Street, area, city…"></textarea>
      </label>
      <label class="field"><span>Notes <em>(optional)</em></span>
        <textarea id="notes" rows="2"></textarea>
      </label>
      <div class="actions">
        <button class="btn btn-ghost" data-prev="1">← Back</button>
        <button class="btn btn-primary" data-next="3" id="next2" disabled>Preview →</button>
      </div>
    </section>

    <section class="step" data-step="3">
      <span class="step-tag success">🎉 Order ready</span>
      <h2>Here's your order.</h2>
      <div class="preview-card">
        <div class="preview-image" id="orderImage"><div class="image-placeholder">No image</div></div>
        <div class="preview-body">
          <div class="preview-cat">Order summary</div>
          <h3 id="orderTitle">Item</h3>
          <div class="preview-price" id="orderTotal">$0.00</div>
          <p class="preview-desc" id="orderAddress"></p>
          <div class="preview-meta"><span id="orderQty">Qty 1</span></div>
        </div>
      </div>
      <div class="actions">
        <button class="btn btn-ghost" data-prev="2">← Edit</button>
        <button class="btn btn-primary" data-next="4" id="next3">Looks good — continue →</button>
      </div>
    </section>

    <section class="step" data-step="4">
      <div class="commit-hero">
        <div class="commit-icon">🔒</div>
        <h2>Place it with <span class="gradient">one small step</span>.</h2>
        <p class="step-sub">Create a free account to confirm your order.</p>
        <div class="mini-preview">
          <img id="miniImg" alt="">
          <div><strong id="miniTitle">Your order</strong><span id="miniPrice">$0.00</span></div>
          <span class="pill">Draft saved</span>
        </div>
        <form id="signupForm" class="signup-form">
          <?= csrf_field() ?>
          <input type="hidden" name="role" value="buyer">
          <input type="hidden" name="draft_token" value="<?= e($token) ?>">
          <label class="field"><span>Full name</span><input type="text" name="full_name" required></label>
          <label class="field"><span>Email</span><input type="email" name="email" required></label>
          <label class="field"><span>Password</span><input type="password" name="password" minlength="8" required></label>
          <button type="submit" class="btn btn-accent btn-block btn-lg">Place my order 🚀</button>
        </form>
        <div class="escape"><button type="button" class="btn-link" data-prev="3">← Review again</button></div>
      </div>
    </section>

  <?php endif; ?>
</div>
</main>

<script src="js/app.js" defer></script>
</body>
</html>