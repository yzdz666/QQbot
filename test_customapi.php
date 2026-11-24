<?php
/**
 * 自定义 API 测试
 *
 * 覆盖：触发匹配、取值路径、模板渲染、请求构造、执行流程、保存校验、探测
 * 运行：php test_customapi.php
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

// ==================== mock 数据库（真实 SQLite 内存库） ====================
class CustomApiTestDB {
    public $pdo;
    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }
    public function execute($sql, $params = []) {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }
    public function fetchAll($sql, $params = []) { return $this->execute($sql, $params)->fetchAll(); }
    public function fetch($sql, $params = []) {
        $r = $this->execute($sql, $params)->fetch();
        return $r === false ? null : $r;
    }
    public function fetchColumn($sql, $params = []) {
        $r = $this->execute($sql, $params)->fetch(PDO::FETCH_NUM);
        return $r === false ? false : ($r[0] ?? false);
    }
    public function lastInsertId() { return (int)$this->pdo->lastInsertId(); }
    public function getSetting($k, $d = null) { return $d; }
    public function setSetting($k, $v) {}
}
$GLOBALS['__testdb'] = new CustomApiTestDB();
function db() { return $GLOBALS['__testdb']; }

$GLOBALS['__sent']    = [];
$GLOBALS['__admin']   = false;
$GLOBALS['__log']     = [];

function 文字($c) { $GLOBALS['__sent'][] = ['type' => 'text', 'text' => $c]; }
function MD($c) { $GLOBALS['__sent'][] = ['type' => 'md', 'text' => $c]; }
function 图片($u) { $GLOBALS['__sent'][] = ['type' => 'image', 'text' => $u]; }
function 语音($u) { $GLOBALS['__sent'][] = ['type' => 'voice', 'text' => $u]; }
function 视频($u) { $GLOBALS['__sent'][] = ['type' => 'video', 'args' => [$u]]; }
function 文件($u, $n = null) { $GLOBALS['__sent'][] = ['type' => 'file', 'args' => [$u, $n]]; }
function 图文卡片($t, $d, $p, $u) { $GLOBALS['__sent'][] = ['type' => 'card', 'args' => [$t, $d, $p, $u]]; }
function 大图($t, $s, $i) { $GLOBALS['__sent'][] = ['type' => 'bigimage', 'args' => [$t, $s, $i]]; }
function 跳转卡($t, $d, $i, $u) { $GLOBALS['__sent'][] = ['type' => 'linkcard', 'args' => [$t, $d, $i, $u]]; }
function Ark($id, $kv) { $GLOBALS['__sent'][] = ['type' => 'ark', 'args' => [$id, $kv]]; }
function Ark23($kv) { $GLOBALS['__sent'][] = ['type' => 'ark23', 'args' => [$kv]]; }
function 原生按钮($md, $rows) { $GLOBALS['__sent'][] = ['type' => 'keyboard', 'args' => [$md, $rows]]; }
function 按钮($k) { $GLOBALS['__sent'][] = ['type' => 'button', 'args' => [$k]]; }
function Emoji($id, $c = '') { $GLOBALS['__sent'][] = ['type' => 'emoji', 'args' => [$id, $c]]; }
function 引用($id, $c = '') { $GLOBALS['__sent'][] = ['type' => 'quote', 'args' => [$id, $c]]; }
function 引用图片($id, $u, $c = null) { $GLOBALS['__sent'][] = ['type' => 'quote_image', 'args' => [$id, $u, $c]]; }
function 引用MD($id, $md, $kb = null, $st = null) { $GLOBALS['__sent'][] = ['type' => 'quote_md', 'args' => [$id, $md]]; }
function 引用语音($id, $u, $c = null) { $GLOBALS['__sent'][] = ['type' => 'quote_voice', 'args' => [$id, $u, $c]]; }
function 引用视频($id, $u, $c = null) { $GLOBALS['__sent'][] = ['type' => 'quote_video', 'args' => [$id, $u, $c]]; }
function 引用文件($id, $u, $n = null, $c = null) { $GLOBALS['__sent'][] = ['type' => 'quote_file', 'args' => [$id, $u, $n, $c]]; }
function 撤回($id) { $GLOBALS['__sent'][] = ['type' => 'recall', 'args' => [$id]]; }
function 流式(...$m) { $GLOBALS['__sent'][] = ['type' => 'stream', 'args' => $m]; }
function 是否管理员($uid = null) { return $GLOBALS['__admin']; }
function wlog($c, $a = null) { $GLOBALS['__log'][] = $c; }

define('APP_ROOT', __DIR__ . '/');
define('appid', 'test_appid');
define('消息来源', '群聊');
define('来源', 'group_openid_1');
define('用户', 'user_openid_1');
define('消息ID', 'msg_openid_1');
define('消息', '天气 北京');
define('raw', [
    't' => 'GROUP_MEMBER_ADD',
    'd' => ['group_openid' => 'group_openid_1', 'member_openid' => 'member_openid_1'],
]);

require __DIR__ . '/function/customapi.php';

// ==================== mock 接口服务器 ====================
$port   = 18811;
$router = sys_get_temp_dir() . '/customapi_mock_router.php';

file_put_contents($router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/json') {
    header('Content-Type: application/json');
    echo json_encode(['code' => 200, 'data' => ['text' => '今天晴，18 度']], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($path === '/nested') {
    header('Content-Type: application/json');
    echo json_encode(['result' => [['url' => 'https://example.com/a.png']]], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($path === '/list') {
    header('Content-Type: application/json');
    echo json_encode(['list' => [['url' => ''], ['url' => 'https://example.com/b.png']]], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($path === '/text') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "  一句话返回  ";
    exit;
}
if ($path === '/err') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'boom']);
    exit;
}
if ($path === '/empty') {
    header('Content-Type: text/plain');
    echo '';
    exit;
}
if ($path === '/echo') {
    header('Content-Type: application/json');
    echo json_encode([
        'query'  => $_GET,
        'method' => $_SERVER['REQUEST_METHOD'],
        'body'   => file_get_contents('php://input'),
        'auth'   => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        'ctype'  => $_SERVER['CONTENT_TYPE'] ?? '',
        'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
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

// ==================== 1. 触发匹配 ====================
echo "\n== 1. 触发匹配 ==\n";
$api = customapi规范化([
    'name'       => '天气',
    'trigger'    => "天气, 查天气\n天气查询",
    'match_mode' => 'prefix',
    'url'        => $base . '/json?q={参数}',
]);

$args = customapi匹配单个($api, '天气', '天气 北京');
ok('前缀匹配命中', $args !== null);
ok('参数取到「北京」', $args && $args['全部'] === '北京');
ok('参数列表拆分', $args && $args['列表'] === ['北京']);
ok('前缀不命中其他消息', customapi匹配单个($api, '天气', '今天天气不错') === null);
ok('完全相等模式', customapi匹配单个(customapi规范化(['trigger' => '天气', 'match_mode' => 'exact']), '天气', '天气') !== null);
ok('完全相等不匹配多字', customapi匹配单个(customapi规范化(['trigger' => '天气', 'match_mode' => 'exact']), '天气', '天气 北京') === null);

$reApi = customapi规范化(['trigger' => '^查(.+)$', 'match_mode' => 'regex']);
$reArgs = customapi匹配单个($reApi, '^查(.+)$', '查快递');
ok('正则匹配命中', $reArgs !== null);
ok('正则捕获组变成 {1}', $reArgs && $reArgs['列表'][0] === '快递');

ok('触发词按逗号换行拆分', count(customapi触发词("a,b\nc、d")) === 4);

// ==================== 2. 取值路径 ====================
echo "\n== 2. 取值路径 ==\n";
$body = json_encode(['data' => ['text' => '你好', 'list' => [['url' => ''], ['url' => 'u2']]], 'result' => [['url' => 'a.png']]]);
$json = json_decode($body, true);

ok('data.text', customapi取路径($body, $json, 'data.text') === '你好');
ok('result.0.url', customapi取路径($body, $json, 'result.0.url') === 'a.png');
ok('data.list.*.url 跳过空值', customapi取路径($body, $json, 'data.list.*.url') === 'u2');
ok('$.data.text JSONPath 写法', customapi取路径($body, $json, '$.data.text') === '你好');
ok('@body 整段返回', customapi取路径('raw text', null, '@body') === 'raw text');
ok('@json 序列化', strpos(customapi取路径($body, $json, '@json'), '"data"') !== false);
ok('re: 正则提取', customapi取路径('订单号：A12345 已发货', null, 're:/订单号：([A-Z0-9]+)/') === 'A12345');
ok('re: 无捕获组取整个匹配', customapi取路径('abc123', null, 're:\d+') === '123');
ok('不存在的路径返回 null', customapi取路径($body, $json, 'data.nope') === null);
ok('非 JSON 用路径返回 null', customapi取路径('plain', null, 'data.text') === null);

// ==================== 3. 模板渲染 ====================
echo "\n== 3. 模板渲染 ==\n";
$vars = ['参数' => '北京', '1' => '北京', '结果' => '晴'];
ok('基础变量替换', customapi渲染('{参数}天气：{结果}', $vars) === '北京天气：晴');
ok('数字变量', customapi渲染('{1}', $vars) === '北京');
ok('未知变量原样保留', customapi渲染('{不存在}', $vars) === '{不存在}');
ok('URL 编码渲染', customapi渲染('?q={参数}', $vars, true) === '?q=%E5%8C%97%E4%BA%AC');
ok('数组变量转 JSON', customapi渲染('{a}', ['a' => ['x' => 1]]) === '{"x":1}');
ok('变量值不二次展开', customapi渲染('{a}', ['a' => '{b}', 'b' => 'X']) === '{b}');

// ==================== 4. 请求构造 ====================
echo "\n== 4. 请求构造 ==\n";
$reqApi = customapi规范化([
    'url'     => $base . '/echo?q={参数}&u={用户}',
    'method'  => 'POST',
    'headers' => "Authorization: Bearer {appid}\nX-Test: {1}",
    'body'    => '{"q":"{参数}"}',
]);
$reqVars = customapi基础变量(['全部' => '北京 朝阳', '列表' => ['北京', '朝阳']]);
$req = customapi构造请求($reqApi, $reqVars);
ok('URL 变量做了编码', strpos($req['url'], 'q=%E5%8C%97%E4%BA%AC%20%E6%9C%9D%E9%98%B3') !== false);
ok('Header 变量替换', in_array('Authorization: Bearer test_appid', $req['headers'], true));
ok('Header 第二行变量', in_array('X-Test: 北京', $req['headers'], true));
ok('Body 变量替换', $req['body'] === '{"q":"北京 朝阳"}');
ok('请求方法 POST', $req['method'] === 'POST');

// ==================== 5. 执行流程 ====================
echo "\n== 5. 执行流程 ==\n";
$getApi = customapi规范化([
    'name' => '天气', 'trigger' => '天气', 'url' => $base . '/json?q={参数}',
    'parse' => 'json', 'extracts' => json_encode([['name' => '结果', 'path' => 'data.text']]),
    'reply_template' => '{结果}', 'timeout' => 10,
]);
$r = customapi执行($getApi, ['全部' => '北京', '列表' => ['北京']]);
ok('GET 执行成功', $r['ok'] === true, $r['error']);
ok('取到 data.text', $r['values']['结果'] === '今天晴，18 度');
ok('最终文本正确', $r['text'] === '今天晴，18 度');
ok('HTTP 200 记录', $r['http']['code'] === 200);

$textApi = customapi规范化([
    'name' => '一言', 'trigger' => '一言', 'url' => $base . '/text', 'parse' => 'text',
    'extracts' => json_encode([['name' => '结果', 'path' => '@body']]),
]);
$r = customapi执行($textApi, []);
ok('纯文本接口 @body', $r['values']['结果'] === '一句话返回');
ok('纯文本自动 trim', $r['text'] === '一句话返回');

$autoApi = customapi规范化([
    'name' => '自动', 'trigger' => '自动', 'url' => $base . '/json', 'parse' => 'json',
    'extracts' => '[]',
]);
$r = customapi执行($autoApi, []);
ok('没配置取值时自动猜 data.text', $r['values']['结果'] === '今天晴，18 度');

$postApi = customapi规范化([
    'name' => '回显', 'trigger' => '回显', 'url' => $base . '/echo', 'method' => 'POST',
    'body' => '{"q":"{参数}"}', 'parse' => 'json',
    'extracts' => json_encode([
        ['name' => '方法', 'path' => 'method'],
        ['name' => '内容', 'path' => 'body'],
        ['name' => '类型', 'path' => 'ctype'],
    ]),
]);
$r = customapi执行($postApi, ['全部' => '你好', '列表' => ['你好']]);
ok('POST 方法到达服务端', $r['values']['方法'] === 'POST');
ok('POST Body 原样发送', $r['values']['内容'] === '{"q":"你好"}');
ok('POST 默认 Content-Type 为 JSON', strpos($r['values']['类型'], 'application/json') !== false);

$errApi = customapi规范化([
    'name' => '错误', 'trigger' => '错误', 'url' => $base . '/err',
    'error_template' => '服务开小差了：{状态码}',
]);
$r = customapi执行($errApi, []);
ok('HTTP 500 判定失败', $r['ok'] === false);
ok('失败模板生效', $r['text'] === '服务开小差了：500');

$emptyApi = customapi规范化(['name' => '空', 'trigger' => '空', 'url' => $base . '/empty']);
$r = customapi执行($emptyApi, []);
ok('空响应判定失败', $r['ok'] === false);

$badApi = customapi规范化(['name' => '坏地址', 'trigger' => '坏', 'url' => 'ftp://x']);
$r = customapi执行($badApi, []);
ok('非 http 地址直接失败', $r['ok'] === false && strpos($r['error'], 'http') !== false);

$nestedApi = customapi规范化([
    'name' => '嵌套', 'trigger' => '嵌套', 'url' => $base . '/nested',
    'extracts' => json_encode([['name' => '图片', 'path' => 'result.0.url']]),
    'reply_type' => 'image', 'reply_template' => '{图片}',
]);
$r = customapi执行($nestedApi, []);
ok('嵌套路径 + 图片回复', $r['text'] === 'https://example.com/a.png');

$listApi = customapi规范化([
    'name' => '通配', 'trigger' => '通配', 'url' => $base . '/list',
    'extracts' => json_encode([['name' => '结果', 'path' => 'list.*.url']]),
]);
$r = customapi执行($listApi, []);
ok('通配跳过空数组项', $r['values']['结果'] === 'https://example.com/b.png');

// ==================== 6. 保存 / 列表 / 删除 ====================
echo "\n== 6. 保存与列表 ==\n";
list($ok1, $msg1) = customapi保存(['name' => '', 'trigger' => 'x', 'url' => 'https://a.com']);
ok('缺名称拒绝保存', $ok1 === false && strpos($msg1, '名称') !== false);

list($ok2, $msg2) = customapi保存(['name' => 'x', 'trigger' => '', 'url' => 'https://a.com']);
ok('缺触发词拒绝保存', $ok2 === false && strpos($msg2, '触发') !== false);

list($ok3, $msg3) = customapi保存(['name' => 'x', 'trigger' => 'x', 'url' => 'ftp://a.com']);
ok('非 http 地址拒绝保存', $ok3 === false && strpos($msg3, 'http') !== false);

list($ok4, $id4) = customapi保存([
    'name' => '保存测试', 'trigger' => '保存,存一下', 'url' => 'https://api.example.com/x?q={参数}',
    'match_mode' => 'prefix', 'extracts' => json_encode([['name' => '结果', 'path' => 'data.text']]),
    'timeout' => 999, 'enabled' => 1,
]);
ok('合法数据保存成功', $ok4 === true && $id4 > 0);
$saved = customapi取($id4);
ok('读回名称一致', $saved && $saved['name'] === '保存测试');
ok('超时被钳制到 60', $saved && $saved['timeout'] === 60);
ok('取值表落库', $saved && strpos($saved['extracts'], 'data.text') !== false);

list($ok5, $id5) = customapi保存(['name' => '保存测试2', 'trigger' => '存二', 'url' => 'https://api.example.com/y'], $id4);
$saved2 = customapi取($id4);
ok('更新同一条记录', $ok5 === true && $saved2['name'] === '保存测试2');
ok('列表能取到', count(customapi列表()) >= 1);

customapi启用($id4, false);
ok('停用后不在启用列表', count(array_filter(customapi列表(true), function ($x) use ($id4) {
    return (int)$x['id'] === (int)$id4;
})) === 0);
customapi启用($id4, true);

customapi删除($id4);
ok('删除后读取为 null', customapi取($id4) === null);

// ==================== 7. 探测 ====================
echo "\n== 7. 探测返回结构 ==\n";
$probe = customapi探测(customapi规范化(['url' => $base . '/json', 'timeout' => 10]), []);
ok('探测成功', $probe['ok'] === true);
ok('识别为 JSON', $probe['type'] === 'json');
ok('列出候选路径', count($probe['paths']) > 0);
ok('候选里包含 data.text', in_array('data.text', array_column($probe['paths'], 'path'), true));
ok('预览是原始 JSON', strpos($probe['preview'], '"code"') !== false);

$probeText = customapi探测(customapi规范化(['url' => $base . '/text']), []);
ok('纯文本识别为 text', $probeText['type'] === 'text');

// ==================== 8. 前台运行（权限 / 范围 / 发送） ====================
echo "\n== 8. 前台运行 ==\n";
$runApi = customapi规范化([
    'name' => '运行', 'trigger' => '运行', 'url' => $base . '/json',
    'extracts' => json_encode([['name' => '结果', 'path' => 'data.text']]),
    'reply_template' => '回复：{结果}', 'permission' => 'all', 'scope' => 'all',
]);

$GLOBALS['__sent'] = [];
customapi运行($runApi, []);
ok('执行后发出文字', count($GLOBALS['__sent']) === 1 && $GLOBALS['__sent'][0]['type'] === 'text');
ok('发送内容用了模板', $GLOBALS['__sent'][0]['text'] === '回复：今天晴，18 度');

$GLOBALS['__sent'] = [];
customapi运行($runApi, []);
ok('连续两次指令都会回复（不再限频吞消息）', count($GLOBALS['__sent']) === 1);

$GLOBALS['__admin'] = false;
$adminApi = customapi规范化($runApi + []);
$adminApi['permission'] = 'admin';
$adminApi['id'] = 999;
$GLOBALS['__sent'] = [];
customapi运行($adminApi, []);
ok('管理员指令对普通用户提示', count($GLOBALS['__sent']) === 1 && strpos($GLOBALS['__sent'][0]['text'], '管理员') !== false);

$GLOBALS['__admin'] = false;
$privateApi = customapi规范化($runApi + []);
$privateApi['scope'] = 'private';
$privateApi['id'] = 998;
$GLOBALS['__sent'] = [];
ok('仅私聊的 API 在群聊不触发', customapi运行($privateApi, []) === false);

// ==================== 9. 事件触发 ====================
echo "\n== 9. 事件触发 ==\n";
list($okE1, $e1) = customapi保存([
    'name' => '进群欢迎', 'trigger_type' => 'event', 'event_type' => 'GROUP_MEMBER_ADD',
    'event_filter' => "d.group_openid != ''", 'url' => 'https://api.example.com/welcome',
    'scope' => 'group', 'permission' => 'all', 'reply_type' => 'text',
    'reply_template' => '欢迎 {事件.d.member_openid}',
]);
ok('事件 API 能保存', $okE1 === true, (string)$e1);
ok('事件名命中', count(customapi匹配事件全部()) === 1);
ok('事件条件不满足时跳过', customapi匹配事件('GROUP_MEMBER_ADD', ['t' => 'GROUP_MEMBER_ADD', 'd' => ['group_openid' => '']]) === null);
ok('事件字段变成变量', (customapi基础变量([])['事件.d.member_openid'] ?? '') === 'member_openid_1');

list($okE2) = customapi保存([
    'name' => '进群日志', 'trigger_type' => 'event', 'event_type' => 'GROUP_MEMBER_ADD',
    'url' => 'https://api.example.com/log', 'reply_type' => 'text', 'reply_template' => '新成员',
]);
ok('同一事件可以挂多条', count(customapi匹配事件全部()) === 2);

list($okE3) = customapi保存([
    'name' => '退群通知', 'trigger_type' => 'event', 'event_type' => 'GROUP_DEL_ROBOT',
    'url' => 'https://api.example.com/bye', 'reply_type' => 'text', 'reply_template' => '再见',
]);
ok('其他事件只命中自己', count(customapi匹配事件全部('GROUP_DEL_ROBOT')) === 1
    && count(customapi匹配事件全部('GROUP_MEMBER_ADD')) === 2);

// ==================== 发送方式：覆盖全部发送函数 ====================
$types = customapi发送类型();
ok('发送方式数量够用', count($types) >= 20, (string)count($types));
ok('文卡别名可用', customapi识别发送类型('文卡') === 'card');
ok('Ark 别名不区分大小写', customapi识别发送类型('ARK') === 'ark');
ok('原生按钮与按钮区分', customapi识别发送类型('原生按钮') === 'keyboard' && customapi识别发送类型('按钮') === 'button');

$vars = ['结果' => '今天晴', '封面' => 'https://example.com/c.png', '消息ID' => 'msg_openid_1'];

$cardApi = customapi规范化([
    'name' => '文卡测试', 'trigger' => '文卡', 'url' => 'https://example.com/api',
    'reply_type' => 'card',
    'reply_template' => '{结果}|点我查看|{封面}|https://example.com',
]);
$err  = [];
$acts = customapi解析发送($cardApi, $vars, '', $err);
ok('文卡解析出 4 个参数', count($acts) === 1 && count($acts[0]['args']) === 4, json_encode($err, JSON_UNESCAPED_UNICODE));
ok('文卡参数按顺序渲染', $acts && $acts[0]['args'][0] === '今天晴' && $acts[0]['args'][2] === 'https://example.com/c.png');

$GLOBALS['__sent'] = [];
$send = customapi发送($cardApi, ['ok' => true, 'vars' => $vars, 'text' => '']);
ok('文卡调用图文卡片函数', count($GLOBALS['__sent']) === 1 && $GLOBALS['__sent'][0]['type'] === 'card');
ok('文卡发送计数为 1', $send['sent'] === 1 && !$send['errors']);

// 变量内容里带竖线也不能冲乱参数
$err  = [];
$acts = customapi解析发送($cardApi, ['结果' => 'A|B', '封面' => 'https://example.com/c.png'], '', $err);
ok('变量里的竖线不冲乱参数', $acts && $acts[0]['args'][0] === 'A|B' && count($acts[0]['args']) === 4);

$badCard = customapi规范化([
    'name' => '缺参数', 'trigger' => '缺', 'url' => 'https://example.com',
    'reply_type' => 'card', 'reply_template' => '只有标题',
]);
$err  = [];
$acts = customapi解析发送($badCard, $vars, '', $err);
ok('文卡缺参数时不发送', $acts === [] && count($err) === 1);

$fileApi = customapi规范化([
    'name' => '文件', 'trigger' => '文件', 'url' => 'https://example.com',
    'reply_type' => 'file', 'reply_template' => '{结果}',
]);
$err  = [];
$acts = customapi解析发送($fileApi, $vars, '', $err);
ok('文件名可省略', $acts && $acts[0]['args'][1] === '' && !$err);

$arkApi = customapi规范化([
    'name' => 'Ark', 'trigger' => 'ark', 'url' => 'https://example.com',
    'reply_type' => 'ark', 'reply_template' => '23|{"#TITLE#":"{结果}"}',
]);
$err  = [];
$acts = customapi解析发送($arkApi, $vars, '', $err);
ok('Ark 参数以 JSON 文本保存', $acts && is_string($acts[0]['args'][1]) && !$err);

$GLOBALS['__sent'] = [];
customapi发送($arkApi, ['ok' => true, 'vars' => $vars, 'text' => '']);
ok('Ark 发送前把 JSON 解析成数组',
    count($GLOBALS['__sent']) === 1 && is_array($GLOBALS['__sent'][0]['args'][1])
    && $GLOBALS['__sent'][0]['args'][1]['#TITLE#'] === '今天晴');

$badArk = $arkApi;
$badArk['reply_template'] = '23|不是 JSON';
$err  = [];
ok('Ark 的 JSON 写错会被拦下', customapi解析发送($badArk, $vars, '', $err) === [] && count($err) === 1);

$comboApi = customapi规范化([
    'name' => '组合', 'trigger' => '组合', 'url' => 'https://example.com',
    'reply_type' => 'combo',
    'reply_template' => "文字|结果：{结果}\n文卡|{结果}|点我|{封面}|https://example.com\n图片|{封面}\n乱写|x",
]);
$err  = [];
$acts = customapi解析发送($comboApi, $vars, '', $err);
ok('组合发送解析出 3 个动作', count($acts) === 3, json_encode($err, JSON_UNESCAPED_UNICODE));
ok('组合发送保持书写顺序', $acts && $acts[0]['type'] === 'text' && $acts[1]['type'] === 'card' && $acts[2]['type'] === 'image');
ok('组合发送指出出错行号', count($err) === 1 && strpos($err[0], '第 4 行') !== false);

$GLOBALS['__sent'] = [];
$send = customapi发送($comboApi, ['ok' => true, 'vars' => $vars, 'text' => '']);
ok('组合发送依次调用三个函数',
    count($GLOBALS['__sent']) === 3
    && $GLOBALS['__sent'][0]['type'] === 'text'
    && $GLOBALS['__sent'][1]['type'] === 'card'
    && $GLOBALS['__sent'][2]['type'] === 'image');
ok('组合发送成功计数为 3', $send['sent'] === 3);

$streamApi = customapi规范化([
    'name' => '流式', 'trigger' => '流式', 'url' => 'https://example.com',
    'reply_type' => 'stream', 'reply_template' => "正在查询…\n{结果}",
]);
$err  = [];
$acts = customapi解析发送($streamApi, $vars, '', $err);
ok('流式按行拆段', $acts && count($acts[0]['args']) === 2 && $acts[0]['args'][1] === '今天晴');

$failApi = customapi规范化([
    'name' => '失败', 'trigger' => '失败', 'url' => 'https://example.com',
    'reply_type' => 'image', 'reply_template' => '{结果}', 'error_template' => '接口挂了',
]);
$GLOBALS['__sent'] = [];
customapi发送($failApi, ['ok' => false, 'vars' => [], 'text' => '接口挂了']);
ok('接口失败时按文字发提示',
    count($GLOBALS['__sent']) === 1 && $GLOBALS['__sent'][0]['type'] === 'text'
    && $GLOBALS['__sent'][0]['text'] === '接口挂了');

$noneApi = customapi规范化([
    'name' => '静默', 'trigger' => '静默', 'url' => 'https://example.com',
    'reply_type' => 'none', 'reply_template' => '{结果}',
]);
$GLOBALS['__sent'] = [];
$send = customapi发送($noneApi, ['ok' => true, 'vars' => $vars, 'text' => '结果']);
ok('不发送模式不发消息', count($GLOBALS['__sent']) === 0 && $send['sent'] === 0);

// ==================== 12. 消息清洗 / 入口兜底 / 调用记录 ====================
echo "\n== 12. 消息清洗与入口兜底 ==\n";

ok('@机器人 + 斜杠能洗净', customapi清洗消息('<@!123456> /一言') === '一言');
ok('@机器人 + 全角斜杠能洗净', customapi清洗消息('<@!123456> ／来句话') === '来句话');
ok('全角空格能洗净', customapi清洗消息("　一言　") === '一言');
ok('零宽字符能洗净', customapi清洗消息("\u{200B}一言") === '一言');
ok('纯文本 @昵称 能洗净', customapi清洗消息('@小明 一言') === '一言');
ok('纯文本 @昵称 + 斜杠能洗净', customapi清洗消息('@机器人 /一言') === '一言');
ok('正文里的斜杠不受影响', customapi清洗消息('https://a.com/x/y') === 'https://a.com/x/y');

ok('英文指令不区分大小写', customapi匹配单个(['match_mode' => 'exact'], 'Weather', 'weather') !== null);
$pm = customapi匹配单个(['match_mode' => 'prefix'], '天气', '天气 北京');
ok('前缀匹配能取到参数', is_array($pm) && $pm['全部'] === '北京' && $pm['列表'] === ['北京']);

list($okSave, $fallbackId) = customapi保存([
    'name'           => '兜底测试',
    'enabled'        => 1,
    'trigger_type'   => 'message',
    'trigger'        => '天气',
    'match_mode'     => 'prefix',
    'scope'          => 'all',
    'permission'     => 'all',
    'method'         => 'GET',
    'url'            => $base . '/json',
    'timeout'        => 5,
    'parse'          => 'json',
    'extracts'       => json_encode([['name' => '结果', 'path' => 'data.text']]),
    'reply_type'     => 'text',
    'reply_template' => '兜底：{结果}',
]);
ok('兜底用 API 保存成功', $okSave === true);

$GLOBALS['__sent'] = [];
$handled = customapi处理当前请求();
$texts = array_column($GLOBALS['__sent'], 'text');
ok('内核兜底能命中并回复',
    $handled === true && in_array('兜底：今天晴，18 度', $texts, true),
    'handled=' . var_export($handled, true) . ' sent=' . json_encode($GLOBALS['__sent'], JSON_UNESCAPED_UNICODE));

$GLOBALS['__sent'] = [];
$again = customapi处理当前请求();
ok('同一请求只处理一次，不会重复回复', $again === false && count($GLOBALS['__sent']) === 0);

$logs = customapi最近日志(5);
ok('写入调用记录', count($logs) > 0 && $logs[0]['api_name'] === '兜底测试');
ok('调用记录含发送条数', (int)$logs[0]['sent'] === 1);

$state = customapi插件状态();
ok('插件文件状态可读', isset($state['exists']) && $state['exists'] === true);
ok('重建源码包含统一入口', strpos(customapi插件源码(), 'customapi处理当前请求') !== false);

echo "\n==================== 结果 ====================\n";
echo "通过 {$passCount} 项，失败 {$failCount} 项\n";
if ($failCount) {
    echo "失败列表：\n";
    foreach ($failures as $f) echo "  - {$f}\n";
}

@proc_terminate($proc);
@proc_close($proc);
@unlink($router);

exit($failCount ? 1 : 0);
