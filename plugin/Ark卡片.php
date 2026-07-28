<?php
// 插件：Ark卡片示例
// 功能：参照Python示例插件，支持发送 ark23列表卡片、ark24文本+图片卡片、ark37大图卡片、图文卡片(tuwen)
// 对应Python示例: reply_ark(23/24/37) + reply_tuwen
// 注意：图文卡片() 函数已移入 bot.php 核心函数库


// ==================== 主菜单 ====================
if (消息 == "ark菜单") {
    $md = "# 📋 Ark卡片示例菜单\n\n> 参照Python示例插件，支持以下卡片类型\n\n---\n\n## 📝 命令列表\n\n| 命令 | 功能 | 模板 |\n|------|------|------|\n| ark23 | 列表卡片 | template 23 |\n| ark24 | 文本+图片卡片 | template 24 |\n| ark37 | 大图卡片 | template 37 |\n| 图文卡片 | tuwen图文卡片 | msg_type 8 |\n\n---\n\n✨ 发送对应命令即可测试";

    $rows = [
        [
            "buttons" => [
                ["id" => "btn_ark23", "render_data" => ["label" => "ark23", "visited_label" => "ark23", "style" => 1], "action" => ["type" => 2, "data" => "ark23", "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "btn_ark24", "render_data" => ["label" => "ark24", "visited_label" => "ark24", "style" => 1], "action" => ["type" => 2, "data" => "ark24", "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "btn_ark37", "render_data" => ["label" => "ark37", "visited_label" => "ark37", "style" => 1], "action" => ["type" => 2, "data" => "ark37", "enter" => false, "permission" => ["type" => 2]]]
            ]
        ],
        [
            "buttons" => [
                ["id" => "btn_tuwen", "render_data" => ["label" => "图文卡片", "visited_label" => "图文卡片", "style" => 0], "action" => ["type" => 2, "data" => "图文卡片", "enter" => false, "permission" => ["type" => 2]]]
            ]
        ]
    ];

    原生按钮($md, $rows);
    return;
}


// ==================== ark23: 列表卡片 (template_id=23) ====================
// 对应Python: reply_ark(23, ("列表卡片示例", "ElainaBot", [['功能1: 图片'], ['功能2: 语音'], ['功能3: 视频', 'https://...']]))
if (消息 == "ark23") {
    文卡(
        ["text" => "列表卡片示例"],
        ["text" => "ElainaBot"],
        ["text" => "功能1: 图片"],
        ["text" => "功能2: 语音"],
        ["text" => "功能3: 视频", "url" => "https://i.elaina.vin/api/"]
    );
    return;
}


// ==================== ark24: 文本+图片卡片 (template_id=24) ====================
// 对应Python: reply_ark(24, ("功能强大的QQ机器人", "机器人信息", "ElainaBot", "支持插件化开发", "https://...", "https://...", "QQ Bot"))
// kv映射: #DESC#, #PROMPT#, #TITLE#, #METADESC#, #IMG#, #LINK#, #SUBTITLE#
if (消息 == "ark24") {
    Ark(24, [
        ["key" => "#DESC#", "value" => "功能强大的QQ机器人"],
        ["key" => "#PROMPT#", "value" => "机器人信息"],
        ["key" => "#TITLE#", "value" => "ElainaBot"],
        ["key" => "#METADESC#", "value" => "支持插件化开发"],
        ["key" => "#IMG#", "value" => "https://gchat.qpic.cn/qmeetpic/0/0-0-52C851D5FB926BC645528EB4AB462B3D/0"],
        ["key" => "#LINK#", "value" => "https://i.elaina.vin/api/"],
        ["key" => "#SUBTITLE#", "value" => "QQ Bot"]
    ]);
    return;
}


// ==================== ark37: 大图卡片 (template_id=37) ====================
// 对应Python: reply_ark(37, ("系统通知", "状态更新", "新功能上线", "https://...", "https://..."))
// kv映射: #PROMPT#, #METATITLE#, #METASUBTITLE#, #METACOVER#, #METAURL#
if (消息 == "ark37") {
    Ark(37, [
        ["key" => "#PROMPT#", "value" => "系统通知"],
        ["key" => "#METATITLE#", "value" => "状态更新"],
        ["key" => "#METASUBTITLE#", "value" => "新功能上线"],
        ["key" => "#METACOVER#", "value" => "https://gchat.qpic.cn/qmeetpic/0/0-0-52C851D5FB926BC645528EB4AB462B3D/0"],
        ["key" => "#METAURL#", "value" => "https://i.elaina.vin/api/"]
    ]);
    return;
}


// ==================== 图文卡片: tuwen (msg_type=8) ====================
// 对应Python: reply_tuwen(("QQ开放平台", "2分钟完成注册并创建QQBot", "https://...", "https://q.qq.com/#/"))
if (消息 == "图文卡片") {
    图文卡片(
        "QQ开放平台",
        "2分钟完成注册并创建QQBot",
        "https://gchat.qpic.cn/qmeetpic/0/0-0-52C851D5FB926BC645528EB4AB462B3D/0",
        "https://q.qq.com/#/"
    );
    return;
}