<?php
/**
 * 官鸡插件市场 - 源码查看（仅已审核通过的版本）
 */
require_once __DIR__ . '/lib/bootstrap.php';
market_security_headers(false);

$id = $_GET['id'] ?? ($_GET['slug'] ?? '');
$plugin = MarketDB::plugin_with_latest($id);
if (!$plugin || $plugin['status'] !== 'approved') {
    http_response_code(404);
    exit('插件不存在或未通过审核');
}

$pdo = MarketDB::pdo();
$stmt = $pdo->prepare("SELECT * FROM versions WHERE plugin_id = ? AND status = 'approved' ORDER BY id DESC");
$stmt->execute([$plugin['id']]);
$versions = $stmt->fetchAll();
if (!$versions) {
    http_response_code(404);
    exit('没有可查看的已审核版本');
}

$version = trim((string)($_GET['version'] ?? ''));
$current = null;
if ($version === '') {
    // 默认展示最新已审核版本（versions 按 id DESC 排序）
    $current = $versions[0];
} else {
    foreach ($versions as $v) {
        if ($v['version'] === $version) { $current = $v; break; }
    }
}
if (!$current) {
    http_response_code(404);
    exit('指定版本不存在或未通过审核');
}

$path = realpath($current['file_path']);
$storage = realpath(MARKET_STORAGE_DIR);
if ($path === false || $storage === false || strpos($path, $storage . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(500);
    exit('插件文件不可用');
}
if (filesize($path) > 1048576) {
    http_response_code(413);
    exit('源码文件过大，请下载后查看');
}
$code = (string)file_get_contents($path);

$pageTitle = $plugin['name'] . ' 源码';
$activeNav = 'home';
require __DIR__ . '/partials/header.php';
$lineCount = max(1, substr_count($code, "\n") + 1);
$lineNumbers = '';
for ($i = 1; $i <= $lineCount; $i++) {
    $lineNumbers .= $i . ($i < $lineCount ? "\n" : '');
}
?>
<link rel="stylesheet" href="<?= market_h(market_base_url()) ?>/assets/highlight/default.min.css">
<div class="container" style="padding-bottom:50px;">
  <div class="breadcrumb">
    <a href="<?= market_h(market_base_url()) ?>/index.php">插件广场</a>
    <span>/</span>
    <a href="<?= market_h(market_base_url()) ?>/plugin.php?id=<?= (int)$plugin['id'] ?>"><?= market_h($plugin['name']) ?></a>
    <span>/</span>
    <span>源码</span>
  </div>

  <div class="detail-head" style="border-bottom:0;padding-bottom:16px;">
    <div class="detail-icon" style="width:58px;height:58px;font-size:26px;border-radius:16px;">&lt;/&gt;</div>
    <div>
      <h1 style="font-size:24px;"><?= market_h($plugin['name']) ?> · 源码</h1>
      <p class="summary" style="margin:0;">当前版本 v<?= market_h($current['version']) ?> · <?= market_size($current['file_size']) ?> · SHA-256 已校验</p>
    </div>
    <div class="detail-actions">
      <a class="btn btn-outline" href="<?= market_h(market_base_url()) ?>/plugin.php?id=<?= (int)$plugin['id'] ?>">返回详情</a>
      <a class="btn btn-primary" href="<?= market_h(market_base_url()) ?>/download.php?id=<?= (int)$plugin['id'] ?>&version=<?= urlencode($current['version']) ?>&format=php">下载此版本</a>
    </div>
  </div>

  <div class="panel">
    <div class="source-toolbar">
      <span class="filename"><?= market_h($plugin['slug']) ?>-<?= market_h($current['version']) ?>.php</span>
      <span class="badge <?= $current['scan_status'] === 'pass' ? 'badge-ok' : ($current['scan_status'] === 'warn' ? 'badge-warn' : 'badge-danger') ?>">
        <?= $current['scan_status'] === 'pass' ? '扫描通过' : ($current['scan_status'] === 'warn' ? '需人工确认' : '高风险') ?>
      </span>
      <?php if (count($versions) > 1): ?>
      <form method="get" style="display:inline-flex;gap:8px;align-items:center;">
        <input type="hidden" name="id" value="<?= (int)$plugin['id'] ?>">
        <select name="version" class="form-control" style="height:32px;min-height:32px;padding:4px 30px 4px 10px;width:auto;" data-auto-submit>
          <?php foreach ($versions as $v): ?>
            <option value="<?= market_h($v['version']) ?>" <?= $v['version'] === $current['version'] ? 'selected' : '' ?>>v<?= market_h($v['version']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
      <span class="grow"></span>
      <span class="small muted"><?= $lineCount ?> 行</span>
      <button class="btn btn-ghost btn-sm" type="button" id="copyBtn" data-target="source-code">复制源码</button>
    </div>
    <div class="code-wrap">
      <div class="code-flex">
        <div class="line-numbers" aria-hidden="true"><?= $lineNumbers ?></div>
        <pre><code id="source-code" class="language-php"><?= market_h($code) ?></code></pre>
      </div>
    </div>
  </div>
  <div class="alert alert-warn" style="margin-top:16px;">
    源码仅供审计参考。静态扫描无法覆盖所有攻击面，安装前请自行确认插件行为与权限。
  </div>
</div>
<script src="<?= market_h(market_base_url()) ?>/assets/highlight/highlight.min.js"></script>
<script src="<?= market_h(market_base_url()) ?>/assets/highlight/php.min.js"></script>
<script nonce="<?= market_nonce() ?>">
(function () {
  try { if (window.hljs) { hljs.highlightElement(document.getElementById('source-code')); } } catch (e) {}
  document.querySelectorAll('[data-auto-submit]').forEach(function (sel) {
    sel.addEventListener('change', function () { sel.form.submit(); });
  });
  var btn = document.getElementById('copyBtn');
  if (btn) {
    btn.addEventListener('click', function () {
      var el = document.getElementById(btn.getAttribute('data-target'));
      if (!el) return;
      var text = el.textContent;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          btn.textContent = '已复制';
          setTimeout(function () { btn.textContent = '复制源码'; }, 1600);
        });
      } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); document.body.removeChild(ta);
        btn.textContent = '已复制';
        setTimeout(function () { btn.textContent = '复制源码'; }, 1600);
      }
    });
  }
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
