<?php
/**
 * 管理后台 - 素材管理
 * 官方 20261021 新增：图片上传到平台后获得 qqbot-media:// 引用，可在 Markdown 消息中反复使用
 * 对应接口：/v2/media-images/prepare · PUT 预签名 URL · /complete · GET /v2/media-images · DELETE
 */
$pageTitle = '素材管理';
require_once('header.php');

$bots = getBots();
$defaultAppid = !empty($bots) ? $bots[0]['appid'] : '';
?>
<style>
/* ===== 素材管理：纸面卡片 + 细线，无渐变无大圆角 ===== */
.mat-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.mat-toolbar .form-control { min-width: 190px; }
.mat-bot-static { font-size: 11.5px; color: var(--ink-3); }
.mat-lib-header { flex-wrap: wrap; row-gap: 8px; }
.mat-lib-tools { display: flex; align-items: center; gap: 6px; flex: 1; justify-content: flex-end; min-width: 0; }
.mat-lib-tools .form-control { width: 180px; max-width: 100%; font-size: 12px; padding: 5px 9px; }
.mat-hint code { display: block; margin-top: 4px; padding: 5px 7px; word-break: break-all; line-height: 1.6; }
.mat-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; background: var(--line); border: 1px solid var(--line); margin-bottom: 14px; }
.mat-summary-item { background: var(--surface); padding: 12px 16px; min-width: 0; }
.mat-summary-label { font-size: 11px; color: var(--ink-3); letter-spacing: .04em; }
.mat-summary-value { margin-top: 3px; font-family: var(--mono); font-size: 20px; line-height: 1.2; color: var(--ink); }
.mat-summary-value small { font-family: var(--font); font-size: 11px; color: var(--ink-3); font-weight: 400; }
.mat-summary-note { margin-top: 2px; font-size: 11px; color: var(--ink-3); }

.mat-uploader {
  border: 1px dashed var(--line-2);
  background: var(--surface);
  padding: 22px 18px;
  text-align: center;
  transition: var(--transition);
  cursor: pointer;
}
.mat-uploader:hover, .mat-uploader.dragover { border-color: var(--brand); background: var(--brand-soft); }
.mat-uploader-icon { color: var(--ink-3); margin-bottom: 8px; }
.mat-uploader-title { font-size: 13px; color: var(--ink); }
.mat-uploader-title .link-btn { color: var(--brand); background: none; border: 0; padding: 0; font: inherit; cursor: pointer; text-decoration: underline; text-underline-offset: 2px; }
.mat-uploader-hint { margin-top: 5px; font-size: 11px; color: var(--ink-3); }

.mat-queue { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
.mat-queue-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: var(--surface-2); border: 1px solid var(--line); font-size: 12px; }
.mat-queue-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mat-queue-bar { width: 120px; height: 4px; background: var(--line); flex: 0 0 auto; }
.mat-queue-bar span { display: block; height: 100%; width: 0; background: var(--brand); transition: width .2s; }
.mat-queue-status { width: 72px; text-align: right; color: var(--ink-3); flex: 0 0 auto; }
.mat-queue-status.ok { color: var(--ok); }
.mat-queue-status.err { color: var(--danger); }

.mat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(228px, 1fr)); gap: 12px; }
.mat-card { background: var(--surface); border: 1px solid var(--line); min-width: 0; display: flex; flex-direction: column; }
.mat-thumb { position: relative; display: block; width: 100%; aspect-ratio: 4 / 3; background: var(--surface-3); border: 0; border-bottom: 1px solid var(--line); padding: 0; cursor: zoom-in; overflow: hidden; }
.mat-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.mat-thumb .mat-thumb-fallback { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: var(--ink-3); font-size: 12px; }
.mat-kind { position: absolute; top: 6px; right: 6px; background: rgba(25,30,26,.72); color: #fff; font-family: var(--mono); font-size: 10px; padding: 1px 5px; letter-spacing: .04em; }
.mat-card-body { padding: 10px 11px 11px; display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.mat-name { font-size: 12.5px; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mat-meta { font-size: 10.5px; color: var(--ink-3); font-family: var(--mono); }
.mat-actions { display: flex; gap: 5px; margin-top: 7px; flex-wrap: wrap; }
.mat-actions button { border: 1px solid var(--line); background: var(--surface); color: var(--ink-2); font-size: 11px; padding: 3px 8px; cursor: pointer; border-radius: var(--radius-sm); transition: var(--transition); }
.mat-actions button:hover { border-color: var(--brand); color: var(--brand); }
.mat-actions button.danger:hover { border-color: var(--danger); color: var(--danger); background: var(--danger-soft); }
.mat-loadmore { display: flex; justify-content: center; padding: 14px 0 4px; }
.mat-hint { margin-top: 12px; font-size: 11px; color: var(--ink-3); line-height: 1.8; }
.mat-hint code { background: var(--surface-2); border: 1px solid var(--line); padding: 0 4px; font-size: 10.5px; }

.mat-preview-modal .modal { max-width: 720px; width: 94vw; }
.mat-preview-body { display: flex; gap: 16px; padding: 16px; }
.mat-preview-img { flex: 1; min-width: 0; background: var(--surface-3); display: flex; align-items: center; justify-content: center; min-height: 220px; }
.mat-preview-img img { max-width: 100%; max-height: 56vh; object-fit: contain; display: block; }
.mat-preview-info { width: 260px; flex: 0 0 auto; min-width: 0; }
.mat-preview-info h4 { font-size: 13px; margin-bottom: 8px; word-break: break-all; }
.mat-preview-info .form-group { margin-bottom: 10px; }
.mat-preview-info textarea { font-family: var(--mono); font-size: 11px; resize: vertical; }
.mat-preview-meta { font-size: 11px; color: var(--ink-3); line-height: 1.8; word-break: break-all; }
.mat-toast { position: fixed; bottom: 22px; right: 22px; max-width: calc(100vw - 32px); background: var(--ink); color: #fff; padding: 8px 14px; font-size: 12px; z-index: 300; opacity: 0; transform: translateY(6px); transition: opacity .2s, transform .2s; pointer-events: none; }
.mat-toast.show { opacity: 1; transform: translateY(0); }
.mat-toast.err { background: var(--danger); }

@media (max-width: 768px) {
  .mat-summary { grid-template-columns: 1fr; }
  .mat-summary-item { padding: 9px 12px; }
  .mat-summary-value { font-size: 17px; }
  .mat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
  .mat-actions button { padding: 3px 6px; }
  .mat-card-body { padding: 8px; }
  .mat-preview-body { flex-direction: column; }
  .mat-preview-info { width: 100%; }
  .mat-preview-img { min-height: 160px; }
  .mat-toolbar { width: 100%; }
  .mat-toolbar .form-control { flex: 1; min-width: 0; }
  .mat-lib-tools { flex: 0 0 100%; justify-content: flex-start; }
  .mat-lib-tools .form-control { flex: 1; width: auto; }
}
</style>

<div class="page-header">
  <div>
    <h2>素材管理</h2>
    <p class="page-desc">图片先上传到 QQ 平台，再用 qqbot-media:// 引用写进 Markdown，避免外链失效</p>
  </div>
  <div class="actions mat-toolbar">
    <?php if (count($bots) > 1): ?>
    <select id="matBot" class="form-control" onchange="onMatBotChange()">
      <?php foreach ($bots as $bot): ?>
      <option value="<?= htmlspecialchars($bot['appid']) ?>" <?= $bot['appid'] === $defaultAppid ? 'selected' : '' ?>>
        <?= htmlspecialchars($bot['nickname'] ?: $bot['appid']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <?php elseif (count($bots) === 1): ?>
    <input type="hidden" id="matBot" value="<?= htmlspecialchars($defaultAppid) ?>">
    <span class="mat-bot-static">机器人 · <?= htmlspecialchars($bots[0]['nickname'] ?: $bots[0]['appid']) ?></span>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($bots)): ?>
<div class="card">
  <div class="empty-state">
    <div class="empty-icon">&#128444;</div>
    <p>还没有添加机器人</p>
    <p style="margin-top:6px;"><a href="bots.php">前往「机器人管理」添加</a></p>
  </div>
</div>
<?php else: ?>

<div class="mat-summary">
  <div class="mat-summary-item">
    <div class="mat-summary-label">已用配额</div>
    <div class="mat-summary-value" id="matUsed">—<small> / 100</small></div>
    <div class="mat-summary-note">超出前请删除不用的素材</div>
  </div>
  <div class="mat-summary-item">
    <div class="mat-summary-label">剩余可上传</div>
    <div class="mat-summary-value" id="matLeft">—<small> 张</small></div>
    <div class="mat-summary-note">每个机器人独立计算</div>
  </div>
  <div class="mat-summary-item">
    <div class="mat-summary-label">有效期</div>
    <div class="mat-summary-value">90<small> 天</small></div>
    <div class="mat-summary-note">滚动过期：每次被消息引用后顺延</div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3>上传图片</h3>
    <span style="font-size:11px;color:var(--ink-3);">单张 ≤ 10MB</span>
  </div>
  <div class="card-body">
    <div class="mat-uploader" id="matDrop" onclick="document.getElementById('matFileInput').click()">
      <div class="mat-uploader-icon">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>
        </svg>
      </div>
      <div class="mat-uploader-title">拖拽图片到这里，或 <button type="button" class="link-btn" onclick="event.stopPropagation();document.getElementById('matFileInput').click()">选择文件</button></div>
      <div class="mat-uploader-hint">PNG / JPEG / GIF / WebP · 支持一次选多张 · 大图自动分片上传</div>
    </div>
    <input type="file" id="matFileInput" accept="image/png,image/jpeg,image/gif,image/webp" multiple style="display:none;" onchange="handleMatFiles(this.files);this.value='';">
    <div class="mat-queue" id="matQueue"></div>
  </div>
</div>

<div class="card">
  <div class="card-header mat-lib-header">
    <h3>素材库 <span style="font-weight:400;font-size:11px;color:var(--ink-3);" id="matTotalLabel">—</span></h3>
    <div class="mat-lib-tools">
      <input type="text" id="matFilter" class="form-control" placeholder="按文件名筛选" oninput="renderMaterials()">
      <button type="button" class="btn btn-outline btn-sm" onclick="loadMaterials(true)">刷新</button>
    </div>
  </div>
  <div class="card-body">
    <div class="mat-grid" id="matGrid"></div>
    <div id="matEmpty" class="empty-state" style="display:none;">
      <div class="empty-icon">&#128444;</div>
      <p>还没有素材，先上传一张图片</p>
    </div>
    <div class="mat-loadmore" id="matLoadMore" style="display:none;">
      <button type="button" class="btn btn-outline btn-sm" onclick="loadMaterials(false)">加载更多</button>
    </div>
    <div class="mat-hint">
      在 Markdown 消息中引用：
      <code>![说明 #宽px #高px](qqbot-media://image/v1/...)</code>
      引用链接与机器人绑定，其他机器人无法使用；图片连续 90 天未被引用会自动失效。
    </div>
  </div>
</div>

<!-- 预览弹窗 -->
<div class="modal-overlay mat-preview-modal" id="matPreviewModal" style="display:none;">
  <div class="modal">
    <div class="modal-header">
      <span>素材预览</span>
      <button type="button" class="modal-close" onclick="closeMatPreview()">&times;</button>
    </div>
    <div class="mat-preview-body">
      <div class="mat-preview-img"><img id="matPreviewImg" alt=""></div>
      <div class="mat-preview-info">
        <h4 id="matPreviewName">—</h4>
        <div class="form-group">
          <label>Markdown 引用</label>
          <textarea id="matPreviewMd" class="form-control" rows="4" readonly></textarea>
        </div>
        <button type="button" class="btn btn-primary btn-sm" onclick="copyMatPreviewMd()">复制 Markdown</button>
        <div class="mat-preview-meta" id="matPreviewMeta" style="margin-top:12px;"></div>
      </div>
    </div>
  </div>
</div>

<div class="mat-toast" id="matToast"></div>

<script>
var matState = { appid: '', images: [], cursor: '', total: 0, loading: false, filter: '', previewItem: null };
var MAT_LIMIT = 100;
var MAT_CHUNK = 1024 * 1024;
var MAT_DIRECT_LIMIT = 1.5 * 1024 * 1024;

function matAppid() {
  var el = document.getElementById('matBot');
  return el ? el.value : '';
}

function matEsc(s) {
  return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function matToast(msg, isErr) {
  var t = document.getElementById('matToast');
  t.textContent = msg;
  t.classList.toggle('err', !!isErr);
  t.classList.add('show');
  clearTimeout(t._timer);
  t._timer = setTimeout(function () { t.classList.remove('show'); }, 2200);
}

function matApi(action, params, callback) {
  var appid = matAppid();
  var url = 'api/material_api.php?action=' + encodeURIComponent(action);
  var body = null;
  if (params) {
    body = new URLSearchParams();
    body.append('appid', appid);
    for (var k in params) {
      if (params.hasOwnProperty(k)) body.append(k, params[k]);
    }
  } else {
    url += '&appid=' + encodeURIComponent(appid);
  }
  var xhr = new XMLHttpRequest();
  xhr.open(body ? 'POST' : 'GET', url, true);
  if (body) xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  xhr.timeout = 45000;
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) return;
    var res;
    try { res = JSON.parse(xhr.responseText); }
    catch (e) { res = { success: false, message: '响应解析失败：' + xhr.responseText.substring(0, 160) }; }
    callback(res);
  };
  xhr.ontimeout = function () { callback({ success: false, message: '请求超时，请稍后重试' }); };
  xhr.onerror = function () { callback({ success: false, message: '网络错误' }); };
  xhr.send(body ? body.toString() : null);
}

function onMatBotChange() {
  matState.appid = matAppid();
  matState.images = [];
  matState.cursor = '';
  matState.total = 0;
  loadMaterials(true);
}

function loadMaterials(reset) {
  if (!matAppid()) return;
  if (matState.loading) return;
  if (reset) {
    matState.images = [];
    matState.cursor = '';
    matState.total = 0;
  }
  matState.loading = true;
  var params = null;
  var url = 'api/material_api.php?action=list&appid=' + encodeURIComponent(matAppid());
  if (!reset && matState.cursor) url += '&cursor=' + encodeURIComponent(matState.cursor);
  var xhr = new XMLHttpRequest();
  xhr.open('GET', url, true);
  xhr.timeout = 30000;
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) return;
    matState.loading = false;
    var res;
    try { res = JSON.parse(xhr.responseText); }
    catch (e) { res = { success: false, message: '响应解析失败' }; }
    if (!res.success) {
      matToast(res.message || '加载失败', true);
      renderMaterials();
      return;
    }
    var incoming = res.images || [];
    if (reset) matState.images = incoming;
    else matState.images = matState.images.concat(incoming);
    matState.cursor = res.next_cursor || '';
    matState.total = parseInt(res.total || matState.images.length, 10) || 0;
    renderMaterials();
  };
  xhr.ontimeout = function () { matState.loading = false; matToast('加载超时', true); };
  xhr.onerror = function () { matState.loading = false; matToast('网络错误', true); };
  xhr.send();
}

function matFormatTime(ts) {
  var d = new Date((parseInt(ts, 10) || 0) * 1000);
  if (!ts) return '—';
  var p = function (n) { return (n < 10 ? '0' : '') + n; };
  return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
}

function matKind(name) {
  var m = String(name || '').match(/\.([a-z0-9]+)$/i);
  return m ? m[1].toUpperCase() : 'IMG';
}

function renderMaterials() {
  var grid = document.getElementById('matGrid');
  var empty = document.getElementById('matEmpty');
  var more = document.getElementById('matLoadMore');
  var filter = (document.getElementById('matFilter').value || '').trim().toLowerCase();
  var list = matState.images.filter(function (it) {
    return !filter || String(it.file_name || '').toLowerCase().indexOf(filter) > -1;
  });

  document.getElementById('matTotalLabel').textContent = '共 ' + matState.total + ' 张';
  document.getElementById('matUsed').innerHTML = matState.total + '<small> / ' + MAT_LIMIT + '</small>';
  document.getElementById('matLeft').innerHTML = Math.max(0, MAT_LIMIT - matState.total) + '<small> 张</small>';

  if (!list.length) {
    grid.innerHTML = '';
    empty.style.display = 'block';
    if (filter && matState.images.length) {
      empty.querySelector('p').textContent = '没有匹配的素材';
    } else {
      empty.querySelector('p').textContent = '还没有素材，先上传一张图片';
    }
    more.style.display = 'none';
    return;
  }
  empty.style.display = 'none';

  var html = '';
  list.forEach(function (it, idx) {
    var name = it.file_name || '未命名图片';
    var thumb = it.preview_url
      ? '<img src="' + matEsc(it.preview_url) + '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\';">'
      : '';
    html += '<article class="mat-card" data-id="' + matEsc(it.image_id) + '">'
          +   '<button type="button" class="mat-thumb" data-act="preview" data-idx="' + idx + '" title="预览">'
          +     thumb
          +     '<span class="mat-thumb-fallback" style="display:' + (it.preview_url ? 'none' : 'flex') + ';">预览不可用</span>'
          +     '<span class="mat-kind">' + matEsc(matKind(name)) + '</span>'
          +   '</button>'
          +   '<div class="mat-card-body">'
          +     '<div class="mat-name" title="' + matEsc(name) + '">' + matEsc(name) + '</div>'
          +     '<div class="mat-meta">' + matFormatTime(it.created_at) + ' 上传</div>'
          +     '<div class="mat-meta">' + (it.expires_at ? matFormatTime(it.expires_at) + ' 到期' : '有效期未知') + '</div>'
          +     '<div class="mat-actions">'
          +       '<button type="button" data-act="copy-md" data-idx="' + idx + '">复制 MD</button>'
          +       '<button type="button" data-act="copy-url" data-idx="' + idx + '">链接</button>'
          +       '<button type="button" class="danger" data-act="delete" data-idx="' + idx + '">删除</button>'
          +     '</div>'
          +   '</div>'
          + '</article>';
  });
  grid.innerHTML = html;
  more.style.display = matState.cursor ? 'flex' : 'none';
}

document.getElementById('matGrid').addEventListener('click', function (e) {
  var btn = e.target.closest('button[data-act]');
  if (!btn) return;
  var idx = parseInt(btn.getAttribute('data-idx'), 10);
  var item = matState.images.filter(function (it) {
    return !document.getElementById('matFilter').value.trim() ||
      String(it.file_name || '').toLowerCase().indexOf(document.getElementById('matFilter').value.trim().toLowerCase()) > -1;
  })[idx];
  if (!item) return;
  var act = btn.getAttribute('data-act');
  if (act === 'preview') { openMatPreview(item); }
  else if (act === 'copy-md') { matCopy('![' + (item.file_name || '图片') + '](' + item.url + ')', btn); }
  else if (act === 'copy-url') { matCopy(item.url, btn); }
  else if (act === 'delete') { deleteMatItem(item); }
});

function matCopy(text, btn) {
  var done = function () {
    if (btn) {
      var old = btn.textContent;
      btn.textContent = '已复制';
      setTimeout(function () { btn.textContent = old; }, 1200);
    }
    matToast('已复制到剪贴板');
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(function () { matCopyFallback(text, done); });
  } else {
    matCopyFallback(text, done);
  }
}

function matCopyFallback(text, done) {
  var ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); done(); } catch (e) { matToast('复制失败，请手动选择', true); }
  document.body.removeChild(ta);
}

function openMatPreview(item) {
  matState.previewItem = item;
  document.getElementById('matPreviewName').textContent = item.file_name || '未命名图片';
  var img = document.getElementById('matPreviewImg');
  img.src = item.preview_url || '';
  img.style.display = item.preview_url ? 'block' : 'none';
  document.getElementById('matPreviewMd').value = '![' + (item.file_name || '图片') + '](' + item.url + ')';
  document.getElementById('matPreviewMeta').innerHTML =
    'image_id：' + matEsc(item.image_id) + '<br>' +
    '上传：' + matFormatTime(item.created_at) + '<br>' +
    '到期：' + (item.expires_at ? matFormatTime(item.expires_at) : '—') + '<br>' +
    '引用：<span style="word-break:break-all;">' + matEsc(item.url) + '</span>';
  document.getElementById('matPreviewModal').style.display = 'flex';
}

function closeMatPreview() {
  document.getElementById('matPreviewModal').style.display = 'none';
  matState.previewItem = null;
}

function copyMatPreviewMd() {
  var item = matState.previewItem;
  if (!item) return;
  matCopy('![' + (item.file_name || '图片') + '](' + item.url + ')', null);
}

function deleteMatItem(item) {
  if (!confirm('删除素材「' + (item.file_name || item.image_id) + '」？\n删除后引用链接立即失效，已发送的消息不受影响。')) return;
  matApi('delete', { image_id: item.image_id }, function (res) {
    if (!res.success) { matToast(res.message || '删除失败', true); return; }
    matToast('已删除');
    loadMaterials(true);
  });
}

// ==================== 上传 ====================
function handleMatFiles(files) {
  if (!files || !files.length) return;
  var arr = Array.prototype.slice.call(files).slice(0, 9);
  var chain = Promise.resolve();
  arr.forEach(function (file) {
    chain = chain.then(function () { return uploadMatFile(file); });
  });
}

function matQueueItem(file) {
  var queue = document.getElementById('matQueue');
  var el = document.createElement('div');
  el.className = 'mat-queue-item';
  el.innerHTML = '<span class="mat-queue-name">' + matEsc(file.name) + '</span>'
    + '<span class="mat-queue-bar"><span></span></span>'
    + '<span class="mat-queue-status">等待</span>';
  queue.appendChild(el);
  return el;
}

function setQueue(el, pct, text, state) {
  var bar = el.querySelector('.mat-queue-bar span');
  var st = el.querySelector('.mat-queue-status');
  if (bar) bar.style.width = Math.max(0, Math.min(100, pct)) + '%';
  if (st) {
    st.textContent = text;
    st.className = 'mat-queue-status' + (state ? ' ' + state : '');
  }
}

function uploadMatFile(file) {
  return new Promise(function (resolve) {
    var el = matQueueItem(file);
    var allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    if (allowed.indexOf(file.type) === -1) {
      setQueue(el, 100, '格式不支持', 'err');
      resolve();
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      setQueue(el, 100, '超过 10MB', 'err');
      resolve();
      return;
    }
    if (file.size <= MAT_DIRECT_LIMIT) {
      uploadMatDirect(file, el, resolve);
    } else {
      uploadMatChunks(file, el, resolve);
    }
  });
}

function uploadMatDirect(file, el, resolve) {
  var fd = new FormData();
  fd.append('appid', matAppid());
  fd.append('file_name', file.name);
  fd.append('file', file, file.name);
  var xhr = new XMLHttpRequest();
  xhr.open('POST', 'api/material_api.php?action=upload', true);
  xhr.timeout = 60000;
  xhr.upload.onprogress = function (e) {
    if (e.lengthComputable) setQueue(el, Math.round(e.loaded / e.total * 88), Math.round(e.loaded / e.total * 88) + '%');
  };
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) return;
    var res;
    try { res = JSON.parse(xhr.responseText); }
    catch (e) { res = { success: false, message: '响应解析失败' }; }
    if (res.success) {
      setQueue(el, 100, '完成', 'ok');
      matToast('上传成功：' + ((res.data && res.data.file_name) || file.name));
      loadMaterials(true);
    } else {
      setQueue(el, 100, '失败', 'err');
      matToast(res.message || '上传失败', true);
    }
    resolve();
  };
  xhr.ontimeout = function () { setQueue(el, 100, '超时', 'err'); matToast('上传超时', true); resolve(); };
  xhr.onerror = function () { setQueue(el, 100, '网络错误', 'err'); matToast('网络错误', true); resolve(); };
  xhr.send(fd);
}

function uploadMatChunks(file, el, resolve) {
  var uploadId = '';
  var cryptoObj = window.crypto || window.msCrypto;
  var buf = new Uint8Array(16);
  if (cryptoObj && cryptoObj.getRandomValues) cryptoObj.getRandomValues(buf);
  else for (var i = 0; i < 16; i++) buf[i] = Math.floor(Math.random() * 256);
  uploadId = Array.prototype.map.call(buf, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  var total = Math.ceil(file.size / MAT_CHUNK);
  var index = 0;

  function next() {
    if (index >= total) {
      finish();
      return;
    }
    var start = index * MAT_CHUNK;
    var blob = file.slice(start, Math.min(start + MAT_CHUNK, file.size));
    var fd = new FormData();
    fd.append('appid', matAppid());
    fd.append('upload_id', uploadId);
    fd.append('chunk_index', index);
    fd.append('chunk_total', total);
    fd.append('chunk', blob, 'chunk');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/material_api.php?action=upload_chunk', true);
    xhr.timeout = 60000;
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      var res;
      try { res = JSON.parse(xhr.responseText); }
      catch (e) { res = { success: false, message: '响应解析失败' }; }
      if (res.success) {
        index++;
        var pct = Math.round(index / (total + 1) * 88);
        setQueue(el, pct, pct + '%');
        next();
      } else {
        setQueue(el, 100, '失败', 'err');
        matToast(res.message || '分片上传失败', true);
        resolve();
      }
    };
    xhr.ontimeout = function () { setQueue(el, 100, '超时', 'err'); matToast('分片上传超时', true); resolve(); };
    xhr.onerror = function () { setQueue(el, 100, '网络错误', 'err'); matToast('网络错误', true); resolve(); };
    xhr.send(fd);
  }

  function finish() {
    setQueue(el, 92, '处理中');
    var fd = new FormData();
    fd.append('appid', matAppid());
    fd.append('upload_id', uploadId);
    fd.append('chunk_total', total);
    fd.append('file_name', file.name);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/material_api.php?action=upload_complete', true);
    xhr.timeout = 120000;
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      var res;
      try { res = JSON.parse(xhr.responseText); }
      catch (e) { res = { success: false, message: '响应解析失败' }; }
      if (res.success) {
        setQueue(el, 100, '完成', 'ok');
        matToast('上传成功：' + ((res.data && res.data.file_name) || file.name));
        loadMaterials(true);
      } else {
        setQueue(el, 100, '失败', 'err');
        matToast(res.message || '上传失败', true);
      }
      resolve();
    };
    xhr.ontimeout = function () { setQueue(el, 100, '超时', 'err'); matToast('上传超时', true); resolve(); };
    xhr.onerror = function () { setQueue(el, 100, '网络错误', 'err'); matToast('网络错误', true); resolve(); };
    xhr.send(fd);
  }

  setQueue(el, 1, '1%');
  next();
}

// 拖拽
(function () {
  var drop = document.getElementById('matDrop');
  if (!drop) return;
  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('dragover'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('dragover'); });
  });
  drop.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files) handleMatFiles(e.dataTransfer.files);
  });
})();

document.getElementById('matPreviewModal').addEventListener('click', function (e) {
  if (e.target === this) closeMatPreview();
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && document.getElementById('matPreviewModal').style.display === 'flex') closeMatPreview();
});

// 初始化
(function () {
  matState.appid = matAppid();
  if (matState.appid) loadMaterials(true);
})();
</script>

<?php endif; ?>
<?php require_once('footer.php'); ?>
