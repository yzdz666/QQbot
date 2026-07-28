<?php
/**
 * 管理后台 - 登录页
 */
date_default_timezone_set('Asia/Shanghai');
require_once(__DIR__ . '/../function.php');
require_once(__DIR__ . '/../auth.php');

// 已登录则跳转
if (Auth::check()) {
    header('Location: index.php');
    exit;
}

// 检查安装
if (!Auth::isInstalled()) {
    header('Location: ../install.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        $result = Auth::login($username, $password);
        if ($result['success']) {
            setcookie('admin_token', $result['token'], time() + 604800, '/');
            header('Location: index.php?token=' . $result['token']);
            exit;
        } else {
            $error = $result['message'];
        }
    }
}

$pageTitle = '登录';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>登录 - 官鸡机器人管理</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="login-wrapper">
  <div class="login-card">
    <div class="logo">
      <h1>官鸡机器人</h1>
      <p>管理后台登录</p>
    </div>
    <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label>用户名</label>
        <input type="text" name="username" class="form-control" required autofocus placeholder="请输入用户名">
      </div>
      <div class="form-group">
        <label>密码</label>
        <input type="password" name="password" class="form-control" required placeholder="请输入密码">
      </div>
      <button type="submit" class="btn btn-primary btn-block">登录</button>
    </form>
  </div>
</div>
</body>
</html>
