<?php
/**
 * 管理后台 - 用户与群组
 */
$pageTitle = '用户与群组';
require_once('header.php');
?>

<style>
/* 手机端：AppID / 首次出现 两列让位给用户ID与昵称，避免表格横向挤压 */
@media (max-width: 768px) {
  .users-table th:nth-child(4), .users-table td:nth-child(4),
  .users-table th:nth-child(5), .users-table td:nth-child(5) { display: none; }
  .users-table th, .users-table td { padding: 8px 9px; }
  .users-table th { font-size: 11px; }
  .users-table td { font-size: 12px; }
  .users-table th:nth-child(1), .users-table td:nth-child(1) { width: 48px; }
}
</style>

<?php
// ==================== 获取参数 ====================
$tab   = isset($_GET['tab']) ? trim($_GET['tab']) : 'users';
if (!in_array($tab, ['users', 'groups'], true)) {
    $tab = 'users';
}
$appid = isset($_GET['appid']) ? trim($_GET['appid']) : '';
$page  = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// 获取机器人列表
$bots = getBots();

// ==================== 当前选项卡对应的数据表 ====================
if ($tab === 'groups') {
    $table      = 'groups';
    $tabTitle   = '群组列表';
    $countLabel = '个';
} else {
    $table      = 'users';
    $tabTitle   = '用户列表';
    $countLabel = '位';
}

// ==================== 构建查询条件 ====================
$where  = [];
$params = [];
if ($appid !== '') {
    $where[]  = 'appid = ?';
    $params[] = $appid;
}
$whereClause = '';
if (!empty($where)) {
    $whereClause = ' WHERE ' . implode(' AND ', $where);
}

// ==================== 分页（每页 30 条）====================
$perPage = 30;
try {
    $total = (int) db()->fetchColumn("SELECT COUNT(*) FROM " . $table . $whereClause, $params);
} catch (Exception $e) {
    $total = 0;
}

$totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
$page       = max(1, min($totalPages, $page));
$offset     = ($page - 1) * $perPage;

// ==================== 查询数据 ====================
try {
    $rows = db()->fetchAll(
        "SELECT * FROM " . $table . $whereClause . " ORDER BY last_active DESC LIMIT " . intval($perPage) . " OFFSET " . intval($offset),
        $params
    );
} catch (Exception $e) {
    $rows = [];
}

// ==================== 构建分页基础查询字符串（保留 tab 与 appid，不含 page）====================
$pageParams = ['tab' => $tab];
if ($appid !== '') {
    $pageParams['appid'] = $appid;
}
$baseQuery   = http_build_query($pageParams);
$pageBaseUrl = 'users.php?' . $baseQuery . '&';

// 选项卡链接（保留 appid 筛选，重置 page）
$tabQuery = $appid !== '' ? '&appid=' . urlencode($appid) : '';
$tabUsersUrl  = 'users.php?tab=users' . $tabQuery;
$tabGroupsUrl = 'users.php?tab=groups' . $tabQuery;
?>

<div class="page-header">
  <h2>用户与群组</h2>
</div>

<!-- ==================== 选项卡 ==================== -->
<div style="display:flex; gap:0; border-bottom:1px solid var(--border); margin-bottom:16px;">
  <a href="<?= htmlspecialchars($tabUsersUrl) ?>" style="padding:10px 20px; text-decoration:none; font-size:14px; font-weight:500; border-bottom:2px solid <?= $tab === 'users' ? 'var(--primary)' : 'transparent' ?>; color: <?= $tab === 'users' ? 'var(--text)' : 'var(--text-secondary)' ?>; margin-bottom:-1px;">
    用户列表
  </a>
  <a href="<?= htmlspecialchars($tabGroupsUrl) ?>" style="padding:10px 20px; text-decoration:none; font-size:14px; font-weight:500; border-bottom:2px solid <?= $tab === 'groups' ? 'var(--primary)' : 'transparent' ?>; color: <?= $tab === 'groups' ? 'var(--text)' : 'var(--text-secondary)' ?>; margin-bottom:-1px;">
    群组列表
  </a>
</div>

<!-- ==================== 筛选区 ==================== -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" action="users.php" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
      <div class="form-group" style="flex:1; min-width:220px; margin-bottom:0;">
        <label>机器人AppID</label>
        <select name="appid" class="form-control">
          <option value="">全部机器人</option>
          <?php foreach ($bots as $bot): ?>
          <option value="<?= htmlspecialchars($bot['appid']) ?>" <?= ($appid === $bot['appid']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($bot['appid']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="flex:0 0 auto; margin-bottom:0;">
        <button type="submit" class="btn btn-primary">筛选</button>
        <a href="users.php?tab=<?= htmlspecialchars($tab) ?>" class="btn btn-outline">重置</a>
      </div>
    </form>
  </div>
</div>

<!-- ==================== 数据列表 ==================== -->
<div class="card">
  <div class="card-header">
    <h3><?= $tabTitle ?>（共 <?= $total ?> <?= $countLabel ?>）</h3>
  </div>
  <div class="card-body no-padding">
    <?php if (empty($rows)): ?>
      <div class="empty-state">
        <div class="empty-icon">--</div>
        <p>暂无<?= $tabTitle ?>记录</p>
      </div>
    <?php elseif ($tab === 'users'): ?>
      <div class="table-responsive">
      <table class="table users-table">
        <thead>
          <tr>
            <th style="width:56px;">头像</th>
            <th>用户ID</th>
            <th>昵称/备注</th>
            <th>机器人AppID</th>
            <th>首次出现</th>
            <th>最后活跃</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $userId   = $row['user_id'] ?? '';
            $nickname = trim($row['nickname'] ?? '');
            $remark   = trim($row['remark'] ?? '');
            $firstSeen  = $row['first_seen'] ?? '';
            $lastActive = $row['last_active'] ?? '';
            $rowAppid   = $row['appid'] ?? '';
            // 头像：官方下发的 avatar 优先，其次 qlogo 640 兜底，最后首字色块
            $avatarUrl = trim($row['avatar'] ?? '');
            // 显示名称：优先备注，其次昵称
            $displayName = $remark !== '' ? $remark : $nickname;
          ?>
          <tr>
            <td><?= avtHtml($avatarUrl, $displayName !== '' ? $displayName : $userId, $userId, 36, true, $rowAppid) ?></td>
            <td><?= htmlspecialchars($userId) ?></td>
            <td>
              <?php if ($displayName !== ''): ?>
                <?= htmlspecialchars($displayName) ?>
                <?php if ($remark !== '' && $nickname !== '' && $remark !== $nickname): ?>
                  <span class="text-muted" style="font-size:12px;">（<?= htmlspecialchars($nickname) ?>）</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= htmlspecialchars($rowAppid) ?></td>
            <td class="text-muted" style="white-space:nowrap;"><?= htmlspecialchars($firstSeen) ?></td>
            <td class="text-muted" style="white-space:nowrap;"><?= htmlspecialchars($lastActive) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php else: ?>
      <div class="table-responsive">
      <table class="table users-table">
        <thead>
          <tr>
            <th style="width:56px;">头像</th>
            <th>群组ID</th>
            <th>群名称/备注</th>
            <th>机器人AppID</th>
            <th>首次出现</th>
            <th>最后活跃</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $groupId   = $row['group_id'] ?? '';
            $groupName = trim($row['group_name'] ?? '');
            $remark    = trim($row['remark'] ?? '');
            $customAvatar = trim($row['custom_avatar'] ?? '');
            $firstSeen  = $row['first_seen'] ?? '';
            $lastActive = $row['last_active'] ?? '';
            $rowAppid   = $row['appid'] ?? '';
            // 群头像：优先自定义头像，没有则用群名首字色块
            $groupAvatar = $customAvatar;
            // 显示名称：优先备注，其次群名称
            $displayName = $remark !== '' ? $remark : $groupName;
          ?>
          <tr>
            <td><?= avtHtml($groupAvatar, $displayName !== '' ? $displayName : $groupId, $groupId, 36, true) ?></td>
            <td><?= htmlspecialchars($groupId) ?></td>
            <td>
              <?php if ($displayName !== ''): ?>
                <?= htmlspecialchars($displayName) ?>
                <?php if ($remark !== '' && $groupName !== '' && $remark !== $groupName): ?>
                  <span class="text-muted" style="font-size:12px;">（<?= htmlspecialchars($groupName) ?>）</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= htmlspecialchars($rowAppid) ?></td>
            <td class="text-muted" style="white-space:nowrap;"><?= htmlspecialchars($firstSeen) ?></td>
            <td class="text-muted" style="white-space:nowrap;"><?= htmlspecialchars($lastActive) ?></td>
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

<?php require_once('footer.php'); ?>
