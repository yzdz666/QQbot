<?php
/**
 * ws.php - 月下独酌管机 官方 WebSocket 客户端守护进程
 *
 * 通过 QQ 官方 WebSocket 网关接收机器人事件，作为 Webhook(index.php) 的替代/补充模式。
 * 事件分发复用 index.php 的 Main() 逻辑（加载插件、调用 bot.php 发送函数）。
 *
 * 用法（CLI）:
 *   php ws.php                      # 为 main.json 中所有机器人建立 WS 连接
 *   php ws.php --appid=102030000    # 仅指定机器人
 *   php ws.php --once               # 单次运行（连接后收到首个事件即退出，用于测试）
 *
 * 状态文件: database/ws_status.json   (供后台 admin/api/ws.php 读取)
 * 停止标志: database/ws_stop.flag     (存在则守护进程优雅退出)
 * 日志:     Log/ws.log
 *
 * 依赖: PHP CLI + curl + sodium 扩展（与 index.php 一致）
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "此脚本只能在 CLI 下运行\n");
    exit(1);
}

set_time_limit(0);
date_default_timezone_set('Asia/Shanghai');

// ---------- 路径常量 ----------
define('APP_ROOT', __DIR__ . '/');
$mainFile = APP_ROOT . 'main.json';
$statusFile = APP_ROOT . 'database/ws_status.json';
$stopFlag   = APP_ROOT . 'database/ws_stop.flag';
$wsLogFile  = APP_ROOT . 'Log/ws.log';

if (!is_dir(APP_ROOT . 'database')) @mkdir(APP_ROOT . 'database', 0777, true);
if (!is_dir(APP_ROOT . 'Log'))      @mkdir(APP_ROOT . 'Log', 0777, true);

// ---------- 加载核心函数（读/写/wlog/curl/sodium 签名） ----------
require APP_ROOT . 'function.php';

// ---------- 解析 CLI 参数 ----------
$opts = getopt('', ['appid::', 'once::']);
$onlyAppid = $opts['appid'] ?? '';
$onceMode  = isset($opts['once']);

// ---------- 加载 main.json ----------
if (!file_exists($mainFile)) {
    wslog("main.json 不存在，无法启动");
    exit(1);
}
$mainJson = json_decode(file_get_contents($mainFile), true);
if (!is_array($mainJson) || empty($mainJson)) {
    wslog("main.json 为空或格式错误");
    exit(1);
}

// 过滤要连接的机器人
$bots = [];
foreach ($mainJson as $appid => $cfg) {
    if ($onlyAppid !== '' && (string)$appid !== (string)$onlyAppid) continue;
    if (empty($cfg['secret'])) {
        wslog("机器人 {$appid} 缺少 secret，跳过");
        continue;
    }
    $bots[(string)$appid] = $cfg;
}
if (empty($bots)) {
    wslog("没有可用的机器人配置（appid=" . $onlyAppid . "）");
    exit(1);
}

wslog("WebSocket 守护进程启动，PID=" . getmypid() . "，机器人数量=" . count($bots) . ($onceMode ? "（单次测试模式）" : ""));

// ---------- 写入初始状态 ----------
writeStatus([
    'running'    => true,
    'pid'        => getmypid(),
    'started_at' => date('Y-m-d H:i:s'),
    'bots'       => array_keys($bots),
    'connections'=> [],
    'once'       => $onceMode,
]);

// 捕获信号优雅退出
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, 'ws_shutdown');
    pcntl_signal(SIGINT,  'ws_shutdown');
}

// ---------- 为主进程内每个机器人建立连接（独立维护 session/seq） ----------
$connections = [];
foreach ($bots as $appid => $cfg) {
    $connections[$appid] = [
        'appid'      => $appid,
        'cfg'        => $cfg,
        'session_id' => null,
        'seq'        => null,
        'socket'     => null,
        'hb_interval'=> 41,
        'last_hb'    => 0,
        'last_hb_ack'=> true,
        'state'      => 'init',       // init / connected / ready / reconnecting / closed
        'gateway'    => '',
        'stats'      => ['received' => 0, 'dispatched' => 0, 'errors' => 0, 'reconnects' => 0],
        'resume'     => false,
    ];
}

// ---------- 主循环：轮询每个连接读写 ----------
$lastStatusFlush = 0;
while (true) {
    if (file_exists($stopFlag)) {
        wslog("检测到停止标志，优雅退出");
        @unlink($stopFlag);
        break;
    }

    $now = time();
    foreach ($connections as $appid => &$conn) {
        // 1. 建立连接
        if ($conn['socket'] === null) {
            try {
                ws_connect($conn);
            } catch (Throwable $e) {
                $conn['stats']['errors']++;
                wslog("[{$appid}] 连接失败: " . $e->getMessage());
                $conn['state'] = 'reconnecting';
                sleep(min(10 + $conn['stats']['reconnects'] * 5, 60));
                $conn['stats']['reconnects']++;
                continue;
            }
        }

        $sock = $conn['socket'];
        // 2. 心跳
        if ($conn['state'] === 'ready' && $conn['hb_interval'] > 0 &&
            ($now - $conn['last_hb']) >= $conn['hb_interval']) {
            if (!$conn['last_hb_ack']) {
                wslog("[{$appid}] 心跳 ACK 超时，重连");
                ws_close($conn);
                $conn['resume'] = true;
                continue;
            }
            ws_send_frame($sock, json_encode(['op' => 1, 'd' => $conn['seq']], JSON_UNESCAPED_UNICODE));
            $conn['last_hb'] = $now;
            $conn['last_hb_ack'] = false;
        }

        // 3. 读取可用数据
        $read = [$sock]; $write = null; $except = null;
        $tv = 0;
        if (is_resource($sock)) {
            $changed = @stream_select($read, $write, $except, $tv);
            if ($changed === false) {
                wslog("[{$appid}] stream_select 出错，重连");
                ws_close($conn);
                $conn['resume'] = true;
                continue;
            }
            if ($changed > 0) {
                $frame = ws_recv_frame($sock);
                if ($frame === null) {
                    wslog("[{$appid}] 连接被远端关闭，重连");
                    ws_close($conn);
                    $conn['resume'] = true;
                    continue;
                }
                if ($frame !== '') {
                    try {
                        ws_handle_payload($conn, $frame);
                    } catch (Throwable $e) {
                        $conn['stats']['errors']++;
                        wslog("[{$appid}] 处理消息异常: " . $e->getMessage());
                    }
                }
            }
        } else {
            ws_close($conn);
            $conn['resume'] = true;
        }
    }
    unset($conn);

    // 4. 定期刷新状态文件
    if (($now - $lastStatusFlush) >= 3) {
        $lastStatusFlush = $now;
        $connStatus = [];
        foreach ($connections as $appid => $c) {
            $connStatus[$appid] = [
                'state'       => $c['state'],
                'gateway'     => $c['gateway'],
                'session_id'  => $c['session_id'],
                'seq'         => $c['seq'],
                'reconnects'  => $c['stats']['reconnects'],
                'received'    => $c['stats']['received'],
                'dispatched'  => $c['stats']['dispatched'],
                'errors'      => $c['stats']['errors'],
                'last_hb_ack' => $c['last_hb_ack'],
            ];
        }
        writeStatus([
            'running'    => true,
            'pid'        => getmypid(),
            'started_at' => date('Y-m-d H:i:s', $now),
            'bots'       => array_keys($bots),
            'connections'=> $connStatus,
            'once'       => $onceMode,
        ]);
    }

    if ($onceMode) {
        // 单次测试模式：收到并分发一个事件后退出
        $anyReady = false; $anyDispatched = false;
        foreach ($connections as $appid => $c) {
            if ($c['state'] === 'ready') $anyReady = true;
            if ($c['stats']['dispatched'] > 0) $anyDispatched = true;
        }
        if ($anyDispatched) {
            wslog("单次模式：已分发事件，退出");
            break;
        }
        // 若 30 秒仍未就绪/无事件，也退出
        if (($now - $lastStatusFlush) > 0 && ($now - (int)(filemtime($statusFile) ?: $now)) > 30) {
            // 简单超时保护
        }
    }

    usleep(200000); // 200ms 轮询间隔，降低 CPU
}

// ---------- 退出清理 ----------
foreach ($connections as $appid => $conn) {
    if ($conn['socket']) ws_close($conn);
}
writeStatus([
    'running'    => false,
    'pid'        => getmypid(),
    'started_at' => date('Y-m-d H:i:s'),
    'bots'       => array_keys($bots),
    'connections'=> [],
    'stopped_at' => date('Y-m-d H:i:s'),
    'once'       => $onceMode,
]);
wslog("WebSocket 守护进程退出");
exit(0);


// ==================== WS 协议实现 ====================

function ws_connect(array &$conn): void
{
    $appid = $conn['appid'];
    // 1. 获取 access token
    $token = get_access_token($appid, $conn['cfg']['secret']);
    if (!$token) {
        throw new RuntimeException("获取 access_token 失败");
    }
    $conn['access_token'] = $token;

    // 2. 获取网关地址
    $env = $conn['cfg']['type'] ?? '正式';
    $base = ($env === '沙箱') ? 'https://sandbox.api.sgroup.qq.com' : 'https://api.sgroup.qq.com';
    $gwResp = curl($base . '/gateway', 'GET', ['Authorization: QQBot ' . $token], '');
    $gwJson = json_decode($gwResp, true);
    $gwUrl = $gwJson['url'] ?? '';
    if (!$gwUrl) {
        throw new RuntimeException("获取网关地址失败: " . $gwResp);
    }
    $conn['gateway'] = $gwUrl;

    // 3. 解析 wss URL
    $parts = parse_url($gwUrl);
    $host = $parts['host'] ?? '';
    $port = $parts['port'] ?? 443;
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    if (!$host) throw new RuntimeException("网关地址无效: {$gwUrl}");

    // 4. 建立 TLS 连接
    $remote = 'ssl://' . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        throw new RuntimeException("stream_socket_client 失败: {$errstr} ({$errno})");
    }
    stream_set_blocking($sock, false);
    $conn['socket'] = $sock;

    // 5. WebSocket 握手
    $key = base64_encode(random_bytes(16));
    $handshake = "GET {$path} HTTP/1.1\r\n"
               . "Host: {$host}\r\n"
               . "Upgrade: websocket\r\n"
               . "Connection: Upgrade\r\n"
               . "Sec-WebSocket-Key: {$key}\r\n"
               . "Sec-WebSocket-Version: 13\r\n"
               . "User-Agent: YueXiaDuZhuo-WS/1.0\r\n"
               . "\r\n";
    fwrite($sock, $handshake);

    // 6. 读取握手响应
    $resp = ws_read_headers($sock, 5);
    if (!preg_match('#^HTTP/1\.1 101#', $resp)) {
        ws_close($conn);
        throw new RuntimeException("握手失败: " . substr($resp, 0, 200));
    }

    $conn['state'] = 'connected';
    $conn['last_hb'] = time();
    $conn['last_hb_ack'] = true;
    wslog("[{$appid}] WS 连接建立 {$gwUrl}");
}

function ws_read_headers($sock, float $timeoutSec): string
{
    $data = '';
    $end = microtime(true) + $timeoutSec;
    while (microtime(true) < $end) {
        $r = [$sock]; $w = null; $e = null;
        $tv = (int)(($end - microtime(true)) * 1_000_000);
        if ($tv <= 0) break;
        @stream_select($r, $w, $e, 0, $tv);
        $chunk = fread($sock, 4096);
        if ($chunk === false || $chunk === '') {
            if (feof($sock)) break;
            continue;
        }
        $data .= $chunk;
        if (strpos($data, "\r\n\r\n") !== false) break;
    }
    return $data;
}

/** 发送一个文本帧（客户端必须 mask） */
function ws_send_frame($sock, string $payload, int $opcode = 0x1): bool
{
    if (!is_resource($sock)) return false;
    $len = strlen($payload);
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < $len; $i++) {
        $masked .= $payload[$i] ^ $mask[$i % 4];
    }
    $frame = chr(0x80 | $opcode); // FIN + opcode
    if ($len < 126) {
        $frame .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $frame .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $frame .= chr(0x80 | 127) . pack('J', $len);
    }
    $frame .= $mask . $masked;
    $written = 0;
    while ($written < strlen($frame)) {
        $w = @fwrite($sock, substr($frame, $written));
        if ($w === false || $w === 0) return false;
        $written += $w;
    }
    return true;
}

/** 读取一帧并返回其 Payload 字符串；'' 表示心跳/控制帧已处理；null 表示连接关闭 */
function ws_recv_frame($sock)
{
    $header = ws_read_exact($sock, 2);
    if ($header === null) return null;
    $b0 = ord($header[0]);
    $b1 = ord($header[1]);
    $fin = ($b0 & 0x80) !== 0;
    $opcode = $b0 & 0x0F;
    $masked = ($b1 & 0x80) !== 0;
    $len = $b1 & 0x7F;
    if ($len === 126) {
        $ext = ws_read_exact($sock, 2);
        if ($ext === null) return null;
        $len = unpack('n', $ext)[1];
    } elseif ($len === 127) {
        $ext = ws_read_exact($sock, 8);
        if ($ext === null) return null;
        $len = unpack('J', $ext)[1];
    }
    $mask = '';
    if ($masked) {
        $mask = ws_read_exact($sock, 4);
        if ($mask === null) return null;
    }
    $payload = '';
    if ($len > 0) {
        $payload = ws_read_exact($sock, $len);
        if ($payload === null) return null;
        if ($masked) {
            $unmasked = '';
            for ($i = 0; $i < $len; $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $unmasked;
        }
    }
    switch ($opcode) {
        case 0x9:  // Ping -> Pong
            ws_send_frame($sock, $payload, 0xA);
            return '';
        case 0xA:  // Pong
            return '';
        case 0x8:  // Close
            return null;
        case 0x1:  // Text
        case 0x2:  // Binary
        case 0x0:  // Continuation
        default:
            return $payload;
    }
}

function ws_read_exact($sock, int $n)
{
    $data = '';
    $end = microtime(true) + 5;
    while (strlen($data) < $n) {
        $r = [$sock]; $w = null; $e = null;
        @stream_select($r, $w, $e, 0, 200000);
        $chunk = @fread($sock, $n - strlen($data));
        if ($chunk === false) return null;
        if ($chunk === '') {
            if (@feof($sock)) return null;
            if (microtime(true) > $end) return null;
            continue;
        }
        $data .= $chunk;
    }
    return $data;
}

function ws_close(array &$conn): void
{
    if (!empty($conn['socket']) && is_resource($conn['socket'])) {
        @ws_send_frame($conn['socket'], '', 0x8);
        @fclose($conn['socket']);
    }
    $conn['socket'] = null;
    $conn['state'] = 'closed';
}


// ==================== 业务处理 ====================

function ws_handle_payload(array &$conn, string $payload): void
{
    $data = json_decode($payload, true);
    if (!is_array($data)) {
        wslog("[{$conn['appid']}] 收到非 JSON 数据: " . substr($payload, 0, 200));
        return;
    }
    $conn['stats']['received']++;
    $op = $data['op'] ?? -1;

    switch ($op) {
        case 10: // Hello
            $conn['hb_interval'] = (int)(($data['d']['heartbeat_interval'] ?? 41250) / 1000);
            if ($conn['hb_interval'] < 5) $conn['hb_interval'] = 41;
            if (!empty($conn['session_id']) && $conn['resume']) {
                // Resume
                $resume = [
                    'op' => 6,
                    'd'  => [
                        'token'      => 'QQBot ' . ($conn['access_token'] ?? ''),
                        'session_id' => $conn['session_id'],
                        'seq'        => $conn['seq'],
                    ],
                ];
                ws_send_frame($conn['socket'], json_encode($resume, JSON_UNESCAPED_UNICODE));
                wslog("[{$conn['appid']}] 发送 Resume，session={$conn['session_id']} seq={$conn['seq']}");
                $conn['resume'] = false;
            } else {
                // Identify
                $identify = [
                    'op' => 2,
                    'd'  => [
                        'token'   => 'QQBot ' . ($conn['access_token'] ?? ''),
                        'intents' => ws_intents(),
                        'shard'   => [0, 1],
                    ],
                ];
                ws_send_frame($conn['socket'], json_encode($identify, JSON_UNESCAPED_UNICODE));
                wslog("[{$conn['appid']}] 发送 Identify，heartbeat_interval={$conn['hb_interval']}s");
            }
            break;

        case 11: // Heartbeat ACK
            $conn['last_hb_ack'] = true;
            break;

        case 0:  // Dispatch
            $t = $data['t'] ?? '';
            $s = $data['s'] ?? null;
            if ($s !== null) $conn['seq'] = $s;
            if ($t === 'READY') {
                $conn['session_id'] = $data['d']['session_id'] ?? null;
                $conn['state'] = 'ready';
                $user = $data['d']['user'] ?? [];
                wslog("[{$conn['appid']}] READY session={$conn['session_id']} user=" . ($user['username'] ?? ''));
                break;
            }
            if ($t === 'RESUMED') {
                $conn['state'] = 'ready';
                wslog("[{$conn['appid']}] RESUMED 成功");
                break;
            }
            // 真实业务事件 -> 分发
            ws_dispatch($conn, $data);
            break;

        case 7:  // Reconnect (服务端要求)
            wslog("[{$conn['appid']}] 服务端要求 Reconnect");
            ws_close($conn);
            $conn['resume'] = true;
            break;

        case 9:  // Invalid session
            wslog("[{$conn['appid']}] Invalid session，重新 Identify");
            $conn['session_id'] = null;
            $conn['seq'] = null;
            ws_close($conn);
            $conn['resume'] = false;
            sleep(3);
            break;

        default:
            wslog("[{$conn['appid']}] 未知 op={$op}: " . substr($payload, 0, 200));
    }
}

/** 业务事件分发：复用 index.php 的 Main() 逻辑 */
function ws_dispatch(array &$conn, array $raw): void
{
    $appid = $conn['appid'];
    $event = $raw['t'] ?? '';
    if ($event === '') return;

    // 设置应用上下文常量（与 index.php initAppContext 一致）
    ws_init_context($appid, $conn['cfg']);

    // 事件去重统计（与 index.php 一致，写入 事件判断 数据库供后台统计）
    $event_id = $raw['id'] ?? '';
    if ($event_id !== '') {
        $already = 读("事件判断/" . appid . "/" . date("Y-m-d"), $event_id, false);
        if ($already) {
            wslog("[{$appid}] 元数据重复 ({$event_id})，跳过");
            return;
        }
        写("事件判断/" . appid . "/" . date("Y-m-d"), $event_id, true);
    }

    // 记录原始事件日志（与 index.php 一致）
    wlog(json_encode($raw, JSON_UNESCAPED_UNICODE), appid);

    $conn['stats']['dispatched']++;
    ws_run_main($raw);
}

/** 模拟 index.php 的 Main()：定义事件相关常量并加载插件 */
function ws_run_main(array $raw): void
{
    if (!defined('raw')) define('raw', $raw);
    $event = $raw["t"] ?? '';
    switch ($event) {
        case "GROUP_AT_MESSAGE_CREATE":
        case "GROUP_MESSAGE_CREATE":
            ws_def("消息来源", "群聊");
            ws_def("消息ID", $raw["d"]["id"] ?? '');
            ws_def("消息", trim($raw["d"]["content"] ?? '', "/ "));
            ws_def("来源", $raw["d"]["group_id"] ?? '');
            ws_def("用户", $raw["d"]["author"]["id"] ?? '');
            break;
        case "C2C_MESSAGE_CREATE":
            ws_def("消息来源", "私聊");
            ws_def("消息ID", $raw["d"]["id"] ?? '');
            ws_def("消息", trim($raw["d"]["content"] ?? '', "/ "));
            ws_def("来源", $raw["d"]["author"]["id"] ?? '');
            ws_def("用户", $raw["d"]["author"]["id"] ?? '');
            break;
        case "GROUP_ADD_ROBOT":
            ws_def("消息来源", "加群");
            ws_def("事件ID", $raw["id"] ?? '');
            ws_def("消息", "[加群]");
            ws_def("来源", $raw["d"]["group_openid"] ?? '');
            ws_def("用户", $raw["d"]["op_member_openid"] ?? '');
            break;
        case "GROUP_DEL_ROBOT":
            ws_def("消息来源", "退群");
            ws_def("事件ID", $raw["id"] ?? '');
            ws_def("消息", "[退群]");
            ws_def("来源", $raw["d"]["group_openid"] ?? '');
            ws_def("用户", $raw["d"]["op_member_openid"] ?? '');
            break;
        case "INTERACTION_CREATE":
            ws_def("消息来源", "互动");
            ws_def("事件ID", $raw["id"] ?? '');
            ws_def("来源", $raw["d"]["group_openid"] ?? ($raw["d"]["user_openid"] ?? ''));
            ws_def("用户", $raw["d"]["user_openid"] ?? ($raw["d"]["group_member_openid"] ?? ""));
            ws_def("消息", "[互动]");
            break;
        case "GROUP_MEMBER_ADD":
            ws_def("消息来源", "群成员增加");
            ws_def("事件ID", $raw["id"] ?? '');
            ws_def("消息", "[群成员增加]");
            ws_def("来源", $raw["d"]["group_openid"] ?? '');
            ws_def("用户", $raw["d"]["member_openid"] ?? '');
            break;
        case "GROUP_MEMBER_REMOVE":
            ws_def("消息来源", "群成员移除");
            ws_def("事件ID", $raw["id"] ?? '');
            ws_def("消息", "[群成员移除]");
            ws_def("来源", $raw["d"]["group_openid"] ?? '');
            ws_def("用户", $raw["d"]["member_openid"] ?? '');
            break;
        default:
            return;
    }

    require APP_ROOT . "bot.php";
    // 加载并执行启用的插件（与 index.php load_plugin 一致）
    $All = glob(APP_ROOT . "plugin/*.php");
    foreach ($All as $name) {
        $plugin_name = basename($name, ".php");
        if (defined('plugin') && is_array(plugin) && isset(plugin[$plugin_name]) && plugin[$plugin_name]) {
            try {
                require_once $name;
            } catch (Throwable $e) {
                $error = json_encode([
                    "plat_error" => "[{$name}]运行出错: " . $e->getMessage() . " 行数:" . $e->getLine()
                ], JSON_UNESCAPED_UNICODE);
                wlog($error, defined('appid') ? appid : null);
            }
        }
    }
}

function ws_init_context(string $appidVal, array $cfg): void
{
    if (!defined('appid'))  define("appid", $appidVal);
    if (!defined('secret')) define("secret", $cfg["secret"] ?? '');
    if (!defined('type'))   define("type", $cfg["type"] ?? '正式');
    if (!defined('plugin')) define("plugin", $cfg["plugin"] ?? []);
}

function ws_def(string $name, $value): void
{
    if (!defined($name)) define($name, $value);
}

/** 获取 access_token（带本地缓存，与 bot.php BOT凭证() 等价但独立，避免常量依赖） */
function get_access_token(string $appid, string $secret): string
{
    $cacheTime = 读("function/" . $appid, "time", 0);
    if (time() < $cacheTime) {
        return 读("function/" . $appid, "Access", '');
    }
    $json = json_encode(["appId" => (string)$appid, "clientSecret" => $secret]);
    $resp = curl("https://bots.qq.com/app/getAppAccessToken", "POST", ['Content-Type: application/json'], $json);
    $arr = json_decode($resp, true);
    $token = $arr['access_token'] ?? '';
    $exp = $arr['expires_in'] ?? 7200;
    if ($token) {
        写("function/" . $appid, "time", time() + (int)$exp - 60);
        写("function/" . $appid, "Access", $token);
    }
    return $token;
}

/** 订阅 intents：覆盖本项目处理的所有事件类型 */
function ws_intents(): int
{
    // C2C_GROUP_AT_MESSAGES (1<<25): GROUP_AT_MESSAGE_CREATE / C2C_MESSAGE_CREATE 等
    // INTERACTION (1<<26): INTERACTION_CREATE
    // DIRECT_MESSAGE (1<<12): 私信频道
    // PUBLIC_GUILD_MESSAGES (1<<30): 公域频道消息
    return (1 << 25) | (1 << 26) | (1 << 12) | (1 << 30);
}


// ==================== 工具函数 ====================

function wslog(string $msg): void
{
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . PHP_EOL;
    file_put_contents($GLOBALS['wsLogFile'], $line, FILE_APPEND | LOCK_EX);
    fwrite(STDOUT, $line);
}

function writeStatus(array $data): void
{
    $data['updated_at'] = date('Y-m-d H:i:s');
    file_put_contents($GLOBALS['statusFile'], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function ws_shutdown(int $signo): void
{
    wslog("收到信号 {$signo}，准备退出");
    @file_put_contents($GLOBALS['stopFlag'], (string)$signo);
}
