<?php
/**
 * admin/api/ws.php - WebSocket 守护进程状态与控制接口
 *
 * type=status     - 读取 database/ws_status.json，并校验 PID 是否存活
 * type=start      - 后台启动 ws.php 守护进程（可为所有机器人或指定 appid）
 * type=autostart  - 自动保活：仅当守护进程未运行时才拉起（免 CLI 关键入口）
 * type=stop       - 创建停止标志，通知守护进程优雅退出
 * type=log        - 返回最近 N 行 Log/ws.log
 * type=clear_log  - 清空 Log/ws.log
 */
$root = dirname(__DIR__, 2);
$statusFile = $root . '/database/ws_status.json';
$stopFlag   = $root . '/database/ws_stop.flag';
$wsLogFile  = $root . '/Log/ws.log';
$wsScript   = $root . '/ws.php';
$keepaliveFlag = $root . '/database/ws_keepalive.flag';  // 持久化保活开关

// 复用后台登录态校验
$type = $_REQUEST['type'] ?? '';
header('Content-Type: application/json; charset=utf-8');

if (!in_array($type, ['status', 'start', 'autostart', 'stop', 'log', 'clear_log', 'keepalive_on', 'keepalive_off', 'keepalive_status'], true)) {
    echo json_encode(['code' => 400, 'msg' => '未传入有效 type'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 自动创建必要目录
if (!is_dir(dirname($statusFile))) @mkdir(dirname($statusFile), 0777, true);
if (!is_dir(dirname($wsLogFile)))  @mkdir(dirname($wsLogFile), 0777, true);

switch ($type) {
    case 'status':
        $status = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : null;
        if (!is_array($status)) {
            echo json_encode(['code' => 200, 'running' => false, 'msg' => '尚未启动', 'keepalive' => file_exists($keepaliveFlag)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pid = $status['pid'] ?? 0;
        $alive = is_pid_alive((int)$pid);
        $status['running'] = !empty($status['running']) && $alive;
        $status['pid_alive'] = $alive;
        $status['keepalive'] = file_exists($keepaliveFlag);
        echo json_encode(['code' => 200] + $status, JSON_UNESCAPED_UNICODE);
        break;

    case 'keepalive_on':
        @file_put_contents($keepaliveFlag, '1');
        echo json_encode(['code' => 200, 'msg' => '已开启自动保活'], JSON_UNESCAPED_UNICODE);
        break;

    case 'keepalive_off':
        if (file_exists($keepaliveFlag)) @unlink($keepaliveFlag);
        echo json_encode(['code' => 200, 'msg' => '已关闭自动保活'], JSON_UNESCAPED_UNICODE);
        break;

    case 'keepalive_status':
        echo json_encode(['code' => 200, 'keepalive' => file_exists($keepaliveFlag)], JSON_UNESCAPED_UNICODE);
        break;

    case 'autostart':
        // 标记保活已开启
        @file_put_contents($keepaliveFlag, '1');
        $started = try_autostart_daemon($wsScript, $root, $statusFile);
        if ($started === null) {
            // 不需要启动，已在运行
            echo json_encode(['code' => 200, 'msg' => '守护进程已在运行', 'started' => false], JSON_UNESCAPED_UNICODE);
        } elseif ($started === true) {
            echo json_encode(['code' => 200, 'msg' => '已自动拉起守护进程', 'started' => true], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['code' => 500, 'msg' => '自动拉起失败：' . $started, 'started' => false], JSON_UNESCAPED_UNICODE);
        }
        break;

    case 'start':
        if (!file_exists($wsScript)) {
            echo json_encode(['code' => 404, 'msg' => 'ws.php 不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // 避免重复启动
        $cur = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : [];
        $pid = $cur['pid'] ?? 0;
        if (!empty($cur['running']) && is_pid_alive((int)$pid)) {
            echo json_encode(['code' => 200, 'msg' => '守护进程已在运行', 'pid' => $pid], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $result = launch_daemon($wsScript, $root);
        if ($result !== true) {
            echo json_encode(['code' => 500, 'msg' => '启动失败：' . $result], JSON_UNESCAPED_UNICODE);
            exit;
        }
        usleep(800000);
        $st = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : [];
        echo json_encode([
            'code' => 200,
            'msg'  => '已发送启动指令',
            'pid'  => $st['pid'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'stop':
        // 关闭保活标记
        if (file_exists($keepaliveFlag)) @unlink($keepaliveFlag);
        @file_put_contents($stopFlag, 'stop-from-admin');
        echo json_encode(['code' => 200, 'msg' => '已发送停止指令'], JSON_UNESCAPED_UNICODE);
        break;

    case 'log':
        $lines = isset($_REQUEST['lines']) ? max(20, min(500, (int)$_REQUEST['lines'])) : 200;
        $data = file_exists($wsLogFile) ? file($wsLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $tail = array_slice($data, -$lines);
        echo json_encode(['code' => 200, 'lines' => array_values($tail), 'count' => count($tail)], JSON_UNESCAPED_UNICODE);
        break;

    case 'clear_log':
        @file_put_contents($wsLogFile, '');
        echo json_encode(['code' => 200, 'msg' => '日志已清空'], JSON_UNESCAPED_UNICODE);
        break;
}


// ==================== 辅助函数 ====================

/** 判断 PID 是否存活 */
function is_pid_alive(int $pid): bool
{
    if ($pid <= 0) return false;
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return file_exists("/proc/" . $pid);
}

/**
 * 检查并尝试自动拉起守护进程
 * @return null=已在运行 / true=已拉起 / string=失败原因
 */
function try_autostart_daemon(string $wsScript, string $root, string $statusFile)
{
    if (!file_exists($wsScript)) return 'ws.php 不存在';

    // 检查 stop 标志，存在则不自动启动（用户主动停止过）
    $stopFlag = $root . '/database/ws_stop.flag';
    // stop 标志超过 10 秒视为陈旧，可自动拉起
    if (file_exists($stopFlag) && (time() - filemtime($stopFlag) < 10)) {
        return '用户已主动停止，10 秒内不自动拉起';
    }
    if (file_exists($stopFlag)) @unlink($stopFlag);

    // 检查当前状态
    $cur = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : [];
    $pid = $cur['pid'] ?? 0;
    if (!empty($cur['running']) && is_pid_alive((int)$pid)) {
        return null;
    }

    return launch_daemon($wsScript, $root);
}

/**
 * 后台拉起 ws.php 守护进程
 * - 使用 nohup + & 模式，与父进程解耦
 * - 标准输出/错误重定向到 Log/ws.out
 * @return true|string 成功返回 true，失败返回错误信息
 */
function launch_daemon(string $wsScript, string $root)
{
    if (!file_exists($wsScript)) return 'ws.php 不存在';
    if (!is_executable($wsScript) && !is_readable($wsScript)) return 'ws.php 不可读';

    // 选定 PHP 二进制
    $phpBin = PHP_BINDIR . '/php';
    if (!file_exists($phpBin)) {
        $which = trim((string)shell_exec('which php 2>/dev/null'));
        if ($which && file_exists($which)) $phpBin = $which;
    }
    if (!$phpBin || !file_exists($phpBin)) $phpBin = 'php';

    $logDir = $root . '/Log';
    if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
    $out = $logDir . '/ws.out';

    // 构造命令（nohup 完全脱离 shell）
    $cmd = sprintf(
        'nohup %s %s > %s 2>&1 &',
        escapeshellarg($phpBin),
        escapeshellarg($wsScript),
        escapeshellarg($out)
    );

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', $out, 'a'],
        2 => ['file', $out, 'a'],
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes, $root);
    if (is_resource($proc)) {
        proc_close($proc);
        // 额外通过 exec 兜底（部分环境 proc_open 不能 disown）
        @exec('cd ' . escapeshellarg($root) . ' && ' . $cmd, $o, $ret);
        return true;
    }
    // 兜底：纯 exec
    @exec('cd ' . escapeshellarg($root) . ' && ' . $cmd, $o, $ret);
    return $ret === 0 ? true : ('exec 返回码 ' . $ret);
}
