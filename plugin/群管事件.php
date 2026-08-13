<?php
// 插件：群管事件（仅管理员，群聊场景）
// 功能：
//   1. 群成员管理指令：禁言/解禁/批量禁言/查询禁言状态
//   2. 监听群成员变动事件（入群/退群）并通知
// 参照官方文档:
//   - 设置群成员禁言: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_group_openid_restrict_chat_setting.post.html
//   - 查询群禁言状态: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_group_openid_restrict_chat_setting.get.html
//   - 群成员事件: GROUP_MEMBER_ADD / GROUP_MEMBER_REMOVE
//
// ⚠️ 仅管理员可用，权限基于 bots 表 owner_ids 字段（见 bot.php 是否管理员()）
// ⚠️ 仅群聊(group_openid)场景有效，机器人需拥有群管理员身份
// ⚠️ 禁言只能操作普通成员，不能操作群主/管理员/机器人

// ==================== 事件监听（非指令，无需管理员权限）====================
// 群成员入群/退群通知
if (defined('消息来源')) {
    switch (消息来源) {
        case '群成员增加':
            _群管事件_群成员加入通知();
            return;
        case '群成员移除':
            _群管事件_群成员退出通知();
            return;
    }
}

// ==================== 指令处理（仅群聊场景）====================
if (!defined('消息来源')) return;
if (消息来源 !== '群聊') return;

// ==================== 管理员权限校验 ====================
if (!是否管理员()) {
    return;
}

// ==================== 解析指令 ====================
$msg = trim(消息);
if ($msg === '') return;

// group_openid 来自 来源 常量（群聊场景下框架已设置）
$groupOpenid = 来源;
if (empty($groupOpenid)) return;

// 拆分指令
$spacePos = strpos($msg, ' ');
if ($spacePos === false) {
    $cmd = $msg;
    $argsStr = '';
} else {
    $cmd = substr($msg, 0, $spacePos);
    $argsStr = trim(substr($msg, $spacePos + 1));
}

// ==================== 指令分发 ====================
switch ($cmd) {
    case '禁言':
        _群管事件_禁言($groupOpenid, $argsStr);
        break;
    case '解禁':
        _群管事件_解禁($groupOpenid, $argsStr);
        break;
    case '批量禁言':
        _群管事件_批量禁言($groupOpenid, $argsStr);
        break;
    case '查询禁言':
    case '禁言状态':
        _群管事件_查询禁言状态($groupOpenid);
        break;
    case '群信息':
        _群管事件_查询群信息($groupOpenid);
        break;
    case '群状态':
    case '机器人状态':
        _群管事件_查询机器人群状态($groupOpenid);
        break;
    case '入群申请':
    case '申请列表':
        _群管事件_查询入群申请($groupOpenid, $argsStr);
        break;
    case '同意入群':
    case '拒绝入群':
        _群管事件_处理入群申请($groupOpenid, $cmd, $argsStr);
        break;
    case '群管帮助':
    case '群管':
        _群管事件_输出帮助();
        break;
}

// ====================================================================
// 以下为内部实现函数（前缀 _群管事件_ 避免与其它插件冲突）
// ====================================================================

/**
 * 从消息文本中提取所有 @用户 的 member_openid
 * 群聊消息的 @ 格式与频道不同，群聊通常没有 <@!id> 格式
 * 因此这里支持两种方式：
 *   1. 直接传入 openid 字符串（空格分隔多个）
 *   2. 兼容 <@!openid> 格式
 * 返回: member_openid 数组
 */
function _群管事件_提取用户($text) {
    $users = [];
    // 先尝试匹配 <@!xxx> 格式
    if (preg_match_all('/<@!?([A-Za-z0-9]+)>/', $text, $matches)) {
        foreach ($matches[1] as $uid) {
            if (!in_array($uid, $users, true)) $users[] = $uid;
        }
        return $users;
    }
    // 没有匹配到 @ 格式，按空格分割作为 openid 列表
    $parts = preg_split('/\s+/', trim($text));
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && !in_array($p, $users, true)) $users[] = $p;
    }
    return $users;
}

/**
 * 解析时长字符串为秒数
 * 支持: "60" / "5m" / "1h" / "1d" / "7d"
 * 单位: s=秒, m=分, h=时, d=天
 * 上限: 28天 (QQ禁言上限)
 * 返回: [秒数, 错误信息] 错误信息为空表示成功
 */
function _群管事件_解析时长($str) {
    $str = trim($str);
    if ($str === '') return [0, '时长不能为空'];
    if (!preg_match('/^(\d+)\s*([smhd]?)$/i', $str, $m)) {
        return [0, '时长格式错误，应为 数字+单位(可选s/m/h/d)'];
    }
    $num = intval($m[1]);
    $unit = strtolower($m[2] ?? 's');
    switch ($unit) {
        case '':     // 无单位默认秒
        case 's': $seconds = $num; break;
        case 'm': $seconds = $num * 60; break;
        case 'h': $seconds = $num * 3600; break;
        case 'd': $seconds = $num * 86400; break;
        default: return [0, '未知时长单位'];
    }
    if ($seconds > 28 * 86400) {
        return [0, '禁言时长不能超过28天'];
    }
    return [$seconds, ''];
}

/**
 * 禁言群成员
 * 用法: 禁言 @用户 时长
 *   例: 禁言 @用户 10m
 *   例: 禁言 OPENID123 1h
 * 若未提供用户，则提示用法
 */
function _群管事件_禁言($groupOpenid, $argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: 禁言 @用户 时长\n或: 禁言 成员openid 时长\n例: 禁言 ABC123 10m\n时长单位: s/m/h/d，默认秒");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    if (count($parts) < 2) {
        文字("❌ 用法: 禁言 @用户 时长\n时长单位: s/m/h/d");
        return;
    }
    $timeStr = array_pop($parts);
    list($seconds, $err) = _群管事件_解析时长($timeStr);
    if ($err !== '') {
        文字("❌ {$err}");
        return;
    }
    $remaining = implode(' ', $parts);
    $users = _群管事件_提取用户($remaining);
    if (empty($users)) {
        文字("❌ 请提供要禁言的成员\n用法: 禁言 @用户 时长");
        return;
    }
    if (count($users) > 1) {
        文字("❌ 禁言单个成员仅支持一位用户\n批量禁言请用: 批量禁言 用户1 用户2 时长");
        return;
    }
    $resp = 群禁言成员($groupOpenid, $users[0], $seconds);
    _群管事件_回复结果($resp, "禁言成员 " . $users[0] . " " . _群管事件_时长人类可读($seconds));
}

/**
 * 解除禁言
 * 用法: 解禁 @用户
 *   例: 解禁 ABC123
 */
function _群管事件_解禁($groupOpenid, $argsStr) {
    $users = _群管事件_提取用户($argsStr);
    if (empty($users)) {
        文字("❌ 用法: 解禁 @用户\n或: 解禁 成员openid");
        return;
    }
    if (count($users) > 1) {
        // 多用户走批量解禁
        $resp = 群批量解禁($groupOpenid, $users);
        _群管事件_回复结果($resp, '批量解禁 ' . count($users) . ' 位成员');
        return;
    }
    $resp = 群解禁成员($groupOpenid, $users[0]);
    _群管事件_回复结果($resp, "解禁成员 " . $users[0]);
}

/**
 * 批量禁言
 * 用法: 批量禁言 用户1 用户2 时长
 *   例: 批量禁言 ABC123 DEF456 1h
 * 单次最多10个（API限制），超过自动分批
 */
function _群管事件_批量禁言($groupOpenid, $argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: 批量禁言 用户1 用户2 ... 时长\n例: 批量禁言 ABC123 DEF456 1h\n时长单位: s/m/h/d");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $timeStr = array_pop($parts);
    list($seconds, $err) = _群管事件_解析时长($timeStr);
    if ($err !== '') {
        文字("❌ {$err}");
        return;
    }
    $remaining = implode(' ', $parts);
    $users = _群管事件_提取用户($remaining);
    if (count($users) < 1) {
        文字("❌ 至少需要一位成员\n用法: 批量禁言 用户1 用户2 时长");
        return;
    }
    $resp = 群批量禁言($groupOpenid, $users, $seconds);
    _群管事件_回复结果($resp, '批量禁言 ' . count($users) . ' 位成员 ' . _群管事件_时长人类可读($seconds));
}

/**
 * 查询群禁言状态
 * 用法: 查询禁言
 * 返回全员禁言模式 + 被禁言成员列表
 */
function _群管事件_查询禁言状态($groupOpenid) {
    $resp = 查询群禁言状态($groupOpenid);
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        文字("❌ 查询失败: {$resp}");
        return;
    }
    $md = "# 🔇 群禁言状态\n\n";
    // 全员禁言规则
    $globalRule = $data['global_rule'] ?? [];
    $mode = $globalRule['mode'] ?? 'none';
    $modeText = ['none' => '未开启', 'always' => '始终禁言', 'schedule' => '定时禁言'][$mode] ?? $mode;
    $md .= "## 全员禁言\n\n";
    $md .= "**模式**: {$modeText}\n\n";
    // 定时禁言规则
    if (!empty($globalRule['schedule_rules'])) {
        $md .= "### 定时任务\n\n";
        foreach ($globalRule['schedule_rules'] as $rule) {
            $enabled = ($rule['enabled'] ?? false) ? '✅' : '❌';
            $md .= "- {$enabled} {$rule['start_at']} ~ {$rule['end_at']} (task: `" . ($rule['task_id'] ?? '') . "`)\n";
        }
        $md .= "\n";
    }
    // 周期禁言规则
    if (!empty($globalRule['recurring_rules'])) {
        $md .= "### 周期任务\n\n";
        foreach ($globalRule['recurring_rules'] as $rule) {
            $enabled = ($rule['enabled'] ?? false) ? '✅' : '❌';
            $weekdays = $rule['weekdays'] ?? [];
            $weekdayText = implode(',', array_map(function($w) {
                return ['一', '二', '三', '四', '五', '六', '日'][$w - 1] ?? $w;
            }, $weekdays));
            $md .= "- {$enabled} 周{$weekdayText} {$rule['start_time']}~{$rule['end_time']}\n";
        }
        $md .= "\n";
    }
    // 被禁言成员列表
    $members = $data['members'] ?? [];
    $md .= "## 被禁言成员 (" . count($members) . ")\n\n";
    if (empty($members)) {
        $md .= "暂无被禁言成员\n";
    } else {
        foreach ($members as $m) {
            $username = $m['username'] ?? '未知';
            $expire = $m['mute_expire_at'] ?? '';
            $md .= "- {$username} (`" . ($m['member_openid'] ?? '') . "`)\n  到期: {$expire}\n";
        }
    }
    MD($md);
}

/**
 * 群成员加入通知
 * 事件: GROUP_MEMBER_ADD
 */
function _群管事件_群成员加入通知() {
    $userId = defined('用户') ? 用户 : '';
    $groupId = defined('来源') ? 来源 : '';
    $md = "# 👋 新成员加入群聊\n\n";
    $md .= "**群**: `{$groupId}`\n";
    $md .= "**成员**: `{$userId}`";
    MD($md);
}

/**
 * 群成员退出通知
 * 事件: GROUP_MEMBER_REMOVE
 */
function _群管事件_群成员退出通知() {
    $userId = defined('用户') ? 用户 : '';
    $groupId = defined('来源') ? 来源 : '';
    $md = "# 👋 成员退出群聊\n\n";
    $md .= "**群**: `{$groupId}`\n";
    $md .= "**成员**: `{$userId}`";
    MD($md);
}

/**
 * 查询群信息 (GET /v2/groups/{group_openid}/info)
 */
function _群管事件_查询群信息($groupOpenid) {
    $resp = 获取群信息($groupOpenid);
    $data = json_decode($resp, true);
    if (!is_array($data) || isset($data['code'])) {
        文字("❌ 查询群信息失败: {$resp}");
        return;
    }
    $md  = "# ℹ️ 群信息\n\n";
    $md .= "**群名称**: " . ($data['group_name'] ?? '未知') . "\n\n";
    $md .= "**群 openid**: `{$groupOpenid}`\n";
    if (isset($data['member_count'])) {
        $md .= "**成员数**: " . $data['member_count'] . "\n";
    }
    if (isset($data['max_member_count'])) {
        $md .= "**上限**: " . $data['max_member_count'] . "\n";
    }
    if (isset($data['owner_openid'])) {
        $md .= "**群主 openid**: `" . $data['owner_openid'] . "`\n";
    }
    MD($md);
}

/**
 * 查询机器人群内状态 (GET /v2/groups/{group_openid}/bot_state)
 */
function _群管事件_查询机器人群状态($groupOpenid) {
    $resp = 获取机器人群状态($groupOpenid);
    $data = json_decode($resp, true);
    if (!is_array($data) || isset($data['code'])) {
        文字("❌ 查询机器人状态失败: {$resp}");
        return;
    }
    $md  = "# 🤖 机器人群内状态\n\n";
    $md .= "**群 openid**: `{$groupOpenid}`\n";
    $state = $data['state'] ?? ($data['status'] ?? '未知');
    $stateText = ['0' => '不在群', '1' => '在群', 0 => '不在群', 1 => '在群'][$state] ?? $state;
    $md .= "**状态**: {$stateText}\n";
    if (isset($data['join_at'])) {
        $md .= "**入群时间**: " . $data['join_at'] . "\n";
    }
    MD($md);
}

/**
 * 查询入群申请列表 (GET /v2/groups/{group_openid}/join_request_list)
 * 用法: 入群申请 [页码/游标]
 */
function _群管事件_查询入群申请($groupOpenid, $argsStr) {
    $cursor = '';
    $limit = 20;
    if ($argsStr !== '') {
        $parts = preg_split('/\s+/', $argsStr);
        // 第一个参数如果是数字视为 limit
        if (isset($parts[0]) && is_numeric($parts[0])) {
            $limit = max(1, min(100, intval($parts[0])));
        } else {
            $cursor = $parts[0];
        }
    }
    $resp = 获取入群申请列表($groupOpenid, $cursor, $limit);
    $data = json_decode($resp, true);
    if (!is_array($data) || isset($data['code'])) {
        文字("❌ 查询入群申请失败: {$resp}");
        return;
    }
    $records = $data['records'] ?? ($data['list'] ?? []);
    $md  = "# 📋 入群申请列表\n\n";
    $md .= "**群 openid**: `{$groupOpenid}`\n\n";
    if (empty($records)) {
        $md .= "暂无入群申请\n";
    } else {
        $md .= "| 成员 openid | 申请时间 | 状态 |\n|---|---|---|\n";
        foreach ($records as $r) {
            $oid = $r['member_openid'] ?? ($r['openid'] ?? '未知');
            $time = $r['request_time'] ?? ($r['create_time'] ?? '');
            $status = $r['status'] ?? 0;
            $statusText = ['待处理', '已同意', '已拒绝'][$status] ?? $status;
            $md .= "| `{$oid}` | {$time} | {$statusText} |\n";
        }
    }
    if (!empty($data['next_cursor'])) {
        $md .= "\n> 下一页游标: `" . $data['next_cursor'] . "`\n";
    }
    MD($md);
}

/**
 * 处理入群申请 (POST /v2/groups/{group_openid}/approval_join_request/{member_openid})
 * 用法: 同意入群 成员openid [原因]
 *       拒绝入群 成员openid [原因]
 */
function _群管事件_处理入群申请($groupOpenid, $cmd, $argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: {$cmd} 成员openid [拒绝原因]\n例: {$cmd} ABC123");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr, 2);
    $memberOpenid = $parts[0];
    $reason = $parts[1] ?? '';
    $approve = ($cmd === '同意入群');
    $resp = 处理入群申请($groupOpenid, $memberOpenid, $approve, $reason);
    _群管事件_回复结果($resp, ($approve ? '同意' : '拒绝') . "入群 {$memberOpenid}");
}

/**
 * 统一回复 API 调用结果
 * 群禁言API成功返回 {}（空对象），失败返回错误信息
 */
function _群管事件_回复结果($resp, $action) {
    $data = json_decode($resp, true);
    // 群禁言API成功返回 {} 或空，失败返回 code+message 或 trace_id
    $code = $data['code'] ?? null;
    $message = $data['message'] ?? ($data['msg'] ?? '');
    if ($code === null || $code === 0 || $code === 200 || $code === '0' || $code === '200') {
        文字("✅ {$action} 操作成功");
    } else {
        文字("❌ {$action} 操作失败\n错误码: {$code}\n信息: {$message}");
    }
    wlog("[群管事件] {$action} | 响应: {$resp}", defined('appid') ? appid : null);
}

/**
 * 将秒数转为人类可读时长
 */
function _群管事件_时长人类可读($seconds) {
    $seconds = intval($seconds);
    if ($seconds < 60) return "{$seconds}秒";
    $days = (int)floor($seconds / 86400);
    $hours = (int)floor(($seconds % 86400) / 3600);
    $mins = (int)floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    $parts = [];
    if ($days > 0) $parts[] = "{$days}天";
    if ($hours > 0) $parts[] = "{$hours}小时";
    if ($mins > 0) $parts[] = "{$mins}分钟";
    if ($secs > 0 && $days === 0) $parts[] = "{$secs}秒";
    return implode('', $parts);
}

/**
 * 输出帮助信息
 */
function _群管事件_输出帮助() {
    $md  = "# 🛡️ 群管事件插件（管理员专用）\n\n";
    $md .= "群聊成员管理指令，仅群聊场景下管理员可用\n\n";
    $md .= "## 📋 指令列表\n\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `禁言 成员openid 时长` | 禁言指定成员 |\n";
    $md .= "| `解禁 成员openid` | 解除禁言 |\n";
    $md .= "| `批量禁言 用户1 用户2 时长` | 批量禁言(单次最多10人) |\n";
    $md .= "| `查询禁言` | 查询群禁言状态与列表 |\n";
    $md .= "| `群信息` | 获取群基础信息 |\n";
    $md .= "| `群状态` | 查询机器人群内状态 |\n";
    $md .= "| `入群申请 [数量]` | 获取入群申请列表 |\n";
    $md .= "| `同意入群 成员openid` | 同意入群申请 |\n";
    $md .= "| `拒绝入群 成员openid [原因]` | 拒绝入群申请 |\n\n";
    $md .= "## ⏱ 时长格式\n\n";
    $md .= "- `60` 或 `60s` → 60秒\n";
    $md .= "- `5m` → 5分钟\n";
    $md .= "- `1h` → 1小时\n";
    $md .= "- `1d` → 1天（上限28天）\n\n";
    $md .= "## 📡 自动事件通知\n\n";
    $md .= "- 新成员加入群聊时自动通知\n";
    $md .= "- 成员退出群聊时自动通知\n\n";
    $md .= "> ⚠️ 仅群聊场景有效，机器人需拥有群管理员身份\n";
    $md .= "> ⚠️ 禁言只能操作普通成员，不能操作群主/管理员/机器人\n";
    $md .= "> 📖 官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_group_openid_restrict_chat_setting.post.html";
    MD($md);
}
?>
