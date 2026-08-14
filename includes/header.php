<?php
if (!isset($page_title)) {
    $page_title = '包裹配送系统';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <title><?= $page_title ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<div class="sidebar">
    <h2>配送系统</h2>
    <?php if (isAdmin()): ?>
        <a href="<?= BASE_URL ?>admin/dashboard.php">📊 仪表盘</a>
        <a href="<?= BASE_URL ?>admin/parcels.php">📦 包裹管理</a>
        <a href="<?= BASE_URL ?>admin/riders.php">🛵 骑手管理</a>
        <a href="<?= BASE_URL ?>admin/map.php">🗺️ 实时地图</a>
        <a href="<?= BASE_URL ?>admin/reports.php">📈 报表</a>
    <?php else: ?>
        <a href="<?= BASE_URL ?>rider/dashboard.php">📋 我的面板</a>
        <a href="<?= BASE_URL ?>rider/parcels.php">📦 我的包裹</a>
        <a href="<?= BASE_URL ?>rider/map.php">🗺️ 配送地图</a>
        <a href="<?= BASE_URL ?>rider/history.php">📜 历史记录</a>
        <a href="<?= BASE_URL ?>rider/profile.php">👤 个人资料</a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>logout.php">🚪 退出</a>
</div>
<div class="main">