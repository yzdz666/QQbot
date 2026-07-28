<?php
/**
 * 指令模拟 API
 * 从原版复制，修改：
 * - 认证改为 Auth::check()
 * - 从数据库读取机器人配置和插件状态（替代 main.json）
 */
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', 0);

// 将 notice/warning 静默
set_error_handler(function () { return true; });

// 致命错误兜底
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        echo json_encode(['code' => 200, 'replies' => [], 'msg' => '插件致命错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

require_once dirname(__DIR__, 2) . '/function.php';
require_once dirname(__DIR__, 2) . '/auth.php';

if (!Auth::check()) {
    echo json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    // 兼容表单提交
    $input = $_POST;
}
if (!$input) {
    echo json_encode(['code' => 400, 'msg' => '无效的请求数据'], JSON_UNESCAPED_UNICODE);
    exit;
}

$content = trim($input['content'] ?? '');
if (empty($content)) {
    echo json_encode(['code' => 400, 'msg' => '请输入指令'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rootDir = dirname(__DIR__, 2);

// 从数据库读取机器人配置
$bots = getBots();
if (empty($bots)) {
    echo json_encode(['code' => 200, 'replies' => [], 'msg' => '无机器人配置'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====================== 目录隔离：防止任何文件写入污染项目目录 ======================
$originalCwd = getcwd();                    // 保存当前工作目录（项目根目录）
$tmpDir = sys_get_temp_dir() . '/simulate_' . uniqid();
mkdir($tmpDir, 0777, true);                // 创建临时目录
chdir($tmpDir);                            // 切换工作目录到临时目录

// 脚本结束后自动清理临时目录并恢复原目录
register_shutdown_function(function () use ($originalCwd, $tmpDir) {
    chdir($originalCwd);                   // 恢复目录
    // 递归删除临时目录
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        @$todo($fileinfo->getRealPath());
    }
    @rmdir($tmpDir);
});
// ====================== 目录隔离结束 ======================

// 管理员身份模拟，确保可测试所有管理员指令
define('用户', 'EF0E86B9E18341650DFDDFDD56815223');
define('来源', 'EF0E86B9E18341650DFDDFDD56815223');
define('消息', $content);
define('消息来源', '私聊');
define('消息ID', 'simulate_msg_' . time());
define('事件ID', '');

// ====================== 随机数修复：确保每次请求结果不同 ======================
$_COOKIE['PHPSESSID'] = bin2hex(random_bytes(16));
mt_srand((int) (microtime(true) * 1000000));
srand((int) (microtime(true) * 1000000));
// ====================================================================

$captured_replies = [];

// ========== 消息发送函数 ==========
function 文字($msg = '') {
    global $captured_replies;
    if (!empty($msg)) $captured_replies[] = ['type' => 'text', 'target' => 来源, 'content' => $msg];
    return true;
}

function MD($md = '', $keyboard = null, $style = null) {
    global $captured_replies;
    $content = $md;

    if ($style !== null && is_array($style)) {
        $content .= " [样式: " . json_encode($style, JSON_UNESCAPED_UNICODE) . "]";
    }
    if ($keyboard !== null) {
        $content .= " [按钮: {$keyboard}]";
    }

    if (!empty($md)) $captured_replies[] = ['type' => 'md', 'target' => 来源, 'content' => $content];
    return true;
}

function 图片($image = '', $content = null) {
    global $captured_replies;
    if (!empty($image)) $captured_replies[] = ['type' => 'image', 'target' => 来源, 'content' => $image];
    if ($content !== null) 文字($content);
    return true;
}
function 视频($video = '') {
    global $captured_replies;
    if (!empty($video)) $captured_replies[] = ['type' => 'video', 'target' => 来源, 'content' => $video];
    return true;
}
function 语音($yy = '') {
    global $captured_replies;
    if (!empty($yy)) $captured_replies[] = ['type' => 'audio', 'target' => 来源, 'content' => $yy];
    return true;
}
function 文卡(...$items) {
    $text = '';
    foreach ($items as $item) {
        if (is_array($item)) {
            $text .= ($item['text'] ?? '') . (isset($item['url']) ? " [链接]{$item['url']}" : '') . "\n";
        }
    }
    return 文字(trim($text));
}
function 大图($t, $st, $url) {
    图片($url, "{$t}\n{$st}");
    return true;
}
function 跳转卡($t, $d, $img, $url) {
    图片($img, "{$t}\n{$d}\n跳转：{$url}");
    return true;
}
function 按钮($key = '') { return 文字("[按钮] ID:{$key}"); }
function 流式(...$msgs) { foreach ($msgs as $msg) 文字($msg); return true; }
function 撤回($id = '') { return true; }
function 文件($data = '', $filename = '') {
    global $captured_replies;
    $captured_replies[] = ['type' => 'text', 'target' => 来源, 'content' => "[文件] {$filename}"];
    return true;
}

// ========== 辅助函数（使用 function_exists 避免与 function.php 冲突） ==========
// 写/读/wlog/curl/域名大写/二维码/markdown转html/HTML转图/邮箱 已在 function.php 中定义
// 这里仅定义 function.php 中未定义的模拟专用函数
if (!function_exists('头像')) {
    function 头像($uid) {
        return 'https://q.qlogo.cn/qqapp/' . appid . '/' . $uid . '/640';
    }
}
if (!function_exists('BOT信息')) {
    function BOT信息() { return ['name' => '模拟', 'appid' => appid]; }
}

// ========== 插件冲突检测与加载（opt-out：默认启用，除非显式禁用） ==========
$pluginDir = $rootDir . '/plugin';
$existingFuncs = array_map('strtolower', get_defined_functions()['user']);
$pluginFiles = [];

// 扫描 plugin 目录中的所有 .php 文件
$allPluginFiles = is_dir($pluginDir) ? glob($pluginDir . '/*.php') : [];

// 从数据库读取每个机器人的禁用插件列表，加载未被禁用的插件（去重）
foreach ($bots as $bot) {
    $appid = $bot['appid'];
    // 获取该 appid 下所有被显式禁用的插件
    $disabledPlugins = [];
    $rows = db()->fetchAll(
        "SELECT plugin_name FROM plugin_status WHERE appid = ? AND enabled = 0",
        [$appid]
    );
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $disabledPlugins[$row['plugin_name']] = true;
        }
    }

    // 默认启用所有存在的插件，除非被显式禁用
    foreach ($allPluginFiles as $file) {
        $name = basename($file, '.php');
        if (isset($disabledPlugins[$name])) continue; // 跳过被禁用的插件
        $real = realpath($file);
        if ($real && !isset($pluginFiles[$real])) {
            $pluginFiles[$real] = ['name' => $name, 'appid' => $appid, 'bot' => $bot];
        }
    }
}

foreach ($pluginFiles as $real => $info) {
    $name = $info['name'];
    $code = @file_get_contents($real);
    if (!$code) continue;
    $tokens = @token_get_all($code);
    if (!is_array($tokens)) continue;
    $funcNames = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_FUNCTION) {
            for ($j = $i+1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $funcNames[] = $tokens[$j][1];
                    break;
                }
                break;
            }
        }
    }
    $conflict = false;
    foreach ($funcNames as $fn) {
        if (in_array(strtolower($fn), $existingFuncs)) { $conflict = true; break; }
    }
    if ($conflict) continue;

    // 定义该插件所属机器人的常量
    $bot = $info['bot'];
    if (!defined('appid')) define('appid', $bot['appid']);
    if (!defined('secret')) define('secret', $bot['secret'] ?? '');
    if (!defined('type')) define('type', $bot['env'] ?? '');

    set_time_limit(30);
    ob_start();
    try {
        @include_once $real;
        $existingFuncs = array_merge($existingFuncs, array_map('strtolower', $funcNames));
    } catch (Throwable $e) {}
    ob_end_clean();
}

// 如果没有任何插件定义了 appid，使用第一个机器人的配置
if (!defined('appid')) {
    define('appid', $bots[0]['appid']);
    define('secret', $bots[0]['secret'] ?? '');
    define('type', $bots[0]['env'] ?? '');
}

// 回到原始目录（保险，实际 shutdown 会处理）
chdir($originalCwd);
while (ob_get_level()) ob_end_clean();

echo json_encode([
    'code' => 200,
    'replies' => $captured_replies,
    'msg' => '完成'
], JSON_UNESCAPED_UNICODE);
