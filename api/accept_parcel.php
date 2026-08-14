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
$parcelId = intval($_POST['parcel_id']);

$pdo = Database::getInstance();

// 检查包裹是否可抢
$stmt = $pdo->prepare("SELECT id FROM parcels WHERE id = ? AND status = 'pending' AND assigned_rider IS NULL");
$stmt->execute([$parcelId]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => '该包裹已被抢走或状态已变更']);
    exit;
}

// 更新包裹
$stmt = $pdo->prepare("UPDATE parcels SET assigned_rider = ?, status = 'out_for_delivery' WHERE id = ?");
$stmt->execute([$riderId, $parcelId]);

// 记录历史
$pdo->prepare("INSERT INTO parcel_status_history (parcel_id, old_status, new_status, changed_by, remarks) VALUES (?, 'pending', 'out_for_delivery', ?, '骑手自主接单')")
   ->execute([$parcelId, $riderId]);

// 设置骑手工作状态为派送中
$stmt = $pdo->prepare("UPDATE riders SET work_status = 'delivering' WHERE user_id = ?");
$stmt->execute([$riderId]);

logActivity($riderId, "接单包裹 #$parcelId");
echo json_encode(['success' => true]);