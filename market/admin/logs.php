<?php
/**
 * 插件市场 - 审计日志
 */
require_once dirname(__DIR__) . '/lib/bootstrap.php';
market_security_headers(true);
MarketAuth::requireAdmin();

$pdo = MarketDB::pdo();
$page = max(1, (int)($_GET['page'] ?? 1));
$size = 40;
$offset = ($page - 1) * $size;
$q = trim((string)($_GET['q'] ?? ''));

$where = '1=1';
$params = [];
if ($q !== '') {
    $where = "(action LIKE ? OR detail LIKE ? OR admin LIKE ? OR ip LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, $like, $like];
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE " . $where);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM audit_logs WHERE " . $where . " ORDER BY id DESC LIMIT ? OFFSET ?");
$stmt->execute(array_merge($params, [$size, $offset]));
$logs = $stmt->fetchAll();

$attempts = $pdo->query("SELECT * FROM login_attempts ORDER BY id DESC LIMIT 20")->fetchAll();
$pages = max(1, (int)ceil($total / $size));

$pageTitle = '审计日志';
require __DIR__ . '/header.php';
?>
<div class="panel" style="margin-bottom:18px;">
  <div class="panel-head">
    <h3>管理员操作记录</h3>
    <span class="grow" style="margin-left:auto;"></span>
    <form method="get" style="display:flex;gap:8px;">
      <input class="form-control" type="text" name="q" placeholder="搜索操作 / 详情 / IP" value="<?= market_h($q) ?>" style="height:32px;min-height:32px;width:220px;">
      <button class="btn btn-ghost btn-sm" type="submit">搜索</button>
    </form>
  </div>
  <div class="table-wrap" style="border:0;border-radius:0;">
    <table class="data">
      <thead><tr><th style="width:150px;">时间</th><th style="width:100px;">操作</th><th>详情</th><th style="width:110px;">管理员</th><th style="width:130px;">IP</th></tr></thead>
      <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="5" class="muted" style="text-align:center;padding:30px;">暂无记录</td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $log): ?>
        <tr>
          <td class="muted small"><?= market_h($log['created_at']) ?></td>
          <td><span class="badge badge-muted"><?= market_h($log['action']) ?></span></td>
          <td class="small" style="word-break:break-all;"><?= market_h($log['detail']) ?></td>
          <td class="muted"><?= market_h($log['admin'] ?: '-') ?></td>
          <td class="mono small muted"><?= market_h($log['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="pagination" style="padding:14px;">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
        <?php else: ?><a href="?page=<?= $i ?>&q=<?= urlencode($q) ?>"><?= $i ?></a><?php endif; ?>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head"><h3>最近登录尝试</h3></div>
  <div class="table-wrap" style="border:0;border-radius:0;">
    <table class="data">
      <thead><tr><th style="width:150px;">时间</th><th style="width:120px;">账号</th><th style="width:100px;">结果</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($attempts as $a): ?>
        <tr>
          <td class="muted small"><?= market_h($a['created_at']) ?></td>
          <td><?= market_h($a['username']) ?></td>
          <td><span class="badge <?= (int)$a['success'] === 1 ? 'badge-ok' : 'badge-danger' ?>"><?= (int)$a['success'] === 1 ? '成功' : '失败' ?></span></td>
          <td class="mono small muted"><?= market_h($a['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
