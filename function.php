<?php
/**
 * 核心工具函数库（增强版）
 * 数据库存储替代JSON文件，保留原有函数签名兼容插件
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__ . '/');
}

// ==================== mbstring 兼容层 ====================
// 如果mbstring扩展未加载，提供兼容函数
if (!function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = 'UTF-8') {
        return substr($string, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = 'UTF-8') {
        return strlen($string);
    }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($haystack, $needle, $offset = 0, $encoding = 'UTF-8') {
        return strpos($haystack, $needle, $offset);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = 'UTF-8') {
        return strtolower($string);
    }
}

// 引入数据库层
require_once(APP_ROOT . 'db.php');

// 引入依赖文件
include(APP_ROOT . "function/qrcode.php");
include(APP_ROOT . "function/GD.php");
include_once(APP_ROOT . "function/Parsedown.php");
include(APP_ROOT . "function/Mail/class.smtp.php");
include(APP_ROOT . "function/tuwen.php");

// 定义 sodium 常量（如果未定义）
if (!defined('SODIUM_CRYPTO_SIGN_SEEDBYTES')) {
    define('SODIUM_CRYPTO_SIGN_SEEDBYTES', 32);
}

// ==================== 数据库存储函数（兼容旧接口） ====================

/**
 * 写入数据（替代原JSON文件写入，现在使用数据库）
 * 兼容旧接口: 写("function/appid", "key", $value)
 */
function 写($文件, $键, $值) {
    $namespace = $文件;
    // 清理路径，提取namespace
    $namespace = str_replace(['/', '\\', '.json'], ['_', '_', ''], $namespace);
    return db()->kvSet($namespace, $键, $值);
}

/**
 * 读取数据（替代原JSON文件读取，现在使用数据库）
 * 兼容旧接口: 读("function/appid", "key", $default)
 */
function 读($文件, $键, $默认值 = null) {
    $namespace = $文件;
    $namespace = str_replace(['/', '\\', '.json'], ['_', '_', ''], $namespace);
    return db()->kvGet($namespace, $键, $默认值);
}

/**
 * 删除数据
 */
function 删($文件, $键) {
    $namespace = str_replace(['/', '\\', '.json'], ['_', '_', ''], $文件);
    return db()->kvDelete($namespace, $键);
}

// ==================== 日志函数（仅数据库存储） ====================

function wlog($content, $appid_param = null) {
    $date = date('Y-m-d H:i:s');

    // 优先使用传入的参数，如果没有则尝试使用已定义的常量
    $logAppId = $appid_param;
    if ($logAppId === null && defined('appid')) {
        $logAppId = appid;
    }

    // 如果仍然没有有效的 appid，使用 'unknown'
    if ($logAppId === null || $logAppId === '') {
        $logAppId = 'unknown';
    }

    // 仅写入数据库，不再使用文件存储
    try {
        db()->execute(
            "INSERT INTO system_logs (appid, log_type, content, level) VALUES (?, ?, ?, ?)",
            [$logAppId, 'system', is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE) : (string)$content, 'INFO']
        );
    } catch (Exception $e) {
        // 数据库写入失败，静默忽略
    }
}

/**
 * 记录消息到数据库
 */
function logMessage($appid, $direction, $sourceType, $targetId, $contentType, $content, $messageId = null, $userId = null, $rawData = null) {
    try {
        // 防止二进制数据存入content字段（导致前端显示乱码）
        if ($content !== null && $content !== '') {
            // 检测是否包含非UTF-8字符（二进制数据）
            $cleanContent = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
            if ($cleanContent !== $content) {
                // 是二进制数据，用占位符替代
                $typeLabel = is_string($contentType) ? $contentType : '数据';
                $content = '[上传' . $typeLabel . ']';
            }
            // 超长内容也截断（防止Base64编码的大文件数据）
            if (strlen($content) > 10000) {
                $content = mb_substr($content, 0, 200) . '...[内容过长已截断]';
            }
        }
        db()->execute(
            "INSERT INTO messages (appid, direction, source_type, target_id, user_id, content_type, content, message_id, raw_data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$appid, $direction, $sourceType, $targetId, $userId, $contentType, $content, $messageId, $rawData]
        );
    } catch (Exception $e) {
        wlog("记录消息失败: " . $e->getMessage(), $appid);
    }
}

// ==================== 消息附件解析函数 ====================

/**
 * 解析消息附件，提取类型和URL
 * 参照 QQ Bot API v2 文档: https://bot.q.qq.com/wiki/develop/api-v2/
 * 
 * 接收消息的 attachments 结构:
 * {
 *   "url": "https://multimedia.nt.qq.com.cn/download?...",
 *   "filename": "xxx.png",
 *   "width": 750,
 *   "height": 1334,
 *   "size": 126933,
 *   "content_type": "image/jpeg",
 *   "content": ""
 * }
 * 
 * @param array $rawData 完整的原始事件数据 (含 op, d, t 等)
 * @return array [
 *   'content_type' => 类型 (图片/视频/语音/文件/文字),
 *   'content'      => 日志内容 (文字内容 + 附件URL),
 *   'attachments'  => 附件详情列表
 * ]
 */
function parseMessageAttachment($rawData) {
    $result = [
        'content_type' => '文字',
        'content' => '',
        'attachments' => []
    ];

    if (!is_array($rawData)) {
        return $result;
    }

    $d = $rawData['d'] ?? $rawData;
    $textContent = trim($d['content'] ?? '');
    $attachments = $d['attachments'] ?? [];

    // 没有附件，返回纯文字
    if (empty($attachments) || !is_array($attachments)) {
        $result['content'] = $textContent;
        return $result;
    }

    $contentParts = [];
    if (!empty($textContent)) {
        $contentParts[] = $textContent;
    }

    $primaryType = '文字';

    foreach ($attachments as $attachment) {
        // URL 可能被反引号包裹，需要清理
        $url = isset($attachment['url']) ? trim($attachment['url'], "`\t\n\r\0\x0B ") : '';
        $contentType = $attachment['content_type'] ?? '';
        $fileName = $attachment['filename'] ?? '';

        // 根据 content_type (MIME) 判断附件类型
        // 参照 API文档: file_type 1=图片 2=视频 3=语音 4=文件
        if (strpos($contentType, 'image/') === 0) {
            $type = '图片';
        } elseif (strpos($contentType, 'video/') === 0) {
            $type = '视频';
        } elseif (strpos($contentType, 'audio/') === 0
                  || $contentType === 'voice'
                  || $contentType === 'silk'
                  || $contentType === 'application/silk') {
            $type = '语音';
        } else {
            $type = '文件';
        }

        // 第一个附件的类型作为消息的主类型
        if ($primaryType === '文字') {
            $primaryType = $type;
        }

        // 构建单个附件的日志内容
        $attachmentLog = "[{$type}]";
        if (!empty($fileName)) {
            $attachmentLog .= " {$fileName}";
        }
        if (!empty($url)) {
            $attachmentLog .= " {$url}";
        }
        $contentParts[] = $attachmentLog;

        // 提取语音WAV URL（浏览器兼容格式）和ASR识别文本
        $wavUrl = '';
        if (isset($attachment['voice_wav_url'])) {
            $wavUrl = trim($attachment['voice_wav_url'], "`\t\n\r\0\x0B ");
        }
        $asrText = '';
        if (isset($attachment['asr_refer_text'])) {
            $asrText = trim($attachment['asr_refer_text']);
        }

        $result['attachments'][] = [
            'type' => $type,
            'url' => $url,
            'wav_url' => $wavUrl,
            'asr_text' => $asrText,
            'filename' => $fileName,
            'content_type' => $contentType,
            'width' => $attachment['width'] ?? null,
            'height' => $attachment['height'] ?? null,
            'size' => $attachment['size'] ?? null
        ];
    }

    $result['content_type'] = $primaryType;
    $result['content'] = implode(' ', $contentParts);

    return $result;
}

// ==================== 网络请求函数 ====================

function curl($url, $method, $headers, $params){
    $url = str_replace(" ", "%20", $url);
    if (is_array($params)) {
        $requestString = http_build_query($params);
    } else {
        $requestString = $params ?: '';
    }
    if (empty($headers)) {
        $headers = array('Content-type: text/json');
    } elseif (!is_array($headers)) {
        parse_str($headers, $headers);
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    switch ($method){
        case "GET" :
            if (!empty($requestString)) {
                $url .= (strpos($url, '?') !== false ? '&' : '?') . $requestString;
            }
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPGET, 1);
            break;
        case "POST": curl_setopt($ch, CURLOPT_URL, $url);
                     curl_setopt($ch, CURLOPT_POST, 1);
                     curl_setopt($ch, CURLOPT_POSTFIELDS, $requestString); break;
        case "PUT" : curl_setopt($ch, CURLOPT_URL, $url);
                     curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                     curl_setopt($ch, CURLOPT_POSTFIELDS, $requestString); break;
        case "DELETE": curl_setopt($ch, CURLOPT_URL, $url);
                       curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                       curl_setopt($ch, CURLOPT_POSTFIELDS, $requestString); break;
        default: curl_setopt($ch, CURLOPT_URL, $url); break;
    }
    $response = curl_exec($ch);
    curl_close($ch);
    if (stristr($response, 'HTTP 404') || $response == '') {
        return json_encode(['Error' => '请求错误']);
    }
    return $response;
}

// ==================== 签名验证 ====================

function sign($payload, $seed){
    while (strlen($seed) < SODIUM_CRYPTO_SIGN_SEEDBYTES) {
        $seed .= $seed;
    }
    $privateKey = sodium_crypto_sign_secretkey(
        sodium_crypto_sign_seed_keypair(substr($seed, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES))
    );
    $signature = bin2hex(
        sodium_crypto_sign_detached(
            $payload['d']['event_ts'] . $payload['d']['plain_token'],
            $privateKey
        )
    );
    echo json_encode([
        'plain_token' => $payload['d']['plain_token'],
        'signature' => $signature
    ]);
}

// ==================== 工具函数 ====================

function 二维码($content){
    ob_start();
    Toplib_Lib_QRcode::png($content, false, QR_ECLEVEL_L, 7, 1, false, [255,255,255], [0,0,0]);
    return ob_get_clean();
}

function 前缀后($str, $prefix) {
    if (strpos($str, $prefix) !== false) {
        return substr($str, strlen($prefix));
    }
    return $str;
}

function 前缀($str, $prefix) {
    return strpos($str, $prefix) === 0;
}

function 域名大写($msg) {
    $suffixes = array(
        'com', 'net', 'org', 'edu', 'gov', 'mil', 'biz', 'info', 'top',
        'xyz', 'vip', 'pro', 'name', 'tech', 'site', 'club', 'online',
        'store', 'shop', 'blog', 'app', 'cn', 'cc', 'tv', 'io', 'ai'
    );
    foreach ($suffixes as $suffix) {
        $pattern = '/([\.\/])(' . $suffix . ')\b/i';
        $msg = preg_replace_callback($pattern, function($matches) {
            return $matches[1] . ucfirst(strtolower($matches[2]));
        }, $msg);
    }
    return $msg;
}

function markdown转html($markdown){
    $parsedown = new Parsedown();
    return $parsedown->text($markdown);
}

function 邮箱($mailTitle, $content, $Adress, $user, $password){
    $mail = new PHPMailer();
    $mail->SMTPDebug = 0;
    $mail->isSMTP();
    $mail->SMTPAuth = true;
    $mail->Host = 'smtp.qq.com';
    $mail->SMTPSecure = 'ssl';
    $mail->Port = 465;
    $mail->CharSet = 'UTF-8';
    $mail->Username = $user;
    $mail->Password = $password;
    $mail->From = $user;
    $mail->FromName = 'Bot';
    $mail->isHTML(true);
    $mail->addAddress($Adress);
    $mail->Subject = $mailTitle;
    $mail->Body = $content;
    return $mail->send();
}

function HTML转图($html,$long,$width){
    $url="https://clrvai.com/Rendering.php";
    $json=json_encode(["html"=>$html,"width"=>$width,"height"=>$long,"queryParams"=>"av=600&ac=1445"],JSON_UNESCAPED_UNICODE);
    $header=array('Content-Type: application/json');
    $image=json_decode(curl($url,"POST",$header,$json),true);
    $image=$image["url"] ?? false;
    return $image;
}

// ==================== 安全辅助函数 ====================

function getClientIp() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function json_response($data, $code = 200) {
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    // 使用 JSON_INVALID_UTF8_SUBSTITUTE 防止无效UTF-8字符导致json_encode返回false
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

function passwordHash($password) {
    $salt = bin2hex(random_bytes(16));
    $hash = hash('sha256', $salt . $password);
    return 'sha256:' . $salt . ':' . $hash;
}

function passwordVerify($password, $stored) {
    if (strpos($stored, 'sha256:') === 0) {
        $parts = explode(':', $stored);
        if (count($parts) !== 3) return false;
        $salt = $parts[1];
        $hash = $parts[2];
        return hash_equals($hash, hash('sha256', $salt . $password));
    }
    // 明文兼容
    return $password === $stored;
}

function isWeakPassword($password) {
    $weak = ['admin', '123456', 'password', 'admin123', '12345678', ''];
    return in_array(strtolower($password), $weak);
}

// ==================== 机器人管理函数 ====================

function getBots() {
    return db()->fetchAll("SELECT * FROM bots ORDER BY created_at");
}

function getBot($appid) {
    return db()->fetch("SELECT * FROM bots WHERE appid = ?", [$appid]);
}

function addBot($appid, $secret, $env = '正式') {
    db()->execute(
        "INSERT OR IGNORE INTO bots (appid, secret, env) VALUES (?, ?, ?)",
        [$appid, $secret, $env]
    );
    return db()->execute(
        "UPDATE bots SET secret = ?, env = ? WHERE appid = ?",
        [$secret, $env, $appid]
    );
}

function deleteBot($appid) {
    db()->execute("DELETE FROM bots WHERE appid = ?", [$appid]);
    db()->execute("DELETE FROM plugin_status WHERE appid = ?", [$appid]);
    return true;
}

function updateBot($appid, $data) {
    $fields = [];
    $params = [];
    foreach (['secret', 'env', 'ws_enabled', 'ws_url', 'enabled', 'robot_qq', 'owner_ids', 'nickname', 'avatar'] as $field) {
        if (isset($data[$field])) {
            $fields[] = "$field = ?";
            $params[] = is_array($data[$field]) ? json_encode($data[$field], JSON_UNESCAPED_UNICODE) : $data[$field];
        }
    }
    if (empty($fields)) return false;
    $params[] = $appid;
    return db()->execute("UPDATE bots SET " . implode(', ', $fields) . " WHERE appid = ?", $params);
}

// ==================== 事件去重 ====================

function isEventProcessed($eventId) {
    if (empty($eventId)) return false;
    $row = db()->fetch("SELECT event_id FROM event_dedup WHERE event_id = ?", [$eventId]);
    return $row !== false;
}

function markEventProcessed($eventId, $appid = null) {
    if (empty($eventId)) return;
    try {
        db()->execute("INSERT OR IGNORE INTO event_dedup (event_id, appid) VALUES (?, ?)", [$eventId, $appid]);
    } catch (Exception $e) {}
}

// ==================== 统计函数 ====================

function getStatistics($appid = null, $days = 30) {
    $params = [];
    $where = "";
    if ($appid) {
        $where = "WHERE appid = ?";
        $params[] = $appid;
    }
    $stats = db()->fetchAll(
        "SELECT * FROM statistics $where ORDER BY stat_date DESC LIMIT ?",
        array_merge($params, [$days])
    );

    $msgCount = db()->fetchColumn(
        "SELECT COUNT(*) FROM messages " . ($appid ? "WHERE appid = ?" : ""),
        $appid ? [$appid] : []
    );
    $userCount = db()->fetchColumn(
        "SELECT COUNT(*) FROM users " . ($appid ? "WHERE appid = ?" : ""),
        $appid ? [$appid] : []
    );
    $groupCount = db()->fetchColumn(
        "SELECT COUNT(*) FROM groups " . ($appid ? "WHERE appid = ?" : ""),
        $appid ? [$appid] : []
    );

    return [
        'daily' => $stats,
        'total_messages' => $msgCount,
        'total_users' => $userCount,
        'total_groups' => $groupCount,
    ];
}

function recordUser($appid, $userId, $nickname = '') {
    if (!empty($nickname)) {
        db()->execute(
            "INSERT OR IGNORE INTO users (appid, user_id, nickname) VALUES (?, ?, ?)",
            [$appid, $userId, $nickname]
        );
        db()->execute(
            "UPDATE users SET nickname = ?, last_active = datetime('now','localtime') WHERE appid = ? AND user_id = ?",
            [$nickname, $appid, $userId]
        );
    } else {
        db()->execute(
            "INSERT OR IGNORE INTO users (appid, user_id, nickname) VALUES (?, ?, '')",
            [$appid, $userId]
        );
        db()->execute(
            "UPDATE users SET last_active = datetime('now','localtime') WHERE appid = ? AND user_id = ?",
            [$appid, $userId]
        );
    }
}

function recordGroup($appid, $groupId, $groupName = '') {
    if (!empty($groupName)) {
        db()->execute(
            "INSERT OR IGNORE INTO groups (appid, group_id, group_name) VALUES (?, ?, ?)",
            [$appid, $groupId, $groupName]
        );
        db()->execute(
            "UPDATE groups SET group_name = ?, last_active = datetime('now','localtime') WHERE appid = ? AND group_id = ?",
            [$groupName, $appid, $groupId]
        );
    } else {
        db()->execute(
            "INSERT OR IGNORE INTO groups (appid, group_id, group_name) VALUES (?, ?, '')",
            [$appid, $groupId]
        );
        db()->execute(
            "UPDATE groups SET last_active = datetime('now','localtime') WHERE appid = ? AND group_id = ?",
            [$appid, $groupId]
        );
    }
}

// ==================== 系统信息 ====================

function getSystemInfo() {
    $load = sys_getloadavg();
    $mem = memory_get_usage(true);
    $memLimit = ini_get('memory_limit');
    // 将 -1 转换为用户友好的显示
    $memLimitDisplay = ($memLimit === '-1' || $memLimit === -1) ? '无限制' : $memLimit;
    $diskFree = @disk_free_space(__DIR__);
    $diskTotal = @disk_total_space(__DIR__);
    // 如果获取失败，尝试使用根目录
    if ($diskFree === false) $diskFree = @disk_free_space('/');
    if ($diskTotal === false) $diskTotal = @disk_total_space('/');

    return [
        'php_version' => PHP_VERSION,
        'os' => php_uname('s') . ' ' . php_uname('r'),
        'hostname' => php_uname('n'),
        'memory_usage' => round($mem / 1024 / 1024, 2) . ' MB',
        'memory_limit' => $memLimitDisplay,
        'disk_free' => $diskFree !== false ? round($diskFree / 1024 / 1024 / 1024, 2) . ' GB' : '不可用',
        'disk_total' => $diskTotal !== false ? round($diskTotal / 1024 / 1024 / 1024, 2) . ' GB' : '不可用',
        'disk_free_raw' => $diskFree,
        'disk_total_raw' => $diskTotal,
        'load_avg' => $load,
        'timezone' => date_default_timezone_get(),
        'bot_count' => db()->fetchColumn("SELECT COUNT(*) FROM bots"),
        'message_count' => db()->fetchColumn("SELECT COUNT(*) FROM messages"),
    ];
}

// ==================== 机器人信息获取（参照原始info.php实现） ====================

/**
 * 获取机器人信息（头像、昵称）
 * 通过 QQ Bot API 获取 access_token，再调用 /users/@me 获取机器人信息
 * 
 * @param string $appid  机器人AppID
 * @param string $secret 机器人Secret
 * @param string $env    环境（正式/沙箱）
 * @return array|null    返回 ['username'=>..., 'avatar'=>...] 或 null
 */
function getBotInfo($appid, $secret, $env = '正式') {
    // 第一步：获取 access_token
    $tokenUrl = 'https://bots.qq.com/app/getAppAccessToken';
    $postData = json_encode([
        'appId'        => (string)$appid,
        'clientSecret' => $secret
    ]);

    $tokenResp = curl($tokenUrl, 'POST', ['Content-Type: application/json'], $postData);
    $tokenData = json_decode($tokenResp, true);
    
    if (!$tokenData || !isset($tokenData['access_token'])) {
        return null;
    }

    $accessToken = $tokenData['access_token'];

    // 第二步：根据环境选择 API 域名
    $apiBase = ($env === '沙箱') 
        ? 'https://sandbox.api.sgroup.qq.com' 
        : 'https://api.sgroup.qq.com';
    $infoUrl = $apiBase . '/users/@me';

    $headers = [
        'Authorization: QQBot ' . $accessToken,
        'Content-Type: application/json'
    ];

    $infoResp = curl($infoUrl, 'GET', $headers, '');
    $infoData = json_decode($infoResp, true);

    if (!$infoData || isset($infoData['code'])) {
        return null;
    }

    return [
        'username' => $infoData['username'] ?? '',
        'avatar'   => $infoData['avatar'] ?? '',
        'id'       => $infoData['id'] ?? '',
    ];
}

/**
 * 获取机器人信息并更新数据库
 * 
 * @param string $appid  机器人AppID
 * @return array         ['success'=>bool, 'data'=>..., 'message'=>...]
 */
function fetchAndUpdateBotInfo($appid) {
    $bot = getBot($appid);
    if (!$bot) {
        return ['success' => false, 'message' => '机器人不存在'];
    }

    $info = getBotInfo($bot['appid'], $bot['secret'], $bot['env'] ?? '正式');
    if (!$info) {
        return ['success' => false, 'message' => '获取机器人信息失败，请检查AppID、Secret和环境设置'];
    }

    $updateData = [];
    if (!empty($info['username'])) {
        $updateData['nickname'] = $info['username'];
    }
    if (!empty($info['avatar'])) {
        $updateData['avatar'] = $info['avatar'];
    }
    if (!empty($info['id'])) {
        $updateData['robot_qq'] = $info['id'];
    }

    if (!empty($updateData)) {
        updateBot($appid, $updateData);
    }

    return [
        'success' => true,
        'message' => '获取成功',
        'data' => [
            'nickname'  => $info['username'] ?? '',
            'avatar'    => $info['avatar'] ?? '',
            'robot_qq'  => $info['id'] ?? '',
        ]
    ];
}

// ==================== 用户/群备注管理函数 ====================

/**
 * 设置用户备注（私聊昵称备注）
 */
function setUserRemark($appid, $userId, $remark) {
    // 确保 users 表中有该用户记录
    db()->execute(
        "INSERT OR IGNORE INTO users (appid, user_id, nickname, remark) VALUES (?, ?, '', ?)",
        [$appid, $userId, $remark]
    );
    db()->execute(
        "UPDATE users SET remark = ? WHERE appid = ? AND user_id = ?",
        [$remark, $appid, $userId]
    );
    return true;
}

/**
 * 获取用户备注
 */
function getUserRemark($appid, $userId) {
    $row = db()->fetch(
        "SELECT remark, nickname FROM users WHERE appid = ? AND user_id = ?",
        [$appid, $userId]
    );
    if (!$row) return '';
    // 优先返回备注，没有则返回昵称
    return !empty($row['remark']) ? $row['remark'] : ($row['nickname'] ?? '');
}

/**
 * 设置群备注（群聊昵称备注）
 */
function setGroupRemark($appid, $groupId, $remark) {
    db()->execute(
        "INSERT OR IGNORE INTO groups (appid, group_id, group_name, remark) VALUES (?, ?, '', ?)",
        [$appid, $groupId, $remark]
    );
    db()->execute(
        "UPDATE groups SET remark = ? WHERE appid = ? AND group_id = ?",
        [$remark, $appid, $groupId]
    );
    return true;
}

/**
 * 获取群备注
 */
function getGroupRemark($appid, $groupId) {
    $row = db()->fetch(
        "SELECT remark, group_name FROM groups WHERE appid = ? AND group_id = ?",
        [$appid, $groupId]
    );
    if (!$row) return '';
    return !empty($row['remark']) ? $row['remark'] : ($row['group_name'] ?? '');
}

/**
 * 设置群自定义头像
 */
function setGroupAvatar($appid, $groupId, $avatarUrl) {
    db()->execute(
        "INSERT OR IGNORE INTO groups (appid, group_id, group_name, custom_avatar) VALUES (?, ?, '', ?)",
        [$appid, $groupId, $avatarUrl]
    );
    db()->execute(
        "UPDATE groups SET custom_avatar = ? WHERE appid = ? AND group_id = ?",
        [$avatarUrl, $appid, $groupId]
    );
    return true;
}

/**
 * 获取群自定义头像
 */
function getGroupAvatar($appid, $groupId) {
    $row = db()->fetch(
        "SELECT custom_avatar FROM groups WHERE appid = ? AND group_id = ?",
        [$appid, $groupId]
    );
    return $row ? ($row['custom_avatar'] ?? '') : '';
}

/**
 * 批量获取用户备注和群备注/头像
 */
function getRemarks($appid, $userIds = [], $groupIds = []) {
    $result = [
        'user_remarks' => [],
        'user_nicknames' => [],
        'group_remarks' => [],
        'group_names' => [],
        'group_avatars' => [],
    ];

    // 用户备注和昵称
    if (!empty($userIds)) {
        $userIds = array_slice($userIds, 0, 100);
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows = db()->fetchAll(
            "SELECT user_id, nickname, remark FROM users WHERE appid = ? AND user_id IN ({$placeholders})",
            array_merge([$appid], $userIds)
        );
        foreach ($rows as $row) {
            $result['user_nicknames'][$row['user_id']] = $row['nickname'];
            $result['user_remarks'][$row['user_id']] = $row['remark'];
        }
    }

    // 群备注、群名和头像
    if (!empty($groupIds)) {
        $groupIds = array_slice($groupIds, 0, 100);
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $rows = db()->fetchAll(
            "SELECT group_id, group_name, remark, custom_avatar FROM groups WHERE appid = ? AND group_id IN ({$placeholders})",
            array_merge([$appid], $groupIds)
        );
        foreach ($rows as $row) {
            $result['group_names'][$row['group_id']] = $row['group_name'];
            $result['group_remarks'][$row['group_id']] = $row['remark'];
            $result['group_avatars'][$row['group_id']] = $row['custom_avatar'];
        }
    }

    return $result;
}
