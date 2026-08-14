<?php
$page_title = '配送历史';
$allowed_roles = ['rider'];
require_once '../includes/auth.php';
require_once '../includes/header.php';

$pdo = Database::getInstance();
$riderId = $_SESSION['user_id'];

// 查询该骑手所有包裹的配送历史（按时间倒序）
$stmt = $pdo->prepare("
    SELECT p.tracking_number, p.recipient_name, p.status, p.updated_at,
           psh.old_status, psh.new_status, psh.remarks, psh.created_at AS status_time, psh.photo_path
    FROM parcels p
    LEFT JOIN parcel_status_history psh ON p.id = psh.parcel_id
    WHERE p.assigned_rider = ?
    ORDER BY psh.created_at DESC
");
$stmt->execute([$riderId]);
$history = $stmt->fetchAll();
?>

<h1>我的配送历史</h1>

<?php if (count($history) === 0): ?>
    <p>暂无历史记录。</p>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>追踪号</th>
                <th>收件人</th>
                <th>当前状态</th>
                <th>变更时间</th>
                <th>备注</th>
                <th>照片</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr>
                <td><?= $h['tracking_number'] ?></td>
                <td><?= sanitize($h['recipient_name']) ?></td>
                <td>
                    <span class="status-badge status-<?= $h['status'] ?>">
                        <?= $h['status'] ?>
                    </span>
                </td>
                <td><?= $h['status_time'] ?? $h['updated_at'] ?></td>
                <td><?= sanitize($h['remarks'] ?? '') ?></td>
                <td>
                    <?php if (!empty($h['photo_path'])): ?>
                        <img src="<?= BASE_URL . $h['photo_path'] ?>" width="60" alt="证明">
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>