<?php
// MyGradebook v1.15 database configuration for InfinityFree
$DB_HOST = 'sql102.infinityfree.com';
$DB_PORT = '3306';
$DB_NAME = 'if0_42692604_mygradebook';
$DB_USER = 'if0_42692604';
$DB_PASS = 'VC1itbTImGUJjog';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'message' => 'MyGradebook database connection failed. Check your InfinityFree MySQL settings in config/database.php.',
        'detail' => $e->getMessage()
    ]);
    exit;
}
