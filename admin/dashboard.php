<?php
$page_title = '管理员仪表盘';
$allowed_roles = ['admin'];
require_once '../includes/auth.php';
require_once '../includes/header.php';

$pdo = Database::getInstance();

// 基础统计
$total_riders = $pdo->query("SELECT COUNT(*) FROM users WHERE role='rider'")->fetchColumn();
$online_riders = $pdo->query("SELECT COUNT(*) FROM riders WHERE status='online'")->fetchColumn();
$offline_riders = $total_riders - $online_riders;

$total_parcels = $pdo->query("SELECT COUNT(*) FROM parcels")->fetchColumn();
$pending = $pdo->query("SELECT COUNT(*) FROM parcels WHERE status='pending'")->fetchColumn();
$delivered = $pdo->query("SELECT COUNT(*) FROM parcels WHERE status='delivered'")->fetchColumn();
$failed = $pdo->query("SELECT COUNT(*) FROM parcels WHERE status='failed'")->fetchColumn();

// 骑手工作状态统计（需要 riders 表包含 work_status 字段）
$idle_riders = $pdo->query("SELECT COUNT(*) FROM riders WHERE status='online' AND work_status='idle'")->fetchColumn();
$delivering_riders = $pdo->query("SELECT COUNT(*) FROM riders WHERE status='online' AND work_status='delivering'")->fetchColumn();
?>

<h1>仪表盘</h1>

<div class="cards">
    <div class="card">
        <h3>总骑手</h3>
        <p><?= $total_riders ?></p>
    </div>
    <div class="card">
        <h3>在线骑手</h3>
        <p><?= $online_riders ?></p>
    </div>
    <div class="card">
        <h3>离线骑手</h3>
        <p><?= $offline_riders ?></p>
    </div>
    <div class="card">
        <h3>空闲骑手</h3>
        <p><?= $idle_riders ?></p>
    </div>
    <div class="card">
        <h3>派送中骑手</h3>
        <p><?= $delivering_riders ?></p>
    </div>
    <div class="card">
        <h3>总包裹</h3>
        <p><?= $total_parcels ?></p>
    </div>
    <div class="card">
        <h3>待处理</h3>
        <p><?= $pending ?></p>
    </div>
    <div class="card">
        <h3>已送达</h3>
        <p><?= $delivered ?></p>
    </div>
    <div class="card">
        <h3>配送失败</h3>
        <p><?= $failed ?></p>
    </div>
</div>

<!-- 最近完成配送通知 -->
<div style="margin-top: 30px;">
    <h3>📢 最近完成配送通知</h3>
    <ul id="deliveryNotifyList" style="list-style: none; padding: 0;">
        <li>暂无新完成记录</li>
    </ul>
</div>

<script>
async function fetchRecentDeliveries() {
    try {
        const resp = await fetch('../api/get_recent_deliveries.php');
        const data = await resp.json();
        const list = document.getElementById('deliveryNotifyList');
        if (data && data.length > 0) {
            list.innerHTML = '';
            data.forEach(d => {
                const li = document.createElement('li');
                li.style.padding = '8px 0';
                li.style.borderBottom = '1px solid #eee';
                li.innerHTML = `✅ <strong>${d.rider_name}</strong> 已完成包裹 <strong>${d.tracking_number}</strong>（收件人：${d.recipient_name}）<br><small>签收时间：${d.delivered_at}</small>`;
                list.appendChild(li);
            });
        } else {
            list.innerHTML = '<li>暂无新完成记录</li>';
        }
    } catch (err) {
        console.error('获取通知失败', err);
    }
}
fetchRecentDeliveries();
setInterval(fetchRecentDeliveries, 10000); // 每10秒刷新
</script>

<?php require_once '../includes/footer.php'; ?>