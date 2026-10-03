<?php
$pageTitle = 'List an item';
$extraCss  = ['/css/onboarding.css'];
$extraJs   = ['/js/onboarding.js'];
require __DIR__ . '/../layout/header.php';
?>

<div class="wizard" data-role="seller" data-token="<?= htmlspecialchars($token) ?>">

  <div class="progress">
    <div class="progress-bar"><span style="width:20%"></span></div>
    <div class="progress-steps">
      <div class="p-step active" data-step="1">Item</div>
      <div class="p-step" data-step="2">Details</div>
      <div class="p-step" data-step="3">Preview</div>
      <div class="p-step" data-step="4">Claim</div>
    </div>
  </div>

  <!-- STEP 1 -->
  <section class="step active" data-step="1">
    <span class="step-tag">Step 1 of 4 · 15 sec</span>
    <h2>What are you selling today?</h2>
    <p class="step-sub">Just the basics. We'll help with the rest.</p>

    <label class="field">
      <span>Item title</span>
      <input type="text" id="title" maxlength="120" autocomplete="off"
             placeholder="e.g. iPhone 13 Pro, 128GB">
    </label>

    <div class="field">
      <span>Category</span>
      <div class="cat-grid" id="categories">
        <?php foreach ($categories as $c): ?>
          <button type="button" class="cat" data-cat="<?= (int)$c['id'] ?>">
            <?= htmlspecialchars(($c['icon'] ?? '📦') . ' ' . $c['name']) ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="actions">
      <span></span>
      <button class="btn btn-primary" data-next="2" disabled id="next1">
        Continue <span class="arrow">→</span>
      </button>
    </div>
  </section>

  <!-- STEP 2 -->
  <section class="step" data-step="2">
    <span class="step-tag">Step 2 of 4</span>
    <h2>Make it irresistible.</h2>
    <p class="step-sub">Great photos sell 3× faster. Add up to 5.</p>

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
      <label class="field">
        <span>Price</span>
        <div class="price-wrap">
          <span class="currency">$</span>
          <input type="number" id="price" min="0" step="0.01" placeholder="0.00">
        </div>
      </label>
      <label class="field">
        <span>Quantity</span>
        <input type="number" id="quantity" min="1" value="1">
      </label>
    </div>

    <label class="field">
      <span>Description <em>(optional)</em></span>
      <textarea id="description" rows="4"
        placeholder="Condition, features, why it's great…"></textarea>
    </label>

    <label class="field">
      <span>Location</span>
      <input type="text" id="location" placeholder="City, Country">
    </label>

    <div class="actions">
      <button class="btn btn-ghost" data-prev="1">← Back</button>
      <button class="btn btn-primary" data-next="3" id="next2" disabled>
        Preview my listing <span class="arrow">→</span>
      </button>
    </div>
  </section>

  <!-- STEP 3 -->
  <section class="step" data-step="3">
    <span class="step-tag success">🎉 Your listing is ready</span>
    <h2>This is exactly how buyers will see it.</h2>
    <p class="step-sub">Scroll through — looks good, doesn't it?</p>

    <div class="preview-card">
      <div class="preview-image" id="previewImage">
        <div class="image-placeholder">No image</div>
      </div>
      <div class="preview-body">
        <div class="preview-cat" id="previewCat">Category</div>
        <h3 id="previewTitle">Your item title</h3>
        <div class="preview-price" id="previewPrice">$0.00</div>
        <p class="preview-desc" id="previewDesc">Description…</p>
        <div class="preview-meta">
          <span id="previewLoc">📍 Location</span>
          <span>👁️ 0 views</span>
        </div>
        <button class="btn btn-accent btn-block" type="button">🛒 Buy Now</button>
      </div>
    </div>

    <div class="actions">
      <button class="btn btn-ghost" data-prev="2">← Edit</button>
      <button class="btn btn-primary" data-next="4" id="next3">
        I love it — continue <span class="arrow">→</span>
      </button>
    </div>
  </section>

  <!-- STEP 4 -->
  <section class="step" data-step="4">
    <div class="commit-hero">
      <div class="commit-icon">🔒</div>
      <h2>Your listing is <span class="gradient">waiting for you</span>.</h2>
      <p class="step-sub">
        Create a free account to publish it. Buyers can reach you in seconds.
      </p>

      <ul class="commit-perks">
        <li>✅ Publish instantly — no fees to start</li>
        <li>✅ Buyers contact you directly</li>
        <li>✅ Edit or remove anytime</li>
        <li>✅ Your listing is <strong>saved below</strong></li>
      </ul>

      <div class="mini-preview">
        <img id="miniImg" alt="">
        <div>
          <strong id="miniTitle">Your item</strong>
          <span id="miniPrice">$0.00</span>
        </div>
        <span class="pill">Draft saved</span>
      </div>

      <form id="signupForm" class="signup-form" autocomplete="on">
        <?= Security::csrfField() ?>
        <input type="hidden" name="role" value="seller">
        <input type="hidden" name="draft_token" value="<?= htmlspecialchars($token) ?>">

        <label class="field">
          <span>Full name</span>
          <input type="text" name="full_name" required>
        </label>
        <label class="field">
          <span>Email</span>
          <input type="email" name="email" required>
        </label>
        <label class="field">
          <span>Password</span>
          <input type="password" name="password" minlength="8" required>
          <small class="hint">At least 8 characters</small>
        </label>

        <button type="submit" class="btn btn-accent btn-block btn-lg">
          Publish my listing 🚀
        </button>
        <p class="fine-print">By continuing you agree to our Terms & Privacy.</p>
      </form>

      <div class="escape">
        <button type="button" class="btn-link" data-prev="3">
          ← Wait, I want to keep editing
        </button>
      </div>
    </div>
  </section>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>