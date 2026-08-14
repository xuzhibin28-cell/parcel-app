<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// 如果已登录，直接跳转到对应面板
if (isset($_SESSION['user_id'])) {
    redirect(isAdmin() ? 'admin/dashboard.php' : 'rider/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF token mismatch");
    }

    $email    = sanitize($_POST['email']);
    $password = $_POST['password'];

    // 如果当前已有其他用户登录（邮箱不同），先完全退出
    if (isset($_SESSION['user_id']) && isset($_SESSION['user_email']) && $_SESSION['user_email'] !== $email) {
        session_unset();
        session_destroy();
        session_start();
        // 重新生成 CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_name']  = $user['name'];
        $_SESSION['user_email'] = $user['email'];   // 保存邮箱用于检测
        $_SESSION['user_role']  = $user['role'];

        logActivity($user['id'], 'Login', 'User logged in');

        // 按角色跳转
        if ($user['role'] === 'admin') {
            redirect('admin/dashboard.php');
        } else {
            redirect('rider/dashboard.php');
        }
    } else {
        $error = '邮箱或密码错误，请重试。';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - 包裹配送系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <h2>包裹配送系统</h2>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= $error ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-group">
                <label>邮箱</label>
                <input type="email" name="email" required value="<?= $_POST['email'] ?? '' ?>">
            </div>
            <div class="form-group">
                <label>密码</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary">登录</button>
        </form>
        <p style="text-align:center; margin-top:1rem;">
            还没有账号？<a href="register.php">注册成为骑手</a><br>
            <a href="customer_home.php">我是顾客，我要下单</a>
        </p>
    </div>
</body>
</html>