<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF 验证（使用顾客 token）
$csrfToken = $_POST['csrf_token'] ?? '';
$validToken = $_SESSION['customer_token'] ?? $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || $csrfToken !== $validToken) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

$trackingNumber = sanitize($_POST['tracking_number'] ?? '');

// 验证该追踪号是否属于当前会话中的订单（顾客只能取消自己下的单）
if (!in_array($trackingNumber, $_SESSION['my_orders'] ?? [])) {
    echo json_encode(['success' => false, 'error' => '无权取消此订单']);
    exit;
}

$pdo = Database::getInstance();

// 检查包裹状态和分配情况
$stmt = $pdo->prepare("SELECT id, status, assigned_rider FROM parcels WHERE tracking_number = ?");
$stmt->execute([$trackingNumber]);
$parcel = $stmt->fetch();

if (!$parcel) {
    echo json_encode(['success' => false, 'error' => '包裹不存在']);
    exit;
}
if ($parcel['status'] !== 'pending') {
    echo json_encode(['success' => false, 'error' => '只能取消待接单的包裹']);
    exit;
}
if (!is_null($parcel['assigned_rider'])) {
    echo json_encode(['success' => false, 'error' => '包裹已被骑手接单，无法取消']);
    exit;
}

// 更新状态为 cancelled
$stmt = $pdo->prepare("UPDATE parcels SET status = 'cancelled' WHERE id = ?");
$stmt->execute([$parcel['id']]);

echo json_encode(['success' => true]);