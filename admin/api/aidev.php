<?php
/**
 * AI 开发 API
 * 从原版复制，修改：
 * - 认证改为 Auth::check()
 * - AI 配置从数据库 ai_config 表读取（替代 ai_config.json）
 * - 插件自动启用写入 plugin_status 表（替代 main.json）
 */
set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/function.php';
require_once dirname(__DIR__, 2) . '/auth.php';

if (!Auth::check()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => '未登录']));
}

// ========== 路径配置 ==========
$rootPath  = dirname(__DIR__, 2);
$pluginDir = $rootPath . '/plugin/';

// ========== AI 配置读写函数（数据库版） ==========
function getAiConfig() {
    $row = db()->fetch("SELECT * FROM ai_config WHERE id = 1");
    if (!$row) {
        return [
            'base_url' => 'https://api.openai.com/v1',
            'api_key'  => '',
            'model'    => 'gpt-4o-mini'
        ];
    }
    return [
        'base_url' => $row['base_url'] ?: 'https://api.openai.com/v1',
        'api_key'  => $row['api_key'] ?: '',
        'model'    => $row['model'] ?: 'gpt-4o-mini'
    ];
}

function saveAiConfig($data) {
    $base  = isset($data['base_url']) ? trim($data['base_url']) : 'https://api.openai.com/v1';
    $key   = isset($data['api_key']) ? trim($data['api_key']) : '';
    $model = isset($data['model']) ? trim($data['model']) : 'gpt-4o-mini';

    $exists = db()->fetch("SELECT id FROM ai_config WHERE id = 1");
    if ($exists) {
        return db()->execute(
            "UPDATE ai_config SET base_url = ?, api_key = ?, model = ? WHERE id = 1",
            [$base, $key, $model]
        ) !== false;
    } else {
        return db()->execute(
            "INSERT INTO ai_config (id, base_url, api_key, model) VALUES (1, ?, ?, ?)",
            [$base, $key, $model]
        ) !== false;
    }
}

// ========== 获取输入 ==========
$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
} else {
    $input = $_POST;
}

$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    // ========== 获取 AI 配置 ==========
    case 'get_config':
        $config = getAiConfig();
        $config['api_key_set'] = !empty($config['api_key']);
        $config['api_key'] = $config['api_key_set'] ? '••••••••' : '';
        echo json_encode(['success' => true, 'config' => $config]);
        break;

    // ========== 保存 AI 配置 ==========
    case 'save_config':
        $base = trim($input['base_url'] ?? '');
        $model = trim($input['model'] ?? '');
        $keyEncoded = trim($input['api_key'] ?? '');

        // 如果是 Base64 编码，先解码
        $key = '';
        if (!empty($keyEncoded)) {
            $key = base64_decode($keyEncoded);
            if ($key === false) {
                die(json_encode(['success' => false, 'error' => '密钥解码失败']));
            }
        }

        // 获取当前配置
        $current = getAiConfig();

        // 如果没有发送新密钥，保留原密钥
        if (empty($key)) {
            $key = $current['api_key'] ?? '';
        }

        // 如果仍然没有密钥，报错
        if (empty($key)) {
            die(json_encode(['success' => false, 'error' => 'API Key 不能为空']));
        }

        $data = [
            'base_url' => $base ?: 'https://api.openai.com/v1',
            'api_key'  => $key,
            'model'    => $model ?: 'gpt-4o-mini'
        ];

        if (saveAiConfig($data)) {
            echo json_encode(['success' => true, 'message' => '配置已保存']);
        } else {
            echo json_encode(['success' => false, 'error' => '保存配置失败，请检查权限']);
        }
        break;

    // ========== 生成插件代码 ==========
    case 'generate':
        $prompt = trim($input['prompt'] ?? '');
        $base = trim($input['base_url'] ?? '');
        $model = trim($input['model'] ?? '');
        $keyEncoded = trim($input['api_key'] ?? '');

        // 解码密钥
        $key = '';
        if (!empty($keyEncoded)) {
            $key = base64_decode($keyEncoded);
            if ($key === false) {
                die(json_encode(['success' => false, 'error' => '密钥解码失败']));
            }
        }

        // 如果前端没有传密钥，从数据库读取
        if (empty($key)) {
            $config = getAiConfig();
            if (empty($base)) $base = $config['base_url'];
            $key = $config['api_key'];
            if (empty($model)) $model = $config['model'];
        }

        if (empty($prompt)) {
            die(json_encode(['success' => false, 'error' => '需求不能为空']));
        }
        if (empty($key)) {
            die(json_encode(['success' => false, 'error' => '缺少 API Key，请先在 AI 配置中设置']));
        }

        // 读取文档.md
        $docFile = $rootPath . '/文档.md';
        $docContent = file_exists($docFile) ? file_get_contents($docFile) : '（文档未找到，请确保文档.md存在于根目录）';

        $system = "你是 QQ 机器人插件开发专家，精通“月下独酌管机”PHP框架。\n"
                . "【极其重要】框架中的变量是全局常量，不是 PHP 变量，使用时直接写名称，不加 $ 符号。\n"
                . "正确示例：if (消息 == \"测试\") { 文字(\"收到\".用户.\"的消息\"); }\n"
                . "错误示例：if ($消息 == \"测试\") { ... }  // 这是错误的！\n\n"
                . "所有可用变量、函数、类的完整说明请参考以下文档，务必严格遵守文档中的每一个示例。\n\n"
                . "=== 框架开发文档（完整） ===\n" . $docContent . "\n=== 文档结束 ===\n"
                . "请根据用户的需求，生成一份符合上述文档规范的 PHP 插件代码。\n"
                . "只输出 PHP 代码，不要包含任何解释、不要用 markdown 代码块包裹，直接输出可运行的 PHP 代码。";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "用户需求：\n" . $prompt . "\n请生成插件代码。"]
        ];

        $url = rtrim($base, '/') . '/chat/completions';
        $payload = [
            'model' => $model ?: 'gpt-4o-mini',
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 4096
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            die(json_encode(['success' => false, 'error' => 'cURL 错误：' . $error]));
        }
        curl_close($ch);

        if ($httpCode !== 200) {
            $err = json_decode($response, true);
            $msg = $err['error']['message'] ?? 'HTTP ' . $httpCode;
            die(json_encode(['success' => false, 'error' => $msg]));
        }

        $data = json_decode($response, true);
        $code = $data['choices'][0]['message']['content'] ?? '';
        $code = preg_replace('/^```php\s*/', '', $code);
        $code = preg_replace('/\s*```$/', '', $code);
        echo json_encode(['success' => true, 'code' => trim($code)]);
        break;

    // ========== 保存插件 ==========
    case 'save':
        $name = trim($input['name'] ?? '');
        $codeRaw = $input['code'] ?? '';

        // Base64 解码
        if (isset($input['encoded']) && $input['encoded'] == '1') {
            $code = base64_decode($codeRaw);
            if ($code === false) {
                die(json_encode(['success' => false, 'error' => '代码解码失败']));
            }
        } else {
            $code = $codeRaw;
        }

        if (empty($name) || empty($code)) {
            die(json_encode(['success' => false, 'error' => '插件名或代码为空']));
        }

        $name = preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}]/u', '', $name);
        if (empty($name)) {
            die(json_encode(['success' => false, 'error' => '插件名不合法']));
        }

        // 确保 plugin 目录存在
        if (!is_dir($pluginDir)) {
            if (!mkdir($pluginDir, 0755, true)) {
                die(json_encode(['success' => false, 'error' => '无法创建 plugin 目录，请检查权限']));
            }
        }
        if (!is_writable($pluginDir)) {
            die(json_encode(['success' => false, 'error' => 'plugin 目录不可写，请检查权限']));
        }

        $pluginFile = $pluginDir . $name . '.php';
        if (file_put_contents($pluginFile, $code) === false) {
            die(json_encode(['success' => false, 'error' => '写入文件失败，请检查磁盘空间或权限']));
        }

        // 自动启用插件：写入 plugin_status 表（替代原版 main.json）
        $bots = getBots();
        if (!empty($bots)) {
            // 取第一个机器人作为默认启用目标
            $appid = $bots[0]['appid'];
            try {
                db()->execute(
                    "INSERT OR IGNORE INTO plugin_status (appid, plugin_name, enabled) VALUES (?, ?, 1)",
                    [$appid, $name]
                );
                db()->execute(
                    "UPDATE plugin_status SET enabled = 1 WHERE appid = ? AND plugin_name = ?",
                    [$appid, $name]
                );
                echo json_encode([
                    'success' => true,
                    'message' => "插件 {$name}.php 已保存并启用"
                ]);
                exit;
            } catch (Exception $e) {
                echo json_encode([
                    'success' => true,
                    'warning' => '插件已保存，但自动启用失败（数据库写入失败），请手动启用'
                ]);
                exit;
            }
        } else {
            echo json_encode([
                'success' => true,
                'warning' => '插件已保存，但未找到可用的机器人配置，请手动启用'
            ]);
            exit;
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => '未知操作']);
}
