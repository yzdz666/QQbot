<?php
// 聊天记录API接口 - 完整版
// 支持：会话列表、消息记录、发送消息、撤回消息、获取昵称
// 引用功能使用 msg_idx (REFIDX_xxxx) 作为标识
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ERROR);
ini_set('display_errors', 0);

require_once dirname(__DIR__, 2) . '/auth.php';
Auth::init();
if (!Auth::check()) {
    echo json_encode(["code" => 401, "msg" => "未登录或会话已过期"], JSON_UNESCAPED_UNICODE);
    exit;
}

$type = $_REQUEST["type"] ?? "";
$appid = $_REQUEST["appid"] ?? "";
$name = $_REQUEST["name"] ?? date("Y-m-d") . ".log";

$appid = preg_replace('/[^a-zA-Z0-9_]/', '', $appid);
if (empty($appid)) {
    echo json_encode(["code" => 400, "msg" => "无效的appid"], JSON_UNESCAPED_UNICODE);
    exit;
}

$name = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $name);
if (empty($name) || strpos($name, '..') !== false) {
    $name = date("Y-m-d") . ".log";
}

$logBase = dirname(__DIR__, 2) . "/Log/";
$path = $logBase . $appid . "/" . $name;

$realPath = realpath(dirname($path));
$realLogBase = realpath($logBase);
if ($realPath === false || $realLogBase === false || strpos($realPath, $realLogBase) !== 0) {
    echo json_encode(["code" => 403, "msg" => "路径非法"], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================== 辅助函数 ====================
function extractMsgIdx($extStr) {
    if (empty($extStr)) return '';
    if (preg_match('/msg_idx=([^&]+)/', $extStr, $m)) {
        return $m[1];
    }
    return '';
}

function getAccessToken($appid, $secret) {
    $url = "https://bots.qq.com/app/getAppAccessToken";
    $data = json_encode(["appId" => (string)$appid, "clientSecret" => $secret]);
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $data,
            'ignore_errors' => true,
            'timeout' => 10
        ]
    ];
    $resp = file_get_contents($url, false, stream_context_create($opts));
    $arr = json_decode($resp, true);
    return $arr['access_token'] ?? '';
}

function sendApiRequest($url, $method, $accessToken, $body = null) {
    $ch = curl_init();
    $headers = ["Authorization: QQBot {$accessToken}"];
    if ($body) {
        $headers[] = "Content-Type: application/json";
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    if ($body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => $resp];
}

function getLatestMessageId($appid, $chatType, $chatId) {
    $logDir = dirname(__DIR__, 2) . "/Log/{$appid}/";
    if (!is_dir($logDir)) return '';
    $files = glob($logDir . "*.log");
    if (empty($files)) return '';
    usort($files, function ($a, $b) {
        return strcmp(basename($b), basename($a));
    });
    $latestLog = $files[0];
    $content = file_get_contents($latestLog);
    $lines = explode("\n", $content);
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim($lines[$i]);
        if (empty($line) || $line == "重复数据") continue;
        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $matches)) {
            $data = json_decode($matches[2], true);
            if (!$data) continue;
            $eventType = $data['t'] ?? '';
            if ($chatType == 'group' && ($eventType == 'GROUP_AT_MESSAGE_CREATE' || $eventType == 'GROUP_MESSAGE_CREATE')) {
                if (($data['d']['group_openid'] ?? '') == $chatId) {
                    $mid = $data['d']['id'] ?? '';
                    if ($mid) return $mid;
                }
            } elseif ($chatType == 'private' && $eventType == 'C2C_MESSAGE_CREATE') {
                if (($data['d']['author']['id'] ?? '') == $chatId) {
                    $mid = $data['d']['id'] ?? '';
                    if ($mid) return $mid;
                }
            }
        }
    }
    return '';
}

// ==================== 主逻辑 ====================
switch ($type) {
    case "list":
        if (!is_file($path)) {
            echo json_encode(["code" => 200, "groups" => [], "privates" => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $content = file_get_contents($path);
        $lines = explode("\n", $content);
        $groups = [];
        $privates = [];
        foreach ($lines as $line) {
            if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $matches)) {
                $time = $matches[1];
                $json = $matches[2];
                if ($json == "重复数据") continue;
                $data = json_decode($json, true);
                if (!is_array($data)) continue;
                $eventType = $data["t"] ?? "";
                
                if ($eventType == "GROUP_AT_MESSAGE_CREATE" || $eventType == "GROUP_MESSAGE_CREATE") {
                    $groupId = $data["d"]["group_openid"] ?? $data["d"]["group_id"] ?? "";
                    if ($groupId) {
                        if (!isset($groups[$groupId])) {
                            $groups[$groupId] = ["id" => $groupId, "type" => "group", "last_message_time" => $time, "last_message" => "", "message_count" => 0];
                        }
                        $groups[$groupId]["message_count"]++;
                        if (strtotime($time) > strtotime($groups[$groupId]["last_message_time"])) {
                            $groups[$groupId]["last_message_time"] = $time;
                            $groups[$groupId]["last_message"] = trim($data["d"]["content"] ?? "", "/ ");
                        }
                    }
                }
                elseif ($eventType == "C2C_MESSAGE_CREATE") {
                    $userId = $data["d"]["author"]["id"] ?? "";
                    if ($userId) {
                        if (!isset($privates[$userId])) {
                            $privates[$userId] = ["id" => $userId, "type" => "private", "last_message_time" => $time, "last_message" => "", "message_count" => 0];
                        }
                        $privates[$userId]["message_count"]++;
                        if (strtotime($time) > strtotime($privates[$userId]["last_message_time"])) {
                            $privates[$userId]["last_message_time"] = $time;
                            $privates[$userId]["last_message"] = trim($data["d"]["content"] ?? "", "/ ");
                        }
                    }
                }
                elseif (isset($data["direction"]) && $data["direction"] === "发送") {
                    $sourceType = $data["source_type"] ?? "";
                    $targetId = $data["target_id"] ?? "";
                    if ($sourceType === "群聊" && $targetId) {
                        if (!isset($groups[$targetId])) {
                            $groups[$targetId] = ["id" => $targetId, "type" => "group", "last_message_time" => $time, "last_message" => $data["content"] ?? "", "message_count" => 0];
                        }
                        $groups[$targetId]["message_count"]++;
                        if (strtotime($time) > strtotime($groups[$targetId]["last_message_time"])) {
                            $groups[$targetId]["last_message_time"] = $time;
                            $groups[$targetId]["last_message"] = $data["content"] ?? "";
                        }
                    } elseif ($sourceType === "私聊" && $targetId) {
                        if (!isset($privates[$targetId])) {
                            $privates[$targetId] = ["id" => $targetId, "type" => "private", "last_message_time" => $time, "last_message" => $data["content"] ?? "", "message_count" => 0];
                        }
                        $privates[$targetId]["message_count"]++;
                        if (strtotime($time) > strtotime($privates[$targetId]["last_message_time"])) {
                            $privates[$targetId]["last_message_time"] = $time;
                            $privates[$targetId]["last_message"] = $data["content"] ?? "";
                        }
                    }
                }
                elseif ($eventType == "BOT_MESSAGE") {
                    $botData = $data["d"] ?? [];
                    $source = $botData["source"] ?? "";
                    $target = $botData["target"] ?? "";
                    if ($source == "群聊" && $target) {
                        if (!isset($groups[$target])) {
                            $groups[$target] = ["id" => $target, "type" => "group", "last_message_time" => $time, "last_message" => $botData["content"] ?? "", "message_count" => 0];
                        }
                        $groups[$target]["message_count"]++;
                        if (strtotime($time) > strtotime($groups[$target]["last_message_time"])) {
                            $groups[$target]["last_message_time"] = $time;
                            $groups[$target]["last_message"] = $botData["content"] ?? "";
                        }
                    } elseif ($source == "私聊" && $target) {
                        if (!isset($privates[$target])) {
                            $privates[$target] = ["id" => $target, "type" => "private", "last_message_time" => $time, "last_message" => $botData["content"] ?? "", "message_count" => 0];
                        }
                        $privates[$target]["message_count"]++;
                        if (strtotime($time) > strtotime($privates[$target]["last_message_time"])) {
                            $privates[$target]["last_message_time"] = $time;
                            $privates[$target]["last_message"] = $botData["content"] ?? "";
                        }
                    }
                }
            }
        }
        $groupsList = array_values($groups);
        $privatesList = array_values($privates);
        usort($groupsList, fn($a, $b) => strtotime($b["last_message_time"]) - strtotime($a["last_message_time"]));
        usort($privatesList, fn($a, $b) => strtotime($b["last_message_time"]) - strtotime($a["last_message_time"]));
        echo json_encode(["code" => 200, "groups" => $groupsList, "privates" => $privatesList], JSON_UNESCAPED_UNICODE);
        break;

    case "messages":
        $chatType = $_REQUEST["chat_type"] ?? "";
        $chatId = $_REQUEST["chat_id"] ?? "";
        if (empty($chatType) || empty($chatId)) {
            echo json_encode(["code" => 400, "msg" => "缺少必要参数"], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!is_file($path)) {
            echo json_encode(["code" => 404, "msg" => "日志文件不存在"], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $content = file_get_contents($path);
        $lines = explode("\n", $content);
        $messages = [];
        
        foreach ($lines as $line) {
            if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $matches)) {
                $time = $matches[1];
                $json = $matches[2];
                if ($json == "重复数据") continue;
                $data = json_decode($json, true);
                if (!is_array($data)) continue;
                $eventType = $data["t"] ?? "";
                $message = null;

                // ========== 群聊用户消息 ==========
                if ($chatType == "group" && ($eventType == "GROUP_AT_MESSAGE_CREATE" || $eventType == "GROUP_MESSAGE_CREATE")) {
                    $groupId = $data["d"]["group_openid"] ?? $data["d"]["group_id"] ?? "";
                    if ($groupId == $chatId) {
                        $attachments = $data["d"]["attachments"] ?? [];
                        $imageUrls = [];
                        $videoUrl = null;
                        $voiceUrl = null;
                        $voiceWavUrl = null;
                        foreach ($attachments as $att) {
                            $contentType = $att["content_type"] ?? "";
                            if (strpos($contentType, "image/") === 0) {
                                $imageUrls[] = $att["url"];
                            } elseif (strpos($contentType, "video/") === 0) {
                                $videoUrl = $att["url"];
                            } elseif ($contentType == "voice") {
                                $voiceUrl = $att["url"];
                                $voiceWavUrl = $att["voice_wav_url"] ?? null;
                            }
                        }
                        
                        $msgIdx = "";
                        if (isset($data["d"]["message_scene"]["ext"][0])) {
                            $msgIdx = extractMsgIdx($data["d"]["message_scene"]["ext"][0]);
                        }
                        
                        $message = [
                            "time" => $time,
                            "type" => "user",
                            "user_id" => $data["d"]["author"]["id"] ?? "",
                            "username" => $data["d"]["author"]["username"] ?? "用户",
                            "content" => trim($data["d"]["content"] ?? "", "/ "),
                            "message_id" => $data["d"]["id"] ?? "",
                            "msg_idx" => $msgIdx,
                            "attachments" => $attachments,
                            "image_urls" => $imageUrls,
                            "video_url" => $videoUrl,
                            "voice_url" => $voiceUrl,
                            "voice_wav_url" => $voiceWavUrl,
                            "can_quote" => true
                        ];
                    }
                }
                // ========== 私聊用户消息 ==========
                elseif ($chatType == "private" && $eventType == "C2C_MESSAGE_CREATE") {
                    $userId = $data["d"]["author"]["id"] ?? "";
                    if ($userId == $chatId) {
                        $attachments = $data["d"]["attachments"] ?? [];
                        $imageUrls = [];
                        $videoUrl = null;
                        $voiceUrl = null;
                        $voiceWavUrl = null;
                        foreach ($attachments as $att) {
                            $contentType = $att["content_type"] ?? "";
                            if (strpos($contentType, "image/") === 0) {
                                $imageUrls[] = $att["url"];
                            } elseif (strpos($contentType, "video/") === 0) {
                                $videoUrl = $att["url"];
                            } elseif ($contentType == "voice") {
                                $voiceUrl = $att["url"];
                                $voiceWavUrl = $att["voice_wav_url"] ?? null;
                            }
                        }
                        
                        $msgIdx = "";
                        if (isset($data["d"]["message_scene"]["ext"][0])) {
                            $msgIdx = extractMsgIdx($data["d"]["message_scene"]["ext"][0]);
                        }
                        
                        $message = [
                            "time" => $time,
                            "type" => "user",
                            "user_id" => $userId,
                            "username" => $data["d"]["author"]["username"] ?? "用户",
                            "content" => trim($data["d"]["content"] ?? "", "/ "),
                            "message_id" => $data["d"]["id"] ?? "",
                            "msg_idx" => $msgIdx,
                            "attachments" => $attachments,
                            "image_urls" => $imageUrls,
                            "video_url" => $videoUrl,
                            "voice_url" => $voiceUrl,
                            "voice_wav_url" => $voiceWavUrl,
                            "can_quote" => true
                        ];
                    }
                }
                // ========== 机器人发送的消息（direction格式） ==========
                elseif (isset($data["direction"]) && $data["direction"] === "发送") {
                    $sourceType = $data["source_type"] ?? "";
                    $targetId = $data["target_id"] ?? "";
                    if (($chatType == "group" && ($sourceType === "群聊" || $sourceType === "互动") && $targetId == $chatId) ||
                        ($chatType == "private" && $sourceType === "私聊" && $targetId == $chatId)) {
                        $rawType = $data["content_type"] ?? "text";
                        $rawTypeNorm = strtolower(trim((string)$rawType));
                        $mappedType = (strpos($rawTypeNorm, 'md') !== false || strpos((string)($data['action'] ?? ''), 'MD') !== false) ? 'native_md' : ((strpos($rawTypeNorm, '卡') !== false) ? 'card' : 'text');
                        
                        $message = [
                            "time" => $time,
                            "type" => "bot",
                            "user_id" => "",
                            "username" => "机器人",
                            "content" => $data["content"] ?? "",
                            "message_type" => $mappedType,
                            "message_id" => $data["id"] ?? "",
                            "msg_idx" => "",
                            "image_url" => null,
                            "voice_url" => null,
                            "video_url" => null,
                            "card_data" => null,
                            "attachments" => [],
                            "can_quote" => true
                        ];
                    }
                }
                // ========== BOT_MESSAGE 事件 ==========
                elseif ($eventType == "BOT_MESSAGE") {
                    $botData = $data["d"] ?? [];
                    $source = $botData["source"] ?? "";
                    $target = $botData["target"] ?? "";
                    if (($chatType == "group" && $source == "群聊" && $target == $chatId) ||
                        ($chatType == "private" && $source == "私聊" && $target == $chatId)) {
                        $message = [
                            "time" => $time,
                            "type" => "bot",
                            "user_id" => "",
                            "username" => "机器人",
                            "content" => $botData["content"] ?? "",
                            "message_type" => $botData["type"] ?? "text",
                            "message_id" => $data["id"] ?? "",
                            "msg_idx" => "",
                            "image_url" => $botData["image_url"] ?? null,
                            "voice_url" => $botData["voice_url"] ?? null,
                            "video_url" => $botData["video_url"] ?? null,
                            "card_data" => $botData["card_data"] ?? null,
                            "attachments" => [],
                            "can_quote" => true
                        ];
                    }
                }
                // ========== 群聊机器人被加入事件 ==========
                elseif ($chatType == "group" && $eventType == "GROUP_ADD_ROBOT") {
                    $groupId = $data["d"]["group_openid"] ?? "";
                    if ($groupId == $chatId) {
                        $message = [
                            "time" => $time,
                            "type" => "event",
                            "user_id" => "",
                            "username" => "系统事件",
                            "content" => "🔔 机器人被加入群聊",
                            "message_id" => "",
                            "msg_idx" => "",
                            "can_quote" => false
                        ];
                    }
                }
                // ========== 群聊机器人退出事件 ==========
                elseif ($chatType == "group" && $eventType == "GROUP_DEL_ROBOT") {
                    $groupId = $data["d"]["group_openid"] ?? "";
                    if ($groupId == $chatId) {
                        $message = [
                            "time" => $time,
                            "type" => "event",
                            "user_id" => "",
                            "username" => "系统事件",
                            "content" => "🚪 机器人退出群聊",
                            "message_id" => "",
                            "msg_idx" => "",
                            "can_quote" => false
                        ];
                    }
                }
                // ========== 群成员增加事件 ==========
                elseif ($chatType == "group" && $eventType == "GROUP_MEMBER_ADD") {
                    $groupId = $data["d"]["group_openid"] ?? $data["d"]["group_id"] ?? "";
                    if ($groupId == $chatId) {
                        $userId = $data["d"]["member_openid"] ?? $data["d"]["user_id"] ?? $data["d"]["openid"] ?? "";
                        $operator = $data["d"]["operator_id"] ?? $data["d"]["op_user_id"] ?? "未知";
                        $message = [
                            "time" => $time,
                            "type" => "event",
                            "user_id" => $userId,
                            "username" => "群成员增加",
                            "content" => "👤 群成员增加\n用户: " . ($userId ?: "未知") . "\n操作者: " . $operator,
                            "message_id" => "",
                            "msg_idx" => "",
                            "can_quote" => false
                        ];
                    }
                }
                // ========== 群成员删除事件 ==========
                elseif ($chatType == "group" && $eventType == "GROUP_MEMBER_REMOVE") {
                    $groupId = $data["d"]["group_openid"] ?? $data["d"]["group_id"] ?? "";
                    if ($groupId == $chatId) {
                        $userId = $data["d"]["member_openid"] ?? $data["d"]["user_id"] ?? $data["d"]["openid"] ?? "";
                        $operator = $data["d"]["operator_id"] ?? $data["d"]["op_user_id"] ?? "未知";
                        $message = [
                            "time" => $time,
                            "type" => "event",
                            "user_id" => $userId,
                            "username" => "群成员删除",
                            "content" => "🚫 群成员删除\n用户: " . ($userId ?: "未知") . "\n操作者: " . $operator,
                            "message_id" => "",
                            "msg_idx" => "",
                            "can_quote" => false
                        ];
                    }
                }
                // ========== 私聊好友添加事件 ==========
                elseif ($chatType == "private" && $eventType == "FRIEND_ADD") {
                    $userId = $data["d"]["openid"] ?? $data["d"]["friend_openid"] ?? $data["d"]["author"]["id"] ?? "";
                    $message = [
                        "time" => $time,
                        "type" => "event",
                        "user_id" => $userId,
                        "username" => "添加好友",
                        "content" => "➕ 添加好友",
                        "message_id" => "",
                        "msg_idx" => "",
                        "can_quote" => false
                    ];
                }
                // ========== 私聊好友删除事件 ==========
                elseif ($chatType == "private" && $eventType == "FRIEND_DEL") {
                    $userId = $data["d"]["openid"] ?? $data["d"]["friend_openid"] ?? $data["d"]["author"]["id"] ?? "";
                    $message = [
                        "time" => $time,
                        "type" => "event",
                        "user_id" => $userId,
                        "username" => "删除好友",
                        "content" => "➖ 删除好友",
                        "message_id" => "",
                        "msg_idx" => "",
                        "can_quote" => false
                    ];
                }

                if ($message) {
                    $messages[] = $message;
                }
            }
        }
        
        usort($messages, function ($a, $b) {
            return strtotime($a["time"]) - strtotime($b["time"]);
        });

        echo json_encode(["code" => 200, "messages" => $messages], JSON_UNESCAPED_UNICODE);
        break;

    case "get_nicknames":
        $userIds = $_POST["user_ids"] ?? $_REQUEST["user_ids"] ?? "";
        if (empty($userIds)) {
            echo json_encode(["code" => 400, "msg" => "缺少用户ID列表"], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (is_string($userIds)) {
            $userIdsArray = json_decode($userIds, true);
        } else {
            $userIdsArray = $userIds;
        }
        if (!is_array($userIdsArray)) {
            echo json_encode(["code" => 400, "msg" => "用户ID列表格式错误"], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $nicknames = [];
        if (is_file($path)) {
            $content = file_get_contents($path);
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $matches)) {
                    $json = $matches[2];
                    if ($json == "重复数据") continue;
                    $data = json_decode($json, true);
                    if (!is_array($data)) continue;
                    $eventType = $data["t"] ?? "";
                    if ($eventType == "GROUP_AT_MESSAGE_CREATE" || $eventType == "C2C_MESSAGE_CREATE" || $eventType == "GROUP_MESSAGE_CREATE") {
                        $userId = $data["d"]["author"]["id"] ?? "";
                        $rawUsername = $data["d"]["author"]["username"] ?? "";
                        $memberNick = $data["d"]["member"]["nick"] ?? "";
                        $username = $rawUsername ?: $memberNick;
                        if ($userId && in_array($userId, $userIdsArray) && $username) {
                            if (!isset($nicknames[$userId]) || empty($nicknames[$userId])) {
                                $nicknames[$userId] = $username;
                            }
                        }
                    }
                }
            }
        }
        foreach ($userIdsArray as $userId) {
            if (!isset($nicknames[$userId])) {
                $nicknames[$userId] = "用户" . substr($userId, -6);
            }
        }
        echo json_encode(["code" => 200, "nicknames" => $nicknames], JSON_UNESCAPED_UNICODE);
        break;

    case "send":
        $chatType = $_POST["chat_type"] ?? $_REQUEST["chat_type"] ?? "";
        $chatId = $_POST["chat_id"] ?? $_REQUEST["chat_id"] ?? "";
        $sendMethod = $_POST["send_method"] ?? $_REQUEST["send_method"] ?? "text";
        $content = $_POST["content"] ?? $_REQUEST["content"] ?? "";

        if (empty($appid) || empty($chatType) || empty($chatId)) {
            echo json_encode(["code" => 400, "msg" => "缺少必要参数"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $allowedMethods = ['text', 'card', 'native_md', 'image', 'voice', 'video', 'file', 'quote'];
        if (!in_array($sendMethod, $allowedMethods)) {
            echo json_encode(["code" => 400, "msg" => "不支持的消息类型"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $content = trim($content);
        if ($content === "" && $sendMethod != 'quote') {
            echo json_encode(["code" => 400, "msg" => "消息内容不能为空"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 从数据库获取机器人配置
        require_once dirname(dirname(__DIR__)) . '/db.php';
        require_once dirname(dirname(__DIR__)) . '/function.php';
        $botConfig = getBot($appid);
        if (empty($botConfig)) {
            echo json_encode(["code" => 404, "msg" => "机器人配置不存在"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!defined('appid')) define('appid', $appid);
        if (!defined('secret')) define('secret', $botConfig['secret']);
        if (!defined('type')) define('type', $botConfig['env'] ?? '正式');
        if (!defined('消息来源')) define('消息来源', $chatType === 'group' ? '群聊' : '私聊');
        if (!defined('来源')) define('来源', $chatId);
        if (!defined('用户')) define('用户', $chatId);

        $frameworkRoot = dirname(dirname(__DIR__));
        $botFile = $frameworkRoot . '/bot.php';

        if (!is_file($botFile)) {
            echo json_encode(["code" => 500, "msg" => "机器人核心文件不存在"], JSON_UNESCAPED_UNICODE);
            exit;
        }
        require_once $botFile;

        // 获取锚点
        $logDir = dirname(__DIR__, 2) . "/Log/{$appid}/";
        $latestLogFile = null;
        if (is_dir($logDir)) {
            $files = glob($logDir . "*.log");
            if (!empty($files)) {
                usort($files, fn($a, $b) => strcmp(basename($b), basename($a)));
                $latestLogFile = $files[0];
            }
        }
        $recentEventId = null;
        $recentMsgId = null;
        $recentMsgTime = null;
        if ($latestLogFile && is_file($latestLogFile)) {
            $logContent = file_get_contents($latestLogFile);
            $lines = explode("\n", $logContent);
            for ($i = count($lines)-1; $i >= 0; $i--) {
                $line = trim($lines[$i]);
                if (empty($line) || $line == "重复数据") continue;
                if (!preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $matches)) continue;
                $timestamp = $matches[1];
                $data = json_decode($matches[2], true);
                if (!$data) continue;
                $eventType = $data["t"] ?? "";
                if ($chatType === 'group') {
                    $groupId = $data["d"]["group_openid"] ?? $data["d"]["group_id"] ?? "";
                    if ($groupId !== $chatId) continue;
                    if ($eventType === "INTERACTION_CREATE" && empty($recentEventId)) {
                        $recentEventId = $data["id"] ?? "";
                    }
                    if (($eventType === "GROUP_AT_MESSAGE_CREATE" || $eventType === "GROUP_MESSAGE_CREATE") && empty($recentMsgId)) {
                        $recentMsgId = $data["d"]["id"] ?? "";
                        $recentMsgTime = $timestamp;
                    }
                } else {
                    $userId = $data["d"]["openid"] ?? $data["d"]["author"]["id"] ?? "";
                    if ($userId !== $chatId) continue;
                    if ($eventType === "INTERACTION_CREATE" && empty($recentEventId)) {
                        $recentEventId = $data["id"] ?? "";
                    }
                    if ($eventType === "C2C_MESSAGE_CREATE" && empty($recentMsgId)) {
                        $recentMsgId = $data["d"]["id"] ?? "";
                        $recentMsgTime = $timestamp;
                    }
                }
                if (!empty($recentEventId) && !empty($recentMsgId)) break;
            }
        }
        if (!defined('消息ID')) define('消息ID', !empty($recentMsgId) ? $recentMsgId : ('ROBOT1.0_FALLBACK_' . time()));
        if (!empty($recentEventId) && !defined('事件ID')) define('事件ID', $recentEventId);

        try {
            $result = null;
            switch ($sendMethod) {
                case 'text': $result = 文字($content); break;
                case 'image': $result = 图片($content); break;
                case 'voice': $result = 语音($content); break;
                case 'video': $result = 视频($content); break;
                case 'file':
                    $filename = 'file';
                    if (preg_match('/[^\/]+\.[^.\/]+(\?.*)?$/', $content, $m)) {
                        $filename = preg_replace('/\?.*$/', '', $m[0]);
                    }
                    $result = 文件($content, $filename);
                    break;
                case 'card':
                    $cardLines = explode("\n---\n", $content);
                    $cardItems = [];
                    foreach ($cardLines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;
                        $cardItem = ['text' => $line];
                        if (preg_match('/^(.+?)\n链接:\s*(.+)$/s', $line, $lineMatches)) {
                            $cardItem['text'] = trim($lineMatches[1]);
                            $cardItem['url'] = trim($lineMatches[2]);
                        }
                        $cardItems[] = $cardItem;
                    }
                    if (empty($cardItems)) $cardItems[] = ['text' => $content];
                    $result = 文卡(...$cardItems);
                    break;
                case 'native_md':
                    $style = null; $keyboardId = null; $mdContent = $content;
                    if (strpos($content, '|') !== false) {
                        $parts = explode('|', $content);
                        $mdContent = trim($parts[0]);
                        if (isset($parts[1])) {
                            $param1 = trim($parts[1]);
                            if (strpos($param1, '{') === 0) {
                                $style = json_decode($param1, true);
                                if (isset($parts[2])) $keyboardId = trim($parts[2]);
                            } else {
                                $keyboardId = $param1;
                                if (isset($parts[2]) && strpos($parts[2], '{') === 0) $style = json_decode(trim($parts[2]), true);
                            }
                        }
                    }
                    $mdContent = str_replace(['\\n', '\n'], "\n", $mdContent);
                    $result = MD($mdContent, $keyboardId, $style);
                    break;
                case 'quote':
                    $quoteId = $_POST['quote_id'] ?? $_REQUEST['quote_id'] ?? '';
                    if (empty($quoteId)) {
                        $quoteId = getLatestMessageId($appid, $chatType, $chatId);
                    }
                    if (!empty($quoteId)) {
                        if (function_exists('引用')) {
                            $result = 引用($quoteId, $content ?: "");
                        } elseif (function_exists('回复')) {
                            $result = 回复($quoteId, $content ?: "");
                        } else {
                            $accessToken = getAccessToken($appid, $botConfig['secret']);
                            $baseUrl = ($botConfig['env'] == '沙箱') ? 'https://sandbox.api.sgroup.qq.com' : 'https://api.sgroup.qq.com';
                            if ($chatType == 'group') {
                                $sendUrl = $baseUrl . "/v2/groups/{$chatId}/messages";
                            } else {
                                $sendUrl = $baseUrl . "/v2/users/{$chatId}/messages";
                            }
                            $payload = json_encode([
                                'content' => $content ?: "",
                                'message_reference' => [
                                    'message_id' => $quoteId,
                                    'ignore_get_message_error' => true
                                ]
                            ]);
                            $apiResult = sendApiRequest($sendUrl, 'POST', $accessToken, $payload);
                            if ($apiResult['code'] >= 200 && $apiResult['code'] < 300) {
                                $result = $apiResult['body'];
                            } else {
                                $result = json_encode(["code" => $apiResult['code'], "msg" => "引用发送失败: " . $apiResult['body']]);
                            }
                        }
                    } else {
                        $result = 文字($content ?: "");
                    }
                    break;
                default: $result = 文字($content);
            }
            $decoded = @json_decode($result, true);
            if (is_array($decoded) && isset($decoded['code']) && $decoded['code'] != 0) {
                $errMsg = $decoded['message'] ?? ($decoded['msg'] ?? '发送失败');
                echo json_encode(["code" => 500, "msg" => "发送失败: " . $errMsg], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(["code" => 200, "msg" => "发送成功"], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(["code" => 500, "msg" => "发送异常: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        break;

    case "recall":
        $messageId = $_POST["message_id"] ?? $_REQUEST["message_id"] ?? "";
        if (empty($messageId)) {
            echo json_encode(["code" => 400, "msg" => "缺少消息ID"], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $chatType = $_POST["chat_type"] ?? $_REQUEST["chat_type"] ?? "";
        $chatId = $_POST["chat_id"] ?? $_REQUEST["chat_id"] ?? "";
        if (empty($chatType) || empty($chatId)) {
            echo json_encode(["code" => 400, "msg" => "缺少会话信息"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 从数据库获取机器人配置
        if (!function_exists('getBot')) {
            require_once dirname(dirname(__DIR__)) . '/db.php';
            require_once dirname(dirname(__DIR__)) . '/function.php';
        }
        $botConfig = getBot($appid);
        if (empty($botConfig)) {
            echo json_encode(["code" => 404, "msg" => "机器人配置不存在"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $accessToken = getAccessToken($appid, $botConfig['secret']);
        if (!$accessToken) {
            echo json_encode(["code" => 500, "msg" => "获取access_token失败"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $baseUrl = ($botConfig['env'] == '沙箱') ? 'https://sandbox.api.sgroup.qq.com' : 'https://api.sgroup.qq.com';
        if ($chatType == 'group') {
            $recallUrl = $baseUrl . "/v2/groups/{$chatId}/messages/{$messageId}";
        } else {
            $recallUrl = $baseUrl . "/v2/users/{$chatId}/messages/{$messageId}";
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $recallUrl,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => ["Authorization: QQBot {$accessToken}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            echo json_encode(["code" => 200, "msg" => "撤回成功"], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(["code" => $httpCode, "msg" => "撤回失败: " . $resp], JSON_UNESCAPED_UNICODE);
        }
        break;

    default:
        echo json_encode(["code" => 400, "msg" => "无效的请求类型"], JSON_UNESCAPED_UNICODE);
}