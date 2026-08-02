<?php
if (!isset($_COOKIE['admin_token'])) {
    header("Location: index.php");
    exit();
}
$root = dirname(__DIR__);
$statusFile = $root . '/database/ws_status.json';
$status = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : [];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=5.0">
    <meta name="theme-color" content="#6366f1">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>WebSocket 模式 · 月下独酌管机</title>
    <link rel="stylesheet" href="assets/icons.css">
    <style>
        /* ===================== 基础重置 ===================== */
        *, *::before, *::after {
            margin: 0; padding: 0; box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        :focus { outline: none; }
        :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

        :root {
            /* 配色 - 现代深色侧栏 + 浅色主区 */
            --bg: #f4f5fb;
            --bg-grad: radial-gradient(1200px 600px at 100% -10%, #e0e7ff 0%, transparent 50%),
                      radial-gradient(900px 500px at -10% 110%, #fae8ff 0%, transparent 50%),
                      #f4f5fb;
            --card: #ffffff;
            --card-soft: #fafbff;
            --border: #ecedf5;
            --border-strong: #d8dae8;

            --text-main: #1e2233;
            --text-sub: #4b5168;
            --text-muted: #8a90a8;

            --accent: #6366f1;
            --accent-2: #8b5cf6;
            --accent-grad: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            --accent-soft: #eef2ff;
            --accent-hover: #4f46e5;

            --success: #10b981;
            --success-soft: #d1fae5;
            --danger: #ef4444;
            --danger-soft: #fee2e2;
            --warning: #f59e0b;
            --warning-soft: #fef3c7;
            --info: #3b82f6;
            --info-soft: #dbeafe;

            --shadow-sm: 0 1px 2px rgba(16, 24, 40, 0.04), 0 1px 3px rgba(16, 24, 40, 0.06);
            --shadow-md: 0 4px 8px -2px rgba(16, 24, 40, 0.06), 0 2px 4px -2px rgba(16, 24, 40, 0.04);
            --shadow-lg: 0 12px 24px -4px rgba(16, 24, 40, 0.08), 0 4px 8px -4px rgba(16, 24, 40, 0.04);
            --shadow-glow: 0 8px 24px -6px rgba(99, 102, 241, 0.45);

            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;

            --sidebar-w: 248px;
            --topbar-h: 60px;
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }

        html, body { height: 100%; }
        body {
            background: var(--bg-grad);
            background-attachment: fixed;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif;
            color: var(--text-main);
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        /* ===================== 桌面布局 ===================== */
        .desktop-layout { display: flex; min-height: 100vh; }

        .sidebar {
            width: var(--sidebar-w);
            background: linear-gradient(180deg, #1e2233 0%, #2a2f45 100%);
            position: fixed; top: 0; bottom: 0; left: 0;
            display: flex; flex-direction: column;
            color: #cbd1e8;
            z-index: 100;
            box-shadow: 4px 0 24px rgba(16, 24, 40, 0.06);
        }
        .sidebar-header {
            padding: 22px 22px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        .brand {
            display: flex; align-items: center; gap: 10px;
        }
        .brand-logo {
            width: 36px; height: 36px; border-radius: 10px;
            background: var(--accent-grad);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 18px;
            box-shadow: 0 6px 16px -4px rgba(99, 102, 241, 0.55);
        }
        .brand-text h1 { color: #fff; font-size: 16px; font-weight: 600; letter-spacing: 0.2px; }
        .brand-text p { color: #7a82a0; font-size: 11px; margin-top: 2px; }

        .sidebar-nav {
            flex: 1; padding: 14px 12px; overflow-y: auto;
            scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.1) transparent;
        }
        .sidebar-nav::-webkit-scrollbar { width: 6px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.08); border-radius: 3px; }

        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 14px; margin-bottom: 2px;
            color: #b3bad6; text-decoration: none;
            font-size: 13.5px; font-weight: 500;
            border-radius: 10px;
            cursor: pointer;
            transition: all .18s ease;
            position: relative;
        }
        .nav-item i { width: 18px; font-size: 14px; text-align: center; opacity: .85; }
        .nav-item:hover { background: rgba(255, 255, 255, 0.06); color: #fff; }
        .nav-item.active {
            background: rgba(99, 102, 241, 0.18);
            color: #fff;
        }
        .nav-item.active::before {
            content: ''; position: absolute; left: -12px; top: 50%; transform: translateY(-50%);
            width: 3px; height: 18px; background: var(--accent-grad); border-radius: 0 3px 3px 0;
        }
        .nav-item.active i { opacity: 1; color: #c7d2fe; }

        .sidebar-footer {
            padding: 14px 22px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            font-size: 11px; color: #6a718f;
            display: flex; align-items: center; gap: 6px;
        }
        .sidebar-footer .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--success); box-shadow: 0 0 8px var(--success); }

        .main-content {
            flex: 1; margin-left: var(--sidebar-w);
            min-height: 100vh;
            display: flex; flex-direction: column;
        }

        .top-bar {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: saturate(180%) blur(14px);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
            border-bottom: 1px solid var(--border);
            padding: 0 28px;
            height: var(--topbar-h);
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 50;
        }
        .top-bar-left { display: flex; align-items: center; gap: 14px; }
        .page-title { font-size: 16px; font-weight: 600; }
        .page-sub { color: var(--text-muted); font-size: 12px; }
        .top-actions { display: flex; gap: 10px; align-items: center; }

        .container {
            padding: 28px 32px 40px;
            max-width: 1480px;
            width: 100%;
            flex: 1;
        }

        /* ===================== 统计卡片 ===================== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 20px 22px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: all .2s;
        }
        .stat-card::after {
            content: ''; position: absolute; top: -40px; right: -40px;
            width: 120px; height: 120px;
            background: radial-gradient(circle, var(--accent-soft) 0%, transparent 70%);
            opacity: .8;
            pointer-events: none;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .stat-label {
            font-size: 12.5px; color: var(--text-muted);
            margin-bottom: 10px; display: flex; align-items: center; gap: 6px;
            font-weight: 500;
        }
        .stat-label .ico {
            width: 22px; height: 22px; border-radius: 6px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--accent-soft); color: var(--accent); font-size: 11px;
        }
        .stat-value {
            font-size: 28px; font-weight: 700;
            color: var(--text-main); line-height: 1.1;
            display: flex; align-items: center; gap: 8px;
            letter-spacing: -0.5px;
        }
        .stat-value .num-grad {
            background: var(--accent-grad);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .stat-hint { font-size: 11px; color: var(--text-muted); margin-top: 6px; }

        .run-pill {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 10px; border-radius: 999px;
            font-size: 12px; font-weight: 600;
            background: var(--success-soft); color: var(--success);
        }
        .run-pill.off { background: #f1f2f7; color: var(--text-muted); }
        .run-pill .dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: currentColor;
            box-shadow: 0 0 0 0 currentColor;
            animation: pulse 2s infinite;
        }
        .run-pill.off .dot { animation: none; }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 currentColor; }
            70% { box-shadow: 0 0 0 6px transparent; }
            100% { box-shadow: 0 0 0 0 transparent; }
        }

        /* ===================== 卡片 ===================== */
        .section-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: 22px;
            box-shadow: var(--shadow-sm);
        }
        .section-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px;
            background: linear-gradient(180deg, var(--card-soft), transparent);
        }
        .section-title {
            font-size: 15px; font-weight: 600;
            display: flex; align-items: center; gap: 8px;
        }
        .section-title .bar {
            width: 4px; height: 14px; background: var(--accent-grad);
            border-radius: 2px;
        }
        .section-sub { font-size: 12px; color: var(--text-muted); margin-top: 3px; }
        .card-body { padding: 20px 22px; }

        /* ===================== 按钮（重点重构） ===================== */
        .btn {
            --btn-bg: var(--accent);
            --btn-fg: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            gap: 7px;
            padding: 9px 16px;
            font-size: 13px; font-weight: 600;
            border-radius: 10px;
            border: 1px solid transparent;
            cursor: pointer;
            background: var(--btn-bg);
            color: var(--btn-fg);
            transition: transform .15s ease, box-shadow .2s ease, background .2s ease, border-color .2s ease;
            white-space: nowrap;
            font-family: inherit;
            letter-spacing: 0.2px;
            position: relative;
            overflow: hidden;
        }
        .btn i { font-size: 12px; }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        .btn:active { transform: translateY(0); box-shadow: var(--shadow-sm); }
        .btn:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }

        /* 主要按钮 - 渐变 */
        .btn-primary {
            --btn-bg: var(--accent-grad);
            background: var(--accent-grad);
            box-shadow: 0 4px 12px -2px rgba(99, 102, 241, 0.35);
        }
        .btn-primary:hover {
            box-shadow: 0 8px 20px -4px rgba(99, 102, 241, 0.5);
            filter: brightness(1.05);
        }

        /* 次要按钮 - 描边浅色 */
        .btn-secondary {
            --btn-bg: #fff;
            background: #fff;
            color: var(--text-sub);
            border-color: var(--border-strong);
        }
        .btn-secondary:hover { background: var(--card-soft); border-color: var(--accent); color: var(--accent); }

        /* 成功按钮 - 绿色 */
        .btn-success {
            --btn-bg: linear-gradient(135deg, #10b981 0%, #059669 100%);
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 4px 12px -2px rgba(16, 185, 129, 0.35);
        }
        .btn-success:hover { box-shadow: 0 8px 20px -4px rgba(16, 185, 129, 0.5); filter: brightness(1.05); }

        /* 危险按钮 - 红色 */
        .btn-danger {
            --btn-bg: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            box-shadow: 0 4px 12px -2px rgba(239, 68, 68, 0.35);
        }
        .btn-danger:hover { box-shadow: 0 8px 20px -4px rgba(239, 68, 68, 0.5); filter: brightness(1.05); }

        /* 警告按钮 - 橙色 */
        .btn-warning {
            --btn-bg: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            box-shadow: 0 4px 12px -2px rgba(245, 158, 11, 0.35);
        }

        /* 幽灵按钮 - 透明 */
        .btn-ghost {
            background: transparent;
            color: var(--text-sub);
            border-color: transparent;
        }
        .btn-ghost:hover { background: var(--accent-soft); color: var(--accent); }

        .btn-sm { padding: 7px 12px; font-size: 12px; border-radius: 8px; }
        .btn-lg { padding: 11px 22px; font-size: 14px; border-radius: 12px; }
        .btn-icon {
            padding: 8px; aspect-ratio: 1;
            border-radius: 10px;
        }
        .btn-block { width: 100%; }

        /* 圆形悬浮按钮（手机版） */
        .fab {
            position: fixed; bottom: calc(20px + var(--safe-bottom)); right: 20px;
            width: 54px; height: 54px; border-radius: 50%;
            background: var(--accent-grad);
            color: #fff; font-size: 20px;
            border: none; cursor: pointer;
            display: none;
            align-items: center; justify-content: center;
            box-shadow: 0 10px 28px -6px rgba(99, 102, 241, 0.6);
            z-index: 200;
            transition: transform .2s;
        }
        .fab:active { transform: scale(0.92); }

        /* ===================== 表格 ===================== */
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 12px 14px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        th {
            color: var(--text-muted); font-weight: 600; font-size: 11.5px;
            text-transform: uppercase; letter-spacing: 0.6px;
            background: var(--card-soft);
            position: sticky; top: 0;
        }
        td { color: var(--text-main); }
        tr:last-child td { border-bottom: none; }
        tbody tr { transition: background .15s; }
        tbody tr:hover { background: var(--card-soft); }
        .td-mono { font-family: 'SF Mono', SFMono-Regular, Consolas, 'Liberation Mono', Menlo, monospace; font-size: 11.5px; }
        .td-muted { color: var(--text-muted); font-size: 11.5px; }

        /* 状态徽章 */
        .state-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 999px;
            font-size: 11.5px; font-weight: 600;
            border: 1px solid transparent;
        }
        .state-badge .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .state-ready        { background: var(--success-soft); color: var(--success); border-color: #a7f3d0; }
        .state-connected    { background: var(--info-soft);    color: var(--info);    border-color: #bfdbfe; }
        .state-init,
        .state-reconnecting { background: var(--warning-soft); color: #b45309; border-color: #fde68a; }
        .state-closed       { background: #f1f2f7;             color: var(--text-muted); border-color: var(--border); }

        /* ===================== 日志框 ===================== */
        .log-box {
            background: #0f1320;
            border: 1px solid #1f2540;
            border-radius: var(--radius-md);
            padding: 14px 16px;
            font-family: 'SF Mono', SFMono-Regular, Consolas, 'Liberation Mono', Menlo, monospace;
            font-size: 12px; line-height: 1.7;
            color: #c7d2fe;
            max-height: 460px; overflow-y: auto;
            white-space: pre-wrap; word-break: break-all;
            scrollbar-width: thin; scrollbar-color: #4338ca transparent;
        }
        .log-box::-webkit-scrollbar { width: 6px; }
        .log-box::-webkit-scrollbar-thumb { background: #4338ca; border-radius: 3px; }
        .log-box .log-line { padding: 1px 0; }
        .log-box .log-line.err { color: #fca5a5; }
        .log-box .log-line.ok { color: #86efac; }
        .log-box .log-line .ts { color: #6b7280; }
        .log-empty { color: #6b7280; text-align: center; padding: 20px; font-style: italic; }

        /* ===================== 信息网格 ===================== */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px 28px;
            font-size: 13px;
        }
        .info-grid .item { display: flex; gap: 8px; align-items: baseline; }
        .info-grid .k { color: var(--text-muted); min-width: 110px; flex-shrink: 0; }
        .info-grid .v { color: var(--text-main); font-weight: 500; word-break: break-all; font-family: 'SF Mono', monospace; font-size: 12.5px; }
        .info-grid .v.code {
            background: var(--card-soft); padding: 2px 8px; border-radius: 6px;
            border: 1px solid var(--border); font-size: 12px;
        }

        /* ===================== 自动保活开关 ===================== */
        .keepalive-row {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 18px;
            background: linear-gradient(135deg, #eef2ff 0%, #fae8ff 100%);
            border: 1px solid #c7d2fe;
            border-radius: var(--radius-md);
            margin-bottom: 18px;
        }
        .keepalive-row .ka-icon {
            width: 38px; height: 38px; border-radius: 10px;
            background: var(--accent-grad);
            color: #fff; font-size: 16px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 12px -2px rgba(99, 102, 241, 0.45);
            flex-shrink: 0;
        }
        .keepalive-row .ka-text { flex: 1; }
        .keepalive-row .ka-text .t { font-size: 13.5px; font-weight: 600; color: var(--text-main); }
        .keepalive-row .ka-text .s { font-size: 11.5px; color: var(--text-sub); margin-top: 2px; }

        /* iOS 风格开关 */
        .switch {
            position: relative; display: inline-block;
            width: 46px; height: 26px; flex-shrink: 0;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .switch .slider {
            position: absolute; cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #cbd5e1;
            transition: .25s;
            border-radius: 999px;
        }
        .switch .slider::before {
            position: absolute; content: '';
            height: 20px; width: 20px; left: 3px; bottom: 3px;
            background: #fff; border-radius: 50%;
            transition: .25s;
            box-shadow: 0 1px 3px rgba(0,0,0,.2);
        }
        .switch input:checked + .slider { background: var(--accent-grad); }
        .switch input:checked + .slider::before { transform: translateX(20px); }

        /* ===================== 通知 ===================== */
        .notification {
            position: fixed; bottom: calc(24px + var(--safe-bottom)); right: 24px;
            padding: 12px 18px 12px 14px;
            border-radius: 12px; font-size: 13px;
            background: var(--card); color: var(--text-main);
            box-shadow: var(--shadow-lg);
            z-index: 1100;
            display: flex; align-items: center; gap: 10px;
            transform: translateX(140%);
            transition: transform .35s cubic-bezier(.22,.61,.36,1);
            border: 1px solid var(--border);
            min-width: 220px; max-width: 90vw;
        }
        .notification.show { transform: translateX(0); }
        .notification .icon {
            width: 22px; height: 22px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; color: #fff; flex-shrink: 0;
        }
        .notification.success .icon { background: var(--success); }
        .notification.error .icon { background: var(--danger); }
        .notification.info .icon { background: var(--info); }

        /* ===================== 移动端头部 ===================== */
        .mobile-header {
            display: none;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: saturate(180%) blur(14px);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
            border-bottom: 1px solid var(--border);
            align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 50;
        }
        .menu-toggle {
            width: 38px; height: 38px; border-radius: 10px;
            background: var(--accent-soft); color: var(--accent);
            border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
        }
        .mobile-header .title { font-weight: 600; font-size: 14px; }
        .mobile-header .badge-mini {
            background: var(--accent-grad); color: #fff;
            font-size: 10px; font-weight: 700;
            padding: 3px 8px; border-radius: 6px;
        }

        /* 遮罩 */
        .overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(16, 24, 40, 0.45);
            backdrop-filter: blur(2px);
            z-index: 90;
        }
        .overlay.show { display: block; }

        /* ===================== 响应式 ===================== */
        pre, code, table { max-width: 100%; overflow-x: auto; }

        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform .25s cubic-bezier(.22,.61,.36,1);
                box-shadow: 8px 0 32px rgba(0,0,0,.2);
            }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .mobile-header { display: flex; }
            .top-bar { display: none; }
            .container { padding: 18px 14px calc(40px + var(--safe-bottom)); }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card { padding: 16px 14px; }
            .stat-value { font-size: 22px; }
            .stat-label { font-size: 11.5px; }
            .info-grid { grid-template-columns: 1fr; gap: 10px; }
            .info-grid .k { min-width: 90px; }
            .section-header { padding: 14px 16px; }
            .card-body { padding: 14px 16px; }
            .section-title { font-size: 14px; }
            th, td { padding: 9px 10px; font-size: 12px; white-space: normal; }
            .btn { padding: 9px 14px; font-size: 12.5px; }
            .btn-sm { padding: 7px 10px; font-size: 11.5px; }
            .keepalive-row { padding: 12px 14px; gap: 10px; }
            .keepalive-row .ka-icon { width: 32px; height: 32px; font-size: 14px; }
            .notification { right: 12px; left: 12px; bottom: calc(16px + var(--safe-bottom)); max-width: none; }
            .fab { display: flex; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 14px 12px; }
            .stat-value { font-size: 20px; }
            .stat-card::after { width: 80px; height: 80px; }
            .btn { padding: 8px 12px; font-size: 12px; }
            .section-header { flex-direction: column; align-items: flex-start; }
        }

        /* 高对比度 / 暗黑模式可后续扩展（占位） */
    </style>
</head>
<body>
    <!-- 移动端顶部 -->
    <div class="mobile-header">
        <button class="menu-toggle" id="menuToggle" aria-label="打开菜单"><i class="fas fa-bars"></i></button>
        <span class="title">WebSocket 模式</span>
        <span class="badge-mini" id="mobileBadge">--</span>
    </div>

    <!-- 遮罩（移动端） -->
    <div class="overlay" id="overlay"></div>

    <div class="desktop-layout">
        <!-- 侧栏 -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="brand">
                    <div class="brand-logo"><i class="fas fa-robot"></i></div>
                    <div class="brand-text">
                        <h1>月下独酌管机</h1>
                        <p>机器人管理后台</p>
                    </div>
                </div>
            </div>
            <nav class="sidebar-nav">
                <a href="main.php" class="nav-item"><i class="fas fa-tachometer-alt"></i> 总览</a>
                <a href="#" class="nav-item" id="navAddBot"><i class="fas fa-plus-circle"></i> 添加机器人</a>
                <a href="set.php" class="nav-item"><i class="fas fa-user-cog"></i> 账号设置</a>
                <a href="simulate.php" class="nav-item"><i class="fas fa-vial"></i> 指令测试</a>
                <a href="cmdtest.php" class="nav-item"><i class="fas fa-paper-plane"></i> 主动消息</a>
                <a href="custom_api.php" class="nav-item"><i class="fas fa-code-branch"></i> API插件生成器</a>
                <a href="aidev.php" class="nav-item"><i class="fas fa-robot"></i> AI 写插件</a>
                <a href="doc.php" class="nav-item"><i class="fas fa-file-alt"></i> 开发文档</a>
                <a href="update.php" class="nav-item"><i class="fas fa-cloud-upload-alt"></i> 在线更新</a>
                <a href="ws.php" class="nav-item active"><i class="fas fa-sync-alt"></i> WebSocket 模式</a>
            </nav>
            <div class="sidebar-footer">
                <span class="dot"></span>
                <span>WS 接收模式 · UI v2.0</span>
            </div>
        </aside>

        <!-- 主区 -->
        <main class="main-content">
            <!-- 顶部栏（桌面） -->
            <div class="top-bar">
                <div class="top-bar-left">
                    <div>
                        <div class="page-title">WebSocket 接收模式</div>
                        <div class="page-sub">长连接主动接收 · 自动心跳 · 断线续传</div>
                    </div>
                </div>
                <div class="top-actions">
                    <button class="btn btn-secondary btn-sm" id="refreshBtn"><i class="fas fa-sync-alt"></i> 刷新</button>
                    <button class="btn btn-success btn-sm" id="startBtn"><i class="fas fa-play"></i> 启动</button>
                    <button class="btn btn-danger btn-sm" id="stopBtn"><i class="fas fa-stop"></i> 停止</button>
                </div>
            </div>

            <div class="container">
                <!-- 自动保活开关 -->
                <div class="keepalive-row">
                    <div class="ka-icon"><i class="fas fa-shield-heart"></i></div>
                    <div class="ka-text">
                        <div class="t">自动后台保活（免 CLI）</div>
                        <div class="s">开启后访问本页将自动拉起守护进程，无需手动 php ws.php</div>
                    </div>
                    <label class="switch" title="开启 / 关闭自动保活">
                        <input type="checkbox" id="keepaliveToggle">
                        <span class="slider"></span>
                    </label>
                </div>

                <!-- 统计卡片 -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label"><span class="ico"><i class="fas fa-heart-pulse"></i></span> 运行状态</div>
                        <div class="stat-value">
                            <span class="run-pill off" id="statState"><span class="dot"></span>未运行</span>
                        </div>
                        <div class="stat-hint" id="statPid">PID: -</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><span class="ico"><i class="fas fa-link"></i></span> 已连接机器人</div>
                        <div class="stat-value"><span class="num-grad" id="statBots">0</span></div>
                        <div class="stat-hint">WS 会话数</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><span class="ico"><i class="fas fa-inbox"></i></span> 接收 / 分发</div>
                        <div class="stat-value">
                            <span class="num-grad" id="statRecv">0</span>
                            <span style="color:var(--text-muted);font-weight:400;">/</span>
                            <span class="num-grad" id="statDisp">0</span>
                        </div>
                        <div class="stat-hint">累计事件数</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><span class="ico"><i class="fas fa-triangle-exclamation"></i></span> 重连 / 错误</div>
                        <div class="stat-value">
                            <span id="statReconn">0</span>
                            <span style="color:var(--text-muted);font-weight:400;">/</span>
                            <span id="statErr">0</span>
                        </div>
                        <div class="stat-hint">启动于 <span id="statStart">-</span></div>
                    </div>
                </div>

                <!-- 连接详情 -->
                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <div class="section-title"><span class="bar"></span> 连接详情</div>
                            <div class="section-sub">每个机器人一条独立 WS · 自动心跳与断线重连/续传</div>
                        </div>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>AppID</th>
                                        <th>状态</th>
                                        <th>Session</th>
                                        <th>Seq</th>
                                        <th>网关</th>
                                        <th>接收</th>
                                        <th>分发</th>
                                        <th>重连</th>
                                        <th>错误</th>
                                    </tr>
                                </thead>
                                <tbody id="connTable">
                                    <tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:32px;">加载中...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 运行日志 -->
                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <div class="section-title"><span class="bar"></span> 运行日志</div>
                            <div class="section-sub">来自 Log/ws.log · 每 3 秒自动刷新</div>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <label style="font-size:12px;color:var(--text-muted);display:inline-flex;align-items:center;gap:5px;cursor:pointer;">
                                <input type="checkbox" id="autoScroll" checked> 自动滚动
                            </label>
                            <button class="btn btn-ghost btn-sm" id="clearLogBtn"><i class="fas fa-eraser"></i> 清空</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="log-box" id="logBox"><div class="log-empty">暂无日志</div></div>
                    </div>
                </div>

                <!-- 使用说明 -->
                <div class="section-card">
                    <div class="section-header">
                        <div>
                            <div class="section-title"><span class="bar"></span> 使用说明</div>
                            <div class="section-sub">WebSocket 与 Webhook 可并存或独立使用</div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="item"><span class="k">守护进程脚本：</span><span class="v code">ws.php</span></div>
                            <div class="item"><span class="k">CLI 手动启动：</span><span class="v code">php ws.php</span></div>
                            <div class="item"><span class="k">指定机器人：</span><span class="v code">php ws.php --appid=102030000</span></div>
                            <div class="item"><span class="k">单次测试：</span><span class="v code">php ws.php --once</span></div>
                            <div class="item"><span class="k">状态文件：</span><span class="v code">database/ws_status.json</span></div>
                            <div class="item"><span class="k">日志文件：</span><span class="v code">Log/ws.log</span></div>
                            <div class="item"><span class="k">订阅 Intents：</span><span class="v">C2C_GROUP_AT_MESSAGES · INTERACTION · DIRECT_MESSAGE · PUBLIC_GUILD_MESSAGES</span></div>
                            <div class="item"><span class="k">事件复用：</span><span class="v">复用 index.php 的 Main() 与插件加载逻辑</span></div>
                        </div>
                        <p style="margin-top:16px;font-size:12px;color:var(--text-muted);line-height:1.7;">
                            说明：开启「自动后台保活」后，每次访问本页都会自动检测守护进程状态，未运行时自动后台拉起（exec + & 后台模式），无需 CLI。
                            WebSocket 模式下，机器人事件通过 QQ 官方 WS 网关主动推送，无需公网回调地址，适合内网/本地部署。
                            断线时自动 Resume 续传，无法续传则重新 Identify。
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- 浮动按钮（移动端快捷操作） -->
    <button class="fab" id="fabStart" title="一键启动"><i class="fas fa-bolt"></i></button>

    <!-- 通知 -->
    <div id="notification" class="notification">
        <div class="icon"><i class="fas fa-check"></i></div>
        <span id="notificationText">操作完成</span>
    </div>

    <script>
        /* ===================== 通知 ===================== */
        function showMsg(text, ok) {
            const el = document.getElementById('notification');
            const txt = document.getElementById('notificationText');
            txt.textContent = text;
            el.classList.remove('success', 'error', 'info');
            el.classList.add(ok === true ? 'success' : (ok === false ? 'error' : 'info'));
            const iconEl = el.querySelector('.icon i');
            iconEl.className = ok === true ? 'fas fa-check' : (ok === false ? 'fas fa-xmark' : 'fas fa-info');
            el.classList.add('show');
            clearTimeout(showMsg._t);
            showMsg._t = setTimeout(() => el.classList.remove('show'), 2600);
        }

        /* ===================== 状态拉取 ===================== */
        const stateLabel = {
            init: '初始化', connected: '已连接', ready: '就绪',
            reconnecting: '重连中', closed: '已关闭'
        };

        async function fetchStatus() {
            try {
                const r = await fetch('api/ws.php?type=status');
                const d = await r.json();
                const running = !!d.running;
                const stateEl = document.getElementById('statState');
                stateEl.className = 'run-pill ' + (running ? '' : 'off');
                stateEl.innerHTML = '<span class="dot"></span>' + (running ? '运行中' : '未运行');
                document.getElementById('statPid').textContent = 'PID: ' + (d.pid || '-') + (d.pid_alive ? ' (存活)' : '');
                document.getElementById('statStart').textContent = d.started_at || '-';
                document.getElementById('mobileBadge').textContent = running ? 'ON' : 'OFF';

                const conns = d.connections || {};
                const appids = Object.keys(conns);
                document.getElementById('statBots').textContent = appids.length;
                let recv = 0, disp = 0, reconn = 0, err = 0;
                appids.forEach(a => {
                    recv += (conns[a].received || 0);
                    disp += (conns[a].dispatched || 0);
                    reconn += (conns[a].reconnects || 0);
                    err += (conns[a].errors || 0);
                });
                document.getElementById('statRecv').textContent = recv;
                document.getElementById('statDisp').textContent = disp;
                document.getElementById('statReconn').textContent = reconn;
                document.getElementById('statErr').textContent = err;

                const tb = document.getElementById('connTable');
                if (!appids.length) {
                    tb.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:32px;">暂无连接' + (running ? '' : '（守护进程未启动）') + '</td></tr>';
                } else {
                    tb.innerHTML = appids.map(a => {
                        const c = conns[a];
                        const st = c.state || 'init';
                        return `<tr>
                            <td><strong>${escapeHtml(a)}</strong></td>
                            <td><span class="state-badge state-${st}"><span class="dot"></span>${stateLabel[st]||st}</span></td>
                            <td class="td-mono">${escapeHtml(c.session_id||'-')}</td>
                            <td>${c.seq==null?'-':c.seq}</td>
                            <td class="td-muted">${escapeHtml(c.gateway||'-')}</td>
                            <td>${c.received||0}</td>
                            <td>${c.dispatched||0}</td>
                            <td>${c.reconnects||0}</td>
                            <td>${c.errors||0}</td>
                        </tr>`;
                    }).join('');
                }
                return d;
            } catch(e) { console.warn(e); return null; }
        }

        async function fetchLog() {
            try {
                const r = await fetch('api/ws.php?type=log&lines=200');
                const d = await r.json();
                const box = document.getElementById('logBox');
                const lines = d.lines || [];
                if (!lines.length) {
                    box.innerHTML = '<div class="log-empty">暂无日志</div>';
                    return;
                }
                box.innerHTML = lines.map(l => {
                    let cls = 'log-line';
                    if (/失败|错误|异常|Invalid|Reconnect|exit/i.test(l)) cls += ' err';
                    else if (/READY|就绪|RESUMED|connected|建立/i.test(l)) cls += ' ok';
                    return `<div class="${cls}">${escapeHtml(l)}</div>`;
                }).join('');
                if (document.getElementById('autoScroll').checked) box.scrollTop = box.scrollHeight;
            } catch(e) {}
        }

        function escapeHtml(s){ if(s==null)return ''; return String(s).replace(/[&<>"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }

        /* ===================== 自动保活本地存储 ===================== */
        const KA_KEY = 'ws_keepalive_enabled';
        const kaToggle = document.getElementById('keepaliveToggle');

        function getKeepalive() { return localStorage.getItem(KA_KEY) === '1'; }
        function setKeepalive(on) {
            localStorage.setItem(KA_KEY, on ? '1' : '0');
            kaToggle.checked = on;
        }
        kaToggle.checked = getKeepalive();
        kaToggle.addEventListener('change', async () => {
            const on = kaToggle.checked;
            setKeepalive(on);
            showMsg(on ? '已开启自动保活' : '已关闭自动保活', true);
            if (on) {
                // 立即尝试拉起
                const r = await fetch('api/ws.php?type=autostart');
                const d = await r.json();
                if (d.code === 200) showMsg('守护进程已自动拉起', true);
                setTimeout(fetchStatus, 1000);
            }
        });

        /* ===================== 操作按钮 ===================== */
        document.getElementById('refreshBtn').addEventListener('click', () => { fetchStatus(); fetchLog(); });

        async function doStart() {
            try {
                const r = await fetch('api/ws.php?type=start');
                const d = await r.json();
                showMsg(d.msg || '已启动', d.code === 200);
                setTimeout(fetchStatus, 1200);
            } catch(e){ showMsg('启动失败', false); }
        }
        async function doStop() {
            try {
                const r = await fetch('api/ws.php?type=stop');
                const d = await r.json();
                showMsg(d.msg || '已停止', d.code === 200);
                setTimeout(fetchStatus, 1500);
            } catch(e){ showMsg('停止失败', false); }
        }

        document.getElementById('startBtn').addEventListener('click', doStart);
        document.getElementById('stopBtn').addEventListener('click', doStop);
        document.getElementById('fabStart').addEventListener('click', doStart);

        document.getElementById('clearLogBtn').addEventListener('click', async () => {
            await fetch('api/ws.php?type=clear_log');
            fetchLog();
            showMsg('日志已清空', true);
        });

        /* ===================== 侧栏（移动端） ===================== */
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('show'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
        if (menuToggle) {
            menuToggle.addEventListener('click', openSidebar);
            overlay.addEventListener('click', closeSidebar);
        }

        /* ===================== 添加机器人快捷入口 ===================== */
        const navAddBot = document.getElementById('navAddBot');
        if (navAddBot) {
            navAddBot.addEventListener('click', (e) => {
                e.preventDefault();
                closeSidebar();
                showMsg('请在「总览」页面添加机器人', 'info');
                setTimeout(() => { window.location.href = 'main.php'; }, 800);
            });
        }

        /* ===================== 自动保活 - 进入即检查 ===================== */
        async function autoKeepaliveCheck() {
            if (!getKeepalive()) return;
            try {
                const r = await fetch('api/ws.php?type=autostart');
                const d = await r.json();
                if (d.code === 200 && d.msg && d.msg.indexOf('已自动拉起') !== -1) {
                    showMsg('检测到守护进程未运行，已自动拉起', true);
                }
            } catch(e) {}
        }

        /* ===================== 启动 ===================== */
        fetchStatus();
        fetchLog();
        autoKeepaliveCheck();
        setInterval(() => { fetchStatus(); fetchLog(); }, 3000);

        // 监听网络恢复
        window.addEventListener('online', () => { fetchStatus(); fetchLog(); autoKeepaliveCheck(); });
    </script>
</body>
</html>
