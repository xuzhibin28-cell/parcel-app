<?php
require_once __DIR__ . '/functions.php';

// 未登录则跳转
if (!isset($_SESSION['user_id'])) {
    redirect(BASE_URL . 'index.php');
}

// 如果页面定义了允许的角色数组，检查权限
if (isset($allowed_roles) && is_array($allowed_roles)) {
    if (!in_array($_SESSION['user_role'], $allowed_roles)) {
        // 角色不匹配，自动跳回自己的面板
        if (isAdmin()) {
            redirect(BASE_URL . 'admin/dashboard.php');
        } elseif (isRider()) {
            redirect(BASE_URL . 'rider/dashboard.php');
        } else {
            die("Access denied.");
        }
    }
}