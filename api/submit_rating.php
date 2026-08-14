<?php
// 关闭错误输出，防止破坏 JSON
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

// CSRF 验证
$csrfToken = $_POST['csrf_token'] ?? '';
$validToken = $_SESSION['customer_token'] ?? $_SESSION['csrf_token'] ?? '';

if (empty($csrfToken) || $csrfToken !== $validToken) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

$parcelId = intval($_POST['parcel_id']);
$rating = intval($_POST['rating'] ?? 0);
$comment = sanitize($_POST['comment'] ?? '');

if ($rating < 1 || $rating > 5) {
    echo json_encode(['success' => false, 'error' => '评分必须在1-5之间']);
    exit;
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("SELECT p.id, p.status, (SELECT COUNT(*) FROM ratings WHERE parcel_id = p.id) as rated FROM parcels p WHERE p.id = ?");
    $stmt->execute([$parcelId]);
    $parcel = $stmt->fetch();

    if (!$parcel) {
        echo json_encode(['success' => false, 'error' => '包裹不存在']);
        exit;
    }
    if ($parcel['status'] !== 'delivered') {
        echo json_encode(['success' => false, 'error' => '只能评价已送达的包裹']);
        exit;
    }
    if ($parcel['rated'] > 0) {
        echo json_encode(['success' => false, 'error' => '该包裹已经被评价']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO ratings (parcel_id, rating, comment) VALUES (?, ?, ?)");
    $stmt->execute([$parcelId, $rating, $comment]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误: ' . $e->getMessage()]);
}