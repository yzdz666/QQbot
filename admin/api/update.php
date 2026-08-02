<?php
header('Content-Type: application/json; charset=utf-8');

// 检查登录态
if (!isset($_COOKIE['admin_token'])) {
    echo json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$type = $_REQUEST['type'] ?? '';

// ==================== 配置区域 ====================
// GitHub仓库信息
define('REPO_OWNER', 'yzdz666');
define('REPO_NAME', 'QQ-');
define('REPO_BRANCH', 'main');

// ==================== 路径自动检测 ====================
/**
 * 自动检测项目根目录（支持二级目录）
 */
function detectRootPath() {
    // 方法1：从当前文件路径向上查找包含 main.json 的目录
    $currentDir = __DIR__;
    $maxLevels = 5;
    
    for ($i = 0; $i < $maxLevels; $i++) {
        // 检查是否存在 main.json（项目根目录标志）
        if (is_file($currentDir . '/main.json')) {
            return $currentDir;
        }
        // 向上走一级
        $parentDir = dirname($currentDir);
        if ($parentDir === $currentDir) {
            break;
        }
        $currentDir = $parentDir;
    }
    
    // 方法2：如果当前文件在 api 目录下，根目录是上级
    if (basename(__DIR__) === 'api') {
        $parent = dirname(__DIR__);
        if (is_file($parent . '/main.json')) {
            return $parent;
        }
    }
    
    // 方法3：使用 dirname(__DIR__, 2) 作为后备
    $fallback = dirname(__DIR__, 2);
    if (is_file($fallback . '/main.json')) {
        return $fallback;
    }
    
    // 方法4：如果都找不到，使用当前目录的上级
    return dirname(__DIR__, 2);
}

define('ROOT_PATH', detectRootPath());

// ==================== 辅助函数 ====================

/**
 * 获取最新版本信息
 */
function getLatestVersion() {
    $url = "https://api.github.com/repos/" . REPO_OWNER . "/" . REPO_NAME . "/releases/latest";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return getLatestCommit();
    }
    
    $data = json_decode($resp, true);
    if (!$data || !isset($data['tag_name'])) {
        return null;
    }
    
    return [
        'version' => ltrim($data['tag_name'], 'v'),
        'tag_name' => $data['tag_name'],
        'name' => $data['name'] ?? '新版本发布',
        'body' => $data['body'] ?? '',
        'published_at' => $data['published_at'] ?? '',
        'html_url' => $data['html_url'] ?? '',
        'zipball_url' => $data['zipball_url'] ?? '',
        'assets' => $data['assets'] ?? []
    ];
}

/**
 * 获取所有版本列表（支持历史版本选择）
 */
function getAllVersions() {
    $url = "https://api.github.com/repos/" . REPO_OWNER . "/" . REPO_NAME . "/releases";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    
    $data = json_decode($resp, true);
    if (!$data || !is_array($data)) {
        return null;
    }
    
    $versions = [];
    foreach ($data as $release) {
        $versions[] = [
            'version' => ltrim($release['tag_name'] ?? '', 'v'),
            'tag_name' => $release['tag_name'] ?? '',
            'name' => $release['name'] ?? '版本发布',
            'body' => $release['body'] ?? '',
            'published_at' => $release['published_at'] ?? '',
            'html_url' => $release['html_url'] ?? '',
            'zipball_url' => $release['zipball_url'] ?? '',
            'prerelease' => $release['prerelease'] ?? false
        ];
    }
    
    return $versions;
}

/**
 * 获取最新提交（当release不可用时）
 */
function getLatestCommit() {
    $url = "https://api.github.com/repos/" . REPO_OWNER . "/" . REPO_NAME . "/commits/" . REPO_BRANCH;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) return null;
    
    $data = json_decode($resp, true);
    if (!$data) return null;
    
    $sha = substr($data['sha'] ?? '', 0, 7);
    return [
        'version' => $sha,
        'tag_name' => $sha,
        'name' => '最新提交: ' . $sha,
        'body' => $data['commit']['message'] ?? '',
        'published_at' => $data['commit']['committer']['date'] ?? '',
        'html_url' => "https://github.com/" . REPO_OWNER . "/" . REPO_NAME . "/commit/" . $data['sha'],
        'zipball_url' => "https://github.com/" . REPO_OWNER . "/" . REPO_NAME . "/archive/" . REPO_BRANCH . ".zip",
        'assets' => []
    ];
}

/**
 * 获取指定版本的下载链接
 */
function getVersionDownloadUrl($version) {
    // 尝试从 Releases 获取
    $url = "https://api.github.com/repos/" . REPO_OWNER . "/" . REPO_NAME . "/releases/tags/v" . $version;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($resp, true);
        if ($data && isset($data['zipball_url'])) {
            return $data['zipball_url'];
        }
    }
    
    // 尝试直接使用分支下载
    return "https://github.com/" . REPO_OWNER . "/" . REPO_NAME . "/archive/refs/tags/v" . $version . ".zip";
}

/**
 * 获取当前版本
 */
function getCurrentVersion() {
    $versionFile = ROOT_PATH . '/version.php';
    if (is_file($versionFile)) {
        $content = file_get_contents($versionFile);
        if (preg_match("/'version'\s*=>\s*'([^']+)'/", $content, $m)) {
            return $m[1];
        }
        if (preg_match('/\$version\s*=\s*"([^"]+)"/', $content, $m)) {
            return $m[1];
        }
    }
    return '1.0.0';
}

/**
 * 递归删除目录（增强版）
 */
function removeDirectory($dir) {
    if (!is_dir($dir)) {
        return true;
    }
    
    $files = @scandir($dir);
    if ($files === false) {
        return false;
    }
    
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') {
            continue;
        }
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            if (!removeDirectory($path)) {
                return false;
            }
        } else {
            if (!@unlink($path)) {
                return false;
            }
        }
    }
    
    return @rmdir($dir);
}

/**
 * 递归复制目录
 */
function copyDirectory($src, $dst) {
    if (!is_dir($src)) return false;
    if (!is_dir($dst)) {
        if (!@mkdir($dst, 0755, true)) {
            return false;
        }
    }
    
    $files = @scandir($src);
    if ($files === false) {
        return false;
    }
    
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') {
            continue;
        }
        $srcPath = $src . '/' . $file;
        $dstPath = $dst . '/' . $file;
        if (is_dir($srcPath)) {
            if (!copyDirectory($srcPath, $dstPath)) {
                return false;
            }
        } else {
            if (!@copy($srcPath, $dstPath)) {
                return false;
            }
        }
    }
    return true;
}

/**
 * 获取备份目录列表
 */
function getBackupList() {
    $pattern = ROOT_PATH . '/backup_*';
    $backups = @glob($pattern, GLOB_ONLYDIR);
    if ($backups === false) {
        return [];
    }
    
    // 按修改时间排序（最新的在前面）
    usort($backups, function($a, $b) {
        $mtimeA = @filemtime($a);
        $mtimeB = @filemtime($b);
        if ($mtimeA === false && $mtimeB === false) return 0;
        if ($mtimeA === false) return 1;
        if ($mtimeB === false) return -1;
        return $mtimeB - $mtimeA;
    });
    
    return $backups;
}

/**
 * 创建备份
 */
function createBackup() {
    $backupDir = ROOT_PATH . '/backup_' . date('Ymd_His');
    if (!@mkdir($backupDir, 0755, true)) {
        return false;
    }
    
    // 备份关键目录
    $dirsToBackup = ['function', 'plugin', 'admin'];
    foreach ($dirsToBackup as $dir) {
        $src = ROOT_PATH . '/' . $dir;
        $dst = $backupDir . '/' . $dir;
        if (is_dir($src)) {
            copyDirectory($src, $dst);
        }
    }
    
    // 备份关键文件
    $filesToBackup = ['main.json', 'config.json', 'version.php', 'index.php', 'main.php', 'bot.php', 'function.php'];
    foreach ($filesToBackup as $file) {
        $src = ROOT_PATH . '/' . $file;
        $dst = $backupDir . '/' . $file;
        if (is_file($src)) {
            @copy($src, $dst);
        }
    }
    
    return $backupDir;
}

/**
 * 下载并解压更新
 */
function downloadAndExtract($url) {
    $tempDir = ROOT_PATH . '/temp_update_' . time();
    if (!@mkdir($tempDir, 0755, true)) {
        return ['error' => '无法创建临时目录'];
    }
    
    $zipFile = $tempDir . '/update.zip';
    
    // 下载
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$data) {
        removeDirectory($tempDir);
        return ['error' => '下载失败 (HTTP ' . $httpCode . ')'];
    }
    
    if (file_put_contents($zipFile, $data) === false) {
        removeDirectory($tempDir);
        return ['error' => '写入ZIP文件失败'];
    }
    
    // 解压
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        removeDirectory($tempDir);
        return ['error' => '解压失败，ZIP文件可能损坏'];
    }
    
    $extractDir = $tempDir . '/extract';
    if (!@mkdir($extractDir, 0755, true)) {
        $zip->close();
        removeDirectory($tempDir);
        return ['error' => '无法创建解压目录'];
    }
    
    $zip->extractTo($extractDir);
    $zip->close();
    @unlink($zipFile);
    
    // 查找实际的根目录（GitHub zip可能包含一层目录）
    $subDirs = @scandir($extractDir);
    $rootDir = $extractDir;
    if ($subDirs !== false) {
        foreach ($subDirs as $sub) {
            if ($sub == '.' || $sub == '..') continue;
            if (is_dir($extractDir . '/' . $sub)) {
                if (is_file($extractDir . '/' . $sub . '/index.php') || 
                    is_file($extractDir . '/' . $sub . '/main.php')) {
                    $rootDir = $extractDir . '/' . $sub;
                    break;
                }
            }
        }
    }
    
    return ['dir' => $rootDir, 'tempDir' => $tempDir];
}

/**
 * 应用更新
 */
function applyUpdate($sourceDir) {
    $errors = [];
    
    // 需要更新的目录
    $dirsToUpdate = ['api', 'function', 'plugin', 'assets'];
    foreach ($dirsToUpdate as $dir) {
        $src = $sourceDir . '/' . $dir;
        $dst = ROOT_PATH . '/' . $dir;
        if (is_dir($src)) {
            if (is_dir($dst)) {
                if (!removeDirectory($dst)) {
                    $errors[] = "无法删除目录: {$dir}";
                    continue;
                }
            }
            if (!copyDirectory($src, $dst)) {
                $errors[] = "无法复制目录: {$dir}";
            }
        }
    }
    
    // 更新根目录文件
    $filesToUpdate = ['index.php', 'main.php', 'bot.php', 'function.php'];
    foreach ($filesToUpdate as $file) {
        $src = $sourceDir . '/' . $file;
        $dst = ROOT_PATH . '/' . $file;
        if (is_file($src)) {
            if (!@copy($src, $dst)) {
                $errors[] = "无法复制文件: {$file}";
            }
        }
    }
    
    // 更新version.php
    $srcVersion = $sourceDir . '/version.php';
    $dstVersion = ROOT_PATH . '/version.php';
    if (is_file($srcVersion)) {
        if (!@copy($srcVersion, $dstVersion)) {
            $errors[] = "无法复制文件: version.php";
        }
    }
    
    return $errors;
}

// ==================== API路由 ====================

switch ($type) {
    case 'check':
        // 检查更新
        $latest = getLatestVersion();
        $current = getCurrentVersion();
        
        if (!$latest) {
            echo json_encode([
                'code' => 500,
                'msg' => '无法获取更新信息，请检查网络或GitHub仓库配置'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $hasUpdate = version_compare($current, $latest['version'], '<');
        
        echo json_encode([
            'code' => 200,
            'data' => [
                'current_version' => $current,
                'latest_version' => $latest['version'],
                'has_update' => $hasUpdate,
                'release_name' => $latest['name'],
                'release_body' => $latest['body'],
                'published_at' => $latest['published_at'],
                'html_url' => $latest['html_url'],
                'zipball_url' => $latest['zipball_url']
            ]
        ], JSON_UNESCAPED_UNICODE);
        break;
        
    case 'versions':
        // 获取所有版本列表
        $versions = getAllVersions();
        if (!$versions) {
            echo json_encode([
                'code' => 500,
                'msg' => '无法获取版本列表'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode([
            'code' => 200,
            'data' => $versions
        ], JSON_UNESCAPED_UNICODE);
        break;
        
    case 'download':
        // 下载更新
        $url = $_REQUEST['url'] ?? '';
        $version = $_REQUEST['version'] ?? '';
        
        if (empty($url) && !empty($version)) {
            $url = getVersionDownloadUrl($version);
        }
        
        if (empty($url)) {
            echo json_encode(['code' => 400, 'msg' => '缺少下载链接或版本号'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 创建备份
        $backupDir = createBackup();
        if ($backupDir === false) {
            echo json_encode(['code' => 500, 'msg' => '创建备份失败，请检查目录权限'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 下载并解压
        $result = downloadAndExtract($url);
        if (isset($result['error'])) {
            echo json_encode(['code' => 500, 'msg' => $result['error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        echo json_encode([
            'code' => 200,
            'msg' => '下载成功',
            'data' => [
                'temp_dir' => $result['tempDir'],
                'source_dir' => $result['dir'],
                'backup_dir' => $backupDir
            ]
        ], JSON_UNESCAPED_UNICODE);
        break;
        
    case 'apply':
        // 应用更新
        $sourceDir = $_REQUEST['source_dir'] ?? '';
        if (empty($sourceDir)) {
            echo json_encode(['code' => 400, 'msg' => '缺少源目录'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if (!is_dir($sourceDir)) {
            echo json_encode(['code' => 400, 'msg' => '源目录不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $errors = applyUpdate($sourceDir);
        
        // 清理临时目录
        $tempDir = dirname($sourceDir);
        if (is_dir($tempDir) && strpos($tempDir, 'temp_update_') !== false) {
            removeDirectory($tempDir);
        }
        
        if (empty($errors)) {
            echo json_encode([
                'code' => 200,
                'msg' => '更新成功！系统已升级到最新版本。'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'code' => 500,
                'msg' => '更新完成，但部分文件可能未更新',
                'errors' => $errors
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'rollback':
        // 回滚到备份
        $backupDir = $_REQUEST['backup_dir'] ?? '';
        if (empty($backupDir)) {
            // 列出所有备份
            $backups = getBackupList();
            $backupList = [];
            foreach ($backups as $backup) {
                $backupList[] = basename($backup);
            }
            echo json_encode([
                'code' => 200,
                'data' => $backupList
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $fullPath = ROOT_PATH . '/' . $backupDir;
        if (!is_dir($fullPath)) {
            echo json_encode(['code' => 400, 'msg' => '备份目录不存在'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // 恢复
        $dirsToRestore = ['api', 'function', 'plugin', 'assets'];
        foreach ($dirsToRestore as $dir) {
            $src = $fullPath . '/' . $dir;
            $dst = ROOT_PATH . '/' . $dir;
            if (is_dir($src)) {
                if (is_dir($dst)) removeDirectory($dst);
                copyDirectory($src, $dst);
            }
        }
        
        $filesToRestore = ['main.json', 'config.json', 'version.php', 'index.php', 'main.php', 'bot.php', 'function.php'];
        foreach ($filesToRestore as $file) {
            $src = $fullPath . '/' . $file;
            $dst = ROOT_PATH . '/' . $file;
            if (is_file($src)) {
                @copy($src, $dst);
            }
        }
        
        echo json_encode([
            'code' => 200,
            'msg' => '回滚成功！'
        ], JSON_UNESCAPED_UNICODE);
        break;
        
    case 'clean_backups':
        // 清理旧备份（保留最近3个）
        $backups = getBackupList();
        
        if (empty($backups)) {
            echo json_encode([
                'code' => 200,
                'msg' => '没有可清理的备份'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $keep = 3;  // 保留最近3个备份
        $deleted = 0;
        $errors = [];
        
        // 从第4个开始删除（索引3开始）
        for ($i = $keep; $i < count($backups); $i++) {
            if (removeDirectory($backups[$i])) {
                $deleted++;
            } else {
                $errors[] = basename($backups[$i]);
            }
        }
        
        if (empty($errors)) {
            $msg = $deleted > 0 ? "清理完成，删除了 {$deleted} 个旧备份（保留最近{$keep}个）" : "没有需要清理的备份（当前共 " . count($backups) . " 个，保留最近{$keep}个）";
            echo json_encode([
                'code' => 200,
                'msg' => $msg,
                'deleted' => $deleted,
                'total' => count($backups),
                'kept' => min($keep, count($backups))
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'code' => 500,
                'msg' => "部分备份清理失败: " . implode(', ', $errors),
                'deleted' => $deleted
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'delete_all_backups':
        // 删除所有备份（危险操作）
        $backups = getBackupList();
        
        if (empty($backups)) {
            echo json_encode([
                'code' => 200,
                'msg' => '没有可删除的备份'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $deleted = 0;
        $errors = [];
        foreach ($backups as $backup) {
            if (removeDirectory($backup)) {
                $deleted++;
            } else {
                $errors[] = basename($backup);
            }
        }
        
        if (empty($errors)) {
            echo json_encode([
                'code' => 200,
                'msg' => "已删除全部 {$deleted} 个备份"
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'code' => 500,
                'msg' => "部分备份删除失败: " . implode(', ', $errors),
                'deleted' => $deleted
            ], JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'get_backup_info':
        // 获取备份信息
        $backups = getBackupList();
        $info = [];
        foreach ($backups as $backup) {
            $info[] = [
                'name' => basename($backup),
                'path' => $backup,
                'size' => getDirectorySize($backup),
                'mtime' => @filemtime($backup)
            ];
        }
        echo json_encode([
            'code' => 200,
            'data' => [
                'count' => count($info),
                'backups' => $info,
                'root_path' => ROOT_PATH
            ]
        ], JSON_UNESCAPED_UNICODE);
        break;
        
    default:
        echo json_encode([
            'code' => 400,
            'msg' => '无效的请求类型'
        ], JSON_UNESCAPED_UNICODE);
}

/**
 * 获取目录大小（辅助函数）
 */
function getDirectorySize($dir) {
    $size = 0;
    if (!is_dir($dir)) return $size;
    $files = @scandir($dir);
    if ($files === false) return $size;
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') continue;
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            $size += getDirectorySize($path);
        } else {
            $size += @filesize($path);
        }
    }
    return $size;
}