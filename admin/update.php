<?php
/**
 * 管理后台 - 系统更新
 * 从 GitHub Releases 检查并安装新版本，支持下载加速源与备份回滚。
 */
$pageTitle = '系统更新';
require_once(__DIR__ . '/header.php');
require_once(__DIR__ . '/lib_update.php');

$当前版本 = 更新当前版本();
$状态 = 更新状态();
$配置 = 更新配置();
$备份 = 更新备份列表();
$zipOk = class_exists('ZipArchive');
$phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
?>
<style>
.upd-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:16px; }
.upd-metric { background:#fff; border:1px solid var(--border); border-radius:8px; padding:14px 16px; }
.upd-metric .k { font-size:11px; color:var(--text-muted); margin-bottom:6px; }
.upd-metric .v { font-size:20px; font-weight:600; letter-spacing:-.2px; line-height:1.2; word-break:break-all; }
.upd-metric .s { font-size:11px; color:var(--text-secondary); margin-top:4px; }

.upd-empty { text-align:center; padding:34px 16px; color:var(--text-secondary); font-size:13px; }
.upd-empty .big { font-size:15px; color:var(--text); margin-bottom:6px; font-weight:500; }

.upd-release-head { display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap; padding-bottom:14px; border-bottom:1px solid var(--border); margin-bottom:14px; }
.upd-release-head .ver { font-size:22px; font-weight:600; letter-spacing:-.3px; line-height:1.2; }
.upd-release-head .tag { font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:11px; color:var(--text-secondary); background:var(--bg-alt); border:1px solid var(--border); border-radius:4px; padding:2px 7px; }
.upd-release-head .meta { font-size:12px; color:var(--text-secondary); margin-top:6px; }
.upd-release-head .grow { margin-left:auto; display:flex; gap:8px; align-items:center; }

.upd-notes {
  white-space:pre-wrap; word-break:break-word; font-size:12.5px; line-height:1.75; color:#3d3d3d;
  background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:14px 16px;
  max-height:360px; overflow:auto; margin:0;
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
}
.upd-steps { display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }
.upd-step { flex:1 1 150px; border:1px solid var(--border); border-radius:6px; padding:10px 12px; font-size:12px; color:var(--text-secondary); background:#fff; }
.upd-step b { display:block; color:var(--text); font-size:12px; margin-bottom:3px; font-weight:600; }
.upd-step.on { border-color:#c8c8c8; background:#fafafa; }
.upd-step.done { border-color:#cfe8d5; background:#f4faf5; }
.upd-step.done b { color:#2e7d32; }

.upd-bar { height:4px; background:var(--bg-secondary); border-radius:999px; overflow:hidden; margin-top:14px; display:none; }
.upd-bar.on { display:block; }
.upd-bar i { display:block; height:100%; width:35%; background:#333; border-radius:999px; animation:updSlide 1.1s ease-in-out infinite; }
@keyframes updSlide { 0%{ transform:translateX(-100%);} 100%{ transform:translateX(320%);} }

.upd-note { font-size:12px; color:var(--text-secondary); line-height:1.7; }
.upd-note code { font-family:ui-monospace,Consolas,monospace; background:var(--bg-alt); padding:1px 5px; border-radius:3px; font-size:11.5px; }
.upd-warn { border-left:3px solid #f0b429; background:#fffdf5; padding:10px 14px; border-radius:0 6px 6px 0; font-size:12px; color:#6b5420; line-height:1.7; }
.upd-ok { border-left:3px solid #34a853; background:#f6fbf7; padding:10px 14px; border-radius:0 6px 6px 0; font-size:12px; color:#205c31; line-height:1.7; }
.upd-err { border-left:3px solid #dc3545; background:#fff7f7; padding:10px 14px; border-radius:0 6px 6px 0; font-size:12px; color:#8a1f2b; line-height:1.7; }

.upd-form { display:grid; grid-template-columns:1fr 1fr; gap:12px 16px; }
.upd-form .full { grid-column:1 / -1; }
.upd-form label { display:block; font-size:12px; color:var(--text-secondary); margin-bottom:5px; }
.upd-form .form-control { width:100%; height:36px; padding:0 10px; border:1px solid var(--border-hover); border-radius:6px; font-size:13px; background:#fff; color:var(--text); }
.upd-form .form-control:focus { outline:none; border-color:#999; }
.upd-form .hint { font-size:11px; color:var(--text-muted); margin-top:4px; line-height:1.6; }
.upd-preset { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; }
.upd-preset button { font-size:11px; padding:3px 9px; border:1px solid var(--border-hover); background:#fff; border-radius:999px; color:var(--text-secondary); cursor:pointer; }
.upd-preset button:hover { border-color:#aaa; color:var(--text); }

.upd-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }

@media (max-width: 720px) {
  .upd-form { grid-template-columns:1fr; }
  .upd-form .full { grid-column:auto; }
  .upd-release-head .grow { margin-left:0; width:100%; }
  .upd-release-head .grow .btn { flex:1; }
}
</style>

<div class="page-header">
  <h2>系统更新</h2>
  <div class="actions">
    <button class="btn btn-outline" id="updCheck">检查更新</button>
  </div>
</div>

<div class="upd-grid">
  <div class="upd-metric">
    <div class="k">当前版本</div>
    <div class="v" id="updCurrent">v<?= htmlspecialchars($当前版本) ?></div>
    <div class="s"><?= htmlspecialchars($状态['updated_at'] ? '更新于 ' . $状态['updated_at'] : '尚未通过在线更新') ?></div>
  </div>
  <div class="upd-metric">
    <div class="k">最新版本</div>
    <div class="v" id="updLatest">—</div>
    <div class="s" id="updLatestSub">点击右上角检查</div>
  </div>
  <div class="upd-metric">
    <div class="k">更新源</div>
    <div class="v" style="font-size:13px;font-weight:500;line-height:1.5;" id="updRepo"><?= htmlspecialchars($配置['repo']) ?></div>
    <div class="s"><?= $配置['proxy'] !== '' ? '已启用下载加速' : '直连 GitHub' ?></div>
  </div>
  <div class="upd-metric">
    <div class="k">环境</div>
    <div class="v" style="font-size:13px;font-weight:500;line-height:1.5;">PHP <?= htmlspecialchars(PHP_VERSION) ?></div>
    <div class="s"><?= $zipOk ? 'ZipArchive 已就绪' : '<span style="color:#c62828">缺少 php-zip</span>' ?></div>
  </div>
</div>

<?php if (!$zipOk): ?>
<div class="upd-warn" style="margin-bottom:16px;">
  服务器未安装 <code>php-zip</code> 扩展，无法解压安装包。请安装后在 <code>php.ini</code> 中启用 <code>extension=zip</code> 再使用在线更新。
</div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-header">更新状态</div>
  <div class="card-body">
    <div id="updPanel">
      <div class="upd-empty">
        <div class="big">尚未检查更新</div>
        点击右上角「检查更新」，会从 GitHub Releases 读取最新版本与更新说明。
      </div>
    </div>

    <div class="upd-bar" id="updBar"><i></i></div>

    <div class="upd-steps" id="updSteps" style="display:none;">
      <div class="upd-step" data-step="1"><b>1. 下载安装包</b>校验域名与大小</div>
      <div class="upd-step" data-step="2"><b>2. 解压预检</b>确认包结构完整</div>
      <div class="upd-step" data-step="3"><b>3. 备份并写入</b>保留 data/ 与 plugin/</div>
    </div>

    <div class="upd-actions" id="updActions" style="display:none;">
      <button class="btn btn-primary" id="updInstall">立即更新</button>
      <button class="btn btn-outline" id="updCancel">取消</button>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">下载加速</div>
  <div class="card-body">
    <div class="upd-note" style="margin-bottom:14px;">
      直连 GitHub 较慢时，可填写加速前缀。系统会拼成
      <code>https://加速镜像/https://github.com/...</code> 的形式请求；下载失败会自动回退直连。
    </div>
    <div class="upd-form">
      <div>
        <label for="updProxy">下载加速源</label>
        <input class="form-control" id="updProxy" placeholder="https://ghfast.top" value="<?= htmlspecialchars($配置['proxy']) ?>">
        <div class="upd-preset">
          <button type="button" data-proxy="">直连</button>
          <button type="button" data-proxy="https://ghfast.top">ghfast.top</button>
          <button type="button" data-proxy="https://ghproxy.net">ghproxy.net</button>
          <button type="button" data-proxy="https://gh-proxy.com">gh-proxy.com</button>
        </div>
        <div class="hint">留空表示直连。也可写成 <code>https://your-mirror/{url}</code> 自定义格式。</div>
      </div>
      <div>
        <label for="updApiProxy">API 加速源（可选）</label>
        <input class="form-control" id="updApiProxy" placeholder="留空直连 api.github.com" value="<?= htmlspecialchars($配置['api_proxy']) ?>">
        <div class="hint">仅当检查更新本身很慢或被限流时填写，一般无需设置。</div>
      </div>
      <div>
        <label for="updRepoInput">GitHub 仓库</label>
        <input class="form-control" id="updRepoInput" placeholder="owner/repo" value="<?= htmlspecialchars($配置['repo']) ?>">
        <div class="hint">默认 <code><?= htmlspecialchars(更新默认仓库) ?></code>，填写自己的 fork 可切换更新通道。</div>
      </div>
      <div>
        <label for="updHint">安装包关键词（可选）</label>
        <input class="form-control" id="updHint" placeholder="例如 QQbot" value="<?= htmlspecialchars($配置['asset_hint']) ?>">
        <div class="hint">一个 Release 有多个 zip 时，优先选择名称包含该关键词的包。</div>
      </div>
    </div>
    <div class="upd-actions">
      <button class="btn btn-outline" id="updSave">保存设置</button>
      <span class="upd-note" id="updSaveMsg" style="align-self:center;"></span>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">更新备份</div>
  <div class="card-body">
    <?php if (!$备份): ?>
      <div class="upd-note">暂无备份。每次在线更新前，系统会把将被覆盖的文件复制到 <code>data/update_backups/</code>，只保留最近 3 次。</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>备份时间</th><th>目录</th><th>文件数</th></tr></thead>
          <tbody>
          <?php foreach ($备份 as $b): ?>
            <tr>
              <td><?= htmlspecialchars($b['time']) ?></td>
              <td><code style="font-size:12px;">data/update_backups/<?= htmlspecialchars($b['name']) ?></code></td>
              <td><?= (int)$b['files'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="upd-note" style="margin-top:10px;">需要回滚时，把对应目录内的文件按相对路径覆盖回站点根目录即可。</div>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var API = 'api/update.php';
  var current = <?= json_encode($当前版本) ?>;
  var latest = null;
  var busy = false;

  var panel = document.getElementById('updPanel');
  var bar = document.getElementById('updBar');
  var steps = document.getElementById('updSteps');
  var actions = document.getElementById('updActions');
  var btnCheck = document.getElementById('updCheck');
  var btnInstall = document.getElementById('updInstall');
  var btnCancel = document.getElementById('updCancel');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function size(n) {
    n = Number(n) || 0;
    if (n >= 1048576) return (n / 1048576).toFixed(1) + ' MB';
    if (n >= 1024) return (n / 1024).toFixed(0) + ' KB';
    return n + ' B';
  }
  function dateText(s) {
    if (!s) return '';
    var d = new Date(s);
    if (isNaN(d.getTime())) return s;
    function p(x) { return x < 10 ? '0' + x : '' + x; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }
  function setStep(n, cls) {
    var el = steps.querySelector('[data-step="' + n + '"]');
    if (el) { el.className = 'upd-step ' + (cls || ''); }
  }
  function resetSteps() {
    steps.querySelectorAll('.upd-step').forEach(function (el) { el.className = 'upd-step'; });
  }
  function setBusy(on) {
    busy = on;
    btnCheck.disabled = on;
    btnInstall.disabled = on;
    bar.className = on ? 'upd-bar on' : 'upd-bar';
  }

  function call(action, data) {
    var opts = { method: data ? 'POST' : 'GET', headers: {} };
    if (data) {
      opts.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
      opts.body = new URLSearchParams(data).toString();
    }
    return fetch(API + '?action=' + action, opts).then(function (r) {
      return r.json().catch(function () { return { success: false, message: '服务器返回异常（HTTP ' + r.status + '）' }; });
    });
  }

  function showError(msg) {
    panel.innerHTML = '<div class="upd-err">' + esc(msg) + '</div>';
  }

  function renderRelease(d) {
    latest = d;
    document.getElementById('updLatest').textContent = 'v' + d.version;
    document.getElementById('updLatestSub').textContent = d.has_update ? '有新版本可用' : '已是最新版本';

    var html = '';
    html += '<div class="upd-release-head">';
    html += '  <div><div class="ver">v' + esc(d.version) + '</div>';
    html += '    <div class="meta">';
    html += '      <span class="tag">' + esc(d.tag) + '</span>';
    if (d.published_at) html += '      <span style="margin-left:8px;">发布于 ' + esc(dateText(d.published_at)) + '</span>';
    if (d.asset && d.asset.name) html += '      <span style="margin-left:8px;">安装包 ' + esc(d.asset.name) + (d.asset.size ? ' · ' + size(d.asset.size) : '') + '</span>';
    html += '    </div>';
    html += '  </div>';
    html += '  <div class="grow">';
    if (d.html_url) html += '<a class="btn btn-outline btn-sm" href="' + esc(d.html_url) + '" target="_blank" rel="noopener">Release 页面</a>';
    html += d.has_update
      ? '<span class="badge badge-warning">可更新</span>'
      : '<span class="badge badge-success">已是最新</span>';
    html += '  </div>';
    html += '</div>';

    if (d.body) {
      html += '<pre class="upd-notes">' + esc(d.body) + '</pre>';
    } else {
      html += '<div class="upd-note">该版本没有填写更新说明。</div>';
    }

    if (d.has_update) {
      html += '<div class="upd-warn" style="margin-top:14px;">';
      html += '更新会覆盖程序文件，<b>data/、plugin/ 与 market/config.php 不会被改动</b>；被覆盖的旧文件会先备份到 data/update_backups/。';
      html += '</div>';
      actions.style.display = 'flex';
    } else {
      actions.style.display = 'none';
    }
    panel.innerHTML = html;
  }

  btnCheck.addEventListener('click', function () {
    if (busy) return;
    setBusy(true);
    panel.innerHTML = '<div class="upd-note">正在连接 GitHub Releases…</div>';
    actions.style.display = 'none';
    call('check').then(function (r) {
      setBusy(false);
      if (!r.success) { showError(r.message || '检查失败'); return; }
      renderRelease(r.data);
    }).catch(function (e) {
      setBusy(false);
      showError('请求失败：' + e.message);
    });
  });

  btnInstall.addEventListener('click', function () {
    if (busy || !latest) return;
    if (!confirm('将更新到 v' + latest.version + '，过程中会短暂覆盖程序文件。确定继续？')) return;
    setBusy(true);
    resetSteps();
    steps.style.display = 'flex';
    setStep(1, 'on');
    panel.innerHTML = '<div class="upd-note" id="updProgress">正在下载安装包，请勿关闭页面…</div>';
    actions.style.display = 'none';

    call('download', {}).then(function (r) {
      if (!r.success) throw new Error(r.message || '下载失败');
      setStep(1, 'done');
      setStep(2, 'done');
      setStep(3, 'on');
      var p = document.getElementById('updProgress');
      if (p) p.textContent = '正在备份并写入 ' + r.data.files + ' 个文件…';
      return call('apply', {});
    }).then(function (r2) {
      setBusy(false);
      if (!r2.success) throw new Error(r2.message || '更新失败');
      setStep(3, 'done');
      panel.innerHTML = '<div class="upd-ok">' + esc(r2.message) + '<br>刷新页面后生效；如有异常，可从 data/update_backups/ 手动回滚。</div>';
      document.getElementById('updCurrent').textContent = 'v' + (r2.data && r2.data.version ? r2.data.version : latest.version);
      document.getElementById('updLatestSub').textContent = '已更新';
      actions.style.display = 'none';
      setTimeout(function () { location.reload(); }, 2600);
    }).catch(function (e) {
      setBusy(false);
      steps.style.display = 'none';
      showError(e.message);
      actions.style.display = 'flex';
    });
  });

  btnCancel.addEventListener('click', function () {
    actions.style.display = 'none';
    panel.innerHTML = '<div class="upd-empty"><div class="big">已取消</div>随时可以重新检查更新。</div>';
  });

  document.getElementById('updSave').addEventListener('click', function () {
    var msg = document.getElementById('updSaveMsg');
    msg.textContent = '保存中…';
    call('save', {
      repo: document.getElementById('updRepoInput').value.trim(),
      proxy: document.getElementById('updProxy').value.trim(),
      api_proxy: document.getElementById('updApiProxy').value.trim(),
      asset_hint: document.getElementById('updHint').value.trim()
    }).then(function (r) {
      msg.textContent = r.success ? '已保存' : (r.message || '保存失败');
      msg.style.color = r.success ? '#2e7d32' : '#c62828';
      if (r.success) {
        document.getElementById('updRepo').textContent = document.getElementById('updRepoInput').value.trim() || '<?= htmlspecialchars(更新默认仓库) ?>';
      }
    }).catch(function (e) {
      msg.textContent = '保存失败：' + e.message;
      msg.style.color = '#c62828';
    });
  });

  document.querySelectorAll('.upd-preset button').forEach(function (b) {
    b.addEventListener('click', function () {
      document.getElementById('updProxy').value = b.getAttribute('data-proxy');
    });
  });
})();
</script>

<?php require_once(__DIR__ . '/footer.php'); ?>
