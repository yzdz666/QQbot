<?php
/**
 * 插件市场 - 审核详情
 */
require_once dirname(__DIR__) . '/lib/bootstrap.php';
market_security_headers(true);
MarketAuth::requireAdmin();

$pdo = MarketDB::pdo();
$adminName = MarketAuth::current()['username'] ?? 'admin';
$versionId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    market_same_origin_check();
    market_csrf_check();
    $action = (string)($_POST['action'] ?? '');

    $stmt = $pdo->prepare("SELECT v.*, p.id AS plugin_id, p.slug, p.name FROM versions v JOIN plugins p ON p.id = v.plugin_id WHERE v.id = ?");
    $stmt->execute([$versionId]);
    $row = $stmt->fetch();
    if (!$row) {
        market_flash('danger', '版本不存在');
        header('Location: index.php');
        exit;
    }

    try {
        if ($action === 'approve') {
            if ($row['status'] === 'approved') {
                throw new RuntimeException('该版本已经是上架状态');
            }
            if ($row['scan_status'] === 'block' && market_setting('allow_blocked', '0') !== '1') {
                throw new RuntimeException('该版本包含高危代码，已禁止通过。如确认安全，请先在安全设置中开启「允许高危插件」。');
            }
            market_approve_version($versionId, $adminName);
            market_flash('ok', '已通过审核并上架：' . $row['name'] . ' v' . $row['version']);
            header('Location: index.php');
            exit;
        }

        if ($action === 'reject') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (mb_strlen($reason, 'UTF-8') < 2) {
                throw new RuntimeException('请填写拒绝原因（至少 2 个字）');
            }
            market_reject_version($versionId, $reason, $adminName);
            market_flash('ok', '已拒绝：' . $row['name'] . ' v' . $row['version']);
            header('Location: index.php');
            exit;
        }

        if ($action === 'save_meta') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('插件名称不能为空');
            $pdo->prepare(
                "UPDATE plugins SET name = ?, summary = ?, category = ?, author = ?, tags = ?, icon = ?, description = ?, license = ?, homepage = ?, updated_at = datetime('now','localtime') WHERE id = ?"
            )->execute([
                $name,
                trim((string)($_POST['summary'] ?? '')),
                trim((string)($_POST['category'] ?? '其他')),
                trim((string)($_POST['author'] ?? '')),
                trim((string)($_POST['tags'] ?? '')),
                trim((string)($_POST['icon'] ?? '🧩')),
                trim((string)($_POST['description'] ?? '')),
                trim((string)($_POST['license'] ?? 'MIT')),
                trim((string)($_POST['homepage'] ?? '')),
                $row['plugin_id'],
            ]);
            market_audit($adminName, '编辑插件信息', $row['slug']);
            market_flash('ok', '插件信息已保存');
            header('Location: review.php?id=' . $versionId);
            exit;
        }
    } catch (Throwable $e) {
        market_flash('danger', $e->getMessage());
        header('Location: review.php?id=' . $versionId);
        exit;
    }
}

$stmt = $pdo->prepare(
    "SELECT v.*, p.id AS plugin_id, p.slug, p.name, p.summary, p.description, p.category, p.author, p.tags, p.icon,
            p.license, p.homepage, p.status AS plugin_status, p.downloads AS plugin_downloads, p.published_at
     FROM versions v JOIN plugins p ON p.id = v.plugin_id WHERE v.id = ?"
);
$stmt->execute([$versionId]);
$v = $stmt->fetch();
if (!$v) {
    market_flash('danger', '版本不存在');
    header('Location: index.php');
    exit;
}

$scan = json_decode((string)$v['scan_report'], true);
if (!is_array($scan)) $scan = ['status' => $v['scan_status'], 'issues' => [], 'summary' => ''];

// 读取源码
$source = '';
$path = realpath($v['file_path']);
$storage = realpath(MARKET_STORAGE_DIR);
if ($path && $storage && strpos($path, $storage . DIRECTORY_SEPARATOR) === 0 && is_file($path) && filesize($path) <= 1048576) {
    $source = (string)file_get_contents($path);
}

$stmt = $pdo->prepare("SELECT * FROM versions WHERE plugin_id = ? ORDER BY id DESC");
$stmt->execute([$v['plugin_id']]);
$history = $stmt->fetchAll();

$pageTitle = '审核：' . $v['name'];
require __DIR__ . '/header.php';

$lineCount = max(1, substr_count($source, "\n") + 1);
$lineNumbers = '';
for ($i = 1; $i <= $lineCount; $i++) { $lineNumbers .= $i . ($i < $lineCount ? "\n" : ''); }
?>
<div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;flex-wrap:wrap;">
  <a class="btn btn-ghost btn-sm" href="index.php">← 返回队列</a>
  <span class="badge <?= $v['status'] === 'approved' ? 'badge-ok' : ($v['status'] === 'rejected' ? 'badge-danger' : 'badge-warn') ?>">
    <?= $v['status'] === 'approved' ? '已通过' : ($v['status'] === 'rejected' ? '已拒绝' : '待审核') ?>
  </span>
  <span class="badge <?= $v['scan_status'] === 'pass' ? 'badge-ok' : ($v['scan_status'] === 'warn' ? 'badge-warn' : 'badge-danger') ?>">
    扫描：<?= $v['scan_status'] === 'pass' ? '通过' : ($v['scan_status'] === 'warn' ? '需人工确认' : '高风险') ?>
  </span>
  <span class="muted small">提交于 <?= market_h($v['created_at']) ?></span>
</div>

<div class="review-grid">
  <div>
    <div class="panel" style="margin-bottom:18px;">
      <div class="panel-head">
        <h3><?= market_h($v['icon'] ?: '🧩') ?> <?= market_h($v['name']) ?> <span class="muted" style="font-weight:400;">v<?= market_h($v['version']) ?></span></h3>
        <span class="grow" style="margin-left:auto;"></span>
        <a class="btn btn-ghost btn-sm" href="../plugin.php?id=<?= (int)$v['plugin_id'] ?>" target="_blank" rel="noopener">前台预览 ↗</a>
      </div>
      <div class="panel-body">
        <p class="muted" style="margin-top:0;"><?= market_h($v['summary'] ?: '暂无简介') ?></p>
        <div class="kv" style="grid-template-columns:1fr 1fr;display:grid;">
          <div><span>作者</span><b><?= market_h($v['author'] ?: '-') ?></b></div>
          <div><span>分类</span><b><?= market_h($v['category']) ?></b></div>
          <div><span>文件大小</span><b><?= market_size($v['file_size']) ?></b></div>
          <div><span>累计下载</span><b><?= (int)$v['plugin_downloads'] ?></b></div>
          <div><span>SHA-256</span><b class="mono small"><?= market_h(substr($v['sha256'], 0, 24)) ?>…</b></div>
          <div><span>更新说明</span><b><?= market_h($v['changelog'] ?: '-') ?></b></div>
        </div>
      </div>
    </div>

    <div class="panel" style="margin-bottom:18px;">
      <div class="panel-head">
        <h3>安全扫描</h3>
        <span class="grow" style="margin-left:auto;"></span>
        <span class="small muted"><?= market_h($scan['summary'] ?? '') ?></span>
      </div>
      <div class="panel-body">
        <?php if (empty($scan['issues'])): ?>
          <div class="alert alert-ok" style="margin:0;">未发现明显风险调用。静态扫描不替代人工审计，请结合源码判断。</div>
        <?php else: ?>
          <?php foreach ($scan['issues'] as $issue): ?>
            <div class="issue issue-<?= $issue['level'] === 'block' ? 'block' : 'warn' ?>">
              <strong><?= $issue['level'] === 'block' ? '阻止' : '提醒' ?></strong>
              · 第 <?= (int)$issue['line'] ?> 行 · <?= market_h($issue['message']) ?>
              <span class="mono small" style="opacity:.7;">[<?= market_h($issue['code']) ?>]</span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3>源码</h3><span class="grow" style="margin-left:auto;"></span><span class="small muted"><?= $lineCount ?> 行</span></div>
      <div class="code-wrap" style="max-height:620px;">
        <div class="code-flex">
          <div class="line-numbers" aria-hidden="true"><?= $lineNumbers ?></div>
          <pre><code id="review-source" class="language-php"><?= market_h($source) ?></code></pre>
        </div>
      </div>
    </div>
  </div>

  <aside>
    <?php if ($v['status'] === 'pending'): ?>
      <div class="panel" style="margin-bottom:18px;">
        <div class="panel-head"><h3>审核决定</h3></div>
        <div class="panel-body">
          <form method="post" style="margin-bottom:14px;">
            <input type="hidden" name="csrf_token" value="<?= market_h(market_csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
            <input type="hidden" name="action" value="approve">
            <button class="btn btn-success btn-block" type="submit" data-confirm="确认通过审核并立即上架？">通过并上架</button>
          </form>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= market_h(market_csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
            <input type="hidden" name="action" value="reject">
            <div class="form-row">
              <label>拒绝原因（会展示给开发者）</label>
              <textarea class="form-control" name="reason" rows="3" placeholder="例如：存在未授权网络请求 / 功能与描述不符"></textarea>
            </div>
            <button class="btn btn-danger btn-block" type="submit" data-confirm="确认拒绝该版本？">拒绝该版本</button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <div class="panel" style="margin-bottom:18px;">
      <div class="panel-head"><h3>插件信息</h3></div>
      <div class="panel-body">
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= market_h(market_csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
          <input type="hidden" name="action" value="save_meta">
          <div class="form-row"><label>名称</label><input class="form-control" type="text" name="name" value="<?= market_h($v['name']) ?>" required></div>
          <div class="form-row"><label>简介</label><input class="form-control" type="text" name="summary" value="<?= market_h($v['summary']) ?>"></div>
          <div class="form-row"><label>分类</label><input class="form-control" type="text" name="category" value="<?= market_h($v['category']) ?>"></div>
          <div class="form-row"><label>作者</label><input class="form-control" type="text" name="author" value="<?= market_h($v['author']) ?>"></div>
          <div class="form-row"><label>标签（逗号分隔）</label><input class="form-control" type="text" name="tags" value="<?= market_h($v['tags']) ?>"></div>
          <div class="form-row"><label>图标</label><input class="form-control" type="text" name="icon" value="<?= market_h($v['icon']) ?>"></div>
          <div class="form-row"><label>开源协议</label><input class="form-control" type="text" name="license" value="<?= market_h($v['license']) ?>"></div>
          <div class="form-row"><label>项目主页</label><input class="form-control" type="text" name="homepage" value="<?= market_h($v['homepage']) ?>"></div>
          <div class="form-row"><label>详细介绍（Markdown）</label><textarea class="form-control" name="description" rows="5"><?= market_h($v['description']) ?></textarea></div>
          <button class="btn btn-outline btn-block" type="submit">保存信息</button>
        </form>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3>版本历史</h3></div>
      <div class="panel-body" style="padding-top:6px;padding-bottom:6px;">
        <?php foreach ($history as $h): ?>
          <div class="version-item">
            <div style="display:flex;align-items:center;gap:8px;">
              <span class="v">v<?= market_h($h['version']) ?></span>
              <?php if ((int)$h['id'] === (int)$v['id']): ?><span class="badge badge-muted">当前</span><?php endif; ?>
              <span class="badge <?= $h['status'] === 'approved' ? 'badge-ok' : ($h['status'] === 'rejected' ? 'badge-danger' : 'badge-warn') ?>" style="margin-left:auto;">
                <?= $h['status'] === 'approved' ? '已通过' : ($h['status'] === 'rejected' ? '已拒绝' : '待审核') ?>
              </span>
            </div>
            <div class="d"><?= market_h($h['created_at']) ?></div>
            <?php if ($h['status'] !== 'pending'): ?>
              <div class="d"><a href="review.php?id=<?= (int)$h['id'] ?>" style="color:#8fb0ff;">查看</a></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </aside>
</div>

<link rel="stylesheet" href="../assets/highlight/default.min.css">
<script src="../assets/highlight/highlight.min.js"></script>
<script src="../assets/highlight/php.min.js"></script>
<script nonce="<?= market_nonce() ?>">
try { if (window.hljs) { hljs.highlightElement(document.getElementById('review-source')); } } catch (e) {}
</script>
<?php require __DIR__ . '/footer.php'; ?>
