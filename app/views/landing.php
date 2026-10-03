<?php
$pageTitle = 'Turn your stuff into cash';
$extraCss  = ['../css/landing.css'];
require __DIR__ . '/layout/header.php';
?>

<section class="hero">
  <div class="hero-glow"></div>

  <div class="hero-inner">
    <span class="badge">✨ No signup needed to start</span>

    <h1 class="hero-title">
      <span class="line">Turn your stuff</span>
      <span class="line gradient">into instant cash.</span>
    </h1>

    <p class="hero-sub">
      List an item in 60 seconds. See exactly how buyers will see it.
      <strong>Only sign up if you love it.</strong>
    </p>

    <div class="cta-grid">
      <a href="../public/onboarding.php?role=seller" class="cta cta-seller">
        <div class="cta-icon">🏷️</div>
        <div>
          <h3>Sell something</h3>
          <p>List an item — free preview, no account yet</p>
        </div>
        <span class="arrow">→</span>
      </a>

      <a href="../public/onboarding.php?role=buyer" class="cta cta-buyer">
        <div class="cta-icon">🛍️</div>
        <div>
          <h3>Buy something</h3>
          <p>Browse real items — order in seconds</p>
        </div>
        <span class="arrow">→</span>
      </a>
    </div>

    <div class="trust-row">
      <div><strong>12,400+</strong><span>items listed</span></div>
      <div><strong>4.9★</strong><span>seller rating</span></div>
      <div><strong>0 fees</strong><span>to get started</span></div>
    </div>
  </div>
</section>

<section class="how">
  <h2>Why people list here first</h2>
  <div class="how-grid">
    <div class="how-card">
      <div class="num">1</div>
      <h4>Build it</h4>
      <p>Add photos, title, price. Watch it come alive.</p>
    </div>
    <div class="how-card">
      <div class="num">2</div>
      <h4>Preview it</h4>
      <p>See it exactly as buyers will see it.</p>
    </div>
    <div class="how-card">
      <div class="num">3</div>
      <h4>Claim it</h4>
      <p>Like it? Sign up to publish. Don't like it? Walk away.</p>
    </div>
  </div>
</section>

<?php require __DIR__ . '/layout/footer.php'; ?>