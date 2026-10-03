<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/Session.php';

class Security
{
    public static function hashPassword(string $pwd): string
    {
        return password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $pwd, string $hash): bool
    {
        return password_verify($pwd, $hash);
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::csrfToken() . '">';
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token)
            && !empty($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }

    public static function sanitize(?string $data): string
    {
        return htmlspecialchars(trim((string)$data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
}