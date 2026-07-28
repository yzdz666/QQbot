<?php
// 插件：发送测试
// 参照点歌.php的写法，支持发送bot.php所有函数
// 每个函数名作为命令前缀，用法和点歌一样简单
// ⚠️ 仅限管理员使用（管理员ID: 1F3EF8A650E371CCAFB0）
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

// 管理员列表
$adminList = ["1F3EF8A650E371CCAFB0"];

// 检查是否是管理员
$isAdmin = false;
if (defined('用户') && in_array(用户, $adminList)) {
    $isAdmin = true;
}

if (!$isAdmin) {
    return; // 非管理员不响应
}

// 解析命令
$parts = explode(" ", trim(消息), 2);
$cmd = $parts[0] ?? "";
$args = $parts[1] ?? "";

// 根据命令调用对应函数
switch ($cmd) {
    case "文字":
        if ($args) 文字($args);
        break;
    case "图片":
        if ($args) 图片($args);
        break;
    case "语音":
        if ($args) 语音($args);
        break;
    case "视频":
        if ($args) 视频($args);
        break;
    case "文件":
        $fileParts = explode(" ", $args, 2);
        $fileUrl = $fileParts[0] ?? "";
        $fileName = $fileParts[1] ?? null;
        if ($fileUrl) 文件($fileUrl, $fileName);
        break;
    case "MD":
        if ($args) MD($args);
        break;
    case "Ark":
        // 格式: Ark 模板ID #KEY1#值1|#KEY2#值2|...
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
        break;
    case "图文卡片":
        // 格式: 图文卡片 标题|描述|图片URL|跳转链接
        if ($args) {
            $tuwenParts = explode("|", $args);
            $title = $tuwenParts[0] ?? "";
            $desc = $tuwenParts[1] ?? "";
            $img = $tuwenParts[2] ?? "";
            $link = $tuwenParts[3] ?? "";
            图文卡片($title, $desc, $img, $link);
        }
        break;
    case "原生按钮":
        // 格式: 原生按钮 Markdown文本
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
        break;
}
