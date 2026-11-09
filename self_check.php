<?php
/**
 * ============================================================
 * 部署自检脚本 self_check.php
 * ============================================================
 * 用途：部署或更新后一键检查运行环境、目录权限、数据库状态、
 *       接口域名设置、WebSocket守护进程状态等。
 *
 * 使用方式：
 *   1. 网页访问: https://你的域名/self_check.php （需先登录管理后台）
 *   2. 命令行  : php self_check.php
 *
 * 安全说明：网页访问需要管理员登录态；未登录仅显示提示，不泄露任何信息。
 * ============================================================
 */
date_default_timezone_set('Asia/Shanghai');

$isCli = (php_sapi_name() === 'cli');

require_once(__DIR__ . '/function.php');
require_once(__DIR__ . '/auth.php');

// 网页访问必须已登录（CLI模式跳过）
if (!$isCli && !Auth::check()) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>自检脚本</title></head>'
        . '<body style="font-family:sans-serif;max-width:640px;margin:60px auto;text-align:center;">'
        . '<h2>请先登录管理后台</h2>'
        . '<p>为防止信息泄露，自检脚本仅允许已登录的管理员查看。</p>'
        . '<p><a href="admin/login.php" style="color:#2563eb;">前往登录 →</a> 登录后刷新本页。</p>'
        . '</body></html>';
    exit;
}

$results = [];
function addCheck($name, $ok, $detail = '', $fix = '') {
    global $results;
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'fix' => $fix];
}

// ==================== 1. PHP 版本与扩展 ====================
$phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
addCheck('PHP 版本 >= 7.4', $phpOk, '当前 ' . PHP_VERSION, $phpOk ? '' : '请升级 PHP 至 7.4 及以上');

foreach ([
    'pdo_sqlite' => 'SQLite 数据库',
    'curl'       => 'cURL 网络请求',
    'json'       => 'JSON 支持',
    'openssl'    => 'OpenSSL 加密',
] as $ext => $desc) {
    $loaded = extension_loaded($ext);
    addCheck("扩展 {$desc}({$ext})", $loaded, $loaded ? '已加载' : '未安装', $loaded ? '' : "请在 PHP 设置中启用 {$ext} 扩展");
}

foreach (['sodium' => '签名验证(webhook回调必需)', 'gd' => '图片处理', 'mbstring' => '多字节字符串(可降级)', 'sockets' => 'Socket(WS网关)'] as $ext => $desc) {
    $loaded = extension_loaded($ext);
    addCheck("扩展 {$desc}({$ext})", $loaded, $loaded ? '已加载' : '未加载', $loaded ? '' : "{$desc}");
}

// 禁用函数检测
$disabled = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
$criticalDisabled = array_intersect($disabled, ['proc_open', 'popen', 'exec', 'shell_exec']);
addCheck(
    empty($criticalDisabled) ? '进程管理函数可用' : '部分进程函数被禁用',
    true, // 提示性检查，不算失败
    empty($criticalDisabled) ? 'popen/exec/shell_exec/proc_open 均可用，可在网页后台直接启停 WS 守护进程'
        : '被禁用: ' . implode(', ', $criticalDisabled) . '。网页端无法直接启动 WS 进程，需配置定时任务 ws_monitor.sh',
    ''
);

// ==================== 2. 目录与文件权限（核心：Permission denied 预防） ====================
$dataDir = __DIR__ . '/data';
$logsDir = $dataDir . '/logs';
$pluginDir = __DIR__ . '/plugin';

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0777, true);
}
$dataWritable = ensureWritableDir($dataDir);
addCheck('data 目录可写', $dataWritable, $dataDir . ($dataWritable ? '' : ' （不可写）'),
    $dataWritable ? '' : "SSH执行: chown -R www:www " . dirname($dataDir) . " && chmod -R 755 {$dataDir}");

ensureWritableDir($logsDir);
$tmpLogFile = $logsDir . '/selfcheck_' . bin2hex(random_bytes(4)) . '.tmp';
$logWrite = writeFileSafe($tmpLogFile, 'permission-test');
if ($logWrite['success']) {
    @unlink($tmpLogFile);
}
addCheck('data/logs 目录写入测试', $logWrite['success'],
    $logWrite['success'] ? '写入/删除成功（本次修复的 Permission denied 问题已解决）' : $logWrite['message'],
    $logWrite['success'] ? '' : '按提示修复目录属主后重试');

$pluginWritable = is_dir($pluginDir) ? is_writable($pluginDir) : @mkdir($pluginDir, 0777, true);
addCheck('plugin 目录可写', (bool)$pluginWritable, $pluginDir,
    $pluginWritable ? '' : '后台无法上传/新建插件，请修正目录属主或权限');

$dbFile = $dataDir . '/bot.db';
$dbExists = file_exists($dbFile);
addCheck('数据库文件存在', $dbExists, $dbFile, $dbExists ? '' : '尚未安装？请访问 install.php 完成安装');

// PID / 标志文件属主检查（www 与 root 不一致检测）
$pidFile = $dataDir . '/ws_master.pid';
$ownerMismatch = [];
foreach ([$dataDir, $logsDir, $pidFile, $dataDir . '/ws_daemon_status.json'] as $path) {
    if (file_exists($path)) {
        $perms = fileperms($path);
        if ($perms !== false && !($perms & 0x0002)) { // 无“其他用户写”位
            $owner = function_exists('posix_getpwuid') && function_exists('fileowner')
                ? (@posix_getpwuid(@fileowner($path))['name'] ?? @fileowner($path))
                : @fileowner($path);
            $whoami = $isCli ? (get_current_user() ?: 'cli') : 'www(php-fpm)';
            if ((string)$owner !== (string)$whoami) {
                $ownerMismatch[] = basename($path) . "(属主:{$owner})";
            }
        }
    }
}
addCheck('数据文件属主一致性', empty($ownerMismatch),
    empty($ownerMismatch) ? '正常' : '以下文件属主与当前用户不一致且权限较严: ' . implode(', ', $ownerMismatch),
    empty($ownerMismatch) ? '' : 'SSH执行: chown -R www:www ' . $dataDir . ' ; 或 chmod -R 777 ' . $dataDir);

// ==================== 3. 数据库与业务表 ====================
try {
    $tablesOk = true;
    foreach (['admin', 'bots', 'messages', 'groups', 'users', 'settings', 'plugin_status'] as $t) {
        db()->fetchColumn("SELECT COUNT(*) FROM {$t}");
    }
    // 检查 groups.info_updated_at 迁移字段（群标签TTL刷新依赖）
    $cols = [];
    foreach (db()->getPdo()->query('PRAGMA table_info(groups)') as $colRow) {
        $cols[] = $colRow['name'];
    }
    $hasInfoTs = in_array('info_updated_at', $cols, true);
    addCheck('数据库表结构完整', $tablesOk, '核心表均可访问', '');
    addCheck('群标签自动刷新字段(info_updated_at)', $hasInfoTs,
        $hasInfoTs ? '已就绪（群标签/成员数将每30分钟自动更新）' : '缺失',
        $hasInfoTs ? '' : '删除 data/bot.db 同级以外不要动它——重新访问任意页面会自动迁移补齐该字段');
} catch (Throwable $e) {
    addCheck('数据库连接', false, $e->getMessage(), '检查 data 目录权限后重试');
}

// ==================== 4. 接口域名设置 ====================
try {
    addCheck('接口域名设置', true, '当前使用: 官方统一域名 api.bot.qq.com（正式/沙箱相同，含access_token接口）', '');
} catch (Throwable $e) {
    addCheck('接口域名设置', false, $e->getMessage(), '检查网络连接后重试');
}

// ==================== 5. WebSocket 守护进程状态 ====================
try {
    $masterPid = 0;
    if (file_exists($dataDir . '/ws_master.pid')) {
        $masterPid = intval((string)@file_get_contents($dataDir . '/ws_master.pid'));
    }
    $running = false;
    if ($masterPid > 0) {
        if (function_exists('posix_kill')) {
            $running = @posix_kill($masterPid, 0);
        } elseif (is_dir('/proc/' . $masterPid)) {
            $running = true;
        }
    }
    $statusData = db()->getSetting('ws_daemon_status', []);
    $botSummary = '';
    if (is_array($statusData) && !empty($statusData)) {
        foreach ($statusData as $aid => $s) {
            $botSummary .= "{$aid}: " . ($s['status'] ?? '?') . " (" . ($s['updated_at'] ?? '-') . ")  ";
        }
    } else {
        $botSummary = '暂无机器人连接记录';
    }
    addCheck('WebSocket 守护进程', true,
        $running ? "运行中 (PID:{$masterPid})。" . $botSummary
                 : '未运行。在 后台→系统设置→WebSocket模式 启动，或配置定时任务 ws_monitor.sh',
        '');
} catch (Throwable $e) {
    addCheck('WebSocket 守护进程', false, $e->getMessage(), '');
}

// ==================== 6. 机器人配置概览 ====================
try {
    $bots = getBots();
    $wsOn = count(array_filter($bots, function ($b) { return intval($b['ws_enabled']) === 1; }));
    $enabled = count(array_filter($bots, function ($b) { return intval($b['enabled']) === 1; }));
    addCheck('机器人配置', count($bots) > 0,
        sprintf('共 %d 个机器人，其中 %d 个已启用，%d 个开启 WebSocket', count($bots), $enabled, $wsOn),
        count($bots) > 0 ? '' : '请在 后台→机器人管理 中添加机器人');
} catch (Throwable $e) {
    // 数据库异常已在上面报告
}

// ==================== 输出 ====================
$passCount = count(array_filter($results, function ($r) { return $r['ok']; }));
$totalCount = count($results);

if ($isCli) {
    echo "========== QQBot 部署自检报告 ==========\n";
    foreach ($results as $r) {
        echo sprintf("[%s] %s\n", $r['ok'] ? '✓' : '✗', $r['name']);
        if ($r['detail'] !== '') echo "      详情: {$r['detail']}\n";
        if (!$r['ok'] && $r['fix'] !== '') echo "      修复建议: {$r['fix']}\n";
    }
    echo "========================================\n";
    echo "通过 {$passCount}/{$totalCount} 项\n";
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="referrer" content="no-referrer">
<title>部署自检报告</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f5f6fa;color:#1f2937;margin:0;padding:24px;}
.wrap{max-width:820px;margin:0 auto;}
h1{font-size:22px;margin:0 0 6px;} .sub{color:#6b7280;font-size:13px;margin-bottom:20px;}
.item{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin-bottom:10px;display:flex;gap:12px;align-items:flex-start;}
.icon{font-size:16px;line-height:22px;}
.ok .icon{color:#16a34a;} .fail .icon{color:#dc2626;}
.name{font-weight:600;font-size:14px;margin-bottom:2px;}
.detail{font-size:13px;color:#6b7280;word-break:break-all;}
.fix{font-size:13px;color:#b45309;margin-top:4px;word-break:break-all;}
.summary{margin-top:16px;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600;
 background:<?= $passCount === $totalCount ? '#dcfce7' : '#fef9c3' ?>;color:<?= $passCount === $totalCount ? '#166534' : '#854d0e' ?>;}
code{background:#f3f4f6;border-radius:4px;padding:1px 5px;font-size:12px;}
.back{display:inline-block;margin-bottom:16px;color:#2563eb;text-decoration:none;font-size:13px;}
</style>
</head>
<body>
<div class="wrap">
  <a class="back" href="admin/settings.php">← 返回系统设置</a>
  <h1>部署自检报告</h1>
  <div class="sub">检查时间 <?= date('Y-m-d H:i:s') ?> · 运行身份: <?= htmlspecialchars(PHP_SAPI) ?></div>

  <?php foreach ($results as $r): ?>
  <div class="item <?= $r['ok'] ? 'ok' : 'fail' ?>">
    <div class="icon"><?= $r['ok'] ? '✔' : '✘' ?></div>
    <div style="flex:1;">
      <div class="name"><?= htmlspecialchars($r['name']) ?></div>
      <?php if ($r['detail'] !== ''): ?><div class="detail"><?= htmlspecialchars($r['detail']) ?></div><?php endif; ?>
      <?php if (!$r['ok'] && $r['fix'] !== ''): ?><div class="fix">修复建议: <?= htmlspecialchars($r['fix']) ?></div><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="summary">
    自检完成：通过 <?= $passCount ?>/<?= $totalCount ?> 项
    <?= $passCount === $totalCount ? '— 一切正常！' : '— 请按上方建议修复失败项后重新自检' ?>
  </div>
</div>
</body>
</html>
