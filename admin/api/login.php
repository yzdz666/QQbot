<?php
/**
 * 登录 API
 * 支持 login(登录)、set(修改账号密码)
 * 使用 Auth 类，替代原版 config.json
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/function.php';
require_once dirname(__DIR__, 2) . '/auth.php';


// 同源校验：拦截跨站 POST（与 SameSite Cookie 双重防护）
Auth::requireSameOrigin();
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
                $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') || intval($_SERVER['SERVER_PORT'] ?? 0) === 443;
                if (PHP_VERSION_ID >= 70300) {
                    setcookie('admin_token', $result['token'], [
                        'expires'  => time() + 604800,
                        'path'     => '/',
                        'secure'   => $secure,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                } else {
                    setcookie('admin_token', $result['token'], time() + 604800, '/', '', $secure, true);
                }
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
        // 安全加固：修改账号密码必须已登录，防止未认证用户直接重置管理员凭证
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode([
                "code" => 401,
                "msg" => "未登录或会话已过期，请先登录后再修改"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $currentPassword = $_POST["current_password"] ?? ($_POST["old_password"] ?? "");
        if (empty($admin) || empty($password)) {
            echo json_encode([
                "code" => 400,
                "msg" => "缺少参数"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $result = Auth::changePassword($admin, $password, $currentPassword);
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
