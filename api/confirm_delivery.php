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

$csrfToken = $_POST['csrf_token'] ?? '';
$validToken = $_SESSION['customer_token'] ?? $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || $csrfToken !== $validToken) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

$trackingNumber = sanitize($_POST['tracking_number'] ?? '');

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("SELECT id, status FROM parcels WHERE tracking_number = ?");
    $stmt->execute([$trackingNumber]);
    $parcel = $stmt->fetch();

    if (!$parcel || $parcel['status'] !== 'awaiting_confirmation') {
        echo json_encode(['success' => false, 'error' => '包裹当前不可签收']);
        exit;
    }

    // 更新为顾客已签收，等待骑手确认
    $stmt = $pdo->prepare("UPDATE parcels SET status = 'customer_confirmed', customer_confirmed_at = NOW() WHERE id = ?");
    $stmt->execute([$parcel['id']]);

    // 记录历史（changed_by 为 NULL）
    $pdo->prepare("INSERT INTO parcel_status_history (parcel_id, old_status, new_status, changed_by, remarks) VALUES (?, 'awaiting_confirmation', 'customer_confirmed', NULL, '顾客签收')")
       ->execute([$parcel['id']]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误：' . $e->getMessage()]);
}