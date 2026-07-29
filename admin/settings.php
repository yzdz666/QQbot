<?php
/**
 * 管理后台 - 系统设置
 */
$pageTitle = '系统设置';
require_once('header.php');

// ==================== 加载 AI 配置 ====================
$aiConfig = db()->fetch("SELECT * FROM ai_config WHERE id = 1");
if (!$aiConfig) {
    $aiConfig = ['base_url' => '', 'api_key' => '', 'model' => 'gpt-4o-mini'];
}

// ==================== 加载登录日志 ====================
$loginLogs = Auth::getLoginLogs();

// ==================== 计算被封禁的 IP（24小时内失败次数 >= 5）====================
$failWindow = 86400;
$cutoff = date('Y-m-d H:i:s', time() - $failWindow);
try {
    $bannedIps = db()->fetchAll(
        "SELECT ip, COUNT(*) AS fail_count, MAX(created_at) AS last_attempt
         FROM ip_records
         WHERE success = 0 AND created_at > ?
         GROUP BY ip
         HAVING fail_count >= 5
         ORDER BY last_attempt DESC",
        [$cutoff]
    );
} catch (Exception $e) {
    $bannedIps = [];
}

// ==================== 获取机器人列表（用于 WebSocket 模式展示）====================
$bots = getBots();

// ==================== 系统信息 ====================
$sysInfo = getSystemInfo();
?>

<style>
/* 系统设置页面移动端适配 */
.card-body { overflow-wrap: break-word; word-wrap: break-word; }

/* ==================== 核心修复：alert 复杂内容布局 ==================== */
/* style.css 中 .alert 使用 display:flex，适用于简单图标+文字提示；
   但本页面的 alert 包含 h4/p/div 等复杂嵌套内容，
   flex 横向布局会导致内容溢出屏幕。此处覆盖为 block 布局。 */
.card-body .alert,
#wsCronGuide,
#wsPendingNotice,
.card-body > .alert-info {
    display: block !important;
    align-items: stretch;
    max-width: 100%;
    overflow-x: hidden;
    overflow-wrap: break-word;
    word-wrap: break-word;
}

/* 所有 code 元素强制换行，防止长路径溢出 */
.card-body code,
.alert code,
.alert-info code,
.alert-warning code {
    word-break: break-all;
    overflow-wrap: anywhere;
    display: inline-block;
    max-width: 100%;
}

/* cron 命令长文本换行 */
#wsCliCronHint,
#wsCronCommand,
.wsCronCommand {
    word-break: break-all;
    overflow-wrap: anywhere;
    display: block;
    max-width: 100%;
}

/* cron 命令容器约束 */
#wsCronGuide > div {
    max-width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

pre#wsLogBox {
    word-break: break-all;
    overflow-wrap: anywhere;
    white-space: pre-wrap;
}
.table-responsive {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* WS 控制按钮区移动端适配 */
#wsStartBtn, #wsStopBtn {
    white-space: nowrap;
}

@media (max-width: 768px) {
    .card-body { padding: 16px; }
    .card-header { padding: 12px 16px; }
    .alert { font-size: 13px; padding: 10px 12px; }
    .alert code { font-size: 11px; }
    h4 { font-size: 13px !important; }
    .d-flex.gap-2.flex-wrap { gap: 8px; }
    .d-flex.gap-2.flex-wrap .btn { width: 100%; }
    /* WS 控制按钮区在移动端纵向排列 */
    .card-body > div[style*="display:flex; gap:12px; flex-wrap:wrap"] {
        flex-direction: column;
        align-items: stretch;
    }
    .card-body > div[style*="display:flex; gap:12px; flex-wrap:wrap"] > * {
        width: 100%;
        margin-bottom: 8px;
    }
    .card-body > div[style*="display:flex; gap:12px; flex-wrap:wrap"] > label {
        width: auto;
        margin-left: 0;
    }
}
</style>

<div class="page-header">
  <h2>系统设置</h2>
  <div class="actions">
    <a href="api.php?action=export_config" class="btn btn-outline">导出配置</a>
  </div>
</div>

<!-- ==================== Section 1: 管理员账户 ==================== -->
<div class="card mb-3">
  <div class="card-header">
    <h3>管理员账户</h3>
  </div>
  <div class="card-body">
    <form id="adminForm" onsubmit="return saveAdmin(event)">
      <div class="form-group">
        <label for="adminUsername">用户名</label>
        <input type="text" id="adminUsername" class="form-control" value="<?= htmlspecialchars($admin['username'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label for="adminPassword">新密码</label>
        <input type="password" id="adminPassword" class="form-control" placeholder="输入新密码（至少6位）" required>
        <div class="form-hint">修改密码或用户名后将清除所有会话，需要重新登录</div>
      </div>
      <button type="submit" class="btn btn-primary" id="adminSaveBtn">保存</button>
    </form>
  </div>
</div>

<!-- ==================== Section 2: AI配置 ==================== -->
<div class="card mb-3">
  <div class="card-header">
    <h3>AI配置</h3>
  </div>
  <div class="card-body">
    <form id="aiForm" onsubmit="return saveAiConfig(event)">
      <div class="form-group">
        <label for="aiBaseUrl">Base URL</label>
        <input type="text" id="aiBaseUrl" class="form-control" value="<?= htmlspecialchars($aiConfig['base_url'] ?? '') ?>" placeholder="例如 https://api.openai.com/v1">
      </div>
      <div class="form-group">
        <label for="aiApiKey">API Key</label>
        <input type="password" id="aiApiKey" class="form-control" value="<?= htmlspecialchars($aiConfig['api_key'] ?? '') ?>" placeholder="AI API Key">
      </div>
      <div class="form-group">
        <label for="aiModel">模型</label>
        <input type="text" id="aiModel" class="form-control" value="<?= htmlspecialchars($aiConfig['model'] ?? 'gpt-4o-mini') ?>" placeholder="gpt-4o-mini">
      </div>
      <button type="submit" class="btn btn-primary" id="aiSaveBtn">保存配置</button>
    </form>
  </div>
</div>

<!-- ==================== Section 3: 安全设置 ==================== -->
<div class="card mb-3">
  <div class="card-header">
    <h3>安全设置</h3>
  </div>
  <div class="card-body">
    <!-- IP 访问记录 -->
    <h4 style="font-size:14px; font-weight:600; margin-bottom:12px;">IP访问记录</h4>
    <div style="border:1px solid var(--border); border-radius:var(--radius-sm); overflow:hidden; margin-bottom:24px;">
      <?php if (empty($loginLogs)): ?>
        <div class="empty-state" style="padding:32px 16px;">
          <p>暂无访问记录</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>IP地址</th>
              <th>结果</th>
              <th>时间</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($loginLogs as $log):
              $success = intval($log['success']) === 1;
            ?>
            <tr>
              <td style="font-family:'SF Mono', Consolas, monospace;"><?= htmlspecialchars($log['ip']) ?></td>
              <td>
                <span class="badge <?= $success ? 'badge-success' : 'badge-danger' ?>">
                  <?= $success ? '成功' : '失败' ?>
                </span>
              </td>
              <td class="text-muted" style="white-space:nowrap;"><?= htmlspecialchars($log['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- 封禁的 IP -->
    <h4 style="font-size:14px; font-weight:600; margin-bottom:12px;">封禁的IP（24小时内失败5次以上）</h4>
    <div style="border:1px solid var(--border); border-radius:var(--radius-sm); overflow:hidden; margin-bottom:24px;">
      <?php if (empty($bannedIps)): ?>
        <div class="empty-state" style="padding:32px 16px;">
          <p>当前没有被封禁的IP</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>IP地址</th>
              <th>失败次数</th>
              <th>最后尝试</th>
              <th class="text-right">操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bannedIps as $ban):
              $ipJs = json_encode($ban['ip'], JSON_UNESCAPED_UNICODE);
            ?>
            <tr id="ban-row-<?= htmlspecialchars($ban['ip']) ?>">
              <td style="font-family:'SF Mono', Consolas, monospace;"><?= htmlspecialchars($ban['ip']) ?></td>
              <td>
                <span class="badge badge-danger"><?= intval($ban['fail_count']) ?> 次</span>
              </td>
              <td class="text-muted" style="white-space:nowrap;"><?= htmlspecialchars($ban['last_attempt']) ?></td>
              <td class="text-right">
                <button class="btn btn-outline btn-sm" onclick="unbanIp(<?= $ipJs ?>, this)">解封</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- 数据清理 -->
    <h4 style="font-size:14px; font-weight:600; margin-bottom:12px;">数据清理</h4>
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-outline" onclick="clearMessages()">清空消息</button>
      <button class="btn btn-outline" onclick="clearLogs()">清空日志</button>
      <button class="btn btn-outline" onclick="clearEvents()">清空事件去重</button>
    </div>
  </div>
</div>

<!-- ==================== Section 4: WebSocket模式 ==================== -->
<div class="card mb-3">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
    <h3>WebSocket模式</h3>
    <div style="display:flex; gap:8px; align-items:center;">
      <span id="wsMasterStatus" class="badge badge-secondary">状态加载中...</span>
    </div>
  </div>
  <div class="card-body">
    <p style="color:var(--text-secondary); margin-bottom:16px;">
      WebSocket 模式让机器人主动连接 QQ 网关接收实时事件，无需公网回调地址。点击下方按钮即可在后台启动/停止守护进程。
    </p>

    <!-- 控制按钮区 -->
    <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px; align-items:center;">
      <button id="wsStartBtn" class="btn btn-primary" onclick="wsStart()">启动守护进程</button>
      <button id="wsStopBtn" class="btn btn-danger" onclick="wsStop()" style="display:none;">停止守护进程</button>
      <button class="btn btn-outline" onclick="wsRefreshStatus()">刷新状态</button>
      <label style="display:flex; align-items:center; gap:6px; cursor:pointer; margin-left:8px;">
        <input type="checkbox" id="wsAutoStartToggle" onchange="wsToggleAutoStart(this.checked)">
        <span style="font-size:14px;">崩溃自动重启</span>
      </label>
    </div>

    <!-- 定时任务设置引导（仅当需要时显示） -->
    <div id="wsCronGuide" class="alert alert-warning" style="display:none; margin-bottom:16px;">
      <h4 style="margin:0 0 8px; font-size:14px;">⚠ 需要配置定时任务才能在网页后台启动/停止 WS 守护进程</h4>
      <p style="margin:0 0 8px; font-size:13px;">您的服务器禁用了所有进程管理函数（shell_exec/exec/popen/proc_open），网页无法直接启动进程。请配置以下定时任务：</p>
      <div style="background:rgba(0,0,0,0.05); padding:10px; border-radius:6px; margin:8px 0; max-width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch;">
        <code id="wsCronCommand" style="font-size:12px; word-break:break-all; display:block; max-width:100%;">* * * * * /bin/bash ws_monitor.sh</code>
      </div>
      <p style="margin:8px 0 0; font-size:13px; word-break:break-word; overflow-wrap:break-word;"><strong>宝塔面板设置方法：</strong>计划任务 → 添加任务 → 任务类型选 Shell脚本 → 执行周期选每1分钟 → 脚本内容填上面的命令</p>
      <p style="margin:4px 0 0; font-size:13px; word-break:break-word; overflow-wrap:break-word;"><strong>SSH设置方法：</strong>执行 <code>crontab -e</code>，添加上面的命令，保存退出</p>
      <p style="margin:4px 0 0; font-size:13px; color:var(--success-color, #28a745); word-break:break-word; overflow-wrap:break-word;"><strong>✓ 已配置好定时任务？</strong> 点击"启动守护进程"后，系统将在1分钟内自动启动 WS</p>
    </div>

    <!-- 待处理状态提示 -->
    <div id="wsPendingNotice" class="alert alert-info" style="display:none; margin-bottom:16px;">
      <span id="wsPendingText">处理中...</span>
    </div>

    <!-- 机器人连接状态表 -->
    <h4 style="font-size:14px; font-weight:600; margin:20px 0 12px;">
      已启用 WebSocket 的机器人（共 <?= count(array_filter($bots, function($b) { return intval($b['ws_enabled']) === 1; })) ?> 个）
    </h4>
    <div style="border:1px solid var(--border); border-radius:var(--radius-sm); overflow:hidden;">
      <?php
      $wsBots = array_filter($bots, function($b) { return intval($b['ws_enabled']) === 1; });
      ?>
      <?php if (empty($wsBots)): ?>
        <div class="empty-state" style="padding:32px 16px;">
          <p>暂无机器人启用 WebSocket 模式</p>
        </div>
      <?php else: ?>
        <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>AppID</th>
              <th>环境</th>
              <th>机器人状态</th>
              <th>WS连接状态</th>
              <th>自定义WS地址</th>
              <th>最后更新</th>
            </tr>
          </thead>
          <tbody id="wsBotTableBody">
            <?php foreach ($wsBots as $bot):
              $botEnabled = intval($bot['enabled']) === 1;
              $wsUrl = trim($bot['ws_url'] ?? '');
            ?>
            <tr data-appid="<?= htmlspecialchars($bot['appid']) ?>">
              <td style="font-weight:500;"><?= htmlspecialchars($bot['appid']) ?></td>
              <td>
                <span class="badge <?= ($bot['env'] ?? '正式') === '沙箱' ? 'badge-warning' : 'badge-info' ?>">
                  <?= htmlspecialchars($bot['env'] ?? '正式') ?>
                </span>
              </td>
              <td>
                <span class="badge <?= $botEnabled ? 'badge-success' : 'badge-secondary' ?>">
                  <?= $botEnabled ? '已启用' : '已禁用' ?>
                </span>
              </td>
              <td class="ws-conn-status">
                <span class="badge badge-secondary">未知</span>
              </td>
              <td class="text-muted">
                <?= $wsUrl !== '' ? htmlspecialchars($wsUrl) : '<span class="text-muted">自动获取</span>' ?>
              </td>
              <td class="ws-last-update text-muted" style="white-space:nowrap;">-</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- 运行日志 -->
    <div style="margin-top:20px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <h4 style="font-size:14px; font-weight:600; margin:0;">运行日志（最近50行）</h4>
        <button class="btn btn-outline btn-sm" onclick="wsClearLogs()">清空日志</button>
      </div>
      <pre id="wsLogBox" style="background:var(--bg-secondary, #1a1a2e); color:#e0e0e0; padding:12px; border-radius:var(--radius-sm); max-height:300px; overflow-y:auto; font-size:12px; font-family:'SF Mono',Consolas,monospace; white-space:pre-wrap; word-break:break-all;">等待加载...</pre>
    </div>

    <div class="alert alert-info" style="margin-top:16px; display:block; max-width:100%; overflow-x:hidden; word-break:break-word; overflow-wrap:break-word;">
      运行环境：PHP <?= htmlspecialchars($sysInfo['php_version']) ?> / <?= htmlspecialchars($sysInfo['os']) ?>。
      也可通过命令行手动运行：<code>php ws_client.php</code>
      <br>配置定时任务监控（推荐）：<code id="wsCliCronHint" style="word-break:break-all; display:inline-block; max-width:100%;">* * * * * /bin/bash ws_monitor.sh</code>
    </div>
  </div>
</div>

<script>
// ==================== 通用 AJAX 调用 ====================
function apiCall(action, data, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php?action=' + encodeURIComponent(action), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            var res;
            try {
                res = JSON.parse(xhr.responseText);
            } catch (e) {
                res = { success: false, message: '响应解析失败' };
            }
            callback(res);
        }
    };
    var params = [];
    for (var key in data) {
        if (data.hasOwnProperty(key)) {
            params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
        }
    }
    xhr.send(params.join('&'));
}

// ==================== 修改管理员账户/密码 ====================
function saveAdmin(event) {
    event.preventDefault();
    var username = document.getElementById('adminUsername').value.trim();
    var newPassword = document.getElementById('adminPassword').value;

    if (!username) {
        alert('用户名不能为空');
        return false;
    }
    if (newPassword.length < 6) {
        alert('密码长度至少6位');
        return false;
    }
    if (!confirm('确定要修改管理员账户吗？\n修改后将清除所有会话，需要重新登录。')) {
        return false;
    }

    var btn = document.getElementById('adminSaveBtn');
    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = '保存中...';

    apiCall('change_password', { username: username, new_password: newPassword }, function(res) {
        btn.disabled = false;
        btn.textContent = originalText;
        if (res.success) {
            alert(res.message || '修改成功');
            window.location.href = 'login.php';
        } else {
            alert(res.message || '修改失败');
        }
    });
    return false;
}

// ==================== 保存 AI 配置 ====================
function saveAiConfig(event) {
    event.preventDefault();
    var baseUrl = document.getElementById('aiBaseUrl').value.trim();
    var apiKey = document.getElementById('aiApiKey').value.trim();
    var model = document.getElementById('aiModel').value.trim() || 'gpt-4o-mini';

    var btn = document.getElementById('aiSaveBtn');
    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = '保存中...';

    apiCall('save_ai_config', { base_url: baseUrl, api_key: apiKey, model: model }, function(res) {
        btn.disabled = false;
        btn.textContent = originalText;
        if (res.success) {
            alert(res.message || 'AI配置已保存');
        } else {
            alert(res.message || '保存失败');
        }
    });
    return false;
}

// ==================== 解封 IP ====================
function unbanIp(ip, btn) {
    if (!confirm('确定要解封 IP「' + ip + '」吗？')) {
        return;
    }
    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = '解封中...';

    apiCall('unban_ip', { ip: ip }, function(res) {
        btn.disabled = false;
        btn.textContent = originalText;
        if (res.success) {
            alert(res.message || 'IP已解封');
            location.reload();
        } else {
            alert(res.message || '解封失败');
        }
    });
}

// ==================== 数据清理 ====================
function clearMessages() {
    if (!confirm('确定要清空所有消息记录吗？\n该操作不可恢复！')) {
        return;
    }
    apiCall('clear_messages', {}, function(res) {
        if (res.success) {
            alert(res.message || '消息已清空');
        } else {
            alert(res.message || '清空失败');
        }
    });
}

function clearLogs() {
    if (!confirm('确定要清空所有系统日志吗？\n该操作不可恢复！')) {
        return;
    }
    apiCall('clear_logs', {}, function(res) {
        if (res.success) {
            alert(res.message || '日志已清空');
            location.reload();
        } else {
            alert(res.message || '清空失败');
        }
    });
}

function clearEvents() {
    if (!confirm('确定要清空事件去重记录吗？\n该操作不可恢复！')) {
        return;
    }
    apiCall('clear_events', {}, function(res) {
        if (res.success) {
            alert(res.message || '事件记录已清空');
        } else {
            alert(res.message || '清空失败');
        }
    });
}

// ==================== WebSocket 守护进程控制 ====================
function wsStart() {
    var btn = document.getElementById('wsStartBtn');
    btn.disabled = true;
    btn.textContent = '启动中...';
    apiCall('ws_start', {}, function(res) {
        btn.disabled = false;
        btn.textContent = '启动守护进程';
        if (res.success) {
            if (res.mode === 'cron') {
                // 通过定时任务启动
                var notice = document.getElementById('wsPendingNotice');
                var text = document.getElementById('wsPendingText');
                if (notice && text) {
                    text.textContent = '✓ 已提交启动请求！定时任务将在1分钟内自动启动守护进程。';
                    notice.style.display = '';
                    notice.className = 'alert alert-success';
                }
            }
            setTimeout(wsRefreshStatus, 2000);
        } else {
            alert(res.message || '启动失败');
        }
    });
}

function wsStop() {
    if (!confirm('确定要停止 WebSocket 守护进程吗？')) return;
    var btn = document.getElementById('wsStopBtn');
    btn.disabled = true;
    btn.textContent = '停止中...';
    apiCall('ws_stop', {}, function(res) {
        btn.disabled = false;
        btn.textContent = '停止守护进程';
        if (res.success) {
            setTimeout(wsRefreshStatus, 1000);
        } else {
            alert(res.message || '停止失败');
        }
    });
}

function wsRefreshStatus() {
    apiCall('ws_status', {}, function(res) {
        if (!res.success) return;
        var data = res.data;
        var masterBadge = document.getElementById('wsMasterStatus');
        var startBtn = document.getElementById('wsStartBtn');
        var stopBtn = document.getElementById('wsStopBtn');
        var cronGuide = document.getElementById('wsCronGuide');
        var cronCmd = document.getElementById('wsCronCommand');
        var pendingNotice = document.getElementById('wsPendingNotice');
        var pendingText = document.getElementById('wsPendingText');

        // 显示/隐藏定时任务引导
        if (data.needs_cron && cronGuide) {
            cronGuide.style.display = '';
            if (cronCmd && data.cron_command) {
                cronCmd.textContent = data.cron_command;
            }
        } else if (cronGuide) {
            cronGuide.style.display = 'none';
        }

        // 显示待处理状态
        if (data.pending_action && pendingNotice && pendingText) {
            pendingNotice.style.display = '';
            if (data.pending_action === 'starting') {
                pendingText.textContent = '⏳ 启动请求处理中... 定时任务将在1分钟内执行';
                pendingNotice.className = 'alert alert-info';
            } else if (data.pending_action === 'stopping') {
                pendingText.textContent = '⏳ 停止请求处理中...';
                pendingNotice.className = 'alert alert-info';
            }
        } else if (pendingNotice) {
            pendingNotice.style.display = 'none';
        }

        if (data.master_running) {
            masterBadge.className = 'badge badge-success';
            masterBadge.textContent = '运行中 (PID:' + data.master_pid + ')';
            startBtn.style.display = 'none';
            stopBtn.style.display = '';
            // 进程运行中时隐藏待处理提示
            if (pendingNotice) pendingNotice.style.display = 'none';
        } else {
            masterBadge.className = 'badge badge-secondary';
            masterBadge.textContent = '已停止';
            startBtn.style.display = '';
            stopBtn.style.display = 'none';
        }

        document.getElementById('wsAutoStartToggle').checked = data.auto_start;

        if (data.bots) {
            data.bots.forEach(function(bot) {
                var row = document.querySelector('tr[data-appid="' + (window.CSS ? CSS.escape(bot.appid) : bot.appid.replace(/"/g,'\\"')) + '"]');
                if (!row) return;
                var connCell = row.querySelector('.ws-conn-status');
                var updateCell = row.querySelector('.ws-last-update');
                var statusClass = 'badge-secondary';
                var statusText = '未知';
                switch (bot.status) {
                    case 'connected': statusClass = 'badge-success'; statusText = '已连接'; break;
                    case 'connecting': statusClass = 'badge-warning'; statusText = '连接中'; break;
                    case 'reconnecting': statusClass = 'badge-warning'; statusText = '重连中'; break;
                    case 'disconnected': statusClass = 'badge-danger'; statusText = '已断开'; break;
                    case 'error': statusClass = 'badge-danger'; statusText = '错误'; break;
                    case 'stopped': statusClass = 'badge-secondary'; statusText = '已停止'; break;
                    case 'unknown': statusClass = 'badge-secondary'; statusText = '未知'; break;
                }
                if (connCell) connCell.innerHTML = '<span class="badge ' + statusClass + '" title="' + (bot.message || '') + '">' + statusText + '</span>';
                if (updateCell) updateCell.textContent = bot.updated_at || '-';
            });
        }

        var logBox = document.getElementById('wsLogBox');
        if (logBox) {
            logBox.textContent = data.logs || '(无日志)';
            logBox.scrollTop = logBox.scrollHeight;
        }
    });
}

function wsToggleAutoStart(enabled) {
    apiCall('ws_autostart', { enabled: enabled ? 1 : 0 }, function(res) {
        if (!res.success) {
            alert(res.message || '设置失败');
            document.getElementById('wsAutoStartToggle').checked = !enabled;
        }
    });
}

function wsClearLogs() {
    if (!confirm('确定要清空 WebSocket 运行日志吗？')) return;
    apiCall('ws_clear_logs', {}, function(res) {
        if (res.success) wsRefreshStatus();
        else alert(res.message || '清空失败');
    });
}

// 页面加载后初始化
(function() {
    wsRefreshStatus();
    setInterval(wsRefreshStatus, 10000);
    // 检查是否需要自动启动（仅当进程不在运行且开启了自动启动时）
    apiCall('ws_status', {}, function(res) {
        if (res.success && res.data && res.data.auto_start && !res.data.master_running && !res.data.pending_action) {
            // 如果可以直接启动进程，立即启动；否则写标志文件
            apiCall('ws_start', {}, function() { setTimeout(wsRefreshStatus, 2000); });
        }
    });
})();
</script>

<?php require_once('footer.php'); ?>
