<?php
/**
 * 官鸡插件市场 - 安全工具库
 * 提供输出转义、安全响应头、CSRF、限流、代码静态扫描等能力。
 */

/** HTML 转义 */
function market_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 是否 HTTPS */
function market_is_https() {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    return isset($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) === 443;
}

/**
 * 推断市场根路径（不含末尾斜杠）
 *
 * 返回的是「路径前缀」而不是完整 URL，例如：
 *   - 独立部署在域名根目录 → ''（拼接后为 /index.php）
 *   - 部署在子目录 /market  → '/market'
 *
 * 这样页面里的链接不依赖域名与协议，反向代理 / 自定义域名 / HTTPS 下都不会串到内网地址。
 * 如需完整地址（如对外文档），请配置 MARKET_SITE_URL 后使用 market_absolute_url()。
 */
function market_base_url() {
    if (defined('MARKET_SITE_URL') && MARKET_SITE_URL !== '') {
        $path = parse_url(MARKET_SITE_URL, PHP_URL_PATH);
        return rtrim((string)$path, '/');
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    // admin 子目录下调用时回退两级
    if (substr($dir, -6) === '/admin') {
        $dir = substr($dir, 0, -6);
    }
    return $dir === '/' ? '' : $dir;
}

/** 完整站点地址（用于对外文档、API 说明等需要绝对地址的场景） */
function market_absolute_url() {
    if (defined('MARKET_SITE_URL') && MARKET_SITE_URL !== '') {
        return rtrim(MARKET_SITE_URL, '/');
    }
    $scheme = market_is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[A-Za-z0-9\.\-]+(:\d+)?$/', $host)) {
        $host = 'localhost';
    }
    return $scheme . '://' . $host . market_base_url();
}

/** 客户端 IP（只信任 REMOTE_ADDR，避免 X-Forwarded-For 伪造） */
function market_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** JSON 输出并结束 */
function market_json_out($data, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 生成 CSP nonce（每个请求固定） */
function market_nonce() {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/** 安全响应头（市场前后台统一） */
function market_security_headers($isAdmin = false) {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: ' . ($isAdmin ? 'DENY' : 'SAMEORIGIN'));
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    $nonce = market_nonce();
    $csp = "default-src 'self'; "
         . "script-src 'self' 'nonce-{$nonce}'; "
         . "style-src 'self' 'unsafe-inline'; "
         . "img-src 'self' data: https:; "
         . "font-src 'self' data:; "
         . "connect-src 'self'; "
         . "frame-ancestors 'none'; "
         . "base-uri 'self'; "
         . "form-action 'self'";
    header('Content-Security-Policy: ' . $csp);
    if (market_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/** 启动会话（仅后台/需要 CSRF 的页面） */
function market_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = market_is_https();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $secure,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/', '', $secure, true);
    }
    session_name('MARKETSESSID');
    session_start();
}

/** CSRF token */
function market_csrf_token() {
    market_session_start();
    if (empty($_SESSION['market_csrf'])) {
        $_SESSION['market_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['market_csrf'];
}

/** 校验 CSRF（失败直接 JSON/文本输出） */
function market_csrf_check($token = null) {
    market_session_start();
    $token = $token !== null ? $token : ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = $_SESSION['market_csrf'] ?? '';
    if ($expected === '' || !is_string($token) || !hash_equals($expected, $token)) {
        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
            || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            market_json_out(['success' => false, 'message' => 'CSRF 校验失败，请刷新页面后重试'], 403);
        }
        http_response_code(403);
        exit('CSRF validation failed');
    }
}

/** 同源校验（补充 SameSite，防止子域或浏览器兼容问题） */
function market_same_origin_check() {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return;
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $source = $origin !== '' ? $origin : $referer;
    if ($source === '') return; // 命令行/服务端调用
    $sourceHost = parse_url($source, PHP_URL_HOST);
    $hostOnly = explode(':', $host)[0];
    if ($sourceHost && strcasecmp($sourceHost, $hostOnly) !== 0) {
        market_json_out(['success' => false, 'message' => '非法来源请求'], 403);
    }
}

/** 简单限流：$max 次 / $window 秒。返回 true 表示允许 */
function market_rate_limit($key, $max, $window) {
    $pdo = MarketDB::pdo();
    $now = time();
    $bucket = hash('sha256', $key);
    try {
        $pdo->prepare("DELETE FROM rate_limits WHERE ts < ?")->execute([$now - 86400]);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE k = ? AND ts > ?");
        $stmt->execute([$bucket, $now - $window]);
        $count = (int)$stmt->fetchColumn();
        if ($count >= $max) return false;
        $pdo->prepare("INSERT INTO rate_limits (k, ts) VALUES (?, ?)")->execute([$bucket, $now]);
        return true;
    } catch (Throwable $e) {
        return true; // 限流表异常不应阻断业务
    }
}

/** 审计日志 */
function market_audit($admin, $action, $detail = '') {
    try {
        MarketDB::pdo()->prepare(
            "INSERT INTO audit_logs (admin, action, detail, ip) VALUES (?, ?, ?, ?)"
        )->execute([(string)$admin, (string)$action, (string)$detail, market_client_ip()]);
    } catch (Throwable $e) {
        // 忽略
    }
}

/** 读取设置 */
function market_setting($key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (MarketDB::pdo()->query("SELECT k, v FROM settings") as $row) {
                $cache[$row['k']] = $row['v'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/** 写入设置 */
function market_set_setting($key, $value) {
    $pdo = MarketDB::pdo();
    $pdo->prepare("INSERT OR IGNORE INTO settings (k, v) VALUES (?, ?)")->execute([$key, (string)$value]);
    $pdo->prepare("UPDATE settings SET v = ? WHERE k = ?")->execute([(string)$value, $key]);
}

/** 版本比较，返回 -1/0/1 */
function market_version_cmp($a, $b) {
    $a = preg_replace('/^v/i', '', trim((string)$a));
    $b = preg_replace('/^v/i', '', trim((string)$b));
    return version_compare($a, $b);
}

/** 由名称生成 slug（仅保留安全字符） */
function market_slugify($name) {
    $slug = strtolower(trim((string)$name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    if ($slug === '' || strlen($slug) < 2) {
        $slug = 'plugin-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
    return substr($slug, 0, 64);
}

/** 安全插件文件名：保留字母数字中文下划线横杠与空格，去除路径分隔符 */
function market_safe_plugin_name($name) {
    $name = trim((string)$name);
    $name = str_replace(['.php', '.PHP'], '', $name);
    $name = preg_replace('/[\/\\\\:\*\?"<>\|\x00-\x1f]/u', '', $name);
    $name = trim($name, " .\t\n\r\0\x0B");
    if ($name === '' || $name === '.' || $name === '..') return '';
    if (mb_strlen($name, 'UTF-8') > 60) $name = mb_substr($name, 0, 60, 'UTF-8');
    return $name;
}

/** 将注释/字符串替换为空格（保留换行），用于代码扫描降噪 */
function market_strip_code($code) {
    $out = '';
    try {
        $tokens = token_get_all($code);
    } catch (Throwable $e) {
        return $code;
    }
    foreach ($tokens as $token) {
        if (is_array($token)) {
            $id = $token[0];
            $text = $token[1];
            if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $out .= preg_replace('/[^\n]/', ' ', $text);
            } else {
                $out .= $text;
            }
        } else {
            $out .= $token;
        }
    }
    return $out;
}

/** 计算偏移量所在行号 */
function market_line_of($code, $offset) {
    return substr_count(substr($code, 0, max(0, $offset)), "\n") + 1;
}

/**
 * 插件代码静态安全扫描
 * 注意：静态扫描只能降低风险，不能替代人工审计。
 * @return array{status:string,issues:array,summary:string}
 */
function market_scan_code($code) {
    $issues = [];
    $code = (string)$code;

    if (trim($code) === '') {
        return ['status' => 'block', 'issues' => [['level' => 'block', 'line' => 1, 'code' => 'empty', 'message' => '代码为空']], 'summary' => '代码为空'];
    }
    if (stripos($code, '<?php') === false) {
        return ['status' => 'block', 'issues' => [['level' => 'block', 'line' => 1, 'code' => 'no_php', 'message' => '缺少 <?php 起始标签']], 'summary' => '不是有效的 PHP 文件'];
    }

    // 语法检查（TOKEN_PARSE 会在语法错误时抛 ParseError）
    try {
        token_get_all($code, TOKEN_PARSE);
    } catch (Throwable $e) {
        $issues[] = [
            'level' => 'block',
            'line' => max(1, (int)$e->getLine()),
            'code' => 'syntax',
            'message' => 'PHP 语法错误：' . $e->getMessage(),
        ];
    }

    $clean = market_strip_code($code);

    $blockRules = [
        'eval'           => ['/\beval\s*\(/i', 'eval() 可执行任意代码'],
        'assert'         => ['/\bassert\s*\(/i', 'assert() 可被用于执行代码'],
        'create_function'=> ['/\bcreate_function\s*\(/i', 'create_function() 可动态创建函数'],
        'shell'          => ['/\b(shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(/i', '调用系统命令执行函数'],
        'backtick'       => ['/`[^`\n]{1,500}`/', '反引号执行系统命令'],
        'preg_e'         => ['/preg_replace\s*\(\s*([\'"])[^\'"]*\/[a-z]*e[a-z]*\1/i', 'preg_replace /e 修饰符可执行代码'],
        'callable_string'=> ['/\bcall_user_func(_array)?\s*\(\s*([\'"])(assert|eval|system|exec)\2/i', '通过回调执行危险函数'],
    ];

    foreach ($blockRules as $ruleCode => $rule) {
        if (preg_match_all($rule[0], $clean, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $m) {
                $issues[] = [
                    'level' => 'block',
                    'line' => market_line_of($clean, $m[1]),
                    'code' => $ruleCode,
                    'message' => $rule[1],
                ];
            }
        }
    }

    $warnRules = [
        'file_write' => ['/\b(file_put_contents|fwrite|fopen|rename|copy|unlink|rmdir|chmod)\s*\(/i', '存在文件写入/删除操作，请确认路径受控'],
        'network'    => ['/\b(curl_init|curl_exec|fsockopen|stream_socket_client|mail)\s*\(/i', '存在网络/邮件调用，请确认目标可信'],
        'dynamic'    => ['/\b(base64_decode|gzinflate|str_rot13|extract|parse_str)\s*\(/i', '存在动态解码/变量导入，请人工确认用途'],
        'globals'    => ['/\$GLOBALS\b/', '直接操作 $GLOBALS'],
        'include_var'=> ['/\b(include|require)(_once)?\s*\(?\s*\$/', '动态包含文件，可能存在任意文件包含风险'],
        'db'         => ['/\bnew\s+PDO\s*\(/i', '插件直接创建数据库连接，请确认不会破坏主库'],
    ];

    foreach ($warnRules as $ruleCode => $rule) {
        if (preg_match_all($rule[0], $clean, $matches, PREG_OFFSET_CAPTURE)) {
            foreach (array_slice($matches[0], 0, 8) as $m) {
                $issues[] = [
                    'level' => 'warn',
                    'line' => market_line_of($clean, $m[1]),
                    'code' => $ruleCode,
                    'message' => $rule[1],
                ];
            }
        }
    }

    // 去重（同一行同一规则只保留一条）
    $seen = [];
    $unique = [];
    foreach ($issues as $issue) {
        $key = $issue['level'] . '|' . $issue['line'] . '|' . $issue['code'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = $issue;
    }
    usort($unique, function ($a, $b) {
        if ($a['line'] === $b['line']) return strcmp($a['code'], $b['code']);
        return $a['line'] <=> $b['line'];
    });

    $hasBlock = false;
    $hasWarn = false;
    foreach ($unique as $issue) {
        if ($issue['level'] === 'block') $hasBlock = true;
        if ($issue['level'] === 'warn') $hasWarn = true;
    }
    $status = $hasBlock ? 'block' : ($hasWarn ? 'warn' : 'pass');
    $summary = $status === 'pass' ? '未发现明显风险' : ($status === 'warn' ? '存在需人工确认的调用' : '检测到高危代码，禁止直接安装');

    return ['status' => $status, 'issues' => $unique, 'summary' => $summary];
}

/** 从插件文件头解析元信息（@name / @version / @author ...） */
function market_parse_meta($code) {
    $meta = [
        'name' => '', 'version' => '', 'author' => '', 'description' => '',
        'category' => '', 'link' => '', 'license' => '',
    ];
    $head = substr((string)$code, 0, 8192);
    $map = [
        'name' => ['@name', '@plugin-name', 'Plugin Name:'],
        'version' => ['@version', '@plugin-version', 'Version:'],
        'author' => ['@author', '@plugin-author', 'Author:'],
        'description' => ['@description', '@plugin-description', 'Description:'],
        'category' => ['@category', '@plugin-category', 'Category:'],
        'link' => ['@link', '@plugin-link', 'Plugin URI:'],
        'license' => ['@license', 'License:'],
    ];
    foreach ($map as $field => $tags) {
        foreach ($tags as $tag) {
            $pattern = '/' . preg_quote($tag, '/') . '\s*[:：]?\s*([^\r\n\*]+)/iu';
            if (preg_match($pattern, $head, $m)) {
                $meta[$field] = trim($m[1]);
                break;
            }
        }
    }
    return $meta;
}
