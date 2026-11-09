<?php
/**
 * 管理后台 - 系统在线更新 API
 * 动作: check / download / apply / status / save / clear_temp
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/function.php';
require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__) . '/lib_update.php';

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

switch ($action) {
    case 'status':
        $状态 = 更新状态();
        json_response([
            'success' => true,
            'data' => [
                'version' => 更新当前版本(),
                'state' => $状态,
                'config' => 更新配置(),
                'backups' => 更新备份列表(),
                'php' => PHP_VERSION,
                'zip' => class_exists('ZipArchive'),
            ],
        ]);
        break;

    case 'save':
        $repo = trim((string)($_POST['repo'] ?? ''));
        $proxy = trim((string)($_POST['proxy'] ?? ''));
        $apiProxy = trim((string)($_POST['api_proxy'] ?? ''));
        $hint = trim((string)($_POST['asset_hint'] ?? ''));

        if ($repo !== '' && !preg_match('#^[\w.\-]+/[\w.\-]+$#', $repo)) {
            json_response(['success' => false, 'message' => '仓库格式应为 owner/repo']);
        }
        foreach ([['下载加速源', $proxy], ['API 加速源', $apiProxy]] as $item) {
            [$label, $val] = $item;
            if ($val !== '' && strpos($val, '{url}') === false && !preg_match('#^https?://#i', $val)) {
                json_response(['success' => false, 'message' => $label . '必须以 http:// 或 https:// 开头，或包含 {url} 占位符']);
            }
            if ($val !== '' && !preg_match('#^https?://#i', $val)) {
                json_response(['success' => false, 'message' => $label . '格式不正确']);
            }
        }
        if ($hint !== '' && !preg_match('#^[\w.\-]+$#', $hint)) {
            json_response(['success' => false, 'message' => '安装包关键词只能包含字母、数字、点、横线']);
        }

        更新保存配置('update_repo', $repo === '' ? 更新默认仓库 : $repo);
        更新保存配置('update_proxy', $proxy);
        更新保存配置('update_api_proxy', $apiProxy);
        更新保存配置('update_asset_hint', $hint);
        后台操作记录('保存在线更新配置', $repo === '' ? 更新默认仓库 : $repo);
        json_response(['success' => true, 'message' => '已保存']);
        break;

    case 'check':
        $配置 = 更新配置();
        $错误 = '';
        $r = 更新检查($配置, $错误);
        if (empty($r['ok'])) {
            json_response(['success' => false, 'message' => $r['message'] ?? $错误 ?? '检查失败']);
        }
        json_response([
            'success' => true,
            'data' => $r,
        ]);
        break;

    case 'download':
        $配置 = 更新配置();
        $错误 = '';
        $info = 更新检查($配置, $错误);
        if (empty($info['ok'])) {
            json_response(['success' => false, 'message' => $info['message'] ?? $错误]);
        }
        if (empty($info['has_update'])) {
            json_response(['success' => false, 'message' => '当前已是最新版本 v' . $info['current']]);
        }
        $asset = $info['asset'];
        $url = (string)($asset['url'] ?? '');
        if ($url === '') {
            json_response(['success' => false, 'message' => '该 Release 没有可用的 zip 安装包']);
        }
        // 只允许 GitHub 域名，或用户显式配置的加速前缀
        $允许 = ['https://github.com/', 'https://api.github.com/', 'https://objects.githubusercontent.com/', 'https://codeload.github.com/'];
        $okDomain = false;
        foreach ($允许 as $p) {
            if (strpos($url, $p) === 0) { $okDomain = true; break; }
        }
        if (!$okDomain && $配置['proxy'] !== '' && strpos($url, rtrim($配置['proxy'], '/') . '/') === 0) {
            $okDomain = true;
        }
        if (!$okDomain) {
            json_response(['success' => false, 'message' => '安装包地址不在允许的域名内']);
        }

        // 清理上次残留
        更新删除目录(更新临时目录);
        @mkdir(更新临时目录, 0755, true);
        $zipPath = 更新临时目录 . 'package.zip';

        $下载 = 更新下载文件(更新代理地址($url, $配置['proxy']), $zipPath);
        $usedProxy = $配置['proxy'] !== '';
        // 加速源失败则回退直连
        if (!$下载['ok'] && $usedProxy) {
            $下载 = 更新下载文件($url, $zipPath);
            $usedProxy = false;
        }
        if (!$下载['ok']) {
            更新删除目录(更新临时目录);
            json_response(['success' => false, 'message' => '下载失败：' . $下载['error']]);
        }

        if (!class_exists('ZipArchive')) {
            更新删除目录(更新临时目录);
            json_response(['success' => false, 'message' => '服务器未安装 php-zip 扩展，无法解压。请安装后在 php.ini 启用 extension=zip']);
        }

        $extract = 更新临时目录 . 'extract';
        $解压错误 = '';
        $count = 更新解压($zipPath, $extract, $解压错误);
        if ($count <= 0) {
            更新删除目录(更新临时目录);
            json_response(['success' => false, 'message' => $解压错误 !== '' ? $解压错误 : '解压失败']);
        }

        $根 = 更新包根($extract);
        // 预检：必须是一个完整的机器人包
        $必需 = ['index.php', 'db.php', 'admin/header.php', 'admin/api.php'];
        foreach ($必需 as $f) {
            if (!is_file($根 . '/' . $f)) {
                更新删除目录(更新临时目录);
                json_response(['success' => false, 'message' => '安装包不完整，缺少 ' . $f . '，已中止']);
            }
        }
        $文件数 = count(更新收集文件($根));
        file_put_contents(更新临时目录 . 'meta.json', json_encode([
            'root' => $根,
            'tag' => $info['tag'],
            'version' => $info['version'],
            'asset' => $asset['name'],
            'size' => $下载['size'],
            'proxy' => $usedProxy,
            'at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE));

        json_response([
            'success' => true,
            'message' => '安装包已就绪',
            'data' => [
                'version' => $info['version'],
                'tag' => $info['tag'],
                'asset' => $asset['name'],
                'size' => $下载['size'],
                'files' => $文件数,
                'proxy' => $usedProxy,
            ],
        ]);
        break;

    case 'apply':
        $metaFile = 更新临时目录 . 'meta.json';
        if (!is_file($metaFile)) {
            json_response(['success' => false, 'message' => '没有待应用的安装包，请先下载']);
        }
        $meta = json_decode((string)@file_get_contents($metaFile), true);
        if (!is_array($meta) || empty($meta['root']) || !is_dir($meta['root'])) {
            json_response(['success' => false, 'message' => '安装包已失效，请重新下载']);
        }
        $r = 更新应用($meta['root'], (string)($meta['tag'] ?? ''));
        更新删除目录(更新临时目录);
        if (empty($r['ok'])) {
            json_response(['success' => false, 'message' => $r['message']]);
        }
        后台操作记录('系统在线更新', 'v' . ($r['version'] ?? '') . ' / ' . ($meta['tag'] ?? ''));
        json_response([
            'success' => true,
            'message' => $r['message'] . '，当前版本 v' . ($r['version'] ?? ''),
            'data' => $r,
        ]);
        break;

    case 'clear_temp':
        更新删除目录(更新临时目录);
        json_response(['success' => true, 'message' => '临时文件已清理']);
        break;

    default:
        json_response(['success' => false, 'message' => '未知操作'], 400);
}
