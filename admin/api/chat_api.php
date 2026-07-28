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

// 认证检查
if (!Auth::check()) {
    json_response(['code' => 401, 'msg' => '未登录或会话已过期'], 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
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
            // 系统事件（退群、群成员移除、加群、群成员增加）归入群聊会话
            $total = db()->fetchColumn(
                "SELECT COUNT(DISTINCT target_id) FROM messages " . $where,
                $params
            );

            $sessions = db()->fetchAll(
                "SELECT appid, target_id, 
                        CASE WHEN source_type IN ('退群', '群成员移除', '加群', '群成员增加') THEN '群聊' ELSE source_type END as source_type,
                        MAX(created_at) as last_active,
                        COUNT(*) as msg_count
                 FROM messages 
                 {$where} 
                 GROUP BY appid, target_id, 
                          CASE WHEN source_type IN ('退群', '群成员移除', '加群', '群成员增加') THEN '群聊' ELSE source_type END 
                 ORDER BY last_active DESC 
                 LIMIT ? OFFSET ?",
                array_merge($params, [$pageSize, $offset])
            );

            // 尝试补充群名/用户名、备注和头像，以及最后一条消息预览
            foreach ($sessions as &$session) {
                if ($session['source_type'] === '群聊') {
                    $group = db()->fetch(
                        "SELECT group_name, remark, custom_avatar FROM groups WHERE appid = ? AND group_id = ?",
                        [$appid, $session['target_id']]
                    );
                    $session['name'] = $group ? (!empty($group['remark']) ? $group['remark'] : $group['group_name']) : '';
                    $session['remark'] = $group ? ($group['remark'] ?? '') : '';
                    $session['custom_avatar'] = $group ? ($group['custom_avatar'] ?? '') : '';
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
                    $sysTypes = ['退群', '群成员移除', '加群', '群成员增加'];
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
                // 群聊视图包含系统事件（退群、群成员移除、加群、群成员增加）
                if ($sourceType === '群聊') {
                    $where .= " AND source_type IN ('群聊', '退群', '群成员移除', '加群', '群成员增加')";
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
                        content_type, content, message_id as msg_id, raw_data, created_at
                 FROM messages $where
                 ORDER BY created_at DESC 
                 LIMIT ? OFFSET ?",
                array_merge($params, [$limit, $offset])
            );

            // 解析 raw_data 提取 member_role、username、bot、ref_idx
            foreach ($messages as &$msg) {
                $memberRole = '';
                $username = '';
                $isBot = false;
                $refIdx = '';
                if (!empty($msg['raw_data'])) {
                    $rawData = json_decode($msg['raw_data'], true);
                    if (is_array($rawData)) {
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
                        // 提取 REFIDX（用于引用消息）
                        if (isset($rawData['d']['message_scene']['ext']) && is_array($rawData['d']['message_scene']['ext'])) {
                            foreach ($rawData['d']['message_scene']['ext'] as $extStr) {
                                if (preg_match('/msg_idx=([^&]+)/', $extStr, $m)) {
                                    $refIdx = $m[1];
                                    break;
                                }
                            }
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
                                if (!isset($msg['media_url']) || empty($msg['media_url'])) {
                                    // 语音优先使用WAV URL（浏览器兼容）
                                    $msg['media_url'] = ($attType === 'voice' && !empty($attWavUrl)) ? $attWavUrl : $attUrl;
                                    $msg['content_type'] = $attType;
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
                $msg['ref_idx'] = $refIdx;
                // 提取 message_type 和 msg_elements（引用消息等）
                $msg['message_type'] = $rawData['d']['message_type'] ?? 0;
                if (isset($rawData['d']['msg_elements']) && is_array($rawData['d']['msg_elements'])) {
                    $msg['msg_elements'] = $rawData['d']['msg_elements'];
                }
            }
            unset($msg);

            // 补充群名/用户名、备注和头像
            $targetName = '';
            $targetRemark = '';
            $targetAvatar = '';
            if (!empty($sourceType) && !empty($targetId)) {
                if ($sourceType === '群聊') {
                    $group = db()->fetch(
                        "SELECT group_name, remark, custom_avatar FROM groups WHERE appid = ? AND group_id = ?",
                        [$appid, $targetId]
                    );
                    $targetName = $group ? $group['group_name'] : '';
                    $targetRemark = $group ? ($group['remark'] ?? '') : '';
                    $targetAvatar = $group ? ($group['custom_avatar'] ?? '') : '';
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
                        'total' => $total
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

                switch ($uploadType) {
                    case 'image':
                        // 图片支持附带文字内容（图片+文字一起发送）
                        $resp = 图片($fileData, $textContent ?: null);
                        break;
                    case 'voice':
                        // 上传的语音文件是二进制数据，使用本地语音函数直接发送
                        // 语音()函数期望URL并调用外部silk转换服务，不适用于二进制数据
                        $resp = 本地语音($fileData);
                        break;
                    case 'video':
                        $resp = 视频($fileData);
                        break;
                    case 'file':
                    default:
                        $resp = 文件($fileData, $originalName);
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
                        $resp = MD($content);
                        break;
                    case 'image':
                        $imageContent = $_POST['image_content'] ?? null;
                        $resp = 图片($content, $imageContent);
                        break;
                    case 'voice':
                        $resp = 语音($content);
                        break;
                    case 'video':
                        $resp = 视频($content);
                        break;
                    case 'file':
                        $fileName = $_POST['file_name'] ?? null;
                        $resp = 文件($content, $fileName);
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
                json_response(['code' => 0, 'msg' => '发送成功', 'success' => true, 'data' => $data]);
            } else {
                json_response(['code' => 500, 'msg' => '发送失败: ' . ($data['message'] ?? '未知错误'), 'success' => false, 'data' => $data]);
            }
            break;

        // ==================== 撤回消息 ====================
        case 'retract':
        case 'recall':
            $appid = trim($_POST['appid'] ?? '');
            $sourceType = trim($_POST['source_type'] ?? '群聊');
            $targetId = trim($_POST['target_id'] ?? '');
            // 兼容前端发送的 msg_id 和 message_id 参数
            $messageId = trim($_POST['msg_id'] ?? $_POST['message_id'] ?? '');

            if (empty($appid) || empty($messageId)) {
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
            db()->execute(
                "UPDATE messages SET content = '[已撤回]', content_type = '系统' WHERE appid = ? AND message_id = ?",
                [$appid, $messageId]
            );

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
                "SELECT message_id, target_id FROM messages 
                 WHERE appid = ? AND target_id = ? AND direction = '发送' AND content_type != '系统'
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

            // 撤回成功后，更新数据库中的消息状态为已撤回
            db()->execute(
                "UPDATE messages SET content = '[已撤回]', content_type = '系统' WHERE appid = ? AND message_id = ?",
                [$appid, $lastMsg['message_id']]
            );

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

        default:
            json_response(['code' => 400, 'msg' => '未知操作类型: ' . htmlspecialchars($action)]);
    }
} catch (Exception $e) {
    json_response(['code' => 500, 'msg' => '服务器错误: ' . $e->getMessage()]);
} catch (Throwable $e) {
    json_response(['code' => 500, 'msg' => '服务器异常: ' . $e->getMessage()]);
}
