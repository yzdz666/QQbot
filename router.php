<?php
// Router script for PHP built-in server
// Handles URL-encoded Chinese characters properly

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// [安全加固] 屏蔽敏感文件/目录, 防止 SQLite 数据库(bot.db 含管理员密码哈希/机器人AppSecret/会话token)、
// 日志、PID 文件被匿名下载。对应 nginx 部署请参照 文档.md 中 "data 目录防护" 配置 location 块。
if (preg_match('#^/data/|^/market/(data|storage|seed|lib)/|^/market/config\.php|/function/(Mail|qrcode|GD|Parsedown)|\.(db|sqlite|log|pid|jsonl)$#i', $uri)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    exit('{"error":"forbidden"}');
}

// If the file exists, serve it directly
$filePath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($filePath) && is_file($filePath)) {
    return false; // Let PHP serve the file
}

// For directory requests, try index.php
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    return true;
}

// For admin routes
if (strpos($uri, '/admin/') === 0) {
    $adminFile = __DIR__ . $uri;
    if (file_exists($adminFile) && is_file($adminFile)) {
        return false;
    }
    // Try with .php extension
    if (file_exists($adminFile . '.php')) {
        require $adminFile . '.php';
        return true;
    }
}

// Default: let the original file handling work
return false;
