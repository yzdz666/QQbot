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
include_once(APP_ROOT . "function/Mail/PHPMailer.php");
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

        // ==================== 系统事件去重 ====================
        // 系统事件（入群申请、群成员增加/移除、加群、退群等）可能同时通过
        // webhook(index.php) 和 WebSocket(ws_event_handler.php) 两条路径到达，
        // 导致同一条事件被记录两次。在此做去重：检查最近10秒内是否已有相同事件。
        $systemEventTypes = ['加群', '退群', '群成员增加', '群成员移除', '入群申请',
                             '群消息拒绝', '群消息接收', '好友增加', '好友删除',
                             '订阅状态', '频道更新'];
        if ($direction === '接收' && in_array($sourceType, $systemEventTypes, true)) {
            $existing = db()->fetch(
                "SELECT id FROM messages
                 WHERE appid = ? AND direction = '接收' AND source_type = ?
                   AND target_id = ? AND user_id = ?
                   AND created_at >= datetime('now','localtime','-10 seconds')
                 LIMIT 1",
                [$appid, $sourceType, $targetId, $userId]
            );
            if ($existing) {
                // 已有相同事件，跳过插入（去重）
                return;
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
        case "PATCH": curl_setopt($ch, CURLOPT_URL, $url);
                      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
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
    // 清除所有输出缓冲区内容，确保只输出纯JSON
    // 防止 include 的文件产生的警告/通知/空白字符破坏JSON响应
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        // 禁止浏览器缓存API响应
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
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

// ==================== 安全进程管理函数 ====================
// 兼容宝塔面板等 disable_functions 限制环境
// 优先使用 posix_* 扩展, 其次 proc_open/popen, 最后才考虑 shell_exec

/**
 * 检测当前环境是否可以启动后台进程
 * 宝塔面板等环境可能禁用所有进程管理函数
 * 
 * @return array ['can_start' => bool, 'method' => string, 'all_disabled' => bool]
 */
function canStartProcess() {
    $methods = [];
    if (function_exists('popen')) $methods[] = 'popen';
    if (function_exists('shell_exec')) $methods[] = 'shell_exec';
    if (function_exists('proc_open')) $methods[] = 'proc_open';
    if (function_exists('exec')) $methods[] = 'exec';
    
    if (!empty($methods)) {
        return ['can_start' => true, 'method' => implode('/', $methods), 'all_disabled' => false];
    }
    
    $disabled = explode(',', ini_get('disable_functions') ?: '');
    $disabledStr = implode(', ', array_map('trim', array_filter($disabled)));
    return [
        'can_start' => false,
        'method' => 'none',
        'all_disabled' => true,
        'disabled_list' => $disabledStr
    ];
}

/**
 * 检测是否可以通过 posix_kill 终止进程
 */
function canKillProcess() {
    return function_exists('posix_kill');
}

/**
 * 检查进程是否存活
 * 优先 posix_kill(pid, 0), 降级 /proc 文件系统, 最后 shell_exec
 */
function isProcessRunning($pid) {
    $pid = intval($pid);
    if ($pid <= 0) return false;

    // 方案1: posix_kill(pid, 0) - 最可靠, 不需要 shell
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    // 方案2: Linux /proc 文件系统 - 纯文件读取, 无 shell 调用
    if (DIRECTORY_SEPARATOR === '/') {
        $procPath = '/proc/' . $pid;
        if (@file_exists($procPath)) {
            // 检查 /proc/pid/stat 可读性确认进程真实存在
            $statFile = $procPath . '/stat';
            if (@is_readable($statFile)) {
                $stat = @file_get_contents($statFile);
                if ($stat !== false && trim($stat) !== '') {
                    // 进程状态: Z=zombie 也算"存在"但即将消亡
                    $parts = explode(' ', $stat);
                    if (isset($parts[2]) && $parts[2] !== 'Z') {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    // 方案3: shell_exec 降级（可能被禁用, 用 @ 抑制）
    if (function_exists('shell_exec')) {
        $check = @shell_exec('ps -p ' . escapeshellarg((string)$pid) . ' 2>/dev/null');
        return !empty($check);
    }

    // 无法检测, 返回 false
    return false;
}

/**
 * 后台启动进程（不依赖 shell_exec）
 * 完全兼容 disable_functions 限制环境（宝塔面板等）
 *
 * 策略优先级:
 *   1. popen + nohup &  (最佳: 非阻塞, 不等待子进程)
 *   2. proc_open        (备选: 需特殊处理避免阻塞)
 *   3. shell_exec       (最后降级, 可能被禁用)
 *
 * @param string $phpBin PHP 可执行文件路径
 * @param string $script 要执行的脚本
 * @param string $logFile 日志输出文件
 * @return array ['success'=>bool, 'pid'=>int, 'error'=>string]
 */
function startBackgroundProcess($phpBin, $script, $logFile) {
    // 确保 PHP 路径有效
    if (empty($phpBin) || $phpBin === 'php') {
        return ['success' => false, 'pid' => 0, 'error' => 'PHP 可执行文件路径无效'];
    }

    // 确保日志目录存在
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    // 方案1: popen + nohup & (推荐: 非阻塞, 不会等待进程退出)
    // popen 以 'r' 模式打开后, pclose 立即返回 (因为命令末尾有 &)
    if (function_exists('popen')) {
        $cmd = 'nohup ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
             . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
        $handle = @popen($cmd, 'r');
        if ($handle !== false) {
            // 读取一行输出 (nohup 通常无输出), 然后 pclose
            // pclose 在 & 后台模式下会立即返回
            @fgets($handle);
            pclose($handle);
            // popen+& 模式下无法直接获取 PID, 返回 0
            // WS 客户端会在启动后自己写入 PID 文件
            return ['success' => true, 'pid' => 0, 'error' => ''];
        }
    }

    // 方案2: shell_exec + nohup & echo $! (可以获取 PID)
    if (function_exists('shell_exec')) {
        $cmd = 'nohup ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
             . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
        $output = @shell_exec($cmd);
        $pid = intval(trim($output ?: ''));
        if ($pid > 0) {
            return ['success' => true, 'pid' => $pid, 'error' => ''];
        }
    }

    // 方案3: proc_open (最后手段, 但 proc_close 会阻塞)
    // 使用特殊技巧: 通过 bash -c "... &" 让进程在 shell 中后台运行
    if (function_exists('proc_open')) {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // 用 bash -c 执行后台命令, proc_close 不会阻塞因为 shell 已退出
        $cmd = 'bash -c ' . escapeshellarg(
            'nohup ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
            . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!'
        );
        $proc = @proc_open($cmd, $descriptorSpec, $pipes);
        if (is_resource($proc)) {
            fclose($pipes[0]); // 关闭 stdin
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc); // bash -c 已退出, 不会阻塞
            $pid = intval(trim($output ?: ''));
            if ($pid > 0) {
                return ['success' => true, 'pid' => $pid, 'error' => ''];
            }
            // 即使没获取到 PID, bash 已执行了 nohup &, 进程可能已启动
            return ['success' => true, 'pid' => 0, 'error' => ''];
        }
    }

    // 所有方案都失败
    $disabled = explode(',', ini_get('disable_functions') ?: '');
    $disabledStr = implode(', ', array_map('trim', array_filter($disabled)));
    return [
        'success' => false,
        'pid' => 0,
        'error' => '服务器禁用了进程管理函数(popen/shell_exec/proc_open), 无法启动守护进程。' .
                   ($disabledStr ? ' 已禁用函数: ' . $disabledStr : '')
    ];
}

/**
 * 终止进程（不依赖 shell_exec）
 * 优先 posix_kill, 降级 shell_exec('kill')
 */
function killProcess($pid, $signal = 15) {
    $pid = intval($pid);
    if ($pid <= 0) return false;

    // 方案1: posix_kill
    if (function_exists('posix_kill')) {
        return posix_kill($pid, $signal);
    }

    // 方案2: proc_open 执行 kill 命令
    if (function_exists('proc_open')) {
        $cmd = 'kill -' . intval($signal) . ' ' . escapeshellarg((string)$pid) . ' 2>/dev/null';
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptorSpec, $pipes);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return true;
        }
    }

    // 方案3: shell_exec
    if (function_exists('shell_exec')) {
        @shell_exec('kill -' . intval($signal) . ' ' . escapeshellarg((string)$pid) . ' 2>/dev/null');
        return true;
    }

    return false;
}

/**
 * 获取 PHP 可执行文件路径（不依赖 shell_exec）
 * PHP_BINARY 是最可靠的来源
 */
function getPhpBinary() {
    $phpBin = PHP_BINARY;

    // PHP_BINARY 有时返回空或 'php'
    if (empty($phpBin) || $phpBin === 'php' || $phpBin === 'auto') {
        // 降级: 尝试 PHP_BINDIR (但可能受 open_basedir 限制)
        $candidates = [
            PHP_BINDIR . '/php',
            PHP_BINDIR . '/php8',
            PHP_BINDIR . '/php8.1',
            PHP_BINDIR . '/php8.2',
            PHP_BINDIR . '/php8.3',
            '/usr/bin/php',
            '/usr/local/bin/php',
        ];
        foreach ($candidates as $candidate) {
            if (@file_exists($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        // 最后降级: shell_exec('which php')（可能被禁用）
        if (function_exists('shell_exec')) {
            $found = trim(@shell_exec('which php 2>/dev/null') ?: '');
            if (!empty($found) && @file_exists($found)) {
                return $found;
            }
        }

        return 'php'; // 依赖 PATH
    }

    return $phpBin;
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
