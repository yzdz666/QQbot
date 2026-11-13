<?php
/**
 * 管理后台 - 群成员管理
 * 对应 20260903 官方新增的群成员管理接口：
 *   获取群成员列表 / 获取群成员信息 / 群成员批量移除 / 群黑名单查询 / 群黑名单操作
 */
$pageTitle = '群成员管理';
require_once('header.php');

$bots = getBots();
?>
<style>
.gm-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
.gm-toolbar .form-group { margin-bottom:0; flex:1; min-width:180px; }
.gm-tabs { display:flex; border-bottom:1px solid var(--border); margin-bottom:0; }
.gm-tab { padding:10px 20px; cursor:pointer; border-bottom:2px solid transparent; color:var(--text-muted); font-size:14px; user-select:none; }
.gm-tab:hover { color:var(--text); }
.gm-tab.active { color:var(--text); border-bottom-color:var(--primary); font-weight:600; }
.gm-actionbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; padding:14px 20px; border-bottom:1px solid var(--border); }
.gm-actionbar .spacer { flex:1; }
.gm-openid { font-family:Consolas,Monaco,monospace; font-size:12px; color:var(--text-muted); cursor:pointer; }
.gm-openid:hover { color:var(--primary); text-decoration:underline; }
.gm-pager { display:flex; align-items:center; gap:10px; padding:14px 20px; border-top:1px solid var(--border); }
.gm-pager .info { color:var(--text-muted); font-size:13px; flex:1; }
.gm-detail-table td { padding:8px 12px; font-size:13px; border-bottom:1px solid var(--border); }
.gm-detail-table td:first-child { color:var(--text-muted); white-space:nowrap; width:130px; }
.gm-detail-table td:last-child { font-family:Consolas,Monaco,monospace; word-break:break-all; }
.gm-copy-tip { position:fixed; top:60px; right:24px; background:var(--primary); color:#fff; padding:8px 16px; border-radius:6px; font-size:13px; z-index:9999; opacity:0; transition:opacity .25s; pointer-events:none; }
.gm-copy-tip.show { opacity:1; }
.gm-check-cell { width:36px; }
#blacklistTable td:nth-child(3) { word-break:break-all; }
.gm-role-owner { background:#fff3e0; color:#e65100; }
.gm-role-admin { background:#e3f2fd; color:#1565c0; }
.gm-role-member { background:#f5f5f5; color:#616161; }
.gm-tip { font-size:12px; color:var(--text-muted); line-height:1.7; }

/* ===== 表格显示控制（class 方式，兼容移动端卡片化） ===== */
#memberTable, #blacklistTable, #joinTable { display: none; }
#memberTable.gm-table-show, #blacklistTable.gm-table-show, #joinTable.gm-table-show { display: table; }
.gm-selectall-m { display: none; }
.gm-join-name { font-weight: 600; }
.gm-join-verify { font-size: 12px; color: var(--text-secondary); max-width: 260px; line-height: 1.5; }
.gm-join-risk { color: var(--danger); font-size: 12px; }

/* ===== 手机端：表格卡片化，避免列挤压与横向滚动 ===== */
@media (max-width: 768px) {
  .gm-toolbar .form-group { flex: 0 0 100% !important; min-width: 0; }
  .gm-actionbar { padding: 10px 12px; }
  .gm-actionbar .spacer { display: none; }
  .gm-selectall-m { display: inline-flex; align-items: center; gap: 4px; font-size: 13px; cursor: pointer; user-select: none; }
  .gm-openid { word-break: break-all; }

  #memberTable.gm-table-show, #blacklistTable.gm-table-show, #joinTable.gm-table-show { display: block; }
  #memberTable thead, #blacklistTable thead, #joinTable thead { display: none; }
  #memberTable tbody, #blacklistTable tbody, #joinTable tbody { display: block; }
  #memberTable tr, #blacklistTable tr {
    display: flex; flex-wrap: wrap; align-items: center;
    border: 1px solid var(--border); border-radius: 10px;
    margin: 10px 12px; padding: 10px 12px;
    background: var(--bg);
  }
  #memberTable td, #blacklistTable td {
    display: block; border: none; padding: 2px 0; text-align: left; width: auto;
  }

  /* 成员卡片：首行 = 复选框 + 昵称 + 角色/类型徽章，其后 openid / 时间 / 操作 */
  #memberTable td:nth-child(1) { order: 1; }
  #memberTable td:nth-child(2) { order: 2; margin-left: 6px; font-weight: 600; max-width: 52%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  #memberTable td:nth-child(4) { order: 3; margin-left: auto; flex-shrink: 0; }
  #memberTable td:nth-child(5) { order: 4; flex-shrink: 0; }
  #memberTable td:nth-child(3) { order: 5; flex: 0 0 100%; }
  #memberTable td:nth-child(6) { order: 6; flex: 0 0 100%; font-size: 12px; color: var(--text-muted); }
  #memberTable td:nth-child(6)::before { content: '入群：'; }
  #memberTable td:nth-child(7) { order: 7; flex: 0 0 100%; margin-top: 6px; }

  /* 黑名单卡片：首行 = 昵称 + 类型徽章，其后 openid / union / 拉黑时间 / 操作 */
  #blacklistTable td:nth-child(1) { order: 1; font-weight: 600; max-width: 65%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  #blacklistTable td:nth-child(5) { order: 2; margin-left: auto; }
  #blacklistTable td:nth-child(2) { order: 3; flex: 0 0 100%; }
  #blacklistTable td:nth-child(3) { order: 4; flex: 0 0 100%; font-size: 11px; color: var(--text-muted); }
  #blacklistTable td:nth-child(3)::before { content: 'union：'; }
  #blacklistTable td:nth-child(4) { order: 5; flex: 0 0 100%; font-size: 12px; color: var(--text-muted); }
  #blacklistTable td:nth-child(4)::before { content: '拉黑：'; }
  /* 入群申请卡片 */
  #joinTable tr {
    display: flex; flex-wrap: wrap; align-items: center;
    border: 1px solid var(--border); border-radius: 10px;
    margin: 10px 12px; padding: 10px 12px; background: var(--bg);
  }
  #joinTable td { display: block; border: none; padding: 2px 0; text-align: left; width: auto; }
  #joinTable td:nth-child(1) { order: 1; font-weight: 600; max-width: 60%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  #joinTable td:nth-child(4) { order: 2; margin-left: auto; }
  #joinTable td:nth-child(2) { order: 3; flex: 0 0 100%; font-size: 11px; color: var(--text-muted); word-break: break-all; }
  #joinTable td:nth-child(3) { order: 4; flex: 0 0 100%; font-size: 11px; color: var(--text-muted); }
  #joinTable td:nth-child(5) { order: 5; flex: 0 0 100%; }
  #joinTable td:nth-child(6) { order: 6; flex: 0 0 100%; margin-top: 6px; }
  #blacklistTable td:nth-child(6) { order: 6; flex: 0 0 100%; margin-top: 6px; }

  .gm-pager { flex-wrap: wrap; padding: 10px 12px; }
  .gm-pager .info { flex: 0 0 100%; margin-bottom: 6px; }
  .gm-detail-table td:first-child { width: 96px; }
}
</style>

<div class="page-header">
  <h2>群成员管理</h2>
  <div class="actions">
    <button class="btn btn-outline" onclick="toggleRaw()">显示/隐藏原始响应</button>
  </div>
</div>

<!-- 顶部筛选栏 -->
<div class="card">
  <div class="card-body">
    <div class="gm-toolbar">
      <div class="form-group">
        <label class="form-label">机器人</label>
        <select id="botSelect" class="form-control" onchange="onBotChange()">
          <option value="">-- 选择机器人 --</option>
          <?php foreach ($bots as $bot): ?>
          <option value="<?= htmlspecialchars($bot['appid']) ?>"><?= htmlspecialchars($bot['nickname'] ?: $bot['appid']) ?><?= $bot['env'] === '沙箱' ? '（沙箱）' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">群聊</label>
        <select id="groupSelect" class="form-control" onchange="onGroupChange()" disabled>
          <option value="">-- 请先选择机器人 --</option>
        </select>
      </div>
      <div class="form-group" style="flex:0 0 220px;">
        <label class="form-label">群 OpenID（可选手动输入）</label>
        <input type="text" id="groupOpenidInput" class="form-control" placeholder="group_openid">
      </div>
      <div class="form-group" style="flex:0 0 auto;">
        <button class="btn btn-primary" onclick="loadMembersFirst()">查询成员</button>
      </div>
    </div>
    <div class="form-hint" style="margin-top:10px;">
      官方 20260903 新增的群成员管理接口：获取群成员列表、获取群成员信息、群成员批量移除、群黑名单查询、群黑名单操作。
      该能力处于官方内邀阶段（错误码 11253 表示未获权限），移除成员需机器人拥有群管理员身份。
    </div>
  </div>
</div>

<!-- 成员/黑名单 主区域 -->
<div class="card" style="margin-top:16px;">
  <div class="gm-tabs">
    <div class="gm-tab active" id="tabMembers" onclick="switchTab('members')">成员列表</div>
    <div class="gm-tab" id="tabBlacklist" onclick="switchTab('blacklist')">群黑名单</div>
    <div class="gm-tab" id="tabJoins" onclick="switchTab('joins')">入群申请</div>
  </div>

  <!-- ==================== 成员列表 ==================== -->
  <div id="panelMembers">
    <div class="gm-actionbar">
      <label class="gm-selectall-m"><input type="checkbox" onchange="toggleSelectAll(this)"> 全选本页</label>
      <button class="btn btn-outline btn-sm" onclick="loadMembersFirst()">刷新本页</button>
      <button class="btn btn-outline btn-sm" onclick="loadAllMembers()">加载全部成员</button>
      <span class="spacer"></span>
      <label style="font-size:13px; display:flex; align-items:center; gap:4px; cursor:pointer;">
        <input type="checkbox" id="chkRemoveBlk"> 移除时同时加入黑名单
      </label>
      <button class="btn btn-danger btn-sm" onclick="batchRemoveSelected()">批量移除选中成员</button>
      <button class="btn btn-danger btn-sm" onclick="showRemoveModal()">手动移除成员</button>
    </div>
    <div id="memberAlert"></div>
    <div class="card-body no-padding" style="overflow-x:auto;">
      <table class="table" id="memberTable">
        <thead>
          <tr>
            <th class="gm-check-cell"><input type="checkbox" id="chkAll" onchange="toggleSelectAll(this)"></th>
            <th>昵称</th>
            <th>member_openid</th>
            <th>角色</th>
            <th>类型</th>
            <th>入群时间</th>
            <th class="text-right">操作</th>
          </tr>
        </thead>
        <tbody id="memberTbody"></tbody>
      </table>
      <div id="memberEmpty" class="empty-state" style="display:none;">暂无数据，请选择群后点击「查询成员」</div>
    </div>
    <div class="gm-pager" id="memberPager" style="display:none;">
      <span class="info" id="memberPageInfo"></span>
      <button class="btn btn-outline btn-sm" id="btnMemberPrev" onclick="memberPrevPage()">上一页</button>
      <button class="btn btn-outline btn-sm" id="btnMemberNext" onclick="memberNextPage()">下一页</button>
    </div>
  </div>

  <!-- ==================== 群黑名单 ==================== -->
  <div id="panelBlacklist" style="display:none;">
    <div class="gm-actionbar">
      <button class="btn btn-outline btn-sm" onclick="loadBlacklistFirst()">刷新黑名单</button>
      <span class="spacer"></span>
      <button class="btn btn-primary btn-sm" onclick="showBlacklistAddModal()">加入黑名单</button>
    </div>
    <div id="blacklistAlert"></div>
    <div class="card-body no-padding" style="overflow-x:auto;">
      <table class="table" id="blacklistTable">
        <thead>
          <tr>
            <th>昵称</th>
            <th>member_openid</th>
            <th>union_openid</th>
            <th>拉黑时间</th>
            <th>类型</th>
            <th class="text-right">操作</th>
          </tr>
        </thead>
        <tbody id="blacklistTbody"></tbody>
      </table>
      <div id="blacklistEmpty" class="empty-state" style="display:none;">暂无黑名单数据，请选择群后点击「刷新黑名单」</div>
    </div>
    <div class="gm-pager" id="blacklistPager" style="display:none;">
      <span class="info" id="blacklistPageInfo"></span>
      <button class="btn btn-outline btn-sm" id="btnBlkPrev" onclick="blacklistPrevPage()">上一页</button>
      <button class="btn btn-outline btn-sm" id="btnBlkNext" onclick="blacklistNextPage()">下一页</button>
    </div>
    <div class="card-body" style="border-top:1px solid var(--border);">
      <div class="gm-tip">
        官方规则：只有目标用户<b>不在群中</b>时才能加入群黑名单。如需「移除并拉黑」，请在成员列表中勾选成员后，勾选「移除时同时加入黑名单」再批量移除。
      </div>
    </div>
  </div>

  <!-- ==================== 入群申请（官方 20260810） ==================== -->
  <div id="panelJoins" style="display:none;">
    <div class="gm-actionbar">
      <button class="btn btn-outline btn-sm" onclick="loadJoinRequests(true)">刷新申请</button>
      <span class="spacer"></span>
      <span class="gm-tip">机器人需为群管理员 · 该接口处于官方内邀阶段</span>
    </div>
    <div id="joinAlert"></div>
    <div class="card-body no-padding" style="overflow-x:auto;">
      <table class="table" id="joinTable">
        <thead>
          <tr>
            <th>申请人</th>
            <th>member_openid</th>
            <th>申请时间</th>
            <th>来源</th>
            <th>验证信息</th>
            <th class="text-right">操作</th>
          </tr>
        </thead>
        <tbody id="joinTbody"></tbody>
      </table>
      <div id="joinEmpty" class="empty-state" style="display:none;">暂无入群申请，请选择群后点击「刷新申请」</div>
    </div>
    <div class="gm-pager" id="joinPager" style="display:none;">
      <span class="info" id="joinPageInfo"></span>
      <button class="btn btn-outline btn-sm" id="btnJoinMore" onclick="loadJoinRequests(false)">加载更多</button>
    </div>
    <div class="card-body" style="border-top:1px solid var(--border);">
      <div class="gm-tip">通过/拒绝会直接作用于真实入群申请；拒绝时可填写理由并选择同时加入群黑名单。审批结果会写入后台操作记录。</div>
    </div>
  </div>
</div>

<!-- 原始响应 -->
<div class="card" id="rawCard" style="display:none; margin-top:16px;">
  <div class="card-header"><h3>原始响应</h3></div>
  <div class="card-body">
    <pre id="rawOutput" style="font-size:12px; white-space:pre-wrap; word-break:break-all; background:var(--bg); padding:12px; border-radius:6px; max-height:300px; overflow:auto;"></pre>
  </div>
</div>

<!-- ==================== 成员详情模态框 ==================== -->
<div class="modal-overlay" id="memberDetailModal" style="display:none;">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">成员详情</div>
    <div class="modal-body" id="memberDetailBody"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('memberDetailModal')">关闭</button>
    </div>
  </div>
</div>

<!-- ==================== 手动移除成员模态框 ==================== -->
<div class="modal-overlay" id="removeModal" style="display:none;">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">批量移除群成员</div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">member_openid 列表</label>
        <textarea id="removeOpenids" class="form-control" rows="5" placeholder="每行一个 member_openid，单次最多 20 个"></textarea>
        <div class="form-hint">也可粘贴逗号分隔的 openid</div>
      </div>
      <label style="font-size:13px; display:flex; align-items:center; gap:6px; cursor:pointer;">
        <input type="checkbox" id="removeModalBlk"> 同时加入群黑名单
      </label>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('removeModal')">取消</button>
      <button class="btn btn-danger" onclick="doManualRemove()">确认移除</button>
    </div>
  </div>
</div>

<!-- ==================== 加入黑名单模态框 ==================== -->
<div class="modal-overlay" id="blacklistAddModal" style="display:none;">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">加入群黑名单</div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">member_openid 列表</label>
        <textarea id="blkAddOpenids" class="form-control" rows="5" placeholder="每行一个 member_openid，单次最多 20 个"></textarea>
        <div class="form-hint">注意：只有目标用户不在群中时才能加入黑名单</div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('blacklistAddModal')">取消</button>
      <button class="btn btn-primary" onclick="doBlacklistAdd()">确认拉黑</button>
    </div>
  </div>
</div>

<!-- ==================== 拒绝入群申请模态框 ==================== -->
<div class="modal-overlay" id="joinDeclineModal" style="display:none;">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">拒绝入群申请</div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">申请人</label>
        <div id="joinDeclineName" style="font-size:13px; padding:6px 0; word-break:break-all;"></div>
      </div>
      <div class="form-group">
        <label class="form-label">拒绝理由（可留空）</label>
        <textarea id="joinDeclineReason" class="form-control" rows="3" placeholder="例如：群满 / 信息不完整" maxlength="200"></textarea>
      </div>
      <label style="font-size:13px; display:flex; align-items:center; gap:6px; cursor:pointer;">
        <input type="checkbox" id="joinDeclineBlacklist"> 同时加入群黑名单
      </label>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('joinDeclineModal')">取消</button>
      <button class="btn btn-danger" onclick="doDeclineJoin()">确认拒绝</button>
    </div>
  </div>
</div>

<div class="gm-copy-tip" id="copyTip">已复制</div>

<script>
// ==================== 认证 token ====================
var authToken = '';
(function() {
    var urlParams = new URLSearchParams(window.location.search);
    authToken = urlParams.get('token') || '';
    if (!authToken) {
        var match = document.cookie.match(/admin_token=([^;]+)/);
        if (match) authToken = match[1];
    }
})();

// ==================== 通用 AJAX ====================
function apiCall(url, data, callback, method) {
    var xhr = new XMLHttpRequest();
    if (authToken && url.indexOf('token=') === -1) {
        url += (url.indexOf('?') > -1 ? '&' : '?') + 'token=' + encodeURIComponent(authToken);
    }
    xhr.open(method || 'POST', url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = 30000;
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            var res;
            try { res = JSON.parse(xhr.responseText); }
            catch (e) { res = { success: false, msg: '响应解析失败: ' + xhr.responseText.substring(0, 200) }; }
            callback(res);
        }
    };
    xhr.ontimeout = function() { callback({ success: false, msg: '请求超时，请检查网络连接' }); };
    xhr.onerror = function() { callback({ success: false, msg: '网络错误，请检查服务器状态' }); };
    var params = [];
    for (var key in data) {
        if (data.hasOwnProperty(key)) {
            params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
        }
    }
    xhr.send(params.join('&'));
}

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// ISO 时间转简短显示：2026-02-02T10:30:00+08:00 -> 2026-02-02 10:30
function fmtTime(s) {
    if (!s) return '-';
    var m = String(s).match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/);
    return m ? m[1] + ' ' + m[2] : String(s);
}

// ==================== 状态 ====================
var groupsCache = [];
var memberCursorStack = [];   // 游标栈: [''] 表示第一页
var memberNextCursor = '';
var memberAll = [];           // 当前页成员数据
var blkCursorStack = [];
var blkNextCursor = '';
var blkUsers = [];

// ==================== 工具 ====================
function currentBotId() { return document.getElementById('botSelect').value; }

function currentGroupId() {
    var manual = document.getElementById('groupOpenidInput').value.trim();
    if (manual) return manual;
    return document.getElementById('groupSelect').value || '';
}

function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(showCopyTip, function() { fallbackCopy(text); });
    } else {
        fallbackCopy(text);
    }
}
function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    showCopyTip();
}
function showCopyTip() {
    var tip = document.getElementById('copyTip');
    tip.classList.add('show');
    setTimeout(function() { tip.classList.remove('show'); }, 1200);
}

function toggleRaw() {
    var card = document.getElementById('rawCard');
    card.style.display = card.style.display === 'none' ? 'block' : 'none';
}
function showRaw(title, obj) {
    var out = document.getElementById('rawOutput');
    var text = '';
    try { text = JSON.stringify(obj, null, 2); } catch (e) { text = String(obj); }
    out.textContent = '[' + new Date().toLocaleTimeString() + '] ' + title + '\n' + text + '\n\n' + out.textContent;
    document.getElementById('rawCard').style.display = 'block';
}

function showAlert(containerId, type, msg) {
    document.getElementById(containerId).innerHTML =
        '<div style="padding:12px 20px;"><div class="alert alert-' + type + '">' + escapeHtml(msg) + '</div></div>';
}
function clearAlert(containerId) { document.getElementById(containerId).innerHTML = ''; }

function roleBadge(role) {
    var map = { owner: ['群主', 'gm-role-owner'], admin: ['管理员', 'gm-role-admin'], member: ['成员', 'gm-role-member'] };
    var item = map[role] || [role || '-', 'badge-secondary'];
    return '<span class="badge ' + item[1] + '">' + escapeHtml(item[0]) + '</span>';
}

function closeModal(id) { document.getElementById(id).style.display = 'none'; }
// 点击遮罩关闭
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === m) m.style.display = 'none'; });
});

// ==================== Tab 切换 ====================
function switchTab(tab) {
    var tabs = ['members', 'blacklist', 'joins'];
    tabs.forEach(function (t) {
        var cap = t.charAt(0).toUpperCase() + t.slice(1);
        var tabEl = document.getElementById('tab' + cap);
        var panelEl = document.getElementById('panel' + cap);
        if (tabEl) tabEl.classList.toggle('active', tab === t);
        if (panelEl) panelEl.style.display = tab === t ? 'block' : 'none';
    });
    if (tab === 'joins' && !joinLoading && (!joinLoaded || joinItems.length === 0) && currentGroupId()) loadJoinRequests(true);
}

// ==================== 入群申请 ====================
var joinItems = [];
var joinCursor = '';
var joinLoaded = false;
var joinLoading = false;

function loadJoinRequests(reset) {
    if (!currentBotId()) {
        showAlert('joinAlert', 'danger', '请先选择机器人');
        return;
    }
    var gid = currentGroupId();
    if (!gid) {
        showAlert('joinAlert', 'danger', '请选择群或输入群 OpenID');
        return;
    }
    if (joinLoading) return;
    if (reset) {
        joinItems = [];
        joinCursor = '';
        joinLoaded = false;
        document.getElementById('joinTbody').innerHTML = '';
    }
    joinLoading = true;
    clearAlert('joinAlert');
    var empty = document.getElementById('joinEmpty');
    empty.style.display = 'block';
    empty.textContent = '加载中...';
    apiCall('api/group_member_api.php?action=join_requests', {
        appid: currentBotId(),
        group_openid: gid,
        cursor: joinCursor,
        limit: 20
    }, function (res) {
        joinLoading = false;
        showRaw('获取入群申请列表 (cursor=' + (joinCursor || '') + ')', res);
        if (!res.success) {
            empty.textContent = '加载失败';
            showAlert('joinAlert', 'danger', res.msg || res.message || '获取入群申请失败');
            return;
        }
        var data = res.data || {};
        var list = data.list || [];
        joinCursor = data.next_cursor || '';
        joinLoaded = true;
        joinItems = joinItems.concat(list);
        renderJoinRows();
    });
}

function joinSourceText(item) {
    if (item.apply_source === 'invited') return '被邀请';
    if (item.apply_source === 'self_apply') return '主动申请';
    return item.apply_source || '-';
}

function joinVerifyHtml(item) {
    var v = item.verify_info || {};
    var parts = [];
    if (v.verify_message) parts.push('验证消息：' + escapeHtml(v.verify_message));
    if (Array.isArray(v.review_qa_list) && v.review_qa_list.length) {
        v.review_qa_list.forEach(function (qa) {
            parts.push('问答：' + escapeHtml(qa.question || '') + ' → ' + escapeHtml(qa.answer || ''));
        });
    }
    if (!parts.length) return '<span style="color:var(--text-muted);">—</span>';
    return '<div class="gm-join-verify">' + parts.join('<br>') + '</div>';
}

function renderJoinRows() {
    var tbody = document.getElementById('joinTbody');
    var empty = document.getElementById('joinEmpty');
    var pager = document.getElementById('joinPager');
    document.getElementById('joinTable').classList.add('gm-table-show');
    if (!joinItems.length) {
        tbody.innerHTML = '';
        empty.style.display = 'block';
        empty.textContent = '暂无入群申请';
        pager.style.display = 'none';
        return;
    }
    empty.style.display = 'none';
    var html = '';
    joinItems.forEach(function (item, idx) {
        var risk = item.risk_tips ? '<div class="gm-join-risk">' + escapeHtml(item.risk_tips) + '</div>' : '';
        html += '<tr data-idx="' + idx + '">'
             +   '<td><span class="gm-join-name">' + escapeHtml(item.username || '(未命名)') + '</span>' + (item.bot ? ' <span class="badge badge-warn">机器人</span>' : '') + risk + '</td>'
             +   '<td><span class="gm-openid" onclick="copyText(this.textContent)" title="点击复制">' + escapeHtml(item.member_openid || '') + '</span></td>'
             +   '<td style="font-size:12px;color:var(--text-muted);">' + escapeHtml(item.apply_at || '') + '</td>'
             +   '<td>' + escapeHtml(joinSourceText(item)) + '</td>'
             +   '<td>' + joinVerifyHtml(item) + '</td>'
             +   '<td class="text-right" style="white-space:nowrap;">'
             +     '<button class="btn btn-primary btn-sm" onclick="approveJoin(' + idx + ')">通过</button> '
             +     '<button class="btn btn-danger btn-sm" onclick="showDeclineJoin(' + idx + ')">拒绝</button>'
             +   '</td>'
             + '</tr>';
    });
    tbody.innerHTML = html;
    document.getElementById('joinPageInfo').textContent = '已加载 ' + joinItems.length + ' 条申请';
    pager.style.display = joinCursor ? 'flex' : 'none';
}

function approveJoin(idx) {
    var item = joinItems[idx];
    if (!item) return;
    if (!confirm('通过「' + (item.username || item.member_openid) + '」的入群申请？')) return;
    apiCall('api/group_member_api.php?action=join_approve', {
        appid: currentBotId(),
        group_openid: currentGroupId(),
        member_openid: item.member_openid,
        join_request_id: item.join_request_id || '',
        op: 'approve'
    }, function (res) {
        showRaw('审批入群申请（通过）', res);
        if (!res.success) { showAlert('joinAlert', 'danger', res.msg || res.message || '审批失败'); return; }
        showAlert('joinAlert', 'success', res.msg || '已通过入群申请');
        joinItems.splice(idx, 1);
        renderJoinRows();
    });
}

var joinDeclineIdx = -1;
function showDeclineJoin(idx) {
    var item = joinItems[idx];
    if (!item) return;
    joinDeclineIdx = idx;
    document.getElementById('joinDeclineName').textContent = (item.username || item.member_openid) + '  ·  ' + (item.member_openid || '');
    document.getElementById('joinDeclineReason').value = '';
    document.getElementById('joinDeclineBlacklist').checked = false;
    document.getElementById('joinDeclineModal').style.display = 'flex';
}

function doDeclineJoin() {
    var item = joinItems[joinDeclineIdx];
    if (!item) return;
    var reason = document.getElementById('joinDeclineReason').value.trim();
    var blacklist = document.getElementById('joinDeclineBlacklist').checked;
    closeModal('joinDeclineModal');
    apiCall('api/group_member_api.php?action=join_approve', {
        appid: currentBotId(),
        group_openid: currentGroupId(),
        member_openid: item.member_openid,
        join_request_id: item.join_request_id || '',
        op: 'decline',
        reason: reason,
        blacklist: blacklist ? 1 : 0
    }, function (res) {
        showRaw('审批入群申请（拒绝）', res);
        if (!res.success) { showAlert('joinAlert', 'danger', res.msg || res.message || '审批失败'); return; }
        showAlert('joinAlert', 'success', res.msg || '已拒绝入群申请');
        joinItems.splice(joinDeclineIdx, 1);
        joinDeclineIdx = -1;
        renderJoinRows();
    });
}

// ==================== 群列表加载 ====================
function onBotChange() {
    var appid = currentBotId();
    var sel = document.getElementById('groupSelect');
    sel.innerHTML = '<option value="">加载中...</option>';
    sel.disabled = true;
    document.getElementById('groupOpenidInput').value = '';
    if (!appid) {
        sel.innerHTML = '<option value="">-- 请先选择机器人 --</option>';
        return;
    }
    apiCall('api/group_member_api.php?action=get_groups', { appid: appid }, function(res) {
        if (!res.success) {
            sel.innerHTML = '<option value="">-- 加载失败 --</option>';
            showAlert('memberAlert', 'danger', '加载群列表失败: ' + (res.msg || res.message || '未知错误'));
            return;
        }
        clearAlert('memberAlert');
        groupsCache = (res.data && res.data.groups) || [];
        var html = '<option value="">-- 选择群（共 ' + groupsCache.length + ' 个） --</option>';
        for (var i = 0; i < groupsCache.length; i++) {
            var g = groupsCache[i];
            var label = g.name || g.group_openid;
            if (g.dismissed) {
                label = '【已解散】' + label;
            } else if (g.member_num > 0) {
                label += ' (' + g.member_num + '人)';
            }
            html += '<option value="' + escapeHtml(g.group_openid) + '">' + escapeHtml(label) + '</option>';
        }
        if (groupsCache.length === 0) {
            html = '<option value="">-- 暂无群记录，请手动输入群 OpenID --</option>';
        }
        sel.innerHTML = html;
        sel.disabled = false;
    });
}

function onGroupChange() {
    // 选择群后清空手动输入，保持单一来源
    document.getElementById('groupOpenidInput').value = '';
    // 切换群后入群申请缓存失效
    joinItems = [];
    joinCursor = '';
    joinLoaded = false;
    document.getElementById('joinTbody').innerHTML = '';
    document.getElementById('joinEmpty').style.display = 'none';
    document.getElementById('joinPager').style.display = 'none';
    if (document.getElementById('panelJoins').style.display === 'block') loadJoinRequests(true);
}

// ==================== 成员列表 ====================
function requireGroup() {
    var gid = currentGroupId();
    if (!currentBotId()) { showAlert('memberAlert', 'danger', '请先选择机器人'); return null; }
    if (!gid) { showAlert('memberAlert', 'danger', '请选择群或输入群 OpenID'); return null; }
    return gid;
}

function loadMembersFirst() {
    var gid = requireGroup(); if (!gid) return;
    memberCursorStack = [''];
    loadMembers('');
}

function loadMembers(cursor) {
    var gid = requireGroup(); if (!gid) return;
    clearAlert('memberAlert');
    document.getElementById('memberEmpty').style.display = 'none';
    document.getElementById('memberEmpty').textContent = '加载中...';
    document.getElementById('memberEmpty').style.display = 'block';
    apiCall('api/group_member_api.php?action=get_members', {
        appid: currentBotId(),
        group_openid: gid,
        cursor: cursor || ''
    }, function(res) {
        showRaw('获取群成员列表 (cursor=' + (cursor || '') + ')', res);
        if (!res.success) {
            document.getElementById('memberEmpty').textContent = '加载失败';
            showAlert('memberAlert', 'danger', res.msg || res.message || '获取群成员列表失败');
            return;
        }
        renderMembers(res.data || {});
    });
}

function loadAllMembers() {
    var gid = requireGroup(); if (!gid) return;
    clearAlert('memberAlert');
    document.getElementById('memberEmpty').style.display = 'block';
    document.getElementById('memberEmpty').textContent = '正在沿分页游标拉取全部成员...';
    apiCall('api/group_member_api.php?action=get_members', {
        appid: currentBotId(),
        group_openid: gid,
        fetch_all: '1'
    }, function(res) {
        showRaw('获取全部群成员 (自动分页)', res);
        if (!res.success) {
            document.getElementById('memberEmpty').textContent = '加载失败';
            showAlert('memberAlert', 'danger', res.msg || res.message || '获取全部成员失败');
            return;
        }
        var data = res.data || {};
        // 全量数据视作单页展示（禁用分页）
        memberCursorStack = ['__all__'];
        memberNextCursor = '';
        renderMembers({ members: data.members || [], next_cursor: '', pages: data.pages, is_all: true });
    });
}

function renderMembers(data) {
    clearAlert('memberAlert');
    var members = data.members || [];
    memberAll = members;
    memberNextCursor = data.next_cursor || '';
    var tbody = document.getElementById('memberTbody');
    var table = document.getElementById('memberTable');
    var empty = document.getElementById('memberEmpty');
    document.getElementById('chkAll').checked = false;

    if (members.length === 0) {
        table.classList.remove('gm-table-show');
        empty.textContent = '暂无成员数据';
        empty.style.display = 'block';
        document.getElementById('memberPager').style.display = 'none';
        return;
    }
    empty.style.display = 'none';
    table.classList.add('gm-table-show');

    var html = '';
    for (var i = 0; i < members.length; i++) {
        var m = members[i];
        html += '<tr>'
            + '<td class="gm-check-cell"><input type="checkbox" class="gm-member-chk" value="' + escapeHtml(m.member_openid || '') + '"></td>'
            + '<td>' + escapeHtml(m.username || '(未公开)') + '</td>'
            + '<td><span class="gm-openid" onclick="copyText(\'' + escapeHtml(m.member_openid || '') + '\')" title="点击复制">' + escapeHtml(m.member_openid || '') + '</span></td>'
            + '<td>' + roleBadge(m.member_role) + '</td>'
            + '<td>' + (m.bot ? '<span class="badge badge-info">机器人</span>' : '<span class="badge badge-secondary">用户</span>') + '</td>'
            + '<td class="text-muted" title="' + escapeHtml(m.joined_at || '') + '">' + escapeHtml(fmtTime(m.joined_at)) + '</td>'
            + '<td class="text-right" style="white-space:nowrap;">'
            + '<button class="btn btn-outline btn-sm" onclick="showMemberDetail(\'' + escapeHtml(m.member_openid || '') + '\')">详情</button> '
            + '<button class="btn btn-danger btn-sm" onclick="removeSingle(\'' + escapeHtml(m.member_openid || '') + '\')">移除</button>'
            + '</td></tr>';
    }
    tbody.innerHTML = html;

    // 分页信息
    var pager = document.getElementById('memberPager');
    var isAll = memberCursorStack.length === 1 && memberCursorStack[0] === '__all__';
    if (isAll || (memberCursorStack.length === 1 && !memberNextCursor)) {
        // 单页或全量
        if (data.is_all) {
            pager.style.display = 'flex';
            document.getElementById('memberPageInfo').textContent = '已加载全部成员：共 ' + members.length + ' 人（' + (data.pages || 1) + ' 页）';
            document.getElementById('btnMemberPrev').disabled = true;
            document.getElementById('btnMemberNext').disabled = true;
        } else if (memberCursorStack.length === 1 && !memberNextCursor) {
            pager.style.display = 'none';
        }
    }
    if (!data.is_all) {
        pager.style.display = 'flex';
        var page = memberCursorStack.length;
        document.getElementById('memberPageInfo').textContent = '第 ' + page + ' 页，本页 ' + members.length + ' 人'
            + (memberNextCursor ? '，还有下一页' : '，已是末页');
        document.getElementById('btnMemberPrev').disabled = page <= 1;
        document.getElementById('btnMemberNext').disabled = !memberNextCursor;
    }
}

function memberNextPage() {
    if (!memberNextCursor) return;
    memberCursorStack.push(memberNextCursor);
    loadMembers(memberNextCursor);
}
function memberPrevPage() {
    if (memberCursorStack.length <= 1) return;
    memberCursorStack.pop();
    loadMembers(memberCursorStack[memberCursorStack.length - 1]);
}

function toggleSelectAll(cb) {
    var list = document.querySelectorAll('.gm-member-chk');
    for (var i = 0; i < list.length; i++) list[i].checked = cb.checked;
}

// ==================== 成员详情 ====================
function showMemberDetail(memberOpenid) {
    var gid = requireGroup(); if (!gid) return;
    var body = document.getElementById('memberDetailBody');
    body.innerHTML = '<div class="text-muted" style="padding:20px; text-align:center;">加载中...</div>';
    document.getElementById('memberDetailModal').style.display = 'flex';
    apiCall('api/group_member_api.php?action=get_member_info', {
        appid: currentBotId(),
        group_openid: gid,
        member_openid: memberOpenid
    }, function(res) {
        showRaw('获取群成员信息 (' + memberOpenid + ')', res);
        if (!res.success) {
            body.innerHTML = '<div class="alert alert-danger">' + escapeHtml(res.msg || res.message || '获取失败') + '</div>';
            return;
        }
        var m = res.data || {};
        var roleMap = { owner: '群主', admin: '管理员', member: '普通成员' };
        var html = '<table class="gm-detail-table" style="width:100%;">'
            + '<tr><td>昵称</td><td>' + escapeHtml(m.username || '-') + '</td></tr>'
            + '<tr><td>member_openid</td><td>' + escapeHtml(m.member_openid || '-') + '</td></tr>'
            + '<tr><td>union_openid</td><td>' + escapeHtml(m.union_openid || '-') + '</td></tr>'
            + '<tr><td>角色</td><td>' + escapeHtml(roleMap[m.member_role] || m.member_role || '-') + '</td></tr>'
            + '<tr><td>是否机器人</td><td>' + (m.bot ? '是' : '否') + '</td></tr>'
            + '<tr><td>入群时间</td><td title="' + escapeHtml(m.joined_at || '') + '">' + escapeHtml(fmtTime(m.joined_at)) + '</td></tr>'
            + '</table>';
        body.innerHTML = html;
    });
}

// ==================== 移除成员 ====================
function confirmRemoveMsg(count, withBlk) {
    return '确定要移除 ' + count + ' 个成员吗？' + (withBlk ? '\n移除后将同时加入群黑名单！' : '\n此操作不可撤销！');
}

function doRemove(openids, withBlk) {
    var gid = requireGroup(); if (!gid) return;
    apiCall('api/group_member_api.php?action=batch_remove_members', {
        appid: currentBotId(),
        group_openid: gid,
        member_openids: JSON.stringify(openids),
        add_to_blacklist: withBlk ? '1' : ''
    }, function(res) {
        showRaw('群成员批量移除 (' + openids.length + '人' + (withBlk ? '，同时拉黑' : '') + ')', res);
        if (!res.success) {
            showAlert('memberAlert', 'danger', res.msg || res.message || '批量移除失败');
            return;
        }
        var failBlk = (res.data && res.data.add_to_member_blacklist_fail_openids) || [];
        var extra = (withBlk && failBlk.length > 0) ? '；拉黑失败 ' + failBlk.length + ' 人' : '';
        showAlert('memberAlert', 'success', (res.msg || '移除操作已执行') + extra);
        // 刷新当前页（若处于"全部成员"视图则整表重载，避免把内部标记 __all__ 当游标传入接口）
        if (memberCursorStack.length === 1 && memberCursorStack[0] === '__all__') {
            loadAllMembers();
        } else {
            loadMembers(memberCursorStack[memberCursorStack.length - 1] || '');
        }
        if (withBlk && blkUsers.length > 0) loadBlacklist(blkCursorStack[blkCursorStack.length - 1] || '');
    });
}

function removeSingle(memberOpenid) {
    var withBlk = document.getElementById('chkRemoveBlk').checked;
    if (!confirm(confirmRemoveMsg(1, withBlk))) return;
    doRemove([memberOpenid], withBlk);
}

function batchRemoveSelected() {
    var checked = document.querySelectorAll('.gm-member-chk:checked');
    if (checked.length === 0) { showAlert('memberAlert', 'warning', '请先勾选要移除的成员'); return; }
    var withBlk = document.getElementById('chkRemoveBlk').checked;
    if (!confirm(confirmRemoveMsg(checked.length, withBlk))) return;
    var openids = [];
    for (var i = 0; i < checked.length; i++) openids.push(checked[i].value);
    doRemove(openids, withBlk);
}

function showRemoveModal() {
    var gid = requireGroup(); if (!gid) return;
    document.getElementById('removeOpenids').value = '';
    document.getElementById('removeModalBlk').checked = document.getElementById('chkRemoveBlk').checked;
    document.getElementById('removeModal').style.display = 'flex';
}

function doManualRemove() {
    var raw = document.getElementById('removeOpenids').value.trim();
    if (!raw) { alert('请输入 member_openid'); return; }
    var openids = raw.split(/[\s,，;；]+/).filter(function(s) { return s.length > 0; });
    if (openids.length === 0) { alert('请输入 member_openid'); return; }
    if (openids.length > 20) { alert('单次最多移除 20 个成员，当前 ' + openids.length + ' 个'); return; }
    var withBlk = document.getElementById('removeModalBlk').checked;
    if (!confirm(confirmRemoveMsg(openids.length, withBlk))) return;
    closeModal('removeModal');
    doRemove(openids, withBlk);
}

// ==================== 群黑名单 ====================
function loadBlacklistFirst() {
    var gid = requireGroup(); if (!gid) return;
    blkCursorStack = [''];
    loadBlacklist('');
}

function loadBlacklist(cursor) {
    var gid = requireGroup(); if (!gid) return;
    clearAlert('blacklistAlert');
    document.getElementById('blacklistEmpty').style.display = 'block';
    document.getElementById('blacklistEmpty').textContent = '加载中...';
    apiCall('api/group_member_api.php?action=get_blacklist', {
        appid: currentBotId(),
        group_openid: gid,
        cursor: cursor || '',
        limit: 20
    }, function(res) {
        showRaw('群黑名单查询 (cursor=' + (cursor || '') + ')', res);
        if (!res.success) {
            document.getElementById('blacklistEmpty').textContent = '加载失败';
            showAlert('blacklistAlert', 'danger', res.msg || res.message || '查询黑名单失败');
            return;
        }
        renderBlacklist(res.data || {});
    });
}

function renderBlacklist(data) {
    clearAlert('blacklistAlert');
    var users = data.users || [];
    blkUsers = users;
    blkNextCursor = data.next_cursor || '';
    var tbody = document.getElementById('blacklistTbody');
    var table = document.getElementById('blacklistTable');
    var empty = document.getElementById('blacklistEmpty');

    if (users.length === 0) {
        table.classList.remove('gm-table-show');
        empty.textContent = '暂无黑名单数据';
        empty.style.display = 'block';
        document.getElementById('blacklistPager').style.display = 'none';
        return;
    }
    empty.style.display = 'none';
    table.classList.add('gm-table-show');

    var html = '';
    for (var i = 0; i < users.length; i++) {
        var u = users[i];
        html += '<tr>'
            + '<td>' + escapeHtml(u.username || '(未公开)') + '</td>'
            + '<td><span class="gm-openid" onclick="copyText(\'' + escapeHtml(u.member_openid || '') + '\')" title="点击复制">' + escapeHtml(u.member_openid || '') + '</span></td>'
            + '<td class="text-muted" style="font-size:12px;">' + escapeHtml(u.union_openid || '-') + '</td>'
            + '<td class="text-muted" title="' + escapeHtml(u.banned_at || '') + '">' + escapeHtml(fmtTime(u.banned_at)) + '</td>'
            + '<td>' + (u.bot ? '<span class="badge badge-info">机器人</span>' : '<span class="badge badge-secondary">用户</span>') + '</td>'
            + '<td class="text-right"><button class="btn btn-outline btn-sm" onclick="blacklistRemoveOne(\'' + escapeHtml(u.member_openid || '') + '\')">移出黑名单</button></td>'
            + '</tr>';
    }
    tbody.innerHTML = html;

    var pager = document.getElementById('blacklistPager');
    pager.style.display = 'flex';
    var page = blkCursorStack.length;
    document.getElementById('blacklistPageInfo').textContent = '第 ' + page + ' 页，本页 ' + users.length + ' 条（每页20条）'
        + (blkNextCursor ? '，还有下一页' : '，已是末页');
    document.getElementById('btnBlkPrev').disabled = page <= 1;
    document.getElementById('btnBlkNext').disabled = !blkNextCursor;
}

function blacklistNextPage() {
    if (!blkNextCursor) return;
    blkCursorStack.push(blkNextCursor);
    loadBlacklist(blkNextCursor);
}
function blacklistPrevPage() {
    if (blkCursorStack.length <= 1) return;
    blkCursorStack.pop();
    loadBlacklist(blkCursorStack[blkCursorStack.length - 1]);
}

function showBlacklistAddModal() {
    var gid = requireGroup(); if (!gid) return;
    document.getElementById('blkAddOpenids').value = '';
    document.getElementById('blacklistAddModal').style.display = 'flex';
}

function doBlacklistAdd() {
    var raw = document.getElementById('blkAddOpenids').value.trim();
    if (!raw) { alert('请输入 member_openid'); return; }
    var openids = raw.split(/[\s,，;；]+/).filter(function(s) { return s.length > 0; });
    if (openids.length === 0) { alert('请输入 member_openid'); return; }
    if (openids.length > 20) { alert('单次最多操作 20 个成员，当前 ' + openids.length + ' 个'); return; }
    if (!confirm('确定将 ' + openids.length + ' 个用户加入群黑名单吗？\n注意：只有不在群中的用户才能加入黑名单。')) return;
    doBlacklistOp('add', openids);
}

function blacklistRemoveOne(memberOpenid) {
    if (!confirm('确定将 ' + memberOpenid + ' 移出群黑名单吗？')) return;
    doBlacklistOp('del', [memberOpenid]);
}

function doBlacklistOp(op, openids) {
    var gid = requireGroup(); if (!gid) return;
    apiCall('api/group_member_api.php?action=blacklist_op', {
        appid: currentBotId(),
        group_openid: gid,
        op: op,
        member_openids: JSON.stringify(openids)
    }, function(res) {
        showRaw('群黑名单操作 (op=' + op + ', ' + openids.length + '人)', res);
        if (!res.success) {
            showAlert('blacklistAlert', 'danger', res.msg || res.message || '黑名单操作失败');
            return;
        }
        showAlert('blacklistAlert', 'success', res.msg || '操作已执行');
        closeModal('blacklistAddModal');
        loadBlacklist(blkCursorStack[blkCursorStack.length - 1] || '');
    });
}
</script>

<?php require_once('footer.php'); ?>
