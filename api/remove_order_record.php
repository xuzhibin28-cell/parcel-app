<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF 验证
$csrfToken = $_POST['csrf_token'] ?? '';
$validToken = $_SESSION['customer_token'] ?? $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || $csrfToken !== $validToken) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

$trackingNumber = sanitize($_POST['tracking_number'] ?? '');

// 检查该订单是否在当前会话中
if (!in_array($trackingNumber, $_SESSION['my_orders'] ?? [])) {
    echo json_encode(['success' => false, 'error' => '记录不存在']);
    exit;
}

// 从会话中移除该追踪号
$_SESSION['my_orders'] = array_values(array_diff($_SESSION['my_orders'], [$trackingNumber]));

echo json_encode(['success' => true]);