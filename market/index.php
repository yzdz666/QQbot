<?php
/**
 * 官鸡插件市场 - 插件广场
 */
require_once __DIR__ . '/lib/bootstrap.php';
market_security_headers(false);

$pdo = MarketDB::pdo();
$q = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'featured');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

$where = [MarketDB::public_where()];
$params = [];
if ($q !== '') {
    $where[] = "(p.name LIKE ? OR p.summary LIKE ? OR p.author LIKE ? OR p.tags LIKE ?)";
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($category !== '' && $category !== '全部') {
    $where[] = "p.category = ?";
    $params[] = $category;
}
$whereSql = ' WHERE ' . implode(' AND ', $where);
$orderMap = [
    'featured' => 'p.featured DESC, p.downloads DESC, p.published_at DESC',
    'newest' => 'p.published_at DESC, p.id DESC',
    'downloads' => 'p.downloads DESC, p.id DESC',
    'name' => 'p.name ASC',
];
$order = $orderMap[$sort] ?? $orderMap['featured'];

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM plugins p" . $whereSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT p.*,
            (SELECT v.version FROM versions v WHERE v.plugin_id = p.id AND v.status='approved' ORDER BY v.id DESC LIMIT 1) AS version,
            (SELECT v.scan_status FROM versions v WHERE v.plugin_id = p.id AND v.status='approved' ORDER BY v.id DESC LIMIT 1) AS scan_status,
            (SELECT v.file_size FROM versions v WHERE v.plugin_id = p.id AND v.status='approved' ORDER BY v.id DESC LIMIT 1) AS file_size
     FROM plugins p" . $whereSql . " ORDER BY " . $order . " LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$limit, $offset]));
$items = $stmt->fetchAll();

$categories = market_categories();
$stats = market_stats();
$pages = max(1, (int)ceil($total / $limit));

$pageTitle = $q !== '' ? '搜索：' . $q : '插件广场';
$activeNav = 'home';
require __DIR__ . '/partials/header.php';

/** 构造保留筛选条件的 URL */
function market_url_with($overrides = []) {
    $params = array_merge($_GET, $overrides);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) unset($params[$k]);
    }
    return market_base_url() . '/index.php' . ($params ? '?' . http_build_query($params) : '');
}
?>

<section class="hero">
  <div class="container">
    <div class="hero-badge"><i></i> 插件均通过静态扫描与人工审核后上架</div>
    <h1>插件市场</h1>
    <p><?= market_h(market_setting('site_desc', '为官鸡 QQ 机器人框架提供插件浏览、源码审计与一键安装。')) ?></p>
    <div class="hero-stats">
      <div class="hero-stat"><strong><?= (int)$stats['plugins'] ?></strong><span>个插件</span></div>
      <div class="hero-stat"><strong><?= (int)$stats['downloads'] ?></strong><span>次下载</span></div>
      <div class="hero-stat"><strong><?= (int)$stats['authors'] ?></strong><span>位作者</span></div>
      <div class="hero-stat"><strong>100%</strong><span>人工审核</span></div>
    </div>
  </div>
</section>

<section class="section" id="plugins">
  <div class="container">
    <form class="searchbar" method="get" action="<?= market_h(market_base_url()) ?>/index.php">
      <input type="text" name="q" placeholder="搜索插件名称、简介、作者或标签…" value="<?= market_h($q) ?>">
      <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= market_h($category) ?>"><?php endif; ?>
      <?php if ($sort !== ''): ?><input type="hidden" name="sort" value="<?= market_h($sort) ?>"><?php endif; ?>
      <button class="btn btn-primary" type="submit">搜索</button>
    </form>

    <div class="filter-row">
      <a class="chip <?= $category === '' ? 'active' : '' ?>" href="<?= market_h(market_url_with(['category' => '', 'page' => 1])) ?>">全部</a>
      <?php foreach ($categories as $cat): ?>
        <a class="chip <?= $category === $cat['category'] ? 'active' : '' ?>" href="<?= market_h(market_url_with(['category' => $cat['category'], 'page' => 1])) ?>">
          <?= market_h($cat['category']) ?> <span class="muted"><?= (int)$cat['c'] ?></span>
        </a>
      <?php endforeach; ?>
      <select class="sort-select" data-nav-select aria-label="排序">
        <option value="<?= market_h(market_url_with(['sort' => 'featured', 'page' => 1])) ?>" <?= $sort === 'featured' ? 'selected' : '' ?>>推荐排序</option>
        <option value="<?= market_h(market_url_with(['sort' => 'newest', 'page' => 1])) ?>" <?= $sort === 'newest' ? 'selected' : '' ?>>最新上架</option>
        <option value="<?= market_h(market_url_with(['sort' => 'downloads', 'page' => 1])) ?>" <?= $sort === 'downloads' ? 'selected' : '' ?>>下载最多</option>
        <option value="<?= market_h(market_url_with(['sort' => 'name', 'page' => 1])) ?>" <?= $sort === 'name' ? 'selected' : '' ?>>名称排序</option>
      </select>
    </div>

    <?php if (market_setting('site_notice', '') !== ''): ?>
      <div class="alert alert-info"><?= market_h(market_setting('site_notice')) ?></div>
    <?php endif; ?>

    <?php if (empty($items)): ?>
      <div class="empty">
        <div class="icon">🔍</div>
        <p><?= $q !== '' ? '没有找到与「' . market_h($q) . '」相关的插件' : '暂无已上架插件' ?></p>
        <p class="small">换个关键词，或 <a href="<?= market_h(market_base_url()) ?>/submit.php" style="color:var(--brand)">发布第一个插件</a></p>
      </div>
    <?php else: ?>
      <div class="plugin-grid">
        <?php foreach ($items as $item): ?>
          <a class="plugin-card" href="<?= market_h(market_base_url()) ?>/plugin.php?id=<?= (int)$item['id'] ?>">
            <?php $首字 = trim((string)$item['name']); $首字 = $首字 !== '' ? mb_substr($首字, 0, 1, 'UTF-8') : 'P'; ?>
            <div class="plugin-card-head">
              <div class="plugin-icon"><?= market_h($首字) ?></div>
              <div class="plugin-title">
                <h3>
                  <span><?= market_h($item['name']) ?></span>
                  <?php if ((int)$item['featured'] === 1): ?><span class="badge badge-featured">精选</span><?php endif; ?>
                </h3>
                <div class="meta">
                  <span>@<?= market_h($item['author'] ?: '匿名') ?></span>
                  <span>·</span>
                  <span>v<?= market_h($item['version'] ?: '1.0.0') ?></span>
                  <span>·</span>
                  <span><?= market_h($item['category']) ?></span>
                  <span>·</span>
                  <span><?= (int)$item['downloads'] ?> 次下载</span>
                  <span>·</span>
                  <span><?= market_size($item['file_size']) ?></span>
                </div>
              </div>
            </div>
            <p class="plugin-summary"><?= market_h($item['summary'] ?: '暂无简介') ?></p>
            <div class="plugin-tags">
              <?php foreach (array_slice(array_filter(array_map('trim', explode(',', (string)$item['tags']))), 0, 3) as $tag): ?>
                <span class="tag"><?= market_h($tag) ?></span>
              <?php endforeach; ?>
              <?php if (($item['scan_status'] ?? '') === 'pass'): ?>
                <span class="tag tag-ok">已扫描</span>
              <?php endif; ?>
            </div>
            <div class="plugin-card-foot">
              <span class="detail-link">查看详情 →</span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if ($pages > 1): ?>
        <div class="pagination">
          <?php if ($page > 1): ?><a href="<?= market_h(market_url_with(['page' => $page - 1])) ?>">上一页</a><?php endif; ?>
          <?php
          $start = max(1, $page - 2);
          $end = min($pages, $page + 2);
          if ($start > 1) echo '<a href="' . market_h(market_url_with(['page' => 1])) . '">1</a><span>…</span>';
          for ($i = $start; $i <= $end; $i++) {
              if ($i === $page) echo '<span class="current">' . $i . '</span>';
              else echo '<a href="' . market_h(market_url_with(['page' => $i])) . '">' . $i . '</a>';
          }
          if ($end < $pages) echo '<span>…</span><a href="' . market_h(market_url_with(['page' => $pages])) . '">' . $pages . '</a>';
          ?>
          <?php if ($page < $pages): ?><a href="<?= market_h(market_url_with(['page' => $page + 1])) ?>">下一页</a><?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
