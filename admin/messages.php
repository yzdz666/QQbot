<?php
/**
 * 管理后台 - 消息日志
 */
$pageTitle = '消息日志';
require_once('header.php');

// ==================== 获取筛选参数 ====================
$appid     = isset($_GET['appid']) ? trim($_GET['appid']) : '';
$direction = isset($_GET['direction']) ? trim($_GET['direction']) : '';
$q         = isset($_GET['q']) ? trim($_GET['q']) : '';
$days      = isset($_GET['days']) ? trim($_GET['days']) : '';
$page      = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// 仅允许合法方向值
if (!in_array($direction, ['接收', '发送'], true)) {
    $direction = '';
}

// 仅允许合法天数值（空=全部）
$validDays = ['1', '3', '7', '30', '90'];
if (!in_array($days, $validDays, true)) {
    $days = '';
}

// 获取机器人列表
$bots = getBots();

// ==================== 构建查询条件 ====================
$where  = [];
$params = [];
if ($appid !== '') {
    $where[]  = 'm.appid = ?';
    $params[] = $appid;
}
if ($direction !== '') {
    $where[]  = 'm.direction = ?';
    $params[] = $direction;
}
if ($q !== '') {
    $where[]  = 'm.content LIKE ?';
    $params[] = '%' . $q . '%';
}
if ($days !== '') {
    $where[]  = "m.created_at >= datetime('now','localtime','-" . intval($days) . " days')";
}
$whereClause = '';
if (!empty($where)) {
    $whereClause = ' WHERE ' . implode(' AND ', $where);
}

// ==================== 分页 ====================
$perPage = 20;
try {
    $total = (int) db()->fetchColumn("SELECT COUNT(*) FROM messages m" . $whereClause, $params);
} catch (Exception $e) {
    $total = 0;
}

$totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
$page       = max(1, min($totalPages, $page));
$offset     = ($page - 1) * $perPage;

// ==================== 查询消息 ====================
try {
    $messages = db()->fetchAll(
        "SELECT m.*, u.nickname as user_nickname 
         FROM messages m 
         LEFT JOIN users u ON m.appid = u.appid AND m.user_id = u.user_id" 
         . $whereClause . " ORDER BY m.id DESC LIMIT " . intval($perPage) . " OFFSET " . intval($offset),
        $params
    );
} catch (Exception $e) {
    // 如果JOIN失败，回退到原始查询
    try {
        $messages = db()->fetchAll(
            "SELECT * FROM messages" . $whereClause . " ORDER BY id DESC LIMIT " . intval($perPage) . " OFFSET " . intval($offset),
            $params
        );
    } catch (Exception $e2) {
        $messages = [];
    }
}

// ==================== 构建分页基础查询字符串（不含 page）====================
$pageParams = [];
if ($appid !== '')     $pageParams['appid']     = $appid;
if ($direction !== '') $pageParams['direction'] = $direction;
if ($days !== '')      $pageParams['days']      = $days;
if ($q !== '')         $pageParams['q']         = $q;
$baseQuery   = http_build_query($pageParams);
$pageBaseUrl = 'messages.php' . ($baseQuery !== '' ? '?' . $baseQuery . '&' : '?');

// ==================== 从raw_data解析附件信息 ====================
// 返回 ['content'=>URL, 'content_type'=>类型, 'file_name'=>文件名] 或 null
function parseAttachmentFromRawData($rawDataJson) {
    if (empty($rawDataJson)) return null;
    $rawData = json_decode($rawDataJson, true);
    if (!is_array($rawData) || !isset($rawData['d']['attachments'])) return null;
    if (!is_array($rawData['d']['attachments']) || count($rawData['d']['attachments']) === 0) return null;

    // 取第一个附件
    $att = $rawData['d']['attachments'][0];
    $url = isset($att['url']) ? trim($att['url']) : '';
    // 去除反引号
    $url = trim($url, "`\t\n\r ");
    if ($url === '') return null;

    $fileName = $att['filename'] ?? '';
    $contentType = $att['content_type'] ?? '';

    // 判断类型
    if (strpos($contentType, 'image/') === 0) {
        $type = 'image';
    } elseif (strpos($contentType, 'video/') === 0) {
        $type = 'video';
    } elseif (strpos($contentType, 'audio/') === 0 || $contentType === 'voice') {
        $type = 'voice';
    } elseif ($contentType === 'file' || !empty($fileName)) {
        $type = 'file';
    } else {
        $type = 'file';
    }

    return [
        'content' => $url,
        'content_type' => $type,
        'file_name' => $fileName,
    ];
}

// ==================== 文件URL检测函数 ====================
function detectFileUrlFromContent($url) {
    // 去除URL两端的反引号和空白
    $url = trim($url, "`\t\n\r ");
    if (!preg_match('/^https?:\/\/\S+$/i', $url)) {
        return null;
    }

    // 尝试从fname参数获取文件名（ftn.qq.com链接）
    $fileName = '';
    $query = parse_url($url, PHP_URL_QUERY);
    if ($query) {
        parse_str($query, $params);
        if (isset($params['fname']) && $params['fname'] !== '') {
            $fileName = $params['fname'];
        }
    }

    // 如果没有fname参数，从路径提取
    if ($fileName === '') {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path) {
            $pathParts = explode('/', $path);
            $lastPart = end($pathParts);
            if ($lastPart !== '' && strpos($lastPart, '.') !== false) {
                $fileName = $lastPart;
            }
        }
    }

    if ($fileName === '') {
        // 无法确定文件名，返回link类型
        return ['type' => 'link', 'fileName' => '', 'extension' => ''];
    }

    // 获取扩展名
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext === '') {
        return ['type' => 'link', 'fileName' => $fileName, 'extension' => ''];
    }

    // 根据扩展名判断类型
    $videoExts = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v', '3gp', 'mpeg', 'mpg', 'ts', 'vob'];
    $audioExts = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma', 'opus', 'aiff', 'amr', 'mid', 'midi'];
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'tiff', 'tif', 'heic', 'heif', 'raw', 'psd', 'ai'];
    // 扩展所有文件后缀：代码、文档、压缩包、字体、应用等
    $fileExts  = [
        // 文档类
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'md',
        // 代码类
        'js', 'ts', 'jsx', 'tsx', 'mjs', 'cjs', 'css', 'scss', 'sass', 'less', 'styl',
        'html', 'htm', 'xhtml', 'xml', 'json', 'yaml', 'yml', 'toml', 'ini', 'conf', 'cfg', 'properties',
        'php', 'py', 'java', 'c', 'cpp', 'cc', 'cxx', 'h', 'hpp', 'hxx', 'cs', 'go', 'rs', 'rb', 'swift',
        'kt', 'kts', 'dart', 'lua', 'r', 'scala', 'clj', 'ex', 'exs', 'erl', 'hs', 'ml', 'fs', 'fsx',
        'sh', 'bash', 'zsh', 'bat', 'cmd', 'ps1', 'psm1', 'fish', 'sql', 'graphql', 'gql', 'proto',
        'vue', 'svelte', 'astro', 'makefile', 'cmake', 'gradle', 'groovy', 'jenkinsfile',
        'dockerfile', 'gitignore', 'editorconfig', 'env',
        // 压缩包
        'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'lz', 'lzma', 'zst', 'tgz', 'tbz2',
        // 字体
        'ttf', 'otf', 'woff', 'woff2', 'eot',
        // 应用/安装包
        'exe', 'msi', 'apk', 'ipa', 'deb', 'rpm', 'dmg', 'pkg', 'app', 'jar', 'war', 'ear',
        'dll', 'so', 'dylib', 'lib', 'a',
        // 电子书
        'epub', 'mobi', 'azw', 'azw3', 'fb2', 'lit', 'lrf',
        // 系统镜像
        'iso', 'img', 'vmdk', 'vdi', 'qcow2',
        // 设计文件
        'sketch', 'fig', 'xd', 'svgz', 'indd', 'idml',
        // 其他
        'log', 'dat', 'db', 'sqlite', 'sqlite3', 'mdb', 'accdb', 'plist', 'key', 'numbers', 'pages'
    ];

    if (in_array($ext, $videoExts)) {
        $type = 'video';
    } elseif (in_array($ext, $audioExts)) {
        $type = 'audio';
    } elseif (in_array($ext, $imageExts)) {
        $type = 'image';
    } elseif (in_array($ext, $fileExts)) {
        $type = 'file';
    } else {
        // 未识别的扩展名也当作文件处理（而非link），确保所有文件后缀都能显示
        $type = 'file';
    }

    return ['type' => $type, 'fileName' => $fileName, 'extension' => $ext];
}
?>

<style>
/* 表格行滚动优化 */
.table tbody tr {
    content-visibility: auto;
    contain-intrinsic-size: 50px;
}
/* 文件URL预览样式 */
.msg-file-preview {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    padding: 4px 10px;
    border-radius: 6px;
    background: #f0f4ff;
    border: 1px solid #d0dfff;
    transition: all 0.15s;
    max-width: 100%;
    overflow: hidden;
}
.msg-file-preview:hover {
    background: #e0eaff;
    border-color: #a0c0ff;
}
.msg-file-preview .file-type-icon {
    font-size: 16px;
    flex-shrink: 0;
}
.msg-file-preview .file-name-text {
    font-size: 12px;
    font-weight: 500;
    color: #2c6b9e;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 200px;
}
.msg-file-preview .file-ext-badge {
    font-size: 10px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 4px;
    color: white;
    flex-shrink: 0;
}
.file-ext-mp4, .file-ext-webm, .file-ext-mov, .file-ext-avi, .file-ext-mkv { background: #c62828; }
.file-ext-mp3, .file-ext-wav, .file-ext-ogg, .file-ext-m4a, .file-ext-aac { background: #2e7d32; }
.file-ext-jpg, .file-ext-jpeg, .file-ext-png, .file-ext-gif, .file-ext-webp { background: #e65100; }
.file-ext-pdf { background: #d32f2f; }
.file-ext-doc, .file-ext-docx { background: #1565c0; }
.file-ext-xls, .file-ext-xlsx { background: #2e7d32; }
.file-ext-ppt, .file-ext-pptx { background: #ef6c00; }
.file-ext-zip, .file-ext-rar, .file-ext-7z { background: #7b1fa2; }
.file-ext-txt, .file-ext-csv, .file-ext-md, .file-ext-log { background: #546e7a; }
.file-ext-json, .file-ext-xml, .file-ext-yaml, .file-ext-yml, .file-ext-toml { background: #00838f; }
.file-ext-js, .file-ext-mjs, .file-ext-cjs { background: #f7df1e; color: #000; }
.file-ext-ts, .file-ext-tsx, .file-ext-jsx { background: #3178c6; }
.file-ext-css, .file-ext-scss, .file-ext-sass, .file-ext-less { background: #c6538c; }
.file-ext-html, .file-ext-htm { background: #e44d26; }
.file-ext-php { background: #777bb4; }
.file-ext-py { background: #3776ab; }
.file-ext-java { background: #f89820; }
.file-ext-c, .file-ext-cpp, .file-ext-h, .file-ext-hpp { background: #00599c; }
.file-ext-go { background: #00add8; }
.file-ext-rs { background: #dea584; color: #000; }
.file-ext-rb { background: #cc342d; }
.file-ext-swift { background: #fa7343; }
.file-ext-kt, .file-ext-kts { background: #7f52ff; }
.file-ext-sh, .file-ext-bash, .file-ext-zsh { background: #4eaa25; }
.file-ext-sql { background: #e38c00; }
.file-ext-vue { background: #42b883; }
.file-ext-dockerfile { background: #2496ed; }
.file-ext-exe, .file-ext-msi, .file-ext-apk, .file-ext-dmg, .file-ext-deb, .file-ext-rpm { background: #455a64; }
.file-ext-jar, .file-ext-war, .file-ext-ear { background: #e76f00; }
.file-ext-epub, .file-ext-mobi, .file-ext-azw3 { background: #689f38; }
.file-ext-ttf, .file-ext-otf, .file-ext-woff, .file-ext-woff2 { background: #5c6bc0; }
.file-ext-iso, .file-ext-img { background: #607d8b; }
.file-ext-sketch, .file-ext-fig, .file-ext-xd { background: #9c27b0; }
/* 默认文件后缀颜色（未识别的后缀） */
.msg-file-preview .file-ext-badge { background: #607d8b; }

/* 消息列表用户头像和昵称 */
.msg-user-cell {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 120px;
}
.msg-user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    flex-shrink: 0;
    background: var(--border);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: var(--text-secondary);
}
.msg-user-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.msg-user-info {
    min-width: 0;
    flex: 1;
}
.msg-user-nickname {
    font-size: 13px;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.msg-user-id {
    font-size: 11px;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
/* 机器人标签 */
.msg-bot-badge {
    display: inline-block;
    font-size: 10px;
    padding: 1px 5px;
    border-radius: 3px;
    margin-left: 4px;
    font-weight: 500;
    vertical-align: middle;
    background: #f3e5f5;
    color: #7b1fa2;
    border: 1px solid #ce93d8;
}
/* 系统事件来源类型（带头像） */
.msg-source-event {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.msg-event-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    flex-shrink: 0;
    overflow: hidden;
    background: var(--border, #e0e0e0);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: #666;
}
.msg-event-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.msg-event-type {
    font-size: 12px;
    font-weight: 500;
    padding: 2px 8px;
    border-radius: 4px;
    background: #f5f5f5;
    color: #666;
}
.msg-event-type.event-join { background: #e8f5e9; color: #2e7d32; }
.msg-event-type.event-leave { background: #fff3e0; color: #e65100; }
.msg-event-type.event-remove { background: #fce4ec; color: #c62828; }
.msg-event-type.event-bot { background: #e3f2fd; color: #1565c0; }
/* 内容类型徽章 */
.msg-content-type {
    display: inline-block;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 4px;
    font-weight: 500;
}
.msg-content-type.type-text { background: #f0f0f0; color: #666; }
.msg-content-type.type-image { background: #fff3e0; color: #e65100; }
.msg-content-type.type-voice, .msg-content-type.type-audio { background: #e8f5e9; color: #2e7d32; }
.msg-content-type.type-video { background: #fce4ec; color: #c62828; }
.msg-content-type.type-file { background: #e3f2fd; color: #1565c0; }
.msg-content-type.type-md, .msg-content-type.type-markdown { background: #f3e5f5; color: #7b1fa2; }
.msg-content-type.type-quote { background: #fff8e1; color: #f57c00; }
.msg-content-type.type-button { background: #e0f7fa; color: #00838f; }
.msg-content-type.type-json { background: #f3e5f5; color: #7b1fa2; }
.msg-content-type.type-unknown { background: #eceff1; color: #546e7a; }

/* 文件预览模态框 */
.file-preview-modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.file-preview-modal.show {
    display: flex;
}
.file-preview-content {
    background: white;
    border-radius: 12px;
    max-width: 800px;
    width: 100%;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.file-preview-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}
.file-preview-header h3 {
    font-size: 15px;
    font-weight: 600;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 600px;
}
.file-preview-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 200px;
}
.file-preview-body video {
    max-width: 100%;
    max-height: 60vh;
    border-radius: 8px;
}
.file-preview-body audio {
    width: 100%;
}
.file-preview-body img {
    max-width: 100%;
    max-height: 60vh;
    border-radius: 8px;
}
.file-preview-body .file-download-card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 24px;
    background: #f5f7fa;
    border-radius: 10px;
    border: 1px solid var(--border);
}
.file-preview-body .file-download-card .file-icon-large {
    font-size: 48px;
}
.file-preview-body .file-download-card .file-info {
    flex: 1;
}
.file-preview-body .file-download-card .file-info .file-name {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 4px;
    word-break: break-all;
}
.file-preview-body .file-download-card .file-info .file-type {
    font-size: 12px;
    color: var(--text-muted);
}
.file-preview-body .file-download-card .download-btn {
    padding: 10px 20px;
    background: var(--primary);
    color: white;
    border-radius: 8px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    flex-shrink: 0;
}
</style>

<div class="page-header">
  <h2>消息日志</h2>
  <div class="actions">
    <button class="btn btn-danger" onclick="clearMessages()">清空消息</button>
  </div>
</div>

<!-- ==================== 筛选区 ==================== -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" action="messages.php" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
      <div class="form-group" style="flex:1; min-width:180px; margin-bottom:0;">
        <label>机器人</label>
        <select name="appid" class="form-control">
          <option value="">全部机器人</option>
          <?php foreach ($bots as $bot):
            $botLabel = $bot['appid'];
            if (!empty($bot['nickname'])) $botLabel .= ' (' . $bot['nickname'] . ')';
          ?>
          <option value="<?= htmlspecialchars($bot['appid']) ?>" <?= ($appid === $bot['appid']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($botLabel) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:0 0 140px; margin-bottom:0;">
        <label>方向</label>
        <select name="direction" class="form-control">
          <option value="" <?= ($direction === '') ? 'selected' : '' ?>>全部</option>
          <option value="接收" <?= ($direction === '接收') ? 'selected' : '' ?>>接收</option>
          <option value="发送" <?= ($direction === '发送') ? 'selected' : '' ?>>发送</option>
        </select>
      </div>
      <div class="form-group" style="flex:0 0 130px; margin-bottom:0;">
        <label>时间范围</label>
        <select name="days" class="form-control">
          <option value="" <?= ($days === '') ? 'selected' : '' ?>>全部时间</option>
          <option value="1" <?= ($days === '1') ? 'selected' : '' ?>>近1天</option>
          <option value="3" <?= ($days === '3') ? 'selected' : '' ?>>近3天</option>
          <option value="7" <?= ($days === '7') ? 'selected' : '' ?>>近7天</option>
          <option value="30" <?= ($days === '30') ? 'selected' : '' ?>>近30天</option>
          <option value="90" <?= ($days === '90') ? 'selected' : '' ?>>近90天</option>
        </select>
      </div>
      <div class="form-group" style="flex:1; min-width:200px; margin-bottom:0;">
        <label>内容搜索</label>
        <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="搜索消息内容">
      </div>
      <div class="form-group" style="flex:0 0 auto; margin-bottom:0;">
        <button type="submit" class="btn btn-primary">筛选</button>
        <a href="messages.php" class="btn btn-outline">重置</a>
      </div>
    </form>
  </div>
</div>

<!-- ==================== 消息列表 ==================== -->
<div class="card">
  <div class="card-header">
    <h3>消息列表（共 <?= $total ?> 条）</h3>
  </div>
  <div class="card-body no-padding">
    <?php if (empty($messages)): ?>
      <div class="empty-state">
        <div class="empty-icon">--</div>
        <p>暂无消息记录</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>时间</th>
            <th>方向</th>
            <th>用户</th>
            <th>来源类型</th>
            <th>目标ID</th>
            <th>内容类型</th>
            <th>内容预览</th>
            <th class="text-right">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($messages as $msg):
            $isRecv      = ($msg['direction'] === '接收');
            $content     = trim($msg['content'] ?? '');
            $contentType = $msg['content_type'] ?? '';
            if ($contentType === '') $contentType = 'text';

            // 如果content为空或content_type为默认的"文字"，尝试从raw_data解析附件
            $rawDataJson = $msg['raw_data'] ?? '';
            if ($content === '' || $contentType === '文字' || $contentType === 'text') {
                $attInfo = parseAttachmentFromRawData($rawDataJson);
                if ($attInfo) {
                    // 用附件URL填充content
                    if ($content === '') {
                        $content = $attInfo['content'];
                    }
                    // 更新content_type
                    $typeMap = ['image'=>'image','video'=>'video','voice'=>'voice','audio'=>'voice','file'=>'file'];
                    if (isset($typeMap[$attInfo['content_type']])) {
                        $contentType = $typeMap[$attInfo['content_type']];
                    }
                }
            }

            // 去除content两端的反引号
            $content = trim($content, "`\t\n\r ");

            // 检测内容是否为文件URL
            $isFileUrl = false;
            $fileUrlInfo = null;
            $originalContent = $content;
            if ($content !== '' && preg_match('/^https?:\/\/\S+$/i', $content)) {
                $fileUrlInfo = detectFileUrlFromContent($content);
                if ($fileUrlInfo && $fileUrlInfo['type'] !== 'link') {
                    $isFileUrl = true;
                    // 根据检测到的文件类型更新content_type显示
                    if ($fileUrlInfo['type'] === 'video') {
                        $contentType = 'video';
                    } elseif ($fileUrlInfo['type'] === 'audio') {
                        $contentType = 'audio';
                    } elseif ($fileUrlInfo['type'] === 'image') {
                        $contentType = 'image';
                    } elseif ($fileUrlInfo['type'] === 'file') {
                        $contentType = 'file';
                    }
                }
            }

            // 内容预览
            $previewContent = $content;
            if ($content === '') {
                $previewContent = '(无内容)';
            } elseif ($isFileUrl) {
                $previewContent = $fileUrlInfo['fileName'];
            } elseif (mb_strlen($content, 'UTF-8') > 50) {
                $previewContent = mb_substr($content, 0, 50, 'UTF-8') . '...';
            }

            $sourceType  = $msg['source_type'] ?? '';
            if ($sourceType === '') $sourceType = '-';
            $targetId    = $msg['target_id'] ?? '';
            if ($targetId === '') $targetId = '-';
            $rawData     = $msg['raw_data'] ?? '';
            $decoded = json_decode($rawData, true);
            if ($decoded !== null) {
                $rawDataJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            } else {
                $rawDataJson = json_encode($rawData, JSON_UNESCAPED_UNICODE);
            }
            $rawDataB64 = base64_encode($rawDataJson);

            // 用户信息
            $msgAppid = $msg['appid'] ?? '';
            $msgUserId = $msg['user_id'] ?? '';
            $userNickname = $msg['user_nickname'] ?? '';
            // 从 raw_data 解析 username 和 bot 标记
            $isAuthorBot = false;
            if (!empty($rawData)) {
                $rawDecoded = json_decode($rawData, true);
                if (is_array($rawDecoded)) {
                    if (!$userNickname && isset($rawDecoded['d']['author']['username'])) {
                        $userNickname = $rawDecoded['d']['author']['username'];
                    }
                    if (isset($rawDecoded['d']['author']['bot'])) {
                        $isAuthorBot = (bool)$rawDecoded['d']['author']['bot'];
                    }
                }
            }

            // 机器人信息：发送方向的消息显示机器人昵称和头像；加群事件也需要机器人信息
            $botInfo = null;
            $systemEventTypesForBot = ['退群', '群成员移除', '加群', '群成员增加', '入群申请', '群消息拒绝', '群消息接收', '好友增加', '好友删除', '订阅状态', '频道', '频道私信', '表情表态', '频道更新'];
            if ($msgAppid && (!$isRecv || in_array($sourceType, $systemEventTypesForBot, true))) {
                foreach ($bots as $bot) {
                    if ($bot['appid'] === $msgAppid) {
                        $botInfo = $bot;
                        break;
                    }
                }
            }

            // 头像URL与显示名称
            $avatarUrl = '';
            $displayName = '';
            if (!$isRecv && $botInfo) {
                // 机器人发送的消息：显示机器人昵称和头像
                if (!empty($botInfo['nickname'])) {
                    $displayName = $botInfo['nickname'];
                } elseif (!empty($botInfo['robot_qq'])) {
                    $displayName = $botInfo['robot_qq'];
                } else {
                    $displayName = $msgAppid;
                }
                // 机器人头像
                if (!empty($botInfo['avatar'])) {
                    $avatarUrl = $botInfo['avatar'];
                } elseif (!empty($botInfo['robot_qq'])) {
                    $avatarUrl = 'https://q1.qlogo.cn/g?b=qq&nk=' . $botInfo['robot_qq'] . '&s=640';
                }
            } else {
                // 用户接收的消息：显示用户头像和昵称
                if ($msgAppid && $msgUserId) {
                    $avatarUrl = 'https://q.qlogo.cn/qqapp/' . $msgAppid . '/' . $msgUserId . '/640';
                }
                $displayName = $userNickname ?: ($msgUserId ?: ($isRecv ? '用户' : '机器人'));
            }
            // 兜底：如果机器人发送但没找到机器人信息，使用 appid 作为显示名
            if ($displayName === '') {
                $displayName = $msgAppid ?: ($isRecv ? '用户' : '机器人');
            }
            // 头像字符回退：机器人使用机器人emoji，其他使用用户emoji
            $displayAvatarChar = ($isAuthorBot || !$isRecv) ? '&#129302;' : '&#128100;';

            // 内容类型中文映射
            $contentTypeLabels = [
                'text' => '文本', 'image' => '图片', 'video' => '视频',
                'voice' => '语音', 'audio' => '音乐', 'file' => '文件',
                'md' => 'Markdown', 'markdown' => 'Markdown', 'quote' => '引用',
                'button' => '按钮', 'json' => 'JSON', 'unknown' => '未知'
            ];
            $contentTypeLabel = $contentTypeLabels[$contentType] ?? $contentType;
            $contentTypeCls = 'type-' . $contentType;
          ?>
          <tr>
            <td class="text-muted" style="white-space:nowrap;"><?= htmlspecialchars($msg['created_at']) ?></td>
            <td>
              <span class="badge <?= $isRecv ? 'badge-info' : 'badge-success' ?>">
                <?= htmlspecialchars($msg['direction']) ?>
              </span>
            </td>
            <td>
              <div class="msg-user-cell">
                <?php if ($avatarUrl): ?>
                <div class="msg-user-avatar">
                  <img src="<?= htmlspecialchars($avatarUrl) ?>" onerror="this.style.display='none';this.parentElement.innerHTML='<?= $displayAvatarChar ?>';" referrerpolicy="no-referrer">
                </div>
                <?php else: ?>
                <div class="msg-user-avatar"><?= $displayAvatarChar ?></div>
                <?php endif; ?>
                <div class="msg-user-info">
                  <div class="msg-user-nickname">
                    <?= htmlspecialchars($displayName) ?>
                    <?php if ($isAuthorBot): ?>
                      <span class="msg-bot-badge">机器人</span>
                    <?php endif; ?>
                  </div>
                  <?php if ($msgUserId && $msgUserId !== $displayName): ?>
                  <div class="msg-user-id"><?= htmlspecialchars(mb_substr($msgUserId, 0, 20, 'UTF-8')) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <?php
              $systemEventTypes = ['退群', '群成员移除', '加群', '群成员增加', '入群申请', '群消息拒绝', '群消息接收', '好友增加', '好友删除', '订阅状态', '频道', '频道私信', '表情表态', '频道更新'];
              if (in_array($sourceType, $systemEventTypes, true)):
                  // 生成系统事件头像
                  $eventAvatarUrl = '';
                  $eventAvatarChar = '&#128100;';
                  if ($sourceType === '加群') {
                      // 机器人加群，显示机器人头像
                      $eventAvatarChar = '&#129302;';
                      if (!empty($botInfo['avatar'])) {
                          $eventAvatarUrl = $botInfo['avatar'];
                      } elseif (!empty($botInfo['robot_qq'])) {
                          $eventAvatarUrl = 'https://q1.qlogo.cn/g?b=qq&nk=' . $botInfo['robot_qq'] . '&s=640';
                      }
                  } elseif ($msgAppid && $msgUserId) {
                      $eventAvatarUrl = 'https://q.qlogo.cn/qqapp/' . $msgAppid . '/' . $msgUserId . '/640';
                  }
                  // 事件类型CSS类
                  $eventCls = 'event-leave';
                  if ($sourceType === '群成员增加') $eventCls = 'event-join';
                  elseif ($sourceType === '群成员移除') $eventCls = 'event-remove';
                  elseif ($sourceType === '加群') $eventCls = 'event-bot';
                  elseif ($sourceType === '入群申请') $eventCls = 'event-join';
                  elseif ($sourceType === '好友增加') $eventCls = 'event-join';
                  elseif ($sourceType === '好友删除') $eventCls = 'event-leave';
                  elseif ($sourceType === '群消息拒绝') $eventCls = 'event-remove';
                  elseif ($sourceType === '群消息接收') $eventCls = 'event-join';
                  elseif ($sourceType === '订阅状态') $eventCls = 'event-bot';
                  elseif ($sourceType === '频道') $eventCls = 'event-bot';
                  elseif ($sourceType === '频道私信') $eventCls = 'event-bot';
                  elseif ($sourceType === '表情表态') $eventCls = 'event-join';
                  elseif ($sourceType === '频道更新') $eventCls = 'event-bot';
              ?>
                <div class="msg-source-event">
                  <div class="msg-event-avatar">
                    <?php if ($eventAvatarUrl): ?>
                    <img src="<?= htmlspecialchars($eventAvatarUrl) ?>" onerror="this.style.display='none';this.parentElement.innerHTML='<?= $eventAvatarChar ?>';" referrerpolicy="no-referrer">
                    <?php else: ?>
                    <?= $eventAvatarChar ?>
                    <?php endif; ?>
                  </div>
                  <span class="msg-event-type <?= $eventCls ?>"><?= htmlspecialchars($sourceType) ?></span>
                </div>
              <?php else: ?>
                <?= htmlspecialchars($sourceType) ?>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($targetId) ?></td>
            <td><span class="msg-content-type <?= htmlspecialchars($contentTypeCls) ?>"><?= htmlspecialchars($contentTypeLabel) ?></span></td>
            <td>
              <?php if ($isFileUrl): ?>
                <span class="msg-file-preview" onclick="previewFileUrl(<?= htmlspecialchars(json_encode($previewContent, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($fileUrlInfo['type'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($originalContent, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)">
                  <?php
                    $fileIcons = [
                        'video' => '&#127909;',
                        'audio' => '&#127925;',
                        'image' => '&#128247;',
                        'file'  => '&#128206;',
                    ];
                    $iconChar = $fileIcons[$fileUrlInfo['type']] ?? '&#128206;';
                  ?>
                  <span class="file-type-icon"><?= $iconChar ?></span>
                  <span class="file-name-text"><?= htmlspecialchars($previewContent) ?></span>
                  <span class="file-ext-badge file-ext-<?= htmlspecialchars($fileUrlInfo['extension']) ?>"><?= htmlspecialchars(strtoupper($fileUrlInfo['extension'])) ?></span>
                </span>
              <?php else: ?>
                <?= htmlspecialchars($previewContent) ?>
              <?php endif; ?>
            </td>
            <td class="text-right">
              <button class="btn btn-outline btn-sm" onclick='viewRawDataB64("<?= $rawDataB64 ?>")'>查看原始数据</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ==================== 分页 ==================== -->
<?php if ($totalPages > 1):
  $startPage = max(1, $page - 4);
  $endPage   = min($totalPages, $page + 4);
?>
<div class="pagination">
  <?php if ($page > 1): ?>
    <a href="<?= $pageBaseUrl ?>page=<?= $page - 1 ?>">&laquo; 上一页</a>
  <?php else: ?>
    <span class="text-muted">&laquo; 上一页</span>
  <?php endif; ?>

  <?php if ($startPage > 1): ?>
    <a href="<?= $pageBaseUrl ?>page=1">1</a>
    <?php if ($startPage > 2): ?><span>...</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
    <?php if ($i === $page): ?>
      <span class="current"><?= $i ?></span>
    <?php else: ?>
      <a href="<?= $pageBaseUrl ?>page=<?= $i ?>"><?= $i ?></a>
    <?php endif; ?>
  <?php endfor; ?>

  <?php if ($endPage < $totalPages): ?>
    <?php if ($endPage < $totalPages - 1): ?><span>...</span><?php endif; ?>
    <a href="<?= $pageBaseUrl ?>page=<?= $totalPages ?>"><?= $totalPages ?></a>
  <?php endif; ?>

  <?php if ($page < $totalPages): ?>
    <a href="<?= $pageBaseUrl ?>page=<?= $page + 1 ?>">下一页 &raquo;</a>
  <?php else: ?>
    <span class="text-muted">下一页 &raquo;</span>
  <?php endif; ?>

  <span style="margin-left:8px; align-self:center; color:var(--text-muted); font-size:12px;">
    第 <?= $page ?> / <?= $totalPages ?> 页
  </span>
</div>
<?php endif; ?>

<!-- ==================== 原始数据 模态框 ==================== -->
<div class="modal-overlay" id="rawDataModal" style="display:none;">
  <div class="modal" style="max-width:800px;">
    <div class="modal-header">
      消息原始数据
      <button class="btn btn-outline btn-sm" onclick="closeRawDataModal()" style="float:right;">&times;</button>
    </div>
    <div class="modal-body">
      <pre id="rawDataContent" style="background:var(--bg); border:1px solid var(--border); border-radius:var(--radius-sm); padding:16px; font-size:12px; line-height:1.5; overflow-x:auto; max-height:500px; overflow-y:auto; white-space:pre-wrap; word-break:break-all; font-family:'SF Mono','Consolas','Monaco',monospace;"></pre>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="copyRawData()">复制</button>
      <button class="btn btn-primary" onclick="closeRawDataModal()">关闭</button>
    </div>
  </div>
</div>

<script>
// ==================== 通用 AJAX 调用 ====================
function apiCall(action, data, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php?action=' + encodeURIComponent(action), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            var res;
            try {
                res = JSON.parse(xhr.responseText);
            } catch (e) {
                res = { success: false, message: '响应解析失败' };
            }
            callback(res);
        }
    };
    var params = [];
    for (var key in data) {
        if (data.hasOwnProperty(key)) {
            params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
        }
    }
    xhr.send(params.join('&'));
}

// ==================== 清空消息 ====================
function clearMessages() {
    var currentAppid = <?= json_encode($appid, JSON_UNESCAPED_UNICODE) ?>;
    var tip;
    if (currentAppid) {
        tip = '确定要清空机器人「' + currentAppid + '」的全部消息吗？\n该操作不可恢复！';
    } else {
        tip = '确定要清空全部机器人的所有消息吗？\n该操作不可恢复！';
    }
    if (!confirm(tip)) {
        return;
    }
    apiCall('clear_messages', { appid: currentAppid }, function(res) {
        if (res.success) {
            alert(res.message || '消息已清空');
            location.reload();
        } else {
            alert(res.message || '清空失败');
        }
    });
}

// ==================== 查看原始数据 ====================
function viewRawDataB64(b64Data) {
    var rawData = '';
    try {
        // 解码base64
        rawData = atob(b64Data);
        // 处理UTF-8编码
        rawData = decodeURIComponent(escape(rawData));
    } catch(e) {
        rawData = '(解码失败)';
    }
    
    var content = document.getElementById('rawDataContent');
    if (!rawData) {
        content.textContent = '(无原始数据)';
    } else {
        // 尝试格式化JSON
        try {
            var parsed = JSON.parse(rawData);
            content.textContent = JSON.stringify(parsed, null, 2);
        } catch(e) {
            // 不是JSON，直接显示原始文本
            content.textContent = rawData;
        }
    }
    document.getElementById('rawDataModal').style.display = 'flex';
}

function viewRawData(rawData) {
    var content = document.getElementById('rawDataContent');
    if (!rawData) {
        content.textContent = '(无原始数据)';
    } else {
        // 尝试格式化JSON
        try {
            var parsed = JSON.parse(rawData);
            content.textContent = JSON.stringify(parsed, null, 2);
        } catch(e) {
            // 不是JSON，直接显示原始文本
            content.textContent = rawData;
        }
    }
    document.getElementById('rawDataModal').style.display = 'flex';
}

function closeRawDataModal() {
    document.getElementById('rawDataModal').style.display = 'none';
}

function copyRawData() {
    var text = document.getElementById('rawDataContent').textContent;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
    alert('已复制到剪贴板');
}

// 点击遮罩层关闭原始数据模态框
document.getElementById('rawDataModal').addEventListener('click', function(e) {
    if (e.target === this) closeRawDataModal();
});

// ESC键关闭
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRawDataModal();
    }
});

// ==================== 自动刷新消息日志 ====================
var msgAutoRefreshTimer = null;
var msgLastCount = <?= $total ?>;

function startMsgAutoRefresh() {
    stopMsgAutoRefresh();
    // 每10秒检查是否有新消息（轻量级API调用，不再获取整个页面HTML）
    msgAutoRefreshTimer = setInterval(function() {
        var urlParams = new URLSearchParams(window.location.search);
        var currentPage = parseInt(urlParams.get('page') || '1');
        // 只在第1页自动刷新，避免在翻页时意外刷新
        if (currentPage === 1 && !document.getElementById('rawDataModal').style.display.includes('flex') && !document.getElementById('filePreviewModal').classList.contains('show')) {
            autoRefreshMsgList();
        }
    }, 10000);
}

function stopMsgAutoRefresh() {
    if (msgAutoRefreshTimer) {
        clearInterval(msgAutoRefreshTimer);
        msgAutoRefreshTimer = null;
    }
}

function autoRefreshMsgList() {
    // 轻量级检查：通过API获取消息总数，避免下载整个页面HTML
    var urlParams = new URLSearchParams(window.location.search);
    var data = {};
    var currentAppid = urlParams.get('appid') || '';
    var currentDirection = urlParams.get('direction') || '';
    var currentDays = urlParams.get('days') || '';
    if (currentAppid) data.appid = currentAppid;
    if (currentDirection) data.direction = currentDirection;
    if (currentDays) data.days = currentDays;
    
    apiCall('msg_count', data, function(res) {
        if (res.success) {
            var newCount = res.count || 0;
            if (newCount !== msgLastCount) {
                msgLastCount = newCount;
                location.reload();
            }
        }
    });
}

// 启动自动刷新
startMsgAutoRefresh();

// 页面可见性变化时暂停/恢复
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        stopMsgAutoRefresh();
    } else {
        startMsgAutoRefresh();
        autoRefreshMsgList();
    }
});
</script>

<!-- ==================== 文件预览模态框 ==================== -->
<div class="file-preview-modal" id="filePreviewModal" onclick="if(event.target===this)closeFilePreview()">
    <div class="file-preview-content">
        <div class="file-preview-header">
            <h3 id="filePreviewTitle">文件预览</h3>
            <button class="btn btn-outline btn-sm" onclick="closeFilePreview()">&times;</button>
        </div>
        <div class="file-preview-body" id="filePreviewBody"></div>
    </div>
</div>

<script>
// ==================== 工具函数 ====================
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// ==================== 文件URL预览 ====================
function previewFileUrl(fileName, fileType, fileUrl) {
    var modal = document.getElementById('filePreviewModal');
    var title = document.getElementById('filePreviewTitle');
    var body = document.getElementById('filePreviewBody');
    
    title.textContent = fileName || '文件预览';
    
    var html = '';
    if (fileType === 'video') {
        html = '<video controls preload="metadata" style="max-width:100%;max-height:60vh;border-radius:8px;">';
        html += '<source src="' + escapeHtml(fileUrl) + '">';
        html += '</video>';
    } else if (fileType === 'audio') {
        html = '<audio controls style="width:100%;">';
        html += '<source src="' + escapeHtml(fileUrl) + '">';
        html += '</audio>';
    } else if (fileType === 'image') {
        html = '<img src="' + escapeHtml(fileUrl) + '" style="max-width:100%;max-height:60vh;border-radius:8px;" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'<p>图片加载失败</p>\'" referrerpolicy="no-referrer">';
    } else if (fileType === 'file') {
        var ext = fileName.split('.').pop().toLowerCase();
        var iconMap = {
            // 文档
            'pdf': '&#128213;', 'doc': '&#128209;', 'docx': '&#128209;',
            'xls': '&#128202;', 'xlsx': '&#128202;', 'ppt': '&#128200;', 'pptx': '&#128200;',
            'txt': '&#128221;', 'csv': '&#128221;', 'md': '&#128221;', 'rtf': '&#128209;',
            'odt': '&#128209;', 'ods': '&#128202;', 'odp': '&#128200;',
            // 代码
            'js': '&#128187;', 'ts': '&#128187;', 'jsx': '&#128187;', 'tsx': '&#128187;',
            'mjs': '&#128187;', 'cjs': '&#128187;',
            'css': '&#128187;', 'scss': '&#128187;', 'less': '&#128187;',
            'html': '&#128187;', 'htm': '&#128187;', 'xml': '&#128187;',
            'json': '&#128187;', 'yaml': '&#128187;', 'yml': '&#128187;',
            'php': '&#128187;', 'py': '&#128187;', 'java': '&#128187;',
            'c': '&#128187;', 'cpp': '&#128187;', 'h': '&#128187;',
            'cs': '&#128187;', 'go': '&#128187;', 'rs': '&#128187;', 'rb': '&#128187;',
            'swift': '&#128187;', 'kt': '&#128187;', 'sh': '&#128187;', 'sql': '&#128187;',
            'vue': '&#128187;', 'toml': '&#128187;', 'ini': '&#128187;', 'conf': '&#128187;',
            // 压缩包
            'zip': '&#128230;', 'rar': '&#128230;', '7z': '&#128230;',
            'tar': '&#128230;', 'gz': '&#128230;', 'bz2': '&#128230;', 'xz': '&#128230;',
            // 字体
            'ttf': '&#128288;', 'otf': '&#128288;', 'woff': '&#128288;', 'woff2': '&#128288;',
            // 应用
            'exe': '&#9881;', 'msi': '&#9881;', 'apk': '&#128241;', 'ipa': '&#128241;',
            'deb': '&#9881;', 'rpm': '&#9881;', 'dmg': '&#9881;', 'pkg': '&#9881;',
            'jar': '&#9749;', 'war': '&#9749;',
            // 电子书
            'epub': '&#128214;', 'mobi': '&#128214;', 'azw3': '&#128214;',
            // 镜像
            'iso': '&#128190;', 'img': '&#128190;'
        };
        var icon = iconMap[ext] || '&#128206;';
        var typeLabel = ext ? ext.toUpperCase() + ' 文件' : '文件';
        // 代码类文件特殊提示
        var codeExts = ['js','ts','jsx','tsx','mjs','cjs','css','scss','less','html','htm','xml',
                        'json','yaml','yml','php','py','java','c','cpp','h','hpp','cs','go','rs',
                        'rb','swift','kt','sh','bash','sql','vue','toml','ini','conf','env','md'];
        if (codeExts.indexOf(ext) > -1) {
            typeLabel = ext.toUpperCase() + ' 代码文件';
        }
        html = '<div class="file-download-card">';
        html += '<div class="file-icon-large">' + icon + '</div>';
        html += '<div class="file-info">';
        html += '<div class="file-name">' + escapeHtml(fileName) + '</div>';
        html += '<div class="file-type">' + typeLabel + '</div>';
        html += '</div>';
        html += '<a href="' + escapeHtml(fileUrl) + '" target="_blank" class="download-btn">下载</a>';
        html += '</div>';
    } else {
        html = '<a href="' + escapeHtml(fileUrl) + '" target="_blank" style="color:var(--primary);text-decoration:underline;">' + escapeHtml(fileUrl) + '</a>';
    }
    
    body.innerHTML = html;
    modal.classList.add('show');
}

function closeFilePreview() {
    var modal = document.getElementById('filePreviewModal');
    modal.classList.remove('show');
    var body = document.getElementById('filePreviewBody');
    // 清空内容停止视频/音频播放
    body.innerHTML = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeFilePreview();
    }
});

// ==================== @提及昵称替换 ====================
var msgNicknameCache = {};
var msgNicknameQueried = {};
function processAtMentionsInMsgLog() {
    // 获取当前页面的机器人appid（从URL参数）
    var urlParams = new URLSearchParams(window.location.search);
    var botAppid = urlParams.get('appid') || '';
    if (!botAppid) return;

    // 收集所有消息内容单元格中的@提及用户ID
    var contentCells = document.querySelectorAll('td:nth-child(7)');
    var userIdsToLoad = [];
    var cellData = []; // [{cell, matches: [{fullMatch, userId}]}]

    contentCells.forEach(function(cell) {
        var html = cell.innerHTML;
        // 匹配 &lt;@USERID&gt; 格式（HTML转义后的 <@USERID>）
        var atRegex = /&lt;@([A-Fa-f0-9]{16,})&gt;/g;
        var matches = [];
        var match;
        while ((match = atRegex.exec(html)) !== null) {
            matches.push({fullMatch: match[0], userId: match[1]});
            if (!msgNicknameCache[match[1]] && !msgNicknameQueried[match[1]] && userIdsToLoad.indexOf(match[1]) === -1) {
                userIdsToLoad.push(match[1]);
            }
        }
        // 也匹配 &lt;qqbot-at-user id=&quot;...&quot; /&gt; 格式（htmlspecialchars将"转为&quot;）
        var qbRegex = /&lt;qqbot-at-user\s+id=&quot;([^&]+)&quot;\s*\/?&gt;/g;
        while ((match = qbRegex.exec(html)) !== null) {
            matches.push({fullMatch: match[0], userId: match[1]});
            if (!msgNicknameCache[match[1]] && !msgNicknameQueried[match[1]] && userIdsToLoad.indexOf(match[1]) === -1) {
                userIdsToLoad.push(match[1]);
            }
        }
        if (matches.length > 0) {
            cellData.push({cell: cell, matches: matches});
        }
    });

    // 标记为已查询
    userIdsToLoad.forEach(function(id) {
        msgNicknameQueried[id] = true;
    });

    if (userIdsToLoad.length > 0) {
        // 批量加载昵称
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/chat_api.php?action=get_nicknames', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && res.data) {
                        var nicknames = res.data.user_nicknames || {};
                        var groupNames = res.data.group_names || {};
                        var userRemarks = res.data.user_remarks || {};
                        var groupRemarks = res.data.group_remarks || {};
                        // 合并昵称、群名、备注到缓存
                        for (var id in nicknames) {
                            if (nicknames[id]) msgNicknameCache[id] = nicknames[id];
                        }
                        for (var gid in groupNames) {
                            if (groupNames[gid]) msgNicknameCache[gid] = groupNames[gid];
                        }
                        for (var id in userRemarks) {
                            if (userRemarks[id]) msgNicknameCache[id] = userRemarks[id];
                        }
                        for (var gid in groupRemarks) {
                            if (groupRemarks[gid]) msgNicknameCache[gid] = groupRemarks[gid];
                        }
                        // 替换所有单元格中的@提及
                        replaceAtMentionsInMsgLog(cellData);
                    }
                } catch(e) {
                    console.error('解析昵称响应失败:', e);
                }
            }
        };
        xhr.send('appid=' + encodeURIComponent(botAppid) + '&user_ids=' + encodeURIComponent(JSON.stringify(userIdsToLoad)));
    } else {
        // 所有昵称已在缓存中，直接替换
        replaceAtMentionsInMsgLog(cellData);
    }
}

function replaceAtMentionsInMsgLog(cellData) {
    cellData.forEach(function(item) {
        var html = item.cell.innerHTML;
        item.matches.forEach(function(m) {
            var nick = msgNicknameCache[m.userId] || (m.userId.length > 8 ? m.userId.substring(0, 8) + '...' : m.userId);
            var replacement = '<span style="color:#7b1fa2;background:#f3e5f5;padding:1px 4px;border-radius:3px;font-size:12px;">@' + escapeHtml(nick) + '</span>';
            // 需要转义fullMatch中的特殊字符用于正则
            var escapedMatch = m.fullMatch.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            html = html.replace(new RegExp(escapedMatch, 'g'), replacement);
        });
        item.cell.innerHTML = html;
    });
}

// 页面加载完成后处理@提及
document.addEventListener('DOMContentLoaded', function() {
    processAtMentionsInMsgLog();
});
</script>

<?php require_once('footer.php'); ?>
