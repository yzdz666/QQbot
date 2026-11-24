<?php
/**
 * WebSocket 客户端 + 多机器人守护进程
 *
 * 用法:
 *   php ws_client.php                 启动守护进程，并行连接所有启用 WS 的机器人
 *   php ws_client.php <appid>         只运行指定机器人（前台，便于调试）
 *   php ws_client.php <appid> --worker 由守护进程拉起的子进程（带孤儿检测）
 *
 * 架构:
 *   ws_client.php（守护进程）
 *     ├── 机器人 A 子进程（独立 WS 连接、独立 Token 缓存、独立状态）
 *     ├── 机器人 B 子进程
 *     └── 机器人 N 子进程
 *           └── 收到事件 → exec ws_event_handler.php（短生命周期，常量隔离）
 *
 * 关键行为:
 *  - 子进程崩溃 5/10/20/40/60 秒退避重启；运行满 2 分钟后再崩溃重新从 5 秒起
 *  - 后台启用/停用机器人，守护进程 10 秒内自动接入/断开，无需重启整个服务
 *  - 停止时先停子进程再停守护进程；守护进程被 kill -9 时子进程 10 秒内自行退出
 *  - 不依赖 pcntl：优先 proc_open，其次 popen，最后 pcntl_fork
 *
 * 参照 Python (ElainaBot_v2) websocket.py + access.py:
 *  - RESUME 会话恢复、READY/RESUMED、互动回调 ACK
 *  - 心跳 ACK 超时检测、Token 提前刷新与重试
 */

if (php_sapi_name() !== 'cli') {
    die('此脚本只能在命令行运行');
}

require_once(__DIR__ . '/function.php');

set_time_limit(0);
date_default_timezone_set('Asia/Shanghai');

// QQ Gateway OP 码
define('OP_DISPATCH', 0);
define('OP_HEARTBEAT', 1);
define('OP_IDENTIFY', 2);
define('OP_RESUME', 6);
define('OP_RECONNECT', 7);
define('OP_INVALID_SESSION', 9);
define('OP_HELLO', 10);
define('OP_HEARTBEAT_ACK', 11);
define('OP_EVENT_ACK', 12);

// Intents 位掩码
const INTENTS = (1 << 0) | (1 << 10) | (1 << 12) | (1 << 24) | (1 << 25) | (1 << 26) | (1 << 27) | (1 << 30);

const TOKEN_REFRESH_BUFFER = 60;
const TOKEN_MAX_RETRIES = 3;
const TOKEN_RETRY_DELAYS = [3, 6, 12];
const HEARTBEAT_ACK_TIMEOUT = 3;
const RECONNECT_INTERVAL = 5;
const MAX_RECONNECTS = -1;

const UA_PLUGIN_TAG = 'QQBotPlugin/1.8.3';
const UA_CLIENT_VERSION = '9.9.9';
const UA_CLIENT_NAME = 'ElainaBot';

/** 当前进程 PID */
function ws_get_pid() {
    if (function_exists('posix_getpid')) {
        return posix_getpid();
    }
    return getmypid();
}

class WebSocketClient {
    private $socket;
    private $appid;
    private $secret;
    private $env;
    private $seq = null;
    private $sessionId = null;
    private $heartbeatInterval = 45;
    private $lastHeartbeat = 0;
    private $heartbeatAckCount = 0;
    private $connected = false;
    private $customUrl = '';
    private $reconnectCount = 0;
    private $maxReconnects = MAX_RECONNECTS;
    private $reconnectInterval = RECONNECT_INTERVAL;

    // 多机器人守护相关
    private $supervised = false;
    private $stopRequested = false;
    private $lastMasterCheck = 0;
    private $masterDeadCount = 0;

    public function __construct($appid, $secret, $env = '正式', $customUrl = '', $supervised = false) {
        $this->appid = $appid;
        $this->secret = $secret;
        $this->env = $env;
        $this->customUrl = $customUrl;
        $this->supervised = $supervised;
    }

    private function log($msg) {
        echo '[' . date('Y-m-d H:i:s') . '] [' . $this->appid . '] ' . $msg . "\n";
    }

    public function run() {
        $this->log('启动 WebSocket 客户端' . ($this->supervised ? '（子进程）' : ''));
        ws写入连接状态($this->appid, 'connecting', '正在连接...', ws_get_pid());

        while (true) {
            if ($this->stopRequested) break;

            if ($this->maxReconnects > 0 && $this->reconnectCount >= $this->maxReconnects) {
                $this->log("达到最大重连次数 {$this->maxReconnects}，退出");
                ws写入连接状态($this->appid, 'stopped', '达到最大重连次数', 0);
                break;
            }

            try {
                $this->connect();
                $this->loop();
            } catch (Throwable $e) {
                $this->log('错误: ' . $e->getMessage());

                // 守护进程已经消失：子进程不再重连，直接退出，避免成为孤儿继续抢消息
                if ($this->supervised && $this->masterDeadCount >= 2) {
                    $this->log('守护进程已退出，子进程结束');
                    ws写入连接状态($this->appid, 'stopped', '守护进程已退出', 0);
                    break;
                }
                ws写入连接状态($this->appid, 'reconnecting', $e->getMessage(), ws_get_pid());
            }

            $this->connected = false;
            $this->heartbeatAckCount = 0;
            if ($this->socket) {
                @fclose($this->socket);
                $this->socket = null;
            }

            if ($this->stopRequested) break;

            $this->reconnectCount++;
            // 指数退避：5 / 10 / 20 / 40 / 60 秒，避免密钥错误时每秒打接口
            $wait = min(60, $this->reconnectInterval * (1 << min(3, max(0, $this->reconnectCount - 1))));
            $this->log("{$wait}秒后重连... (第{$this->reconnectCount}次)");

            // 分段睡眠，期间保持对守护进程的探测
            $until = time() + $wait;
            while (time() < $until) {
                if ($this->supervised && $this->checkMaster() === false) {
                    if ($this->masterDeadCount >= 2) {
                        $this->log('守护进程已退出，停止重连');
                        ws写入连接状态($this->appid, 'stopped', '守护进程已退出', 0);
                        return;
                    }
                }
                sleep(1);
            }
        }

        $this->log('WebSocket 客户端已退出');
    }

    // ==================== 守护进程存活检测（仅子进程） ====================

    /**
     * @return bool|null true=存活 false=确认已消失 null=暂时读不到（不计数）
     */
    private function checkMaster() {
        $pid = intval(@file_get_contents(ws守护PID文件()));
        if ($pid <= 0) {
            // PID 文件刚被原子替换的瞬间可能读不到，按"暂时未知"处理，避免误退
            return null;
        }
        if ($pid === ws_get_pid()) return true;
        if (isProcessRunning($pid) && ws是客户端进程($pid)) {
            $this->masterDeadCount = 0;
            return true;
        }
        $this->masterDeadCount++;
        $this->log('守护进程检测失败 (' . $this->masterDeadCount . '/2)');
        return false;
    }

    // ==================== Token 管理 ====================

    private function getAccessToken($forceRefresh = false) {
        $cacheTime = 读('function_' . $this->appid, 'time', 0);
        if (!$forceRefresh && time() < $cacheTime) {
            return 读('function_' . $this->appid, 'Access', '');
        }

        $lastError = null;
        for ($i = 0; $i < TOKEN_MAX_RETRIES; $i++) {
            try {
                $url = getApiTokenUrl();
                $json = json_encode(['appId' => $this->appid, 'clientSecret' => $this->secret]);
                $resp = curl($url, 'POST', ['Content-Type: application/json'], $json);
                $data = json_decode($resp, true);

                if (isset($data['access_token'])) {
                    $expiresIn = $data['expires_in'] ?? 7200;
                    写('function_' . $this->appid, 'time', time() + $expiresIn - TOKEN_REFRESH_BUFFER);
                    写('function_' . $this->appid, 'Access', $data['access_token']);
                    $this->log("Token 已刷新, 有效期 {$expiresIn}s");
                    return $data['access_token'];
                }
                $lastError = '响应无access_token: ' . $resp;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }

            if ($i < TOKEN_MAX_RETRIES - 1) {
                $delay = TOKEN_RETRY_DELAYS[$i];
                $this->log("Token获取失败, {$delay}s后重试 (" . ($i + 1) . '/' . TOKEN_MAX_RETRIES . "): {$lastError}");
                sleep($delay);
            }
        }

        throw new Exception('获取Access Token失败 (重试' . TOKEN_MAX_RETRIES . '次): ' . $lastError);
    }

    // ==================== User-Agent ====================

    private function buildUserAgent() {
        $runtime = 'PHP/' . PHP_VERSION . '; ' . strtolower(PHP_OS);
        return UA_PLUGIN_TAG . " ({$runtime}; " . UA_CLIENT_NAME . '/' . UA_CLIENT_VERSION . ')';
    }

    // ==================== 网关地址 ====================

    private function getGatewayUrl() {
        if (!empty($this->customUrl)) {
            return $this->customUrl;
        }
        $token = $this->getAccessToken();
        $env = in_array($this->env, ['正式', '沙箱'], true) ? $this->env : '正式';
        $url = getApiBase($env) . '/gateway/bot';
        $headers = ["Authorization: QQBot {$token}", 'Content-Type: application/json'];
        $resp = curl($url, 'GET', $headers, '');
        $data = json_decode($resp, true);
        $gw = $data['url'] ?? null;
        if (!$gw) {
            throw new Exception('无法获取Gateway URL: ' . $resp);
        }
        return $gw;
    }

    // ==================== 连接 ====================

    private function connect() {
        $gwUrl = trim($this->getGatewayUrl());
        [$host, $path, $port, $plain] = $this->parseGateway($gwUrl);

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        $scheme = $plain ? 'tcp' : 'ssl';
        $address = "{$scheme}://{$host}:{$port}";
        $resumeTag = ($this->sessionId && $this->seq !== null) ? ' [RESUME模式]' : ' [IDENTIFY模式]';
        $this->log("连接: {$address}{$path}{$resumeTag}");

        $this->socket = @stream_socket_client($address, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket) {
            throw new Exception("Socket连接失败: {$errstr} ({$errno})");
        }

        stream_set_timeout($this->socket, 300);

        $key = base64_encode(random_bytes(16));
        $ua = $this->buildUserAgent();
        $handshake = "GET {$path} HTTP/1.1\r\n"
                   . "Host: {$host}\r\n"
                   . "Upgrade: websocket\r\n"
                   . "Connection: Upgrade\r\n"
                   . "Sec-WebSocket-Key: {$key}\r\n"
                   . "Sec-WebSocket-Version: 13\r\n"
                   . "User-Agent: {$ua}\r\n"
                   . "\r\n";
        fwrite($this->socket, $handshake);

        $response = '';
        while (!feof($this->socket)) {
            $line = fgets($this->socket, 1024);
            if ($line === false) break;
            $response .= $line;
            if (strpos($response, "\r\n\r\n") !== false) break;
        }

        if (strpos($response, '101') === false) {
            throw new Exception('WebSocket握手失败: ' . trim($response));
        }

        $this->connected = true;
        $this->log('WebSocket 连接成功');
    }

    /**
     * 解析网关地址：支持 wss://host:port/path、ws://host:port/path、host/path
     * 旧实现固定 443 端口，自定义 WS 地址带端口时会连错。
     */
    private function parseGateway($url) {
        $plain = false;
        $work = $url;
        if (stripos($work, 'ws://') === 0) {
            $plain = true;
        } elseif (stripos($work, 'wss://') === 0) {
            $plain = false;
        } else {
            $work = 'https://' . $work;
        }
        $parts = parse_url($work);
        $host = $parts['host'] ?? 'gateway.qq.com';
        $path = $parts['path'] ?? '/';
        if (isset($parts['query'])) $path .= '?' . $parts['query'];
        $defaultPort = $plain ? 80 : 443;
        $port = intval($parts['port'] ?? $defaultPort) ?: $defaultPort;
        return [$host, $path, $port, $plain];
    }

    // ==================== 主循环 ====================

    private function loop() {
        $lastReceive = time();

        while ($this->connected && !feof($this->socket)) {
            if ($this->supervised && time() - $this->lastMasterCheck >= 5) {
                $this->lastMasterCheck = time();
                $state = $this->checkMaster();
                if ($state === false && $this->masterDeadCount >= 2) {
                    throw new Exception('守护进程已退出');
                }
            }

            if (time() - $this->lastHeartbeat >= $this->heartbeatInterval) {
                $this->sendHeartbeat();
                $this->lastHeartbeat = time();
            }

            if ($this->heartbeatAckCount >= HEARTBEAT_ACK_TIMEOUT) {
                throw new Exception("心跳ACK超时 (连续{$this->heartbeatAckCount}次未收到确认)");
            }

            $frame = $this->readFrame();
            if ($frame === null) {
                if (time() - $lastReceive > $this->heartbeatInterval * HEARTBEAT_ACK_TIMEOUT) {
                    throw new Exception('接收超时');
                }
                continue;
            }

            $lastReceive = time();
            $this->handleMessage($frame);
        }

        if (feof($this->socket)) {
            throw new Exception('连接已断开');
        }
    }

    // ==================== 消息处理 ====================

    private function handleMessage($data) {
        $payload = json_decode($data, true);
        if (!$payload) return;

        $op = $payload['op'] ?? -1;

        if (isset($payload['s']) && $payload['s'] !== null) {
            $this->seq = $payload['s'];
        }

        switch ($op) {
            case OP_HELLO:
                $this->heartbeatInterval = ($payload['d']['heartbeat_interval'] ?? 45000) / 1000;
                $this->log("收到HELLO, 心跳间隔: {$this->heartbeatInterval}s");
                $this->lastHeartbeat = time() - $this->heartbeatInterval;
                if ($this->sessionId && $this->seq !== null) {
                    $this->sendResume();
                } else {
                    $this->sendIdentify();
                }
                break;

            case OP_DISPATCH:
                $this->handleDispatch($payload);
                break;

            case OP_HEARTBEAT_ACK:
                $this->heartbeatAckCount = 0;
                break;

            case OP_RECONNECT:
                $this->log('服务端要求重连');
                $this->connected = false;
                break;

            case OP_INVALID_SESSION:
                $d = $payload['d'] ?? false;
                if ($d && $this->sessionId) {
                    $this->log('会话无效但可恢复');
                } else {
                    $this->log('会话无效, 重新鉴权');
                    $this->sessionId = null;
                    $this->seq = null;
                }
                $this->connected = false;
                sleep(3);
                break;
        }
    }

    // ==================== 事件分发 ====================

    private function handleDispatch($payload) {
        $eventType = $payload['t'] ?? '';
        $data = $payload['d'] ?? [];

        if ($eventType === 'READY') {
            $this->sessionId = $data['session_id'] ?? null;
            $this->reconnectCount = 0;
            $this->log('WebSocket 已就绪 (session=' . substr((string)$this->sessionId, 0, 16) . '...)');
            ws写入连接状态($this->appid, 'connected', 'WebSocket 已连接', ws_get_pid());
            return;
        }

        if ($eventType === 'RESUMED') {
            $this->reconnectCount = 0;
            $this->log('会话已恢复');
            ws写入连接状态($this->appid, 'connected', '会话已恢复', ws_get_pid());
            return;
        }

        $this->log("收到事件: {$eventType}");

        if ($eventType === 'INTERACTION_CREATE') {
            $interactionId = $data['id'] ?? '';
            if (!empty($interactionId)) {
                $this->confirmInteraction($interactionId);
            }
        }

        $this->dispatchEvent($payload);
    }

    // ==================== 子进程派发 ====================

    private function dispatchEvent($payload) {
        $eventJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $script = escapeshellarg(__DIR__ . '/ws_event_handler.php');
        $appidArg = escapeshellarg($this->appid);
        $dataArg = escapeshellarg($eventJson);

        $logDir = APP_ROOT . 'data';
        if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
        $logFileArg = escapeshellarg($logDir . '/ws_event.log');

        $phpBin = getPhpBinary();
        if (empty($phpBin) || $phpBin === 'php') {
            $phpBin = PHP_BINARY;
        }

        if (function_exists('popen')) {
            $cmd = 'nohup ' . escapeshellarg($phpBin) . " {$script} {$appidArg} {$dataArg}"
                 . " >> {$logFileArg} 2>&1 &";
            $handle = @popen($cmd, 'r');
            if ($handle !== false) {
                @fgets($handle);
                pclose($handle);
                return;
            }
        }

        if (function_exists('shell_exec')) {
            $cmd = 'nohup ' . escapeshellarg($phpBin) . " {$script} {$appidArg} {$dataArg}"
                 . " >> {$logFileArg} 2>&1 &";
            @shell_exec($cmd);
            return;
        }

        if (function_exists('proc_open')) {
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $innerCmd = 'nohup ' . escapeshellarg($phpBin) . " {$script} {$appidArg} {$dataArg}"
                       . " >> {$logFileArg} 2>&1 & echo $!";
            $proc = @proc_open('bash -c ' . escapeshellarg($innerCmd), $descriptorSpec, $pipes);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return;
            }
        }

        if (function_exists('exec')) {
            $cmd = 'nohup ' . escapeshellarg($phpBin) . " {$script} {$appidArg} {$dataArg}"
                 . " >> {$logFileArg} 2>&1 &";
            @exec($cmd);
        }
    }

    // ==================== WS 操作帧 ====================

    private function sendIdentify() {
        $token = $this->getAccessToken();
        $payload = [
            'op' => OP_IDENTIFY,
            'd' => [
                'token' => 'QQBot ' . $token,
                'intents' => INTENTS,
                'shard' => [0, 1],
            ]
        ];
        $this->sendFrame(json_encode($payload));
        $this->log('已发送IDENTIFY');
    }

    private function sendResume() {
        // 优先强制刷新；刷新失败（接口抖动/限流）时退回缓存 Token，
        // 否则一次网络波动会让 WS 一直重连不上。
        try {
            $token = $this->getAccessToken(true);
        } catch (Throwable $e) {
            $cached = 读('function_' . $this->appid, 'Access', '');
            if ($cached === '' || $cached === null) throw $e;
            $this->log('Token 刷新失败，使用缓存 Token 继续恢复会话: ' . $e->getMessage());
            $token = $cached;
        }
        $payload = [
            'op' => OP_RESUME,
            'd' => [
                'token' => 'QQBot ' . $token,
                'session_id' => $this->sessionId,
                'seq' => $this->seq,
            ]
        ];
        $this->sendFrame(json_encode($payload));
        $this->log("已发送RESUME (seq={$this->seq})");
    }

    private function sendHeartbeat() {
        $this->sendFrame(json_encode(['op' => OP_HEARTBEAT, 'd' => $this->seq]));
        $this->heartbeatAckCount++;
    }

    private function sendEventAck($code = 0) {
        $this->sendFrame(json_encode(['op' => OP_EVENT_ACK, 'code' => $code]));
    }

    private function confirmInteraction($interactionId) {
        if (empty($interactionId)) return;
        try {
            $token = $this->getAccessToken();
            $env = in_array($this->env, ['正式', '沙箱'], true) ? $this->env : '正式';
            $url = getApiBase($env) . '/interactions/' . $interactionId;
            $headers = [
                'Authorization: QQBot ' . $token,
                'Content-Type: application/json',
            ];
            $resp = curl($url, 'PUT', $headers, json_encode(['code' => 0]));
            $this->log("互动回调已确认: {$interactionId} => {$resp}");
        } catch (Throwable $e) {
            $this->log('互动回调确认失败: ' . $e->getMessage());
        }
    }

    // ==================== WebSocket 帧 ====================

    private function sendFrame($data) {
        if (!$this->socket) return;
        $data = is_string($data) ? $data : json_encode($data);
        $len = strlen($data);
        $frame = chr(0x81);

        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }

        $mask = random_bytes(4);
        $frame .= $mask;
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $data[$i] ^ $mask[$i % 4];
        }
        $frame .= $masked;

        $written = @fwrite($this->socket, $frame);
        if ($written === false) {
            $this->connected = false;
        }
    }

    private function readFrame() {
        if (!$this->socket || feof($this->socket)) return null;

        $read = [$this->socket];
        $write = $except = null;
        $changed = @stream_select($read, $write, $except, 1);

        if ($changed === false || $changed === 0) return null;

        $firstByte = fread($this->socket, 1);
        if ($firstByte === '' || strlen($firstByte) < 1) return null;

        $secondByte = fread($this->socket, 1);
        if (strlen($secondByte) < 1) return null;

        $opcode = ord($firstByte) & 0x0F;
        $masked = (ord($secondByte) & 0x80) !== 0;
        $len = ord($secondByte) & 0x7F;
        $mask = null;

        if ($len === 126) {
            $ext = fread($this->socket, 2);
            if (strlen($ext) < 2) return null;
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = fread($this->socket, 8);
            if (strlen($ext) < 8) return null;
            $len = unpack('J', $ext)[1];
        }

        if ($masked) {
            $mask = fread($this->socket, 4);
            if (strlen($mask) < 4) return null;
        }

        $payload = '';
        $remaining = $len;
        while ($remaining > 0 && !feof($this->socket)) {
            $chunk = fread($this->socket, $remaining);
            if ($chunk === '' || $chunk === false) break;
            $payload .= $chunk;
            $remaining = $len - strlen($payload);
        }

        if (strlen($payload) < $len) return null;

        if ($masked && $mask !== null && strlen($mask) === 4) {
            $unmasked = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $unmasked;
        }

        if ($opcode === 0x08) {
            $this->log('收到close帧');
            $this->connected = false;
            return null;
        }

        if ($opcode === 0x09) {
            $this->sendPong($payload);
            return null;
        }

        return $payload;
    }

    private function sendPong($data) {
        if (!$this->socket) return;
        $frame = chr(0x8A);
        $len = strlen($data);
        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }
        $mask = random_bytes(4);
        $frame .= $mask;
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $data[$i] ^ $mask[$i % 4];
        }
        $frame .= $masked;
        @fwrite($this->socket, $frame);
    }
}

// ==================== 子进程入口 ====================

/**
 * 运行单个机器人
 * @param string $appid
 * @param bool $supervised true=由守护进程拉起，需检测守护进程存活
 */
function ws_run_worker($appid, $supervised = false) {
    $bot = getBot($appid);
    if (!$bot) {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] 机器人 {$appid} 不存在\n");
        ws写入连接状态($appid, 'stopped', '机器人不存在', 0);
        return 1;
    }
    if (intval($bot['enabled']) !== 1 || intval($bot['ws_enabled']) !== 1) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] [{$appid}] 机器人未启用 WS，退出\n");
        ws写入连接状态($appid, 'stopped', '机器人未启用 WS', 0);
        return 0;
    }

    $pid = ws_get_pid();
    ws写入子进程PID($appid, $pid);

    register_shutdown_function(function () use ($appid, $pid) {
        ws删除子进程PID($appid, $pid);
        // 客户端已写明退出原因（如"守护进程已退出"）时保留，不要覆盖成笼统的"进程已退出"
        $states = ws读取连接状态();
        $cur = $states[$appid] ?? null;
        if (is_array($cur) && ($cur['status'] ?? '') === 'stopped' && ($cur['message'] ?? '') !== '') {
            return;
        }
        ws写入连接状态($appid, 'stopped', '进程已退出', 0);
    });

    $client = new WebSocketClient($bot['appid'], $bot['secret'], $bot['env'], $bot['ws_url'], $supervised);
    $client->run();
    return 0;
}

// ==================== 守护进程（supervisor） ====================

class WsSupervisor {
    /** @var array appid => ['pid'=>int,'proc'=>resource|null,'started_at'=>int,'restarts'=>int,'next_start'=>int,'sig'=>string] */
    private $workers = [];
    private $configured = [];
    private $stopping = false;
    private $cleaned = false;
    private $lastReconcile = 0;

    public function __construct() {
        $this->log('守护进程启动 (PID:' . ws_get_pid() . ')');
    }

    private function log($msg) {
        echo '[' . date('Y-m-d H:i:s') . '] [守护进程] ' . $msg . "\n";
    }

    /** 已有存活的守护进程则返回其 PID */
    private function alreadyRunning() {
        $pid = intval(@file_get_contents(ws守护PID文件()));
        if ($pid <= 0 || $pid === ws_get_pid()) return 0;
        return ws是客户端进程($pid) ? $pid : 0;
    }

    public function requestStop() {
        if (!$this->stopping) {
            $this->log('收到停止信号，正在停止所有机器人...');
        }
        $this->stopping = true;
    }

    public function run() {
        $existing = $this->alreadyRunning();
        if ($existing > 0) {
            $this->log("已有守护进程在运行 (PID:{$existing})，本进程退出");
            return 0;
        }

        // 清理上一次运行残留的子进程（守护进程被 kill -9、服务器重启后）
        $stale = ws清理残留子进程();
        if (!empty($stale)) {
            $desc = [];
            foreach ($stale as $item) $desc[] = $item['appid'] . '(' . $item['pid'] . ')';
            $this->log('已清理残留子进程: ' . implode(', ', $desc));
        }

        $write = writeFileSafe(ws守护PID文件(), (string)ws_get_pid());
        if (!$write['success']) {
            fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] 警告: ' . $write['message'] . "\n");
        }

        $this->reconcile(true);
        if (empty($this->workers)) {
            $this->log('没有已启用 WebSocket 的机器人，守护进程退出');
            $this->cleanup('没有已启用的机器人');
            return 0;
        }

        $this->log('机器人数量: ' . count($this->workers) . '（' . implode(', ', array_keys($this->workers)) . '）');

        while (!$this->stopping) {
            $this->reap();

            if (time() - $this->lastReconcile >= 10) {
                $this->reconcile(false);
            }

            $this->spawnPending();
            $this->writeWorkersFile();

            if (empty($this->workers) && empty($this->configured)) {
                $this->log('已没有需要管理的机器人，守护进程退出');
                break;
            }

            sleep(1);
        }

        $this->cleanup('守护进程已停止');
        return 0;
    }

    /**
     * 读取数据库中的机器人并同步子进程
     * @param bool $initial 首次启动：立即拉起
     */
    private function reconcile($initial = false) {
        $bots = ws启用的机器人();
        $now = [];
        foreach ($bots as $bot) {
            $appid = (string)$bot['appid'];
            if ($appid === '') continue;
            $now[$appid] = $bot;
        }

        $changed = false;

        // 1. 机器人被停用/删除 → 停掉子进程
        foreach (array_keys($this->workers) as $appid) {
            if (!isset($now[$appid])) {
                $this->log("机器人 {$appid} 已停用，停止其连接");
                $this->stopWorker($appid, '机器人已停用');
                $changed = true;
            }
        }

        // 2. 连接参数变化 → 重启子进程
        foreach ($now as $appid => $bot) {
            if (!isset($this->workers[$appid])) continue;
            $sig = ws机器人签名($bot);
            if ($this->workers[$appid]['pid'] > 0 && $this->workers[$appid]['sig'] !== $sig) {
                $this->log("机器人 {$appid} 配置已变更，重启连接");
                $this->stopWorker($appid, '配置已变更，重启中');
                $this->workers[$appid] = [
                    'pid' => 0,
                    'proc' => null,
                    'started_at' => time(),
                    'restarts' => 0,
                    'next_start' => 0,
                    'sig' => $sig,
                    'forked' => false,
                ];
                $changed = true;
            }
        }

        // 3. 新增机器人 → 加入待启动列表
        foreach ($now as $appid => $bot) {
            if (!isset($this->workers[$appid])) {
                $this->workers[$appid] = [
                    'pid' => 0,
                    'proc' => null,
                    'started_at' => time(),
                    'restarts' => 0,
                    'next_start' => 0,
                    'sig' => ws机器人签名($bot),
                    'forked' => false,
                ];
                $this->log("发现新机器人 {$appid}");
                $changed = true;
            }
        }

        $this->configured = $now;
        $this->lastReconcile = time();

        if ($initial) {
            $this->spawnPending();
            $this->writeWorkersFile();
        } elseif ($changed) {
            $this->writeWorkersFile();
        }
    }

    /** 启动到点的待启动子进程 */
    private function spawnPending() {
        foreach ($this->workers as $appid => &$worker) {
            if ($worker['pid'] > 0 || $worker['next_start'] > time()) continue;
            if (!isset($this->configured[$appid])) continue;
            $bot = $this->configured[$appid];
            $restarts = $worker['restarts'];
            $pid = $this->spawn($bot);
            if ($pid > 0) {
                $worker['pid'] = $pid;
                $worker['proc'] = $worker['_proc'] ?? null;
                $worker['forked'] = !empty($worker['_forked']);
                $worker['started_at'] = time();
                $worker['next_start'] = 0;
                $worker['restarts'] = $restarts;
                $worker['sig'] = ws机器人签名($bot);
                $this->log("机器人 {$appid} 子进程已启动 (PID:{$pid})");
                ws写入连接状态($appid, 'connecting', '子进程已启动', $pid);
            } else {
                $worker['next_start'] = time() + 10;
                $this->log("机器人 {$appid} 子进程启动失败，10秒后重试");
                ws写入连接状态($appid, 'error', '子进程启动失败', 0);
            }
            unset($worker['_proc'], $worker['_forked']);
        }
        unset($worker);
    }

    /**
     * 启动一个机器人子进程
     * 优先级: proc_open（可拿 PID/可管理） → popen+nohup → pcntl_fork
     * @return int 子进程 PID，0 表示失败
     */
    private function spawn($bot) {
        $appid = (string)$bot['appid'];
        $php = getPhpBinary();
        if (empty($php) || $php === 'php') $php = PHP_BINARY;
        if (!@is_executable($php)) {
            $this->log("找不到 PHP 可执行文件，无法启动 {$appid}");
            return 0;
        }

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($appid) . ' --worker';
        $logDir = APP_ROOT . 'data/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
        $logFile = $logDir . '/ws_daemon.log';
        $pid = 0;

        // 方案1: proc_open（PHP CLI 默认可用，子进程是直接子进程，方便回收）
        // 注意：必须用数组形式或用 exec 前缀，否则 proc_open 会先起一个 sh -c，
        // 记录到的是 shell 的 PID，停止时只杀 shell 会留下真正的 PHP 子进程。
        if (function_exists('proc_open')) {
            $desc = [
                0 => ['file', DIRECTORY_SEPARATOR === '/' ? '/dev/null' : 'NUL', 'r'],
                1 => ['file', $logFile, 'a'],
                2 => ['file', $logFile, 'a'],
            ];
            $pipes = [];
            if (PHP_VERSION_ID >= 70400 && DIRECTORY_SEPARATOR === '/') {
                $proc = @proc_open([$php, __FILE__, $appid, '--worker'], $desc, $pipes, APP_ROOT);
            } else {
                $proc = @proc_open('exec ' . $cmd, $desc, $pipes, APP_ROOT);
            }
            if (is_resource($proc)) {
                $st = @proc_get_status($proc);
                $pid = intval($st['pid'] ?? 0);
                if ($pid > 0) {
                    // 交给 spawnPending 存入 worker
                    $this->workers[$appid]['_proc'] = $proc;
                    return $pid;
                }
                @proc_close($proc);
            }
        }

        // 方案2: popen + nohup（无法拿 PID，等子进程自己写 PID 文件）
        if (function_exists('popen')) {
            $handle = @popen('nohup ' . $cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &', 'r');
            if ($handle !== false) {
                @fgets($handle);
                pclose($handle);
                $pid = $this->waitWorkerPid($appid, 30);
                if ($pid > 0) return $pid;
            }
        }

        // 方案3: pcntl_fork（子进程必须重连数据库，不能复用父进程 PDO 句柄）
        if (function_exists('pcntl_fork')) {
            $fork = @pcntl_fork();
            if ($fork === 0) {
                // 子进程：必须把继承来的信号处理器恢复为默认动作，
                // 否则子进程收到 SIGTERM 只会设置父进程副本里的 stopping 标志，
                // 表现为"杀不死"，停止要等 3 秒超时后 SIGKILL。
                if (function_exists('pcntl_signal')) {
                    @pcntl_signal(SIGTERM, SIG_DFL);
                    @pcntl_signal(SIGINT, SIG_DFL);
                }
                if (function_exists('posix_setsid')) @posix_setsid();
                try { db()->reconnect(); } catch (Throwable $e) {}
                ws_run_worker($appid, true);
                exit(0);
            }
            if ($fork > 0) {
                $this->workers[$appid]['_forked'] = true;
                return $fork;
            }
        }

        return 0;
    }

    /** 等待子进程写入自己的 PID 文件 */
    private function waitWorkerPid($appid, $tries = 30) {
        $file = ws子进程PID文件($appid);
        for ($i = 0; $i < $tries; $i++) {
            usleep(100000);
            $pid = intval(@file_get_contents($file));
            if ($pid > 0 && isProcessRunning($pid)) return $pid;
        }
        return 0;
    }

    /** 判断子进程是否仍在运行（fork 子进程死亡后是僵尸，必须用 waitpid 回收） */
    private function processAlive($worker) {
        $pid = intval($worker['pid'] ?? 0);
        if ($pid <= 0) return false;
        if (!empty($worker['proc']) && is_resource($worker['proc'])) {
            $st = @proc_get_status($worker['proc']);
            return !empty($st['running']);
        }
        if (!empty($worker['forked']) && function_exists('pcntl_waitpid')) {
            $res = @pcntl_waitpid($pid, $status, WNOHANG);
            return $res === 0;
        }
        return isProcessRunning($pid);
    }

    /** 回收已退出的子进程，安排退避重启 */
    private function reap() {
        foreach ($this->workers as $appid => &$worker) {
            if ($worker['pid'] <= 0) continue;

            $alive = $this->processAlive($worker);

            if ($alive) continue;

            if (!empty($worker['proc']) && is_resource($worker['proc'])) {
                @proc_close($worker['proc']);
            }

            $runtime = time() - $worker['started_at'];
            $oldPid = $worker['pid'];
            $worker['pid'] = 0;
            $worker['proc'] = null;

            if ($runtime >= 120) $worker['restarts'] = 0;
            $worker['restarts']++;
            $delay = min(60, RECONNECT_INTERVAL * (1 << min(4, max(0, $worker['restarts'] - 1))));
            $worker['next_start'] = time() + $delay;

            $this->log("机器人 {$appid} 子进程退出 (PID:{$oldPid}, 运行{$runtime}s)，{$delay}秒后重启");
            ws写入连接状态($appid, 'reconnecting', "子进程退出，{$delay}秒后重启", 0);
        }
        unset($worker);
    }

    /** 停止一个子进程：先 SIGTERM，3 秒不退再 SIGKILL */
    private function stopWorker($appid, $message = '已停止') {
        if (!isset($this->workers[$appid])) return;
        $worker = $this->workers[$appid];
        $pid = intval($worker['pid']);
        $proc = $worker['proc'] ?? null;

        if ($pid > 0) {
            if (is_resource($proc)) {
                @proc_terminate($proc, 15);
            } else {
                killProcess($pid, 15);
            }

            $waited = 0;
            while ($waited < 30) {
                if (!$this->processAlive(['pid' => $pid, 'proc' => $proc, 'forked' => !empty($worker['forked'])])) break;
                usleep(100000);
                $waited++;
            }

            if (is_resource($proc)) {
                $st = @proc_get_status($proc);
                if (!empty($st['running'])) @proc_terminate($proc, 9);
                @proc_close($proc);
            } else {
                if (isProcessRunning($pid)) killProcess($pid, 9);
                if (!empty($worker['forked']) && function_exists('pcntl_waitpid')) {
                    @pcntl_waitpid($pid, $status, WNOHANG);
                }
            }
        }

        ws删除子进程PID($appid, $pid ?: null);
        ws写入连接状态($appid, 'stopped', $message, 0);
        unset($this->workers[$appid]);
    }

    /** 把当前子进程 PID 写入列表文件（供后台/监控脚本停止与诊断） */
    private function writeWorkersFile() {
        $list = [];
        foreach ($this->workers as $appid => $worker) {
            if ($worker['pid'] > 0) $list[$appid] = $worker['pid'];
        }
        ws写入子进程列表($list);
    }

    /** 退出清理：停子进程、删 PID 文件、状态置为停止 */
    private function cleanup($message = '守护进程已停止') {
        if ($this->cleaned) return;
        $this->cleaned = true;

        foreach (array_keys($this->workers) as $appid) {
            $this->stopWorker($appid, $message);
        }

        $masterFile = ws守护PID文件();
        if (intval(@file_get_contents($masterFile)) === ws_get_pid()) {
            @unlink($masterFile);
        }
        ws清理子进程列表();
        foreach (glob(APP_ROOT . 'data/ws_worker_*.pid') ?: [] as $file) {
            @unlink($file);
        }
        ws停止全部连接状态($message);
        $this->log('守护进程已退出');
    }

    /** 致命错误/exit 时兜底清理，避免子进程残留 */
    public function emergencyCleanup() {
        if (!$this->cleaned) {
            $this->cleanup('守护进程异常退出');
        }
    }
}

// ==================== 主入口 ====================

$targetAppid = $argv[1] ?? '';
$isWorker = in_array('--worker', $argv, true);

if ($targetAppid !== '' && $targetAppid[0] !== '-') {
    exit(ws_run_worker($targetAppid, $isWorker));
}

$supervisor = new WsSupervisor();

// 只在支持异步信号时注册处理器：否则 pcntl_signal 会替换默认动作，
// 导致 SIGTERM 被"吞掉"，后台停止只能靠 SIGKILL，子进程变孤儿。
// 注意：pcntl_async_signals(true) 返回的是"之前是否开启"，首次调用返回 false，
// 不能把返回值当成功判断，否则信号处理器不会注册，SIGTERM 会直接把主进程打死。
$asyncSignals = false;
if (function_exists('pcntl_async_signals')) {
    @pcntl_async_signals(true);
    $asyncSignals = true;
}
// 没有异步信号支持时不要注册处理器，否则 SIGTERM 会被"吞掉"，
// 反而不如让系统默认动作直接结束进程（子进程还有孤儿检测兜底）。
if ($asyncSignals && function_exists('pcntl_signal')) {
    @pcntl_signal(SIGTERM, function () use ($supervisor) { $supervisor->requestStop(); });
    @pcntl_signal(SIGINT, function () use ($supervisor) { $supervisor->requestStop(); });
}

register_shutdown_function(function () use ($supervisor) {
    $supervisor->emergencyCleanup();
});

exit($supervisor->run());
