<?php
/**
 * 管理后台 - 操作记录
 * 可视化展示后台全部管理操作（谁在何时做了什么），支持筛选、分页与按时间段清理。
 * 数据来源: system_logs 表（log_type='后台操作' 或 content 以 [操作] 开头的历史记录）
 */
$pageTitle = '操作记录';
require_once(__DIR__ . '/header.php');

$bots = db()->fetchAll("SELECT appid, nickname FROM bots ORDER BY appid");
$opTotal = 0;
$opToday = 0;
$opWeek = 0;
try {
    $opTotal = (int) db()->fetchColumn("SELECT COUNT(*) FROM system_logs WHERE log_type = '后台操作' OR content LIKE '[%]'");
    $opToday = (int) db()->fetchColumn("SELECT COUNT(*) FROM system_logs WHERE (log_type = '后台操作' OR content LIKE '[%]') AND created_at >= datetime('now','localtime','start of day')");
    $opWeek  = (int) db()->fetchColumn("SELECT COUNT(*) FROM system_logs WHERE (log_type = '后台操作' OR content LIKE '[%]') AND created_at >= datetime('now','localtime','-7 days')");
} catch (Exception $e) {}
?>

<div class="page-header">
  <h2>操作记录</h2>
  <div class="actions">
    <button class="btn btn-outline" onclick="loadOpLogs()">刷新</button>
  </div>
</div>

<!-- ==================== 统计概览 ==================== -->
<div class="stats-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:12px; margin-bottom:16px;">
  <div class="stat-card">
    <div class="stat-label">累计操作</div>
    <div class="stat-value" id="statTotal"><?= $opTotal ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">今日操作</div>
    <div class="stat-value" id="statToday"><?= $opToday ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">近7天操作</div>
    <div class="stat-value" id="statWeek"><?= $opWeek ?></div>
  </div>
</div>

<!-- ==================== 操作记录列表 ==================== -->
<div class="card mb-3">
  <div class="card-header">
    <h3>后台操作明细</h3>
    <div style="margin-left:auto; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
      <input type="text" id="oplogKw" class="form-control" placeholder="搜索操作/详情..." style="width:180px; height:32px;" onkeydown="if(event.key==='Enter')searchOpLogs()">
      <select id="oplogBot" class="form-control" style="width:auto; height:32px;" onchange="searchOpLogs()">
        <option value="">全部机器人</option>
        <?php foreach ($bots as $b): ?>
          <option value="<?= htmlspecialchars($b['appid']) ?>"><?= htmlspecialchars($b['nickname'] ?: $b['appid']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-sm" onclick="searchOpLogs()">搜索</button>
    </div>
  </div>
  <div class="card-body no-padding">
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th style="width:170px;">时间</th>
            <th style="width:150px;">操作</th>
            <th style="width:90px;">操作人</th>
            <th>详情</th>
            <th style="width:120px;">相关机器人</th>
          </tr>
        </thead>
        <tbody id="oplogBody">
          <tr><td colspan="5" class="text-muted" style="text-align:center; padding:24px;">加载中...</td></tr>
        </tbody>
      </table>
    </div>
    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 14px; border-top:1px solid var(--border); flex-wrap:wrap;">
      <span class="text-muted" id="oplogInfo" style="font-size:12px;"></span>
      <div style="display:flex; gap:6px; align-items:center;">
        <button class="btn btn-outline btn-sm" id="oplogPrev" onclick="oplogGoPage(-1)">上一页</button>
        <span class="text-muted" style="font-size:12px;" id="oplogPageNum"></span>
        <button class="btn btn-outline btn-sm" id="oplogNext" onclick="oplogGoPage(1)">下一页</button>
      </div>
    </div>
  </div>
</div>

<!-- ==================== 清理与登录记录 ==================== -->
<div class="dashboard-row" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px,1fr)); gap:16px; align-items:start;">
  <div class="card">
    <div class="card-header"><h3>清理操作记录</h3></div>
    <div class="card-body">
      <p class="text-muted" style="font-size:12px; margin-top:0;">
        清理仅影响「后台操作记录」，不影响消息记录、系统日志与插件数据。清理后不可恢复，请谨慎操作。
      </p>
      <div style="display:flex; flex-wrap:wrap; gap:8px;">
        <button class="btn btn-outline btn-sm" onclick="clearOpLogs('7d')">清理 7 天前</button>
        <button class="btn btn-outline btn-sm" onclick="clearOpLogs('30d')">清理 30 天前</button>
        <button class="btn btn-outline btn-sm" onclick="clearOpLogs('90d')">清理 90 天前</button>
        <button class="btn btn-danger btn-sm" onclick="clearOpLogs('all')">清空全部记录</button>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3>最近登录记录（含失败尝试）</h3></div>
    <div class="card-body no-padding">
      <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
        <table class="table">
          <thead>
            <tr><th>IP</th><th>结果</th><th>时间</th></tr>
          </thead>
          <tbody>
            <?php
            try {
                $loginLogs = Auth::getLoginLogs();
                if (empty($loginLogs)) {
                    echo '<tr><td colspan="3" class="text-muted" style="text-align:center; padding:18px;">暂无登录记录</td></tr>';
                }
                foreach ($loginLogs as $log):
                    $ok = intval($log['success']) === 1;
            ?>
            <tr>
              <td style="font-family:monospace;"><?= htmlspecialchars($log['ip'] ?? '') ?></td>
              <td><span class="badge <?= $ok ? 'badge-success' : 'badge-warn' ?>"><?= $ok ? '成功' : '失败' ?></span></td>
              <td class="text-muted" style="white-space:nowrap; font-size:12px;"><?= htmlspecialchars($log['created_at'] ?? '') ?></td>
            </tr>
            <?php endforeach;
            } catch (Exception $e) {
                echo '<tr><td colspan="3" class="text-muted">加载失败</td></tr>';
            } ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
var oplogPage = 1;

function oplogApiCall(action, data, cb) {
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

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

// 操作类型徽章配色
function opBadgeColor(op) {
    if (/失败|解封|删除|清空|清理|停用|关闭/.test(op)) return 'var(--danger)';
    if (/添加|创建|上传|启用|开启|启动|登录/.test(op)) return 'var(--success, #20c997)';
    if (/重置|修改|更新|设置|保存|导入|发送|撤回|禁言/.test(op)) return '#4b7bec';
    return '#8a94a6';
}

function loadOpLogs() {
    var kw = document.getElementById('oplogKw').value.trim();
    var appid = document.getElementById('oplogBot').value;
    oplogApiCall('oplog_list', { page: oplogPage, size: 30, kw: kw, appid: appid }, function (res) {
        var body = document.getElementById('oplogBody');
        if (!res.success) {
            body.innerHTML = '<tr><td colspan="5" class="text-danger" style="text-align:center; padding:24px;">' + escapeHtml(res.message || '查询失败') + '</td></tr>';
            return;
        }
        if (!res.items || res.items.length === 0) {
            body.innerHTML = '<tr><td colspan="5" class="text-muted" style="text-align:center; padding:24px;">暂无操作记录</td></tr>';
            document.getElementById('oplogInfo').textContent = '';
            document.getElementById('oplogPageNum').textContent = '';
            return;
        }
        var html = '';
        res.items.forEach(function (it) {
            html += '<tr>'
                + '<td class="text-muted" style="white-space:nowrap; font-size:12px;">' + escapeHtml(it.time || '') + '</td>'
                + '<td><span class="badge" style="background:' + opBadgeColor(it.op) + '; color:#fff;">' + escapeHtml(it.op || '未知') + '</span></td>'
                + '<td>' + escapeHtml(it.user || '-') + '</td>'
                + '<td style="word-break:break-all;">' + escapeHtml(it.detail || '-') + '</td>'
                + '<td class="text-muted" style="font-family:monospace; font-size:12px;">' + escapeHtml(it.appid && it.appid !== 'admin' ? it.appid : '-') + '</td>'
                + '</tr>';
        });
        body.innerHTML = html;
        document.getElementById('oplogInfo').textContent = '共 ' + res.total + ' 条记录';
        document.getElementById('oplogPageNum').textContent = res.page + ' / ' + res.pages;
        document.getElementById('oplogPrev').disabled = (res.page <= 1);
        document.getElementById('oplogNext').disabled = (res.page >= res.pages);
        // 同步统计数字
        oplogApiCall('oplog_list', { page: 1, size: 10, kw: '', appid: '' }, function () {});
    });
}

function searchOpLogs() { oplogPage = 1; loadOpLogs(); }

function oplogGoPage(dir) {
    oplogPage += dir;
    if (oplogPage < 1) oplogPage = 1;
    loadOpLogs();
}

function clearOpLogs(scope) {
    var label = { '7d': '7 天前', '30d': '30 天前', '90d': '90 天前', 'all': '全部' }[scope] || scope;
    if (!confirm('确定清理' + label + '的操作记录吗？\n清理后不可恢复！')) return;
    if (scope === 'all' && !confirm('再次确认：将清空全部操作记录，此操作不可恢复！')) return;
    oplogApiCall('oplog_clear', { scope: scope }, function (res) {
        if (res.success) {
            alert(res.message || '清理完成');
            loadOpLogs();
            setTimeout(function () { window.location.reload(); }, 600);
        } else {
            alert(res.message || '清理失败');
        }
    });
}

loadOpLogs();
</script>

<?php require_once(__DIR__ . '/footer.php'); ?>
