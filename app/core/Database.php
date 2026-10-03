<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $host = env('DB_HOST', '127.0.0.1');
        $db   = env('DB_NAME', 'marketplace');
        $user = env('DB_USER', 'root');
        $pass = env('DB_PASS', '');
        $char = env('DB_CHARSET', 'utf8mb4');

        $dsn = "mysql:host=$host;dbname=$db;charset=$char";
        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(APP_ENV === 'development'
                ? 'DB connection failed: ' . $e->getMessage()
                : 'Service temporarily unavailable.');
        }
    }

    public static function getInstance(): Database
    {
        return self::$instance ??= new self();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}