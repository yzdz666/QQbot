<?php
/**
 * 管理后台 - 指令面板管理
 * 严格对应官方文档:
 *   列表: GET    /v2/panels?scope=...     https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels.get.html
 *   创建: POST   /v2/panels               https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels.post.html
 *   详情: GET    /v2/panels/{panel_id}    https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels_panel_id.get.html
 *   修改: PUT    /v2/panels/{panel_id}    https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels_panel_id.put.html
 *   删除: DELETE /v2/panels/{panel_id}    https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels_panel_id.delete.html
 *
 * PanelRecord 字段(官方):
 *   panel_id, scope, target_type(all/specific), panel, created_at, updated_at, version
 *
 * Panel 字段(官方):
 *   items   PanelItem 数组(最多20个)
 *   remark  面板备注(最多255字符,不对用户展示)
 *   version 版本号
 *
 * PanelItem 字段(官方):
 *   name        元素名称(type=command时填入输入框, type=link时仅展示; 最多14字符)
 *   desc        元素描述(最多30字符)
 *   type        command(指令) | link(链接跳转)
 *   only_admin  是否仅管理员可操作
 *   link        跳转URL(仅type=link时有效)
 *
 * 创建请求(POST)额外字段:
 *   scope         c2c|group|channel|dm (必填)
 *   target_type   all|specific (channel/dm只能all)
 *   user_openids  c2c+specific 时有效
 *   group_openids group+specific 时有效
 */
$pageTitle = '指令面板';
require_once(__DIR__ . '/header.php');

$bots = db()->fetchAll("SELECT appid, nickname, env FROM bots ORDER BY appid");
?>
<div class="page-header">
  <h1>指令面板管理</h1>
  <p class="text-muted">管理机器人指令面板 · 支持 C2C单聊 / 群聊 / 文字子频道 / 频道私信 四种场景 · 严格遵循官方 API 字段格式</p>
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
      <label class="form-label">生效场景 (scope)</label>
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
  <div class="modal-content" style="max-width:780px;">
    <div class="modal-header">
      <h3 id="modalTitle">创建指令面板</h3>
      <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
        <div style="flex:1; min-width:180px;">
          <label class="form-label">面板备注 (remark)</label>
          <input type="text" id="panelRemark" class="form-input" placeholder="例如: 帮助菜单 (最多255字符,不对用户展示)">
        </div>
        <div style="min-width:180px;">
          <label class="form-label">生效场景 (scope)</label>
          <select id="modalScope" class="form-input" onchange="onModalScopeChange()">
            <option value="group">群聊 (group)</option>
            <option value="c2c">单聊 (c2c)</option>
            <option value="channel">文字子频道 (channel)</option>
            <option value="dm">频道私信 (dm)</option>
          </select>
        </div>
        <div style="min-width:160px;">
          <label class="form-label">作用范围 (target_type)</label>
          <select id="targetType" class="form-input" onchange="onTargetTypeChange()">
            <option value="all">全局 (all)</option>
            <option value="specific">指定 (specific)</option>
          </select>
        </div>
      </div>
      <div id="targetOpenidsWrap" style="display:none; margin-bottom:12px;">
        <label class="form-label" id="targetOpenidsLabel">目标 OpenID 列表（每行一个，最多 20 个）</label>
        <textarea id="targetOpenids" class="form-input" rows="3" placeholder="每行一个 openid"></textarea>
      </div>

      <div style="margin-bottom:12px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
          <label class="form-label" style="margin:0;">面板元素列表 (panel.items，最多 20 个)</label>
          <button class="btn btn-sm btn-success" onclick="addPanelItem()">+ 添加元素</button>
        </div>
        <div id="panelItemsEditor"></div>
      </div>

      <div style="margin-bottom:12px;">
        <label class="form-label">原始 JSON（高级用户，留空则使用上方表单自动生成）</label>
        <textarea id="panelRawJson" class="form-input" rows="8" placeholder='留空则使用上方字段自动组装; 填写则直接使用此 JSON' style="font-family:monospace;font-size:12px;"></textarea>
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
var panelItems = []; // PanelItem 数组

function apiCall(action, data, cb) {
  var fd = new FormData();
  fd.append('action', action);
  for (var k in data) fd.append(k, data[k]);
  fetch('api.php', {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(cb)
    .catch(function(e){ alert('请求失败: '+e.message); });
}

function onBotChange() { /* 占位 */ }

// ==================== 查询面板列表 (GET /v2/panels?scope=...) ====================
function loadPanels() {
  var appid = document.getElementById('botSelect').value;
  var scope = document.getElementById('scopeSelect').value;
  if (!appid) { alert('请选择机器人'); return; }
  var listEl = document.getElementById('panelList');
  listEl.innerHTML = '<div class="text-muted" style="text-align:center;padding:30px;">加载中...</div>';
  apiCall('panel_list', {appid:appid, scope:scope}, function(res){
    if (!res.success) {
      listEl.innerHTML = '<div class="alert alert-danger">' + escapeHtml(res.message||'查询失败') + (res.raw ? '<br><pre style="white-space:pre-wrap;font-size:11px;">'+escapeHtml(res.raw)+'</pre>' : '') + '</div>';
      document.getElementById('panelCount').textContent = '';
      return;
    }
    // 官方返回格式: {records: [...], next_cursor: string, is_end: boolean}
    var data = res.data || {};
    var records = Array.isArray(data.records) ? data.records : (Array.isArray(data) ? data : []);
    document.getElementById('panelCount').textContent = '共 ' + records.length + ' 个面板' + (data.is_end === false ? ' (有下一页)' : ' (已到最后一页)');
    if (records.length === 0) {
      listEl.innerHTML = '<div class="empty-tip text-muted" style="text-align:center;padding:30px;">暂无面板，点击「创建面板」新建</div>';
      return;
    }
    var html = '<div class="table-responsive"><table class="table"><thead><tr>'
      + '<th>面板ID</th><th>备注 (remark)</th><th>场景</th><th>范围</th><th>元素数</th><th>版本</th><th>更新时间</th><th>操作</th>'
      + '</tr></thead><tbody>';
    records.forEach(function(p){
      var pid = p.panel_id || '';
      var panel = p.panel || {};
      var items = Array.isArray(panel.items) ? panel.items : [];
      var remark = panel.remark || '';
      html += '<tr>'
        + '<td style="font-family:monospace;font-size:12px;word-break:break-all;max-width:180px;">' + escapeHtml(pid) + '</td>'
        + '<td>' + escapeHtml(remark) + '</td>'
        + '<td><span class="badge">' + escapeHtml(p.scope||'') + '</span></td>'
        + '<td><span class="badge ' + (p.target_type==='specific'?'badge-warn':'') + '">' + escapeHtml(p.target_type||'') + '</span></td>'
        + '<td>' + items.length + '</td>'
        + '<td>v' + (p.version||0) + '</td>'
        + '<td style="font-size:12px;white-space:nowrap;">' + escapeHtml(p.updated_at||p.created_at||'') + '</td>'
        + '<td style="white-space:nowrap;">'
        + '<button class="btn btn-sm btn-outline" onclick="viewPanelDetail(\''+pid+'\')">详情</button> '
        + '<button class="btn btn-sm btn-primary" onclick="editPanel(\''+pid+'\')">编辑</button> '
        + '<button class="btn btn-sm btn-danger" onclick="deletePanel(\''+pid+'\')">删除</button>'
        + '</td>'
        + '</tr>';
    });
    html += '</tbody></table></div>';
    listEl.innerHTML = html;
  });
}

// ==================== 查询面板详情 (GET /v2/panels/{panel_id}) ====================
function viewPanelDetail(pid) {
  var appid = document.getElementById('botSelect').value;
  if (!appid || !pid) return;
  apiCall('panel_detail', {appid:appid, panel_id:pid}, function(res){
    if (!res.success) {
      alert('查询详情失败: ' + (res.message||'') + (res.raw ? '\n' + res.raw : ''));
      return;
    }
    var d = res.data || {};
    var panel = d.panel || {};
    var items = Array.isArray(panel.items) ? panel.items : [];
    var msg = '面板ID: ' + (d.panel_id||pid)
      + '\n场景: ' + (d.scope||'')
      + '\n范围: ' + (d.target_type||'')
      + '\n备注: ' + (panel.remark||'')
      + '\n版本: v' + (d.version||0)
      + '\n创建时间: ' + (d.created_at||'')
      + '\n更新时间: ' + (d.updated_at||'')
      + '\n\n面板元素 (' + items.length + ' 个):';
    items.forEach(function(it, i){
      msg += '\n  [' + (i+1) + '] type=' + (it.type||'') + ', name=' + (it.name||'') + ', desc=' + (it.desc||'') + (it.only_admin?', 仅管理员':'') + (it.link?(', link='+it.link):'');
    });
    if (d.user_openids && d.user_openids.length) msg += '\n\n关联用户: ' + d.user_openids.length + ' 个';
    if (d.group_openids && d.group_openids.length) msg += '\n关联群: ' + d.group_openids.length + ' 个';
    alert(msg);
  });
}

// ==================== 创建面板 ====================
function showCreateModal() {
  var appid = document.getElementById('botSelect').value;
  if (!appid) { alert('请先选择机器人'); return; }
  currentEditId = '';
  document.getElementById('modalTitle').textContent = '创建指令面板';
  document.getElementById('panelRemark').value = '';
  document.getElementById('modalScope').value = document.getElementById('scopeSelect').value;
  document.getElementById('targetType').value = 'all';
  document.getElementById('targetOpenidsWrap').style.display = 'none';
  document.getElementById('targetOpenids').value = '';
  document.getElementById('panelRawJson').value = '';
  panelItems = [{name:'查询天气', desc:'查询当前天气', type:'command', only_admin:false}];
  renderPanelItems();
  document.getElementById('panelModal').style.display = 'flex';
}

// ==================== 编辑面板 (先查详情再填充表单) ====================
function editPanel(pid) {
  var appid = document.getElementById('botSelect').value;
  if (!appid || !pid) return;
  apiCall('panel_detail', {appid:appid, panel_id:pid}, function(res){
    if (!res.success) {
      // 详情查询失败时回退到 JSON 输入模式
      var raw = prompt('查询详情失败: ' + (res.message||'') + '\n\n请手动输入面板的完整 JSON 配置（panel 字段内容）:\n(留空取消)');
      if (raw === null || raw.trim() === '') return;
      try {
        var data = JSON.parse(raw);
        currentEditId = pid;
        document.getElementById('modalTitle').textContent = '编辑指令面板 (JSON模式)';
        document.getElementById('panelRemark').value = data.remark || '';
        document.getElementById('modalScope').value = data.scope || 'group';
        document.getElementById('targetType').value = data.target_type || 'all';
        document.getElementById('panelRawJson').value = raw;
        panelItems = Array.isArray(data.items) ? data.items : [];
        renderPanelItems();
        document.getElementById('panelModal').style.display = 'flex';
      } catch(e) { alert('JSON 解析失败: ' + e.message); }
      return;
    }
    var d = res.data || {};
    var panel = d.panel || {};
    currentEditId = pid;
    document.getElementById('modalTitle').textContent = '编辑指令面板 (' + pid + ')';
    document.getElementById('panelRemark').value = panel.remark || '';
    document.getElementById('modalScope').value = d.scope || 'group';
    document.getElementById('targetType').value = d.target_type || 'all';
    onTargetTypeChange();
    panelItems = Array.isArray(panel.items) ? panel.items : [];
    renderPanelItems();
    // 显示关联对象（仅 specific 时）
    if (d.target_type === 'specific') {
      var openids = d.user_openids || d.group_openids || [];
      document.getElementById('targetOpenids').value = openids.join('\n');
    }
    document.getElementById('panelRawJson').value = '';
    document.getElementById('panelModal').style.display = 'flex';
  });
}

// ==================== 面板元素编辑器 ====================
function renderPanelItems() {
  var el = document.getElementById('panelItemsEditor');
  if (panelItems.length === 0) {
    el.innerHTML = '<div class="text-muted" style="font-size:12px; padding:8px;">暂无面板元素，点击「添加元素」新建</div>';
    return;
  }
  var html = '';
  panelItems.forEach(function(it, idx){
    var type = it.type || 'command';
    html += '<div class="card" style="margin-bottom:8px; padding:10px; background:var(--bg-alt); border-left:3px solid var(--primary);">'
      + '<div style="display:flex; gap:8px; align-items:center; margin-bottom:6px; flex-wrap:wrap;">'
      + '<span class="badge" style="background:var(--primary);color:#fff;">#' + (idx+1) + '</span>'
      + '<select class="form-input" style="width:160px;" onchange="updatePanelItem(' + idx + ',\'type\',this.value)">'
      + '<option value="command"' + (type==='command'?' selected':'') + '>指令 command</option>'
      + '<option value="link"' + (type==='link'?' selected':'') + '>链接跳转 link</option>'
      + '</select>'
      + '<label style="font-size:12px; display:flex; align-items:center; gap:4px;">'
      + '<input type="checkbox" ' + (it.only_admin?'checked':'') + ' onchange="updatePanelItem(' + idx + ',\'only_admin\',this.checked)"> 仅管理员'
      + '</label>'
      + '<button class="btn btn-danger btn-sm" onclick="removePanelItem(' + idx + ')" style="margin-left:auto;">删除</button>'
      + '</div>'
      + '<div style="display:flex; gap:8px; flex-wrap:wrap;">'
      + '<div style="flex:1; min-width:150px;"><label class="form-label">name (元素名称, 最多14字符)</label>'
      + '<input type="text" class="form-input" value="' + escapeAttr(it.name||'') + '" onchange="updatePanelItem(' + idx + ',\'name\',this.value)"></div>'
      + '<div style="flex:1; min-width:150px;"><label class="form-label">desc (元素描述, 最多30字符)</label>'
      + '<input type="text" class="form-input" value="' + escapeAttr(it.desc||'') + '" onchange="updatePanelItem(' + idx + ',\'desc\',this.value)"></div>';
    if (type === 'link') {
      html += '<div style="flex:1; min-width:200px;"><label class="form-label">link (跳转URL, 必须 https://)</label>'
        + '<input type="text" class="form-input" placeholder="https://example.com" value="' + escapeAttr(it.link||'') + '" onchange="updatePanelItem(' + idx + ',\'link\',this.value)"></div>';
    }
    html += '</div></div>';
  });
  el.innerHTML = html;
}

function addPanelItem() {
  if (panelItems.length >= 20) { alert('面板元素最多 20 个'); return; }
  panelItems.push({name:'新指令', desc:'', type:'command', only_admin:false});
  renderPanelItems();
}

function removePanelItem(idx) {
  panelItems.splice(idx, 1);
  renderPanelItems();
}

function updatePanelItem(idx, field, value) {
  if (!panelItems[idx]) return;
  panelItems[idx][field] = value;
  if (field === 'type') {
    if (value === 'command') delete panelItems[idx].link;
    else if (value === 'link') { panelItems[idx].link = panelItems[idx].link || ''; }
    renderPanelItems();
  }
}

// ==================== 场景/范围联动 ====================
function onModalScopeChange() {
  var scope = document.getElementById('modalScope').value;
  var tt = document.getElementById('targetType');
  // channel/dm 仅支持 all
  if (scope === 'channel' || scope === 'dm') {
    tt.value = 'all';
    tt.disabled = true;
  } else {
    tt.disabled = false;
  }
  onTargetTypeChange();
}

function onTargetTypeChange() {
  var tt = document.getElementById('targetType').value;
  var scope = document.getElementById('modalScope').value;
  var wrap = document.getElementById('targetOpenidsWrap');
  var label = document.getElementById('targetOpenidsLabel');
  if (tt === 'specific' && (scope === 'c2c' || scope === 'group')) {
    wrap.style.display = 'block';
    label.textContent = (scope === 'c2c' ? '用户 OpenID 列表' : '群 OpenID 列表') + '（每行一个，最多 20 个）';
  } else {
    wrap.style.display = 'none';
  }
}

// ==================== 保存面板 (POST 创建 / PUT 修改) ====================
function savePanel() {
  var appid = document.getElementById('botSelect').value;
  if (!appid) { alert('请选择机器人'); return; }

  var rawJson = document.getElementById('panelRawJson').value.trim();
  var data;
  if (rawJson) {
    // 高级模式：直接使用原始 JSON
    try { data = JSON.parse(rawJson); }
    catch(e) { alert('原始 JSON 格式错误: ' + e.message); return; }
  } else {
    // 表单模式：按官方格式组装
    var remark = document.getElementById('panelRemark').value.trim();
    var scope = document.getElementById('modalScope').value;
    var targetType = document.getElementById('targetType').value;
    // 组装 panel 对象（官方: {items, remark, version}）
    var panel = {
      items: panelItems.map(function(it){
        var item = {type: it.type || 'command', name: it.name || '', desc: it.desc || '', only_admin: !!it.only_admin};
        if (item.type === 'link' && it.link) item.link = it.link;
        return item;
      })
    };
    if (remark) panel.remark = remark;

    if (currentEditId) {
      // 修改面板: PUT /v2/panels/{panel_id}，请求体只需 panel 字段
      data = {panel: panel};
    } else {
      // 创建面板: POST /v2/panels，请求体包含 scope/target_type/panel
      data = {scope: scope, target_type: targetType, panel: panel};
      if (targetType === 'specific') {
        var openids = document.getElementById('targetOpenids').value.split('\n').map(function(s){return s.trim();}).filter(function(s){return s;});
        if (openids.length === 0) { alert('指定范围模式下需填写至少一个 OpenID'); return; }
        if (openids.length > 20) { alert('OpenID 最多 20 个'); return; }
        if (scope === 'c2c') data.user_openids = openids;
        else if (scope === 'group') data.group_openids = openids;
      }
    }
  }

  document.getElementById('saveBtn').disabled = true;
  var action = currentEditId ? 'panel_update' : 'panel_create';
  var params = {appid:appid, panel_data: JSON.stringify(data, null, 2)};
  if (currentEditId) params.panel_id = currentEditId;
  apiCall(action, params, function(res){
    document.getElementById('saveBtn').disabled = false;
    if (res.success) {
      var msg = currentEditId ? '修改成功' : '创建成功';
      if (res.data && res.data.panel_id) msg += '\n面板ID: ' + res.data.panel_id;
      if (res.data && res.data.version) msg += '\n版本: v' + res.data.version;
      alert(msg);
      closeModal();
      loadPanels();
    } else {
      alert('失败: ' + (res.message||'未知错误') + (res.raw ? '\n原始响应: ' + res.raw : ''));
    }
  });
}

// ==================== 删除面板 (DELETE /v2/panels/{panel_id}) ====================
function deletePanel(pid) {
  if (!confirm('确定删除面板 ' + pid + ' 吗？\n删除后该面板不再对任何用户或群生效')) return;
  var appid = document.getElementById('botSelect').value;
  apiCall('panel_delete', {appid:appid, panel_id:pid}, function(res){
    if (res.success) { alert('删除成功'); loadPanels(); }
    else alert('删除失败: ' + (res.message||'') + (res.raw ? '\n原始响应: ' + res.raw : ''));
  });
}

function closeModal() { document.getElementById('panelModal').style.display = 'none'; }

function escapeHtml(s) {
  if (s === null || s === undefined) return '';
  return String(s).replace(/[&<>"']/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
}
function escapeAttr(s) {
  return escapeHtml(s).replace(/"/g, '&quot;');
}
</script>

<?php require_once(__DIR__ . '/footer.php'); ?>
