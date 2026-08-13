<?php
// 插件：频道管理（仅管理员，频道场景）
// 功能：QQ频道(Guild)的完整管理能力
//   1. 频道信息：详情/修改/机器人加入的频道列表
//   2. 频道成员：详情/列表/移除(踢人)
//   3. 身份组：列表/创建/修改/删除/成员增删
//   4. 子频道：列表/详情/创建/修改/删除
//   5. 公告：创建/删除
//   6. 音频：控制/上麦/下麦
//   7. 子频道权限：查询/修改
//   8. 日程：列表/详情/创建/修改/删除
//   9. 频道消息发送(子频道文本/图片)
//  10. 精华消息：列表/添加/删除
//  11. 频道禁言：成员禁言/全员禁言/批量禁言
// 参照官方文档:
//   - https://bot.q.qq.com/wiki/develop/api-v2/server-inter/channel/guild-controller.html
//   - https://bot.q.qq.com/wiki/develop/api-v2/server-inter/channel/user-controller.html
//   - https://bot.q.qq.com/wiki/develop/api-v2/server-inter/channel/role-controller.html
//   - https://bot.q.qq.com/wiki/develop/api-v2/server-inter/channel/channel-controller.html
//   - https://bot.q.qq.com/wiki/develop/api-v2/server-inter/channel/announces-controller.html
//   - https://bot.q.qq.com/wiki/develop/api-v2/server-inter/channel/audio-controller.html
//   - https://bot.q.qq.com/wiki/develop/api-v2/server-inter/channel/permissions-controller.html
//   - https://bot.q.qq.com/wiki/develop/api-v2/server-inter/channel/mute-controller.html
//   - https://bot.q.qq.com/wiki/develop/api-v2/server-inter/channel/schedule-controller.html
//
// ⚠️ 仅管理员可用，权限基于 bots 表 owner_ids 字段（见 bot.php 是否管理员()）
// ⚠️ 写操作要求机器人被添加为频道管理员

// ==================== 入口：仅处理消息类事件 ====================
if (!defined('消息来源')) return;
// 频道场景: AT_MESSAGE_CREATE / MESSAGE_CREATE -> 消息来源=频道; DIRECT_MESSAGE_CREATE -> 频道私信
if (!in_array(消息来源, ['频道', '频道私信'], true)) return;

// ==================== 管理员权限校验 ====================
if (!是否管理员()) {
    return;
}

// ==================== 解析指令 ====================
$msg = trim(消息);
if ($msg === '' || strpos($msg, '频道') !== 0) return;

$rest = trim(substr($msg, strlen('频道')));
if ($rest === '') {
    _频道管理_输出帮助();
    return;
}

// 拆分 子指令 与 参数
$spacePos = strpos($rest, ' ');
if ($spacePos === false) {
    $subCmd = $rest;
    $argsStr = '';
} else {
    $subCmd = substr($rest, 0, $spacePos);
    $argsStr = trim(substr($rest, $spacePos + 1));
}

// 解析 guild_id / channel_id: 优先级 框架常量来源 > 参数显式传入
function _频道管理_取GuildId() {
    $gid = defined('来源') ? 来源 : '';
    // 频道场景下来源通常是 channel_id, 因此尝试从 raw 提取 guild_id
    if (defined('raw') && is_array(raw)) {
        $g = raw['d']['guild_id'] ?? '';
        if (!empty($g)) return $g;
    }
    return $gid;
}
function _频道管理_取ChannelId() {
    $cid = defined('来源') ? 来源 : '';
    if (defined('raw') && is_array(raw)) {
        $c = raw['d']['channel_id'] ?? '';
        if (!empty($c)) return $c;
    }
    return $cid;
}

// ==================== 指令分发 ====================
switch ($subCmd) {
    // -------- 频道信息 --------
    case '详情':
        _频道管理_频道详情($argsStr);
        break;
    case '列表':
        _频道管理_频道列表($argsStr);
        break;

    // -------- 成员管理 --------
    case '成员':
        _频道管理_成员操作($argsStr);
        break;
    case '踢人':
        _频道管理_踢人($argsStr);
        break;

    // -------- 身份组 --------
    case '身份组':
        _频道管理_身份组操作($argsStr);
        break;

    // -------- 子频道 --------
    case '子频道':
        _频道管理_子频道操作($argsStr);
        break;

    // -------- 公告 --------
    case '公告':
        _频道管理_公告操作($argsStr);
        break;

    // -------- 音频 --------
    case '音频':
        _频道管理_音频操作($argsStr);
        break;

    // -------- 权限 --------
    case '权限':
        _频道管理_权限操作($argsStr);
        break;

    // -------- 日程 --------
    case '日程':
        _频道管理_日程操作($argsStr);
        break;

    // -------- 精华消息 --------
    case '精华':
        _频道管理_精华操作($argsStr);
        break;

    // -------- 表情表态 --------
    case '表态':
        _频道管理_表态操作($argsStr);
        break;

    // -------- 语音成员 --------
    case '语音':
    case '语音成员':
        _频道管理_语音成员($argsStr);
        break;

    // -------- 子频道消息 --------
    case '消息':
        _频道管理_消息操作($argsStr);
        break;

    // -------- API权限 --------
    case 'API权限':
    case 'api权限':
        _频道管理_API权限操作($argsStr);
        break;

    // -------- 论坛帖子 --------
    case '帖子':
    case '论坛':
        _频道管理_帖子操作($argsStr);
        break;

    // -------- 禁言 --------
    case '禁言':
        _频道管理_禁言($argsStr);
        break;
    case '解禁':
        _频道管理_解禁($argsStr);
        break;
    case '批量禁言':
        _频道管理_批量禁言($argsStr);
        break;
    case '全员禁言':
        _频道管理_全员禁言($argsStr);
        break;
    case '解除全员':
        _频道管理_解除全员禁言();
        break;

    // -------- 帮助 --------
    case '帮助':
    case 'help':
    case '?':
    default:
        _频道管理_输出帮助();
        break;
}

// ====================================================================
// 以下为内部实现函数（前缀 _频道管理_ 避免与其它插件冲突）
// ====================================================================

// -------- 频道详情 --------
function _频道管理_频道详情($argsStr) {
    $gid = $argsStr !== '' ? $argsStr : _频道管理_取GuildId();
    if (empty($gid)) {
        文字("❌ 用法: 频道详情 [guild_id]\n示例: 频道详情 1234567890");
        return;
    }
    $resp = 获取频道详情($gid);
    _频道管理_输出Json($resp, "频道详情 [{$gid}]");
}

// -------- 机器人加入的频道列表 --------
function _频道管理_频道列表($argsStr) {
    $parts = preg_split('/\s+/', trim($argsStr));
    $limit = isset($parts[0]) ? intval($parts[0]) : 100;
    if ($limit <= 0) $limit = 100;
    $after = $parts[1] ?? '';
    $resp = 获取机器人频道列表('', $after, $limit);
    _频道管理_输出Json($resp, "机器人加入的频道列表");
}

// -------- 成员操作 --------
function _频道管理_成员操作($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法:\n  频道成员 详情 user_id\n  频道成员 列表 [limit] [after_user_id]");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $action = $parts[0] ?? '';
    if ($action === '详情') {
        if (empty($parts[1])) {
            文字("❌ 用法: 频道成员 详情 user_id");
            return;
        }
        $gid = _频道管理_取GuildId();
        $resp = 获取频道成员($gid, $parts[1]);
        _频道管理_输出Json($resp, "频道成员详情 [{$parts[1]}]");
    } elseif ($action === '列表') {
        $gid = _频道管理_取GuildId();
        $limit = isset($parts[1]) ? intval($parts[1]) : 100;
        if ($limit <= 0) $limit = 100;
        $after = $parts[2] ?? '0';
        $resp = 获取频道成员列表($gid, $after, $limit);
        _频道管理_输出Json($resp, "频道成员列表 [{$gid}]");
    } else {
        文字("❌ 未知子指令: {$action}\n支持: 详情 / 列表");
    }
}

// -------- 踢人 --------
function _频道管理_踢人($argsStr) {
    $parts = preg_split('/\s+/', trim($argsStr));
    if (empty($parts[0])) {
        文字("❌ 用法: 踢人 user_id [撤回天数] [是否拉黑]\n撤回天数: 0(不撤回)/3/7/15/30/-1(全部)\n是否拉黑: true/false 默认 false");
        return;
    }
    $userId = $parts[0];
    $delDays = isset($parts[1]) ? intval($parts[1]) : 0;
    $addBlk = isset($parts[2]) ? (strtolower($parts[2]) === 'true') : false;
    $gid = _频道管理_取GuildId();
    $resp = 移除频道成员($gid, $userId, $addBlk, $delDays);
    _频道管理_回复结果($resp, "踢出成员 {$userId} 撤回{$delDays}天 拉黑:" . ($addBlk ? '是' : '否'));
}

// -------- 身份组操作 --------
function _频道管理_身份组操作($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法:\n  频道身份组 列表\n  频道身份组 创建 名称 [颜色(十进制ARGB)] [hoist:0/1]\n  频道身份组 修改 role_id [名称] [颜色] [hoist]\n  频道身份组 删除 role_id\n  频道身份组 成员 role_id [limit] [start_index]\n  频道身份组 增加成员 user_id role_id [channel_id]\n  频道身份组 删除成员 user_id role_id [channel_id]");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $action = $parts[0] ?? '';
    $gid = _频道管理_取GuildId();
    switch ($action) {
        case '列表':
            $resp = 获取身份组列表($gid);
            _频道管理_输出Json($resp, "身份组列表 [{$gid}]");
            break;
        case '创建':
            if (empty($parts[1])) {
                文字("❌ 用法: 频道身份组 创建 名称 [颜色(十进制ARGB)] [hoist:0/1]");
                return;
            }
            $data = ['name' => $parts[1]];
            if (isset($parts[2])) $data['color'] = intval($parts[2]);
            if (isset($parts[3])) $data['hoist'] = intval($parts[3]);
            $resp = 创建身份组($gid, $data);
            _频道管理_输出Json($resp, "创建身份组 {$parts[1]}");
            break;
        case '修改':
            if (empty($parts[1])) {
                文字("❌ 用法: 频道身份组 修改 role_id [名称] [颜色] [hoist]");
                return;
            }
            $data = [];
            if (isset($parts[2])) $data['name'] = $parts[2];
            if (isset($parts[3])) $data['color'] = intval($parts[3]);
            if (isset($parts[4])) $data['hoist'] = intval($parts[4]);
            $resp = 修改身份组($gid, $parts[1], $data);
            _频道管理_输出Json($resp, "修改身份组 {$parts[1]}");
            break;
        case '删除':
            if (empty($parts[1])) {
                文字("❌ 用法: 频道身份组 删除 role_id");
                return;
            }
            $resp = 删除身份组($gid, $parts[1]);
            _频道管理_回复结果($resp, "删除身份组 {$parts[1]}");
            break;
        case '成员':
            if (empty($parts[1])) {
                文字("❌ 用法: 频道身份组 成员 role_id [limit] [start_index]");
                return;
            }
            $limit = isset($parts[2]) ? intval($parts[2]) : 100;
            $start = $parts[3] ?? '0';
            $resp = 获取身份组成员列表($gid, $parts[1], $start, $limit);
            _频道管理_输出Json($resp, "身份组成员 [{$parts[1]}]");
            break;
        case '增加成员':
            if (empty($parts[1]) || empty($parts[2])) {
                文字("❌ 用法: 频道身份组 增加成员 user_id role_id [channel_id]");
                return;
            }
            $chId = $parts[3] ?? '';
            $resp = 增加成员身份组($gid, $parts[1], $parts[2], $chId);
            _频道管理_回复结果($resp, "增加成员 {$parts[1]} 至身份组 {$parts[2]}");
            break;
        case '删除成员':
            if (empty($parts[1]) || empty($parts[2])) {
                文字("❌ 用法: 频道身份组 删除成员 user_id role_id [channel_id]");
                return;
            }
            $chId = $parts[3] ?? '';
            $resp = 删除成员身份组($gid, $parts[1], $parts[2], $chId);
            _频道管理_回复结果($resp, "从身份组 {$parts[2]} 移除成员 {$parts[1]}");
            break;
        default:
            文字("❌ 未知子指令: {$action}\n支持: 列表/创建/修改/删除/成员/增加成员/删除成员");
    }
}

// -------- 子频道操作 --------
function _频道管理_子频道操作($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法:\n  频道子频道 列表\n  频道子频道 详情 channel_id\n  频道子频道 创建 名称 type [sub_type] [parent_id]\n  频道子频道 修改 channel_id [名称] [type]\n  频道子频道 删除 channel_id");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $action = $parts[0] ?? '';
    $gid = _频道管理_取GuildId();
    switch ($action) {
        case '列表':
            $resp = 获取子频道列表($gid);
            _频道管理_输出Json($resp, "子频道列表 [{$gid}]");
            break;
        case '详情':
            if (empty($parts[1])) {
                文字("❌ 用法: 频道子频道 详情 channel_id");
                return;
            }
            $resp = 获取子频道详情($parts[1]);
            _频道管理_输出Json($resp, "子频道详情 [{$parts[1]}]");
            break;
        case '创建':
            if (empty($parts[1])) {
                文字("❌ 用法: 频道子频道 创建 名称 type [sub_type] [parent_id]\ntype: 0=文字 2=语音 4=分组 10005=直播 10006=应用 10007=论坛");
                return;
            }
            $data = ['name' => $parts[1]];
            if (isset($parts[2])) $data['type'] = intval($parts[2]);
            if (isset($parts[3])) $data['sub_type'] = intval($parts[3]);
            if (isset($parts[4])) $data['parent_id'] = $parts[4];
            $resp = 创建子频道($gid, $data);
            _频道管理_输出Json($resp, "创建子频道 {$parts[1]}");
            break;
        case '修改':
            if (empty($parts[1])) {
                文字("❌ 用法: 频道子频道 修改 channel_id [名称] [type]");
                return;
            }
            $data = [];
            if (isset($parts[2])) $data['name'] = $parts[2];
            if (isset($parts[3])) $data['type'] = intval($parts[3]);
            $resp = 修改子频道($parts[1], $data);
            _频道管理_输出Json($resp, "修改子频道 {$parts[1]}");
            break;
        case '删除':
            if (empty($parts[1])) {
                文字("❌ 用法: 频道子频道 删除 channel_id");
                return;
            }
            $resp = 删除子频道($parts[1]);
            _频道管理_回复结果($resp, "删除子频道 {$parts[1]}");
            break;
        default:
            文字("❌ 未知子指令: {$action}\n支持: 列表/详情/创建/修改/删除");
    }
}

// -------- 公告操作 --------
function _频道管理_公告操作($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法:\n  频道公告 创建 消息类型 message_id channel_id [announces_type:0/1]\n  频道公告 创建 推荐子频道 channel_id1 introduce1 channel_id2 introduce2 ...\n  频道公告 删除 [message_id=all]");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $action = $parts[0] ?? '';
    $gid = _频道管理_取GuildId();
    if ($action === '创建') {
        $subType = $parts[1] ?? '';
        if ($subType === '消息类型') {
            if (empty($parts[2]) || empty($parts[3])) {
                文字("❌ 用法: 频道公告 创建 消息类型 message_id channel_id [announces_type]");
                return;
            }
            $data = [
                'message_id' => $parts[2],
                'channel_id' => $parts[3],
            ];
            if (isset($parts[4])) $data['announces_type'] = intval($parts[4]);
            $resp = 创建频道公告($gid, $data);
            _频道管理_输出Json($resp, "创建频道公告(消息类型)");
        } elseif ($subType === '推荐子频道') {
            // 后续参数成对: channel_id introduce
            $rest = array_slice($parts, 2);
            if (count($rest) < 2 || (count($rest) % 2) !== 0) {
                文字("❌ 推荐子频道参数需成对: channel_id introduce [channel_id introduce ...]");
                return;
            }
            $rc = [];
            for ($i = 0; $i < count($rest); $i += 2) {
                $rc[] = ['channel_id' => $rest[$i], 'introduce' => $rest[$i + 1]];
            }
            $data = [
                'announces_type' => 0,
                'recommend_channels' => $rc,
            ];
            $resp = 创建频道公告($gid, $data);
            _频道管理_输出Json($resp, "创建频道公告(推荐子频道)");
        } else {
            文字("❌ 子指令: 频道公告 创建 消息类型|推荐子频道 ...");
        }
    } elseif ($action === '删除') {
        $msgId = $parts[1] ?? 'all';
        $resp = 删除频道公告($gid, $msgId);
        _频道管理_回复结果($resp, "删除频道公告 {$msgId}");
    } else {
        文字("❌ 未知子指令: {$action}\n支持: 创建 / 删除");
    }
}

// -------- 音频操作 --------
function _频道管理_音频操作($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法:\n  频道音频 播放 channel_id audio_url [状态文本]\n  频道音频 暂停 channel_id\n  频道音频 继续 channel_id\n  频道音频 停止 channel_id\n  频道音频 上麦 channel_id\n  频道音频 下麦 channel_id");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $action = $parts[0] ?? '';
    $channelId = $parts[1] ?? '';
    if (empty($channelId)) {
        文字("❌ 缺少 channel_id");
        return;
    }
    switch ($action) {
        case '播放':
            if (empty($parts[2])) {
                文字("❌ 用法: 频道音频 播放 channel_id audio_url [状态文本]");
                return;
            }
            $text = $parts[3] ?? '';
            $resp = 音频控制($channelId, 0, $parts[2], $text);
            _频道管理_回复结果($resp, "音频播放 [{$channelId}]");
            break;
        case '暂停':
            $resp = 音频控制($channelId, 1);
            _频道管理_回复结果($resp, "音频暂停 [{$channelId}]");
            break;
        case '继续':
            $resp = 音频控制($channelId, 2);
            _频道管理_回复结果($resp, "音频继续 [{$channelId}]");
            break;
        case '停止':
            $resp = 音频控制($channelId, 3);
            _频道管理_回复结果($resp, "音频停止 [{$channelId}]");
            break;
        case '上麦':
            $resp = 机器人上麦($channelId);
            _频道管理_回复结果($resp, "机器人上麦 [{$channelId}]");
            break;
        case '下麦':
            $resp = 机器人下麦($channelId);
            _频道管理_回复结果($resp, "机器人下麦 [{$channelId}]");
            break;
        default:
            文字("❌ 未知子指令: {$action}\n支持: 播放/暂停/继续/停止/上麦/下麦");
    }
}

// -------- 子频道权限操作 --------
function _频道管理_权限操作($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法:\n  频道权限 查询用户 channel_id user_id\n  频道权限 查询身份组 channel_id role_id\n  频道权限 改用户 channel_id user_id add[,remove] [remove]\n  频道权限 改身份组 channel_id role_id add[,remove] [remove]\n  位图: 1=可查看 2=可管理 4=可发言");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $action = $parts[0] ?? '';
    switch ($action) {
        case '查询用户':
            if (empty($parts[1]) || empty($parts[2])) {
                文字("❌ 用法: 频道权限 查询用户 channel_id user_id");
                return;
            }
            $resp = 获取子频道用户权限($parts[1], $parts[2]);
            _频道管理_输出Json($resp, "子频道用户权限");
            break;
        case '查询身份组':
            if (empty($parts[1]) || empty($parts[2])) {
                文字("❌ 用法: 频道权限 查询身份组 channel_id role_id");
                return;
            }
            $resp = 获取子频道身份组权限($parts[1], $parts[2]);
            _频道管理_输出Json($resp, "子频道身份组权限");
            break;
        case '改用户':
            if (empty($parts[1]) || empty($parts[2]) || empty($parts[3])) {
                文字("❌ 用法: 频道权限 改用户 channel_id user_id add [remove]");
                return;
            }
            $add = $parts[3];
            $remove = $parts[4] ?? '';
            $resp = 修改子频道用户权限($parts[1], $parts[2], $add, $remove);
            _频道管理_回复结果($resp, "修改用户权限 {$parts[2]} add={$add} remove={$remove}");
            break;
        case '改身份组':
            if (empty($parts[1]) || empty($parts[2]) || empty($parts[3])) {
                文字("❌ 用法: 频道权限 改身份组 channel_id role_id add [remove]");
                return;
            }
            $add = $parts[3];
            $remove = $parts[4] ?? '';
            $resp = 修改子频道身份组权限($parts[1], $parts[2], $add, $remove);
            _频道管理_回复结果($resp, "修改身份组权限 {$parts[2]} add={$add} remove={$remove}");
            break;
        default:
            文字("❌ 未知子指令: {$action}\n支持: 查询用户/查询身份组/改用户/改身份组");
    }
}

// -------- 日程操作 --------
function _频道管理_日程操作($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法:\n  频道日程 列表 channel_id [since时间戳ms]\n  频道日程 详情 channel_id schedule_id\n  频道日程 创建 channel_id 名称 开始ms 结束ms [跳转channel_id] [remind_type:0-5]\n  频道日程 修改 channel_id schedule_id 名称 开始ms 结束ms\n  频道日程 删除 channel_id schedule_id\nremind_type: 0=不提醒 1=开始时 2=5分钟前 3=15分钟前 4=30分钟前 5=60分钟前");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $action = $parts[0] ?? '';
    switch ($action) {
        case '列表':
            if (empty($parts[1])) {
                文字("❌ 用法: 频道日程 列表 channel_id [since时间戳ms]");
                return;
            }
            $since = isset($parts[2]) ? intval($parts[2]) : 0;
            $resp = 获取日程列表($parts[1], $since);
            _频道管理_输出Json($resp, "日程列表 [{$parts[1]}]");
            break;
        case '详情':
            if (empty($parts[1]) || empty($parts[2])) {
                文字("❌ 用法: 频道日程 详情 channel_id schedule_id");
                return;
            }
            $resp = 获取日程详情($parts[1], $parts[2]);
            _频道管理_输出Json($resp, "日程详情 [{$parts[2]}]");
            break;
        case '创建':
            if (empty($parts[1]) || empty($parts[2]) || empty($parts[3]) || empty($parts[4])) {
                文字("❌ 用法: 频道日程 创建 channel_id 名称 开始ms 结束ms [跳转channel_id] [remind_type]");
                return;
            }
            $sched = [
                'name' => $parts[2],
                'start_timestamp' => $parts[3],
                'end_timestamp' => $parts[4],
            ];
            if (isset($parts[5])) $sched['jump_channel_id'] = $parts[5];
            if (isset($parts[6])) $sched['remind_type'] = $parts[6];
            $resp = 创建日程($parts[1], $sched);
            _频道管理_输出Json($resp, "创建日程 {$parts[2]}");
            break;
        case '修改':
            if (empty($parts[1]) || empty($parts[2]) || empty($parts[3]) || empty($parts[4]) || empty($parts[5])) {
                文字("❌ 用法: 频道日程 修改 channel_id schedule_id 名称 开始ms 结束ms");
                return;
            }
            $sched = [
                'name' => $parts[3],
                'start_timestamp' => $parts[4],
                'end_timestamp' => $parts[5],
            ];
            $resp = 修改日程($parts[1], $parts[2], $sched);
            _频道管理_输出Json($resp, "修改日程 {$parts[2]}");
            break;
        case '删除':
            if (empty($parts[1]) || empty($parts[2])) {
                文字("❌ 用法: 频道日程 删除 channel_id schedule_id");
                return;
            }
            $resp = 删除日程($parts[1], $parts[2]);
            _频道管理_回复结果($resp, "删除日程 {$parts[2]}");
            break;
        default:
            文字("❌ 未知子指令: {$action}\n支持: 列表/详情/创建/修改/删除");
    }
}

// -------- 精华消息 --------
function _频道管理_精华操作($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法:\n  频道精华 列表 channel_id\n  频道精华 添加 channel_id message_id\n  频道精华 删除 channel_id message_id");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $action = $parts[0] ?? '';
    switch ($action) {
        case '列表':
            if (empty($parts[1])) {
                文字("❌ 用法: 频道精华 列表 channel_id");
                return;
            }
            $resp = 获取精华消息($parts[1]);
            _频道管理_输出Json($resp, "精华消息列表 [{$parts[1]}]");
            break;
        case '添加':
            if (empty($parts[1]) || empty($parts[2])) {
                文字("❌ 用法: 频道精华 添加 channel_id message_id");
                return;
            }
            $resp = 添加精华消息($parts[1], $parts[2]);
            _频道管理_回复结果($resp, "添加精华消息 {$parts[2]}");
            break;
        case '删除':
            if (empty($parts[1]) || empty($parts[2])) {
                文字("❌ 用法: 频道精华 删除 channel_id message_id");
                return;
            }
            $resp = 删除精华消息($parts[1], $parts[2]);
            _频道管理_回复结果($resp, "删除精华消息 {$parts[2]}");
            break;
        default:
            文字("❌ 未知子指令: {$action}\n支持: 列表/添加/删除");
    }
}

// -------- 表情表态 --------
function _频道管理_表态操作($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法:\n  频道表态 添加 channel_id message_id type emoji_id\n  频道表态 删除 channel_id message_id type emoji_id\n  频道表态 用户 channel_id message_id type emoji_id [cookie]\ntype: 1=系统表情 2=自定义表情");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $action = $parts[0] ?? '';
    switch ($action) {
        case '添加':
            if (count($parts) < 5) {
                文字("❌ 用法: 频道表态 添加 channel_id message_id type emoji_id");
                return;
            }
            $resp = 添加表态($parts[1], $parts[2], $parts[3], $parts[4]);
            _频道管理_回复结果($resp, "添加表态 {$parts[4]}");
            break;
        case '删除':
            if (count($parts) < 5) {
                文字("❌ 用法: 频道表态 删除 channel_id message_id type emoji_id");
                return;
            }
            $resp = 删除表态($parts[1], $parts[2], $parts[3], $parts[4]);
            _频道管理_回复结果($resp, "删除表态 {$parts[4]}");
            break;
        case '用户':
            if (count($parts) < 5) {
                文字("❌ 用法: 频道表态 用户 channel_id message_id type emoji_id [cookie]");
                return;
            }
            $cookie = $parts[5] ?? '';
            $resp = 获取表态用户列表($parts[1], $parts[2], $parts[3], $parts[4], $cookie);
            _频道管理_输出Json($resp, "表态用户列表");
            break;
        default:
            文字("❌ 未知子指令: {$action}\n支持: 添加/删除/用户");
    }
}

// -------- 语音子频道成员 --------
function _频道管理_语音成员($argsStr) {
    $parts = preg_split('/\s+/', trim($argsStr));
    if (empty($parts[0])) {
        文字("❌ 用法: 频道语音 channel_id\n获取语音子频道成员列表");
        return;
    }
    $resp = 获取语音成员($parts[0]);
    _频道管理_输出Json($resp, "语音子频道成员 [{$parts[0]}]");
}

// -------- 子频道消息管理 --------
function _频道管理_消息操作($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法:\n  频道消息 获取 channel_id message_id\n  频道消息 修改 channel_id message_id content");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $action = $parts[0] ?? '';
    switch ($action) {
        case '获取':
            if (count($parts) < 3) {
                文字("❌ 用法: 频道消息 获取 channel_id message_id");
                return;
            }
            $resp = 获取子频道消息($parts[1], $parts[2]);
            _频道管理_输出Json($resp, "子频道消息 [{$parts[2]}]");
            break;
        case '修改':
            if (count($parts) < 4) {
                文字("❌ 用法: 频道消息 修改 channel_id message_id content\n⚠️ 仅 markdown 消息可修改");
                return;
            }
            $content = implode(' ', array_slice($parts, 3));
            $data = ['content' => $content, 'msg_type' => 2];
            $resp = 修改子频道消息($parts[1], $parts[2], $data);
            _频道管理_回复结果($resp, "修改子频道消息 [{$parts[2]}]");
            break;
        default:
            文字("❌ 未知子指令: {$action}\n支持: 获取/修改");
    }
}

// -------- API权限 --------
function _频道管理_API权限操作($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法:\n  频道API权限 列表 [guild_id]\n  频道API权限 申请 channel_id path method\n例: 频道API权限 申请 cid123 /channels/{channel_id}/messages GET");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $action = $parts[0] ?? '';
    switch ($action) {
        case '列表':
            $gid = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : _频道管理_取GuildId();
            $resp = 获取API权限列表($gid);
            _频道管理_输出Json($resp, "API权限列表 [{$gid}]");
            break;
        case '申请':
            if (count($parts) < 4) {
                文字("❌ 用法: 频道API权限 申请 channel_id path method");
                return;
            }
            $apiIdentify = ['path' => $parts[2], 'method' => strtoupper($parts[3])];
            $gid = _频道管理_取GuildId();
            $resp = 申请API权限($gid, $parts[1], $apiIdentify);
            _频道管理_回复结果($resp, "申请API权限 {$parts[2]} {$parts[3]}");
            break;
        default:
            文字("❌ 未知子指令: {$action}\n支持: 列表/申请");
    }
}

// -------- 论坛帖子 --------
function _频道管理_帖子操作($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法:\n  频道帖子 列表 channel_id [cursor]\n  频道帖子 详情 channel_id thread_id\n  频道帖子 发表 channel_id 标题 内容 [format]\n  频道帖子 删除 channel_id thread_id\nformat: 0=未知 1=文本 2=HTML 3=Markdown 4=JSON");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $action = $parts[0] ?? '';
    switch ($action) {
        case '列表':
            if (empty($parts[1])) {
                文字("❌ 用法: 频道帖子 列表 channel_id [cursor]");
                return;
            }
            $cursor = $parts[2] ?? '';
            $resp = 获取帖子列表($parts[1], $cursor);
            _频道管理_输出Json($resp, "帖子列表 [{$parts[1]}]");
            break;
        case '详情':
            if (count($parts) < 3) {
                文字("❌ 用法: 频道帖子 详情 channel_id thread_id");
                return;
            }
            $resp = 获取帖子详情($parts[1], $parts[2]);
            _频道管理_输出Json($resp, "帖子详情 [{$parts[2]}]");
            break;
        case '发表':
            if (count($parts) < 4) {
                文字("❌ 用法: 频道帖子 发表 channel_id 标题 内容 [format]");
                return;
            }
            $data = [
                'title' => $parts[2],
                'content' => $parts[3],
                'format' => isset($parts[4]) ? intval($parts[4]) : 1,
            ];
            $resp = 发表帖子($parts[1], $data);
            _频道管理_回复结果($resp, "发表帖子 {$parts[2]}");
            break;
        case '删除':
            if (count($parts) < 3) {
                文字("❌ 用法: 频道帖子 删除 channel_id thread_id");
                return;
            }
            $resp = 删除帖子($parts[1], $parts[2]);
            _频道管理_回复结果($resp, "删除帖子 [{$parts[2]}]");
            break;
        default:
            文字("❌ 未知子指令: {$action}\n支持: 列表/详情/发表/删除");
    }
}

// -------- 频道禁言 --------
function _频道管理_禁言($argsStr) {
    $parts = preg_split('/\s+/', $argsStr);
    if (count($parts) < 2) {
        文字("❌ 用法: 频道禁言 user_id 时长\n时长单位: s/m/h/d，默认秒");
        return;
    }
    $userId = $parts[0];
    $timeStr = $parts[1];
    list($seconds, $err) = _频道管理_解析时长($timeStr);
    if ($err !== '') {
        文字("❌ {$err}");
        return;
    }
    $gid = _频道管理_取GuildId();
    $resp = 禁言成员($gid, $userId, $seconds);
    _频道管理_回复结果($resp, "禁言成员 {$userId} " . _频道管理_时长人类可读($seconds));
}

function _频道管理_解禁($argsStr) {
    $parts = preg_split('/\s+/', trim($argsStr));
    if (empty($parts[0])) {
        文字("❌ 用法: 频道解禁 user_id");
        return;
    }
    $gid = _频道管理_取GuildId();
    $resp = 解禁成员($gid, $parts[0]);
    _频道管理_回复结果($resp, "解禁成员 {$parts[0]}");
}

function _频道管理_批量禁言($argsStr) {
    $parts = preg_split('/\s+/', trim($argsStr));
    if (count($parts) < 2) {
        文字("❌ 用法: 频道批量禁言 user_id1 user_id2 ... 时长");
        return;
    }
    $timeStr = array_pop($parts);
    list($seconds, $err) = _频道管理_解析时长($timeStr);
    if ($err !== '') {
        文字("❌ {$err}");
        return;
    }
    $gid = _频道管理_取GuildId();
    $resp = 批量禁言($gid, $parts, $seconds);
    _频道管理_回复结果($resp, "批量禁言 " . count($parts) . " 位成员 " . _频道管理_时长人类可读($seconds));
}

function _频道管理_全员禁言($argsStr) {
    $parts = preg_split('/\s+/', trim($argsStr));
    if (empty($parts[0])) {
        文字("❌ 用法: 频道全员禁言 时长");
        return;
    }
    list($seconds, $err) = _频道管理_解析时长($parts[0]);
    if ($err !== '') {
        文字("❌ {$err}");
        return;
    }
    $gid = _频道管理_取GuildId();
    $resp = 全员禁言($gid, $seconds);
    _频道管理_回复结果($resp, "全员禁言 " . _频道管理_时长人类可读($seconds));
}

function _频道管理_解除全员禁言() {
    $gid = _频道管理_取GuildId();
    $resp = 解除全员禁言($gid);
    _频道管理_回复结果($resp, "解除全员禁言");
}

// ====================================================================
// 通用工具函数
// ====================================================================

function _频道管理_解析时长($str) {
    $str = trim($str);
    if ($str === '') return [0, '时长不能为空'];
    if (!preg_match('/^(\d+)\s*([smhd]?)$/i', $str, $m)) {
        return [0, '时长格式错误，应为 数字+单位(可选s/m/h/d)'];
    }
    $num = intval($m[1]);
    $unit = strtolower($m[2] ?? 's');
    switch ($unit) {
        case '':
        case 's': $seconds = $num; break;
        case 'm': $seconds = $num * 60; break;
        case 'h': $seconds = $num * 3600; break;
        case 'd': $seconds = $num * 86400; break;
        default: return [0, '未知时长单位'];
    }
    return [$seconds, ''];
}

function _频道管理_时长人类可读($seconds) {
    $seconds = intval($seconds);
    if ($seconds <= 0) return "0秒";
    if ($seconds < 60) return "{$seconds}秒";
    $days = (int)floor($seconds / 86400);
    $hours = (int)floor(($seconds % 86400) / 3600);
    $mins = (int)floor(($seconds % 3600) / 60);
    $parts = [];
    if ($days > 0) $parts[] = "{$days}天";
    if ($hours > 0) $parts[] = "{$hours}小时";
    if ($mins > 0) $parts[] = "{$mins}分钟";
    return implode('', $parts);
}

function _频道管理_回复结果($resp, $action) {
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        // 部分接口成功返回空字符串或 204 No Content
        if (trim($resp) === '' || trim($resp) === '{}') {
            文字("✅ {$action} 操作成功");
        } else {
            文字("✅ {$action} 完成\n响应: {$resp}");
        }
        return;
    }
    $code = $data['code'] ?? null;
    $message = $data['message'] ?? ($data['msg'] ?? '');
    if ($code === null || $code === 0 || $code === 200 || $code === '0' || $code === '200') {
        文字("✅ {$action} 操作成功");
    } else {
        文字("❌ {$action} 操作失败\n错误码: {$code}\n信息: {$message}");
    }
    wlog("[频道管理] {$action} | 响应: {$resp}", defined('appid') ? appid : null);
}

function _频道管理_输出Json($resp, $title) {
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        文字("❌ {$title} 失败: {$resp}");
        return;
    }
    $code = $data['code'] ?? null;
    if ($code !== null && $code !== 0 && $code !== 200 && $code !== '0' && $code !== '200') {
        $message = $data['message'] ?? ($data['msg'] ?? '');
        文字("❌ {$title} 失败\n错误码: {$code}\n信息: {$message}");
        return;
    }
    // 输出格式化 JSON（截断超长内容）
    $pretty = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (mb_strlen($pretty) > 3000) {
        $pretty = mb_substr($pretty, 0, 3000) . "\n... (已截断)";
    }
    文字("📋 {$title}\n\n{$pretty}");
}

function _频道管理_输出帮助() {
    $md  = "# 🏰 频道管理插件（管理员专用）\n\n";
    $md .= "QQ频道(Guild)完整管理能力，仅频道场景下管理员可用\n\n";
    $md .= "## 📋 指令列表\n\n";
    $md .= "### 频道信息\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `频道详情 [guild_id]` | 获取频道详情 |\n";
    $md .= "| `频道列表 [limit] [after]` | 机器人加入的频道列表 |\n\n";
    $md .= "### 成员管理\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `频道成员 详情 user_id` | 获取成员详情 |\n";
    $md .= "| `频道成员 列表 [limit] [after]` | 获取成员列表 |\n";
    $md .= "| `踢人 user_id [撤回天数] [是否拉黑]` | 移除频道成员 |\n\n";
    $md .= "### 身份组\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `频道身份组 列表` | 获取身份组列表 |\n";
    $md .= "| `频道身份组 创建 名称 [颜色] [hoist]` | 创建身份组 |\n";
    $md .= "| `频道身份组 修改 role_id [名称] [颜色] [hoist]` | 修改身份组 |\n";
    $md .= "| `频道身份组 删除 role_id` | 删除身份组 |\n";
    $md .= "| `频道身份组 成员 role_id [limit] [start]` | 身份组成员列表 |\n";
    $md .= "| `频道身份组 增加成员 user_id role_id [channel_id]` | 增加成员身份组 |\n";
    $md .= "| `频道身份组 删除成员 user_id role_id [channel_id]` | 删除成员身份组 |\n\n";
    $md .= "### 子频道\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `频道子频道 列表` | 获取子频道列表 |\n";
    $md .= "| `频道子频道 详情 channel_id` | 获取子频道详情 |\n";
    $md .= "| `频道子频道 创建 名称 type [sub_type] [parent_id]` | 创建子频道 |\n";
    $md .= "| `频道子频道 修改 channel_id [名称] [type]` | 修改子频道 |\n";
    $md .= "| `频道子频道 删除 channel_id` | 删除子频道 |\n";
    $md .= "> type: 0=文字 2=语音 4=分组 10005=直播 10006=应用 10007=论坛\n\n";
    $md .= "### 公告\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `频道公告 创建 消息类型 message_id channel_id [type]` | 创建消息类型公告 |\n";
    $md .= "| `频道公告 创建 推荐子频道 channel_id1 intro1 channel_id2 intro2 ...` | 创建推荐子频道公告 |\n";
    $md .= "| `频道公告 删除 [message_id=all]` | 删除公告 |\n\n";
    $md .= "### 音频\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `频道音频 播放 channel_id audio_url [状态文本]` | 开始播放 |\n";
    $md .= "| `频道音频 暂停 channel_id` | 暂停 |\n";
    $md .= "| `频道音频 继续 channel_id` | 继续 |\n";
    $md .= "| `频道音频 停止 channel_id` | 停止 |\n";
    $md .= "| `频道音频 上麦 channel_id` | 机器人上麦 |\n";
    $md .= "| `频道音频 下麦 channel_id` | 机器人下麦 |\n\n";
    $md .= "### 子频道权限\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `频道权限 查询用户 channel_id user_id` | 获取用户权限 |\n";
    $md .= "| `频道权限 查询身份组 channel_id role_id` | 获取身份组权限 |\n";
    $md .= "| `频道权限 改用户 channel_id user_id add [remove]` | 修改用户权限 |\n";
    $md .= "| `频道权限 改身份组 channel_id role_id add [remove]` | 修改身份组权限 |\n";
    $md .= "> 位图: 1=可查看 2=可管理 4=可发言\n\n";
    $md .= "### 日程\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `频道日程 列表 channel_id [since]` | 获取日程列表 |\n";
    $md .= "| `频道日程 详情 channel_id schedule_id` | 获取日程详情 |\n";
    $md .= "| `频道日程 创建 channel_id 名称 开始ms 结束ms [跳转cid] [remind]` | 创建日程 |\n";
    $md .= "| `频道日程 修改 channel_id schedule_id 名称 开始ms 结束ms` | 修改日程 |\n";
    $md .= "| `频道日程 删除 channel_id schedule_id` | 删除日程 |\n";
    $md .= "> remind_type: 0=不提醒 1=开始时 2=5分前 3=15分前 4=30分前 5=60分前\n\n";
    $md .= "### 精华消息\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `频道精华 列表 channel_id` | 获取精华消息列表 |\n";
    $md .= "| `频道精华 添加 channel_id message_id` | 添加精华消息 |\n";
    $md .= "| `频道精华 删除 channel_id message_id` | 删除精华消息 |\n\n";
    $md .= "### 表情表态\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `频道表态 添加 channel_id message_id type emoji_id` | 添加表态 |\n";
    $md .= "| `频道表态 删除 channel_id message_id type emoji_id` | 删除表态 |\n";
    $md .= "| `频道表态 用户 channel_id message_id type emoji_id [cookie]` | 表态用户列表 |\n";
    $md .= "> type: 1=系统表情 2=自定义表情\n\n";
    $md .= "### 语音与消息\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `频道语音 channel_id` | 语音子频道成员列表 |\n";
    $md .= "| `频道消息 获取 channel_id message_id` | 获取指定子频道消息 |\n";
    $md .= "| `频道消息 修改 channel_id message_id content` | 修改markdown消息 |\n\n";
    $md .= "### API权限与论坛\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `频道API权限 列表 [guild_id]` | 获取API权限列表 |\n";
    $md .= "| `频道API权限 申请 channel_id path method` | 申请API权限 |\n";
    $md .= "| `频道帖子 列表 channel_id [cursor]` | 论坛帖子列表 |\n";
    $md .= "| `频道帖子 详情 channel_id thread_id` | 帖子详情 |\n";
    $md .= "| `频道帖子 发表 channel_id 标题 内容 [format]` | 发表帖子 |\n";
    $md .= "| `频道帖子 删除 channel_id thread_id` | 删除帖子 |\n\n";
    $md .= "### 频道禁言\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `频道禁言 user_id 时长` | 禁言成员 |\n";
    $md .= "| `频道解禁 user_id` | 解禁成员 |\n";
    $md .= "| `频道批量禁言 user_id1 user_id2 ... 时长` | 批量禁言 |\n";
    $md .= "| `频道全员禁言 时长` | 全员禁言 |\n";
    $md .= "| `频道解除全员` | 解除全员禁言 |\n\n";
    $md .= "## ⏱ 时长格式\n\n";
    $md .= "- `60` 或 `60s` → 60秒\n";
    $md .= "- `5m` → 5分钟\n";
    $md .= "- `1h` → 1小时\n";
    $md .= "- `1d` → 1天\n\n";
    $md .= "> ⚠️ 仅频道/频道私信场景有效\n";
    $md .= "> ⚠️ 写操作要求机器人被添加为频道管理员\n";
    $md .= "> 📖 官方文档: https://bot.q.qq.com/wiki/develop/api-v2/server-inter/channel/guild-controller.html";
    MD($md);
}
?>
