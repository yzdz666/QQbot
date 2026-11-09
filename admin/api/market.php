<?php
/**
 * 管理后台 - 插件市场 API
 * 动作: list / detail / source / install / uninstall / check_updates / save_source / test
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/function.php';
require_once dirname(__DIR__, 2) . '/auth.php';

if (!Auth::isInstalled()) {
    json_response(['success' => false, 'message' => '系统未安装'], 503);
}
if (!Auth::check()) {
    json_response(['success' => false, 'message' => '未登录或会话已过期'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    Auth::requireSameOrigin();
    Auth::verifyCsrf();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$base = 市场地址();

switch ($action) {
    case 'test':
        $target = trim((string)($_POST['url'] ?? $_GET['url'] ?? ''));
        if ($target === '') $target = $base;
        if (!preg_match('#^https?://#i', $target)) {
            json_response(['success' => false, 'message' => '地址必须以 http:// 或 https:// 开头']);
        }
        $data = 市场API($target, 'list', ['limit' => 1]);
        if (empty($data['success'])) {
            json_response(['success' => false, 'message' => $data['message'] ?? '连接失败']);
        }
        json_response([
            'success' => true,
            'message' => '连接成功',
            'data' => [
                'total' => (int)($data['data']['total'] ?? 0),
                'stats' => $data['data']['stats'] ?? [],
                'url' => rtrim($target, '/'),
            ],
        ]);
        break;

    case 'save_source':
        $url = trim((string)($_POST['url'] ?? ''));
        if ($url === '') {
            db()->execute("DELETE FROM settings WHERE key = 'market_base_url'");
            后台操作记录('保存插件市场地址', '恢复自动识别');
            json_response(['success' => true, 'message' => '已恢复为自动识别同域 /market', 'url' => 市场地址()]);
        }
        if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            json_response(['success' => false, 'message' => '请输入合法的 http(s) 地址']);
        }
        if (strlen($url) > 500) {
            json_response(['success' => false, 'message' => '地址过长']);
        }
        $url = rtrim($url, '/');
        db()->setSetting('market_base_url', $url);
        $data = 市场API($url, 'list', ['limit' => 1], 6);
        后台操作记录('保存插件市场地址', $url . (!empty($data['success']) ? ' 连接正常' : ' 连接失败'));
        json_response([
            'success' => true,
            'message' => !empty($data['success']) ? '地址已保存，连接正常' : '地址已保存，但当前无法连接：' . ($data['message'] ?? ''),
            'url' => $url,
            'connected' => !empty($data['success']),
            'total' => (int)($data['data']['total'] ?? 0),
        ]);
        break;

    case 'list':
        $data = 市场API($base, 'list', [
            'q' => $_GET['q'] ?? '',
            'category' => $_GET['category'] ?? '',
            'sort' => $_GET['sort'] ?? 'featured',
            'page' => max(1, (int)($_GET['page'] ?? 1)),
            'limit' => 12,
        ]);
        json_response($data);
        break;

    case 'detail':
        $id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
        if ($id === '') json_response(['success' => false, 'message' => '缺少插件 ID']);
        $data = 市场API($base, 'detail', ['id' => $id]);
        if (!empty($data['success']) && isset($data['data']['description']) && class_exists('Parsedown')) {
            $pd = new Parsedown();
            $pd->setSafeMode(true);
            $pd->setBreaksEnabled(true);
            $data['data']['description_html'] = $pd->text((string)$data['data']['description']);
        }
        json_response($data);
        break;

    case 'source':
        $id = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));
        $version = trim((string)($_GET['version'] ?? $_POST['version'] ?? ''));
        if ($id === '') json_response(['success' => false, 'message' => '缺少插件 ID']);
        $data = 市场API($base, 'source', ['id' => $id, 'version' => $version]);
        if (empty($data['success'])) json_response($data);
        $code = (string)($data['data']['source'] ?? '');
        if (strlen($code) > 1048576) {
            json_response(['success' => false, 'message' => '源码过大']);
        }
        $data['data']['scan'] = 插件安全扫描($code);
        json_response($data);
        break;

    case 'install':
        $id = trim((string)($_POST['id'] ?? ''));
        $wantVersion = trim((string)($_POST['version'] ?? ''));
        $force = (int)($_POST['force'] ?? 0) === 1;
        if ($id === '') json_response(['success' => false, 'message' => '缺少插件 ID']);

        // 1. 拉取详情（不使用缓存，确保 SHA 与版本一致）
        $detail = 市场API($base, 'detail', ['id' => $id]);
        if (empty($detail['success'])) json_response($detail);
        $plugin = $detail['data'];
        if (($plugin['status'] ?? '') !== 'approved') {
            json_response(['success' => false, 'message' => '该插件未通过审核，禁止安装']);
        }
        $versionInfo = null;
        if ($wantVersion !== '') {
            foreach (($plugin['versions'] ?? []) as $v) {
                if ($v['version'] === $wantVersion) { $versionInfo = $v; break; }
            }
        } else {
            $versionInfo = $plugin['latest'] ?? null;
            if ($versionInfo && empty($versionInfo['version'])) $versionInfo['version'] = $plugin['version'] ?? '';
        }
        if (!$versionInfo || empty($versionInfo['version'])) {
            json_response(['success' => false, 'message' => '指定版本不存在或未通过审核']);
        }
        $version = (string)$versionInfo['version'];
        $expectSha = strtolower((string)($versionInfo['sha256'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $expectSha)) {
            json_response(['success' => false, 'message' => '市场未返回有效的 SHA-256，已阻止安装']);
        }

        // 2. 下载源码（单文件模式，避免 zip 解压风险）
        $downloadUrl = 市场链接($base, '/download.php', [
            'id' => $id, 'version' => $version, 'format' => 'php',
        ]);
        $resp = 市场请求($downloadUrl, 1048576, 15);
        if (!$resp['ok'] || $resp['status'] !== 200) {
            json_response(['success' => false, 'message' => '下载插件失败：' . ($resp['error'] ?: ('HTTP ' . $resp['status']))]);
        }
        $code = $resp['body'];
        if (strlen($code) > 1048576) {
            json_response(['success' => false, 'message' => '插件源码超过 1MB，已阻止安装']);
        }
        $actualSha = hash('sha256', $code);
        if (!hash_equals($expectSha, $actualSha)) {
            json_response(['success' => false, 'message' => 'SHA-256 校验失败，文件可能被篡改，已阻止安装']);
        }
        // 响应头也应一致（若市场提供）
        if (!empty($resp['headers']['x-plugin-sha256']) && !hash_equals($expectSha, strtolower($resp['headers']['x-plugin-sha256']))) {
            json_response(['success' => false, 'message' => '响应头 SHA-256 与清单不一致，已阻止安装']);
        }

        // 3. 静态安全扫描
        $scan = 插件安全扫描($code);
        if ($scan['status'] === 'block' && !$force) {
            json_response([
                'success' => false,
                'need_confirm' => true,
                'message' => '安全扫描发现高危代码：' . $scan['summary'] . '。如你已逐行审计并确认安全，可强制安装。',
                'scan' => $scan,
            ]);
        }
        if ($scan['status'] === 'warn' && !$force) {
            json_response([
                'success' => false,
                'need_confirm' => true,
                'message' => '安全检查发现需人工确认的调用：' . $scan['summary'] . '。确认继续安装？',
                'scan' => $scan,
            ]);
        }

        // 4. 解析插件名（优先文件头 @name，其次市场名称）
        $tmp = tempnam(sys_get_temp_dir(), 'gjplg_');
        file_put_contents($tmp, $code);
        $meta = 插件元信息($tmp);
        @unlink($tmp);
        $name = trim((string)($meta['name'] ?? ''));
        if ($name === '' || $name === basename($tmp, '.php')) {
            $name = (string)($plugin['name'] ?? ($plugin['slug'] ?? ''));
        }
        $name = 插件名过滤($name);
        if ($name === false || $name === '') {
            // 市场名称可能带 " / "、"｜" 等描述性分隔符，替换为空格后重试
            $candidate = trim((string)($plugin['name'] ?? ''));
            $candidate = preg_replace('#[/\\\\:*?"<>|\x00-\x1F]+#u', ' ', $candidate);
            $candidate = trim(preg_replace('/\s+/u', ' ', (string)$candidate));
            $name = $candidate === '' ? false : 插件名过滤($candidate);
        }
        if ($name === false || $name === '') {
            json_response(['success' => false, 'message' => '无法确定合法的插件文件名']);
        }
        $name = preg_replace('/\.php$/i', '', $name);

        $existingFile = 插件真实文件($name);
        $record = db()->fetch("SELECT * FROM market_installed WHERE plugin_name = ?", [$name]);

        if ($record) {
            if (version_compare($record['version'], $version, '>') && !$force) {
                json_response(['success' => false, 'message' => '本地已安装更高版本 v' . $record['version'] . '，如需降级请强制安装']);
            }
            if ($record['version'] === $version && hash_equals((string)$record['sha256'], $actualSha) && !$force) {
                json_response(['success' => true, 'message' => '已是最新版本 v' . $version, 'version' => $version, 'already' => true]);
            }
        } elseif ($existingFile && !$force) {
            json_response([
                'success' => false,
                'need_confirm' => true,
                'message' => 'plugin/ 目录已存在同名插件「' . $name . '」，但不是通过市场安装的。继续将覆盖原文件（会先备份），是否继续？',
            ]);
        }

        // 4.5 内容去重：本地已有完全相同的插件时只登记，不重复写入，避免同一功能被加载两次
        $dupFile = null;
        foreach ((array)glob(APP_ROOT . 'plugin/*.php') as $pf) {
            if (!is_file($pf)) continue;
            if (hash_file('sha256', $pf) === $actualSha) { $dupFile = $pf; break; }
        }
        if ($dupFile !== null) {
            $dupName = basename($dupFile, '.php');
            try {
                db()->execute(
                    "INSERT OR REPLACE INTO market_installed (plugin_name, slug, version, sha256, source, installed_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, datetime('now','localtime'), datetime('now','localtime'))",
                    [$dupName, (string)($plugin['slug'] ?? ''), $version, $actualSha, rtrim($base, '/') . '#local']
                );
            } catch (Exception $e) {}
            后台操作记录('插件市场安装', $dupName . ' v' . $version . '（本地已存在相同内容，仅登记）');
            json_response([
                'success' => true,
                'already' => true,
                'dedup' => true,
                'name' => $dupName,
                'version' => $version,
                'sha256' => $actualSha,
                'message' => '本地已存在内容完全相同的插件「' . $dupName . '」，已登记为市场安装，未重复写入文件。',
            ]);
        }

        // 5. 备份旧文件
        $backupPath = '';
        if ($existingFile) {
            $backupDir = APP_ROOT . 'data/plugin_backups/';
            ensureWritableDir($backupDir);
            if (!is_file($backupDir . '.htaccess')) {
                @file_put_contents($backupDir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
            }
            $backupPath = $backupDir . $name . '_' . date('YmdHis') . '.php';
            if (!@copy($existingFile, $backupPath)) {
                json_response(['success' => false, 'message' => '备份原插件失败，请检查 data/plugin_backups 目录权限']);
            }
            // 仅保留最近 30 个备份
            $backups = glob($backupDir . '*.php');
            if (is_array($backups) && count($backups) > 30) {
                usort($backups, function ($a, $b) { return filemtime($a) <=> filemtime($b); });
                foreach (array_slice($backups, 0, count($backups) - 30) as $old) @unlink($old);
            }
        }

        // 6. 原子写入
        $pluginDir = APP_ROOT . 'plugin/';
        ensureWritableDir($pluginDir);
        $target = $existingFile ?: ($pluginDir . $name . '.php');
        $writeTmp = $pluginDir . '.market_tmp_' . bin2hex(random_bytes(6)) . '.php';
        if (@file_put_contents($writeTmp, $code) === false) {
            json_response(['success' => false, 'message' => '写入临时文件失败，请检查 plugin 目录权限']);
        }
        @chmod($writeTmp, 0644);
        if (!@rename($writeTmp, $target)) {
            @unlink($writeTmp);
            json_response(['success' => false, 'message' => '替换插件文件失败，请检查 plugin 目录权限']);
        }

        // 7. 记录安装信息
        try {
            db()->execute(
                "INSERT OR REPLACE INTO market_installed (plugin_name, slug, version, sha256, source, installed_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, datetime('now','localtime'), datetime('now','localtime'))",
                [$name, (string)($plugin['slug'] ?? ''), $version, $actualSha, rtrim($base, '/')]
            );
        } catch (Exception $e) {
            // 记录失败不影响插件使用，但提示管理员
        }
        后台操作记录('插件市场安装', $name . ' v' . $version . ' (SHA256:' . substr($actualSha, 0, 12) . '…)');

        json_response([
            'success' => true,
            'message' => '安装成功：' . $name . ' v' . $version . ($backupPath !== '' ? '（原文件已备份）' : ''),
            'name' => $name,
            'version' => $version,
            'sha256' => $actualSha,
            'scan' => $scan,
            'backup' => $backupPath,
        ]);
        break;

    case 'uninstall':
        $name = 插件名过滤($_POST['name'] ?? '');
        if ($name === false || $name === '') {
            json_response(['success' => false, 'message' => '无效的插件名']);
        }
        $record = db()->fetch("SELECT * FROM market_installed WHERE plugin_name = ?", [$name]);
        if (!$record) {
            json_response(['success' => false, 'message' => '该插件不是通过市场安装的，请到「插件管理」删除']);
        }
        // 安装时判定为「本地已存在相同内容」的插件，只解除市场登记，不删除原有文件
        $isLocal = substr((string)$record['source'], -6) === '#local';
        if ($isLocal) {
            try {
                db()->execute("DELETE FROM market_installed WHERE plugin_name = ?", [$name]);
            } catch (Exception $e) {}
            后台操作记录('插件市场解除登记', $name);
            json_response(['success' => true, 'message' => '已解除市场登记，本地插件「' . $name . '」保留不动']);
        }
        $file = 插件真实文件($name);
        if ($file) {
            $backupDir = APP_ROOT . 'data/plugin_backups/';
            ensureWritableDir($backupDir);
            @copy($file, $backupDir . $name . '_uninstall_' . date('YmdHis') . '.php');
            @unlink($file);
        }
        try {
            db()->execute("DELETE FROM plugin_status WHERE plugin_name = ?", [$name]);
            db()->execute("DELETE FROM market_installed WHERE plugin_name = ?", [$name]);
        } catch (Exception $e) {}
        后台操作记录('插件市场卸载', $name);
        json_response(['success' => true, 'message' => '已卸载 ' . $name . '（文件已备份）']);
        break;

    case 'check_updates':
        $rows = db()->fetchAll("SELECT * FROM market_installed ORDER BY updated_at DESC");
        if (empty($rows)) json_response(['success' => true, 'data' => ['items' => [], 'updates' => 0]]);
        $items = [];
        $updates = 0;
        foreach ($rows as $row) {
            $item = [
                'plugin_name' => $row['plugin_name'],
                'slug' => $row['slug'],
                'version' => $row['version'],
                'latest' => '',
                'has_update' => false,
                'error' => '',
            ];
            $data = 市场API($base, 'check', ['slug' => $row['slug'], 'version' => $row['version']], 6);
            if (!empty($data['success'])) {
                $item['latest'] = $data['data']['latest'] ?? '';
                $item['has_update'] = !empty($data['data']['has_update']);
                if ($item['has_update']) $updates++;
            } else {
                $item['error'] = $data['message'] ?? '检查失败';
            }
            $items[] = $item;
        }
        json_response(['success' => true, 'data' => ['items' => $items, 'updates' => $updates]]);
        break;

    case 'installed':
        json_response(['success' => true, 'data' => db()->fetchAll("SELECT * FROM market_installed ORDER BY plugin_name")]);
        break;

    default:
        json_response(['success' => false, 'message' => '未知操作'], 400);
}
