<?php
/**
 * 管理后台 - 指令面板管理
 * 对应官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels.get.html
 */
$pageTitle = '指令面板';
require_once(__DIR__ . '/header.php');

$bots = db()->fetchAll("SELECT appid, nickname, env FROM bots ORDER BY appid");
?>
<div class="page-header">
  <h1>指令面板管理</h1>
  <p class="text-muted">管理机器人的指令面板，支持 C2C单聊 / 群聊 / 文字子频道 / 频道私信 四种场景</p>
</div>

<div class="card" style="margin-bottom:16px;">
  <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
    <div style="flex:1; min-width:200px;">
      <label class="form-label">选择机器人</label>
      <select id="botSelect" class="form-input" onchange="onBotChange()">
        <option value="">请选择机器人</option>
        <?php foreach ($bots as $bot): ?>
          <option value="<?= htmlspecialchars($bot['appid']) ?>" data-env="<?= htmlspecialchars($bot['env']) ?>">
            <?= htmlspecialchars($bot['nickname'] ?: $bot['appid']) ?> (<?= htmlspecialchars($bot['appid']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:1; min-width:200px;">
      <label class="form-label">生效场景</label>
      <select id="scopeSelect" class="form-input">
        <option value="group">群聊 (group)</option>
        <option value="c2c">单聊 (c2c)</option>
        <option value="channel">文字子频道 (channel)</option>
        <option value="dm">频道私信 (dm)</option>
      </select>
    </div>
    <button class="btn btn-primary" onclick="loadPanels()">查询面板列表</button>
    <button class="btn btn-success" onclick="showCreateModal()">创建面板</button>
  </div>
</div>

<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
    <h2 style="margin:0;">面板列表</h2>
    <span id="panelCount" class="text-muted" style="font-size:13px;"></span>
  </div>
  <div id="panelList" style="min-height:200px;">
    <div class="empty-tip text-muted" style="text-align:center; padding:40px 0;">
      请选择机器人后点击「查询面板列表」
    </div>
  </div>
</div>

<!-- 创建/编辑面板模态框 -->
<div id="panelModal" class="modal" style="display:none;">
  <div class="modal-content" style="max-width:700px;">
    <div class="modal-header">
      <h3 id="modalTitle">创建指令面板</h3>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div style="margin-bottom:12px;">
        <label class="form-label">面板名称</label>
        <input type="text" id="panelName" class="form-input" placeholder="例如: 帮助菜单">
      </div>
      <div style="margin-bottom:12px;">
        <label class="form-label">面板类型</label>
        <select id="panelType" class="form-input">
          <option value="pcmd">关键词指令 (pcmd)</option>
          <option value="app">应用面板 (app)</option>
        </select>
      </div>
      <div style="margin-bottom:12px;">
        <label class="form-label">生效场景</label>
        <select id="modalScope" class="form-input">
          <option value="group">群聊 (group)</option>
          <option value="c2c">单聊 (c2c)</option>
          <option value="channel">文字子频道 (channel)</option>
          <option value="dm">频道私信 (dm)</option>
        </select>
      </div>
      <div style="margin-bottom:12px;">
        <label class="form-label">目标类型</label>
        <select id="targetType" class="form-input" onchange="onTargetTypeChange()">
          <option value="all">全部 (all)</option>
          <option value="specific">指定 (specific)</option>
        </select>
      </div>
      <div id="targetOpenidsWrap" style="display:none; margin-bottom:12px;">
        <label class="form-label">目标 OpenID 列表（每行一个）</label>
        <textarea id="targetOpenids" class="form-input" rows="3" placeholder="群openid或用户openid，每行一个"></textarea>
      </div>
      <div style="margin-bottom:12px;">
        <label class="form-label">指令列表 JSON（pcmd 类型）</label>
        <textarea id="panelCmds" class="form-input" rows="8" placeholder='[{"name":"天气","description":"查询天气","cmd":"/天气"}]'></textarea>
      </div>
      <div style="margin-bottom:12px;">
        <label class="form-label">原始 JSON（高级用户，留空则使用上面字段自动生成）</label>
        <textarea id="panelRawJson" class="form-input" rows="6" placeholder='留空则使用上方字段自动组装'></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal()">取消</button>
      <button class="btn btn-primary" id="saveBtn" onclick="savePanel()">保存</button>
    </div>
  </div>
</div>

<script>
var currentEditId = '';

function apiCall(action, data, cb) {
  var fd = new FormData();
  fd.append('action', action);
  for (var k in data) fd.append(k, data[k]);
  fetch('api.php', {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(cb)
    .catch(function(e){ alert('请求失败: '+e.message); });
}

function onBotChange() { /* 触发场景选择 */ }

function loadPanels() {
  var appid = document.getElementById('botSelect').value;
  var scope = document.getElementById('scopeSelect').value;
  if (!appid) { alert('请选择机器人'); return; }
  var listEl = document.getElementById('panelList');
  listEl.innerHTML = '<div class="text-muted" style="text-align:center;padding:30px;">加载中...</div>';
  apiCall('panel_list', {appid:appid, scope:scope}, function(res){
    if (!res.success) {
      listEl.innerHTML = '<div class="alert alert-danger">' + (res.message||'查询失败') + '</div>';
      return;
    }
    var panels = (res.data && res.data.data && res.data.data.dir_panels) ? res.data.data.dir_panels : [];
    if (res.data && res.data.data && Array.isArray(res.data.data)) panels = res.data.data;
    document.getElementById('panelCount').textContent = '共 ' + panels.length + ' 个面板';
    if (panels.length === 0) {
      listEl.innerHTML = '<div class="empty-tip text-muted" style="text-align:center;padding:30px;">暂无面板</div>';
      return;
    }
    var html = '<table class="table"><thead><tr><th>面板ID</th><th>名称</th><th>类型</th><th>场景</th><th>目标</th><th>操作</th></tr></thead><tbody>';
    panels.forEach(function(p){
      var pid = p.panel_id || p.id || '';
      html += '<tr>'
        + '<td style="font-family:monospace;font-size:12px;word-break:break-all;max-width:200px;">' + escapeHtml(pid) + '</td>'
        + '<td>' + escapeHtml(p.name || p.panel_name || '') + '</td>'
        + '<td>' + escapeHtml(p.type || p.panel_type || '') + '</td>'
        + '<td>' + escapeHtml(p.scope || '') + '</td>'
        + '<td>' + escapeHtml(p.target_type || '') + '</td>'
        + '<td><button class="btn btn-sm btn-outline" onclick="editPanel(\''+pid+'\')">编辑</button> '
        + '<button class="btn btn-sm btn-danger" onclick="deletePanel(\''+pid+'\')">删除</button></td>'
        + '</tr>';
    });
    html += '</tbody></table>';
    listEl.innerHTML = html;
  });
}

function showCreateModal() {
  var appid = document.getElementById('botSelect').value;
  if (!appid) { alert('请先选择机器人'); return; }
  currentEditId = '';
  document.getElementById('modalTitle').textContent = '创建指令面板';
  document.getElementById('panelName').value = '';
  document.getElementById('panelType').value = 'pcmd';
  document.getElementById('modalScope').value = document.getElementById('scopeSelect').value;
  document.getElementById('targetType').value = 'all';
  document.getElementById('targetOpenidsWrap').style.display = 'none';
  document.getElementById('targetOpenids').value = '';
  document.getElementById('panelCmds').value = '[\n  {"name":"帮助","description":"查看帮助","cmd":"/帮助"}\n]';
  document.getElementById('panelRawJson').value = '';
  document.getElementById('panelModal').style.display = 'flex';
}

function editPanel(pid) {
  currentEditId = pid;
  document.getElementById('modalTitle').textContent = '编辑指令面板';
  // 简化:用原始JSON编辑
  var raw = prompt('请输入面板的完整JSON配置(获取详情后修改):\n(留空取消)');
  if (raw === null) return;
  if (raw.trim() === '') return;
  try {
    var data = JSON.parse(raw);
    document.getElementById('panelName').value = data.name || data.panel_name || '';
    document.getElementById('panelType').value = data.type || data.panel_type || 'pcmd';
    document.getElementById('modalScope').value = data.scope || 'group';
    document.getElementById('targetType').value = data.target_type || 'all';
    document.getElementById('panelRawJson').value = raw;
    if (data.target_type === 'specific') {
      document.getElementById('targetOpenidsWrap').style.display = 'block';
      document.getElementById('targetOpenids').value = (data.target_openids||[]).join('\n');
    } else {
      document.getElementById('targetOpenidsWrap').style.display = 'none';
    }
    document.getElementById('panelModal').style.display = 'flex';
  } catch(e) {
    alert('JSON解析失败: ' + e.message);
  }
}

function onTargetTypeChange() {
  var tt = document.getElementById('targetType').value;
  document.getElementById('targetOpenidsWrap').style.display = (tt === 'specific') ? 'block' : 'none';
}

function savePanel() {
  var appid = document.getElementById('botSelect').value;
  if (!appid) { alert('请选择机器人'); return; }
  var rawJson = document.getElementById('panelRawJson').value.trim();
  var data;
  if (rawJson) {
    try { data = JSON.parse(rawJson); }
    catch(e) { alert('原始JSON格式错误: ' + e.message); return; }
  } else {
    // 自动组装
    var name = document.getElementById('panelName').value.trim();
    var type = document.getElementById('panelType').value;
    var scope = document.getElementById('modalScope').value;
    var targetType = document.getElementById('targetType').value;
    if (!name) { alert('请填写面板名称'); return; }
    data = {name:name, type:type, scope:scope, target_type:targetType};
    if (targetType === 'specific') {
      var openids = document.getElementById('targetOpenids').value.split('\n').map(function(s){return s.trim();}).filter(function(s){return s;});
      data.target_openids = openids;
    }
    // pmd 指令
    var cmdsStr = document.getElementById('panelCmds').value.trim();
    if (cmdsStr && type === 'pcmd') {
      try { data.cmds = JSON.parse(cmdsStr); }
      catch(e) { alert('指令列表JSON错误: ' + e.message); return; }
    }
  }
  document.getElementById('saveBtn').disabled = true;
  var action = currentEditId ? 'panel_update' : 'panel_create';
  var params = {appid:appid, panel_data: JSON.stringify(data, null, 2)};
  if (currentEditId) params.panel_id = currentEditId;
  apiCall(action, params, function(res){
    document.getElementById('saveBtn').disabled = false;
    if (res.success) {
      alert(currentEditId ? '修改成功' : '创建成功');
      closeModal();
      loadPanels();
    } else {
      alert('失败: ' + (res.message||'未知错误') + (res.raw ? '\n原始响应: ' + res.raw : ''));
    }
  });
}

function deletePanel(pid) {
  if (!confirm('确定删除面板 ' + pid + ' 吗？')) return;
  var appid = document.getElementById('botSelect').value;
  apiCall('panel_delete', {appid:appid, panel_id:pid}, function(res){
    if (res.success) { alert('删除成功'); loadPanels(); }
    else alert('删除失败: ' + (res.message||''));
  });
}

function closeModal() { document.getElementById('panelModal').style.display = 'none'; }

function escapeHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/[&<>"']/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
}
</script>

<?php require_once(__DIR__ . '/footer.php'); ?>
