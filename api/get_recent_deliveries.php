<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$pdo = Database::getInstance();
$deliveries = $pdo->query("
    SELECT p.tracking_number, p.recipient_name, p.delivered_at, u.name as rider_name
    FROM parcels p
    JOIN users u ON p.assigned_rider = u.id
    WHERE p.status = 'delivered'
    ORDER BY p.delivered_at DESC
    LIMIT 5
")->fetchAll();

echo json_encode($deliveries);