<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// 初始化顾客 token（用于 CSRF 保护）
if (empty($_SESSION['customer_token'])) {
    $_SESSION['customer_token'] = bin2hex(random_bytes(32));
}

// 从会话中获取本顾客的历史订单追踪号
$myOrders = $_SESSION['my_orders'] ?? [];
$orderDetails = [];

if (!empty($myOrders)) {
    $pdo = Database::getInstance();
    $placeholders = implode(',', array_fill(0, count($myOrders), '?'));
    $stmt = $pdo->prepare("
        SELECT tracking_number, recipient_name, description, status, updated_at, assigned_rider 
        FROM parcels 
        WHERE tracking_number IN ($placeholders) 
        ORDER BY created_at DESC
    ");
    $stmt->execute($myOrders);
    $orderDetails = $stmt->fetchAll();
}

$statusMap = [
    'pending' => '待接单',
    'out_for_delivery' => '派送中',
    'delivered' => '已送达',
    'failed' => '失败',
    'cancelled' => '已取消'
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>速达配送 - 顾客中心</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body.customer-home { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; }
        .hero { background: linear-gradient(135deg, #3498db, #2c3e50); color: white; padding: 60px 20px; text-align: center; }
        .hero h1 { font-size: 2.5rem; margin-bottom: 10px; }
        .hero p { font-size: 1.2rem; opacity: 0.9; }
        .cta-buttons { margin-top: 30px; }
        .cta-buttons .btn { display: inline-block; padding: 15px 40px; margin: 10px; border-radius: 30px; font-size: 1.1rem; text-decoration: none; transition: all 0.3s ease; }
        .btn-order { background: #e67e22; color: white; box-shadow: 0 4px 12px rgba(230, 126, 34, 0.4); }
        .btn-order:hover { background: #d35400; }
        .btn-track { background: white; color: #2c3e50; box-shadow: 0 4px 12px rgba(255,255,255,0.3); }
        .btn-track:hover { background: #ecf0f1; }
        .features { display: flex; justify-content: center; gap: 30px; padding: 50px 20px; flex-wrap: wrap; }
        .feature-card { background: white; border-radius: 12px; padding: 30px 20px; width: 250px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .feature-card img { width: 60px; margin-bottom: 15px; }
        .feature-card h3 { margin-bottom: 10px; color: #2c3e50; }
        .orders-section { max-width: 800px; margin: 30px auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .orders-section h2 { margin-top: 0; }
        .order-card { display: flex; justify-content: space-between; align-items: center; padding: 15px; border-bottom: 1px solid #eee; }
        .order-card:last-child { border-bottom: none; }
        .order-info { flex: 1; }
        .order-info .tracking { font-weight: bold; color: #2c3e50; }
        .order-info .desc { color: #666; font-size: 0.9rem; }
        .order-status { margin-left: 20px; text-align: right; }
        .badge { padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; }
        .status-pending { background: #f1c40f; color: #000; }
        .status-out_for_delivery { background: #3498db; color: white; }
        .status-delivered { background: #2ecc71; color: white; }
        .status-failed { background: #e74c3c; color: white; }
        .status-cancelled { background: #95a5a6; color: white; }
        .view-link { color: #3498db; text-decoration: none; margin-left: 10px; font-size: 0.9rem; }
        .btn-sm { padding: 0.3rem 0.8rem; font-size: 0.8rem; margin-left: 5px; }
        .footer-links { text-align: center; margin: 20px 0; }
    </style>
</head>
<body class="customer-home">

<div class="hero">
    <h1>🚀 速达配送</h1>
    <p>轻点几下，包裹即可上门取送</p>
    <div class="cta-buttons">
        <a href="customer_order.php" class="btn btn-order">📦 立即下单</a>
        <a href="customer_dashboard.php" class="btn btn-track">🔍 追踪包裹</a>
    </div>
</div>

<div class="features">
    <div class="feature-card">
        <img src="https://cdn-icons-png.flaticon.com/512/684/684908.png" alt="GPS定位">
        <h3>GPS 精确定位</h3>
        <p>一键获取您的位置，骑手快速找到您</p>
    </div>
    <div class="feature-card">
        <img src="https://cdn-icons-png.flaticon.com/512/2912/2912047.png" alt="实时追踪">
        <h3>实时追踪</h3>
        <p>随时查看骑手位置和包裹状态</p>
    </div>
    <div class="feature-card">
        <img src="https://cdn-icons-png.flaticon.com/512/2589/2589334.png" alt="安全评价">
        <h3>评价反馈</h3>
        <p>对配送服务进行评分，帮助我们改进</p>
    </div>
</div>

<!-- 我的订单 -->
<div class="orders-section">
    <h2>📋 我的订单</h2>
    <?php if (empty($orderDetails)): ?>
        <p>您还没有下单。快去 <a href="customer_order.php">下一单</a> 吧！</p>
    <?php else: ?>
        <?php foreach ($orderDetails as $order): ?>
            <div class="order-card">
                <div class="order-info">
                    <div class="tracking"><?= $order['tracking_number'] ?></div>
                    <div class="desc">
                        <?= !empty($order['description']) ? sanitize($order['description']) : (sanitize($order['recipient_name']) . ' 的包裹') ?>
                    </div>
                    <small>最后更新：<?= $order['updated_at'] ?></small>
                </div>
                <div class="order-status">
                    <span class="badge status-<?= $order['status'] ?>">
                        <?= $statusMap[$order['status']] ?? $order['status'] ?>
                    </span>
                    <br>
                    <a class="view-link" href="customer_dashboard.php?tracking=<?= $order['tracking_number'] ?>">详情 →</a>
                    
                    <!-- 操作按钮：取消、修改再下单、删除 -->
                    <?php if ($order['status'] === 'pending' && is_null($order['assigned_rider'])): ?>
                        <button class="btn btn-sm btn-danger" onclick="cancelOrder('<?= $order['tracking_number'] ?>')">取消</button>
                    <?php elseif ($order['status'] === 'cancelled'): ?>
                        <a href="customer_order.php?reorder=<?= $order['tracking_number'] ?>" class="btn btn-sm btn-warning">✏️ 修改再下单</a>
                        <button class="btn btn-sm btn-secondary" onclick="deleteOrderRecord('<?= $order['tracking_number'] ?>')">🗑️ 删除</button>
                    <?php elseif ($order['status'] === 'delivered'): ?>
                        <button class="btn btn-sm btn-secondary" onclick="deleteOrderRecord('<?= $order['tracking_number'] ?>')">🗑️ 删除</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="footer-links">
    <a href="index.php" class="btn btn-sm">员工登录</a>
</div>

<script>
// 取消订单
async function cancelOrder(tracking) {
    if (!confirm('确定要取消这个订单吗？')) return;
    try {
        const res = await fetch('api/cancel_parcel.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `tracking_number=${encodeURIComponent(tracking)}&csrf_token=<?= $_SESSION['customer_token'] ?>`
        });
        const data = await res.json();
        if (data.success) {
            alert('订单已取消');
            location.reload();
        } else {
            alert('取消失败：' + (data.error || ''));
        }
    } catch (err) {
        alert('网络错误');
        console.error(err);
    }
}

// 删除记录（仅从会话中移除）
async function deleteOrderRecord(tracking) {
    if (!confirm('确定要删除这条购物记录吗？该操作不可恢复。')) return;
    try {
        const res = await fetch('api/remove_order_record.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `tracking_number=${encodeURIComponent(tracking)}&csrf_token=<?= $_SESSION['customer_token'] ?>`
        });
        const data = await res.json();
        if (data.success) {
            alert('已删除');
            location.reload();
        } else {
            alert('删除失败：' + (data.error || ''));
        }
    } catch (err) {
        alert('网络错误');
        console.error(err);
    }
}
</script>
</body>
</html>