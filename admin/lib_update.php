<?php
/**
 * 系统在线更新核心库
 *
 * 从 GitHub Releases 拉取最新版本，支持下载加速源、断点失败自动回退直连、
 * 备份与回滚。仅由后台调用。
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__) . '/');
}

define('更新默认仓库', 'yzdz666/QQbot');
define('更新临时目录', APP_ROOT . 'data/update_tmp/');
define('更新备份目录', APP_ROOT . 'data/update_backups/');
define('更新状态文件', APP_ROOT . 'data/update_state.json');
define('更新单包上限', 41943040);   // 40MB
define('更新解压上限', 83886080);   // 80MB

/** 读取更新配置 */
function 更新配置(): array {
    $c = [
        'repo'       => 更新默认仓库,
        'proxy'      => '',   // 下载加速前缀，空 = 直连
        'api_proxy'  => '',   // API 加速前缀，空 = 直连
        'asset_hint' => '',   // 资产名关键词，空 = 自动
    ];
    try {
        $rows = db()->fetchAll("SELECT key, value FROM settings WHERE key IN ('update_repo','update_proxy','update_api_proxy','update_asset_hint')");
        foreach ($rows as $r) {
            switch ($r['key']) {
                case 'update_repo':       if (trim((string)$r['value']) !== '') $c['repo'] = trim((string)$r['value']); break;
                case 'update_proxy':      $c['proxy'] = trim((string)$r['value']); break;
                case 'update_api_proxy':  $c['api_proxy'] = trim((string)$r['value']); break;
                case 'update_asset_hint': $c['asset_hint'] = trim((string)$r['value']); break;
            }
        }
    } catch (Throwable $e) {
        // 数据表未就绪时使用默认值
    }
    if (!preg_match('#^[\w.\-]+/[\w.\-]+$#', $c['repo'])) {
        $c['repo'] = 更新默认仓库;
    }
    return $c;
}

/** 保存单项配置 */
function 更新保存配置(string $key, string $value): void {
    $allow = ['update_repo', 'update_proxy', 'update_api_proxy', 'update_asset_hint'];
    if (!in_array($key, $allow, true)) return;
    db()->execute(
        "INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now','localtime'))
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
        [$key, $value]
    );
}

/** 当前安装版本 */
function 更新当前版本(): string {
    $file = APP_ROOT . 'admin/version.php';
    if (is_file($file)) {
        // OPcache 可能缓存旧文件，读取前强制失效
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        $info = @include $file;
        if (is_array($info) && !empty($info['version'])) {
            return (string)$info['version'];
        }
    }
    return '0.0.0';
}

/** 版本号规范化：v1.2.3 / 1.2.3-beta → 1.2.3 */
function 更新规范版本($tag): string {
    $tag = trim((string)$tag);
    if (preg_match('/\d+(?:\.\d+){1,3}/', $tag, $m)) {
        return $m[0];
    }
    return $tag;
}

/** 给地址套上加速前缀 */
function 更新代理地址(string $url, string $proxy): string {
    $proxy = trim($proxy);
    if ($proxy === '') return $url;
    if (strpos($proxy, '{url}') !== false) {
        return str_replace('{url}', $url, $proxy);
    }
    return rtrim($proxy, '/') . '/' . $url;
}

/**
 * HTTP 请求（JSON/文本）
 *
 * @return array{ok:bool,status:int,headers:array,body:string,error:string}
 */
function 更新请求(string $url, int $timeout = 20, int $maxBytes = 2097152, array $headers = []): array {
    if (!preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'status' => 0, 'headers' => [], 'body' => '', 'error' => '仅支持 http/https 地址'];
    }
    $respHeaders = [];
    $body = '';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GuanjiBot-Updater/1.0 (+https://github.com/' . 更新默认仓库 . ')');
    $h = array_merge(['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'], $headers);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$respHeaders) {
        $pos = strpos($line, ':');
        if ($pos !== false) {
            $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }
        return strlen($line);
    });
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$body, $maxBytes) {
        if (strlen($body) + strlen($chunk) > $maxBytes) return 0;
        $body .= $chunk;
        return strlen($chunk);
    });
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($ok === false) {
        return ['ok' => false, 'status' => $status, 'headers' => $respHeaders, 'body' => $body, 'error' => $err !== '' ? $err : '请求失败或响应过大'];
    }
    return ['ok' => true, 'status' => $status, 'headers' => $respHeaders, 'body' => $body, 'error' => ''];
}

/**
 * 下载文件到本地（流式，避免占内存）
 *
 * @return array{ok:bool,status:int,size:int,error:string}
 */
function 更新下载文件(string $url, string $dest, int $timeout = 300, int $maxBytes = 更新单包上限): array {
    if (!preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'status' => 0, 'size' => 0, 'error' => '仅支持 http/https 地址'];
    }
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'status' => 0, 'size' => 0, 'error' => '无法创建下载目录'];
    }
    $fp = @fopen($dest, 'wb');
    if (!$fp) {
        return ['ok' => false, 'status' => 0, 'size' => 0, 'error' => '无法写入临时文件'];
    }
    $written = 0;
    $tooBig = false;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 6);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 25);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
    curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GuanjiBot-Updater/1.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/octet-stream']);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($fp, &$written, $maxBytes, &$tooBig) {
        $len = strlen($chunk);
        if ($written + $len > $maxBytes) {
            $tooBig = true;
            return 0;
        }
        $n = fwrite($fp, $chunk);
        $written += (int)$n;
        return $n === false ? 0 : $n;
    });
    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    fclose($fp);
    if ($tooBig) {
        @unlink($dest);
        return ['ok' => false, 'status' => $status, 'size' => $written, 'error' => '安装包超过 ' . round($maxBytes / 1048576) . 'MB 限制'];
    }
    if ($ok === false) {
        @unlink($dest);
        $msg = $err;
        if ($msg === '') {
            $msg = '传输中断（curl #' . $errno . '）';
        }
        if ($errno === 28) {
            $msg = '下载超时，可尝试填写下载加速源';
        } elseif ($errno === 6) {
            $msg = '无法解析下载地址，请检查 DNS 或使用加速源';
        } elseif ($errno === 7) {
            $msg = '无法连接下载服务器，可尝试填写下载加速源';
        }
        return ['ok' => false, 'status' => $status, 'size' => $written, 'error' => $msg];
    }
    if ($status >= 400) {
        @unlink($dest);
        return ['ok' => false, 'status' => $status, 'size' => $written, 'error' => '服务器返回 HTTP ' . $status];
    }
    if (stripos($ctype, 'text/html') !== false) {
        @unlink($dest);
        return ['ok' => false, 'status' => $status, 'size' => $written, 'error' => '返回的是网页而不是安装包，加速源可能不可用'];
    }
    if ($written < 1024) {
        @unlink($dest);
        return ['ok' => false, 'status' => $status, 'size' => $written, 'error' => '安装包内容过小，疑似下载失败'];
    }
    return ['ok' => true, 'status' => $status, 'size' => $written, 'error' => ''];
}

/** 查询最新 Release */
function 更新检查(array $配置, string &$错误 = ''): array {
    $api = 'https://api.github.com/repos/' . $配置['repo'] . '/releases/latest';
    $尝试 = [];
    if ($配置['api_proxy'] !== '') {
        $尝试[] = 更新代理地址($api, $配置['api_proxy']);
    }
    $尝试[] = $api;

    $lastError = '';
    foreach ($尝试 as $url) {
        $r = 更新请求($url, 20, 2097152);
        if (!$r['ok']) {
            $lastError = $r['error'] !== '' ? $r['error'] : '网络不可达';
            continue;
        }
        if ($r['status'] === 404) {
            $错误 = '仓库还没有发布任何 Release';
            return ['ok' => false, 'message' => $错误];
        }
        if ($r['status'] === 403) {
            $错误 = 'GitHub API 访问受限（可能是频率限制），请稍后重试或配置 API 加速源';
            return ['ok' => false, 'message' => $错误];
        }
        if ($r['status'] !== 200) {
            $lastError = 'GitHub API 返回 HTTP ' . $r['status'];
            continue;
        }
        $data = json_decode($r['body'], true);
        if (!is_array($data) || empty($data['tag_name'])) {
            $lastError = 'Release 数据解析失败';
            continue;
        }

        $tag = (string)$data['tag_name'];
        $version = 更新规范版本($tag);
        $current = 更新当前版本();

        // 选择安装包
        $assets = [];
        foreach ((array)($data['assets'] ?? []) as $a) {
            $assets[] = [
                'name' => (string)($a['name'] ?? ''),
                'size' => (int)($a['size'] ?? 0),
                'url'  => (string)($a['browser_download_url'] ?? ''),
                'downloads' => (int)($a['download_count'] ?? 0),
            ];
        }
        $pick = null;
        $hint = $配置['asset_hint'];
        foreach ($assets as $a) {
            if (!preg_match('/\.zip$/i', $a['name'])) continue;
            if ($hint !== '' && stripos($a['name'], $hint) !== false) { $pick = $a; break; }
        }
        if (!$pick) {
            foreach ($assets as $a) {
                if (!preg_match('/\.zip$/i', $a['name'])) continue;
                if (preg_match('/源码|source|market|插件市场站点/i', $a['name'])) continue;
                $pick = $a;
                break;
            }
        }
        if (!$pick) {
            foreach ($assets as $a) {
                if (preg_match('/\.zip$/i', $a['name'])) { $pick = $a; break; }
            }
        }
        if (!$pick) {
            $pick = [
                'name' => 'source.zip',
                'size' => 0,
                'url'  => (string)($data['zipball_url'] ?? ''),
                'downloads' => 0,
            ];
        }

        return [
            'ok' => true,
            'current' => $current,
            'version' => $version,
            'tag' => $tag,
            'name' => (string)($data['name'] ?? $tag),
            'body' => (string)($data['body'] ?? ''),
            'published_at' => (string)($data['published_at'] ?? ''),
            'html_url' => (string)($data['html_url'] ?? ''),
            'prerelease' => !empty($data['prerelease']),
            'assets' => $assets,
            'asset' => $pick,
            'has_update' => version_compare($version, $current, '>'),
            'repo' => $配置['repo'],
        ];
    }

    $错误 = $lastError !== '' ? $lastError : '无法连接 GitHub';
    return ['ok' => false, 'message' => $错误];
}

/** 清理目录 */
function 更新删除目录(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir()) {
            @rmdir($f->getPathname());
        } else {
            @unlink($f->getPathname());
        }
    }
    @rmdir($dir);
}

/** 安全解压 zip */
function 更新解压(string $zipPath, string $destDir, string &$错误 = ''): int {
    if (!class_exists('ZipArchive')) {
        $错误 = '服务器未安装 php-zip 扩展，无法解压安装包';
        return 0;
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        $错误 = '安装包损坏，无法打开';
        return 0;
    }
    if ($zip->numFiles > 4000) {
        $zip->close();
        $错误 = '安装包文件数量异常（' . $zip->numFiles . '），已中止';
        return 0;
    }

    @mkdir($destDir, 0755, true);
    $total = 0;
    $count = 0;
    $base = rtrim(str_replace('\\', '/', realpath($destDir)), '/');

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!$stat) continue;
        $name = str_replace('\\', '/', (string)$stat['name']);
        $name = ltrim($name, '/');
        if ($name === '' || substr($name, -1) === '/') continue;
        if (preg_match('#(^|/)\.\.(/|$)#', $name) || preg_match('#^[A-Za-z]:#', $name)) {
            $zip->close();
            $错误 = '安装包包含非法路径：' . $name;
            return 0;
        }
        $total += (int)$stat['size'];
        if ($total > 更新解压上限) {
            $zip->close();
            $错误 = '安装包解压后超过 ' . round(更新解压上限 / 1048576) . 'MB，已中止';
            return 0;
        }
        $target = $destDir . '/' . $name;
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            continue;
        }
        $real = realpath($dir);
        if ($real === false || strpos(str_replace('\\', '/', $real), $base) !== 0) {
            $zip->close();
            $错误 = '安装包路径越界：' . $name;
            return 0;
        }
        $stream = $zip->getStream($stat['name']);
        if (!$stream) continue;
        $out = @fopen($target, 'wb');
        if ($out) {
            while (!feof($stream)) {
                $buf = fread($stream, 262144);
                if ($buf === false) break;
                fwrite($out, $buf);
            }
            fclose($out);
        }
        fclose($stream);
        $count++;
    }
    $zip->close();
    return $count;
}

/** 探测包根目录（zipball 会多一层 owner-repo-sha/） */
function 更新包根(string $dir): string {
    $dir = rtrim($dir, '/');
    if (is_file($dir . '/index.php')) return $dir;
    $items = @scandir($dir);
    if (!$items) return $dir;
    $dirs = [];
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        if (is_dir($dir . '/' . $it)) $dirs[] = $it;
    }
    if (count($dirs) === 1) {
        $sub = $dir . '/' . $dirs[0];
        if (is_file($sub . '/index.php')) return $sub;
    }
    return $dir;
}

/** 该相对路径是否应跳过（用户数据与站点配置） */
function 更新跳过路径(string $rel): bool {
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $前缀 = ['data/', 'plugin/', 'market/data/', 'market/storage/', '.git/'];
    foreach ($前缀 as $p) {
        if (strpos($rel, $p) === 0) return true;
    }
    if ($rel === 'market/config.php') return true;
    if ($rel === '.gitignore') return false;
    return false;
}

/** 递归收集相对路径 */
function 更新收集文件(string $root): array {
    $root = rtrim($root, '/');
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir()) continue;
        $path = str_replace('\\', '/', $f->getPathname());
        $rel = ltrim(substr($path, strlen($root)), '/');
        if ($rel === '' || 更新跳过路径($rel)) continue;
        $out[] = $rel;
    }
    sort($out);
    return $out;
}

/** 读取更新状态 */
function 更新状态(): array {
    if (!is_file(更新状态文件)) {
        return ['version' => 更新当前版本(), 'updated_at' => '', 'backup' => '', 'tag' => ''];
    }
    $d = json_decode((string)@file_get_contents(更新状态文件), true);
    if (!is_array($d)) {
        return ['version' => 更新当前版本(), 'updated_at' => '', 'backup' => '', 'tag' => ''];
    }
    if (empty($d['version'])) $d['version'] = 更新当前版本();
    return $d;
}

/** 写入更新状态 */
function 更新写状态(array $data): void {
    $dir = dirname(更新状态文件);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents(更新状态文件, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/** 备份单个文件（保持相对路径） */
function 更新备份文件(string $rel, string $备份根): bool {
    $src = APP_ROOT . $rel;
    if (!is_file($src)) return true; // 新文件，无需备份
    $dst = $备份根 . '/' . $rel;
    $dir = dirname($dst);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    return @copy($src, $dst);
}

/** 原子写入单个文件 */
function 更新写入文件(string $src, string $rel): bool {
    $dst = APP_ROOT . $rel;
    $dir = dirname($dst);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $tmp = $dst . '.upd_' . substr(md5((string)microtime(true)), 0, 8);
    if (!@copy($src, $tmp)) return false;
    @chmod($tmp, preg_match('/\.sh$/', $rel) ? 0755 : 0644);
    if (!@rename($tmp, $dst)) {
        @unlink($tmp);
        // 跨分区或 Windows 占位：退化为直接覆盖
        if (!@copy($src, $dst)) return false;
        @chmod($dst, preg_match('/\.sh$/', $rel) ? 0755 : 0644);
    }
    return true;
}

/**
 * 应用更新
 *
 * @param string $srcDir 已解压的包根目录
 * @param string $tag    版本标签，用于记录
 * @return array{ok:bool,message:string,files:int,backup:string}
 */
function 更新应用(string $srcDir, string $tag = ''): array {
    $files = 更新收集文件($srcDir);
    if (!$files) {
        return ['ok' => false, 'message' => '安装包内没有可更新的文件', 'files' => 0, 'backup' => ''];
    }

    $ts = date('YmdHis');
    $备份相对 = 'data/update_backups/' . $ts;
    $备份根 = APP_ROOT . $备份相对;
    if (!is_dir($备份根) && !@mkdir($备份根, 0755, true) && !is_dir($备份根)) {
        return ['ok' => false, 'message' => '无法创建备份目录，请检查 data/ 写入权限', 'files' => 0, 'backup' => ''];
    }

    // 1. 备份所有将被覆盖的文件
    foreach ($files as $rel) {
        if (!更新备份文件($rel, $备份根)) {
            return ['ok' => false, 'message' => '备份失败：' . $rel . '（请检查文件权限）', 'files' => 0, 'backup' => $备份相对];
        }
    }

    // 2. 逐个写入，失败则回滚
    $done = [];
    foreach ($files as $rel) {
        if (!更新写入文件($srcDir . '/' . $rel, $rel)) {
            foreach ($done as $d) {
                $bak = $备份根 . '/' . $d;
                if (is_file($bak)) {
                    @copy($bak, APP_ROOT . $d);
                } else {
                    @unlink(APP_ROOT . $d);
                }
            }
            return ['ok' => false, 'message' => '写入失败：' . $rel . '，已回滚本次改动', 'files' => 0, 'backup' => $备份相对];
        }
        $done[] = $rel;
    }

    // 3. 记录状态（版本取安装包内的定义，避免 OPcache 读到旧文件）
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    clearstatcache(true);
    $ver = '';
    $版本文件 = rtrim($srcDir, '/') . '/admin/version.php';
    if (is_file($版本文件)) {
        $info = @include $版本文件;
        if (is_array($info) && !empty($info['version'])) {
            $ver = (string)$info['version'];
        }
    }
    if ($ver === '') {
        $ver = 更新规范版本($tag);
    }
    if ($ver === '') {
        $ver = 更新当前版本();
    }
    更新写状态([
        'version' => $ver,
        'tag' => $tag,
        'updated_at' => date('Y-m-d H:i:s'),
        'files' => count($files),
        'backup' => $备份相对,
    ]);

    // 4. 清理旧备份，只保留最近 3 次
    $all = glob(APP_ROOT . 'data/update_backups/*', GLOB_ONLYDIR) ?: [];
    sort($all);
    while (count($all) > 3) {
        $old = array_shift($all);
        if ($old) 更新删除目录($old);
    }

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    return [
        'ok' => true,
        'message' => '更新完成，共写入 ' . count($files) . ' 个文件',
        'files' => count($files),
        'backup' => $备份相对,
        'version' => $ver,
    ];
}

/** 列出本地备份 */
function 更新备份列表(): array {
    $out = [];
    foreach (glob(APP_ROOT . 'data/update_backups/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $out[] = [
            'name' => basename($dir),
            'time' => date('Y-m-d H:i:s', filemtime($dir)),
            'files' => iterator_count(new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            )),
        ];
    }
    usort($out, function ($a, $b) { return strcmp($b['name'], $a['name']); });
    return $out;
}
