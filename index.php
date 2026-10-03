<?php
require __DIR__ . '/db.php';

$q        = trim($_GET['q'] ?? '');
$catSlug  = trim($_GET['cat'] ?? '');
$sort     = $_GET['sort'] ?? 'new';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 6;

// Categories for the filter chips
$cats = db()->query("SELECT id,name,slug,icon FROM categories ORDER BY name")->fetchAll();

// ─── Build WHERE (shared by count and list queries) ───
$where  = ["p.status = 'active'"];
$args   = [];

if ($q !== '') {
    $where[] = "(
        p.title LIKE ?
        OR p.description LIKE ?
        OR sp.business_name LIKE ?
        OR u.full_name LIKE ?
    )";
    $like = "%$q%";
    array_push($args, $like, $like, $like, $like);
}

if ($catSlug !== '') {
    $where[] = "c.slug = ?";
    $args[]  = $catSlug;
}

$whereSql = implode(' AND ', $where);

// ─── Count total for pagination ───
$countSql = "
    SELECT COUNT(*)
    FROM products p
    LEFT JOIN categories c      ON c.id = p.category_id
    JOIN users u                ON u.id = p.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = p.seller_id
    WHERE $whereSql
";
$countStmt = db()->prepare($countSql);
$countStmt->execute($args);
$totalItems = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalItems / $perPage));

// Clamp page so out-of-range URLs show the last page
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;

// ─── Fetch current page of products ───
$orderSql = match($sort) {
    'price_asc'  => " ORDER BY p.price ASC",
    'price_desc' => " ORDER BY p.price DESC",
    default      => " ORDER BY p.created_at DESC",
};

$listSql = "
    SELECT p.id, p.title, p.price, p.cover_image, p.location, p.created_at,
           c.slug AS cat_slug, c.name AS cat_name,
           COALESCE(NULLIF(sp.business_name,''), u.full_name) AS seller_name
    FROM products p
    LEFT JOIN categories c      ON c.id = p.category_id
    JOIN users u                ON u.id = p.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = p.seller_id
    WHERE $whereSql
    $orderSql
    LIMIT $perPage OFFSET $offset
";

$listStmt = db()->prepare($listSql);
$listStmt->execute($args);
$products = $listStmt->fetchAll();

// ─── Helper: build a URL preserving q / cat / sort ───
function shop_url(array $overrides = []): string {
    $base = [
        'q'    => $_GET['q']    ?? '',
        'cat'  => $_GET['cat']  ?? '',
        'sort' => $_GET['sort'] ?? '',
        'page' => $_GET['page'] ?? '',
    ];
    $params = array_filter(array_merge($base, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

$__pageTitle = 'Shop';
require __DIR__ . '/layout/header.php';
?>

<div class="container">

  <!-- ═══ Hero strip ═══ -->
  <section class="shop-hero">
    <div class="shop-hero-text">
      <span class="badge">✨ Sell in 60 seconds · Buy in 30</span>
      <h1>Come sell. Come buy.</h1>
      <p>Discover thousands of items from sellers near you — or list your own for free.</p>
      <div class="shop-hero-actions">
        <a href="sell.php" class="btn btn-accent btn-lg">🏷️ Start selling</a>
        <a href="#shop" class="btn btn-primary btn-lg">🛍️ Browse items</a>
      </div>
    </div>
  </section>

  <!-- ═══ Filters + grid ═══ -->
  <section id="shop" class="shop-section">

    <div class="shop-toolbar">
      <div class="chips">
        <a href="<?= e(shop_url(['cat' => '', 'page' => ''])) ?>"
           class="chip <?= $catSlug === '' ? 'active' : '' ?>">All</a>
        <?php foreach ($cats as $c): ?>
          <a href="<?= e(shop_url(['cat' => $c['slug'], 'page' => ''])) ?>"
             class="chip <?= $catSlug === $c['slug'] ? 'active' : '' ?>">
            <?= e(($c['icon'] ?? '') . ' ' . $c['name']) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <form method="get" class="sort-form">
        <?php if ($q): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <?php if ($catSlug): ?><input type="hidden" name="cat" value="<?= e($catSlug) ?>"><?php endif; ?>
        <select name="sort" onchange="this.form.submit()">
          <option value="new"        <?= $sort==='new'?'selected':'' ?>>Newest</option>
          <option value="price_asc"  <?= $sort==='price_asc'?'selected':'' ?>>Price: low → high</option>
          <option value="price_desc" <?= $sort==='price_desc'?'selected':'' ?>>Price: high → low</option>
        </select>
      </form>
    </div>

    <!-- Result count line -->
    <?php if ($totalItems > 0): ?>
      <p class="shop-meta">
        Showing <?= count($products) ?> of <?= $totalItems ?> item<?= $totalItems === 1 ? '' : 's' ?>
        <?php if ($q): ?> for “<?= e($q) ?>”<?php endif; ?>
        <?php if ($catSlug): ?> in this category<?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if (!$products): ?>
      <div class="empty-state">
        <div class="empty-icon">🔍</div>
        <h3>No items found</h3>
        <p>Try a different search or category.</p>
      </div>
    <?php else: ?>
      <div class="product-grid">
        <?php foreach ($products as $p): ?>
          <a href="product.php?id=<?= (int)$p['id'] ?>" class="product-card">
            <div class="product-thumb"
                 style="background-image:url('<?= e($p['cover_image'] ?: '') ?>')">
              <?php if (!$p['cover_image']): ?>
                <span class="thumb-placeholder">📦</span>
              <?php endif; ?>
            </div>
            <div class="product-body">
              <span class="product-cat"><?= e($p['cat_name'] ?? 'Uncategorised') ?></span>
              <strong class="product-title"><?= e($p['title']) ?></strong>
              <div class="product-foot">
                <span class="product-price">UGX <?= number_format((float)$p['price'], 2) ?></span>
                <span class="product-loc">📍 <?= e($p['location'] ?: '—') ?></span>
              </div>
              <small class="product-seller">by <?= e($p['seller_name']) ?></small>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <!-- ═══ PAGINATION ═══ -->
      <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Pagination">

          <!-- Prev -->
          <?php if ($page > 1): ?>
            <a href="<?= e(shop_url(['page' => $page - 1])) ?>"
               class="page-btn" aria-label="Previous page">‹ Prev</a>
          <?php else: ?>
            <span class="page-btn disabled" aria-disabled="true">‹ Prev</span>
          <?php endif; ?>

          <!-- Numbered pages -->
          <?php
            // Show a small window of page numbers (max 5 around current)
            $window = 2;
            $start  = max(1, $page - $window);
            $end    = min($totalPages, $page + $window);

            if ($start > 1) {
                echo '<a href="' . e(shop_url(['page' => 1])) . '" class="page-btn">1</a>';
                if ($start > 2) echo '<span class="page-dots">…</span>';
            }

            for ($i = $start; $i <= $end; $i++):
          ?>
              <a href="<?= e(shop_url(['page' => $i])) ?>"
                 class="page-btn <?= $i === $page ? 'active' : '' ?>"
                 <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
          <?php
            endfor;

            if ($end < $totalPages) {
                if ($end < $totalPages - 1) echo '<span class="page-dots">…</span>';
                echo '<a href="' . e(shop_url(['page' => $totalPages])) . '" class="page-btn">' . $totalPages . '</a>';
            }
          ?>

          <!-- Next -->
          <?php if ($page < $totalPages): ?>
            <a href="<?= e(shop_url(['page' => $page + 1])) ?>"
               class="page-btn" aria-label="Next page">Next ›</a>
          <?php else: ?>
            <span class="page-btn disabled" aria-disabled="true">Next ›</span>
          <?php endif; ?>

        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/layout/footer.php'; ?>