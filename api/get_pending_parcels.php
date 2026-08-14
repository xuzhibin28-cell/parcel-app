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
$parcels = $pdo->query("
    SELECT id, recipient_name, recipient_address, latitude, longitude 
    FROM parcels 
    WHERE status = 'pending' AND assigned_rider IS NULL AND latitude IS NOT NULL
    ORDER BY created_at DESC
")->fetchAll();

echo json_encode($parcels);