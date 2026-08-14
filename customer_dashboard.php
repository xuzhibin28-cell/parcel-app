<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// 顾客 token（评价、签收用）
if (empty($_SESSION['customer_token'])) {
    $_SESSION['customer_token'] = bin2hex(random_bytes(32));
}

// ========== 处理签收 POST ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delivery'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['customer_token'] ?? $_SESSION['csrf_token'] ?? '')) {
        die('CSRF token mismatch');
    }

    $trackingNumber = sanitize($_POST['tracking_number'] ?? '');
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("SELECT id, status FROM parcels WHERE tracking_number = ?");
    $stmt->execute([$trackingNumber]);
    $parcel = $stmt->fetch();

    if ($parcel && $parcel['status'] === 'awaiting_confirmation') {
        // 顾客签收：直接更新为已送达，完成订单
        $pdo->prepare("UPDATE parcels SET status = 'delivered', delivered_at = NOW() WHERE id = ?")->execute([$parcel['id']]);
        // 记录历史（changed_by 为 NULL，表示顾客操作）
        $pdo->prepare("INSERT INTO parcel_status_history (parcel_id, old_status, new_status, changed_by, remarks) VALUES (?, 'awaiting_confirmation', 'delivered', NULL, '顾客签收')")->execute([$parcel['id']]);
        header('Location: customer_dashboard.php?tracking=' . urlencode($trackingNumber) . '#ratingSection');
        exit;
    } else {
        $error = '包裹当前不可签收';
    }
}

// ========== 处理评价 POST ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rating'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['customer_token'] ?? $_SESSION['csrf_token'] ?? '')) {
        die('CSRF token mismatch');
    }

    $rating = intval($_POST['rating'] ?? 0);
    $comment = sanitize($_POST['comment'] ?? '');
    $trackingNumber = sanitize($_POST['tracking_number'] ?? '');

    if ($rating < 1 || $rating > 5) {
        $error = '评分必须在1-5之间';
    } else {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id, status FROM parcels WHERE tracking_number = ?");
        $stmt->execute([$trackingNumber]);
        $parcel = $stmt->fetch();

        if ($parcel && $parcel['status'] === 'delivered') {
            // 检查是否已有评价
            $stmt = $pdo->prepare("SELECT id FROM ratings WHERE parcel_id = ?");
            $stmt->execute([$parcel['id']]);
            if ($stmt->fetch()) {
                $error = '该包裹已评价';
            } else {
                $pdo->prepare("INSERT INTO ratings (parcel_id, rating, comment) VALUES (?, ?, ?)")
                   ->execute([$parcel['id'], $rating, $comment]);
                header('Location: customer_dashboard.php?tracking=' . urlencode($trackingNumber) . '#ratingSection');
                exit;
            }
        } else {
            $error = '只能评价已送达的包裹';
        }
    }
}

// ========== 常规查询 ==========
$tracking = sanitize($_GET['tracking'] ?? '');
$parcel = null;
$history = [];
$error = $error ?? '';
$ratingData = null;
$lastFailed = null;
$deliveredTime = null;

if (empty($tracking)) {
    $error = '请输入追踪单号。';
} else {
    $pdo = Database::getInstance();

    // 包裹详情 + 骑手信息
    $stmt = $pdo->prepare("
        SELECT p.*, u.name as rider_name, r.vehicle_type as rider_vehicle 
        FROM parcels p 
        LEFT JOIN users u ON p.assigned_rider = u.id 
        LEFT JOIN riders r ON u.id = r.user_id 
        WHERE p.tracking_number = ?
    ");
    $stmt->execute([$tracking]);
    $parcel = $stmt->fetch();

    if (!$parcel) {
        $error = '找不到该追踪号对应的包裹。';
    } else {
        // 状态历史
        $stmt2 = $pdo->prepare("SELECT * FROM parcel_status_history WHERE parcel_id = ? ORDER BY created_at ASC");
        $stmt2->execute([$parcel['id']]);
        $history = $stmt2->fetchAll();

        // 已有评价
        $stmt3 = $pdo->prepare("SELECT * FROM ratings WHERE parcel_id = ?");
        $stmt3->execute([$parcel['id']]);
        $ratingData = $stmt3->fetch();

        // 最近一次失败记录
        $stmt4 = $pdo->prepare("SELECT * FROM parcel_status_history WHERE parcel_id = ? AND new_status = 'failed' ORDER BY created_at DESC LIMIT 1");
        $stmt4->execute([$parcel['id']]);
        $lastFailed = $stmt4->fetch();

        // 送达时间（如果已送达）
        if ($parcel['status'] === 'delivered') {
            $stmt5 = $pdo->prepare("SELECT created_at FROM parcel_status_history WHERE parcel_id = ? AND new_status = 'delivered' ORDER BY created_at DESC LIMIT 1");
            $stmt5->execute([$parcel['id']]);
            $delRow = $stmt5->fetch();
            $deliveredTime = $delRow['created_at'] ?? null;
        }
    }
}

$statusMap = [
    'pending' => '待接单',
    'out_for_delivery' => '正在派送中',
    'awaiting_confirmation' => '等待签收',
    'delivered' => '您的包裹已到达',
    'failed' => '配送失败',
    'cancelled' => '已取消'
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>我的包裹 - 顾客中心</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .dashboard-container { max-width: 800px; margin: 30px auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .parcel-card { background: #f9f9f9; padding: 1rem; border-radius: 5px; margin-bottom: 1rem; }
        .status-badge { padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; }
        .status-pending { background: #f1c40f; color: #000; }
        .status-out_for_delivery { background: #3498db; color: white; }
        .status-awaiting_confirmation { background: #e67e22; color: white; }
        .status-delivered { background: #2ecc71; color: white; }
        .status-failed { background: #e74c3c; color: white; }
        .status-cancelled { background: #95a5a6; color: white; }
        .alert { padding: 10px; border-radius: 4px; margin: 10px 0; }
        .alert-warning { background: #fcf8e3; color: #8a6d3b; }
        .alert-success { background: #dff0d8; color: #3c763d; }
        .alert-danger { background: #f2dede; color: #a94442; }
        .alert-info { background: #d9edf7; color: #31708f; }
        .alert-secondary { background: #e2e3e5; color: #383d41; }
        #trackingMap { height: 300px; margin-top: 1rem; }
        .rating-stars { font-size: 2rem; cursor: pointer; }
        .rating-stars .star { color: #ccc; transition: color 0.2s; }
        .rating-stars .star.active { color: #f1c40f; }
    </style>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body class="login-page">
    <div class="dashboard-container">
        <h2>📬 我的包裹</h2>
        <form method="GET" class="track-form">
            <div class="form-group">
                <label>追踪单号</label>
                <input type="text" name="tracking" value="<?= $tracking ?>" placeholder="输入 TRK...">
            </div>
            <button type="submit" class="btn btn-primary">查询</button>
            <a href="customer_home.php" class="btn">返回首页</a>
            <a href="index.php" class="btn">员工登录</a>
        </form>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>

        <?php if ($parcel): ?>
            <div class="parcel-card">
                <h3>包裹信息</h3>
                <p><strong>追踪单号：</strong><?= $parcel['tracking_number'] ?></p>
                <p><strong>发件人：</strong><?= sanitize($parcel['sender_name'] ?? '—') ?></p>
                <p><strong>收件人：</strong><?= sanitize($parcel['recipient_name']) ?></p>
                <p><strong>收件地址：</strong><?= sanitize($parcel['recipient_address'] ?? '未提供地址') ?></p>
                <p><strong>GPS 坐标：</strong><?= $parcel['latitude'] ? $parcel['latitude'].', '.$parcel['longitude'] : '未定位' ?></p>
                <?php if (!empty($parcel['description'])): ?>
                    <p><strong>包裹内容：</strong><?= sanitize($parcel['description']) ?></p>
                <?php endif; ?>
                <p><strong>状态：</strong>
                    <span class="status-badge status-<?= $parcel['status'] ?>">
                        <?= $statusMap[$parcel['status']] ?? $parcel['status'] ?>
                    </span>
                </p>

                <?php if ($parcel['status'] === 'awaiting_confirmation'): ?>
                    <div class="alert alert-warning">
                        🚚 骑手已送达，请签收确认
                        <br>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['customer_token'] ?? $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="tracking_number" value="<?= $parcel['tracking_number'] ?>">
                            <button type="submit" name="confirm_delivery" class="btn btn-success">✅ 确认签收</button>
                        </form>
                    </div>
                <?php elseif ($parcel['status'] === 'delivered'): ?>
                    <div class="alert alert-success">✅ <?= $statusMap['delivered'] ?>！</div>
                    <?php if ($deliveredTime): ?>
                        <p><strong>签收时间：</strong><?= $deliveredTime ?></p>
                    <?php endif; ?>
                <?php elseif ($parcel['status'] === 'failed'): ?>
                    <div class="alert alert-danger">
                        ❌ <?= $statusMap['failed'] ?>
                        <?php if (!empty($parcel['failure_reason'])): ?>
                            <br>原因：<?= sanitize($parcel['failure_reason']) ?>
                        <?php endif; ?>
                        <?php if (!empty($parcel['rescheduled_date'])): ?>
                            <br>📅 重新安排配送日期：<?= $parcel['rescheduled_date'] ?>
                        <?php endif; ?>
                    </div>
                <?php elseif ($parcel['status'] === 'out_for_delivery'): ?>
                    <div class="alert alert-info">🚚 您的包裹正在派送中</div>
                <?php elseif ($parcel['status'] === 'cancelled'): ?>
                    <div class="alert alert-secondary">🚫 此订单已取消</div>
                <?php endif; ?>

                <p><strong>骑手：</strong>
                    <?php if ($parcel['rider_name']): ?>
                        <?= $parcel['rider_name'] ?>
                        <?php if (!empty($parcel['rider_vehicle'])): ?>（<?= $parcel['rider_vehicle'] ?>）<?php endif; ?>
                    <?php else: ?>
                        未分配
                    <?php endif; ?>
                </p>
                <p><strong>最后更新：</strong><?= $parcel['updated_at'] ?></p>
            </div>

            <!-- 评价区域 -->
            <?php if ($parcel['status'] === 'delivered'): ?>
                <div id="ratingSection" style="background:#fff; padding:1rem; border-radius:5px; margin-bottom:1rem;">
                    <?php if ($ratingData): ?>
                        <h4>🌟 您的评价</h4>
                        <p>评分：<?= str_repeat('⭐', $ratingData['rating']) ?> (<?= $ratingData['rating'] ?>/5)</p>
                        <?php if (!empty($ratingData['comment'])): ?>
                            <p>评论：<?= sanitize($ratingData['comment']) ?></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <h4>🌟 给这次配送评分</h4>
                        <form method="POST" action="" id="ratingForm">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['customer_token'] ?? $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="tracking_number" value="<?= $parcel['tracking_number'] ?>">
                            <input type="hidden" name="submit_rating" value="1">
                            <input type="hidden" name="rating" id="selectedRating" value="0">
                            <div class="rating-stars" id="starContainer">
                                <span class="star" data-value="1">⭐</span>
                                <span class="star" data-value="2">⭐</span>
                                <span class="star" data-value="3">⭐</span>
                                <span class="star" data-value="4">⭐</span>
                                <span class="star" data-value="5">⭐</span>
                            </div>
                            <span id="ratingDisplay" style="margin-left: 10px; font-size: 1rem; color: #f1c40f;">请选择评分</span>
                            <div class="form-group" style="margin-top:0.5rem;">
                                <label>评论（可选）</label>
                                <textarea name="comment" rows="2" style="width:100%;"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">提交评价</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- 地图 -->
            <?php if ($parcel['latitude']): ?>
                <div id="trackingMap"></div>
                <script>
                    const map = L.map('trackingMap').setView([<?= $parcel['latitude'] ?>, <?= $parcel['longitude'] ?>], 15);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
                    L.marker([<?= $parcel['latitude'] ?>, <?= $parcel['longitude'] ?>]).addTo(map)
                        .bindPopup('<?= sanitize($parcel['recipient_name']) ?><br>状态：<?= $statusMap[$parcel['status']] ?? $parcel['status'] ?>');
                </script>
            <?php endif; ?>

            <!-- 配送进度时间线 -->
            <?php if (count($history) > 0): ?>
                <h3 style="margin-top:1.5rem;">配送进度</h3>
                <ul class="status-timeline" style="list-style:none; padding:0;">
                    <?php foreach ($history as $h): ?>
                        <li style="padding:10px 0; border-bottom:1px solid #ddd;">
                            <span class="badge status-<?= $h['new_status'] ?>"><?= $statusMap[$h['new_status']] ?? $h['new_status'] ?></span>
                            <small><?= $h['created_at'] ?></small>
                            <?php if ($h['remarks']): ?><p><?= sanitize($h['remarks']) ?></p><?php endif; ?>
                            <?php if ($h['photo_path']): ?>
                                <img src="<?= BASE_URL . $h['photo_path'] ?>" width="100" alt="交付照片">
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div style="margin-top:1rem;">
                <a href="customer_dashboard.php?tracking=<?= $tracking ?>" class="btn">刷新状态</a>
                <a href="customer_home.php" class="btn btn-primary">返回首页</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- 星星评分交互 -->
    <?php if ($parcel && $parcel['status'] === 'delivered' && !$ratingData): ?>
    <script>
        const stars = document.querySelectorAll('#starContainer .star');
        const ratingInput = document.getElementById('selectedRating');
        const ratingDisplay = document.getElementById('ratingDisplay');

        stars.forEach(star => {
            star.addEventListener('click', function() {
                const value = this.getAttribute('data-value');
                ratingInput.value = value;
                updateStars(value);
                ratingDisplay.textContent = `您选择了 ${value} 星`;
            });
            star.addEventListener('mouseenter', function() {
                const value = this.getAttribute('data-value');
                updateStars(value);
                ratingDisplay.textContent = `您选择了 ${value} 星`;
            });
            star.addEventListener('mouseleave', function() {
                updateStars(ratingInput.value);
                if (ratingInput.value == 0) {
                    ratingDisplay.textContent = '请选择评分';
                } else {
                    ratingDisplay.textContent = `您选择了 ${ratingInput.value} 星`;
                }
            });
        });

        function updateStars(value) {
            stars.forEach(s => {
                const starValue = s.getAttribute('data-value');
                if (starValue <= value) {
                    s.classList.add('active');
                } else {
                    s.classList.remove('active');
                }
            });
        }
    </script>
    <?php endif; ?>
</body>
</html>