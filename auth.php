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
            return ['success' => false, 'message' => '用户名或密码错误'];
        }

        if (!passwordVerify($password, $admin['password'])) {
            self::recordFailedAttempt($ip);
            return ['success' => false, 'message' => '用户名或密码错误'];
        }

        // 登录成功
        self::clearFailedAttempts($ip);
        $token = self::createSession($ip);

        // 如果密码是明文，自动升级为哈希
        if (strpos($admin['password'], 'sha256:') !== 0) {
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
        // 从Cookie获取
        if (isset($_COOKIE['admin_token'])) {
            return $_COOKIE['admin_token'];
        }
        // 从GET参数获取
        if (isset($_GET['token'])) {
            return $_GET['token'];
        }
        return null;
    }

    // 登出
    public static function logout() {
        self::init();
        $token = self::getToken();
        if ($token) {
            db()->execute("DELETE FROM sessions WHERE token = ?", [$token]);
        }
        unset($_SESSION['admin_token']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        setcookie('admin_token', '', time() - 3600, '/');
        return true;
    }

    // 修改密码
    public static function changePassword($username, $newPassword) {
        if (strlen($newPassword) < 6) {
            return ['success' => false, 'message' => '密码长度至少6位'];
        }
        db()->execute("UPDATE admin SET username = ?, password = ?", [$username, passwordHash($newPassword)]);
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
