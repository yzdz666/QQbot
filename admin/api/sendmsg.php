<?php
header('Content-Type: application/json');

// 校验登录态
if (!isset($_COOKIE['admin_token'])) {
    echo json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['code' => 400, 'msg' => '无效的请求数据'], JSON_UNESCAPED_UNICODE);
    exit;
}

$appid = $input['appid'] ?? '';
$type = $input['type'] ?? '';          // c2c 或 group
$target_id = $input['target_id'] ?? '';
$content = trim($input['content'] ?? '');
$msg_type = $input['msg_type'] ?? 'text';   // text, image, voice, video, file, quote, card, native_md
$quote_id = $input['quote_id'] ?? '';       // 引用消息ID（可选）

if (empty($appid) || empty($type) || empty($target_id) || empty($content)) {
    echo json_encode(['code' => 400, 'msg' => '缺少必要参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 加载机器人配置
$mainFile = dirname(__DIR__, 2) . '/main.json';
if (!file_exists($mainFile)) {
    echo json_encode(['code' => 500, 'msg' => '机器人配置文件不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}
$config = json_decode(file_get_contents($mainFile), true);
if (!isset($config[$appid])) {
    echo json_encode(['code' => 404, 'msg' => '未找到该机器人配置'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 定义必要的常量（供 bot.php 使用）
if (!defined('appid')) define('appid', $appid);
if (!defined('secret')) define('secret', $config[$appid]['secret']);
if (!defined('type')) define('type', $config[$appid]['type'] ?? '正式');
if (!defined('消息来源')) define('消息来源', $type === 'group' ? '群聊' : '私聊');
if (!defined('来源')) define('来源', $target_id);
if (!defined('用户')) define('用户', $target_id);

// 加载 bot.php 所需的基础函数和发送函数
$frameworkRoot = dirname(__DIR__, 2);
$botFile = $frameworkRoot . '/bot.php';
$funcFile = $frameworkRoot . '/function.php';

if (!is_file($funcFile) || !is_file($botFile)) {
    echo json_encode(['code' => 500, 'msg' => '系统文件缺失，请联系管理员'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once $funcFile;
require_once $botFile;

// 获取最近的 msg_id 或 event_id 作为锚点（用于发送时引用）
function getRecentAnchor($appid, $chatType, $chatId) {
    $logDir = dirname(__DIR__, 2) . "/Log/{$appid}/";
    $latestLogFile = null;
    if (is_dir($logDir)) {
        $files = glob($logDir . "*.log");
        if (!empty($files)) {
            usort($files, function($a, $b) {
                return strcmp(basename($b), basename($a));
            });
            $latestLogFile = $files[0];
        }
    }
    if (!$latestLogFile || !is_file($latestLogFile)) {
        return ['msg_id' => null, 'event_id' => null];
    }
    
    $logContent = file_get_contents($latestLogFile);
    $lines = explode("\n", $logContent);
    $recentEventId = null;
    $recentMsgId = null;
    
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim($lines[$i]);
        if (empty($line) || $line === "重复数据") continue;
        if (!preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $matches)) continue;
        
        $jsonStr = $matches[2];
        $data = json_decode($jsonStr, true);
        if (!is_array($data)) continue;
        
        $eventType = $data["t"] ?? "";
        
        if ($chatType === 'group') {
            $groupId = $data["d"]["group_openid"] ?? $data["d"]["group_id"] ?? "";
            if ($groupId !== $chatId) continue;
            if ($eventType === "INTERACTION_CREATE" && empty($recentEventId)) {
                $recentEventId = $data["id"] ?? "";
            }
            if (($eventType === "GROUP_AT_MESSAGE_CREATE" || $eventType === "GROUP_MESSAGE_CREATE") && empty($recentMsgId)) {
                $recentMsgId = $data["d"]["id"] ?? "";
            }
        } else {
            $userId = $data["d"]["openid"] ?? $data["d"]["author"]["id"] ?? "";
            if ($userId !== $chatId) continue;
            if ($eventType === "INTERACTION_CREATE" && empty($recentEventId)) {
                $recentEventId = $data["id"] ?? "";
            }
            if ($eventType === "C2C_MESSAGE_CREATE" && empty($recentMsgId)) {
                $recentMsgId = $data["d"]["id"] ?? "";
            }
        }
        if (!empty($recentEventId) && !empty($recentMsgId)) break;
    }
    return ['msg_id' => $recentMsgId, 'event_id' => $recentEventId];
}

// 设置消息ID和事件ID（用于回复锚点）
$anchor = getRecentAnchor($appid, $type, $target_id);
if (empty($anchor['msg_id']) && empty($anchor['event_id'])) {
    // 没有锚点也可以尝试发送（主动消息不需要回复锚点，但部分接口需要 msg_id）
    // 对于群聊/私聊主动消息，不需要 msg_id，直接发送即可
    if (!defined('消息ID')) define('消息ID', '');
    if (!defined('事件ID')) define('事件ID', '');
} else {
    if (!defined('消息ID')) define('消息ID', $anchor['msg_id'] ?? '');
    if (!defined('事件ID')) define('事件ID', $anchor['event_id'] ?? '');
}

// 根据 msg_type 调用对应的 bot 发送函数
try {
    $result = null;
    switch ($msg_type) {
        case 'text':
            $result = 文字($content);
            break;
        case 'image':
            $result = 图片($content);
            break;
        case 'voice':
            $result = 语音($content);
            break;
        case 'video':
            $result = 视频($content);
            break;
        case 'file':
            // 提取文件名
            $filename = 'file';
            if (preg_match('/[^\/]+\.[^.\/]+(\?.*)?$/', $content, $m)) {
                $filename = preg_replace('/\?.*$/', '', $m[0]);
            }
            $result = 文件($content, $filename);
            break;
        case 'quote':
            // 如果没有提供 quote_id，尝试自动获取最新一条消息ID
            if (empty($quote_id) && !empty($anchor['msg_id'])) {
                $quote_id = $anchor['msg_id'];
            }
            if (function_exists('引用') && !empty($quote_id)) {
                $result = 引用($quote_id, $content ?: "引用回复");
            } else {
                $result = 文字("[引用" . ($quote_id ? " $quote_id" : "") . "] " . ($content ?: ""));
            }
            break;
        case 'card':
            // 卡片格式：每行一张卡片，用 --- 分隔，可附带链接
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
            // 解析新版 Markdown 样式和按钮
            // 格式: 内容 | 按钮ID | {"layout":"...","main_font_size":"..."}
            $style = null;
            $keyboardId = null;
            $mdContent = $content;
            
            if (strpos($content, '|') !== false) {
                $parts = explode('|', $content);
                $mdContent = trim($parts[0]);
                if (isset($parts[1])) {
                    $param1 = trim($parts[1]);
                    if (strpos($param1, '{') === 0) {
                        $style = json_decode($param1, true);
                        if ($style === null) $style = null;
                        if (isset($parts[2])) $keyboardId = trim($parts[2]);
                    } else {
                        $keyboardId = $param1;
                        if (isset($parts[2]) && strpos($parts[2], '{') === 0) {
                            $style = json_decode(trim($parts[2]), true);
                        }
                    }
                }
            }
            // 处理换行符
            $mdContent = str_replace(['\\n', '\n'], "\n", $mdContent);
            $result = MD($mdContent, $keyboardId, $style);
            break;
        default:
            echo json_encode(['code' => 400, 'msg' => '不支持的消息类型'], JSON_UNESCAPED_UNICODE);
            exit;
    }
    
    // 检查返回结果
    $decoded = @json_decode($result, true);
    if (is_array($decoded) && isset($decoded['code']) && $decoded['code'] != 0) {
        $errMsg = $decoded['message'] ?? ($decoded['msg'] ?? '发送失败');
        echo json_encode(['code' => 500, 'msg' => "发送失败: {$errMsg}"], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 尝试提取消息ID（如果返回中有）
    $msgId = '';
    if (is_array($decoded) && isset($decoded['id'])) {
        $msgId = $decoded['id'];
    } elseif (is_string($result) && preg_match('/"id":"([^"]+)"/', $result, $m)) {
        $msgId = $m[1];
    }
    
    echo json_encode([
        'code' => 200,
        'msg' => '发送成功',
        'msg_id' => $msgId
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    echo json_encode(['code' => 500, 'msg' => '发送异常: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}