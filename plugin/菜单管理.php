<?php
// 插件：菜单管理（仅管理员）
// 功能：通过指令管理机器人自定义菜单（PUT /v2/menu）
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/openapi/menu/
// 支持的菜单项类型:
//   send_message - 发送消息按钮 (字段: name, send_message, 可选 icon)
//   link         - 链接跳转按钮 (字段: name, link，link 必须 https://)
//   switch       - 开关按钮 (字段: name, switch:{switch_id, default})
//   menu         - 折叠子菜单 (字段: name, sub_menu_items:[...])
//
// ⚠️ 仅管理员可用，权限基于 bots 表 owner_ids 字段（见 bot.php 是否管理员()）

// ==================== 仅处理消息类事件 ====================
if (!defined('消息来源')) return;
if (!in_array(消息来源, ['群聊', '私聊', '频道', '频道私信'], true)) return;

// ==================== 管理员权限校验 ====================
if (!是否管理员()) {
    return; // 非管理员静默忽略
}

// ==================== 解析指令 ====================
// 指令前缀: "菜单"，支持空格分隔参数
$msg = trim(消息);
if ($msg === '' || strpos($msg, '菜单') !== 0) return;

// 提取"菜单"之后的子指令与参数
$rest = trim(substr($msg, strlen('菜单')));
if ($rest === '') {
    // 仅输入"菜单"，显示帮助
    _菜单管理_输出帮助();
    return;
}

// 拆分 [子指令] [剩余参数]
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
    // -------- 1. 发送消息按钮 (send_message) --------
    case '1':
    case '发送':
    case 'send_message':
        _菜单管理_发送按钮($argsStr);
        break;

    // -------- 2. 链接跳转按钮 (link, 必须 https://) --------
    case '2':
    case '链接':
    case 'link':
        _菜单管理_链接按钮($argsStr);
        break;

    // -------- 3. 开关按钮 (switch) --------
    case '3':
    case '开关':
    case 'switch':
        _菜单管理_开关按钮($argsStr);
        break;

    // -------- 4. 折叠子菜单 (menu) --------
    case '4':
    case '折叠':
    case '子菜单':
    case 'menu':
        _菜单管理_子菜单($argsStr);
        break;

    // -------- 5. 混合菜单 --------
    case '5':
    case '混合':
    case 'mixed':
        _菜单管理_混合菜单();
        break;

    // -------- 6. 带图标的发送消息按钮 --------
    case '6':
    case '图标':
    case 'icon':
        _菜单管理_图标按钮($argsStr);
        break;

    // -------- 7. 简单版（单个发送消息按钮）--------
    case '7':
    case '简单':
    case 'simple':
        _菜单管理_简单按钮($argsStr);
        break;

    // -------- 查看当前菜单 --------
    case '查看':
    case 'get':
        _菜单管理_查看菜单();
        break;

    // -------- 删除菜单 --------
    case '删除':
    case 'delete':
    case '清空':
        _菜单管理_删除菜单();
        break;

    // -------- 自定义 JSON 菜单 --------
    case '自定义':
    case 'custom':
    case 'json':
        _菜单管理_自定义菜单($argsStr);
        break;

    // -------- 持久化: 保存菜单配置到数据库 --------
    case '保存':
    case 'save':
        _菜单管理_保存配置();
        break;

    // -------- 持久化: 从数据库加载并应用 --------
    case '加载':
    case 'load':
        _菜单管理_加载配置();
        break;

    // -------- 帮助 --------
    case '帮助':
    case 'help':
    case '?':
    default:
        _菜单管理_输出帮助();
        break;
}

// ====================================================================
// 以下为内部实现函数（前缀 _菜单管理_ 避免与其它插件冲突）
// ====================================================================

/**
 * 1. 发送消息按钮 (send_message)
 * 用法: 菜单 发送 名称 内容
 *   例: 菜单 发送 帮助 /help
 *   无参数时使用预设示例
 */
function _菜单管理_发送按钮($argsStr) {
    if ($argsStr === '') {
        // 预设示例
        $menu = [
            'menu' => [
                'items' => [
                    ['type' => 'send_message', 'name' => '帮助', 'send_message' => '/help']
                ]
            ]
        ];
    } else {
        $parts = explode(' ', $argsStr, 2);
        $name = trim($parts[0] ?? '');
        $content = trim($parts[1] ?? '');
        if ($name === '' || $content === '') {
            文字("❌ 用法: 菜单 发送 名称 内容\n例: 菜单 发送 帮助 /help");
            return;
        }
        $menu = [
            'menu' => [
                'items' => [
                    ['type' => 'send_message', 'name' => $name, 'send_message' => $content]
                ]
            ]
        ];
    }
    _菜单管理_应用并回复($menu, '发送消息按钮');
}

/**
 * 2. 链接跳转按钮 (link, 必须 https://)
 * 用法: 菜单 链接 名称 https://xxx
 *   例: 菜单 链接 官网 https://qwq.tangdouz.com
 */
function _菜单管理_链接按钮($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: 菜单 链接 名称 https://链接\n⚠️ 链接必须以 https:// 开头");
        return;
    }
    $parts = explode(' ', $argsStr, 2);
    $name = trim($parts[0] ?? '');
    $link = trim($parts[1] ?? '');
    if ($name === '' || $link === '') {
        文字("❌ 用法: 菜单 链接 名称 https://链接");
        return;
    }
    // 强制校验 https://
    if (stripos($link, 'https://') !== 0) {
        文字("❌ 链接必须以 https:// 开头\n当前链接: {$link}");
        return;
    }
    $menu = [
        'menu' => [
            'items' => [
                ['type' => 'link', 'name' => $name, 'link' => $link]
            ]
        ]
    ];
    _菜单管理_应用并回复($menu, '链接跳转按钮');
}

/**
 * 3. 开关按钮 (switch)
 * 用法: 菜单 开关 名称 switch_id [on|off]
 *   例: 菜单 开关 消息提醒 notify_switch on
 */
function _菜单管理_开关按钮($argsStr) {
    if ($argsStr === '') {
        // 预设示例
        $menu = [
            'menu' => [
                'items' => [
                    ['type' => 'switch', 'name' => '消息提醒',
                     'switch' => ['switch_id' => 'notify_switch', 'default' => true]]
                ]
            ]
        ];
    } else {
        $parts = preg_split('/\s+/', $argsStr);
        $name = trim($parts[0] ?? '');
        $switchId = trim($parts[1] ?? '');
        $defaultStr = strtolower(trim($parts[2] ?? 'on'));
        if ($name === '' || $switchId === '') {
            文字("❌ 用法: 菜单 开关 名称 switch_id [on|off]");
            return;
        }
        $default = in_array($defaultStr, ['on', 'true', '1', '开'], true);
        $menu = [
            'menu' => [
                'items' => [
                    ['type' => 'switch', 'name' => $name,
                     'switch' => ['switch_id' => $switchId, 'default' => $default]]
                ]
            ]
        ];
    }
    _菜单管理_应用并回复($menu, '开关按钮');
}

/**
 * 4. 折叠子菜单 (menu)
 * 用法: 菜单 折叠
 *   预设: 包含"设置"和"反馈"两个子项
 */
function _菜单管理_子菜单($argsStr) {
    // 预设示例（与用户测试用例一致）
    $menu = [
        'menu' => [
            'items' => [
                ['type' => 'menu', 'name' => '更多',
                 'sub_menu_items' => [
                     ['type' => 'send_message', 'name' => '设置', 'send_message' => '/settings'],
                     ['type' => 'link', 'name' => '反馈', 'link' => 'https://example.com']
                 ]]
            ]
        ]
    ];
    _菜单管理_应用并回复($menu, '折叠子菜单');
}

/**
 * 5. 混合菜单（多个按钮组合）
 */
function _菜单管理_混合菜单() {
    $menu = [
        'menu' => [
            'items' => [
                ['type' => 'send_message', 'name' => '帮助', 'send_message' => '/help'],
                ['type' => 'link', 'name' => '官网', 'link' => 'https://example.com'],
                ['type' => 'menu', 'name' => '更多',
                 'sub_menu_items' => [
                     ['type' => 'send_message', 'name' => '设置', 'send_message' => '/settings']
                 ]]
            ]
        ]
    ];
    _菜单管理_应用并回复($menu, '混合菜单');
}

/**
 * 6. 带图标的发送消息按钮
 * 用法: 菜单 图标 名称 icon_url 内容
 *   例: 菜单 图标 菜单 https://xxx.png 菜单
 */
function _菜单管理_图标按钮($argsStr) {
    if ($argsStr === '') {
        // 预设示例（使用官方文档示例图标）
        $menu = [
            'menu' => [
                'items' => [
                    ['type' => 'send_message',
                     'icon' => 'https://bot-resource-1251316161.file.myqcloud.com/avatar/4afc01b8-844c-48ef-9b99-c7978688113b2517704087985544616?ts=1734693433',
                     'name' => '菜单', 'send_message' => '菜单']
                ]
            ]
        ];
    } else {
        $parts = explode(' ', $argsStr, 3);
        $name = trim($parts[0] ?? '');
        $icon = trim($parts[1] ?? '');
        $content = trim($parts[2] ?? '');
        if ($name === '' || $icon === '' || $content === '') {
            文字("❌ 用法: 菜单 图标 名称 图标URL 内容\n⚠️ 图标URL必须以 https:// 开头");
            return;
        }
        if (stripos($icon, 'https://') !== 0) {
            文字("❌ 图标URL必须以 https:// 开头");
            return;
        }
        $menu = [
            'menu' => [
                'items' => [
                    ['type' => 'send_message', 'icon' => $icon,
                     'name' => $name, 'send_message' => $content]
                ]
            ]
        ];
    }
    _菜单管理_应用并回复($menu, '带图标发送消息按钮');
}

/**
 * 7. 简单版（只有一个发送消息按钮）
 * 用法: 菜单 简单 名称 内容
 *   例: 菜单 简单 菜单 菜单
 */
function _菜单管理_简单按钮($argsStr) {
    if ($argsStr === '') {
        $menu = [
            'menu' => [
                'items' => [
                    ['type' => 'send_message', 'name' => '菜单', 'send_message' => '菜单']
                ]
            ]
        ];
    } else {
        $parts = explode(' ', $argsStr, 2);
        $name = trim($parts[0] ?? '');
        $content = trim($parts[1] ?? '');
        if ($name === '' || $content === '') {
            文字("❌ 用法: 菜单 简单 名称 内容");
            return;
        }
        $menu = [
            'menu' => [
                'items' => [
                    ['type' => 'send_message', 'name' => $name, 'send_message' => $content]
                ]
            ]
        ];
    }
    _菜单管理_应用并回复($menu, '简单版菜单');
}

/**
 * 查看当前菜单 (GET /v2/menu)
 */
function _菜单管理_查看菜单() {
    $resp = 获取菜单();
    $data = json_decode($resp, true);
    if (isset($data['code']) && $data['code'] !== 0 && $data['code'] !== 200) {
        $msg = "❌ 获取菜单失败\n响应: {$resp}";
    } elseif (isset($data['menu']['items'])) {
        $msg = "📋 当前菜单配置:\n\n```json\n" . json_encode($data['menu'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```";
    } else {
        $msg = "📋 当前菜单响应:\n\n```json\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n```";
    }
    MD($msg);
}

/**
 * 删除菜单 (DELETE /v2/menu)
 */
function _菜单管理_删除菜单() {
    $resp = 删除菜单();
    $data = json_decode($resp, true);
    if (isset($data['code']) && ($data['code'] === 0 || $data['code'] === 200)) {
        文字("✅ 菜单已删除");
    } else {
        文字("🗑️ 删除菜单请求已发送\n响应: {$resp}");
    }
}

/**
 * 自定义 JSON 菜单
 * 用法: 菜单 自定义 {"menu":{"items":[...]}}
 * 自动兼容: 缺少 menu 外层会自动补全
 */
function _菜单管理_自定义菜单($argsStr) {
    if ($argsStr === '') {
        文字("❌ 用法: 菜单 自定义 {JSON字符串}\n例: 菜单 自定义 {\"menu\":{\"items\":[{\"type\":\"send_message\",\"name\":\"帮助\",\"send_message\":\"/help\"}]}}");
        return;
    }
    $data = json_decode($argsStr, true);
    if (!is_array($data)) {
        文字("❌ JSON 解析失败，请检查格式\n输入: {$argsStr}");
        return;
    }
    // 兼容: 如果传入的是 items 数组，自动包成 menu 结构
    if (isset($data['items'])) {
        $menu = ['menu' => $data];
    } elseif (isset($data['menu'])) {
        $menu = $data;
    } else {
        文字("❌ JSON 结构无效，应包含 menu.items 或 items 字段");
        return;
    }
    _菜单管理_应用并回复($menu, '自定义菜单');
}

/**
 * 保存当前菜单配置到数据库（持久化）
 * 读取 GET /v2/menu 返回的内容并存储
 */
function _菜单管理_保存配置() {
    $resp = 获取菜单();
    $data = json_decode($resp, true);
    if (!isset($data['menu']['items']) && !isset($data['menu'])) {
        文字("❌ 当前无菜单可保存，或获取失败\n响应: {$resp}");
        return;
    }
    $ns = 'menu_config_' . appid;
    写($ns, 'last_menu', $resp);
    写($ns, 'saved_at', date('Y-m-d H:i:s'));
    文字("✅ 菜单配置已保存到数据库\n保存时间: " . date('Y-m-d H:i:s'));
}

/**
 * 从数据库加载并应用菜单配置
 */
function _菜单管理_加载配置() {
    $ns = 'menu_config_' . appid;
    $saved = 读($ns, 'last_menu', '');
    $savedAt = 读($ns, 'saved_at', '');
    if ($saved === '') {
        文字("❌ 数据库中未找到已保存的菜单配置");
        return;
    }
    $menu = json_decode($saved, true);
    if (!is_array($menu)) {
        文字("❌ 存储的菜单配置已损坏，无法加载");
        return;
    }
    _菜单管理_应用并回复($menu, "加载的菜单 (保存于 {$savedAt})");
}

/**
 * 应用菜单并回复结果
 */
function _菜单管理_应用并回复($menu, $label) {
    $resp = 设置菜单($menu);
    $data = json_decode($resp, true);
    $pretty = json_encode($menu, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (isset($data['code']) && ($data['code'] === 0 || $data['code'] === 200)) {
        $msg = "✅ {$label} 设置成功\n\n```json\n{$pretty}\n```";
        if (isset($data['message']) && $data['message']) {
            $msg .= "\n\n响应: {$data['message']}";
        }
    } else {
        $msg = "⚠️ {$label} 设置请求已发送\n\n请求:\n```json\n{$pretty}\n```\n\n响应:\n```\n{$resp}\n```";
    }
    MD($msg);
}

/**
 * 输出帮助信息
 */
function _菜单管理_输出帮助() {
    $md  = "# 📋 菜单管理插件（管理员专用）\n\n";
    $md .= "通过指令管理机器人自定义菜单，所有操作仅管理员可用\n\n";
    $md .= "## 🚀 快速预设（7种菜单类型）\n\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `菜单 1` | 发送消息按钮 (send_message) |\n";
    $md .= "| `菜单 2` | 链接跳转按钮 (link, 必须 https) |\n";
    $md .= "| `菜单 3` | 开关按钮 (switch) |\n";
    $md .= "| `菜单 4` | 折叠子菜单 (menu) |\n";
    $md .= "| `菜单 5` | 混合菜单（多种组合）|\n";
    $md .= "| `菜单 6` | 带图标的发送消息按钮 |\n";
    $md .= "| `菜单 7` | 简单版（单个按钮）|\n\n";
    $md .= "## 🛠 自定义配置\n\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `菜单 发送 名称 内容` | 自定义发送消息按钮 |\n";
    $md .= "| `菜单 链接 名称 https://链接` | 自定义链接按钮 |\n";
    $md .= "| `菜单 开关 名称 ID [on/off]` | 自定义开关按钮 |\n";
    $md .= "| `菜单 图标 名称 图标URL 内容` | 自定义带图标按钮 |\n";
    $md .= "| `菜单 简单 名称 内容` | 自定义简单按钮 |\n";
    $md .= "| `菜单 自定义 {JSON}` | 完整JSON自定义 |\n\n";
    $md .= "## 📦 管理操作\n\n";
    $md .= "| 指令 | 说明 |\n|------|------|\n";
    $md .= "| `菜单 查看` | 查看当前菜单 |\n";
    $md .= "| `菜单 删除` | 删除所有菜单 |\n";
    $md .= "| `菜单 保存` | 保存当前菜单到数据库 |\n";
    $md .= "| `菜单 加载` | 从数据库恢复菜单 |\n";
    $md .= "| `菜单 帮助` | 显示此帮助 |\n\n";
    $md .= "> 📖 官方文档: https://bot.q.qq.com/wiki/develop/api-v2/openapi/menu/";
    MD($md);
}
?>
