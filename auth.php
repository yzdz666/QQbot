<?php
/**
 * 认证系统 - 安全的会话管理和IP防护
 */

require_once(__DIR__ . '/function.php');

class Auth {
    const SESSION_DURATION = 604800; // 7天
    const MAX_FAIL_COUNT = 5;
    const BAN_DURATION = 43200; // 12小时
    const FAIL_WINDOW = 86400; // 24小时

    public static function init() {
        if (session_status() === PHP_SESSION_NONE) {
            // 设置 session cookie 参数，确保在手机浏览器上也能可靠传递
            // SameSite=Lax 允许同站 AJAX 请求携带 cookie
            // HttpOnly 防止 JavaScript 访问 cookie
            // 路径为 / 确保所有路径都能访问
            if (PHP_VERSION_ID >= 70300) {
                session_set_cookie_params([
                    'lifetime' => self::SESSION_DURATION,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            } else {
                session_set_cookie_params(self::SESSION_DURATION, '/', '', false, true);
            }
            session_start();
        }
    }

    // 登录验证
    public static function login($username, $password) {
        self::init();
        $ip = getClientIp();

        // 检查IP是否被封禁
        if (self::isIpBanned($ip)) {
            return ['success' => false, 'message' => 'IP已被封禁，请稍后再试'];
        }

        // 从数据库获取管理员
        $admin = db()->fetch("SELECT * FROM admin LIMIT 1");
        if (!$admin) {
            return ['success' => false, 'message' => '系统未初始化'];
        }

        if ($admin['username'] !== $username) {
            self::recordFailedAttempt($ip);
            后台操作记录('登录失败', '用户名不匹配: ' . $username . ' IP: ' . $ip);
            return ['success' => false, 'message' => '用户名或密码错误'];
        }

        if (!passwordVerify($password, $admin['password'])) {
            self::recordFailedAttempt($ip);
            后台操作记录('登录失败', '密码错误: ' . $username . ' IP: ' . $ip);
            return ['success' => false, 'message' => '用户名或密码错误'];
        }

        // 登录成功：轮换 Session ID，防止会话固定攻击
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        self::clearFailedAttempts($ip);
        $token = self::createSession($ip);
        $_SESSION['admin_username'] = $username;
        后台操作记录('管理员登录', 'IP: ' . $ip);

        // 旧版 sha256 / 明文密码自动升级为 bcrypt
        $storedPassword = (string)$admin['password'];
        if ($storedPassword === '' || $storedPassword[0] !== '$' || password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
            db()->execute("UPDATE admin SET password = ? WHERE id = ?", [passwordHash($password), $admin['id']]);
        }

        return [
            'success' => true,
            'token' => $token,
            'is_weak' => isWeakPassword($password)
        ];
    }

    // 创建会话
    private static function createSession($ip) {
        $token = generateToken(32);
        $expiresAt = date('Y-m-d H:i:s', time() + self::SESSION_DURATION);
        db()->execute(
            "INSERT INTO sessions (token, ip, expires_at) VALUES (?, ?, ?)",
            [$token, $ip, $expiresAt]
        );

        // 同时存入PHP Session
        $_SESSION['admin_token'] = $token;

        // 清理过期会话
        db()->execute("DELETE FROM sessions WHERE expires_at < datetime('now','localtime')");

        // 限制最大会话数
        $count = db()->fetchColumn("SELECT COUNT(*) FROM sessions");
        if ($count > 10) {
            db()->execute("DELETE FROM sessions WHERE token IN (
                SELECT token FROM sessions ORDER BY created_at ASC LIMIT ?
            )", [$count - 10]);
        }

        return $token;
    }

    // 验证会话
    public static function check() {
        self::init();
        $token = self::getToken();
        if (!$token) return false;

        $row = db()->fetch(
            "SELECT * FROM sessions WHERE token = ? AND expires_at > datetime('now','localtime')",
            [$token]
        );
        return $row !== false;
    }

    // 获取当前token
    public static function getToken() {
        // 从Session获取
        if (isset($_SESSION['admin_token'])) {
            return $_SESSION['admin_token'];
        }
        // 从Header获取
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                $auth = $headers['Authorization'];
                if (preg_match('/Bearer\s+(.+)/', $auth, $m)) {
                    return trim($m[1]);
                }
            }
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\s+(.+)/', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
                return trim($m[1]);
            }
        }
        // 从Cookie获取（HttpOnly）
        if (isset($_COOKIE['admin_token'])) {
            return $_COOKIE['admin_token'];
        }
        // 安全说明：不再接受 ?token= 形式，避免 token 通过 Referer / 访问日志 / 截图泄露
        return null;
    }

    // 登出
    public static function logout() {
        self::init();
        $token = self::getToken();
        if ($token) {
            db()->execute("DELETE FROM sessions WHERE token = ?", [$token]);
        }
        后台操作记录('管理员登出', 'IP: ' . getClientIp());
        unset($_SESSION['admin_token']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        setcookie('admin_token', '', time() - 3600, '/');
        return true;
    }

    // 当前登录管理员用户名（用于操作记录）
    public static function currentUsername() {
        $username = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : '';
        if ($username === '') {
            $admin = db()->fetch("SELECT username FROM admin LIMIT 1");
            $username = $admin ? $admin['username'] : '';
        }
        return $username;
    }

    // 修改密码（必须校验当前密码，防止会话被劫持后直接改密）
    public static function changePassword($username, $newPassword, $currentPassword = '') {
        if (!is_string($newPassword) || strlen($newPassword) < 8) {
            return ['success' => false, 'message' => '新密码长度至少8位'];
        }
        if (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
            return ['success' => false, 'message' => '新密码需同时包含字母和数字'];
        }
        if ((string)$currentPassword === '') {
            return ['success' => false, 'message' => '请输入当前密码'];
        }
        $admin = db()->fetch("SELECT * FROM admin LIMIT 1");
        if (!$admin || !passwordVerify($currentPassword, $admin['password'])) {
            后台操作记录('修改密码失败', '当前密码校验失败');
            return ['success' => false, 'message' => '当前密码不正确'];
        }
        $username = trim((string)$username);
        if ($username === '') {
            return ['success' => false, 'message' => '用户名不能为空'];
        }
        if ($username === $admin['username'] && $newPassword === $currentPassword) {
            return ['success' => false, 'message' => '新密码不能与当前密码相同'];
        }
        db()->execute("UPDATE admin SET username = ?, password = ? WHERE id = ?",
            [$username, passwordHash($newPassword), $admin['id']]);
        后台操作记录('修改管理员密码', '用户名: ' . $username);
        // 清除所有会话
        db()->execute("DELETE FROM sessions");
        unset($_SESSION['admin_token']);
        return ['success' => true, 'message' => '修改成功，请重新登录'];
    }

    // IP防护
    private static function isIpBanned($ip) {
        $cutoff = date('Y-m-d H:i:s', time() - self::FAIL_WINDOW);
        $fails = db()->fetchColumn(
            "SELECT COUNT(*) FROM ip_records WHERE ip = ? AND success = 0 AND created_at > ?",
            [$ip, $cutoff]
        );
        return $fails >= self::MAX_FAIL_COUNT;
    }

    private static function recordFailedAttempt($ip) {
        db()->execute("INSERT INTO ip_records (ip, success) VALUES (?, 0)", [$ip]);
        // 清理旧记录
        $cutoff = date('Y-m-d H:i:s', time() - self::FAIL_WINDOW);
        db()->execute("DELETE FROM ip_records WHERE created_at < ?", [$cutoff]);
    }

    private static function clearFailedAttempts($ip) {
        db()->execute("DELETE FROM ip_records WHERE ip = ? AND success = 0", [$ip]);
    }

    // 获取登录日志
    public static function getLoginLogs() {
        return db()->fetchAll(
            "SELECT ip, success, created_at FROM ip_records ORDER BY created_at DESC LIMIT 100"
        );
    }

    // 解封IP
    public static function unbanIp($ip) {
        db()->execute("DELETE FROM ip_records WHERE ip = ?", [$ip]);
        return true;
    }

    // 检查是否已安装
    public static function isInstalled() {
        $admin = db()->fetch("SELECT COUNT(*) as c FROM admin");
        return $admin && $admin['c'] > 0;
    }

    // 初始化管理员
    public static function setupAdmin($username, $password) {
        db()->execute("DELETE FROM admin");
        db()->execute("INSERT INTO admin (username, password) VALUES (?, ?)", [$username, passwordHash($password)]);
        return true;
    }

    // ==================== CSRF / 同源防护 ====================

    /** 获取（或生成）当前会话的 CSRF token */
    public static function csrfToken() {
        self::init();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /** 校验 CSRF token（POST/PUT/DELETE 必需） */
    public static function verifyCsrf() {
        self::init();
        $expected = $_SESSION['csrf_token'] ?? '';
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
        if ($expected === '' || !is_string($token) || !hash_equals($expected, $token)) {
            json_response(['success' => false, 'message' => 'CSRF 校验失败，请刷新页面后重试'], 403);
        }
    }

    /**
     * 同源校验：拦截跨站表单/AJAX（补充 SameSite，兼容旧浏览器与子域场景）
     * 允许无 Origin/Referer 的服务端调用（如 CLI、自建脚本）。
     */
    public static function requireSameOrigin() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return;
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $source = $origin !== '' ? $origin : $referer;
        if ($source === '') return;
        $sourceHost = parse_url($source, PHP_URL_HOST);
        if ($sourceHost && strcasecmp($sourceHost, explode(':', $host)[0]) !== 0) {
            json_response(['success' => false, 'message' => '非法来源请求'], 403);
        }
    }

    /**
     * 写操作防护：同源校验 + CSRF 令牌校验
     * 前端由 admin/header.php 注入的脚本自动为 fetch / XHR 附加 X-CSRF-Token。
     */
    public static function requireWriteProtection() {
        self::requireSameOrigin();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            self::verifyCsrf();
        }
    }

    // 要求登录（用于API和页面保护）
    public static function requireAuth() {
        if (!self::check()) {
            if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
                json_response(['success' => false, 'message' => '未登录或会话已过期'], 401);
            } else {
                header('Location: login.php');
                exit;
            }
        }
    }
}
