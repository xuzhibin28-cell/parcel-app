<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

if (isset($_SESSION['user_id'])) {
    redirect(isAdmin() ? 'admin/dashboard.php' : 'rider/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token mismatch');
    }

    $name     = sanitize($_POST['name']);
    $email    = sanitize($_POST['email']);
    $phone    = sanitize($_POST['phone'] ?? '');
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    if (empty($name) || empty($email) || empty($password)) {
        $error = '请填写所有必填字段。';
    } elseif ($password !== $confirm) {
        $error = '两次输入的密码不一致。';
    } elseif (strlen($password) < 6) {
        $error = '密码至少需要6个字符。';
    } else {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = '该邮箱已被注册。';
        } else {
            $pdo->beginTransaction();
            try {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'rider')");
                $stmt->execute([$name, $email, $hashed]);
                $userId = $pdo->lastInsertId();

                $stmt = $pdo->prepare("INSERT INTO riders (user_id, phone, vehicle_type, status) VALUES (?, ?, 'Motorcycle', 'offline')");
                $stmt->execute([$userId, $phone]);

                $pdo->commit();

                session_regenerate_id(true);
                $_SESSION['user_id']    = $userId;
                $_SESSION['user_name']  = $name;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role']  = 'rider';

                logActivity($userId, 'Register', 'User registered via web');
                redirect('rider/dashboard.php');
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = '注册失败，请稍后重试。';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>骑手注册 - 包裹配送系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <h2>骑手注册</h2>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-group">
                <label>姓名 *</label>
                <input type="text" name="name" required value="<?= $_POST['name'] ?? '' ?>">
            </div>
            <div class="form-group">
                <label>邮箱 *</label>
                <input type="email" name="email" required value="<?= $_POST['email'] ?? '' ?>">
            </div>
            <div class="form-group">
                <label>手机号码（选填）</label>
                <input type="text" name="phone" value="<?= $_POST['phone'] ?? '' ?>">
            </div>
            <div class="form-group">
                <label>密码 *</label>
                <input type="password" name="password" required minlength="6">
            </div>
            <div class="form-group">
                <label>确认密码 *</label>
                <input type="password" name="confirm_password" required minlength="6">
            </div>
            <button type="submit" class="btn btn-primary">注册</button>
        </form>
        <p style="margin-top:1rem;">已有账号？<a href="index.php">立即登录</a></p>
    </div>
</body>
</html>