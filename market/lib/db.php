<?php
/**
 * 官鸡插件市场 - 数据层（SQLite）
 */

class MarketDB {
    private static $pdo = null;
    private static $booted = false;

    public static function pdo() {
        if (self::$pdo === null) {
            self::boot();
        }
        return self::$pdo;
    }

    private static function boot() {
        $dir = MARKET_DATA_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            http_response_code(500);
            exit('插件市场数据目录无法创建，请检查 market/data 写权限');
        }
        if (!is_writable($dir)) {
            http_response_code(500);
            exit('插件市场数据目录不可写，请执行 chown -R www:www market/data market/storage');
        }
        self::protect_dir($dir);
        $dbFile = $dir . '/market.db';
        try {
            self::$pdo = new PDO('sqlite:' . $dbFile);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
        } catch (Throwable $e) {
            http_response_code(500);
            exit('插件市场数据库初始化失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        self::create_tables();
        self::seed();
        self::$booted = true;
    }

    /** data/storage 目录的 Web 访问防护（Apache） */
    public static function protect_dir($dir) {
        $ht = rtrim($dir, '/') . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        }
        $index = rtrim($dir, '/') . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }

    private static function create_tables() {
        $sqls = [
            "CREATE TABLE IF NOT EXISTS plugins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                summary TEXT DEFAULT '',
                description TEXT DEFAULT '',
                category TEXT DEFAULT '其他',
                author TEXT DEFAULT '',
                author_link TEXT DEFAULT '',
                license TEXT DEFAULT 'MIT',
                homepage TEXT DEFAULT '',
                icon TEXT DEFAULT '🧩',
                tags TEXT DEFAULT '',
                status TEXT DEFAULT 'pending',
                featured INTEGER DEFAULT 0,
                downloads INTEGER DEFAULT 0,
                reject_reason TEXT DEFAULT '',
                manage_hash TEXT DEFAULT '',
                created_at TEXT DEFAULT (datetime('now','localtime')),
                updated_at TEXT DEFAULT (datetime('now','localtime')),
                published_at TEXT
            )",
            "CREATE INDEX IF NOT EXISTS idx_market_plugins_status ON plugins(status)",
            "CREATE INDEX IF NOT EXISTS idx_market_plugins_category ON plugins(category)",

            "CREATE TABLE IF NOT EXISTS versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                plugin_id INTEGER NOT NULL,
                version TEXT NOT NULL,
                changelog TEXT DEFAULT '',
                file_path TEXT NOT NULL,
                file_size INTEGER DEFAULT 0,
                sha256 TEXT NOT NULL,
                scan_status TEXT DEFAULT 'pending',
                scan_report TEXT DEFAULT '',
                status TEXT DEFAULT 'pending',
                downloads INTEGER DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now','localtime')),
                approved_at TEXT,
                UNIQUE(plugin_id, version)
            )",
            "CREATE INDEX IF NOT EXISTS idx_market_versions_plugin ON versions(plugin_id)",

            "CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                last_login TEXT DEFAULT '',
                last_ip TEXT DEFAULT '',
                created_at TEXT DEFAULT (datetime('now','localtime'))
            )",

            "CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin TEXT DEFAULT '',
                action TEXT NOT NULL,
                detail TEXT DEFAULT '',
                ip TEXT DEFAULT '',
                created_at TEXT DEFAULT (datetime('now','localtime'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_market_audit_created ON audit_logs(created_at)",

            "CREATE TABLE IF NOT EXISTS settings (
                k TEXT PRIMARY KEY,
                v TEXT DEFAULT ''
            )",

            "CREATE TABLE IF NOT EXISTS rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                k TEXT NOT NULL,
                ts INTEGER NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_market_rate_k ON rate_limits(k, ts)",

            "CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                username TEXT DEFAULT '',
                success INTEGER DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now','localtime'))
            )",
        ];
        foreach ($sqls as $sql) {
            self::$pdo->exec($sql);
        }
        self::migrate();
    }

    /** 轻量迁移：为已存在的库补充新列 */
    private static function migrate() {
        $cols = [];
        foreach (self::$pdo->query("PRAGMA table_info(plugins)")->fetchAll() as $row) {
            $cols[$row['name']] = true;
        }
        if (!isset($cols['manage_hash'])) {
            try { self::$pdo->exec("ALTER TABLE plugins ADD COLUMN manage_hash TEXT DEFAULT ''"); } catch (Throwable $e) {}
        }
        if (!isset($cols['author_link'])) {
            try { self::$pdo->exec("ALTER TABLE plugins ADD COLUMN author_link TEXT DEFAULT ''"); } catch (Throwable $e) {}
        }
    }

    private static function seed() {
        // 默认设置
        $defaults = [
            'site_name'        => '官鸡插件市场',
            'site_desc'        => '为官鸡 QQ 机器人框架提供经过审核的插件分发服务',
            'site_notice'      => '',
            'upload_enabled'   => '1',
            'auto_approve'     => '0',
            'max_upload'       => '2097152',
            'admin_ip_allow'   => '',
            'maintenance'      => '0',
            'allow_blocked'    => '0',
            'password_changed' => '0',
        ];
        $stmt = self::$pdo->prepare("INSERT OR IGNORE INTO settings (k, v) VALUES (?, ?)");
        foreach ($defaults as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        // 默认管理员
        $count = (int)self::$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        if ($count === 0) {
            self::$pdo->prepare("INSERT INTO admins (username, password) VALUES (?, ?)")
                ->execute([MARKET_ADMIN_USER, MARKET_ADMIN_HASH]);
        }

        // 首次导入示例插件
        $count = (int)self::$pdo->query("SELECT COUNT(*) FROM plugins")->fetchColumn();
        if ($count === 0) {
            self::seed_plugins();
        }
    }

    private static function seed_plugins() {
        $manifestFile = dirname(__DIR__) . '/seed/manifest.json';
        if (!is_file($manifestFile)) return;
        $items = json_decode((string)file_get_contents($manifestFile), true);
        if (!is_array($items)) return;

        foreach ($items as $item) {
            $file = dirname(__DIR__) . '/seed/' . ($item['file'] ?? '');
            if (!is_file($file)) continue;
            $code = (string)file_get_contents($file);
            $scan = market_scan_code($code);
            if ($scan['status'] === 'block') continue;

            $slug = market_slugify($item['slug'] ?? $item['name']);
            $version = $item['version'] ?? '1.0.0';
            $name = $item['name'] ?? $slug;
            $storeDir = MARKET_STORAGE_DIR . '/plugins/' . $slug . '/' . preg_replace('/[^0-9A-Za-z\.\-_]/', '', $version);
            if (!is_dir($storeDir)) @mkdir($storeDir, 0750, true);
            // 存为 .txt：即使 .htaccess/nginx 配置失效，服务器也不会执行市场内的插件源码
            $dest = $storeDir . '/plugin.php.txt';
            if (!@file_put_contents($dest, $code)) continue;

            try {
                $stmt = self::$pdo->prepare(
                    "INSERT INTO plugins (slug, name, summary, description, category, author, license, icon, tags, status, featured, published_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?, datetime('now','localtime'))"
                );
                $stmt->execute([
                    $slug, $name, $item['summary'] ?? '', $item['description'] ?? '',
                    $item['category'] ?? '其他', $item['author'] ?? '官方',
                    $item['license'] ?? 'MIT', $item['icon'] ?? '🧩',
                    implode(',', $item['tags'] ?? []), !empty($item['featured']) ? 1 : 0,
                ]);
                $pluginId = (int)self::$pdo->lastInsertId();
                self::$pdo->prepare(
                    "INSERT INTO versions (plugin_id, version, changelog, file_path, file_size, sha256, scan_status, scan_report, status, approved_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'approved', datetime('now','localtime'))"
                )->execute([
                    $pluginId, $version, $item['changelog'] ?? '首个版本',
                    $dest, filesize($dest), hash_file('sha256', $dest),
                    $scan['status'], json_encode($scan, JSON_UNESCAPED_UNICODE),
                ]);
            } catch (Throwable $e) {
                // 忽略单条种子失败
            }
        }
    }

    /** 查询单个插件（含最新已通过版本） */
    public static function plugin_with_latest($idOrSlug) {
        $pdo = self::pdo();
        if (ctype_digit((string)$idOrSlug)) {
            $stmt = $pdo->prepare("SELECT * FROM plugins WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("SELECT * FROM plugins WHERE slug = ?");
        }
        $stmt->execute([$idOrSlug]);
        $plugin = $stmt->fetch();
        if (!$plugin) return null;
        $stmt = $pdo->prepare(
            "SELECT * FROM versions WHERE plugin_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$plugin['id']]);
        $plugin['latest'] = $stmt->fetch() ?: null;
        return $plugin;
    }

    /** 公开可见插件条件 */
    public static function public_where() {
        return "p.status = 'approved' AND EXISTS (SELECT 1 FROM versions v WHERE v.plugin_id = p.id AND v.status = 'approved')";
    }
}
