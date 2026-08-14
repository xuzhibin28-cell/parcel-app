<?php
$page_title = '个人资料';
$allowed_roles = ['rider'];
require_once '../includes/auth.php';
require_once '../includes/header.php';

$pdo = Database::getInstance();
$riderId = $_SESSION['user_id'];

// 获取用户信息
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$riderId]);
$user = $stmt->fetch();

// 获取骑手详细信息
$stmt2 = $pdo->prepare("SELECT * FROM riders WHERE user_id = ?");
$stmt2->execute([$riderId]);
$riderInfo = $stmt2->fetch();

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token mismatch');
    }

    $name    = sanitize($_POST['name']);
    $phone   = sanitize($_POST['phone']);
    $vehicle = sanitize($_POST['vehicle']);

    $pdo->prepare("UPDATE users SET name = ? WHERE id = ?")->execute([$name, $riderId]);
    $pdo->prepare("UPDATE riders SET phone = ?, vehicle_type = ? WHERE user_id = ?")->execute([$phone, $vehicle, $riderId]);

    $success = '资料已更新，刷新后生效。';
    // 更新会话中的用户名
    $_SESSION['user_name'] = $name;
    logActivity($riderId, 'Updated profile');
}
?>

<h1>个人资料</h1>

<?php if ($success): ?>
    <div class="alert alert-success"><?= $success ?></div>
<?php endif; ?>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="form-group">
        <label>姓名</label>
        <input type="text" name="name" value="<?= sanitize($user['name']) ?>" required>
    </div>
    <div class="form-group">
        <label>邮箱（不可修改）</label>
        <input type="email" value="<?= $user['email'] ?>" disabled>
    </div>
    <div class="form-group">
        <label>手机号码</label>
        <input type="text" name="phone" value="<?= sanitize($riderInfo['phone'] ?? '') ?>">
    </div>
    <div class="form-group">
        <label>车辆类型</label>
        <input type="text" name="vehicle" value="<?= sanitize($riderInfo['vehicle_type'] ?? '') ?>">
    </div>
    <button type="submit" class="btn btn-primary">保存修改</button>
</form>

<?php require_once '../includes/footer.php'; ?>