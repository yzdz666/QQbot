<?php
/**
 * WebSocket 客户端 - 连接QQ网关接收实时事件
 * 支持 CLI 模式运行: php ws_client.php [appid]
 * 当不指定appid时，连接所有ws_enabled=1的机器人
 *
 * 参照 Python (ElainaBot_v2) 的 websocket.py + access.py 实现:
 * - RESUME 会话恢复 (断线重连时优先恢复而非重新鉴权)
 * - READY/RESUMED 事件处理 (保存 session_id)
 * - OP_EVENT_ACK (op 12) 交互事件确认
 * - 群成员增加/移除事件支持
 * - 重连时强制刷新 Token
 * - 心跳ACK超时检测 (3次未收到ACK即重连)
 * - 最大重连次数限制
 * - Token 过期主动刷新 + 3次重试 (3/6/12秒, 对应 Python access.py)
 * - 子进程派发事件 (对应 Python asyncio.create_task, 确保常量隔离+require_once正常)
 * - User-Agent 格式 (对应 Python build_user_agent, QQ前端显示连接客户端)
 *
 * 架构:
 *   ws_client.php (WS客户端, 长驻进程)
 *     ├── 接收事件 → exec ws_event_handler.php (子进程, 短生命周期)
 *     │                ├── 常量隔离 (define不冲突)
 *     │                ├── require_once 正常 (每个进程全新)
 *     │                └── 插件错误不影响WS连接
 *     └── 互动事件 → 立即发送ACK (不等待子进程)
 */

if (php_sapi_name() !== 'cli') {
    die('此脚本只能在命令行运行');
}

require_once(__DIR__ . '/function.php');

set_time_limit(0);
date_default_timezone_set('Asia/Shanghai');

// QQ Gateway OP 码 (与 Python websocket.py 一致)
define('OP_DISPATCH', 0);
define('OP_HEARTBEAT', 1);
define('OP_IDENTIFY', 2);
define('OP_RESUME', 6);
define('OP_RECONNECT', 7);
define('OP_INVALID_SESSION', 9);
define('OP_HELLO', 10);
define('OP_HEARTBEAT_ACK', 11);
define('OP_EVENT_ACK', 12);

// Intents 位掩码 (与 Python _INTENTS 一致)
const INTENTS = (1 << 0) | (1 << 10) | (1 << 12) | (1 << 24) | (1 << 25) | (1 << 26) | (1 << 27) | (1 << 30);

// Token 提前刷新缓冲 (秒, 对应 Python _REFRESH_BUFFER)
const TOKEN_REFRESH_BUFFER = 60;
// Token 获取重试次数 (对应 Python _MAX_RETRIES)
const TOKEN_MAX_RETRIES = 3;
// Token 重试延迟 (秒, 对应 Python _RETRY_DELAYS)
const TOKEN_RETRY_DELAYS = [3, 6, 12];
// 心跳ACK超时阈值 (3次心跳未收到ACK)
const HEARTBEAT_ACK_TIMEOUT = 3;
// 默认重连间隔 (秒, 对应 Python reconnect_interval)
const RECONNECT_INTERVAL = 5;
// 默认最大重连次数 (-1 = 无限, 对应 Python max_reconnects)
const MAX_RECONNECTS = -1;

// User-Agent 标识 (对应 Python _UA_PLUGIN_TAG / _CLIENT_VERSION)
const UA_PLUGIN_TAG = 'QQBotPlugin/1.7.2';
const UA_CLIENT_VERSION = '9.9.9';
const UA_CLIENT_NAME = 'ElainaBot';

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
    private $gatewayUrl = '';
    private $customUrl = '';
    private $reconnectCount = 0;
    private $maxReconnects = MAX_RECONNECTS;
    private $reconnectInterval = RECONNECT_INTERVAL;

    public function __construct($appid, $secret, $env = '正式', $customUrl = '') {
        $this->appid = $appid;
        $this->secret = $secret;
        $this->env = $env;
        $this->customUrl = $customUrl;
    }

    public function run() {
        echo "[" . date('Y-m-d H:i:s') . "] 启动 WebSocket 客户端: appid={$this->appid}\n";
        ws_update_status($this->appid, 'connecting', '正在连接...');

        while (true) {
            // 检查最大重连次数 (对应 Python: 0 < max_reconnects <= reconnect_count)
            if ($this->maxReconnects > 0 && $this->reconnectCount >= $this->maxReconnects) {
                echo "[" . date('Y-m-d H:i:s') . "] 达到最大重连次数 {$this->maxReconnects}，退出\n";
                ws_update_status($this->appid, 'stopped', '达到最大重连次数');
                break;
            }

            try {
                $this->connect();
                $this->loop();
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] 错误: " . $e->getMessage() . "\n";
                ws_update_status($this->appid, 'reconnecting', $e->getMessage());
            }

            // 清理连接
            $this->connected = false;
            $this->heartbeatAckCount = 0;
            if ($this->socket) {
                @fclose($this->socket);
                $this->socket = null;
            }

            $this->reconnectCount++;
            $wait = $this->reconnectInterval;
            echo "[" . date('Y-m-d H:i:s') . "] {$wait}秒后重连... (第{$this->reconnectCount}次)\n";
            sleep($wait);
        }

        echo "[" . date('Y-m-d H:i:s') . "] WebSocket 客户端已退出\n";
    }

    // ==================== Token 管理 (对应 Python access.py TokenManager) ====================

    private function getAccessToken($forceRefresh = false) {
        $cacheTime = 读("function_".$this->appid, "time", 0);
        if (!$forceRefresh && time() < $cacheTime) {
            return 读("function_".$this->appid, "Access", "");
        }

        // 参照 Python access.py _refresh(): 3次重试, 延迟 3/6/12 秒
        $lastError = null;
        for ($i = 0; $i < TOKEN_MAX_RETRIES; $i++) {
            try {
                $url = "https://bots.qq.com/app/getAppAccessToken";
                $json = json_encode(["appId" => $this->appid, "clientSecret" => $this->secret]);
                $resp = curl($url, "POST", ['Content-Type: application/json'], $json);
                $data = json_decode($resp, true);

                if (isset($data['access_token'])) {
                    $expiresIn = $data['expires_in'] ?? 7200;
                    // 提前60秒刷新 (对应 Python _REFRESH_BUFFER)
                    写("function_".$this->appid, "time", time() + $expiresIn - TOKEN_REFRESH_BUFFER);
                    写("function_".$this->appid, "Access", $data['access_token']);
                    echo "[" . date('Y-m-d H:i:s') . "] Token 已刷新, 有效期 {$expiresIn}s\n";
                    return $data['access_token'];
                }
                $lastError = "响应无access_token: " . $resp;
            } catch (Exception $e) {
                $lastError = $e->getMessage();
            }

            if ($i < TOKEN_MAX_RETRIES - 1) {
                $delay = TOKEN_RETRY_DELAYS[$i];
                echo "[" . date('Y-m-d H:i:s') . "] Token获取失败, {$delay}s后重试 (" . ($i+1) . "/" . TOKEN_MAX_RETRIES . "): {$lastError}\n";
                sleep($delay);
            }
        }

        throw new Exception("获取Access Token失败 (重试" . TOKEN_MAX_RETRIES . "次): " . $lastError);
    }

    // ==================== User-Agent (对应 Python build_user_agent) ====================

    private function buildUserAgent() {
        // QQ前端据末段 {名称}/{版本} 显示连接客户端
        $runtime = 'PHP/' . PHP_VERSION . '; ' . strtolower(PHP_OS);
        return UA_PLUGIN_TAG . " ({$runtime}; " . UA_CLIENT_NAME . '/' . UA_CLIENT_VERSION . ')';
    }

    // ==================== 网关获取 (对应 Python _get_gateway_url) ====================

    private function getGatewayUrl() {
        // 优先使用自定义地址 (对应 Python _custom_url)
        if (!empty($this->customUrl)) {
            return $this->customUrl;
        }
        $token = $this->getAccessToken();
        $url = "https://api.sgroup.qq.com/gateway/bot";
        $headers = ["Authorization: QQBot {$token}", "Content-Type: application/json"];
        $resp = curl($url, "GET", $headers, '');
        $data = json_decode($resp, true);
        $gw = $data['url'] ?? null;
        if (!$gw) {
            throw new Exception("无法获取Gateway URL: " . $resp);
        }
        return $gw;
    }

    // ==================== 连接 ====================

    private function connect() {
        $gwUrl = $this->getGatewayUrl();
        $gwUrl = str_replace(['wss://', 'ws://'], ['', ''], $gwUrl);
        $parts = parse_url('https://' . $gwUrl);
        $host = $parts['host'] ?? 'gateway.qq.com';
        $path = $parts['path'] ?? '/';

        $port = 443;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        $address = "ssl://{$host}:{$port}";
        $resumeTag = ($this->sessionId && $this->seq !== null) ? " [RESUME模式]" : " [IDENTIFY模式]";
        echo "[" . date('Y-m-d H:i:s') . "] 连接: {$address}{$path}{$resumeTag}\n";

        $this->socket = @stream_socket_client($address, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket) {
            throw new Exception("Socket连接失败: {$errstr} ({$errno})");
        }

        stream_set_timeout($this->socket, 300);

        // WebSocket 握手 (使用与Python一致的User-Agent格式)
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
            $response .= $line;
            if (strpos($response, "\r\n\r\n") !== false) break;
        }

        if (strpos($response, '101') === false) {
            throw new Exception("WebSocket握手失败: " . $response);
        }

        $this->connected = true;
        echo "[" . date('Y-m-d H:i:s') . "] WebSocket 连接成功\n";
    }

    // ==================== 主循环 (对应 Python _handle_connection) ====================

    private function loop() {
        $lastReceive = time();

        while ($this->connected && !feof($this->socket)) {
            // 心跳 (对应 Python _heartbeat_loop)
            if (time() - $this->lastHeartbeat >= $this->heartbeatInterval) {
                $this->sendHeartbeat();
                $this->lastHeartbeat = time();
            }

            // 心跳ACK超时检测
            if ($this->heartbeatAckCount >= HEARTBEAT_ACK_TIMEOUT) {
                throw new Exception("心跳ACK超时 (连续{$this->heartbeatAckCount}次未收到确认)");
            }

            // 读取数据（带超时）
            $frame = $this->readFrame();
            if ($frame === null) {
                if (time() - $lastReceive > $this->heartbeatInterval * HEARTBEAT_ACK_TIMEOUT) {
                    throw new Exception("接收超时");
                }
                continue;
            }

            $lastReceive = time();
            $this->handleMessage($frame);
        }

        if (feof($this->socket)) {
            throw new Exception("连接已断开");
        }
    }

    // ==================== 消息处理 (对应 Python _handle_connection) ====================

    private function handleMessage($data) {
        $payload = json_decode($data, true);
        if (!$payload) return;

        $op = $payload['op'] ?? -1;

        // 保存序列号 (对应 Python: if payload.get('s') is not None: self._seq = payload['s'])
        if (isset($payload['s']) && $payload['s'] !== null) {
            $this->seq = $payload['s'];
        }

        switch ($op) {
            case OP_HELLO:
                // 对应 Python _on_hello: 启动心跳 + 鉴权/恢复
                $this->heartbeatInterval = ($payload['d']['heartbeat_interval'] ?? 45000) / 1000;
                echo "[" . date('Y-m-d H:i:s') . "] 收到HELLO, 心跳间隔: {$this->heartbeatInterval}s\n";
                $this->lastHeartbeat = time() - $this->heartbeatInterval; // 立即发一次心跳
                // 有session_id和seq时尝试RESUME，否则IDENTIFY
                if ($this->sessionId && $this->seq !== null) {
                    $this->sendResume();
                } else {
                    $this->sendIdentify();
                }
                break;

            case OP_DISPATCH:
                // 对应 Python _on_dispatch
                $this->handleDispatch($payload);
                break;

            case OP_HEARTBEAT_ACK:
                // 心跳确认，重置计数
                $this->heartbeatAckCount = 0;
                break;

            case OP_RECONNECT:
                // 对应 Python: 收到重连请求, break
                echo "[" . date('Y-m-d H:i:s') . "] 服务端要求重连\n";
                $this->connected = false;
                break;

            case OP_INVALID_SESSION:
                // 对应 Python: resumable = payload.get('d', False) and self._session_id
                $d = $payload['d'] ?? false;
                if ($d && $this->sessionId) {
                    // 可恢复，保留 session_id 和 seq
                    echo "[" . date('Y-m-d H:i:s') . "] 会话无效但可恢复\n";
                } else {
                    // 不可恢复，清空会话
                    echo "[" . date('Y-m-d H:i:s') . "] 会话无效, 重新鉴权\n";
                    $this->sessionId = null;
                    $this->seq = null;
                }
                $this->gatewayUrl = ''; // 强制重新获取网关
                $this->connected = false;
                sleep(3);
                break;

            default:
                // 未知OP, 忽略
                break;
        }
    }

    // ==================== 事件分发 (对应 Python _on_dispatch) ====================

    private function handleDispatch($payload) {
        $eventType = $payload['t'] ?? '';
        $data = $payload['d'] ?? [];

        // 处理 READY 事件: 保存 session_id (对应 Python: self._session_id = payload.get('d', {}).get('session_id'))
        if ($eventType === 'READY') {
            $this->sessionId = $data['session_id'] ?? null;
            $this->reconnectCount = 0; // 重置重连计数 (对应 Python: self._reconnect_count = 0)
            echo "[" . date('Y-m-d H:i:s') . "] WebSocket 已就绪 (session=" . substr($this->sessionId ?? '', 0, 16) . "...)\n";
            ws_update_status($this->appid, 'connected', 'WebSocket 已连接', ws_get_pid());
            return;
        }

        // 处理 RESUMED 事件: 会话已恢复
        if ($eventType === 'RESUMED') {
            $this->reconnectCount = 0;
            echo "[" . date('Y-m-d H:i:s') . "] 会话已恢复\n";
            ws_update_status($this->appid, 'connected', '会话已恢复', ws_get_pid());
            return;
        }

        echo "[" . date('Y-m-d H:i:s') . "] 收到事件: {$eventType}\n";

        // 互动事件: 立即通过 HTTP PUT /interactions/{id} 确认, 不等待子进程
        // 官方文档: 收到 INTERACTION_CREATE 事件后需调用 PUT /interactions/{interaction_id} 回应
        // 否则客户端会一直 loading 直到超时 (显示"请求第三方失败")
        // 注意: OP 12 (HTTP Callback ACK) 仅用于 webhook 模式, WebSocket 模式不可使用
        // interaction_id 从事件 d.id 字段获取 (非顶层 raw.id)
        if ($eventType === 'INTERACTION_CREATE') {
            $interactionId = $data['id'] ?? '';
            if (!empty($interactionId)) {
                $this->confirmInteraction($interactionId);
            }
        }

        // 后台子进程处理事件 (对应 Python: asyncio.create_task(self._dispatch_with_backpressure(event)))
        $this->dispatchEvent($payload);
    }

    // ==================== 子进程派发 (对应 Python asyncio.create_task) ====================

    private function dispatchEvent($payload) {
        $eventJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $script = escapeshellarg(__DIR__ . '/ws_event_handler.php');
        $appidArg = escapeshellarg($this->appid);
        $dataArg = escapeshellarg($eventJson);

        // 确保日志目录存在
        $logDir = APP_ROOT . 'data';
        if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
        $logFilePath = $logDir . '/ws_event.log';
        $logFileArg = escapeshellarg($logFilePath);

        // 后台执行, 不阻塞WS读取循环 (& 符号使进程异步运行)
        // 对应 Python asyncio.create_task: 事件在独立进程中处理, WS客户端继续读取下一个事件
        // 注意：使用 getPhpBinary() 获取 PHP 路径，兼容 open_basedir + disable_functions
        $phpBin = getPhpBinary();
        if (empty($phpBin) || $phpBin === 'php') {
            $phpBin = PHP_BINARY;
        }

        // 方案1: popen + nohup & (推荐: 非阻塞, 不等待子进程)
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

        // 方案2: shell_exec
        if (function_exists('shell_exec')) {
            $cmd = 'nohup ' . escapeshellarg($phpBin) . " {$script} {$appidArg} {$dataArg}"
                 . " >> {$logFileArg} 2>&1 &";
            @shell_exec($cmd);
            return;
        }

        // 方案3: proc_open + bash -c (不会阻塞, 因为 bash 会退出)
        if (function_exists('proc_open')) {
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $innerCmd = 'nohup ' . escapeshellarg($phpBin) . " {$script} {$appidArg} {$dataArg}"
                       . " >> {$logFileArg} 2>&1 & echo $!";
            $cmd = 'bash -c ' . escapeshellarg($innerCmd);
            $proc = @proc_open($cmd, $descriptorSpec, $pipes);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc); // bash -c 已退出, 不会阻塞
                return;
            }
        }

        // 方案4: exec（最后降级, 可能被禁用）
        if (function_exists('exec')) {
            $cmd = 'nohup ' . escapeshellarg($phpBin) . " {$script} {$appidArg} {$dataArg}"
                 . " >> {$logFileArg} 2>&1 &";
            @exec($cmd);
        }
    }

    // ==================== WS 操作帧 (对应 Python _send_op) ====================

    private function sendIdentify() {
        // 对应 Python _send_identify
        $token = $this->getAccessToken();
        $payload = [
            'op' => OP_IDENTIFY,
            'd' => [
                'token' => "QQBot {$token}",
                'intents' => INTENTS,
                'shard' => [0, 1],
            ]
        ];
        $this->sendFrame(json_encode($payload));
        echo "[" . date('Y-m-d H:i:s') . "] 已发送IDENTIFY\n";
    }

    private function sendResume() {
        // 对应 Python _send_resume: 重连时强制刷新Token
        $token = $this->getAccessToken(true);
        $payload = [
            'op' => OP_RESUME,
            'd' => [
                'token' => "QQBot {$token}",
                'session_id' => $this->sessionId,
                'seq' => $this->seq,
            ]
        ];
        $this->sendFrame(json_encode($payload));
        echo "[" . date('Y-m-d H:i:s') . "] 已发送RESUME (seq={$this->seq})\n";
    }

    private function sendHeartbeat() {
        // 对应 Python _heartbeat_loop: {'op': _OP_HEARTBEAT, 'd': self._seq}
        $payload = [
            'op' => OP_HEARTBEAT,
            'd' => $this->seq
        ];
        $this->sendFrame(json_encode($payload));
        $this->heartbeatAckCount++;
    }

    private function sendEventAck($code = 0) {
        // OP 12 仅用于 HTTP 回调(webhook)模式, WebSocket 模式不应使用
        // 保留此方法以备将来需要, 但当前不调用
        $payload = [
            'op' => OP_EVENT_ACK,
            'code' => $code
        ];
        $this->sendFrame(json_encode($payload));
    }

    /**
     * 立即通过 HTTP PUT /interactions/{id} 确认互动回调
     * 官方文档: PUT /interactions/{interaction_id}, body={"code":0}
     * 必须在收到 INTERACTION_CREATE 事件后尽快调用, 否则客户端超时显示"请求第三方失败"
     * interaction_id 从事件 d.id 获取 (非顶层 raw.id)
     */
    private function confirmInteraction($interactionId) {
        if (empty($interactionId)) return;

        try {
            $token = $this->getAccessToken();
            $urls = [
                '正式' => 'https://api.sgroup.qq.com',
                '沙箱' => 'https://sandbox.api.sgroup.qq.com',
            ];
            $env = isset($urls[$this->env]) ? $this->env : '正式';
            $url = $urls[$env] . '/interactions/' . $interactionId;
            $headers = [
                'Authorization: QQBot ' . $token,
                'Content-Type: application/json',
            ];
            $body = json_encode(['code' => 0]);
            $resp = curl($url, 'PUT', $headers, $body);
            echo "[" . date('Y-m-d H:i:s') . "] 互动回调已确认: {$interactionId} => {$resp}\n";
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] 互动回调确认失败: " . $e->getMessage() . "\n";
        }
    }

    // ==================== WebSocket 帧编解码 ====================

    private function sendFrame($data) {
        if (!$this->socket) return;
        $data = is_string($data) ? $data : json_encode($data);
        $len = strlen($data);
        $frame = chr(0x81); // FIN + text frame

        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $len);
        }

        // 掩码 (客户端发送必须掩码)
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

        if ($masked && strlen($mask) === 4) {
            $unmasked = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $unmasked;
        }

        // 处理close帧
        if ($opcode === 0x08) {
            echo "[" . date('Y-m-d H:i:s') . "] 收到close帧\n";
            $this->connected = false;
            return null;
        }

        // 处理ping帧 -> 回pong
        if ($opcode === 0x09) {
            $this->sendPong($payload);
            return null;
        }

        return $payload;
    }

    private function sendPong($data) {
        if (!$this->socket) return;
        $frame = chr(0x8A); // FIN + pong
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

// ==================== 主入口 ====================

/**
 * 获取当前进程 PID（兼容无 posix 扩展的环境）
 */
function ws_get_pid() {
    if (function_exists('posix_getpid')) {
        return posix_getpid();
    }
    return getmypid();
}

/**
 * 更新数据库中的 WS 守护进程状态
 * 供 admin/api.php 的 ws_status 接口读取
 */
function ws_update_status($appid, $status, $message = '', $pid = 0) {
    try {
        $statusData = db()->getSetting('ws_daemon_status', []);
        if (!is_array($statusData)) $statusData = [];
        $statusData[$appid] = [
            'status' => $status,
            'message' => $message,
            'updated_at' => date('Y-m-d H:i:s'),
            'pid' => $pid ?: ws_get_pid(),
        ];
        db()->setSetting('ws_daemon_status', $statusData);
    } catch (Exception $e) {
        // 数据库更新失败不影响 WS 运行
    }
}

/**
 * 清理 PID 文件并更新所有 bot 状态为已停止
 */
function ws_cleanup() {
    global $wsPidFile, $wsCleanedUp;
    if ($wsCleanedUp) return;
    $wsCleanedUp = true;
    if (!empty($wsPidFile) && file_exists($wsPidFile)) {
        @unlink($wsPidFile);
    }
    try {
        $statusData = db()->getSetting('ws_daemon_status', []);
        if (!is_array($statusData)) $statusData = [];
        foreach ($statusData as $aid => &$s) {
            $s['status'] = 'stopped';
            $s['message'] = '守护进程已退出';
            $s['updated_at'] = date('Y-m-d H:i:s');
            $s['pid'] = 0;
        }
        unset($s);
        db()->setSetting('ws_daemon_status', $statusData);
    } catch (Exception $e) {}
}
$wsCleanedUp = false;

// 写入 PID 文件
$wsPidFile = APP_ROOT . 'data/ws_master.pid';
$wsDataDir = APP_ROOT . 'data';
if (!is_dir($wsDataDir)) {
    @mkdir($wsDataDir, 0777, true);
}
file_put_contents($wsPidFile, (string)ws_get_pid());

// 注册清理函数（正常退出和致命错误时触发）
register_shutdown_function('ws_cleanup');

// 信号处理（SIGTERM/SIGINT 优雅退出）
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() { ws_cleanup(); exit(0); });
    pcntl_signal(SIGINT,  function() { ws_cleanup(); exit(0); });
}

$targetAppid = $argv[1] ?? null;

if ($targetAppid) {
    $bot = getBot($targetAppid);
    if (!$bot) {
        ws_cleanup();
        die("机器人 {$targetAppid} 不存在\n");
    }
    $client = new WebSocketClient($bot['appid'], $bot['secret'], $bot['env'], $bot['ws_url']);
    $client->run();
} else {
    // 连接所有启用了WS的机器人
    $bots = db()->fetchAll("SELECT * FROM bots WHERE ws_enabled = 1 AND enabled = 1");
    if (empty($bots)) {
        ws_cleanup();
        die("没有启用了WebSocket的机器人\n");
    }

    if (count($bots) === 1) {
        // 单个机器人直接运行
        $bot = $bots[0];
        $client = new WebSocketClient($bot['appid'], $bot['secret'], $bot['env'], $bot['ws_url']);
        $client->run();
    } elseif (function_exists('pcntl_fork')) {
        // 多机器人使用子进程并行运行
        foreach ($bots as $bot) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                echo "无法创建子进程，降级为顺序运行\n";
                $client = new WebSocketClient($bot['appid'], $bot['secret'], $bot['env'], $bot['ws_url']);
                $client->run();
            } elseif ($pid == 0) {
                // 子进程
                $client = new WebSocketClient($bot['appid'], $bot['secret'], $bot['env'], $bot['ws_url']);
                $client->run();
                exit(0);
            }
        }

        // 等待所有子进程
        while (pcntl_waitpid(-1, $status) != -1) {
            // 子进程退出后自动重启
        }
    } else {
        // pcntl 不可用，顺序运行（仅第一个机器人）
        echo "警告: pcntl扩展未安装，仅运行第一个机器人。如需多机器人并行，请安装pcntl扩展。\n";
        $bot = $bots[0];
        $client = new WebSocketClient($bot['appid'], $bot['secret'], $bot['env'], $bot['ws_url']);
        $client->run();
    }
}

// 主进程退出时清理
ws_cleanup();
