<?php
/**
 * 管理后台 - 共享头部
 */
date_default_timezone_set('Asia/Shanghai');
require_once(__DIR__ . '/../function.php');
require_once(__DIR__ . '/../auth.php');

// 检查安装状态
if (!Auth::isInstalled()) {
    header('Location: ../install.php');
    exit;
}

// 安全响应头
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// 检查登录状态（登录页除外）
$currentScript = basename($_SERVER['SCRIPT_NAME']);
$csrfToken = '';
if ($currentScript !== 'login.php') {
    Auth::requireAuth();
    // 先生成 CSRF token 再关闭 session 写入
    $csrfToken = Auth::csrfToken();
    // 认证完成后立即关闭session写入，释放session锁
    // 防止页面渲染期间阻塞AJAX请求（session文件锁竞争）
    // 注意: 关闭后 $_SESSION 仍可读取，但不能修改
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

$admin = db()->fetch("SELECT * FROM admin LIMIT 1");
$currentPage = $currentScript;
$sysVersion = require __DIR__ . '/version.php';
$sysVersionNo = $sysVersion['version'] ?? '';

// 统计未读消息数
$unreadMsgs = 0;
try {
    $unreadMsgs = db()->fetchColumn("SELECT COUNT(*) FROM messages WHERE direction = '接收' AND created_at > datetime('now','localtime','-1 hour')");
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="referrer" content="no-referrer">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<title><?= isset($pageTitle) ? $pageTitle . ' - ' : '' ?>官鸡机器人管理</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<?php if ($currentScript !== 'login.php'): ?>
<script>
window.ADMIN_CSRF = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>;
(function () {
  var token = window.ADMIN_CSRF;
  if (!token) return;
  function isApi(url) {
    if (!url || typeof url !== 'string') return false;
    try { url = new URL(url, location.href).pathname; } catch (e) {}
    return /(^|\/)api(\.php|\/)/.test(url);
  }
  // fetch
  var rawFetch = window.fetch;
  if (rawFetch) {
    window.fetch = function (input, init) {
      try {
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        if (method !== 'GET' && method !== 'HEAD' && isApi(url)) {
          init = init || {};
          var headers = new Headers(init.headers || (input && input.headers) || {});
          if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', token);
          init.headers = headers;
        }
      } catch (e) {}
      return rawFetch.call(this, input, init);
    };
  }
  // XMLHttpRequest
  var rawOpen = XMLHttpRequest.prototype.open;
  var rawSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url) {
    this.__csrfUrl = url;
    this.__csrfMethod = String(method || 'GET').toUpperCase();
    return rawOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    try {
      if (this.__csrfMethod !== 'GET' && this.__csrfMethod !== 'HEAD' && isApi(this.__csrfUrl) && !this.__csrfSet) {
        this.setRequestHeader('X-CSRF-Token', token);
        this.__csrfSet = true;
      }
    } catch (e) {}
    return rawSend.apply(this, arguments);
  };
})();
</script>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<div class="layout">
  <aside class="sidebar" id="adminSidebar">
    <a href="index.php" class="brand">
      <span class="brand-mark">官</span>
      <span class="brand-text"><strong>官鸡机器人</strong><small>后台控制台</small></span>
    </a>
    <div class="nav-menu">
      <div class="nav-section">概览</div>
      <a href="index.php" class="nav-item <?= $currentPage === 'index.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="1" y="1" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="1" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="1" y="9" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="9" width="6" height="6" rx="1" stroke="currentColor" stroke-width="1.5"/></svg>
        </span>
        仪表盘
      </a>
      <div class="nav-section">管理</div>
      <a href="bots.php" class="nav-item <?= $currentPage === 'bots.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="6" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M2 14C2 11.2 4.7 9 8 9C11.3 9 14 11.2 14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </span>
        机器人管理
      </a>
      <a href="messages.php" class="nav-item <?= $currentPage === 'messages.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 4C2 3 2.5 2.5 3.5 2.5H12.5C13.5 2.5 14 3 14 4V10C14 11 13.5 11.5 12.5 11.5H6L3 14V11.5H3.5C2.5 11.5 2 11 2 10V4Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
        </span>
        消息日志
        <?php if ($unreadMsgs > 0): ?>
        <span class="nav-badge"><?= $unreadMsgs > 99 ? '99+' : $unreadMsgs ?></span>
        <?php endif; ?>
      </a>
      <a href="plugins.php" class="nav-item <?= $currentPage === 'plugins.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M5 2H11V5.5C11 6.5 10.5 7 9.5 7H6.5C5.5 7 5 6.5 5 5.5V2Z" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 7V9.5C6.5 10.5 7 11 8 11C9 11 9.5 10.5 9.5 9.5V7" stroke="currentColor" stroke-width="1.5"/><rect x="3" y="11" width="10" height="3" rx="1" stroke="currentColor" stroke-width="1.5"/></svg>
        </span>
        插件管理
      </a>
      <a href="market.php" class="nav-item <?= $currentPage === 'market.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 5.5L8 2L14 5.5V10.5L8 14L2 10.5V5.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M2 5.5L8 9L14 5.5M8 9V14" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
        </span>
        插件市场
      </a>
      <a href="chat.php" class="nav-item <?= $currentPage === 'chat.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M1 3C1 2 2 1 3 1H13C14 1 15 2 15 3V9C15 10 14 11 13 11H5L2 14V11H3C2 11 1 10 1 9V3Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><line x1="4" y1="5" x2="12" y2="5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="4" y1="8" x2="9" y2="8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </span>
        聊天记录
      </a>
      <a href="material.php" class="nav-item <?= $currentPage === 'material.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5" stroke="currentColor" stroke-width="1.5"/><circle cx="5.5" cy="6" r="1.25" stroke="currentColor" stroke-width="1.25"/><path d="M2 12L6 8L9 11L11.5 8.5L14 11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        素材管理
      </a>
      <a href="group_members.php" class="nav-item <?= $currentPage === 'group_members.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="6" cy="5" r="2.5" stroke="currentColor" stroke-width="1.5"/><path d="M1.5 13C1.5 10.5 3.5 9 6 9C8.5 9 10.5 10.5 10.5 13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="12" cy="6" r="2" stroke="currentColor" stroke-width="1.5"/><path d="M12 9.5C13.8 9.5 15 10.6 15 12.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="6" cy="5" r="0.75" fill="currentColor"/></svg>
        </span>
        群成员管理
      </a>
      <a href="simulate.php" class="nav-item <?= $currentPage === 'simulate.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M9 1H14V9H9M2 7H9V14H2V7Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M11 3H12M4 9.5H7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </span>
        指令测试
      </a>
      <a href="panels.php" class="nav-item <?= $currentPage === 'panels.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="2" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="2" y="9" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="9" y="9" width="5" height="5" rx="1" stroke="currentColor" stroke-width="1.5"/></svg>
        </span>
        指令面板
      </a>
      <a href="menu.php" class="nav-item <?= $currentPage === 'menu.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><line x1="2" y1="4" x2="14" y2="4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="2" y1="8" x2="14" y2="8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="2" y1="12" x2="10" y2="12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="13" cy="12" r="1.5" fill="currentColor"/></svg>
        </span>
        自定义菜单
      </a>
      <div class="nav-section">开发</div>
      <a href="aidev.php" class="nav-item <?= $currentPage === 'aidev.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M8 5V9M8 11V11.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </span>
        AI写插件
      </a>
      <a href="doc.php" class="nav-item <?= $currentPage === 'doc.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 1.5H10L13 4.5V14.5H3V1.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 1.5V4.5H13" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><line x1="5" y1="8" x2="11" y2="8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="5" y1="10.5" x2="11" y2="10.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="5" y1="6" x2="8" y2="6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </span>
        开发文档
      </a>
      <a href="users.php" class="nav-item <?= $currentPage === 'users.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="5.5" cy="5" r="2.5" stroke="currentColor" stroke-width="1.5"/><path d="M1 13C1 10.5 3 9 5.5 9C8 9 10 10.5 10 13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="11.5" cy="6" r="2" stroke="currentColor" stroke-width="1.5"/><path d="M10.5 9.5C12.5 9.5 14.5 10.5 14.5 12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </span>
        用户与群组
      </a>
      <div class="nav-section">系统</div>
      <a href="logs.php" class="nav-item <?= $currentPage === 'logs.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 1.5H10L13 4.5V14.5H3V1.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 1.5V4.5H13" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><line x1="5.5" y1="8" x2="10.5" y2="8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="5.5" y1="10.5" x2="10.5" y2="10.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="5.5" cy="5.5" r="0.75" fill="currentColor"/></svg>
        </span>
        操作记录
      </a>
      <a href="database.php" class="nav-item <?= $currentPage === 'database.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><ellipse cx="8" cy="3.5" rx="5.5" ry="2" stroke="currentColor" stroke-width="1.5"/><path d="M2.5 3.5V12C2.5 13.1 5 14 8 14C11 14 13.5 13.1 13.5 12V3.5" stroke="currentColor" stroke-width="1.5"/><path d="M2.5 7.75C2.5 8.85 5 9.75 8 9.75C11 9.75 13.5 8.85 13.5 7.75" stroke="currentColor" stroke-width="1.5"/></svg>
        </span>
        数据库管理
      </a>
      <a href="settings.php" class="nav-item <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 5V2M8 14V11M11 8H14M2 8H5M10.5 5.5L12.5 3.5M3.5 12.5L5.5 10.5M10.5 10.5L12.5 12.5M3.5 3.5L5.5 5.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/></svg>
        </span>
        系统设置
      </a>
      <a href="update.php" class="nav-item <?= $currentPage === 'update.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 2V10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M4.5 6.5L8 10L11.5 6.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M2.5 11.5V12.5C2.5 13.05 2.95 13.5 3.5 13.5H12.5C13.05 13.5 13.5 13.05 13.5 12.5V11.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </span>
        系统更新
      </a>
    </div>
    <div class="sidebar-foot">
      <span class="foot-avatar"><?= htmlspecialchars(mb_substr($admin['username'] ?? 'A', 0, 1, 'UTF-8')) ?></span>
      <span class="foot-name"><?= htmlspecialchars($admin['username'] ?? 'admin') ?></span>
      <a class="foot-logout" href="api.php?action=logout">退出</a>
    </div>
  </aside>
  <div class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <button class="mobile-menu-btn" type="button" id="mobileMenuBtn" onclick="toggleSidebar()" aria-label="打开菜单" aria-controls="adminSidebar" aria-expanded="false">☰</button>
        <div class="breadcrumb">
          <span class="breadcrumb-root">控制台</span>
          <span class="breadcrumb-sep">/</span>
          <strong><?= htmlspecialchars($pageTitle ?? '仪表盘') ?></strong>
        </div>
      </div>
      <div class="topbar-right">
        <span class="topbar-status"><i></i> 服务运行中</span>
        <span class="topbar-version">v<?= htmlspecialchars($sysVersionNo) ?></span>
      </div>
    </header>
<?php endif; ?>
