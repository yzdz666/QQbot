<?php /** 市场前台公共底部 */ ?>
</main>
<footer class="site-footer">
  <div class="container inner">
    <div>
      <strong><?= market_h(market_setting('site_name', '官鸡插件市场')) ?></strong>
      <span class="muted"> · 插件均经人工审核后上架，安装前仍建议阅读源码</span>
    </div>
    <div>
      <a href="<?= market_h(market_base_url()) ?>/docs.php">接入说明</a>
      <span class="muted"> · </span>
      <a href="<?= market_h(market_base_url()) ?>/submit.php">发布插件</a>
      <span class="muted"> · </span>
      <a href="<?= market_h(market_base_url()) ?>/admin/">审核后台</a>
    </div>
  </div>
</footer>
</body>
</html>
