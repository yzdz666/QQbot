<?php
/**
 * 数据库抽象层 - SQLite
 * 替代原有的JSON文件存储，提供高性能数据持久化
 */

class Database {
    private static $instance = null;
    private $pdo = null;
    private $dbPath;

    private function __construct($dbPath) {
        $this->dbPath = $dbPath;
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->initTables();
    }

    public static function getInstance($dbPath = null) {
        if (self::$instance === null) {
            if ($dbPath === null) {
                // 优先使用 APP_ROOT（支持二三级目录部署）
                $root = defined('APP_ROOT') ? APP_ROOT : __DIR__ . '/';
                $dbPath = $root . 'data/bot.db';
            }
            self::$instance = new self($dbPath);
        }
        return self::$instance;
    }

    private function initTables() {
        $sqls = [
            // 管理员表
            "CREATE TABLE IF NOT EXISTS admin (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                password TEXT NOT NULL,
                created_at TEXT DEFAULT (datetime('now','localtime'))
            )",

            // 机器人配置表
            "CREATE TABLE IF NOT EXISTS bots (
                appid TEXT PRIMARY KEY,
                secret TEXT NOT NULL,
                env TEXT DEFAULT '正式',
                ws_enabled INTEGER DEFAULT 0,
                ws_url TEXT DEFAULT '',
                enabled INTEGER DEFAULT 1,
                robot_qq TEXT DEFAULT '',
                owner_ids TEXT DEFAULT '[]',
                nickname TEXT DEFAULT '',
                avatar TEXT DEFAULT '',
                created_at TEXT DEFAULT (datetime('now','localtime'))
            )",

            // 通用键值存储表（替代JSON文件读写）
            "CREATE TABLE IF NOT EXISTS kv_store (
                namespace TEXT NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                updated_at TEXT DEFAULT (datetime('now','localtime')),
                PRIMARY KEY (namespace, key)
            )",

            // 插件启用状态表
            "CREATE TABLE IF NOT EXISTS plugin_status (
                appid TEXT NOT NULL,
                plugin_name TEXT NOT NULL,
                enabled INTEGER DEFAULT 1,
                PRIMARY KEY (appid, plugin_name)
            )",

            // 消息日志表
            "CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                appid TEXT NOT NULL,
                direction TEXT NOT NULL,
                source_type TEXT,
                target_id TEXT,
                user_id TEXT,
                content_type TEXT,
                content TEXT,
                message_id TEXT,
                raw_data TEXT,
                created_at TEXT DEFAULT (datetime('now','localtime'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_messages_appid ON messages(appid)",
            "CREATE INDEX IF NOT EXISTS idx_messages_target ON messages(target_id)",
            "CREATE INDEX IF NOT EXISTS idx_messages_direction ON messages(direction)",
            "CREATE INDEX IF NOT EXISTS idx_messages_created ON messages(created_at)",

            // 系统日志表
            "CREATE TABLE IF NOT EXISTS system_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                appid TEXT,
                log_type TEXT NOT NULL,
                content TEXT,
                level TEXT DEFAULT 'INFO',
                created_at TEXT DEFAULT (datetime('now','localtime'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_syslogs_appid ON system_logs(appid)",
            "CREATE INDEX IF NOT EXISTS idx_syslogs_type ON system_logs(log_type)",
            "CREATE INDEX IF NOT EXISTS idx_syslogs_created ON system_logs(created_at)",

            // 事件去重表
            "CREATE TABLE IF NOT EXISTS event_dedup (
                event_id TEXT PRIMARY KEY,
                appid TEXT,
                created_at TEXT DEFAULT (datetime('now','localtime'))
            )",

            // 会话表
            "CREATE TABLE IF NOT EXISTS sessions (
                token TEXT PRIMARY KEY,
                ip TEXT,
                created_at TEXT DEFAULT (datetime('now','localtime')),
                expires_at TEXT
            )",

            // IP访问记录表
            "CREATE TABLE IF NOT EXISTS ip_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                success INTEGER DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now','localtime'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_iprecords_ip ON ip_records(ip)",

            // 统计表
            "CREATE TABLE IF NOT EXISTS statistics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                appid TEXT,
                stat_date TEXT,
                active_users INTEGER DEFAULT 0,
                active_groups INTEGER DEFAULT 0,
                total_messages INTEGER DEFAULT 0,
                receive_count INTEGER DEFAULT 0,
                send_count INTEGER DEFAULT 0,
                detail TEXT,
                UNIQUE(appid, stat_date)
            )",

            // 用户表
            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                appid TEXT NOT NULL,
                user_id TEXT NOT NULL,
                nickname TEXT DEFAULT '',
                remark TEXT DEFAULT '',
                first_seen TEXT DEFAULT (datetime('now','localtime')),
                last_active TEXT DEFAULT (datetime('now','localtime')),
                UNIQUE(appid, user_id)
            )",

            // 群组表
            "CREATE TABLE IF NOT EXISTS groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                appid TEXT NOT NULL,
                group_id TEXT NOT NULL,
                group_name TEXT DEFAULT '',
                remark TEXT DEFAULT '',
                custom_avatar TEXT DEFAULT '',
                first_seen TEXT DEFAULT (datetime('now','localtime')),
                last_active TEXT DEFAULT (datetime('now','localtime')),
                UNIQUE(appid, group_id)
            )",

            // 群备注表
            "CREATE TABLE IF NOT EXISTS group_remarks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                appid TEXT NOT NULL,
                group_id TEXT NOT NULL,
                name TEXT,
                qq TEXT,
                UNIQUE(appid, group_id, name)
            )",

            // AI配置表
            "CREATE TABLE IF NOT EXISTS ai_config (
                id INTEGER PRIMARY KEY,
                base_url TEXT DEFAULT '',
                api_key TEXT DEFAULT '',
                model TEXT DEFAULT 'gpt-4o-mini'
            )",

            // 系统设置表
            "CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at TEXT DEFAULT (datetime('now','localtime'))
            )",
        ];

        foreach ($sqls as $sql) {
            $this->pdo->exec($sql);
        }

        // ==================== 数据库迁移：为已有表添加新字段 ====================
        $this->migrateColumns();

        // 插入默认AI配置
        $count = $this->pdo->query("SELECT COUNT(*) FROM ai_config")->fetchColumn();
        if ($count == 0) {
            $this->pdo->prepare("INSERT INTO ai_config (id, base_url, api_key, model) VALUES (1, '', '', 'gpt-4o-mini')")->execute();
        }
    }

    /**
     * 数据库迁移：检查并添加缺失的列
     * 确保 SQLite 已有表能获得新增字段
     */
    private function migrateColumns() {
        $migrations = [
            'users' => [
                'remark' => "ALTER TABLE users ADD COLUMN remark TEXT DEFAULT ''",
            ],
            'groups' => [
                'remark'         => "ALTER TABLE groups ADD COLUMN remark TEXT DEFAULT ''",
                'custom_avatar'  => "ALTER TABLE groups ADD COLUMN custom_avatar TEXT DEFAULT ''",
            ],
            'bots' => [
                'robot_qq'  => "ALTER TABLE bots ADD COLUMN robot_qq TEXT DEFAULT ''",
                'owner_ids' => "ALTER TABLE bots ADD COLUMN owner_ids TEXT DEFAULT '[]'",
                'nickname'  => "ALTER TABLE bots ADD COLUMN nickname TEXT DEFAULT ''",
                'avatar'    => "ALTER TABLE bots ADD COLUMN avatar TEXT DEFAULT ''",
            ],
        ];

        foreach ($migrations as $table => $columns) {
            // 获取表的所有列名
            $stmt = $this->pdo->query("PRAGMA table_info({$table})");
            if ($stmt === false) continue; // 表不存在则跳过
            $existingCols = [];
            while ($row = $stmt->fetch()) {
                $existingCols[] = $row['name'];
            }

            foreach ($columns as $colName => $alterSql) {
                if (!in_array($colName, $existingCols)) {
                    try {
                        $this->pdo->exec($alterSql);
                    } catch (Exception $e) {
                        // 列可能已存在，忽略错误
                    }
                }
            }
        }
    }

    public function getPdo() {
        return $this->pdo;
    }

    // 通用查询
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn($sql, $params = []) {
        return $this->query($sql, $params)->fetchColumn();
    }

    public function execute($sql, $params = []) {
        return $this->query($sql, $params)->rowCount();
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    // KV存储 - 替代原 写()/读() 函数
    public function kvSet($namespace, $key, $value) {
        // 兼容原版：原版用json_encode存整个文件，单个值直接存原始类型
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_null($value)) {
            $value = '';
        } else {
            $value = (string)$value;
        }
        $this->execute(
            "INSERT OR IGNORE INTO kv_store (namespace, key, value, updated_at) VALUES (?, ?, ?, datetime('now','localtime'))",
            [$namespace, $key, $value]
        );
        return $this->execute(
            "UPDATE kv_store SET value = ?, updated_at = datetime('now','localtime') WHERE namespace = ? AND key = ?",
            [$value, $namespace, $key]
        );
    }

    public function kvGet($namespace, $key, $default = null) {
        $row = $this->fetch("SELECT value FROM kv_store WHERE namespace = ? AND key = ?", [$namespace, $key]);
        if ($row === false) return $default;
        $val = $row['value'];
        // 尝试json_decode，但仅在值看起来像JSON时才解码
        if ($val !== null && $val !== '' && ($val[0] === '{' || $val[0] === '[')) {
            $decoded = json_decode($val, true);
            if ($decoded !== null) return $decoded;
        }
        // 尝试还原数字类型（含布尔兼容：1/0 在PHP弱类型中等价于true/false）
        if (is_numeric($val) && $val !== '') {
            if (strpos($val, '.') === false && ctype_digit(ltrim($val, '-'))) {
                return (int)$val;
            }
            $floatVal = (float)$val;
            if ((string)$floatVal === $val) {
                return $floatVal;
            }
        }
        return $val;
    }

    public function kvGetAll($namespace) {
        $rows = $this->fetchAll("SELECT key, value FROM kv_store WHERE namespace = ?", [$namespace]);
        $result = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row['value'], true);
            $result[$row['key']] = $decoded !== null ? $decoded : $row['value'];
        }
        return $result;
    }

    public function kvDelete($namespace, $key) {
        return $this->execute("DELETE FROM kv_store WHERE namespace = ? AND key = ?", [$namespace, $key]);
    }

    public function kvDeleteNamespace($namespace) {
        return $this->execute("DELETE FROM kv_store WHERE namespace = ?", [$namespace]);
    }

    // 设置管理
    public function setSetting($key, $value) {
        $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value;
        $this->execute(
            "INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now','localtime'))",
            [$key, $value]
        );
        return $this->execute(
            "UPDATE settings SET value = ?, updated_at = datetime('now','localtime') WHERE key = ?",
            [$value, $key]
        );
    }

    public function getSetting($key, $default = null) {
        $row = $this->fetch("SELECT value FROM settings WHERE key = ?", [$key]);
        if ($row === false) return $default;
        $decoded = json_decode($row['value'], true);
        return $decoded !== null ? $decoded : $row['value'];
    }

    public function getAllSettings() {
        $rows = $this->fetchAll("SELECT key, value FROM settings");
        $result = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row['value'], true);
            $result[$row['key']] = $decoded !== null ? $decoded : $row['value'];
        }
        return $result;
    }
}

// 全局辅助函数
function db() {
    return Database::getInstance();
}
