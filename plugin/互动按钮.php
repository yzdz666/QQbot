<?php
// 插件：互动按钮
// 功能：发送带回调按钮的Markdown消息，处理按钮点击事件
//
// 命令：
//   互动菜单  - 显示带按钮的菜单
//   确认操作  - 弹出确认/取消按钮
//
// 按钮点击（消息来源 == "互动"）：
//   框架已将 button_data 提取到「消息」常量，直接匹配即可

// ==================== 互动菜单 ====================
if (消息 == "互动菜单") {
    $md  = "# 🎮 互动按钮菜单\n\n";
    $md .= "点击下方按钮体验回调功能\n\n";
    $md .= "| 按钮 | 颜色 | 类型 |\n";
    $md .= "|------|------|------|\n";
    $md .= "| 点赞 | 蓝 | 回调 |\n";
    $md .= "| 关注 | 灰 | 回调 |\n";
    $md .= "| 举报 | 红 | 回调 |\n";
    $md .= "| 菜单 | 蓝 | 指令 |";

    $rows = [
        [
            "buttons" => [
                [
                    "id" => "btn_like",
                    "render_data" => ["label" => "👍 点赞", "visited_label" => "已点赞", "style" => 1],
                    "action" => ["type" => 1, "data" => "action_like", "permission" => ["type" => 2]]
                ],
                [
                    "id" => "btn_follow",
                    "render_data" => ["label" => "➕ 关注", "visited_label" => "已关注", "style" => 0],
                    "action" => ["type" => 1, "data" => "action_follow", "permission" => ["type" => 2]]
                ],
                [
                    "id" => "btn_report",
                    "render_data" => ["label" => "🚩 举报", "visited_label" => "已举报", "style" => 2],
                    "action" => ["type" => 1, "data" => "action_report", "permission" => ["type" => 2]]
                ]
            ]
        ],
        [
            "buttons" => [
                [
                    "id" => "btn_menu",
                    "render_data" => ["label" => "📋 打开菜单", "visited_label" => "已打开", "style" => 1],
                    "action" => ["type" => 2, "data" => "互动菜单", "enter" => false, "permission" => ["type" => 2]]
                ]
            ]
        ]
    ];

    原生按钮($md, $rows);
    return;
}

// ==================== 确认操作 ====================
if (消息 == "确认操作") {
    $md = "# 🔔 确认操作\n\n您确定要执行此操作吗？\n\n⚠️ 此操作不可撤销";

    $rows = [
        [
            "buttons" => [
                [
                    "id" => "btn_confirm",
                    "render_data" => ["label" => "✅ 确认", "visited_label" => "已确认", "style" => 1],
                    "action" => ["type" => 1, "data" => "action_confirm", "permission" => ["type" => 2]]
                ],
                [
                    "id" => "btn_cancel",
                    "render_data" => ["label" => "❌ 取消", "visited_label" => "已取消", "style" => 0],
                    "action" => ["type" => 1, "data" => "action_cancel", "permission" => ["type" => 2]]
                ]
            ]
        ]
    ];

    原生按钮($md, $rows);
    return;
}

// ==================== 处理按钮回调（互动事件） ====================
// 框架在 index.php / ws_event_handler.php 中已将 button_data 提取到「消息」常量
// button_data 就是按钮 action.data 字段的值（如 "action_like"）
// 同时也读取 button_id 用于更精确的匹配
if (消息来源 == "互动") {
    $buttonId = raw["d"]["data"]["resolved"]["button_id"] ?? "";
    // 消息 已由框架设置为 button_data 值
    $buttonData = 消息;

    wlog('[互动按钮] ID: ' . $buttonId . ', Data: ' . $buttonData, appid);

    switch ($buttonData) {
        case "action_like":
            文字("👍 感谢点赞！");
            break;

        case "action_follow":
            文字("➕ 关注成功！感谢支持～");
            break;

        case "action_report":
            文字("🚩 已收到您的举报，我们会尽快处理");
            break;

        case "action_confirm":
            文字("✅ 操作已确认执行");
            break;

        case "action_cancel":
            文字("❌ 操作已取消");
            break;

        default:
            // 未匹配的按钮，输出调试信息
            if (!empty($buttonData) && $buttonData != "[互动]") {
                文字("📦 收到按钮回调\n\nID: " . $buttonId . "\nData: " . $buttonData);
            }
            break;
    }
    return;
}
?>
