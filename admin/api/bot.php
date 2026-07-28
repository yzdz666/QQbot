<?php
/**
 * 机器人管理 API
 * 支持 add(添加)、del(删除)、switch_env(切换环境)
 * 使用数据库存储，替代原版 main.json
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/function.php';
require_once dirname(__DIR__, 2) . '/auth.php';

if (!Auth::check()) {
    echo json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$type = $_REQUEST["type"] ?? "";

if (empty($type)) {
    echo json_encode([
        "code" => 400,
        "msg" => "未传入数据"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($type) {
    case "add":
        $appid = trim($_REQUEST["appid"] ?? "");
        $secret = trim($_REQUEST["secret"] ?? "");
        $environment = $_REQUEST["environment"] ?? "正式";

        if (empty($appid) || empty($secret)) {
            echo json_encode([
                "code" => 400,
                "msg" => "缺少必要参数"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            addBot($appid, $secret, $environment);
            echo json_encode([
                "code" => 200,
                "msg" => "添加成功"
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode([
                "code" => 500,
                "msg" => "添加失败: " . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        break;

    case "del":
        $appid = $_REQUEST["appid"] ?? "";

        if (empty($appid)) {
            echo json_encode([
                "code" => 400,
                "msg" => "缺少必要参数"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $bot = getBot($appid);
        if (!$bot) {
            echo json_encode([
                "code" => 404,
                "msg" => "机器人不存在"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            deleteBot($appid);
            echo json_encode([
                "code" => 200,
                "msg" => "删除成功"
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode([
                "code" => 500,
                "msg" => "删除失败: " . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        break;

    case "switch_env":
        $appid = $_REQUEST["appid"] ?? "";
        $environment = $_REQUEST["environment"] ?? "";

        if (empty($appid) || empty($environment)) {
            echo json_encode([
                "code" => 400,
                "msg" => "缺少必要参数"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $bot = getBot($appid);
        if (!$bot) {
            echo json_encode([
                "code" => 404,
                "msg" => "机器人不存在"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            updateBot($appid, ['env' => $environment]);
            echo json_encode([
                "code" => 200,
                "msg" => "切换成功"
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode([
                "code" => 500,
                "msg" => "保存配置失败: " . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        break;

    default:
        echo json_encode([
            "code" => 400,
            "msg" => "无效的操作类型"
        ], JSON_UNESCAPED_UNICODE);
}
