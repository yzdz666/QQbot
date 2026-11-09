<?php
/**
 * 官鸡插件市场 - 插件下载
 * GET id, version(可选), format=php|zip
 */
require_once __DIR__ . '/lib/bootstrap.php';
market_security_headers(false);

if (!market_rate_limit('download:' . market_client_ip(), 60, 60)) {
    http_response_code(429);
    exit('下载过于频繁，请稍后再试');
}

$id = $_GET['id'] ?? ($_GET['slug'] ?? '');
$format = strtolower((string)($_GET['format'] ?? 'php'));
if (!in_array($format, ['php', 'zip'], true)) $format = 'php';

$plugin = MarketDB::plugin_with_latest($id);
if (!$plugin || $plugin['status'] !== 'approved' || empty($plugin['latest'])) {
    http_response_code(404);
    exit('插件不存在或未通过审核');
}

$pdo = MarketDB::pdo();
$version = trim((string)($_GET['version'] ?? ''));
if ($version !== '') {
    $stmt = $pdo->prepare("SELECT * FROM versions WHERE plugin_id = ? AND version = ? AND status = 'approved'");
    $stmt->execute([$plugin['id'], $version]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM versions WHERE plugin_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$plugin['id']]);
}
$v = $stmt->fetch();
if (!$v) {
    http_response_code(404);
    exit('指定版本不存在或未通过审核');
}

$path = realpath($v['file_path']);
$storage = realpath(MARKET_STORAGE_DIR);
if ($path === false || $storage === false || strpos($path, $storage . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
    http_response_code(500);
    exit('插件文件不可用');
}

$code = (string)file_get_contents($path);
if (hash_file('sha256', $path) !== $v['sha256']) {
    http_response_code(500);
    exit('插件文件校验失败，已阻止下载');
}

// 计数（同一 IP 对同一版本 10 分钟内只计一次，避免刷量）
try {
    $dedupKey = 'dl:' . market_client_ip() . ':' . $v['id'];
    if (market_rate_limit($dedupKey, 1, 600)) {
        $pdo->prepare("UPDATE plugins SET downloads = downloads + 1 WHERE id = ?")->execute([$plugin['id']]);
        $pdo->prepare("UPDATE versions SET downloads = downloads + 1 WHERE id = ?")->execute([$v['id']]);
    }
} catch (Throwable $e) {
    // 计数失败不影响下载
}

$safeName = market_safe_plugin_name($plugin['name']);
if ($safeName === '') $safeName = 'plugin-' . $plugin['id'];
$fileBase = $safeName . '-' . preg_replace('/[^0-9A-Za-z\.\-_]/', '', $v['version']);
$asciiFallback = 'plugin-' . $plugin['id'] . '-' . preg_replace('/[^0-9A-Za-z\.\-_]/', '', $v['version']);

while (ob_get_level() > 0) { ob_end_clean(); }

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Plugin-Id: ' . $plugin['id']);
header('X-Plugin-Slug: ' . $plugin['slug']);
header('X-Plugin-Version: ' . $v['version']);
header('X-Plugin-Sha256: ' . $v['sha256']);
header('X-Plugin-Name: ' . rawurlencode($plugin['name']));

if ($format === 'php') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $asciiFallback . '.php"; filename*=UTF-8\'\'' . rawurlencode($fileBase . '.php'));
    header('Content-Length: ' . strlen($code));
    echo $code;
    exit;
}

// ZIP 包
if (!class_exists('ZipArchive')) {
    http_response_code(501);
    header('Content-Type: text/plain; charset=utf-8');
    exit('服务器未安装 ZipArchive 扩展，请使用 format=php 下载单文件插件。');
}

$tmp = tempnam(sys_get_temp_dir(), 'market_zip_');
@unlink($tmp);
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
    http_response_code(500);
    exit('打包失败');
}
$zip->addFromString('plugin.php', $code);
$manifest = [
    'name' => $plugin['name'],
    'slug' => $plugin['slug'],
    'version' => $v['version'],
    'author' => $plugin['author'],
    'description' => $plugin['summary'],
    'license' => $plugin['license'],
    'sha256' => $v['sha256'],
    'homepage' => $plugin['homepage'],
    'published_at' => $v['approved_at'] ?: $v['created_at'],
];
$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$zip->addFromString('README.md', (string)$plugin['description']);
$zip->close();

$size = filesize($tmp);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $asciiFallback . '.zip"; filename*=UTF-8\'\'' . rawurlencode($fileBase . '.zip'));
header('Content-Length: ' . $size);
readfile($tmp);
@unlink($tmp);
exit;
