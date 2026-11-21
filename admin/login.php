<?php
/**
 * 管理后台 - 登录页
 */
date_default_timezone_set('Asia/Shanghai');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
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
            $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || intval($_SERVER['SERVER_PORT'] ?? 0) === 443;
            if (PHP_VERSION_ID >= 70300) {
                setcookie('admin_token', $result['token'], [
                    'expires'  => time() + 604800,
                    'path'     => '/',
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            } else {
                setcookie('admin_token', $result['token'], time() + 604800, '/', '', $secure, true);
            }
            // 不再把 token 拼进 URL，避免泄露到浏览器历史 / 日志 / Referer
            header('Location: index.php');
            exit;
        } else {
            $error = $result['message'];
        }
    }
}

$pageTitle = '登录';
$ver = @include __DIR__ . '/version.php';
$verNo = is_array($ver) ? ($ver['version'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>登录 - 官鸡机器人管理</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<div class="login-wrapper">
  <aside class="login-aside">
    <div class="login-aside-top">
      <span class="mark">官</span>
      <span class="name">官鸡机器人<small>Console</small></span>
    </div>

    <div class="login-aside-mid">
      <div class="kicker">Admin Console</div>
      <h2>把机器人<br>管得清楚<em>一点</em></h2>
      <p>消息收发、群组与成员、插件与定时任务，全部收在同一个控制台里。打开就是全局，不用来回翻页。</p>
    </div>

    <div class="login-aside-foot">
      <span><i></i>API v2</span>
      <span><i></i>WebSocket</span>
      <span><i></i>数据本地化</span>
    </div>
  </aside>

  <main class="login-main">
    <div class="login-card">
      <div class="logo">
        <div class="login-eyebrow">Sign in / 管理员</div>
        <h1>登录控制台</h1>
        <p>使用安装时创建的账号进入后台</p>
      </div>

      <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" autocomplete="on">
        <div class="form-group">
          <label for="loginUser">用户名</label>
          <input type="text" id="loginUser" name="username" class="form-control" required autofocus placeholder="请输入用户名" autocomplete="username">
        </div>
        <div class="form-group">
          <label for="loginPass">密码</label>
          <input type="password" id="loginPass" name="password" class="form-control" required placeholder="请输入密码" autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary btn-block">进入后台</button>
      </form>

      <div class="login-foot">
        <span>仅限授权管理员</span>
        <?php if ($verNo): ?><span>v<?= htmlspecialchars($verNo) ?></span><?php endif; ?>
      </div>
    </div>
  </main>
</div>
</body>
</html>
