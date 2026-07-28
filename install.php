<?php
/**
 * 引导安装页面
 * 支持环境检测、管理员设置、JSON文件导入
 */
date_default_timezone_set('Asia/Shanghai');
require_once(__DIR__ . '/function.php');
require_once(__DIR__ . '/auth.php');

$step = $_POST['step'] ?? ($_GET['step'] ?? '1');
$installed = Auth::isInstalled();

// 如果已安装，跳转到管理后台（阻止所有访问包括POST请求）
if ($installed) {
    header('Location: admin/index.php');
    exit;
}

$errors = [];
$success = false;
$successMsg = '';

// 环境检测
$requirements = [
    'PHP >= 7.4' => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO SQLite' => extension_loaded('pdo_sqlite'),
    'cURL' => extension_loaded('curl'),
    'mbstring' => extension_loaded('mbstring'),
    'sodium' => extension_loaded('sodium'),
    'GD' => extension_loaded('gd'),
    'openssl' => extension_loaded('openssl'),
    'sockets' => extension_loaded('sockets'),
    'JSON' => extension_loaded('json'),
];

$allOk = true;
foreach ($requirements as $req => $ok) {
    if (!$ok) $allOk = false;
}

// 数据库目录可写检查
$dataDir = __DIR__ . '/data';
$isWritable = is_writable(dirname($dataDir)) || (is_dir($dataDir) && is_writable($dataDir));

// 自动扫描并导入当前目录的JSON文件
$autoImportedFiles = [];
$autoImportErrors = [];

function autoImportJsonFiles($dir) {
    global $autoImportedFiles, $autoImportErrors;

    $jsonFiles = glob($dir . '/*.json');
    if (!$jsonFiles) return;

    // 需要跳过的系统文件
    $skipFiles = ['config.json', 'main.json', 'composer.json', 'package.json'];

    foreach ($jsonFiles as $filePath) {
        $fileName = basename($filePath);
        if (in_array($fileName, $skipFiles)) continue;

        $content = file_get_contents($filePath);
        $data = json_decode($content, true);
        if (!$data || !is_array($data)) {
            continue;
        }

        try {
            // 尝试识别文件类型并导入
            $imported = false;

            // 如果是机器人配置格式（key是appid，value有secret字段）
            foreach ($data as $key => $value) {
                if (is_array($value) && isset($value['secret']) && !empty($key)) {
                    // 这是一个机器人配置
                    $env = $value['type'] ?? $value['env'] ?? '正式';
                    addBot($key, $value['secret'], $env);

                    // 导入插件配置
                    if (isset($value['plugin']) && is_array($value['plugin'])) {
                        foreach ($value['plugin'] as $pluginName => $enabled) {
                            db()->execute(
                                "INSERT OR IGNORE INTO plugin_status (appid, plugin_name, enabled) VALUES (?, ?, ?)",
                                [$key, $pluginName, $enabled ? 1 : 0]
                            );
                        }
                    }
                    $imported = true;
                }
            }

            if ($imported) {
                $autoImportedFiles[] = $fileName;
                continue;
            }

            // 如果是KV存储格式（扁平键值对或嵌套键值对）
            $isKvData = false;
            foreach ($data as $k => $v) {
                if (!is_array($v) && !is_object($v)) {
                    $isKvData = true;
                    break;
                }
            }

            if ($isKvData) {
                // 检测是否是管理员配置
                if (isset($data['admin']) && isset($data['password'])) {
                    if (!Auth::isInstalled()) {
                        Auth::setupAdmin($data['admin'], $data['password']);
                    }
                    $autoImportedFiles[] = $fileName . ' (管理员配置)';
                    continue;
                }

                // 检测是否是AI配置
                if (isset($data['base_url']) || isset($data['api_key']) || isset($data['model'])) {
                    db()->execute("UPDATE ai_config SET base_url = ?, api_key = ?, model = ? WHERE id = 1",
                        [$data['base_url'] ?? '', $data['api_key'] ?? '', $data['model'] ?? 'gpt-4o-mini']);
                    $autoImportedFiles[] = $fileName . ' (AI配置)';
                    continue;
                }

                // 作为通用KV数据导入
                $namespace = str_replace('.json', '', $fileName);
                foreach ($data as $k => $v) {
                    db()->kvSet($namespace, $k, $v);
                }
                $autoImportedFiles[] = $fileName . ' (KV数据)';
                continue;
            }

            // 如果是嵌套对象格式
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    $namespace = str_replace('.json', '', $fileName) . '_' . $k;
                    foreach ($v as $vk => $vv) {
                        db()->kvSet($namespace, $vk, $vv);
                    }
                }
            }
            $autoImportedFiles[] = $fileName . ' (嵌套数据)';

        } catch (Exception $e) {
            $autoImportErrors[] = $fileName . ': ' . $e->getMessage();
        }
    }
}

// 自动导入日志文件
$autoImportedLogs = 0;
$autoLogErrors = [];

function autoImportLogFiles($baseDir) {
    global $autoImportedLogs, $autoLogErrors;

    // 兼容大小写：Log / log
    $logDir = null;
    foreach (['Log', 'log', 'LOG'] as $candidate) {
        if (is_dir($baseDir . '/' . $candidate)) {
            $logDir = $baseDir . '/' . $candidate;
            break;
        }
    }
    if ($logDir === null) return;

    // 扫描每个 appid 子目录
    $appDirs = glob($logDir . '/*', GLOB_ONLYDIR);
    if (!$appDirs) return;

    foreach ($appDirs as $appDir) {
        $appid = basename($appDir);
        $logFiles = glob($appDir . '/*.log');
        if (!$logFiles) continue;

        foreach ($logFiles as $logFile) {
            $content = file_get_contents($logFile);
            if ($content === false) continue;

            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                // 解析格式: [Y-m-d H:i:s] {json内容}
                if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $matches)) {
                    $timestamp = $matches[1];
                    $jsonContent = $matches[2];

                    // 跳过"重复数据"标记
                    if ($jsonContent === '重复数据') continue;

                    // 判断日志级别
                    $level = 'INFO';
                    if (strpos($jsonContent, 'plat_error') !== false || strpos($jsonContent, '错误') !== false || strpos($jsonContent, '失败') !== false) {
                        $level = 'ERROR';
                    }

                    // 判断日志类型
                    $logType = 'system';
                    $decoded = json_decode($jsonContent, true);
                    if (is_array($decoded)) {
                        if (isset($decoded['direction'])) {
                            $logType = 'message';
                        } elseif (isset($decoded['t']) || isset($decoded['op'])) {
                            $logType = 'event';
                        } elseif (isset($decoded['plat_error'])) {
                            $logType = 'error';
                            $level = 'ERROR';
                        }
                    }

                    try {
                        db()->execute(
                            "INSERT INTO system_logs (appid, log_type, content, level, created_at) VALUES (?, ?, ?, ?, ?)",
                            [$appid, $logType, $jsonContent, $level, $timestamp]
                        );
                        $autoImportedLogs++;
                    } catch (Exception $e) {
                        // 忽略重复导入错误
                        if (strpos($e->getMessage(), 'UNIQUE') === false) {
                            $autoLogErrors[] = basename($logFile) . ': ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

// 在步骤1时自动执行导入
if ($step == '1' && !$installed) {
    autoImportJsonFiles(__DIR__);
    autoImportLogFiles(__DIR__);
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case '2':
            // 设置管理员
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $passwordConfirm = $_POST['password_confirm'] ?? '';

            if (empty($username)) {
                $errors[] = '用户名不能为空';
            }
            if (strlen($password) < 6) {
                $errors[] = '密码长度至少6位';
            }
            if ($password !== $passwordConfirm) {
                $errors[] = '两次密码不一致';
            }

            if (empty($errors)) {
                Auth::setupAdmin($username, $password);
                $success = true;
                $successMsg = '管理员设置成功！请继续配置机器人。';
                $step = '3';
            }
            break;

        case '3':
            // 添加机器人
            $appid = trim($_POST['appid'] ?? '');
            $secret = trim($_POST['secret'] ?? '');
            $env = $_POST['env'] ?? '正式';
            $wsEnabled = isset($_POST['ws_enabled']) ? 1 : 0;
            $wsUrl = trim($_POST['ws_url'] ?? '');

            if (empty($appid) || empty($secret)) {
                $errors[] = 'AppID和Secret不能为空';
            }

            if (empty($errors)) {
                addBot($appid, $secret, $env);
                if ($wsEnabled) {
                    updateBot($appid, ['ws_enabled' => 1, 'ws_url' => $wsUrl]);
                }
                // 自动获取机器人信息（头像、昵称等）
                $fetchResult = fetchAndUpdateBotInfo($appid);
                $success = true;
                if ($fetchResult['success']) {
                    $successMsg = '机器人添加成功！已自动获取信息：' .
                        '昵称「' . ($fetchResult['data']['nickname'] ?? '') . '」' .
                        ($fetchResult['data']['avatar'] ? '，头像已获取' : '') .
                        '。可以继续添加或完成安装。';
                } else {
                    $successMsg = '机器人添加成功！自动获取信息失败（' . ($fetchResult['message'] ?? '未知原因') . '），可稍后在管理后台手动获取。';
                }
            }
            break;

        case 'import':
            // 导入JSON文件
            $importResult = handleImport();
            if ($importResult['success']) {
                $success = true;
            } else {
                $errors = $importResult['errors'];
            }
            break;
    }
}

// 处理JSON导入
function handleImport() {
    $errors = [];
    $imported = [];

    // 服务端验证上传文件类型和大小
    $allowedMimeTypes = ['application/json', 'text/plain', 'application/zip', 'application/x-zip-compressed'];
    $maxSize = 50 * 1024 * 1024;
    $fileFields = ['config_json', 'main_json', 'ai_config_json', 'database_zip'];
    foreach ($fileFields as $field) {
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            if ($_FILES[$field]['size'] > $maxSize) {
                $errors[] = '文件 ' . $field . ' 过大（超过50MB）';
                continue;
            }
            $fileMime = mime_content_type($_FILES[$field]['tmp_name']);
            if (!in_array($fileMime, $allowedMimeTypes)) {
                $errors[] = '文件 ' . $field . ' 类型不支持';
                continue;
            }
        }
    }
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors, 'imported' => []];
    }

    // 导入 config.json (管理员账号)
    if (isset($_FILES['config_json']) && $_FILES['config_json']['error'] === UPLOAD_ERR_OK) {
        $content = file_get_contents($_FILES['config_json']['tmp_name']);
        $data = json_decode($content, true);
        if ($data && isset($data['admin']) && isset($data['password'])) {
            if (!Auth::isInstalled()) {
                Auth::setupAdmin($data['admin'], $data['password']);
            } else {
                db()->execute("UPDATE admin SET username = ?, password = ? WHERE id = 1",
                    [$data['admin'], passwordHash($data['password'])]);
            }
            $imported[] = '管理员配置 (config.json)';
        } else {
            $errors[] = 'config.json 格式错误';
        }
    }

    // 导入 main.json (机器人配置)
    if (isset($_FILES['main_json']) && $_FILES['main_json']['error'] === UPLOAD_ERR_OK) {
        $content = file_get_contents($_FILES['main_json']['tmp_name']);
        $data = json_decode($content, true);
        if ($data && is_array($data)) {
            $count = 0;
            foreach ($data as $appid => $config) {
                if (isset($config['secret'])) {
                    $env = $config['type'] ?? '正式';
                    addBot($appid, $config['secret'], $env);

                    // 导入插件配置
                    if (isset($config['plugin']) && is_array($config['plugin'])) {
                        foreach ($config['plugin'] as $pluginName => $enabled) {
                            db()->execute(
                                "INSERT OR IGNORE INTO plugin_status (appid, plugin_name, enabled) VALUES (?, ?, ?)",
                                [$appid, $pluginName, $enabled ? 1 : 0]
                            );
                        }
                    }
                    $count++;
                }
            }
            $imported[] = "机器人配置 (main.json) - {$count}个机器人";
        } else {
            $errors[] = 'main.json 格式错误';
        }
    }

    // 导入 ai_config.json (AI配置)
    if (isset($_FILES['ai_config_json']) && $_FILES['ai_config_json']['error'] === UPLOAD_ERR_OK) {
        $content = file_get_contents($_FILES['ai_config_json']['tmp_name']);
        $data = json_decode($content, true);
        if ($data) {
            db()->execute("UPDATE ai_config SET base_url = ?, api_key = ?, model = ? WHERE id = 1",
                [$data['base_url'] ?? '', $data['api_key'] ?? '', $data['model'] ?? 'gpt-4o-mini']);
            $imported[] = 'AI配置 (ai_config.json)';
        } else {
            $errors[] = 'ai_config.json 格式错误';
        }
    }

    // 导入 database/ 目录下的JSON数据文件
    if (isset($_FILES['database_zip']) && $_FILES['database_zip']['error'] === UPLOAD_ERR_OK) {
        $zipPath = $_FILES['database_zip']['tmp_name'];
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            $tempDir = sys_get_temp_dir() . '/guanji_import_' . time();
            mkdir($tempDir, 0777, true);
            $zip->extractTo($tempDir);

            // 解压后验证文件路径，防止ZIP路径遍历攻击
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $fileName = $stat['name'];
                $destPath = $tempDir . '/' . $fileName;
                $realDest = realpath($destPath);
                $realTemp = realpath($tempDir);
                if ($realDest === false || $realTemp === false || strpos($realDest, $realTemp) !== 0) {
                    @unlink($destPath);
                    continue;
                }
            }

            $zip->close();

            $count = importDatabaseDir($tempDir);
            $imported[] = "数据库文件 (database/) - {$count}条记录";

            // 清理临时目录
            removeDir($tempDir);
        } else {
            $errors[] = '无法打开ZIP文件';
        }
    }

    return [
        'success' => empty($errors) && !empty($imported),
        'errors' => $errors,
        'imported' => $imported
    ];
}

function importDatabaseDir($dir) {
    $count = 0;
    $files = glob($dir . '/**/*.json');
    if (!$files) $files = glob($dir . '/*.json');
    // 递归查找
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'json') continue;
        $content = file_get_contents($file->getRealPath());
        $data = json_decode($content, true);
        if (!is_array($data)) continue;

        // 从文件路径推断namespace
        $relPath = str_replace($dir . '/', '', $file->getRealPath());
        $relPath = str_replace($dir . DIRECTORY_SEPARATOR, '', $relPath);
        $namespace = str_replace(['/', '\\', '.json'], ['_', '_', ''], $relPath);

        foreach ($data as $key => $value) {
            db()->kvSet($namespace, $key, $value);
            $count++;
        }
    }
    return $count;
}

function removeDir($dir) {
    if (!is_dir($dir)) return false;
    $allowedBase = sys_get_temp_dir();
    $realDir = realpath($dir);
    $realBase = realpath($allowedBase);
    if ($realDir === false || $realBase === false || strpos($realDir, $realBase) !== 0) {
        return false;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    return rmdir($dir);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>安装向导 - 官鸡机器人管理</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; color: #333; }
.container { max-width: 600px; margin: 40px auto; padding: 0 20px; }
.card { background: #fff; border-radius: 12px; padding: 40px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.logo { text-align: center; margin-bottom: 30px; }
.logo h1 { font-size: 28px; font-weight: 600; color: #1a1a1a; }
.logo p { color: #999; font-size: 14px; margin-top: 8px; }
.steps { display: flex; justify-content: space-between; margin-bottom: 30px; }
.step { flex: 1; text-align: center; position: relative; }
.step .circle { width: 32px; height: 32px; border-radius: 50%; background: #e0e0e0; color: #999; line-height: 32px; font-size: 14px; margin: 0 auto 8px; }
.step.active .circle { background: #333; color: #fff; }
.step.done .circle { background: #4caf50; color: #fff; }
.step .label { font-size: 12px; color: #999; }
.step.active .label { color: #333; font-weight: 500; }
.step::after { content: ''; position: absolute; top: 16px; left: 50%; width: 100%; height: 2px; background: #e0e0e0; z-index: -1; }
.step:last-child::after { display: none; }
.step.done::after { background: #4caf50; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; font-size: 14px; color: #666; margin-bottom: 6px; }
.form-group input, .form-group select { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; transition: border-color 0.2s; }
.form-group input:focus, .form-group select:focus { outline: none; border-color: #333; }
.form-group .hint { font-size: 12px; color: #999; margin-top: 4px; }
.btn { width: 100%; padding: 12px; background: #333; color: #fff; border: none; border-radius: 8px; font-size: 14px; cursor: pointer; transition: background 0.2s; }
.btn:hover { background: #555; }
.btn:disabled { background: #ccc; cursor: not-allowed; }
.req-list { list-style: none; }
.req-list li { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
.req-list li:last-child { border-bottom: none; }
.req-list .ok { color: #4caf50; }
.req-list .fail { color: #f44336; }
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
.alert-error { background: #fff0f0; color: #c62828; }
.alert-success { background: #f0fff0; color: #2e7d32; }
.file-group { margin-bottom: 16px; }
.file-group label { display: block; font-size: 14px; color: #666; margin-bottom: 6px; }
.file-group .file-hint { font-size: 12px; color: #999; margin-top: 4px; }
.file-group input[type="file"] { width: 100%; padding: 8px; border: 1px dashed #ddd; border-radius: 8px; font-size: 13px; }
.divider { text-align: center; margin: 24px 0; color: #ccc; font-size: 13px; position: relative; }
.divider::before, .divider::after { content: ''; position: absolute; top: 50%; width: 40%; height: 1px; background: #e0e0e0; }
.divider::before { left: 0; }
.divider::after { right: 0; }
.imported-list { background: #f0fff0; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; }
.imported-list h4 { font-size: 14px; color: #2e7d32; margin-bottom: 8px; }
.imported-list ul { list-style: none; font-size: 13px; color: #555; }
.imported-list li { padding: 2px 0; }
</style>
</head>
<body>
<div class="container">
<div class="card">
<div class="logo">
<h1>官鸡机器人</h1>
<p>安装向导</p>
</div>

<?php if ($step == '1'): ?>
<!-- 步骤1：环境检测 -->
<div class="steps">
<div class="step active"><div class="circle">1</div><div class="label">环境检测</div></div>
<div class="step"><div class="circle">2</div><div class="label">管理员设置</div></div>
<div class="step"><div class="circle">3</div><div class="label">机器人配置</div></div>
<div class="step"><div class="circle">4</div><div class="label">完成</div></div>
</div>

<?php if (!$allOk): ?>
<div class="alert alert-error">部分环境要求未满足，请检查下方列表</div>
<?php endif; ?>

<ul class="req-list">
<?php foreach ($requirements as $req => $ok): ?>
<li><span><?= htmlspecialchars($req) ?></span><span class="<?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? '✓' : '✗' ?></span></li>
<?php endforeach; ?>
<li><span>data/ 目录可写</span><span class="<?= $isWritable ? 'ok' : 'fail' ?>"><?= $isWritable ? '✓' : '✗' ?></span></li>
</ul>

<div class="divider">数据导入（可选）</div>

<?php if (!empty($autoImportedFiles)): ?>
<div class="imported-list" style="margin-bottom: 16px;">
<h4>自动导入结果</h4>
<p style="font-size: 12px; color: #888; margin-bottom: 8px;">已自动检测并导入当前目录中的以下JSON文件：</p>
<ul>
<?php foreach ($autoImportedFiles as $item): ?>
<li>✓ <?= htmlspecialchars($item) ?></li>
<?php endforeach; ?>
</ul>
</div>
<?php endif; ?>

<?php if (!empty($autoImportErrors)): ?>
<div class="alert alert-error" style="margin-bottom: 16px;">
<h4 style="margin-bottom: 4px;">部分文件导入失败</h4>
<?php foreach ($autoImportErrors as $err): ?>
<div style="font-size: 13px;"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($autoImportedLogs > 0): ?>
<div class="imported-list" style="margin-bottom: 16px;">
<h4>日志导入结果</h4>
<p style="font-size: 12px; color: #888; margin-bottom: 8px;">已自动扫描 Log/ 目录并导入历史日志：</p>
<ul>
<li>✓ 已导入 <?= $autoImportedLogs ?> 条日志记录</li>
</ul>
</div>
<?php endif; ?>

<?php if (!empty($autoLogErrors)): ?>
<div class="alert alert-error" style="margin-bottom: 16px;">
<h4 style="margin-bottom: 4px;">部分日志导入失败</h4>
<?php foreach ($autoLogErrors as $err): ?>
<div style="font-size: 13px;"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="step" value="import">
<div class="file-group">
<label>导入 config.json（管理员配置）</label>
<input type="file" name="config_json" accept=".json">
<div class="file-hint">从旧版官鸡导出的管理员账号密码配置</div>
</div>
<div class="file-group">
<label>导入 main.json（机器人配置）</label>
<input type="file" name="main_json" accept=".json">
<div class="file-hint">从旧版官鸡导出的机器人列表和插件配置</div>
</div>
<div class="file-group">
<label>导入 ai_config.json（AI配置）</label>
<input type="file" name="ai_config_json" accept=".json">
<div class="file-hint">AI写插件功能的配置文件</div>
</div>
<div class="file-group">
<label>导入 database/ 目录（ZIP打包）</label>
<input type="file" name="database_zip" accept=".zip">
<div class="file-hint">将旧版database/目录打包为ZIP后导入</div>
</div>
<button type="submit" class="btn" style="margin-bottom: 12px;">导入选中的文件</button>
</form>

<?php if ($success && $step == 'import'): ?>
<div class="imported-list">
<h4>✓ 导入成功</h4>
<ul>
<?php foreach ($importResult['imported'] ?? [] as $item): ?>
<li><?= htmlspecialchars($item) ?></li>
<?php endforeach; ?>
</ul>
</div>
<?php endif; ?>

<?php if (!empty($errors) && $step == 'import'): ?>
<div class="alert alert-error">
<?php foreach ($errors as $e): ?>
<div><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="step" value="2">
<button type="submit" class="btn" <?= !$allOk ? 'disabled' : '' ?>>下一步：设置管理员</button>
</form>

<?php elseif ($step == '2'): ?>
<!-- 步骤2：管理员设置 -->
<div class="steps">
<div class="step done"><div class="circle">✓</div><div class="label">环境检测</div></div>
<div class="step active"><div class="circle">2</div><div class="label">管理员设置</div></div>
<div class="step"><div class="circle">3</div><div class="label">机器人配置</div></div>
<div class="step"><div class="circle">4</div><div class="label">完成</div></div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
<?php foreach ($errors as $e): ?>
<div><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>

<?php if (!$success): ?>
<form method="POST">
<input type="hidden" name="step" value="2">
<div class="form-group">
<label>管理员用户名</label>
<input type="text" name="username" required placeholder="请输入用户名">
</div>
<div class="form-group">
<label>管理员密码</label>
<input type="password" name="password" required placeholder="至少6位">
<div class="hint">密码将使用SHA-256加盐加密存储</div>
</div>
<div class="form-group">
<label>确认密码</label>
<input type="password" name="password_confirm" required placeholder="再次输入密码">
</div>
<button type="submit" class="btn">下一步</button>
</form>
<?php else: ?>
<form method="POST">
<input type="hidden" name="step" value="3">
<button type="submit" class="btn">下一步：配置机器人</button>
</form>
<?php endif; ?>

<?php elseif ($step == '3'): ?>
<!-- 步骤3：机器人配置 -->
<div class="steps">
<div class="step done"><div class="circle">✓</div><div class="label">环境检测</div></div>
<div class="step done"><div class="circle">✓</div><div class="label">管理员设置</div></div>
<div class="step active"><div class="circle">3</div><div class="label">机器人配置</div></div>
<div class="step"><div class="circle">4</div><div class="label">完成</div></div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
<?php foreach ($errors as $e): ?>
<div><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>

<?php
$bots = getBots();
if (!empty($bots)):
?>
<div style="margin-bottom: 20px;">
<h3 style="font-size: 14px; color: #666; margin-bottom: 10px;">已配置的机器人</h3>
<?php foreach ($bots as $b):
    $bNickname = trim($b['nickname'] ?? '');
    $bAvatar = trim($b['avatar'] ?? '');
    $bRobotQq = trim($b['robot_qq'] ?? '');
?>
<div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: #f9f9f9; border-radius: 8px; margin-bottom: 8px;">
<div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
    <div style="width:32px; height:32px; border-radius:6px; overflow:hidden; flex-shrink:0; background:#e0e0e0; display:flex; align-items:center; justify-content:center; font-size:11px; color:#666;">
        <?php if (!empty($bAvatar)): ?>
            <img src="<?= htmlspecialchars($bAvatar) ?>" alt="头像" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none'; this.parentElement.innerText='<?= mb_substr($b['appid'],0,4) ?>';">
        <?php else: ?>
            <?= mb_substr($b['appid'], 0, 4) ?>
        <?php endif; ?>
    </div>
    <div style="min-width:0;">
        <div style="font-size: 14px; font-weight: 500;"><?= htmlspecialchars($b['appid']) ?></div>
        <?php if ($bNickname !== '' || $bRobotQq !== ''): ?>
        <div style="font-size: 11px; color: #999;">
            <?php if ($bNickname !== ''): ?><?= htmlspecialchars($bNickname) ?><?php endif; ?>
            <?php if ($bNickname !== '' && $bRobotQq !== ''): ?> · <?php endif; ?>
            <?php if ($bRobotQq !== ''): ?>QQ:<?= htmlspecialchars($bRobotQq) ?><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<span style="font-size: 12px; color: <?= $b['ws_enabled'] ? '#4caf50' : '#999' ?>; flex-shrink:0;"><?= $b['ws_enabled'] ? 'WS已启用' : htmlspecialchars($b['env']) ?></span>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="step" value="3">
<div class="form-group">
<label>机器人 AppID</label>
<input type="text" name="appid" required placeholder="QQ开放平台AppID">
</div>
<div class="form-group">
<label>机器人 Secret</label>
<input type="text" name="secret" required placeholder="AppSecret">
</div>
<div class="form-group">
<label>环境</label>
<select name="env">
<option value="正式">正式</option>
<option value="沙箱">沙箱</option>
</select>
</div>
<div class="form-group">
<label>
<input type="checkbox" name="ws_enabled" value="1" style="width: auto; display: inline-block;">
启用 WebSocket 模式
</label>
<div class="hint">启用后将通过WebSocket连接QQ网关，无需公网回调地址</div>
</div>
<div class="form-group" id="ws_url_group" style="display:none;">
<label>自定义 WS URL（可选）</label>
<input type="text" name="ws_url" placeholder="留空则自动获取">
</div>
<button type="submit" class="btn" style="margin-bottom: 12px;">添加机器人</button>
</form>

<form method="POST">
<input type="hidden" name="step" value="4">
<button type="submit" class="btn" style="background: #4caf50;">完成安装</button>
</form>

<?php else: ?>
<!-- 步骤4：完成 -->
<div class="steps">
<div class="step done"><div class="circle">✓</div><div class="label">环境检测</div></div>
<div class="step done"><div class="circle">✓</div><div class="label">管理员设置</div></div>
<div class="step done"><div class="circle">✓</div><div class="label">机器人配置</div></div>
<div class="step active done"><div class="circle">✓</div><div class="label">完成</div></div>
</div>

<div class="alert alert-success">
<h3 style="margin-bottom: 8px;">安装完成！</h3>
<p>系统已成功初始化，请登录管理后台开始使用。</p>
</div>

<a href="admin/index.php" class="btn" style="display: block; text-align: center; text-decoration: none; line-height: 44px;">进入管理后台</a>

<?php if (file_exists(__DIR__ . '/ws_client.php')): ?>
<div style="margin-top: 20px; padding: 16px; background: #f9f9f9; border-radius: 8px;">
<h4 style="font-size: 14px; margin-bottom: 8px;">WebSocket 模式启动命令：</h4>
<code style="font-size: 12px; color: #666;">php <?= __DIR__ ?>/ws_client.php</code>
<p style="font-size: 12px; color: #999; margin-top: 4px;">在命令行中运行以上命令启动WebSocket客户端</p>
</div>
<?php endif; ?>
<?php endif; ?>

</div>
</div>
<script>
document.querySelector('input[name="ws_enabled"]')?.addEventListener('change', function() {
document.getElementById('ws_url_group').style.display = this.checked ? 'block' : 'none';
});
</script>
</body>
</html>
