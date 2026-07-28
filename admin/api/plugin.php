<?php
/**
 * 插件管理 API
 * 支持 list/open/close/filelist/add/delete/read/write
 * 使用数据库 plugin_status 表存储插件启用状态，替代原版 main.json
 */
header('Content-Type: application/json; charset=utf-8');

// 开启错误日志
ini_set('log_errors', 1);
ini_set('error_log', dirname(__DIR__, 2) . '/error.log');

require_once dirname(__DIR__, 2) . '/function.php';
require_once dirname(__DIR__, 2) . '/auth.php';

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['code' => 401, 'msg' => '未登录']);
    exit;
}

// 获取输入（支持 JSON 和 FormData 两种方式）
$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: [];
} else {
    $input = $_POST;
}

$type  = $input['type'] ?? $_GET['type'] ?? '';
$appid = $input['appid'] ?? $_GET['appid'] ?? '';
$name  = $input['name'] ?? $_GET['name'] ?? '';

$pluginDir = dirname(__DIR__, 2) . '/plugin/';

switch ($type) {
    case 'list':
        // 返回 appid 的已启用插件列表（与 index.php initAppContext 逻辑一致）
        // 默认启用所有存在的插件，除非被显式禁用
        $pluginDirFull = dirname(__DIR__, 2) . '/plugin/';
        $pluginFiles = is_dir($pluginDirFull) ? glob($pluginDirFull . '*.php') : [];

        // 获取被显式禁用的插件
        $disabledPlugins = [];
        $rows = db()->fetchAll(
            "SELECT plugin_name, enabled FROM plugin_status WHERE appid = ?",
            [$appid]
        );
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (intval($row['enabled']) === 0) {
                    $disabledPlugins[$row['plugin_name']] = true;
                }
            }
        }

        $plugins = [];
        foreach ($pluginFiles as $file) {
            $name = basename($file, '.php');
            if (!isset($disabledPlugins[$name])) {
                $plugins[$name] = true;
            }
        }
        echo json_encode($plugins, JSON_UNESCAPED_UNICODE);
        break;

    case 'open':
        if (empty($appid) || empty($name)) {
            die(json_encode(['code' => 400, 'msg' => '缺少参数']));
        }
        try {
            db()->execute(
                "INSERT OR IGNORE INTO plugin_status (appid, plugin_name, enabled) VALUES (?, ?, 1)",
                [$appid, $name]
            );
            db()->execute(
                "UPDATE plugin_status SET enabled = 1 WHERE appid = ? AND plugin_name = ?",
                [$appid, $name]
            );
            echo json_encode(['code' => 200, 'msg' => '已启用']);
        } catch (Exception $e) {
            die(json_encode(['code' => 500, 'msg' => '写入配置失败: ' . $e->getMessage()]));
        }
        break;

    case 'close':
        if (empty($appid) || empty($name)) {
            die(json_encode(['code' => 400, 'msg' => '缺少参数']));
        }
        try {
            db()->execute(
                "UPDATE plugin_status SET enabled = 0 WHERE appid = ? AND plugin_name = ?",
                [$appid, $name]
            );
            echo json_encode(['code' => 200, 'msg' => '已禁用']);
        } catch (Exception $e) {
            die(json_encode(['code' => 500, 'msg' => '写入配置失败: ' . $e->getMessage()]));
        }
        break;

    case 'filelist':
        $files = glob($pluginDir . '*.php');
        $list = [];
        if (is_array($files)) {
            foreach ($files as $file) {
                $list[] = basename($file, '.php');
            }
        }
        echo json_encode(['code' => 200, 'list' => $list]);
        break;

    case 'add':
        if (empty($name)) {
            die(json_encode(['code' => 400, 'msg' => '插件名不能为空']));
        }
        // 安全过滤
        $name = preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}]/u', '', $name);
        if (empty($name)) {
            die(json_encode(['code' => 400, 'msg' => '插件名不合法']));
        }
        $path = $pluginDir . $name . '.php';
        if (is_file($path)) {
            die(json_encode(['code' => 400, 'msg' => '插件已存在']));
        }
        // 确保 plugin 目录存在
        if (!is_dir($pluginDir)) {
            mkdir($pluginDir, 0755, true);
        }
        $content = "<?php\n\n// 插件：" . $name . "\n// 生成时间：" . date('Y-m-d H:i:s') . "\n\n?>";
        if (file_put_contents($path, $content) === false) {
            die(json_encode(['code' => 500, 'msg' => '创建失败，请检查权限']));
        }
        echo json_encode(['code' => 200, 'msg' => '创建成功']);
        break;

    case 'delete':
        if (empty($name)) {
            die(json_encode(['code' => 400, 'msg' => '插件名不能为空']));
        }
        $safeName = preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}]/u', '', $name);
        $path = $pluginDir . $safeName . '.php';
        if (!is_file($path)) {
            die(json_encode(['code' => 400, 'msg' => '插件不存在']));
        }
        if (unlink($path) === false) {
            die(json_encode(['code' => 500, 'msg' => '删除失败，请检查权限']));
        }
        // 同时删除所有 bot 的插件状态
        try {
            db()->execute("DELETE FROM plugin_status WHERE plugin_name = ?", [$safeName]);
        } catch (Exception $e) {}
        echo json_encode(['code' => 200, 'msg' => '删除成功']);
        break;

    case 'read':
        if (empty($name)) {
            die(json_encode(['code' => 400, 'msg' => '插件名不能为空']));
        }
        $safeName = preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}]/u', '', $name);
        $path = $pluginDir . $safeName . '.php';
        if (!is_file($path)) {
            die(json_encode(['code' => 400, 'msg' => '插件不存在']));
        }
        $content = file_get_contents($path);
        if ($content === false) {
            die(json_encode(['code' => 500, 'msg' => '读取失败']));
        }
        echo json_encode(['code' => 200, 'msg' => $content]);
        break;

    case 'write':
        if (empty($name)) {
            die(json_encode(['code' => 400, 'msg' => '插件名不能为空']));
        }
        // 支持 JSON 和 FormData 两种方式
        $content = $input['content'] ?? '';

        // 如果是 Base64 编码，先解码
        if (isset($input['encoded']) && $input['encoded'] == '1') {
            $content = base64_decode($content);
            if ($content === false) {
                die(json_encode(['code' => 500, 'msg' => '代码解码失败']));
            }
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}]/u', '', $name);
        $path = $pluginDir . $safeName . '.php';
        // 确保 plugin 目录存在
        if (!is_dir($pluginDir)) {
            mkdir($pluginDir, 0755, true);
        }
        if (file_put_contents($path, $content) === false) {
            die(json_encode(['code' => 500, 'msg' => '写入失败，请检查权限']));
        }
        echo json_encode(['code' => 200, 'msg' => '保存成功']);
        break;

    default:
        echo json_encode(['code' => 400, 'msg' => '无效的操作类型']);
}
