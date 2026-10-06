<?php
$__pageTitle = 'Users';
$__activeNav = 'users';
require __DIR__ . '/_layout.php';

$pdo = db();

/* ═══ Filters ═══ */
$q      = trim($_GET['q'] ?? '');
$role   = $_GET['role']   ?? '';
$status = $_GET['status'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;

if (!in_array($role, ['', 'buyer', 'seller', 'admin'], true)) $role = '';
if (!in_array($status, ['', 'active', 'inactive', 'locked'], true)) $status = '';

/* ═══ Build WHERE ═══ */
$where  = [];
$args   = [];

if ($q !== '') {
    $where[] = "(full_name LIKE ? OR email LIKE ?)";
    $like = "%$q%";
    array_push($args, $like, $like);
}
if ($role !== '') { $where[] = "role = ?"; $args[] = $role; }
if ($status === 'active')   $where[] = "is_active = 1";
if ($status === 'inactive') $where[] = "is_active = 0";
if ($status === 'locked')   $where[] = "locked_until IS NOT NULL AND locked_until > NOW()";

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ═══ KPIs (always whole set — not filtered) ═══ */
$kpi = $pdo->query("
    SELECT
        COUNT(*)                                                              AS total,
        COALESCE(SUM(CASE WHEN role='buyer'  THEN 1 ELSE 0 END), 0)           AS buyers,
        COALESCE(SUM(CASE WHEN role='seller' THEN 1 ELSE 0 END), 0)           AS sellers,
        COALESCE(SUM(CASE WHEN is_active=0 THEN 1 ELSE 0 END), 0)             AS inactive,
        COALESCE(SUM(CASE WHEN locked_until IS NOT NULL AND locked_until > NOW()
                          THEN 1 ELSE 0 END), 0)                              AS locked,
        COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                          THEN 1 ELSE 0 END), 0)                              AS new_this_week
    FROM users
")->fetch();

/* ═══ Pagination ═══ */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users $whereSql");
$countStmt->execute($args);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $per));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $per;

/* ═══ Fetch ═══ */
$stmt = $pdo->prepare("
    SELECT id, full_name, email, role, is_active, locked_until, created_at,
           (SELECT COUNT(*) FROM products WHERE seller_id = users.id) AS product_count,
           (SELECT COUNT(*) FROM orders WHERE buyer_id = users.id)    AS buyer_orders,
           (SELECT COUNT(*) FROM orders WHERE seller_id = users.id)   AS seller_orders
    FROM users
    $whereSql
    ORDER BY created_at DESC
    LIMIT $per OFFSET $offset
");
$stmt->execute($args);
$rows = $stmt->fetchAll();

function users_url(array $over = []): string {
    $base = [
        'q'      => $_GET['q']      ?? '',
        'role'   => $_GET['role']   ?? '',
        'status' => $_GET['status'] ?? '',
        'page'   => $_GET['page']   ?? '',
    ];
    $params = array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null);
    return 'users.php' . ($params ? '?' . http_build_query($params) : '');
}

$actions = '<button type="button" class="btn btn-ghost" id="downloadSelected" disabled>⬇ Export selected</button>';
dash_header('Users', $total . ' match' . ($total === 1 ? '' : 'es'), $actions);
?>

<!-- ═══ KPI CARDS ═══ -->
<div class="kpi-grid">
  <div class="kpi-card gradient decorated">
    <div class="kpi-icon">👥</div>
    <span class="kpi-label">Total users</span>
    <span class="kpi-value"><?= (int)$kpi['total'] ?></span>
    <span class="kpi-delta"><?= (int)$kpi['new_this_week'] ?> new this week</span>
  </div>

  <div class="kpi-card info">
    <div class="kpi-icon">🛍️</div>
    <span class="kpi-label">Buyers</span>
    <span class="kpi-value"><?= (int)$kpi['buyers'] ?></span>
    <span class="kpi-delta"><?= (int)$kpi['sellers'] ?> sellers</span>
  </div>

  <div class="kpi-card <?= (int)$kpi['inactive'] > 0 ? 'warn' : 'neutral' ?>">
    <div class="kpi-icon">🚫</div>
    <span class="kpi-label">Inactive</span>
    <span class="kpi-value"><?= (int)$kpi['inactive'] ?></span>
    <span class="kpi-delta"><?= (int)$kpi['inactive'] > 0 ? 'Needs attention' : 'All active' ?></span>
  </div>

  <div class="kpi-card <?= (int)$kpi['locked'] > 0 ? 'danger' : 'neutral' ?>">
    <div class="kpi-icon">🔒</div>
    <span class="kpi-label">Locked</span>
    <span class="kpi-value"><?= (int)$kpi['locked'] ?></span>
    <span class="kpi-delta"><?= (int)$kpi['locked'] > 0 ? 'Locked out' : 'None locked' ?></span>
  </div>
</div>

<!-- ═══ Toolbar ═══ -->
<form method="get" class="list-toolbar">
  <div class="search-mini">
    <span class="search-mini-icon">🔍</span>
    <input type="search" name="q" value="<?= e($q) ?>"
           placeholder="Search by name or email…" autocomplete="off">
  </div>
  <select name="role" class="mini-select" onchange="this.form.submit()">
    <option value="">All roles</option>
    <option value="buyer"  <?= $role==='buyer'?'selected':'' ?>>Buyers</option>
    <option value="seller" <?= $role==='seller'?'selected':'' ?>>Sellers</option>
    <option value="admin"  <?= $role==='admin'?'selected':'' ?>>Admins</option>
  </select>
  <select name="status" class="mini-select" onchange="this.form.submit()">
    <option value="">Any status</option>
    <option value="active"   <?= $status==='active'?'selected':'' ?>>Active</option>
    <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
    <option value="locked"   <?= $status==='locked'?'selected':'' ?>>Locked</option>
  </select>
  <?php if ($q || $role || $status): ?>
    <a href="users.php" class="btn-link">Clear</a>
  <?php endif; ?>
</form>

<?php if (!$rows): ?>
  <div class="empty-state small"><p>No users match.</p></div>
<?php else: ?>
  <div class="table-scroll">
    <table class="data-table data-table-wide" id="usersTable">
      <thead>
        <tr>
          <th style="width:36px">
            <input type="checkbox" id="selectAll" aria-label="Select all">
          </th>
          <th>User</th>
          <th>Role</th>
          <th>Status</th>
          <th class="num">Products</th>
          <th class="num">Orders (buy/sell)</th>
          <th>Joined</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $isLocked = $r['locked_until'] && strtotime($r['locked_until']) > time();
        $isSelf   = (int)$r['id'] === (int)$u['id'];
      ?>
        <tr>
          <td>
            <input type="checkbox"
                   class="row-select"
                   data-user-id="<?= (int)$r['id'] ?>"
                   aria-label="Select <?= e($r['full_name']) ?>"
                   <?= $isSelf ? 'disabled' : '' ?>>
          </td>
          <td>
            <a href="user.php?id=<?= (int)$r['id'] ?>">
              <strong><?= e($r['full_name']) ?></strong>
            </a>
            <br><small><?= e($r['email']) ?></small>
            <?php if ($isSelf): ?>
              <span class="status-pill status-info">you</span>
            <?php endif; ?>
          </td>
          <td><span class="status-pill"><?= e($r['role']) ?></span></td>
          <td>
            <?php if ($isLocked): ?>
              <span class="status-pill status-failed">locked</span>
            <?php elseif ((int)$r['is_active'] === 1): ?>
              <span class="status-pill status-completed">active</span>
            <?php else: ?>
              <span class="status-pill status-cancelled">inactive</span>
            <?php endif; ?>
          </td>
          <td class="num"><?= (int)$r['product_count'] ?></td>
          <td class="num"><?= (int)$r['buyer_orders'] ?> / <?= (int)$r['seller_orders'] ?></td>
          <td><small><?= e(date('M j, Y', strtotime($r['created_at']))) ?></small></td>
          <td class="row-actions">
            <?php if (!$isSelf): ?>
              <form method="post" action="user_action.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="redirect" value="<?= e(users_url()) ?>">
                <?php if ((int)$r['is_active'] === 1): ?>
                  <input type="hidden" name="action" value="deactivate">
                  <button class="btn-link danger">Deactivate</button>
                <?php else: ?>
                  <input type="hidden" name="action" value="activate">
                  <button class="btn-link">Activate</button>
                <?php endif; ?>
              </form>

              <?php if ($isLocked): ?>
                <form method="post" action="user_action.php" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="unlock">
                  <input type="hidden" name="redirect" value="<?= e(users_url()) ?>">
                  <button class="btn-link">Unlock</button>
                </form>
              <?php endif; ?>

              <button class="btn-link danger"
                      data-delete-user="<?= (int)$r['id'] ?>"
                      data-delete-name="<?= e($r['full_name']) ?>">Delete</button>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <nav class="pagination">
      <?php if ($page > 1): ?>
        <a href="<?= e(users_url(['page' => $page - 1])) ?>" class="page-btn">‹ Prev</a>
      <?php else: ?>
        <span class="page-btn disabled">‹ Prev</span>
      <?php endif; ?>

      <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="<?= e(users_url(['page' => $i])) ?>"
           class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
      <?php endfor; ?>

      <?php if ($page < $totalPages): ?>
        <a href="<?= e(users_url(['page' => $page + 1])) ?>" class="page-btn">Next ›</a>
      <?php else: ?>
        <span class="page-btn disabled">Next ›</span>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<!-- ═══ Delete modal ═══ -->
<div class="modal" id="deleteUserModal" hidden>
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-panel modal-panel-sm">
    <button class="modal-close" data-close-modal>×</button>
    <div class="confirm-hero">
      <div class="confirm-icon">🗑️</div>
      <h2>Delete user?</h2>
      <p class="step-sub" id="delete-user-sub">This cannot be undone.</p>
      <div class="confirm-warning">
        ⚠️ Deleting a user removes their account, <strong>all their products</strong>,
        and cascades through their orders. This action is permanent.
      </div>
      <form id="deleteUserForm" class="settings-form" style="margin-top:20px">
        <?= csrf_field() ?>
        <input type="hidden" name="user_id" id="delete-user-id">
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" data-close-modal>Cancel</button>
          <button type="submit" class="btn btn-danger">Delete user</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  /* ═══ Select-all + bulk export ═══ */
  const selectAll   = document.getElementById('selectAll');
  const downloadBtn = document.getElementById('downloadSelected');
  const checkboxes  = [...document.querySelectorAll('.row-select')];

  function updateDownloadButton() {
    const checked = checkboxes.filter(r => r.checked);
    downloadBtn.disabled = checked.length === 0;
    downloadBtn.textContent = checked.length === 0
      ? '⬇ Export selected'
      : `⬇ Export ${checked.length} user${checked.length === 1 ? '' : 's'}`;
  }

  selectAll?.addEventListener('change', () => {
    checkboxes.forEach(r => { if (!r.disabled) r.checked = selectAll.checked; });
    updateDownloadButton();
  });
  checkboxes.forEach(r => r.addEventListener('change', updateDownloadButton));
  updateDownloadButton();

  downloadBtn?.addEventListener('click', () => {
    const ids = checkboxes.filter(r => r.checked).map(r => r.dataset.userId);
    if (!ids.length) return;
    window.open(`users_export.php?ids=${ids.join(',')}`, '_blank');
  });

  /* ═══ Modal close ═══ */
  document.querySelectorAll('[data-close-modal]').forEach(el => {
    el.addEventListener('click', () => el.closest('.modal').hidden = true);
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
      document.querySelectorAll('.modal:not([hidden])').forEach(m => m.hidden = true);
  });

  /* ═══ Delete user ═══ */
  document.querySelectorAll('[data-delete-user]').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('delete-user-id').value = btn.dataset.deleteUser;
      document.getElementById('delete-user-sub').textContent =
        `Delete "${btn.dataset.deleteName}"? All their data will be removed.`;
      document.getElementById('deleteUserModal').hidden = false;
    });
  });

  document.getElementById('deleteUserForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const btn  = e.target.querySelector('button[type=submit]');
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Deleting…';
    try {
      const fd = new FormData(e.target);
      fd.append('action', 'delete');
      const r = await fetch('user_action.php', { method:'POST', body: fd });
      const j = await r.json();
      if (j.ok) {
        location.reload();
      } else {
        alert(j.error || 'Failed to delete');
        btn.disabled = false; btn.textContent = orig;
      }
    } catch {
      alert('Network error');
      btn.disabled = false; btn.textContent = orig;
    }
  });
})();
</script>

<?php require __DIR__ . '/_layout_end.php'; ?>