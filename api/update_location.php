<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

$riderId = $_SESSION['user_id'];
$lat = floatval($_POST['lat'] ?? 0);
$lng = floatval($_POST['lng'] ?? 0);

$pdo = Database::getInstance();

// 更新当前位置
$stmt = $pdo->prepare("UPDATE riders SET last_latitude = ?, last_longitude = ?, last_update = NOW() WHERE user_id = ?");
$stmt->execute([$lat, $lng, $riderId]);

// 插入历史位置
$stmt = $pdo->prepare("INSERT INTO rider_locations (rider_id, latitude, longitude) VALUES (?, ?, ?)");
$stmt->execute([$riderId, $lat, $lng]);

echo json_encode(['success' => true]);