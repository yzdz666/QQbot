<?php
/**
 * WebSocket 多机器人进程池测试
 * 运行：php test_ws_pool.php
 *
 * 覆盖：文件名安全转换、PID 列表读写、机器人筛选去重、参数指纹、状态 UPSERT、停止清理
 */

date_default_timezone_set('Asia/Shanghai');

$passCount = 0;
$failCount = 0;
$failures = [];

function ok($name, $cond, $extra = '') {
    global $passCount, $failCount, $failures;
    if ($cond) {
        $passCount++;
        echo "  [PASS] {$name}\n";
    } else {
        $failCount++;
        $failures[] = $name . ($extra !== '' ? " ({$extra})" : '');
        echo "  [FAIL] {$name}" . ($extra !== '' ? " - {$extra}" : '') . "\n";
    }
}

// ==================== 临时目录 / 模拟环境 ====================
$tmpRoot = sys_get_temp_dir() . '/ws_pool_test_' . getmypid() . '/';
@mkdir($tmpRoot . 'data', 0777, true);
define('APP_ROOT', $tmpRoot);

// 进程存活模拟：PID 1~1000 视为存活
function isProcessRunning($pid) {
    return intval($pid) > 0 && intval($pid) <= 1000;
}
function killProcess($pid, $signal = 15) { return true; }

// ==================== mock 数据库（真实 SQLite 内存库） ====================
class WsPoolTestDB {
    public $pdo;
    public $settings = [];
    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("CREATE TABLE bots (
            appid TEXT PRIMARY KEY, secret TEXT, env TEXT, ws_enabled INTEGER DEFAULT 0,
            ws_url TEXT DEFAULT '', enabled INTEGER DEFAULT 1, created_at TEXT DEFAULT '2026-01-01 00:00:00'
        )");
        $this->pdo->exec("CREATE TABLE ws_bot_status (
            appid TEXT PRIMARY KEY, status TEXT DEFAULT 'unknown', message TEXT DEFAULT '',
            pid INTEGER DEFAULT 0, updated_at TEXT DEFAULT (datetime('now','localtime'))
        )");
    }
    public function execute($sql, $params = []) {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }
    public function fetchAll($sql, $params = []) { return $this->execute($sql, $params)->fetchAll(); }
    public function fetch($sql, $params = []) {
        $r = $this->execute($sql, $params)->fetch();
        return $r === false ? null : $r;
    }
    public function fetchColumn($sql, $params = []) {
        $r = $this->execute($sql, $params)->fetch(PDO::FETCH_NUM);
        return $r === false ? false : ($r[0] ?? false);
    }
    public function getSetting($k, $d = null) { return $this->settings[$k] ?? $d; }
    public function setSetting($k, $v) { $this->settings[$k] = $v; }
}
$GLOBALS['__testdb'] = new WsPoolTestDB();
function db() { return $GLOBALS['__testdb']; }

require_once __DIR__ . '/function/ws_pool.php';

// ==================== 1. 文件名安全 ====================
echo "\n[1] 子进程键与文件路径\n";
$key = ws子进程键('102345678');
ok('普通 AppID 原样保留', $key === '102345678');
$key2 = ws子进程键('a/b:c\\d e');
ok('斜杠冒号空格转下划线', $key2 === 'a_b_c_d_e', $key2);
$long1 = ws子进程键(str_repeat('A', 60));
$long2 = ws子进程键(str_repeat('A', 59) . 'B');
ok('超长 AppID 截断后仍唯一', $long1 !== $long2 && strlen($long1) <= 48, $long1 . ' / ' . $long2);
ok('空 AppID 有兜底', ws子进程键('') === 'bot');
ok('PID 文件路径含安全键', strpos(ws子进程PID文件('a/b'), 'ws_worker_a_b.pid') !== false);

// ==================== 2. 子进程列表读写 ====================
echo "\n[2] 子进程列表读写\n";
$listFile = $tmpRoot . 'data/ws_workers.pids';
$ok = ws写入子进程列表(['APPID_A' => 101, 'APPID_B' => 202], $listFile);
ok('写入列表成功', $ok !== false);
$read = ws读取子进程列表($listFile);
ok('读回两条记录', count($read) === 2 && $read['APPID_A'] === 101 && $read['APPID_B'] === 202);
@file_put_contents($listFile, "GOOD\t303\nbad-line\nGOOD2\t0\nGOOD3\tabc\n");
$read = ws读取子进程列表($listFile);
ok('跳过格式错误/非法 PID', count($read) === 1 && $read['GOOD'] === 303, json_encode($read));
$tmpLeft = glob($listFile . '.tmp.*') ?: [];
ok('原子写入不留临时文件', empty($tmpLeft));
ws清理子进程列表($listFile);
ok('清理后文件不存在', !file_exists($listFile));

// ==================== 3. 子进程 PID 文件 ====================
echo "\n[3] 子进程 PID 文件\n";
$pidFile = ws子进程PID文件('APPID_X');
ws写入子进程PID('APPID_X', 456);
ok('写入自己的 PID', intval(@file_get_contents($pidFile)) === 456);
ws删除子进程PID('APPID_X', 789);
ok('PID 不匹配时不删除（防止误删新进程）', file_exists($pidFile));
ws删除子进程PID('APPID_X', 456);
ok('PID 匹配时删除', !file_exists($pidFile));

// ==================== 4. 机器人筛选 ====================
echo "\n[4] 启用机器人的筛选与去重\n";
db()->execute("INSERT INTO bots (appid, secret, env, ws_enabled, enabled) VALUES ('A','s1','正式',1,1)");
db()->execute("INSERT INTO bots (appid, secret, env, ws_enabled, enabled) VALUES ('B','s2','沙箱',1,0)");
db()->execute("INSERT INTO bots (appid, secret, env, ws_enabled, enabled) VALUES ('C','s3','正式',0,1)");
db()->execute("INSERT INTO bots (appid, secret, env, ws_enabled, enabled) VALUES ('D','s4','正式',1,1)");
// 再写一次 D，确保不会因重复数据多出一个实例
db()->execute("INSERT OR REPLACE INTO bots (appid, secret, env, ws_enabled, enabled) VALUES ('D','s4','正式',1,1)");
$bots = ws启用的机器人();
$ids = array_column($bots, 'appid');
sort($ids);
ok('仅返回 ws_enabled=1 且 enabled=1', $ids === ['A', 'D'], implode(',', $ids));
ok('同一 AppID 不重复', count($ids) === count(array_unique($ids)));

// ==================== 5. 参数指纹 ====================
echo "\n[5] 连接参数指纹\n";
$base = ['appid' => 'A', 'secret' => 's1', 'env' => '正式', 'ws_url' => '', 'enabled' => 1, 'ws_enabled' => 1];
$sig1 = ws机器人签名($base);
$sig2 = ws机器人签名($base);
ok('相同参数指纹一致', $sig1 === $sig2);
$changed = $base; $changed['secret'] = 's2';
ok('改 Secret 指纹变化', ws机器人签名($changed) !== $sig1);
$changed = $base; $changed['ws_url'] = 'wss://example.com:9443';
ok('改 WS 地址指纹变化', ws机器人签名($changed) !== $sig1);
$changed = $base; $changed['env'] = '沙箱';
ok('改环境指纹变化', ws机器人签名($changed) !== $sig1);
$changed = $base; $changed['enabled'] = 0;
ok('禁用机器人指纹变化', ws机器人签名($changed) !== $sig1);

// ==================== 6. 连接状态 UPSERT ====================
echo "\n[6] 连接状态写入与停止\n";
ws写入连接状态('A', 'connected', 'WebSocket 已连接', 111);
ws写入连接状态('D', 'connecting', '正在连接...', 222);
$states = ws读取连接状态();
ok('写入两条状态', count($states) === 2 && $states['A']['status'] === 'connected' && $states['D']['status'] === 'connecting');
ok('状态带 PID', $states['A']['pid'] === 111);
ok('状态带更新时间', $states['A']['updated_at'] !== '');
ws写入连接状态('A', 'reconnecting', '子进程退出', 0);
$states = ws读取连接状态();
ok('更新 A 不影响 D', $states['A']['status'] === 'reconnecting' && $states['D']['status'] === 'connecting', json_encode($states));
ok('更新后 PID 归零', $states['A']['pid'] === 0);
ws停止全部连接状态('守护进程已停止');
$states = ws读取连接状态();
$allStopped = true;
foreach ($states as $s) {
    if ($s['status'] !== 'stopped' || $s['pid'] !== 0) $allStopped = false;
}
ok('停止后所有状态为 stopped 且 PID 归零', $allStopped);
ok('停止原因写入 message', $states['A']['message'] === '守护进程已停止');

// ==================== 7. 存活计数 ====================
echo "\n[7] 子进程存活统计\n";
ok('全部存活', ws存活子进程数(['A' => 11, 'B' => 22]) === 2);
ok('部分退出', ws存活子进程数(['A' => 11, 'B' => 5000]) === 1);
ok('全部退出', ws存活子进程数(['A' => 5000]) === 0);
ok('空列表', ws存活子进程数([]) === 0);

// ==================== 汇总 ====================
@unlink($pidFile);
@rmdir($tmpRoot . 'data');
@rmdir($tmpRoot);

echo "\n========================================\n";
echo "通过 {$passCount} 项";
if ($failCount > 0) {
    echo "，失败 {$failCount} 项：\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    echo "========================================\n";
    exit(1);
}
echo "，失败 0 项\n";
echo "========================================\n";
exit(0);
