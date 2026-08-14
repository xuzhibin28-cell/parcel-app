<?php
$page_title = '我的包裹';
$allowed_roles = ['rider'];
require_once '../includes/auth.php';
require_once '../includes/header.php';

$pdo = Database::getInstance();
$riderId = $_SESSION['user_id'];

// 可抢订单：未被分配且状态为 pending
$available = $pdo->query("SELECT * FROM parcels WHERE status = 'pending' AND assigned_rider IS NULL ORDER BY created_at DESC")->fetchAll();

// 我的包裹：已分配给当前骑手，且未最终完成（排除 delivered 和 cancelled）
$stmt = $pdo->prepare("
    SELECT p.*, rat.rating, rat.comment,
           (SELECT created_at FROM parcel_status_history 
            WHERE parcel_id = p.id AND new_status = 'out_for_delivery' 
            ORDER BY created_at ASC LIMIT 1) as accept_time
    FROM parcels p 
    LEFT JOIN ratings rat ON p.id = rat.parcel_id 
    WHERE p.assigned_rider = ? 
      AND p.status NOT IN ('delivered','cancelled')
    ORDER BY accept_time ASC, p.created_at ASC
");
$stmt->execute([$riderId]);
$myParcels = $stmt->fetchAll();
?>

<h1>配送工作台</h1>

<h2>🆕 待抢订单</h2>
<?php if (count($available) === 0): ?>
    <p>当前没有待接单的包裹。</p>
<?php else: ?>
    <table class="data-table">
        <thead><tr><th>追踪号</th><th>收件人</th><th>地址/坐标</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($available as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['tracking_number']) ?></td>
                <td><?= htmlspecialchars($p['recipient_name']) ?></td>
                <td>
                    <?= htmlspecialchars($p['recipient_address'] ?? '') ?>
                    <?php if ($p['latitude']): ?>
                        <br><small>📍 <?= htmlspecialchars($p['latitude']) ?>, <?= htmlspecialchars($p['longitude']) ?></small>
                    <?php endif; ?>
                </td>
                <td><button onclick="acceptParcel(<?= (int)$p['id'] ?>)" class="btn btn-success btn-sm">抢单</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<hr>
<h2>📦 我的包裹</h2>
<?php if (count($myParcels) === 0): ?>
    <p>您目前没有需要处理的包裹。</p>
<?php else: ?>
    <table class="data-table">
        <thead><tr><th>优先</th><th>追踪号</th><th>收件人</th><th>状态</th><th>接单时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php $priority = 1; foreach ($myParcels as $p): ?>
            <tr>
                <td><strong>#<?= $priority++ ?></strong></td>
                <td><?= htmlspecialchars($p['tracking_number']) ?></td>
                <td><?= htmlspecialchars($p['recipient_name']) ?></td>
                <td>
                    <span class="status-badge status-<?= htmlspecialchars($p['status']) ?>">
                        <?php
                        $statusTexts = [
                            'pending' => '待处理',
                            'out_for_delivery' => '派送中',
                            'awaiting_confirmation' => '等待顾客签收',
                            'customer_confirmed' => '顾客已签收，请确认送达',
                            'failed' => '配送失败'
                        ];
                        echo $statusTexts[$p['status']] ?? htmlspecialchars($p['status']);
                        ?>
                    </span>
                </td>
                <td><?= $p['accept_time'] ?? '—' ?></td>
                <td>
                    <?php if ($p['status'] === 'pending'): ?>
                        <button onclick="updateStatus(<?= (int)$p['id'] ?>, 'out_for_delivery')" class="btn btn-primary btn-sm">开始配送</button>
                    <?php elseif ($p['status'] === 'out_for_delivery'): ?>
                        <?php if ($p['source'] === 'admin'): ?>
                            <button onclick="updateStatus(<?= (int)$p['id'] ?>, 'delivered')" class="btn btn-success btn-sm">已送达</button>
                        <?php else: ?>
                            <button onclick="updateStatus(<?= (int)$p['id'] ?>, 'awaiting_confirmation')" class="btn btn-success btn-sm">请求签收</button>
                        <?php endif; ?>
                        <button onclick="updateStatus(<?= (int)$p['id'] ?>, 'failed')" class="btn btn-danger btn-sm">配送失败</button>
                        <button onclick="uploadPhoto(<?= (int)$p['id'] ?>)" class="btn btn-info btn-sm">📷 拍照</button>
                    <?php elseif ($p['status'] === 'awaiting_confirmation'): ?>
                        <span class="badge badge-warning">等待顾客签收</span>
                    <?php elseif ($p['status'] === 'customer_confirmed'): ?>
                        <button onclick="updateStatus(<?= (int)$p['id'] ?>, 'delivered')" class="btn btn-success btn-sm">✅ 已送达</button>
                    <?php elseif ($p['status'] === 'failed'): ?>
                        <button onclick="retryDelivery(<?= (int)$p['id'] ?>)" class="btn btn-warning btn-sm">🔄 重新配送</button>
                    <?php endif; ?>
                    <!-- 导航按钮 -->
                    <?php if ($p['latitude']): ?>
                        <a href="map.php?focus=<?= (int)$p['id'] ?>" class="btn btn-sm">🧭 导航</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<input type="file" id="photoInput" accept="image/*" capture="environment" style="display:none">

<script>
// 所有函数完整实现
async function acceptParcel(parcelId) {
    if (!confirm('确定要接这个订单吗？')) return;
    const resp = await fetch('../api/accept_parcel.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `parcel_id=${parcelId}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
    });
    const data = await resp.json();
    if (data.success) location.reload();
    else alert('接单失败：' + (data.error || ''));
}

async function updateStatus(parcelId, status) {
    let failureReason = '', rescheduledDate = '', remark = '';
    if (status === 'failed') {
        failureReason = prompt('失败原因（必填）：');
        if (!failureReason) return;
        rescheduledDate = prompt('重新配送日期（YYYY-MM-DD，可选）：');
        remark = prompt('备注（可选）：');
    } else if (status === 'awaiting_confirmation') {
        remark = prompt('备注（可选，例如放前台）：');
    } else {
        remark = prompt('备注（可选）：');
    }
    const body = `parcel_id=${parcelId}&status=${status}&remarks=${encodeURIComponent(remark || '')}&failure_reason=${encodeURIComponent(failureReason)}&rescheduled_date=${rescheduledDate}&csrf_token=<?= $_SESSION['csrf_token'] ?>`;
    const resp = await fetch('../api/update_parcel_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    });
    const data = await resp.json();
    if (data.success) location.reload();
    else alert('操作失败：' + (data.error || ''));
}

async function retryDelivery(parcelId) {
    if (!confirm('确认重新配送该包裹？')) return;
    const body = `parcel_id=${parcelId}&status=out_for_delivery&remarks=重新配送&csrf_token=<?= $_SESSION['csrf_token'] ?>`;
    const resp = await fetch('../api/update_parcel_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
    });
    const data = await resp.json();
    if (data.success) location.reload();
    else alert('操作失败');
}

function uploadPhoto(parcelId) {
    const input = document.getElementById('photoInput');
    input.setAttribute('data-parcel-id', parcelId);
    input.click();
}
document.getElementById('photoInput').addEventListener('change', async function () {
    const file = this.files[0];
    const parcelId = this.getAttribute('data-parcel-id');
    if (!file) return;
    const formData = new FormData();
    formData.append('photo', file);
    formData.append('parcel_id', parcelId);
    formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
    const resp = await fetch('../api/upload_photo.php', { method: 'POST', body: formData });
    const data = await resp.json();
    if (data.success) alert('照片上传成功');
    else alert('上传失败');
    location.reload();
});

// 自动刷新：每30秒重新加载一次
setTimeout(function() {
    location.reload();
}, 30000);
</script>

<?php require_once '../includes/footer.php'; ?>