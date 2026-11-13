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
            [$logAppId, 'system', is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$content, 'INFO']
        );
    } catch (Exception $e) {
        // 数据库写入失败，静默忽略
    }
}

/**
 * 清理昵称中的不可见字符（零宽空格、方向控制符、BOM 等）
 * 官方下发的 username 偶尔会带这些字符（例如「‏⁧⁧」），直接显示会错位
 */
function 清理昵称($name) {
    if (!is_string($name) || $name === '') return '';
    $clean = preg_replace('/[\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{FFA0}]/u', '', $name);
    if ($clean === null) return trim($name);
    return trim($clean);
}

/**
 * 从事件原始数据中提取发送者资料
 *
 * 官方 v2 事件在 d.author 下新增/补齐了以下字段：
 *   - username        用户昵称（私聊 C2C 现已下发，此前为空）
 *   - avatar          头像链接（私聊与群聊均下发）
 *   - member_nickname 群昵称（仅群聊场景）
 *
 * @param string|array|null $rawData 事件原始 JSON 或数组
 * @return array{name:string,avatar:string,group_nickname:string,member_role:string,is_bot:bool}
 */
function extractAuthorProfile($rawData) {
    $result = [
        'name' => '',
        'avatar' => '',
        'group_nickname' => '',
        'member_role' => '',
        'is_bot' => false,
    ];
    if (empty($rawData)) return $result;

    $event = is_array($rawData) ? $rawData : json_decode((string)$rawData, true);
    if (!is_array($event)) return $result;

    $d = (isset($event['d']) && is_array($event['d'])) ? $event['d'] : $event;
    $author = (isset($d['author']) && is_array($d['author'])) ? $d['author'] : [];
    if (empty($author)) return $result;

    $result['name'] = 清理昵称($author['username'] ?? '');
    $result['group_nickname'] = 清理昵称($author['member_nickname'] ?? '');
    $result['member_role'] = (string)($author['member_role'] ?? '');
    $result['is_bot'] = !empty($author['bot']);

    $avatar = (string)($author['avatar'] ?? '');
    // 只接受 http(s) 链接，避免把异常数据写进 img src
    if ($avatar !== '' && preg_match('#^https?://#i', $avatar)) {
        $result['avatar'] = $avatar;
    }
    return $result;
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

        // 从事件中提取昵称/头像/群昵称快照（发送方向的 raw_data 是接口响应，提取结果为空）
        $authorProfile = extractAuthorProfile($rawData);

        db()->execute(
            "INSERT INTO messages (appid, direction, source_type, target_id, user_id, content_type, content, message_id, raw_data, user_name, user_avatar, group_nickname)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$appid, $direction, $sourceType, $targetId, $userId, $contentType, $content, $messageId, $rawData,
             $authorProfile['name'], $authorProfile['avatar'], $authorProfile['group_nickname']]
        );
    } catch (Exception $e) {
        wlog("记录消息失败: " . $e->getMessage(), $appid);
    }
}

// ==================== 后台本地上传媒体持久化 ====================
// 后台上传的图片/语音/视频/文件以二进制形式经官方 /files 接口(base64 file_data)发送,
// 官方不回传可展示的URL; 此处把二进制留存到 data/uploads/, 日志记录站内相对URL,
// 由 admin/api/chat_api.php?action=media 鉴权后流式输出, 使聊天记录页可回看媒体内容。

/**
 * 保存二进制媒体到 data/uploads/{appid}/
 * @param string $data 二进制内容
 * @param string $ext  扩展名(不含点, 如 jpg/png/mp4/silk/pdf)
 * @return string 站内相对URL (api/chat_api.php?action=media&f=...); 失败返回 ''
 */
function 保存本地媒体($data, $ext = 'bin') {
    try {
        if ($data === '' || $data === false || $data === null) return '';
        $ext = preg_replace('/[^A-Za-z0-9]/', '', (string)$ext);
        if ($ext === '' || strlen($ext) > 8) $ext = 'bin';
        $appid = defined('appid') ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string)appid) : 'unknown';
        if ($appid === '') $appid = 'unknown';
        $dir = __DIR__ . '/data/uploads/' . $appid . '/' . date('Ym');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return '';
        $name = date('d_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $name, $data) === false) return '';
        // 相对URL: 后台页面均在 admin/ 下, 页面相对路径部署于子目录也可用
        return 'api/chat_api.php?action=media&f=' . rawurlencode($appid . '/' . date('Ym') . '/' . $name);
    } catch (Exception $e) {
        return '';
    }
}

/**
 * 由 保存本地媒体 所存文件标识(appid/年月/文件名)反解出 data/uploads 下的真实路径 (带路径穿越防护)
 * @param string $f 文件标识, 如 "88888888/202609/09_HHMMSS_ab12cd.jpg"
 * @return string|null 绝对路径或 null
 */
function 本地媒体路径($f) {
    $f = trim((string)$f);
    if ($f === '' || strpos($f, '..') !== false || strpos($f, "\0") !== false) return null;
    $f = preg_replace('#^/+#', '', $f);
    $base = realpath(__DIR__ . '/data/uploads');
    if ($base === false) return null;
    $real = realpath($base . '/' . $f);
    if ($real === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) return null;
    return $real;
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

    // 没有顶层附件时, 兜底从 msg_elements 提取本条自身媒体
    // (QQ v2 真实事件格式: 图片/视频/语音等媒体在 msg_elements[].attachments; 引用消息的元素属于被引用内容, 不算本条)
    if (empty($attachments) || !is_array($attachments)) {
        $attachments = [];
        $ownIdx = '';
        $isReplyEvt = intval($d['message_type'] ?? 0) === 103;
        if (isset($d['message_scene']['ext']) && is_array($d['message_scene']['ext'])) {
            foreach ($d['message_scene']['ext'] as $extStr) {
                if (!is_string($extStr)) continue;
                if ($ownIdx === '' && preg_match('/(?<!ref_)msg_idx=([^&]+)/', $extStr, $m)) {
                    $ownIdx = $m[1];
                }
                if (strpos($extStr, 'ref_msg_idx=') !== false) {
                    $isReplyEvt = true;
                }
            }
        }
        $elements = (isset($d['msg_elements']) && is_array($d['msg_elements'])) ? $d['msg_elements'] : [];
        foreach ($elements as $el) {
            if (!is_array($el) || empty($el['attachments']) || !is_array($el['attachments'])) continue;
            if ($ownIdx !== '') {
                // 仅取本条自身元素 (msg_idx == 自己的 msg_idx), 引用消息的被引用元素不算
                if (($el['msg_idx'] ?? '') === $ownIdx) {
                    $attachments = $el['attachments'];
                    break;
                }
            } elseif (!$isReplyEvt) {
                // 无 msg_idx 且非引用事件: 带附件的元素视为本条媒体
                foreach ($el['attachments'] as $a) {
                    if (is_array($a)) $attachments[] = $a;
                }
            }
        }
    }

    // 仍然没有附件时: 先做 Markdown 消息判定, 再回退纯文字
    if (empty($attachments) || !is_array($attachments)) {
        // ==================== Markdown 消息判定 ====================
        // QQ v2 接收的 markdown 消息(msg_type=2)无 attachments, 内容为 markdown 源码
        // (含 ![图片](url)、[@昵称]、[按钮](mqqapi://...) 等)。此前一律判为"文字"导致后台
        // 无法渲染 MD 卡片, 此处三重判定: 显式 msg_type / 元素 content_type / 内容强特征。
        $mdDetected = false;
        $mdElementContent = '';
        if (intval($d['msg_type'] ?? 0) === 2) {
            // 显式 msg_type=2
            $mdDetected = true;
        } elseif (isset($d['msg_elements']) && is_array($d['msg_elements'])) {
            foreach ($d['msg_elements'] as $el) {
                if (is_array($el) && strtolower((string)($el['content_type'] ?? '')) === 'markdown') {
                    $mdDetected = true;
                    if ($mdElementContent === '' && isset($el['content']) && trim((string)$el['content']) !== '') {
                        $mdElementContent = (string)$el['content'];
                    }
                }
            }
        }
        if (!$mdDetected && $textContent !== ''
            && preg_match('/!\[[^\]\n]*\]\s*\(|\]\(mqqapi:/u', $textContent)) {
            // 内容强特征兜底(图片语法/QQ内联按钮, 普通聊天文本几乎不会出现)
            $mdDetected = true;
        }
        if ($mdDetected) {
            $result['content_type'] = 'MD';
            // 优先 d.content (QQ 接收事件通常携带完整源码); 缺失时回退 markdown 元素拼接
            $result['content'] = $textContent !== '' ? $textContent : $mdElementContent;
            return $result;
        }
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

// ==================== 引用(回复)消息解析函数 ====================

/**
 * 从被引用元素提取附件列表 (供引用块缩略预览使用)
 * 兼容多种可能的事件形态: attachments[] 数组 / url、image_url 等单字段 / file_info 对象
 * @param array|null $el 被引用的 msg_elements 元素
 * @return array [{type:image|video|voice|file, url, filename, width, height}] (最多9个)
 */
function extractQuoteAttachments($el) {
    $out = [];
    if (!is_array($el)) return $out;

    $candidates = [];
    if (isset($el['attachments']) && is_array($el['attachments'])) {
        foreach ($el['attachments'] as $a) {
            if (is_array($a)) $candidates[] = $a;
        }
    }
    // 单字段兜底 (不同事件版本字段名可能不同)
    $flat = [];
    foreach (['image_url' => 'image', 'video_url' => 'video', 'voice_url' => 'audio'] as $k => $ct) {
        if (!empty($el[$k]) && is_string($el[$k])) {
            $flat[] = ['url' => $el[$k], 'content_type' => $ct];
        }
    }
    if (!empty($el['url']) && is_string($el['url'])) {
        $flat[] = ['url' => $el['url'], 'content_type' => ''];
    }
    if (isset($el['file_info']) && is_array($el['file_info'])) {
        $flat[] = $el['file_info'];
    }

    foreach (array_merge($candidates, $flat) as $a) {
        $url = trim((string)($a['url'] ?? ''), "` \t\n\r ");
        if ($url === '') continue;
        // 允许绝对URL(http/https)与站内相对路径(后台本地媒体: api/chat_api.php?action=media / /xxx)
        if (!preg_match('/^https?:\/\//i', $url) && $url[0] !== '/' && strpos($url, 'api/') !== 0) continue;
        $ct  = strtolower((string)($a['content_type'] ?? ''));
        $fn  = (string)($a['filename'] ?? ($a['file_name'] ?? ''));
        $type = 'file';
        if (strpos($ct, 'image/') === 0 || $ct === 'image') {
            $type = 'image';
        } elseif (strpos($ct, 'video/') === 0 || $ct === 'video') {
            $type = 'video';
        } elseif (strpos($ct, 'audio/') === 0 || in_array($ct, ['voice', 'audio', 'silk', 'application/silk'], true)) {
            $type = 'voice';
        } else {
            // 按扩展名兜底判断
            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) $type = 'image';
            elseif (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm'], true)) $type = 'video';
            elseif (in_array($ext, ['mp3', 'wav', 'silk', 'amr', 'aac', 'm4a'], true)) $type = 'voice';
        }
        $out[] = [
            'type'     => $type,
            'url'      => $url,
            'filename' => $fn,
            'width'    => isset($a['width']) ? intval($a['width']) : null,
            'height'   => isset($a['height']) ? intval($a['height']) : null
        ];
        if (count($out) >= 9) break; // 上限保护
    }
    return $out;
}

/**
 * 从原始事件数据解析引用(回复)消息信息 (message_type=103)
 * 事件结构 (GROUP_MESSAGE_CREATE 实测):
 *   d.message_scene.ext[]: "ref_msg_idx=REFIDX_xxx" (被引用消息), "msg_idx=REFIDX_yyy" (本条)
 *   d.msg_elements[]:      被引用消息的元素列表 (msg_idx == ref_msg_idx 的项即被引用内容)
 *   d.content / d.parallel_message.msg_nodes[]: 本条消息自身的文字
 * ⚠️ "ref_msg_idx=" 包含子串 "msg_idx=", 提取时必须精确匹配键名
 * @param array|string|null $rawData 完整原始事件 (数组或JSON字符串)
 * @return array{own_idx:string, ref_idx:string, quote:array|null}
 *   quote = ['ref_idx'=>string, 'message_type'=>int, 'content'=>string, 'attachments'=>array]
 */
function parseQuoteInfo($rawData) {
    if (is_string($rawData)) {
        $decoded = json_decode($rawData, true);
        $rawData = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($rawData)) {
        return ['own_idx' => '', 'ref_idx' => '', 'quote' => null];
    }
    $d = is_array($rawData['d'] ?? null) ? $rawData['d'] : [];

    // 1) 提取 message_scene.ext 中的消息索引
    $ownIdx = '';
    $refIdx = '';
    if (isset($d['message_scene']['ext']) && is_array($d['message_scene']['ext'])) {
        foreach ($d['message_scene']['ext'] as $extStr) {
            if (!is_string($extStr)) continue;
            if ($refIdx === '' && preg_match('/(?:^|&)ref_msg_idx=([^&]+)/', $extStr, $m)) {
                $refIdx = $m[1];
            }
            if ($ownIdx === '' && preg_match('/(?<!ref_)msg_idx=([^&]+)/', $extStr, $m)) {
                $ownIdx = $m[1];
            }
        }
    }

    // 1.5) 发送记录兼容: 记录发送 存储的官方发送响应形如 {"id":"...","ext_info":{"ref_idx":"REFIDX_xxx"}}
    //      提取 ref_idx 作为机器人本条消息的引用索引, 使后台聊天页可引用机器人自己发的消息
    if ($ownIdx === '') {
        $extInfo = (isset($rawData['ext_info']) && is_array($rawData['ext_info'])) ? $rawData['ext_info'] : [];
        if (!empty($extInfo['ref_idx'])) {
            $ownIdx = (string)$extInfo['ref_idx'];
        } elseif (isset($rawData['ref_idx']) && is_string($rawData['ref_idx']) && $rawData['ref_idx'] !== '') {
            $ownIdx = $rawData['ref_idx'];
        }
    }

    // 2) 判断是否为回复/引用消息
    // ⚠️ 不能用 !empty($elements) 判定: QQ v2 群事件中普通消息(含图片等媒体消息)也携带
    //    msg_elements (内含本条自身元素, msg_idx == 自己的 msg_idx), 会把直接图片消息误判为引用。
    //    可靠判据: ext 中存在 ref_msg_idx, 或事件级 message_type=103 (群回复消息)
    $elements = (isset($d['msg_elements']) && is_array($d['msg_elements'])) ? $d['msg_elements'] : [];
    $isReply = $refIdx !== '' || intval($d['message_type'] ?? 0) === 103;
    if (!$isReply) {
        return ['own_idx' => $ownIdx, 'ref_idx' => $refIdx, 'quote' => null];
    }

    // 3) 定位被引用元素 (msg_idx == ref_msg_idx)
    $el = null;
    if ($refIdx !== '') {
        foreach ($elements as $e) {
            if (is_array($e) && ($e['msg_idx'] ?? '') === $refIdx) {
                $el = $e;
                break;
            }
        }
    }
    $elType  = $el ? intval($el['message_type'] ?? -1) : -1;
    $content = $el ? trim((string)($el['content'] ?? '')) : '';
    $atts    = extractQuoteAttachments($el);

    // 4) 引用文案: 媒体引用用类型占位, 其余按元素类型给占位
    if ($content === '' && !empty($atts)) {
        $labels = ['image' => '图片', 'video' => '视频', 'voice' => '语音', 'file' => '文件'];
        $names  = array_values(array_unique(array_map(function ($a) use ($labels) {
            return $labels[$a['type']] ?? '文件';
        }, $atts)));
        $content = '[引用' . implode('/', $names) . ']';
    } elseif ($content === '') {
        // 元素缺失/未命中时按类型给占位 (0文字 1图文 2markdown 3ark 4embed 5媒体)
        $typeNames = [0 => '[引用文字消息]', 1 => '[引用图文消息]', 2 => '[引用Markdown消息]',
                      3 => '[引用Ark卡片]', 4 => '[引用Embed卡片]', 5 => '[引用媒体消息]'];
        $content = $typeNames[$elType] ?? '[引用消息]';
    }
    if (mb_strlen($content) > 500) {
        $content = mb_substr($content, 0, 500) . '…';
    }

    return [
        'own_idx' => $ownIdx,
        'ref_idx' => $refIdx,
        'quote'   => [
            'ref_idx'      => $refIdx,
            'message_type' => $elType,
            'content'      => $content,
            'attachments'  => $atts
        ]
    ];
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
    // 默认校验证书；仅在明确设置 ssl_insecure=1 时降级（兼容极老旧 CA 环境）
    static $sslInsecure = null;
    if ($sslInsecure === null) {
        try {
            $sslInsecure = db()->getSetting('ssl_insecure', '0') === '1';
        } catch (Throwable $e) {
            $sslInsecure = false;
        }
    }
    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$sslInsecure);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslInsecure ? 0 : 2);
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

// ==================== multipart/form-data 分片上传请求函数 ====================
// 用于上传文件(图片/视频/语音/文件)到 QQ Bot API，支持二进制文件流上传
// 与 curl() 不同，本函数使用 multipart/form-data 编码，适用于大文件与二进制内容
// 参数:
//   $url     - 完整请求 URL
//   $headers - 请求头数组（不含 Content-Type，由 curl 自动生成 boundary）
//   $fields  - 表单字段数组，支持 CURLFile 对象或字符串值
// 返回: 响应体字符串
function curlMultipart($url, $headers, $fields) {
    $url = str_replace(" ", "%20", $url);
    if (empty($headers)) {
        $headers = [];
    } elseif (!is_array($headers)) {
        parse_str($headers, $headers);
    }
    // 移除可能存在的 Content-Type，让 curl 自动设置 multipart boundary
    $headers = array_values(array_filter($headers, function($h) {
        return stripos($h, 'Content-Type:') !== 0;
    }));
    $ch = curl_init();
    static $sslInsecure2 = null;
    if ($sslInsecure2 === null) {
        try {
            $sslInsecure2 = db()->getSetting('ssl_insecure', '0') === '1';
        } catch (Throwable $e) {
            $sslInsecure2 = false;
        }
    }
    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$sslInsecure2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslInsecure2 ? 0 : 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
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
    // 使用 PHP 内置 bcrypt/argon2（自适应成本，抗离线暴力破解）
    return password_hash($password, PASSWORD_DEFAULT);
}

function passwordVerify($password, $stored) {
    $stored = (string)$stored;
    // 新版 bcrypt / argon2 哈希
    if ($stored !== '' && $stored[0] === '$') {
        return password_verify($password, $stored);
    }
    // 兼容旧版 sha256 加盐哈希（登录成功后由 Auth::login 自动升级）
    if (strpos($stored, 'sha256:') === 0) {
        $parts = explode(':', $stored);
        if (count($parts) !== 3) return false;
        return hash_equals($parts[2], hash('sha256', $parts[1] . $password));
    }
    // 明文兼容（仅用于极旧数据，登录后自动升级）
    return hash_equals($stored, (string)$password);
}

function isWeakPassword($password) {
    $weak = ['admin', '123456', 'password', 'admin123', '12345678', ''];
    return in_array(strtolower($password), $weak);
}

// ==================== 安全文件写入助手（修复 Permission denied 问题） ====================
// 场景: data/logs/ws_daemon.log 等文件可能由 root(cron/终端) 创建，
// PHP-FPM(www用户) 无权写入导致 file_put_contents 报 "Failed to open stream: Permission denied"
// 策略: 常规写入 → 尝试 chmod 0666 后重写 → 目录可写时删除重建 → 全部失败返回错误及修复建议

/**
 * 确保目录存在且尽量可写
 * @param string $dir 目录路径
 * @return bool
 */
function ensureWritableDir($dir) {
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }
        // 尝试放开权限，保证 www/root 双向可写
        @chmod($dir, 0777);
    }
    return is_writable($dir);
}

/**
 * 安全写入文件（自动降级修复权限问题）
 * @param string $file    目标文件
 * @param string $content 写入内容
 * @param bool   $append  是否追加写入
 * @return array ['success'=>bool, 'message'=>string]
 */
function writeFileSafe($file, $content, $append = false) {
    // 先确保目录存在
    if (!ensureWritableDir(dirname($file))) {
        return [
            'success' => false,
            'message' => '目录不可写: ' . dirname($file)
        ];
    }

    $flags = ($append ? FILE_APPEND : 0) | LOCK_EX;
    // 第一次尝试直接写入（抑制warning，由本函数返回错误）
    $result = @file_put_contents($file, $content, $flags);
    if ($result !== false) {
        return ['success' => true, 'message' => ''];
    }

    // 第二次: 文件存在但属主不对（如 root 创建），尝试 chmod 后重写
    if (file_exists($file)) {
        if (@chmod($file, 0666)) {
            $result = @file_put_contents($file, $content, $flags);
            if ($result !== false) {
                return ['success' => true, 'message' => ''];
            }
        }
        // 第三次: 目录可写时，删除旧文件重建（要求目录属主正确）
        if (is_writable(dirname($file))) {
            @unlink($file);
            clearstatcache(true, $file);
            $result = @file_put_contents($file, $content, $flags);
            if ($result !== false) {
                @chmod($file, 0666);
                return ['success' => true, 'message' => ''];
            }
        }
    }

    // 全部失败: 给出可操作的修复建议
    return [
        'success' => false,
        'message' => '无法写入文件: ' . basename($file) . '（Permission denied）。'
                   . '该文件可能由 root 用户创建，请在SSH执行: '
                   . 'chown -R www:www ' . dirname(dirname($file)) . ' && chmod -R 755 ' . dirname($file)
                   . ' （或临时 chmod -R 777 ' . dirname($file) . '）'
    ];
}

/**
 * 安全清空文件内容（用于清空日志等场景）
 * @param string $file 目标文件
 * @return array ['success'=>bool, 'message'=>string]
 */
function truncateFileSafe($file) {
    if (!file_exists($file)) {
        return ['success' => true, 'message' => ''];
    }
    return writeFileSafe($file, '');
}

// ==================== API 接口域名集中管理 ====================
// 官方 2026-08-10 起所有接口域名统一为 https://api.bot.qq.com（正式/沙箱相同），
// access_token 接口同步迁移至 https://api.bot.qq.com/app/getAppAccessToken。
// 旧版 api.sgroup.qq.com / sandbox.api.sgroup.qq.com 已被官方停用，相关切换代码已移除。
/**
 * 获取QQ机器人API基础域名（正式/沙箱统一为 api.bot.qq.com）
 * @param string $env 环境（正式/沙箱，保留参数兼容历史调用）
 * @return string 例如 https://api.bot.qq.com
 */
function getApiBase($env = '正式') {
    return 'https://api.bot.qq.com';
}

/**
 * 获取 access_token 接口地址
 * 官方文档: https://api.bot.qq.com/app/getAppAccessToken
 * @return string
 */
function getApiTokenUrl() {
    return 'https://api.bot.qq.com/app/getAppAccessToken';
}

/**
 * 清理QQ接口返回文本中的HTML实体（如 &nbsp;）与多余空白
 * @param string $str
 * @return string
 */
function cleanApiText($str) {
    $str = (string)$str;
    if ($str === '') {
        return '';
    }
    $decoded = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // 多次解码防止二次编码实体（&amp;nbsp; 等）
    $again = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($again !== $decoded) {
        $decoded = $again;
    }
    // 将不间断空格/制表符归一为普通空格，压缩连续空白
    $decoded = str_replace(["\xc2\xa0", "\t", "\r"], ' ', $decoded);
    $decoded = preg_replace('/ {2,}/', ' ', $decoded);
    return trim($decoded);
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

// ==================== 插件名规范化辅助（修复 GBK 等非 UTF-8 文件名插件后台显示为乱码/空白的问题） ====================

/**
 * 将插件文件名字节规范化为 UTF-8 显示名
 * 第三方插件常由 Windows 打包, 文件名为 GBK/GB18030 字节序列:
 * PHP8 下 htmlspecialchars() 输出乱码、json_encode() 直接返回 false,
 * 导致插件过多时(安装的第三方插件越多概率越大)后台插件名显示异常。
 * 已是合法 UTF-8 时原样返回(幂等, 对 DB 中旧值也适用)。
 */
function 插件规范名($rawName) {
    $rawName = (string)$rawName;
    if ($rawName === '' || mb_check_encoding($rawName, 'UTF-8')) return $rawName;
    foreach (['GB18030', 'GBK', 'BIG5', 'EUC-CN'] as $enc) {
        $conv = @mb_convert_encoding($rawName, 'UTF-8', $enc);
        if (is_string($conv) && $conv !== '' && mb_check_encoding($conv, 'UTF-8')) {
            return $conv;
        }
    }
    // 兑底: 无效字节替换为 U+FFFD, 保证 json_encode/htmlspecialchars 不再失败
    return mb_scrub($rawName, 'UTF-8');
}

/**
 * 由规范化插件名定位 plugin 目录下的真实文件(处理 GBK 等历史文件名)
 * 优先精确匹配 UTF-8 文件名, 再扫描目录按规范名比对; 未找到返回 null
 */
function 插件真实文件($name) {
    $name = 插件规范名($name);
    if ($name === '' || strpos($name, '/') !== false || strpos($name, "\\") !== false) return null;
    $dir = APP_ROOT . 'plugin/';
    if (!is_dir($dir)) return null;
    $direct = $dir . $name . '.php';
    if (is_file($direct)) return $direct;
    $files = glob($dir . '*.php');
    if (is_array($files)) {
        foreach ($files as $f) {
            if (插件规范名(basename($f, '.php')) === $name) return $f;
        }
    }
    return null;
}

/**
 * 插件名安全校验(用于读/写/删除等文件操作)
 * 相比旧正则(仅字母数字中文)放宽为: 允许空格/括号/引号/表情等任意文件系统合法字符,
 * 仅拦截路径穿越与空名; 返回规范化后的名称, 不合法返回 false
 */
function 插件名过滤($name) {
    $name = trim((string)$name);
    if ($name === '' || $name === '.' || $name === '..') return false;
    if (strpos($name, "/") !== false || strpos($name, "\\") !== false || strpos($name, "\0") !== false) return false;
    return 插件规范名($name);
}

/**
 * 解析插件文件头元信息
 * 支持 @name/@version/@author/@description/@category/@link/@license 与
 * "Plugin Name:" 风格的 WordPress 头。
 *
 * @param string $fileOrName 插件文件绝对路径或插件名
 * @return array{name:string,version:string,author:string,description:string,category:string,link:string,license:string,file:string}
 */
function 插件元信息($fileOrName) {
    $file = is_file($fileOrName) ? $fileOrName : 插件真实文件($fileOrName);
    $result = ['name' => '', 'version' => '', 'author' => '', 'description' => '', 'category' => '', 'link' => '', 'license' => '', 'file' => (string)$file];
    if ($file === null || !is_file($file)) {
        return $result;
    }
    $result['name'] = basename($file, '.php');
    $head = @file_get_contents($file, false, null, 0, 8192);
    if ($head === false) return $result;

    $map = [
        'name'        => ['@name', '@plugin-name', 'Plugin Name:'],
        'version'     => ['@version', '@plugin-version', 'Version:'],
        'author'      => ['@author', '@plugin-author', 'Author:'],
        'description' => ['@description', '@plugin-description', 'Description:'],
        'category'    => ['@category', '@plugin-category', 'Category:'],
        'link'        => ['@link', '@plugin-link', 'Plugin URI:'],
        'license'     => ['@license', 'License:'],
    ];
    foreach ($map as $field => $tags) {
        foreach ($tags as $tag) {
            $pattern = '/' . preg_quote($tag, '/') . '\s*[:：]?\s*([^\r\n\*]+)/iu';
            if (preg_match($pattern, $head, $m)) {
                $value = trim($m[1]);
                if ($value !== '') {
                    if ($field === 'name' && $value === $result['name']) break;
                    $result[$field] = $value;
                    break;
                }
            }
        }
    }
    return $result;
}

/**
 * 插件代码静态安全扫描（安装/上传前调用）
 * 说明：静态规则只能降低风险，不能替代人工审计。
 *
 * @param string $code PHP 源码
 * @return array{status:string,issues:array,summary:string}
 */
function 插件安全扫描($code) {
    $code = (string)$code;
    if (trim($code) === '') {
        return ['status' => 'block', 'issues' => [['level' => 'block', 'line' => 1, 'code' => 'empty', 'message' => '代码为空']], 'summary' => '代码为空'];
    }
    if (stripos($code, '<?php') === false) {
        return ['status' => 'block', 'issues' => [['level' => 'block', 'line' => 1, 'code' => 'no_php', 'message' => '缺少 <?php 起始标签']], 'summary' => '不是有效的 PHP 文件'];
    }
    $issues = [];
    try {
        token_get_all($code, TOKEN_PARSE);
    } catch (Throwable $e) {
        $issues[] = ['level' => 'block', 'line' => max(1, (int)$e->getLine()), 'code' => 'syntax', 'message' => 'PHP 语法错误：' . $e->getMessage()];
    }

    // 注释与字符串替换为空格，避免文档里的 eval 字样误报
    $clean = '';
    foreach (token_get_all($code) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $clean .= preg_replace('/[^\n]/', ' ', $token[1]);
            } else {
                $clean .= $token[1];
            }
        } else {
            $clean .= $token;
        }
    }
    $lineOf = function ($offset) use ($clean) {
        return substr_count(substr($clean, 0, max(0, $offset)), "\n") + 1;
    };

    $blockRules = [
        'eval'            => ['/\beval\s*\(/i', 'eval() 可执行任意代码'],
        'assert'          => ['/\bassert\s*\(/i', 'assert() 可被用于执行代码'],
        'create_function' => ['/\bcreate_function\s*\(/i', 'create_function() 可动态创建函数'],
        'shell'           => ['/\b(shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(/i', '调用系统命令执行函数'],
        'backtick'        => ['/`[^`\n]{1,500}`/', '反引号执行系统命令'],
        'preg_e'          => ['/preg_replace\s*\(\s*([\'"])[^\'"]*\/[a-z]*e[a-z]*\1/i', 'preg_replace /e 修饰符可执行代码'],
    ];
    foreach ($blockRules as $ruleCode => $rule) {
        if (preg_match_all($rule[0], $clean, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $m) {
                $issues[] = ['level' => 'block', 'line' => $lineOf($m[1]), 'code' => $ruleCode, 'message' => $rule[1]];
            }
        }
    }
    $warnRules = [
        'file_write'  => ['/\b(file_put_contents|fwrite|fopen|rename|copy|unlink|rmdir|chmod)\s*\(/i', '存在文件写入/删除操作，请确认路径受控'],
        'network'     => ['/\b(curl_init|curl_exec|fsockopen|stream_socket_client|mail)\s*\(/i', '存在网络/邮件调用，请确认目标可信'],
        'dynamic'     => ['/\b(base64_decode|gzinflate|str_rot13|extract|parse_str)\s*\(/i', '存在动态解码/变量导入，请人工确认用途'],
        'globals'     => ['/\$GLOBALS\b/', '直接操作 $GLOBALS'],
        'include_var' => ['/\b(include|require)(_once)?\s*\(?\s*\$/', '动态包含文件，可能存在任意文件包含风险'],
        'db'          => ['/\bnew\s+PDO\s*\(/i', '插件直接创建数据库连接，请确认不会破坏主库'],
    ];
    foreach ($warnRules as $ruleCode => $rule) {
        if (preg_match_all($rule[0], $clean, $matches, PREG_OFFSET_CAPTURE)) {
            foreach (array_slice($matches[0], 0, 8) as $m) {
                $issues[] = ['level' => 'warn', 'line' => $lineOf($m[1]), 'code' => $ruleCode, 'message' => $rule[1]];
            }
        }
    }

    $seen = [];
    $unique = [];
    foreach ($issues as $issue) {
        $key = $issue['level'] . '|' . $issue['line'] . '|' . $issue['code'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = $issue;
    }
    usort($unique, function ($a, $b) {
        return $a['line'] === $b['line'] ? strcmp($a['code'], $b['code']) : $a['line'] <=> $b['line'];
    });
    $hasBlock = false; $hasWarn = false;
    foreach ($unique as $issue) {
        if ($issue['level'] === 'block') $hasBlock = true;
        if ($issue['level'] === 'warn') $hasWarn = true;
    }
    $status = $hasBlock ? 'block' : ($hasWarn ? 'warn' : 'pass');
    $summary = $status === 'pass' ? '未发现明显风险' : ($status === 'warn' ? '存在需人工确认的调用' : '检测到高危代码，禁止直接安装');
    return ['status' => $status, 'issues' => $unique, 'summary' => $summary];
}

/**
 * 默认插件市场地址（官方在线市场）
 * 后台未保存自定义地址时使用；可在「插件市场 → 市场源」改成自建市场。
 */
if (!defined('DEFAULT_MARKET_BASE')) {
    define('DEFAULT_MARKET_BASE', 'https://3000-ac02071770e941319c9aa30ed5287647.e2b.gz1.sandbox.cloudstudio.club');
}

/**
 * 解析插件市场地址：优先使用后台保存的配置，否则使用默认在线市场。
 * 如需使用同域自建市场，在后台「市场源」填写完整地址（如 https://your.site/market）。
 */
function 市场地址() {
    $saved = '';
    try {
        $saved = (string)db()->getSetting('market_base_url', '');
    } catch (Exception $e) {}
    if ($saved !== '' && preg_match('#^https?://#i', $saved)) {
        return rtrim($saved, '/');
    }
    return rtrim(DEFAULT_MARKET_BASE, '/');
}

/**
 * 访问市场 HTTP（带超时/大小/协议限制，校验证书）
 *
 * @return array{ok:bool,body:string,status:int,headers:array,error:string}
 */
function 市场请求($url, $maxBytes = 2097152, $timeout = 10) {
    if (!preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'body' => '', 'status' => 0, 'headers' => [], 'error' => '仅支持 http/https 地址'];
    }
    $ch = curl_init($url);
    $headers = [];
    $body = '';
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GuanjiBot-MarketClient/1.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json,text/plain,*/*']);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$headers) {
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $name = strtolower(trim(substr($line, 0, $pos)));
            $headers[$name] = trim(substr($line, $pos + 1));
        }
        return strlen($line);
    });
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$body, $maxBytes) {
        if (strlen($body) + strlen($chunk) > $maxBytes) return 0;
        $body .= $chunk;
        return strlen($chunk);
    });
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($ok === false) {
        return ['ok' => false, 'body' => $body, 'status' => $status, 'headers' => $headers, 'error' => $err !== '' ? $err : '请求失败或响应超过大小限制'];
    }
    return ['ok' => true, 'body' => $body, 'status' => $status, 'headers' => $headers, 'error' => ''];
}

/**
 * 在基地地址上拼接接口路径
 *
 * 保留基地地址自带的查询参数（例如托管平台分享链接的 ?sharecode=xxx），
 * 避免直接字符串拼接得到 https://host/?sharecode=x/api.php 这类错误地址。
 */
function 市场链接($base, $path, $params = []) {
    $base = trim((string)$base);
    $parts = parse_url($base);
    if (!$parts || empty($parts['host'])) {
        $url = rtrim($base, '/') . '/' . ltrim($path, '/');
        return $params ? $url . '?' . http_build_query($params) : $url;
    }
    $scheme = (($parts['scheme'] ?? 'https') === 'http') ? 'http' : 'https';
    $url = $scheme . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $url .= ':' . (int)$parts['port'];
    }
    $url .= rtrim($parts['path'] ?? '', '/') . '/' . ltrim($path, '/');
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    foreach ($params as $k => $v) {
        $query[$k] = $v;
    }
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

/**
 * 调用插件市场 API 并解析 JSON
 * @return array 市场返回结构；失败时返回 success=false 与 message
 */
function 市场API($base, $action, $params = [], $timeout = 10) {
    $base = rtrim((string)$base, '/');
    if ($base === '') {
        return ['success' => false, 'message' => '未配置插件市场地址'];
    }
    $url = 市场链接($base, '/api.php', array_merge(['action' => $action], $params));
    $resp = 市场请求($url, 2097152, $timeout);
    if (!$resp['ok']) {
        return ['success' => false, 'message' => '无法连接插件市场：' . $resp['error'], '_http' => $resp['status']];
    }
    if ($resp['status'] !== 200) {
        return ['success' => false, 'message' => '市场返回 HTTP ' . $resp['status'], '_http' => $resp['status']];
    }
    $data = json_decode($resp['body'], true);
    if (!is_array($data)) {
        return ['success' => false, 'message' => '市场响应格式错误（可能不是官鸡插件市场）', '_http' => $resp['status']];
    }
    return $data;
}

/** 格式化字节数为可读大小 */
function 格式化大小($bytes) {
    $bytes = (int)$bytes;
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// ==================== 后台操作记录（写入 system_logs, 在「操作记录」页可视化） ====================

/**
 * 记录一条后台管理员操作
 * @param string $action 动作名, 如 "插件启用" "机器人删除"
 * @param string $detail 详情(纯文本, 不含敏感信息)
 * @param string $appid 关联机器人(可空)
 */
function 后台操作记录($action, $detail = '', $appid = '') {
    try {
        $user = class_exists('Auth') && method_exists('Auth', 'currentUsername') ? Auth::currentUsername() : '';
        $line = '[' . $action . ']' . ($user !== '' ? '(' . $user . ')' : '') . ($detail !== '' ? ' ' . $detail : '');
        db()->execute(
            "INSERT INTO system_logs (appid, log_type, content, level) VALUES (?, '后台操作', ?, 'INFO')",
            [$appid !== '' ? $appid : 'admin', $line]
        );
    } catch (Exception $e) {
        // 记录失败不影响主流程
    }
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
            $params[] = is_array($data[$field]) ? json_encode($data[$field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $data[$field];
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
        // [bugfix] 原版只增不删, event_dedup 表无限膨胀; 去重仅需覆盖官方重试窗口, 保留3天即可
        if (random_int(1, 50) === 1) { // 2% 概率顺带清理, 避免每次写事件都多一条 DELETE
            db()->execute("DELETE FROM event_dedup WHERE created_at < datetime('now','localtime','-3 day')");
        }
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

function recordUser($appid, $userId, $nickname = '', $avatar = '') {
    if ($userId === null || $userId === '') return;
    $nickname = 清理昵称($nickname);
    $avatar = is_string($avatar) ? trim($avatar) : '';
    if ($avatar !== '' && !preg_match('#^https?://#i', $avatar)) $avatar = '';

    if ($nickname !== '') {
        db()->execute(
            "INSERT OR IGNORE INTO users (appid, user_id, nickname, avatar) VALUES (?, ?, ?, ?)",
            [$appid, $userId, $nickname, $avatar]
        );
        if ($avatar !== '') {
            db()->execute(
                "UPDATE users SET nickname = ?, avatar = ?, last_active = datetime('now','localtime') WHERE appid = ? AND user_id = ?",
                [$nickname, $avatar, $appid, $userId]
            );
        } else {
            db()->execute(
                "UPDATE users SET nickname = ?, last_active = datetime('now','localtime') WHERE appid = ? AND user_id = ?",
                [$nickname, $appid, $userId]
            );
        }
    } else {
        db()->execute(
            "INSERT OR IGNORE INTO users (appid, user_id, nickname, avatar) VALUES (?, ?, '', ?)",
            [$appid, $userId, $avatar]
        );
        if ($avatar !== '') {
            db()->execute(
                "UPDATE users SET avatar = ?, last_active = datetime('now','localtime') WHERE appid = ? AND user_id = ?",
                [$avatar, $appid, $userId]
            );
        } else {
            db()->execute(
                "UPDATE users SET last_active = datetime('now','localtime') WHERE appid = ? AND user_id = ?",
                [$appid, $userId]
            );
        }
    }
}

function recordGroup($appid, $groupId, $groupName = '') {
    if ($groupId === null || $groupId === '') return;
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

/**
 * 标记群聊状态
 *
 * GROUP_DEL_ROBOT 事件新增 dismiss 字段：
 *   dismiss=true  → 群聊已解散，机器人被动移除
 *   dismiss=false → 机器人被群主/管理员移出（群仍然存在）
 *
 * @param bool $dismissed true=已解散；false=恢复为正常（重新加群时复位）
 */
function markGroupDismissed($appid, $groupId, $dismissed = true) {
    if (empty($appid) || empty($groupId)) return;
    recordGroup($appid, $groupId);
    if ($dismissed) {
        db()->execute(
            "UPDATE groups SET status = 'dismissed', dismissed_at = datetime('now','localtime') WHERE appid = ? AND group_id = ?",
            [$appid, $groupId]
        );
    } else {
        db()->execute(
            "UPDATE groups SET status = 'active', dismissed_at = '' WHERE appid = ? AND group_id = ?",
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
    // 第一步：获取 access_token（域名统一后走 api.bot.qq.com，与接口域名模式保持一致）
    $tokenUrl = getApiTokenUrl();
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

    // 第二步：根据环境选择 API 域名（支持后台切换新版/旧版域名）
    $apiBase = getApiBase($env);
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

/**
 * 获取群信息并更新数据库
 * 参照官方文档: GET /v2/groups/{group_openid}/info
 * 返回群名称、群简介、群分类、群标签、群成员人数
 *
 * @param string $appid       机器人AppID
 * @param string $groupOpenid 群OpenID
 * @param bool   $force       是否强制刷新（忽略缓存）
 * @return array              ['success'=>bool, 'data'=>..., 'message'=>...]
 */
function fetchAndUpdateGroupInfo($appid, $groupOpenid, $force = false) {
    if (empty($appid) || empty($groupOpenid)) {
        return ['success' => false, 'message' => 'appid 或 group_openid 为空'];
    }

    // 非强制刷新时，先检查数据库缓存（群名称/简介/分类/标签/成员数等字段）
    // 缓存有效期 TTL_GROUP_INFO_TTL 秒(默认30分钟)，过期后自动重新拉取，确保群标签状态及时更新
    if (!defined('TTL_GROUP_INFO_TTL')) define('TTL_GROUP_INFO_TTL', 1800);
    if (!$force) {
        $existing = db()->fetch(
            "SELECT group_name, group_finger_memo, group_class_text, group_tags, group_member_num, info_updated_at FROM groups WHERE appid = ? AND group_id = ?",
            [$appid, $groupOpenid]
        );
        if ($existing && !empty($existing['group_name'])) {
            // 检查缓存是否仍在有效期内
            $infoUpdatedAt = strtotime((string)($existing['info_updated_at'] ?? ''));
            $cacheFresh = ($infoUpdatedAt !== false && (time() - $infoUpdatedAt) < TTL_GROUP_INFO_TTL);
            if (!$cacheFresh) {
                // 缓存过期：重新从API拉取最新群信息（含标签），失败时降级用旧缓存
                $refreshed = null;
                try {
                    $refreshed = fetchAndUpdateGroupInfo($appid, $groupOpenid, true);
                } catch (Throwable $e) {
                    $refreshed = null;
                }
                if (is_array($refreshed) && !empty($refreshed['success'])) {
                    return $refreshed;
                }
            }
            return ['success' => true, 'message' => $cacheFresh ? '已缓存' : '已过期(使用旧缓存)', 'data' => $existing];
        }
    }

    $bot = getBot($appid);
    if (!$bot) {
        return ['success' => false, 'message' => '机器人不存在'];
    }

    // 第一步：获取 access_token（域名统一后走 api.bot.qq.com）
    $tokenUrl = getApiTokenUrl();
    $postData = json_encode([
        'appId'        => (string)$appid,
        'clientSecret' => $bot['secret']
    ]);
    $tokenResp = curl($tokenUrl, 'POST', ['Content-Type: application/json'], $postData);
    $tokenData = json_decode($tokenResp, true);

    if (!$tokenData || !isset($tokenData['access_token'])) {
        return ['success' => false, 'message' => '获取Access Token失败'];
    }

    $accessToken = $tokenData['access_token'];

    // 第二步：调用获取群信息接口（支持后台切换新版/旧版域名）
    $apiBase = getApiBase($bot['env'] ?? '正式');
    $infoUrl = $apiBase . '/v2/groups/' . $groupOpenid . '/info';

    $headers = [
        'Authorization: QQBot ' . $accessToken,
        'Content-Type: application/json'
    ];

    $infoResp = curl($infoUrl, 'GET', $headers, '');
    $infoData = json_decode($infoResp, true);

    if (!$infoData || isset($infoData['code'])) {
        $errMsg = $infoData['message'] ?? $infoResp;
        return ['success' => false, 'message' => '获取群信息失败: ' . $errMsg];
    }

    $groupName = cleanApiText($infoData['group_name'] ?? '');
    $fingerMemo = cleanApiText($infoData['group_finger_memo'] ?? '');
    $classText = cleanApiText($infoData['group_class_text'] ?? '');
    $tags = $infoData['group_tags'] ?? [];
    if (is_array($tags)) {
        $tags = array_map('cleanApiText', $tags);
        $tagsStr = implode(',', array_filter($tags, function ($t) { return $t !== ''; }));
    } else {
        $tagsStr = cleanApiText($tags);
    }
    $memberNum = intval($infoData['group_member_num'] ?? 0);

    // 第三步：存入数据库（同时记录 info_updated_at 用于TTL判断，保证标签状态及时更新）
    db()->execute(
        "INSERT OR IGNORE INTO groups (appid, group_id, group_name, remark, custom_avatar) VALUES (?, ?, '', '', '')",
        [$appid, $groupOpenid]
    );
    db()->execute(
        "UPDATE groups SET group_name = ?, group_finger_memo = ?, group_class_text = ?, group_tags = ?, group_member_num = ?, info_updated_at = datetime('now','localtime'), last_active = datetime('now','localtime') WHERE appid = ? AND group_id = ?",
        [$groupName, $fingerMemo, $classText, $tagsStr, $memberNum, $appid, $groupOpenid]
    );

    return [
        'success' => true,
        'message' => '获取成功',
        'data' => [
            'group_openid'       => $infoData['group_openid'] ?? $groupOpenid,
            'group_name'         => $groupName,
            'group_finger_memo'  => $fingerMemo,
            'group_class_text'   => $classText,
            'group_tags'         => $tagsStr,
            'group_member_num'   => $memberNum,
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
