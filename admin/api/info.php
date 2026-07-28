<?php
/**
 * 系统信息 API
 * type=list 返回所有机器人列表及其基本信息
 * 使用数据库存储，替代原版 main.json
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/function.php';
require_once dirname(__DIR__, 2) . '/auth.php';

if (!Auth::check()) {
    echo json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$type = $_REQUEST["type"] ?? "";

if (empty($type)) {
    echo json_encode([
        "code" => 400,
        "msg" => "未传入数据"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($type) {
    case "list":
        $bots = getBots();
        $list = [];
        foreach ($bots as $bot) {
            $appid = $bot['appid'];
            $secret = $bot['secret'];
            $environment = $bot['env'] ?? '正式';

            $fh = [
                "appid" => $appid,
                "secret" => $secret,
                "type" => $environment
            ];

            // 优先使用数据库中已缓存的昵称/头像
            $cachedName = trim($bot['nickname'] ?? '');
            $cachedAvatar = trim($bot['avatar'] ?? '');

            $msg = bot_info($appid, $secret);
            $fh["name"] = $msg["username"] ?? ($cachedName ?: $appid);
            $fh["avatar"] = $msg["avatar"] ?? ($cachedAvatar ?: '');
            $fh["data"] = data_statistics($appid);
            $list[] = $fh;
        }
        echo json_encode($list, JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode([
            "code" => 400,
            "msg" => "无效的操作类型"
        ], JSON_UNESCAPED_UNICODE);
}

/**
 * 从数据库统计今日事件数据
 */
function data_statistics($appid) {
    $today = date('Y-m-d');
    $counts = [
        "群聊" => 0,
        "私聊" => 0,
        "加群" => 0,
        "退群" => 0,
        "被删" => 0,
        "添加" => 0,
        "群成员增加" => 0,
        "群成员删除" => 0
    ];

    try {
        // 统计今日消息（从 messages 表）
        $rows = db()->fetchAll(
            "SELECT source_type, COUNT(*) as c FROM messages
             WHERE appid = ? AND date(created_at) = ?
             GROUP BY source_type",
            [$appid, $today]
        );
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $st = $row['source_type'];
                $c = (int)$row['c'];
                switch ($st) {
                    case '群聊':
                        $counts["群聊"] = $c;
                        break;
                    case '私聊':
                        $counts["私聊"] = $c;
                        break;
                    case '加群':
                        $counts["加群"] = $c;
                        break;
                    case '退群':
                        $counts["退群"] = $c;
                        break;
                }
            }
        }

        // 从日志文件统计事件类型（兼容原版统计口径）
        $logFile = dirname(__DIR__, 2) . "/Log/{$appid}/" . $today . ".log";
        if (is_file($logFile)) {
            $content = file_get_contents($logFile);
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $matches)) {
                    $json = $matches[2];
                    if ($json == "重复数据") continue;
                    $data = json_decode($json, true);
                    if (!is_array($data)) continue;
                    $eventType = $data["t"] ?? "";
                    switch ($eventType) {
                        case "GROUP_AT_MESSAGE_CREATE":
                        case "GROUP_MESSAGE_CREATE":
                            $counts["群聊"]++;
                            break;
                        case "C2C_MESSAGE_CREATE":
                            $counts["私聊"]++;
                            break;
                        case "GROUP_ADD_ROBOT":
                            $counts["加群"]++;
                            break;
                        case "GROUP_DEL_ROBOT":
                            $counts["退群"]++;
                            break;
                        case "GROUP_MEMBER_ADD":
                            $counts["群成员增加"]++;
                            break;
                        case "GROUP_MEMBER_REMOVE":
                            $counts["群成员删除"]++;
                            break;
                        case "FRIEND_ADD":
                            $counts["添加"]++;
                            break;
                        case "FRIEND_DEL":
                            $counts["被删"]++;
                            break;
                    }
                }
            }
        }
    } catch (Exception $e) {
        // 静默忽略
    }

    return $counts;
}

/**
 * 调用 QQ 开放平台 API 获取机器人信息
 */
function bot_info($appid, $secret) {
    $url = "https://bots.qq.com/app/getAppAccessToken";
    $json = json_encode(["appId" => (string)$appid, "clientSecret" => $secret]);
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $json,
            'ignore_errors' => true,
            'timeout' => 10
        ]
    ];
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    $fw = json_decode($response, true);
    if (!is_array($fw) || empty($fw["access_token"])) {
        return ["username" => $appid, "avatar" => ""];
    }
    $Access = $fw["access_token"];

    // 沙箱使用 sandbox，正式使用正式接口
    $bot = getBot($appid);
    $apiBase = ($bot && ($bot['env'] ?? '') == '沙箱')
        ? "https://sandbox.api.sgroup.qq.com"
        : "https://api.sgroup.qq.com";

    $url = $apiBase . "/users/@me";
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: QQBot " . $Access . "\r\n" .
                        "Content-Type: application/json\r\n",
            'ignore_errors' => true,
            'timeout' => 10
        ]
    ];
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    $info = json_decode($response, true);
    if (!is_array($info)) {
        return ["username" => $appid, "avatar" => ""];
    }
    return $info;
}
