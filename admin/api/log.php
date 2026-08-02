<?php
header('Content-Type: application/json');

$type = $_REQUEST["type"] ?? "";
$appid = $_REQUEST["appid"] ?? "";
$name = $_REQUEST["name"] ?? date("Y-m-d").".log";
$path = dirname(__DIR__, 2)."/Log/{$appid}/".$name;

switch ($type) {
    case "list":
        $dir = glob(dirname(__DIR__, 2)."/Log/{$appid}/*.log");
        $logs = [];
        foreach($dir as $va) {
            $logs[] = basename($va);
        }
        rsort($logs);
        echo json_encode([
            "code" => 200,
            "list" => $logs
        ], JSON_UNESCAPED_UNICODE);
        break;
        
    case "delete":
        if (!is_file($path)) {
            echo json_encode([
                "code" => 400,
                "msg" => "日志不存在"
            ], JSON_UNESCAPED_UNICODE);
        } else {
            if (unlink($path)) {
                echo json_encode([
                    "code" => 200,
                    "msg" => "删除成功"
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    "code" => 500,
                    "msg" => "删除失败"
                ], JSON_UNESCAPED_UNICODE);
            }
        }
        break;
        
    case "read":
        if (!is_file($path)) {
            echo json_encode([
                "code" => 404,
                "msg" => "日志文件不存在"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $content = file_get_contents($path);
        if (empty($content)) {
            echo json_encode([
                "code" => 200,
                "list" => []
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $content = explode("\n", $content);
        $result = [];
        foreach ($content as $value) {
            if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $value, $matches)) {
                $time = $matches[1];
                $json = $matches[2];
                if ($json == "重复数据") {
                    continue;
                } else {
                    $res = [
                        "time" => $time,
                        "raw" => $json,
                        "summary" => event($json)
                    ];
                    array_unshift($result, $res);
                }
            }
        }
        echo json_encode([
            "code" => 200,
            "list" => $result
        ], JSON_UNESCAPED_UNICODE);
        break;
        
    default:
        echo json_encode([
            "code" => 400,
            "msg" => "无效的请求类型"
        ], JSON_UNESCAPED_UNICODE);
}

function event($json) {
    $json = json_decode($json, true);
    if (!is_array($json)) {
        return "无效";
    }
    
    // 处理机器人主动发送的记录
    if (isset($json["direction"]) && $json["direction"] == "发送") {
        $action = $json["action"] ?? "";
        $content = $json["content"] ?? "";
        if ($action == "发送MD") {
            $preview = mb_substr($content, 0, 30);
            return "[发送MD] " . $preview . (mb_strlen($content) > 30 ? "..." : "");
        }
        return "[发送] " . ($json["content_type"] ?? "消息");
    }
    
    $t = $json["t"] ?? "";
    switch ($t) {
        case "GROUP_AT_MESSAGE_CREATE":
        case "GROUP_MESSAGE_CREATE":
            $content = $json["d"]["content"] ?? "";
            $content = preg_replace('/<@[A-F0-9]+>\s*/', '', $content);
            $content = preg_replace('/<faceType=\d+,faceId="\d+",ext="[^"]*">/', '', $content);
            return trim($content) ?: "[图片/表情/文件]";
            break;
        case "C2C_MESSAGE_CREATE":
            $content = $json["d"]["content"] ?? "";
            $content = preg_replace('/<@[A-F0-9]+>\s*/', '', $content);
            return trim($content) ?: "[图片/表情]";
            break;
        case "GROUP_ADD_ROBOT":
            return "🤖 被邀进群";
            break;
        case "GROUP_DEL_ROBOT":
            return "🚪 被踢出群";
            break;
        case "GROUP_MEMBER_ADD":
            $userId = $json["d"]["user_id"] ?? $json["d"]["openid"] ?? "未知成员";
            $operator = $json["d"]["operator_id"] ?? $json["d"]["op_user_id"] ?? "未知";
            return "👤 群成员增加 (用户: {$userId}, 操作者: {$operator})";
            break;
        case "GROUP_MEMBER_REMOVE":
            $userId = $json["d"]["user_id"] ?? $json["d"]["openid"] ?? "未知成员";
            $operator = $json["d"]["operator_id"] ?? $json["d"]["op_user_id"] ?? "未知";
            return "🚫 群成员删除 (用户: {$userId}, 操作者: {$operator})";
            break;
        case "FRIEND_ADD":
            return "➕ 添加好友";
            break;
        case "FRIEND_DEL":
            return "➖ 删除好友";
            break;
        case "INTERACTION_CREATE":
            $type = $json["d"]["type"] ?? "";
            if ($type == 20) {
                return "按钮/指令交互";
            }
            return "交互事件";
            break;
        case "MESSAGE_CREATE":
            $content = $json["d"]["content"] ?? "";
            return trim($content) ?: "[图片/表情]";
            break;
        default:
            if (isset($json["d"]["content"])) {
                $content = $json["d"]["content"];
                $content = preg_replace('/<@[A-F0-9]+>\s*/', '', $content);
                return trim($content) ?: "[消息]";
            }
            return "未知事件: " . $t;
    }
}