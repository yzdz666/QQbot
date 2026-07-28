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
  <div class="card-header">
    <h3>WebSocket模式</h3>
  </div>
  <div class="card-body">
    <p style="color:var(--text-secondary); margin-bottom:16px;">
      WebSocket 模式允许机器人通过 WebSocket 主动连接 QQ 网关接收实时事件，无需公网回调地址。请在命令行中运行以下命令启动客户端：
    </p>

    <div class="form-group">
      <label>启动所有已启用 WebSocket 的机器人</label>
      <div class="code-block">php ws_client.php</div>
      <div class="form-hint">将连接所有 ws_enabled=1 且 enabled=1 的机器人，每个机器人会在独立子进程中运行</div>
    </div>

    <div class="form-group">
      <label>启动指定机器人</label>
      <div class="code-block">php ws_client.php [appid]</div>
      <div class="form-hint">将 appid 替换为具体的机器人 AppID，仅连接该机器人</div>
    </div>

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
              <th>状态</th>
              <th>自定义WS地址</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($wsBots as $bot):
              $botEnabled = intval($bot['enabled']) === 1;
              $wsUrl = trim($bot['ws_url'] ?? '');
            ?>
            <tr>
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
              <td class="text-muted">
                <?= $wsUrl !== '' ? htmlspecialchars($wsUrl) : '<span class="text-muted">自动获取</span>' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="alert alert-info" style="margin-top:16px;">
      运行环境：PHP <?= htmlspecialchars($sysInfo['php_version']) ?> / <?= htmlspecialchars($sysInfo['os']) ?>。
      请确保已安装 sockets、sodium 扩展，并以 CLI 模式运行。
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
</script>

<?php require_once('footer.php'); ?>
