<?php
ob_start();
date_default_timezone_set('Asia/Shanghai');

require_once(__DIR__ . "/function.php");

// ==================== 检查是否已安装 ====================
if (!file_exists(APP_ROOT . "data/bot.db")) {
    ob_end_clean();
    header('Location: install.php');
    exit;
}

// ==================== 获取原始请求数据 ====================
$rawText = file_get_contents("php://input");
if (empty($rawText)) {
    wlog('{"plat_error":"收到未知请求,元数据为空已阻拦"}', null);
    ob_end_clean();
    die("Request error");
}

$raw = json_decode($rawText, true);
if (!is_array($raw)) {
    wlog('{"plat_error":"JSON解析失败"}', null);
    ob_end_clean();
    die("JSON error");
}

// ==================== 兼容代发入口 ====================
if (($raw['type'] ?? '') === '代发') {
    handleRelay($raw);
    exit;
}

// ==================== 获取 appid ====================
$appid = $_SERVER["HTTP_X_BOT_APPID"] ?? "";
if (empty($appid)) {
    wlog('{"plat_error":"收到非官方请求(无appid),已阻拦"}', null);
    ob_end_clean();
    die("Appid error");
}

// 从数据库获取机器人配置
$bot = getBot($appid);
if (!$bot) {
    wlog('{"plat_error":"appid未注册: ' . $appid . '}",' . null);
    ob_end_clean();
    die("Appid not found");
}

// ==================== 初始化全局常量 ====================
initAppContext($bot);

// ==================== sodium 扩展检查 ====================
if (!function_exists('sodium_crypto_sign_seed_keypair') || !extension_loaded('sodium')) {
    wlog('{"plat_error":"未安装或未加载sodium拓展"}', appid);
    ob_end_clean();
    die("sodium error");
}

// ==================== 签名验证 (op=13) ====================
$op = $raw["op"] ?? null;

if ($op == 13) {
    sign($raw, secret);
    exit;
}

// ==================== 事件处理 (op=0) ====================
if ($op == 0) {
    // 原版用 $raw["id"] 作为事件ID（顶层id）
    $event_id = $raw["id"] ?? '';
    if ($event_id === '') {
        ob_end_clean();
        die("event error");
    }

    // 事件去重
    if (isEventProcessed($event_id)) {
        wlog('{"plat_error":"元数据重复上传"}', appid);
        ob_end_clean();
        die("error");
    }
    markEventProcessed($event_id, appid);

    // 记录原始事件
    wlog(json_encode($raw, JSON_UNESCAPED_UNICODE), appid);

    // 定义 raw 常量（与原版一致：在 Main 调用前定义，互动私聊等函数需要）
    if (!defined('raw')) define("raw", $raw);

    // 处理事件（传入完整 raw）
    Main($raw);
}

ob_end_clean();

// ==================== 初始化应用上下文 ====================
function initAppContext($bot) {
    if (!defined('appid'))  define("appid",  $bot['appid']);
    if (!defined('secret')) define("secret", $bot['secret']);
    if (!defined('type'))   define("type",   $bot['env']);

    // 获取插件启用状态（与原版一致：插件文件存在且未被显式禁用即为启用）
    $pluginConfig = [];

    // 扫描 plugin 目录中的所有 .php 文件
    $pluginDir = APP_ROOT . "plugin/";
    $pluginFiles = is_dir($pluginDir) ? glob($pluginDir . "*.php") : [];

    // 获取该 appid 下所有被显式禁用的插件
    $disabledPlugins = [];
    $allStatus = db()->fetchAll("SELECT plugin_name, enabled FROM plugin_status WHERE appid = ?", [$bot['appid']]);
    foreach ($allStatus as $row) {
        if (intval($row['enabled']) === 0) {
            $disabledPlugins[$row['plugin_name']] = true;
        }
    }

    // 默认启用所有存在的插件，除非被显式禁用
    foreach ($pluginFiles as $file) {
        $pluginName = basename($file, ".php");
        if (!isset($disabledPlugins[$pluginName])) {
            $pluginConfig[$pluginName] = true;
        }
    }

    if (!defined('plugin')) define("plugin", $pluginConfig);
}

// ==================== 代发处理 ====================
function handleRelay(array $raw) {
    $relayOp = $raw['op'] ?? '';
    $data = $raw['data'] ?? [];

    switch ($relayOp) {
        case 'get':
            $group = $data['group'] ?? '';
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
            $targetAppid = (string)($data['appid'] ?? '');
            if ($targetAppid === '') {
                $targetAppid = appid;
            }
            if ($targetAppid === '' || !defined('appid')) {
                echo json_encode(['code' => -3, 'msg' => 'appid not found'], JSON_UNESCAPED_UNICODE);
                return;
            }

            require_once __DIR__ . "/bot.php";

            $address = $data['address'] ?? '';
            $method = $data['method'] ?? 'POST';
            $body = $data['body'] ?? [];

            if ($address === '') {
                echo json_encode(['code' => -4, 'msg' => 'address empty'], JSON_UNESCAPED_UNICODE);
                return;
            }

            $allowedPrefixes = ['/v2/groups/', '/v2/users/', '/v2/guilds/', '/users/@me'];
            $isAllowed = false;
            foreach ($allowedPrefixes as $prefix) {
                if (strpos($address, $prefix) === 0) {
                    $isAllowed = true;
                    break;
                }
            }
            if (!$isAllowed) {
                echo json_encode(['code' => 403, 'msg' => '不被允许的API路径'], JSON_UNESCAPED_UNICODE);
                return;
            }
            if (strpos($address, '://') !== false) {
                echo json_encode(['code' => 403, 'msg' => '不允许使用完整URL'], JSON_UNESCAPED_UNICODE);
                return;
            }

            echo BOTAPI($address, $method, json_encode($body, JSON_UNESCAPED_UNICODE));
            return;

        default:
            echo json_encode(['code' => -9, 'msg' => 'unknown relay op'], JSON_UNESCAPED_UNICODE);
            return;
    }
}

// ==================== 主处理函数（与ws_event_handler.php对齐，参照ElainaBot_v2） ====================
function Main($raw) {
    $event = $raw["t"] ?? '';

    switch ($event) {
        case "GROUP_AT_MESSAGE_CREATE":
        case "GROUP_MESSAGE_CREATE":
            define("消息来源", "群聊");
            define("消息ID", $raw["d"]["id"] ?? '');
            // 内容清洗: 去首尾空白 + 去/前缀 + 去@前缀 (参照 Python MessageUtils.sanitize_content)
            $content = trim($raw["d"]["content"] ?? '', "/ ");
            $content = preg_replace('/<@!?[A-Za-z0-9]+>/', '', $content);
            $content = trim($content);
            define("消息", $content);
            // 参照 Python base.py + ws_event_handler.php: group_openid 优先, group_id 兜底
            define("来源", $raw["d"]["group_openid"] ?? ($raw["d"]["group_id"] ?? ''));
            // 参照 ws_event_handler.php: member_openid 优先, id 兜底
            define("用户", $raw["d"]["author"]["member_openid"] ?? ($raw["d"]["author"]["id"] ?? ''));
            break;

        case "C2C_MESSAGE_CREATE":
            define("消息来源", "私聊");
            define("消息ID", $raw["d"]["id"] ?? '');
            $content = trim($raw["d"]["content"] ?? '', "/ ");
            define("消息", $content);
            define("来源", $raw["d"]["author"]["user_openid"] ?? ($raw["d"]["author"]["id"] ?? ''));
            define("用户", $raw["d"]["author"]["user_openid"] ?? ($raw["d"]["author"]["id"] ?? ''));
            break;

        case "GROUP_ADD_ROBOT":
            // 参照 Python GroupAddRobotParser: op_member_openid
            define("消息来源", "加群");
            define("事件ID", $raw["id"] ?? '');
            define("消息", "[加群]");
            define("来源", $raw["d"]["group_openid"] ?? '');
            define("用户", $raw["d"]["op_member_openid"] ?? '');
            break;

        case "GROUP_DEL_ROBOT":
            // 参照 Python GroupDelRobotParser: op_member_openid
            define("消息来源", "退群");
            define("事件ID", $raw["id"] ?? '');
            define("消息", "[退群]");
            define("来源", $raw["d"]["group_openid"] ?? '');
            define("用户", $raw["d"]["op_member_openid"] ?? '');
            break;

        case "INTERACTION_CREATE":
            // 参照 Python InteractionParser: 根据 chat_type/scene 判断群聊/私聊
            define("消息来源", "互动");
            define("事件ID", $raw["id"] ?? '');
            $chatType = $raw["d"]["chat_type"] ?? null;
            $scene = $raw["d"]["scene"] ?? '';
            if ($chatType === 1 || $scene === "group") {
                define("来源", $raw["d"]["group_openid"] ?? ($raw["d"]["group_id"] ?? ''));
                define("用户", $raw["d"]["group_member_openid"] ?? ($raw["d"]["author"]["id"] ?? ''));
            } elseif ($chatType === 2 || $scene === "c2c") {
                define("来源", $raw["d"]["user_openid"] ?? '');
                define("用户", $raw["d"]["user_openid"] ?? ($raw["d"]["author"]["id"] ?? ''));
            } else {
                $gid = $raw["d"]["group_openid"] ?? ($raw["d"]["group_id"] ?? '');
                define("来源", $gid);
                define("用户", $raw["d"]["group_member_openid"] ?? ($raw["d"]["user_openid"] ?? ($raw["d"]["author"]["id"] ?? '')));
            }
            // 互动内容: 参照 Python InteractionParser -> resolved.button_data
            $buttonData = $raw["d"]["data"]["resolved"]["button_data"] ?? '';
            define("消息", $buttonData ?: "[互动]");
            break;

        case "GROUP_MEMBER_ADD":
            // 参照 Python GroupMemberAddParser: member_openid
            define("消息来源", "群成员增加");
            define("事件ID", $raw["id"] ?? '');
            define("消息", "[群成员增加]");
            define("来源", $raw["d"]["group_openid"] ?? '');
            define("用户", $raw["d"]["member_openid"] ?? '');
            break;

        case "GROUP_MEMBER_REMOVE":
            // 参照 Python GroupMemberRemoveParser: member_openid
            define("消息来源", "群成员移除");
            define("事件ID", $raw["id"] ?? '');
            define("消息", "[群成员移除]");
            define("来源", $raw["d"]["group_openid"] ?? '');
            define("用户", $raw["d"]["member_openid"] ?? '');
            break;

        default:
            return;
    }

    // raw 已在 Main 调用前定义（与原版一致），无需重复定义

    // 记录到数据库（增强功能）
    recordIncomingMessage($event, $raw);

    // 按钮互动需要快速 ACK
    if (($raw["t"] ?? "") === "INTERACTION_CREATE" && function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }

    // 加载bot.php和插件（与原版一致：在Main内加载）
    require __DIR__ . "/bot.php";
    load_plugin();
    exit;
}

// ==================== 记录接收消息到数据库（增强功能） ====================
function recordIncomingMessage($eventType, $raw) {
    $d = $raw["d"] ?? [];
    $content = '';
    $targetId = '';
    $userId = '';
    $sourceType = '';

    switch ($eventType) {
        case 'GROUP_AT_MESSAGE_CREATE':
        case 'GROUP_MESSAGE_CREATE':
            $sourceType = '群聊';
            $content = $d['content'] ?? '';
            // 参照 ws_event_handler.php: group_openid 优先, group_id 兜底
            $targetId = $d['group_openid'] ?? ($d['group_id'] ?? '');
            // 参照 ws_event_handler.php: member_openid 优先, id 兜底
            $userId = $d['author']['member_openid'] ?? ($d['author']['id'] ?? '');
            break;
        case 'C2C_MESSAGE_CREATE':
            $sourceType = '私聊';
            $content = $d['content'] ?? '';
            $targetId = $d['author']['user_openid'] ?? ($d['author']['id'] ?? '');
            $userId = $d['author']['user_openid'] ?? ($d['author']['id'] ?? '');
            break;
        case 'INTERACTION_CREATE':
            $sourceType = '互动';
            // 参照 Python InteractionParser: 根据 chat_type/scene 判断
            $chatType = $d['chat_type'] ?? null;
            $scene = $d['scene'] ?? '';
            if ($chatType === 1 || $scene === 'group') {
                $targetId = $d['group_openid'] ?? ($d['group_id'] ?? '');
                $userId = $d['group_member_openid'] ?? ($d['author']['id'] ?? '');
            } elseif ($chatType === 2 || $scene === 'c2c') {
                $targetId = $d['user_openid'] ?? '';
                $userId = $d['user_openid'] ?? ($d['author']['id'] ?? '');
            } else {
                $targetId = $d['group_openid'] ?? ($d['group_id'] ?? '');
                $userId = $d['group_member_openid'] ?? ($d['user_openid'] ?? ($d['author']['id'] ?? ''));
            }
            break;
        case 'GROUP_ADD_ROBOT':
            $sourceType = '加群';
            $targetId = $d['group_openid'] ?? '';
            break;
        case 'GROUP_DEL_ROBOT':
            $sourceType = '退群';
            $targetId = $d['group_openid'] ?? '';
            break;
        case 'GROUP_MEMBER_ADD':
            $sourceType = '群成员增加';
            $targetId = $d['group_openid'] ?? '';
            $userId = $d['member_openid'] ?? '';
            break;
        case 'GROUP_MEMBER_REMOVE':
            $sourceType = '群成员移除';
            $targetId = $d['group_openid'] ?? '';
            $userId = $d['member_openid'] ?? '';
            break;
    }

    // 记录用户和群组（含昵称）
    if ($userId && defined('appid')) {
        // 参照 ws_event_handler.php: 优先使用 author.username
        $nickname = $d['author']['username'] ?? '';
        recordUser(appid, $userId, $nickname);
    }
    if ($targetId && in_array($sourceType, ['群聊', '加群', '退群', '群成员增加', '群成员移除', '互动']) && defined('appid')) recordGroup(appid, $targetId);

    // 解析附件，正确识别图片/视频/语音/文件类型和URL
    $parsedMsg = parseMessageAttachment($raw);
    // 如果有附件内容则用解析结果，否则用原始文字内容
    $logContent = !empty($parsedMsg['content']) ? $parsedMsg['content'] : $content;
    $logContentType = $parsedMsg['content_type'];

    // 记录消息
    if (defined('appid')) {
        $msgId = $d['id'] ?? '';
        $isBotMsg = !empty($d['author']['bot']);
        
        // 如果是机器人自己发送的消息（webhook回传），更新已有的发送记录，不创建重复的接收记录
        if ($isBotMsg && $msgId) {
            $existing = db()->fetch(
                "SELECT id FROM messages WHERE appid = ? AND message_id = ? AND direction = '发送' LIMIT 1",
                [appid, $msgId]
            );
            if ($existing) {
                // 更新已有发送记录的 raw_data 和 content（补充附件信息）
                db()->execute(
                    "UPDATE messages SET raw_data = ?, content = ?, content_type = ? WHERE id = ?",
                    [json_encode($raw, JSON_UNESCAPED_UNICODE), $logContent, $logContentType, $existing['id']]
                );
                return;
            }
        }
        
        logMessage(appid, '接收', $sourceType, $targetId, $logContentType, $logContent, $msgId, $userId, json_encode($raw, JSON_UNESCAPED_UNICODE));
    }
}

// ==================== 加载插件 ====================
function load_plugin() {
    $pluginDir = APP_ROOT . "plugin/";
    if (!is_dir($pluginDir)) return;

    $All = glob($pluginDir . "*.php");
    foreach ($All as $name) {
        $plugin_name = basename($name, ".php");
        if (defined('plugin') && is_array(plugin) && isset(plugin[$plugin_name]) && plugin[$plugin_name]) {
            try {
                require_once($name);
            } catch (Throwable $e) {
                $error = json_encode([
                    "plat_error" => "[{$name}]运行出错: " . $e->getMessage() . " 行数:" . $e->getLine()
                ], JSON_UNESCAPED_UNICODE);
                wlog($error, defined('appid') ? appid : null);
                continue;
            }
        }
    }
}
