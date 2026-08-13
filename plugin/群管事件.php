<?php
// 插件：群管事件（仅管理员）
// 功能：
//   1. 频道成员管理指令：禁言/解禁/批量禁言/全员禁言/踢出成员
//   2. 监听成员状态变更事件（禁言/解禁/加入/退出）并通知
// 参照官方文档:
//   - 频道禁言: https://bot.q.qq.com/wiki/develop/api-v2/server-api/guild-mute/
//   - 删除频道成员: https://bot.q.qq.com/wiki/develop/api-v2/server-api/guild-member/
//   - 成员事件: https://bot.q.qq.com/wiki/develop/api-v2/dev-prepare/interface-framework/event-emit.html#guild-members
//
// ⚠️ 仅管理员可用，权限基于 bots 表 owner_ids 字段（见 bot.php 是否管理员()）
// ⚠️ 禁言/踢人 API 仅适用于频道(guild)场景，群聊(group_openid)不支持

// ==================== 事件监听（非指令） ====================
// 这些事件不需要管理员权限，由框架自动触发通知
if (defined('消息来源')) {
    switch (消息来源) {
        case '频道成员更新':
            _群管事件_成员更新通知();
            return;
        case '频道成员增加':
            _群管事件_成员加入通知();
            return;
        case '频道成员移除':
            _群管事件_成员退出通知();
            return;
    }
}

// ==================== 指令处理（仅消息类事件） ====================
if (!defined('消息来源')) return;
if (!in_array(消息来源, ['频道', '频道私信'], true)) {
    // 群聊场景不支持下述指令（QQ官方API限制）
    return;
}

// ==================== 管理员权限校验 ====================
if (!是否管理员()) {
    return;
}

// ==================== 解析指令 ====================
$msg = trim(消息);
if ($msg === '') return;

// 提取 guild_id（频道禁言API需要 guild_id，而 来源 是 channel_id）
$guildId = _群管事件_获取GuildId();
if (empty($guildId)) {
    // 没有 guild_id 无法执行禁言/踢人指令
    return;
}

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
        _群管事件_禁言($guildId, $argsStr);
        break;
    case '解禁':
        _群管事件_解禁($guildId, $argsStr);
        break;
    case '批量禁言':
        _群管事件_批量禁言($guildId, $argsStr);
        break;
    case '全员禁言':
        _群管事件_全员禁言($guildId, $argsStr);
        break;
    case '解除全员禁言':
        _群管事件_解除全员禁言($guildId);
        break;
    case '踢出':
    case '踢人':
        _群管事件_踢出($guildId, $argsStr);
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
 * 从 raw 事件中提取 guild_id
 * 频道消息事件(AT_MESSAGE_CREATE/MESSAGE_CREATE): raw.d.guild_id
 * 频道私信(DIRECT_MESSAGE_CREATE): raw.d.guild_id
 * 频道成员事件: raw.d.guild_id
 */
function _群管事件_获取GuildId() {
    if (!defined('raw') || !is_array(raw)) return '';
    $d = raw['d'] ?? [];
    if (!is_array($d)) return '';
    // 频道消息事件优先 guild_id；频道成员事件也用 guild_id
    return $d['guild_id'] ?? '';
}

/**
 * 从消息文本中提取所有 @用户 的 user_id
 * QQ频道消息的 @ 格式: <@!userid> 或 <@userid>
 * 返回: user_id 数组
 */
function _群管事件_提取At用户($text) {
    $users = [];
    if (preg_match_all('/<@!?([A-Za-z0-9]+)>/', $text, $matches)) {
        foreach ($matches[1] as $uid) {
            if (!in_array($uid, $users, true)) {
                $users[] = $uid;
        }
        }
    }
    return $users;
}

/**
 * 解析时长字符串为秒数
 * 支持: "60" / "5m" / "1h" / "1d" / "7d"
 * 单位: s=秒, m=分, h=时, d=天
 * 上限: 28天 (QQ官方禁言上限)
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
    $seconds = $num;
    switch ($unit) {
        case '':     // 无单位默认秒
        case 's': $seconds = $num; break;
        case 'm': $seconds = $num * 60; break;
        case 'h': $seconds = $num * 3600; break;
        case 'd': $seconds = $num * 86400; break;
    }
    // QQ官方禁言上限: 28天
    if ($seconds > 28 * 86400) {
        return [0, '禁言时长不能超过28天'];
    }
    return [$seconds, ''];
}

/**
 * 禁言单个成员
 * 用法: 禁言 @用户 时长
 *   例: 禁言 @用户 10m
 *   例: 禁言 @用户 1h
 * 若未 @ 用户，则禁言消息发送者自己（用于自检）
 */
function _群管事件_禁言($guildId, $argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: 禁言 @用户 时长\n例: 禁言 @用户 10m\n时长单位: s/m/h/d，默认秒");
        return;
    }
    // 提取最后一个词作为时长，前面作为@用户
    $parts = preg_split('/\s+/', $argsStr);
    if (count($parts) < 1) {
        文字("❌ 用法: 禁言 @用户 时长");
        return;
    }
    $timeStr = array_pop($parts);
    list($seconds, $err) = _群管事件_解析时长($timeStr);
    if ($err !== '') {
        文字("❌ {$err}");
        return;
    }
    $remaining = implode(' ', $parts);
    $users = _群管事件_提取At用户($remaining);
    if (empty($users)) {
        // 未@用户，禁言消息发送者
        $users = [用户];
    }
    if (count($users) > 1) {
        文字("❌ 禁言单个成员仅支持 @一位用户\n批量禁言请用: 批量禁言 @用户1 @用户2 时长");
        return;
    }
    $userId = $users[0];
    $resp = 禁言成员($guildId, $userId, $seconds);
    _群管事件_回复结果($resp, "禁言成员 {$userId} {$seconds}秒");
}

/**
 * 解除禁言单个成员
 * 用法: 解禁 @用户
 */
function _群管事件_解禁($guildId, $argsStr) {
    $users = _群管事件_提取At用户($argsStr);
    if (empty($users)) {
        // 未@用户，解禁消息发送者
        $users = [用户];
    }
    if (count($users) > 1) {
        // 多个用户走批量解禁
        $resp = 批量解禁($guildId, $users);
        _群管事件_回复结果($resp, '批量解禁 ' . count($users) . ' 位成员');
        return;
    }
    $userId = $users[0];
    $resp = 解禁成员($guildId, $userId);
    _群管事件_回复结果($resp, "解禁成员 {$userId}");
}

/**
 * 批量禁言多个成员
 * 用法: 批量禁言 @用户1 @用户2 时长
 */
function _群管事件_批量禁言($guildId, $argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: 批量禁言 @用户1 @用户2 时长\n例: 批量禁言 @用户1 @用户2 1h");
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
    $users = _群管事件_提取At用户($remaining);
    if (count($users) < 1) {
        文字("❌ 至少需要 @一位用户\n用法: 批量禁言 @用户1 @用户2 时长");
        return;
    }
    $resp = 批量禁言($guildId, $users, $seconds);
    _群管事件_回复结果($resp, '批量禁言 ' . count($users) . ' 位成员 ' . $seconds . '秒');
}

/**
 * 全员禁言
 * 用法: 全员禁言 时长
 *   例: 全员禁言 1h
 */
function _群管事件_全员禁言($guildId, $argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: 全员禁言 时长\n例: 全员禁言 1h");
        return;
    }
    list($seconds, $err) = _群管事件_解析时长($argsStr);
    if ($err !== '') {
        文字("❌ {$err}");
        return;
    }
    $resp = 全员禁言($guildId, $seconds);
    _群管事件_回复结果($resp, "全员禁言 {$seconds}秒");
}

/**
 * 解除全员禁言
 * 用法: 解除全员禁言
 */
function _群管事件_解除全员禁言($guildId) {
    $resp = 解除全员禁言($guildId);
    _群管事件_回复结果($resp, '解除全员禁言');
}

/**
 * 踢出成员
 * 用法: 踢出 @用户
 */
function _群管事件_踢出($guildId, $argsStr) {
    $users = _群管事件_提取At用户($argsStr);
    if (empty($users)) {
        文字("❌ 用法: 踢出 @用户\n⚠️ 此操作不可撤销");
        return;
    }
    if (count($users) > 1) {
        $results = [];
        foreach ($users as $uid) {
            $r = 踢出成员($guildId, $uid);
            $results[] = "{$uid}: " . _群管事件_简短结果($r);
        }
        文字("📤 批量踢出结果:\n" . implode("\n", $results));
        return;
    }
    $userId = $users[0];
    $resp = 踢出成员($guildId, $userId);
    _群管事件_回复结果($resp, "踢出成员 {$userId}");
}

/**
 * 统一回复 API 调用结果
 */
function _群管事件_回复结果($resp, $action) {
    $data = json_decode($resp, true);
    // QQ官方API: 成功通常返回空或 code=0/200，失败返回 code+message
    $code = $data['code'] ?? null;
    if ($code === null || $code === 0 || $code === 200 || $code === '0' || $code === '200') {
        文字("✅ {$action} 操作成功");
    } else {
        $message = $data['message'] ?? ($data['msg'] ?? '未知错误');
        文字("❌ {$action} 操作失败\n错误码: {$code}\n信息: {$message}");
    }
    wlog("[群管事件] {$action} | 响应: {$resp}", defined('appid') ? appid : null);
}

/**
 * 提取简短结果（用于批量操作汇总）
 */
function _群管事件_简短结果($resp) {
    $data = json_decode($resp, true);
    $code = $data['code'] ?? null;
    if ($code === null || $code === 0 || $code === 200) return '✅成功';
    $message = $data['message'] ?? '失败';
    return '❌' . $message;
}

/**
 * 频道成员更新通知（含禁言/解禁状态变化）
 * 事件: GUILD_MEMBER_UPDATE
 * 字段: d.mute_end_timestamp (禁言结束时间戳, 0或不存在表示未禁言)
 */
function _群管事件_成员更新通知() {
    if (!defined('raw') || !is_array(raw)) return;
    $d = raw['d'] ?? [];
    $userId = $d['user']['id'] ?? '';
    $guildId = $d['guild_id'] ?? '';
    $muteTs = $d['mute_end_timestamp'] ?? '0';
    $nickname = $d['user']['username'] ?? $userId;
    // mute_end_timestamp 为 0/空 表示解禁，>0 表示被禁言
    if (!empty($muteTs) && $muteTs !== '0') {
        $remaining = intval($muteTs) - time();
        if ($remaining > 0) {
            $human = _群管事件_时长人类可读($remaining);
            $md = "# 🔇 成员被禁言\n\n";
            $md .= "**频道**: `{$guildId}`\n";
            $md .= "**成员**: {$nickname} (`{$userId}`)\n";
            $md .= "**剩余时长**: {$human}";
            MD($md);
        }
    } else {
        $md = "# 🔊 成员被解除禁言\n\n";
        $md .= "**频道**: `{$guildId}`\n";
        $md .= "**成员**: {$nickname} (`{$userId}`)";
        MD($md);
    }
}

/**
 * 频道成员加入通知
 * 事件: GUILD_MEMBER_ADD
 */
function _群管事件_成员加入通知() {
    if (!defined('raw') || !is_array(raw)) return;
    $d = raw['d'] ?? [];
    $userId = $d['user']['id'] ?? '';
    $guildId = $d['guild_id'] ?? '';
    $nickname = $d['user']['username'] ?? $userId;
    $md = "# 👋 新成员加入频道\n\n";
    $md .= "**频道**: `{$guildId}`\n";
    $md .= "**成员**: {$nickname} (`{$userId}`)";
    MD($md);
}

/**
 * 频道成员退出通知
 * 事件: GUILD_MEMBER_REMOVE
 */
function _群管事件_成员退出通知() {
    if (!defined('raw') || !is_array(raw)) return;
    $d = raw['d'] ?? [];
    $userId = $d['user']['id'] ?? '';
    $guildId = $d['guild_id'] ?? '';
    $nickname = $d['user']['username'] ?? $userId;
    $md = "# 👋 成员退出频道\n\n";
    $md .= "**频道**: `{$guildId}`\n";
    $md .= "**成员**: {$nickname} (`{$userId}`)";
    MD($md);
}

/**
 * 将秒数转为人类可读时长
 */
function _群管事件_时长人类可读($seconds) {
    $seconds = intval($seconds);
    if ($seconds < 60) return "{$seconds}秒";
    // 注意: floor() 返回 float，必须强转 int 否则 === 0 严格比较会失败
    $days = (int)floor($seconds / 86400);
    $hours = (int)floor(($seconds % 86400) / 3600);
    $mins = (int)floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    $parts = [];
    if ($days > 0) $parts[] = "{$days}天";
    if ($hours > 0) $parts[] = "{$hours}小时";
    if ($mins > 0) $parts[] = "{$mins}分钟";
    // 仅在天数为0时显示秒（天数>0时秒级精度无意义）
    if ($secs > 0 && $days === 0) $parts[] = "{$secs}秒";
    return implode('', $parts);
}

/**
 * 输出帮助信息
 */
function _群管事件_输出帮助() {
    $md  = "# 🛡️ 群管事件插件（管理员专用）\n\n";
    $md .= "频道成员管理指令，仅管理员可用\n\n";
    $md .= "## 📋 指令列表\n\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `禁言 @用户 时长` | 禁言指定成员 |\n";
    $md .= "| `解禁 @用户` | 解除禁言 |\n";
    $md .= "| `批量禁言 @用户1 @用户2 时长` | 批量禁言 |\n";
    $md .= "| `全员禁言 时长` | 全员禁言 |\n";
    $md .= "| `解除全员禁言` | 解除全员禁言 |\n";
    $md .= "| `踢出 @用户` | 踢出成员（不可撤销）|\n\n";
    $md .= "## ⏱ 时长格式\n\n";
    $md .= "- `60` 或 `60s` → 60秒\n";
    $md .= "- `5m` → 5分钟\n";
    $md .= "- `1h` → 1小时\n";
    $md .= "- `1d` → 1天（上限28天）\n\n";
    $md .= "## 📡 自动事件通知\n\n";
    $md .= "- 成员被禁言/解禁时自动通知\n";
    $md .= "- 新成员加入频道时自动通知\n";
    $md .= "- 成员退出频道时自动通知\n\n";
    $md .= "> ⚠️ 仅在频道(guild)场景有效，群聊(group)不支持禁言API\n";
    $md .= "> 📖 官方文档: https://bot.q.qq.com/wiki/develop/api-v2/server-api/guild-mute/";
    MD($md);
}
?>
