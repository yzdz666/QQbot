<?php
/** 市场后台 - 公共头部 */
if (!defined('MARKET_ROOT')) { require_once dirname(__DIR__) . '/lib/bootstrap.php'; }
market_security_headers(true);
MarketAuth::requireAdmin();
$admin = MarketAuth::current();
$siteName = market_setting('site_name', '官鸡插件市场');
$current = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
$pageTitle = $pageTitle ?? '概览';
$flash = market_flash_get();
$pendingCount = (int)MarketDB::pdo()->query("SELECT COUNT(*) FROM versions WHERE status = 'pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= market_h($pageTitle) ?> - <?= market_h($siteName) ?> 审核后台</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body class="admin-body">
<div class="admin-side-overlay" id="adminSideOverlay"></div>
<div class="admin-layout">
  <aside class="admin-side" id="adminSide">
    <a class="brand" href="index.php">
      <span class="brand-mark">官</span>
      <span><?= market_h($siteName) ?><small>REVIEW CONSOLE</small></span>
    </a>
    <div class="nav-section">工作台</div>
    <a class="nav-item <?= $current === 'index.php' ? 'active' : '' ?>" href="index.php">概览与审核队列</a>
    <a class="nav-item <?= $current === 'plugins.php' ? 'active' : '' ?>" href="plugins.php">插件管理</a>
    <div class="nav-section">系统</div>
    <a class="nav-item <?= $current === 'settings.php' ? 'active' : '' ?>" href="settings.php">安全设置</a>
    <a class="nav-item <?= $current === 'logs.php' ? 'active' : '' ?>" href="logs.php">审计日志</a>
    <a class="nav-item" href="../index.php" target="_blank" rel="noopener">前台市场 ↗</a>
    <div class="side-foot">
      <div>当前账号：<strong style="color:var(--ink)"><?= market_h($admin['username'] ?? '') ?></strong></div>
      <div style="margin-top:4px;">IP：<?= market_h(market_client_ip()) ?></div>
      <div style="margin-top:10px;"><a href="logout.php?token=<?= market_h(market_csrf_token()) ?>" style="color:var(--danger);">退出登录</a></div>
    </div>
  </aside>
  <div class="admin-main">
    <header class="admin-top">
      <button class="sidebar-toggle" type="button" id="adminSideToggle" aria-label="打开菜单" aria-controls="adminSide" aria-expanded="false">☰</button>
      <h1><?= market_h($pageTitle) ?></h1>
      <?php if ($pendingCount > 0): ?>
        <span class="badge badge-warn"><?= $pendingCount ?> 个版本待审核</span>
      <?php endif; ?>
      <span class="grow"></span>
      <span class="small muted"><?= date('Y-m-d H:i') ?></span>
    </header>
    <div class="admin-content">
      <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'ok' ? 'ok' : ($flash['type'] === 'warn' ? 'warn' : 'danger') ?>"><?= market_h($flash['message']) ?></div>
      <?php endif; ?>
