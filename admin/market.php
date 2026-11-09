<?php
/**
 * 管理后台 - 插件市场
 * 浏览市场、查看源码、一键安装/更新/卸载
 */
$pageTitle = '插件市场';
require_once('header.php');

$base = 市场地址();
$q = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'featured');
$page = max(1, (int)($_GET['page'] ?? 1));
$check = (string)($_GET['check'] ?? '') === '1';

// 已安装记录
$installedRows = [];
try {
    $installedRows = db()->fetchAll("SELECT * FROM market_installed ORDER BY plugin_name");
} catch (Exception $e) {}
$installedByName = [];
foreach ($installedRows as $row) {
    $installedByName[$row['plugin_name']] = $row;
}

// 拉取市场列表
$list = 市场API($base, 'list', [
    'q' => $q,
    'category' => $category,
    'sort' => $sort,
    'page' => $page,
    'limit' => 12,
], 8);
$marketOk = !empty($list['success']);
$items = $marketOk ? ($list['data']['items'] ?? []) : [];
$total = $marketOk ? (int)($list['data']['total'] ?? 0) : 0;
$pages = $marketOk ? (int)($list['data']['pages'] ?? 1) : 1;
$categories = $marketOk ? ($list['data']['categories'] ?? []) : [];
$stats = $marketOk ? ($list['data']['stats'] ?? []) : [];

// 市场最新版本映射（用于更新提示）
$latestBySlug = [];
foreach ($items as $item) {
    $latestBySlug[$item['slug']] = $item;
}

$updates = 0;
foreach ($installedRows as $row) {
    $latest = $latestBySlug[$row['slug']] ?? null;
    if ($latest && version_compare($row['version'], $latest['version'], '<')) {
        $updates++;
    }
}

// 主动检查更新（逐个请求，限时）
$checkResult = null;
if ($check && $marketOk && !empty($installedRows)) {
    $found = 0;
    foreach ($installedRows as $row) {
        $res = 市场API($base, 'check', ['slug' => $row['slug'], 'version' => $row['version']], 5);
        if (!empty($res['success']) && !empty($res['data']['has_update'])) {
            $found++;
            $latestBySlug[$row['slug']] = array_merge(
                $latestBySlug[$row['slug']] ?? [],
                ['version' => $res['data']['latest']]
            );
        }
    }
    $updates = $found;
    $checkResult = $found > 0 ? "发现 {$found} 个可更新插件" : '所有市场插件均为最新版本';
}

/** 首字标记，替代 emoji 图标 */
function mk_monogram($name) {
    $name = trim((string)$name);
    if ($name === '') return '·';
    return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
}
?>
<style>
/* ========== 插件市场（沿用后台控制台设计语言） ========== */
.mk-stats {
  display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1px;
  background:var(--line); border:1px solid var(--line); border-radius:var(--radius);
  overflow:hidden; margin-bottom:14px;
}
.mk-stat { padding:14px 16px; min-height:84px; display:block; background:var(--surface); border:0; border-radius:0; }
.mk-stat::after { display:none; }
.mk-stat .stat-label { font-size:11.5px; color:var(--ink-3); margin-bottom:7px; }
.mk-stat .stat-value {
  font-family:var(--mono); font-size:24px; line-height:1.1; color:var(--ink);
  font-weight:600; letter-spacing:-.02em; display:block;
}
.mk-stat-icon { display:none; }

/* 市场源 */
.mk-source { margin-bottom:14px; }
.mk-source-line { min-height:46px; padding:10px 16px; display:flex; align-items:center; gap:9px; flex-wrap:wrap; }
.mk-dot { width:6px; height:6px; background:var(--ok); flex:0 0 6px; }
.mk-dot.off { background:var(--danger); }
.mk-source-label { font-size:12.5px; font-weight:600; color:var(--ink); }
.mk-source-url {
  font-family:var(--mono); font-size:11.5px; color:var(--ink-2);
  background:var(--surface-2); border:1px solid var(--line); border-radius:2px;
  padding:2px 7px; max-width:44vw; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.mk-source-meta { font-size:11.5px; color:var(--ink-3); }
.mk-source-actions { margin-left:auto; display:flex; gap:6px; }
.mk-source-edit { display:none; padding:12px 16px; border-top:1px solid var(--line); background:var(--surface-2); }
.mk-source-edit.open { display:block; }
.mk-source-form { display:flex; gap:8px; flex-wrap:wrap; }
.mk-source-form .form-control { flex:1; min-width:240px; height:32px; }

/* 工具栏 */
.mk-toolbar { display:flex; align-items:center; gap:8px; padding:10px 16px; border-bottom:1px solid var(--line); flex-wrap:wrap; }
.mk-search { position:relative; flex:1; min-width:200px; max-width:340px; }
.mk-search svg { position:absolute; left:9px; top:50%; transform:translateY(-50%); color:var(--ink-3); pointer-events:none; }
.mk-search .form-control { padding-left:30px; height:32px; }
.mk-filters { display:flex; gap:8px; margin-left:auto; }
.mk-filters .form-control { height:32px; width:auto; min-width:108px; padding-right:26px; }
.mk-total { font-size:11px; color:var(--ink-3); white-space:nowrap; }

/* 列表 */
.mk-list { display:flex; flex-direction:column; }
.mk-row { display:flex; gap:12px; padding:12px 16px; border-bottom:1px solid var(--line); transition:background .14s ease; }
.mk-row:last-child { border-bottom:0; }
.mk-row:hover { background:var(--surface-2); }
.mk-mono {
  width:32px; height:32px; flex:0 0 32px; border-radius:2px;
  background:var(--surface-3); border:1px solid var(--line);
  color:var(--ink-2); display:grid; place-items:center;
  font-family:var(--mono); font-size:13px; font-weight:600; user-select:none;
}
.mk-main { min-width:0; flex:1; }
.mk-line1 { display:flex; align-items:center; gap:7px; flex-wrap:wrap; }
.mk-name { font-size:13px; font-weight:600; color:var(--ink); text-decoration:none; cursor:pointer; }
.mk-name:hover { color:var(--brand); text-decoration:underline; }
.mk-summary { margin-top:4px; font-size:12px; color:var(--ink-2); line-height:1.6; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
.mk-meta { margin-top:5px; display:flex; align-items:center; gap:7px; flex-wrap:wrap; font-size:11px; color:var(--ink-3); }
.mk-meta i { font-style:normal; color:var(--line-2); }
.mk-actions { display:flex; align-items:center; gap:6px; flex:0 0 auto; align-self:center; }
.mk-installed { min-width:700px; }
.mk-installed th, .mk-installed td { white-space:nowrap; }
.mk-installed th:nth-child(1) { width:36%; }
.mk-installed th:nth-child(2) { width:15%; }
.mk-installed th:nth-child(3) { width:13%; }
.mk-installed th:nth-child(4) { width:20%; }
.mk-danger { border-color:#e3c4be; color:var(--danger); }
.mk-danger:hover { border-color:#d3a89f; background:var(--danger-soft); color:var(--danger); }

/* 分页 */
.mk-pager { display:flex; align-items:center; justify-content:center; gap:8px; padding:12px; border-top:1px solid var(--line); }
.mk-pager-info { font-size:11px; color:var(--ink-3); }
.mk-pager .btn.is-disabled { opacity:.45; pointer-events:none; }

/* 空态 / 提示 */
.mk-empty { padding:40px 20px; text-align:center; color:var(--ink-3); font-size:12px; }
.mk-empty svg { color:var(--ink-3); opacity:.5; margin-bottom:8px; }
.mk-empty p { margin:0 0 4px; }
.mk-empty .form-hint { font-size:11px; }
.mk-hint {
  display:flex; align-items:center; gap:9px; padding:10px 14px; margin-bottom:14px;
  background:var(--surface); border:1px solid var(--line); border-left:3px solid var(--brand-line);
  border-radius:var(--radius-sm); font-size:11.5px; color:var(--ink-2); line-height:1.6;
}
.mk-hint svg { color:var(--ink-3); flex:0 0 14px; }

/* 弹窗 */
.mk-modal .modal { width:800px; max-width:94%; max-height:88vh; }
.mk-modal .modal-header h3 { font-size:13.5px; }
.mk-modal-source .modal { width:900px; }
.mk-modal .modal-body { padding:16px 18px; }
.mk-modal .modal-footer { padding:11px 18px; }
.mk-detail-head { display:flex; gap:12px; align-items:flex-start; padding-bottom:13px; margin-bottom:14px; border-bottom:1px solid var(--line); }
.mk-detail-head .mk-mono { width:40px; height:40px; flex:0 0 40px; font-size:16px; }
.mk-detail-title { font-size:14px; font-weight:600; color:var(--ink); }
.mk-detail-sub { margin-top:3px; font-size:11.5px; color:var(--ink-3); }
.mk-code { margin:0; background:var(--surface-2); border:1px solid var(--line); border-radius:2px; padding:12px; overflow:auto; max-height:58vh; }
.mk-code code { font-family:var(--mono); font-size:11.5px; line-height:1.75; background:transparent; padding:0; }
.mk-code code.hljs { background:transparent; padding:0; }
.mk-scan { margin-bottom:10px; display:flex; flex-direction:column; gap:5px; }
.mk-scan-item { padding:5px 9px; border-radius:2px; font-size:11px; border:1px solid transparent; }
.mk-scan-item.block { background:var(--danger-soft); color:var(--danger); border-color:#ecd2cc; }
.mk-scan-item.warn { background:var(--warn-soft); color:var(--warn); border-color:#e8d9b6; }
.mk-scan-ok { font-size:11px; color:var(--ink-3); padding:5px 0; }

/* 详情 markdown */
.mk-md { font-size:12.5px; line-height:1.8; color:var(--ink-2); }
.mk-md h1, .mk-md h2 { font-size:14px; font-weight:600; color:var(--ink); margin:16px 0 6px; }
.mk-md h3, .mk-md h4 { font-size:13px; font-weight:600; color:var(--ink); margin:12px 0 5px; }
.mk-md > :first-child { margin-top:0; }
.mk-md p { margin:0 0 8px; }
.mk-md ul, .mk-md ol { margin:0 0 8px; padding-left:18px; }
.mk-md li { margin-bottom:3px; }
.mk-md code { background:var(--surface-3); padding:1px 5px; border-radius:2px; font-size:11.5px; }
.mk-md pre { background:var(--surface-2); border:1px solid var(--line); border-radius:2px; padding:10px; overflow:auto; }
.mk-md pre code { background:transparent; padding:0; }
.mk-md table { border-collapse:collapse; width:100%; margin:8px 0; display:block; overflow-x:auto; }
.mk-md th, .mk-md td { border:1px solid var(--line); padding:6px 9px; text-align:left; font-size:11.5px; white-space:nowrap; }
.mk-md th { background:var(--surface-2); color:var(--ink-2); font-weight:600; }
.mk-md blockquote { margin:8px 0; padding:7px 12px; border-left:2px solid var(--brand-line); background:var(--surface-2); color:var(--ink-2); }
.mk-md img { max-width:100%; }
.mk-section-title { margin:18px 0 2px; padding-top:13px; border-top:1px solid var(--line); font-size:11.5px; font-weight:600; color:var(--ink-2); }
.mk-ver-list { margin-top:2px; }
.mk-ver-item { padding:7px 0; border-bottom:1px solid var(--line); font-size:12px; color:var(--ink-2); }
.mk-ver-item:last-child { border-bottom:0; }
.mk-ver-item .mk-ver-no { font-weight:600; color:var(--ink); font-family:var(--mono); font-size:11.5px; }
.mk-ver-item .mk-ver-sub { font-size:11px; color:var(--ink-3); }

/* 轻提示 */
.mk-toast { position:fixed; right:18px; bottom:18px; z-index:3000; display:flex; flex-direction:column; gap:8px; pointer-events:none; }
.mk-toast-item {
  background:var(--surface); border:1px solid var(--line-2); border-left:3px solid var(--brand);
  border-radius:2px; box-shadow:0 12px 30px rgba(25,30,26,.12);
  padding:9px 13px; font-size:12px; color:var(--ink); max-width:340px; line-height:1.6;
  animation:mkToastIn .18s ease;
}
.mk-toast-item.ok { border-left-color:var(--ok); }
.mk-toast-item.err { border-left-color:var(--danger); }
@keyframes mkToastIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
@media (prefers-reduced-motion: reduce) { .mk-toast-item { animation:none; } }

@media (max-width:1100px) { .mk-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:768px) {
  /* 窄屏只保留插件 / 版本 / 操作三列，避免横向滚动 */
  .mk-installed { min-width:0; }
  .mk-installed th:nth-child(3), .mk-installed td:nth-child(3),
  .mk-installed th:nth-child(4), .mk-installed td:nth-child(4) { display:none; }
  .mk-installed th:first-child { width:auto; }
  .mk-actions { width:100%; justify-content:flex-end; margin-top:2px; }
  .mk-row { flex-wrap:wrap; }
  .mk-filters { margin-left:0; width:100%; }
  .mk-filters .form-control { flex:1; }
  .mk-source-url { max-width:100%; }
  .mk-search { max-width:none; }
}
</style>

<div class="page-header">
  <h2>插件市场</h2>
  <div class="actions">
    <a class="btn btn-outline" href="market.php?check=1<?= $q !== '' ? '&q=' . urlencode($q) : '' ?><?= $category !== '' ? '&category=' . urlencode($category) : '' ?>">检查更新<?= $updates > 0 ? '（' . $updates . '）' : '' ?></a>
    <a class="btn btn-primary" href="<?= htmlspecialchars($base) ?>" target="_blank" rel="noopener">打开市场 ↗</a>
  </div>
</div>

<?php if ($checkResult): ?>
<div class="alert alert-info" style="margin-bottom:12px;"><?= htmlspecialchars($checkResult) ?></div>
<?php endif; ?>

<div class="mk-stats">
  <div class="stat-card mk-stat">
    <div>
      <div class="stat-label">市场插件</div>
      <div class="stat-value"><?= $marketOk ? (int)$total : '—' ?></div>
    </div>
    <div class="mk-stat-icon">
      <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M2 5.5L8 2L14 5.5V10.5L8 14L2 10.5V5.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M2 5.5L8 9L14 5.5M8 9V14" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
    </div>
  </div>
  <div class="stat-card mk-stat">
    <div>
      <div class="stat-label">已安装</div>
      <div class="stat-value"><?= count($installedRows) ?></div>
    </div>
    <div class="mk-stat-icon">
      <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M8 2.5V10M8 10L5 7M8 10L11 7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M2.5 11.5V13C2.5 13.3 2.7 13.5 3 13.5H13C13.3 13.5 13.5 13.3 13.5 13V11.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
    </div>
  </div>
  <div class="stat-card mk-stat <?= $updates > 0 ? 'warn' : 'ok' ?>">
    <div>
      <div class="stat-label">可更新</div>
      <div class="stat-value"><?= (int)$updates ?></div>
    </div>
    <div class="mk-stat-icon">
      <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M13.5 8C13.5 11.04 11.04 13.5 8 13.5C4.96 13.5 2.5 11.04 2.5 8C2.5 4.96 4.96 2.5 8 2.5C9.9 2.5 11.58 3.46 12.57 4.94" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M12.8 2.2V5.2H9.8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </div>
  </div>
  <div class="stat-card mk-stat">
    <div>
      <div class="stat-label">累计下载</div>
      <div class="stat-value"><?= $marketOk ? (int)($stats['downloads'] ?? 0) : '—' ?></div>
    </div>
    <div class="mk-stat-icon">
      <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M8 2V9.5M8 9.5L5.5 7M8 9.5L10.5 7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 12.5H13" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
    </div>
  </div>
</div>

<!-- 市场源 -->
<div class="card mk-source">
  <div class="mk-source-line">
    <span class="mk-dot <?= $marketOk ? '' : 'off' ?>"></span>
    <span class="mk-source-label">市场源</span>
    <code class="mk-source-url" title="<?= htmlspecialchars($base) ?>"><?= htmlspecialchars($base) ?></code>
    <span class="mk-source-meta">
      <?php if ($marketOk): ?>连接正常 · <?= (int)($stats['plugins'] ?? 0) ?> 个插件
      <?php else: ?>连接失败 · <?= htmlspecialchars(mb_substr((string)($list['message'] ?? '未知错误'), 0, 40, 'UTF-8')) ?>
      <?php endif; ?>
    </span>
    <div class="mk-source-actions">
      <button class="btn btn-outline btn-sm" type="button" id="mkSourceToggle">修改</button>
    </div>
  </div>
  <div class="mk-source-edit <?= $marketOk ? '' : 'open' ?>" id="mkSourceEdit">
    <div class="mk-source-form">
      <input type="text" id="marketBaseUrl" class="form-control" value="<?= htmlspecialchars($base) ?>" placeholder="https://你的域名/market" spellcheck="false">
      <button class="btn btn-primary btn-sm" id="saveSourceBtn" type="button">保存并连接</button>
      <button class="btn btn-outline btn-sm" id="testSourceBtn" type="button">测试连接</button>
    </div>
    <div class="form-hint" style="margin-top:7px;">默认连接官方在线市场；使用自建市场时填写完整地址（如 <code>https://你的域名/market</code>），建议 HTTPS。</div>
    <div id="sourceStatus" style="margin-top:7px;font-size:10px;"></div>
  </div>
</div>

<?php if (empty($installedRows)): ?>
<div class="mk-hint">
  <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.2" stroke="currentColor" stroke-width="1.3"/><path d="M8 7.2V11M8 5.2V5.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
  <span>还没有通过市场安装的插件。在下方列表点「安装」即可，系统会校验 SHA-256 并备份被覆盖的旧文件。</span>
</div>
<?php else: ?>
<div class="card mb-3">
  <div class="card-header">
    <h3>已安装的市场插件</h3>
    <span class="mk-total"><?= count($installedRows) ?> 个</span>
  </div>
  <div class="card-body no-padding">
    <div class="table-responsive">
      <table class="table mk-installed">
        <thead>
          <tr><th>插件</th><th>版本</th><th>来源</th><th>安装时间</th><th class="text-right">操作</th></tr>
        </thead>
        <tbody>
        <?php foreach ($installedRows as $row):
          $latest = $latestBySlug[$row['slug']] ?? null;
          $hasUpdate = $latest && version_compare($row['version'], $latest['version'], '<');
          $isLocal = substr((string)$row['source'], -6) === '#local';
        ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($row['plugin_name']) ?></strong>
              <div class="mk-meta" style="margin-top:1px;">plugin/<?= htmlspecialchars($row['plugin_name']) ?>.php</div>
            </td>
            <td>
              v<?= htmlspecialchars($row['version']) ?>
              <?php if ($hasUpdate): ?>
                <span class="mk-ver-next">→ v<?= htmlspecialchars($latest['version']) ?></span><span class="badge badge-warning">可更新</span>
              <?php endif; ?>
            </td>
            <td><span class="badge badge-secondary"><?= $isLocal ? '本地已有' : '市场' ?></span></td>
            <td class="text-muted" style="white-space:nowrap;"><?= htmlspecialchars(substr((string)$row['installed_at'], 0, 16)) ?></td>
            <td class="text-right" style="white-space:nowrap;">
              <?php if ($latest && !empty($latest['id'])): ?>
                <button class="btn btn-outline btn-sm" type="button" data-source="<?= htmlspecialchars($latest['id']) ?>" data-version="<?= htmlspecialchars((string)($latest['version'] ?? '')) ?>">源码</button>
                <?php if ($hasUpdate): ?>
                  <button class="btn btn-primary btn-sm" type="button" data-install="<?= htmlspecialchars($latest['id'] ?? '') ?>" data-name="<?= htmlspecialchars($row['plugin_name']) ?>" data-version="<?= htmlspecialchars((string)($latest['version'] ?? '')) ?>">更新</button>
                <?php endif; ?>
              <?php endif; ?>
              <button class="btn btn-outline btn-sm mk-danger" type="button" data-uninstall="<?= htmlspecialchars($row['plugin_name']) ?>">卸载</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- 市场插件 -->
<div class="card">
  <div class="mk-toolbar">
    <form method="get" class="mk-search" id="mkSearchForm">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="4.6" stroke="currentColor" stroke-width="1.5"/><path d="M10.6 10.6L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="搜索插件、作者或标签" autocomplete="off">
      <input type="hidden" name="category" id="mkCatInput" value="<?= htmlspecialchars($category) ?>">
      <input type="hidden" name="sort" id="mkSortInput" value="<?= htmlspecialchars($sort) ?>">
    </form>
    <div class="mk-filters">
      <select class="form-control" id="mkCatSelect" aria-label="分类">
        <option value="">全部分类</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= htmlspecialchars($cat['category']) ?>" <?= $category === $cat['category'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['category']) ?>（<?= (int)$cat['c'] ?>）</option>
        <?php endforeach; ?>
      </select>
      <select class="form-control" id="mkSortSelect" aria-label="排序">
        <option value="featured" <?= $sort === 'featured' ? 'selected' : '' ?>>推荐排序</option>
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>最新发布</option>
        <option value="downloads" <?= $sort === 'downloads' ? 'selected' : '' ?>>下载最多</option>
        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>按名称</option>
      </select>
    </div>
    <span class="mk-total">共 <?= $marketOk ? (int)$total : 0 ?> 个</span>
  </div>

  <?php if (!$marketOk): ?>
    <div class="mk-empty">
      <svg width="26" height="26" viewBox="0 0 16 16" fill="none"><path d="M8 2L14.5 13.5H1.5L8 2Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M8 6.5V9M8 10.8V11.1" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
      <p><?= htmlspecialchars($list['message'] ?? '无法连接插件市场') ?></p>
      <div class="form-hint">请确认 <code>market/</code> 已部署、服务可访问，或在「市场源」中检查地址。</div>
    </div>
  <?php elseif (empty($items)): ?>
    <div class="mk-empty">
      <svg width="26" height="26" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="4.6" stroke="currentColor" stroke-width="1.2"/><path d="M10.6 10.6L14 14" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
      <p>没有找到符合条件的插件</p>
      <div class="form-hint"><a href="market.php">清除筛选条件</a></div>
    </div>
  <?php else: ?>
    <div class="mk-list">
      <?php foreach ($items as $item):
        $inst = null;
        foreach ($installedRows as $row) {
            if ($row['slug'] === $item['slug']) { $inst = $row; break; }
        }
        $hasUpdate = $inst && version_compare($inst['version'], $item['version'], '<');
        $isLatest = $inst && !$hasUpdate && $inst['version'] === $item['version'];
        $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];
      ?>
        <div class="mk-row">
          <div class="mk-mono"><?= htmlspecialchars(mk_monogram($item['name'])) ?></div>
          <div class="mk-main">
            <div class="mk-line1">
              <a class="mk-name" data-detail="<?= htmlspecialchars($item['id']) ?>"><?= htmlspecialchars($item['name']) ?></a>
              <?php if ((int)$item['featured'] === 1): ?><span class="badge badge-brand">精选</span><?php endif; ?>
              <?php if ($isLatest): ?><span class="badge badge-success">已安装</span>
              <?php elseif ($hasUpdate): ?><span class="badge badge-warning">可更新</span><?php endif; ?>
            </div>
            <div class="mk-summary"><?= htmlspecialchars($item['summary'] ?: '暂无简介') ?></div>
            <div class="mk-meta">
              <span>v<?= htmlspecialchars($item['version']) ?></span><i>·</i>
              <span>@<?= htmlspecialchars($item['author'] ?: '匿名') ?></span><i>·</i>
              <span><?= htmlspecialchars($item['category']) ?></span><i>·</i>
              <span><?= htmlspecialchars(格式化大小($item['file_size'] ?? 0)) ?></span><i>·</i>
              <span>↓ <?= (int)$item['downloads'] ?></span>
              <?php if ($tags): ?><i>·</i><span><?= htmlspecialchars(implode(' / ', array_slice($tags, 0, 2))) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="mk-actions">
            <button class="btn btn-outline btn-sm" type="button" data-detail="<?= htmlspecialchars($item['id']) ?>">详情</button>
            <button class="btn btn-outline btn-sm" type="button" data-source="<?= htmlspecialchars($item['id']) ?>" data-version="<?= htmlspecialchars((string)$item['version']) ?>">源码</button>
            <?php if ($hasUpdate): ?>
              <button class="btn btn-primary btn-sm" type="button" data-install="<?= htmlspecialchars($item['id']) ?>" data-name="<?= htmlspecialchars($item['name']) ?>" data-version="<?= htmlspecialchars((string)$item['version']) ?>">更新</button>
            <?php else: ?>
              <button class="btn btn-primary btn-sm" type="button" data-install="<?= htmlspecialchars($item['id']) ?>" data-name="<?= htmlspecialchars($item['name']) ?>" data-version="<?= htmlspecialchars((string)$item['version']) ?>"><?= $isLatest ? '重装' : '安装' ?></button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <div class="mk-pager">
        <?php
          $qs = function ($p) use ($q, $category, $sort) {
              return 'market.php?page=' . $p . '&q=' . urlencode($q) . '&category=' . urlencode($category) . '&sort=' . urlencode($sort);
          };
        ?>
        <a class="btn btn-outline btn-sm <?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars($qs(max(1, $page - 1))) ?>">上一页</a>
        <span class="mk-pager-info"><?= $page ?> / <?= $pages ?></span>
        <a class="btn btn-outline btn-sm <?= $page >= $pages ? 'is-disabled' : '' ?>" href="<?= htmlspecialchars($qs(min($pages, $page + 1))) ?>">下一页</a>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- 详情弹窗 -->
<div class="modal-overlay mk-modal" id="detailModal" style="display:none;">
  <div class="modal">
    <div class="modal-header">
      <h3 id="detailTitle">插件详情</h3>
      <button class="modal-close" type="button" data-close>&times;</button>
    </div>
    <div class="modal-body" id="detailBody"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" type="button" data-close>关闭</button>
    </div>
  </div>
</div>

<!-- 源码弹窗 -->
<div class="modal-overlay mk-modal mk-modal-source" id="sourceModal" style="display:none;">
  <div class="modal">
    <div class="modal-header">
      <h3 id="sourceTitle">插件源码</h3>
      <button class="modal-close" type="button" data-close>&times;</button>
    </div>
    <div class="modal-body">
      <div id="sourceScanBox"></div>
      <pre class="mk-code"><code id="sourceCode" class="language-php">加载中…</code></pre>
    </div>
    <div class="modal-footer">
      <span id="sourceScan" style="margin-right:auto;"></span>
      <button class="btn btn-outline" type="button" data-close>关闭</button>
    </div>
  </div>
</div>

<div class="mk-toast" id="mkToast"></div>

<link rel="stylesheet" href="assets/highlight/default.min.css">
<script src="assets/highlight/highlight.min.js"></script>
<script src="assets/highlight/php.min.js"></script>
<script>
(function () {
  var CSRF = window.ADMIN_CSRF || '';
  var toastBox = document.getElementById('mkToast');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function toast(msg, type, ms) {
    var el = document.createElement('div');
    el.className = 'mk-toast-item' + (type ? ' ' + type : '');
    el.textContent = msg;
    toastBox.appendChild(el);
    setTimeout(function () {
      el.style.transition = 'opacity .2s ease';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 220);
    }, ms || 2600);
  }

  function post(action, data) {
    var fd = new FormData();
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('api/market.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-CSRF-Token': CSRF }
    }).then(function (r) {
      return r.text().then(function (text) {
        try { return JSON.parse(text); }
        catch (e) { throw new Error('服务器返回异常（HTTP ' + r.status + '）：' + text.substring(0, 160)); }
      });
    });
  }

  function openModal(id) {
    var m = document.getElementById(id);
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
  function closeModal(el) {
    var m = el.closest ? el.closest('.modal-overlay') : null;
    if (m) m.style.display = 'none';
    if (!document.querySelector('.modal-overlay[style*="flex"]')) document.body.style.overflow = '';
  }

  document.addEventListener('click', function (e) {
    var t = e.target;

    if (t.closest('[data-close]')) { closeModal(t); return; }

    var ov = t.classList && t.classList.contains('modal-overlay') ? t : null;
    if (ov) { ov.style.display = 'none'; document.body.style.overflow = ''; return; }

    var detail = t.closest('[data-detail]');
    if (detail) { openDetail(detail.getAttribute('data-detail')); return; }

    var src = t.closest('[data-source]');
    if (src) { openSource(src.getAttribute('data-source'), src.getAttribute('data-version') || ''); return; }

    var ins = t.closest('[data-install]');
    if (ins) {
      installPlugin(ins.getAttribute('data-install'), ins.getAttribute('data-name'), ins.getAttribute('data-version'), false);
      return;
    }

    var un = t.closest('[data-uninstall]');
    if (un) { uninstallPlugin(un.getAttribute('data-uninstall')); return; }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-overlay').forEach(function (m) { m.style.display = 'none'; });
      document.body.style.overflow = '';
    }
  });

  // 市场源折叠
  var srcToggle = document.getElementById('mkSourceToggle');
  var srcEdit = document.getElementById('mkSourceEdit');
  if (srcToggle) {
    srcToggle.addEventListener('click', function () {
      var open = srcEdit.classList.toggle('open');
      srcToggle.textContent = open ? '收起' : '修改';
    });
  }

  document.getElementById('saveSourceBtn').addEventListener('click', function () {
    var btn = this;
    var url = document.getElementById('marketBaseUrl').value.trim();
    btn.disabled = true;
    var old = btn.textContent;
    btn.textContent = '连接中…';
    post('save_source', { url: url }).then(function (res) {
      if (!res.success) { toast(res.message || '保存失败', 'err', 4000); return; }
      toast('已保存，正在重新载入市场…', 'ok');
      setTimeout(function () { location.reload(); }, 700);
    }).catch(function (e) {
      toast(e.message, 'err', 5000);
    }).finally(function () {
      btn.disabled = false;
      btn.textContent = old;
    });
  });

  document.getElementById('testSourceBtn').addEventListener('click', function () {
    var btn = this;
    var url = document.getElementById('marketBaseUrl').value.trim();
    var box = document.getElementById('sourceStatus');
    btn.disabled = true;
    box.innerHTML = '<span style="color:#9aa5b4;">正在连接…</span>';
    post('test', { url: url }).then(function (res) {
      if (res.success) {
        box.innerHTML = '<span style="color:#19ad81;">连接正常</span>，共 ' + (res.data.total || 0) + ' 个插件';
      } else {
        box.innerHTML = '<span style="color:#e05a6b;">连接失败：' + esc(res.message) + '</span>';
      }
    }).catch(function (e) {
      box.innerHTML = '<span style="color:#e05a6b;">' + esc(e.message) + '</span>';
    }).finally(function () { btn.disabled = false; });
  });

  // 筛选
  var catSel = document.getElementById('mkCatSelect');
  var sortSel = document.getElementById('mkSortSelect');
  function applyFilter() {
    document.getElementById('mkCatInput').value = catSel.value;
    document.getElementById('mkSortInput').value = sortSel.value;
    document.getElementById('mkSearchForm').submit();
  }
  if (catSel) catSel.addEventListener('change', applyFilter);
  if (sortSel) sortSel.addEventListener('change', applyFilter);

  function openDetail(id) {
    var body = document.getElementById('detailBody');
    document.getElementById('detailTitle').textContent = '插件详情';
    body.innerHTML = '<p style="color:#9aa5b4;font-size:11px;margin:0;">加载中…</p>';
    openModal('detailModal');
    fetch('api/market.php?action=detail&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) { body.innerHTML = '<div class="alert alert-danger" style="margin:0;">' + esc(res.message) + '</div>'; return; }
        var p = res.data;
        var versions = (p.versions || []).map(function (v) {
          return '<div class="mk-ver-item"><span class="mk-ver-no">v' + esc(v.version) + '</span>' +
            '<span class="mk-ver-sub"> · ' + esc(v.created_at) + ' · ' + esc(v.downloads) + ' 次下载</span>' +
            (v.changelog ? '<div class="mk-ver-sub">' + esc(v.changelog) + '</div>' : '') + '</div>';
        }).join('');
        body.innerHTML =
          '<div class="mk-detail-head">' +
            '<div class="mk-mono">' + esc(p.name ? p.name.trim().charAt(0) : '·') + '</div>' +
            '<div>' +
              '<div class="mk-detail-title">' + esc(p.name) + '</div>' +
              '<div class="mk-detail-sub">v' + esc(p.version) + ' · @' + esc(p.author || '匿名') + ' · ' + esc(p.category) + ' · ↓ ' + esc(p.downloads) + '</div>' +
            '</div>' +
          '</div>' +
          '<div class="mk-md">' + (p.description_html || '<p style="color:#9aa5b4;">暂无详细介绍</p>') + '</div>' +
          '<div class="mk-section-title">版本历史</div>' +
          '<div class="mk-ver-list">' + (versions || '<div class="mk-ver-item" style="color:#9aa5b4;">暂无版本记录</div>') + '</div>' +
          '<div class="mk-detail-sub" style="margin-top:12px;word-break:break-all;">SHA-256 ' + esc(p.latest ? p.latest.sha256 : '-') + '</div>';
      })
      .catch(function (e) { body.innerHTML = '<div class="alert alert-danger" style="margin:0;">' + esc(e.message) + '</div>'; });
  }

  function openSource(id, version) {
    var codeEl = document.getElementById('sourceCode');
    var scanBox = document.getElementById('sourceScanBox');
    var scanTag = document.getElementById('sourceScan');
    document.getElementById('sourceTitle').textContent = '插件源码';
    scanTag.innerHTML = '';
    scanBox.innerHTML = '';
    codeEl.textContent = '加载中…';
    codeEl.className = 'language-php';
    openModal('sourceModal');
    fetch('api/market.php?action=source&id=' + encodeURIComponent(id) + '&version=' + encodeURIComponent(version || ''), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) { codeEl.textContent = res.message || '加载失败'; return; }
        document.getElementById('sourceTitle').textContent = res.data.name + ' v' + res.data.version + ' 源码';
        codeEl.textContent = res.data.source || '';
        if (window.hljs) { try { hljs.highlightElement(codeEl); } catch (e) {} }
        var s = res.data.scan || { status: 'pass', issues: [] };
        var cls = s.status === 'block' ? 'badge-danger' : (s.status === 'warn' ? 'badge-warning' : 'badge-success');
        var label = s.status === 'block' ? '检出高危调用' : (s.status === 'warn' ? '需人工确认' : '扫描通过');
        scanTag.innerHTML = '<span class="badge ' + cls + '">' + label + '</span>';
        if (s.issues && s.issues.length) {
          scanBox.className = 'mk-scan';
          scanBox.innerHTML = s.issues.map(function (it) {
            return '<div class="mk-scan-item ' + (it.level === 'block' ? 'block' : 'warn') + '">第 ' + it.line + ' 行 · ' + esc(it.message) + '</div>';
          }).join('');
        } else {
          scanBox.className = '';
          scanBox.innerHTML = '<div class="mk-scan-ok">未发现明显风险调用。仍建议结合业务逻辑审阅。</div>';
        }
      })
      .catch(function (e) { codeEl.textContent = e.message; });
  }

  function installPlugin(id, name, version, force) {
    if (!force && !window.confirm('安装「' + name + ' v' + version + '」？\n\n系统会校验 SHA-256、执行静态扫描，并备份同名旧文件。')) return;
    var fd = new FormData();
    fd.append('id', id);
    fd.append('version', version);
    fd.append('action', 'install');
    if (force) fd.append('force', '1');
    fetch('api/market.php?action=install', {
      method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF }
    }).then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) {
          toast(res.message || '安装成功', 'ok', 1800);
          setTimeout(function () { location.reload(); }, 800);
          return;
        }
        if (res.need_confirm) {
          if (window.confirm(res.message)) installPlugin(id, name, version, true);
          return;
        }
        toast(res.message || '安装失败', 'err', 6000);
      })
      .catch(function (e) { toast(e.message, 'err', 6000); });
  }

  function uninstallPlugin(name) {
    if (!window.confirm('卸载「' + name + '」？\n\n文件会先备份到 data/plugin_backups/，并从各机器人的插件状态中移除。')) return;
    post('uninstall', { name: name }).then(function (res) {
      if (res.success) {
        toast(res.message || '已卸载', 'ok', 1600);
        setTimeout(function () { location.reload(); }, 700);
      } else {
        toast(res.message || '卸载失败', 'err', 5000);
      }
    }).catch(function (e) { toast(e.message, 'err', 5000); });
  }
})();
</script>

<?php require_once('footer.php'); ?>
