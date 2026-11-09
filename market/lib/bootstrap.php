<?php
/**
 * 官鸡插件市场 - 引导文件
 * 所有页面统一从这里加载，保证配置、数据库、安全策略一致。
 */

if (!defined('MARKET_ROOT')) {
    define('MARKET_ROOT', dirname(__DIR__));
}

// 生产环境不向浏览器输出错误细节，统一写入日志
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', MARKET_ROOT . '/data/php_error.log');
error_reporting(E_ALL);

date_default_timezone_set('Asia/Shanghai');

$configFile = MARKET_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('缺少 market/config.php');
}
require_once $configFile;

require_once MARKET_ROOT . '/lib/security.php';
require_once MARKET_ROOT . '/lib/db.php';
require_once MARKET_ROOT . '/lib/auth.php';
require_once MARKET_ROOT . '/lib/repo.php';
require_once MARKET_ROOT . '/lib/Parsedown.php';

// 触发数据库初始化（建表 / 首次写入管理员 / 导入示例插件）
MarketDB::pdo();

// 维护模式（后台不受影响）
if (market_setting('maintenance', '0') === '1') {
    $self = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($self, '/admin/') === false) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>维护中</title>'
           . '<div style="font-family:system-ui;padding:80px;text-align:center;color:#334">'
           . '<h1>插件市场维护中</h1><p>请稍后再访问。</p></div>';
        exit;
    }
}

/** Markdown 渲染（安全模式，禁止原始 HTML） */
function market_markdown($text) {
    static $parser = null;
    if ($parser === null) {
        $parser = new Parsedown();
        $parser->setSafeMode(true);
        $parser->setBreaksEnabled(true);
    }
    return $parser->text((string)$text);
}

/** 设置一次性提示 */
function market_flash($type, $message) {
    market_session_start();
    $_SESSION['market_flash'] = ['type' => $type, 'message' => $message];
}

/** 读取并清除一次性提示 */
function market_flash_get() {
    market_session_start();
    if (empty($_SESSION['market_flash'])) return null;
    $flash = $_SESSION['market_flash'];
    unset($_SESSION['market_flash']);
    return $flash;
}

/** 格式化文件大小 */
function market_size($bytes) {
    $bytes = (int)$bytes;
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

/** 相对时间 */
function market_time_ago($time) {
    if (!$time) return '';
    $ts = strtotime($time);
    if (!$ts) return $time;
    $diff = time() - $ts;
    if ($diff < 60) return '刚刚';
    if ($diff < 3600) return floor($diff / 60) . ' 分钟前';
    if ($diff < 86400) return floor($diff / 3600) . ' 小时前';
    if ($diff < 2592000) return floor($diff / 86400) . ' 天前';
    return date('Y-m-d', $ts);
}

/** 分类列表 */
function market_categories() {
    $rows = MarketDB::pdo()->query(
        "SELECT p.category, COUNT(*) AS c FROM plugins p WHERE " . MarketDB::public_where() . " GROUP BY category ORDER BY c DESC"
    )->fetchAll();
    return $rows;
}

/** 站点统计 */
function market_stats() {
    $pdo = MarketDB::pdo();
    $plugins = (int)$pdo->query("SELECT COUNT(*) FROM plugins p WHERE " . MarketDB::public_where())->fetchColumn();
    $downloads = (int)$pdo->query("SELECT COALESCE(SUM(downloads),0) FROM plugins")->fetchColumn();
    $authors = (int)$pdo->query("SELECT COUNT(DISTINCT p.author) FROM plugins p WHERE " . MarketDB::public_where())->fetchColumn();
    return ['plugins' => $plugins, 'downloads' => $downloads, 'authors' => $authors];
}
