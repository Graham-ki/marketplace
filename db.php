<?php
declare(strict_types=1);

/* ─────────────────────────────────────────────
   CONFIG + DB + HELPERS  (single-file bootstrap)
   Include this at the top of every page:
       require __DIR__ . '/db.php';
   ───────────────────────────────────────────── */

// ── Load .env ──
$envPath = __DIR__ . '/.env';
if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}
function env(string $k, $d = null) { return $_ENV[$k] ?? $d; }
define('APP_ENV', env('APP_ENV', 'production'));

// ── Errors (dev: show, prod: hide) ──
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// ── Session (hardened cookies) ──
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// ── DB (lazy PDO singleton) ──
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . env('DB_HOST','127.0.0.1')
         . ';dbname='    . env('DB_NAME','marketplace')
         . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, env('DB_USER','root'), env('DB_PASS',''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        die(APP_ENV === 'development' ? 'DB: ' . $e->getMessage() : 'Service unavailable');
    }
    return $pdo;
}

// ── Auth helpers ──
function current_user(): ?array {
    return isset($_SESSION['user_id']) ? [
        'id'   => (int)$_SESSION['user_id'],
        'role' => $_SESSION['role'] ?? 'buyer',
        'name' => $_SESSION['name'] ?? '',
    ] : null;
}
function require_login(): void {
    if (!current_user()) { header('Location: /login.php'); exit; }
}
function login_user(array $u): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['role']    = $u['role'];
    $_SESSION['name']    = $u['full_name'];
}

// ── Security helpers ──
function csrf_token(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}
function csrf_ok(?string $t): bool {
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function flash(string $key, ?string $val = null): ?string {
    if ($val !== null) { $_SESSION['_f'][$key] = $val; return null; }
    $v = $_SESSION['_f'][$key] ?? null;
    unset($_SESSION['_f'][$key]);
    return $v;
}

// ── Draft token (for guest onboarding) ──
$_SESSION['draft_token'] ??= bin2hex(random_bytes(32));
function draft_token(): string { return $_SESSION['draft_token']; }