<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

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

$parcelId = intval($_POST['parcel_id']);

$pdo = Database::getInstance();

// 检查包裹是否待分配
$stmt = $pdo->prepare("SELECT id, assigned_rider, status FROM parcels WHERE id = ?");
$stmt->execute([$parcelId]);
$parcel = $stmt->fetch();

if (!$parcel || $parcel['status'] !== 'pending' || !is_null($parcel['assigned_rider'])) {
    echo json_encode(['success' => false, 'error' => '包裹不可分配']);
    exit;
}

// 获取最合适的骑手：在线 + 空闲 + 当前配送中包裹最少
$stmt = $pdo->query("
    SELECT u.id, u.name, 
           (SELECT COUNT(*) FROM parcels p WHERE p.assigned_rider = u.id AND p.status = 'out_for_delivery') as active_count
    FROM users u
    JOIN riders r ON u.id = r.user_id
    WHERE u.role = 'rider' AND r.status = 'online' AND r.work_status = 'idle'
    ORDER BY active_count ASC, u.id ASC
    LIMIT 1
");
$rider = $stmt->fetch();

if (!$rider) {
    echo json_encode(['success' => false, 'error' => '没有可用的空闲骑手']);
    exit;
}

// 分配订单
$stmt = $pdo->prepare("UPDATE parcels SET assigned_rider = ?, status = 'out_for_delivery' WHERE id = ?");
$stmt->execute([$rider['id'], $parcelId]);

// 更新骑手工作状态
$stmt = $pdo->prepare("UPDATE riders SET work_status = 'delivering' WHERE user_id = ?");
$stmt->execute([$rider['id']]);

// 记录历史
$pdo->prepare("INSERT INTO parcel_status_history (parcel_id, old_status, new_status, changed_by, remarks) VALUES (?, 'pending', 'out_for_delivery', ?, '智能分配')")
   ->execute([$parcelId, $_SESSION['user_id']]);

logActivity($_SESSION['user_id'], "Auto assigned parcel #$parcelId to rider #{$rider['id']} ({$rider['name']})");

echo json_encode(['success' => true, 'rider_name' => $rider['name']]);