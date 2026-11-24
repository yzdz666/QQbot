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

// ==================== 加载语音转 Silk 配置 ====================
// WorkBuddy 部分取「生效值」：库里留空时自动套用推荐配置（访问码 / 防盗链 / 采样率等）
$silkWb = silk_wb生效配置();
$silkCfg = [
    'silk_provider'        => db()->getSetting('silk_provider', 'oiapi'),
    'silk_oiapi_base'      => db()->getSetting('silk_oiapi_base', 'https://oiapi.net/api/Mp32Silk'),
    'silk_wb_base'         => $silkWb['base'],
    'silk_wb_sharecode'    => $silkWb['sharecode'],
    'silk_wb_sample_rate'  => $silkWb['sample_rate'],
    'silk_wb_frame_ms'     => $silkWb['frame_ms'],
    'silk_wb_complexity'   => $silkWb['complexity'],
    'silk_wb_tencent'      => $silkWb['tencent'],
    'silk_wb_timeout'      => (string)$silkWb['timeout'],
    'silk_custom_url'      => db()->getSetting('silk_custom_url', ''),
    'silk_custom_method'   => db()->getSetting('silk_custom_method', 'GET'),
    'silk_custom_headers'  => db()->getSetting('silk_custom_headers', ''),
    'silk_custom_body'     => db()->getSetting('silk_custom_body', ''),
    'silk_custom_parse'    => db()->getSetting('silk_custom_parse', 'json'),
    'silk_custom_field'    => db()->getSetting('silk_custom_field', 'data.url'),
    'silk_custom_timeout'  => db()->getSetting('silk_custom_timeout', '60'),
];
$silkProviders = silkProvider列表();
if (!isset($silkProviders[$silkCfg['silk_provider']])) $silkCfg['silk_provider'] = 'oiapi';

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
        <label for="adminCurrentPassword">当前密码</label>
        <input type="password" id="adminCurrentPassword" class="form-control" placeholder="请输入当前密码" required autocomplete="current-password">
      </div>
      <div class="form-group">
        <label for="adminPassword">新密码</label>
        <input type="password" id="adminPassword" class="form-control" placeholder="输入新密码（至少8位，含字母和数字）" required autocomplete="new-password">
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

<!-- ==================== Section 2.6: 语音转 Silk ==================== -->
<div class="card mb-3">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <h3>语音转 Silk</h3>
    <span class="badge badge-secondary" id="silkProviderBadge"><?= htmlspecialchars($silkProviders[$silkCfg['silk_provider']]) ?></span>
  </div>
  <div class="card-body">
    <p style="color:var(--text-secondary); margin:0 0 18px; font-size:13px; line-height:1.75;">
      发语音前需要把 mp3 / wav 等格式转成 QQ 要求的 SILK。接口返回「音频 URL」或「SILK 二进制」都能自动识别，
      转换失败时会回落到文字提示，不会把空数据交给官方接口。
    </p>

    <form id="silkForm" onsubmit="return saveSilkConfig(event)">
      <div class="form-group">
        <label for="silkProvider">转换接口</label>
        <select id="silkProvider" class="form-control" onchange="silkSwitch()">
          <?php foreach ($silkProviders as $pk => $pLabel): ?>
            <option value="<?= htmlspecialchars($pk) ?>" <?= $silkCfg['silk_provider'] === $pk ? 'selected' : '' ?>><?= htmlspecialchars($pLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- oiapi 参数 -->
      <div id="silkOiapiBox">
        <div class="form-group">
          <label for="silkOiapiBase">接口地址</label>
          <input type="text" id="silkOiapiBase" class="form-control" value="<?= htmlspecialchars($silkCfg['silk_oiapi_base']) ?>" placeholder="https://oiapi.net/api/Mp32Silk">
          <small style="display:block; margin-top:6px; color:var(--text-secondary); font-size:12px;">
            默认官方地址，可换成同结构的镜像；调用方式为 <code>?url=编码后的音频地址</code>。
          </small>
        </div>
      </div>

      <!-- WorkBuddy 参数 -->
      <div id="silkWbBox">
        <div style="display:flex; justify-content:flex-end; margin-bottom:8px;">
          <button type="button" class="btn btn-outline" style="font-size:12px; padding:4px 10px;" onclick="silkWb推荐()">填入推荐配置</button>
        </div>
        <div class="form-group">
          <label for="silkWbBase">接口地址</label>
          <input type="text" id="silkWbBase" class="form-control" value="<?= htmlspecialchars($silkCfg['silk_wb_base']) ?>" placeholder="https://your-host/api/v1/convert/url">
          <small style="display:block; margin-top:6px; color:var(--text-secondary); font-size:12px;">
            默认是示例实例，建议换成你自己的部署；接口需支持 <code>url</code> 查询参数。
          </small>
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
          <div class="form-group" style="flex:1 1 180px;">
            <label for="silkWbSharecode">访问码 sharecode</label>
            <input type="text" id="silkWbSharecode" class="form-control" value="<?= htmlspecialchars($silkCfg['silk_wb_sharecode']) ?>" placeholder="示例：YNB7MWHTCB">
            <small style="display:block; margin-top:6px; color:var(--text-secondary); font-size:12px;">
              留空时，示例部署会自动补默认访问码；自建部署留空则不发该参数。
            </small>
          </div>
          <div class="form-group" style="flex:1 1 120px;">
            <label for="silkWbSampleRate">采样率</label>
            <input type="text" id="silkWbSampleRate" class="form-control" value="<?= htmlspecialchars($silkCfg['silk_wb_sample_rate']) ?>" placeholder="24000">
          </div>
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
          <div class="form-group" style="flex:1 1 120px;">
            <label for="silkWbFrameMs">帧长 frame_ms</label>
            <input type="text" id="silkWbFrameMs" class="form-control" value="<?= htmlspecialchars($silkCfg['silk_wb_frame_ms']) ?>" placeholder="20">
          </div>
          <div class="form-group" style="flex:1 1 120px;">
            <label for="silkWbComplexity">复杂度</label>
            <input type="text" id="silkWbComplexity" class="form-control" value="<?= htmlspecialchars($silkCfg['silk_wb_complexity']) ?>" placeholder="1">
          </div>
          <div class="form-group" style="flex:1 1 120px;">
            <label for="silkWbTimeout">超时（秒）</label>
            <input type="text" id="silkWbTimeout" class="form-control" value="<?= htmlspecialchars($silkCfg['silk_wb_timeout']) ?>" placeholder="90">
          </div>
        </div>
        <div class="form-group">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
            <input type="checkbox" id="silkWbTencent" <?= $silkCfg['silk_wb_tencent'] === '1' ? 'checked' : '' ?>>
            <span>腾讯音乐模式（tencent=true，处理 QQ 音乐 vkey 防盗链）</span>
          </label>
        </div>
      </div>

      <!-- 自定义 API 参数 -->
      <div id="silkCustomBox">
        <div class="form-group">
          <label for="silkCustomUrl">请求地址模板</label>
          <input type="text" id="silkCustomUrl" class="form-control" value="<?= htmlspecialchars($silkCfg['silk_custom_url']) ?>" placeholder="https://api.example.com/convert?audio={url}">
          <small style="display:block; margin-top:6px; color:var(--text-secondary); font-size:12px;">
            用 <code>{url}</code> 表示编码后的音频地址，<code>{url_raw}</code> 表示原始地址。
          </small>
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
          <div class="form-group" style="flex:1 1 140px;">
            <label for="silkCustomMethod">请求方式</label>
            <select id="silkCustomMethod" class="form-control">
              <?php foreach (['GET', 'POST'] as $m): ?>
                <option value="<?= $m ?>" <?= strtoupper($silkCfg['silk_custom_method']) === $m ? 'selected' : '' ?>><?= $m ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="flex:1 1 140px;">
            <label for="silkCustomParse">响应类型</label>
            <select id="silkCustomParse" class="form-control">
              <option value="json" <?= $silkCfg['silk_custom_parse'] === 'json' ? 'selected' : '' ?>>JSON（取字段里的 URL）</option>
              <option value="binary" <?= $silkCfg['silk_custom_parse'] === 'binary' ? 'selected' : '' ?>>二进制（直接是 SILK）</option>
            </select>
          </div>
          <div class="form-group" style="flex:1 1 140px;">
            <label for="silkCustomTimeout">超时（秒）</label>
            <input type="text" id="silkCustomTimeout" class="form-control" value="<?= htmlspecialchars($silkCfg['silk_custom_timeout']) ?>" placeholder="60">
          </div>
        </div>
        <div class="form-group">
          <label for="silkCustomField">结果字段路径（JSON 时生效）</label>
          <input type="text" id="silkCustomField" class="form-control" value="<?= htmlspecialchars($silkCfg['silk_custom_field']) ?>" placeholder="data.url">
          <small style="display:block; margin-top:6px; color:var(--text-secondary); font-size:12px;">
            支持点号路径，例如 <code>data.url</code>、<code>result.0.audio</code>。
          </small>
        </div>
        <div class="form-group">
          <label for="silkCustomHeaders">自定义请求头</label>
          <textarea id="silkCustomHeaders" class="form-control" rows="3" placeholder="Authorization: Bearer xxx"><?= htmlspecialchars($silkCfg['silk_custom_headers']) ?></textarea>
          <small style="display:block; margin-top:6px; color:var(--text-secondary); font-size:12px;">每行一条，格式 <code>Key: Value</code>。</small>
        </div>
        <div class="form-group">
          <label for="silkCustomBody">POST Body 模板（可选）</label>
          <textarea id="silkCustomBody" class="form-control" rows="3" placeholder='{"audio":"{url}"} / audio={url}'><?= htmlspecialchars($silkCfg['silk_custom_body']) ?></textarea>
        </div>
      </div>

      <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:4px;">
        <button type="submit" class="btn btn-primary" id="silkSaveBtn">保存配置</button>
      </div>
    </form>

    <hr style="border:0; border-top:1px solid var(--line); margin:22px 0 18px;">

    <div class="form-group">
      <label for="silkTestUrl">测试当前接口</label>
      <input type="text" id="silkTestUrl" class="form-control" placeholder="https://example.com/test.mp3">
      <small style="display:block; margin-top:6px; color:var(--text-secondary); font-size:12px;">
        填一个可公开访问的音频直链，用「已保存」的配置跑一次转换。
      </small>
    </div>
    <button type="button" class="btn btn-outline" id="silkTestBtn" onclick="silkTest()">开始测试</button>
    <div id="silkTestResult" style="display:none; margin-top:14px; padding:12px 14px; border:1px solid var(--line); background:var(--surface-3); font-family:var(--mono); font-size:12px; line-height:1.8; white-space:pre-wrap; word-break:break-all;"></div>
  </div>
</div>

<!-- ==================== Section 2.5: 接口域名说明 ==================== -->
<!-- 官方 2026-08-10 起所有接口域名统一为 api.bot.qq.com，旧版域名已停用，切换功能已移除 -->
<div class="card mb-3">
  <div class="card-header">
    <h3>接口域名</h3>
    <span class="badge badge-success">官方统一域名 (api.bot.qq.com)</span>
  </div>
  <div class="card-body">
    <p style="color:var(--text-secondary); margin:0; font-size:13px;">
      所有 API 接口调用（消息发送、群信息、指令面板、菜单、WebSocket网关等）统一使用官方域名
      <code style="font-size:12px;">https://api.bot.qq.com</code>（正式与沙箱相同，含 access_token 接口）。
      官方已停用旧版 api.sgroup.qq.com 域名，框架已同步移除相关配置。
    </p>
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
      <p style="margin:4px 0 0; font-size:13px; color:var(--success-color, #14653f); word-break:break-word; overflow-wrap:break-word;"><strong>✓ 已配置好定时任务？</strong> 点击"启动守护进程"后，系统将在1分钟内自动启动 WS</p>
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
      <pre id="wsLogBox" style="background:var(--bg-secondary, #0b0b0c); color:#e0e0e0; padding:12px; border-radius:var(--radius-sm); max-height:300px; overflow-y:auto; font-size:12px; font-family:'SF Mono',Consolas,monospace; white-space:pre-wrap; word-break:break-all;">等待加载...</pre>
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
    if (newPassword.length < 8) {
        alert('密码长度至少8位');
        return false;
    }
    if (!/[A-Za-z]/.test(newPassword) || !/\d/.test(newPassword)) {
        alert('新密码需同时包含字母和数字');
        return false;
    }
    var currentPassword = document.getElementById('adminCurrentPassword').value;
    if (!currentPassword) {
        alert('请输入当前密码');
        return false;
    }
    if (!confirm('确定要修改管理员账户吗？\n修改后将清除所有会话，需要重新登录。')) {
        return false;
    }

    var btn = document.getElementById('adminSaveBtn');
    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = '保存中...';

    apiCall('change_password', { username: username, new_password: newPassword, current_password: currentPassword }, function(res) {
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

// ==================== 语音转 Silk ====================
function silkSwitch() {
    var sel = document.getElementById('silkProvider');
    if (!sel) return;
    var p = sel.value;
    document.getElementById('silkOiapiBox').style.display  = (p === 'oiapi') ? '' : 'none';
    document.getElementById('silkWbBox').style.display     = (p === 'workbuddy') ? '' : 'none';
    document.getElementById('silkCustomBox').style.display = (p === 'custom') ? '' : 'none';
    var badge = document.getElementById('silkProviderBadge');
    if (badge) badge.textContent = sel.options[sel.selectedIndex].text;
}

// 一键填回推荐值：示例地址 + 默认访问码 + 打开防盗链 + 标准参数
function silkWb推荐() {
    document.getElementById('silkWbBase').value = 'https://a9601a654d5c4fdb1.app.workbuddy.host/api/v1/convert/url';
    document.getElementById('silkWbSharecode').value = 'YNB7MWHTCB';
    document.getElementById('silkWbSampleRate').value = '24000';
    document.getElementById('silkWbFrameMs').value = '20';
    document.getElementById('silkWbComplexity').value = '1';
    document.getElementById('silkWbTimeout').value = '90';
    document.getElementById('silkWbTencent').checked = true;
    document.getElementById('silkProvider').value = 'workbuddy';
    silkSwitch();
}

function saveSilkConfig(event) {
    event.preventDefault();
    var btn = document.getElementById('silkSaveBtn');
    var payload = {
        silk_provider:       document.getElementById('silkProvider').value,
        silk_oiapi_base:     document.getElementById('silkOiapiBase').value.trim(),
        silk_wb_base:        document.getElementById('silkWbBase').value.trim(),
        silk_wb_sharecode:   document.getElementById('silkWbSharecode').value.trim(),
        silk_wb_sample_rate: document.getElementById('silkWbSampleRate').value.trim(),
        silk_wb_frame_ms:    document.getElementById('silkWbFrameMs').value.trim(),
        silk_wb_complexity:  document.getElementById('silkWbComplexity').value.trim(),
        silk_wb_timeout:     document.getElementById('silkWbTimeout').value.trim(),
        silk_wb_tencent:     document.getElementById('silkWbTencent').checked ? '1' : '0',
        silk_custom_url:     document.getElementById('silkCustomUrl').value.trim(),
        silk_custom_method:  document.getElementById('silkCustomMethod').value,
        silk_custom_parse:   document.getElementById('silkCustomParse').value,
        silk_custom_field:   document.getElementById('silkCustomField').value.trim(),
        silk_custom_headers: document.getElementById('silkCustomHeaders').value,
        silk_custom_body:    document.getElementById('silkCustomBody').value,
        silk_custom_timeout: document.getElementById('silkCustomTimeout').value.trim()
    };
    btn.disabled = true;
    var original = btn.textContent;
    btn.textContent = '保存中...';
    apiCall('save_silk_config', payload, function(res) {
        btn.disabled = false;
        btn.textContent = original;
        silkSwitch();
        if (res.success) {
            alert(res.message || '已保存');
        } else {
            alert(res.message || '保存失败');
        }
    });
    return false;
}

function silkTest() {
    var url = document.getElementById('silkTestUrl').value.trim();
    var box = document.getElementById('silkTestResult');
    var btn = document.getElementById('silkTestBtn');
    if (!url) { alert('先填一个音频直链'); return; }
    if (!/^https?:\/\//i.test(url)) { alert('地址需要以 http:// 或 https:// 开头'); return; }

    btn.disabled = true;
    var original = btn.textContent;
    btn.textContent = '测试中...';
    box.style.display = 'block';
    box.textContent = '请求中，最长等待约 90 秒…';

    apiCall('silk_test', { url: url }, function(res) {
        btn.disabled = false;
        btn.textContent = original;
        var d = res.data || {};
        var typeMap = { silk: 'SILK 二进制', url: '音频 URL', empty: '无数据' };
        var lines = [];
        lines.push('结果：' + (res.success ? '成功' : '失败'));
        lines.push('接口：' + (d.provider || '-'));
        lines.push('耗时：' + (d.elapsed_ms != null ? d.elapsed_ms + ' ms' : '-'));
        lines.push('类型：' + (typeMap[d.type] || '-'));
        if (d.bytes) lines.push('大小：' + d.bytes + ' 字节');
        if (d.url) lines.push('地址：' + d.url);
        if (d.http) {
            lines.push('HTTP：' + (d.http.code || '-'));
            if (d.http.error) lines.push('错误：' + d.http.error);
        }
        lines.push('说明：' + (res.message || ''));
        box.textContent = lines.join('\n');
    });
}

silkSwitch();

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
