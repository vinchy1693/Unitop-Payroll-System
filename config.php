<?php
// =====================================================================
// config.php — Database connection (PDO)
// Update these 4 values to match your local MySQL setup.
// =====================================================================
$DB_HOST = "localhost";
$DB_NAME = "payroll_db";
$DB_USER = "root";
$DB_PASS = "";

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    // If config.php is loaded from api/_bootstrap.php this will still be
    // caught there and turned into a JSON error response.
    throw $e;
}
