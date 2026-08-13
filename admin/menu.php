<?php
/**
 * 管理后台 - 自定义菜单管理
 * 对应官方文档: https://bot.q.qq.com/wiki/develop/api-v2/server-opens/openapi/menu/
 * 接口: GET/PUT/DELETE /v2/menu
 */
$pageTitle = '自定义菜单';
require_once(__DIR__ . '/header.php');

$bots = db()->fetchAll("SELECT appid, nickname, env FROM bots ORDER BY appid");
?>
<div class="page-header">
  <h1>自定义菜单管理</h1>
  <p class="text-muted">管理机器人自定义菜单，支持 send_message / link / switch / menu 四种按钮类型</p>
</div>

<div class="card" style="margin-bottom:16px;">
  <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
    <div style="flex:1; min-width:200px;">
      <label class="form-label">选择机器人</label>
      <select id="botSelect" class="form-input">
        <option value="">请选择机器人</option>
        <?php foreach ($bots as $bot): ?>
          <option value="<?= htmlspecialchars($bot['appid']) ?>">
            <?= htmlspecialchars($bot['nickname'] ?: $bot['appid']) ?> (<?= htmlspecialchars($bot['appid']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary" onclick="loadMenu()">查询当前菜单</button>
    <button class="btn btn-success" onclick="addMenuItem()">添加菜单项</button>
    <button class="btn btn-primary" onclick="saveMenu()">保存菜单</button>
    <button class="btn btn-danger" onclick="deleteMenu()">删除所有菜单</button>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">菜单编辑器</h2>
  <p class="text-muted" style="font-size:13px;">
    按钮类型说明：<br>
    • <b>send_message</b>：点击后向机器人发送一条消息（需填 input）<br>
    • <b>link</b>：跳转链接（需填 url）<br>
    • <b>switch</b>：切换子菜单（需填 child_menu_name）<br>
    • <b>menu</b>：子菜单容器（其下可挂按钮）
  </p>
  <div id="menuEditor" style="margin-top:12px;">
    <div class="empty-tip text-muted" style="text-align:center;padding:30px;">请选择机器人后点击「查询当前菜单」</div>
  </div>
</div>

<div class="card" style="margin-top:16px;">
  <h2 style="margin-top:0;">原始 JSON</h2>
  <textarea id="rawJson" class="form-input" rows="12" placeholder='{"menu":{"items":[{"label":"帮助","type":"send_message","input":"/帮助"}]}}' style="font-family:monospace;font-size:12px;"></textarea>
  <div style="margin-top:8px; display:flex; gap:8px;">
    <button class="btn btn-outline btn-sm" onclick="syncToJson()">从编辑器同步到JSON</button>
    <button class="btn btn-outline btn-sm" onclick="syncFromJson()">从JSON同步到编辑器</button>
  </div>
</div>

<script>
var menuItems = [];

function apiCall(action, data, cb) {
  var fd = new FormData();
  fd.append('action', action);
  for (var k in data) fd.append(k, data[k]);
  fetch('api.php', {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(cb)
    .catch(function(e){ alert('请求失败: '+e.message); });
}

function loadMenu() {
  var appid = document.getElementById('botSelect').value;
  if (!appid) { alert('请选择机器人'); return; }
  apiCall('menu_get', {appid:appid}, function(res){
    if (!res.success) {
      alert('查询失败: ' + (res.message||''));
      menuItems = [];
    } else {
      var data = res.data || {};
      var items = (data.menu && data.menu.items) ? data.menu.items : (data.items || []);
      menuItems = items;
    }
    renderEditor();
    syncToJson();
  });
}

function renderEditor() {
  var el = document.getElementById('menuEditor');
  if (menuItems.length === 0) {
    el.innerHTML = '<div class="empty-tip text-muted" style="text-align:center;padding:30px;">暂无菜单项，点击「添加菜单项」开始</div>';
    return;
  }
  var html = '';
  menuItems.forEach(function(item, idx){
    html += renderMenuItem(item, idx);
  });
  el.innerHTML = html;
}

function renderMenuItem(item, idx) {
  var type = item.type || 'send_message';
  var label = item.label || item.name || '';
  var desc = item.description || '';
  var html = '<div class="card" style="margin-bottom:8px; padding:12px; background:var(--bg-alt);">'
    + '<div style="display:flex; gap:8px; align-items:center; margin-bottom:8px; flex-wrap:wrap;">'
    + '<span class="badge">#' + (idx+1) + '</span>'
    + '<input type="text" class="form-input" style="flex:1; min-width:150px;" placeholder="按钮名称" value="' + escapeAttr(label) + '" onchange="updateField(' + idx + ',\'label\',this.value)">'
    + '<select class="form-input" style="width:140px;" onchange="updateField(' + idx + ',\'type\',this.value); renderEditor();">'
    + '<option value="send_message"' + (type==='send_message'?' selected':'') + '>发送消息</option>'
    + '<option value="link"' + (type==='link'?' selected':'') + '>跳转链接</option>'
    + '<option value="switch"' + (type==='switch'?' selected':'') + '>切换子菜单</option>'
    + '<option value="menu"' + (type==='menu'?' selected':'') + '>子菜单容器</option>'
    + '</select>'
    + '<button class="btn btn-danger btn-sm" onclick="removeItem(' + idx + ')">删除</button>'
    + '</div>';
  // 类型相关字段
  if (type === 'send_message') {
    html += '<div style="margin-bottom:6px;"><label class="form-label">发送内容 (input)</label>'
      + '<input type="text" class="form-input" value="' + escapeAttr(item.input||'') + '" onchange="updateField(' + idx + ',\'input\',this.value)"></div>';
  } else if (type === 'link') {
    html += '<div style="margin-bottom:6px;"><label class="form-label">跳转URL (url)</label>'
      + '<input type="text" class="form-input" value="' + escapeAttr(item.url||'') + '" onchange="updateField(' + idx + ',\'url\',this.value)"></div>';
  } else if (type === 'switch') {
    html += '<div style="margin-bottom:6px;"><label class="form-label">子菜单名称 (child_menu_name)</label>'
      + '<input type="text" class="form-input" value="' + escapeAttr(item.child_menu_name||'') + '" onchange="updateField(' + idx + ',\'child_menu_name\',this.value)"></div>';
  } else if (type === 'menu') {
    html += '<div style="margin-bottom:6px;"><label class="form-label">子菜单按钮 (JSON)</label>'
      + '<textarea class="form-input" rows="3" onchange="updateField(' + idx + ',\'button_list\',this.value)">' + escapeHtml(JSON.stringify(item.button_list||[], null, 2)) + '</textarea></div>';
  }
  html += '<div><label class="form-label">描述 (description, 可选)</label>'
    + '<input type="text" class="form-input" value="' + escapeAttr(desc) + '" onchange="updateField(' + idx + ',\'description\',this.value)"></div>';
  html += '</div>';
  return html;
}

function addMenuItem() {
  menuItems.push({label:'新按钮', type:'send_message', input:'', description:''});
  renderEditor();
  syncToJson();
}

function removeItem(idx) {
  menuItems.splice(idx, 1);
  renderEditor();
  syncToJson();
}

function updateField(idx, field, value) {
  if (!menuItems[idx]) return;
  if (field === 'button_list') {
    try { menuItems[idx][field] = JSON.parse(value); }
    catch(e) { /* 忽略解析错误,等用户改完 */ }
  } else {
    menuItems[idx][field] = value;
  }
  syncToJson();
}

function syncToJson() {
  var payload = {menu: {items: menuItems}};
  document.getElementById('rawJson').value = JSON.stringify(payload, null, 2);
}

function syncFromJson() {
  var raw = document.getElementById('rawJson').value.trim();
  if (!raw) return;
  try {
    var data = JSON.parse(raw);
    menuItems = (data.menu && data.menu.items) ? data.menu.items : (data.items || []);
    renderEditor();
  } catch(e) { alert('JSON格式错误: ' + e.message); }
}

function saveMenu() {
  var appid = document.getElementById('botSelect').value;
  if (!appid) { alert('请选择机器人'); return; }
  syncToJson();
  var rawJson = document.getElementById('rawJson').value.trim();
  if (!rawJson) { alert('菜单数据为空'); return; }
  try { JSON.parse(rawJson); }
  catch(e) { alert('JSON格式错误: ' + e.message); return; }
  if (!confirm('确定保存菜单到机器人 ' + appid + ' 吗？')) return;
  apiCall('menu_set', {appid:appid, menu_data: rawJson}, function(res){
    if (res.success) alert('保存成功');
    else alert('保存失败: ' + (res.message||'') + (res.raw ? '\n' + res.raw : ''));
  });
}

function deleteMenu() {
  var appid = document.getElementById('botSelect').value;
  if (!appid) { alert('请选择机器人'); return; }
  if (!confirm('确定删除机器人 ' + appid + ' 的所有自定义菜单吗？')) return;
  apiCall('menu_delete', {appid:appid}, function(res){
    if (res.success) {
      alert('删除成功');
      menuItems = [];
      renderEditor();
      document.getElementById('rawJson').value = '';
    } else alert('删除失败: ' + (res.message||''));
  });
}

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
