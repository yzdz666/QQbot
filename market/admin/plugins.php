<?php
/**
 * 插件市场 - 插件管理
 */
require_once dirname(__DIR__) . '/lib/bootstrap.php';
market_security_headers(true);
MarketAuth::requireAdmin();

$pdo = MarketDB::pdo();
$adminName = MarketAuth::current()['username'] ?? 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    market_same_origin_check();
    market_csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $pluginId = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'offline') {
            market_set_plugin_status($pluginId, 'offline', $adminName);
            market_flash('ok', '插件已下架，已安装的机器人不受影响。');
        } elseif ($action === 'online') {
            market_set_plugin_status($pluginId, 'approved', $adminName);
            market_flash('ok', '插件已重新上架。');
        } elseif ($action === 'feature') {
            $value = (int)($_POST['value'] ?? 0) ? 1 : 0;
            $pdo->prepare("UPDATE plugins SET featured = ? WHERE id = ?")->execute([$value, $pluginId]);
            market_audit($adminName, $value ? '设为精选' : '取消精选', '#' . $pluginId);
            market_flash('ok', $value ? '已设为精选' : '已取消精选');
        } elseif ($action === 'delete') {
            market_delete_plugin($pluginId, $adminName);
            market_flash('ok', '插件及其全部版本文件已删除。');
        }
    } catch (Throwable $e) {
        market_flash('danger', $e->getMessage());
    }
    header('Location: plugins.php');
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$size = 30;
$offset = ($page - 1) * $size;

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = "(p.name LIKE ? OR p.slug LIKE ? OR p.author LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($status, ['approved', 'pending', 'rejected', 'offline'], true)) {
    $where[] = "p.status = ?";
    $params[] = $status;
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM plugins p" . $whereSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT p.*,
        (SELECT v.version FROM versions v WHERE v.plugin_id=p.id AND v.status='approved' ORDER BY v.id DESC LIMIT 1) AS version,
        (SELECT COUNT(*) FROM versions v WHERE v.plugin_id=p.id AND v.status='pending') AS pending_count,
        (SELECT COUNT(*) FROM versions v WHERE v.plugin_id=p.id) AS version_count,
        (SELECT v.id FROM versions v WHERE v.plugin_id=p.id AND v.status='pending' ORDER BY v.id ASC LIMIT 1) AS pending_id
     FROM plugins p" . $whereSql . " ORDER BY p.updated_at DESC, p.id DESC LIMIT ? OFFSET ?"
);
$stmt->execute(array_merge($params, [$size, $offset]));
$plugins = $stmt->fetchAll();
$pages = max(1, (int)ceil($total / $size));

$pageTitle = '插件管理';
require __DIR__ . '/header.php';
?>
<div class="panel" style="margin-bottom:18px;">
  <div class="panel-body" style="padding:14px 18px;">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <input class="form-control" type="text" name="q" placeholder="搜索名称 / slug / 作者" value="<?= market_h($q) ?>" style="max-width:280px;">
      <select class="form-control" name="status" style="max-width:160px;">
        <option value="">全部状态</option>
        <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>已上架</option>
        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>待审核</option>
        <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>已拒绝</option>
        <option value="offline" <?= $status === 'offline' ? 'selected' : '' ?>>已下架</option>
      </select>
      <button class="btn btn-primary" type="submit">筛选</button>
      <span class="muted small" style="margin-left:auto;">共 <?= $total ?> 个插件</span>
    </form>
  </div>
</div>

<div class="panel">
  <div class="table-wrap" style="border:0;border-radius:0;">
    <table class="data">
      <thead>
        <tr><th>插件</th><th>状态</th><th>最新版本</th><th>作者</th><th>下载</th><th>更新时间</th><th style="text-align:right;">操作</th></tr>
      </thead>
      <tbody>
      <?php if (empty($plugins)): ?>
        <tr><td colspan="7" class="muted" style="text-align:center;padding:40px;">暂无插件</td></tr>
      <?php endif; ?>
      <?php foreach ($plugins as $p): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:9px;">
              <span style="font-size:18px;"><?= market_h($p['icon'] ?: '🧩') ?></span>
              <div>
                <div style="font-weight:600;">
                  <?= market_h($p['name']) ?>
                  <?php if ((int)$p['featured'] === 1): ?><span class="badge badge-featured">精选</span><?php endif; ?>
                </div>
                <div class="small muted"><?= market_h($p['slug']) ?> · <?= (int)$p['version_count'] ?> 个版本</div>
              </div>
            </div>
          </td>
          <td>
            <?php
            $statusMap = ['approved' => ['已上架', 'badge-ok'], 'pending' => ['待审核', 'badge-warn'], 'rejected' => ['已拒绝', 'badge-danger'], 'offline' => ['已下架', 'badge-muted']];
            $sm = $statusMap[$p['status']] ?? ['未知', 'badge-muted'];
            ?>
            <span class="badge <?= $sm[1] ?>"><?= $sm[0] ?></span>
            <?php if ((int)$p['pending_count'] > 0): ?><span class="badge badge-warn"><?= (int)$p['pending_count'] ?> 待审</span><?php endif; ?>
          </td>
          <td><?= $p['version'] ? 'v' . market_h($p['version']) : '<span class="muted">-</span>' ?></td>
          <td class="muted"><?= market_h($p['author'] ?: '-') ?></td>
          <td><?= (int)$p['downloads'] ?></td>
          <td class="muted small"><?= market_h($p['updated_at']) ?></td>
          <td style="text-align:right;">
            <div class="table-actions" style="justify-content:flex-end;">
              <?php if ((int)$p['pending_count'] > 0 && $p['pending_id']): ?>
                <a class="btn btn-primary btn-sm" href="review.php?id=<?= (int)$p['pending_id'] ?>">审核</a>
              <?php endif; ?>
              <?php if ($p['status'] === 'approved'): ?>
                <a class="btn btn-ghost btn-sm" href="../plugin.php?id=<?= (int)$p['id'] ?>" target="_blank" rel="noopener">查看</a>
                <form method="post" class="inline-form" data-confirm="确认下架该插件？">
                  <input type="hidden" name="csrf_token" value="<?= market_h(market_csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="action" value="offline">
                  <button class="btn btn-warn btn-sm" type="submit">下架</button>
                </form>
              <?php elseif ($p['status'] === 'offline' || $p['status'] === 'rejected'): ?>
                <form method="post" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= market_h(market_csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="action" value="online">
                  <button class="btn btn-success btn-sm" type="submit">上架</button>
                </form>
              <?php endif; ?>
              <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= market_h(market_csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="action" value="feature">
                <input type="hidden" name="value" value="<?= (int)$p['featured'] === 1 ? 0 : 1 ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= (int)$p['featured'] === 1 ? '取消精选' : '精选' ?></button>
              </form>
              <form method="post" class="inline-form" data-confirm="删除后不可恢复，且会删除全部版本文件。确认删除该插件？">
                <input type="hidden" name="csrf_token" value="<?= market_h(market_csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="action" value="delete">
                <button class="btn btn-danger btn-sm" type="submit">删除</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($pages > 1): ?>
  <div class="pagination">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <?php if ($i === $page): ?><span class="current"><?= $i ?></span>
      <?php else: ?><a href="?page=<?= $i ?>&q=<?= urlencode($q) ?>&status=<?= urlencode($status) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
