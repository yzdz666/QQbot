<?php
// Router script for PHP built-in server
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

$uri = str_replace('\\', '/', $uri);
$uri = preg_replace('#/{2,}#', '/', $uri);

if (strpos($uri, '..') !== false) {
    http_response_code(404);
    echo '404 Not Found';
    return true;
}

// If the file exists, serve it directly
$filePath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($filePath) && is_file($filePath)) {
    return false;
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
    $phpFile = $adminFile . '.php';
    $realPath = realpath($phpFile);
    $projectRoot = realpath(__DIR__);
    if ($realPath !== false && strpos($realPath, $projectRoot) === 0) {
        if (file_exists($phpFile)) {
            require $phpFile;
            return true;
        }
    }
}

return false;
