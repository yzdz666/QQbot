<?php
/**
 * 官鸡插件市场 - 后台认证
 */

class MarketAuth {
    const IDLE_TIMEOUT = 7200;      // 2 小时无操作自动退出
    const MAX_FAIL_IP = 10;         // 单 IP 15 分钟最多失败次数
    const MAX_FAIL_USER = 5;        // 单账号 15 分钟最多失败次数
    const FAIL_WINDOW = 900;

    public static function start() {
        market_session_start();
    }

    public static function check() {
        self::start();
        if (empty($_SESSION['market_admin_id']) || empty($_SESSION['market_admin_name'])) {
            return false;
        }
        $now = time();
        if (!empty($_SESSION['market_last_active']) && ($now - (int)$_SESSION['market_last_active']) > self::IDLE_TIMEOUT) {
            self::logout();
            return false;
        }
        // 会话指纹（User-Agent），降低会话被劫持后的可用性
        $ua = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        if (!empty($_SESSION['market_ua']) && !hash_equals($_SESSION['market_ua'], $ua)) {
            self::logout();
            return false;
        }
        $_SESSION['market_last_active'] = $now;
        return true;
    }

    public static function current() {
        if (!self::check()) return null;
        return [
            'id' => (int)$_SESSION['market_admin_id'],
            'username' => (string)$_SESSION['market_admin_name'],
        ];
    }

    public static function login($username, $password) {
        self::start();
        $username = trim((string)$username);
        $ip = market_client_ip();

        if ($username === '' || $password === '') {
            return ['success' => false, 'message' => '请输入用户名和密码'];
        }
        if (!market_rate_limit('login-ip:' . $ip, self::MAX_FAIL_IP, self::FAIL_WINDOW)) {
            return ['success' => false, 'message' => '尝试次数过多，请 15 分钟后再试'];
        }

        $pdo = MarketDB::pdo();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND username = ? AND success = 0 AND created_at > datetime('now','localtime','-15 minutes')"
        );
        $stmt->execute([$ip, $username]);
        if ((int)$stmt->fetchColumn() >= self::MAX_FAIL_USER) {
            return ['success' => false, 'message' => '该账号失败次数过多，请 15 分钟后再试'];
        }

        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        $ok = $admin && password_verify($password, $admin['password']);
        $pdo->prepare("INSERT INTO login_attempts (ip, username, success) VALUES (?, ?, ?)")
            ->execute([$ip, $username, $ok ? 1 : 0]);

        if (!$ok) {
            market_audit($username, '登录失败', 'IP: ' . $ip);
            // 统一提示，避免用户名枚举
            return ['success' => false, 'message' => '用户名或密码错误'];
        }

        // 自动升级旧哈希
        if (password_needs_rehash($admin['password'], PASSWORD_DEFAULT)) {
            $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
        }

        session_regenerate_id(true);
        $_SESSION['market_admin_id'] = (int)$admin['id'];
        $_SESSION['market_admin_name'] = $admin['username'];
        $_SESSION['market_last_active'] = time();
        $_SESSION['market_ua'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        // 登录后轮换 CSRF token
        $_SESSION['market_csrf'] = bin2hex(random_bytes(32));

        $pdo->prepare("UPDATE admins SET last_login = datetime('now','localtime'), last_ip = ? WHERE id = ?")
            ->execute([$ip, $admin['id']]);
        market_audit($admin['username'], '登录成功', 'IP: ' . $ip);

        return ['success' => true, 'message' => '登录成功'];
    }

    public static function logout() {
        self::start();
        if (!empty($_SESSION['market_admin_name'])) {
            market_audit($_SESSION['market_admin_name'], '退出登录', 'IP: ' . market_client_ip());
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function requireAdmin() {
        if (!self::check()) {
            $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
            header('Location: login.php?next=' . urlencode($next));
            exit;
        }
        if (!self::ipAllowed()) {
            http_response_code(403);
            exit('当前 IP 不在后台白名单内');
        }
    }

    /** 后台 IP 白名单（逗号/换行分隔，支持 IPv4 CIDR） */
    public static function ipAllowed() {
        $raw = trim((string)market_setting('admin_ip_allow', ''));
        if ($raw === '') return true;
        $ip = market_client_ip();
        foreach (preg_split('/[\s,]+/', $raw) as $rule) {
            $rule = trim($rule);
            if ($rule === '') continue;
            if ($rule === $ip) return true;
            if (strpos($rule, '/') !== false && self::cidrMatch($ip, $rule)) return true;
        }
        return false;
    }

    private static function cidrMatch($ip, $cidr) {
        list($subnet, $bits) = array_pad(explode('/', $cidr, 2), 2, null);
        if ($bits === null || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || !filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $bits = (int)$bits;
        if ($bits < 0 || $bits > 32) return false;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /** 修改密码 */
    public static function changePassword($oldPassword, $newPassword) {
        $admin = self::current();
        if (!$admin) return ['success' => false, 'message' => '未登录'];
        if (strlen($newPassword) < 8) return ['success' => false, 'message' => '新密码至少 8 位'];
        if (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
            return ['success' => false, 'message' => '新密码需同时包含字母和数字'];
        }
        $pdo = MarketDB::pdo();
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->execute([$admin['id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($oldPassword, $row['password'])) {
            return ['success' => false, 'message' => '当前密码不正确'];
        }
        if ($oldPassword === $newPassword) {
            return ['success' => false, 'message' => '新密码不能与当前密码相同'];
        }
        $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?")
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $admin['id']]);
        market_set_setting('password_changed', '1');
        market_audit($admin['username'], '修改密码', 'IP: ' . market_client_ip());
        return ['success' => true, 'message' => '密码已修改，请重新登录'];
    }
}
