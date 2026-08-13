<?php
/**
 * 测试群管事件(群聊场景) + 指令面板插件
 * 运行: php test_group_panel.php
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

$GLOBALS['__mock'] = [
    'last_text' => null,
    'last_md' => null,
    'last_mute_call' => null,      // 群禁言API调用
    'last_panel_call' => null,     // 面板API调用
    'last_strategy_call' => null,  // 审批策略API调用
    'admin' => true,
    'mute_resp' => '{}',
    'panel_create_resp' => '{"panel_id":"p_test123"}',
    'panel_list_resp' => '{"records":[{"panel_id":"p_1","scope":"group","target_type":"all","panel":{"items":[{"type":"command","name":"签到","desc":"每日签到"}],"remark":"测试"}}],"next_cursor":"","is_end":true}',
    'panel_detail_resp' => '{"panel_id":"p_1","scope":"group","target_type":"specific","panel":{"items":[{"type":"command","name":"签到","desc":"每日签到"}]},"version":1,"group_openids":["g1"]}',
    'panel_delete_resp' => '{}',
    'restrict_query_resp' => '{"global_rule":{"mode":"none"},"members":[]}',
];

// ==================== Mock 函数 ====================
function 是否管理员($userId = null) { return $GLOBALS['__mock']['admin']; }

// 群禁言API - 底层mock (与bot.php参数校验保持一致)
function 设置群成员禁言($groupOpenid, $members) {
    if (empty($groupOpenid) || empty($members) || !is_array($members)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 members 为空']);
    }
    if (count($members) > 10) {
        return json_encode(['code' => -1, 'message' => '单次设置不能超过10个成员']);
    }
    $GLOBALS['__mock']['last_mute_call'] = ['method' => '设置群成员禁言', 'group' => $groupOpenid, 'members' => $members];
    return $GLOBALS['__mock']['mute_resp'];
}
function 查询群禁言状态($groupOpenid) {
    if (empty($groupOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 为空']);
    }
    $GLOBALS['__mock']['last_mute_call'] = ['method' => '查询群禁言状态', 'group' => $groupOpenid];
    return $GLOBALS['__mock']['restrict_query_resp'];
}
// 群禁言封装 - 真实实现(调用mock的设置群成员禁言)
function 群禁言成员($groupOpenid, $memberOpenid, $seconds) {
    if (empty($groupOpenid) || empty($memberOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 member_openid 为空']);
    }
    if ($seconds <= 0) {
        return 群解禁成员($groupOpenid, $memberOpenid);
    }
    $expireAt = date('c', time() + intval($seconds));
    $members = [['op' => 'add', 'member_openid' => $memberOpenid, 'mute_expire_at' => $expireAt]];
    return 设置群成员禁言($groupOpenid, $members);
}
function 群解禁成员($groupOpenid, $memberOpenid) {
    if (empty($groupOpenid) || empty($memberOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 member_openid 为空']);
    }
    $members = [['op' => 'del', 'member_openid' => $memberOpenid, 'mute_expire_at' => '']];
    return 设置群成员禁言($groupOpenid, $members);
}
function 群批量禁言($groupOpenid, $memberOpenids, $seconds) {
    if (empty($groupOpenid) || empty($memberOpenids) || !is_array($memberOpenids)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 member_openids 为空']);
    }
    if ($seconds <= 0) {
        return 群批量解禁($groupOpenid, $memberOpenids);
    }
    $expireAt = date('c', time() + intval($seconds));
    $memberOpenids = array_values($memberOpenids);
    $results = [];
    foreach (array_chunk($memberOpenids, 10) as $batch) {
        $members = [];
        foreach ($batch as $oid) {
            $members[] = ['op' => 'add', 'member_openid' => $oid, 'mute_expire_at' => $expireAt];
        }
        $results[] = 设置群成员禁言($groupOpenid, $members);
    }
    if (count($results) === 1) return $results[0];
    return json_encode(['code' => 0, 'message' => 'batch done', 'results' => $results], JSON_UNESCAPED_UNICODE);
}
function 群批量解禁($groupOpenid, $memberOpenids) {
    if (empty($groupOpenid) || empty($memberOpenids) || !is_array($memberOpenids)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 member_openids 为空']);
    }
    $memberOpenids = array_values($memberOpenids);
    $results = [];
    foreach (array_chunk($memberOpenids, 10) as $batch) {
        $members = [];
        foreach ($batch as $oid) {
            $members[] = ['op' => 'del', 'member_openid' => $oid, 'mute_expire_at' => ''];
        }
        $results[] = 设置群成员禁言($groupOpenid, $members);
    }
    if (count($results) === 1) return $results[0];
    return json_encode(['code' => 0, 'message' => 'batch done', 'results' => $results], JSON_UNESCAPED_UNICODE);
}

// 面板API (与bot.php参数校验保持一致)
function 创建指令面板($data) {
    if (empty($data) || !is_array($data)) {
        return json_encode(['code' => -1, 'message' => '面板数据为空']);
    }
    $GLOBALS['__mock']['last_panel_call'] = ['method' => '创建指令面板', 'data' => $data];
    return $GLOBALS['__mock']['panel_create_resp'];
}
function 查询面板列表($scope, $cursor = '', $limit = 20) {
    if (empty($scope)) {
        return json_encode(['code' => -1, 'message' => 'scope 为空']);
    }
    $GLOBALS['__mock']['last_panel_call'] = ['method' => '查询面板列表', 'scope' => $scope, 'cursor' => $cursor, 'limit' => $limit];
    return $GLOBALS['__mock']['panel_list_resp'];
}
function 查询面板详情($panelId) {
    if (empty($panelId)) {
        return json_encode(['code' => -1, 'message' => 'panel_id 为空']);
    }
    $GLOBALS['__mock']['last_panel_call'] = ['method' => '查询面板详情', 'panel_id' => $panelId];
    return $GLOBALS['__mock']['panel_detail_resp'];
}
function 修改指令面板($panelId, $data) {
    if (empty($panelId) || empty($data)) {
        return json_encode(['code' => -1, 'message' => 'panel_id 或数据为空']);
    }
    $GLOBALS['__mock']['last_panel_call'] = ['method' => '修改指令面板', 'panel_id' => $panelId, 'data' => $data];
    return '{}';
}
function 删除指令面板($panelId) {
    if (empty($panelId)) {
        return json_encode(['code' => -1, 'message' => 'panel_id 为空']);
    }
    $GLOBALS['__mock']['last_panel_call'] = ['method' => '删除指令面板', 'panel_id' => $panelId];
    return $GLOBALS['__mock']['panel_delete_resp'];
}
function 修改面板关联对象($panelId, $data) {
    if (empty($panelId) || empty($data)) {
        return json_encode(['code' => -1, 'message' => 'panel_id 或数据为空']);
    }
    $GLOBALS['__mock']['last_panel_call'] = ['method' => '修改面板关联对象', 'panel_id' => $panelId, 'data' => $data];
    return '{}';
}

// 审批策略API (与bot.php参数校验保持一致)
function 创建入群审批策略($data) {
    if (empty($data) || !is_array($data)) {
        return json_encode(['code' => -1, 'message' => '策略数据为空']);
    }
    $GLOBALS['__mock']['last_strategy_call'] = ['method' => '创建入群审批策略', 'data' => $data];
    return '{"strategy_id":"st_test","is_enable":"on","expire_at":"2027-08-13T00:00:00+08:00"}';
}
function 查询入群审批策略列表($cursor = '', $limit = 20) {
    $GLOBALS['__mock']['last_strategy_call'] = ['method' => '查询入群审批策略列表'];
    return '{"strategies":[],"next_cursor":""}';
}
function 修改入群审批策略($strategyId, $data) {
    if (empty($strategyId) || empty($data)) {
        return json_encode(['code' => -1, 'message' => 'strategy_id 或数据为空']);
    }
    $GLOBALS['__mock']['last_strategy_call'] = ['method' => '修改入群审批策略', 'strategy_id' => $strategyId];
    return '{}';
}
function 删除入群审批策略($strategyId) {
    if (empty($strategyId)) {
        return json_encode(['code' => -1, 'message' => 'strategy_id 为空']);
    }
    $GLOBALS['__mock']['last_strategy_call'] = ['method' => '删除入群审批策略', 'strategy_id' => $strategyId];
    return '{}';
}

// 通用
function 文字($c) { $GLOBALS['__mock']['last_text'] = $c; }
function MD($c) { $GLOBALS['__mock']['last_md'] = $c; }
function wlog($c, $a = null) {}

function resetMock() {
    $GLOBALS['__mock']['last_text'] = null;
    $GLOBALS['__mock']['last_md'] = null;
    $GLOBALS['__mock']['last_mute_call'] = null;
    $GLOBALS['__mock']['last_panel_call'] = null;
    $GLOBALS['__mock']['last_strategy_call'] = null;
}

// ==================== 设置常量 ====================
define('appid', 'TEST');
define('secret', 's');
define('type', '正式');
define('plugin', ['群管事件' => true, '指令面板' => true]);
// 群聊场景
define('消息来源', '群聊');
define('消息', '群管帮助');
define('来源', 'group_openid_test');
define('用户', 'admin_user');
define('消息ID', 'm1');
define('事件ID', 'e1');
define('raw', ['d' => ['group_openid' => 'group_openid_test']]);

// ==================== 加载群管事件插件 ====================
echo "========== 加载群管事件插件(群聊场景) ==========\n";
resetMock();
require_once __DIR__ . '/plugin/群管事件.php';
// 消息=群管帮助 应触发帮助输出
if ($GLOBALS['__mock']['last_md'] !== null && strpos($GLOBALS['__mock']['last_md'], '群管事件插件') !== false) {
    pass('群管事件插件加载+帮助');
} else {
    fail('群管事件插件加载', '未输出帮助');
}

// ==================== 测试群管事件指令 ====================
echo "\n========== 测试群管事件指令(群聊) ==========\n";

// 禁言: 禁言 ABC123 10m
resetMock();
_群管事件_禁言('group_test', 'ABC123 10m');
$call = $GLOBALS['__mock']['last_mute_call'];
// 群禁言成员封装会调用设置群成员禁言
if ($call['method'] === '设置群成员禁言' && $call['group'] === 'group_test' &&
    $call['members'][0]['op'] === 'add' && $call['members'][0]['member_openid'] === 'ABC123') {
    pass('禁言成员ABC123 10分钟');
} else {
    fail('禁言成员', json_encode($call));
}

// 禁言时长解析(验证RFC3339到期时间是否合理)
resetMock();
_群管事件_禁言('g', 'U1 1h');
$call = $GLOBALS['__mock']['last_mute_call'];
$expireTs = strtotime($call['members'][0]['mute_expire_at'] ?? '');
$diff = $expireTs - time();
if ($diff > 3500 && $diff < 3700) pass('禁言1h=RFC3339到期时间正确');
else fail('禁言1h', "到期时间diff={$diff}");

resetMock();
_群管事件_禁言('g', 'U1 1d');
$call = $GLOBALS['__mock']['last_mute_call'];
$expireTs = strtotime($call['members'][0]['mute_expire_at'] ?? '');
$diff = $expireTs - time();
if ($diff > 86000 && $diff < 87000) pass('禁言1d=RFC3339到期时间正确');
else fail('禁言1d', "到期时间diff={$diff}");

// 超限
resetMock();
_群管事件_禁言('g', 'U1 30d');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '28天') !== false) pass('禁言超28天拦截');
else fail('禁言超限', '');

// 空参数
resetMock();
_群管事件_禁言('g', '');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '用法') !== false) pass('禁言空参数提示');
else fail('禁言空参数', '');

// 单参数(缺时长)
resetMock();
_群管事件_禁言('g', 'ABC123');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '用法') !== false) pass('禁言缺时长提示');
else fail('禁言缺时长', '');

// 多用户禁言单个拦截
resetMock();
_群管事件_禁言('g', 'U1 U2 10m');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '仅支持') !== false) pass('禁言多用户拦截');
else fail('禁言多用户', '');

// 解禁
resetMock();
_群管事件_解禁('group_test', 'ABC123');
$call = $GLOBALS['__mock']['last_mute_call'];
if ($call['method'] === '设置群成员禁言' && $call['members'][0]['op'] === 'del' && $call['members'][0]['member_openid'] === 'ABC123' && $call['members'][0]['mute_expire_at'] === '') {
    pass('解禁成员(op=del,空串)');
} else {
    fail('解禁成员', json_encode($call));
}

// 解禁多用户走批量(批量解禁封装也调用设置群成员禁言)
resetMock();
_群管事件_解禁('g', 'U1 U2 U3');
$call = $GLOBALS['__mock']['last_mute_call'];
if ($call['method'] === '设置群成员禁言' && count($call['members']) === 3 && $call['members'][0]['op'] === 'del') pass('解禁多用户走批量');
else fail('解禁多用户', json_encode($call));

// 解禁空参数
resetMock();
_群管事件_解禁('g', '');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '用法') !== false) pass('解禁空参数提示');
else fail('解禁空参数', '');

// 批量禁言
resetMock();
_群管事件_批量禁言('group_test', 'U1 U2 U3 1h');
$call = $GLOBALS['__mock']['last_mute_call'];
if ($call['method'] === '设置群成员禁言' && count($call['members']) === 3 && $call['members'][0]['op'] === 'add') {
    pass('批量禁言3人1小时');
} else {
    fail('批量禁言', json_encode($call));
}

// 批量禁言空参数
resetMock();
_群管事件_批量禁言('g', '');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '用法') !== false) pass('批量禁言空参数提示');
else fail('批量禁言空参数', '');

// 查询禁言状态
resetMock();
_群管事件_查询禁言状态('group_test');
$call = $GLOBALS['__mock']['last_mute_call'];
if ($call['method'] === '查询群禁言状态' && $call['group'] === 'group_test') {
    pass('查询禁言状态');
} else {
    fail('查询禁言状态', json_encode($call));
}
if ($GLOBALS['__mock']['last_md'] !== null && strpos($GLOBALS['__mock']['last_md'], '群禁言状态') !== false) {
    pass('查询禁言状态输出MD');
} else {
    fail('查询禁言状态MD', '');
}

// 查询禁言状态(有数据)
resetMock();
$GLOBALS['__mock']['restrict_query_resp'] = '{"global_rule":{"mode":"schedule","schedule_rules":[{"task_id":"t1","start_at":"2026-08-13T10:00:00+08:00","end_at":"2026-08-13T11:00:00+08:00","enabled":true}],"recurring_rules":[{"task_id":"t2","weekdays":[1,2,3,4,5],"start_time":"09:00","end_time":"18:00","enabled":true}]},"members":[{"member_openid":"M1","mute_expire_at":"2026-08-13T12:00:00+08:00","username":"测试用户"}]}';
_群管事件_查询禁言状态('g');
if ($GLOBALS['__mock']['last_md'] !== null && strpos($GLOBALS['__mock']['last_md'], '测试用户') !== false && strpos($GLOBALS['__mock']['last_md'], '定时') !== false) {
    pass('查询禁言状态(含定时规则+成员)');
} else {
    fail('查询禁言状态(有数据)', '');
}
$GLOBALS['__mock']['restrict_query_resp'] = '{"global_rule":{"mode":"none"},"members":[]}';

// 事件通知
resetMock();
_群管事件_群成员加入通知();
if ($GLOBALS['__mock']['last_md'] !== null && strpos($GLOBALS['__mock']['last_md'], '加入群聊') !== false) pass('群成员加入通知');
else fail('群成员加入通知', '');

resetMock();
_群管事件_群成员退出通知();
if ($GLOBALS['__mock']['last_md'] !== null && strpos($GLOBALS['__mock']['last_md'], '退出群聊') !== false) pass('群成员退出通知');
else fail('群成员退出通知', '');

// 失败响应
resetMock();
$GLOBALS['__mock']['mute_resp'] = '{"code":400,"message":"无权限"}';
_群管事件_禁言('g', 'U1 10m');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '无权限') !== false) pass('禁言失败响应显示错误');
else fail('禁言失败响应', '');
$GLOBALS['__mock']['mute_resp'] = '{}';

// 时长解析
list($s, $e) = _群管事件_解析时长('0');
if ($s === 0 && $e === '') pass('时长0秒');
else fail('时长0', '');
list($s, $e) = _群管事件_解析时长('29d');
if ($e !== '') pass('时长29天报错');
else fail('时长29天', '');
list($s, $e) = _群管事件_解析时长('abc');
if ($e !== '') pass('非法时长报错');
else fail('非法时长', '');

// 人类可读时长
if (_群管事件_时长人类可读(3600) === '1小时') pass('1小时');
else fail('1小时', _群管事件_时长人类可读(3600));
if (_群管事件_时长人类可读(86400) === '1天') pass('1天');
else fail('1天', _群管事件_时长人类可读(86400));

// ==================== 加载指令面板插件 ====================
echo "\n========== 加载指令面板插件 ==========\n";
resetMock();
// 消息常量已定义为'群管帮助'，需要重新设置不影响
// 直接require会执行顶层代码，消息不含'面板'前缀所以不会触发
// 我们手动改消息常量... 但常量不可改。直接调用内部函数测试
require_once __DIR__ . '/plugin/指令面板.php';
pass('指令面板插件加载');

// ==================== 测试指令面板插件 ====================
echo "\n========== 测试指令面板插件 ==========\n";

// 快捷指令面板
resetMock();
_面板管理_快捷指令面板('group 签到 每日签到');
$call = $GLOBALS['__mock']['last_panel_call'];
if ($call['method'] === '创建指令面板') {
    $data = $call['data'];
    if ($data['scope'] === 'group' && $data['target_type'] === 'all' &&
        $data['panel']['items'][0]['type'] === 'command' &&
        $data['panel']['items'][0]['name'] === '签到' &&
        $data['panel']['items'][0]['desc'] === '每日签到') {
        pass('快捷指令面板创建');
    } else {
        fail('快捷指令面板', '数据结构错误: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
    }
} else {
    fail('快捷指令面板', '未调用创建API');
}
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], 'p_test123') !== false) {
    pass('快捷指令面板返回panel_id');
} else {
    fail('快捷指令面板返回', '');
}

// 快捷指令面板(无描述)
resetMock();
_面板管理_快捷指令面板('c2c 帮助');
$call = $GLOBALS['__mock']['last_panel_call'];
if ($call['data']['scope'] === 'c2c' && $call['data']['panel']['items'][0]['name'] === '帮助' && !isset($call['data']['panel']['items'][0]['desc'])) {
    pass('快捷指令面板(无描述)');
} else {
    fail('快捷指令面板(无描述)', json_encode($call['data'], JSON_UNESCAPED_UNICODE));
}

// 快捷指令面板(缺参数)
resetMock();
_面板管理_快捷指令面板('group');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '用法') !== false) pass('快捷指令面板缺参数');
else fail('快捷指令面板缺参数', '');

// 快捷指令面板(无效场景)
resetMock();
_面板管理_快捷指令面板('invalid 签到');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '场景无效') !== false) pass('快捷指令面板无效场景');
else fail('快捷指令面板无效场景', '');

// 快捷链接面板
resetMock();
_面板管理_快捷链接面板('group 官网 https://example.com');
$call = $GLOBALS['__mock']['last_panel_call'];
if ($call['method'] === '创建指令面板') {
    $data = $call['data'];
    if ($data['panel']['items'][0]['type'] === 'link' &&
        $data['panel']['items'][0]['name'] === '官网' &&
        $data['panel']['items'][0]['link'] === 'https://example.com') {
        pass('快捷链接面板创建');
    } else {
        fail('快捷链接面板', json_encode($data, JSON_UNESCAPED_UNICODE));
    }
} else {
    fail('快捷链接面板', '未调用创建API');
}

// 快捷链接面板(http拦截)
resetMock();
_面板管理_快捷链接面板('group 官网 http://insecure.com');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], 'https://') !== false) pass('快捷链接面板http拦截');
else fail('快捷链接面板http拦截', '');

// 快捷链接面板(缺参数)
resetMock();
_面板管理_快捷链接面板('group 官网');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '用法') !== false) pass('快捷链接面板缺参数');
else fail('快捷链接面板缺参数', '');

// 创建面板(JSON)
resetMock();
$json = '{"scope":"group","target_type":"all","panel":{"items":[{"type":"command","name":"测试","desc":"测试指令"}]}}';
_面板管理_创建面板($json);
$call = $GLOBALS['__mock']['last_panel_call'];
if ($call['method'] === '创建指令面板' && $call['data']['scope'] === 'group') pass('JSON创建面板');
else fail('JSON创建面板', '');

// 创建面板(无效JSON)
resetMock();
_面板管理_创建面板('not json');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], 'JSON') !== false) pass('创建面板无效JSON提示');
else fail('创建面板无效JSON', '');

// 创建面板(缺scope)
resetMock();
_面板管理_创建面板('{"panel":{"items":[]}}');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], 'scope') !== false) pass('创建面板缺scope提示');
else fail('创建面板缺scope', '');

// 创建面板(缺panel)
resetMock();
_面板管理_创建面板('{"scope":"group"}');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], 'panel') !== false) pass('创建面板缺panel提示');
else fail('创建面板缺panel', '');

// 创建面板(空参数)
resetMock();
_面板管理_创建面板('');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '用法') !== false) pass('创建面板空参数');
else fail('创建面板空参数', '');

// 查询列表(默认group)
resetMock();
_面板管理_查询列表('');
$call = $GLOBALS['__mock']['last_panel_call'];
if ($call['method'] === '查询面板列表' && $call['scope'] === 'group') pass('查询列表默认group');
else fail('查询列表默认', json_encode($call));

// 查询列表(指定c2c)
resetMock();
_面板管理_查询列表('c2c');
if ($GLOBALS['__mock']['last_panel_call']['scope'] === 'c2c') pass('查询列表c2c');
else fail('查询列表c2c', '');

// 查询列表(无效场景)
resetMock();
_面板管理_查询列表('invalid');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], 'scope') !== false) pass('查询列表无效场景');
else fail('查询列表无效场景', '');

// 查询列表输出MD
resetMock();
_面板管理_查询列表('group');
if ($GLOBALS['__mock']['last_md'] !== null && strpos($GLOBALS['__mock']['last_md'], '面板列表') !== false) pass('查询列表输出MD');
else fail('查询列表MD', 'md=' . substr($GLOBALS['__mock']['last_md'] ?? '', 0, 100));

// 查询详情
resetMock();
_面板管理_查询详情('p_test123');
$call = $GLOBALS['__mock']['last_panel_call'];
if ($call['method'] === '查询面板详情' && $call['panel_id'] === 'p_test123') pass('查询详情');
else fail('查询详情', json_encode($call));
if ($GLOBALS['__mock']['last_md'] !== null && strpos($GLOBALS['__mock']['last_md'], '签到') !== false) pass('查询详情输出MD');
else fail('查询详情MD', '');

// 查询详情(空参数)
resetMock();
_面板管理_查询详情('');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '用法') !== false) pass('查询详情空参数');
else fail('查询详情空参数', '');

// 删除面板
resetMock();
_面板管理_删除面板('p_test123');
$call = $GLOBALS['__mock']['last_panel_call'];
if ($call['method'] === '删除指令面板' && $call['panel_id'] === 'p_test123') pass('删除面板');
else fail('删除面板', json_encode($call));
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '已删除') !== false) pass('删除面板成功提示');
else fail('删除面板提示', '');

// 删除面板(空参数)
resetMock();
_面板管理_删除面板('');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '用法') !== false) pass('删除面板空参数');
else fail('删除面板空参数', '');

// 删除面板(失败)
resetMock();
$GLOBALS['__mock']['panel_delete_resp'] = '{"code":40030006,"message":"指令面板不存在"}';
_面板管理_删除面板('p_bad');
if ($GLOBALS['__mock']['last_text'] !== null && strpos($GLOBALS['__mock']['last_text'], '不存在') !== false) pass('删除面板失败提示');
else fail('删除面板失败', '');
$GLOBALS['__mock']['panel_delete_resp'] = '{}';

// 帮助
resetMock();
_面板管理_输出帮助();
if ($GLOBALS['__mock']['last_md'] !== null && strpos($GLOBALS['__mock']['last_md'], '指令面板管理插件') !== false) pass('面板帮助输出');
else fail('面板帮助', '');

// ==================== 测试 bot.php API函数 ====================
echo "\n========== 测试 bot.php API封装函数 ==========\n";

// 群禁言成员(秒数转RFC3339)
resetMock();
群禁言成员('g1', 'm1', 600);
$call = $GLOBALS['__mock']['last_mute_call'];
if ($call['method'] === '设置群成员禁言') {
    $members = $call['members'];
    if (count($members) === 1 && $members[0]['op'] === 'add' && $members[0]['member_openid'] === 'm1') {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $members[0]['mute_expire_at'])) {
            pass('群禁言成员(秒数转RFC3339)');
        } else {
            fail('群禁言成员RFC3339', '时间格式错误: ' . $members[0]['mute_expire_at']);
        }
    } else {
        fail('群禁言成员', 'members结构错误');
    }
} else {
    fail('群禁言成员', '未调用设置群成员禁言: ' . json_encode($call));
}

// 群禁言成员(0秒转解禁)
resetMock();
群禁言成员('g1', 'm1', 0);
$call = $GLOBALS['__mock']['last_mute_call'];
if ($call['method'] === '设置群成员禁言' && $call['members'][0]['op'] === 'del') pass('群禁言0秒转解禁');
else fail('群禁言0秒', json_encode($call));

// 群解禁成员
resetMock();
群解禁成员('g1', 'm1');
$call = $GLOBALS['__mock']['last_mute_call'];
if ($call['method'] === '设置群成员禁言' && $call['members'][0]['op'] === 'del' && $call['members'][0]['mute_expire_at'] === '') {
    pass('群解禁成员(op=del,空串)');
} else {
    fail('群解禁成员', json_encode($call));
}

// 群批量禁言(10人以内单批)
resetMock();
群批量禁言('g1', ['m1', 'm2', 'm3'], 3600);
$call = $GLOBALS['__mock']['last_mute_call'];
if ($call['method'] === '设置群成员禁言' && count($call['members']) === 3 && $call['members'][0]['op'] === 'add') {
    pass('群批量禁言(3人单批)');
} else {
    fail('群批量禁言(3人)', json_encode($call));
}

// 群批量禁言(超过10人分批)
resetMock();
$users15 = [];
for ($i = 1; $i <= 15; $i++) $users15[] = "m{$i}";
群批量禁言('g1', $users15, 600);
$call = $GLOBALS['__mock']['last_mute_call'];
// 15人应分2批，最后一批是5人(11-15)
if ($call['method'] === '设置群成员禁言' && count($call['members']) === 5) {
    pass('群批量禁言(15人分批,最后批5人)');
} else {
    fail('群批量禁言(分批)', '期望最后批5人,实际' . count($call['members']) . '人');
}

// 群批量禁言(0秒转批量解禁)
resetMock();
群批量禁言('g1', ['m1', 'm2'], 0);
$call = $GLOBALS['__mock']['last_mute_call'];
if ($call['method'] === '设置群成员禁言' && $call['members'][0]['op'] === 'del') pass('群批量禁言0秒转批量解禁');
else fail('群批量禁言0秒', json_encode($call));

// 设置群成员禁言(超过10人拦截)
resetMock();
$users11 = [];
for ($i = 1; $i <= 11; $i++) $users11[] = ['op' => 'add', 'member_openid' => "m{$i}", 'mute_expire_at' => '2026-08-13T12:00:00+08:00'];
$resp = 设置群成员禁言('g1', $users11);
$data = json_decode($resp, true);
if (isset($data['code']) && strpos($data['message'], '10') !== false) pass('设置群成员禁言(超10人拦截)');
else fail('设置群成员禁言超限', $resp);

// 空参数
$resp = 设置群成员禁言('', []);
if (json_decode($resp, true)['code'] === -1) pass('设置群成员禁言空参数');
else fail('设置群成员禁言空参数', '');

$resp = 查询群禁言状态('');
if (json_decode($resp, true)['code'] === -1) pass('查询群禁言状态空参数');
else fail('查询群禁言状态空参数', '');

// 创建指令面板
resetMock();
$resp = 创建指令面板(['scope' => 'group', 'panel' => ['items' => []]]);
if ($GLOBALS['__mock']['last_panel_call']['method'] === '创建指令面板') pass('创建指令面板调用');
else fail('创建指令面板', '');

$resp = 创建指令面板('');
if (json_decode($resp, true)['code'] === -1) pass('创建指令面板空参数');
else fail('创建指令面板空参数', '');

// 查询面板列表
resetMock();
查询面板列表('group', '', 50);
if ($GLOBALS['__mock']['last_panel_call']['method'] === '查询面板列表') pass('查询面板列表调用');
else fail('查询面板列表', '');

$resp = 查询面板列表('');
if (json_decode($resp, true)['code'] === -1) pass('查询面板列表空参数');
else fail('查询面板列表空参数', '');

// 查询面板详情
resetMock();
查询面板详情('p1');
if ($GLOBALS['__mock']['last_panel_call']['method'] === '查询面板详情') pass('查询面板详情调用');
else fail('查询面板详情', '');

$resp = 查询面板详情('');
if (json_decode($resp, true)['code'] === -1) pass('查询面板详情空参数');
else fail('查询面板详情空参数', '');

// 修改指令面板
resetMock();
修改指令面板('p1', ['items' => []]);
if ($GLOBALS['__mock']['last_panel_call']['method'] === '修改指令面板') pass('修改指令面板调用');
else fail('修改指令面板', '');

$resp = 修改指令面板('', []);
if (json_decode($resp, true)['code'] === -1) pass('修改指令面板空参数');
else fail('修改指令面板空参数', '');

// 删除指令面板
resetMock();
删除指令面板('p1');
if ($GLOBALS['__mock']['last_panel_call']['method'] === '删除指令面板') pass('删除指令面板调用');
else fail('删除指令面板', '');

$resp = 删除指令面板('');
if (json_decode($resp, true)['code'] === -1) pass('删除指令面板空参数');
else fail('删除指令面板空参数', '');

// 修改面板关联对象
resetMock();
修改面板关联对象('p1', ['group_openids' => ['g1']]);
if ($GLOBALS['__mock']['last_panel_call']['method'] === '修改面板关联对象') pass('修改面板关联对象调用');
else fail('修改面板关联对象', '');

$resp = 修改面板关联对象('', []);
if (json_decode($resp, true)['code'] === -1) pass('修改面板关联对象空参数');
else fail('修改面板关联对象空参数', '');

// 创建入群审批策略
resetMock();
创建入群审批策略(['group_openids' => ['g1'], 'is_enable' => 'on']);
if ($GLOBALS['__mock']['last_strategy_call']['method'] === '创建入群审批策略') pass('创建入群审批策略调用');
else fail('创建入群审批策略', '');

$resp = 创建入群审批策略('');
if (json_decode($resp, true)['code'] === -1) pass('创建入群审批策略空参数');
else fail('创建入群审批策略空参数', '');

// 查询入群审批策略列表
resetMock();
查询入群审批策略列表('', 100);
if ($GLOBALS['__mock']['last_strategy_call']['method'] === '查询入群审批策略列表') pass('查询入群审批策略列表调用');
else fail('查询入群审批策略列表', '');

// 修改入群审批策略
resetMock();
修改入群审批策略('st1', ['is_enable' => 'off']);
if ($GLOBALS['__mock']['last_strategy_call']['method'] === '修改入群审批策略') pass('修改入群审批策略调用');
else fail('修改入群审批策略', '');

$resp = 修改入群审批策略('', []);
if (json_decode($resp, true)['code'] === -1) pass('修改入群审批策略空参数');
else fail('修改入群审批策略空参数', '');

// 删除入群审批策略
resetMock();
删除入群审批策略('st1');
if ($GLOBALS['__mock']['last_strategy_call']['method'] === '删除入群审批策略') pass('删除入群审批策略调用');
else fail('删除入群审批策略', '');

$resp = 删除入群审批策略('');
if (json_decode($resp, true)['code'] === -1) pass('删除入群审批策略空参数');
else fail('删除入群审批策略空参数', '');

// ==================== 权限测试 ====================
echo "\n========== 测试权限控制 ==========\n";
$GLOBALS['__mock']['admin'] = false;
if (!是否管理员()) pass('非管理员识别');
else fail('非管理员', '');
$GLOBALS['__mock']['admin'] = true;
if (是否管理员()) pass('管理员识别');
else fail('管理员', '');

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
