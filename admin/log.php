<?php
if (!isset($_COOKIE['admin_token'])) {
    header("Location: index.php");
    exit();
}

$appid = $_GET['appid'] ?? '';
if (empty($appid)) {
    die("缺少appid参数");
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>月下独酌管机 · 日志管理 - <?php echo htmlspecialchars($appid); ?></title>
    <link rel="stylesheet" href="assets/icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        *, *::before, *::after { -webkit-tap-highlight-color: transparent; }
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
            --send: #1f1f1f;
            --receive: #3a7a3a;
            --event: #999999;
            --danger: #c23d2e;
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
        .sidebar-header h1 { font-size: 18px; font-weight: 600; color: var(--text-main); }
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
        .page-title { font-size: 15px; font-weight: 500; color: var(--text-main); }
        .container {
            padding: 28px 32px; max-width: 1400px;
            overflow-x: hidden;
        }

        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr);
            gap: 16px; margin-bottom: 24px;
        }
        .stat-card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 10px; padding: 14px 18px;
        }
        .stat-label { font-size: 12px; color: var(--text-muted); margin-bottom: 6px; }
        .stat-value { font-size: 20px; font-weight: 600; color: var(--text-main); word-break: break-all; }

        .card {
            background: var(--card); border: 1px solid var(--border);
            border-radius: 10px; overflow: hidden; margin-bottom: 20px;
        }
        .card-header {
            padding: 14px 20px; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px;
        }
        .card-header h2 { font-size: 16px; font-weight: 600; color: var(--text-main); }
        .card-body {
            padding: 20px;
            overflow-x: hidden;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .log-select {
            width: 100%; max-width: 320px;
            padding: 10px 12px; border: 1px solid var(--border);
            border-radius: 8px; font-size: 13px; background: white;
        }
        .log-actions { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }

        .filter-bar {
            display: flex; gap: 12px; flex-wrap: wrap;
            margin-bottom: 20px; align-items: center;
        }
        .filter-bar input, .filter-bar select {
            padding: 8px 12px; border: 1px solid var(--border);
            border-radius: 8px; font-size: 13px; background: white;
        }
        .filter-bar input { flex: 1; min-width: 180px; }
        .filter-bar select { width: 120px; }

        .log-list { display: flex; flex-direction: column; gap: 12px; }
        .log-card {
            border: 1px solid var(--border); border-left: 4px solid var(--event);
            border-radius: 8px; padding: 14px 16px; cursor: pointer;
            transition: all 0.15s; background: white;
            display: flex; gap: 14px; align-items: flex-start;
            max-width: 100%;
        }
        .log-card:hover { background: #fafcff; border-color: #cdd9ed; }
        .log-card.send { border-left-color: var(--send); }
        .log-card.receive { border-left-color: var(--receive); }
        .log-card.event { border-left-color: var(--event); }

        .log-avatar {
            flex-shrink: 0; width: 44px; height: 44px;
            border-radius: 50%; background: #f5f5f5;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        .log-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .log-avatar i { font-size: 22px; color: var(--text-muted); }

        .log-content { flex: 1; min-width: 0; }
        .log-header {
            display: flex; justify-content: space-between; align-items: baseline;
            flex-wrap: wrap; gap: 8px; margin-bottom: 6px;
        }
        .log-user { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
        .log-nickname { font-weight: 600; font-size: 14px; color: var(--text-main); }
        .log-time { font-size: 11px; color: var(--text-muted); }
        .log-badge { font-size: 10px; padding: 2px 8px; border-radius: 20px; background: #f5f5f5; }
        .log-badge.send { background: #eef2fc; color: var(--send); }
        .log-badge.receive { background: #f5f5f5; color: var(--receive); }
        .log-badge.event { background: #f5f5f5; color: var(--event); }
        .log-summary {
            font-size: 13px; color: var(--text-sub);
            word-break: break-word; line-height: 1.4; margin-top: 4px;
        }

        .btn {
            padding: 6px 14px; font-size: 13px; font-weight: 500;
            border-radius: 6px; cursor: pointer; border: none;
            display: inline-flex; align-items: center; gap: 6px;
            text-decoration: none; transition: all 0.15s; white-space: nowrap;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-secondary { background: #f5f5f5; color: var(--text-sub); border: 1px solid var(--border); }
        .btn-secondary:hover { background: #ececec; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #a83426; }

        .empty-state { text-align: center; padding: 48px 20px; color: var(--text-muted); }

        .modal {
            display: none; position: fixed; inset: 0;
            background: rgba(0, 0, 0, 0.4); z-index: 1000;
            align-items: center; justify-content: center; padding: 20px;
        }
        .modal-content {
            background: white; border-radius: 12px;
            width: 100%; max-width: 780px; max-height: 85vh;
            overflow-y: auto; overflow-x: hidden;
        }
        .modal-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 16px; font-weight: 600; }
        .close-btn { background: none; border: none; font-size: 18px; cursor: pointer; color: var(--text-muted); }
        .modal-body { padding: 20px; }
        .modal-footer { padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px; }

        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: var(--text-sub); margin-bottom: 6px; }
        .form-control, .form-select {
            width: 100%; padding: 10px 12px; font-size: 14px;
            border: 1px solid var(--border); border-radius: 8px;
            font-family: inherit; background: var(--card);
            max-width: 100%;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
        }

        .detail-row { display: flex; margin-bottom: 14px; }
        .detail-label { width: 80px; font-size: 12px; color: var(--text-muted); font-weight: 500; flex-shrink: 0; }
        .detail-value { flex: 1; font-size: 13px; color: var(--text-main); word-break: break-word; min-width: 0; }
        .raw-box {
            background: #f5f5f5; border: 1px solid var(--border);
            border-radius: 8px; padding: 12px;
            font-family: 'SF Mono', monospace; font-size: 11px;
            line-height: 1.5; overflow-x: auto; white-space: pre-wrap;
            max-height: 320px; max-width: 100%;
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
        .notification.success { background: #3a7a3a; }
        .notification.error { background: #c23d2e; }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.2s; z-index: 200; box-shadow: 2px 0 12px rgba(0,0,0,0.1); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .mobile-header { display: flex; }
            .top-bar { display: none; }
            .container { padding: 16px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .log-select { max-width: 100%; }
            .detail-row { flex-direction: column; }
            .detail-label { width: auto; margin-bottom: 4px; }
            .log-card { gap: 10px; }
            .log-avatar { width: 36px; height: 36px; }
            .log-avatar i { font-size: 18px; }
            .card-body { padding: 16px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
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
                <a href="update.php" class="nav-item"><i class="fas fa-cloud-upload-alt"></i> 在线更新</a>
                <a href="ws.php" class="nav-item"><i class="fas fa-sync-alt"></i> WebSocket 模式</a>
            </nav>
            <div class="sidebar-footer">保留 1.0 原有逻辑 · 简洁商务版</div>
        </aside>

        <main class="main-content">
            <div class="top-bar">
                <div class="page-title">日志管理 · <?php echo htmlspecialchars($appid); ?></div>
                <a href="main.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> 返回后台</a>
            </div>

            <div class="container">
                <div class="stats-grid">
                    <div class="stat-card"><div class="stat-label">当前日志</div><div class="stat-value" id="currentFile" style="font-size:13px;">未选择</div></div>
                    <div class="stat-card"><div class="stat-label">记录数</div><div class="stat-value" id="recordCount">0</div></div>
                    <div class="stat-card"><div class="stat-label">文件数</div><div class="stat-value" id="fileCount">0</div></div>
                    <div class="stat-card"><div class="stat-label">AppID</div><div class="stat-value" style="font-size:12px;"><?php echo htmlspecialchars($appid); ?></div></div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2>日志文件</h2>
                        <div class="log-actions">
                            <select id="logFileSelect" class="log-select"><option>加载中...</option></select>
                            <button id="refreshBtn" class="btn btn-secondary"><i class="fas fa-sync-alt"></i> 刷新</button>
                            <button id="deleteFileBtn" class="btn btn-danger"><i class="fas fa-trash"></i> 删除当前文件</button>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2>日志内容</h2>
                        <span id="logCount" class="stat-label">0 条记录</span>
                    </div>
                    <div class="card-body">
                        <div class="filter-bar">
                            <input type="text" id="searchInput" placeholder="🔍 搜索昵称 / 内容 / 原始数据" autocomplete="off">
                            <select id="typeFilter">
                                <option value="all">📋 全部类型</option>
                                <option value="send">📤 发送</option>
                                <option value="receive">📥 接收</option>
                                <option value="event">⚡ 事件</option>
                            </select>
                            <button id="resetFilterBtn" class="btn btn-secondary">重置筛选</button>
                        </div>
                        <div id="logList" class="log-list"><div class="empty-state">请选择日志文件</div></div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- 日志详情模态框 -->
    <div class="modal" id="detailModal">
        <div class="modal-content">
            <div class="modal-header"><h3>日志详情</h3><button class="close-btn" data-close="detailModal">&times;</button></div>
            <div class="modal-body">
                <div class="detail-row"><div class="detail-label">时间</div><div class="detail-value" id="detailTime"></div></div>
                <div class="detail-row"><div class="detail-label">类型</div><div class="detail-value" id="detailType"></div></div>
                <div class="detail-row"><div class="detail-label">目标</div><div class="detail-value" id="detailTarget"></div></div>
                <div class="detail-row"><div class="detail-label">内容</div><div class="detail-value" id="detailContent"></div></div>
                <div class="detail-row"><div class="detail-label">原始数据</div><div class="detail-value"><pre class="raw-box" id="detailRaw"></pre></div></div>
            </div>
            <div class="modal-footer"><button class="btn btn-secondary" data-close="detailModal">关闭</button></div>
        </div>
    </div>

    <!-- 删除确认模态框 -->
    <div class="modal" id="confirmModal">
        <div class="modal-content" style="max-width:400px;">
            <div class="modal-header"><h3>确认删除</h3><button class="close-btn" data-close="confirmModal">&times;</button></div>
            <div class="modal-body" style="text-align:center;"><p>确定要删除日志文件 <strong id="confirmFileName"></strong> 吗？</p><p style="font-size:12px; color:var(--text-muted);">此操作不可恢复</p></div>
            <div class="modal-footer"><button class="btn btn-secondary" data-close="confirmModal">取消</button><button class="btn btn-danger" id="confirmDeleteBtn">确认删除</button></div>
        </div>
    </div>

    <!-- 添加机器人模态框 -->
    <div class="modal" id="addModal">
        <div class="modal-content" style="max-width:480px;">
            <div class="modal-header"><h3>添加机器人</h3><button class="close-btn" data-close="addModal">&times;</button></div>
            <form id="addForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label>AppID</label>
                        <input type="text" class="form-control" id="addAppid" required placeholder="请输入机器人 AppID">
                    </div>
                    <div class="form-group">
                        <label>Secret</label>
                        <input type="text" class="form-control" id="addSecret" required placeholder="请输入机器人 Secret">
                    </div>
                    <div class="form-group">
                        <label>环境</label>
                        <select class="form-select" id="addEnvironment">
                            <option value="正式">正式环境</option>
                            <option value="沙箱">沙箱环境</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-close="addModal">取消</button>
                    <button type="submit" class="btn btn-primary">添加</button>
                </div>
            </form>
        </div>
    </div>

    <div id="notification" class="notification"></div>

    <script>
        const appid = '<?php echo addslashes($appid); ?>';
        let currentLogFile = '';
        let deleteTargetFile = '';
        let allLogsRaw = [];
        let logsMetadata = [];

        function showMsg(text, isSuccess) {
            const el = document.getElementById('notification');
            el.textContent = text;
            el.className = 'notification ' + (isSuccess ? 'success' : 'error') + ' show';
            setTimeout(() => el.classList.remove('show'), 2500);
        }

        function closeModal(id) { document.getElementById(id).style.display = 'none'; }
        document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
        window.addEventListener('click', e => { if (e.target.classList.contains('modal')) e.target.style.display = 'none'; });

        /**
         * 提取用户信息（支持所有事件类型）
         * @param {string} rawJson - 原始日志JSON字符串
         * @param {string} appid - 机器人AppID，用于生成头像链接
         * @returns {object} { openid, username, avatarUrl }
         */
        function extractUserInfo(rawJson, appid) {
            try {
                const data = JSON.parse(rawJson);
                let openid = null;
                let username = '系统事件';
                let avatarUrl = '';

                // 1. 检查是否有 author 字段（消息类事件）
                if (data.d && data.d.author) {
                    openid = data.d.author.id || data.d.author.member_openid || data.d.author.union_openid || null;
                    username = data.d.author.username || '未知用户';
                }
                // 2. 检查是否是机器人发送的消息
                else if (data.direction === '发送') {
                    username = '机器人';
                }
                // 3. 检查群成员增加事件 - 使用 member_openid
                else if (data.t === 'GROUP_MEMBER_ADD') {
                    openid = data.d?.member_openid || data.d?.user_id || data.d?.openid || null;
                    username = '群成员增加';
                    if (data.d?.operator_id) {
                        username += ' (操作者: ' + data.d.operator_id + ')';
                    }
                }
                // 4. 检查群成员删除事件 - 使用 member_openid
                else if (data.t === 'GROUP_MEMBER_REMOVE') {
                    openid = data.d?.member_openid || data.d?.user_id || data.d?.openid || null;
                    username = '群成员删除';
                    if (data.d?.operator_id) {
                        username += ' (操作者: ' + data.d.operator_id + ')';
                    }
                }
                // 5. 检查机器人被加入群事件
                else if (data.t === 'GROUP_ADD_ROBOT') {
                    openid = data.d?.op_member_openid || data.d?.operator_id || null;
                    username = '成员加入群聊';
                }
                // 6. 检查机器人被移出群事件
                else if (data.t === 'GROUP_DEL_ROBOT') {
                    openid = data.d?.op_member_openid || data.d?.operator_id || null;
                    username = '成员移除群聊';
                }
                // 7. 检查好友添加事件 - 使用 d.openid
                else if (data.t === 'FRIEND_ADD') {
                    openid = data.d?.openid || data.d?.friend_openid || data.d?.author?.union_openid || null;
                    username = '添加好友';
                }
                // 8. 检查好友删除事件 - 使用 d.openid
                else if (data.t === 'FRIEND_DEL') {
                    openid = data.d?.openid || data.d?.friend_openid || data.d?.author?.union_openid || null;
                    username = '删除好友';
                }
                // 9. 通用兜底：从 d 中提取可能的用户ID
                else if (data.d) {
                    const possibleOpenid = data.d.member_openid || 
                                          data.d.op_member_openid || 
                                          data.d.author?.id || 
                                          data.d.author?.union_openid ||
                                          data.d.group_openid || 
                                          data.d.user_openid ||
                                          data.d.user_id ||
                                          data.d.openid ||
                                          data.d.friend_openid;
                    if (possibleOpenid) openid = possibleOpenid;
                    if (data.t) username = data.t.replace(/_/g, ' ').toLowerCase();
                }

                // 生成头像链接
                if (openid && appid) {
                    avatarUrl = `https://q.qlogo.cn/qqapp/${appid}/${openid}/640`;
                }

                return { openid, username, avatarUrl };
            } catch (e) {
                console.warn('extractUserInfo 解析失败:', e);
                return { openid: null, username: '解析错误', avatarUrl: '' };
            }
        }

        function parseLogEnhanced(log) {
            try {
                const data = JSON.parse(log.raw);
                const time = log.time;
                let typeClass = 'event';
                let typeText = '事件';
                let summary = '';

                if (data.direction === '发送') {
                    typeClass = 'send';
                    const action = data.action || '发送';
                    typeText = action;
                    const typeMap = { 
                        '发送文字': '📝', 
                        '发送图片': '🖼️', 
                        '发送语音': '🎤', 
                        '发送视频': '🎬', 
                        '发送文件': '📎', 
                        '发送按钮': '🔘', 
                        '发送Markdown': '📝' 
                    };
                    const icon = typeMap[action] || '📨';
                    summary = `${icon} ${escapeHtml(String(data.content || '').substring(0, 100))}`;
                } 
                else if (data.t === 'GROUP_AT_MESSAGE_CREATE' || data.t === 'GROUP_MESSAGE_CREATE') {
                    typeClass = 'receive'; 
                    typeText = '群聊消息';
                    const content = data.d?.content || '';
                    summary = `👥 ${escapeHtml(content.substring(0, 100))}`;
                } 
                else if (data.t === 'C2C_MESSAGE_CREATE') {
                    typeClass = 'receive'; 
                    typeText = '私聊消息';
                    const content = data.d?.content || '';
                    summary = `💬 ${escapeHtml(content.substring(0, 100))}`;
                } 
                else if (data.t === 'GROUP_ADD_ROBOT') {
                    typeClass = 'event'; 
                    typeText = '群聊事件';
                    const operator = data.d?.op_member_openid || data.d?.operator_id || '?';
                    summary = `🤖 机器人被加入群聊 (操作者: ${operator})`;
                } 
                else if (data.t === 'GROUP_DEL_ROBOT') {
                    typeClass = 'event'; 
                    typeText = '群聊事件';
                    const operator = data.d?.op_member_openid || data.d?.operator_id || '?';
                    summary = `🚪 机器人退出群聊 (操作者: ${operator})`;
                } 
                else if (data.t === 'GROUP_MEMBER_ADD') {
                    typeClass = 'event'; 
                    typeText = '群成员事件';
                    const userId = data.d?.member_openid || data.d?.user_id || data.d?.openid || '?';
                    const operator = data.d?.operator_id || '?';
                    summary = `👤 群成员增加 (用户: ${userId}, 操作者: ${operator})`;
                } 
                else if (data.t === 'GROUP_MEMBER_REMOVE') {
                    typeClass = 'event'; 
                    typeText = '群成员事件';
                    const userId = data.d?.member_openid || data.d?.user_id || data.d?.openid || '?';
                    const operator = data.d?.operator_id || '?';
                    summary = `🚫 群成员删除 (用户: ${userId}, 操作者: ${operator})`;
                } 
                else if (data.t === 'FRIEND_ADD') {
                    typeClass = 'event'; 
                    typeText = '好友事件';
                    const userId = data.d?.openid || data.d?.friend_openid || data.d?.author?.union_openid || '?';
                    summary = `➕ 添加好友 (用户: ${userId})`;
                } 
                else if (data.t === 'FRIEND_DEL') {
                    typeClass = 'event'; 
                    typeText = '好友事件';
                    const userId = data.d?.openid || data.d?.friend_openid || data.d?.author?.union_openid || '?';
                    summary = `➖ 删除好友 (用户: ${userId})`;
                } 
                else {
                    typeText = data.t || '事件';
                    summary = log.summary || `事件: ${typeText}`;
                }

                return { typeClass, typeText, time, summary };
            } catch (e) {
                console.warn('parseLogEnhanced 解析失败:', e);
                return { typeClass: 'event', typeText: '解析错误', time: log.time, summary: '日志格式异常' };
            }
        }

        function buildMetadata(logs) {
            return logs.map(log => {
                const userInfo = extractUserInfo(log.raw, appid);
                const parsed = parseLogEnhanced(log);
                return { raw: log.raw, time: log.time, userInfo: userInfo, parsed: parsed };
            });
        }

        function renderLogsFromMetadata(metadataList) {
            const container = document.getElementById('logList');
            if (!metadataList.length) {
                container.innerHTML = '<div class="empty-state">暂无匹配的日志记录</div>';
                return;
            }
            container.innerHTML = metadataList.map((meta, idx) => {
                const userInfo = meta.userInfo;
                const parsed = meta.parsed;
                const avatarHtml = userInfo.avatarUrl
                    ? `<img src="${userInfo.avatarUrl}" alt="avatar" onerror="this.onerror=null;this.parentElement.innerHTML='<i class=\'fas fa-user-circle\'></i>';">`
                    : '<i class="fas fa-user-circle"></i>';
                return `
                    <div class="log-card ${parsed.typeClass}" data-idx="${idx}">
                        <div class="log-avatar">${avatarHtml}</div>
                        <div class="log-content">
                            <div class="log-header">
                                <div class="log-user">
                                    <span class="log-nickname">${escapeHtml(userInfo.username)}</span>
                                    <span class="log-time">${escapeHtml(parsed.time)}</span>
                                </div>
                                <span class="log-badge ${parsed.typeClass}">${escapeHtml(parsed.typeText)}</span>
                            </div>
                            <div class="log-summary">${parsed.summary}</div>
                        </div>
                    </div>
                `;
            }).join('');
            container.querySelectorAll('.log-card').forEach((card, i) => {
                card.addEventListener('click', () => showDetail(metadataList[i].raw, metadataList[i].parsed));
            });
        }

        function showDetail(rawLog, parsed) {
            try {
                const data = JSON.parse(rawLog);
                document.getElementById('detailTime').textContent = parsed.time;
                document.getElementById('detailType').textContent = data.direction || data.t || '事件';
                let target = data.target_id || data.d?.group_id || data.d?.author?.id || data.d?.group_openid || data.d?.openid || '-';
                document.getElementById('detailTarget').textContent = target;
                document.getElementById('detailContent').textContent = data.content || data.d?.content || '-';
                document.getElementById('detailRaw').textContent = JSON.stringify(data, null, 2);
            } catch (e) {
                document.getElementById('detailRaw').textContent = rawLog;
            }
            document.getElementById('detailModal').style.display = 'flex';
        }

        function applyFilters() {
            const searchTerm = document.getElementById('searchInput').value.trim().toLowerCase();
            const typeFilter = document.getElementById('typeFilter').value;
            const filtered = logsMetadata.filter(meta => {
                const userInfo = meta.userInfo;
                const parsed = meta.parsed;
                let matchSearch = true;
                if (searchTerm !== '') {
                    const nickname = userInfo.username.toLowerCase();
                    const summary = parsed.summary.toLowerCase();
                    const rawStr = JSON.stringify(meta.raw).toLowerCase();
                    matchSearch = nickname.includes(searchTerm) || summary.includes(searchTerm) || rawStr.includes(searchTerm);
                }
                let matchType = (typeFilter === 'all') || (parsed.typeClass === typeFilter);
                return matchSearch && matchType;
            });
            renderLogsFromMetadata(filtered);
            document.getElementById('recordCount').textContent = filtered.length;
            document.getElementById('logCount').textContent = `${filtered.length} / ${logsMetadata.length} 条记录`;
        }

        async function loadFileList() {
            const select = document.getElementById('logFileSelect');
            try {
                const res = await fetch(`api/log.php?type=list&appid=${encodeURIComponent(appid)}`);
                const data = await res.json();
                if (data.code === 200 && data.list?.length) {
                    const files = data.list.sort().reverse();
                    document.getElementById('fileCount').textContent = files.length;
                    select.innerHTML = files.map(f => `<option value="${escapeHtml(f)}">${escapeHtml(f)}</option>`).join('');
                    if (currentLogFile && files.includes(currentLogFile)) select.value = currentLogFile;
                    else { select.value = files[0]; currentLogFile = files[0]; }
                    document.getElementById('currentFile').textContent = currentLogFile;
                    await loadLogContent(currentLogFile);
                } else {
                    select.innerHTML = '<option>暂无日志文件</option>';
                    allLogsRaw = []; logsMetadata = [];
                    renderLogsFromMetadata([]);
                    document.getElementById('recordCount').textContent = '0';
                    document.getElementById('logCount').textContent = '0 条记录';
                }
            } catch (err) { 
                console.error('loadFileList 失败:', err);
                showMsg('加载文件列表失败', false); 
            }
        }

        async function loadLogContent(file) {
            document.getElementById('logList').innerHTML = '<div class="empty-state">加载中...</div>';
            try {
                const res = await fetch(`api/log.php?type=read&appid=${encodeURIComponent(appid)}&name=${encodeURIComponent(file)}`);
                const data = await res.json();
                if (data.code === 200) {
                    allLogsRaw = data.list || [];
                    logsMetadata = buildMetadata(allLogsRaw);
                    document.getElementById('searchInput').value = '';
                    document.getElementById('typeFilter').value = 'all';
                    applyFilters();
                } else {
                    allLogsRaw = []; logsMetadata = [];
                    renderLogsFromMetadata([]);
                    document.getElementById('recordCount').textContent = '0';
                    document.getElementById('logCount').textContent = '0 条记录';
                    showMsg('加载失败：' + (data.msg || ''), false);
                }
            } catch (err) { 
                console.error('loadLogContent 失败:', err);
                showMsg('加载失败', false); 
            }
        }

        function escapeHtml(str) { 
            if (!str) return ''; 
            return String(str).replace(/[&<>"]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                if (m === '"') return '&quot;';
                return m;
            });
        }

        document.getElementById('logFileSelect').addEventListener('change', (e) => {
            currentLogFile = e.target.value;
            document.getElementById('currentFile').textContent = currentLogFile;
            if (currentLogFile) loadLogContent(currentLogFile);
        });
        document.getElementById('refreshBtn').addEventListener('click', () => { loadFileList(); });
        document.getElementById('deleteFileBtn').addEventListener('click', () => {
            if (!currentLogFile) { showMsg('请先选择日志文件', false); return; }
            deleteTargetFile = currentLogFile;
            document.getElementById('confirmFileName').textContent = currentLogFile;
            document.getElementById('confirmModal').style.display = 'flex';
        });
        document.getElementById('confirmDeleteBtn').addEventListener('click', async () => {
            if (!deleteTargetFile) return;
            try {
                const res = await fetch(`api/log.php?type=delete&appid=${encodeURIComponent(appid)}&name=${encodeURIComponent(deleteTargetFile)}`);
                const data = await res.json();
                if (data.code === 200) { showMsg('删除成功', true); closeModal('confirmModal'); loadFileList(); }
                else showMsg(data.msg || '删除失败', false);
            } catch (err) { showMsg('删除失败', false); }
            deleteTargetFile = '';
        });

        document.getElementById('searchInput').addEventListener('input', applyFilters);
        document.getElementById('typeFilter').addEventListener('change', applyFilters);
        document.getElementById('resetFilterBtn').addEventListener('click', () => {
            document.getElementById('searchInput').value = '';
            document.getElementById('typeFilter').value = 'all';
            applyFilters();
        });

        document.getElementById('navAddBot').addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('addModal').style.display = 'flex';
        });

        document.getElementById('addForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const addAppid = document.getElementById('addAppid').value.trim();
            const addSecret = document.getElementById('addSecret').value.trim();
            const addEnv = document.getElementById('addEnvironment').value;
            if (!addAppid || !addSecret) { showMsg('请填写完整信息', false); return; }
            try {
                const res = await fetch(`api/bot.php?type=add&appid=${encodeURIComponent(addAppid)}&secret=${encodeURIComponent(addSecret)}&environment=${encodeURIComponent(addEnv)}`);
                const data = await res.json();
                if (data.code === 200) { showMsg('添加成功', true); closeModal('addModal'); document.getElementById('addForm').reset(); }
                else showMsg(data.msg || '添加失败', false);
            } catch (err) { showMsg('网络错误', false); }
        });

        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        if (menuToggle) {
            menuToggle.addEventListener('click', () => sidebar.classList.toggle('open'));
            document.addEventListener('click', (e) => {
                if (window.innerWidth <= 768 && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) sidebar.classList.remove('open');
            });
        }

        loadFileList();
    </script>
</body>
</html>