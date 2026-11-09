<?php
/**
 * 聊天记录 API
 * 提供会话列表、消息记录、发送消息、撤回消息、获取昵称等功能
 */
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

// 捕获致命错误，返回 JSON（不使用ob_start，避免与json_response的缓冲区清理冲突）
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level() > 0) ob_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 500, 'msg' => '致命错误: ' . $error['message'] . ' (文件: ' . basename($error['file']) . ' 行: ' . $error['line'] . ')'], JSON_UNESCAPED_UNICODE);
    }
});

require_once(__DIR__ . '/../../function.php');
require_once(__DIR__ . '/../../auth.php');

// 同源校验：拦截跨站 POST（与 SameSite Cookie 双重防护）
Auth::requireWriteProtection();

// 认证检查
if (!Auth::check()) {
    json_response(['code' => 401, 'msg' => '未登录或会话已过期'], 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 解析时长字符串: 30s / 10m / 1h / 7d → 秒数
function parseDuration($str) {
    $str = trim($str);
    if (!preg_match('/^(\d+)\s*(s|m|h|d)$/i', $str, $m)) return false;
    $val = intval($m[1]);
    $unit = strtolower($m[2]);
    switch ($unit) {
        case 's': return $val;
        case 'm': return $val * 60;
        case 'h': return $val * 3600;
        case 'd': return $val * 86400;
    }
    return false;
}

// 从 msg_elements 提取本条消息自身的媒体附件 (QQ v2 真实事件格式: 媒体信息在元素.attachments 里,
// 顶层 d.attachments 往往不存在)。仅限非引用消息(引用消息的元素属于被引用内容, 由 quote 块展示)。
// 优先收集 msg_idx == 本条own_idx 的元素; own_idx 未命中时回退收集全部元素附件。
function extractOwnAttachmentsFromElements($rawData, $ownIdx) {
    $out = [];
    $d = is_array($rawData['d'] ?? null) ? $rawData['d'] : null;
    if (!$d || empty($d['msg_elements']) || !is_array($d['msg_elements'])) return $out;
    $ownMatched = false;
    $all = [];
    foreach ($d['msg_elements'] as $el) {
        if (!is_array($el) || empty($el['attachments']) || !is_array($el['attachments'])) continue;
        $elIdx = (string)($el['msg_idx'] ?? '');
        foreach ($el['attachments'] as $att) {
            if (!is_array($att)) continue;
            $all[] = $att;
            if ($ownIdx !== '' && $elIdx === (string)$ownIdx) { $out[] = $att; $ownMatched = true; }
        }
    }
    return $ownMatched ? $out : $all;
}

// 由 MIME/原始文件名推断本地上传媒体的存储扩展名
function 上传媒体扩展名($mime, $filename = '') {
    $mime = strtolower(trim((string)$mime));
    $map = ['image/jpeg'=>'jpg','image/jpg'=>'jpg','image/pjpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/bmp'=>'bmp',
            'video/mp4'=>'mp4','video/quicktime'=>'mov','video/webm'=>'webm',
            'audio/mpeg'=>'mp3','audio/mp3'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/wave'=>'wav','audio/silk'=>'silk','audio/amr'=>'amr','audio/aac'=>'aac','audio/mp4'=>'m4a','audio/ogg'=>'ogg',
            'application/pdf'=>'pdf','application/zip'=>'zip','text/plain'=>'txt'];
    if (isset($map[$mime])) return $map[$mime];
    $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
    if (preg_match('/^[a-z0-9]{1,8}$/', $ext)) return $ext;
    return 'bin';
}

try {
    switch ($action) {
        // ==================== 本地上传媒体流式输出 (聊天记录页回放) ====================
        // 鉴权已在文件顶部 Auth::check() 完成; 仅允许 data/uploads 白名单扩展名文件
        case 'media':
            $f = $_GET['f'] ?? '';
            $path = 本地媒体路径($f);
            if ($path === null || !is_file($path)) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=utf-8');
                echo '媒体不存在或已过期';
                exit;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mimeMap = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp',
                        'mp4'=>'video/mp4','mov'=>'video/quicktime','webm'=>'video/webm',
                        'mp3'=>'audio/mpeg','wav'=>'audio/wav','silk'=>'application/silk','amr'=>'audio/amr','aac'=>'audio/aac','m4a'=>'audio/mp4','ogg'=>'audio/ogg',
                        'pdf'=>'application/pdf','zip'=>'application/zip','txt'=>'text/plain; charset=utf-8'];
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($path));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=86400');
            readfile($path);
            exit;

        // ==================== 获取会话列表 ====================
        case 'get_sessions':
        case 'list':
            $appid = trim($_GET['appid'] ?? $_POST['appid'] ?? '');
            if (empty($appid)) {
                json_response(['code' => 400, 'msg' => '缺少 appid 参数']);
            }

            $page = max(1, intval($_GET['page'] ?? $_POST['page'] ?? 1));
            $pageSize = min(100, max(1, intval($_GET['page_size'] ?? $_POST['page_size'] ?? 20)));
            $offset = ($page - 1) * $pageSize;
            $sourceTypeFilter = trim($_GET['source_type'] ?? $_POST['source_type'] ?? '');

            // 基础查询条件
            $where = "WHERE appid = ?";
            $params = [$appid];

            if (!empty($sourceTypeFilter)) {
                $where .= " AND source_type = ?";
                $params[] = $sourceTypeFilter;
            }

            // 获取去重的 target_id 列表（即会话列表）
            // 群相关系统事件归入群聊会话，好友相关归入私聊会话
            $groupEventTypes = ['退群', '群成员移除', '加群', '群成员增加', '入群申请', '群消息拒绝', '群消息接收'];
            $c2cEventTypes = ['好友增加', '好友删除'];
            $total = db()->fetchColumn(
                "SELECT COUNT(DISTINCT target_id) FROM messages " . $where,
                $params
            );

            $sessions = db()->fetchAll(
                "SELECT appid, target_id, 
                        CASE 
                            WHEN source_type IN ('退群', '群成员移除', '加群', '群成员增加', '入群申请', '群消息拒绝', '群消息接收') THEN '群聊'
                            WHEN source_type IN ('好友增加', '好友删除') THEN '私聊'
                            ELSE source_type 
                        END as source_type,
                        MAX(created_at) as last_active,
                        COUNT(*) as msg_count
                 FROM messages 
                 {$where} 
                 GROUP BY appid, target_id, 
                          CASE 
                            WHEN source_type IN ('退群', '群成员移除', '加群', '群成员增加', '入群申请', '群消息拒绝', '群消息接收') THEN '群聊'
                            WHEN source_type IN ('好友增加', '好友删除') THEN '私聊'
                            ELSE source_type 
                          END 
                 ORDER BY last_active DESC 
                 LIMIT ? OFFSET ?",
                array_merge($params, [$pageSize, $offset])
            );

            // 尝试补充群名/用户名、备注和头像，以及最后一条消息预览
            foreach ($sessions as &$session) {
                if ($session['source_type'] === '群聊') {
                    $group = db()->fetch(
                        "SELECT group_name, remark, custom_avatar, group_finger_memo, group_class_text, group_tags, group_member_num FROM groups WHERE appid = ? AND group_id = ?",
                        [$appid, $session['target_id']]
                    );
                    $session['name'] = $group ? (!empty($group['remark']) ? $group['remark'] : $group['group_name']) : '';
                    $session['remark'] = $group ? ($group['remark'] ?? '') : '';
                    $session['custom_avatar'] = $group ? ($group['custom_avatar'] ?? '') : '';
                    // 群详细信息
                    $session['group_name'] = $group ? ($group['group_name'] ?? '') : '';
                    $session['group_finger_memo'] = $group ? ($group['group_finger_memo'] ?? '') : '';
                    $session['group_class_text'] = $group ? ($group['group_class_text'] ?? '') : '';
                    $session['group_tags'] = $group ? ($group['group_tags'] ?? '') : '';
                    $session['group_member_num'] = $group ? intval($group['group_member_num'] ?? 0) : 0;
                } else {
                    $user = db()->fetch(
                        "SELECT nickname, remark FROM users WHERE appid = ? AND user_id = ?",
                        [$appid, $session['target_id']]
                    );
                    $session['name'] = $user ? (!empty($user['remark']) ? $user['remark'] : $user['nickname']) : '';
                    $session['remark'] = $user ? ($user['remark'] ?? '') : '';
                    $session['custom_avatar'] = '';
                }
                // 获取最后一条消息预览
                $lastMsg = db()->fetch(
                    "SELECT content, content_type, source_type, direction FROM messages 
                     WHERE appid = ? AND target_id = ? 
                     ORDER BY created_at DESC LIMIT 1",
                    [$appid, $session['target_id']]
                );
                if ($lastMsg) {
                    $previewContent = $lastMsg['content'] ?? '';
                    // 系统事件显示事件类型
                    $sysTypes = ['退群', '群成员移除', '加群', '群成员增加', '入群申请', '群消息拒绝', '群消息接收', '好友增加', '好友删除', '订阅状态', '频道', '频道私信', '表情表态', '频道更新'];
                    // 媒体类型（兼容英文和中文content_type）
                    $imageTypes = ['image', '图片'];
                    $voiceTypes = ['voice', '语音'];
                    $videoTypes = ['video', '视频'];
                    $fileTypes = ['file', '文件'];
                    $mdTypes = ['md', 'markdown', 'MD'];
                    $arkTypes = ['Ark', 'ark'];

                    if (in_array($lastMsg['source_type'], $sysTypes)) {
                        $session['last_msg'] = '[' . $lastMsg['source_type'] . ']';
                    } elseif (in_array($lastMsg['content_type'], $imageTypes)) {
                        $session['last_msg'] = '[图片]';
                    } elseif (in_array($lastMsg['content_type'], ['引用图片', '引用图文'])) {
                        // 引用图片/引用图文: 预览去URL后保留配文 (如 "[引用图文] 配文")
                        $safeContent = mb_convert_encoding($previewContent, 'UTF-8', 'UTF-8');
                        $stripped = trim(preg_replace('/^(https?:\/\/\S+|api\/\S+|\/\S+)(\s*\+\s*|\s+)/u', '', (string)$safeContent) ?? '');
                        $session['last_msg'] = '[' . $lastMsg['content_type'] . ']' . ($stripped !== '' ? ' ' . mb_substr($stripped, 0, 30) : '');
                    } elseif (in_array($lastMsg['content_type'], ['引用语音', '引用视频', '引用文件'])) {
                        $session['last_msg'] = '[' . $lastMsg['content_type'] . ']';
                    } elseif (in_array($lastMsg['content_type'], ['引用', '引用MD'])) {
                        $safeContent = mb_convert_encoding($previewContent, 'UTF-8', 'UTF-8');
                        $session['last_msg'] = '[' . $lastMsg['content_type'] . '] ' . mb_substr($safeContent, 0, 30);
                    } elseif (in_array($lastMsg['content_type'], $voiceTypes)) {
                        $session['last_msg'] = '[语音]';
                    } elseif (in_array($lastMsg['content_type'], $videoTypes)) {
                        $session['last_msg'] = '[视频]';
                    } elseif (in_array($lastMsg['content_type'], $fileTypes)) {
                        $session['last_msg'] = '[文件]';
                    } elseif (in_array($lastMsg['content_type'], $mdTypes)) {
                        $safeContent = mb_convert_encoding($previewContent, 'UTF-8', 'UTF-8');
                        $session['last_msg'] = '[MD] ' . mb_substr($safeContent, 0, 30);
                    } elseif (in_array($lastMsg['content_type'], $arkTypes)) {
                        $safeContent = mb_convert_encoding($previewContent, 'UTF-8', 'UTF-8');
                        $session['last_msg'] = '[Ark] ' . mb_substr($safeContent, 0, 30);
                    } else {
                        // 确保预览内容是有效的UTF-8，避免二进制数据导致json_encode失败
                        $safeContent = mb_convert_encoding($previewContent, 'UTF-8', 'UTF-8');
                        if ($safeContent === '' && $previewContent !== '') {
                            // mb_convert_encoding清除后为空，说明是二进制数据
                            $session['last_msg'] = '[二进制数据]';
                        } else {
                            $session['last_msg'] = mb_substr($safeContent, 0, 50);
                        }
                    }
                    $session['last_time'] = $session['last_active'];
                } else {
                    $session['last_msg'] = '';
                    $session['last_time'] = $session['last_active'];
                }
            }
            unset($session);

            json_response([
                'code' => 0,
                'msg' => 'success',
                'success' => true,
                'data' => $sessions
            ]);
            break;

        // ==================== 获取指定会话的消息记录 ====================
        case 'get_messages':
        case 'messages':
            $appid = trim($_GET['appid'] ?? $_POST['appid'] ?? '');
            $targetId = trim($_GET['target_id'] ?? $_POST['target_id'] ?? '');
            $sourceType = trim($_GET['source_type'] ?? $_POST['source_type'] ?? '');

            if (empty($appid) || empty($targetId)) {
                json_response(['code' => 400, 'msg' => '缺少 appid 或 target_id 参数']);
            }

            // 前端发送 offset/limit，兼容 page/page_size
            $offset = intval($_GET['offset'] ?? $_POST['offset'] ?? 0);
            $limit = intval($_GET['limit'] ?? $_POST['limit'] ?? 50);
            // 允许大limit以加载全部消息
            $limit = min(100000, max(1, $limit));

            $direction = $_GET['direction'] ?? $_POST['direction'] ?? '';

            $where = "WHERE appid = ? AND target_id = ?";
            $params = [$appid, $targetId];

            if (!empty($sourceType)) {
                // 群聊视图包含群相关系统事件
                if ($sourceType === '群聊') {
                    $where .= " AND source_type IN ('群聊', '退群', '群成员移除', '加群', '群成员增加', '入群申请', '群消息拒绝', '群消息接收')";
                } elseif ($sourceType === '私聊') {
                    // 私聊视图包含好友相关系统事件
                    $where .= " AND source_type IN ('私聊', '好友增加', '好友删除')";
                } else {
                    $where .= " AND source_type = ?";
                    $params[] = $sourceType;
                }
            }

            if (!empty($direction) && in_array($direction, ['接收', '发送'])) {
                $where .= " AND direction = ?";
                $params[] = $direction;
            }

            $total = db()->fetchColumn(
                "SELECT COUNT(*) FROM messages " . $where,
                $params
            );

            $messages = db()->fetchAll(
                "SELECT id, appid, direction, source_type, target_id, user_id, 
                        content_type, content, message_id as msg_id, raw_data, created_at, is_retracted
                 FROM messages $where
                 ORDER BY created_at DESC 
                 LIMIT ? OFFSET ?",
                array_merge($params, [$limit, $offset])
            );

            // 去重: 系统事件可能通过 webhook 和 WebSocket 两条路径重复记录
            // 1. 加群事件与群成员增加事件在10秒内只保留群成员增加
            // 2. 同类型系统事件在10秒内只保留第一条（防止重复显示）
            $filteredMessages = [];
            $skipIds = [];
            $systemEventTypes = ['加群', '退群', '群成员增加', '群成员移除', '入群申请',
                                 '群消息拒绝', '群消息接收', '好友增加', '好友删除',
                                 '订阅状态', '频道更新'];
            foreach ($messages as $msg) {
                if (!in_array($msg['source_type'], $systemEventTypes)) continue;
                foreach ($messages as $other) {
                    if ($other['id'] === $msg['id']) continue;
                    if ($other['target_id'] !== $msg['target_id']) continue;
                    if (abs(strtotime($other['created_at']) - strtotime($msg['created_at'])) > 10) continue;

                    // 规则1: 加群 + 群成员增加 → 跳过加群
                    if ($msg['source_type'] === '加群' && $other['source_type'] === '群成员增加') {
                        $skipIds[] = $msg['id'];
                        break;
                    }
                    // 规则2: 同类型 + 同用户 + 同来源 → 跳过较新的那条
                    if ($msg['source_type'] === $other['source_type']
                        && $msg['user_id'] === $other['user_id']
                        && $msg['id'] > $other['id']) {
                        $skipIds[] = $msg['id'];
                        break;
                    }
                }
            }
            foreach ($messages as $msg) {
                if (!in_array($msg['id'], $skipIds)) {
                    $filteredMessages[] = $msg;
                }
            }
            $messages = $filteredMessages;
            $total = max(0, $total - count($skipIds));

            // 解析 raw_data 提取 member_role、username、bot、ref_idx
            foreach ($messages as &$msg) {
                $memberRole = '';
                $username = '';
                $isBot = false;
                $rawData = [];
                if (!empty($msg['raw_data'])) {
                    $decodedRaw = json_decode($msg['raw_data'], true);
                    if (is_array($decodedRaw)) {
                        $rawData = $decodedRaw;
                        // 从原始事件数据中提取 member_role、username 和 bot
                        if (isset($rawData['d']['author']['member_role'])) {
                            $memberRole = $rawData['d']['author']['member_role'];
                        }
                        if (isset($rawData['d']['author']['username'])) {
                            $username = $rawData['d']['author']['username'];
                            // 清理不可见字符（零宽空格、BOM等）
                            $username = preg_replace('/[\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{FFA0}]/u', '', $username);
                            $username = trim($username);
                        }
                        if (isset($rawData['d']['author']['bot'])) {
                            $isBot = (bool)$rawData['d']['author']['bot'];
                        }
                        // 提取 mentions 数组（@提及的用户信息）
                        $mentionsList = [];
                        if (isset($rawData['d']['mentions']) && is_array($rawData['d']['mentions'])) {
                            foreach ($rawData['d']['mentions'] as $mention) {
                                $mentionId = $mention['id'] ?? $mention['member_openid'] ?? '';
                                $mentionUsername = $mention['username'] ?? '';
                                // 清理不可见字符（零宽空格、BOM等）
                                $mentionUsername = preg_replace('/[\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{FFA0}]/u', '', $mentionUsername);
                                $mentionUsername = trim($mentionUsername);
                                $mentionsList[] = [
                                    'id' => $mentionId,
                                    'username' => $mentionUsername,
                                    'member_openid' => $mention['member_openid'] ?? '',
                                    'member_role' => $mention['member_role'] ?? '',
                                    'is_you' => $mention['is_you'] ?? false
                                ];
                                // 如果有有效的用户名，尝试更新数据库中的用户昵称
                                if (!empty($mentionId) && !empty($mentionUsername)) {
                                    $existingUser = db()->fetch(
                                        "SELECT user_id FROM users WHERE appid = ? AND user_id = ?",
                                        [$appid, $mentionId]
                                    );
                                    if ($existingUser && empty($existingUser['nickname'])) {
                                        db()->execute(
                                            "UPDATE users SET nickname = ? WHERE appid = ? AND user_id = ? AND (nickname IS NULL OR nickname = '' OR nickname = ?)",
                                            [$mentionUsername, $appid, $mentionId, $mention['username'] ?? '']
                                        );
                                    }
                                }
                            }
                        }
                        $msg['mentions'] = $mentionsList;
                        // 如果有附件，提取所有媒体URL（支持多图片/多文件）
                        $msg['attachments'] = [];
                        if (isset($rawData['d']['attachments']) && is_array($rawData['d']['attachments'])) {
                            foreach ($rawData['d']['attachments'] as $attachment) {
                                // 去除URL两端的反引号和空白
                                $attUrl = isset($attachment['url']) ? trim($attachment['url']) : '';
                                $attUrl = trim($attUrl, "`\t\n\r ");
                                $attFileName = $attachment['filename'] ?? '';
                                $attContentType = $attachment['content_type'] ?? '';

                                // 提取语音WAV URL（浏览器兼容格式）
                                $attWavUrl = '';
                                if (isset($attachment['voice_wav_url'])) {
                                    $attWavUrl = trim($attachment['voice_wav_url']);
                                    $attWavUrl = trim($attWavUrl, "`\t\n\r ");
                                }

                                // 提取ASR识别文本（语音转文字）
                                $attAsrText = '';
                                if (isset($attachment['asr_refer_text'])) {
                                    $attAsrText = trim($attachment['asr_refer_text']);
                                }

                                if ($attUrl === '' && $attWavUrl === '') continue;

                                // 判断附件类型（兼容简单类型 image/video/voice/file 和 MIME类型 image/jpeg 等）
                                $attType = 'file';
                                $ctLower = strtolower($attContentType);
                                if ($ctLower === 'image' || strpos($ctLower, 'image/') === 0) {
                                    $attType = 'image';
                                } elseif ($ctLower === 'video' || strpos($ctLower, 'video/') === 0) {
                                    $attType = 'video';
                                } elseif ($ctLower === 'voice' || $ctLower === 'audio' || $ctLower === 'silk' || strpos($ctLower, 'audio/') === 0 || $ctLower === 'application/silk') {
                                    $attType = 'voice';
                                } elseif (!empty($attWavUrl)) {
                                    $attType = 'voice';
                                } elseif ($ctLower === 'file' || !empty($attFileName)) {
                                    $attType = 'file';
                                }

                                // 收集到 attachments 数组
                                $msg['attachments'][] = [
                                    'type' => $attType,
                                    'url' => $attUrl,
                                    'wav_url' => $attWavUrl,
                                    'asr_text' => $attAsrText,
                                    'filename' => $attFileName,
                                    'content_type' => $attContentType,
                                    'width' => $attachment['width'] ?? null,
                                    'height' => $attachment['height'] ?? null,
                                    'size' => $attachment['size'] ?? null
                                ];

                                // 兼容旧逻辑：第一个附件设置 media_url 和 content_type
                                // content_type 仅在缺省/默认值时推断, 保留数据库中的引用系类型(引用图片/引用图文等)不被覆盖
                                $ctIsDefault = !isset($msg['content_type']) || in_array($msg['content_type'], ['', 'text', '文字'], true);
                                if (!isset($msg['media_url']) || empty($msg['media_url'])) {
                                    // 语音优先使用WAV URL（浏览器兼容）
                                    $msg['media_url'] = ($attType === 'voice' && !empty($attWavUrl)) ? $attWavUrl : $attUrl;
                                    if ($ctIsDefault) {
                                        $msg['content_type'] = $attType;
                                    }
                                    if (!empty($attFileName) && $attType === 'file') {
                                        $msg['file_name'] = $attFileName;
                                    }
                                }
                            }
                            // 如果 content 为空且只有附件没有文字，保持 content 为第一个URL（兼容旧前端）
                            if (empty($msg['content']) && !empty($msg['attachments'])) {
                                $msg['content'] = $msg['attachments'][0]['url'];
                            }
                        }
                    }
                }
                $msg['member_role'] = $memberRole;
                $msg['username'] = $username;
                $msg['bot'] = $isBot;
                // ==================== 引用(回复)消息解析 (共用 function.php parseQuoteInfo) ====================
                // ref_idx 语义 = 引用"这条消息"时应使用的索引: 本条自身的 msg_idx > ref_msg_idx (兼容旧行为) > ''
                $quoteInfo = parseQuoteInfo($rawData);
                $msg['ref_idx'] = $quoteInfo['own_idx'] !== '' ? $quoteInfo['own_idx'] : $quoteInfo['ref_idx'];
                $msg['msg_idx'] = $quoteInfo['own_idx'];
                // 提取 message_type 和 msg_elements（引用消息等）
                $msg['message_type'] = $rawData['d']['message_type'] ?? 0;
                if (isset($rawData['d']['msg_elements']) && is_array($rawData['d']['msg_elements'])) {
                    $msg['msg_elements'] = $rawData['d']['msg_elements'];
                }
                if ($quoteInfo['quote']) {
                    $msg['quote'] = $quoteInfo['quote'];   // ['ref_idx','message_type','content','attachments']
                }
                // 非引用消息无顶层 attachments 时, 从 msg_elements 提取本条自身媒体
                // (QQ v2 真实事件格式: 图片/视频/语音/文件的媒体信息在 msg_elements[].attachments,
                //  引用消息的元素属于被引用内容, 已由 quote 块展示, 此处跳过防止重复)
                if (empty($msg['quote']) && empty($msg['attachments'])) {
                    $elAtts = extractOwnAttachmentsFromElements($rawData, $quoteInfo['own_idx']);
                    if (!empty($elAtts)) {
                        foreach ($elAtts as $attachment) {
                            $attUrl = isset($attachment['url']) ? trim($attachment['url']) : '';
                            $attUrl = trim($attUrl, "`\t\n\r ");
                            if ($attUrl === '') continue;
                            $attFileName = $attachment['filename'] ?? '';
                            $attContentType = $attachment['content_type'] ?? '';
                            $attWavUrl = isset($attachment['voice_wav_url']) ? trim($attachment['voice_wav_url']) : '';
                            $attAsrText = isset($attachment['asr_refer_text']) ? trim($attachment['asr_refer_text']) : '';

                            $attType = 'file';
                            $ctLower = strtolower($attContentType);
                            if ($ctLower === 'image' || strpos($ctLower, 'image/') === 0) {
                                $attType = 'image';
                            } elseif ($ctLower === 'video' || strpos($ctLower, 'video/') === 0) {
                                $attType = 'video';
                            } elseif ($ctLower === 'voice' || $ctLower === 'audio' || $ctLower === 'silk' || strpos($ctLower, 'audio/') === 0 || $ctLower === 'application/silk') {
                                $attType = 'voice';
                            } elseif (!empty($attWavUrl)) {
                                $attType = 'voice';
                            }

                            $msg['attachments'][] = [
                                'type' => $attType,
                                'url' => $attUrl,
                                'wav_url' => $attWavUrl,
                                'asr_text' => $attAsrText,
                                'filename' => $attFileName,
                                'content_type' => $attContentType,
                                'width' => $attachment['width'] ?? null,
                                'height' => $attachment['height'] ?? null,
                                'size' => $attachment['size'] ?? null
                            ];
                            if (!isset($msg['media_url']) || empty($msg['media_url'])) {
                                $msg['media_url'] = ($attType === 'voice' && !empty($attWavUrl)) ? $attWavUrl : $attUrl;
                                $ctIsDefault2 = !isset($msg['content_type']) || in_array($msg['content_type'], ['', 'text', '文字'], true);
                                if ($ctIsDefault2) {
                                    $msg['content_type'] = $attType;
                                }
                                if (!empty($attFileName) && $attType === 'file') {
                                    $msg['file_name'] = $attFileName;
                                }
                            }
                        }
                        if (empty($msg['content']) && !empty($msg['attachments'])) {
                            $msg['content'] = $msg['attachments'][0]['url'];
                        }
                    }
                }
                // 本条消息自身内容兜底: content 为空时用 parallel_message.msg_nodes 拼接
                $dArr = is_array($rawData['d'] ?? null) ? $rawData['d'] : [];
                if (trim((string)($msg['content'] ?? '')) === ''
                    && isset($dArr['parallel_message']['msg_nodes']) && is_array($dArr['parallel_message']['msg_nodes'])) {
                    $nodeTexts = [];
                    foreach ($dArr['parallel_message']['msg_nodes'] as $node) {
                        if (is_array($node) && isset($node['content']) && trim((string)$node['content']) !== '') {
                            $nodeTexts[] = trim((string)$node['content']);
                        }
                    }
                    if (!empty($nodeTexts)) {
                        $msg['content'] = implode(' ', $nodeTexts);
                    }
                }
            }
            unset($msg);

            // 补充群名/用户名、备注和头像
            $targetName = '';
            $targetRemark = '';
            $targetAvatar = '';
            $groupInfo = null;
            if (!empty($sourceType) && !empty($targetId)) {
                if ($sourceType === '群聊') {
                    $group = db()->fetch(
                        "SELECT group_name, remark, custom_avatar, group_finger_memo, group_class_text, group_tags, group_member_num FROM groups WHERE appid = ? AND group_id = ?",
                        [$appid, $targetId]
                    );
                    $targetName = $group ? $group['group_name'] : '';
                    $targetRemark = $group ? ($group['remark'] ?? '') : '';
                    $targetAvatar = $group ? ($group['custom_avatar'] ?? '') : '';
                    if ($group) {
                        $groupInfo = [
                            'group_name'         => $group['group_name'] ?? '',
                            'group_finger_memo'  => $group['group_finger_memo'] ?? '',
                            'group_class_text'   => $group['group_class_text'] ?? '',
                            'group_tags'         => $group['group_tags'] ?? '',
                            'group_member_num'   => intval($group['group_member_num'] ?? 0),
                        ];
                    }
                } else {
                    $user = db()->fetch(
                        "SELECT nickname, remark FROM users WHERE appid = ? AND user_id = ?",
                        [$appid, $targetId]
                    );
                    $targetName = $user ? $user['nickname'] : '';
                    $targetRemark = $user ? ($user['remark'] ?? '') : '';
                }
            }

            json_response([
                'code' => 0,
                'msg' => 'success',
                'success' => true,
                'data' => [
                    'info' => [
                        'name' => $targetName,
                        'remark' => $targetRemark,
                        'custom_avatar' => $targetAvatar,
                        'source_type' => $sourceType,
                        'target_id' => $targetId,
                        'total' => $total,
                        'group_info' => $groupInfo,
                    ],
                    'messages' => $messages
                ]
            ]);
            break;

        // ==================== 发送消息 ====================
        case 'send':
            $appid = trim($_POST['appid'] ?? '');
            $sourceType = trim($_POST['source_type'] ?? '群聊');
            $targetId = trim($_POST['target_id'] ?? '');
            $msgType = trim($_POST['msg_type'] ?? 'text');
            // 兼容前端发送的 message 和 content 参数
            $content = $_POST['message'] ?? $_POST['content'] ?? '';

            // 文件上传支持：检查是否有上传的文件
            $hasUpload = isset($_FILES['upload_file']) && $_FILES['upload_file']['error'] === UPLOAD_ERR_OK;

            if ($hasUpload) {
                // 有文件上传时，content 可以为空
                if (empty($appid) || empty($targetId)) {
                    json_response(['code' => 400, 'msg' => '缺少必要参数']);
                }
                $content = ''; // 文件上传模式下 content 为空
            } else {
                // ark / tuwen / quote 类型使用各自的参数，content 可以为空
                $contentOptionalTypes = ['ark', 'tuwen', 'quote', 'image', 'voice', 'video', 'file'];
                if (in_array($msgType, $contentOptionalTypes)) {
                    if (empty($appid) || empty($targetId)) {
                        json_response(['code' => 400, 'msg' => '缺少必要参数']);
                    }
                } elseif (empty($appid) || empty($targetId) || $content === '') {
                    json_response(['code' => 400, 'msg' => '缺少必要参数']);
                }
            }

            $bot = getBot($appid);
            if (!$bot) {
                json_response(['code' => 404, 'msg' => '机器人不存在']);
            }

            // 设置全局常量上下文
            if (!defined('appid')) define('appid', $bot['appid']);
            if (!defined('secret')) define('secret', $bot['secret']);
            if (!defined('type')) define('type', $bot['env']);
            if (!defined('消息来源')) define('消息来源', $sourceType);
            if (!defined('来源')) define('来源', $targetId);
            if (!defined('消息ID')) define('消息ID', '');
            if (!defined('事件ID')) define('事件ID', '');

            require_once(__DIR__ . '/../../bot.php');

            // 处理文件上传
            if ($hasUpload) {
                $uploadFile = $_FILES['upload_file'];
                $fileData = file_get_contents($uploadFile['tmp_name']);
                $originalName = $uploadFile['name'];
                $fileMime = $uploadFile['type'];
                $fileSize = $uploadFile['size'];

                // 附加的文字内容（图片+文字一起发送时使用）
                $textContent = trim($_POST['text_content'] ?? '');

                // 根据上传类型或文件MIME类型判断消息类型
                $uploadType = trim($_POST['upload_type'] ?? $msgType);

                // 如果 msgType 还是 text 但有文件上传，根据 MIME 自动推断
                if ($uploadType === 'text' || empty($uploadType)) {
                    if (strpos($fileMime, 'image/') === 0) $uploadType = 'image';
                    elseif (strpos($fileMime, 'video/') === 0) $uploadType = 'video';
                    elseif (strpos($fileMime, 'audio/') === 0) $uploadType = 'voice';
                    else $uploadType = 'file';
                }

                // 二进制留存: 官方 /files 上传不回URL, 本地保存一份供聊天记录页回放
                // (留存失败不影响发送, 仅回退旧占位符显示)
                $localMediaUrl = 保存本地媒体($fileData, 上传媒体扩展名($fileMime, $originalName));

                switch ($uploadType) {
                    case 'image':
                        // 图片支持附带文字内容（图片+文字一起发送）
                        // 携带 ref_msg_id 时以引用形式发送: 无文字=引用图片, 有文字=引用图文
                        $quoteRefId = trim($_POST['ref_msg_id'] ?? '');
                        if ($quoteRefId !== '') {
                            $resp = 引用图片($quoteRefId, $fileData, $textContent ?: null, $localMediaUrl ?: null);
                        } else {
                            $resp = 图片($fileData, $textContent ?: null, $localMediaUrl ?: null);
                        }
                        break;
                    case 'voice':
                        // 上传的语音文件是二进制数据，使用本地语音函数直接发送
                        // 语音()函数期望URL并调用外部silk转换服务，不适用于二进制数据
                        $resp = 本地语音($fileData, $localMediaUrl ?: null);
                        break;
                    case 'video':
                        $resp = 视频($fileData, $localMediaUrl ?: null);
                        break;
                    case 'file':
                    default:
                        $resp = 文件($fileData, $originalName, $localMediaUrl ?: null);
                        break;
                }
            } else {
                $resp = null;
                switch ($msgType) {
                    case 'text':
                        $resp = 文字($content);
                        break;
                    case 'markdown':
                    case 'md':
                        // 携带 ref_msg_id 时以引用形式发送 (官方 message_reference)
                        $refMsgId = trim($_POST['ref_msg_id'] ?? '');
                        if ($refMsgId !== '') {
                            $resp = 引用MD($refMsgId, $content);
                        } else {
                            $resp = MD($content);
                        }
                        break;
                    case 'image':
                        $imageContent = $_POST['image_content'] ?? null;
                        // 携带 ref_msg_id 时: 无文字=引用发图片, 有文字=引用发图片+文字(图文)
                        $refMsgId = trim($_POST['ref_msg_id'] ?? '');
                        if ($refMsgId !== '') {
                            $resp = 引用图片($refMsgId, $content, $imageContent);
                        } else {
                            $resp = 图片($content, $imageContent);
                        }
                        break;
                    case 'voice':
                        // 携带 ref_msg_id 时以引用形式发送 (msg_type=7 + media + message_reference)
                        $refMsgId = trim($_POST['ref_msg_id'] ?? '');
                        $resp = ($refMsgId !== '') ? 引用语音($refMsgId, $content) : 语音($content);
                        break;
                    case 'video':
                        $refMsgId = trim($_POST['ref_msg_id'] ?? '');
                        $resp = ($refMsgId !== '') ? 引用视频($refMsgId, $content) : 视频($content);
                        break;
                    case 'file':
                        $fileName = $_POST['file_name'] ?? null;
                        $refMsgId = trim($_POST['ref_msg_id'] ?? '');
                        $resp = ($refMsgId !== '') ? 引用文件($refMsgId, $content, $fileName) : 文件($content, $fileName);
                        break;
                    case 'quote':
                        $refMsgId = trim($_POST['ref_msg_id'] ?? '');
                        $quoteContent = $_POST['quote_content'] ?? $content;
                        if (empty($refMsgId)) {
                            json_response(['code' => 400, 'msg' => '引用消息需要 ref_msg_id']);
                        }
                        $resp = 引用($refMsgId, $quoteContent);
                        break;
                    case 'ark':
                        // Ark 消息 - 支持 template_id + kv 键值对
                        $templateId = trim($_POST['template_id'] ?? '');
                        $kvInput = $_POST['kv'] ?? $content;
                        if (empty($templateId)) {
                            json_response(['code' => 400, 'msg' => 'Ark消息需要 template_id 参数']);
                        }
                        // 解析 kv：支持 JSON 字符串或 POST 数组
                        $kv = null;
                        if (is_string($kvInput)) {
                            $kv = json_decode($kvInput, true);
                        } elseif (is_array($kvInput)) {
                            $kv = $kvInput;
                        }
                        if (!is_array($kv)) {
                            json_response(['code' => 400, 'msg' => 'Ark消息的 kv 参数格式不正确，需要 JSON 对象或数组']);
                        }

                        // 模板23（链接卡片）特殊处理：构建 #LIST# obj 结构
                        if ($templateId === '23') {
                            $resp = Ark23($kv);
                        } else {
                            // 将 kv 对象转换为 Ark 函数所需的数组格式
                            $kvArray = [];
                            foreach ($kv as $k => $v) {
                                $kvArray[] = ['key' => $k, 'value' => $v];
                            }
                            if (!function_exists('Ark')) {
                                json_response(['code' => 500, 'msg' => 'Ark 函数不存在']);
                            }
                            $resp = Ark($templateId, $kvArray);
                        }
                        break;
                    case 'tuwen':
                        // 图文卡片消息 - 使用 图文卡片() 函数
                        $tuwenTitle = trim($_POST['title'] ?? '');
                        $tuwenDesc = trim($_POST['desc'] ?? '');
                        $tuwenImg = trim($_POST['img'] ?? '');
                        $tuwenLink = trim($_POST['link'] ?? '');
                        $tuwenPrompt = trim($_POST['prompt'] ?? '');
                        if (empty($tuwenTitle) && empty($tuwenDesc) && empty($tuwenImg)) {
                            json_response(['code' => 400, 'msg' => '图文卡片至少需要标题、描述或图片']);
                        }
                        if (!function_exists('图文卡片')) {
                            json_response(['code' => 500, 'msg' => '图文卡片函数不存在']);
                        }
                        $resp = 图文卡片($tuwenTitle, $tuwenDesc, $tuwenImg, $tuwenLink);
                        break;
                    default:
                        $resp = 文字($content);
                }
            }

            $data = json_decode($resp, true);
            if (isset($data['id']) || (isset($data['code']) && $data['code'] == 0)) {
                后台操作记录('发送消息', $targetId . ' 类型:' . $msgType, $appid);
                json_response(['code' => 0, 'msg' => '发送成功', 'success' => true, 'data' => $data]);
            } else {
                json_response(['code' => 500, 'msg' => '发送失败: ' . ($data['message'] ?? '未知错误'), 'success' => false, 'data' => $data]);
            }
            break;

        // ==================== 分片上传: 追加分片 ====================
        // 前端把 >1.5MB 的文件按 1MB/片逐片 POST 到此, 全部完成后调 upload_complete 合并发送。
        // 分片存放 data/uploads/tmp/ (受 data/.htaccess 保护), 覆盖写保证同分片重传幂等。
        case 'upload_chunk': {
            $appidSafe = preg_replace('/[^A-Za-z0-9_\-]/', '', trim($_POST['appid'] ?? ''));
            $uploadId = trim($_POST['upload_id'] ?? '');
            $chunkIndex = intval($_POST['chunk_index'] ?? -1);
            $chunkTotal = intval($_POST['chunk_total'] ?? 0);
            if ($appidSafe === '' || !preg_match('/^[A-Za-z0-9]{16,64}$/', $uploadId)) {
                json_response(['code' => 400, 'msg' => '参数不合法']);
            }
            if ($chunkTotal < 1 || $chunkTotal > 1024 || $chunkIndex < 0 || $chunkIndex >= $chunkTotal) {
                json_response(['code' => 400, 'msg' => '分片序号不合法']);
            }
            if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
                json_response(['code' => 400, 'msg' => '分片数据缺失或超过服务器 upload_max_filesize 限制']);
            }
            $tmpDir = __DIR__ . '/../../data/uploads/tmp';
            if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0755, true)) {
                json_response(['code' => 500, 'msg' => '无法创建分片临时目录']);
            }
            // 孤儿分片清理 (2% 概率触发): 清理 24 小时前的残留
            if (mt_rand(1, 50) <= 1) {
                foreach (array_merge(glob($tmpDir . '/*.part_*') ?: [], glob($tmpDir . '/*.merged') ?: []) as $old) {
                    if (is_file($old) && filemtime($old) < time() - 86400) @unlink($old);
                }
            }
            // 单文件总量防护 512MB
            $prefix = $appidSafe . '_' . $uploadId . '.part_';
            $existingTotal = 0;
            foreach (glob($tmpDir . '/' . $prefix . '*') ?: [] as $pf) {
                if (is_file($pf)) $existingTotal += filesize($pf);
            }
            $incomingSize = filesize($_FILES['chunk']['tmp_name']);
            if ($existingTotal + $incomingSize > 512 * 1024 * 1024) {
                json_response(['code' => 413, 'msg' => '文件超出 512MB 上限']);
            }
            $partFile = $tmpDir . '/' . $prefix . $chunkIndex;
            if (!@move_uploaded_file($_FILES['chunk']['tmp_name'], $partFile)) {
                json_response(['code' => 500, 'msg' => '分片写入失败']);
            }
            $received = count(glob($tmpDir . '/' . $prefix . '*') ?: []);
            json_response(['code' => 0, 'msg' => 'ok', 'success' => true, 'received' => $received, 'chunk_total' => $chunkTotal]);
            break;
        }

        // ==================== 分片上传: 合并发送 ====================
        // 校验分片完整性 → 流式合并(不占内存) → 留存 data/uploads/ → 与整包上传相同的发送链路
        case 'upload_complete': {
            $appid = trim($_POST['appid'] ?? '');
            $targetId = trim($_POST['target_id'] ?? '');
            $sourceType = trim($_POST['source_type'] ?? '群聊');
            $uploadId = trim($_POST['upload_id'] ?? '');
            $chunkTotal = intval($_POST['chunk_total'] ?? 0);
            $originalName = trim($_POST['file_name'] ?? 'file');
            if (empty($appid) || empty($targetId) || !preg_match('/^[A-Za-z0-9]{16,64}$/', $uploadId)
                || $chunkTotal < 1 || $chunkTotal > 1024) {
                json_response(['code' => 400, 'msg' => '缺少必要参数或参数不合法']);
            }
            $bot = getBot($appid);
            if (!$bot) json_response(['code' => 404, 'msg' => '机器人不存在']);

            $tmpDir = __DIR__ . '/../../data/uploads/tmp';
            $appidSafe = preg_replace('/[^A-Za-z0-9_\-]/', '', $appid);
            $prefix = $appidSafe . '_' . $uploadId . '.part_';
            // 校验分片齐全并统计大小
            $totalSize = 0;
            for ($i = 0; $i < $chunkTotal; $i++) {
                $p = $tmpDir . '/' . $prefix . $i;
                if (!is_file($p)) json_response(['code' => 400, 'msg' => "缺少分片 {$i}，请重新上传"]);
                $totalSize += filesize($p);
            }
            if ($totalSize === 0) json_response(['code' => 400, 'msg' => '文件内容为空']);
            if ($totalSize > 512 * 1024 * 1024) json_response(['code' => 413, 'msg' => '文件超出 512MB 上限']);

            // 流式合并到 .merged (不占内存)
            $mergedPath = $tmpDir . '/' . $appidSafe . '_' . $uploadId . '.merged';
            $out = @fopen($mergedPath, 'wb');
            if (!$out) json_response(['code' => 500, 'msg' => '合并临时文件创建失败']);
            $okMerge = true;
            for ($i = 0; $i < $chunkTotal; $i++) {
                $in = @fopen($tmpDir . '/' . $prefix . $i, 'rb');
                if (!$in) { $okMerge = false; break; }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fclose($out);
            if (!$okMerge || filesize($mergedPath) !== $totalSize) {
                @unlink($mergedPath);
                json_response(['code' => 500, 'msg' => '分片合并失败，请重新上传']);
            }

            // 发送内存防护: base64+JSON 放大约2.7倍, 60MB 为 128M 内存上限内的安全阈值
            if ($totalSize > 60 * 1024 * 1024) {
                @unlink($mergedPath);
                foreach (glob($tmpDir . '/' . $prefix . '*') ?: [] as $p) @unlink($p);
                json_response(['code' => 413, 'msg' => sprintf('文件 %.1fMB 超过直接发送上限 60MB（QQ官方接口限制），已取消发送', $totalSize / 1048576)]);
            }

            $fileData = file_get_contents($mergedPath);
            $fileMime = 'application/octet-stream';
            if (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $detected = finfo_file($fi, $mergedPath);
                finfo_close($fi);
                if (is_string($detected) && $detected !== '') $fileMime = $detected;
            }

            // 清理分片与合并临时文件 (留存文件由 保存本地媒体 负责)
            foreach (glob($tmpDir . '/' . $prefix . '*') ?: [] as $p) @unlink($p);
            @unlink($mergedPath);

            // 与 send 上传分支相同的上下文与发送链路
            $content = trim($_POST['text_content'] ?? '');
            $uploadType = trim($_POST['upload_type'] ?? '');
            if ($uploadType === 'text' || empty($uploadType)) {
                if (strpos($fileMime, 'image/') === 0) $uploadType = 'image';
                elseif (strpos($fileMime, 'video/') === 0) $uploadType = 'video';
                elseif (strpos($fileMime, 'audio/') === 0) $uploadType = 'voice';
                else $uploadType = 'file';
            }
            $refMsgId = trim($_POST['ref_msg_id'] ?? '');

            if (!defined('appid')) define('appid', $bot['appid']);
            if (!defined('secret')) define('secret', $bot['secret']);
            if (!defined('type')) define('type', $bot['env']);
            if (!defined('消息来源')) define('消息来源', $sourceType);
            if (!defined('来源')) define('来源', $targetId);
            if (!defined('消息ID')) define('消息ID', '');
            if (!defined('事件ID')) define('事件ID', '');
            require_once(__DIR__ . '/../../bot.php');

            $localMediaUrl = 保存本地媒体($fileData, 上传媒体扩展名($fileMime, $originalName));

            switch ($uploadType) {
                case 'image':
                    // 携带 ref_msg_id 时以引用形式发送: 无文字=引用图片, 有文字=引用图文
                    if ($refMsgId !== '') {
                        $resp = 引用图片($refMsgId, $fileData, $content ?: null, $localMediaUrl ?: null);
                    } else {
                        $resp = 图片($fileData, $content ?: null, $localMediaUrl ?: null);
                    }
                    break;
                case 'voice':
                    $resp = 本地语音($fileData, $localMediaUrl ?: null);
                    break;
                case 'video':
                    $resp = 视频($fileData, $localMediaUrl ?: null);
                    break;
                case 'file':
                default:
                    $resp = 文件($fileData, $originalName, $localMediaUrl ?: null);
                    break;
            }

            $data = json_decode($resp, true);
            if (isset($data['id']) || (isset($data['code']) && $data['code'] == 0)) {
                后台操作记录('发送消息', $targetId . ' 类型:' . $msgType . ' (附件)', $appid);
                json_response(['code' => 0, 'msg' => '发送成功', 'success' => true, 'data' => $data]);
            } else {
                json_response(['code' => 500, 'msg' => '发送失败: ' . ($data['message'] ?? '未知错误'), 'success' => false, 'data' => $data]);
            }
            break;
        }

        // ==================== 撤回消息 ====================
        case 'retract':
        case 'recall':
            $appid = trim($_POST['appid'] ?? '');
            $sourceType = trim($_POST['source_type'] ?? '群聊');
            $targetId = trim($_POST['target_id'] ?? '');
            // 兼容前端发送的 msg_id 和 message_id 参数
            $messageId = trim($_POST['msg_id'] ?? $_POST['message_id'] ?? '');
            // 行主键(优先): 同一会话中 QQ 可能返回空/重复的 message_id,
            // 按 message_id 更新会误标多条消息为"已撤回", 故优先按主键精确定位
            $rowId = intval($_POST['row_id'] ?? 0);

            if (empty($appid) || ($rowId <= 0 && empty($messageId))) {
                json_response(['code' => 400, 'msg' => '缺少 appid 或 message_id 参数']);
            }

            $bot = getBot($appid);
            if (!$bot) {
                json_response(['code' => 404, 'msg' => '机器人不存在']);
            }

            if (!defined('appid')) define('appid', $bot['appid']);
            if (!defined('secret')) define('secret', $bot['secret']);
            if (!defined('type')) define('type', $bot['env']);
            if (!defined('消息来源')) define('消息来源', $sourceType);
            if (!defined('来源')) define('来源', $targetId ?: '');
            if (!defined('消息ID')) define('消息ID', '');
            if (!defined('事件ID')) define('事件ID', '');

            require_once(__DIR__ . '/../../bot.php');
            $resp = 撤回($messageId);
            $data = json_decode($resp, true);

            // 检查API是否返回错误（QQ API成功撤回时返回空body或无code字段）
            $hasError = is_array($data) && isset($data['code']) && $data['code'] != 0;

            // 无论API是否返回错误，都更新数据库标记为已撤回（用户已明确操作）
            // 不覆盖原始消息内容，只标记 is_retracted = 1
            // 优先按主键 id 精确标记；回退按 message_id 时排除空ID并只取最新一条，
            // 防止历史发送日志 message_id 为空时一次误标全部消息
            if ($rowId > 0) {
                db()->execute(
                    "UPDATE messages SET is_retracted = 1 WHERE id = ? AND appid = ?",
                    [$rowId, $appid]
                );
            } else {
                db()->execute(
                    "UPDATE messages SET is_retracted = 1 WHERE id = (
                        SELECT id FROM messages WHERE appid = ? AND message_id = ?
                        ORDER BY created_at DESC, id DESC LIMIT 1
                    )",
                    [$appid, $messageId]
                );
            }

            后台操作记录('撤回消息', $targetId . ' ID:' . ($messageId ?: ('行' . $rowId)), $appid);
            if ($hasError) {
                json_response([
                    'code' => 0,
                    'msg' => '已标记撤回（API返回: ' . ($data['message'] ?? '未知') . '）',
                    'success' => true,
                    'data' => $data
                ]);
            } else {
                json_response([
                    'code' => 0,
                    'msg' => '撤回成功',
                    'success' => true,
                    'data' => $data
                ]);
            }
            break;

        // ==================== 撤回最后一条消息 ====================
        case 'retract_last':
            $appid = trim($_POST['appid'] ?? $_GET['appid'] ?? '');
            $targetId = trim($_POST['target_id'] ?? $_GET['target_id'] ?? '');
            $sourceType = trim($_POST['source_type'] ?? $_GET['source_type'] ?? '群聊');

            if (empty($appid) || empty($targetId)) {
                json_response(['code' => 400, 'msg' => '缺少 appid 或 target_id 参数']);
            }

            // 查找该会话中最后一条发送的消息（排除已撤回的消息）
            $lastMsg = db()->fetch(
                "SELECT id, message_id, target_id FROM messages 
                 WHERE appid = ? AND target_id = ? AND direction = '发送' AND (is_retracted IS NULL OR is_retracted = 0)
                 ORDER BY created_at DESC LIMIT 1",
                [$appid, $targetId]
            );

            if (!$lastMsg || empty($lastMsg['message_id'])) {
                json_response(['code' => 404, 'msg' => '未找到可撤回的消息', 'success' => false]);
            }

            $bot = getBot($appid);
            if (!$bot) {
                json_response(['code' => 404, 'msg' => '机器人不存在']);
            }

            if (!defined('appid')) define('appid', $bot['appid']);
            if (!defined('secret')) define('secret', $bot['secret']);
            if (!defined('type')) define('type', $bot['env']);
            if (!defined('消息来源')) define('消息来源', $sourceType);
            if (!defined('来源')) define('来源', $lastMsg['target_id']);
            if (!defined('消息ID')) define('消息ID', '');
            if (!defined('事件ID')) define('事件ID', '');

            require_once(__DIR__ . '/../../bot.php');
            $resp = 撤回($lastMsg['message_id']);
            $data = json_decode($resp, true);

            // 撤回成功后，按行主键精确更新数据库中的消息状态（不覆盖原始内容）
            db()->execute(
                "UPDATE messages SET is_retracted = 1 WHERE id = ?",
                [$lastMsg['id']]
            );

            后台操作记录('撤回最后消息', $targetId . ' ID:' . $lastMsg['message_id'], $appid);
            json_response([
                'code' => 0,
                'msg' => '撤回成功',
                'success' => true,
                'data' => $data
            ]);
            break;

        // ==================== 批量获取用户昵称 ====================
        case 'get_nicknames':
            $appid = trim($_POST['appid'] ?? $_GET['appid'] ?? '');
            $userIds = $_POST['user_ids'] ?? $_GET['user_ids'] ?? '';

            if (empty($appid) || empty($userIds)) {
                json_response(['code' => 400, 'msg' => '缺少参数']);
            }

            // 支持逗号分隔或JSON数组
            if (is_string($userIds)) {
                $decoded = json_decode($userIds, true);
                if (is_array($decoded)) {
                    $userIds = $decoded;
                } else {
                    $userIds = explode(',', $userIds);
                }
            }

            if (!is_array($userIds) || count($userIds) === 0) {
                json_response(['code' => 400, 'msg' => 'user_ids 格式不正确']);
            }

            // 限制最多查询100个
            $userIds = array_slice($userIds, 0, 100);

            // 使用 getRemarks 获取完整信息（昵称+备注+群头像）
            $remarksData = getRemarks($appid, $userIds, $userIds);

            $nicknames = [];
            foreach ($userIds as $uid) {
                // 优先使用备注，其次昵称
                $nicknames[$uid] = !empty($remarksData['user_remarks'][$uid]) 
                    ? $remarksData['user_remarks'][$uid] 
                    : ($remarksData['user_nicknames'][$uid] ?? '');
            }

            $groupNames = [];
            foreach ($userIds as $gid) {
                $groupNames[$gid] = !empty($remarksData['group_remarks'][$gid])
                    ? $remarksData['group_remarks'][$gid]
                    : ($remarksData['group_names'][$gid] ?? '');
            }

            json_response([
                'code' => 0,
                'msg' => 'success',
                'success' => true,
                'data' => [
                    'user_nicknames'  => $nicknames,
                    'group_names'     => $groupNames,
                    'user_remarks'    => $remarksData['user_remarks'],
                    'group_remarks'   => $remarksData['group_remarks'],
                    'group_avatars'   => $remarksData['group_avatars']
                ]
            ]);
            break;

        // ==================== 设置用户/群备注 ====================
        case 'set_remark':
            $appid      = trim($_POST['appid'] ?? '');
            $targetId   = trim($_POST['target_id'] ?? '');
            $sourceType = trim($_POST['source_type'] ?? '群聊');
            $remark     = trim($_POST['remark'] ?? '');

            if (empty($appid) || empty($targetId)) {
                json_response(['code' => 400, 'msg' => '缺少 appid 或 target_id 参数']);
            }

            if ($sourceType === '群聊') {
                setGroupRemark($appid, $targetId, $remark);
            } else {
                setUserRemark($appid, $targetId, $remark);
            }

            后台操作记录('设置备注', $targetId . ' => ' . $remark, $appid);
            json_response([
                'code'    => 0,
                'msg'     => '备注设置成功',
                'success' => true,
                'data'    => ['remark' => $remark]
            ]);
            break;

        // ==================== 设置群头像 ====================
        case 'set_group_avatar':
            $appid     = trim($_POST['appid'] ?? '');
            $groupId   = trim($_POST['target_id'] ?? $_POST['group_id'] ?? '');
            $avatarUrl = trim($_POST['avatar_url'] ?? '');

            if (empty($appid) || empty($groupId)) {
                json_response(['code' => 400, 'msg' => '缺少 appid 或 target_id 参数']);
            }

            setGroupAvatar($appid, $groupId, $avatarUrl);
            后台操作记录('设置群头像', $groupId, $appid);

            json_response([
                'code'    => 0,
                'msg'     => '群头像设置成功',
                'success' => true,
                'data'    => ['avatar_url' => $avatarUrl]
            ]);
            break;

        // ==================== 获取备注信息 ====================
        case 'get_remarks':
            $appid    = trim($_POST['appid'] ?? $_GET['appid'] ?? '');
            $userIds  = $_POST['user_ids'] ?? $_GET['user_ids'] ?? '';
            $groupIds = $_POST['group_ids'] ?? $_GET['group_ids'] ?? '';

            if (empty($appid)) {
                json_response(['code' => 400, 'msg' => '缺少 appid 参数']);
            }

            // 解析 user_ids
            $userArr = [];
            if (!empty($userIds)) {
                if (is_string($userIds)) {
                    $decoded = json_decode($userIds, true);
                    $userArr = is_array($decoded) ? $decoded : explode(',', $userIds);
                } else {
                    $userArr = $userIds;
                }
            }

            // 解析 group_ids
            $groupArr = [];
            if (!empty($groupIds)) {
                if (is_string($groupIds)) {
                    $decoded = json_decode($groupIds, true);
                    $groupArr = is_array($decoded) ? $decoded : explode(',', $groupIds);
                } else {
                    $groupArr = $groupIds;
                }
            }

            $data = getRemarks($appid, $userArr, $groupArr);

            json_response([
                'code'    => 0,
                'msg'     => 'success',
                'success' => true,
                'data'    => $data
            ]);
            break;

        // ==================== 禁言成员 ====================
        case 'mute_member':
            $appid       = trim($_POST['appid'] ?? '');
            $groupOpenid = trim($_POST['group_openid'] ?? $_POST['target_id'] ?? '');
            $memberOpenid = trim($_POST['member_openid'] ?? $_POST['user_id'] ?? '');
            $seconds     = intval($_POST['seconds'] ?? 0);
            // 支持时长字符串: 30s / 10m / 1h / 7d
            $durationStr = trim($_POST['duration'] ?? '');

            if (empty($appid) || empty($groupOpenid) || empty($memberOpenid)) {
                json_response(['code' => 400, 'msg' => '缺少 appid、group_openid 或 member_openid 参数']);
            }

            // 解析时长字符串
            if (!empty($durationStr)) {
                $seconds = parseDuration($durationStr);
                if ($seconds === false) {
                    json_response(['code' => 400, 'msg' => '时长格式错误，支持: 30s/10m/1h/7d']);
                }
            }
            if ($seconds <= 0) {
                json_response(['code' => 400, 'msg' => '禁言时长必须大于0']);
            }

            $bot = getBot($appid);
            if (!$bot) {
                json_response(['code' => 404, 'msg' => '机器人不存在']);
            }

            if (!defined('appid')) define('appid', $bot['appid']);
            if (!defined('secret')) define('secret', $bot['secret']);
            if (!defined('type')) define('type', $bot['env']);

            require_once(__DIR__ . '/../../bot.php');
            $resp = 群禁言成员($groupOpenid, $memberOpenid, $seconds);
            $data = json_decode($resp, true);

            后台操作记录('群成员禁言', $groupOpenid . ' 成员:' . $memberOpenid . ' 时长:' . $seconds . 's', $appid);
            json_response([
                'code'    => 0,
                'msg'     => '禁言操作已执行',
                'success' => true,
                'data'    => $data
            ]);
            break;

        // ==================== 解禁成员 ====================
        case 'unmute_member':
            $appid        = trim($_POST['appid'] ?? '');
            $groupOpenid  = trim($_POST['group_openid'] ?? $_POST['target_id'] ?? '');
            $memberOpenid = trim($_POST['member_openid'] ?? $_POST['user_id'] ?? '');

            if (empty($appid) || empty($groupOpenid) || empty($memberOpenid)) {
                json_response(['code' => 400, 'msg' => '缺少 appid、group_openid 或 member_openid 参数']);
            }

            $bot = getBot($appid);
            if (!$bot) {
                json_response(['code' => 404, 'msg' => '机器人不存在']);
            }

            if (!defined('appid')) define('appid', $bot['appid']);
            if (!defined('secret')) define('secret', $bot['secret']);
            if (!defined('type')) define('type', $bot['env']);

            require_once(__DIR__ . '/../../bot.php');
            $resp = 群解禁成员($groupOpenid, $memberOpenid);
            $data = json_decode($resp, true);

            后台操作记录('群成员解禁', $groupOpenid . ' 成员:' . $memberOpenid, $appid);
            json_response([
                'code'    => 0,
                'msg'     => '解禁操作已执行',
                'success' => true,
                'data'    => $data
            ]);
            break;

        // ==================== 批量禁言 ====================
        case 'batch_mute':
            $appid       = trim($_POST['appid'] ?? '');
            $groupOpenid = trim($_POST['group_openid'] ?? $_POST['target_id'] ?? '');
            $memberOpenids = $_POST['member_openids'] ?? $_POST['user_ids'] ?? '';
            $seconds     = intval($_POST['seconds'] ?? 0);
            $durationStr = trim($_POST['duration'] ?? '');

            if (empty($appid) || empty($groupOpenid) || empty($memberOpenids)) {
                json_response(['code' => 400, 'msg' => '缺少必要参数']);
            }

            if (is_string($memberOpenids)) {
                $decoded = json_decode($memberOpenids, true);
                $memberOpenids = is_array($decoded) ? $decoded : explode(',', $memberOpenids);
            }
            if (!is_array($memberOpenids) || count($memberOpenids) === 0) {
                json_response(['code' => 400, 'msg' => 'member_openids 格式不正确']);
            }

            if (!empty($durationStr)) {
                $seconds = parseDuration($durationStr);
                if ($seconds === false) {
                    json_response(['code' => 400, 'msg' => '时长格式错误']);
                }
            }
            if ($seconds <= 0) {
                json_response(['code' => 400, 'msg' => '禁言时长必须大于0']);
            }

            $bot = getBot($appid);
            if (!$bot) {
                json_response(['code' => 404, 'msg' => '机器人不存在']);
            }

            if (!defined('appid')) define('appid', $bot['appid']);
            if (!defined('secret')) define('secret', $bot['secret']);
            if (!defined('type')) define('type', $bot['env']);

            require_once(__DIR__ . '/../../bot.php');
            $resp = 群批量禁言($groupOpenid, $memberOpenids, $seconds);
            $data = json_decode($resp, true);

            后台操作记录('群批量禁言', $groupOpenid . ' 成员数:' . count($memberOpenids) . ' 时长:' . $seconds . 's', $appid);
            json_response([
                'code'    => 0,
                'msg'     => '批量禁言操作已执行',
                'success' => true,
                'data'    => $data
            ]);
            break;

        // ==================== 查询群禁言状态 ====================
        case 'query_mute':
            $appid       = trim($_GET['appid'] ?? $_POST['appid'] ?? '');
            $groupOpenid = trim($_GET['group_openid'] ?? $_POST['group_openid'] ?? $_POST['target_id'] ?? '');

            if (empty($appid) || empty($groupOpenid)) {
                json_response(['code' => 400, 'msg' => '缺少 appid 或 group_openid 参数']);
            }

            $bot = getBot($appid);
            if (!$bot) {
                json_response(['code' => 404, 'msg' => '机器人不存在']);
            }

            if (!defined('appid')) define('appid', $bot['appid']);
            if (!defined('secret')) define('secret', $bot['secret']);
            if (!defined('type')) define('type', $bot['env']);

            require_once(__DIR__ . '/../../bot.php');
            $resp = 查询群禁言状态($groupOpenid);
            $data = json_decode($resp, true);

            json_response([
                'code'    => 0,
                'msg'     => 'success',
                'success' => true,
                'data'    => $data
            ]);
            break;

        default:
            json_response(['code' => 400, 'msg' => '未知操作类型: ' . htmlspecialchars($action)]);
    }
} catch (Exception $e) {
    json_response(['code' => 500, 'msg' => '服务器错误: ' . $e->getMessage()]);
} catch (Throwable $e) {
    json_response(['code' => 500, 'msg' => '服务器异常: ' . $e->getMessage()]);
}
