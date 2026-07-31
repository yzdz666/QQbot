<?php
// 插件：回调按钮示例
// 演示如何发送回调按钮和处理按钮点击事件
// 兼容 WS（WebSocket）和 WH（WebHook）两种模式
//
// 参照 ElainaBot_v2 的回调按钮实现:
// - 发送: 使用 原生按钮() 函数，action.type=1 为回调按钮
// - 接收: 消息来源 == "互动" 时为按钮点击事件
// - 匹配: 消息 常量已包含 button_data，可直接用于匹配
//
// 命令:
//   回调菜单   - 显示带回调按钮的菜单
//   回调测试   - 显示测试按钮
//
// 回调按钮 action.type 说明:
//   0 = 跳转链接 (data 为 URL)
//   1 = 回调 (data 为回调标识，点击后通过 button_data 回传)
//   2 = 输入指令 (data 填充到输入框)
//   4 = 订阅


// ==================== 发送回调按钮菜单 ====================
if (消息 == "回调菜单" || 消息 == "回调按钮") {
    $md = "# 🎛️ 回调按钮菜单\n\n"
        . "点击下方按钮体验回调功能\n\n"
        . "| 类型 | 说明 |\n|------|------|\n"
        . "| 回调 | 点击后触发 INTERACTION_CREATE 事件 |\n"
        . "| 指令 | 点击后自动填入输入框 |\n"
        . "| 链接 | 点击后跳转网页 |";

    $rows = [
        [
            "buttons" => [
                [
                    "id" => "cb_info",
                    "render_data" => ["label" => "📋 查看信息", "visited_label" => "✅ 已查看", "style" => 1],
                    "action" => [
                        "type" => 1,           // 回调按钮
                        "data" => "cb_info",   // 回调标识 (点击后通过 button_data 回传)
                        "permission" => ["type" => 2]  // 所有人可点击
                    ]
                ],
                [
                    "id" => "cb_help",
                    "render_data" => ["label" => "❓ 帮助", "visited_label" => "✅ 已查看", "style" => 1],
                    "action" => [
                        "type" => 1,
                        "data" => "cb_help",
                        "permission" => ["type" => 2]
                    ]
                ],
                [
                    "id" => "cb_time",
                    "render_data" => ["label" => "🕐 当前时间", "visited_label" => "✅", "style" => 4],
                    "action" => [
                        "type" => 1,
                        "data" => "cb_time",
                        "permission" => ["type" => 2]
                    ]
                ]
            ]
        ],
        [
            "buttons" => [
                [
                    "id" => "cb_link",
                    "render_data" => ["label" => "🔗 开发文档", "visited_label" => "跳转中...", "style" => 0],
                    "action" => [
                        "type" => 0,                                    // 跳转链接
                        "data" => "https://bot.q.qq.com/wiki/",        // URL
                        "permission" => ["type" => 2]
                    ]
                ],
                [
                    "id" => "cb_cmd",
                    "render_data" => ["label" => "💬 发送指令", "visited_label" => "✅", "style" => 2],
                    "action" => [
                        "type" => 2,              // 输入指令
                        "data" => "回调菜单",     // 填入输入框的内容
                        "enter" => false,         // 不自动发送
                        "permission" => ["type" => 2]
                    ]
                ]
            ]
        ]
    ];

    原生按钮($md, $rows);
    return;
}


// ==================== 发送确认/取消按钮 ====================
if (消息 == "确认测试") {
    $md = "# ⚠️ 确认操作\n\n您确定要执行此操作吗？\n\n请点击下方按钮确认或取消";

    $rows = [
        [
            "buttons" => [
                [
                    "id" => "confirm_btn",
                    "render_data" => ["label" => "✅ 确认", "visited_label" => "已确认", "style" => 1],
                    "action" => [
                        "type" => 1,
                        "data" => "confirm",
                        "permission" => ["type" => 2],
                        "click_limit" => 1  // 只能点击一次
                    ]
                ],
                [
                    "id" => "cancel_btn",
                    "render_data" => ["label" => "❌ 取消", "visited_label" => "已取消", "style" => 0],
                    "action" => [
                        "type" => 1,
                        "data" => "cancel",
                        "permission" => ["type" => 2],
                        "click_limit" => 1
                    ]
                ]
            ]
        ]
    ];

    原生按钮($md, $rows);
    return;
}


// ==================== 处理回调按钮点击 ====================
// 当消息来源为"互动"时，说明是按钮点击事件
// 消息 常量已包含 button_data (即按钮 action.data 的值)
// 参照 ElainaBot_v2: button_data 提取为 event.content
if (消息来源 == "互动") {
    // 获取按钮ID和回调数据
    $buttonId = raw["d"]["data"]["resolved"]["button_id"] ?? "";
    $buttonData = raw["d"]["data"]["resolved"]["button_data"] ?? "";
    // 消息 常量已包含 button_data，可直接用于匹配
    $callbackData = 消息;

    // 调试日志（帮助排查问题）
    wlog('[回调按钮] 点击 - ID: ' . $buttonId . ', Data: ' . $buttonData . ', 消息: ' . $callbackData, appid);

    // 通过 button_data (消息常量) 匹配回调
    switch ($callbackData) {
        case "cb_info":
            // 回复信息（支持群聊和私聊互动）
            文字("📋 机器人信息\n\n"
               . "🤖 AppID: " . appid . "\n"
               . "🌍 环境: " . (defined('type') ? type : '正式') . "\n"
               . "⏰ 当前时间: " . date('Y-m-d H:i:s'));
            break;

        case "cb_help":
            $md = "## ❓ 帮助文档\n\n"
                . "### 可用命令\n"
                . "- `回调菜单` - 显示回调按钮菜单\n"
                . "- `确认测试` - 显示确认/取消按钮\n"
                . "- `回调测试` - 显示多彩测试按钮\n\n"
                . "### 按钮类型\n"
                . "| type | 说明 |\n|------|------|\n"
                . "| 0 | 跳转链接 |\n| 1 | 回调 |\n| 2 | 输入指令 |";
            MD($md);
            break;

        case "cb_time":
            文字("🕐 当前时间\n\n" . date('Y年m月d日 H:i:s') . "\n\n星期" . ['日', '一', '二', '三', '四', '五', '六'][date('w')]);
            break;

        case "confirm":
            文字("✅ 操作已确认！\n\n您点击了确认按钮，操作已执行。");
            break;

        case "cancel":
            文字("❌ 操作已取消。\n\n您点击了取消按钮，操作未执行。");
            break;

        // 多彩测试按钮的回调
        case "cb_blue":
            文字("🔵 您点击了蓝色按钮");
            break;
        case "cb_gray":
            文字("⚫ 您点击了灰色按钮");
            break;
        case "cb_red":
            文字("🔴 您点击了红色按钮");
            break;
        case "cb_md":
            $md = "## 📝 Markdown 回复\n\n"
                . "这是通过回调按钮触发的 Markdown 回复\n\n"
                . "> 回调数据: `" . $buttonData . "`\n"
                . "> 按钮ID: `" . $buttonId . "`";
            MD($md);
            break;

        default:
            // 未匹配的回调数据，不处理（让其他插件处理）
            break;
    }
    return;
}


// ==================== 多彩按钮测试 ====================
if (消息 == "回调测试") {
    $md = "# 🎨 多彩按钮测试\n\n"
        . "点击不同颜色的按钮测试回调\n\n"
        . "| 颜色 | style |\n|------|-------|\n"
        . "| 蓝框蓝字 | 1 |\n"
        . "| 灰框 | 0 |\n"
        . "| 红字 | 2 |\n"
        . "| 蓝底白字 | 4 |";

    $rows = [
        [
            "buttons" => [
                [
                    "id" => "test_blue",
                    "render_data" => ["label" => "蓝色按钮", "visited_label" => "已点击", "style" => 1],
                    "action" => ["type" => 1, "data" => "cb_blue", "permission" => ["type" => 2]]
                ],
                [
                    "id" => "test_gray",
                    "render_data" => ["label" => "灰色按钮", "visited_label" => "已点击", "style" => 0],
                    "action" => ["type" => 1, "data" => "cb_gray", "permission" => ["type" => 2]]
                ]
            ]
        ],
        [
            "buttons" => [
                [
                    "id" => "test_red",
                    "render_data" => ["label" => "红色按钮", "visited_label" => "已点击", "style" => 2],
                    "action" => ["type" => 1, "data" => "cb_red", "permission" => ["type" => 2]]
                ],
                [
                    "id" => "test_md",
                    "render_data" => ["label" => "MD回复", "visited_label" => "已点击", "style" => 4],
                    "action" => ["type" => 1, "data" => "cb_md", "permission" => ["type" => 2]]
                ]
            ]
        ]
    ];

    原生按钮($md, $rows);
    return;
}
