<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

$tracking = sanitize($_GET['tracking'] ?? '');
$parcel = null;
$error = '';

if ($tracking) {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("SELECT p.*, u.name as rider_name 
                           FROM parcels p 
                           LEFT JOIN users u ON p.assigned_rider = u.id 
                           WHERE p.tracking_number = ?");
    $stmt->execute([$tracking]);
    $parcel = $stmt->fetch();
    if (!$parcel) {
        $error = '找不到该追踪号对应的包裹。';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>包裹追踪 - 包裹配送系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <h2>📬 包裹追踪</h2>
        <form method="GET">
            <div class="form-group">
                <label>输入追踪单号</label>
                <input type="text" name="tracking" value="<?= $tracking ?>" placeholder="TRK...">
            </div>
            <button type="submit" class="btn btn-primary">查询</button>
        </form>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php elseif ($parcel): ?>
            <div style="margin-top:1rem; background:#f9f9f9; padding:1rem; border-radius:5px;">
                <p><strong>追踪单号：</strong><?= $parcel['tracking_number'] ?></p>
                <p><strong>发件人：</strong><?= sanitize($parcel['sender_name']) ?></p>
                <p><strong>收件人：</strong><?= sanitize($parcel['recipient_name']) ?></p>
                <p><strong>收件地址：</strong><?= sanitize($parcel['recipient_address']) ?></p>
                <p><strong>状态：</strong>
                    <span class="status-badge status-<?= $parcel['status'] ?>">
                        <?= $parcel['status'] ?>
                    </span>
                </p>
                <p><strong>配送骑手：</strong><?= $parcel['rider_name'] ?? '未分配' ?></p>
                <p><strong>更新时间：</strong><?= $parcel['updated_at'] ?></p>
            </div>
        <?php endif; ?>
        <p style="margin-top:1rem;"><a href="customer_order.php">我要下单</a> | <a href="index.php">员工登录</a></p>
    </div>
</body>
</html>