<?php
$pageTitle = 'Place an order';
$extraCss  = ['../public/css/onboarding.css'];
$extraJs   = ['../public/js/onboarding.js'];
require __DIR__ . '/../layout/header.php';
?>

<div class="wizard" data-role="buyer" data-token="<?= htmlspecialchars($token) ?>">

  <div class="progress">
    <div class="progress-bar"><span style="width:25%"></span></div>
    <div class="progress-steps">
      <div class="p-step active" data-step="1">Browse</div>
      <div class="p-step" data-step="2">Details</div>
      <div class="p-step" data-step="3">Preview</div>
      <div class="p-step" data-step="4">Confirm</div>
    </div>
  </div>

  <!-- STEP 1 — pick an item -->
  <section class="step active" data-step="1">
    <span class="step-tag">Step 1 of 4</span>
    <h2>Pick something you like.</h2>
    <p class="step-sub">Real items from real sellers. No account yet.</p>

    <div class="product-grid" id="productGrid">
      <?php foreach ($products as $p): ?>
        <button type="button" class="product-card"
                data-id="<?= (int)$p['id'] ?>"
                data-title="<?= htmlspecialchars($p['title']) ?>"
                data-price="<?= htmlspecialchars($p['price']) ?>"
                data-image="<?= htmlspecialchars($p['cover_image'] ?? '') ?>">
          <div class="product-thumb"
               style="background-image:url('<?= htmlspecialchars($p['cover_image'] ?: '') ?>')"></div>
          <div class="product-info">
            <strong><?= htmlspecialchars($p['title']) ?></strong>
            <span class="price">$<?= number_format((float)$p['price'], 2) ?></span>
          </div>
        </button>
      <?php endforeach; ?>
    </div>

    <div class="actions">
      <span></span>
      <button class="btn btn-primary" data-next="2" id="next1" disabled>
        Continue <span class="arrow">→</span>
      </button>
    </div>
  </section>

  <!-- STEP 2 — quantity + address -->
  <section class="step" data-step="2">
    <span class="step-tag">Step 2 of 4</span>
    <h2>Where should we deliver it?</h2>
    <p class="step-sub">Your seller uses this to reach you.</p>

    <div class="two-col">
      <label class="field">
        <span>Quantity</span>
        <input type="number" id="quantity" min="1" value="1">
      </label>
      <label class="field">
        <span>Phone</span>
        <input type="text" id="phone" placeholder="+256 …">
      </label>
    </div>

    <label class="field">
      <span>Delivery address</span>
      <textarea id="address" rows="3" placeholder="Street, area, city…"></textarea>
    </label>

    <label class="field">
      <span>Notes <em>(optional)</em></span>
      <textarea id="notes" rows="2" placeholder="Any special instructions?"></textarea>
    </label>

    <div class="actions">
      <button class="btn btn-ghost" data-prev="1">← Back</button>
      <button class="btn btn-primary" data-next="3" id="next2" disabled>
        Preview order <span class="arrow">→</span>
      </button>
    </div>
  </section>

  <!-- STEP 3 — order preview -->
  <section class="step" data-step="3">
    <span class="step-tag success">🎉 Order ready</span>
    <h2>Here's your order.</h2>
    <p class="step-sub">One quick check before we place it.</p>

    <div class="preview-card">
      <div class="preview-image" id="orderImage">
        <div class="image-placeholder">No image</div>
      </div>
      <div class="preview-body">
        <div class="preview-cat">Order summary</div>
        <h3 id="orderTitle">Item</h3>
        <div class="preview-price" id="orderTotal">$0.00</div>
        <p class="preview-desc" id="orderAddress"></p>
        <div class="preview-meta">
          <span id="orderQty">Qty 1</span>
        </div>
      </div>
    </div>

    <div class="actions">
      <button class="btn btn-ghost" data-prev="2">← Edit</button>
      <button class="btn btn-primary" data-next="4" id="next3">
        Looks good — continue <span class="arrow">→</span>
      </button>
    </div>
  </section>

  <!-- STEP 4 — commit -->
  <section class="step" data-step="4">
    <div class="commit-hero">
      <div class="commit-icon">🔒</div>
      <h2>Place it with <span class="gradient">one small step</span>.</h2>
      <p class="step-sub">Create a free account to confirm your order.</p>

      <ul class="commit-perks">
        <li>✅ Order placed instantly</li>
        <li>✅ Track status in your dashboard</li>
        <li>✅ Your order is <strong>saved below</strong></li>
      </ul>

      <div class="mini-preview">
        <img id="miniImg" alt="">
        <div>
          <strong id="miniTitle">Your order</strong>
          <span id="miniPrice">$0.00</span>
        </div>
        <span class="pill">Draft saved</span>
      </div>

      <form id="signupForm" class="signup-form" autocomplete="on">
        <?= Security::csrfField() ?>
        <input type="hidden" name="role" value="buyer">
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
          Place my order 🚀
        </button>
        <p class="fine-print">By continuing you agree to our Terms & Privacy.</p>
      </form>

      <div class="escape">
        <button type="button" class="btn-link" data-prev="3">
          ← Wait, let me review
        </button>
      </div>
    </div>
  </section>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>