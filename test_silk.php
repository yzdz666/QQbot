<?php
/**
 * 语音转 Silk 多接口测试
 *
 * 覆盖：
 *   - SILK 二进制识别（含前导字节）
 *   - 字段路径解析
 *   - WorkBuddy：二进制返回 / JSON 回落 / 错误响应 / 参数拼装
 *   - oiapi：code=1 解析 / 失败拒绝
 *   - 自定义 API：JSON 取值 / 二进制 / POST + 请求头 + Body 模板
 *   - off 透传、未知 provider 回落
 *   - silk测试() 诊断结构
 *
 * 运行: php test_silk.php
 */

date_default_timezone_set('Asia/Shanghai');

$passCount = 0;
$failCount = 0;
$failures  = [];

function ok($name, $cond, $extra = '') {
    global $passCount, $failCount, $failures;
    if ($cond) {
        $passCount++;
        echo "  [PASS] {$name}\n";
    } else {
        $failCount++;
        $failures[] = $name . ($extra !== '' ? " ({$extra})" : '');
        echo "  [FAIL] {$name}" . ($extra !== '' ? " - {$extra}" : '') . "\n";
    }
}

// ==================== mock 配置层 ====================
class SilkTestDB {
    public $s = [];
    public function getSetting($k, $d = null) {
        return array_key_exists($k, $this->s) ? $this->s[$k] : $d;
    }
    public function setSetting($k, $v) { $this->s[$k] = $v; }
}
$GLOBALS['__testdb'] = new SilkTestDB();
function db() { return $GLOBALS['__testdb']; }
$DB = $GLOBALS['__testdb'];

require __DIR__ . '/function/silk.php';

// ==================== 启动 mock 接口服务器 ====================
$port   = 18799;
$router = sys_get_temp_dir() . '/silk_mock_router.php';

file_put_contents($router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/binary') {
    header('Content-Type: application/octet-stream');
    echo "#!SILK_V3" . str_repeat("\x00\x01\x02\x03", 60);
    exit;
}
if ($path === '/binary2') {
    header('Content-Type: application/octet-stream');
    echo "\x02#!SILK_V3" . str_repeat("\xAA\xBB", 40);
    exit;
}
if ($path === '/json') {
    header('Content-Type: application/json');
    echo json_encode(['code' => 1, 'data' => ['url' => 'https://example.com/ok.silk']]);
    exit;
}
if ($path === '/json2') {
    header('Content-Type: application/json');
    echo json_encode(['result' => [['audio' => 'https://example.com/nest.silk']]]);
    exit;
}
if ($path === '/bad') {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => ['code' => 'url_forbidden', 'message' => '403']]);
    exit;
}
if ($path === '/echo') {
    $hdrs = function_exists('getallheaders') ? getallheaders() : [];
    header('Content-Type: application/json');
    echo json_encode([
        'query'  => $_GET,
        'method' => $_SERVER['REQUEST_METHOD'],
        'body'   => file_get_contents('php://input'),
        'auth'   => $hdrs['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
        'ctype'  => $hdrs['Content-Type'] ?? ($_SERVER['CONTENT_TYPE'] ?? ''),
    ]);
    exit;
}

http_response_code(404);
echo 'not found';
PHP);

$cmd  = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router) . ' > /dev/null 2>&1';
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

$ready = false;
for ($i = 0; $i < 60; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($fp) { fclose($fp); $ready = true; break; }
    usleep(100000);
}
if (!$ready) {
    echo "[FATAL] mock 服务器启动失败\n";
    exit(1);
}

$base = 'http://127.0.0.1:' . $port;

// ==================== 1. 二进制识别 ====================
echo "\n== 1. SILK 二进制识别 ==\n";
ok('识别 #!SILK_V3 头', silk是二进制("#!SILK_V3" . str_repeat("\x00", 20)));
ok('识别带前导字节的 SILK', silk是二进制("\x02#!SILK_V3abc"));
ok('普通文本不误判', !silk是二进制('plain text response'));
ok('JSON 不误判', !silk是二进制('{"ok":true}'));
ok('空串不误判', !silk是二进制(''));

// ==================== 2. 字段路径 ====================
echo "\n== 2. 字段路径解析 ==\n";
ok('data.url', silk_pick(['data' => ['url' => 'u1']], 'data.url') === 'u1');
ok('result.0.audio', silk_pick(['result' => [['audio' => 'a1']]], 'result.0.audio') === 'a1');
ok('不存在的路径返回 null', silk_pick(['a' => 1], 'b.c') === null);
ok('空路径返回 null', silk_pick(['a' => 1], '') === null);

// ==================== 3. WorkBuddy ====================
echo "\n== 3. WorkBuddy 接口 ==\n";
$DB->s = [
    'silk_provider'       => 'workbuddy',
    'silk_wb_base'        => $base . '/binary',
    'silk_wb_sharecode'   => 'SC123',
    'silk_wb_sample_rate' => '24000',
    'silk_wb_frame_ms'    => '20',
    'silk_wb_complexity'  => '1',
    'silk_wb_tencent'     => '1',
    'silk_wb_timeout'     => '30',
];
$r = silk转换('https://example.com/a.mp3');
ok('直接返回 SILK 二进制', silk是二进制($r));
ok('二进制长度合理', strlen($r) > 50, 'len=' . strlen($r));

$DB->s['silk_wb_base'] = $base . '/echo';
silk_workbuddy('https://example.com/a b.mp3');
$last = $GLOBALS['__silk_http_last'];
$q = [];
parse_str((string)parse_url($last['url'] ?? '', PHP_URL_QUERY), $q);
ok('url 参数正确传递', ($q['url'] ?? '') === 'https://example.com/a b.mp3', $last['url'] ?? '');
ok('sample_rate=24000', ($q['sample_rate'] ?? '') === '24000');
ok('tencent=true', ($q['tencent'] ?? '') === 'true');
ok('frame_ms=20', ($q['frame_ms'] ?? '') === '20');
ok('complexity=1', ($q['complexity'] ?? '') === '1');
ok('sharecode 正确传递', ($q['sharecode'] ?? '') === 'SC123');

$DB->s['silk_wb_base'] = $base . '/json';
ok('兼容 JSON 返回 URL 的部署', silk转换('https://example.com/b.mp3') === 'https://example.com/ok.silk');

$DB->s['silk_wb_base'] = $base . '/bad';
ok('接口报错时返回空', silk转换('https://example.com/c.mp3') === '');

// ==================== 4. oiapi ====================
echo "\n== 4. oiapi 接口 ==\n";
$DB->s = ['silk_provider' => 'oiapi', 'silk_oiapi_base' => $base . '/json'];
ok('解析 code=1 的 data.url', silk转换('https://example.com/d.mp3') === 'https://example.com/ok.silk');

$DB->s['silk_oiapi_base'] = $base . '/bad';
ok('code!=1 时返回空', silk转换('https://example.com/e.mp3') === '');

// ==================== 5. 自定义 API ====================
echo "\n== 5. 自定义 API ==\n";
$DB->s = [
    'silk_provider'      => 'custom',
    'silk_custom_url'    => $base . '/json2?u={url}',
    'silk_custom_method' => 'GET',
    'silk_custom_parse'  => 'json',
    'silk_custom_field'  => 'result.0.audio',
];
ok('JSON 嵌套字段提取', silk转换('https://example.com/f.mp3') === 'https://example.com/nest.silk');

$DB->s = [
    'silk_provider'      => 'custom',
    'silk_custom_url'    => $base . '/binary2',
    'silk_custom_method' => 'GET',
    'silk_custom_parse'  => 'binary',
];
ok('二进制模式直出', silk是二进制(silk转换('https://example.com/g.mp3')));

$DB->s = [
    'silk_provider'       => 'custom',
    'silk_custom_url'     => $base . '/echo',
    'silk_custom_method'  => 'POST',
    'silk_custom_headers' => "Authorization: Bearer t1\nX-Test: v1",
    'silk_custom_body'    => '{"audio":"{url}"}',
    'silk_custom_parse'   => 'json',
    'silk_custom_field'   => 'body',
];
silk_custom('https://example.com/h.mp3');
$last = $GLOBALS['__silk_http_last'];
$hdrText = implode("\n", $last['headers'] ?? []);
ok('POST 方法正确', ($last['method'] ?? '') === 'POST');
ok('Authorization 头生效', strpos($hdrText, 'Authorization: Bearer t1') !== false, $hdrText);
ok('Body 模板替换为编码地址', strpos($last['body'] ?? '', 'https%3A%2F%2Fexample.com%2Fh.mp3') !== false, $last['body'] ?? '');
ok('POST 自动补 Content-Type', stripos($hdrText, 'Content-Type: application/json') !== false, $hdrText);

// ==================== 6. 关闭与回落 ====================
echo "\n== 6. 关闭与回落 ==\n";
$DB->s = ['silk_provider' => 'off'];
ok('off 模式原样返回', silk转换('https://example.com/i.mp3') === 'https://example.com/i.mp3');

$DB->s = ['silk_provider' => 'unknown_provider', 'silk_oiapi_base' => $base . '/json'];
ok('未知 provider 回落 oiapi', silk转换('https://example.com/j.mp3') === 'https://example.com/ok.silk');

// ==================== 7. 测试诊断 ====================
echo "\n== 7. 测试诊断结构 ==\n";
$DB->s = ['silk_provider' => 'workbuddy', 'silk_wb_base' => $base . '/binary'];
$t = silk测试('https://example.com/k.mp3');
ok('provider 字段', ($t['provider'] ?? '') === 'workbuddy');
ok('type=silk', ($t['type'] ?? '') === 'silk');
ok('bytes > 0', ($t['bytes'] ?? 0) > 0);
ok('HTTP 状态 200', ($t['http']['code'] ?? 0) === 200);
ok('elapsed_ms 为整数', is_int($t['elapsed_ms'] ?? null));
ok('诊断结果不含请求头', !isset($t['http']['headers']));
ok('诊断结果不含 Body', !isset($t['http']['body']));

// ==================== 清理 ====================
proc_terminate($proc);
@proc_close($proc);
@unlink($router);

// ==================== 汇总 ====================
echo "\n" . str_repeat('=', 46) . "\n";
echo "通过 {$passCount} 项，失败 {$failCount} 项\n";
if ($failCount) {
    echo "失败列表:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
}
echo str_repeat('=', 46) . "\n";
exit($failCount ? 1 : 0);
