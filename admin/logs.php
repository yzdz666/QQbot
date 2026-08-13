<?php
/**
 * 管理后台 - 系统日志
 * 显示所有日志（system_logs 表 + messages 表的原始事件）
 */
$pageTitle = '系统日志';
require_once('header.php');

// ==================== 获取筛选参数 ====================
$appid   = isset($_GET['appid']) ? trim($_GET['appid']) : '';
$level   = isset($_GET['level']) ? trim($_GET['level']) : '';
$logType = isset($_GET['log_type']) ? trim($_GET['log_type']) : '';
$q       = isset($_GET['q']) ? trim($_GET['q']) : '';
$days    = isset($_GET['days']) ? trim($_GET['days']) : '';
$page    = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// 仅允许合法值
$validLevels = ['INFO', 'WARNING', 'ERROR', 'DEBUG'];
if (!in_array($level, $validLevels, true)) $level = '';

$validDays = ['1', '3', '7', '30', '90'];
if (!in_array($days, $validDays, true)) $days = '';

// 获取机器人列表
$bots = getBots();

// ==================== 构建查询条件 ====================
$where  = [];
$params = [];
if ($appid !== '') {
    $where[]  = 'appid = ?';
    $params[] = $appid;
}
if ($level !== '') {
    $where[]  = 'level = ?';
    $params[] = $level;
}
if ($logType !== '') {
    $where[]  = 'log_type = ?';
    $params[] = $logType;
}
if ($q !== '') {
    $where[]  = 'content LIKE ?';
    $params[] = '%' . $q . '%';
}
if ($days !== '') {
    $where[]  = "created_at >= datetime('now','localtime','-" . intval($days) . " days')";
}
$whereClause = '';
if (!empty($where)) {
    $whereClause = ' WHERE ' . implode(' AND ', $where);
}

// ==================== 分页 ====================
$perPage = 50;
try {
    $total = (int) db()->fetchColumn("SELECT COUNT(*) FROM system_logs" . $whereClause, $params);
} catch (Exception $e) {
    $total = 0;
}

$totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
$page       = max(1, min($totalPages, $page));
$offset     = ($page - 1) * $perPage;

// ==================== 查询日志 ====================
try {
    $logs = db()->fetchAll(
        "SELECT * FROM system_logs" . $whereClause . " ORDER BY id DESC LIMIT " . intval($perPage) . " OFFSET " . intval($offset),
        $params
    );
} catch (Exception $e) {
    $logs = [];
}

// ==================== 构建分页基础查询字符串 ====================
$pageParams = [];
if ($appid !== '')   $pageParams['appid']   = $appid;
if ($level !== '')   $pageParams['level']   = $level;
if ($logType !== '') $pageParams['log_type'] = $logType;
if ($days !== '')    $pageParams['days']    = $days;
if ($q !== '')       $pageParams['q']       = $q;
$baseQuery   = http_build_query($pageParams);
$pageBaseUrl = 'logs.php' . ($baseQuery !== '' ? '?' . $baseQuery . '&' : '?');

// 日志级别样式映射
$levelStyles = [
    'INFO'    => 'badge-info',
    'WARNING' => 'badge-warning',
    'ERROR'   => 'badge-danger',
    'DEBUG'   => '',
];
?>

<style>
.log-entry {
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    line-height: 1.6;
    display: flex;
    gap: 10px;
    align-items: flex-start;
}
.log-entry:hover { background: var(--bg); }
.log-time {
    color: var(--text-muted);
    font-size: 12px;
    white-space: nowrap;
    flex-shrink: 0;
    min-width: 145px;
    font-family: 'SF Mono','Consolas','Monaco',monospace;
}
.log-level {
    flex-shrink: 0;
    min-width: 70px;
    text-align: center;
}
.log-appid {
    flex-shrink: 0;
    min-width: 100px;
    font-size: 11px;
    color: var(--text-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.log-type {
    flex-shrink: 0;
    min-width: 60px;
    font-size: 11px;
    color: var(--text-secondary);
}
.log-content {
    flex: 1;
    word-break: break-all;
    white-space: pre-wrap;
    font-family: 'SF Mono','Consolas','Monaco',monospace;
    font-size: 12px;
}
.log-content.expanded {
    max-height: none;
}
.log-content.collapsed {
    max-height: 60px;
    overflow: hidden;
    position: relative;
}
.log-content.collapsed::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 20px;
    background: linear-gradient(transparent, var(--card-bg, #fff));
}
.log-toggle {
    flex-shrink: 0;
    cursor: pointer;
    color: var(--primary);
    font-size: 11px;
    user-select: none;
}
.badge-info { background: #e3f2fd; color: #1565c0; }
.badge-warning { background: #fff3e0; color: #e65100; }
.badge-danger { background: #fce4ec; color: #c62828; }
</style>

<div class="page-header">
  <h2>系统日志</h2>
  <div class="actions">
    <button class="btn btn-danger" onclick="clearLogs()">清空日志</button>
  </div>
</div>

<!-- ==================== 统计信息 ==================== -->
<div class="card mb-3">
  <div class="card-body" style="display:flex; gap:20px; flex-wrap:wrap;">
    <?php
    try {
        $totalCount = (int) db()->fetchColumn("SELECT COUNT(*) FROM system_logs");
        $errorCount = (int) db()->fetchColumn("SELECT COUNT(*) FROM system_logs WHERE level = 'ERROR'");
        $warnCount  = (int) db()->fetchColumn("SELECT COUNT(*) FROM system_logs WHERE level = 'WARNING'");
        $todayCount = (int) db()->fetchColumn("SELECT COUNT(*) FROM system_logs WHERE created_at >= datetime('now','localtime','start of day')");
    } catch (Exception $e) {
        $totalCount = $errorCount = $warnCount = $todayCount = 0;
    }
    ?>
    <div style="text-align:center;">
      <div style="font-size:24px; font-weight:700; color:var(--primary);"><?= $totalCount ?></div>
      <div style="font-size:12px; color:var(--text-muted);">总日志</div>
    </div>
    <div style="text-align:center;">
      <div style="font-size:24px; font-weight:700; color:var(--danger);"><?= $errorCount ?></div>
      <div style="font-size:12px; color:var(--text-muted);">错误</div>
    </div>
    <div style="text-align:center;">
      <div style="font-size:24px; font-weight:700; color:var(--warning, #e65100);"><?= $warnCount ?></div>
      <div style="font-size:12px; color:var(--text-muted);">警告</div>
    </div>
    <div style="text-align:center;">
      <div style="font-size:24px; font-weight:700; color:var(--success, #2e7d32);"><?= $todayCount ?></div>
      <div style="font-size:12px; color:var(--text-muted);">今日</div>
    </div>
  </div>
</div>

<!-- ==================== 筛选区 ==================== -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" action="logs.php" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
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
      <div class="form-group" style="flex:0 0 120px; margin-bottom:0;">
        <label>级别</label>
        <select name="level" class="form-control">
          <option value="" <?= ($level === '') ? 'selected' : '' ?>>全部</option>
          <option value="INFO" <?= ($level === 'INFO') ? 'selected' : '' ?>>INFO</option>
          <option value="WARNING" <?= ($level === 'WARNING') ? 'selected' : '' ?>>WARNING</option>
          <option value="ERROR" <?= ($level === 'ERROR') ? 'selected' : '' ?>>ERROR</option>
          <option value="DEBUG" <?= ($level === 'DEBUG') ? 'selected' : '' ?>>DEBUG</option>
        </select>
      </div>
      <div class="form-group" style="flex:0 0 120px; margin-bottom:0;">
        <label>类型</label>
        <select name="log_type" class="form-control">
          <option value="" <?= ($logType === '') ? 'selected' : '' ?>>全部</option>
          <option value="system" <?= ($logType === 'system') ? 'selected' : '' ?>>system</option>
          <option value="api" <?= ($logType === 'api') ? 'selected' : '' ?>>api</option>
          <option value="plugin" <?= ($logType === 'plugin') ? 'selected' : '' ?>>plugin</option>
          <option value="ws" <?= ($logType === 'ws') ? 'selected' : '' ?>>ws</option>
          <option value="wh" <?= ($logType === 'wh') ? 'selected' : '' ?>>wh</option>
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
        <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="搜索日志内容">
      </div>
      <div class="form-group" style="flex:0 0 auto; margin-bottom:0;">
        <button type="submit" class="btn btn-primary">筛选</button>
        <a href="logs.php" class="btn btn-outline">重置</a>
      </div>
    </form>
  </div>
</div>

<!-- ==================== 日志列表 ==================== -->
<div class="card">
  <div class="card-header">
    <h3>日志列表（共 <?= $total ?> 条）</h3>
  </div>
  <div class="card-body no-padding">
    <?php if (empty($logs)): ?>
      <div class="empty-state">
        <div class="empty-icon">--</div>
        <p>暂无日志记录</p>
      </div>
    <?php else: ?>
      <?php foreach ($logs as $log): 
        $logLevel = $log['level'] ?? 'INFO';
        $logContent = $log['content'] ?? '';
        $isLong = mb_strlen($logContent, 'UTF-8') > 200;
      ?>
        <div class="log-entry">
          <span class="log-time"><?= htmlspecialchars($log['created_at'] ?? '') ?></span>
          <span class="log-level">
            <span class="badge <?= $levelStyles[$logLevel] ?? '' ?>"><?= htmlspecialchars($logLevel) ?></span>
          </span>
          <span class="log-appid" title="<?= htmlspecialchars($log['appid'] ?? '') ?>"><?= htmlspecialchars(mb_substr($log['appid'] ?? '', 0, 16, 'UTF-8')) ?></span>
          <span class="log-type"><?= htmlspecialchars($log['log_type'] ?? '') ?></span>
          <span class="log-content <?= $isLong ? 'collapsed' : '' ?>" onclick="this.classList.toggle('collapsed'); this.classList.toggle('expanded');"><?= htmlspecialchars($logContent) ?></span>
        </div>
      <?php endforeach; ?>
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

<script>
function apiCall(action, data, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php?action=' + encodeURIComponent(action), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            var res;
            try { res = JSON.parse(xhr.responseText); } catch (e) { res = { success: false, message: '响应解析失败' }; }
            callback(res);
        }
    };
    var params = [];
    for (var key in data) {
        if (data.hasOwnProperty(key)) params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
    }
    xhr.send(params.join('&'));
}

function clearLogs() {
    var currentAppid = <?= json_encode($appid, JSON_UNESCAPED_UNICODE) ?>;
    var tip;
    if (currentAppid) {
        tip = '确定要清空机器人「' + currentAppid + '」的全部系统日志吗？\n该操作不可恢复！';
    } else {
        tip = '确定要清空全部系统日志吗？\n该操作不可恢复！';
    }
    if (!confirm(tip)) return;
    apiCall('clear_logs', { appid: currentAppid }, function(res) {
        if (res.success) {
            alert(res.message || '日志已清空');
            location.reload();
        } else {
            alert(res.message || '清空失败');
        }
    });
}

// 自动刷新（每15秒检查新日志）
var logLastCount = <?= $total ?>;
setInterval(function() {
    if (document.hidden) return;
    apiCall('log_count', {}, function(res) {
        if (res.success && res.count !== logLastCount) {
            logLastCount = res.count;
            location.reload();
        }
    });
}, 15000);
</script>

<?php require_once('footer.php'); ?>
