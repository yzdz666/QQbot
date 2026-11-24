<?php
/**
 * WebSocket 多机器人进程池 - 公共函数
 *
 * 设计目标：
 *  - 一个守护进程（supervisor）管理 N 个机器人子进程，每个机器人独立连接 QQ 网关；
 *  - 子进程崩溃自动重启，后台启用/停用机器人约 10 秒内自动生效；
 *  - 停止时先停子进程再停守护进程，避免残留进程继续收消息导致重复回复；
 *  - 子进程发现守护进程已消失（被 kill -9 等）会自行退出，不再当孤儿。
 *
 * 本文件只放无副作用的函数，方便单元测试直接引用。
 */

/** 子进程 PID 文件 / 列表文件路径 */
function ws子进程PID文件($appid) {
    return APP_ROOT . 'data/ws_worker_' . ws子进程键($appid) . '.pid';
}

function ws子进程列表文件() {
    return APP_ROOT . 'data/ws_workers.pids';
}

function ws守护PID文件() {
    return APP_ROOT . 'data/ws_master.pid';
}

/**
 * appid 转成安全文件名：保留字母数字与 _ . -，过长时截断并加哈希防撞
 */
function ws子进程键($appid) {
    $appid = (string)$appid;
    $key = preg_replace('/[^A-Za-z0-9_.-]/', '_', $appid);
    if ($key === '' || $key === null) $key = 'bot';
    if (strlen($key) > 48) {
        $key = substr($key, 0, 30) . '_' . substr(md5($appid), 0, 12);
    }
    return $key;
}

/**
 * 读取子进程列表文件（每行：appid<TAB>pid）
 * @return array [appid => pid]
 */
function ws读取子进程列表($file = null) {
    $file = $file ?: ws子进程列表文件();
    if (!is_file($file)) return [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];
    $out = [];
    foreach ($lines as $line) {
        $parts = explode("\t", $line, 2);
        if (count($parts) !== 2) continue;
        $appid = trim($parts[0]);
        $pid = intval(trim($parts[1]));
        if ($appid === '' || $pid <= 0) continue;
        $out[$appid] = $pid;
    }
    return $out;
}

/**
 * 原子写入子进程列表（先写临时文件再 rename，后台读到的一半内容不会解析出错）
 * @param array $workers [appid => pid] 或 [appid => ['pid'=>..]]
 */
function ws写入子进程列表($workers, $file = null) {
    $file = $file ?: ws子进程列表文件();
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);

    $content = '';
    foreach ((array)$workers as $appid => $info) {
        $pid = is_array($info) ? intval($info['pid'] ?? 0) : intval($info);
        if ($pid <= 0) continue;
        $appid = str_replace(["\t", "\r", "\n"], ' ', (string)$appid);
        $content .= $appid . "\t" . $pid . "\n";
    }

    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $content) === false) return false;
    @chmod($tmp, 0666);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** 删除子进程列表文件 */
function ws清理子进程列表($file = null) {
    $file = $file ?: ws子进程列表文件();
    if (is_file($file)) @unlink($file);
}

/** 写入某个机器人的 PID 文件 */
function ws写入子进程PID($appid, $pid = null) {
    $pid = $pid ?: (function_exists('ws_get_pid') ? ws_get_pid() : getmypid());
    $file = ws子进程PID文件($appid);
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, (string)$pid) === false) return false;
    @chmod($tmp, 0666);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** 删除某个机器人的 PID 文件（只删属于自己的，避免误删新进程的 PID） */
function ws删除子进程PID($appid, $pid = null) {
    $file = ws子进程PID文件($appid);
    if (!is_file($file)) return;
    if ($pid !== null) {
        $current = intval(@file_get_contents($file));
        if ($current > 0 && $current !== intval($pid)) return;
    }
    @unlink($file);
}

/**
 * 当前应该由守护进程管理的机器人：
 *  - 必须同时 ws_enabled=1 且 enabled=1
 *  - 同一 appid 只保留一条（历史数据可能有重复）
 */
function ws启用的机器人() {
    try {
        $rows = db()->fetchAll("SELECT * FROM bots WHERE ws_enabled = 1 AND enabled = 1 ORDER BY created_at, appid");
    } catch (Throwable $e) {
        return [];
    }
    $seen = [];
    $out = [];
    foreach ($rows as $row) {
        $appid = (string)($row['appid'] ?? '');
        if ($appid === '' || isset($seen[$appid])) continue;
        $seen[$appid] = true;
        $out[] = $row;
    }
    return $out;
}

/**
 * 机器人连接参数指纹：secret/env/ws_url 变化时守护进程会自动重启对应子进程
 */
function ws机器人签名($bot) {
    return md5(implode('|', [
        (string)($bot['appid'] ?? ''),
        (string)($bot['secret'] ?? ''),
        (string)($bot['env'] ?? ''),
        trim((string)($bot['ws_url'] ?? '')),
        (string)($bot['enabled'] ?? ''),
        (string)($bot['ws_enabled'] ?? ''),
    ]));
}

/** 写入单个机器人的 WS 连接状态（UPSERT，多个子进程并发写不会互相覆盖） */
function ws写入连接状态($appid, $status, $message = '', $pid = 0) {
    try {
        db()->execute(
            "INSERT INTO ws_bot_status (appid, status, message, pid, updated_at)
             VALUES (?, ?, ?, ?, datetime('now','localtime'))
             ON CONFLICT(appid) DO UPDATE SET
               status = excluded.status,
               message = excluded.message,
               pid = excluded.pid,
               updated_at = excluded.updated_at",
            [(string)$appid, (string)$status, (string)$message, intval($pid)]
        );
    } catch (Throwable $e) {
        // 状态只是展示用途，写失败不影响 WS 连接
    }
}

/**
 * 读取全部机器人连接状态
 * 新表读不到时兼容旧版 settings.ws_daemon_status
 */
function ws读取连接状态() {
    try {
        $rows = db()->fetchAll("SELECT * FROM ws_bot_status");
        $out = [];
        foreach ($rows as $row) {
            $appid = (string)($row['appid'] ?? '');
            if ($appid === '') continue;
            $out[$appid] = [
                'status' => (string)($row['status'] ?? 'unknown'),
                'message' => (string)($row['message'] ?? ''),
                'pid' => intval($row['pid'] ?? 0),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        $legacy = db()->getSetting('ws_daemon_status', []);
        return is_array($legacy) ? $legacy : [];
    }
}

/** 把所有机器人状态标记为已停止（守护进程退出/后台停止时调用） */
function ws停止全部连接状态($message = '守护进程已退出') {
    try {
        db()->execute(
            "UPDATE ws_bot_status SET status = 'stopped', message = ?, pid = 0, updated_at = datetime('now','localtime')",
            [(string)$message]
        );
    } catch (Throwable $e) {
        try {
            db()->setSetting('ws_daemon_status', []);
        } catch (Throwable $e2) {}
    }
}

/**
 * 判断 PID 是否确实是"本项目"的 ws_client.php 进程
 * Linux 下核对 cmdline / 工作目录，避免 PID 复用或同服务器多个 QQbot 站点互相误杀。
 */
function ws是客户端进程($pid) {
    $pid = intval($pid);
    if ($pid <= 0) return false;
    if (!isProcessRunning($pid)) return false;
    if (DIRECTORY_SEPARATOR !== '/') return true; // 非 Linux 无法核对，按存活判断

    $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($cmdline === false) return true;
    $cmd = str_replace("\0", ' ', $cmdline);
    if (strpos($cmd, 'ws_client.php') === false) return false;

    $root = rtrim(str_replace('\\', '/', APP_ROOT), '/');
    $rootReal = @realpath($root);
    $cmdNorm = str_replace('\\', '/', $cmd);
    if (strpos($cmdNorm, $root . '/') !== false) return true;
    if ($rootReal !== false && strpos($cmdNorm, $rootReal . '/') !== false) return true;

    // 相对路径启动（cd 项目目录 && php ws_client.php）：用工作目录判断
    $cwd = @readlink('/proc/' . $pid . '/cwd');
    if ($cwd !== false) {
        $cwdReal = @realpath($cwd);
        if ($cwdReal !== false && $rootReal !== false && $cwdReal === $rootReal) return true;
        if (rtrim(str_replace('\\', '/', $cwd), '/') === $root) return true;
    }
    return false;
}

/**
 * 扫描 /proc，找出本项目目录下所有 ws_client.php 进程
 * 用于清理旧版本（1.8.2 及以前）没有写 PID 文件的子进程，避免升级后重复回复。
 * @param array $exclude 需要排除的 PID
 * @return array [pid => pid]
 */
function ws查找本项目客户端进程($exclude = []) {
    $out = [];
    if (DIRECTORY_SEPARATOR !== '/') return $out;
    $root = rtrim(str_replace('\\', '/', APP_ROOT), '/') . '/';
    $self = function_exists('ws_get_pid') ? ws_get_pid() : getmypid();
    $exclude = array_map('intval', (array)$exclude);
    $exclude[] = intval($self);

    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
        $pid = intval(basename(dirname($file)));
        if ($pid <= 0 || in_array($pid, $exclude, true)) continue;
        $cmd = @file_get_contents($file);
        if ($cmd === false || $cmd === '') continue;
        $cmd = str_replace("\0", ' ', $cmd);
        if (strpos($cmd, 'ws_client.php') === false) continue;

        $matched = strpos(str_replace('\\', '/', $cmd), $root) !== false;
        if (!$matched) {
            // 兼容 `cd 项目目录 && php ws_client.php` 这种相对路径启动：
            // cmdline 里没有项目路径，但 /proc/pid/cwd 指向项目根目录。
            $cwd = @readlink('/proc/' . $pid . '/cwd');
            if ($cwd !== false) {
                $cwdReal = @realpath($cwd);
                $rootReal = @realpath(rtrim($root, '/'));
                if (($cwdReal !== false && $rootReal !== false && $cwdReal === $rootReal)
                    || rtrim(str_replace('\\', '/', $cwd), '/') === rtrim($root, '/')) {
                    $matched = true;
                }
            }
        }
        if (!$matched) continue;
        $out[$pid] = $pid;
    }
    return $out;
}

/**
 * 清理上一次运行残留的子进程（守护进程被 kill -9、服务器重启后 PID 文件残留等）
 * 只杀 cmdline 含 ws_client.php 的进程，不会误伤其它 PHP 进程。
 *
 * @return array 清理结果 [['appid'=>..,'pid'=>..], ...]
 */
function ws清理残留子进程() {
    $candidates = [];
    foreach (ws读取子进程列表() as $appid => $pid) {
        $candidates[$pid] = $appid;
    }
    foreach (glob(APP_ROOT . 'data/ws_worker_*.pid') ?: [] as $file) {
        $pid = intval(@file_get_contents($file));
        if ($pid <= 0) continue;
        $appid = preg_replace('/^ws_worker_|\.pid$/', '', basename($file));
        if (!isset($candidates[$pid])) $candidates[$pid] = $appid;
    }
    // 旧版本子进程不写 PID 文件，按项目路径全量扫描兜底
    foreach (ws查找本项目客户端进程() as $pid) {
        if (!isset($candidates[$pid])) $candidates[$pid] = 'unknown';
    }

    $killed = [];
    foreach ($candidates as $pid => $appid) {
        if (!ws是客户端进程($pid)) continue;
        killProcess($pid, 15);
        $waited = 0;
        while ($waited < 20 && isProcessRunning($pid)) {
            usleep(100000);
            $waited++;
        }
        if (isProcessRunning($pid)) {
            killProcess($pid, 9);
        }
        $killed[] = ['appid' => $appid, 'pid' => $pid];
    }

    foreach (glob(APP_ROOT . 'data/ws_worker_*.pid') ?: [] as $file) {
        @unlink($file);
    }
    ws清理子进程列表();
    return $killed;
}

/** 统计子进程列表中仍存活的实例数 */
function ws存活子进程数($workers = null) {
    if ($workers === null) $workers = ws读取子进程列表();
    $n = 0;
    foreach ($workers as $pid) {
        if (isProcessRunning($pid)) $n++;
    }
    return $n;
}
