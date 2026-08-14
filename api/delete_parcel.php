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
if (!isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}
if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

$parcelId = intval($_POST['parcel_id']);

$pdo = Database::getInstance();
$stmt = $pdo->prepare("SELECT id, status FROM parcels WHERE id = ?");
$stmt->execute([$parcelId]);
$parcel = $stmt->fetch();

if (!$parcel) {
    echo json_encode(['success' => false, 'error' => 'Parcel not found']);
    exit;
}
if ($parcel['status'] !== 'delivered') {
    echo json_encode(['success' => false, 'error' => '只有已送达的包裹才能删除']);
    exit;
}

$stmt = $pdo->prepare("DELETE FROM parcels WHERE id = ?");
$stmt->execute([$parcelId]);

logActivity($_SESSION['user_id'], "Deleted parcel #$parcelId");
echo json_encode(['success' => true]);