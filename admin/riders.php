<?php
$page_title = '骑手管理';
$allowed_roles = ['admin'];
require_once '../includes/auth.php';
require_once '../includes/header.php';

$pdo = Database::getInstance();
$riders = $pdo->query("
    SELECT u.id, u.name, u.email, r.phone, r.vehicle_type, r.status, r.work_status
    FROM users u 
    JOIN riders r ON u.id = r.user_id 
    WHERE u.role = 'rider'
    ORDER BY r.status DESC, r.work_status ASC
")->fetchAll();
?>

<h1>骑手列表</h1>
<table class="data-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>姓名</th>
            <th>邮箱</th>
            <th>电话</th>
            <th>车辆</th>
            <th>在线状态</th>
            <th>工作状态</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($riders as $r): ?>
        <tr>
            <td><?= $r['id'] ?></td>
            <td><?= sanitize($r['name']) ?></td>
            <td><?= $r['email'] ?></td>
            <td><?= $r['phone'] ?? '—' ?></td>
            <td><?= $r['vehicle_type'] ?? '—' ?></td>
            <td>
                <span class="badge badge-<?= $r['status'] === 'online' ? 'online' : 'offline' ?>">
                    <?= $r['status'] === 'online' ? '在线' : '离线' ?>
                </span>
            </td>
            <td>
                <?php if ($r['status'] === 'offline'): ?>
                    <span class="badge badge-offline">离线</span>
                <?php elseif ($r['work_status'] === 'delivering'): ?>
                    <span class="badge badge-delivering">派送中</span>
                <?php else: ?>
                    <span class="badge badge-idle">空闲</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<style>
.badge-delivering { background: #e67e22; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; }
.badge-idle { background: #27ae60; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; }
.badge-offline { background: #95a5a6; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; }
</style>

<?php require_once '../includes/footer.php'; ?>