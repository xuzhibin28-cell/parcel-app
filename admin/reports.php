<?php
$page_title = '配送报表';
$allowed_roles = ['admin'];
require_once '../includes/auth.php';
require_once '../includes/header.php';
$pdo = Database::getInstance();
// 按骑手统计成功配送数
$report = $pdo->query("SELECT u.name, COUNT(p.id) as delivered_count
    FROM parcels p JOIN users u ON p.assigned_rider = u.id
    WHERE p.status = 'delivered'
    GROUP BY u.id")->fetchAll();
?>
<h1>骑手配送报表</h1>
<table class="data-table">
    <thead><tr><th>骑手</th><th>成功配送数量</th></tr></thead>
    <tbody>
    <?php foreach($report as $r): ?>
        <tr><td><?= $r['name'] ?></td><td><?= $r['delivered_count'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php require_once '../includes/footer.php'; ?>