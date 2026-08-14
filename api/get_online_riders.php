<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$pdo = Database::getInstance();
// 获取所有骑手，包括在线和离线，以及最后位置
$riders = $pdo->query("
    SELECT u.id, u.name, r.status, r.last_latitude as latitude, r.last_longitude as longitude, r.last_update
    FROM users u
    JOIN riders r ON u.id = r.user_id
    WHERE u.role = 'rider'
    ORDER BY r.status DESC, u.name ASC
")->fetchAll();

echo json_encode($riders);