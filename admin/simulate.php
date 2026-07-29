<?php
/**
 * 管理后台 - 指令测试
 * 参照原版 simulate.php 逻辑：调用 api/simulate.php 加载插件并捕获回复
 */
$pageTitle = '指令测试';
require_once('header.php');

// 获取机器人列表
$bots = getBots();
?>

<style>
/* 指令测试布局 */
.simulate-layout {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 16px;
    min-height: calc(100vh - 180px);
}
.simulate-input-panel {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.simulate-result-panel {
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.simulate-result-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.simulate-result-header h3 {
    font-size: 15px;
    font-weight: 600;
}
.simulate-result-body {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    min-height: 300px;
}
.simulate-result-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    min-height: 300px;
    color: var(--text-muted);
}

/* 回复项 */
.reply-item {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 14px 16px;
    margin-bottom: 12px;
    border-left: 4px solid var(--primary);
    word-break: break-word;
}
.reply-item.reply-error {
    border-left-color: var(--danger);
    background: #fff5f5;
}
.reply-meta {
    font-size: 11px;
    color: var(--text-muted);
    margin-bottom: 8px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.reply-type-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
}
.reply-type-badge.text { background: #e3f2fd; color: #1565c0; }
.reply-type-badge.md { background: #f3e5f5; color: #7b1fa2; }
.reply-type-badge.image { background: #e8f5e9; color: #2e7d32; }
.reply-type-badge.audio { background: #fff3e0; color: #e65100; }
.reply-type-badge.video { background: #fce4ec; color: #c62828; }
.reply-type-badge.file { background: #efebe9; color: #5d4037; }

.reply-content {
    font-size: 14px;
    line-height: 1.6;
    word-break: break-word;
    white-space: pre-wrap;
}
.reply-content img {
    max-width: 100%;
    max-height: 400px;
    border-radius: var(--radius-sm);
    margin-top: 8px;
}
.reply-content video {
    max-width: 100%;
    max-height: 400px;
    border-radius: var(--radius-sm);
    margin-top: 8px;
}
.reply-content audio {
    width: 100%;
    margin-top: 8px;
}

/* 快捷指令 */
.quick-cmds {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.quick-cmd-btn {
    font-size: 12px;
    padding: 3px 8px;
    border: 1px solid var(--border);
    border-radius: 3px;
    background: var(--bg);
    color: var(--text-secondary);
    cursor: pointer;
    transition: var(--transition);
}
.quick-cmd-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
}

/* 响应式 */
@media (max-width: 900px) {
    .simulate-layout {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="page-header">
  <h2>指令测试</h2>
</div>

<div class="simulate-layout">
  <!-- 左侧输入面板 -->
  <div class="simulate-input-panel">
    <div class="card">
      <div class="card-body">
        <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 16px;">
          选择机器人，输入指令，系统将模拟真实用户触发机器人插件，并实时显示机器人回复（支持文字/图片/音频/视频/Markdown）。
        </p>
        <div class="form-group">
          <label>选择机器人</label>
          <select id="simBotId" class="form-control">
            <option value="">-- 请选择机器人 --</option>
            <?php foreach ($bots as $bot):
              $label = $bot['appid'];
              if (!empty($bot['nickname'])) $label .= ' (' . $bot['nickname'] . ')';
            ?>
            <option value="<?= htmlspecialchars($bot['appid']) ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>快捷指令</label>
          <div class="quick-cmds">
            <span class="quick-cmd-btn" onclick="insertCmd('/help')">/help</span>
            <span class="quick-cmd-btn" onclick="insertCmd('/status')">/status</span>
            <span class="quick-cmd-btn" onclick="insertCmd('/ping')">/ping</span>
            <span class="quick-cmd-btn" onclick="insertCmd('/version')">/version</span>
            <span class="quick-cmd-btn" onclick="insertCmd('统计')">统计</span>
            <span class="quick-cmd-btn" onclick="insertCmd('菜单')">菜单</span>
          </div>
        </div>
        <div class="form-group">
          <label>指令内容</label>
          <textarea id="simCommand" class="form-control" rows="4" placeholder="例如：统计" onkeydown="handleSimKeydown(event)"></textarea>
          <div class="form-hint">按 Ctrl+Enter 或点击发送按钮执行</div>
        </div>
        <button class="btn btn-primary btn-block" id="simSendBtn" onclick="executeCommand()">发送指令</button>
        <button class="btn btn-outline btn-block mt-2" onclick="clearReplies()">清空回复</button>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 10px;">使用说明</h3>
        <ul style="margin-left: 20px; color: var(--text-secondary); font-size: 13px; line-height: 1.8;">
          <li>模拟用户消息不会写入日志，仅用于测试插件响应。</li>
          <li>支持显示机器人发送的文字、图片、音频、视频、Markdown 内容。</li>
          <li>系统自动模拟私聊场景，以管理员身份触发指令。</li>
        </ul>
      </div>
    </div>
  </div>

  <!-- 右侧结果面板 -->
  <div class="card simulate-result-panel">
    <div class="simulate-result-header">
      <h3>机器人回复</h3>
      <span id="simStatus" style="font-size: 12px; color: var(--text-muted);"></span>
    </div>
    <div class="simulate-result-body" id="simResultBody">
      <div class="simulate-result-empty">
        <div>
          <div style="font-size:48px; opacity:0.2; margin-bottom:12px;">&#9889;</div>
          <p>在左侧输入指令并执行，机器人回复将显示在这里</p>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// ==================== 快捷指令插入 ====================
function insertCmd(cmd) {
    var el = document.getElementById('simCommand');
    el.value = cmd + ' ';
    el.focus();
}

// ==================== 键盘事件 ====================
function handleSimKeydown(e) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        executeCommand();
    }
}

// ==================== 执行指令 ====================
function executeCommand() {
    var command = document.getElementById('simCommand').value.trim();
    if (!command) { alert('请输入指令内容'); return; }

    var btn = document.getElementById('simSendBtn');
    btn.disabled = true;
    var origText = btn.textContent;
    btn.textContent = '执行中...';

    var startTime = Date.now();
    var resultContainer = document.getElementById('simResultBody');
    var statusEl = document.getElementById('simStatus');
    resultContainer.innerHTML = '<div class="simulate-result-empty"><div><div class="loading"></div><p style="margin-top:8px;">正在执行指令...</p></div></div>';
    statusEl.textContent = '';

    // 调用原版 simulate API（api/simulate.php）
    // 该 API 加载所有已启用插件，模拟私聊场景，捕获所有回复
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/simulate.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json; charset=utf-8');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            btn.disabled = false;
            btn.textContent = origText;
            var elapsed = Date.now() - startTime;

            var res;
            try {
                res = JSON.parse(xhr.responseText);
            } catch (e) {
                res = { code: 500, msg: '响应解析失败', raw: xhr.responseText };
            }

            renderReplies(res, elapsed, statusEl, resultContainer);
        }
    };

    // 发送 JSON 格式请求，与原版 api/simulate.php 兼容
    xhr.send(JSON.stringify({ content: command }));
}

// ==================== 渲染回复 ====================
function renderReplies(res, elapsed, statusEl, container) {
    var html = '';

    if (res.code === 200 || res.code === 0) {
        var replies = res.replies || [];
        if (replies.length === 0) {
            html += '<div class="reply-item">';
            html += '<div class="reply-meta"><span>无回复</span></div>';
            html += '<div class="reply-content" style="color:var(--text-muted);">机器人没有返回任何回复。请检查插件是否已启用，或指令是否正确。</div>';
            html += '</div>';
            statusEl.textContent = '完成 · ' + elapsed + 'ms · 无回复';
        } else {
            statusEl.textContent = '完成 · ' + elapsed + 'ms · ' + replies.length + ' 条回复';
            for (var i = 0; i < replies.length; i++) {
                var reply = replies[i];
                var type = reply.type || 'text';
                var content = reply.content || '';
                var target = reply.target || '';

                html += '<div class="reply-item">';
                html += '<div class="reply-meta">';
                html += '<span class="reply-type-badge ' + type + '">' + getTypeLabel(type) + '</span>';
                if (target) html += '<span>目标: ' + escapeHtml(target) + '</span>';
                html += '<span>#' + (i + 1) + '</span>';
                html += '</div>';

                html += '<div class="reply-content">';
                if (type === 'image') {
                    html += '<img src="' + escapeAttr(content) + '" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\'">';
                    html += '<div style="display:none; color:var(--text-muted);">图片加载失败: ' + escapeHtml(content.substring(0, 100)) + '</div>';
                } else if (type === 'video') {
                    html += '<video controls src="' + escapeAttr(content) + '"></video>';
                } else if (type === 'audio') {
                    html += '<audio controls src="' + escapeAttr(content) + '"></audio>';
                } else if (type === 'md' || type === 'markdown') {
                    html += escapeHtml(content);
                } else {
                    html += escapeHtml(content);
                }
                html += '</div>';
                html += '</div>';
            }
        }
    } else {
        html += '<div class="reply-item reply-error">';
        html += '<div class="reply-meta"><span class="reply-type-badge" style="background:#ffebee;color:#c62828;">错误</span></div>';
        html += '<div class="reply-content">' + escapeHtml(res.msg || res.message || '未知错误') + '</div>';
        html += '</div>';
        statusEl.textContent = '失败 · ' + elapsed + 'ms';
    }

    container.innerHTML = html;
}

// ==================== 清空回复 ====================
function clearReplies() {
    document.getElementById('simResultBody').innerHTML =
        '<div class="simulate-result-empty"><div><div style="font-size:48px; opacity:0.2; margin-bottom:12px;">&#9889;</div><p>在左侧输入指令并执行，机器人回复将显示在这里</p></div></div>';
    document.getElementById('simStatus').textContent = '';
}

// ==================== 工具函数 ====================
function getTypeLabel(type) {
    var labels = {
        'text': '文字',
        'md': 'Markdown',
        'markdown': 'Markdown',
        'image': '图片',
        'audio': '语音',
        'video': '视频',
        'file': '文件'
    };
    return labels[type] || type;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function escapeAttr(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/'/g, '&#39;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
</script>

<?php require_once('footer.php'); ?>
