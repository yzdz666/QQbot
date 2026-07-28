<?php
/**
 * 管理后台 - AI写插件
 */
$pageTitle = 'AI写插件';
require_once('header.php');

// 从数据库获取AI配置
$aiConfig = [];
try {
    $aiConfig = db()->fetch("SELECT * FROM ai_config WHERE id = 1");
    if (!$aiConfig) {
        // 插入默认配置
        db()->execute("INSERT OR IGNORE INTO ai_config (id, base_url, api_key, model) VALUES (1, '', '', 'gpt-4o-mini')");
        $aiConfig = db()->fetch("SELECT * FROM ai_config WHERE id = 1");
    }
} catch (Exception $e) {
    $aiConfig = ['base_url' => '', 'api_key' => '', 'model' => 'gpt-4o-mini'];
}
?>

<style>
/* AI写插件布局 */
.aidev-layout {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 16px;
    height: calc(100vh - 130px);
    min-height: 400px;
}

/* AI配置面板 */
.ai-config-panel {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.ai-config-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
}
.ai-config-card .card-header {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ai-config-card .card-body {
    padding: 16px;
}
.ai-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}
.ai-status-dot.connected { background: var(--success); }
.ai-status-dot.disconnected { background: var(--danger); }
.ai-status-dot.unknown { background: var(--text-muted); }

/* 需求输入区 */
.requirement-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
}
.requirement-card .card-header {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
    font-weight: 600;
}
.requirement-card .card-body {
    padding: 16px;
}

/* 代码编辑/预览区 */
.code-panel {
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.code-panel-header {
    padding: 12px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.code-panel-header h3 {
    font-size: 15px;
    font-weight: 600;
}
.code-panel-actions {
    display: flex;
    gap: 8px;
}
.code-panel-body {
    flex: 1;
    overflow: auto;
    position: relative;
}
.code-editor {
    width: 100%;
    height: 100%;
    min-height: 400px;
    padding: 16px;
    border: none;
    outline: none;
    font-family: "SF Mono", Consolas, "Liberation Mono", Menlo, monospace;
    font-size: 13px;
    line-height: 1.6;
    resize: none;
    background: #fafafa;
    color: var(--text);
    tab-size: 4;
}
.code-preview {
    background: #fafafa;
    padding: 16px;
    min-height: 400px;
    font-family: "SF Mono", Consolas, "Liberation Mono", Menlo, monospace;
    font-size: 13px;
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-all;
    overflow: auto;
}

/* 插件名称输入 */
.plugin-name-row {
    display: flex;
    gap: 8px;
}
.plugin-name-row .btn {
    flex-shrink: 0;
    align-self: flex-end;
}

/* 生成状态 */
.gen-status {
    padding: 12px 16px;
    background: var(--bg);
    border-top: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
}
.gen-status.generating {
    color: var(--text-secondary);
}
.gen-status.success {
    color: var(--success);
}
.gen-status.error {
    color: var(--danger);
}

/* 模板提示 */
.template-hints {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.template-hint-btn {
    font-size: 12px;
    padding: 3px 8px;
    border: 1px solid var(--border);
    border-radius: 3px;
    background: var(--bg);
    color: var(--text-secondary);
    cursor: pointer;
    transition: var(--transition);
}
.template-hint-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
}

/* AI对话历史 */
.ai-history {
    flex: 1;
    overflow-y: auto;
}
.ai-history-item {
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 12px;
    cursor: pointer;
    transition: var(--transition);
}
.ai-history-item:hover {
    background: var(--bg);
}
.ai-history-role {
    font-weight: 500;
    margin-bottom: 2px;
}
.ai-history-role.user { color: var(--info); }
.ai-history-role.assistant { color: var(--success); }
.ai-history-content {
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* 行号 */
.line-numbers {
    position: absolute;
    left: 0;
    top: 0;
    width: 40px;
    padding: 16px 4px 16px 0;
    text-align: right;
    font-family: "SF Mono", Consolas, "Liberation Mono", Menlo, monospace;
    font-size: 13px;
    line-height: 1.6;
    color: var(--text-muted);
    user-select: none;
    background: #f0f0f0;
}

/* 响应式 */
@media (max-width: 1200px) {
    .aidev-layout {
        grid-template-columns: 280px 1fr;
    }
}
@media (max-width: 900px) {
    .aidev-layout {
        grid-template-columns: 1fr;
        height: auto;
    }
    .code-panel-body {
        min-height: 300px;
    }
}
</style>

<div class="page-header">
  <h2>AI写插件</h2>
  <div class="actions">
    <button class="btn btn-outline btn-sm" onclick="clearChat()">清空对话</button>
  </div>
</div>

<div class="aidev-layout">
  <!-- 左侧配置和输入 -->
  <div class="ai-config-panel">
    <!-- AI配置 -->
    <div class="ai-config-card">
      <div class="card-header">
        <span class="ai-status-dot unknown" id="aiStatusDot"></span>
        AI 配置
      </div>
      <div class="card-body">
        <div class="form-group">
          <label>Base URL</label>
          <input type="text" id="aiBaseUrl" class="form-control" placeholder="例如: https://api.openai.com/v1" value="<?= htmlspecialchars($aiConfig['base_url'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>API Key</label>
          <input type="password" id="aiApiKey" class="form-control" placeholder="输入 API Key" value="<?= htmlspecialchars($aiConfig['api_key'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>模型</label>
          <input type="text" id="aiModel" class="form-control" placeholder="例如: gpt-4o-mini" value="<?= htmlspecialchars($aiConfig['model'] ?? 'gpt-4o-mini') ?>">
        </div>
        <button class="btn btn-outline btn-block" onclick="saveAiConfig()">保存配置</button>
      </div>
    </div>

    <!-- 插件名称 -->
    <div class="ai-config-card">
      <div class="card-header">插件信息</div>
      <div class="card-body">
        <div class="form-group" style="margin-bottom:0;">
          <label>插件名称</label>
          <input type="text" id="pluginName" class="form-control" placeholder="例如: weather（仅字母/数字/下划线）">
          <div class="form-hint">保存后将创建 plugin/插件名.php</div>
        </div>
      </div>
    </div>

    <!-- 需求输入 -->
    <div class="requirement-card">
      <div class="card-header">需求描述</div>
      <div class="card-body">
        <div class="form-group">
          <label>快捷模板</label>
          <div class="template-hints">
            <span class="template-hint-btn" onclick="insertTemplate('weather')">天气查询</span>
            <span class="template-hint-btn" onclick="insertTemplate('ping')">Ping测试</span>
            <span class="template-hint-btn" onclick="insertTemplate('gpt')">ChatGPT</span>
            <span class="template-hint-btn" onclick="insertTemplate('admin')">管理员验证</span>
            <span class="template-hint-btn" onclick="insertTemplate('timer')">定时任务</span>
            <span class="template-hint-btn" onclick="insertTemplate('image')">随机图片</span>
          </div>
        </div>
        <div class="form-group">
          <label>详细需求</label>
          <textarea id="aiRequirement" class="form-control" rows="6" placeholder="描述你想要的插件功能、触发指令、回复方式等..."></textarea>
        </div>
        <button class="btn btn-primary btn-block" id="genBtn" onclick="generatePlugin()">生成插件代码</button>
      </div>
    </div>

    <!-- AI对话历史 -->
    <div class="ai-config-card">
      <div class="card-header">对话历史</div>
      <div class="card-body no-padding">
        <div class="ai-history" id="aiHistory">
          <div class="empty-state" style="padding:20px;">
            <p>暂无对话记录</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 右侧代码区域 -->
  <div class="card code-panel">
    <div class="code-panel-header">
      <h3>代码预览</h3>
      <div class="code-panel-actions">
        <button class="btn btn-outline btn-sm" onclick="copyCode()" id="copyCodeBtn" style="display:none;">复制代码</button>
        <button class="btn btn-primary btn-sm" onclick="savePlugin()" id="savePluginBtn" style="display:none;">保存为插件</button>
      </div>
    </div>
    <div class="code-panel-body" id="codePanelBody">
      <div class="code-preview" id="codePreview">
        <div style="display:flex; align-items:center; justify-content:center; height:100%; min-height:400px; color:var(--text-muted);">
          <div style="text-align:center;">
            <div style="font-size:48px; opacity:0.3; margin-bottom:12px;">&#128187;</div>
            <p>在左侧描述需求，AI将为你生成插件代码</p>
          </div>
        </div>
      </div>
    </div>
    <div class="gen-status" id="genStatus" style="display:none;">
      <span id="genStatusIcon"></span>
      <span id="genStatusText"></span>
    </div>
  </div>
</div>

<script>
(function() {
    var generatedCode = '';
    var chatHistory = [];

    // ==================== 通用 AJAX ====================
    function apiCall(url, data, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                var res;
                try { res = JSON.parse(xhr.responseText); }
                catch (e) { res = { success: false, message: '响应解析失败' }; }
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

    // ==================== 保存AI配置 ====================
    window.saveAiConfig = function() {
        var baseUrl = document.getElementById('aiBaseUrl').value.trim();
        var apiKey = document.getElementById('aiApiKey').value.trim();
        var model = document.getElementById('aiModel').value.trim();

        apiCall('api/aidev_api.php?action=save_config', {
            base_url: baseUrl,
            api_key: apiKey,
            model: model || 'gpt-4o-mini'
        }, function(res) {
            if (res.code === 0) {
                alert('AI配置已保存');
                updateAiStatus();
            } else {
                alert(res.msg || res.message || '保存失败');
            }
        });
    };

    // ==================== 更新AI状态 ====================
    function updateAiStatus() {
        var dot = document.getElementById('aiStatusDot');
        var baseUrl = document.getElementById('aiBaseUrl').value.trim();
        var apiKey = document.getElementById('aiApiKey').value.trim();
        if (baseUrl && apiKey) {
            dot.className = 'ai-status-dot connected';
            dot.title = '已配置';
        } else {
            dot.className = 'ai-status-dot disconnected';
            dot.title = '未配置';
        }
    }

    // ==================== 模板插入 ====================
    window.insertTemplate = function(type) {
        var templates = {
            weather: '做一个天气查询插件，指令是 /天气 城市名，调用免费的天气API获取当前天气信息并以文字回复。',
            ping: '做一个Ping测试插件，收到 /ping 指令时回复 Pong! 和当前服务器时间。',
            gpt: '做一个ChatGPT对话插件，使用 /ask 问题 来向AI提问，使用本项目的AI配置（ai_config表）中的API信息。',
            admin: '做一个管理员验证插件，只有管理员QQ才能使用特定指令，通过 users 表验证权限。',
            timer: '做一个定时提醒插件，使用 /提醒 时间 内容 来设置提醒，到时间后自动发送消息。',
            image: '做一个随机图片插件，使用 /随机图片 指令从指定API获取随机图片并以图片消息回复。'
        };
        document.getElementById('aiRequirement').value = templates[type] || '';
    };

    // ==================== 生成插件 ====================
    window.generatePlugin = function() {
        var requirement = document.getElementById('aiRequirement').value.trim();
        if (!requirement) { alert('请输入需求描述'); return; }

        var btn = document.getElementById('genBtn');
        btn.disabled = true;
        btn.textContent = 'AI生成中...';

        var status = document.getElementById('genStatus');
        status.style.display = 'flex';
        status.className = 'gen-status generating';
        document.getElementById('genStatusIcon').innerHTML = '<span class="loading"></span>';
        document.getElementById('genStatusText').textContent = '正在调用AI生成代码，请稍候...';

        apiCall('api/aidev_api.php?action=generate', {
            prompt: requirement,
            plugin_name: document.getElementById('pluginName').value.trim()
        }, function(res) {
            btn.disabled = false;
            btn.textContent = '生成插件代码';

            if (res.code === 0 && res.data && res.data.code) {
                generatedCode = res.data.code;
                status.className = 'gen-status success';
                document.getElementById('genStatusIcon').innerHTML = '&#10003;';
                document.getElementById('genStatusText').textContent = '代码生成成功';
                renderCode(res.data.code);
                document.getElementById('copyCodeBtn').style.display = 'inline-flex';
                document.getElementById('savePluginBtn').style.display = 'inline-flex';

                // 记录对话
                addHistory('user', requirement);
                addHistory('assistant', '生成成功，代码长度: ' + res.data.code.length + '字符');
            } else {
                status.className = 'gen-status error';
                document.getElementById('genStatusIcon').innerHTML = '&#10007;';
                document.getElementById('genStatusText').textContent = res.msg || res.message || '生成失败';
                addHistory('user', requirement);
                addHistory('assistant', '生成失败: ' + (res.msg || res.message || '未知错误'));
            }
        });
    };

    // ==================== 渲染代码 ====================
    function renderCode(code) {
        var container = document.getElementById('codePreview');
        // 简单语法高亮（纯JS实现）
        var highlighted = escapeHtml(code);
        // PHP关键字高亮
        var keywords = ['function', 'if', 'else', 'elseif', 'return', 'class', 'public', 'private', 'protected', 'static', 'new', 'true', 'false', 'null', 'array', 'foreach', 'for', 'while', 'switch', 'case', 'break', 'continue', 'echo', 'print', 'define', 'require_once', 'require', 'include', 'try', 'catch', 'throw', 'isset', 'empty', 'global', 'as', 'use', 'namespace'];
        for (var i = 0; i < keywords.length; i++) {
            var regex = new RegExp('\\b(' + keywords[i] + ')\\b', 'g');
            highlighted = highlighted.replace(regex, '<span style="color:#795e26;">$1</span>');
        }
        // 字符串
        highlighted = highlighted.replace(/(&#39;[^&#]*?&#39;)/g, '<span style="color:#2e7d32;">$1</span>');
        highlighted = highlighted.replace(/(&quot;[^&]*?&quot;)/g, '<span style="color:#2e7d32;">$1</span>');
        // 注释
        highlighted = highlighted.replace(/(\/\/.*)/g, '<span style="color:#8c8c8c;">$1</span>');
        highlighted = highlighted.replace(/(\/\*[\s\S]*?\*\/)/g, '<span style="color:#8c8c8c;">$1</span>');
        // PHP标签
        highlighted = highlighted.replace(/(&lt;\?php)/g, '<span style="color:#c62828;">$1</span>');

        container.innerHTML = '<pre style="margin:0;white-space:pre-wrap;word-break:break-all;">' + highlighted + '</pre>';
    }

    // ==================== 复制代码 ====================
    window.copyCode = function() {
        if (!generatedCode) return;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(generatedCode);
        } else {
            var ta = document.createElement('textarea');
            ta.value = generatedCode;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
        var btn = document.getElementById('copyCodeBtn');
        btn.textContent = '已复制';
        setTimeout(function() { btn.textContent = '复制代码'; }, 1500);
    };

    // ==================== 保存插件 ====================
    window.savePlugin = function() {
        var pluginName = document.getElementById('pluginName').value.trim();
        if (!pluginName) { alert('请输入插件名称'); return; }
        if (!/^[\w\-\u4e00-\u9fff\u3400-\u4dbf]+$/.test(pluginName)) { alert('插件名只允许字母、数字、下划线、横杠和中文'); return; }
        if (!generatedCode) { alert('没有可保存的代码'); return; }

        if (!confirm('确定要将代码保存为插件「' + pluginName + '.php」吗？\n已有同名文件将被覆盖。')) return;

        var btn = document.getElementById('savePluginBtn');
        btn.disabled = true;
        btn.textContent = '保存中...';

        apiCall('api/aidev_api.php?action=save', {
            plugin_name: pluginName,
            code: generatedCode
        }, function(res) {
            btn.disabled = false;
            btn.textContent = '保存为插件';
            if (res.code === 0) {
                alert('插件保存成功!');
                addHistory('assistant', '插件已保存为 ' + pluginName + '.php');
            } else {
                alert(res.msg || res.message || '保存失败');
            }
        });
    };

    // ==================== 对话历史 ====================
    function addHistory(role, content) {
        chatHistory.push({ role: role, content: content });
        if (chatHistory.length > 100) chatHistory.shift();
        renderHistory();
    }

    function renderHistory() {
        var container = document.getElementById('aiHistory');
        if (chatHistory.length === 0) {
            container.innerHTML = '<div class="empty-state" style="padding:20px;"><p>暂无对话记录</p></div>';
            return;
        }
        var html = '';
        for (var i = chatHistory.length - 1; i >= 0; i--) {
            var h = chatHistory[i];
            var preview = h.content.length > 60 ? h.content.substring(0, 60) + '...' : h.content;
            html += '<div class="ai-history-item">';
            html += '<div class="ai-history-role ' + h.role + '">' + (h.role === 'user' ? '用户' : 'AI') + '</div>';
            html += '<div class="ai-history-content">' + escapeHtml(preview) + '</div>';
            html += '</div>';
        }
        container.innerHTML = html;
    }

    // ==================== 清空对话 ====================
    window.clearChat = function() {
        if (!confirm('确定要清空对话历史吗？')) return;
        chatHistory = [];
        renderHistory();
    };

    // ==================== 工具函数 ====================
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ==================== 初始化 ====================
    updateAiStatus();
})();
</script>

<?php require_once('footer.php'); ?>
