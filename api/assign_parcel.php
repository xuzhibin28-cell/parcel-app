<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
if(!isAdmin() || $_SERVER['REQUEST_METHOD']!=='POST') die;
if($_POST['csrf_token']!==$_SESSION['csrf_token']) die('CSRF');
$parcelId = intval($_POST['parcel_id']);
$riderId = intval($_POST['rider_id']);
$pdo = Database::getInstance();
$pdo->prepare("UPDATE parcels SET assigned_rider=? WHERE id=?")->execute([$riderId, $parcelId]);
logActivity($_SESSION['user_id'], "Assigned parcel #$parcelId to rider #$riderId");
echo json_encode(['success'=>true]);