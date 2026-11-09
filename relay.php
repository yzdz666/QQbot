<?php
/**
 * 统一代发网关 relay.php
 *
 * 适用模式: WebHook (WH) 与 WebSocket (WS) 通用
 *   - WH 部署:  外部脚本 POST 到本文件（或沿用 index.php 入口，两者共用同一实现）
 *   - WS 部署:  ① 外部脚本 POST 到本文件（与 WH 完全一致）
 *               ② 本机脚本/定时任务走 CLI: php relay.php --json '<json>' --auto-sign
 *                  （纯 WS 部署即使不开 Web 服务也能代发；发消息始终走 QQ HTTP API，无需 WS 连接）
 *
 * 签名鉴权 (HMAC-SHA256, 防篡改 + 防重放):
 *   string_to_sign = "{timestamp}\n{nonce}\n{原始请求体}"
 *   sign           = hash_hmac('sha256', string_to_sign, relay_secret)   // 64位hex
 *
 *   传递方式:
 *     HTTP Header:  X-Relay-Timestamp: <Unix秒>
 *                   X-Relay-Nonce:    <随机串, 建议>=16字符>
 *                   X-Relay-Sign:     <64位hex签名>
 *     CLI 参数:     --timestamp <N> --nonce <S> --sign <HEX>
 *                   或 --auto-sign (本机便捷模式, 自动读取密钥并签名)
 *
 *   防重放:
 *     - timestamp 与服务器时间偏差不得超过 ±300 秒
 *     - nonce 在时间窗内仅允许使用一次 (存储于 kv_store, TTL 自动清理)
 *
 * 密钥管理:
 *   - settings 表 relay_secret: 首次使用自动生成 64 位 hex, 后台 admin/api.php action=relay_auth_get 可查看
 *   - settings 表 relay_auth_enabled: 鉴权开关 (默认开启 '1')
 *
 * 请求格式 (与 index.php 代发入口完全一致):
 *   {"type":"代发","op":"send","data":{"appid":"可选","address":"/v2/groups/{openid}/messages","method":"POST","body":{...}}}
 *   {"type":"代发","op":"get","data":{"group":"群ID"}}
 *
 * 响应码:
 *   1    get 成功 (msgid/bind)
 *   -1   get 绑定消息过期
 *   -2   get 参数缺失或未绑定
 *   -3   无可用机器人
 *   -4   address 为空
 *   -9   未知 op
 *   -10  鉴权失败 (msg 说明具体原因)
 *   -11  请求体非法
 *   其余为 QQ 官方 API 原样响应
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__ . '/');
}

require_once(__DIR__ . '/function.php');

// ==================== 密钥与开关 ====================

/**
 * 读取代发签名密钥; 不存在时自动生成 (64位hex)
 */
function relay_get_secret() {
    $secret = db()->getSetting('relay_secret', '');
    if (!is_string($secret) || !preg_match('/^[0-9a-f]{64}$/', $secret)) {
        $secret = bin2hex(random_bytes(32));
        db()->setSetting('relay_secret', $secret);
    }
    return $secret;
}

/**
 * 鉴权开关 (默认开启)
 */
function relay_auth_enabled() {
    $v = db()->getSetting('relay_auth_enabled', '1');
    return !in_array((string)$v, ['0', 'false', 'off'], true);
}

// ==================== 签名与防重放 ====================

const RELAY_TS_WINDOW = 300;   // 时间戳允许偏差 (秒)
const RELAY_NONCE_TTL = 600;   // nonce 保留时长 (秒), >= 2x 时间窗

function relay_sign($timestamp, $nonce, $body, $secret) {
    return hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret);
}

/**
 * nonce 去重: 时间窗内每个 nonce 仅允许一次
 * 返回 true=首次使用, false=重复(重放)
 */
function relay_nonce_consume($nonce, $timestamp) {
    $ns = 'relay_nonce';
    $existing = db()->kvGet($ns, $nonce, null);
    if ($existing !== null) {
        return false;
    }
    db()->kvSet($ns, $nonce, (string)$timestamp);
    // 顺带清理过期 nonce (避免表膨胀)
    try {
        $cutoff = (string)(time() - RELAY_NONCE_TTL);
        db()->execute(
            "DELETE FROM kv_store WHERE namespace = ? AND CAST(value AS INTEGER) < ?",
            [$ns, $cutoff]
        );
    } catch (Exception $e) {
        // 清理失败不影响验证
    }
    return true;
}

/**
 * 校验签名 + 防重放
 * $auth: ['timestamp'=>..., 'nonce'=>..., 'sign'=>...]
 * 返回: ['ok'=>bool, 'msg'=>string]
 */
function relay_verify_auth($rawBody, $auth) {
    $timestamp = (string)($auth['timestamp'] ?? '');
    $nonce     = (string)($auth['nonce'] ?? '');
    $sign      = (string)($auth['sign'] ?? '');

    if ($timestamp === '' || $nonce === '' || $sign === '') {
        return ['ok' => false, 'msg' => '缺少签名参数 (需要 timestamp/nonce/sign)'];
    }
    if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > RELAY_TS_WINDOW) {
        return ['ok' => false, 'msg' => 'timestamp 缺失格式错误或超出±' . RELAY_TS_WINDOW . '秒时间窗'];
    }
    if (strlen($nonce) < 8 || strlen($nonce) > 128) {
        return ['ok' => false, 'msg' => 'nonce 长度需在 8-128 之间'];
    }
    $expect = relay_sign($timestamp, $nonce, $rawBody, relay_get_secret());
    if (!hash_equals($expect, strtolower($sign))) {
        return ['ok' => false, 'msg' => '签名不匹配'];
    }
    if (!relay_nonce_consume($nonce, (int)$timestamp)) {
        return ['ok' => false, 'msg' => 'nonce 已被使用 (疑似重放请求)'];
    }
    return ['ok' => true, 'msg' => 'ok'];
}

// ==================== 代发核心分发 ====================

/**
 * 代发请求分发 (op=get / op=send)
 * $raw: 解析后的请求 JSON 数组
 */
function relay_dispatch(array $raw) {
    $relayOp = $raw['op'] ?? '';
    $data = (isset($raw['data']) && is_array($raw['data'])) ? $raw['data'] : [];

    switch ($relayOp) {
        case 'get':
            $group = (string)($data['group'] ?? '');
            if ($group === '') {
                echo json_encode(['code' => -2], JSON_UNESCAPED_UNICODE);
                return;
            }

            // 查找绑定关系
            $boundGroup = 读($group . "2bind.json", $group, '');
            if ($boundGroup === '') {
                echo json_encode(['code' => -2], JSON_UNESCAPED_UNICODE);
                return;
            }

            $eventMap = 读("官机事件ID.json", $boundGroup, null);
            if (!$eventMap || !isset($eventMap['time'], $eventMap['msgid'])) {
                echo json_encode(['code' => -1], JSON_UNESCAPED_UNICODE);
                return;
            }

            if (time() - (int)$eventMap['time'] > 290) {
                echo json_encode(['code' => -1], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo json_encode([
                'code' => 1,
                'msgid' => $eventMap['msgid'],
                'bind' => $boundGroup
            ], JSON_UNESCAPED_UNICODE);
            return;

        case 'send':
            // 参照官机原版 handleRelay：data.appid 可自定义发信机器人；
            // 未传或未注册时回退到第一个机器人，一个机器人都没有才返回 -3
            $targetAppid = (string)($data['appid'] ?? '');
            $relayBot = $targetAppid !== '' ? getBot($targetAppid) : null;
            if (!$relayBot) {
                if ($targetAppid !== '') {
                    wlog('{"relay_warn":"指定appid未注册,已回退第一个机器人:' . $targetAppid . '"}', null);
                }
                $first = db()->fetchAll("SELECT appid FROM bots ORDER BY rowid LIMIT 1");
                $relayBot = $first ? getBot($first[0]['appid']) : null;
            }
            if (!$relayBot) {
                echo json_encode(['code' => -3, 'msg' => 'appid not found'], JSON_UNESCAPED_UNICODE);
                return;
            }
            // 代发入口不经过 initAppContext，这里按目标机器人初始化 appid/secret/type 常量
            if (!defined('appid'))  define('appid',  $relayBot['appid']);
            if (!defined('secret')) define('secret', $relayBot['secret']);
            if (!defined('type'))   define('type',   $relayBot['env']);

            require_once __DIR__ . "/bot.php";

            $address = (string)($data['address'] ?? '');
            $method  = strtoupper((string)($data['method'] ?? 'POST'));
            $body    = isset($data['body']) ? $data['body'] : [];

            // 安全加固: address 必须以 / 开头, 防止 "@host/" userinfo 注入造成 SSRF
            if ($address === '' || $address[0] !== '/') {
                echo json_encode(['code' => -4, 'msg' => 'address empty or invalid'], JSON_UNESCAPED_UNICODE);
                return;
            }
            // 安全加固: method 白名单, 防止 CRLF/任意字符串注入
            if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], true)) {
                echo json_encode(['code' => -5, 'msg' => 'method not allowed'], JSON_UNESCAPED_UNICODE);
                return;
            }

            wlog(json_encode([
                'relay_send' => [
                    'appid'  => $relayBot['appid'],
                    'address' => $address,
                    'method' => $method,
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $relayBot['appid']);

            echo BOTAPI($address, $method, json_encode($body, JSON_UNESCAPED_UNICODE));
            return;

        default:
            echo json_encode(['code' => -9, 'msg' => 'unknown relay op'], JSON_UNESCAPED_UNICODE);
            return;
    }
}

// ==================== HTTP 入口 ====================

/**
 * 供 index.php 代发分支调用的统一处理函数
 * $raw: 已解析的请求 JSON; $rawBody: 原始请求体字符串
 */
function relay_handle_http($raw, $rawBody) {
    if (relay_auth_enabled()) {
        $auth = [
            'timestamp' => $_SERVER['HTTP_X_RELAY_TIMESTAMP'] ?? '',
            'nonce'     => $_SERVER['HTTP_X_RELAY_NONCE'] ?? '',
            'sign'      => $_SERVER['HTTP_X_RELAY_SIGN'] ?? '',
        ];
        $check = relay_verify_auth($rawBody, $auth);
        if (!$check['ok']) {
            wlog(json_encode(['relay_auth_deny' => $check['msg']], JSON_UNESCAPED_UNICODE), null);
            http_response_code(403);
            echo json_encode(['code' => -10, 'msg' => $check['msg']], JSON_UNESCAPED_UNICODE);
            return;
        }
    } else {
        // 鉴权关闭时记录告警 (安全审计可见)
        wlog('{"relay_warn":"代发鉴权已关闭(relay_auth_enabled=0), 任何人可调用代发"}', null);
    }
    relay_dispatch($raw);
}

// ==================== CLI 入口 ====================

function relay_cli_usage() {
    fwrite(STDERR, <<<EOT
用法:
  php relay.php --json '<代发JSON>' [--auto-sign | --timestamp <N> --nonce <S> --sign <HEX>]
  php relay.php --stdin [--auto-sign | --timestamp <N> --nonce <S> --sign <HEX>]
  echo '<代发JSON>' | php relay.php --stdin --auto-sign

示例 (本机自动签名):
  php relay.php --json '{"type":"代发","op":"send","data":{"appid":"88888888","address":"/v2/groups/xxx/messages","method":"POST","body":{"content":"你好","msg_type":0,"msg_seq":1}}}' --auto-sign

手动签名算法:
  sign = hash_hmac('sha256', "{timestamp}\\n{nonce}\\n{原始JSON}", relay_secret)

EOT);
}

/**
 * 独立运行时的主入口 (HTTP 直接访问 relay.php 或 CLI 调用)
 * 被 index.php include 时不会执行此函数
 */
function relay_gateway_main() {
    // ---------- CLI 模式 ----------
    if (php_sapi_name() === 'cli') {
        global $argv;
        $jsonArg = null; $stdin = false;
        $ts = null; $nonce = null; $sign = null; $autoSign = false;

        for ($i = 1; $i < count($argv); $i++) {
            switch ($argv[$i]) {
                case '--json':     $jsonArg = $argv[++$i] ?? null; break;
                case '--stdin':    $stdin = true; break;
                case '--auto-sign': $autoSign = true; break;
                case '--timestamp': $ts = $argv[++$i] ?? null; break;
                case '--nonce':     $nonce = $argv[++$i] ?? null; break;
                case '--sign':      $sign = $argv[++$i] ?? null; break;
                case '--help': case '-h': relay_cli_usage(); exit(0);
                default:
                    fwrite(STDERR, "未知参数: {$argv[$i]}\n");
                    relay_cli_usage();
                    exit(1);
            }
        }

        if ($jsonArg !== null) {
            $rawBody = $jsonArg;
        } elseif ($stdin) {
            $rawBody = stream_get_contents(STDIN);
        } else {
            relay_cli_usage();
            exit(1);
        }
        $rawBody = (string)$rawBody;

        // 未安装检查 (CLI 下静默失败更友好)
        if (!file_exists(APP_ROOT . 'data/bot.db')) {
            fwrite(STDERR, "错误: 系统未安装 (缺少 data/bot.db)\n");
            exit(1);
        }

        if ($autoSign) {
            $ts = (string)time();
            $nonce = bin2hex(random_bytes(16));
            $sign = relay_sign($ts, $nonce, $rawBody, relay_get_secret());
        }

        $raw = json_decode($rawBody, true);
        if (!is_array($raw)) {
            fwrite(STDERR, json_encode(['code' => -11, 'msg' => '请求体不是合法JSON'], JSON_UNESCAPED_UNICODE) . "\n");
            exit(1);
        }
        if (($raw['type'] ?? '') !== '代发') {
            fwrite(STDERR, json_encode(['code' => -11, 'msg' => 'type 必须为 代发'], JSON_UNESCAPED_UNICODE) . "\n");
            exit(1);
        }

        if (relay_auth_enabled()) {
            $check = relay_verify_auth($rawBody, [
                'timestamp' => $ts, 'nonce' => $nonce, 'sign' => $sign,
            ]);
            if (!$check['ok']) {
                wlog(json_encode(['relay_auth_deny' => $check['msg'], 'mode' => 'cli'], JSON_UNESCAPED_UNICODE), null);
                fwrite(STDERR, json_encode(['code' => -10, 'msg' => $check['msg']], JSON_UNESCAPED_UNICODE) . "\n");
                exit(1);
            }
        } else {
            wlog('{"relay_warn":"代发鉴权已关闭(relay_auth_enabled=0), 任何人可调用代发"}', null);
        }

        relay_dispatch($raw);
        echo "\n";
        exit(0);
    }

    // ---------- HTTP 独立模式 ----------
    date_default_timezone_set('Asia/Shanghai');
    ob_start();

    if (!file_exists(APP_ROOT . 'data/bot.db')) {
        ob_end_clean();
        header('Location: install.php');
        exit;
    }

    $rawBody = file_get_contents('php://input');
    if (empty($rawBody)) {
        wlog('{"relay_error":"收到空请求"}', null);
        ob_end_clean();
        http_response_code(400);
        die(json_encode(['code' => -11, 'msg' => 'empty body'], JSON_UNESCAPED_UNICODE));
    }

    $raw = json_decode($rawBody, true);
    if (!is_array($raw) || ($raw['type'] ?? '') !== '代发') {
        wlog('{"relay_error":"请求不是合法的代发格式"}', null);
        ob_end_clean();
        http_response_code(400);
        die(json_encode(['code' => -11, 'msg' => 'invalid relay request'], JSON_UNESCAPED_UNICODE));
    }

    relay_handle_http($raw, $rawBody);
    exit;
}

// ==================== 入口判定 ====================
// 仅当本文件被直接运行 (CLI 执行 / HTTP 直接访问) 时启动网关;
// 被 index.php include 时不执行, 由 index.php 显式调用 relay_handle_http()

$relayIsMain = false;
if (php_sapi_name() === 'cli') {
    $relayIsMain = isset($argv[0]) && basename($argv[0]) === basename(__FILE__)
        && realpath($argv[0]) === __FILE__;
} elseif (isset($_SERVER['SCRIPT_FILENAME'])) {
    $relayIsMain = realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__;
}
if ($relayIsMain) {
    relay_gateway_main();
}
