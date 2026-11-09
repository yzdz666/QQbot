<?php
/**
 * 插件市场 - 后台概览
 */
require_once dirname(__DIR__) . '/lib/bootstrap.php';

$pdo = MarketDB::pdo();
$counts = [
    'plugins' => (int)$pdo->query("SELECT COUNT(*) FROM plugins")->fetchColumn(),
    'approved' => (int)$pdo->query("SELECT COUNT(*) FROM plugins WHERE status='approved'")->fetchColumn(),
    'pending_versions' => (int)$pdo->query("SELECT COUNT(*) FROM versions WHERE status='pending'")->fetchColumn(),
    'downloads' => (int)$pdo->query("SELECT COALESCE(SUM(downloads),0) FROM plugins")->fetchColumn(),
    'week' => (int)$pdo->query("SELECT COUNT(*) FROM versions WHERE created_at > datetime('now','localtime','-7 days')")->fetchColumn(),
];

$pending = $pdo->query(
    "SELECT v.*, p.name, p.slug, p.author, p.category, p.icon
     FROM versions v JOIN plugins p ON p.id = v.plugin_id
     WHERE v.status = 'pending' ORDER BY v.id ASC LIMIT 30"
)->fetchAll();

$recent = $pdo->query("SELECT * FROM audit_logs ORDER BY id DESC LIMIT 12")->fetchAll();

$pageTitle = '概览';
require __DIR__ . '/header.php';
?>

<?php if (market_setting('password_changed', '0') !== '1'): ?>
  <div class="alert alert-warn">
    <strong>安全提醒：</strong>当前仍在使用部署时的初始密码。请前往 <a href="settings.php" style="color:#fde68a;text-decoration:underline;">安全设置</a> 立即修改，否则任何知道默认密码的人都能审核插件。
  </div>
<?php endif; ?>
<?php if (!market_is_https()): ?>
  <div class="alert alert-info">当前站点未启用 HTTPS。建议为市场配置 SSL，避免管理员密码与插件源码在传输中被窃听。</div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat-card accent"><small>待审核版本</small><strong><?= $counts['pending_versions'] ?></strong></div>
  <div class="stat-card ok"><small>已上架插件</small><strong><?= $counts['approved'] ?></strong></div>
  <div class="stat-card"><small>插件总数</small><strong><?= $counts['plugins'] ?></strong></div>
  <div class="stat-card warn"><small>累计下载</small><strong><?= $counts['downloads'] ?></strong></div>
</div>

<div class="panel" style="margin-bottom:20px;">
  <div class="panel-head">
    <h3>审核队列</h3>
    <span class="muted small" style="margin-left:8px;">按提交时间从早到晚</span>
    <span class="grow" style="margin-left:auto;"></span>
    <a class="btn btn-outline btn-sm" href="plugins.php">全部插件</a>
  </div>
  <?php if (empty($pending)): ?>
    <div class="panel-body">
      <div class="empty" style="padding:40px 20px;">
        <div class="icon">✅</div>
        <p>审核队列已清空</p>
        <p class="small">开发者提交的新插件会出现在这里。</p>
      </div>
    </div>
  <?php else: ?>
    <div class="table-wrap" style="border:0;border-radius:0;">
      <table class="data">
        <thead>
          <tr><th>插件</th><th>版本</th><th>作者</th><th>扫描</th><th>提交时间</th><th style="text-align:right;">操作</th></tr>
        </thead>
        <tbody>
        <?php foreach ($pending as $v): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:9px;">
                <span style="font-size:18px;"><?= market_h($v['icon'] ?: '🧩') ?></span>
                <div>
                  <div style="font-weight:600;"><?= market_h($v['name']) ?></div>
                  <div class="small muted"><?= market_h($v['slug']) ?> · <?= market_h($v['category']) ?></div>
                </div>
              </div>
            </td>
            <td>v<?= market_h($v['version']) ?></td>
            <td class="muted"><?= market_h($v['author'] ?: '-') ?></td>
            <td>
              <span class="badge <?= $v['scan_status'] === 'pass' ? 'badge-ok' : ($v['scan_status'] === 'warn' ? 'badge-warn' : 'badge-danger') ?>">
                <?= $v['scan_status'] === 'pass' ? '通过' : ($v['scan_status'] === 'warn' ? '需确认' : '高风险') ?>
              </span>
            </td>
            <td class="muted small"><?= market_h($v['created_at']) ?></td>
            <td style="text-align:right;">
              <a class="btn btn-primary btn-sm" href="review.php?id=<?= (int)$v['id'] ?>">审核</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head"><h3>最近操作</h3><span class="grow" style="margin-left:auto;"></span><a class="btn btn-ghost btn-sm" href="logs.php">查看全部</a></div>
  <div class="panel-body" style="padding:8px 20px;">
    <?php if (empty($recent)): ?>
      <p class="muted small" style="padding:14px 0;">暂无操作记录。</p>
    <?php else: ?>
      <?php foreach ($recent as $log): ?>
        <div style="display:flex;gap:12px;padding:9px 0;border-bottom:1px solid var(--line);font-size:12.5px;">
          <span class="muted" style="width:132px;flex:0 0 132px;"><?= market_h($log['created_at']) ?></span>
          <span style="color:#cfe0ff;width:86px;flex:0 0 86px;"><?= market_h($log['action']) ?></span>
          <span class="muted" style="flex:1;word-break:break-all;"><?= market_h($log['detail']) ?></span>
          <span class="muted small"><?= market_h($log['admin']) ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
