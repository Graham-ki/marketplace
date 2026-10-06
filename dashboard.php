<?php
require __DIR__ . '/db.php';
require_login();

$u = current_user();
$role = $u['role'];
if ($role === 'admin') {
    header('Location: admin/index.php');
    exit;
}
// Seller display name
$sellerProfile = null;
if ($role === 'seller') {
    $s = db()->prepare("SELECT business_name FROM seller_profiles WHERE user_id = ?");
    $s->execute([$u['id']]);
    $sellerProfile = $s->fetch() ?: null;
}
$displayName = ($role === 'seller' && !empty($sellerProfile['business_name']))
    ? $sellerProfile['business_name']
    : $u['name'];

$tab = $_GET['tab'] ?? ($role === 'seller' ? 'home' : 'orders');

$tabs = $role === 'seller'
    ? [
        'home'     => ['label'=>'Home',     'icon'=>'🏠'],
        'stock'    => ['label'=>'Stock',    'icon'=>'📦'],
        'orders'   => ['label'=>'Orders',   'icon'=>'🧾'],
        'payments' => ['label'=>'Payments', 'icon'=>'💳'],
        'refunds'  => ['label'=>'Refunds',  'icon'=>'↩️'],
        'reports'  => ['label'=>'Reports',  'icon'=>'📊'],
        'settings' => ['label'=>'Settings', 'icon'=>'⚙️'],
      ]
    : [
        'orders'   => ['label'=>'Orders',   'icon'=>'🧾'],
        'payments' => ['label'=>'Payments', 'icon'=>'💳'],
        'refunds'  => ['label'=>'Refunds',  'icon'=>'↩️'],
        'reports'  => ['label'=>'Reports',  'icon'=>'📊'],
        'profile'  => ['label'=>'Profile',  'icon'=>'👤'],
      ];

if (!isset($tabs[$tab])) $tab = array_key_first($tabs);
$tabFile = __DIR__ . "/dash/$role/$tab.php";
if (!is_file($tabFile)) { http_response_code(404); die("Tab not found: $role/$tab"); }

$__pageTitle = ucfirst($tab);
require __DIR__ . '/layout/header.php';
?>

<div class="dash-shell">
  <aside class="sidebar">
    <div class="sidebar-user">
      <div class="avatar"><?= strtoupper(substr($displayName, 0, 1)) ?></div>
      <div>
        <strong><?= e($displayName) ?></strong>
        <small><?= e($role) ?></small>
      </div>
    </div>

    <nav class="sidebar-nav">
      <?php foreach ($tabs as $key => $info): ?>
        <a href="dashboard.php?tab=<?= e($key) ?>"
           class="sidebar-link <?= $tab === $key ? 'active' : '' ?>">
          <span class="sidebar-icon"><?= $info['icon'] ?></span>
          <span><?= e($info['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
      <a href="index.php" class="sidebar-link">
        <span class="sidebar-icon">🛍️</span><span>Back to shop</span>
      </a>
      <a href="logout.php" class="sidebar-link">
        <span class="sidebar-icon">🚪</span><span>Log out</span>
      </a>
    </div>
  </aside>

  <section class="dash-content">
    <?php require $tabFile; ?>
  </section>
</div>

<?php require __DIR__ . '/layout/footer.php'; ?>