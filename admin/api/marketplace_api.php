<?php
/**
 * 插件市场 API - 基于GitHub的插件安装/更新/卸载
 * 支持 list/installed/install/update/uninstall/search
 */
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ERROR);
ini_set('display_errors', 0);

require_once dirname(__DIR__, 2) . '/function.php';
require_once dirname(__DIR__, 2) . '/auth.php';

Auth::init();
if (!Auth::check()) {
    echo json_encode(["code" => 401, "msg" => "未登录"], JSON_UNESCAPED_UNICODE);
    exit;
}

$type = $_REQUEST["type"] ?? "";
$pluginName = $_REQUEST["plugin_name"] ?? "";

define('PLUGIN_DIR', dirname(__DIR__, 2) . '/plugin/');
define('MARKETPLACE_FILE', dirname(__DIR__, 2) . '/marketplace.json');
define('TEMP_DIR', sys_get_temp_dir() . '/yuexia_plugin_');

ensureMarketTable();

switch ($type) {
    case "list":
        handleList();
        break;
    case "installed":
        handleInstalled();
        break;
    case "install":
        handleInstall($pluginName);
        break;
    case "update":
        handleUpdate($pluginName);
        break;
    case "uninstall":
        handleUninstall($pluginName);
        break;
    case "search":
        handleSearch();
        break;
    default:
        echo json_encode(["code" => 400, "msg" => "无效操作"], JSON_UNESCAPED_UNICODE);
}

function ensureMarketTable() {
    try {
        db()->execute("CREATE TABLE IF NOT EXISTS plugin_market (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            plugin_name TEXT UNIQUE NOT NULL,
            installed_version TEXT,
            repository_url TEXT,
            installed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status TEXT DEFAULT 'active'
        )");
        db()->execute("CREATE TABLE IF NOT EXISTS github_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            github_id INTEGER UNIQUE,
            login TEXT NOT NULL,
            avatar_url TEXT,
            name TEXT,
            email TEXT,
            access_token TEXT,
            token_type TEXT DEFAULT 'token',
            scope TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        db()->execute("CREATE TABLE IF NOT EXISTS plugin_market_settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )");
    } catch (Exception $e) {
        wlog("创建插件市场表失败: " . $e->getMessage(), 'system');
    }
}

function handleList() {
    try {
        $remotePlugins = [];
        if (file_exists(MARKETPLACE_FILE)) {
            $content = file_get_contents(MARKETPLACE_FILE);
            $data = json_decode($content, true);
            $remotePlugins = $data['plugins'] ?? [];
        }

        $installed = getInstalledPluginsInfo();

        $result = [];
        foreach ($remotePlugins as $plugin) {
            $name = $plugin['name'];
            $installedInfo = $installed[$name] ?? null;
            $plugin['installed'] = $installedInfo !== null;
            $plugin['installed_version'] = $installedInfo ? $installedInfo['version'] : null;
            $plugin['has_update'] = $installedInfo && isset($plugin['version']) && version_compare($installedInfo['version'], $plugin['version'], '<');
            $plugin['local_path'] = $installedInfo ? $installedInfo['path'] : null;
            if ($installedInfo) {
                $plugin['installed_at'] = $installedInfo['installed_at'];
                $plugin['updated_at'] = $installedInfo['updated_at'];
            }
            $result[] = $plugin;
        }

        echo json_encode(["code" => 200, "plugins" => $result], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(["code" => 500, "msg" => "获取列表失败: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

function handleInstalled() {
    try {
        $plugins = scanLocalPlugins();

        $records = db()->fetchAll("SELECT * FROM plugin_market");
        $marketMap = [];
        foreach ($records as $r) {
            $marketMap[$r['plugin_name']] = $r;
        }

        foreach ($plugins as &$p) {
            $name = $p['name'];
            if (isset($marketMap[$name])) {
                $p['repository_url'] = $marketMap[$name]['repository_url'];
                $p['installed_version'] = $marketMap[$name]['installed_version'];
                $p['installed_at'] = $marketMap[$name]['installed_at'];
                $p['updated_at'] = $marketMap[$name]['updated_at'];
                $p['market_installed'] = true;
            } else {
                $p['market_installed'] = false;
            }
        }
        unset($p);

        echo json_encode(["code" => 200, "plugins" => $plugins], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(["code" => 500, "msg" => "获取已安装插件失败: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

function handleInstall($pluginName) {
    if (empty($pluginName)) {
        echo json_encode(["code" => 400, "msg" => "缺少插件名称"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pluginInfo = findPluginInMarketplace($pluginName);
        if (!$pluginInfo) {
            echo json_encode(["code" => 404, "msg" => "未找到插件: " . $pluginName], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $repoUrl = $pluginInfo['repository']['url'] ?? '';
        if (empty($repoUrl)) {
            echo json_encode(["code" => 400, "msg" => "插件仓库地址为空"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $result = installFromGithub($repoUrl, $pluginName);

        if ($result['success']) {
            $version = $pluginInfo['version'] ?? '1.0.0';
            db()->execute(
                "INSERT OR REPLACE INTO plugin_market (plugin_name, installed_version, repository_url, updated_at) VALUES (?, ?, ?, datetime('now','localtime'))",
                [$pluginName, $version, $repoUrl]
            );
            wlog("插件安装成功: {$pluginName} v{$version}", 'system');
            echo json_encode(["code" => 200, "msg" => "安装成功", "version" => $version], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(["code" => 500, "msg" => "安装失败: " . $result['error']], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        echo json_encode(["code" => 500, "msg" => "安装异常: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

function handleUpdate($pluginName) {
    if (empty($pluginName)) {
        echo json_encode(["code" => 400, "msg" => "缺少插件名称"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $record = db()->fetch("SELECT * FROM plugin_market WHERE plugin_name = ?", [$pluginName]);
        if (!$record) {
            echo json_encode(["code" => 404, "msg" => "插件未安装或不是通过市场安装的"], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $repoUrl = $record['repository_url'];
        $result = installFromGithub($repoUrl, $pluginName);

        if ($result['success']) {
            $pluginInfo = findPluginInMarketplace($pluginName);
            $version = $pluginInfo['version'] ?? $record['installed_version'];
            db()->execute(
                "UPDATE plugin_market SET installed_version = ?, updated_at = datetime('now','localtime') WHERE plugin_name = ?",
                [$version, $pluginName]
            );
            wlog("插件更新成功: {$pluginName} v{$version}", 'system');
            echo json_encode(["code" => 200, "msg" => "更新成功", "version" => $version], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(["code" => 500, "msg" => "更新失败: " . $result['error']], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        echo json_encode(["code" => 500, "msg" => "更新异常: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

function handleUninstall($pluginName) {
    if (empty($pluginName)) {
        echo json_encode(["code" => 400, "msg" => "缺少插件名称"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pluginFile = PLUGIN_DIR . $pluginName . '.php';
        $pluginDir = PLUGIN_DIR . $pluginName . '/';

        $deleted = false;
        if (file_exists($pluginFile)) {
            if (unlink($pluginFile)) {
                $deleted = true;
            }
        }
        if (is_dir($pluginDir)) {
            removeDirRecursive($pluginDir);
            $deleted = true;
        }

        if ($deleted) {
            db()->execute("DELETE FROM plugin_market WHERE plugin_name = ?", [$pluginName]);
            db()->execute("DELETE FROM plugin_status WHERE plugin_name = ?", [$pluginName]);
            wlog("插件卸载成功: {$pluginName}", 'system');
            echo json_encode(["code" => 200, "msg" => "卸载成功"], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(["code" => 404, "msg" => "插件文件不存在"], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        echo json_encode(["code" => 500, "msg" => "卸载异常: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

function handleSearch() {
    try {
        $q = $_REQUEST["q"] ?? "";

        $remotePlugins = [];
        if (file_exists(MARKETPLACE_FILE)) {
            $content = file_get_contents(MARKETPLACE_FILE);
            $data = json_decode($content, true);
            $allPlugins = $data['plugins'] ?? [];
            if (!empty($q)) {
                foreach ($allPlugins as $p) {
                    if (stripos($p['name'], $q) !== false ||
                        stripos($p['title'] ?? '', $q) !== false ||
                        stripos($p['description'] ?? '', $q) !== false) {
                        $remotePlugins[] = $p;
                    }
                }
            } else {
                $remotePlugins = $allPlugins;
            }
        }

        echo json_encode(["code" => 200, "plugins" => $remotePlugins], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(["code" => 500, "msg" => "搜索失败: " . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}

function installFromGithub($repoUrl, $pluginName) {
    $repoInfo = parseGithubUrl($repoUrl);
    if (!$repoInfo) {
        return ['success' => false, 'error' => '无法解析GitHub仓库地址'];
    }

    $tempDir = TEMP_DIR . $pluginName . '_' . time();
    @mkdir($tempDir, 0755, true);

    $gitResult = tryGitClone($repoInfo, $tempDir);
    if (!$gitResult['success']) {
        $zipResult = tryDownloadZip($repoInfo, $tempDir);
        if (!$zipResult['success']) {
            removeDirRecursive($tempDir);
            return ['success' => false, 'error' => '下载失败: ' . ($zipResult['error'] ?? '未知错误')];
        }
    }

    $copyResult = copyPluginFiles($tempDir, $pluginName);
    if (!$copyResult['success']) {
        removeDirRecursive($tempDir);
        return $copyResult;
    }

    removeDirRecursive($tempDir);

    return ['success' => true];
}

function parseGithubUrl($url) {
    if (preg_match('/github\.com[:]([^\/]+)\/([^\/]+?)(\.git)?$/', $url, $m)) {
        return ['owner' => $m[1], 'repo' => preg_replace('/\.git$/', '', $m[2])];
    }
    if (preg_match('/github\.com\/([^\/]+)\/([^\/]+?)(\.git)?$/', $url, $m)) {
        return ['owner' => $m[1], 'repo' => preg_replace('/\.git$/', '', $m[2])];
    }
    if (preg_match('/^([^\/]+)\/([^\/]+)$/', $url, $m)) {
        return ['owner' => $m[1], 'repo' => preg_replace('/\.git$/', '', $m[2])];
    }
    return null;
}

function tryGitClone($repoInfo, $targetDir) {
    $repoUrl = "https://github.com/{$repoInfo['owner']}/{$repoInfo['repo']}.git";
    $cmd = "git clone --depth 1 " . escapeshellarg($repoUrl) . " " . escapeshellarg($targetDir) . " 2>&1";
    $output = [];
    $returnVar = 0;
    exec($cmd, $output, $returnVar);

    if ($returnVar === 0) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => implode("\n", $output)];
}

function tryDownloadZip($repoInfo, $targetDir) {
    $branches = ['main', 'master'];
    $zipContent = false;

    foreach ($branches as $branch) {
        $zipUrl = "https://github.com/{$repoInfo['owner']}/{$repoInfo['repo']}/archive/refs/heads/{$branch}.zip";
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Yuexia-PHP-Marketplace/1.0\r\n",
                'timeout' => 30,
                'ignore_errors' => true
            ]
        ]);
        $zipContent = @file_get_contents($zipUrl, false, $ctx);
        if ($zipContent !== false && strlen($zipContent) > 100) {
            break;
        }
        $zipContent = false;
    }

    if ($zipContent === false) {
        return ['success' => false, 'error' => '无法下载ZIP文件'];
    }

    $zipFile = $targetDir . '/repo.zip';
    file_put_contents($zipFile, $zipContent);

    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'error' => '服务器未安装ZipArchive扩展'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        @unlink($zipFile);
        return ['success' => false, 'error' => '无法解压ZIP文件'];
    }

    $zip->extractTo($targetDir);
    $zip->close();
    @unlink($zipFile);

    $items = array_diff(scandir($targetDir), ['.', '..']);
    foreach ($items as $item) {
        $itemPath = $targetDir . '/' . $item;
        if (is_dir($itemPath) && $item !== 'repo.zip') {
            moveDirContents($itemPath, $targetDir);
            removeDirRecursive($itemPath);
            break;
        }
    }

    return ['success' => true];
}

function moveDirContents($srcDir, $destDir) {
    $items = array_diff(scandir($srcDir), ['.', '..']);
    foreach ($items as $item) {
        $src = $srcDir . '/' . $item;
        $dest = $destDir . '/' . $item;
        if (is_dir($src)) {
            @mkdir($dest, 0755, true);
            moveDirContents($src, $dest);
            removeDirRecursive($src);
        } else {
            rename($src, $dest);
        }
    }
}

function copyPluginFiles($sourceDir, $pluginName) {
    $manifestFile = $sourceDir . '/plugin.json';
    $mainFile = $pluginName . '.php';

    if (file_exists($manifestFile)) {
        $manifest = @json_decode(file_get_contents($manifestFile), true);
        if ($manifest && isset($manifest['main'])) {
            $mainFile = $manifest['main'];
        }
    }

    $sourceFile = $sourceDir . '/' . $mainFile;
    if (file_exists($sourceFile)) {
        $destFile = PLUGIN_DIR . $mainFile;
        if (!is_dir(PLUGIN_DIR)) {
            @mkdir(PLUGIN_DIR, 0755, true);
        }
        if (!copy($sourceFile, $destFile)) {
            return ['success' => false, 'error' => '复制插件文件失败'];
        }
        @chmod($destFile, 0644);
        return ['success' => true];
    }

    $pluginSubDir = $sourceDir . '/' . $pluginName . '/';
    if (is_dir($pluginSubDir)) {
        $destDir = PLUGIN_DIR . $pluginName . '/';
        @mkdir($destDir, 0755, true);
        copyDir($pluginSubDir, $destDir);
        return ['success' => true];
    }

    $phpFiles = glob($sourceDir . '/*.php');
    $copied = false;
    foreach ($phpFiles as $phpFile) {
        $basename = basename($phpFile);
        $destFile = PLUGIN_DIR . $basename;
        if (!is_dir(PLUGIN_DIR)) {
            @mkdir(PLUGIN_DIR, 0755, true);
        }
        if (copy($phpFile, $destFile)) {
            @chmod($destFile, 0644);
            $copied = true;
        }
    }

    if ($copied) {
        return ['success' => true];
    }

    return ['success' => false, 'error' => '未找到有效的插件文件'];
}

function copyDir($src, $dst) {
    $dir = opendir($src);
    if (!$dir) return;
    @mkdir($dst, 0755, true);
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') continue;
        $srcFile = $src . '/' . $file;
        $dstFile = $dst . '/' . $file;
        if (is_dir($srcFile)) {
            copyDir($srcFile, $dstFile);
        } else {
            copy($srcFile, $dstFile);
            @chmod($dstFile, 0644);
        }
    }
    closedir($dir);
}

function findPluginInMarketplace($pluginName) {
    if (!file_exists(MARKETPLACE_FILE)) return null;
    $data = @json_decode(file_get_contents(MARKETPLACE_FILE), true);
    $plugins = $data['plugins'] ?? [];
    foreach ($plugins as $p) {
        if ($p['name'] === $pluginName) {
            return $p;
        }
    }
    return null;
}

function getInstalledPluginsInfo() {
    $result = [];
    try {
        $records = db()->fetchAll("SELECT * FROM plugin_market");
        foreach ($records as $r) {
            $result[$r['plugin_name']] = [
                'version' => $r['installed_version'],
                'path' => PLUGIN_DIR . $r['plugin_name'] . '.php',
                'installed_at' => $r['installed_at'],
                'updated_at' => $r['updated_at']
            ];
        }
    } catch (Exception $e) {
        wlog("获取已安装插件信息失败: " . $e->getMessage(), 'system');
    }
    return $result;
}

function scanLocalPlugins() {
    $plugins = [];

    if (!is_dir(PLUGIN_DIR)) {
        return $plugins;
    }

    foreach (glob(PLUGIN_DIR . '*.php') as $file) {
        $name = basename($file, '.php');
        $plugins[] = [
            'name' => $name,
            'type' => 'file',
            'file' => basename($file),
            'size' => filesize($file),
            'mtime' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }

    $items = @scandir(PLUGIN_DIR);
    if ($items === false) return $plugins;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $itemPath = PLUGIN_DIR . $item;
        if (is_dir($itemPath)) {
            $manifestFile = $itemPath . '/plugin.json';
            $manifest = null;
            if (file_exists($manifestFile)) {
                $manifest = @json_decode(file_get_contents($manifestFile), true);
            }

            $mainFile = null;
            $phpFiles = glob($itemPath . '/*.php');
            if (!empty($phpFiles)) {
                $mainFile = basename($phpFiles[0]);
            }

            $entryFile = null;
            if ($manifest && isset($manifest['main'])) {
                $entryFile = $manifest['main'];
            } else {
                $entryFile = $mainFile;
            }

            $plugins[] = [
                'name' => $item,
                'type' => 'directory',
                'path' => $itemPath,
                'manifest' => $manifest,
                'main' => $entryFile,
                'mtime' => date('Y-m-d H:i:s', filemtime($itemPath))
            ];
        }
    }

    return $plugins;
}

function removeDirRecursive($dir) {
    if (!is_dir($dir)) {
        if (file_exists($dir)) @unlink($dir);
        return;
    }
    $items = @scandir($dir);
    if ($items === false) return;
    $items = array_diff($items, ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        is_dir($path) ? removeDirRecursive($path) : @unlink($path);
    }
    @rmdir($dir);
}
