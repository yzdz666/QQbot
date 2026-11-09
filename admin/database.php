<?php
/**
 * 管理后台 - 数据库管理
 * 可视化查看各数据表的行数与空间占用（定位数据库体积膨胀来源），
 * 支持分表清理（消息/日志支持按天数清理）与一键 VACUUM 回收磁盘空间。
 * 清理/重建操作均写入操作记录（system_logs.log_type='后台操作'）。
 */
$pageTitle = '数据库管理';
require_once(__DIR__ . '/header.php');

// ==================== 表定义 ====================
// 表名 => [中文说明, 是否可清理, 支持按天数清理的时间列]
$tableDefs = [
    'messages'      => ['消息记录（聊天/收发日志）', true, 'created_at'],
    'system_logs'   => ['系统日志与操作记录', true, 'created_at'],
    'event_dedup'   => ['事件去重（防重复推送）', true, null],
    'ip_records'    => ['IP 访问记录（登录尝试）', true, 'created_at'],
    'statistics'    => ['每日统计数据', true, null],
    'users'         => ['用户数据（自动记录活跃用户）', true, null],
    'group_remarks' => ['群成员备注', true, null],
    'groups'        => ['群组数据', false, null],
    'bots'          => ['机器人配置（含 Secret）', false, null],
    'plugin_status' => ['插件启用状态', false, null],
    'kv_store'      => ['键值存储（插件运行数据）', false, null],
    'settings'      => ['系统设置', false, null],
    'sessions'      => ['登录会话', false, null],
    'admin'         => ['管理员账户', false, null],
    'ai_config'     => ['AI 接口配置', false, null],
];

// ==================== 定位数据库文件与文件级信息 ====================
$dbFile = '';
try {
    foreach (db()->fetchAll('PRAGMA database_list') as $r) {
        if ($r['name'] === 'main') { $dbFile = $r['file']; break; }
    }
} catch (Exception $e) {}
if ($dbFile === '') {
    $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__) . '/';
    $dbFile = $root . 'data/bot.db';
}
clearstatcache(true, $dbFile);
$dbSize  = @filesize($dbFile) ?: 0;
$walSize = @filesize($dbFile . '-wal') ?: 0;

$pageSize = (int) db()->fetchColumn('PRAGMA page_size');
$pageCount = (int) db()->fetchColumn('PRAGMA page_count');
$freeCount = (int) db()->fetchColumn('PRAGMA freelist_count');
$freeBytes = $pageSize * $freeCount;          // 空闲页 = VACUUM 可回收
$liveBytes = $pageSize * ($pageCount - $freeCount); // 实际占用

// ==================== 逐表统计（行数 + 数据字节） ====================
$tableStats = [];
$totalRows = 0;
$totalBytes = 0;
foreach ($tableDefs as $t => $def) {
    try {
        $rows = (int) db()->fetchColumn("SELECT COUNT(*) FROM \"{$t}\"");
        // 数据字节 = 全部 TEXT/BLOB 列内容长度合计（估算数据体积）
        $bytes = 0;
        $cols = db()->fetchAll("PRAGMA table_info(\"{$t}\")");
        $sums = [];
        foreach ($cols as $c) {
            $ty = strtoupper((string)$c['type']);
            if ($ty === 'TEXT' || $ty === 'BLOB') {
                $sums[] = "SUM(LENGTH(\"{$c['name']}\"))";
            }
        }
        if ($sums) {
            $r = db()->fetch("SELECT " . implode(' + ', $sums) . " AS b FROM \"{$t}\"");
            $bytes = (int)($r['b'] ?? 0);
        }
    } catch (Exception $e) {
        $rows = 0; $bytes = 0;
    }
    $tableStats[$t] = [
        'desc' => $def[0], 'clearable' => $def[1], 'timeCol' => $def[2],
        'rows' => $rows, 'bytes' => $bytes,
    ];
    $totalRows += $rows;
    $totalBytes += $bytes;
}

function dbm_fmt_bytes($b) {
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return round($b / 1024, 1) . ' KB';
    return $b . ' B';
}
?>
<style>
.db-size-bar { height:8px; border-radius:4px; background:var(--border); overflow:hidden; min-width:80px; }
.db-size-bar > div { height:100%; border-radius:4px; }
.db-table-name { font-family:monospace; font-size:12px; color:var(--text-muted); }
.db-actions { display:flex; gap:6px; flex-wrap:wrap; }
.vacuum-result { font-family:monospace; font-size:12px; }
</style>

<div class="page-header">
  <h2>数据库管理</h2>
  <div class="actions">
    <button class="btn btn-outline" onclick="window.location.reload()">刷新统计</button>
  </div>
</div>

<!-- ==================== 文件级概览 ==================== -->
<div class="stats-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px,1fr)); gap:12px; margin-bottom:16px;">
  <div class="stat-card">
    <div class="stat-label">数据库文件大小</div>
    <div class="stat-value"><?= dbm_fmt_bytes($dbSize) ?></div>
    <div class="text-muted" style="font-size:11px;">bot.db<?= $walSize > 0 ? ' + WAL ' . dbm_fmt_bytes($walSize) : '' ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">空闲可回收空间</div>
    <div class="stat-value" style="color: <?= $freeBytes > 1048576 ? 'var(--danger)' : 'inherit' ?>;"><?= dbm_fmt_bytes($freeBytes) ?></div>
    <div class="text-muted" style="font-size:11px;">历史删除产生，VACUUM 可回收</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">实际数据占用</div>
    <div class="stat-value"><?= dbm_fmt_bytes($liveBytes) ?></div>
    <div class="text-muted" style="font-size:11px;">含索引 <?= round(($pageCount - $freeCount) * $pageSize / 1048576, 1) ?> MB</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">数据总行数</div>
    <div class="stat-value"><?= number_format($totalRows) ?></div>
    <div class="text-muted" style="font-size:11px;">全部表合计</div>
  </div>
</div>

<?php if ($freeBytes > 1048576): ?>
<div class="card mb-3" style="border-left:4px solid var(--danger);">
  <div class="card-body" style="padding:12px 16px;">
    <b style="color:var(--danger);">检测到 <?= dbm_fmt_bytes($freeBytes) ?> 空闲空间未回收</b>
    <span class="text-muted" style="font-size:12px;">—— 历史数据删除后 SQLite 不会自动缩小文件。点击下方「VACUUM 重建数据库」即可回收，预计文件可缩小至约 <?= dbm_fmt_bytes($liveBytes) ?>。</span>
  </div>
</div>
<?php endif; ?>

<!-- ==================== 分表统计与清理 ==================== -->
<div class="card mb-3">
  <div class="card-header">
    <h3>数据表占用明细</h3>
    <span class="text-muted" style="margin-left:auto; font-size:12px;">数据字节为文本内容体积估算（不含索引）；清理后需 VACUUM 才会缩小文件</span>
  </div>
  <div class="card-body no-padding">
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th style="width:230px;">数据表</th>
            <th style="width:110px;">行数</th>
            <th style="width:110px;">数据大小</th>
            <th style="width:180px;">占比</th>
            <th>清理操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($tableStats as $t => $s):
            $pct = $totalBytes > 0 ? $s['bytes'] * 100 / $totalBytes : 0;
            $barColor = $pct > 50 ? 'var(--danger)' : ($pct > 15 ? '#f0a020' : 'var(--primary)');
        ?>
          <tr>
            <td>
              <div><?= htmlspecialchars($s['desc']) ?></div>
              <div class="db-table-name"><?= htmlspecialchars($t) ?></div>
            </td>
            <td style="font-family:monospace;"><?= number_format($s['rows']) ?></td>
            <td style="font-family:monospace;"><?= $s['bytes'] > 0 ? dbm_fmt_bytes($s['bytes']) : '—' ?></td>
            <td>
              <?php if ($s['bytes'] > 0): ?>
              <div style="display:flex; align-items:center; gap:8px;">
                <div class="db-size-bar" style="flex:1;"><div style="width:<?= round(max($pct, 2), 1) ?>%; background:<?= $barColor ?>;"></div></div>
                <span class="text-muted" style="font-size:11px; min-width:38px;"><?= round($pct, 1) ?>%</span>
              </div>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td>
              <?php if ($s['clearable']): ?>
              <div class="db-actions">
                <?php if ($s['timeCol'] !== null): ?>
                <button class="btn btn-outline btn-sm" onclick="dbClear('<?= $t ?>', 30)">清理30天前</button>
                <button class="btn btn-outline btn-sm" onclick="dbClear('<?= $t ?>', 90)">清理90天前</button>
                <?php endif; ?>
                <button class="btn btn-danger btn-sm" onclick="dbClear('<?= $t ?>', 0)">清空</button>
              </div>
              <?php else: ?>
              <span class="text-muted" style="font-size:12px;">核心数据，不提供清理</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ==================== VACUUM 与说明 ==================== -->
<div class="dashboard-row" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px,1fr)); gap:16px; align-items:start;">
  <div class="card">
    <div class="card-header"><h3>VACUUM 重建数据库</h3></div>
    <div class="card-body">
      <p class="text-muted" style="font-size:12px; margin-top:0;">
        清理数据后文件体积不会自动缩小，VACUUM 会重建整个数据库文件并回收全部空闲空间。
        当前预计可回收 <b style="color:var(--danger);"><?= dbm_fmt_bytes($freeBytes) ?></b>。
      </p>
      <ul class="text-muted" style="font-size:12px; padding-left:18px; margin:0 0 12px;">
        <li>执行期间请勿操作后台或收发消息（需要独占数据库）</li>
        <li>耗时取决于文件大小，建议在机器人空闲时执行</li>
        <li>请确保服务器磁盘剩余空间充足（约等于当前数据库大小）</li>
      </ul>
      <button class="btn btn-primary" id="vacuumBtn" onclick="dbVacuum()">VACUUM 重建数据库（回收 <?= dbm_fmt_bytes($freeBytes) ?>）</button>
      <div class="vacuum-result text-muted" id="vacuumResult" style="margin-top:10px;"></div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>体积膨胀常见原因</h3></div>
    <div class="card-body">
      <ul style="font-size:12px; padding-left:18px; margin:0; line-height:1.9;">
        <li><b>系统日志长期积累</b>：每条运行日志约 1KB，数万条即上百 MB，可在上方按天数清理</li>
        <li><b>删除后未 VACUUM</b>：SQLite 删除数据只标记空闲页，文件不缩小，需定期 VACUUM</li>
        <li><b>消息 raw_data 全量存储</b>：每条消息保存完整事件报文，量大后明显占用空间</li>
        <li><b>建议</b>：定期「清理 90 天前日志 + VACUUM」即可长期保持数据库精简</li>
      </ul>
    </div>
  </div>
</div>

<script>
function dbApiCall(action, data, cb) {
    var fd = new FormData();
    fd.append('action', action);
    for (var k in data) fd.append(k, data[k]);
    fetch('api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
            if (r.status === 401) { window.location.href = 'login.php'; throw new Error('未登录'); }
            return r.json();
        })
        .then(cb)
        .catch(function (e) { if (e.message !== '未登录') alert('请求失败: ' + e.message); });
}

function dbClear(table, days) {
    var msg = days > 0
        ? '确定清理表 ' + table + ' 中 ' + days + ' 天前的数据吗？\n清理后不可恢复！'
        : '确定清空表 ' + table + ' 的全部数据吗？\n清空后不可恢复！';
    if (!confirm(msg)) return;
    if (days === 0 && !confirm('再次确认：将清空 ' + table + ' 表全部数据，建议先备份 data/bot.db！')) return;
    dbApiCall('db_clear', { table: table, days: days }, function (res) {
        alert(res.message || (res.success ? '完成' : '操作失败'));
        if (res.success) setTimeout(function () { window.location.reload(); }, 400);
    });
}

function dbVacuum() {
    if (!confirm('确定执行 VACUUM 重建数据库吗？\n\n将回收全部空闲空间并缩小文件体积。\n执行期间请勿操作后台，耗时取决于文件大小。')) return;
    var btn = document.getElementById('vacuumBtn');
    btn.disabled = true;
    btn.textContent = '正在重建数据库，请稍候...';
    document.getElementById('vacuumResult').textContent = '';
    dbApiCall('db_vacuum', {}, function (res) {
        if (res.success) {
            document.getElementById('vacuumResult').textContent = res.message;
            alert(res.message);
            setTimeout(function () { window.location.reload(); }, 800);
        } else {
            alert(res.message || 'VACUUM 失败');
            btn.disabled = false;
            btn.textContent = 'VACUUM 重建数据库';
        }
    });
}
</script>

<?php require_once(__DIR__ . '/footer.php'); ?>
