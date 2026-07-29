<?php
/**
 * 指令测试 API
 * 在隔离环境中加载插件并执行，捕获所有发送函数的输出并返回
 */
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

require_once(__DIR__ . '/../../function.php');
require_once(__DIR__ . '/../../auth.php');

// 认证检查
if (!Auth::check()) {
    json_response(['code' => 401, 'msg' => '未登录或会话已过期'], 401);
}

$action = $_POST['action'] ?? 'run';

try {
    if ($action === 'run') {
        $content = $_POST['content'] ?? '';
        $appid = trim($_POST['appid'] ?? '');
        $pluginName = trim($_POST['plugin_name'] ?? '');
        $simulateSource = trim($_POST['simulate_source'] ?? '群聊');
        $simulateTarget = trim($_POST['simulate_target'] ?? 'test_target');
        $simulateUser = trim($_POST['simulate_user'] ?? 'test_user');

        if (empty($content)) {
            json_response(['code' => 400, 'msg' => '缺少消息内容']);
        }

        // 验证 bot 存在
        $bot = getBot($appid);
        if (!$bot) {
            json_response(['code' => 404, 'msg' => '机器人不存在']);
        }

        // 用于收集发送输出的数组
        $capturedOutputs = [];
        $logOutputs = [];

        // ==================== 定义捕获模式的全局常量 ====================
        // 这些常量模拟真实运行环境
        define('SIM_MODE', true); // 标记模拟模式
        define('消息', $content);
        define('消息来源', $simulateSource);
        define('来源', $simulateTarget);
        define('用户', $simulateUser);
        define('消息ID', 'sim_' . bin2hex(random_bytes(8)));
        define('事件ID', 'sim_evt_' . bin2hex(random_bytes(8)));
        define('appid', $bot['appid']);
        define('secret', $bot['secret']);
        define('type', $bot['env']);

        // ==================== 定义捕获模式的发送函数 ====================
        // 重写所有发送函数为捕获模式，不实际调用API

        function 文字($content) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'text',
                'content' => $content
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function MD($md, $keyboard = null, $style = null) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'markdown',
                'content' => $md,
                'keyboard' => $keyboard,
                'style' => $style
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 图片($image, $content = null) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'image',
                'image' => (strlen($image) > 200 ? substr($image, 0, 200) . '...' : $image),
                'content' => $content
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 语音($yy) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'voice',
                'content' => '[语音]'
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 视频($video) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'video',
                'content' => '[视频]'
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 文件($yy, $nm = null) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'file',
                'name' => $nm,
                'content' => '[文件]'
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 引用($msgId, $content = '') {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'quote',
                'ref_msg_id' => $msgId,
                'content' => $content
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 文卡(...$items) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'text_card',
                'items' => $items
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 大图($title, $xtitle, $iurl) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'image_card',
                'title' => $title,
                'subtitle' => $xtitle,
                'image' => $iurl
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 跳转卡($title, $desc, $image, $tz) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'link_card',
                'title' => $title,
                'desc' => $desc,
                'image' => $image,
                'url' => $tz
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 按钮($key) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'keyboard',
                'keyboard_id' => $key
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 原生按钮($md, $rows) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'native_keyboard',
                'markdown' => $md,
                'rows' => $rows
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 流式(...$msgs) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'stream',
                'messages' => $msgs
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 撤回($id) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'recall',
                'message_id' => $id
            ];
            return json_encode(['code' => 0, 'simulated' => true]);
        }

        function 头像($id) {
            return "https://q.qlogo.cn/qqapp/" . appid . "/{$id}/640";
        }

        function BOT信息() {
            return json_encode(['id' => appid, 'username' => 'Simulated Bot', 'simulated' => true]);
        }

        function 推送到群($groupOpenid, $content, $msgType = 0) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'push_group',
                'target' => $groupOpenid,
                'content' => $content,
                'msg_type' => $msgType
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 推送到用户($userOpenid, $content, $msgType = 0) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'push_user',
                'target' => $userOpenid,
                'content' => $content,
                'msg_type' => $msgType
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 推送MD到群($groupOpenid, $md, $keyboard = null) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'push_md_group',
                'target' => $groupOpenid,
                'content' => $md,
                'keyboard' => $keyboard
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 推送MD到用户($userOpenid, $md, $keyboard = null) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'push_md_user',
                'target' => $userOpenid,
                'content' => $md,
                'keyboard' => $keyboard
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function 本地语音($yy) {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'local_voice',
                'content' => '[本地语音]'
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        function Emoji($emojiId, $content = '') {
            global $capturedOutputs;
            $capturedOutputs[] = [
                'type' => 'emoji',
                'emoji_id' => $emojiId,
                'content' => $content
            ];
            return json_encode(['id' => 'sim_msg_' . bin2hex(random_bytes(4)), 'code' => 0, 'simulated' => true]);
        }

        // wlog 保持正常功能，额外捕获日志
        function wlog_sim_capture($content) {
            global $logOutputs;
            $logOutputs[] = $content;
        }

        // ==================== 加载并执行插件 ====================
        $pluginDir = __DIR__ . '/../../plugin/';
        $pluginFile = null;

        if (!empty($pluginName)) {
            // 测试指定插件
            $candidate = $pluginDir . $pluginName . '.php';
            if (!file_exists($candidate)) {
                // 尝试直接作为文件名
                $candidate = $pluginDir . $pluginName;
            }
            if (!file_exists($candidate)) {
                json_response(['code' => 404, 'msg' => '插件文件不存在']);
            }
            $pluginFile = $candidate;
        } else {
            // 没有指定插件，遍历所有已启用的插件
            $enabledPlugins = db()->fetchAll(
                "SELECT plugin_name FROM plugin_status WHERE appid = ? AND enabled = 1",
                [$appid]
            );
            if (empty($enabledPlugins)) {
                json_response(['code' => 404, 'msg' => '没有启用的插件']);
            }
            // 依次加载所有启用的插件
            foreach ($enabledPlugins as $ep) {
                $candidate = $pluginDir . $ep['plugin_name'] . '.php';
                if (file_exists($candidate)) {
                    // 在沙箱中执行
                    ob_start();
                    try {
                        include($candidate);
                    } catch (Exception $e) {
                        $logOutputs[] = '插件 ' . $ep['plugin_name'] . ' 执行错误: ' . $e->getMessage();
                    }
                    ob_end_clean();
                }
            }
            json_response([
                'code' => 0,
                'msg' => '模拟执行完成',
                'data' => [
                    'input' => [
                        'content' => $content,
                        'source' => $simulateSource,
                        'target' => $simulateTarget,
                        'user' => $simulateUser
                    ],
                    'outputs' => $capturedOutputs,
                    'logs' => $logOutputs
                ]
            ]);
            return;
        }

        // 执行指定插件
        ob_start();
        $startTime = microtime(true);

        try {
            include($pluginFile);
        } catch (Exception $e) {
            $logOutputs[] = '插件执行错误: ' . $e->getMessage();
            $logOutputs[] = '错误文件: ' . $e->getFile() . ':' . $e->getLine();
        }

        $outputBuffer = ob_get_clean();
        $endTime = microtime(true);
        $executionTime = round(($endTime - $startTime) * 1000, 2);

        json_response([
            'code' => 0,
            'msg' => '模拟执行完成',
            'data' => [
                'plugin' => $pluginName,
                'input' => [
                    'content' => $content,
                    'source' => $simulateSource,
                    'target' => $simulateTarget,
                    'user' => $simulateUser
                ],
                'outputs' => $capturedOutputs,
                'logs' => $logOutputs,
                'stdout' => $outputBuffer ?: null,
                'execution_time_ms' => $executionTime
            ]
        ]);

    } else {
        json_response(['code' => 400, 'msg' => '未知操作: ' . htmlspecialchars($action)]);
    }
} catch (Exception $e) {
    json_response(['code' => 500, 'msg' => '服务器错误: ' . $e->getMessage()]);
}
