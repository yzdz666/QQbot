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

// 同源校验：拦截跨站 POST（与 SameSite Cookie 双重防护）
Auth::requireSameOrigin();
// 代发网关函数 (relay_auth_get/reset/toggle 需要; relay.php 被 include 时仅定义函数不执行入口)
require_once(__DIR__ . '/../relay.php');

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

// CSRF 校验：登录后的写操作必须携带令牌（前端由 header.php 自动附加）
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    Auth::verifyCsrf();
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
        后台操作记录('添加机器人', $appid . ' (' . $env . ')');
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
        后台操作记录('更新机器人', $appid . ' 字段: ' . implode(',', array_keys($data)), $appid);
        json_response(['success' => true, 'message' => '更新成功']);
        break;

    case 'bot_delete':
        $appid = $_POST['appid'] ?? '';
        if (empty($appid)) {
            json_response(['success' => false, 'message' => 'AppID不能为空']);
        }
        deleteBot($appid);
        后台操作记录('删除机器人', $appid, $appid);
        json_response(['success' => true, 'message' => '已删除']);
        break;

    case 'bot_toggle':
        $appid = $_POST['appid'] ?? '';
        $field = $_POST['field'] ?? 'enabled';
        $value = intval($_POST['value'] ?? 0);
        // 字段白名单：防止任意字段被修改
        if (!in_array($field, ['enabled', 'ws_enabled'], true)) {
            json_response(['success' => false, 'message' => '不支持的状态字段: ' . htmlspecialchars($field)]);
        }
        $value = $value ? 1 : 0;
        $bot = getBot($appid);
        if (!$bot) {
            json_response(['success' => false, 'message' => '机器人不存在']);
        }
        updateBot($appid, [$field => $value]);
        后台操作记录($field === 'enabled' ? ($value ? '启用机器人' : '停用机器人') : ($value ? '开启WS' : '关闭WS'), $appid, $appid);
        // 返回实际写入后的状态，供前端同步徽章标签显示
        $updatedBot = getBot($appid);
        json_response([
            'success' => true,
            'message' => '状态已更新',
            'field' => $field,
            'enabled' => isset($updatedBot[$field]) ? intval($updatedBot[$field]) : $value
        ]);
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

    // ==================== 获取群信息（群名称等） ====================
    case 'group_get_info':
        $appid = trim($_POST['appid'] ?? '');
        $groupOpenid = trim($_POST['group_openid'] ?? '');
        if (empty($appid) || empty($groupOpenid)) {
            json_response(['success' => false, 'message' => 'AppID和群OpenID不能为空']);
        }
        $result = fetchAndUpdateGroupInfo($appid, $groupOpenid, true);
        json_response($result);
        break;

    // ==================== 指令面板管理 ====================
    case 'panel_list':
        $appid = trim($_POST['appid'] ?? '');
        $scope = trim($_POST['scope'] ?? 'group');
        $cursor = trim($_POST['cursor'] ?? '');
        $limit = intval($_POST['limit'] ?? 20);
        if (empty($appid)) {
            json_response(['success' => false, 'message' => 'AppID不能为空']);
        }
        $bot = getBot($appid);
        if (!$bot) {
            json_response(['success' => false, 'message' => '机器人不存在']);
        }
        if (!defined('appid')) define('appid', $bot['appid']);
        if (!defined('secret')) define('secret', $bot['secret']);
        if (!defined('type')) define('type', $bot['env']);
        if (!defined('消息来源')) define('消息来源', '');
        if (!defined('来源')) define('来源', '');
        if (!defined('消息ID')) define('消息ID', '');
        if (!defined('事件ID')) define('事件ID', '');
        require_once(__DIR__ . '/../bot.php');
        $resp = 查询面板列表($scope, $cursor, $limit);
        $data = json_decode($resp, true);
        if (isset($data['code']) && $data['code'] != 0) {
            json_response(['success' => false, 'message' => $data['message'] ?? '查询失败', 'raw' => $resp]);
        }
        json_response(['success' => true, 'data' => $data, 'raw' => $resp]);
        break;

    case 'panel_create':
        $appid = trim($_POST['appid'] ?? '');
        $panelData = $_POST['panel_data'] ?? '';
        if (empty($appid) || empty($panelData)) {
            json_response(['success' => false, 'message' => 'AppID和面板数据不能为空']);
        }
        $decoded = json_decode($panelData, true);
        if (!is_array($decoded)) {
            json_response(['success' => false, 'message' => '面板数据JSON格式错误']);
        }
        $bot = getBot($appid);
        if (!$bot) {
            json_response(['success' => false, 'message' => '机器人不存在']);
        }
        if (!defined('appid')) define('appid', $bot['appid']);
        if (!defined('secret')) define('secret', $bot['secret']);
        if (!defined('type')) define('type', $bot['env']);
        if (!defined('消息来源')) define('消息来源', '');
        if (!defined('来源')) define('来源', '');
        if (!defined('消息ID')) define('消息ID', '');
        if (!defined('事件ID')) define('事件ID', '');
        require_once(__DIR__ . '/../bot.php');
        $resp = 创建指令面板($decoded);
        $data = json_decode($resp, true);
        if (isset($data['code']) && $data['code'] != 0) {
            json_response(['success' => false, 'message' => $data['message'] ?? '创建失败', 'raw' => $resp]);
        }
        后台操作记录('创建指令面板', $appid, $appid);
        json_response(['success' => true, 'data' => $data, 'raw' => $resp]);
        break;

    case 'panel_update':
        $appid = trim($_POST['appid'] ?? '');
        $panelId = trim($_POST['panel_id'] ?? '');
        $panelData = $_POST['panel_data'] ?? '';
        if (empty($appid) || empty($panelId) || empty($panelData)) {
            json_response(['success' => false, 'message' => 'AppID、面板ID和数据不能为空']);
        }
        $decoded = json_decode($panelData, true);
        if (!is_array($decoded)) {
            json_response(['success' => false, 'message' => '面板数据JSON格式错误']);
        }
        $bot = getBot($appid);
        if (!$bot) {
            json_response(['success' => false, 'message' => '机器人不存在']);
        }
        if (!defined('appid')) define('appid', $bot['appid']);
        if (!defined('secret')) define('secret', $bot['secret']);
        if (!defined('type')) define('type', $bot['env']);
        if (!defined('消息来源')) define('消息来源', '');
        if (!defined('来源')) define('来源', '');
        if (!defined('消息ID')) define('消息ID', '');
        if (!defined('事件ID')) define('事件ID', '');
        require_once(__DIR__ . '/../bot.php');
        $resp = 修改指令面板($panelId, $decoded);
        $data = json_decode($resp, true);
        if (isset($data['code']) && $data['code'] != 0) {
            json_response(['success' => false, 'message' => $data['message'] ?? '修改失败', 'raw' => $resp]);
        }
        后台操作记录('修改指令面板', $appid, $appid);
        json_response(['success' => true, 'data' => $data, 'raw' => $resp]);
        break;

    // ==================== 指令面板 - 查询详情 (GET /v2/panels/{panel_id}) ====================
    case 'panel_detail':
        $appid = trim($_POST['appid'] ?? '');
        $panelId = trim($_POST['panel_id'] ?? '');
        if (empty($appid) || empty($panelId)) {
            json_response(['success' => false, 'message' => 'AppID和面板ID不能为空']);
        }
        $bot = getBot($appid);
        if (!$bot) {
            json_response(['success' => false, 'message' => '机器人不存在']);
        }
        if (!defined('appid')) define('appid', $bot['appid']);
        if (!defined('secret')) define('secret', $bot['secret']);
        if (!defined('type')) define('type', $bot['env']);
        if (!defined('消息来源')) define('消息来源', '');
        if (!defined('来源')) define('来源', '');
        if (!defined('消息ID')) define('消息ID', '');
        if (!defined('事件ID')) define('事件ID', '');
        require_once(__DIR__ . '/../bot.php');
        $resp = 查询面板详情($panelId);
        $data = json_decode($resp, true);
        if (isset($data['code']) && $data['code'] != 0) {
            json_response(['success' => false, 'message' => $data['message'] ?? '查询详情失败', 'raw' => $resp]);
        }
        json_response(['success' => true, 'data' => $data, 'raw' => $resp]);
        break;

    case 'panel_delete':
        $appid = trim($_POST['appid'] ?? '');
        $panelId = trim($_POST['panel_id'] ?? '');
        if (empty($appid) || empty($panelId)) {
            json_response(['success' => false, 'message' => 'AppID和面板ID不能为空']);
        }
        $bot = getBot($appid);
        if (!$bot) {
            json_response(['success' => false, 'message' => '机器人不存在']);
        }
        if (!defined('appid')) define('appid', $bot['appid']);
        if (!defined('secret')) define('secret', $bot['secret']);
        if (!defined('type')) define('type', $bot['env']);
        if (!defined('消息来源')) define('消息来源', '');
        if (!defined('来源')) define('来源', '');
        if (!defined('消息ID')) define('消息ID', '');
        if (!defined('事件ID')) define('事件ID', '');
        require_once(__DIR__ . '/../bot.php');
        $resp = 删除指令面板($panelId);
        $data = json_decode($resp, true);
        if (isset($data['code']) && $data['code'] != 0) {
            json_response(['success' => false, 'message' => $data['message'] ?? '删除失败', 'raw' => $resp]);
        }
        后台操作记录('删除指令面板', ($panelId ?: '') . ' ' . $appid, $appid);
        json_response(['success' => true, 'data' => $data, 'raw' => $resp]);
        break;

    // ==================== 自定义菜单管理 ====================
    case 'menu_get':
        $appid = trim($_POST['appid'] ?? '');
        if (empty($appid)) {
            json_response(['success' => false, 'message' => 'AppID不能为空']);
        }
        $bot = getBot($appid);
        if (!$bot) {
            json_response(['success' => false, 'message' => '机器人不存在']);
        }
        if (!defined('appid')) define('appid', $bot['appid']);
        if (!defined('secret')) define('secret', $bot['secret']);
        if (!defined('type')) define('type', $bot['env']);
        if (!defined('消息来源')) define('消息来源', '');
        if (!defined('来源')) define('来源', '');
        if (!defined('消息ID')) define('消息ID', '');
        if (!defined('事件ID')) define('事件ID', '');
        require_once(__DIR__ . '/../bot.php');
        $resp = 获取菜单();
        $data = json_decode($resp, true);
        if (isset($data['code']) && $data['code'] != 0) {
            json_response(['success' => false, 'message' => $data['message'] ?? '查询失败', 'raw' => $resp]);
        }
        json_response(['success' => true, 'data' => $data, 'raw' => $resp]);
        break;

    case 'menu_set':
        $appid = trim($_POST['appid'] ?? '');
        $menuData = $_POST['menu_data'] ?? '';
        if (empty($appid) || empty($menuData)) {
            json_response(['success' => false, 'message' => 'AppID和菜单数据不能为空']);
        }
        $decoded = json_decode($menuData, true);
        if (!is_array($decoded)) {
            json_response(['success' => false, 'message' => '菜单数据JSON格式错误']);
        }
        $bot = getBot($appid);
        if (!$bot) {
            json_response(['success' => false, 'message' => '机器人不存在']);
        }
        if (!defined('appid')) define('appid', $bot['appid']);
        if (!defined('secret')) define('secret', $bot['secret']);
        if (!defined('type')) define('type', $bot['env']);
        if (!defined('消息来源')) define('消息来源', '');
        if (!defined('来源')) define('来源', '');
        if (!defined('消息ID')) define('消息ID', '');
        if (!defined('事件ID')) define('事件ID', '');
        require_once(__DIR__ . '/../bot.php');
        $resp = 设置菜单($decoded);
        $data = json_decode($resp, true);
        if (isset($data['code']) && $data['code'] != 0) {
            json_response(['success' => false, 'message' => $data['message'] ?? '设置失败', 'raw' => $resp]);
        }
        后台操作记录('保存自定义菜单', $appid, $appid);
        json_response(['success' => true, 'data' => $data, 'raw' => $resp]);
        break;

    case 'menu_delete':
        $appid = trim($_POST['appid'] ?? '');
        if (empty($appid)) {
            json_response(['success' => false, 'message' => 'AppID不能为空']);
        }
        $bot = getBot($appid);
        if (!$bot) {
            json_response(['success' => false, 'message' => '机器人不存在']);
        }
        if (!defined('appid')) define('appid', $bot['appid']);
        if (!defined('secret')) define('secret', $bot['secret']);
        if (!defined('type')) define('type', $bot['env']);
        if (!defined('消息来源')) define('消息来源', '');
        if (!defined('来源')) define('来源', '');
        if (!defined('消息ID')) define('消息ID', '');
        if (!defined('事件ID')) define('事件ID', '');
        require_once(__DIR__ . '/../bot.php');
        $resp = 删除菜单();
        $data = json_decode($resp, true);
        if (isset($data['code']) && $data['code'] != 0) {
            json_response(['success' => false, 'message' => $data['message'] ?? '删除失败', 'raw' => $resp]);
        }
        后台操作记录('删除自定义菜单', $appid, $appid);
        json_response(['success' => true, 'data' => $data, 'raw' => $resp]);
        break;

    // ==================== 插件管理 ====================
    case 'plugin_toggle':
        $appid = $_POST['appid'] ?? '';
        $pluginName = 插件规范名($_POST['plugin_name'] ?? '');
        $enabled = intval($_POST['enabled'] ?? 0);
        if (empty($appid) || empty($pluginName)) {
            json_response(['success' => false, 'message' => '参数缺失']);
            break;
        }
        try {
            $pdo = db()->getPdo();
            // 使用事务确保 INSERT OR IGNORE + UPDATE 原子执行
            $pdo->beginTransaction();
            // 先 INSERT OR IGNORE 确保记录存在，再 UPDATE 设置状态
            $pdo->prepare(
                "INSERT OR IGNORE INTO plugin_status (appid, plugin_name, enabled) VALUES (?, ?, ?)"
            )->execute([$appid, $pluginName, $enabled]);
            $pdo->prepare(
                "UPDATE plugin_status SET enabled = ? WHERE appid = ? AND plugin_name = ?"
            )->execute([$enabled, $appid, $pluginName]);
            $pdo->commit();

            // 从数据库读取实际状态，验证写入是否成功
            $verifyRow = db()->fetch(
                "SELECT enabled FROM plugin_status WHERE appid = ? AND plugin_name = ?",
                [$appid, $pluginName]
            );
            $actualEnabled = $verifyRow ? intval($verifyRow['enabled']) : -1;

            if ($actualEnabled !== $enabled) {
                // 验证失败：数据库状态与请求不一致
                json_response([
                    'success' => false,
                    'message' => '数据库验证失败: 期望=' . $enabled . ' 实际=' . $actualEnabled
                ]);
            }

            后台操作记录($enabled === 1 ? '插件启用' : '插件禁用', $pluginName, $appid);
            json_response([
                'success' => true,
                'message' => '插件状态已更新',
                'enabled' => $actualEnabled
            ]);
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response(['success' => false, 'message' => '数据库写入失败: ' . $e->getMessage()]);
        }
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
        后台操作记录('插件创建', $pluginName);
        json_response(['success' => true, 'message' => '插件创建成功']);
        break;

    case 'plugin_upload':
        if (!isset($_FILES['plugin_file']) || $_FILES['plugin_file']['error'] !== UPLOAD_ERR_OK) {
            json_response(['success' => false, 'message' => '请选择文件']);
        }
        if ((int)$_FILES['plugin_file']['size'] > 2097152) {
            json_response(['success' => false, 'message' => '插件文件不能超过 2MB']);
        }
        // 浏览器上传的原始文件名可能是 GBK（历史环境），先规范化为 UTF-8
        $filename = 插件规范名($_FILES['plugin_file']['name']);
        $filename = str_replace(['/', '\\'], '', $filename);
        if (!preg_match('/^[\w\-\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]+\.php$/u', $filename)) {
            json_response(['success' => false, 'message' => '文件名只允许字母、数字、横杠、下划线和中文，且必须为 .php']);
        }
        // 双扩展名/空字节等异常文件名拦截
        if (substr_count(strtolower($filename), '.php') !== 1 || strpos($filename, "\0") !== false) {
            json_response(['success' => false, 'message' => '非法文件名']);
        }
        $pluginDir = __DIR__ . '/../plugin/';
        if (!is_dir($pluginDir)) {
            mkdir($pluginDir, 0755, true);
        }
        $dest = $pluginDir . $filename;
        $uploaded = (string)file_get_contents($_FILES['plugin_file']['tmp_name']);
        $scan = 插件安全扫描($uploaded);
        if ($scan['status'] === 'block') {
            json_response([
                'success' => false,
                'message' => '插件包含高危代码，已拒绝上传：' . $scan['summary'],
                'scan' => $scan,
            ]);
        }
        if (move_uploaded_file($_FILES['plugin_file']['tmp_name'], $dest)) {
            @chmod($dest, 0644);
            后台操作记录('插件上传', $filename . ' 扫描:' . $scan['status']);
            json_response([
                'success' => true,
                'message' => '插件上传成功' . ($scan['status'] === 'warn' ? '（扫描有提醒，请查看源码确认）' : ''),
                'scan' => $scan,
            ]);
        } else {
            json_response(['success' => false, 'message' => '上传失败']);
        }
        break;

    case 'plugin_rename':
        $oldName = 插件规范名(trim($_POST['old_name'] ?? ''));
        $newName = trim($_POST['new_name'] ?? '');
        if ($oldName === '' || $newName === '') {
            json_response(['success' => false, 'message' => '原插件名和新名称不能为空']);
        }
        if (!preg_match('/^[\w\-\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]+$/u', $newName)) {
            json_response(['success' => false, 'message' => '新名称只允许字母、数字、下划线、横杠和中文']);
        }
        if ($oldName === $newName) {
            json_response(['success' => false, 'message' => '新名称与原名称相同']);
        }
        // 由规范名定位真实文件(兼容 GBK 等历史文件名)
        $oldFile = 插件真实文件($oldName);
        if ($oldFile === null || !is_file($oldFile)) {
            json_response(['success' => false, 'message' => '原插件文件不存在']);
        }
        $newFile = __DIR__ . '/../plugin/' . $newName . '.php';
        if (file_exists($newFile)) {
            json_response(['success' => false, 'message' => '目标文件名已存在: ' . $newName . '.php']);
        }
        if (!@rename($oldFile, $newFile)) {
            json_response(['success' => false, 'message' => '重命名失败，请检查 plugin 目录写权限']);
        }
        // 同步所有机器人的插件启用状态（旧名 → 新名；含历史 GBK 字节记录按规范名比对）
        try {
            db()->execute(
                "UPDATE plugin_status SET plugin_name = ? WHERE plugin_name = ?",
                [$newName, $oldName]
            );
            $stale = db()->fetchAll("SELECT id, plugin_name FROM plugin_status");
            foreach ($stale as $row) {
                if ($row['plugin_name'] !== $newName && 插件规范名($row['plugin_name']) === $oldName) {
                    db()->execute(
                        "UPDATE plugin_status SET plugin_name = ? WHERE id = ?",
                        [$newName, $row['id']]
                    );
                }
            }
        } catch (Exception $e) {}
        后台操作记录('插件重命名', $oldName . ' → ' . $newName);
        json_response(['success' => true, 'message' => '插件已重命名为 ' . $newName]);
        break;

    case 'plugin_delete':
        $pluginName = 插件名过滤($_POST['plugin_name'] ?? '');
        if ($pluginName === false) {
            json_response(['success' => false, 'message' => '无效的插件名']);
        }
        // 由规范名定位真实文件(兼容 GBK 等历史文件名)
        $file = 插件真实文件($pluginName);
        if ($file !== null) {
            @unlink($file);
        }
        // 同时删除所有 bot 的该插件状态(含历史 GBK 字节记录: 按规范名比对清理)
        try {
            db()->execute("DELETE FROM plugin_status WHERE plugin_name = ?", [$pluginName]);
            $stale = db()->fetchAll("SELECT id, plugin_name FROM plugin_status");
            foreach ($stale as $row) {
                if (插件规范名($row['plugin_name']) === $pluginName) {
                    db()->execute("DELETE FROM plugin_status WHERE id = ?", [$row['id']]);
                }
            }
        } catch (Exception $e) {}
        后台操作记录('插件删除', $pluginName);
        json_response(['success' => true, 'message' => '插件已删除']);
        break;

    case 'plugin_read':
        $pluginName = 插件规范名($_POST['plugin_name'] ?? $_GET['plugin_name'] ?? '');
        if (!preg_match('/^[\w\-\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}]+$/u', $pluginName)) {
            json_response(['success' => false, 'message' => '无效的插件名']);
        }
        // 由规范名定位真实文件，兼容 GBK 等历史文件名（此前直接拼 UTF-8 路径会读不到）
        $file = 插件真实文件($pluginName);
        if ($file === null || !is_file($file)) {
            json_response(['success' => false, 'message' => '插件文件不存在']);
        }
        $content = file_get_contents($file);
        if ($content === false) {
            json_response(['success' => false, 'message' => '读取失败']);
        }
        json_response(['success' => true, 'content' => $content]);
        break;

    case 'plugin_write':
        $pluginName = 插件规范名($_POST['plugin_name'] ?? '');
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
            mkdir($pluginDir, 0755, true);
        }
        // 已存在同规范名文件（可能是 GBK 文件名）时写回原文件，避免产生重复插件
        $existing = 插件真实文件($pluginName);
        $file = $existing !== null ? $existing : ($pluginDir . $pluginName . '.php');
        if (file_put_contents($file, $content) === false) {
            json_response(['success' => false, 'message' => '写入失败，请检查权限']);
        }
        @chmod($file, 0644);
        json_response(['success' => true, 'message' => '保存成功']);
        break;

    // ==================== 设置 ====================
    case 'change_password':
        $username = trim($_POST['username'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $currentPassword = $_POST['current_password'] ?? '';
        if (empty($username) || strlen($newPassword) < 8) {
            json_response(['success' => false, 'message' => '用户名不能为空且新密码至少8位']);
        }
        $result = Auth::changePassword($username, $newPassword, $currentPassword);
        json_response($result);
        break;

    case 'save_ai_config':
        $baseUrl = trim($_POST['base_url'] ?? '');
        $apiKey = trim($_POST['api_key'] ?? '');
        $model = trim($_POST['model'] ?? 'gpt-4o-mini');
        db()->execute("UPDATE ai_config SET base_url = ?, api_key = ?, model = ? WHERE id = 1",
            [$baseUrl, $apiKey, $model]);
        后台操作记录('保存AI配置', $baseUrl);
        json_response(['success' => true, 'message' => 'AI配置已保存']);
        break;

    case 'save_setting':
        $key = $_POST['key'] ?? '';
        $value = $_POST['value'] ?? '';
        if (empty($key)) {
            json_response(['success' => false, 'message' => '键名不能为空']);
        }
        db()->setSetting($key, $value);
        后台操作记录('保存设置', $key . ' = ' . $value);
        json_response(['success' => true, 'message' => '设置已保存']);
        break;

    // ==================== 代发网关鉴权 (relay.php) ====================
    case 'relay_auth_get':
        // 查看代发签名密钥与开关状态 (密钥用于外部脚本计算 HMAC-SHA256 签名)
        json_response([
            'success' => true,
            'enabled' => relay_auth_enabled(),
            'secret' => relay_get_secret(),
            'ts_window' => RELAY_TS_WINDOW,
            'sign_algo' => 'HMAC-SHA256("{timestamp}\\n{nonce}\\n{body}", relay_secret)',
        ]);
        break;

    case 'relay_auth_reset':
        // 重置签名密钥 (旧密钥立即失效, 需同步更新所有调用方)
        db()->setSetting('relay_secret', bin2hex(random_bytes(32)));
        wlog('{"relay_info":"代发签名密钥已由管理员重置"}', null);
        后台操作记录('重置代发鉴权密钥', '');
        json_response(['success' => true, 'message' => '密钥已重置', 'secret' => relay_get_secret()]);
        break;

    case 'relay_auth_toggle':
        // 开启/关闭代发鉴权 (关闭后任何人可调用代发入口, 仅建议内网使用)
        $enable = ($_POST['enable'] ?? '1') === '1';
        db()->setSetting('relay_auth_enabled', $enable ? '1' : '0');
        wlog('{"relay_info":"代发鉴权已' . ($enable ? '开启' : '关闭') . '"}', null);
        后台操作记录('代发鉴权' . ($enable ? '开启' : '关闭'), '');
        json_response(['success' => true, 'message' => '代发鉴权已' . ($enable ? '开启' : '关闭'), 'enabled' => $enable]);
        break;

    // ==================== 消息 ====================
    case 'msg_count':
        // 轻量级消息计数接口，用于自动刷新检测新消息
        $appid = $_POST['appid'] ?? $_GET['appid'] ?? '';
        $direction = $_POST['direction'] ?? $_GET['direction'] ?? '';
        $days = $_POST['days'] ?? $_GET['days'] ?? '';
        $where = [];
        $params = [];
        if ($appid !== '') {
            $where[] = 'appid = ?';
            $params[] = $appid;
        }
        if (in_array($direction, ['接收', '发送'], true)) {
            $where[] = 'direction = ?';
            $params[] = $direction;
        }
        if (in_array($days, ['1', '3', '7', '30', '90'], true)) {
            $where[] = "created_at >= datetime('now','localtime','-" . intval($days) . " days')";
        }
        $whereClause = '';
        if (!empty($where)) {
            $whereClause = ' WHERE ' . implode(' AND ', $where);
        }
        try {
            $count = (int) db()->fetchColumn("SELECT COUNT(*) FROM messages" . $whereClause, $params);
        } catch (Exception $e) {
            $count = 0;
        }
        json_response(['success' => true, 'count' => $count]);
        break;

    case 'clear_messages':
        $appid = $_POST['appid'] ?? '';
        if (empty($appid)) {
            db()->execute("DELETE FROM messages");
        } else {
            db()->execute("DELETE FROM messages WHERE appid = ?", [$appid]);
        }
        后台操作记录('清空消息记录', $appid !== '' ? $appid : '全部机器人', $appid);
        json_response(['success' => true, 'message' => '消息已清空']);
        break;

    case 'clear_logs':
        $appid = $_POST['appid'] ?? '';
        if (empty($appid)) {
            db()->execute("DELETE FROM system_logs");
        } else {
            db()->execute("DELETE FROM system_logs WHERE appid = ?", [$appid]);
        }
        后台操作记录('清空系统日志', $appid !== '' ? $appid : '全部机器人', $appid);
        json_response(['success' => true, 'message' => '日志已清空']);
        break;

    // ==================== 操作记录（后台操作可视化） ====================
    case 'oplog_list':
        $page    = max(1, intval($_POST['page'] ?? $_GET['page'] ?? 1));
        $size    = min(200, max(10, intval($_POST['size'] ?? $_GET['size'] ?? 30)));
        $kw      = trim($_POST['kw'] ?? $_GET['kw'] ?? '');
        $logType = trim($_POST['log_type'] ?? $_GET['log_type'] ?? '');
        $appid   = trim($_POST['appid'] ?? $_GET['appid'] ?? '');

        $where = [];
        $params = [];
        // 仅展示后台操作类日志（系统/运行日志走消息日志页），log_type 模糊兼容历史扩展
        $where[] = "(log_type = '后台操作' OR content LIKE '[%]')";
        if ($kw !== '') {
            $where[] = "(content LIKE ? OR appid LIKE ?)";
            $params[] = '%' . $kw . '%';
            $params[] = '%' . $kw . '%';
        }
        if ($appid !== '') {
            $where[] = "appid = ?";
            $params[] = $appid;
        }
        $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        try {
            $total = (int) db()->fetchColumn("SELECT COUNT(*) FROM system_logs" . $whereSql, $params);
            $rows = db()->fetchAll(
                "SELECT id, appid, log_type, content, level, created_at
                 FROM system_logs" . $whereSql . "
                 ORDER BY id DESC LIMIT ? OFFSET ?",
                array_merge($params, [$size, ($page - 1) * $size])
            );
            // 解析 content "[操作](用户) 详情" 结构
            $items = [];
            foreach ($rows as $r) {
                $op = $r['content']; $user = ''; $detail = '';
                if (preg_match('/^\[([^\]]+)\](?:\(([^)]*)\))?(.*)$/us', (string)$r['content'], $m)) {
                    $op = $m[1];
                    $user = $m[2] ?? '';
                    $detail = trim($m[3] ?? '');
                }
                $items[] = [
                    'id' => (int)$r['id'],
                    'time' => $r['created_at'],
                    'op' => $op,
                    'user' => $user,
                    'detail' => $detail,
                    'appid' => $r['appid'],
                    'raw' => $r['content'],
                ];
            }
            json_response([
                'success' => true,
                'items' => $items,
                'total' => $total,
                'page' => $page,
                'size' => $size,
                'pages' => max(1, (int)ceil($total / $size)),
            ]);
        } catch (Exception $e) {
            json_response(['success' => false, 'message' => '查询失败: ' . $e->getMessage()]);
        }
        break;

    case 'oplog_clear':
        // scope: all=清空全部后台操作记录 / 7d / 30d / 90d = 清理指定天数之前的记录
        $scope = trim($_POST['scope'] ?? 'all');
        switch ($scope) {
            case '7d':  $days = 7;  break;
            case '30d': $days = 30; break;
            case '90d': $days = 90; break;
            case 'all': $days = 0;  break;
            default:
                json_response(['success' => false, 'message' => '无效的清理范围']);
        }
        try {
            if ($days > 0) {
                $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
                $count = (int) db()->fetchColumn(
                    "SELECT COUNT(*) FROM system_logs WHERE (log_type = '后台操作' OR content LIKE '[%]') AND created_at < ?",
                    [$cutoff]
                );
                db()->execute(
                    "DELETE FROM system_logs WHERE (log_type = '后台操作' OR content LIKE '[%]') AND created_at < ?",
                    [$cutoff]
                );
            } else {
                $count = (int) db()->fetchColumn(
                    "SELECT COUNT(*) FROM system_logs WHERE (log_type = '后台操作' OR content LIKE '[%]')"
                );
                db()->execute(
                    "DELETE FROM system_logs WHERE (log_type = '后台操作' OR content LIKE '[%]')"
                );
            }
            json_response(['success' => true, 'message' => "已清理 {$count} 条操作记录", 'count' => $count]);
        } catch (Exception $e) {
            json_response(['success' => false, 'message' => '清理失败: ' . $e->getMessage()]);
        }
        break;

    // ==================== 数据库管理（分表清理 / VACUUM 回收空间） ====================
    case 'db_clear':
        // 白名单：仅允许清理运行数据表，防止误删配置；days>0 表示仅清理该天数之前的数据（仅限带时间列的表）
        $allow = [
            'messages'      => 'created_at',
            'system_logs'   => 'created_at',
            'event_dedup'   => null,
            'ip_records'    => 'created_at',
            'statistics'    => null,
            'users'         => null,
            'group_remarks' => null,
        ];
        $table = trim($_POST['table'] ?? '');
        $days  = intval($_POST['days'] ?? 0);
        // 注意: 白名单中部分表值为 null，isset() 对 null 键返回 false，必须用 array_key_exists
        if (!array_key_exists($table, $allow)) {
            json_response(['success' => false, 'message' => '不允许清理该表']);
        }
        if ($days !== 0 && $days !== 30 && $days !== 90) {
            json_response(['success' => false, 'message' => '无效的清理范围']);
        }
        try {
            $timeCol = $allow[$table];
            if ($days > 0 && $timeCol !== null) {
                $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
                $count = (int) db()->fetchColumn("SELECT COUNT(*) FROM \"{$table}\" WHERE \"{$timeCol}\" < ?", [$cutoff]);
                db()->execute("DELETE FROM \"{$table}\" WHERE \"{$timeCol}\" < ?", [$cutoff]);
                后台操作记录('清理数据库', "表 {$table} 清理 {$days} 天前数据，删除 {$count} 行");
                json_response(['success' => true, 'message' => "已清理 {$table} 表 {$count} 行数据（{$days} 天前）", 'count' => $count]);
            } else {
                $count = (int) db()->fetchColumn("SELECT COUNT(*) FROM \"{$table}\"");
                db()->execute("DELETE FROM \"{$table}\"");
                后台操作记录('清理数据库', "清空表 {$table}，删除 {$count} 行");
                json_response(['success' => true, 'message' => "已清空 {$table} 表 {$count} 行数据", 'count' => $count]);
            }
        } catch (Exception $e) {
            json_response(['success' => false, 'message' => '清理失败: ' . $e->getMessage()]);
        }
        break;

    case 'db_vacuum':
        // 重建数据库文件，回收全部空闲页（历史删除导致的体积膨胀）
        try {
            @set_time_limit(300);
            $dbFile = '';
            foreach (db()->fetchAll('PRAGMA database_list') as $r) {
                if ($r['name'] === 'main') { $dbFile = $r['file']; break; }
            }
            if ($dbFile === '') {
                json_response(['success' => false, 'message' => '无法定位数据库文件']);
            }
            clearstatcache(true, $dbFile);
            $before = @filesize($dbFile) ?: 0;
            $pdo = db()->getPdo();
            // 先 checkpoint 并截断 WAL，再 VACUUM
            try { $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Exception $e) {}
            $pdo->exec('VACUUM');
            clearstatcache(true, $dbFile);
            $after = @filesize($dbFile) ?: 0;
            $msg = 'VACUUM 完成：' . round($before / 1048576, 1) . ' MB → ' . round($after / 1048576, 1) . ' MB';
            if ($after < $before) {
                $msg .= '（回收 ' . round(($before - $after) / 1048576, 1) . ' MB）';
            }
            后台操作记录('数据库VACUUM', $msg);
            json_response(['success' => true, 'message' => $msg, 'before' => $before, 'after' => $after]);
        } catch (Exception $e) {
            json_response(['success' => false, 'message' => 'VACUUM 失败: ' . $e->getMessage() . '（若提示 database is locked，请先在机器人管理页停止 WS 服务或稍后重试）']);
        }
        break;

    case 'log_count':
        $appid = $_POST['appid'] ?? $_GET['appid'] ?? '';
        try {
            if (empty($appid)) {
                $count = (int) db()->fetchColumn("SELECT COUNT(*) FROM system_logs");
            } else {
                $count = (int) db()->fetchColumn("SELECT COUNT(*) FROM system_logs WHERE appid = ?", [$appid]);
            }
            json_response(['success' => true, 'count' => $count]);
        } catch (Exception $e) {
            json_response(['success' => true, 'count' => 0]);
        }
        break;

    case 'clear_events':
        db()->execute("DELETE FROM event_dedup");
        后台操作记录('清空事件去重记录', '');
        json_response(['success' => true, 'message' => '事件记录已清空']);
        break;

    case 'unban_ip':
        $ip = $_POST['ip'] ?? '';
        if (empty($ip)) {
            json_response(['success' => false, 'message' => 'IP不能为空']);
        }
        Auth::unbanIp($ip);
        后台操作记录('解封IP', $ip);
        json_response(['success' => true, 'message' => 'IP已解封']);
        break;

    // ==================== 导出 ====================
    case 'export_config':
        // 安全加固：不再导出管理员密码哈希（旧版会把 sha256 哈希写入导出文件）
        $config = ['admin' => '', 'password' => ''];
        $admin = db()->fetch("SELECT username FROM admin LIMIT 1");
        if ($admin) {
            $config['admin'] = $admin['username'];
            $config['password'] = '';
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

        // [bugfix] 原版缺失 bot.php 加载, 导致文字()未定义 Fatal Error, 后台"测试发送"必然失败
        // 注意: 必须先定义上下文常量再加载 bot.php —— bot.php 顶部会将未定义的常量兜底 define 为空串,
        //       若先加载 bot.php, 消息来源='' 会让 文字() 的 switch 无匹配而返回 null
        if (!defined('appid')) define('appid', $bot['appid']);
        if (!defined('secret')) define('secret', $bot['secret']);
        if (!defined('type')) define('type', $bot['env']);
        if (!defined('消息来源')) define('消息来源', $sourceType);
        if (!defined('来源')) define('来源', $targetId);
        if (!defined('消息ID')) define('消息ID', '');
        if (!defined('事件ID')) define('事件ID', '');
        require_once(__DIR__ . '/../bot.php');

        $resp = 文字($message);
        // 加固: 文字() 在上下文异常时可能返回 null, json_decode(null) 在 PHP8 会触发 deprecation
        $data = is_string($resp) ? json_decode($resp, true) : null;

        if (isset($data['id']) || (isset($data['code']) && $data['code'] == 0)) {
            后台操作记录('测试发送', $appid . ' ' . ($_POST['type'] ?? ''), $appid);
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

        后台操作记录('导入历史日志', "成功 {$imported} 条, 失败 {$errors} 条");
        json_response(['success' => true, 'message' => "导入完成：成功 {$imported} 条" . ($errors > 0 ? "，失败 {$errors} 条" : ''), 'imported' => $imported, 'errors' => $errors]);
        break;

    // ==================== WebSocket 守护进程控制 ====================
    case 'ws_start':
        $wsRoot = dirname(__DIR__);
        $pidFile = $wsRoot . '/data/ws_master.pid';
        $logFile = $wsRoot . '/data/logs/ws_daemon.log';
        $startFlag = $wsRoot . '/data/ws_start.flag';

        // 检查是否已在运行
        if (file_exists($pidFile)) {
            $pid = intval(file_get_contents($pidFile));
            if ($pid > 0 && isProcessRunning($pid)) {
                json_response(['success' => false, 'message' => 'WebSocket 守护进程已在运行 (PID:' . $pid . ')']);
            }
        }

        // 确保日志目录存在且尽量可写（修复 www/root 属主不一致问题）
        $logDir = $wsRoot . '/data/logs/';
        ensureWritableDir($logDir);
        ensureWritableDir($wsRoot . '/data');

        // 检查是否有启用了 WS 的机器人
        $bots = getBots();
        $wsBots = array_filter($bots, function($b) {
            return intval($b['ws_enabled']) === 1 && intval($b['enabled']) === 1;
        });
        if (empty($wsBots)) {
            json_response(['success' => false, 'message' => '没有已启用 WebSocket 模式的机器人，请先在机器人管理中启用']);
        }

        // 检测环境：是否可以直接启动进程
        $envCheck = canStartProcess();

        if ($envCheck['can_start']) {
            // 服务器允许启动进程 → 直接启动
            $phpBin = getPhpBinary();
            $wsScript = $wsRoot . '/ws_client.php';
            $result = startBackgroundProcess($phpBin, $wsScript, $logFile);

            if (!$result['success']) {
                // 直接启动失败，降级到标志文件方案
                $flagWrite = writeFileSafe($startFlag, date('Y-m-d H:i:s'));
                json_response([
                    'success' => false,
                    'message' => '直接启动失败: ' . $result['error']
                               . ($flagWrite['success'] ? '' : '；' . $flagWrite['message']),
                ]);
            }

            $newPid = $result['pid'];
            // 等待守护进程启动并写入 PID 文件
            $waited = 0;
            while ($waited < 3) {
                usleep(300000);
                $waited += 0.3;
                if (file_exists($pidFile)) {
                    $filePid = intval(file_get_contents($pidFile));
                    if ($filePid > 0) {
                        $newPid = $filePid;
                        break;
                    }
                }
            }
            if ($newPid > 0 && !isProcessRunning($newPid)) {
                if (file_exists($pidFile)) {
                    $filePid = intval(file_get_contents($pidFile));
                    if ($filePid > 0 && isProcessRunning($filePid)) {
                        $newPid = $filePid;
                    }
                }
            }

            后台操作记录('启动WS守护进程', 'PID:' . $newPid);
            json_response([
                'success' => true,
                'message' => 'WebSocket 守护进程已启动' . ($newPid > 0 ? ' (PID:' . $newPid . ')' : ''),
                'pid' => $newPid,
                'mode' => 'direct',
            ]);
        } else {
            // 服务器禁用了所有进程管理函数 → 使用标志文件 + 定时任务方案
            $flagWrite = writeFileSafe($startFlag, date('Y-m-d H:i:s'));
            if (!$flagWrite['success']) {
                json_response(['success' => false, 'message' => '写入启动标志失败: ' . $flagWrite['message']]);
            }

            后台操作记录('启动WS守护进程', 'cron模式');
            json_response([
                'success' => true,
                'message' => '已提交启动请求，守护进程将通过定时任务在1分钟内自动启动。请确保已配置 cron 定时任务：* * * * * /bin/bash ' . $wsRoot . '/ws_monitor.sh',
                'mode' => 'cron',
                'needs_cron' => true,
            ]);
        }
        break;

    case 'ws_stop':
        $wsRoot = dirname(__DIR__);
        $pidFile = $wsRoot . '/data/ws_master.pid';
        $stopFlag = $wsRoot . '/data/ws_stop.flag';

        if (!file_exists($pidFile)) {
            // 写入停止标志，防止定时任务自动重启
            $flagWrite = writeFileSafe($stopFlag, date('Y-m-d H:i:s'));
            @unlink($wsRoot . '/data/ws_autostart.flag');
            json_response(['success' => true, 'message' => '守护进程未运行，已清除自动启动'
                . ($flagWrite['success'] ? '' : '（停止标志写入失败: ' . $flagWrite['message'] . '）')]);
        }

        $pid = intval(file_get_contents($pidFile));
        if ($pid <= 0) {
            @unlink($pidFile);
            $flagWrite = writeFileSafe($stopFlag, date('Y-m-d H:i:s'));
            @unlink($wsRoot . '/data/ws_autostart.flag');
            json_response(['success' => true, 'message' => 'PID文件无效，已清理']);
        }

        // 写入停止标志，防止定时任务自动重启
        $flagWrite = writeFileSafe($stopFlag, date('Y-m-d H:i:s'));
        if (!$flagWrite['success']) {
            json_response(['success' => false, 'message' => '写入停止标志失败: ' . $flagWrite['message']]);
        }
        @unlink($wsRoot . '/data/ws_autostart.flag');

        // 尝试通过 posix_kill 终止进程（宝塔面板未禁用此函数）
        $killed = false;
        if (function_exists('posix_kill')) {
            // 先发 SIGTERM
            @posix_kill($pid, 15);
            $killed = true;

            // 等待退出
            $waited = 0;
            while ($waited < 5) {
                usleep(500000);
                $waited += 0.5;
                if (!isProcessRunning($pid)) break;
            }

            // 如果还没退出，SIGKILL
            if (isProcessRunning($pid)) {
                @posix_kill($pid, 9);
                sleep(1);
            }
        } else {
            // posix_kill 也不可用，只能依赖定时任务停止
            killProcess($pid, 15);
        }

        @unlink($pidFile);

        // 更新数据库状态
        $statusData = db()->getSetting('ws_daemon_status', []);
        if (!is_array($statusData)) $statusData = [];
        foreach ($statusData as $aid => &$s) {
            $s['status'] = 'stopped';
            $s['message'] = '已通过后台停止';
            $s['updated_at'] = date('Y-m-d H:i:s');
            $s['pid'] = 0;
        }
        unset($s);
        db()->setSetting('ws_daemon_status', $statusData);

        if ($killed && !isProcessRunning($pid)) {
            后台操作记录('停止WS守护进程', '');
            json_response(['success' => true, 'message' => 'WebSocket 守护进程已停止']);
        } else {
            json_response(['success' => true, 'message' => '已提交停止请求，守护进程将通过定时任务停止']);
        }
        break;

    case 'ws_status':
        $wsRoot = dirname(__DIR__);
        $pidFile = $wsRoot . '/data/ws_master.pid';
        $logFile = $wsRoot . '/data/logs/ws_daemon.log';
        $startFlag = $wsRoot . '/data/ws_start.flag';
        $stopFlag = $wsRoot . '/data/ws_stop.flag';
        $autostartFile = $wsRoot . '/data/ws_autostart.flag';

        $masterRunning = false;
        $masterPid = 0;
        $pendingAction = null;

        if (file_exists($pidFile)) {
            $masterPid = intval(file_get_contents($pidFile));
            if ($masterPid > 0) {
                $masterRunning = isProcessRunning($masterPid);
            }
        }

        // 检查待处理的操作
        if (file_exists($startFlag)) {
            $pendingAction = 'starting';
        }
        if (file_exists($stopFlag)) {
            $pendingAction = 'stopping';
        }

        // 检测环境能力
        $envCheck = canStartProcess();
        $canKill = canKillProcess();
        $autostartEnabled = file_exists($autostartFile) || db()->getSetting('ws_auto_start', '0') === '1';

        // 从数据库读取各 bot 的 WS 状态
        $botStatuses = db()->getSetting('ws_daemon_status', []);
        if (!is_array($botStatuses)) $botStatuses = [];

        // 获取已启用 WS 的机器人列表
        $bots = getBots();
        $wsBots = [];
        foreach ($bots as $bot) {
            if (intval($bot['ws_enabled']) === 1) {
                $wsBots[] = [
                    'appid' => $bot['appid'],
                    'env' => $bot['env'] ?? '正式',
                    'enabled' => intval($bot['enabled']) === 1,
                    'ws_url' => trim($bot['ws_url'] ?? ''),
                    'status' => $botStatuses[$bot['appid']]['status'] ?? 'unknown',
                    'message' => $botStatuses[$bot['appid']]['message'] ?? '',
                    'updated_at' => $botStatuses[$bot['appid']]['updated_at'] ?? '',
                ];
            }
        }

        // 读取最近日志
        $recentLogs = '';
        if (file_exists($logFile)) {
            $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines) {
                $recentLogs = implode("\n", array_slice($lines, -50));
            }
        }

        json_response([
            'success' => true,
            'data' => [
                'master_running' => $masterRunning,
                'master_pid' => $masterPid,
                'auto_start' => $autostartEnabled,
                'pending_action' => $pendingAction,
                'can_start_direct' => $envCheck['can_start'],
                'can_kill' => $canKill,
                'needs_cron' => !$envCheck['can_start'],
                'cron_command' => '* * * * * /bin/bash ' . $wsRoot . '/ws_monitor.sh',
                'bots' => $wsBots,
                'logs' => $recentLogs,
            ]
        ]);
        break;

    case 'ws_autostart':
        $enabled = intval($_POST['enabled'] ?? 0);
        $wsRoot = dirname(__DIR__);
        $autostartFile = $wsRoot . '/data/ws_autostart.flag';
        db()->setSetting('ws_auto_start', $enabled ? '1' : '0');
        if ($enabled) {
            ensureWritableDir(dirname($autostartFile));
            $writeResult = writeFileSafe($autostartFile, '1');
            if (!$writeResult['success']) {
                json_response(['success' => false, 'message' => '数据库已保存，但标志文件写入失败: ' . $writeResult['message']]);
            }
        } else {
            @unlink($autostartFile);
        }
        json_response(['success' => true, 'message' => $enabled ? '已开启自动启动（需要配置定时任务才能自动重启）' : '已关闭自动启动']);
        break;

    case 'ws_clear_logs':
        $wsRoot = dirname(__DIR__);
        $logFile = $wsRoot . '/data/logs/ws_daemon.log';
        // 修复 Permission denied: 日志文件可能由 root(cron) 创建，www用户无权直接截断
        // truncateFileSafe 会依次尝试: 直接截断 → chmod 0666后重试 → 目录可写时删除重建
        $clearResult = truncateFileSafe($logFile);
        if (!$clearResult['success']) {
            json_response(['success' => false, 'message' => '清空日志失败: ' . $clearResult['message']]);
        }
        json_response(['success' => true, 'message' => '日志已清空']);
        break;

    default:
        json_response(['success' => false, 'message' => '未知操作: ' . htmlspecialchars($action)], 400);
}
