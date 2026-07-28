<?php
/**
 * 登录 API
 * 支持 login(登录)、set(修改账号密码)
 * 使用 Auth 类，替代原版 config.json
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/function.php';
require_once dirname(__DIR__, 2) . '/auth.php';

/*
①登录
type = login
参数:
admin = 账号
password = 密码

②更改
type = set
admin = 账号
password = 密码
*/

$type     = $_POST["type"] ?? "";
$admin    = $_POST["admin"] ?? "";
$password = $_POST["password"] ?? "";

switch ($type) {
    case "login":
        if (empty($admin) || empty($password)) {
            echo json_encode([
                "code" => 400,
                "msg" => "缺少参数"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $result = Auth::login($admin, $password);
        if ($result['success']) {
            // 写入 cookie 以兼容前端基于 cookie 的鉴权
            if (!empty($result['token'])) {
                setcookie('admin_token', $result['token'], [
                    'expires' => time() + 604800,
                    'path' => '/',
                    'httponly' => true,
                    'secure' => true,
                    'samesite' => 'Strict'
                ]);
            }
            echo json_encode([
                "code" => 200,
                "msg" => "登录成功",
                "is_weak" => $result['is_weak'] ?? false
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            echo json_encode([
                "code" => 400,
                "msg" => $result['message'] ?? "账号或密码错误"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        break;

    case "set":
        if (empty($admin) || empty($password)) {
            echo json_encode([
                "code" => 400,
                "msg" => "缺少参数"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $oldPassword = $_POST["old_password"] ?? "";
        if (empty($oldPassword)) {
            echo json_encode([
                "code" => 400,
                "msg" => "需要提供当前密码"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $adminData = db()->fetch("SELECT * FROM admin LIMIT 1");
        if (!$adminData || !passwordVerify($oldPassword, $adminData['password'])) {
            echo json_encode([
                "code" => 400,
                "msg" => "当前密码验证失败"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $result = Auth::changePassword($admin, $password);
        if ($result['success']) {
            echo json_encode([
                "code" => 200,
                "msg" => $result['message'] ?? "更改成功"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            echo json_encode([
                "code" => 400,
                "msg" => $result['message'] ?? "更改失败"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        break;

    default:
        echo json_encode([
            "code" => 400,
            "msg" => "无效的请求类型"
        ], JSON_UNESCAPED_UNICODE);
}
