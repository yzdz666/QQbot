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

// 检查登录状态（登录页除外）
$currentScript = basename($_SERVER['SCRIPT_NAME']);
if ($currentScript !== 'login.php') {
    Auth::requireAuth();
    // 认证完成后立即关闭session写入，释放session锁
    // 防止页面渲染期间阻塞AJAX请求（session文件锁竞争）
    // 注意: 关闭后 $_SESSION 仍可读取，但不能修改
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

$admin = db()->fetch("SELECT * FROM admin LIMIT 1");
$currentPage = $currentScript;

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
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<?php if ($currentScript !== 'login.php'): ?>
<button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()">&#9776;</button>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<div class="layout">
  <div class="sidebar">
    <a href="index.php" class="brand">官鸡机器人</a>
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
      <a href="logs.php" class="nav-item <?= $currentPage === 'logs.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 2H10L13 5V14H3V2Z" stroke="currentColor" stroke-width="1.5"/><path d="M5 6H11M5 8.5H11M5 11H8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </span>
        系统日志
      </a>
      <a href="plugins.php" class="nav-item <?= $currentPage === 'plugins.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M5 2H11V5.5C11 6.5 10.5 7 9.5 7H6.5C5.5 7 5 6.5 5 5.5V2Z" stroke="currentColor" stroke-width="1.5"/><path d="M6.5 7V9.5C6.5 10.5 7 11 8 11C9 11 9.5 10.5 9.5 9.5V7" stroke="currentColor" stroke-width="1.5"/><rect x="3" y="11" width="10" height="3" rx="1" stroke="currentColor" stroke-width="1.5"/></svg>
        </span>
        插件管理
      </a>
      <a href="chat.php" class="nav-item <?= $currentPage === 'chat.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M1 3C1 2 2 1 3 1H13C14 1 15 2 15 3V9C15 10 14 11 13 11H5L2 14V11H3C2 11 1 10 1 9V3Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><line x1="4" y1="5" x2="12" y2="5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><line x1="4" y1="8" x2="9" y2="8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </span>
        聊天记录
      </a>
      <a href="simulate.php" class="nav-item <?= $currentPage === 'simulate.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M9 1H14V9H9M2 7H9V14H2V7Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M11 3H12M4 9.5H7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </span>
        指令测试
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
      <a href="settings.php" class="nav-item <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
        <span class="nav-icon">
          <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 5V2M8 14V11M11 8H14M2 8H5M10.5 5.5L12.5 3.5M3.5 12.5L5.5 10.5M10.5 10.5L12.5 12.5M3.5 3.5L5.5 5.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/></svg>
        </span>
        系统设置
      </a>
      <div class="nav-section" style="margin-top: auto; padding-bottom: 20px;">
        <div style="padding: 8px 24px; font-size: 12px; color: var(--text-muted);">
          管理员: <?= htmlspecialchars($admin['username'] ?? '') ?>
        </div>
        <a href="api.php?action=logout" class="nav-item" style="color: var(--danger);">
          <span class="nav-icon">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M6 2H3C2.5 2 2 2.5 2 3V13C2 13.5 2.5 14 3 14H6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M10 4L13 7L10 10M13 7H6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
          退出登录
        </a>
      </div>
    </div>
  </div>
  <div class="main-content">
<?php endif; ?>
