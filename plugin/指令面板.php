<?php
// 插件：指令面板管理（仅管理员）
// 功能：管理机器人指令面板（/v2/panels）
// 参照官方文档:
//   - 创建: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels.post.html
//   - 查询列表: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels.get.html
//   - 查询详情: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels_panel_id.get.html
//   - 修改: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels_panel_id.put.html
//   - 删除: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels_panel_id.delete.html
//   - 修改关联对象: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels_panel_id_target.put.html
//
// 支持 c2c(单聊)/group(群聊)/channel(文字子频道)/dm(频道私信) 四种场景
// 一个机器人最多20个面板，每个面板最多20个元素
// 元素类型: command(指令,点击填入输入框) / link(链接跳转,需https)
//
// ⚠️ 仅管理员可用，权限基于 bots 表 owner_ids 字段（见 bot.php 是否管理员()）

// ==================== 仅处理消息类事件 ====================
if (!defined('消息来源')) return;
if (!in_array(消息来源, ['群聊', '私聊', '频道', '频道私信'], true)) return;

// ==================== 管理员权限校验 ====================
if (!是否管理员()) {
    return;
}

// ==================== 解析指令 ====================
$msg = trim(消息);
if ($msg === '' || strpos($msg, '面板') !== 0) return;

// 提取"面板"之后的子指令与参数
$rest = trim(substr($msg, strlen('面板')));
if ($rest === '') {
    _面板管理_输出帮助();
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

// ==================== 指令分发 ====================
switch ($subCmd) {
    // -------- 创建面板 --------
    case '创建':
    case 'create':
        _面板管理_创建面板($argsStr);
        break;

    // -------- 查询面板列表 --------
    case '列表':
    case 'list':
        _面板管理_查询列表($argsStr);
        break;

    // -------- 查询面板详情 --------
    case '详情':
    case 'detail':
        _面板管理_查询详情($argsStr);
        break;

    // -------- 删除面板 --------
    case '删除':
    case 'delete':
        _面板管理_删除面板($argsStr);
        break;

    // -------- 快捷创建: 指令面板 --------
    case '指令':
        _面板管理_快捷指令面板($argsStr);
        break;

    // -------- 快捷创建: 链接面板 --------
    case '链接':
        _面板管理_快捷链接面板($argsStr);
        break;

    // -------- 自定义JSON --------
    case '自定义':
    case 'custom':
    case 'json':
        _面板管理_自定义面板($argsStr);
        break;

    // -------- 帮助 --------
    case '帮助':
    case 'help':
    case '?':
    default:
        _面板管理_输出帮助();
        break;
}

// ====================================================================
// 以下为内部实现函数（前缀 _面板管理_ 避免与其它插件冲突）
// ====================================================================

/**
 * 创建面板（完整参数）
 * 用法: 面板 创建 {JSON}
 *   例: 面板 创建 {"scope":"group","target_type":"all","panel":{"items":[{"type":"command","name":"签到","desc":"每日签到"}]}}
 */
function _面板管理_创建面板($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: 面板 创建 {JSON}\n例: 面板 创建 {\"scope\":\"group\",\"target_type\":\"all\",\"panel\":{\"items\":[{\"type\":\"command\",\"name\":\"签到\",\"desc\":\"每日签到\"}]}}");
        return;
    }
    $data = json_decode($argsStr, true);
    if (!is_array($data)) {
        文字("❌ JSON 解析失败\n输入: {$argsStr}");
        return;
    }
    if (empty($data['scope'])) {
        文字("❌ 缺少 scope 字段(可选: c2c/group/channel/dm)");
        return;
    }
    if (empty($data['panel'])) {
        文字("❌ 缺少 panel 字段");
        return;
    }
    $resp = 创建指令面板($data);
    $r = json_decode($resp, true);
    if (isset($r['panel_id'])) {
        文字("✅ 面板创建成功\n面板ID: {$r['panel_id']}");
    } else {
        文字("❌ 创建失败\n响应: {$resp}");
    }
}

/**
 * 查询面板列表
 * 用法: 面板列表 [scope]
 *   scope 可选: c2c/group/channel/dm，默认 group
 */
function _面板管理_查询列表($argsStr) {
    $scope = trim($argsStr);
    if ($scope === '') $scope = 'group';
    $validScopes = ['c2c', 'group', 'channel', 'dm'];
    if (!in_array($scope, $validScopes, true)) {
        文字("❌ scope 无效，可选: c2c/group/channel/dm");
        return;
    }
    $resp = 查询面板列表($scope, '', 50);
    $data = json_decode($resp, true);
    if (!is_array($data) || !isset($data['records'])) {
        文字("❌ 查询失败\n响应: {$resp}");
        return;
    }
    $records = $data['records'] ?? [];
    if (empty($records)) {
        文字("📋 {$scope} 场景下暂无指令面板");
        return;
    }
    $md = "# 📋 指令面板列表 ({$scope})\n\n";
    $md .= "共 " . count($records) . " 个面板\n\n";
    $md .= "| 面板ID | 范围 | 元素数 | 备注 |\n";
    $md .= "|--------|------|--------|------|\n";
    foreach ($records as $r) {
        $pid = $r['panel_id'] ?? '';
        $tt = $r['target_type'] ?? '';
        $itemCount = count($r['panel']['items'] ?? []);
        $remark = $r['panel']['remark'] ?? '';
        $md .= "| `{$pid}` | {$tt} | {$itemCount} | {$remark} |\n";
    }
    MD($md);
}

/**
 * 查询面板详情
 * 用法: 面板 详情 面板ID
 */
function _面板管理_查询详情($argsStr) {
    $panelId = trim($argsStr);
    if ($panelId === '') {
        文字("❌ 用法: 面板 详情 面板ID");
        return;
    }
    $resp = 查询面板详情($panelId);
    $data = json_decode($resp, true);
    if (!is_array($data) || !isset($data['panel_id'])) {
        文字("❌ 查询失败\n响应: {$resp}");
        return;
    }
    $md = "# 📋 面板详情\n\n";
    $md .= "**面板ID**: `{$data['panel_id']}`\n";
    $md .= "**场景**: {$data['scope']}\n";
    $md .= "**范围**: {$data['target_type']}\n";
    $md .= "**版本**: " . ($data['version'] ?? '') . "\n\n";
    $items = $data['panel']['items'] ?? [];
    if (!empty($items)) {
        $md .= "## 元素列表\n\n";
        $md .= "| 名称 | 类型 | 描述 | 仅管理员 |\n";
        $md .= "|------|------|------|----------|\n";
        foreach ($items as $item) {
            $name = $item['name'] ?? '';
            $type = $item['type'] ?? '';
            $desc = $item['desc'] ?? '';
            $onlyAdmin = !empty($item['only_admin']) ? '是' : '否';
            $md .= "| {$name} | {$type} | {$desc} | {$onlyAdmin} |\n";
        }
        $md .= "\n";
    }
    // 关联对象
    $userOpenids = $data['user_openids'] ?? [];
    $groupOpenids = $data['group_openids'] ?? [];
    if (!empty($userOpenids)) {
        $md .= "**关联用户**: " . count($userOpenids) . " 个\n";
    }
    if (!empty($groupOpenids)) {
        $md .= "**关联群**: " . count($groupOpenids) . " 个\n";
    }
    MD($md);
}

/**
 * 删除面板
 * 用法: 面板 删除 面板ID
 */
function _面板管理_删除面板($argsStr) {
    $panelId = trim($argsStr);
    if ($panelId === '') {
        文字("❌ 用法: 面板 删除 面板ID\n⚠️ 此操作不可撤销");
        return;
    }
    $resp = 删除指令面板($panelId);
    $data = json_decode($resp, true);
    // 删除成功返回 {}，失败返回 code+message
    $code = $data['code'] ?? null;
    if ($code === null || $code === 0) {
        文字("✅ 面板 {$panelId} 已删除");
    } else {
        $message = $data['message'] ?? '未知错误';
        文字("❌ 删除失败\n错误码: {$code}\n信息: {$message}");
    }
}

/**
 * 快捷创建: 指令面板（单个指令元素）
 * 用法: 面板 指令 场景 指令名称 [描述]
 *   例: 面板 指令 group 签到 每日签到
 *   例: 面板 指令 c2c 帮助
 */
function _面板管理_快捷指令面板($argsStr) {
    $parts = preg_split('/\s+/', trim($argsStr));
    if (count($parts) < 2) {
        文字("❌ 用法: 面板 指令 场景 指令名称 [描述]\n例: 面板 指令 group 签到 每日签到\n场景: c2c/group/channel/dm");
        return;
    }
    $scope = trim($parts[0]);
    $name = trim($parts[1]);
    $desc = trim($parts[2] ?? '');
    $validScopes = ['c2c', 'group', 'channel', 'dm'];
    if (!in_array($scope, $validScopes, true)) {
        文字("❌ 场景无效，可选: c2c/group/channel/dm");
        return;
    }
    $data = [
        'scope' => $scope,
        'target_type' => 'all',
        'panel' => [
            'items' => [
                ['type' => 'command', 'name' => $name]
            ]
        ]
    ];
    if ($desc !== '') $data['panel']['items'][0]['desc'] = $desc;
    $resp = 创建指令面板($data);
    $r = json_decode($resp, true);
    if (isset($r['panel_id'])) {
        文字("✅ 指令面板创建成功\n面板ID: {$r['panel_id']}\n场景: {$scope}\n指令: {$name}");
    } else {
        文字("❌ 创建失败\n响应: {$resp}");
    }
}

/**
 * 快捷创建: 链接面板（单个链接元素，必须 https://）
 * 用法: 面板 链接 场景 名称 https://链接 [描述]
 *   例: 面板 链接 group 官网 https://example.com
 */
function _面板管理_快捷链接面板($argsStr) {
    $parts = preg_split('/\s+/', trim($argsStr));
    if (count($parts) < 3) {
        文字("❌ 用法: 面板 链接 场景 名称 https://链接 [描述]\n例: 面板 链接 group 官网 https://example.com\n⚠️ 链接必须 https://");
        return;
    }
    $scope = trim($parts[0]);
    $name = trim($parts[1]);
    $link = trim($parts[2]);
    $desc = trim($parts[3] ?? '');
    $validScopes = ['c2c', 'group', 'channel', 'dm'];
    if (!in_array($scope, $validScopes, true)) {
        文字("❌ 场景无效，可选: c2c/group/channel/dm");
        return;
    }
    if (stripos($link, 'https://') !== 0) {
        文字("❌ 链接必须以 https:// 开头");
        return;
    }
    $item = ['type' => 'link', 'name' => $name, 'link' => $link];
    if ($desc !== '') $item['desc'] = $desc;
    $data = [
        'scope' => $scope,
        'target_type' => 'all',
        'panel' => ['items' => [$item]]
    ];
    $resp = 创建指令面板($data);
    $r = json_decode($resp, true);
    if (isset($r['panel_id'])) {
        文字("✅ 链接面板创建成功\n面板ID: {$r['panel_id']}\n场景: {$scope}\n名称: {$name}\n链接: {$link}");
    } else {
        文字("❌ 创建失败\n响应: {$resp}");
    }
}

/**
 * 自定义JSON面板
 * 用法: 面板 自定义 {JSON}
 * 与"创建"相同，直接透传JSON
 */
function _面板管理_自定义面板($argsStr) {
    _面板管理_创建面板($argsStr);
}

/**
 * 输出帮助信息
 */
function _面板管理_输出帮助() {
    $md  = "# 📋 指令面板管理插件（管理员专用）\n\n";
    $md .= "管理机器人指令面板，所有操作仅管理员可用\n\n";
    $md .= "## 🚀 快捷创建\n\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `面板 指令 场景 指令名 [描述]` | 创建指令面板 |\n";
    $md .= "| `面板 链接 场景 名称 https://链接 [描述]` | 创建链接面板 |\n\n";
    $md .= "## 🛠 管理操作\n\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `面板 创建 {JSON}` | 完整JSON创建面板 |\n";
    $md .= "| `面板 列表 [场景]` | 查询面板列表(默认group) |\n";
    $md .= "| `面板 详情 面板ID` | 查询面板详情 |\n";
    $md .= "| `面板 删除 面板ID` | 删除面板 |\n";
    $md .= "| `面板 自定义 {JSON}` | 自定义JSON创建 |\n\n";
    $md .= "## 📌 场景说明\n\n";
    $md .= "| 场景 | 说明 | 指定对象 |\n|------|------|----------|\n";
    $md .= "| c2c | 单聊 | 支持 specific(用户) |\n";
    $md .= "| group | 群聊 | 支持 specific(群) |\n";
    $md .= "| channel | 文字子频道 | 仅 all |\n";
    $md .= "| dm | 频道私信 | 仅 all |\n\n";
    $md .= "## 📐 限制\n\n";
    $md .= "- 一个机器人最多 **20** 个面板\n";
    $md .= "- 每个面板最多 **20** 个元素\n";
    $md .= "- 元素名称最多 **14** 字符(约7汉字)\n";
    $md .= "- 元素描述最多 **30** 字符(约15汉字)\n";
    $md .= "- 链接必须 **https://** 开头\n\n";
    $md .= "## 📖 JSON结构示例\n\n";
    $md .= "```json\n";
    $md .= "{\n";
    $md .= '  "scope": "group",' . "\n";
    $md .= '  "target_type": "all",' . "\n";
    $md .= '  "panel": {' . "\n";
    $md .= '    "items": [' . "\n";
    $md .= '      {"type":"command","name":"签到","desc":"每日签到"},' . "\n";
    $md .= '      {"type":"link","name":"官网","link":"https://example.com","only_admin":false}' . "\n";
    $md .= "    ],\n";
    $md .= '    "remark": "群面板"' . "\n";
    $md .= "  }\n";
    $md .= "}\n";
    $md .= "```\n\n";
    $md .= "> 📖 官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels.post.html";
    MD($md);
}
?>
