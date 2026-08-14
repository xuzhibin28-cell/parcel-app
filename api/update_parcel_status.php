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
$newStatus = $_POST['status'] ?? '';

$allowed = ['out_for_delivery', 'delivered', 'failed', 'awaiting_confirmation'];
if (!in_array($newStatus, $allowed)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status']);
    exit;
}

$pdo = Database::getInstance();

$stmt = $pdo->prepare("SELECT * FROM parcels WHERE id = ? AND assigned_rider = ?");
$stmt->execute([$parcelId, $riderId]);
$parcel = $stmt->fetch();

if (!$parcel) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$oldStatus = $parcel['status'];
$remarks = sanitize($_POST['remarks'] ?? '');

$failureReason = '';
$rescheduledDate = null;

if ($newStatus === 'failed') {
    $failureReason = sanitize($_POST['failure_reason'] ?? '');
    $rescheduledDate = $_POST['rescheduled_date'] ?? null;
    if ($rescheduledDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rescheduledDate)) {
        $rescheduledDate = null;
    }
}

// 更新包裹状态
$stmt = $pdo->prepare("UPDATE parcels SET status = ?, failure_reason = ?, rescheduled_date = ? WHERE id = ?");
$stmt->execute([$newStatus, $failureReason, $rescheduledDate, $parcelId]);

// 如果最终送达，记录完成时间
if ($newStatus === 'delivered') {
    $pdo->prepare("UPDATE parcels SET delivered_at = NOW() WHERE id = ?")->execute([$parcelId]);
}

// 记录状态历史
$stmt = $pdo->prepare("INSERT INTO parcel_status_history (parcel_id, old_status, new_status, changed_by, remarks) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$parcelId, $oldStatus, $newStatus, $riderId, $remarks]);

// 自动更新骑手工作状态
if ($newStatus === 'delivered' || $newStatus === 'failed') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM parcels WHERE assigned_rider = ? AND status = 'out_for_delivery'");
    $stmt->execute([$riderId]);
    $activeDeliveries = $stmt->fetchColumn();
    if ($activeDeliveries == 0) {
        $stmt = $pdo->prepare("UPDATE riders SET work_status = 'idle' WHERE user_id = ?");
        $stmt->execute([$riderId]);
    }
} elseif ($newStatus === 'out_for_delivery') {
    $stmt = $pdo->prepare("UPDATE riders SET work_status = 'delivering' WHERE user_id = ?");
    $stmt->execute([$riderId]);
}

logActivity($riderId, "Changed parcel #$parcelId status to $newStatus");
echo json_encode(['success' => true]);