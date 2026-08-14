<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

$page_title = '骑手仪表盘';
$allowed_roles = ['rider'];
require_once '../includes/header.php';

$pdo = Database::getInstance();
$riderId = $_SESSION['user_id'];

// 获取骑手信息
$stmt = $pdo->prepare("SELECT r.status, r.vehicle_type, r.phone, u.name 
                       FROM riders r 
                       JOIN users u ON r.user_id = u.id 
                       WHERE r.user_id = ?");
$stmt->execute([$riderId]);
$rider = $stmt->fetch();
$isOnline = $rider && $rider['status'] === 'online';
$vehicle = $rider['vehicle_type'] ?? '未设置';
$riderName = $rider['name'] ?? '骑手';

// 统计数据（修正：分开执行）
$stmt = $pdo->prepare("SELECT COUNT(*) FROM parcels WHERE assigned_rider = ?");
$stmt->execute([$riderId]);
$totalParcels = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM parcels WHERE assigned_rider = ? AND status = 'delivered'");
$stmt->execute([$riderId]);
$delivered = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM parcels WHERE assigned_rider = ? AND status = 'failed'");
$stmt->execute([$riderId]);
$failed = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM parcels WHERE assigned_rider = ? AND DATE(created_at) = CURDATE()");
$stmt->execute([$riderId]);
$todayAssigned = $stmt->fetchColumn();

// 平均评分
$stmt = $pdo->prepare("
    SELECT AVG(rat.rating) as avg_rating, COUNT(rat.id) as total_ratings 
    FROM ratings rat 
    JOIN parcels p ON rat.parcel_id = p.id 
    WHERE p.assigned_rider = ?
");
$stmt->execute([$riderId]);
$avgRating = $stmt->fetch();

// 当前配送中的包裹（out_for_delivery）
$stmt = $pdo->prepare("
    SELECT tracking_number, recipient_name, recipient_address, latitude, longitude, updated_at 
    FROM parcels 
    WHERE assigned_rider = ? AND status = 'out_for_delivery'
    ORDER BY updated_at DESC
");
$stmt->execute([$riderId]);
$activeParcels = $stmt->fetchAll();

// 最近完成的包裹（delivered），同时获取评价
$stmt = $pdo->prepare("
    SELECT p.tracking_number, p.recipient_name, p.updated_at as delivered_at, 
           rat.rating, rat.comment 
    FROM parcels p 
    LEFT JOIN ratings rat ON p.id = rat.parcel_id 
    WHERE p.assigned_rider = ? AND p.status = 'delivered'
    ORDER BY p.updated_at DESC
    LIMIT 20
");
$stmt->execute([$riderId]);
$completedParcels = $stmt->fetchAll();
?>

<h1>欢迎，<?= sanitize($riderName) ?></h1>

<div style="background: #f9f9f9; padding: 0.8rem 1rem; border-radius: 5px; margin-bottom: 1.5rem;">
    <strong>🚛 载具：</strong><?= $vehicle ?> &nbsp;&nbsp;
    <strong>📅 日期：</strong><span id="currentDate"></span> &nbsp;&nbsp;
    <strong>⏰ 时间：</strong><span id="currentTime"></span>
</div>

<button id="toggleOnline" class="btn <?= $isOnline ? 'btn-danger' : 'btn-success' ?>">
    <?= $isOnline ? '下线' : '上线' ?>
</button>

<div class="cards" style="margin-top: 1.5rem;">
    <div class="card">
        <h3>📦 总包裹</h3>
        <p><?= $totalParcels ?></p>
    </div>
    <div class="card">
        <h3>✅ 成功送达</h3>
        <p><?= $delivered ?></p>
    </div>
    <div class="card">
        <h3>❌ 配送失败</h3>
        <p><?= $failed ?></p>
    </div>
    <div class="card">
        <h3>📅 今日分配</h3>
        <p><?= $todayAssigned ?></p>
    </div>
    <div class="card">
        <h3>⭐ 平均评分</h3>
        <p><?= $avgRating['avg_rating'] ? number_format($avgRating['avg_rating'], 1) . ' / 5' : '暂无' ?></p>
        <small>共 <?= $avgRating['total_ratings'] ?> 次评价</small>
    </div>
</div>

<hr>

<h2>🚚 配送中的包裹</h2>
<?php if (count($activeParcels) === 0): ?>
    <p>当前没有正在配送的包裹。</p>
<?php else: ?>
    <table class="data-table">
        <thead><tr><th>追踪号</th><th>收件人</th><th>地址/坐标</th><th>最后更新</th></tr></thead>
        <tbody>
        <?php foreach ($activeParcels as $p): ?>
            <tr>
                <td><?= $p['tracking_number'] ?></td>
                <td><?= sanitize($p['recipient_name']) ?></td>
                <td>
                    <?= sanitize($p['recipient_address'] ?? '') ?>
                    <?php if ($p['latitude']): ?>
                        <br><small>📍 <?= $p['latitude'] ?>, <?= $p['longitude'] ?></small>
                    <?php endif; ?>
                </td>
                <td><?= $p['updated_at'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>✅ 最近完成的包裹</h2>
<?php if (count($completedParcels) === 0): ?>
    <p>还没有已送达的包裹。</p>
<?php else: ?>
    <table class="data-table">
        <thead><tr><th>追踪号</th><th>收件人</th><th>完成时间</th><th>顾客评价</th></tr></thead>
        <tbody>
        <?php foreach ($completedParcels as $p): ?>
            <tr>
                <td><?= $p['tracking_number'] ?></td>
                <td><?= sanitize($p['recipient_name']) ?></td>
                <td><?= $p['delivered_at'] ?></td>
                <td>
                    <?php if ($p['rating']): ?>
                        🌟 <?= $p['rating'] ?>/5
                        <?php if (!empty($p['comment'])): ?>
                            <br><small>💬 <?= sanitize($p['comment']) ?></small>
                        <?php endif; ?>
                    <?php else: ?>
                        暂无评价
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<script src="../assets/js/tracking.js"></script>
<script>
// 动态更新时间
function updateDateTime() {
    const now = new Date();
    const dateStr = now.toLocaleDateString('zh-CN', { year:'numeric', month:'2-digit', day:'2-digit' });
    const timeStr = now.toLocaleTimeString('zh-CN', { hour12: false });
    document.getElementById('currentDate').textContent = dateStr;
    document.getElementById('currentTime').textContent = timeStr;
}
updateDateTime();
setInterval(updateDateTime, 1000);
</script>

<?php require_once '../includes/footer.php'; ?>