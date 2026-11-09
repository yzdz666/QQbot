<?php
/**
 * 官鸡插件市场 - 插件详情
 */
require_once __DIR__ . '/lib/bootstrap.php';
market_security_headers(false);

$id = $_GET['id'] ?? ($_GET['slug'] ?? '');
$plugin = MarketDB::plugin_with_latest($id);
if (!$plugin || $plugin['status'] !== 'approved' || empty($plugin['latest'])) {
    http_response_code(404);
    $pageTitle = '插件不存在';
    $activeNav = 'home';
    require __DIR__ . '/partials/header.php';
    echo '<div class="container section"><div class="empty"><div class="icon">🫥</div><p>插件不存在或尚未通过审核</p><p class="small"><a href="' . market_h(market_base_url()) . '/index.php" style="color:var(--brand)">返回插件广场</a></p></div></div>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

$pdo = MarketDB::pdo();
$stmt = $pdo->prepare("SELECT * FROM versions WHERE plugin_id = ? AND status = 'approved' ORDER BY id DESC");
$stmt->execute([$plugin['id']]);
$versions = $stmt->fetchAll();

$latest = $plugin['latest'];
$tags = array_filter(array_map('trim', explode(',', (string)$plugin['tags'])));
$pageTitle = $plugin['name'];
$activeNav = 'home';
require __DIR__ . '/partials/header.php';
?>
<div class="container">
  <div class="breadcrumb">
    <a href="<?= market_h(market_base_url()) ?>/index.php">插件广场</a>
    <span>/</span>
    <a href="<?= market_h(market_base_url()) ?>/index.php?category=<?= urlencode($plugin['category']) ?>"><?= market_h($plugin['category']) ?></a>
    <span>/</span>
    <span><?= market_h($plugin['name']) ?></span>
  </div>

  <div class="detail-head">
    <?php $首字 = trim((string)$plugin['name']); $首字 = $首字 !== '' ? mb_substr($首字, 0, 1, 'UTF-8') : 'P'; ?>
    <div class="detail-icon"><?= market_h($首字) ?></div>
    <div style="min-width:0;flex:1;">
      <h1>
        <?= market_h($plugin['name']) ?>
        <?php if ((int)$plugin['featured'] === 1): ?><span class="badge badge-featured">精选</span><?php endif; ?>
        <?php if (($latest['scan_status'] ?? '') === 'pass'): ?><span class="badge badge-ok">安全扫描通过</span><?php endif; ?>
      </h1>
      <p class="summary"><?= market_h($plugin['summary'] ?: '暂无简介') ?></p>
      <div class="detail-meta">
        <span>作者 <b style="color:var(--ink)"><?= market_h($plugin['author'] ?: '匿名') ?></b></span>
        <span>版本 <b style="color:var(--ink)">v<?= market_h($latest['version']) ?></b></span>
        <span>分类 <?= market_h($plugin['category']) ?></span>
        <span>更新 <?= market_h(market_time_ago($plugin['updated_at'])) ?></span>
        <span>下载 <?= (int)$plugin['downloads'] ?></span>
      </div>
    </div>
    <div class="detail-actions">
      <a class="btn btn-outline" href="<?= market_h(market_base_url()) ?>/source.php?id=<?= (int)$plugin['id'] ?>">查看源码</a>
      <a class="btn btn-primary" href="<?= market_h(market_base_url()) ?>/download.php?id=<?= (int)$plugin['id'] ?>&format=php">下载插件</a>
    </div>
  </div>

  <div class="detail-layout">
    <div>
      <div class="panel">
        <div class="panel-head"><h3>插件说明</h3></div>
        <div class="panel-body markdown">
          <?= market_markdown($plugin['description'] ?: $plugin['summary']) ?>
        </div>
      </div>

      <div class="panel" style="margin-top:18px;">
        <div class="panel-head"><h3>安装方式</h3></div>
        <div class="panel-body">
          <ol class="muted" style="margin:0;padding-left:20px;font-size:13.5px;line-height:2;">
            <li>登录机器人管理后台，进入左侧「插件市场」；</li>
            <li>在市场源中找到本插件，点击「安装」；</li>
            <li>系统会校验 SHA-256、执行静态安全扫描，并自动备份旧版本；</li>
            <li>安装完成后在「插件管理」中启用并按需配置。</li>
          </ol>
          <div class="alert alert-info" style="margin:16px 0 0;">
            手动安装：下载源码后放入机器人根目录的 <code>plugin/</code> 文件夹，文件名即插件名，例如 <code><?= market_h(market_safe_plugin_name($plugin['name']) ?: '插件名') ?>.php</code>。
          </div>
        </div>
      </div>
    </div>

    <aside>
      <div class="panel">
        <div class="panel-head"><h3>插件信息</h3></div>
        <div class="panel-body">
          <div class="side-block">
            <div class="side-list">
              <div class="side-item"><span>最新版本</span><b>v<?= market_h($latest['version']) ?></b></div>
              <div class="side-item"><span>文件大小</span><b><?= market_size($latest['file_size']) ?></b></div>
              <div class="side-item"><span>累计下载</span><b><?= (int)$plugin['downloads'] ?></b></div>
              <div class="side-item"><span>开源协议</span><b><?= market_h($plugin['license'] ?: 'MIT') ?></b></div>
              <div class="side-item"><span>上架时间</span><b><?= market_h($plugin['published_at'] ? date('Y-m-d', strtotime($plugin['published_at'])) : '-') ?></b></div>
            </div>
          </div>
          <div class="side-block">
            <div class="side-item" style="margin-bottom:8px;"><span>SHA-256</span></div>
            <div class="mono small" style="color:#8fa0c0;word-break:break-all;line-height:1.6;"><?= market_h($latest['sha256']) ?></div>
          </div>
          <?php if ($tags): ?>
          <div class="side-block">
            <div class="side-item" style="margin-bottom:8px;"><span>标签</span></div>
            <div class="plugin-tags">
              <?php foreach ($tags as $tag): ?><span class="tag"><?= market_h($tag) ?></span><?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
          <?php if (!empty($plugin['homepage'])): ?>
          <div class="side-block">
            <div class="side-item"><span>项目主页</span><b><a href="<?= market_h($plugin['homepage']) ?>" target="_blank" rel="noopener noreferrer nofollow" style="color:var(--brand)">访问</a></b></div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel" style="margin-top:18px;">
        <div class="panel-head"><h3>版本历史</h3></div>
        <div class="panel-body" style="padding-top:8px;padding-bottom:8px;">
          <?php foreach ($versions as $v): ?>
            <div class="version-item">
              <div class="v">v<?= market_h($v['version']) ?>
                <?php if ((int)$v['id'] === (int)$latest['id']): ?><span class="badge badge-ok" style="margin-left:6px;">最新</span><?php endif; ?>
              </div>
              <div class="d"><?= market_h(market_time_ago($v['created_at'])) ?> · <?= market_size($v['file_size']) ?> · <?= (int)$v['downloads'] ?> 次下载</div>
              <?php if (!empty($v['changelog'])): ?><div class="d" style="color:var(--ink-3)"><?= market_h($v['changelog']) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </aside>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
