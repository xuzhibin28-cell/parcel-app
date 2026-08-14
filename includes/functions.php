<?php
require_once __DIR__ . '/db.php';

function generateTrackingNumber() {
    return 'TRK' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function logActivity($userId, $action, $details = '') {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
}

function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

function isRider() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'rider';
}

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function redirect($url) {
    header("Location: " . $url);
    exit;
}