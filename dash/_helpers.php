<?php
// Small utilities used by every tab.

function money(float $n): string {
    return 'UGX ' . number_format($n, 2);
}

function status_pill(string $status): string {
    $status = strtolower($status);
    return '<span class="status-pill status-' . e($status) . '">' . e($status) . '</span>';
}

function dash_header(string $title, string $sub = '', string $actionHtml = ''): void {
    ?>
    <header class="dash-top">
      <div>
        <h1><?= e($title) ?></h1>
        <?php if ($sub): ?><p class="step-sub"><?= e($sub) ?></p><?php endif; ?>
      </div>
      <div class="dash-top-actions"><?= $actionHtml ?></div>
    </header>
    <?php
}
function time_ago(string $ts): string {
    $diff = time() - strtotime($ts);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff/60) . 'm ago';
    if ($diff < 86400)  return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('M j', strtotime($ts));
}