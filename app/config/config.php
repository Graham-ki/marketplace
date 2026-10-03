<?php
/**
 * Central configuration loader.
 * Reads .env, defines constants, sets sane defaults.
 */

declare(strict_types=1);

// Load .env
$envFile = __DIR__ . '/../../.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v, " \t\n\r\0\x0B\"'");
        $_ENV[$k] = $v;
        putenv("$k=$v");
    }
}

function env(string $key, $default = null) {
    return $_ENV[$key] ?? $default;
}

// Error reporting
if (env('APP_ENV', 'production') === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// App constants
define('APP_URL',  env('APP_URL', 'http://localhost:8000'));
define('APP_ENV',  env('APP_ENV', 'production'));
define('BASE_PATH', dirname(__DIR__, 2));
define('PUBLIC_PATH', BASE_PATH . '/public');
define('UPLOAD_PATH', PUBLIC_PATH . '/uploads');

// Session cookie hardening (before session_start)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    // Uncomment on HTTPS:
    // ini_set('session.cookie_secure', '1');
}