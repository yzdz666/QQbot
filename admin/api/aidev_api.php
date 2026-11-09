<?php
/**
 * AI 写插件 API
 * 提供获取/保存 AI 配置、调用 AI 生成插件代码、保存生成插件等功能
 */
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../../function.php');
require_once(__DIR__ . '/../../auth.php');

// 同源校验：拦截跨站 POST（与 SameSite Cookie 双重防护）
Auth::requireWriteProtection();

// 认证检查
if (!Auth::check()) {
    json_response(['code' => 401, 'msg' => '未登录或会话已过期'], 401);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/**
 * 插件目录绝对路径
 */
function aidev_plugin_dir() {
    return dirname(__DIR__, 2) . '/plugin/';
}

/**
 * 插件名校验：字母、数字、下划线、横杠、中文，禁止路径分隔符
 */
function aidev_valid_name($name) {
    return (bool)preg_match('/^[\w\-\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]+$/u', $name);
}

/**
 * 允许用户直接输入 xxx.php，统一去掉扩展名
 */
function aidev_normalize_name($name) {
    $name = trim($name);
    if (preg_match('/\.php$/i', $name)) {
        $name = substr($name, 0, -4);
    }
    return $name;
}

try {
    switch ($action) {
        // ==================== 获取 AI 配置 ====================
        case 'get_config':
            $config = db()->fetch("SELECT * FROM ai_config WHERE id = 1");
            if (!$config) {
                // 初始化默认配置
                db()->execute(
                    "INSERT INTO ai_config (id, base_url, api_key, model) VALUES (1, '', '', 'gpt-4o-mini')",
                    []
                );
                $config = [
                    'id' => 1,
                    'base_url' => '',
                    'api_key' => '',
                    'model' => 'gpt-4o-mini'
                ];
            }

            // 隐藏 api_key，只显示前后几位
            $apiKey = $config['api_key'] ?? '';
            $maskedKey = '';
            if (!empty($apiKey)) {
                $len = strlen($apiKey);
                if ($len > 8) {
                    $maskedKey = substr($apiKey, 0, 4) . '****' . substr($apiKey, -4);
                } else {
                    $maskedKey = '****';
                }
            }

            json_response([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'base_url' => $config['base_url'] ?? '',
                    'api_key' => $maskedKey,
                    'has_key' => !empty($apiKey),
                    'model' => $config['model'] ?? 'gpt-4o-mini'
                ]
            ]);
            break;

        // ==================== 保存 AI 配置 ====================
        case 'save_config':
            $baseUrl = trim($_POST['base_url'] ?? '');
            $apiKey = trim($_POST['api_key'] ?? '');
            $model = trim($_POST['model'] ?? 'gpt-4o-mini');

            // 如果 api_key 是 base64 编码的，先解码
            $decoded = base64_decode($apiKey, true);
            if ($decoded !== false && strlen($decoded) > 10) {
                $apiKey = $decoded;
            }

            // 检查是否需要保留原有 api_key
            if ($apiKey === '****' || empty($apiKey)) {
                // 保留原有 key
                $existing = db()->fetch("SELECT api_key FROM ai_config WHERE id = 1");
                if ($existing && !empty($existing['api_key'])) {
                    $apiKey = $existing['api_key'];
                }
            }

            db()->execute(
                "INSERT OR IGNORE INTO ai_config (id, base_url, api_key, model) VALUES (1, ?, ?, ?)",
                [$baseUrl, $apiKey, $model]
            );
            db()->execute(
                "UPDATE ai_config SET base_url = ?, api_key = ?, model = ? WHERE id = 1",
                [$baseUrl, $apiKey, $model]
            );

            json_response([
                'code' => 0,
                'msg' => 'AI 配置已保存'
            ]);
            break;

        // ==================== 调用 AI 生成插件代码 ====================
        case 'generate':
            $prompt = trim($_POST['prompt'] ?? '');
            $appid = trim($_POST['appid'] ?? '');
            $baseCode = trim($_POST['base_code'] ?? '');

            if (empty($prompt)) {
                json_response(['code' => 400, 'msg' => '请输入插件描述']);
            }
            if ($baseCode !== '' && strlen($baseCode) > 131072) {
                json_response(['code' => 400, 'msg' => '现有插件代码超过 128KB，无法作为改写上下文']);
            }

            // 获取 AI 配置
            $config = db()->fetch("SELECT * FROM ai_config WHERE id = 1");
            if (!$config || empty($config['base_url']) || empty($config['api_key'])) {
                json_response(['code' => 400, 'msg' => '请先配置 AI 接口（Base URL 和 API Key）']);
            }

            $baseUrl = rtrim($config['base_url'], '/');
            $apiKey = $config['api_key'];
            $model = $config['model'] ?? 'gpt-4o-mini';

            // 智能构建 API URL，避免 /v1/ 重复
            if (preg_match('#/v\d+/?$#', $baseUrl)) {
                // base_url 已包含 /v1，直接追加 /chat/completions
                $apiUrl = $baseUrl . '/chat/completions';
            } else {
                // base_url 不包含版本号，追加 /v1/chat/completions
                $apiUrl = $baseUrl . '/v1/chat/completions';
            }

            // 读取框架开发文档作为上下文
            $docFile = dirname(__DIR__, 2) . '/文档.md';
            $docContent = file_exists($docFile) ? file_get_contents($docFile) : '';

            // 构建系统提示词
            $systemPrompt = "你是一个QQ机器人插件开发专家。请根据用户的需求生成一个PHP插件代码。\n\n";

            if (!empty($docContent)) {
                $systemPrompt .= "=== 框架开发文档 ===\n" . $docContent . "\n=== 文档结束 ===\n\n";
            }

            $systemPrompt .= <<<PROMPT
## 插件格式要求
- 文件开头必须检查: `if (!defined('消息')) return;`
- 消息内容使用全局常量 `消息`（直接写 消息，不加美元符号）
- 其他可用全局常量: 消息来源, 来源, 用户, 消息ID, 事件ID, appid, secret, type
- 发送文字回复: 文字("内容")
- 发送Markdown: MD("markdown内容")
- 发送图片: 图片("图片URL")
- 发送语音: 语音("音频URL")
- 发送视频: 视频("视频URL")
- 发送文件: 文件("文件URL", "文件名")
- 引用消息: 引用("消息ID", "回复内容")
- 文字卡片: 文卡(["text" => "文字", "url" => "链接"])
- 大图卡片: 大图("标题", "副标题", "图片URL")
- 跳转卡片: 跳转卡("标题", "描述", "图片URL", "链接")
- 发送按钮模板: 按钮("按钮模板ID")
- 原生按钮: 原生按钮("MD内容", [按钮行数组])
- 流式回复: 流式("消息1", "消息2", ...)
- 撤回消息: 撤回("消息ID")
- 日志记录: wlog("日志内容", appid)
- 数据存储: 写("命名空间", "键", "值") / 读("命名空间", "键", "默认值")
- HTTP请求: curl("URL", "方法", [头数组], 参数)
- 推送消息: 推送到群("群ID", "内容") / 推送到用户("用户ID", "内容")
- 获取Bot信息: BOT信息()
- 主动推送MD: 推送MD到群("群ID", "MD内容") / 推送MD到用户("用户ID", "MD内容")

## 注意事项
- 只输出PHP代码，不要有其他说明文字
- 代码要简洁高效
- 消息比较前先 trim: `\$msg = trim(消息);`
- 请在关键位置添加中文注释
- 使用 if (\$msg === 'xxx') 或 if (strpos(\$msg, 'xxx') === 0) 进行匹配
- 不要使用 exit/die
PROMPT;

            $userContent = "用户需求：\n" . $prompt . "\n\n";
            if ($baseCode !== '') {
                $userContent .= "=== 现有插件代码开始 ===\n" . $baseCode . "\n=== 现有插件代码结束 ===\n\n";
                $userContent .= "请在这份现有插件的基础上改写：保留用户没有要求改动的指令与功能，不要删减无关逻辑，输出改写后的完整插件文件。";
            } else {
                $userContent .= "请生成插件代码。";
            }

            $requestBody = json_encode([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent]
                ],
                'temperature' => 0.3,
                'max_tokens' => $baseCode !== '' ? 8192 : 4096
            ]);

            // 使用直接 curl 调用，带完整错误处理
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 180);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = '';
            if (curl_errno($ch)) {
                $curlError = curl_error($ch);
            }
            curl_close($ch);

            // 处理 curl 错误
            if ($curlError) {
                json_response([
                    'code' => 500,
                    'msg' => 'AI接口连接失败: ' . $curlError . ' (URL: ' . $apiUrl . ')'
                ]);
            }

            if ($httpCode !== 200) {
                $errData = json_decode($response, true);
                $errorMsg = $errData['error']['message'] ?? ('HTTP ' . $httpCode);
                json_response([
                    'code' => 500,
                    'msg' => 'AI接口返回错误(HTTP ' . $httpCode . '): ' . $errorMsg
                ]);
            }

            $data = json_decode($response, true);

            if (!isset($data['choices'][0]['message']['content'])) {
                $errorMsg = $data['error']['message'] ?? 'AI 接口返回格式异常';
                json_response([
                    'code' => 500,
                    'msg' => 'AI 生成失败: ' . $errorMsg
                ]);
            }

            $generatedCode = $data['choices'][0]['message']['content'];

            // 提取 PHP 代码块（如果被 ```php 包裹）
            if (preg_match('/```php\s*\n?(.*?)\n?\s*```/s', $generatedCode, $matches)) {
                $generatedCode = trim($matches[1]);
            } elseif (preg_match('/```\s*\n?(.*?)\n?\s*```/s', $generatedCode, $matches)) {
                $generatedCode = trim($matches[1]);
            }

            json_response([
                'code' => 0,
                'msg' => '生成成功',
                'data' => [
                    'code' => $generatedCode,
                    'model' => $model,
                    'usage' => $data['usage'] ?? null
                ]
            ]);
            break;

        // ==================== 列出 plugin/ 下的插件 ====================
        case 'list_plugins':
            $dir = aidev_plugin_dir();
            $items = [];
            if (is_dir($dir)) {
                foreach ((array)glob($dir . '*.php') as $file) {
                    $items[] = [
                        'name'  => basename($file, '.php'),
                        'file'  => basename($file),
                        'size'  => (int)filesize($file),
                        'mtime' => date('Y-m-d H:i', (int)filemtime($file)),
                    ];
                }
            }
            usort($items, function ($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            json_response([
                'code' => 0,
                'msg'  => 'ok',
                'data' => [
                    'items'        => $items,
                    'dir_writable' => is_dir($dir) ? is_writable($dir) : false,
                ],
            ]);
            break;

        // ==================== 读取已有插件源码 ====================
        case 'load_plugin':
            $name = aidev_normalize_name($_POST['plugin_name'] ?? '');
            if (!aidev_valid_name($name)) {
                json_response(['code' => 400, 'msg' => '插件名不合法']);
            }
            $file = aidev_plugin_dir() . $name . '.php';
            if (!is_file($file)) {
                json_response(['code' => 404, 'msg' => '插件不存在：' . $name . '.php']);
            }
            if (filesize($file) > 512 * 1024) {
                json_response(['code' => 400, 'msg' => '插件超过 512KB，无法在编辑器中打开']);
            }
            $code = file_get_contents($file);
            if ($code === false) {
                json_response(['code' => 500, 'msg' => '读取插件失败，请检查文件权限']);
            }
            json_response([
                'code' => 0,
                'msg'  => 'ok',
                'data' => [
                    'plugin_name' => $name,
                    'file'        => $name . '.php',
                    'code'        => $code,
                    'size'        => strlen($code),
                    'mtime'       => date('Y-m-d H:i', (int)filemtime($file)),
                ],
            ]);
            break;

        // ==================== 保存生成的插件 ====================
        case 'save':
            $pluginName = aidev_normalize_name($_POST['plugin_name'] ?? '');
            $pluginCode = $_POST['code'] ?? '';
            $appid = trim($_POST['appid'] ?? '');

            if (empty($pluginName) || empty($pluginCode)) {
                json_response(['code' => 400, 'msg' => '缺少插件名或代码']);
            }

            // 安全校验文件名
            if (!preg_match('/^[\w\-\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]+$/u', $pluginName)) {
                json_response(['code' => 400, 'msg' => '插件名只允许字母、数字、横杠、下划线和中文']);
            }

            // 安全检查：代码中不能有危险函数
            $dangerous = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'pcntl_exec'];
            foreach ($dangerous as $func) {
                if (preg_match('/\b' . $func . '\s*\(/i', $pluginCode)) {
                    json_response(['code' => 400, 'msg' => '插件代码包含禁止使用的函数: ' . $func]);
                }
            }

            $pluginDir = aidev_plugin_dir();
            if (!is_dir($pluginDir) && !@mkdir($pluginDir, 0755, true)) {
                json_response(['code' => 500, 'msg' => 'plugin/ 目录不存在且创建失败']);
            }
            if (!is_writable($pluginDir)) {
                json_response(['code' => 500, 'msg' => 'plugin/ 目录不可写，请给 PHP 运行用户写权限']);
            }

            $filePath = $pluginDir . $pluginName . '.php';
            $existed = is_file($filePath);

            // 覆盖已有插件前先备份到 data/plugin_backups/（data 目录已禁止外部访问）
            $backupName = '';
            if ($existed) {
                if (!is_writable($filePath)) {
                    json_response(['code' => 500, 'msg' => '插件文件不可写：plugin/' . $pluginName . '.php']);
                }
                $backupDir = dirname(__DIR__, 2) . '/data/plugin_backups/';
                if (!is_dir($backupDir)) {
                    @mkdir($backupDir, 0755, true);
                }
                if (is_dir($backupDir) && is_writable($backupDir)) {
                    $backupName = $pluginName . '-' . date('YmdHis') . '.php';
                    @copy($filePath, $backupDir . $backupName);
                    // 每个插件只保留最近 20 份
                    $olds = glob($backupDir . $pluginName . '-*.php') ?: [];
                    if (count($olds) > 20) {
                        sort($olds);
                        foreach (array_slice($olds, 0, count($olds) - 20) as $oldFile) {
                            @unlink($oldFile);
                        }
                    }
                }
            }

            // 原子写入：先写临时文件再替换，避免中断后留下半个插件
            $tmpPath = $filePath . '.tmp';
            if (file_put_contents($tmpPath, $pluginCode, LOCK_EX) === false || !@rename($tmpPath, $filePath)) {
                @unlink($tmpPath);
                json_response(['code' => 500, 'msg' => '写入插件文件失败，请检查 plugin/ 目录权限']);
            }
            @chmod($filePath, 0644);
            // 清掉 OPcache，保存后立即生效
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($filePath, true);
            }

            // 如果指定了 appid，自动启用插件
            if (!empty($appid)) {
                db()->execute(
                    "INSERT OR IGNORE INTO plugin_status (appid, plugin_name, enabled) VALUES (?, ?, 1)",
                    [$appid, $pluginName]
                );
                db()->execute(
                    "UPDATE plugin_status SET enabled = 1 WHERE appid = ? AND plugin_name = ?",
                    [$appid, $pluginName]
                );
            }

            json_response([
                'code' => 0,
                'msg' => '插件保存成功',
                'data' => [
                    'plugin_name' => $pluginName,
                    'file' => $pluginName . '.php',
                    'enabled' => !empty($appid),
                    'overwritten' => $existed,
                    'backup' => $backupName,
                ]
            ]);
            break;

        default:
            json_response(['code' => 400, 'msg' => '未知操作: ' . htmlspecialchars($action)]);
    }
} catch (Exception $e) {
    json_response(['code' => 500, 'msg' => '服务器错误: ' . $e->getMessage()]);
}
