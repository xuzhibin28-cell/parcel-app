<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
// 生成 customer_token 以便后续使用
if (empty($_SESSION['customer_token'])) {
    $_SESSION['customer_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>速达配送 - 顾客中心</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body.customer-home {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }
        .hero {
            background: linear-gradient(135deg, #3498db, #2c3e50);
            color: white;
            padding: 60px 20px;
            text-align: center;
        }
        .hero h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        .hero p {
            font-size: 1.2rem;
            opacity: 0.9;
        }
        .cta-buttons {
            margin-top: 30px;
        }
        .cta-buttons .btn {
            display: inline-block;
            padding: 15px 40px;
            margin: 10px;
            border-radius: 30px;
            font-size: 1.1rem;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .btn-order {
            background: #e67e22;
            color: white;
            box-shadow: 0 4px 12px rgba(230, 126, 34, 0.4);
        }
        .btn-order:hover {
            background: #d35400;
        }
        .btn-track {
            background: white;
            color: #2c3e50;
            box-shadow: 0 4px 12px rgba(255,255,255,0.3);
        }
        .btn-track:hover {
            background: #ecf0f1;
        }
        .features {
            display: flex;
            justify-content: center;
            gap: 30px;
            padding: 50px 20px;
            flex-wrap: wrap;
        }
        .feature-card {
            background: white;
            border-radius: 12px;
            padding: 30px 20px;
            width: 250px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .feature-card img {
            width: 60px;
            margin-bottom: 15px;
        }
        .feature-card h3 {
            margin-bottom: 10px;
            color: #2c3e50;
        }
        .footer-links {
            text-align: center;
            margin: 20px 0;
        }
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

<div class="footer-links">
    <a href="index.php" class="btn btn-sm">员工登录</a>
</div>
<p style="text-align:center; margin-top:1rem;">
    还没有账号？<a href="register.php">注册成为骑手</a><br>
    <a href="customer_home.php" style="display:inline-block; margin-top:8px;">🛍️ 我是顾客，进入购物中心</a>
</p>

</body>
</html>