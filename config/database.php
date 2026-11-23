<?php
// Database connection (PDO). Default XAMPP settings: user root, no password.
$host = "localhost";
$dbname = "inventory_full_db";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
    ]);
} catch (PDOException $e) {
    exit("Database connection failed. Check config/database.php and that MySQL is running.");
}
