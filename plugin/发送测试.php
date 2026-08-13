<?php
// 插件：发送测试
// 参照点歌.php的写法，支持发送bot.php所有函数
// 每个函数名作为命令前缀，用法和点歌一样简单
// ⚠️ 仅限管理员使用，权限基于 bots 表 owner_ids 字段（见 bot.php 是否管理员()）
//
// 命令格式：函数名 参数
// 例如：
//   文字 你好              → 文字("你好")
//   图片 https://xxx.png   → 图片("https://xxx.png")
//   语音 https://xxx.mp3   → 语音("https://xxx.mp3")
//   视频 https://xxx.mp4   → 视频("https://xxx.mp4")
//   文件 https://xxx.zip   → 文件("https://xxx.zip","文件名.zip")
//   MD # 标题              → MD("# 标题")
//   引用 消息ID 内容        → 引用("消息ID","内容")
//   Ark 37 标题|描述|图片|链接 → Ark(37, [...])
//   图文卡片 标题|描述|图片|链接 → 图文卡片("标题","描述","图片","链接")
//   原生按钮 Markdown文本   → 原生按钮($md, $rows)

// ==================== 管理员权限校验 ====================
if (!是否管理员()) {
    return;
}

// ==================== 解析命令 ====================
$parts = explode(" ", trim(消息), 2);
$cmd = $parts[0] ?? "";
$args = $parts[1] ?? "";

// ==================== 文字 ====================
if ($cmd == "文字") {
    if ($args) 文字($args);
    return;
}

// ==================== 图片 ====================
if ($cmd == "图片") {
    if ($args) 图片($args);
    return;
}

// ==================== 语音 ====================
if ($cmd == "语音") {
    if ($args) 语音($args);
    return;
}

// ==================== 视频 ====================
if ($cmd == "视频") {
    if ($args) 视频($args);
    return;
}

// ==================== 文件 ====================
if ($cmd == "文件") {
    $fileParts = explode(" ", $args, 2);
    $fileUrl = $fileParts[0] ?? "";
    $fileName = $fileParts[1] ?? null;
    if ($fileUrl) 文件($fileUrl, $fileName);
    return;
}

// ==================== Markdown ====================
if ($cmd == "MD") {
    if ($args) MD($args);
    return;
}

// ==================== Ark卡片 ====================
// 格式: Ark 模板ID #KEY1#值1|#KEY2#值2|...
if ($cmd == "Ark") {
    $arkParts = explode(" ", $args, 2);
    $templateId = $arkParts[0] ?? "";
    $kvStr = $arkParts[1] ?? "";
    if ($templateId) {
        $kv = [];
        if ($kvStr) {
            $pairs = explode("|", $kvStr);
            foreach ($pairs as $pair) {
                $kvItem = explode("#", trim($pair), 2);
                if (count($kvItem) === 2) {
                    $kv[] = ["key" => "#" . $kvItem[0] . "#", "value" => $kvItem[1]];
                }
            }
        }
        if ($kv) Ark($templateId, $kv);
    }
    return;
}

// ==================== 图文卡片 ====================
// 格式: 图文卡片 标题|描述|图片URL|跳转链接
if ($cmd == "图文卡片") {
    if ($args) {
        $tuwenParts = explode("|", $args);
        $title = $tuwenParts[0] ?? "";
        $desc = $tuwenParts[1] ?? "";
        $img = $tuwenParts[2] ?? "";
        $link = $tuwenParts[3] ?? "";
        图文卡片($title, $desc, $img, $link);
    }
    return;
}

// ==================== 原生按钮 ====================
// 格式: 原生按钮 Markdown文本
if ($cmd == "原生按钮") {
    if ($args) {
        $rows = [
            [
                "buttons" => [
                    ["id" => "btn1", "render_data" => ["label" => "按钮1", "visited_label" => "按钮1", "style" => 1], "action" => ["type" => 2, "data" => "测试", "enter" => false, "permission" => ["type" => 2]]]
                ]
            ]
        ];
        原生按钮($args, $rows);
    }
    return;
}
?>
