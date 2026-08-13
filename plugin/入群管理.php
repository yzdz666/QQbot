<?php
// 插件：入群管理（管理员专用指令 + 事件通知）
// 功能：
//   1. 事件通知：入群申请 / 群成员增加 / 机器人加群
//   2. 管理员指令：入群自动审批策略管理（CRUD + 执行 + 白名单）
// 参照官方文档:
//   - 查询策略列表: GET /v2/groups/join_approval_strategy
//   - 创建策略: POST /v2/groups/join_approval_strategy
//   - 修改策略: PATCH /v2/groups/join_approval_strategy/{strategy_id}
//   - 删除策略: DELETE /v2/groups/join_approval_strategy/{strategy_id}
//   - 执行策略: POST /v2/groups/join_approval_strategy/{strategy_id}/execute
//   - 修改白名单: POST /v2/groups/join_approval_strategy/{strategy_id}/whitelist_users
//
// ⚠️ 管理员指令基于 bots 表 owner_ids 字段鉴权（见 bot.php 是否管理员()）

// ==================== 事件通知（无需管理员权限）====================
if (defined('消息来源')) {
    switch (消息来源) {
        case '入群申请':
            _入群管理_入群申请通知();
            return;
        case '群成员增加':
            _入群管理_群成员加入通知();
            return;
        case '加群':
            _入群管理_机器人加群通知();
            return;
    }
}

// ==================== 管理员指令处理 ====================
if (!defined('消息来源')) return;
if (!in_array(消息来源, ['群聊', '私聊'], true)) return;

// 管理员权限校验
if (!是否管理员()) {
    return;
}

$msg = trim(消息);
if ($msg === '' || strpos($msg, '审批策略') !== 0) return;

// 提取"审批策略"之后的子指令
$rest = trim(substr($msg, strlen('审批策略')));
if ($rest === '') {
    _入群管理_输出帮助();
    return;
}

$spacePos = strpos($rest, ' ');
if ($spacePos === false) {
    $subCmd = $rest;
    $argsStr = '';
} else {
    $subCmd = substr($rest, 0, $spacePos);
    $argsStr = trim(substr($rest, $spacePos + 1));
}

switch ($subCmd) {
    case '列表':
    case '查询':
        _入群管理_查询策略列表($argsStr);
        break;
    case '创建':
        _入群管理_创建策略($argsStr);
        break;
    case '修改':
        _入群管理_修改策略($argsStr);
        break;
    case '删除':
        _入群管理_删除策略($argsStr);
        break;
    case '执行':
        _入群管理_执行策略($argsStr);
        break;
    case '白名单':
        _入群管理_修改白名单($argsStr);
        break;
    case '帮助':
        _入群管理_输出帮助();
        break;
}

// ====================================================================
// 事件通知函数
// ====================================================================

function _入群管理_入群申请通知() {
    $userId = defined('用户') ? 用户 : '';
    $avatarUrl = 头像($userId);
    $md = "# 📝 有新成员申请入群\n\n![#200px #200px]({$avatarUrl})";
    MD($md);
}

function _入群管理_群成员加入通知() {
    $userId = defined('用户') ? 用户 : '';
    $avatarUrl = 头像($userId);
    $md = "# 👋 欢迎新成员加入！\n\n![#200px #200px]({$avatarUrl})";
    MD($md);
}

function _入群管理_机器人加群通知() {
    $userId = defined('用户') ? 用户 : '';
    $avatarUrl = 头像($userId);
    $md = "# 🎉 感谢邀请我加入群聊！\n\n![#200px #200px]({$avatarUrl})";
    MD($md);
}

// ====================================================================
// 管理员指令实现
// ====================================================================

/**
 * 查询策略列表
 * 用法: 审批策略 列表 [数量]
 */
function _入群管理_查询策略列表($argsStr) {
    $cursor = '';
    $limit = 20;
    if ($argsStr !== '') {
        $parts = preg_split('/\s+/', $argsStr);
        if (is_numeric($parts[0])) {
            $limit = max(1, min(100, intval($parts[0])));
        } else {
            $cursor = $parts[0];
        }
    }
    $resp = 查询入群审批策略列表($cursor, $limit);
    $data = json_decode($resp, true);
    if (!is_array($data) || isset($data['code'])) {
        文字("❌ 查询策略列表失败: {$resp}");
        return;
    }
    $records = $data['records'] ?? ($data['list'] ?? []);
    $md  = "# 📋 入群审批策略列表\n\n";
    if (empty($records)) {
        $md .= "暂无策略\n";
    } else {
        $md .= "| 策略ID | 名称 | 状态 | 过期时间 |\n|---|---|---|---|\n";
        foreach ($records as $r) {
            $sid = $r['strategy_id'] ?? '';
            $name = $r['name'] ?? '';
            $enabled = !empty($r['enabled']) ? '启用' : '停用';
            $expire = $r['expire_at'] ?? '永久';
            $md .= "| `{$sid}` | {$name} | {$enabled} | {$expire} |\n";
        }
    }
    if (!empty($data['next_cursor'])) {
        $md .= "\n> 下一页游标: `" . $data['next_cursor'] . "`\n";
    }
    MD($md);
}

/**
 * 创建策略
 * 用法: 审批策略 创建 名称 [过期时间]
 *   过期时间格式: YYYY-MM-DD 或留空(永久)
 */
function _入群管理_创建策略($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: 审批策略 创建 名称 [过期时间]\n例: 审批策略 创建 自动审批1 2026-12-31");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $data = ['name' => $parts[0]];
    if (isset($parts[1]) && $parts[1] !== '') {
        $data['expire_at'] = $parts[1];
    }
    $resp = 创建入群审批策略($data);
    $r = json_decode($resp, true);
    if (is_array($r) && isset($r['strategy_id'])) {
        文字("✅ 创建策略成功\n策略ID: " . $r['strategy_id']);
    } else {
        _入群管理_回复结果($resp, '创建策略');
    }
}

/**
 * 修改策略
 * 用法: 审批策略 修改 策略ID [启用|停用] [过期时间]
 */
function _入群管理_修改策略($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: 审批策略 修改 策略ID [启用|停用] [过期时间]\n例: 审批策略 修改 sid123 启用 2026-12-31");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $strategyId = $parts[0];
    if (empty($strategyId)) {
        文字("❌ 策略ID不能为空");
        return;
    }
    $data = [];
    if (isset($parts[1])) {
        $data['enabled'] = ($parts[1] === '启用');
    }
    if (isset($parts[2])) {
        $data['expire_at'] = $parts[2];
    }
    if (empty($data)) {
        文字("❌ 请至少指定一项修改内容(启用/停用 或 过期时间)");
        return;
    }
    $resp = 修改入群审批策略($strategyId, $data);
    _入群管理_回复结果($resp, '修改策略');
}

/**
 * 删除策略
 * 用法: 审批策略 删除 策略ID
 */
function _入群管理_删除策略($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: 审批策略 删除 策略ID");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $strategyId = $parts[0];
    $resp = 删除入群审批策略($strategyId);
    _入群管理_回复结果($resp, '删除策略');
}

/**
 * 执行策略 (全量扫描审批, 异步约10分钟)
 * 用法: 审批策略 执行 策略ID
 */
function _入群管理_执行策略($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: 审批策略 执行 策略ID\n⚠️ 异步执行, 约10分钟完成");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    $strategyId = $parts[0];
    $resp = 执行审批策略($strategyId);
    _入群管理_回复结果($resp, '执行策略(异步)');
}

/**
 * 修改白名单
 * 用法: 审批策略 白名单 策略ID 添加|删除 号码1 号码2 ...
 */
function _入群管理_修改白名单($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: 审批策略 白名单 策略ID 添加|删除 号码1 号码2 ...\n例: 审批策略 白名单 sid123 添加 13800001111");
        return;
    }
    $parts = preg_split('/\s+/', $argsStr);
    if (count($parts) < 3) {
        文字("❌ 参数不足\n用法: 审批策略 白名单 策略ID 添加|删除 号码1 号码2 ...");
        return;
    }
    $strategyId = $parts[0];
    $op = $parts[1];
    if (!in_array($op, ['添加', '删除', 'add', 'del'], true)) {
        文字("❌ 操作必须是 添加 或 删除");
        return;
    }
    $opApi = ($op === '添加' || $op === 'add') ? 'add' : 'del';
    $users = array_slice($parts, 2);
    if (count($users) > 10000) {
        文字("❌ 单次最多修改10000个白名单号码");
        return;
    }
    $data = ['op' => $opApi, 'whitelist_users' => $users];
    $resp = 修改审批策略白名单($strategyId, $data);
    _入群管理_回复结果($resp, '修改白名单(' . count($users) . '个号码)');
}

/**
 * 统一回复结果
 */
function _入群管理_回复结果($resp, $action) {
    $data = json_decode($resp, true);
    $code = $data['code'] ?? null;
    if ($code === null || $code === 0 || $code === 200) {
        文字("✅ {$action} 操作成功");
    } else {
        $message = $data['message'] ?? ($data['msg'] ?? '');
        文字("❌ {$action} 操作失败\n错误码: {$code}\n信息: {$message}");
    }
    wlog("[入群管理] {$action} | 响应: {$resp}", defined('appid') ? appid : null);
}

/**
 * 输出帮助
 */
function _入群管理_输出帮助() {
    $md  = "# 🛡️ 入群管理插件（管理员专用）\n\n";
    $md .= "## 📡 自动事件通知\n\n";
    $md .= "- 新成员申请入群时通知\n";
    $md .= "- 新成员加入群聊时欢迎\n";
    $md .= "- 机器人被邀请加群时致谢\n\n";
    $md .= "## 📋 审批策略指令\n\n";
    $md .= "指令前缀: `审批策略`\n\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `审批策略 列表 [数量]` | 查询策略列表 |\n";
    $md .= "| `审批策略 创建 名称 [过期时间]` | 创建策略 |\n";
    $md .= "| `审批策略 修改 策略ID 启用|停用 [过期时间]` | 修改策略 |\n";
    $md .= "| `审批策略 删除 策略ID` | 删除策略 |\n";
    $md .= "| `审批策略 执行 策略ID` | 执行策略(异步,约10分钟) |\n";
    $md .= "| `审批策略 白名单 策略ID 添加|删除 号码...` | 修改白名单(单次≤1万) |\n\n";
    $md .= "> 📖 官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_join_approval_strategy.post.html";
    MD($md);
}
?>
