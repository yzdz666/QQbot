<?php
if (!isset($_COOKIE['admin_token'])) {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>在线更新 · 月下独酌管机</title>
    <link rel="stylesheet" href="assets/icons.css">
    <style>
        *, *::before, *::after {
            margin: 0; padding: 0; box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        :focus { outline: none; }
        :root {
            --bg: #ffffff;
            --card: #ffffff;
            --border: #ececec;
            --text-main: #1a1a1a;
            --text-sub: #555555;
            --text-muted: #999999;
            --primary: #1f1f1f;
            --primary-hover: #000000;
            --success: #3a7a3a;
            --danger: #c23d2e;
            --warning: #666666;
            --sidebar-width: 240px;
            --header-height: 52px;
        }
        body {
            background: var(--bg);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text-main);
            line-height: 1.5;
            overflow-x: hidden;
            width: 100%;
        }
        .desktop-layout { display: flex; min-height: 100vh; }
        .sidebar {
            width: var(--sidebar-width);
            background: var(--card);
            border-right: 1px solid var(--border);
            position: fixed; top: 0; bottom: 0; left: 0;
            display: flex; flex-direction: column;
            z-index: 100;
        }
        .sidebar-header { padding: 20px 24px; border-bottom: 1px solid var(--border); }
        .sidebar-header h1 { font-size: 18px; font-weight: 600; }
        .sidebar-header p { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .sidebar-nav { flex: 1; padding: 16px 0; overflow-y: auto; }
        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 24px; color: var(--text-sub);
            text-decoration: none; font-size: 14px; font-weight: 500;
            transition: all 0.15s; cursor: pointer;
        }
        .nav-item:hover { background: #f5f5f5; color: var(--primary); }
        .nav-item.active { background: #f5f5f5; color: var(--primary); border-left: 3px solid var(--primary); padding-left: 21px; }
        .nav-item i { width: 20px; font-size: 15px; }
        .sidebar-footer { padding: 16px 24px; border-top: 1px solid var(--border); font-size: 11px; color: var(--text-muted); }
        .main-content {
            flex: 1; margin-left: var(--sidebar-width); min-height: 100vh;
            min-width: 0; overflow-x: hidden;
        }
        .top-bar {
            background: var(--card); border-bottom: 1px solid var(--border);
            padding: 0 32px; height: var(--header-height);
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 10;
        }
        .page-title { font-size: 15px; font-weight: 500; }
        .container {
            padding: 28px 32px; max-width: 1200px; margin: 0 auto;
            overflow-x: hidden;
        }
        .card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 16px; padding: 24px; margin-bottom: 24px;
            overflow-x: hidden;
        }
        .card-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px; flex-wrap: wrap; gap: 12px;
        }
        .card-header h2 { font-size: 18px; font-weight: 600; }
        .version-info {
            display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
        }
        .version-badge {
            display: inline-block; padding: 4px 12px; border-radius: 20px;
            font-size: 13px; font-weight: 600;
        }
        .version-badge.current { background: #f0f0f0; color: var(--text-main); }
        .version-badge.latest { background: #f5f5f5; color: var(--success); }
        .version-badge.outdated { background: #f7f7f7; color: var(--danger); }
        .version-badge.prerelease { background: #fef6e0; color: var(--warning); }
        .btn {
            padding: 8px 18px; border-radius: 10px; border: none;
            cursor: pointer; font-weight: 500;
            display: inline-flex; align-items: center; gap: 8px;
            white-space: nowrap; transition: all 0.15s;
            font-size: 14px;
        }
        .btn-sm { padding: 5px 12px; font-size: 12px; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-secondary { background: #f5f5f5; color: var(--text-sub); border: 1px solid var(--border); }
        .btn-secondary:hover { background: #ececec; }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #235b23; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #a83426; }
        .btn-warning { background: var(--warning); color: white; }
        .btn-warning:hover { background: #9a4c0c; }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .update-status {
            padding: 12px 16px; border-radius: 10px; margin-top: 16px;
            display: none; align-items: center; gap: 12px;
        }
        .update-status.show { display: flex; }
        .update-status.loading { background: #f5f5f5; color: var(--primary); }
        .update-status.success { background: #f5f5f5; color: var(--success); }
        .update-status.error { background: #f7f7f7; color: var(--danger); }
        .update-status .spinner {
            width: 20px; height: 20px; border: 2px solid var(--border);
            border-top-color: var(--primary); border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .release-notes {
            background: #ffffff; border-radius: 10px; padding: 16px;
            margin-top: 12px; max-height: 300px; overflow-y: auto;
            white-space: pre-wrap; font-size: 13px; line-height: 1.6;
        }
        .progress-bar {
            width: 100%; height: 6px; background: var(--border);
            border-radius: 3px; margin-top: 12px; overflow: hidden;
            display: none;
        }
        .progress-bar.show { display: block; }
        .progress-bar .fill {
            height: 100%; background: var(--primary); border-radius: 3px;
            width: 0%; transition: width 0.3s ease;
        }
        .backup-list { display: flex; flex-direction: column; gap: 8px; }
        .backup-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 10px 14px; background: #ffffff; border-radius: 8px;
            border: 1px solid var(--border);
        }
        .backup-item .name { font-size: 13px; font-weight: 500; }
        .backup-item .actions { display: flex; gap: 8px; }
        .backup-empty {
            text-align: center;
            padding: 30px 20px;
            color: var(--text-muted);
        }
        .backup-empty i {
            font-size: 32px;
            display: block;
            margin-bottom: 12px;
            opacity: 0.5;
        }
        .backup-empty .hint {
            font-size: 12px;
            margin-top: 4px;
        }
        .mobile-header { display: none; padding: 12px 16px; background: white; border-bottom: 1px solid var(--border); align-items: center; justify-content: space-between; }
        .menu-toggle { background: none; border: none; font-size: 20px; cursor: pointer; }
        .notification {
            position: fixed; bottom: 20px; right: 20px;
            padding: 10px 16px; border-radius: 8px; font-size: 13px;
            background: var(--text-main); color: white; z-index: 1100;
            transform: translateX(120%); transition: transform 0.2s;
            max-width: calc(100vw - 40px);
        }
        .notification.show { transform: translateX(0); }
        .notification.success { background: var(--success); }
        .notification.error { background: var(--danger); }
        .notification.info { background: var(--primary); }
        .modal {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.4); z-index: 1000;
            align-items: center; justify-content: center; padding: 20px;
        }
        .modal-content {
            background: white; border-radius: 12px;
            width: 100%; max-width: 480px; max-height: 90vh;
            overflow-y: auto; overflow-x: hidden;
        }
        .modal-header { padding: 18px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 16px; font-weight: 600; }
        .close-btn { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--text-muted); padding: 4px; }
        .modal-body { padding: 20px; }
        .modal-footer { padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: var(--text-sub); margin-bottom: 6px; }
        .form-control, .form-select {
            width: 100%; padding: 10px 12px; font-size: 14px;
            border: 1px solid var(--border); border-radius: 8px;
            font-family: inherit; background: white;
            max-width: 100%;
        }
        .form-select:disabled { opacity: 0.6; cursor: not-allowed; }
        .version-select-wrapper {
            display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;
        }
        .version-select-wrapper .form-group {
            flex: 1; min-width: 200px; margin-bottom: 0;
        }
        .tag-prerelease {
            background: #fef6e0;
            color: var(--warning);
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: 8px;
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.2s; z-index: 200; box-shadow: 2px 0 12px rgba(0,0,0,0.1); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .mobile-header { display: flex; }
            .top-bar { display: none; }
            .container { padding: 16px; }
            .card { padding: 16px; }
            .version-info { flex-direction: column; align-items: flex-start; }
            .version-select-wrapper { flex-direction: column; }
            .version-select-wrapper .form-group { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="mobile-header">
        <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
        <span style="font-weight:500;">月下独酌管机</span>
        <div></div>
    </div>

    <div class="desktop-layout">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h1>月下独酌管机</h1>
                <p>机器人管理后台</p>
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
                <a href="update.php" class="nav-item active"><i class="fas fa-cloud-upload-alt"></i> 在线更新</a>
                <a href="ws.php" class="nav-item"><i class="fas fa-sync-alt"></i> WebSocket 模式</a>
            </nav>
            <div class="sidebar-footer">保留 1.0 原有逻辑 · 简洁商务版</div>
        </aside>

        <main class="main-content">
            <div class="top-bar">
                <div class="page-title">在线更新</div>
                <a href="main.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> 返回后台</a>
            </div>

            <div class="container">
                <!-- 版本信息卡片 -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-info-circle"></i> 版本信息</h2>
                        <button id="checkUpdateBtn" class="btn btn-primary"><i class="fas fa-sync-alt"></i> 检查更新</button>
                    </div>
                    <div class="version-info">
                        <div>
                            <span class="version-badge current">当前版本: <span id="currentVersion">加载中...</span></span>
                            <span class="version-badge latest" id="latestVersionBadge" style="display:none;">最新版本: <span id="latestVersion">-</span></span>
                        </div>
                        <div id="updateStatusLabel">
                            <span class="version-badge outdated" style="display:none;" id="updateAvailableBadge">⚠️ 有新版本可用</span>
                            <span class="version-badge latest" style="display:none;" id="upToDateBadge">✅ 已是最新版本</span>
                        </div>
                    </div>
                    <div id="releaseNotes" class="release-notes" style="display:none;"></div>
                    <div id="updateStatus" class="update-status"></div>
                    <div class="progress-bar" id="progressBar"><div class="fill" id="progressFill"></div></div>
                    <div style="margin-top:16px; display:flex; gap:12px; flex-wrap:wrap;" id="actionButtons">
                        <button id="downloadUpdateBtn" class="btn btn-success" style="display:none;"><i class="fas fa-download"></i> 下载最新版</button>
                        <button id="applyUpdateBtn" class="btn btn-warning" style="display:none;"><i class="fas fa-upload"></i> 应用更新</button>
                        <button id="rollbackBtn" class="btn btn-secondary"><i class="fas fa-undo-alt"></i> 管理备份</button>
                    </div>
                </div>

                <!-- 历史版本卡片（新增） -->
                <div class="card" id="versionSelectCard">
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> 历史版本</h2>
                        <button id="refreshVersionsBtn" class="btn btn-secondary btn-sm"><i class="fas fa-sync-alt"></i> 刷新列表</button>
                    </div>
                    <div class="version-select-wrapper">
                        <div class="form-group">
                            <label for="versionSelect">选择要下载的版本：</label>
                            <select class="form-select" id="versionSelect">
                                <option value="">-- 加载中... --</option>
                            </select>
                        </div>
                        <button id="downloadVersionBtn" class="btn btn-success" style="margin-bottom:2px;"><i class="fas fa-download"></i> 下载此版本</button>
                    </div>
                    <div id="versionReleaseNotes" class="release-notes" style="display:none;margin-top:12px;"></div>
                </div>

                <!-- 更新日志卡片 -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-list-ul"></i> 更新日志</h2>
                    </div>
                    <div id="changelogContent">
                        <p style="color: var(--text-muted);">点击"检查更新"查看最新版本信息</p>
                    </div>
                </div>

                <!-- 备份管理卡片 -->
                <div class="card" id="backupCard">
                    <div class="card-header">
                        <h2><i class="fas fa-archive"></i> 备份管理</h2>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button id="cleanBackupsBtn" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> 清理旧备份</button>
                        </div>
                    </div>
                    <div id="backupList" class="backup-list">
                        <p style="color: var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> 加载备份列表中...</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- 添加机器人模态框 -->
    <div class="modal" id="addModal">
        <div class="modal-content">
            <div class="modal-header"><h3>添加机器人</h3><button class="close-btn" data-close="addModal">&times;</button></div>
            <form id="addForm">
                <div class="modal-body">
                    <div class="form-group"><label>AppID</label><input type="text" class="form-control" id="addAppid" required placeholder="请输入机器人 AppID"></div>
                    <div class="form-group"><label>Secret</label><input type="text" class="form-control" id="addSecret" required placeholder="请输入机器人 Secret"></div>
                    <div class="form-group"><label>环境</label><select class="form-select" id="addEnvironment"><option value="正式">正式环境</option><option value="沙箱">沙箱环境</option></select></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-close="addModal">取消</button><button type="submit" class="btn btn-primary">添加</button></div>
            </form>
        </div>
    </div>

    <!-- 确认对话框 -->
    <div class="modal" id="confirmModal">
        <div class="modal-content" style="max-width:400px;">
            <div class="modal-header"><h3 id="confirmTitle">确认操作</h3><button class="close-btn" data-close="confirmModal">&times;</button></div>
            <div class="modal-body" id="confirmBody"><p>确定执行此操作吗？</p></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-close="confirmModal">取消</button>
                <button class="btn btn-danger" id="confirmActionBtn">确认</button>
            </div>
        </div>
    </div>

    <div id="notification" class="notification"></div>

    <script>
        // ==================== 全局变量 ====================
        let currentVersion = '';
        let latestVersion = '';
        let hasUpdate = false;
        let downloadSourceDir = '';
        let downloadTempDir = '';
        let isUpdating = false;
        let availableVersions = [];
        let backupCardVisible = true;

        // ==================== DOM引用 ====================
        const $ = id => document.getElementById(id);
        const currentVersionEl = $('currentVersion');
        const latestVersionEl = $('latestVersion');
        const latestVersionBadge = $('latestVersionBadge');
        const updateAvailableBadge = $('updateAvailableBadge');
        const upToDateBadge = $('upToDateBadge');
        const releaseNotes = $('releaseNotes');
        const updateStatus = $('updateStatus');
        const progressBar = $('progressBar');
        const progressFill = $('progressFill');
        const downloadBtn = $('downloadUpdateBtn');
        const applyBtn = $('applyUpdateBtn');
        const checkBtn = $('checkUpdateBtn');
        const rollbackBtn = $('rollbackBtn');
        const cleanBackupsBtn = $('cleanBackupsBtn');
        const backupCard = $('backupCard');
        const backupList = $('backupList');
        const changelogContent = $('changelogContent');
        const versionSelect = $('versionSelect');
        const versionReleaseNotes = $('versionReleaseNotes');
        const refreshVersionsBtn = $('refreshVersionsBtn');
        const downloadVersionBtn = $('downloadVersionBtn');

        // ==================== 工具函数 ====================
        function showMsg(text, type = 'info') {
            const el = $('notification');
            el.textContent = text;
            el.className = 'notification ' + type + ' show';
            setTimeout(() => el.classList.remove('show'), 4000);
        }

        function closeModal(id) { 
            const modal = document.getElementById(id);
            if (modal) modal.style.display = 'none';
        }

        function showModal(title, body, confirmText, confirmCallback) {
            $('confirmTitle').textContent = title;
            $('confirmBody').innerHTML = typeof body === 'string' ? `<p>${body}</p>` : body;
            $('confirmActionBtn').textContent = confirmText || '确认';
            $('confirmActionBtn').onclick = () => {
                closeModal('confirmModal');
                if (confirmCallback) confirmCallback();
            };
            $('confirmModal').style.display = 'flex';
        }

        function setStatus(text, type = 'loading') {
            updateStatus.className = 'update-status show ' + type;
            if (type === 'loading') {
                updateStatus.innerHTML = `<div class="spinner"></div><span>${text}</span>`;
            } else {
                const icon = type === 'success' ? '✅' : (type === 'error' ? '❌' : 'ℹ️');
                updateStatus.innerHTML = `<span>${icon} ${text}</span>`;
            }
        }

        function hideStatus() {
            updateStatus.className = 'update-status';
        }

        function setProgress(percent) {
            progressBar.classList.add('show');
            progressFill.style.width = Math.min(100, percent) + '%';
            if (percent >= 100) {
                setTimeout(() => {
                    progressBar.classList.remove('show');
                    progressFill.style.width = '0%';
                }, 1000);
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"]/g, m => {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                if (m === '"') return '&quot;';
                return m;
            });
        }

        function formatDate(dateStr) {
            if (!dateStr) return '日期未知';
            try {
                const d = new Date(dateStr);
                return d.toLocaleDateString('zh-CN') + ' ' + d.toLocaleTimeString('zh-CN', {hour: '2-digit', minute: '2-digit'});
            } catch {
                return dateStr.substring(0, 10);
            }
        }

        // ==================== 核心功能 ====================

        /**
         * 检查更新
         */
        async function checkUpdate() {
            checkBtn.disabled = true;
            checkBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 检查中...';
            hideStatus();
            
            try {
                const res = await fetch('api/update.php?type=check');
                const data = await res.json();
                
                if (data.code !== 200) {
                    showMsg(data.msg || '检查更新失败', 'error');
                    checkBtn.disabled = false;
                    checkBtn.innerHTML = '<i class="fas fa-sync-alt"></i> 检查更新';
                    return;
                }
                
                const info = data.data;
                currentVersion = info.current_version;
                latestVersion = info.latest_version;
                hasUpdate = info.has_update;
                
                // 更新显示
                currentVersionEl.textContent = currentVersion;
                latestVersionEl.textContent = latestVersion;
                latestVersionBadge.style.display = 'inline-block';
                
                // 显示版本状态
                if (hasUpdate) {
                    updateAvailableBadge.style.display = 'inline-block';
                    upToDateBadge.style.display = 'none';
                    downloadBtn.style.display = 'inline-flex';
                    applyBtn.style.display = 'none';
                } else {
                    updateAvailableBadge.style.display = 'none';
                    upToDateBadge.style.display = 'inline-block';
                    downloadBtn.style.display = 'none';
                    applyBtn.style.display = 'none';
                }
                
                // 显示发布说明
                if (info.release_body) {
                    releaseNotes.style.display = 'block';
                    releaseNotes.innerHTML = `
                        <strong>📦 ${escapeHtml(info.release_name || '新版本')}</strong>
                        ${info.published_at ? `<span style="color:var(--text-muted);font-size:12px;"> · ${escapeHtml(formatDate(info.published_at))}</span>` : ''}
                        <hr style="border-color:var(--border);margin:8px 0;">
                        ${escapeHtml(info.release_body).replace(/\n/g, '<br>')}
                        ${info.html_url ? `<br><br><a href="${escapeHtml(info.html_url)}" target="_blank" style="color:var(--primary);">查看详情 →</a>` : ''}
                    `;
                } else {
                    releaseNotes.style.display = 'none';
                }
                
                // 更新changelog
                changelogContent.innerHTML = `
                    <div style="background:#ffffff;border-radius:10px;padding:16px;">
                        <p><strong>当前版本:</strong> ${escapeHtml(currentVersion)}</p>
                        <p><strong>最新版本:</strong> ${escapeHtml(latestVersion)}</p>
                        <p><strong>状态:</strong> ${hasUpdate ? '⚠️ 有新版本可用' : '✅ 已是最新版本'}</p>
                        ${hasUpdate ? `<p style="margin-top:8px;"><a href="${escapeHtml(info.html_url)}" target="_blank" class="btn btn-primary btn-sm">前往GitHub查看</a></p>` : ''}
                    </div>
                `;
                
                if (hasUpdate) {
                    showMsg('发现新版本 v' + latestVersion, 'info');
                } else {
                    showMsg('已是最新版本', 'success');
                }
                
            } catch (err) {
                showMsg('检查更新失败: ' + err.message, 'error');
            } finally {
                checkBtn.disabled = false;
                checkBtn.innerHTML = '<i class="fas fa-sync-alt"></i> 检查更新';
            }
        }

        /**
         * 加载所有版本列表（历史版本）
         */
        async function loadVersions() {
            versionSelect.disabled = true;
            versionSelect.innerHTML = '<option value="">-- 加载中... --</option>';
            
            try {
                const res = await fetch('api/update.php?type=versions');
                const data = await res.json();
                
                if (data.code !== 200 || !data.data || data.data.length === 0) {
                    versionSelect.innerHTML = '<option value="">-- 暂无历史版本 --</option>';
                    return;
                }
                
                availableVersions = data.data;
                
                // 填充下拉列表
                versionSelect.innerHTML = '<option value="">-- 请选择版本 --</option>';
                availableVersions.forEach((v, index) => {
                    const opt = document.createElement('option');
                    opt.value = v.version;
                    let label = `v${v.version}`;
                    if (v.prerelease) label += ' 🔶 预发布';
                    if (index === 0) label += ' (最新)';
                    if (v.published_at) label += ` - ${formatDate(v.published_at)}`;
                    opt.textContent = label;
                    // 如果是预发布版本，添加样式标记
                    if (v.prerelease) {
                        opt.style.color = 'var(--warning)';
                    }
                    select.appendChild(opt);
                });
                
            } catch (err) {
                console.error('加载版本列表失败:', err);
                versionSelect.innerHTML = '<option value="">-- 加载失败，请刷新 --</option>';
                showMsg('加载历史版本失败: ' + err.message, 'error');
            } finally {
                versionSelect.disabled = false;
            }
        }

        /**
         * 下载指定历史版本
         */
        async function downloadVersion() {
            const version = versionSelect.value;
            
            if (!version) {
                showMsg('请选择要下载的版本', 'error');
                return;
            }
            
            // 查找版本信息
            const versionInfo = availableVersions.find(v => v.version === version);
            if (!versionInfo) {
                showMsg('未找到该版本信息', 'error');
                return;
            }
            
            // 如果已经有下载好的版本且未应用，提醒用户
            if (downloadSourceDir) {
                showModal(
                    '已有下载的版本',
                    `<p>您已经下载了版本，尚未应用。</p>
                    <p style="color:var(--text-muted);font-size:12px;">继续下载将覆盖之前的下载文件。</p>`,
                    '继续下载',
                    () => doDownloadVersion(version, versionInfo)
                );
                return;
            }
            
            doDownloadVersion(version, versionInfo);
        }

        /**
         * 执行下载历史版本
         */
        async function doDownloadVersion(version, versionInfo) {
            downloadVersionBtn.disabled = true;
            downloadVersionBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 下载中...';
            setStatus(`正在下载 v${version}...`, 'loading');
            setProgress(20);
            
            try {
                // 使用版本号下载
                const res = await fetch(`api/update.php?type=download&version=${encodeURIComponent(version)}`);
                const data = await res.json();
                
                setProgress(60);
                
                if (data.code !== 200) {
                    throw new Error(data.msg || '下载失败');
                }
                
                downloadSourceDir = data.data.source_dir;
                downloadTempDir = data.data.temp_dir;
                
                setProgress(90);
                setStatus(`✅ v${version} 下载完成，请点击"应用更新"`, 'success');
                
                // 显示应用按钮，隐藏下载最新版按钮
                downloadBtn.style.display = 'none';
                applyBtn.style.display = 'inline-flex';
                
                showMsg(`版本 v${version} 下载完成，请点击"应用更新"`, 'success');
                setProgress(100);
                
                // 刷新备份列表
                setTimeout(loadBackups, 1000);
                
            } catch (err) {
                setStatus('下载失败: ' + err.message, 'error');
                showMsg('下载失败: ' + err.message, 'error');
                setProgress(0);
            } finally {
                downloadVersionBtn.disabled = false;
                downloadVersionBtn.innerHTML = '<i class="fas fa-download"></i> 下载此版本';
            }
        }

        /**
         * 下载最新版更新
         */
        async function downloadUpdate() {
            if (!hasUpdate) {
                showMsg('没有可用的更新', 'error');
                return;
            }
            
            showModal(
                '确认下载最新版',
                `即将下载最新版本 <strong>v${latestVersion}</strong>，系统将自动创建备份。确定继续吗？`,
                '下载',
                async () => {
                    downloadBtn.disabled = true;
                    downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 下载中...';
                    setStatus('正在下载最新版...', 'loading');
                    setProgress(30);
                    
                    try {
                        // 获取下载链接
                        const checkRes = await fetch('api/update.php?type=check');
                        const checkData = await checkRes.json();
                        if (checkData.code !== 200) {
                            throw new Error(checkData.msg || '获取下载链接失败');
                        }
                        
                        const url = checkData.data.zipball_url;
                        if (!url) {
                            throw new Error('没有可用的下载链接');
                        }
                        
                        setProgress(50);
                        
                        // 下载
                        const dlRes = await fetch(`api/update.php?type=download&url=${encodeURIComponent(url)}`);
                        const dlData = await dlRes.json();
                        
                        if (dlData.code !== 200) {
                            throw new Error(dlData.msg || '下载失败');
                        }
                        
                        downloadSourceDir = dlData.data.source_dir;
                        downloadTempDir = dlData.data.temp_dir;
                        
                        setProgress(80);
                        setStatus('下载完成，准备应用更新...', 'success');
                        
                        downloadBtn.style.display = 'none';
                        applyBtn.style.display = 'inline-flex';
                        
                        showMsg('下载完成，请点击"应用更新"', 'success');
                        setProgress(100);
                        
                        setTimeout(loadBackups, 1000);
                        
                    } catch (err) {
                        setStatus('下载失败: ' + err.message, 'error');
                        showMsg('下载失败: ' + err.message, 'error');
                        setProgress(0);
                    } finally {
                        downloadBtn.disabled = false;
                        downloadBtn.innerHTML = '<i class="fas fa-download"></i> 下载最新版';
                    }
                }
            );
        }

        /**
         * 应用更新
         */
        async function applyUpdate() {
            if (!downloadSourceDir) {
                showMsg('请先下载更新', 'error');
                return;
            }
            
            // 获取当前下载的版本信息（从文件名或用户选择中获取）
            const versionLabel = versionSelect.value || latestVersion || '未知版本';
            
            showModal(
                '确认应用更新',
                `<p>即将应用版本 <strong>v${versionLabel}</strong>。</p>
                <p style="color:var(--danger);font-weight:bold;margin-top:8px;">⚠️ 应用更新后可能需要重新登录</p>
                <p style="color:var(--text-muted);font-size:12px;">已自动创建备份，如有问题可回滚。</p>`,
                '应用更新',
                async () => {
                    applyBtn.disabled = true;
                    applyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 应用更新中...';
                    setStatus('正在应用更新...', 'loading');
                    setProgress(60);
                    
                    try {
                        const res = await fetch(`api/update.php?type=apply&source_dir=${encodeURIComponent(downloadSourceDir)}`);
                        const data = await res.json();
                        
                        if (data.code === 200) {
                            setStatus('✅ ' + data.msg, 'success');
                            setProgress(100);
                            showMsg('更新成功！系统即将刷新...', 'success');
                            applyBtn.style.display = 'none';
                            downloadSourceDir = '';
                            
                            // 3秒后刷新
                            setTimeout(() => {
                                window.location.reload();
                            }, 3000);
                        } else {
                            throw new Error(data.msg || '应用更新失败');
                        }
                        
                    } catch (err) {
                        setStatus('应用更新失败: ' + err.message, 'error');
                        showMsg('应用更新失败: ' + err.message, 'error');
                        setProgress(0);
                        applyBtn.style.display = 'none';
                        downloadBtn.style.display = 'inline-flex';
                    } finally {
                        applyBtn.disabled = false;
                        applyBtn.innerHTML = '<i class="fas fa-upload"></i> 应用更新';
                    }
                }
            );
        }

        /**
         * 加载备份列表
         */
        async function loadBackups() {
            try {
                const res = await fetch('api/update.php?type=rollback');
                const data = await res.json();
                
                if (data.code !== 200) {
                    backupList.innerHTML = `<p style="color:var(--text-muted);">❌ 加载失败: ${escapeHtml(data.msg || '未知错误')}</p>`;
                    return;
                }
                
                const backups = data.data || [];
                
                if (!backups || backups.length === 0) {
                    backupList.innerHTML = `
                        <div class="backup-empty">
                            <i class="fas fa-archive"></i>
                            暂无备份
                            <div class="hint">💡 下载更新时会自动创建备份</div>
                        </div>
                    `;
                    return;
                }
                
                // 显示备份列表
                backupList.innerHTML = backups.map((backup, index) => `
                    <div class="backup-item">
                        <span class="name">📁 ${escapeHtml(backup)}</span>
                        <div class="actions">
                            <button class="btn btn-secondary btn-sm restore-backup" data-backup="${escapeHtml(backup)}">
                                <i class="fas fa-undo-alt"></i> 恢复
                            </button>
                        </div>
                    </div>
                `).join('');
                
                // 绑定恢复事件
                document.querySelectorAll('.restore-backup').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const backup = this.dataset.backup;
                        restoreBackup(backup);
                    });
                });
                
            } catch (err) {
                console.error('加载备份失败:', err);
                backupList.innerHTML = `<p style="color:var(--text-muted);">❌ 加载失败: ${escapeHtml(err.message)}</p>`;
            }
        }

        /**
         * 恢复备份
         */
        async function restoreBackup(backup) {
            showModal(
                '确认恢复',
                `即将恢复到备份 <strong>${escapeHtml(backup)}</strong>。确定继续吗？`,
                '恢复',
                async () => {
                    try {
                        showMsg('正在恢复...', 'info');
                        const res = await fetch(`api/update.php?type=rollback&backup_dir=${encodeURIComponent(backup)}`);
                        const data = await res.json();
                        
                        if (data.code === 200) {
                            showMsg('恢复成功！系统即将刷新...', 'success');
                            setTimeout(() => window.location.reload(), 2000);
                        } else {
                            showMsg(data.msg || '恢复失败', 'error');
                        }
                    } catch (err) {
                        showMsg('恢复失败: ' + err.message, 'error');
                    }
                    closeModal('confirmModal');
                }
            );
        }

        /**
         * 清理旧备份
         */
        async function cleanBackups() {
            showModal(
                '确认清理',
                '<p>将保留最近5个备份，删除其余旧备份。</p><p style="color:var(--warning);font-size:12px;">⚠️ 此操作不可恢复，请谨慎操作。</p>',
                '确认清理',
                async () => {
                    try {
                        showMsg('正在清理旧备份...', 'info');
                        const res = await fetch('api/update.php?type=clean_backups');
                        const data = await res.json();
                        if (data.code === 200) {
                            showMsg(data.msg, 'success');
                            setTimeout(loadBackups, 500);
                        } else {
                            showMsg(data.msg || '清理失败', 'error');
                        }
                    } catch (err) {
                        showMsg('清理失败: ' + err.message, 'error');
                    }
                    closeModal('confirmModal');
                }
            );
        }

        // ==================== 事件绑定 ====================

        // 检查更新按钮
        checkBtn.addEventListener('click', checkUpdate);

        // 下载最新版按钮
        downloadBtn.addEventListener('click', downloadUpdate);

        // 应用更新按钮
        applyBtn.addEventListener('click', applyUpdate);

        // 管理备份按钮 - 切换显示/隐藏
        rollbackBtn.addEventListener('click', function() {
            backupCardVisible = !backupCardVisible;
            backupCard.style.display = backupCardVisible ? 'block' : 'none';
            this.innerHTML = backupCardVisible ? 
                '<i class="fas fa-chevron-up"></i> 收起备份' : 
                '<i class="fas fa-undo-alt"></i> 管理备份';
            if (backupCardVisible) {
                loadBackups();
            }
        });

        // 清理备份按钮
        cleanBackupsBtn.addEventListener('click', cleanBackups);

        // 刷新版本列表
        refreshVersionsBtn.addEventListener('click', loadVersions);

        // 下载历史版本
        downloadVersionBtn.addEventListener('click', downloadVersion);

        // 版本选择时显示发布说明
        versionSelect.addEventListener('change', function() {
            const version = this.value;
            if (!version) {
                versionReleaseNotes.style.display = 'none';
                return;
            }
            const info = availableVersions.find(v => v.version === version);
            if (info && info.body) {
                versionReleaseNotes.style.display = 'block';
                versionReleaseNotes.innerHTML = `
                    <strong>📦 ${escapeHtml(info.name || '版本 ' + version)}</strong>
                    ${info.prerelease ? '<span class="tag-prerelease">🔶 预发布</span>' : ''}
                    ${info.published_at ? `<span style="color:var(--text-muted);font-size:12px;"> · ${escapeHtml(formatDate(info.published_at))}</span>` : ''}
                    <hr style="border-color:var(--border);margin:8px 0;">
                    ${escapeHtml(info.body).replace(/\n/g, '<br>')}
                    ${info.html_url ? `<br><br><a href="${escapeHtml(info.html_url)}" target="_blank" style="color:var(--primary);">查看详情 →</a>` : ''}
                `;
            } else {
                versionReleaseNotes.style.display = 'none';
            }
        });

        // 添加机器人
        document.getElementById('navAddBot').addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('addModal').style.display = 'flex';
        });

        document.getElementById('addForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const addAppid = document.getElementById('addAppid').value.trim();
            const addSecret = document.getElementById('addSecret').value.trim();
            const addEnv = document.getElementById('addEnvironment').value;
            if (!addAppid || !addSecret) { showMsg('请填写完整信息', 'error'); return; }
            try {
                const res = await fetch(`api/bot.php?type=add&appid=${encodeURIComponent(addAppid)}&secret=${encodeURIComponent(addSecret)}&environment=${encodeURIComponent(addEnv)}`);
                const data = await res.json();
                if (data.code === 200) { showMsg('添加成功', 'success'); closeModal('addModal'); document.getElementById('addForm').reset(); }
                else showMsg(data.msg || '添加失败', 'error');
            } catch (err) { showMsg('网络错误', 'error'); }
        });

        // 模态框关闭
        document.querySelectorAll('[data-close]').forEach(btn => {
            btn.addEventListener('click', () => closeModal(btn.dataset.close));
        });
        window.addEventListener('click', e => {
            if (e.target.classList.contains('modal')) e.target.style.display = 'none';
        });

        // 移动端菜单
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        if (menuToggle) {
            menuToggle.addEventListener('click', () => sidebar.classList.toggle('open'));
            document.addEventListener('click', (e) => {
                if (window.innerWidth <= 768 && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('open');
                }
            });
        }

        // ==================== 初始化 ====================
        function initPage() {
            console.log('页面初始化...');
            
            // 默认展开备份卡片
            backupCard.style.display = 'block';
            rollbackBtn.innerHTML = '<i class="fas fa-chevron-up"></i> 收起备份';
            
            // 加载所有功能
            checkUpdate();
            loadVersions();
            loadBackups();
        }

        // 使用多种方式确保初始化执行
        if (document.readyState === 'complete') {
            setTimeout(initPage, 200);
        } else {
            window.addEventListener('load', function() {
                setTimeout(initPage, 200);
            });
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(initPage, 300);
            });
        }
    </script>
</body>
</html>