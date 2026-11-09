<?php
/**
 * 插件市场 - 后台登录
 */
require_once dirname(__DIR__) . '/lib/bootstrap.php';
market_security_headers(true);
market_session_start();

if (MarketAuth::check()) {
    header('Location: index.php');
    exit;
}

$error = '';
$next = (string)($_GET['next'] ?? '');
// 只允许本站相对路径，防止开放重定向
if ($next !== '' && (strpos($next, '//') !== false || strpos($next, 'http') === 0)) {
    $next = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    market_same_origin_check();
    market_csrf_check();
    $result = MarketAuth::login($_POST['username'] ?? '', $_POST['password'] ?? '');
    if ($result['success']) {
        $target = $next !== '' ? $next : 'index.php';
        // next 只取文件名+查询串
        if (preg_match('#^[a-zA-Z0-9_\-]+\.php(\?.*)?$#', $target)) {
            header('Location: ' . $target);
        } else {
            header('Location: index.php');
        }
        exit;
    }
    $error = $result['message'];
}

$siteName = market_setting('site_name', '官鸡插件市场');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>审核后台登录 - <?= market_h($siteName) ?></title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="brand"><span class="brand-mark">Q</span><span><?= market_h($siteName) ?></span></div>
    <h1>审核后台</h1>
    <p class="sub">仅限市场管理员登录 · 所有操作均记录审计日志</p>

    <?php if ($error): ?><div class="alert alert-danger"><?= market_h($error) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= market_h(market_csrf_token()) ?>">
      <div class="form-row">
        <label>管理员账号</label>
        <input class="form-control" type="text" name="username" required autofocus placeholder="admin">
      </div>
      <div class="form-row">
        <label>密码</label>
        <input class="form-control" type="password" name="password" required placeholder="请输入密码">
      </div>
      <button class="btn btn-primary btn-block" type="submit" style="height:44px;margin-top:6px;">登录后台</button>
    </form>

    <div class="auth-foot">
      首次部署请使用部署说明中的初始密码，登录后立即修改。<br>
      <a href="../index.php" style="color:#8fb0ff;">← 返回插件广场</a>
    </div>
  </div>
</div>
</body>
</html>
