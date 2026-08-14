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
$status = ($_POST['status'] === 'online') ? 'online' : 'offline';

$pdo = Database::getInstance();

// 检查骑手是否有 riders 记录
$stmt = $pdo->prepare("SELECT user_id FROM riders WHERE user_id = ?");
$stmt->execute([$riderId]);
if (!$stmt->fetch()) {
    $stmt = $pdo->prepare("INSERT INTO riders (user_id, status, work_status) VALUES (?, 'offline', 'idle')");
    $stmt->execute([$riderId]);
}

// 如果要下线，检查是否存在配送中的包裹
if ($status === 'offline') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM parcels WHERE assigned_rider = ? AND status = 'out_for_delivery'");
    $stmt->execute([$riderId]);
    $activeDeliveries = $stmt->fetchColumn();

    if ($activeDeliveries > 0) {
        echo json_encode(['success' => false, 'error' => '您还有未完成的配送包裹，请先完成所有配送再下线。']);
        exit;
    }
}

// 更新在线状态
$stmt = $pdo->prepare("UPDATE riders SET status = ? WHERE user_id = ?");
$stmt->execute([$status, $riderId]);

// 上线/下线时，工作状态均设为 idle（下线不能派送，上线默认空闲）
$stmt = $pdo->prepare("UPDATE riders SET work_status = 'idle' WHERE user_id = ?");
$stmt->execute([$riderId]);

logActivity($riderId, "Changed status to $status");
echo json_encode(['success' => true]);