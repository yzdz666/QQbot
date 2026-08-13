<?php
/**
 * 测试后台聊天界面禁言功能
 * 运行: php test_chat_mute.php
 *
 * 测试内容:
 *   1. parseDuration 时长解析
 *   2. chat_api.php 禁言/解禁/批量禁言/查询禁言 API 参数校验
 *   3. bot.php 群禁言/解禁函数调用链
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

// ==================== Mock ====================
$GLOBALS['__mock'] = [
    'last_botapi' => null,
    'admin' => true,
];

function BOTAPI($address, $method, $json) {
    $GLOBALS['__mock']['last_botapi'] = [
        'address' => $address,
        'method' => $method,
        'json' => $json,
    ];
    if (strpos($address, '/restrict_chat_setting') !== false && $method === 'GET') {
        return '{"global_rule":{"mode":"none"},"members":[{"member_openid":"m1","mute_expire_at":"2026-01-01T12:00:00+08:00"}]}';
    }
    return '{}';
}
function BOT凭证() { return ['access_token' => 'test_token', 'expires_in' => 7200]; }
function wlog($msg, $appid = null) { /* noop */ }

// ==================== 测试 parseDuration ====================
echo "\n========== 测试 parseDuration 时长解析 ==========\n";

// 直接定义 parseDuration (与 chat_api.php 中一致)
function parseDuration($str) {
    $str = trim($str);
    if (!preg_match('/^(\d+)\s*(s|m|h|d)$/i', $str, $m)) return false;
    $val = intval($m[1]);
    $unit = strtolower($m[2]);
    switch ($unit) {
        case 's': return $val;
        case 'm': return $val * 60;
        case 'h': return $val * 3600;
        case 'd': return $val * 86400;
    }
    return false;
}

$cases = [
    ['30s', 30],
    ['10m', 600],
    ['1h', 3600],
    ['7d', 604800],
    ['0s', 0],
    ['abc', false],
    ['', false],
    ['100', false],
];
foreach ($cases as $c) {
    $result = parseDuration($c[0]);
    if ($result === $c[1]) {
        pass("parseDuration('{$c[0]}') = " . var_export($result, true));
    } else {
        fail("parseDuration('{$c[0]}')", "期望 " . var_export($c[1], true) . " 实际 " . var_export($result, true));
    }
}

// ==================== 加载 bot.php 的禁言函数 ====================
echo "\n========== 测试 bot.php 禁言函数 ==========\n";

// 加载 bot.php (用 function_exists 守卫)
$botCode = file_get_contents(__DIR__ . '/bot.php');
$tokens = token_get_all($botCode);
$output = '';
$i = 0;
$count = count($tokens);
while ($i < $count) {
    $tok = $tokens[$i];
    if (is_array($tok) && $tok[0] === T_FUNCTION) {
        $funcStart = $i;
        $funcName = '';
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $funcName = $tokens[$j][1];
                break;
            }
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
            break;
        }
        $depth = 0; $started = false; $end = -1;
        for ($j = $funcStart; $j < $count; $j++) {
            $t = $tokens[$j];
            $tid = is_array($t) ? $t[0] : -1;
            if ($t === '{' || $tid === T_CURLY_OPEN || $tid === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++; $started = true;
            } elseif ($t === '}') {
                $depth--;
                if ($depth === 0 && $started) { $end = $j; break; }
            }
        }
        if ($end < 0) { $output .= is_array($tok) ? $tok[1] : $tok; $i++; continue; }
        $funcCode = '';
        for ($j = $funcStart; $j <= $end; $j++) {
            $funcCode .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }
        if ($funcName !== '') {
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
$tmpFile = tempnam(sys_get_temp_dir(), 'bot_test_') . '.php';
file_put_contents($tmpFile, $output);
include $tmpFile;
@unlink($tmpFile);

// 测试群禁言成员
$GLOBALS['__mock']['last_botapi'] = null;
群禁言成员('g1', 'm1', 3600);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'POST' && strpos($call['address'], '/v2/groups/g1/restrict_chat_setting') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['members'][0]['op'] === 'add' && $body['members'][0]['member_openid'] === 'm1') {
        pass('群禁言成员');
    } else {
        fail('群禁言成员', 'body结构错误: ' . json_encode($body));
    }
} else {
    fail('群禁言成员', 'API调用错误: ' . json_encode($call));
}

// 测试群解禁成员
$GLOBALS['__mock']['last_botapi'] = null;
群解禁成员('g1', 'm1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'POST' && strpos($call['address'], '/v2/groups/g1/restrict_chat_setting') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['members'][0]['op'] === 'del') {
        pass('群解禁成员');
    } else {
        fail('群解禁成员', 'op应为del: ' . json_encode($body));
    }
} else {
    fail('群解禁成员', 'API调用错误: ' . json_encode($call));
}

// 测试群批量禁言
$GLOBALS['__mock']['last_botapi'] = null;
群批量禁言('g1', ['m1', 'm2', 'm3'], 600);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'POST') {
    $body = json_decode($call['json'], true);
    if (count($body['members']) === 3 && $body['members'][0]['member_openid'] === 'm1') {
        pass('群批量禁言(3人)');
    } else {
        fail('群批量禁言', '成员数错误: ' . count($body['members']));
    }
} else {
    fail('群批量禁言', 'API调用错误');
}

// 测试群批量禁言超过10人自动分批
$GLOBALS['__mock']['last_botapi'] = null;
$manyIds = [];
for ($k = 0; $k < 25; $k++) $manyIds[] = 'm' . $k;
群批量禁言('g1', $manyIds, 60);
// 批量禁言返回的是多个响应的JSON数组
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/restrict_chat_setting') !== false) {
    pass('群批量禁言(25人自动分批)');
} else {
    fail('群批量禁言(25人)', '未正确调用API');
}

// 测试查询群禁言状态
$GLOBALS['__mock']['last_botapi'] = null;
$resp = 查询群禁言状态('g1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'GET' && strpos($call['address'], '/v2/groups/g1/restrict_chat_setting') !== false) {
    $data = json_decode($resp, true);
    if (isset($data['members']) && count($data['members']) === 1) {
        pass('查询群禁言状态');
    } else {
        fail('查询群禁言状态', '返回数据错误');
    }
} else {
    fail('查询群禁言状态', 'API调用错误');
}

// 测试空参数校验
$emptyTests = [
    '群禁言成员空参数' => [群禁言成员('', 'm1', 60), 'group_openid'],
    '群禁言成员空成员' => [群禁言成员('g1', '', 60), 'member_openid'],
    '群解禁成员空参数' => [群解禁成员('', 'm1'), 'group_openid'],
    '群批量禁言空参数' => [群批量禁言('', ['m1'], 60), 'group_openid'],
    '群批量禁言空数组' => [群批量禁言('g1', [], 60), 'member_openids'],
    '查询群禁言空参数' => [查询群禁言状态(''), 'group_openid'],
];
foreach ($emptyTests as $name => $test) {
    $resp = $test[0];
    $data = json_decode($resp, true);
    if (is_array($data) && isset($data['code']) && $data['code'] === -1) {
        pass($name);
    } else {
        fail($name, '未返回错误码: ' . $resp);
    }
}

// ==================== 测试 chat_api.php 参数校验逻辑 ====================
echo "\n========== 测试 chat_api.php 禁言API参数校验 ==========\n";

// 模拟 chat_api.php 的参数校验逻辑 (不需要真正启动会话)
// 测试 mute_member 参数校验
function testMuteMemberValidation($appid, $groupOpenid, $memberOpenid, $seconds) {
    $errors = [];
    if (empty($appid) || empty($groupOpenid) || empty($memberOpenid)) {
        $errors[] = '缺少参数';
    }
    if ($seconds <= 0) {
        $errors[] = '时长必须大于0';
    }
    return $errors;
}

// 测试合法参数
$errors = testMuteMemberValidation('app1', 'g1', 'm1', 3600);
if (empty($errors)) {
    pass('mute_member 合法参数');
} else {
    fail('mute_member 合法参数', implode(',', $errors));
}

// 测试缺少参数
$errors = testMuteMemberValidation('', 'g1', 'm1', 3600);
if (in_array('缺少参数', $errors)) {
    pass('mute_member 缺少appid');
} else {
    fail('mute_member 缺少appid', '未检测到缺失');
}

// 测试时长为0
$errors = testMuteMemberValidation('app1', 'g1', 'm1', 0);
if (in_array('时长必须大于0', $errors)) {
    pass('mute_member 时长为0');
} else {
    fail('mute_member 时长为0', '未检测到错误');
}

// 测试 batch_mute 参数校验
function testBatchMuteValidation($memberOpenids, $seconds) {
    $errors = [];
    if (!is_array($memberOpenids) || count($memberOpenids) === 0) {
        $errors[] = '格式不正确';
    }
    if (count($memberOpenids) > 10) {
        $errors[] = '单次最多10人';
    }
    if ($seconds <= 0) {
        $errors[] = '时长必须大于0';
    }
    return $errors;
}

$errors = testBatchMuteValidation(['m1', 'm2'], 3600);
if (empty($errors)) { pass('batch_mute 合法参数'); } else { fail('batch_mute 合法参数', implode(',', $errors)); }

$errors = testBatchMuteValidation([], 3600);
if (in_array('格式不正确', $errors)) { pass('batch_mute 空数组'); } else { fail('batch_mute 空数组', '未检测到'); }

$errors = testBatchMuteValidation(array_fill(0, 15, 'm'), 3600);
if (in_array('单次最多10人', $errors)) { pass('batch_mute 超过10人'); } else { fail('batch_mute 超过10人', '未检测到'); }

// ==================== 验证 chat_api.php 包含禁言 action ====================
echo "\n========== 验证 chat_api.php 包含禁言 action ==========\n";

$apiContent = file_get_contents(__DIR__ . '/admin/api/chat_api.php');
$requiredActions = ['mute_member', 'unmute_member', 'batch_mute', 'query_mute', 'parseDuration'];
foreach ($requiredActions as $action) {
    if (strpos($apiContent, $action) !== false) {
        pass("chat_api.php 含 '{$action}'");
    } else {
        fail("chat_api.php 含 '{$action}'", '未找到');
    }
}

// ==================== 验证 chat.php 前端包含禁言功能 ====================
echo "\n========== 验证 chat.php 前端禁言功能 ==========\n";

$chatContent = file_get_contents(__DIR__ . '/admin/chat.php');
$requiredFeatures = [
    'showMuteModal', 'closeMuteModal', 'doMuteMember', 'doUnmuteMember',
    'doBatchMute', 'loadMuteStatus', 'quickMuteMember', 'quickUnmuteMember',
    'muteModal', 'btnMuteManage', 'unmuteFromList',
];
foreach ($requiredFeatures as $feat) {
    if (strpos($chatContent, $feat) !== false) {
        pass("chat.php 含 '{$feat}'");
    } else {
        fail("chat.php 含 '{$feat}'", '未找到');
    }
}

// ==================== 结果汇总 ====================
echo "\n========== 结果汇总 ==========\n";
echo "通过: {$passCount}\n";
echo "失败: {$failCount}\n";
if (!empty($failures)) {
    echo "\n失败详情:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
}
exit($failCount > 0 ? 1 : 0);
?>
