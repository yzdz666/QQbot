<?php
/**
 * 测试新增的频道/身份组/子频道/公告/音频/权限/日程/精华消息API
 * 以及 频道管理插件 和 通知事件插件
 *
 * 测试策略: 通过 mock 函数镜像 bot.php 的参数校验逻辑, 验证调用链与边界条件
 * 运行: php test_new_apis.php
 */

date_default_timezone_set('Asia/Shanghai');

$passCount = 0;
$failCount = 0;
$failures = [];

function pass($name) {
    global $passCount;
    $passCount++;
    echo "  [PASS] {$name}\n";
}
function fail($name, $reason) {
    global $failCount, $failures;
    $failCount++;
    $failures[] = "{$name}: {$reason}";
    echo "  [FAIL] {$name} - {$reason}\n";
}

// ==================== Mock 框架 ====================
$GLOBALS['__mock'] = [
    'last_botapi' => null,    // 最近一次 BOTAPI 调用
    'last_text' => null,
    'last_md' => null,
    'admin' => true,
];

// 核心 mock: BOTAPI (与 bot.php 行为一致: 记录调用并返回响应)
function BOTAPI($address, $method, $json) {
    $GLOBALS['__mock']['last_botapi'] = [
        'address' => $address,
        'method' => $method,
        'json' => $json,
    ];
    // 根据路径返回不同响应
    if (strpos($address, '/panels') !== false) return '{"panel_id":"p1"}';
    if (strpos($address, '/guilds/') === 0 && strpos($address, '/roles') !== false && $method === 'POST') {
        return '{"role_id":"r1","role":{"id":"r1","name":"测试"}}';
    }
    if (strpos($address, '/channels/') === 0 && strpos($address, '/messages') !== false && $method === 'POST') {
        return '{"id":"m1","channel_id":"c1"}';
    }
    // 群信息
    if (strpos($address, '/v2/groups/') !== false && strpos($address, '/info') !== false) {
        return '{"group_name":"测试群","member_count":100,"max_member_count":500,"owner_openid":"owner1"}';
    }
    // 入群申请列表
    if (strpos($address, '/join_request_list') !== false) {
        return '{"records":[{"member_openid":"m1","request_time":"2026-01-01","status":0}],"next_cursor":"c2"}';
    }
    // 审批策略列表
    if (strpos($address, '/join_approval_strategy') !== false && $method === 'GET') {
        return '{"records":[{"strategy_id":"sid1","name":"策略1","enabled":true,"expire_at":"2026-12-31"}]}';
    }
    return '{}';
}
function BOT凭证() { return 'TEST_TOKEN'; }
function 是否管理员($userId = null) { return $GLOBALS['__mock']['admin']; }
function 文字($c) { $GLOBALS['__mock']['last_text'] = $c; }
function MD($c) { $GLOBALS['__mock']['last_md'] = $c; }
function wlog($c, $a = null) {}
function 头像($id) { return "https://example.com/avatar/{$id}"; }

function resetMock() {
    $GLOBALS['__mock']['last_botapi'] = null;
    $GLOBALS['__mock']['last_text'] = null;
    $GLOBALS['__mock']['last_md'] = null;
}

function assertApiCall($name, $expectedMethod, $expectedPathContains, $actual) {
    if ($actual === null) {
        fail($name, '未调用BOTAPI');
        return;
    }
    if ($actual['method'] !== $expectedMethod) {
        fail($name, '方法错误, 期望 ' . $expectedMethod . ' 实际 ' . $actual['method']);
        return;
    }
    if (strpos($actual['address'], $expectedPathContains) === false) {
        fail($name, '路径不匹配, 期望包含 ' . $expectedPathContains . ' 实际 ' . $actual['address']);
        return;
    }
    pass($name);
}

function assertEmptyParam($name, $resp) {
    $data = json_decode($resp, true);
    if (is_array($data) && isset($data['code']) && $data['code'] === -1) {
        pass($name);
    } else {
        fail($name, '未返回code=-1: ' . $resp);
    }
}

// ==================== 设置常量 ====================
define('appid', 'TEST');
define('secret', 's');
define('type', '正式');
define('消息来源', '频道');
define('消息', '');
define('来源', 'channel_test');
define('用户', 'admin_user');
define('消息ID', 'm1');
define('事件ID', 'e1');
define('raw', ['d' => ['guild_id' => 'guild_test', 'channel_id' => 'channel_test']]);

// ==================== 加载真实的 bot.php ====================
// 由于已预定义 BOTAPI/BOT凭证 等 mock, 需要避免重复定义错误
// bot.php 内部不含 function_exists 守卫, 因此采用以下策略:
//   通过 token_get_all 解析, 为每个顶层 function 注入 if(!function_exists) 守卫
//   写入临时文件后 include (比 eval 更稳健, 支持 <?php 标签)
$botCode = file_get_contents(__DIR__ . '/bot.php');
$tokens = token_get_all($botCode);
$output = '';
$i = 0;
$count = count($tokens);
while ($i < $count) {
    $tok = $tokens[$i];
    // 识别顶层 function 关键字
    if (is_array($tok) && $tok[0] === T_FUNCTION) {
        $funcStart = $i;
        // 解析函数名 (跳过空白); 遇到 ( 视为匿名函数不守卫
        $funcName = '';
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $funcName = $tokens[$j][1];
                break;
            }
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
            break; // 非空白非字符串 (如 "(") -> 闭包, 不守卫
        }
        // 匹配函数体结束大括号 (兼容字符串插值产生的 T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES)
        $depth = 0;
        $started = false;
        $end = -1;
        for ($j = $funcStart; $j < $count; $j++) {
            $t = $tokens[$j];
            $tid = is_array($t) ? $t[0] : -1;
            if ($t === '{' || $tid === T_CURLY_OPEN || $tid === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                $started = true;
            } elseif ($t === '}') {
                $depth--;
                if ($depth === 0 && $started) { $end = $j; break; }
            }
        }
        if ($end < 0) {
            // 未找到匹配大括号, 原样输出当前 token
            $output .= is_array($tok) ? $tok[1] : $tok;
            $i++;
            continue;
        }
        // 重建函数代码
        $funcCode = '';
        for ($j = $funcStart; $j <= $end; $j++) {
            $funcCode .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }
        if ($funcName !== '') {
            // 注入 function_exists 守卫, 已定义的函数跳过
            $output .= "\nif (!function_exists('{$funcName}')) {\n{$funcCode}\n}\n";
        } else {
            $output .= $funcCode;
        }
        $i = $end + 1;
        continue;
    }
    $output .= is_array($tok) ? $tok[1] : $tok;
    $i++;
}
// 写入临时文件并 include (避免 eval 的 <?php 标签问题)
$tmpFile = tempnam(sys_get_temp_dir(), 'bot_test_') . '.php';
file_put_contents($tmpFile, $output);
include $tmpFile;
@unlink($tmpFile);

// ==================== 测试频道信息 API ====================
echo "========== 测试频道信息 API ==========\n";

resetMock();
获取频道详情('guild123');
assertApiCall('获取频道详情', 'GET', '/guilds/guild123', $GLOBALS['__mock']['last_botapi']);

assertEmptyParam('获取频道详情空参数', 获取频道详情(''));

resetMock();
修改频道信息('guild123', ['name' => '新名称']);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call['method'] === 'PATCH' && strpos($call['address'], '/guilds/guild123') !== false) {
    pass('修改频道信息');
} else {
    fail('修改频道信息', json_encode($call));
}

assertEmptyParam('修改频道信息空参数', 修改频道信息('', []));

resetMock();
获取机器人频道列表('', '', 50);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call['method'] === 'GET' && strpos($call['address'], '/users/@me/guilds') !== false && strpos($call['address'], 'limit=50') !== false) {
    pass('获取机器人频道列表');
} else {
    fail('获取机器人频道列表', json_encode($call));
}

echo "\n========== 测试频道成员 API ==========\n";

resetMock();
获取频道成员('guild1', 'user1');
assertApiCall('获取频道成员', 'GET', '/guilds/guild1/members/user1', $GLOBALS['__mock']['last_botapi']);

assertEmptyParam('获取频道成员空参数', 获取频道成员('', ''));

resetMock();
获取频道成员列表('guild1', '0', 100);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call['method'] === 'GET' && strpos($call['address'], '/guilds/guild1/members') !== false && strpos($call['address'], 'limit=100') !== false) {
    pass('获取频道成员列表');
} else {
    fail('获取频道成员列表', json_encode($call));
}

resetMock();
获取身份组成员列表('guild1', 'role1', '0', 50);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call['method'] === 'GET' && strpos($call['address'], '/guilds/guild1/roles/role1/members') !== false) {
    pass('获取身份组成员列表');
} else {
    fail('获取身份组成员列表', json_encode($call));
}

resetMock();
移除频道成员('guild1', 'user1', true, 7);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call['method'] === 'DELETE' && strpos($call['address'], '/guilds/guild1/members/user1') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['add_blacklist'] === true && $body['delete_history_msg_days'] === 7) {
        pass('移除频道成员(带拉黑+撤回7天)');
    } else {
        fail('移除频道成员参数', json_encode($body));
    }
} else {
    fail('移除频道成员', json_encode($call));
}

echo "\n========== 测试身份组 API ==========\n";

resetMock();
获取身份组列表('guild1');
assertApiCall('获取身份组列表', 'GET', '/guilds/guild1/roles', $GLOBALS['__mock']['last_botapi']);

resetMock();
创建身份组('guild1', ['name' => '新组', 'color' => 16777215, 'hoist' => 1]);
assertApiCall('创建身份组', 'POST', '/guilds/guild1/roles', $GLOBALS['__mock']['last_botapi']);

resetMock();
修改身份组('guild1', 'role1', ['name' => '改名']);
assertApiCall('修改身份组', 'PATCH', '/guilds/guild1/roles/role1', $GLOBALS['__mock']['last_botapi']);

resetMock();
删除身份组('guild1', 'role1');
assertApiCall('删除身份组', 'DELETE', '/guilds/guild1/roles/role1', $GLOBALS['__mock']['last_botapi']);

resetMock();
增加成员身份组('guild1', 'user1', 'role1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call['method'] === 'PUT' && strpos($call['address'], '/guilds/guild1/members/user1/roles/role1') !== false) {
    pass('增加成员身份组(无channel_id, body为空)');
} else {
    fail('增加成员身份组', json_encode($call));
}

resetMock();
增加成员身份组('guild1', 'user1', '5', 'channel1');
$call = $GLOBALS['__mock']['last_botapi'];
$body = json_decode($call['json'], true);
if ($call['method'] === 'PUT' && $body['channel']['id'] === 'channel1') {
    pass('增加成员身份组(role_id=5带channel_id)');
} else {
    fail('增加成员身份组(role_id=5)', json_encode($call));
}

resetMock();
删除成员身份组('guild1', 'user1', 'role1');
assertApiCall('删除成员身份组', 'DELETE', '/guilds/guild1/members/user1/roles/role1', $GLOBALS['__mock']['last_botapi']);

echo "\n========== 测试子频道 API ==========\n";

resetMock();
获取子频道列表('guild1');
assertApiCall('获取子频道列表', 'GET', '/guilds/guild1/channels', $GLOBALS['__mock']['last_botapi']);

resetMock();
获取子频道详情('channel1');
assertApiCall('获取子频道详情', 'GET', '/channels/channel1', $GLOBALS['__mock']['last_botapi']);

resetMock();
创建子频道('guild1', ['name' => '新频道', 'type' => 0]);
assertApiCall('创建子频道', 'POST', '/guilds/guild1/channels', $GLOBALS['__mock']['last_botapi']);

resetMock();
修改子频道('channel1', ['name' => '改名']);
assertApiCall('修改子频道', 'PATCH', '/channels/channel1', $GLOBALS['__mock']['last_botapi']);

resetMock();
删除子频道('channel1');
assertApiCall('删除子频道', 'DELETE', '/channels/channel1', $GLOBALS['__mock']['last_botapi']);

echo "\n========== 测试公告 API ==========\n";

resetMock();
创建频道公告('guild1', ['message_id' => 'm1', 'channel_id' => 'c1']);
assertApiCall('创建频道公告', 'POST', '/guilds/guild1/announces', $GLOBALS['__mock']['last_botapi']);

resetMock();
删除频道公告('guild1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call['method'] === 'DELETE' && strpos($call['address'], '/guilds/guild1/announces/all') !== false) {
    pass('删除频道公告(默认all)');
} else {
    fail('删除频道公告', json_encode($call));
}

resetMock();
删除频道公告('guild1', 'msg123');
$call = $GLOBALS['__mock']['last_botapi'];
if (strpos($call['address'], '/guilds/guild1/announces/msg123') !== false) {
    pass('删除频道公告(指定message_id)');
} else {
    fail('删除频道公告(指定)', json_encode($call));
}

echo "\n========== 测试音频 API ==========\n";

resetMock();
音频控制('channel1', 0, 'https://example.com/audio.mp3', '播放中');
$call = $GLOBALS['__mock']['last_botapi'];
$body = json_decode($call['json'], true);
if ($call['method'] === 'POST' && strpos($call['address'], '/channels/channel1/audio') !== false &&
    $body['status'] === 0 && $body['audio_url'] === 'https://example.com/audio.mp3' && $body['text'] === '播放中') {
    pass('音频控制(播放)');
} else {
    fail('音频控制(播放)', json_encode($call));
}

resetMock();
音频控制('channel1', 1);
$call = $GLOBALS['__mock']['last_botapi'];
$body = json_decode($call['json'], true);
if ($body['status'] === 1 && !isset($body['audio_url']) && !isset($body['text'])) {
    pass('音频控制(暂停,不带audio_url)');
} else {
    fail('音频控制(暂停)', json_encode($body));
}

resetMock();
机器人上麦('channel1');
assertApiCall('机器人上麦', 'PUT', '/channels/channel1/mic', $GLOBALS['__mock']['last_botapi']);

resetMock();
机器人下麦('channel1');
assertApiCall('机器人下麦', 'DELETE', '/channels/channel1/mic', $GLOBALS['__mock']['last_botapi']);

echo "\n========== 测试子频道权限 API ==========\n";

resetMock();
获取子频道用户权限('channel1', 'user1');
assertApiCall('获取子频道用户权限', 'GET', '/channels/channel1/members/user1/permissions', $GLOBALS['__mock']['last_botapi']);

resetMock();
获取子频道身份组权限('channel1', 'role1');
assertApiCall('获取子频道身份组权限', 'GET', '/channels/channel1/roles/role1/permissions', $GLOBALS['__mock']['last_botapi']);

resetMock();
修改子频道用户权限('channel1', 'user1', '1', '4');
$call = $GLOBALS['__mock']['last_botapi'];
$body = json_decode($call['json'], true);
if ($call['method'] === 'PUT' && $body['add'] === '1' && $body['remove'] === '4') {
    pass('修改子频道用户权限');
} else {
    fail('修改子频道用户权限', json_encode($body));
}

assertEmptyParam('修改子频道用户权限(空add/remove)', 修改子频道用户权限('c1', 'u1', '', ''));

resetMock();
修改子频道身份组权限('channel1', 'role1', '4');
$call = $GLOBALS['__mock']['last_botapi'];
$body = json_decode($call['json'], true);
if ($body['add'] === '4' && !isset($body['remove'])) {
    pass('修改子频道身份组权限(只传add)');
} else {
    fail('修改子频道身份组权限', json_encode($body));
}

echo "\n========== 测试日程 API ==========\n";

resetMock();
获取日程列表('channel1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call['method'] === 'GET' && strpos($call['address'], '/channels/channel1/schedules') !== false && strpos($call['address'], 'since') === false) {
    pass('获取日程列表(无since)');
} else {
    fail('获取日程列表', json_encode($call));
}

resetMock();
获取日程列表('channel1', 1700000000000);
$call = $GLOBALS['__mock']['last_botapi'];
if (strpos($call['address'], 'since=1700000000000') !== false) {
    pass('获取日程列表(带since)');
} else {
    fail('获取日程列表(带since)', json_encode($call));
}

resetMock();
获取日程详情('channel1', 'sched1');
assertApiCall('获取日程详情', 'GET', '/channels/channel1/schedules/sched1', $GLOBALS['__mock']['last_botapi']);

resetMock();
创建日程('channel1', ['name' => '会议', 'start_timestamp' => '1700000000000', 'end_timestamp' => '1700003600000']);
$call = $GLOBALS['__mock']['last_botapi'];
$body = json_decode($call['json'], true);
if ($call['method'] === 'POST' && isset($body['schedule']) && $body['schedule']['name'] === '会议') {
    pass('创建日程(包装schedule)');
} else {
    fail('创建日程', json_encode($body));
}

resetMock();
修改日程('channel1', 'sched1', ['name' => '改名']);
assertApiCall('修改日程', 'PATCH', '/channels/channel1/schedules/sched1', $GLOBALS['__mock']['last_botapi']);

resetMock();
删除日程('channel1', 'sched1');
assertApiCall('删除日程', 'DELETE', '/channels/channel1/schedules/sched1', $GLOBALS['__mock']['last_botapi']);

echo "\n========== 测试子频道消息发送 API ==========\n";

resetMock();
发送子频道消息('channel1', ['content' => 'hello']);
assertApiCall('发送子频道消息', 'POST', '/channels/channel1/messages', $GLOBALS['__mock']['last_botapi']);

resetMock();
发送子频道文字('channel1', '你好');
$call = $GLOBALS['__mock']['last_botapi'];
$body = json_decode($call['json'], true);
if ($body['content'] === '你好') {
    pass('发送子频道文字');
} else {
    fail('发送子频道文字', json_encode($body));
}

resetMock();
发送子频道图片('channel1', 'https://example.com/img.png');
$call = $GLOBALS['__mock']['last_botapi'];
$body = json_decode($call['json'], true);
if ($body['image'] === 'https://example.com/img.png') {
    pass('发送子频道图片');
} else {
    fail('发送子频道图片', json_encode($body));
}

echo "\n========== 测试精华消息 API ==========\n";

resetMock();
获取精华消息('channel1');
assertApiCall('获取精华消息', 'GET', '/channels/channel1/pins', $GLOBALS['__mock']['last_botapi']);

resetMock();
添加精华消息('channel1', 'msg1');
assertApiCall('添加精华消息', 'PUT', '/channels/channel1/pins/msg1', $GLOBALS['__mock']['last_botapi']);

resetMock();
删除精华消息('channel1', 'msg1');
assertApiCall('删除精华消息', 'DELETE', '/channels/channel1/pins/msg1', $GLOBALS['__mock']['last_botapi']);

echo "\n========== 测试频道消息频率 API ==========\n";

resetMock();
获取频道消息频率('guild1');
assertApiCall('获取频道消息频率', 'GET', '/guilds/guild1/message/setting', $GLOBALS['__mock']['last_botapi']);

echo "\n========== 测试频道私信会话 API ==========\n";

resetMock();
创建频道私信('guild1');
$call = $GLOBALS['__mock']['last_botapi'];
$body = json_decode($call['json'], true);
if ($call['method'] === 'POST' && strpos($call['address'], '/users/@me/dms') !== false && $body['source_guild_id'] === 'guild1') {
    pass('创建频道私信');
} else {
    fail('创建频道私信', json_encode($call));
}

echo "\n========== 测试频道管理插件 ==========\n";

$pluginLoaded = false;
try {
    require_once __DIR__ . '/plugin/频道管理.php';
    $pluginLoaded = true;
    pass('频道管理插件加载无错误');
} catch (Throwable $e) {
    fail('频道管理插件加载', $e->getMessage());
}

// 测试内部辅助函数
list($s, $err) = _频道管理_解析时长('10m');
if ($s === 600 && $err === '') pass('_频道管理_解析时长 10m=600s');
else fail('_频道管理_解析时长 10m', "s={$s} err={$err}");

list($s, $err) = _频道管理_解析时长('2h');
if ($s === 7200 && $err === '') pass('_频道管理_解析时长 2h=7200s');
else fail('_频道管理_解析时长 2h', "s={$s} err={$err}");

list($s, $err) = _频道管理_解析时长('1d');
if ($s === 86400 && $err === '') pass('_频道管理_解析时长 1d=86400s');
else fail('_频道管理_解析时长 1d', "s={$s} err={$err}");

list($s, $err) = _频道管理_解析时长('');
if ($s === 0 && $err !== '') pass('_频道管理_解析时长 空串报错');
else fail('_频道管理_解析时长 空串', "s={$s} err={$err}");

list($s, $err) = _频道管理_解析时长('abc');
if ($s === 0 && $err !== '') pass('_频道管理_解析时长 非法格式报错');
else fail('_频道管理_解析时长 非法', "s={$s} err={$err}");

if (_频道管理_时长人类可读(60) === '1分钟') pass('_频道管理_时长人类可读 60s=1分钟');
else fail('_频道管理_时长人类可读 60s', _频道管理_时长人类可读(60));

if (_频道管理_时长人类可读(3600) === '1小时') pass('_频道管理_时长人类可读 3600s=1小时');
else fail('_频道管理_时长人类可读 3600s', _频道管理_时长人类可读(3600));

if (_频道管理_时长人类可读(90000) === '1天1小时') pass('_频道管理_时长人类可读 90000s=1天1小时');
else fail('_频道管理_时长人类可读 90000s', _频道管理_时长人类可读(90000));

if (_频道管理_时长人类可读(0) === '0秒') pass('_频道管理_时长人类可读 0s=0秒');
else fail('_频道管理_时长人类可读 0s', _频道管理_时长人类可读(0));

echo "\n========== 测试通知事件插件 ==========\n";

$pluginLoaded2 = false;
try {
    require_once __DIR__ . '/plugin/通知事件.php';
    $pluginLoaded2 = true;
    pass('通知事件插件加载无错误');
} catch (Throwable $e) {
    fail('通知事件插件加载', $e->getMessage());
}

if (_通知事件_场景映射('1001') === '网络搜索(全部tab)') pass('_通知事件_场景映射 1001');
else fail('_通知事件_场景映射 1001', _通知事件_场景映射('1001'));

if (_通知事件_场景映射('1003') === '群场景') pass('_通知事件_场景映射 1003=群场景');
else fail('_通知事件_场景映射 1003', _通知事件_场景映射('1003'));

if (_通知事件_场景映射('9999') === '') pass('_通知事件_场景映射 未知场景返回空');
else fail('_通知事件_场景映射 未知', _通知事件_场景映射('9999'));

echo "\n========== 测试新增事件处理 (源码层校验) ==========\n";

$wsFile = __DIR__ . '/ws_event_handler.php';
$idxFile = __DIR__ . '/index.php';
$botFile = __DIR__ . '/bot.php';

if (file_exists($wsFile)) pass('ws_event_handler.php 存在');
else fail('ws_event_handler.php', '不存在');

if (file_exists($idxFile)) pass('index.php 存在');
else fail('index.php', '不存在');

if (file_exists($botFile)) pass('bot.php 存在');
else fail('bot.php', '不存在');

// 验证新增事件已添加到 ws_event_handler.php
$wsContent = file_get_contents($wsFile);
foreach (['AUDIO_OR_LIVE_CHANNEL_MEMBER_EXIT', 'C2C_MSG_REJECT', 'C2C_MSG_RECEIVE', 'FORUM_PUBLISH_AUDIT_RESULT'] as $evt) {
    if (strpos($wsContent, "case '" . $evt . "'") !== false) {
        pass("ws_event_handler.php 含事件 {$evt}");
    } else {
        fail("ws_event_handler.php 缺事件 {$evt}", '');
    }
}

// 验证新增事件已添加到 index.php
$idxContent = file_get_contents($idxFile);
foreach (['AUDIO_OR_LIVE_CHANNEL_MEMBER_EXIT', 'C2C_MSG_REJECT', 'C2C_MSG_RECEIVE', 'FORUM_PUBLISH_AUDIT_RESULT'] as $evt) {
    if (strpos($idxContent, 'case "' . $evt . '"') !== false) {
        pass("index.php 含事件 {$evt}");
    } else {
        fail("index.php 缺事件 {$evt}", '');
    }
}

// 验证 recordIncomingMessage 含新增事件
if (strpos($idxContent, "case 'C2C_MSG_REJECT':") !== false && strpos($idxContent, "case 'C2C_MSG_RECEIVE':") !== false) {
    pass('recordIncomingMessage 含 C2C 事件');
} else {
    fail('recordIncomingMessage', '缺少C2C事件分支');
}

if (strpos($idxContent, "case 'AUDIO_OR_LIVE_CHANNEL_MEMBER_EXIT':") !== false) {
    pass('recordIncomingMessage 含 AUDIO_OR_LIVE_CHANNEL_MEMBER_EXIT');
} else {
    fail('recordIncomingMessage', '缺少AUDIO_OR_LIVE_CHANNEL_MEMBER_EXIT');
}

if (strpos($idxContent, "case 'FORUM_PUBLISH_AUDIT_RESULT':") !== false) {
    pass('recordIncomingMessage 含 FORUM_PUBLISH_AUDIT_RESULT');
} else {
    fail('recordIncomingMessage', '缺少FORUM_PUBLISH_AUDIT_RESULT');
}

// 验证新增API已添加到 bot.php
$botContent = file_get_contents($botFile);
$newApis = [
    '获取频道详情', '修改频道信息', '获取机器人频道列表',
    '获取频道成员', '获取频道成员列表', '获取身份组成员列表', '移除频道成员',
    '获取身份组列表', '创建身份组', '修改身份组', '删除身份组', '增加成员身份组', '删除成员身份组',
    '获取子频道列表', '获取子频道详情', '创建子频道', '修改子频道', '删除子频道',
    '创建频道公告', '删除频道公告',
    '音频控制', '机器人上麦', '机器人下麦',
    '获取子频道用户权限', '获取子频道身份组权限', '修改子频道用户权限', '修改子频道身份组权限',
    '获取频道消息频率',
    '获取日程列表', '获取日程详情', '创建日程', '修改日程', '删除日程',
    '发送子频道消息', '发送子频道文字', '发送子频道图片',
    '获取精华消息', '添加精华消息', '删除精华消息',
    '创建频道私信',
];
$missing = [];
foreach ($newApis as $api) {
    if (strpos($botContent, 'function ' . $api . '(') === false) {
        $missing[] = $api;
    }
}
if (empty($missing)) {
    pass('bot.php 含全部 ' . count($newApis) . ' 个新增API函数');
} else {
    fail('bot.php 缺失API', implode(', ', $missing));
}

// 验证原有API仍然存在
$existingApis = [
    '设置群成员禁言', '查询群禁言状态', '群禁言成员', '群解禁成员', '群批量禁言', '群批量解禁',
    '创建指令面板', '查询面板列表', '查询面板详情', '修改指令面板', '删除指令面板', '修改面板关联对象',
    '创建入群审批策略', '查询入群审批策略列表', '修改入群审批策略', '删除入群审批策略',
    '禁言成员', '解禁成员', '批量禁言', '批量解禁', '全员禁言', '解除全员禁言', '踢出成员',
    '设置菜单', '获取菜单', '删除菜单',
    '确认互动', '处理入群申请', '发送频道私信',
    '添加表态', '删除表态',
];
$missing2 = [];
foreach ($existingApis as $api) {
    if (strpos($botContent, 'function ' . $api . '(') === false) {
        $missing2[] = $api;
    }
}
if (empty($missing2)) {
    pass('bot.php 保留全部 ' . count($existingApis) . ' 个原有API函数');
} else {
    fail('bot.php 缺失原有API', implode(', ', $missing2));
}

// ==================== 测试第二批新增 API (表情表态列表/子频道消息/群信息/入群申请/语音成员/审批策略扩展/API权限/论坛) ====================
echo "\n========== 测试第二批新增 API ==========\n";

// 获取表态用户列表 (GET /channels/{channel_id}/messages/{message_id}/reactions/{type}/{id})
resetMock();
获取表态用户列表('c1', 'm1', 1, '4');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call['method'] === 'GET' && strpos($call['address'], '/channels/c1/messages/m1/reactions/1/4') !== false) {
    pass('获取表态用户列表');
} else {
    fail('获取表态用户列表', json_encode($call));
}
resetMock();
获取表态用户列表('c1', 'm1', 1, '4', 'abc');
$call = $GLOBALS['__mock']['last_botapi'];
if (strpos($call['address'], 'cookie=abc') !== false) {
    pass('获取表态用户列表(带cookie)');
} else {
    fail('获取表态用户列表(带cookie)', json_encode($call));
}
assertEmptyParam('获取表态用户列表空参数', 获取表态用户列表('', 'm1', 1, '4'));

// 获取子频道消息 (GET /channels/{channel_id}/messages/{message_id})
resetMock();
获取子频道消息('c1', 'm1');
assertApiCall('获取子频道消息', 'GET', '/channels/c1/messages/m1', $GLOBALS['__mock']['last_botapi']);
assertEmptyParam('获取子频道消息空参数', 获取子频道消息('', 'm1'));

// 修改子频道消息 (PATCH)
resetMock();
修改子频道消息('c1', 'm1', ['content' => '修改后', 'msg_type' => 2]);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call['method'] === 'PATCH' && strpos($call['address'], '/channels/c1/messages/m1') !== false) {
    pass('修改子频道消息');
} else {
    fail('修改子频道消息', json_encode($call));
}
assertEmptyParam('修改子频道消息空参数', 修改子频道消息('c1', 'm1', []));

// 获取群信息 (GET /v2/groups/{group_openid}/info)
resetMock();
获取群信息('g1');
assertApiCall('获取群信息', 'GET', '/v2/groups/g1/info', $GLOBALS['__mock']['last_botapi']);
assertEmptyParam('获取群信息空参数', 获取群信息(''));

// 获取机器人群状态 (GET /v2/groups/{group_openid}/bot_state)
resetMock();
获取机器人群状态('g1');
assertApiCall('获取机器人群状态', 'GET', '/v2/groups/g1/bot_state', $GLOBALS['__mock']['last_botapi']);
assertEmptyParam('获取机器人群状态空参数', 获取机器人群状态(''));

// 获取入群申请列表 (GET /v2/groups/{group_openid}/join_request_list)
resetMock();
获取入群申请列表('g1', '', 50);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call['method'] === 'GET' && strpos($call['address'], '/v2/groups/g1/join_request_list') !== false && strpos($call['address'], 'limit=50') !== false) {
    pass('获取入群申请列表');
} else {
    fail('获取入群申请列表', json_encode($call));
}
resetMock();
获取入群申请列表('g1', 'cur1', 10);
$call = $GLOBALS['__mock']['last_botapi'];
if (strpos($call['address'], 'cursor=cur1') !== false) {
    pass('获取入群申请列表(带cursor)');
} else {
    fail('获取入群申请列表(带cursor)', json_encode($call));
}
assertEmptyParam('获取入群申请列表空参数', 获取入群申请列表(''));

// 获取语音成员 (GET /channels/{channel_id}/voice/members)
resetMock();
获取语音成员('c1');
assertApiCall('获取语音成员', 'GET', '/channels/c1/voice/members', $GLOBALS['__mock']['last_botapi']);
assertEmptyParam('获取语音成员空参数', 获取语音成员(''));

// 执行审批策略 (POST /v2/groups/join_approval_strategy/{strategy_id}/execute)
resetMock();
执行审批策略('sid1');
assertApiCall('执行审批策略', 'POST', '/v2/groups/join_approval_strategy/sid1/execute', $GLOBALS['__mock']['last_botapi']);
assertEmptyParam('执行审批策略空参数', 执行审批策略(''));

// 修改审批策略白名单 (POST /v2/groups/join_approval_strategy/{strategy_id}/whitelist_users)
resetMock();
修改审批策略白名单('sid1', ['op' => 'add', 'whitelist_users' => ['13800001111']]);
$call = $GLOBALS['__mock']['last_botapi'];
$body = json_decode($call['json'], true);
if ($call['method'] === 'POST' && strpos($call['address'], '/whitelist_users') !== false && $body['op'] === 'add' && $body['whitelist_users'][0] === '13800001111') {
    pass('修改审批策略白名单');
} else {
    fail('修改审批策略白名单', json_encode($call));
}
assertEmptyParam('修改审批策略白名单空参数', 修改审批策略白名单('sid1', []));

// 获取API权限列表 (GET /guilds/{guild_id}/api_permission)
resetMock();
获取API权限列表('gid1');
assertApiCall('获取API权限列表', 'GET', '/guilds/gid1/api_permission', $GLOBALS['__mock']['last_botapi']);
assertEmptyParam('获取API权限列表空参数', 获取API权限列表(''));

// 申请API权限 (POST /guilds/{guild_id}/api_permission/demand)
resetMock();
申请API权限('gid1', 'cid1', ['path' => '/channels/{channel_id}/messages', 'method' => 'GET']);
$call = $GLOBALS['__mock']['last_botapi'];
$body = json_decode($call['json'], true);
if ($call['method'] === 'POST' && strpos($call['address'], '/api_permission/demand') !== false && $body['channel_id'] === 'cid1' && $body['api_identify']['method'] === 'GET') {
    pass('申请API权限');
} else {
    fail('申请API权限', json_encode($call));
}
assertEmptyParam('申请API权限空参数', 申请API权限('gid1', 'cid1', []));

// 获取帖子列表 (GET /channels/{channel_id}/threads)
resetMock();
获取帖子列表('c1');
assertApiCall('获取帖子列表', 'GET', '/channels/c1/threads', $GLOBALS['__mock']['last_botapi']);
resetMock();
获取帖子列表('c1', 'cur1');
$call = $GLOBALS['__mock']['last_botapi'];
if (strpos($call['address'], 'cursor=cur1') !== false) {
    pass('获取帖子列表(带cursor)');
} else {
    fail('获取帖子列表(带cursor)', json_encode($call));
}
assertEmptyParam('获取帖子列表空参数', 获取帖子列表(''));

// 获取帖子详情 (GET /channels/{channel_id}/threads/{thread_id})
resetMock();
获取帖子详情('c1', 't1');
assertApiCall('获取帖子详情', 'GET', '/channels/c1/threads/t1', $GLOBALS['__mock']['last_botapi']);
assertEmptyParam('获取帖子详情空参数', 获取帖子详情('', 't1'));

// 发表帖子 (PUT /channels/{channel_id}/threads)
resetMock();
发表帖子('c1', ['title' => '测试', 'content' => '内容', 'format' => 1]);
$call = $GLOBALS['__mock']['last_botapi'];
$body = json_decode($call['json'], true);
if ($call['method'] === 'PUT' && strpos($call['address'], '/channels/c1/threads') !== false && $body['title'] === '测试') {
    pass('发表帖子');
} else {
    fail('发表帖子', json_encode($call));
}
assertEmptyParam('发表帖子空参数', 发表帖子('c1', []));

// 删除帖子 (DELETE /channels/{channel_id}/threads/{thread_id})
resetMock();
删除帖子('c1', 't1');
assertApiCall('删除帖子', 'DELETE', '/channels/c1/threads/t1', $GLOBALS['__mock']['last_botapi']);
assertEmptyParam('删除帖子空参数', 删除帖子('', 't1'));

// ==================== 测试群管事件插件新增指令 ====================
echo "\n========== 测试群管事件插件新增指令 ==========\n";

// 群信息指令
resetMock();
$resp = 获取群信息('g1');
$data = json_decode($resp, true);
if (isset($data['group_name']) && $data['group_name'] === '测试群') {
    pass('群信息指令调用获取群信息');
} else {
    fail('群信息指令', $resp);
}

// 入群申请列表指令
resetMock();
$resp = 获取入群申请列表('g1', '', 20);
$data = json_decode($resp, true);
if (isset($data['records']) && count($data['records']) === 1) {
    pass('入群申请列表指令调用');
} else {
    fail('入群申请列表指令', $resp);
}

// 处理入群申请 (确认调用 POST /approval_join_request/{member_openid})
resetMock();
$resp = 处理入群申请('g1', 'm1', true, '');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call['method'] === 'POST' && strpos($call['address'], '/v2/groups/g1/approval_join_request/m1') !== false) {
    pass('处理入群申请(同意)');
} else {
    fail('处理入群申请(同意)', json_encode($call));
}

// ==================== 测试入群管理插件指令 ====================
echo "\n========== 测试入群管理插件指令 ====================\n";

// 执行审批策略指令调用
resetMock();
$resp = 执行审批策略('sid1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call['method'] === 'POST' && strpos($call['address'], '/join_approval_strategy/sid1/execute') !== false) {
    pass('执行审批策略指令');
} else {
    fail('执行审批策略指令', json_encode($call));
}

// 修改白名单指令调用
resetMock();
$data = ['op' => 'add', 'whitelist_users' => ['13800001111', '13800002222']];
$resp = 修改审批策略白名单('sid1', $data);
$call = $GLOBALS['__mock']['last_botapi'];
$body = json_decode($call['json'], true);
if ($call['method'] === 'POST' && strpos($call['address'], '/whitelist_users') !== false && count($body['whitelist_users']) === 2) {
    pass('修改白名单指令(2个号码)');
} else {
    fail('修改白名单指令', json_encode($call));
}

// ==================== 验证第二批 API 函数定义存在 ====================
echo "\n========== 验证第二批 API 函数存在 ==========\n";

// ==================== 测试第三批新增 API (消息撤回/通用网关/在线成员数/消息列表) ====================
echo "\n========== 测试第三批新增 API (消息撤回等) ==========\n";

// 撤回子频道消息 (DELETE /channels/{channel_id}/messages/{message_id})
resetMock();
撤回子频道消息('c1', 'm1');
assertApiCall('撤回子频道消息', 'DELETE', '/channels/c1/messages/m1', $GLOBALS['__mock']['last_botapi']);
assertEmptyParam('撤回子频道消息空参数', 撤回子频道消息('', 'm1'));

// 撤回频道私信 (DELETE /dms/{guild_id}/messages/{message_id})
resetMock();
撤回频道私信('gid1', 'm1');
assertApiCall('撤回频道私信', 'DELETE', '/dms/gid1/messages/m1', $GLOBALS['__mock']['last_botapi']);
assertEmptyParam('撤回频道私信空参数', 撤回频道私信('', 'm1'));

// 撤回单聊消息 (DELETE /v2/users/{openid}/messages/{message_id})
resetMock();
撤回单聊消息('u1', 'm1');
assertApiCall('撤回单聊消息', 'DELETE', '/v2/users/u1/messages/m1', $GLOBALS['__mock']['last_botapi']);
assertEmptyParam('撤回单聊消息空参数', 撤回单聊消息('', 'm1'));

// 撤回群聊消息 (DELETE /v2/groups/{group_openid}/messages/{message_id})
resetMock();
撤回群聊消息('g1', 'm1');
assertApiCall('撤回群聊消息', 'DELETE', '/v2/groups/g1/messages/m1', $GLOBALS['__mock']['last_botapi']);
assertEmptyParam('撤回群聊消息空参数', 撤回群聊消息('', 'm1'));

// 获取通用网关 (GET /gateway)
resetMock();
获取通用网关();
assertApiCall('获取通用网关', 'GET', '/gateway', $GLOBALS['__mock']['last_botapi']);

// 获取在线成员数 (GET /channels/{channel_id}/online_nums)
resetMock();
获取在线成员数('c1');
assertApiCall('获取在线成员数', 'GET', '/channels/c1/online_nums', $GLOBALS['__mock']['last_botapi']);
assertEmptyParam('获取在线成员数空参数', 获取在线成员数(''));

// 获取子频道消息列表 (GET /channels/{channel_id}/messages)
resetMock();
获取子频道消息列表('c1');
assertApiCall('获取子频道消息列表', 'GET', '/channels/c1/messages', $GLOBALS['__mock']['last_botapi']);
resetMock();
获取子频道消息列表('c1', ['limit' => 10, 'before' => 'm1']);
$call = $GLOBALS['__mock']['last_botapi'];
if (strpos($call['address'], 'limit=10') !== false && strpos($call['address'], 'before=m1') !== false) {
    pass('获取子频道消息列表(带分页)');
} else {
    fail('获取子频道消息列表(带分页)', json_encode($call));
}
assertEmptyParam('获取子频道消息列表空参数', 获取子频道消息列表(''));

// ==================== 验证第二批 API 函数定义存在 ====================
echo "\n========== 验证第二批 API 函数存在 ==========\n";

$batch2Apis = [
    '获取表态用户列表', '获取子频道消息', '修改子频道消息',
    '获取群信息', '获取机器人群状态', '获取入群申请列表',
    '获取语音成员',
    '执行审批策略', '修改审批策略白名单',
    '获取API权限列表', '申请API权限',
    '获取帖子列表', '获取帖子详情', '发表帖子', '删除帖子',
    '撤回子频道消息', '撤回频道私信', '撤回单聊消息', '撤回群聊消息',
    '获取通用网关', '获取在线成员数', '获取子频道消息列表',
];
$missing3 = [];
foreach ($batch2Apis as $api) {
    if (strpos($botContent, 'function ' . $api . '(') === false) {
        $missing3[] = $api;
    }
}
if (empty($missing3)) {
    pass('bot.php 含全部 ' . count($batch2Apis) . ' 个第二/三批新增API');
} else {
    fail('bot.php 缺失第二/三批API', implode(', ', $missing3));
}

// ==================== 验证插件新增指令函数存在 ====================
echo "\n========== 验证插件新增指令函数存在 ==========\n";

$pluginDir = __DIR__ . '/plugin';
$群管Content = file_get_contents($pluginDir . '/群管事件.php');
$入群Content = file_get_contents($pluginDir . '/入群管理.php');
$频道Content = file_get_contents($pluginDir . '/频道管理.php');

$群管Funcs = ['_群管事件_查询群信息', '_群管事件_查询机器人群状态', '_群管事件_查询入群申请', '_群管事件_处理入群申请'];
$missing群管 = [];
foreach ($群管Funcs as $f) {
    if (strpos($群管Content, 'function ' . $f . '(') === false) $missing群管[] = $f;
}
if (empty($missing群管)) {
    pass('群管事件插件含全部 ' . count($群管Funcs) . ' 个新增指令函数');
} else {
    fail('群管事件插件缺失', implode(', ', $missing群管));
}

$入群Funcs = ['_入群管理_执行策略', '_入群管理_修改白名单', '_入群管理_查询策略列表', '_入群管理_创建策略'];
$missing入群 = [];
foreach ($入群Funcs as $f) {
    if (strpos($入群Content, 'function ' . $f . '(') === false) $missing入群[] = $f;
}
if (empty($missing入群)) {
    pass('入群管理插件含全部 ' . count($入群Funcs) . ' 个新增指令函数');
} else {
    fail('入群管理插件缺失', implode(', ', $missing入群));
}

$频道Funcs = ['_频道管理_表态操作', '_频道管理_语音成员', '_频道管理_消息操作', '_频道管理_API权限操作', '_频道管理_帖子操作'];
$missing频道 = [];
foreach ($频道Funcs as $f) {
    if (strpos($频道Content, 'function ' . $f . '(') === false) $missing频道[] = $f;
}
if (empty($missing频道)) {
    pass('频道管理插件含全部 ' . count($频道Funcs) . ' 个新增指令函数');
} else {
    fail('频道管理插件缺失', implode(', ', $missing频道));
}

// ==================== 结果汇总 ====================
echo "\n========================================\n";
echo "测试结果汇总:\n";
echo "  通过: {$passCount}\n";
echo "  失败: {$failCount}\n";
if ($failCount > 0) {
    echo "\n失败详情:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
echo "\n全部测试通过\n";
exit(0);
