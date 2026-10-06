<?php
$__pageTitle = 'Products';
$__activeNav = 'products';
require __DIR__ . '/_layout.php';

$pdo = db();

$q       = trim($_GET['q'] ?? '');
$status  = $_GET['status'] ?? '';
$catId   = (int)($_GET['cat'] ?? 0);
$page    = max(1, (int)($_GET['page'] ?? 1));
$per     = 20;

if (!in_array($status, ['', 'active', 'draft', 'suspended', 'sold'], true)) $status = '';

/* ═══ KPIs ═══ */
$kpi = $pdo->query("
    SELECT
        COUNT(*)                                                                AS total,
        COALESCE(SUM(CASE WHEN status='active'    THEN 1 ELSE 0 END), 0)        AS active,
        COALESCE(SUM(CASE WHEN discount_percent > 0 THEN 1 ELSE 0 END), 0)      AS discounted,
        COALESCE(SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END), 0)              AS out_of_stock,
        COALESCE(SUM(price * quantity), 0)                                      AS inventory_value
    FROM products
")->fetch();

/* ═══ Filters ═══ */
$where = [];
$args  = [];

if ($q !== '') {
    $where[] = "(p.title LIKE ? OR u.full_name LIKE ? OR sp.business_name LIKE ?)";
    $like = "%$q%";
    array_push($args, $like, $like, $like);
}
if ($status !== '') { $where[] = "p.status = ?"; $args[] = $status; }
if ($catId)         { $where[] = "p.category_id = ?"; $args[] = $catId; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ═══ Pagination ═══ */
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM products p
    JOIN users u ON u.id = p.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = u.id
    $whereSql
");
$countStmt->execute($args);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $per));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $per;

/* ═══ Fetch ═══ */
$stmt = $pdo->prepare("
    SELECT p.id, p.title, p.price, p.discount_percent, p.original_price,
           p.quantity, p.status, p.cover_image, p.created_at,
           u.id AS seller_id, u.full_name AS seller_name,
           COALESCE(NULLIF(sp.business_name,''), u.full_name) AS seller_shop
    FROM products p
    JOIN users u ON u.id = p.seller_id
    LEFT JOIN seller_profiles sp ON sp.user_id = u.id
    $whereSql
    ORDER BY p.created_at DESC
    LIMIT $per OFFSET $offset
");
$stmt->execute($args);
$rows = $stmt->fetchAll();

$cats = $pdo->query("SELECT id,name,icon FROM categories ORDER BY name")->fetchAll();

function products_url(array $over = []): string {
    $base = [
        'q'      => $_GET['q']      ?? '',
        'status' => $_GET['status'] ?? '',
        'cat'    => $_GET['cat']    ?? '',
        'page'   => $_GET['page']   ?? '',
    ];
    $params = array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null);
    return 'products.php' . ($params ? '?' . http_build_query($params) : '');
}

dash_header('Products', $total . ' listed');
?>

<!-- ═══ KPIs ═══ -->
<div class="kpi-grid">
  <div class="kpi-card gradient decorated">
    <div class="kpi-icon">📦</div>
    <span class="kpi-label">Total products</span>
    <span class="kpi-value"><?= (int)$kpi['total'] ?></span>
    <span class="kpi-delta"><?= (int)$kpi['active'] ?> active</span>
  </div>

  <div class="kpi-card accent">
    <div class="kpi-icon">💰</div>
    <span class="kpi-label">Inventory value</span>
    <span class="kpi-value"><?= money((float)$kpi['inventory_value']) ?></span>
    <span class="kpi-delta">Across all sellers</span>
  </div>

  <div class="kpi-card <?= (int)$kpi['discounted'] > 0 ? 'info' : 'neutral' ?>">
    <div class="kpi-icon">🏷️</div>
    <span class="kpi-label">On discount</span>
    <span class="kpi-value"><?= (int)$kpi['discounted'] ?></span>
    <span class="kpi-delta"><?= (int)$kpi['discounted'] > 0 ? 'Promoted items' : 'None running' ?></span>
  </div>

  <div class="kpi-card <?= (int)$kpi['out_of_stock'] > 0 ? 'warn' : 'neutral' ?>">
    <div class="kpi-icon">⚠️</div>
    <span class="kpi-label">Out of stock</span>
    <span class="kpi-value"><?= (int)$kpi['out_of_stock'] ?></span>
    <span class="kpi-delta"><?= (int)$kpi['out_of_stock'] > 0 ? 'Restock needed' : 'All stocked' ?></span>
  </div>
</div>

<!-- ═══ Toolbar ═══ -->
<form method="get" class="list-toolbar">
  <div class="search-mini">
    <span class="search-mini-icon">🔍</span>
    <input type="search" name="q" value="<?= e($q) ?>"
           placeholder="Search title or seller…" autocomplete="off">
  </div>
  <select name="status" class="mini-select" onchange="this.form.submit()">
    <option value="">All statuses</option>
    <?php foreach (['active','draft','suspended','sold'] as $s): ?>
      <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="cat" class="mini-select" onchange="this.form.submit()">
    <option value="">All categories</option>
    <?php foreach ($cats as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $catId===(int)$c['id']?'selected':'' ?>>
        <?= e(($c['icon'] ?? '') . ' ' . $c['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <?php if ($q || $status || $catId): ?>
    <a href="products.php" class="btn-link">Clear</a>
  <?php endif; ?>
</form>

<?php if (!$rows): ?>
  <div class="empty-state small"><p>No products match.</p></div>
<?php else: ?>
  <div class="table-scroll">
    <table class="data-table data-table-wide">
      <thead>
        <tr>
          <th>Product</th>
          <th>Seller</th>
          <th class="num">Price</th>
          <th class="num">Stock</th>
          <th>Status</th>
          <th>Listed</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $p):
        $hasDiscount = (float)$p['discount_percent'] > 0;
      ?>
        <tr>
          <td>
            <div class="cell-product">
              <div class="cell-thumb" style="background-image:url('../<?= e($p['cover_image'] ?: '') ?>')"></div>
              <div>
                <a href="../product.php?id=<?= (int)$p['id'] ?>">
                  <strong><?= e($p['title']) ?></strong>
                </a>
              </div>
            </div>
          </td>
          <td>
            <a href="user.php?id=<?= (int)$p['seller_id'] ?>">
              <?= e($p['seller_shop']) ?>
            </a>
          </td>
          <td class="num">
            <div class="cell-price-block">
              <span><?= money((float)$p['price']) ?></span>
              <?php if ($hasDiscount): ?>
                <small class="price-was"><?= money((float)$p['original_price']) ?></small>
                <small class="discount-tag">−<?= number_format((float)$p['discount_percent'], 0) ?>%</small>
              <?php endif; ?>
            </div>
          </td>
          <td class="num">
            <span class="cell-qty <?= $p['quantity'] <= 3 ? 'low' : '' ?>">
              <?= (int)$p['quantity'] ?>
            </span>
          </td>
          <td><?= status_pill($p['status']) ?></td>
          <td><small><?= e(date('M j, Y', strtotime($p['created_at']))) ?></small></td>
          <td class="row-actions">
            <a href="../product.php?id=<?= (int)$p['id'] ?>" target="_blank" class="btn-link">View</a>
            <form method="post" action="product_action.php" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="action" value="<?= $p['status'] === 'active' ? 'suspend' : 'activate' ?>">
              <button class="btn-link <?= $p['status'] === 'active' ? 'danger' : '' ?>">
                <?= $p['status'] === 'active' ? 'Suspend' : 'Activate' ?>
              </button>
            </form>
            <button class="btn-link danger"
                    data-delete-product="<?= (int)$p['id'] ?>"
                    data-delete-title="<?= e($p['title']) ?>">Delete</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav class="pagination">
      <?php if ($page > 1): ?>
        <a href="<?= e(products_url(['page' => $page - 1])) ?>" class="page-btn">‹ Prev</a>
      <?php else: ?>
        <span class="page-btn disabled">‹ Prev</span>
      <?php endif; ?>
      <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="<?= e(products_url(['page' => $i])) ?>"
           class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?>
        <a href="<?= e(products_url(['page' => $page + 1])) ?>" class="page-btn">Next ›</a>
      <?php else: ?>
        <span class="page-btn disabled">Next ›</span>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<!-- ═══ Delete modal ═══ -->
<div class="modal" id="deleteProductModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>
    <div class="confirm-hero">
      <div class="confirm-icon">🗑️</div>
      <h2>Delete product?</h2>
      <p class="step-sub" id="delete-product-sub">This cannot be undone.</p>
      <div class="confirm-warning">
        ⚠️ This removes the listing from the shop. Existing orders
        referencing this product will also be removed.
      </div>
      <form id="deleteProductForm" class="settings-form" style="margin-top:20px">
        <?= csrf_field() ?>
        <input type="hidden" name="product_id" id="delete-product-id">
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-danger">Delete product</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  document.querySelectorAll('[data-close-modal]').forEach(el => {
    el.addEventListener('click', () => el.closest('.modal').hidden = true);
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
      document.querySelectorAll('.modal:not([hidden])').forEach(m => m.hidden = true);
  });

  document.querySelectorAll('[data-delete-product]').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('delete-product-id').value = btn.dataset.deleteProduct;
      document.getElementById('delete-product-sub').textContent =
        `Delete "${btn.dataset.deleteTitle}"?`;
      document.getElementById('deleteProductModal').hidden = false;
    });
  });

  document.getElementById('deleteProductForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const btn  = e.target.querySelector('button[type=submit]');
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Deleting…';
    try {
      const fd = new FormData(e.target);
      fd.append('action', 'delete');
      const r = await fetch('product_action.php', { method:'POST', body: fd });
      const j = await r.json();
      if (j.ok) location.reload();
      else { alert(j.error || 'Failed'); btn.disabled = false; btn.textContent = orig; }
    } catch {
      alert('Network error');
      btn.disabled = false; btn.textContent = orig;
    }
  });
})();
</script>

<?php require __DIR__ . '/_layout_end.php'; ?>