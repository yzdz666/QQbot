<?php
/**
 * 管理后台 - 自定义菜单管理
 * 严格对应官方文档:
 *   查询: GET  /v2/menu        https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_menu.get.html
 *   修改: PUT  /v2/menu        https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_menu.put.html
 *   删除: DELETE /v2/menu
 *
 * MenuItem 字段(官方):
 *   name              按钮名称(最多10字符,中文算2)
 *   type              switch|send_message|link|menu
 *   send_message      type=send_message 时有效,点击后填入输入框的内容
 *   link              type=link 时有效,跳转URL(必须https://)
 *   switch            type=switch 时有效,对象 {switch_id, default}
 *   sub_menu_items    type=menu 时有效,子菜单列表(最多5个,不支持嵌套)
 *
 * SubMenuItem 字段(官方):
 *   name              按钮名称(最多14字符)
 *   type              send_message|link (二级菜单不支持 menu)
 *   send_message      type=send_message 时有效
 *   link              type=link 时有效
 */
$pageTitle = '自定义菜单';
require_once(__DIR__ . '/header.php');

$bots = db()->fetchAll("SELECT appid, nickname, env FROM bots ORDER BY appid");
?>
<div class="page-header">
  <h1>自定义菜单管理</h1>
  <p class="text-muted">管理机器人自定义菜单（仅 C2C 单聊场景生效） · 严格遵循官方 API 字段格式</p>
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
    <button class="btn btn-success" onclick="addMenuItem()">添加按钮</button>
    <button class="btn btn-primary" onclick="saveMenu()">保存菜单</button>
    <button class="btn btn-danger" onclick="deleteMenu()">删除所有菜单</button>
  </div>
</div>

<div class="card" style="margin-bottom:16px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
    <h2 style="margin:0;">菜单版本</h2>
    <span id="menuVersion" class="text-muted" style="font-size:13px;">未查询</span>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;">可视化菜单编辑器</h2>
  <p class="text-muted" style="font-size:13px; line-height:1.8;">
    <b>官方按钮类型</b>（type 字段）：<br>
    • <b>send_message</b>：发送消息。用户点击后 <code>send_message</code> 内容自动填入聊天输入框<br>
    • <b>link</b>：链接跳转。用户点击后跳转到 <code>link</code> 指定的 URL（必须 https:// 开头）<br>
    • <b>switch</b>：开关。用户切换状态后会发送携带 <code>switch_id</code> 的消息，需配置 <code>switch_id</code> 与 <code>default</code><br>
    • <b>menu</b>：含子菜单的折叠项。其下 <code>sub_menu_items</code> 最多 5 个，二级菜单仅支持 send_message / link<br>
    <br>
    <b>限制</b>：一级菜单最多 10 个按钮；按钮名称最多 10 字符（中文算 2 个）；子菜单按钮名称最多 14 字符
  </p>
  <div id="menuEditor" style="margin-top:12px;">
    <div class="empty-tip text-muted" style="text-align:center;padding:30px;">请选择机器人后点击「查询当前菜单」加载已有配置，或点击「添加按钮」新建</div>
  </div>
</div>

<div class="card" style="margin-top:16px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
    <h2 style="margin:0;">原始 JSON（与官方 API 完全一致）</h2>
    <div style="display:flex; gap:8px;">
      <button class="btn btn-outline btn-sm" onclick="syncToJson()">编辑器 → JSON</button>
      <button class="btn btn-outline btn-sm" onclick="syncFromJson()">JSON → 编辑器</button>
    </div>
  </div>
  <textarea id="rawJson" class="form-input" rows="14" placeholder='{"menu":{"items":[{"name":"帮助","type":"send_message","send_message":"/help"}]}}' style="font-family:monospace;font-size:12px;"></textarea>
</div>

<script>
// ==================== 菜单数据模型（严格对齐官方字段） ====================
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

// ==================== 查询菜单 ====================
function loadMenu() {
  var appid = document.getElementById('botSelect').value;
  if (!appid) { alert('请选择机器人'); return; }
  var editorEl = document.getElementById('menuEditor');
  editorEl.innerHTML = '<div class="text-muted" style="text-align:center;padding:30px;">加载中...</div>';
  apiCall('menu_get', {appid:appid}, function(res){
    if (!res.success) {
      alert('查询失败: ' + (res.message||'') + (res.raw ? '\n原始响应: ' + res.raw : ''));
      menuItems = [];
      document.getElementById('menuVersion').textContent = '查询失败';
    } else {
      // 官方返回格式: {version: int, menu: {items: [...]}}
      var data = res.data || {};
      var menuObj = data.menu || {};
      menuItems = Array.isArray(menuObj.items) ? menuObj.items : [];
      var ver = data.version;
      document.getElementById('menuVersion').textContent = ver ? ('版本 v' + ver) : '已查询(无版本号)';
    }
    renderEditor();
    syncToJson();
  });
}

// ==================== 渲染编辑器 ====================
function renderEditor() {
  var el = document.getElementById('menuEditor');
  if (menuItems.length === 0) {
    el.innerHTML = '<div class="empty-tip text-muted" style="text-align:center;padding:30px;">暂无菜单项，点击「添加按钮」开始创建</div>';
    return;
  }
  var html = '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">'
    + '<span class="text-muted" style="font-size:13px;">共 ' + menuItems.length + ' / 10 个一级按钮</span>'
    + '</div>';
  menuItems.forEach(function(item, idx){
    html += renderMenuItem(item, idx, false);
  });
  el.innerHTML = html;
}

function renderMenuItem(item, idx, isSub) {
  var type = item.type || 'send_message';
  var name = item.name || '';
  var html = '<div class="card" style="margin-bottom:10px; padding:12px; background:var(--bg-alt); border-left:3px solid var(--primary);">'
    + '<div style="display:flex; gap:8px; align-items:center; margin-bottom:8px; flex-wrap:wrap;">'
    + '<span class="badge" style="background:var(--primary);color:#fff;">#' + (idx+1) + (isSub ? ' 子' : '') + '</span>'
    + '<input type="text" class="form-input" style="flex:1; min-width:150px;" placeholder="按钮名称 (最多' + (isSub?'14':'10') + '字符)" value="' + escapeAttr(name) + '" onchange="updateField(' + idx + ',\'name\',this.value' + (isSub?',true':'') + ')">'
    + '<select class="form-input" style="width:150px;" onchange="updateField(' + idx + ',\'type\',this.value' + (isSub?',true':'') + '); ' + (isSub?'':'renderEditor();') + '">'
    + '<option value="send_message"' + (type==='send_message'?' selected':'') + '>发送消息 send_message</option>'
    + '<option value="link"' + (type==='link'?' selected':'') + '>跳转链接 link</option>';
  if (!isSub) {
    // 一级菜单额外支持 switch 和 menu
    html += '<option value="switch"' + (type==='switch'?' selected':'') + '>开关 switch</option>'
      + '<option value="menu"' + (type==='menu'?' selected':'') + '>子菜单容器 menu</option>';
  }
  html += '</select>'
    + '<button class="btn btn-danger btn-sm" onclick="removeItem(' + idx + (isSub?',true':'') + ')">删除</button>'
    + '</div>';

  // 类型相关字段（严格按官方字段名）
  if (type === 'send_message') {
    html += '<div style="margin-bottom:6px;"><label class="form-label">send_message（点击后填入输入框的内容）</label>'
      + '<input type="text" class="form-input" placeholder="例如: /help" value="' + escapeAttr(item.send_message||'') + '" onchange="updateField(' + idx + ',\'send_message\',this.value' + (isSub?',true':'') + ')"></div>';
  } else if (type === 'link') {
    html += '<div style="margin-bottom:6px;"><label class="form-label">link（跳转 URL，必须 https:// 开头）</label>'
      + '<input type="text" class="form-input" placeholder="https://example.com" value="' + escapeAttr(item.link||'') + '" onchange="updateField(' + idx + ',\'link\',this.value' + (isSub?',true':'') + ')"></div>';
  } else if (type === 'switch') {
    // switch 类型: {switch_id, default}
    var sw = item.switch || {};
    html += '<div style="margin-bottom:6px; padding:8px; background:var(--bg); border-radius:4px;">'
      + '<label class="form-label">switch.switch_id（开关唯一标识，切换后消息 ext 字段携带此标识）</label>'
      + '<input type="text" class="form-input" placeholder="例如: search" value="' + escapeAttr(sw.switch_id||'') + '" onchange="updateSwitchField(' + idx + ',\'switch_id\',this.value)">'
      + '<label class="form-label" style="margin-top:6px;">switch.default（初始状态）</label>'
      + '<select class="form-input" onchange="updateSwitchField(' + idx + ',\'default\',this.value===\'true\')">'
      + '<option value="false"' + (sw.default!==true?' selected':'') + '>关闭 (false)</option>'
      + '<option value="true"' + (sw.default===true?' selected':'') + '>打开 (true)</option>'
      + '</select></div>';
  } else if (type === 'menu') {
    // menu 类型: sub_menu_items 数组
    var subs = Array.isArray(item.sub_menu_items) ? item.sub_menu_items : [];
    html += '<div style="margin-bottom:6px; padding:8px; background:var(--bg); border-radius:4px;">'
      + '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">'
      + '<label class="form-label" style="margin:0;">sub_menu_items（子菜单，最多 5 个，仅支持 send_message / link）</label>'
      + '<button class="btn btn-sm btn-success" onclick="addSubItem(' + idx + ')" ' + (subs.length>=5?'disabled':'') + '>+ 添加子按钮</button>'
      + '</div>';
    if (subs.length === 0) {
      html += '<div class="text-muted" style="font-size:12px; padding:6px;">暂无子菜单按钮</div>';
    } else {
      subs.forEach(function(sub, sidx){
        html += renderMenuItem(sub, sidx, true)
          .replace('<div class="card" style="margin-bottom:10px;', '<div class="card" style="margin-bottom:6px; margin-left:12px;')
          .replace('onclick="removeItem(' + sidx + ',true)"', 'onclick="removeSubItem(' + idx + ',' + sidx + ')"')
          .replace(/onchange="updateField\(' + sidx + ',([^,]+),this.value,true\)"/g, 'onchange="updateSubField(' + idx + ',' + sidx + ',$1,this.value)"');
      });
    }
    html += '</div>';
  }
  html += '</div>';
  return html;
}

// ==================== 添加/删除按钮 ====================
function addMenuItem() {
  if (menuItems.length >= 10) { alert('一级按钮最多 10 个'); return; }
  menuItems.push({name:'新按钮', type:'send_message', send_message:''});
  renderEditor();
  syncToJson();
}

function removeItem(idx) {
  if (!confirm('确定删除按钮 #' + (idx+1) + ' 吗？')) return;
  menuItems.splice(idx, 1);
  renderEditor();
  syncToJson();
}

function addSubItem(parentIdx) {
  var subs = menuItems[parentIdx].sub_menu_items || [];
  if (subs.length >= 5) { alert('子菜单按钮最多 5 个'); return; }
  if (!Array.isArray(menuItems[parentIdx].sub_menu_items)) {
    menuItems[parentIdx].sub_menu_items = [];
  }
  menuItems[parentIdx].sub_menu_items.push({name:'子按钮', type:'send_message', send_message:''});
  renderEditor();
  syncToJson();
}

function removeSubItem(parentIdx, subIdx) {
  if (!confirm('确定删除子按钮 #' + (subIdx+1) + ' 吗？')) return;
  menuItems[parentIdx].sub_menu_items.splice(subIdx, 1);
  renderEditor();
  syncToJson();
}

// ==================== 字段更新 ====================
function updateField(idx, field, value, isSub) {
  if (!menuItems[idx]) return;
  menuItems[idx][field] = value;
  // 切换 type 时清理无关字段，避免脏数据
  if (field === 'type') {
    var newType = value;
    var keepFields = ['name', 'type'];
    var allowed = {send_message:['send_message'], link:['link'], switch:['switch'], menu:['sub_menu_items']};
    var allowedExtra = allowed[newType] || [];
    Object.keys(menuItems[idx]).forEach(function(k){
      if (keepFields.indexOf(k) === -1 && allowedExtra.indexOf(k) === -1) {
        delete menuItems[idx][k];
      }
    });
    // 初始化默认值
    if (newType === 'send_message') menuItems[idx].send_message = menuItems[idx].send_message || '';
    else if (newType === 'link') menuItems[idx].link = menuItems[idx].link || '';
    else if (newType === 'switch') menuItems[idx].switch = menuItems[idx].switch || {switch_id:'', default:false};
    else if (newType === 'menu') menuItems[idx].sub_menu_items = menuItems[idx].sub_menu_items || [];
  }
  syncToJson();
}

function updateSubField(parentIdx, subIdx, field, value) {
  var subs = menuItems[parentIdx] && menuItems[parentIdx].sub_menu_items;
  if (!subs || !subs[subIdx]) return;
  subs[subIdx][field] = value;
  if (field === 'type') {
    var newType = value;
    if (newType === 'send_message') { delete subs[subIdx].link; subs[subIdx].send_message = subs[subIdx].send_message || ''; }
    else if (newType === 'link') { delete subs[subIdx].send_message; subs[subIdx].link = subs[subIdx].link || ''; }
  }
  syncToJson();
}

function updateSwitchField(idx, field, value) {
  if (!menuItems[idx]) return;
  if (!menuItems[idx].switch) menuItems[idx].switch = {};
  menuItems[idx].switch[field] = value;
  syncToJson();
}

// ==================== JSON 同步 ====================
function syncToJson() {
  var payload = {menu: {items: menuItems}};
  document.getElementById('rawJson').value = JSON.stringify(payload, null, 2);
}

function syncFromJson() {
  var raw = document.getElementById('rawJson').value.trim();
  if (!raw) return;
  try {
    var data = JSON.parse(raw);
    // 兼容 {menu:{items:[]}} 或 {items:[]} 两种格式
    menuItems = (data.menu && data.menu.items) ? data.menu.items : (data.items || []);
    renderEditor();
  } catch(e) { alert('JSON 格式错误: ' + e.message); }
}

// ==================== 保存菜单 (PUT /v2/menu) ====================
function saveMenu() {
  var appid = document.getElementById('botSelect').value;
  if (!appid) { alert('请选择机器人'); return; }
  syncToJson();
  var rawJson = document.getElementById('rawJson').value.trim();
  if (!rawJson) { alert('菜单数据为空'); return; }
  try { JSON.parse(rawJson); }
  catch(e) { alert('JSON 格式错误: ' + e.message); return; }
  if (!confirm('确定保存菜单到机器人 ' + appid + ' 吗？\n此操作会覆盖原有菜单配置')) return;
  apiCall('menu_set', {appid:appid, menu_data: rawJson}, function(res){
    if (res.success) {
      alert('保存成功' + (res.data && res.data.version ? '\n新版本号: v' + res.data.version : ''));
      loadMenu(); // 重新加载以获取新版本号
    } else {
      alert('保存失败: ' + (res.message||'') + (res.raw ? '\n原始响应: ' + res.raw : ''));
    }
  });
}

// ==================== 删除菜单 (DELETE /v2/menu) ====================
function deleteMenu() {
  var appid = document.getElementById('botSelect').value;
  if (!appid) { alert('请选择机器人'); return; }
  if (!confirm('确定删除机器人 ' + appid + ' 的所有自定义菜单吗？\n此操作不可恢复')) return;
  apiCall('menu_delete', {appid:appid}, function(res){
    if (res.success) {
      alert('删除成功');
      menuItems = [];
      document.getElementById('menuVersion').textContent = '已删除';
      renderEditor();
      document.getElementById('rawJson').value = '';
    } else alert('删除失败: ' + (res.message||'') + (res.raw ? '\n原始响应: ' + res.raw : ''));
  });
}

// ==================== 工具函数 ====================
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
