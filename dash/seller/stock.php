<?php
require_once __DIR__ . '/../_helpers.php';

$u = current_user();

/* ═══════════════════════════════════════════════════
   PAGINATION + KPIs
   ═══════════════════════════════════════════════════ */
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;

$kpiStmt = db()->prepare("
    SELECT
        COUNT(*)                                                       AS total_items,
        COALESCE(SUM(quantity), 0)                                     AS total_units,
        COALESCE(SUM(price * quantity), 0)                             AS inventory_value,
        COALESCE(SUM(CASE WHEN quantity <= 3 THEN 1 ELSE 0 END), 0)    AS low_stock,
        COALESCE(SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END), 0)     AS out_of_stock,
        COALESCE(SUM(views), 0)                                        AS total_views,
        COALESCE(SUM(CASE WHEN discount_percent > 0 THEN 1 ELSE 0 END), 0) AS on_discount
    FROM products
    WHERE seller_id = ?
");
$kpiStmt->execute([$u['id']]);
$kpi = $kpiStmt->fetch();

$totalItems     = (int)$kpi['total_items'];
$totalUnits     = (int)$kpi['total_units'];
$inventoryValue = (float)$kpi['inventory_value'];
$lowStock       = (int)$kpi['low_stock'];
$outOfStock     = (int)$kpi['out_of_stock'];
$totalViews     = (int)$kpi['total_views'];
$onDiscount     = (int)$kpi['on_discount'];

$totalPages = max(1, (int)ceil($totalItems / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare("
    SELECT p.*, c.name AS cat_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.seller_id = ?
    ORDER BY p.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute([$u['id']]);
$items = $stmt->fetchAll();

$cats = db()->query("SELECT id,name,icon FROM categories ORDER BY name")->fetchAll();

function stock_url(array $overrides = []): string {
    $base = ['page' => $_GET['page'] ?? ''];
    $params = array_filter(array_merge($base, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'dashboard.php?tab=stock' . ($params ? '&' . http_build_query($params) : '');
}

$actions = '<button class="btn btn-accent" data-open-modal="addItemModal">+ Add item</button>';
dash_header(
    'Stock',
    $totalItems . ' item' . ($totalItems === 1 ? '' : 's') . ' · '
        . $totalUnits . ' units · ' . money($inventoryValue) . ' inventory value',
    $actions
);
?>

<!-- KPI CARDS -->
<div class="kpi-grid">
  <div class="kpi-card">
    <span class="kpi-label">Items listed</span>
    <span class="kpi-value"><?= $totalItems ?></span>
    <span class="kpi-delta"><?= $totalViews ?> total views</span>
  </div>
  <div class="kpi-card">
    <span class="kpi-label">Inventory value</span>
    <span class="kpi-value"><?= money($inventoryValue) ?></span>
    <span class="kpi-delta"><?= $totalUnits ?> units in stock</span>
  </div>
  <div class="kpi-card <?= $onDiscount > 0 ? 'accent' : 'neutral' ?>">
    <span class="kpi-label">On discount</span>
    <span class="kpi-value"><?= $onDiscount ?></span>
    <span class="kpi-delta"><?= $onDiscount > 0 ? 'Promoted items' : 'None running' ?></span>
  </div>
  <div class="kpi-card <?= $lowStock > 0 ? 'warn' : 'neutral' ?>">
    <span class="kpi-label">Low stock</span>
    <span class="kpi-value"><?= $lowStock ?></span>
    <span class="kpi-delta"><?= $lowStock > 0 ? 'Restock soon' : 'All healthy' ?></span>
  </div>
</div>

<?php if (!$items): ?>
  <div class="empty-state">
    <div class="empty-icon">📦</div>
    <h3>No items yet</h3>
    <p>Add your first item to start selling.</p>
    <button class="btn btn-accent" data-open-modal="addItemModal">+ Add item</button>
  </div>
<?php else: ?>

  <div class="list-toolbar">
    <div class="search-mini">
      <span class="search-mini-icon">🔍</span>
      <input type="search" id="stockSearch"
             placeholder="Search by title, category, or location…"
             autocomplete="off">
    </div>
    <span class="list-count" id="stockCount">
      <?= count($items) ?> of <?= $totalItems ?> items
    </span>
  </div>

  <div class="table-scroll">
    <table class="data-table data-table-wide" id="stockTable">
      <thead>
        <tr>
          <th>Item</th>
          <th>Category</th>
          <th class="num">Price</th>
          <th class="num">Stock</th>
          <th class="num">Views</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <?php $hasDiscount = (float)$it['discount_percent'] > 0; ?>
        <tr data-product-row="<?= (int)$it['id'] ?>">
          <td>
            <div class="cell-product">
              <div class="cell-thumb" style="background-image:url('<?= e($it['cover_image'] ?: '') ?>')"></div>
              <div>
                <strong><?= e($it['title']) ?></strong>
                <small><?= e($it['location'] ?: '—') ?></small>
              </div>
            </div>
          </td>
          <td><?= e($it['cat_name'] ?? '—') ?></td>
          <td class="num">
            <div class="cell-price-block">
              <span class="cell-price"><?= money((float)$it['price']) ?></span>
              <?php if ($hasDiscount): ?>
                <small class="price-was"><?= money((float)$it['original_price']) ?></small>
                <small class="discount-tag">−<?= number_format((float)$it['discount_percent'], 0) ?>%</small>
              <?php endif; ?>
            </div>
          </td>
          <td class="num">
            <span class="cell-qty <?= $it['quantity'] <= 3 ? 'low' : '' ?>" data-field="quantity">
              <?= (int)$it['quantity'] ?>
            </span>
          </td>
          <td class="num"><?= (int)$it['views'] ?></td>
          <td><?= status_pill($it['status']) ?></td>
          <td class="row-actions">
            <button class="btn-link"
                    data-edit-item="<?= (int)$it['id'] ?>"
                    data-item='<?= e(json_encode([
                        'id' => (int)$it['id'],
                        'title' => $it['title'],
                        'price' => (float)$it['price'],
                        'discount_percent' => (float)$it['discount_percent'],
                        'original_price' => $it['original_price'] !== null ? (float)$it['original_price'] : null,
                        'quantity' => (int)$it['quantity'],
                        'category_id' => (int)$it['category_id'],
                        'description' => $it['description'],
                        'location' => $it['location'],
                        'status' => $it['status'],
                        'cover_image' => $it['cover_image'],
                    ])) ?>'>Edit</button>
            <button class="btn-link" data-restock-item="<?= (int)$it['id'] ?>">Restock</button>
            <button class="btn-link"
                data-quick-sell="<?= (int)$it['id'] ?>"
                data-quick-title="<?= e($it['title']) ?>"
                data-quick-price="<?= e($it['price']) ?>"
                data-quick-stock="<?= (int)$it['quantity'] ?>">Sell</button>
            <button class="btn-link danger" data-delete-item="<?= (int)$it['id'] ?>"
                    data-item-title="<?= e($it['title']) ?>">Delete</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="empty-state small" id="noResults" hidden>
    <p>No items match your search.</p>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Pagination">
      <?php if ($page > 1): ?>
        <a href="<?= e(stock_url(['page' => $page - 1])) ?>" class="page-btn">‹ Prev</a>
      <?php else: ?>
        <span class="page-btn disabled">‹ Prev</span>
      <?php endif; ?>

      <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="<?= e(stock_url(['page' => $i])) ?>"
           class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>

      <?php if ($page < $totalPages): ?>
        <a href="<?= e(stock_url(['page' => $page + 1])) ?>" class="page-btn">Next ›</a>
      <?php else: ?>
        <span class="page-btn disabled">Next ›</span>
      <?php endif; ?>
    </nav>
  <?php endif; ?>

<?php endif; ?>


<!-- ═══════════ ADD ITEM MODAL ═══════════ -->
<div class="modal" id="addItemModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel">
    <button class="modal-close" data-close-modal>×</button>
    <h2>Add new item</h2>
    <p class="step-sub">It goes live immediately after saving.</p>

    <form id="addItemForm" class="settings-form" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">

      <label class="field"><span>Title</span>
        <input type="text" name="title" required maxlength="200">
      </label>

      <div class="two-col">
        <label class="field"><span>Current price</span>
          <input type="number" name="price" step="0.01" min="0" required>
        </label>
        <label class="field"><span>Quantity</span>
          <input type="number" name="quantity" min="1" value="1" required>
        </label>
      </div>

      <label class="field"><span>Discount % <em>(0 = no discount)</em></span>
        <input type="number" name="discount_percent" step="0.01" min="0" max="99" value="0">
      </label>

      <p class="discount-preview" data-discount-preview hidden>
        Original: <s data-original-display></s> ·
        <span data-save-display></span>
      </p>

      <label class="field"><span>Category</span>
        <select name="category_id">
          <option value="">— choose —</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e(($c['icon'] ?? '') . ' ' . $c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="field"><span>Description</span>
        <textarea name="description" rows="3"></textarea>
      </label>

      <label class="field"><span>Location</span>
        <input type="text" name="location">
      </label>

      <label class="field"><span>Photo</span>
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
      </label>

      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-accent">Save item</button>
      </div>
    </form>
  </div>
</div>


<!-- ═══════════ EDIT ITEM MODAL ═══════════ -->
<div class="modal" id="editItemModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel">
    <button class="modal-close" data-close-modal>×</button>
    <h2>Edit item</h2>

    <form id="editItemForm" class="settings-form" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="product_id" id="edit-id">

      <label class="field"><span>Title</span>
        <input type="text" name="title" id="edit-title" required maxlength="200">
      </label>

      <div class="two-col">
        <label class="field"><span>Current price</span>
          <input type="number" name="price" id="edit-price" step="0.01" min="0" required>
        </label>
        <label class="field"><span>Quantity</span>
          <input type="number" name="quantity" id="edit-quantity" min="0" required>
        </label>
      </div>

      <label class="field"><span>Discount % <em>(0 = no discount)</em></span>
        <input type="number" name="discount_percent" id="edit-discount"
               step="0.01" min="0" max="99" value="0">
      </label>

      <p class="discount-preview" id="edit-discount-preview" hidden>
        Original: <s data-original-display></s> ·
        <span data-save-display></span>
      </p>

      <label class="field"><span>Category</span>
        <select name="category_id" id="edit-category">
          <option value="">— choose —</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e(($c['icon'] ?? '') . ' ' . $c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="field"><span>Description</span>
        <textarea name="description" id="edit-description" rows="3"></textarea>
      </label>

      <label class="field"><span>Location</span>
        <input type="text" name="location" id="edit-location">
      </label>

      <label class="field"><span>Status</span>
        <select name="status" id="edit-status">
          <option value="active">Active</option>
          <option value="draft">Draft</option>
          <option value="suspended">Suspended</option>
          <option value="sold">Sold out</option>
        </select>
      </label>

      <label class="field"><span>Replace photo <em>(optional)</em></span>
        <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
      </label>

      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-accent">Save changes</button>
      </div>
    </form>
  </div>
</div>


<!-- ═══════════ RESTOCK MODAL ═══════════ -->
<div class="modal" id="restockModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>
    <h2>Restock item</h2>
    <p class="step-sub">Add or reduce units.</p>

    <form id="restockForm" class="settings-form">
      <?= csrf_field() ?>
      <input type="hidden" name="product_id" id="restock-id">

      <label class="field"><span>Units to add (use negative to reduce)</span>
        <input type="number" name="delta" id="restock-delta" required value="10">
      </label>

      <label class="field"><span>Note <em>(optional)</em></span>
        <input type="text" name="note" placeholder="e.g. Supplier delivery">
      </label>

      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-accent">Apply</button>
      </div>
    </form>
  </div>
</div>


<!-- ═══════════ QUICK SELL MODAL ═══════════ -->
<div class="modal" id="quickSellModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>
    <h2>Quick sell</h2>
    <p class="step-sub" id="qs-title"></p>

    <form id="quickSellForm" class="settings-form">
      <?= csrf_field() ?>
      <input type="hidden" name="product_id" id="qs-id">

      <label class="field"><span>Customer name</span>
        <input type="text" name="customer_name" required placeholder="Walk-in customer">
      </label>

      <div class="two-col">
        <label class="field"><span>Quantity</span>
          <input type="number" name="quantity" id="qs-qty" min="1" value="1" required>
        </label>
        <label class="field"><span>Unit price</span>
          <input type="number" name="unit_price" id="qs-price" step="0.01" min="0" required>
        </label>
      </div>

      <label class="field"><span>Payment</span>
        <select name="payment_status">
          <option value="completed">Paid now</option>
          <option value="pending">Unpaid (owe)</option>
        </select>
      </label>

      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
        <button type="submit" class="btn btn-accent">Record sale</button>
      </div>
    </form>
  </div>
</div>


<script>
(() => {
  /* ── Modal open/close ── */
  document.querySelectorAll('[data-open-modal]').forEach(btn => {
    btn.addEventListener('click', () => {
      const m = document.getElementById(btn.dataset.openModal);
      if (m) m.hidden = false;
    });
  });
  document.querySelectorAll('[data-close-modal]').forEach(el => {
    el.addEventListener('click', () => el.closest('.modal').hidden = true);
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
      document.querySelectorAll('.modal:not([hidden])').forEach(m => m.hidden = true);
  });

  /* ── Discount preview helpers ── */
  function wireDiscountPreview(formEl, previewEl) {
    if (!formEl || !previewEl) return;
    const priceEl    = formEl.querySelector('[name="price"]');
    const discountEl = formEl.querySelector('[name="discount_percent"]');
    if (!priceEl || !discountEl) return;

    const origEl = previewEl.querySelector('[data-original-display]');
    const saveEl = previewEl.querySelector('[data-save-display]');

    function recalc() {
      const current = parseFloat(priceEl.value) || 0;
      const pct     = parseFloat(discountEl.value) || 0;
      if (current <= 0 || pct <= 0) { previewEl.hidden = true; return; }
      const original = current / (1 - pct / 100);
      origEl.textContent = '$' + original.toFixed(2);
      saveEl.textContent = `save $${(original - current).toFixed(2)}`;
      previewEl.hidden = false;
    }
    priceEl.addEventListener('input', recalc);
    discountEl.addEventListener('input', recalc);
  }

  wireDiscountPreview(
    document.getElementById('addItemForm'),
    document.querySelector('#addItemForm [data-discount-preview]')
  );
  wireDiscountPreview(
    document.getElementById('editItemForm'),
    document.getElementById('edit-discount-preview')
  );

  /* ── Add item ── */
  document.getElementById('addItemForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const r = await fetch('stock_api.php', { method:'POST', body: new FormData(e.target) });
    const j = await r.json();
    if (j.ok) location.reload();
    else alert(j.error || 'Failed');
  });

  /* ── Edit item ── */
  document.querySelectorAll('[data-edit-item]').forEach(btn => {
    btn.addEventListener('click', () => {
      const d = JSON.parse(btn.dataset.item);
      document.getElementById('edit-id').value          = d.id;
      document.getElementById('edit-title').value       = d.title;
      document.getElementById('edit-price').value       = d.price;
      document.getElementById('edit-discount').value    = d.discount_percent || 0;
      document.getElementById('edit-quantity').value    = d.quantity;
      document.getElementById('edit-category').value    = d.category_id || '';
      document.getElementById('edit-description').value = d.description || '';
      document.getElementById('edit-location').value    = d.location || '';
      document.getElementById('edit-status').value      = d.status;
      document.getElementById('editItemModal').hidden   = false;
      // Fire the preview update
      document.getElementById('edit-discount').dispatchEvent(new Event('input'));
    });
  });
  document.getElementById('editItemForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const r = await fetch('stock_api.php', { method:'POST', body: new FormData(e.target) });
    const j = await r.json();
    if (j.ok) location.reload();
    else alert(j.error || 'Failed');
  });

  /* ── Restock ── */
  document.querySelectorAll('[data-restock-item]').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('restock-id').value = btn.dataset.restockItem;
      document.getElementById('restockModal').hidden = false;
    });
  });
  document.getElementById('restockForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('action', 'restock');
    const r = await fetch('stock_api.php', { method:'POST', body: fd });
    const j = await r.json();
    if (j.ok) location.reload();
    else alert(j.error || 'Failed');
  });

  /* ── Delete ── */
  document.querySelectorAll('[data-delete-item]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm(`Delete "${btn.dataset.itemTitle}"? This cannot be undone.`)) return;
      const fd = new FormData();
      fd.append('action', 'delete');
      fd.append('product_id', btn.dataset.deleteItem);
      fd.append('csrf', document.querySelector('input[name=csrf]').value);
      const r = await fetch('stock_api.php', { method:'POST', body: fd });
      const j = await r.json();
      if (j.ok) document.querySelector(`tr[data-product-row="${btn.dataset.deleteItem}"]`)?.remove();
      else alert(j.error || 'Failed');
    });
  });

  /* ── Quick sell ── */
  document.querySelectorAll('[data-quick-sell]').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('qs-id').value          = btn.dataset.quickSell;
      document.getElementById('qs-title').textContent = btn.dataset.quickTitle;
      document.getElementById('qs-price').value       = btn.dataset.quickPrice;
      document.getElementById('qs-qty').value         = 1;
      document.getElementById('qs-qty').max           = btn.dataset.quickStock;
      document.getElementById('quickSellModal').hidden = false;
    });
  });
  document.getElementById('quickSellForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('payment_method', 'cash');
    fd.append('notes', 'Quick sale from stock tab');
    const r = await fetch('manual_order.php', { method:'POST', body: fd });
    const j = await r.json();
    if (j.ok) location.reload();
    else alert(j.error || 'Failed');
  });

  /* ── Live search ── */
  const searchEl = document.getElementById('stockSearch');
  const tableEl  = document.getElementById('stockTable');
  const countEl  = document.getElementById('stockCount');
  const noResEl  = document.getElementById('noResults');

  if (searchEl && tableEl) {
    const rows  = [...tableEl.querySelectorAll('tbody tr')];
    const total = rows.length;

    searchEl.addEventListener('input', () => {
      const q = searchEl.value.trim().toLowerCase();
      let shown = 0;
      rows.forEach(r => {
        const hit = !q || r.textContent.toLowerCase().includes(q);
        r.hidden = !hit;
        if (hit) shown++;
      });
      if (countEl) {
        countEl.textContent = q
          ? `${shown} of ${total} items`
          : `${total} of <?= $totalItems ?> items`;
      }
      if (noResEl) noResEl.hidden = shown > 0;
    });
  }
})();
</script>