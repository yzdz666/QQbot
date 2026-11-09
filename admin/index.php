<?php
/**
 * 管理后台 - 仪表盘
 */
$pageTitle = '仪表盘';
require_once('header.php');

// 统计数据
$stats = [
    'bots' => 0, 'online' => 0, 'today' => 0, 'offline' => 0,
    'messages' => 0, 'users' => 0, 'groups' => 0,
];
try { $stats['bots'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM bots"); } catch (Exception $e) {}
try { $stats['online'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM bots WHERE enabled = 1"); } catch (Exception $e) {}
try { $stats['today'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM messages WHERE created_at >= datetime('now','localtime','start of day')"); } catch (Exception $e) {}
try { $stats['messages'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM messages"); } catch (Exception $e) {}
try { $stats['users'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM users"); } catch (Exception $e) {}
try { $stats['groups'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM groups"); } catch (Exception $e) {}
try { $stats['offline'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM bots WHERE enabled = 0"); } catch (Exception $e) {}

// 最近消息
try { $recentMessages = db()->fetchAll("SELECT * FROM messages ORDER BY id DESC LIMIT 5"); } catch (Exception $e) { $recentMessages = []; }
// 最近系统事件/日志
try { $recentLogs = db()->fetchAll("SELECT * FROM system_logs ORDER BY id DESC LIMIT 5"); } catch (Exception $e) { $recentLogs = []; }
// 机器人
try { $bots = getBots(); } catch (Exception $e) { $bots = []; }

function dashTime($time) {
    if (!$time) return '';
    $ts = strtotime($time);
    if (!$ts) return $time;
    return date('m-d H:i', $ts);
}
function dashText($value, $len = 48) {
    $value = trim((string)$value);
    if ($value === '') return '暂无内容';
    return mb_strlen($value, 'UTF-8') > $len ? mb_substr($value, 0, $len, 'UTF-8') . '…' : $value;
}
?>

<div class="page-header">
  <div>
    <h2>仪表盘</h2>
    <p class="page-desc"><?= $stats['bots'] ?> 个机器人 · <?= $stats['online'] ?> 个运行中 · 今日 <?= $stats['today'] ?> 条消息</p>
  </div>
  <div class="actions">
    <a href="messages.php" class="btn btn-outline btn-sm">消息日志</a>
    <a href="bots.php" class="btn btn-primary btn-sm">管理机器人</a>
  </div>
</div>

<div class="dashboard-top">
  <div class="dashboard-stat">
    <div class="ds-text"><small>机器人总数</small><strong><?= $stats['bots'] ?></strong></div>
  </div>
  <div class="dashboard-stat">
    <div class="ds-text"><small>运行中 / 已停用</small><strong><?= $stats['online'] ?> / <?= $stats['offline'] ?></strong></div>
  </div>
  <div class="dashboard-stat">
    <div class="ds-text"><small>今日消息</small><strong><?= $stats['today'] ?></strong></div>
  </div>
  <div class="dashboard-stat">
    <div class="ds-text"><small>累计消息</small><strong><?= $stats['messages'] ?></strong></div>
  </div>
</div>

<section class="dashboard-panel">
  <div class="dashboard-panel-title"><span>快捷入口</span><span class="text-muted" style="font-size:11px;font-weight:400;">常用页面</span></div>
  <div class="quick-actions">
    <a class="quick-action" href="bots.php">
      <span class="qa-icon"><svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="5.5" r="2.8" stroke="currentColor" stroke-width="1.3"/><path d="M2.5 13.5c0-2.6 2.5-4.5 5.5-4.5s5.5 1.9 5.5 4.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg></span>
      <span>机器人管理</span>
    </a>
    <a class="quick-action" href="messages.php">
      <span class="qa-icon"><svg viewBox="0 0 16 16" fill="none"><path d="M2.5 3.5h11v7h-6l-3 2.5v-2.5h-2z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg></span>
      <span>消息日志</span>
    </a>
    <a class="quick-action" href="plugins.php">
      <span class="qa-icon"><svg viewBox="0 0 16 16" fill="none"><path d="M6 2.5h4v2a1.5 1.5 0 0 0 3 0v-2h1v5h-2a1.5 1.5 0 0 0 0 3h2v3h-5v-2a1.5 1.5 0 0 0-3 0v2H3v-5h2a1.5 1.5 0 0 0 0-3H3v-3h3z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg></span>
      <span>插件管理</span>
    </a>
    <a class="quick-action" href="market.php">
      <span class="qa-icon"><svg viewBox="0 0 16 16" fill="none"><path d="M2.5 5.5 8 2l5.5 3.5v5L8 14l-5.5-3.5z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M2.5 5.5 8 9l5.5-3.5M8 9v5" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg></span>
      <span>插件市场</span>
    </a>
    <a class="quick-action" href="chat.php">
      <span class="qa-icon"><svg viewBox="0 0 16 16" fill="none"><path d="M2 3h12v7H7.5L5 12.5V10H2z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M5 6h6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg></span>
      <span>聊天记录</span>
    </a>
    <a class="quick-action" href="panels.php">
      <span class="qa-icon"><svg viewBox="0 0 16 16" fill="none"><rect x="2" y="2.5" width="12" height="11" rx="1" stroke="currentColor" stroke-width="1.3"/><path d="M2 6.5h12M6 6.5v7" stroke="currentColor" stroke-width="1.3"/></svg></span>
      <span>指令面板</span>
    </a>
    <a class="quick-action" href="menu.php">
      <span class="qa-icon"><svg viewBox="0 0 16 16" fill="none"><path d="M2.5 4h11M2.5 8h11M2.5 12h7" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg></span>
      <span>自定义菜单</span>
    </a>
    <a class="quick-action" href="settings.php">
      <span class="qa-icon"><svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="2.2" stroke="currentColor" stroke-width="1.3"/><path d="M8 2v2M8 12v2M2 8h2M12 8h2M3.8 3.8l1.4 1.4M10.8 10.8l1.4 1.4M12.2 3.8l-1.4 1.4M5.2 10.8l-1.4 1.4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg></span>
      <span>系统设置</span>
    </a>
    <a class="quick-action" href="aidev.php">
      <span class="qa-icon"><svg viewBox="0 0 16 16" fill="none"><path d="M5 4 1.8 8 5 12M11 4l3.2 4L11 12" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
      <span>AI 写插件</span>
    </a>
  </div>
</section>

<section class="dashboard-panel">
  <div class="dashboard-panel-title">
    <span>机器人状态 · <?= $stats['online'] ?>/<?= $stats['bots'] ?> 运行中</span>
    <a href="bots.php" class="btn btn-outline btn-sm">管理全部</a>
  </div>
  <?php if (empty($bots)): ?>
    <div class="dashboard-table-empty">还没有机器人。到「机器人管理」添加第一个 AppID。</div>
  <?php else: ?>
    <div class="bot-strip">
      <?php foreach (array_slice($bots, 0, 5) as $bot):
        $enabled = (int)($bot['enabled'] ?? 0) === 1;
        $name = trim($bot['nickname'] ?? '') ?: trim($bot['appid'] ?? '未命名机器人');
        $avatar = trim($bot['avatar'] ?? '');
        $initial = htmlspecialchars(mb_substr($name, 0, 1, 'UTF-8'));
      ?>
      <div class="bot-mini">
        <div class="bot-mini-avatar">
          <?php if ($avatar): ?><img src="<?= htmlspecialchars($avatar) ?>" alt="头像" onerror="this.style.display='none';"><?php else: ?><?= $initial ?><?php endif; ?>
        </div>
        <div class="bot-mini-main">
          <div class="bot-mini-name" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></div>
          <div class="bot-mini-meta"><i class="status-dot <?= $enabled ? '' : 'off' ?>"></i><?= $enabled ? '运行中' : '已停用' ?><span>·</span><?= htmlspecialchars($bot['env'] ?? '正式') ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<div class="dashboard-columns">
  <section class="dashboard-panel">
    <div class="dashboard-panel-title"><span>最近消息</span><a href="messages.php" class="btn btn-outline btn-sm">查看全部</a></div>
    <div class="dashboard-list">
      <?php if (empty($recentMessages)): ?>
        <div class="dashboard-table-empty">暂无消息记录</div>
      <?php else: foreach ($recentMessages as $msg):
        $recv = ($msg['direction'] ?? '') === '接收';
        $content = dashText($msg['content'] ?? '', 55);
        $type = $msg['source_type'] ?? '消息';
      ?>
      <div class="dashboard-list-item">
        <div class="dli-icon <?= $recv ? 'recv' : 'send' ?>"><?= $recv ? '收' : '发' ?></div>
        <div class="dli-body">
          <div class="dli-title"><?= htmlspecialchars($content) ?></div>
          <div class="dli-sub"><?= htmlspecialchars($type) ?> · <?= htmlspecialchars($msg['appid'] ?? '') ?></div>
        </div>
        <div class="dli-time"><?= htmlspecialchars(dashTime($msg['created_at'] ?? '')) ?></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </section>

  <section class="dashboard-panel">
    <div class="dashboard-panel-title"><span>系统日志</span><a href="logs.php" class="btn btn-outline btn-sm">查看全部</a></div>
    <div class="dashboard-list">
      <?php if (empty($recentLogs)): ?>
        <div class="dashboard-table-empty">暂无系统日志</div>
      <?php else: foreach ($recentLogs as $log): ?>
      <div class="dashboard-list-item">
        <div class="dli-icon">·</div>
        <div class="dli-body">
          <div class="dli-title"><?= htmlspecialchars(dashText($log['content'] ?? $log['log_type'] ?? '系统事件', 60)) ?></div>
          <div class="dli-sub"><?= htmlspecialchars($log['log_type'] ?? '系统') ?> · <?= htmlspecialchars($log['appid'] ?? '') ?></div>
        </div>
        <div class="dli-time"><?= htmlspecialchars(dashTime($log['created_at'] ?? '')) ?></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </section>
</div>

<div class="stats-grid">
  <div class="stat-card"><div class="stat-label">用户总数</div><div class="stat-value"><?= $stats['users'] ?></div></div>
  <div class="stat-card"><div class="stat-label">群组总数</div><div class="stat-value"><?= $stats['groups'] ?></div></div>
  <div class="stat-card"><div class="stat-label">接收 / 发送</div><div class="stat-value"><?php try { echo (int)db()->fetchColumn("SELECT COUNT(*) FROM messages WHERE direction='接收'"); } catch(Exception $e){echo 0;} ?> / <?php try { echo (int)db()->fetchColumn("SELECT COUNT(*) FROM messages WHERE direction='发送'"); } catch(Exception $e){echo 0;} ?></div></div>
  <div class="stat-card"><div class="stat-label">WebSocket</div><div class="stat-value"><?php try { echo (int)db()->fetchColumn("SELECT COUNT(*) FROM bots WHERE ws_enabled=1"); } catch(Exception $e){echo 0;} ?></div></div>
</div>

<?php require_once('footer.php'); ?>
