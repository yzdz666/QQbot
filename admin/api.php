<?php
/**
 * 管理后台 API - 处理所有AJAX请求
 */
date_default_timezone_set('Asia/Shanghai');

// 开启输出缓冲，防止任何意外输出导致 JSON 解析失败
ob_start();

// 设置自定义错误处理，将错误转为 JSON 响应
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // 仅处理实际错误，忽略 @ 抑制的错误
    if (!(error_reporting() & $errno)) return false;
    // 清空缓冲区，确保只输出 JSON
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '服务器错误: ' . $errstr . ' (文件: ' . basename($errfile) . ' 行: ' . $errline . ')'], JSON_UNESCAPED_UNICODE);
    exit;
});

// 捕获致命错误
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '致命错误: ' . $error['message'] . ' (文件: ' . basename($error['file']) . ' 行: ' . $error['line'] . ')'], JSON_UNESCAPED_UNICODE);
    }
});

require_once(__DIR__ . '/../function.php');
require_once(__DIR__ . '/../auth.php');

// 检查安装
if (!Auth::isInstalled()) {
    json_response(['success' => false, 'message' => '系统未安装'], 503);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 登录/登出不需要认证
if ($action === 'logout') {
    Auth::logout();
    // 重定向到登录页（首页），而不是返回JSON
    header('Location: login.php');
    exit;
}

// 其他操作需要认证
if (!Auth::check()) {
    json_response(['success' => false, 'message' => '未登录或会话已过期'], 401);
}

// CSRF验证（写操作需要）
$csrfProtectedActions = ['bot_add', 'bot_delete', 'bot_update', 'bot_toggle', 'plugin_toggle', 'plugin_create', 'plugin_upload', 'plugin_delete', 'plugin_write', 'change_password', 'save_ai_config', 'save_setting', 'clear_messages', 'clear_logs', 'clear_events', 'unban_ip', 'test_send', 'import_log'];
if (in_array($action, $csrfProtectedActions)) {
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        json_response(['success' => false, 'message' => 'CSRF Token验证失败，请刷新页面重试'], 403);
    }
}

switch ($action) {
    // ==================== 机器人管理 ====================
    case 'bot_add':
        $appid = trim($_POST['appid'] ?? '');
        $secret = trim($_POST['secret'] ?? '');
        $env = $_POST['env'] ?? '正式';
        if (empty($appid) || empty($secret)) {
            json_response(['success' => false, 'message' => 'AppID和Secret不能为空']);
        }
        addBot($appid, $secret, $env);
        json_response(['success' => true, 'message' => '机器人添加成功']);
        break;

    case 'bot_update':
        $appid = $_POST['appid'] ?? '';
        $data = [];
        foreach (['secret', 'env', 'ws_enabled', 'ws_url', 'enabled', 'robot_qq', 'nickname', 'avatar'] as $f) {
            if (isset($_POST[$f])) $data[$f] = $_POST[$f];
        }
        if (empty($appid)) {
            json_response(['success' => false, 'message' => 'AppID不能为空']);
        }
        updateBot($appid, $data);
        json_response(['success' => true, 'message' => '更新成功']);
        break;

    case 'bot_delete':
        $appid = $_POST['appid'] ?? '';
        if (empty($appid)) {
            json_response(['success' => false, 'message' => 'AppID不能为空']);
        }
        deleteBot($appid);
        json_response(['success' => true, 'message' => '已删除']);
        break;

    case 'bot_toggle':
        $appid = $_POST['appid'] ?? '';
        $field = $_POST['field'] ?? 'enabled';
        $value = intval($_POST['value'] ?? 0);
        $bot = getBot($appid);
        if (!$bot) {
            json_response(['success' => false, 'message' => '机器人不存在']);
        }
        updateBot($appid, [$field => $value]);
        json_response(['success' => true, 'message' => '状态已更新']);
        break;

    // ==================== 获取机器人信息（头像、昵称） ====================
    case 'bot_get_info':
        $appid = trim($_POST['appid'] ?? '');
        if (empty($appid)) {
            json_response(['success' => false, 'message' => 'AppID不能为空']);
        }
        $result = fetchAndUpdateBotInfo($appid);
        json_response($result);
        break;

    // ==================== 插件管理 ====================
    case 'plugin_toggle':
        $appid = $_POST['appid'] ?? '';
        $pluginName = $_POST['plugin_name'] ?? '';
        $enabled = intval($_POST['enabled'] ?? 0);
        if (empty($appid) || empty($pluginName)) {
            json_response(['success' => false, 'message' => '参数缺失']);
        }
        db()->execute(
            "INSERT OR IGNORE INTO plugin_status (appid, plugin_name, enabled) VALUES (?, ?, ?)",
            [$appid, $pluginName, $enabled]
        );
        db()->execute(
            "UPDATE plugin_status SET enabled = ? WHERE appid = ? AND plugin_name = ?",
            [$enabled, $appid, $pluginName]
        );
        json_response(['success' => true, 'message' => '插件状态已更新']);
        break;

    case 'plugin_create':
        $pluginName = trim($_POST['plugin_name'] ?? '');
        if (!preg_match('/^[\w\-\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]+$/u', $pluginName)) {
            json_response(['success' => false, 'message' => '插件名只允许字母、数字、下划线、横杠和中文']);
        }
        $pluginDir = __DIR__ . '/../plugin/';
        if (!is_dir($pluginDir)) {
            mkdir($pluginDir, 0777, true);
        }
        $file = $pluginDir . $pluginName . '.php';
        if (file_exists($file)) {
            json_response(['success' => false, 'message' => '插件已存在']);
        }
        $template = "<?php\n\n/**\n * 插件：{$pluginName}\n * 创建时间：" . date('Y-m-d H:i:s') . "\n */\n\nif (!defined('消息')) return;\n\n\$msg = trim(消息);\n\n// 在这里编写你的插件逻辑\n";
        if (file_put_contents($file, $template) === false) {
            json_response(['success' => false, 'message' => '创建失败，请检查权限']);
        }
        json_response(['success' => true, 'message' => '插件创建成功']);
        break;

    case 'plugin_upload':
        if (!isset($_FILES['plugin_file']) || $_FILES['plugin_file']['error'] !== UPLOAD_ERR_OK) {
            json_response(['success' => false, 'message' => '请选择文件']);
        }
        $filename = $_FILES['plugin_file']['name'];
        if (!preg_match('/^[\w\-\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]+\.php$/u', $filename)) {
            json_response(['success' => false, 'message' => '文件名只允许字母、数字、横杠、下划线和中文']);
        }
        $pluginDir = __DIR__ . '/../plugin/';
        if (!is_dir($pluginDir)) {
            mkdir($pluginDir, 0777, true);
        }
        $dest = $pluginDir . $filename;
        if (move_uploaded_file($_FILES['plugin_file']['tmp_name'], $dest)) {
            json_response(['success' => true, 'message' => '插件上传成功']);
        } else {
            json_response(['success' => false, 'message' => '上传失败']);
        }
        break;

    case 'plugin_delete':
        $pluginName = $_POST['plugin_name'] ?? '';
        if (!preg_match('/^[\w\-\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]+$/u', $pluginName)) {
            json_response(['success' => false, 'message' => '无效的插件名']);
        }
        $file = __DIR__ . '/../plugin/' . $pluginName . '.php';
        if (file_exists($file)) {
            unlink($file);
            // 同时删除所有bot的插件状态
            db()->execute("DELETE FROM plugin_status WHERE plugin_name = ?", [$pluginName]);
            json_response(['success' => true, 'message' => '插件已删除']);
        } else {
            json_response(['success' => false, 'message' => '插件文件不存在']);
        }
        break;

    case 'plugin_read':
        $pluginName = $_POST['plugin_name'] ?? $_GET['plugin_name'] ?? '';
        if (!preg_match('/^[\w\-\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]+$/u', $pluginName)) {
            json_response(['success' => false, 'message' => '无效的插件名']);
        }
        $file = __DIR__ . '/../plugin/' . $pluginName . '.php';
        if (!file_exists($file)) {
            json_response(['success' => false, 'message' => '插件文件不存在']);
        }
        $content = file_get_contents($file);
        if ($content === false) {
            json_response(['success' => false, 'message' => '读取失败']);
        }
        json_response(['success' => true, 'content' => $content]);
        break;

    case 'plugin_write':
        $pluginName = $_POST['plugin_name'] ?? '';
        $content = $_POST['content'] ?? '';
        if (!preg_match('/^[\w\-\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]+$/u', $pluginName)) {
            json_response(['success' => false, 'message' => '无效的插件名']);
        }
        // 检查是否使用base64编码（前端通过 btoa(unescape(encodeURIComponent(content))) 编码）
        $encoding = $_POST['encoding'] ?? '';
        if ($encoding === 'base64') {
            $decoded = base64_decode($content, true);
            if ($decoded === false) {
                json_response(['success' => false, 'message' => 'Base64解码失败，内容可能已损坏']);
            }
            $content = $decoded;
        }
        $pluginDir = __DIR__ . '/../plugin/';
        if (!is_dir($pluginDir)) {
            mkdir($pluginDir, 0777, true);
        }
        $file = $pluginDir . $pluginName . '.php';
        if (file_put_contents($file, $content) === false) {
            json_response(['success' => false, 'message' => '写入失败，请检查权限']);
        }
        json_response(['success' => true, 'message' => '保存成功']);
        break;

    // ==================== 设置 ====================
    case 'change_password':
        $username = trim($_POST['username'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        if (empty($username) || strlen($newPassword) < 6) {
            json_response(['success' => false, 'message' => '用户名不能为空且密码至少6位']);
        }
        $result = Auth::changePassword($username, $newPassword);
        json_response($result);
        break;

    case 'save_ai_config':
        $baseUrl = trim($_POST['base_url'] ?? '');
        $apiKey = trim($_POST['api_key'] ?? '');
        $model = trim($_POST['model'] ?? 'gpt-4o-mini');
        db()->execute("UPDATE ai_config SET base_url = ?, api_key = ?, model = ? WHERE id = 1",
            [$baseUrl, $apiKey, $model]);
        json_response(['success' => true, 'message' => 'AI配置已保存']);
        break;

    case 'save_setting':
        $key = $_POST['key'] ?? '';
        $value = $_POST['value'] ?? '';
        if (empty($key)) {
            json_response(['success' => false, 'message' => '键名不能为空']);
        }
        db()->setSetting($key, $value);
        json_response(['success' => true, 'message' => '设置已保存']);
        break;

    // ==================== 消息 ====================
    case 'clear_messages':
        $appid = $_POST['appid'] ?? '';
        if (empty($appid)) {
            db()->execute("DELETE FROM messages");
        } else {
            db()->execute("DELETE FROM messages WHERE appid = ?", [$appid]);
        }
        json_response(['success' => true, 'message' => '消息已清空']);
        break;

    case 'clear_logs':
        db()->execute("DELETE FROM system_logs");
        json_response(['success' => true, 'message' => '日志已清空']);
        break;

    case 'clear_events':
        db()->execute("DELETE FROM event_dedup");
        json_response(['success' => true, 'message' => '事件记录已清空']);
        break;

    case 'unban_ip':
        $ip = $_POST['ip'] ?? '';
        if (empty($ip)) {
            json_response(['success' => false, 'message' => 'IP不能为空']);
        }
        Auth::unbanIp($ip);
        json_response(['success' => true, 'message' => 'IP已解封']);
        break;

    // ==================== 导出 ====================
    case 'export_config':
        $config = ['admin' => '', 'password' => ''];
        $admin = db()->fetch("SELECT * FROM admin LIMIT 1");
        if ($admin) {
            $config['admin'] = $admin['username'];
            $config['password'] = $admin['password'];
        }

        $bots = getBots();
        $main = [];
        foreach ($bots as $bot) {
            $main[$bot['appid']] = [
                'secret' => $bot['secret'],
                'type' => $bot['env'],
                'ws_enabled' => $bot['ws_enabled'],
                'ws_url' => $bot['ws_url'],
                'plugin' => []
            ];
            $plugins = db()->fetchAll("SELECT plugin_name, enabled FROM plugin_status WHERE appid = ?", [$bot['appid']]);
            foreach ($plugins as $p) {
                $main[$bot['appid']]['plugin'][$p['plugin_name']] = (bool)$p['enabled'];
            }
        }

        $aiConfig = db()->fetch("SELECT * FROM ai_config WHERE id = 1");

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="guanji_export_' . date('Ymd_His') . '.json"');
        echo json_encode([
            'config' => $config,
            'main' => $main,
            'ai_config' => $aiConfig
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
        break;

    // ==================== 测试发送消息 ====================
    case 'test_send':
        $appid = $_POST['appid'] ?? '';
        $targetId = trim($_POST['target_id'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $sourceType = $_POST['source_type'] ?? '群聊';

        if (empty($appid) || empty($targetId) || empty($message)) {
            json_response(['success' => false, 'message' => '参数不完整']);
        }

        $bot = getBot($appid);
        if (!$bot) {
            json_response(['success' => false, 'message' => '机器人不存在']);
        }

        // 设置上下文
        if (!defined('appid')) define('appid', $bot['appid']);
        if (!defined('secret')) define('secret', $bot['secret']);
        if (!defined('type')) define('type', $bot['env']);
        if (!defined('消息来源')) define('消息来源', $sourceType);
        if (!defined('来源')) define('来源', $targetId);
        if (!defined('消息ID')) define('消息ID', '');
        if (!defined('事件ID')) define('事件ID', '');

        $resp = 文字($message);
        $data = json_decode($resp, true);

        if (isset($data['id']) || (isset($data['code']) && $data['code'] == 0)) {
            json_response(['success' => true, 'message' => '发送成功', 'response' => $data]);
        } else {
            json_response(['success' => false, 'message' => '发送失败: ' . ($data['message'] ?? '未知错误'), 'response' => $data]);
        }
        break;

    // ==================== 统计数据 ====================
    case 'dashboard_stats':
        $botCount = db()->fetchColumn("SELECT COUNT(*) FROM bots");
        $msgCount = db()->fetchColumn("SELECT COUNT(*) FROM messages");
        $userCount = db()->fetchColumn("SELECT COUNT(*) FROM users");
        $groupCount = db()->fetchColumn("SELECT COUNT(*) FROM groups");
        $todayMsg = db()->fetchColumn("SELECT COUNT(*) FROM messages WHERE created_at > datetime('now','localtime','start of day')");
        $recvCount = db()->fetchColumn("SELECT COUNT(*) FROM messages WHERE direction = '接收'");
        $sendCount = db()->fetchColumn("SELECT COUNT(*) FROM messages WHERE direction = '发送'");

        // 最近7天趋势
        $trend = db()->fetchAll(
            "SELECT date(created_at) as d, 
                    SUM(CASE WHEN direction='接收' THEN 1 ELSE 0 END) as recv,
                    SUM(CASE WHEN direction='发送' THEN 1 ELSE 0 END) as send
             FROM messages 
             WHERE created_at > datetime('now','localtime','-7 days')
             GROUP BY date(created_at) 
             ORDER BY d"
        );

        json_response([
            'success' => true,
            'data' => [
                'bots' => $botCount,
                'messages' => $msgCount,
                'users' => $userCount,
                'groups' => $groupCount,
                'today_messages' => $todayMsg,
                'recv_count' => $recvCount,
                'send_count' => $sendCount,
                'trend' => $trend
            ]
        ]);
        break;

    // ==================== 导入日志文件到数据库 ====================
    case 'import_log':
        $appid = $_POST['appid'] ?? '';
        $logContent = $_POST['content'] ?? '';
        $fileName = $_POST['filename'] ?? 'import.log';

        if (empty($logContent)) {
            json_response(['success' => false, 'message' => '日志内容为空']);
        }

        $imported = 0;
        $errors = 0;
        $lines = explode("\n", $logContent);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $matches)) {
                $time = $matches[1];
                $json = $matches[2];
                if ($json === '重复数据') continue;

                $data = json_decode($json, true);
                if (!is_array($data)) continue;

                $eventType = $data['t'] ?? '';
                $content_text = '';
                $targetId = '';
                $userId = '';
                $sourceType = '';
                $msgId = '';

                // 解析机器人发送的日志
                if (isset($data['direction']) && $data['direction'] === '发送') {
                    $sourceType = $data['source_type'] ?? '';
                    $targetId = $data['target_id'] ?? '';
                    $content_text = $data['content'] ?? '';
                    $msgId = $data['id'] ?? '';
                    $userId = '';
                }
                // 解析原始事件日志
                elseif (!empty($eventType)) {
                    switch ($eventType) {
                        case 'GROUP_AT_MESSAGE_CREATE':
                        case 'GROUP_MESSAGE_CREATE':
                            $sourceType = '群聊';
                            $content_text = $data['d']['content'] ?? '';
                            $targetId = $data['d']['group_openid'] ?? ($data['d']['group_id'] ?? '');
                            $userId = $data['d']['author']['id'] ?? '';
                            $msgId = $data['d']['id'] ?? '';
                            break;
                        case 'C2C_MESSAGE_CREATE':
                            $sourceType = '私聊';
                            $content_text = $data['d']['content'] ?? '';
                            $targetId = $data['d']['author']['id'] ?? '';
                            $userId = $data['d']['author']['id'] ?? '';
                            $msgId = $data['d']['id'] ?? '';
                            break;
                        case 'INTERACTION_CREATE':
                            $sourceType = '互动';
                            $targetId = $data['d']['group_openid'] ?? ($data['d']['user_openid'] ?? '');
                            $userId = $data['d']['user_openid'] ?? '';
                            $msgId = $data['id'] ?? '';
                            break;
                        case 'GROUP_ADD_ROBOT':
                            $sourceType = '加群';
                            $targetId = $data['d']['group_openid'] ?? '';
                            break;
                        case 'GROUP_DEL_ROBOT':
                            $sourceType = '退群';
                            $targetId = $data['d']['group_openid'] ?? '';
                            break;
                        default:
                            $sourceType = $eventType;
                            break;
                    }
                }

                try {
                    if (!empty($sourceType) || !empty($content_text)) {
                        db()->execute(
                            "INSERT OR IGNORE INTO messages (appid, direction, source_type, target_id, user_id, content_type, content, message_id, raw_data, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [$appid, isset($data['direction']) ? $data['direction'] : '接收', $sourceType, $targetId, $userId, 
                             isset($data['content_type']) ? $data['content_type'] : '文字', 
                             $content_text, $msgId, $json, $time]
                        );
                        $imported++;
                    }
                } catch (Exception $e) {
                    $errors++;
                }
            }
        }

        json_response(['success' => true, 'message' => "导入完成：成功 {$imported} 条" . ($errors > 0 ? "，失败 {$errors} 条" : ''), 'imported' => $imported, 'errors' => $errors]);
        break;

    default:
        json_response(['success' => false, 'message' => '未知操作: ' . htmlspecialchars($action)], 400);
}
