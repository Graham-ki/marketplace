<?php
/**
 * One-off seeder: creates a test user with a properly hashed password.
 * Run: php seed_user.php
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/core/Database.php';

// ─── Configure the user you want to create ───
$fullName = 'Demo Seller';
$email    = 'demo@shop.com';
$phone    = '+256700000000';
$password = 'demo1234';          // plain — will be hashed before storing
$role     = 'seller';            // 'seller' or 'buyer'

// ─── Hash the password ───
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// ─── Insert ───
$pdo = Database::getInstance()->pdo();

try {
    $stmt = $pdo->prepare("
        INSERT INTO users (full_name, email, phone, password_hash, role, is_active)
        VALUES (?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([$fullName, $email, $phone, $hash, $role]);

    echo "✅ User created successfully\n";
    echo "   ID:       " . $pdo->lastInsertId() . "\n";
    echo "   Email:    $email\n";
    echo "   Password: $password\n";
    echo "   Role:     $role\n";
    echo "   Hash:     $hash\n";
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo "❌ User already exists with email: $email\n";
        echo "   Delete first: DELETE FROM users WHERE email = ?;\n";
    } else {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}