<?php
/**
 * 官鸡插件市场 - 开发者提交插件
 * 支持上传 .php / .zip 或直接粘贴源码；首次提交返回管理密钥，用于后续发布新版本。
 */
require_once __DIR__ . '/lib/bootstrap.php';
market_security_headers(false);
market_session_start();

$errors = [];
$success = null;
$statusPlugin = null;
$statusVersions = [];
$newToken = '';

$categories = ['系统', '群管理', '娱乐', '工具', '消息', '开发工具', '其他'];

// 查询提交状态
$queryToken = trim((string)($_GET['token'] ?? ''));
if ($queryToken !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = MarketDB::pdo()->prepare("SELECT * FROM plugins WHERE manage_hash = ?");
    $stmt->execute([hash('sha256', $queryToken)]);
    $statusPlugin = $stmt->fetch();
    if ($statusPlugin) {
        $stmt = MarketDB::pdo()->prepare("SELECT * FROM versions WHERE plugin_id = ? ORDER BY id DESC");
        $stmt->execute([$statusPlugin['id']]);
        $statusVersions = $stmt->fetchAll();
    } else {
        $errors[] = '管理密钥无效，未找到对应插件。';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    market_same_origin_check();
    market_csrf_check();

    if (market_setting('upload_enabled', '1') !== '1') {
        $errors[] = '站点暂时关闭了插件提交，请稍后再试。';
    }
    if (!market_rate_limit('submit:' . market_client_ip(), 8, 3600)) {
        $errors[] = '提交过于频繁，请 1 小时后再试。';
    }
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        $errors[] = '提交被拦截。';
    }
    if (empty($_POST['agree'])) {
        $errors[] = '请先确认插件代码安全合规。';
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $version = trim((string)($_POST['version'] ?? ''));
    $summary = trim((string)($_POST['summary'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $category = trim((string)($_POST['category'] ?? '其他'));
    $author = trim((string)($_POST['author'] ?? ''));
    $authorLink = trim((string)($_POST['author_link'] ?? ''));
    $license = trim((string)($_POST['license'] ?? 'MIT'));
    $homepage = trim((string)($_POST['homepage'] ?? ''));
    $tags = trim((string)($_POST['tags'] ?? ''));
    $icon = trim((string)($_POST['icon'] ?? '🧩'));
    $changelog = trim((string)($_POST['changelog'] ?? ''));
    $manageToken = trim((string)($_POST['manage_token'] ?? ''));
    $code = (string)($_POST['code'] ?? '');

    $version = preg_replace('/^v/i', '', $version);
    if ($name === '') $errors[] = '请填写插件名称';
    if (mb_strlen($name, 'UTF-8') > 40) $errors[] = '插件名称不能超过 40 个字';
    if ($author === '') $errors[] = '请填写作者名称';
    if (!preg_match('/^\d{1,4}\.\d{1,4}(\.\d{1,4})?([\-+][0-9A-Za-z\.\-]{1,20})?$/', $version)) {
        $errors[] = '版本号格式应为 1.0.0（可带 -beta 后缀）';
    }
    if (mb_strlen($summary, 'UTF-8') > 120) $errors[] = '简介不能超过 120 个字';
    if (!in_array($category, $categories, true)) $category = '其他';
    if ($homepage !== '' && !preg_match('#^https?://#i', $homepage)) $errors[] = '项目主页必须是 http(s) 地址';
    if ($authorLink !== '' && !preg_match('#^https?://#i', $authorLink)) $errors[] = '作者链接必须是 http(s) 地址';

    // 读取上传文件（优先于粘贴源码）
    $uploaded = $_FILES['plugin_file'] ?? null;
    if ($uploaded && is_array($uploaded) && ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($uploaded['error'] !== UPLOAD_ERR_OK) {
            $errors[] = '文件上传失败（错误码 ' . (int)$uploaded['error'] . '），请检查 PHP upload_max_filesize 限制';
        } elseif (!is_uploaded_file($uploaded['tmp_name'])) {
            $errors[] = '非法上传';
        } else {
            $max = max(65536, (int)market_setting('max_upload', '2097152'));
            if ((int)$uploaded['size'] > $max) {
                $errors[] = '文件超过大小限制（最大 ' . market_size($max) . '）';
            } else {
                $raw = (string)file_get_contents($uploaded['tmp_name']);
                $ext = strtolower(pathinfo((string)$uploaded['name'], PATHINFO_EXTENSION));
                if ($ext === 'zip') {
                    $code = market_extract_zip_code($raw, $max, $errors);
                } elseif (in_array($ext, ['php', 'txt'], true)) {
                    $code = $raw;
                } else {
                    $errors[] = '仅支持 .php、.txt 或 .zip 文件';
                }
            }
        }
    }

    if (trim($code) === '') {
        $errors[] = '请上传插件文件或粘贴插件源码';
    }
    if (strlen($code) > 1048576) {
        $errors[] = '插件源码不能超过 1 MB';
    }

    // 名称/版本与已有插件冲突处理
    $pdo = MarketDB::pdo();
    $existing = null;
    if ($name !== '') {
        $stmt = $pdo->prepare("SELECT * FROM plugins WHERE name = ? COLLATE NOCASE LIMIT 1");
        $stmt->execute([$name]);
        $existing = $stmt->fetch() ?: null;
    }
    if ($existing) {
        if ($manageToken === '' || !hash_equals((string)$existing['manage_hash'], hash('sha256', $manageToken))) {
            $errors[] = '插件名称「' . $name . '」已被占用。如要发布新版本，请填写首次提交时获得的管理密钥；否则请更换名称。';
        }
    }

    if (empty($errors)) {
        $scan = market_scan_code($code);
        if ($scan['status'] === 'block' && market_setting('allow_blocked', '0') !== '1') {
            $errors[] = '安全扫描未通过：' . $scan['summary'] . '。请移除高危调用后重新提交。';
            foreach (array_slice($scan['issues'], 0, 5) as $issue) {
                if ($issue['level'] === 'block') {
                    $errors[] = '第 ' . $issue['line'] . ' 行：' . $issue['message'];
                }
            }
        }
    }

    if (empty($errors)) {
        $scan = $scan ?? market_scan_code($code);
        $pdo->beginTransaction();
        try {
            if ($existing) {
                $pluginId = (int)$existing['id'];
                $slug = $existing['slug'];
                $pdo->prepare(
                    "UPDATE plugins SET summary = ?, description = ?, category = ?, author = ?, author_link = ?, license = ?, homepage = ?, tags = ?, icon = ?, updated_at = datetime('now','localtime') WHERE id = ?"
                )->execute([$summary, $description, $category, $author, $authorLink, $license, $homepage, $tags, $icon, $pluginId]);
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM versions WHERE plugin_id = ? AND version = ?");
                $stmt->execute([$pluginId, $version]);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new RuntimeException('该版本号已存在，请提升版本号后再提交');
                }
                $tokenToShow = $manageToken;
            } else {
                $slug = market_slugify($name);
                $baseSlug = $slug;
                $i = 2;
                while (true) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM plugins WHERE slug = ?");
                    $stmt->execute([$slug]);
                    if ((int)$stmt->fetchColumn() === 0) break;
                    $slug = $baseSlug . '-' . $i++;
                }
                $tokenToShow = bin2hex(random_bytes(18));
                $pdo->prepare(
                    "INSERT INTO plugins (slug, name, summary, description, category, author, author_link, license, homepage, icon, tags, status, manage_hash)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)"
                )->execute([
                    $slug, $name, $summary, $description, $category, $author, $authorLink,
                    $license, $homepage, $icon, $tags, hash('sha256', $tokenToShow),
                ]);
                $pluginId = (int)$pdo->lastInsertId();
            }

            $pendingDir = MARKET_STORAGE_DIR . '/pending';
            if (!is_dir($pendingDir)) @mkdir($pendingDir, 0750, true);
            $tmpName = 'p' . $pluginId . '-' . bin2hex(random_bytes(8)) . '.php.txt';
            $tmpPath = $pendingDir . '/' . $tmpName;
            if (file_put_contents($tmpPath, $code) === false) {
                throw new RuntimeException('写入临时文件失败，请检查 storage 目录权限');
            }
            $sha = hash_file('sha256', $tmpPath);
            $pdo->prepare(
                "INSERT INTO versions (plugin_id, version, changelog, file_path, file_size, sha256, scan_status, scan_report, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            )->execute([
                $pluginId, $version, $changelog, $tmpPath, filesize($tmpPath), $sha,
                $scan['status'], json_encode($scan, JSON_UNESCAPED_UNICODE),
            ]);
            $versionId = (int)$pdo->lastInsertId();
            $pdo->commit();

            market_audit($author, $existing ? '提交新版本' : '提交新插件', $name . ' v' . $version . ' (#' . $versionId . ')');
            $newToken = $tokenToShow;

            // 自动审核（默认关闭）：仅扫描无风险时放行
            if (market_setting('auto_approve', '0') === '1' && $scan['status'] === 'pass') {
                market_approve_version($versionId, 'system-auto');
                $success = '插件已通过自动审核并上架。';
            } else {
                $success = '提交成功，已进入人工审核队列。审核通过后将自动上架。';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (isset($tmpPath) && is_file($tmpPath)) @unlink($tmpPath);
            $errors[] = $e->getMessage();
        }
    }
}

/**
 * 从 zip 中提取单个插件源码（防路径穿越/zip bomb）
 */
function market_extract_zip_code($raw, $max, &$errors) {
    if (!class_exists('ZipArchive')) {
        $errors[] = '服务器未安装 ZipArchive，无法处理 zip，请直接上传 .php 文件';
        return '';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'mzip_');
    file_put_contents($tmp, $raw);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        $errors[] = 'zip 文件无法打开';
        return '';
    }
    $found = '';
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!$stat) continue;
        $entry = str_replace('\\', '/', $stat['name']);
        if (strpos($entry, '../') !== false || strpos($entry, '/') === 0) continue;
        $total += (int)$stat['size'];
        if ($total > $max) {
            $errors[] = 'zip 解压后体积超过限制';
            break;
        }
        if (preg_match('/\.php$/i', $entry) && $found === '') {
            $found = (string)$zip->getFromIndex($i);
        }
    }
    $zip->close();
    @unlink($tmp);
    if ($found === '' && empty($errors)) {
        $errors[] = 'zip 中未找到 .php 插件文件（请将插件放在压缩包根目录或任意子目录）';
    }
    return $found;
}

$pageTitle = '发布插件';
$activeNav = 'submit';
require __DIR__ . '/partials/header.php';
?>
<div class="container" style="max-width:900px;padding-bottom:60px;">
  <div style="padding:34px 0 10px;">
    <div class="hero-badge"><i></i> 人工审核 · 静态扫描 · SHA-256 校验</div>
    <h1 style="font-size:32px;margin:0 0 10px;letter-spacing:-.6px;">发布你的插件</h1>
    <p class="muted" style="max-width:640px;margin:0;">上传插件源码，填写版本说明后进入审核队列。审核通过后可被所有接入市场的机器人一键安装。</p>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-ok">
      <strong><?= market_h($success) ?></strong>
      <?php if ($newToken !== ''): ?>
        <div style="margin-top:10px;">请妥善保存以下管理密钥，后续发布新版本或查询审核进度时需要用到（只显示一次）：</div>
        <div class="mono" style="margin-top:8px;padding:10px 12px;background:rgba(0,0,0,.28);border-radius:8px;word-break:break-all;"><?= market_h($newToken) ?></div>
        <div style="margin-top:8px;">
          <a href="<?= market_h(market_base_url()) ?>/submit.php?token=<?= urlencode($newToken) ?>" style="color:#8ef0c8">查看审核进度 →</a>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <?php foreach ($errors as $err): ?><div>· <?= market_h($err) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($statusPlugin): ?>
    <div class="panel" style="margin-bottom:20px;">
      <div class="panel-head">
        <h3><?= market_h($statusPlugin['name']) ?> · 审核进度</h3>
        <span class="badge <?= $statusPlugin['status'] === 'approved' ? 'badge-ok' : ($statusPlugin['status'] === 'rejected' ? 'badge-danger' : 'badge-warn') ?>" style="margin-left:auto;">
          <?= $statusPlugin['status'] === 'approved' ? '已上架' : ($statusPlugin['status'] === 'rejected' ? '已拒绝' : '审核中') ?>
        </span>
      </div>
      <div class="table-wrap" style="border:0;border-radius:0;">
        <table class="data">
          <thead><tr><th>版本</th><th>状态</th><th>扫描</th><th>提交时间</th><th>说明</th></tr></thead>
          <tbody>
          <?php foreach ($statusVersions as $v): ?>
            <tr>
              <td>v<?= market_h($v['version']) ?></td>
              <td>
                <?php
                $map = ['approved' => ['已通过', 'badge-ok'], 'rejected' => ['已拒绝', 'badge-danger'], 'pending' => ['待审核', 'badge-warn']];
                $st = $map[$v['status']] ?? ['未知', 'badge-muted'];
                ?>
                <span class="badge <?= $st[1] ?>"><?= $st[0] ?></span>
              </td>
              <td><span class="badge <?= $v['scan_status'] === 'pass' ? 'badge-ok' : ($v['scan_status'] === 'warn' ? 'badge-warn' : 'badge-danger') ?>"><?= market_h($v['scan_status']) ?></span></td>
              <td class="muted small"><?= market_h($v['created_at']) ?></td>
              <td class="muted small"><?= $v['status'] === 'rejected' ? market_h($statusPlugin['reject_reason']) : market_h($v['changelog']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <form class="panel" method="post" enctype="multipart/form-data" style="padding:24px;">
    <input type="hidden" name="csrf_token" value="<?= market_h(market_csrf_token()) ?>">
    <div style="position:absolute;left:-9999px;"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>

    <div class="form-grid">
      <div class="form-row">
        <label>插件名称 *</label>
        <input class="form-control" type="text" name="name" maxlength="40" required placeholder="例如：点歌系统" value="<?= market_h($_POST['name'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>版本号 *</label>
        <input class="form-control" type="text" name="version" required placeholder="1.0.0" value="<?= market_h($_POST['version'] ?? '1.0.0') ?>">
      </div>
    </div>
    <div class="form-grid">
      <div class="form-row">
        <label>作者 *</label>
        <input class="form-control" type="text" name="author" required placeholder="你的昵称" value="<?= market_h($_POST['author'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>分类</label>
        <select class="form-control" name="category">
          <?php foreach ($categories as $cat): ?>
            <option value="<?= market_h($cat) ?>" <?= ($_POST['category'] ?? '') === $cat ? 'selected' : '' ?>><?= market_h($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <label>一句话简介 *</label>
      <input class="form-control" type="text" name="summary" maxlength="120" required placeholder="用一句话说明插件能做什么" value="<?= market_h($_POST['summary'] ?? '') ?>">
    </div>
    <div class="form-row">
      <label>插件说明（Markdown）</label>
      <textarea class="form-control" name="description" rows="7" placeholder="## 功能特性&#10;&#10;- ..."><?= market_h($_POST['description'] ?? '') ?></textarea>
    </div>

    <div class="form-grid">
      <div class="form-row">
        <label>上传插件文件</label>
        <input class="form-control" type="file" name="plugin_file" accept=".php,.txt,.zip">
        <div class="form-hint">支持 .php / .txt / .zip，最大 <?= market_h(market_size((int)market_setting('max_upload', '2097152'))) ?>。zip 将取其中第一个 .php。</div>
      </div>
      <div class="form-row">
        <label>或粘贴源码</label>
        <textarea class="form-control" name="code" rows="4" placeholder="&lt;?php ..." style="font-family:var(--mono);font-size:12px;"><?= market_h($_POST['code'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="form-grid">
      <div class="form-row">
        <label>标签（逗号分隔）</label>
        <input class="form-control" type="text" name="tags" placeholder="音乐,点歌,娱乐" value="<?= market_h($_POST['tags'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>图标（Emoji）</label>
        <input class="form-control" type="text" name="icon" maxlength="8" placeholder="🧩" value="<?= market_h($_POST['icon'] ?? '🧩') ?>">
      </div>
    </div>
    <div class="form-grid">
      <div class="form-row">
        <label>项目主页</label>
        <input class="form-control" type="url" name="homepage" placeholder="https://github.com/..." value="<?= market_h($_POST['homepage'] ?? '') ?>">
      </div>
      <div class="form-row">
        <label>开源协议</label>
        <input class="form-control" type="text" name="license" placeholder="MIT" value="<?= market_h($_POST['license'] ?? 'MIT') ?>">
      </div>
    </div>
    <div class="form-row">
      <label>版本更新说明</label>
      <input class="form-control" type="text" name="changelog" maxlength="200" placeholder="本次更新了什么" value="<?= market_h($_POST['changelog'] ?? '') ?>">
    </div>
    <div class="form-row">
      <label>管理密钥（更新已有插件时填写）</label>
      <input class="form-control" type="text" name="manage_token" placeholder="首次提交时获得，用于发布新版本" value="<?= market_h($_POST['manage_token'] ?? '') ?>">
      <div class="form-hint">新插件留空即可；同名插件必须提供首次提交返回的密钥，防止他人覆盖。</div>
    </div>

    <label class="check" style="margin:14px 0 18px;">
      <input type="checkbox" name="agree" value="1" <?= !empty($_POST['agree']) ? 'checked' : '' ?>>
      <span>我确认插件代码不含后门、木马、恶意扣费等行为，并同意站点对代码进行静态扫描与人工审核。</span>
    </label>

    <button class="btn btn-primary btn-lg" type="submit">提交审核</button>
  </form>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
