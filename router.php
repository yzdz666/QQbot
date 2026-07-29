<?php
// Router script for PHP built-in server
// Handles URL-encoded Chinese characters properly

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

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
