<?php
// 插件：全能测试
// 功能：测试所有消息类型（文字、图片、语音、视频、文件、按钮、文卡、大图、跳转卡、流式、MD、原生按钮、撤回、引用）
// 修复版：使用数据库提取REFIDX，修复引用功能，完善所有功能代码


// ==================== 主菜单 ====================
if (消息 == "全能菜单") {
    $md = "# 🧪 全能测试菜单\n\n> 点击下方按钮或发送对应命令进行测试\n\n---\n\n## 📝 命令列表\n\n| 命令 | 功能 |\n|------|------|\n| 测试文字 | 发送文字消息 |\n| 测试图片 | 发送图片消息 |\n| 测试语音 | 发送语音消息 |\n| 测试视频 | 发送视频消息 |\n| 测试文件 | 发送文件消息 |\n| 测试按钮 | 发送官方按钮 |\n| 测试文卡 | 发送文本卡片 |\n| 测试大图 | 发送大图卡片 |\n| 测试跳转卡 | 发送跳转卡片 |\n| 测试流式 | 流式回复 |\n| 测试MD | Markdown消息 |\n| 测试原生按钮 | 原生自定义按钮 |\n| 测试引用 | 引用最新消息（REFIDX） |\n| 测试撤回 | 撤回最新消息 |\n| #签到 | 每日签到 |\n\n---\n\n✨ 发送对应命令即可测试";
    
    $rows = [
        [
            "buttons" => [
                ["id" => "btn_text", "render_data" => ["label" => "文字", "visited_label" => "文字", "style" => 1], "action" => ["type" => 2, "data" => "测试文字", "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "btn_image", "render_data" => ["label" => "图片", "visited_label" => "图片", "style" => 1], "action" => ["type" => 2, "data" => "测试图片", "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "btn_voice", "render_data" => ["label" => "语音", "visited_label" => "语音", "style" => 1], "action" => ["type" => 2, "data" => "测试语音", "enter" => false, "permission" => ["type" => 2]]]
            ]
        ],
        [
            "buttons" => [
                ["id" => "btn_video", "render_data" => ["label" => "视频", "visited_label" => "视频", "style" => 0], "action" => ["type" => 2, "data" => "测试视频", "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "btn_file", "render_data" => ["label" => "文件", "visited_label" => "文件", "style" => 0], "action" => ["type" => 2, "data" => "测试文件", "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "btn_wenka", "render_data" => ["label" => "文卡", "visited_label" => "文卡", "style" => 0], "action" => ["type" => 2, "data" => "测试文卡", "enter" => false, "permission" => ["type" => 2]]]
            ]
        ],
        [
            "buttons" => [
                ["id" => "btn_datu", "render_data" => ["label" => "大图", "visited_label" => "大图", "style" => 0], "action" => ["type" => 2, "data" => "测试大图", "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "btn_tiaozhuan", "render_data" => ["label" => "跳转卡", "visited_label" => "跳转卡", "style" => 0], "action" => ["type" => 2, "data" => "测试跳转卡", "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "btn_liushi", "render_data" => ["label" => "流式", "visited_label" => "流式", "style" => 0], "action" => ["type" => 2, "data" => "测试流式", "enter" => false, "permission" => ["type" => 2]]]
            ]
        ],
        [
            "buttons" => [
                ["id" => "btn_md", "render_data" => ["label" => "MD", "visited_label" => "MD", "style" => 0], "action" => ["type" => 2, "data" => "测试MD", "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "btn_yuansheng", "render_data" => ["label" => "原生按钮", "visited_label" => "原生按钮", "style" => 0], "action" => ["type" => 2, "data" => "测试原生按钮", "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "btn_quote", "render_data" => ["label" => "引用", "visited_label" => "引用", "style" => 0], "action" => ["type" => 2, "data" => "测试引用", "enter" => false, "permission" => ["type" => 2]]]
            ]
        ],
        [
            "buttons" => [
                ["id" => "btn_recall", "render_data" => ["label" => "撤回", "visited_label" => "撤回", "style" => 2], "action" => ["type" => 2, "data" => "测试撤回", "enter" => false, "permission" => ["type" => 2]]],
                [
                    "id" => "btn_1780912607822_z61nx9",
                    "render_data" => [
                        "label" => "签到",
                        "visited_label" => "已签到",
                        "style" => 1
                    ],
                    "action" => [
                        "type" => 2,
                        "permission" => ["type" => 2],
                        "data" => "#签到",
                        "unsupport_tips" => "请升级QQ版本",
                        "enter" => true
                    ]
                ]
            ]
        ]
    ];
    
    原生按钮($md, $rows);
    return;
}

// ==================== 签到功能 ====================
if (消息 == "#签到") {
    $today = date("Y-m-d");
    $signKey = "sign_" . 用户 . "_" . $today;
    $hasSigned = 读("签到/" . appid, $signKey, false);
    
    if ($hasSigned) {
        文字("❌ 今天已经签到过了！\n\n📅 签到日期: " . $today);
        return;
    }
    
    写("签到/" . appid, $signKey, true);
    
    // 随机签到积分
    $points = mt_rand(1, 100);
    $totalKey = "sign_total_" . 用户;
    $totalPoints = 读("签到/" . appid, $totalKey, 0);
    $totalPoints += $points;
    写("签到/" . appid, $totalKey, $totalPoints);
    
    文字("✅ 签到成功！\n\n📅 签到日期: " . $today . "\n🎁 获得积分: " . $points . "\n📊 累计积分: " . $totalPoints . "\n🌟 感谢您的支持！");
    return;
}

// ==================== 文字测试 ====================
if (消息 == "测试文字") {
    文字("📝 这是一条文字消息\n\n发送时间: " . date("Y-m-d H:i:s") . "\n消息来源: " . 消息来源);
    return;
}

// ==================== 图片测试 ====================
if (消息 == "测试图片") {
    图片("https://picsum.photos/400/300", "📸 示例图片");
    return;
}

// ==================== 语音测试 ====================
if (消息 == "测试语音") {
    语音("https://jcy.meiaodai.xyz/api/api/baoshi/13.mp3");
    return;
}

// ==================== 视频测试 ====================
if (消息 == "测试视频") {
    视频("https://www.w3schools.com/html/mov_bbb.mp4");
    return;
}

// ==================== 文件测试 ====================
if (消息 == "测试文件") {
    文件("https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf", "测试文档.pdf");
    return;
}

// ==================== 按钮测试 ====================
if (消息 == "测试按钮") {
    文字("⚠️ 按钮功能需要先在QQ开放平台申请 keyboard_id\n\n示例：按钮(keyboard_id)\n\n请在QQ开放平台 -> 开发 ->机器人 -> 功能配置 -> 自定义按键 中申请");
    return;
}

// ==================== 文卡测试 ====================
if (消息 == "测试文卡") {
    文卡(
        ["text" => "🎉 欢迎使用全能测试插件", "url" => "https://github.com"],
        ["text" => "支持所有消息类型"],
        ["text" => "点击链接查看更多"]
    );
    return;
}

// ==================== 大图测试 ====================
if (消息 == "测试大图") {
    大图("全能测试插件", "支持文字/图片/语音/视频等多种消息类型", "https://picsum.photos/800/400");
    return;
}

// ==================== 跳转卡测试 ====================
if (消息 == "测试跳转卡") {
    跳转卡("点击跳转", "QQ机器人官方文档", "https://picsum.photos/400/200", "https://bot.q.qq.com/wiki/");
    return;
}

// ==================== 流式测试 ====================
if (消息 == "测试流式") {
    流式("第1段：正在处理...", "第2段：处理完成！", "第3段：流式回复结束");
    return;
}

// ==================== MD测试 ====================
if (消息 == "测试MD") {
    $md = "# 📝 Markdown 测试\n\n## 支持格式\n\n- **粗体文本**\n- *斜体文本*\n- [链接](https://www.qq.com)\n\n## 代码块\n```php\necho \"Hello World!\";\n```\n\n---\n\n> 引用文本\n\n✅ 测试成功";
    MD($md);
    return;
}

// ==================== 弹窗测试 ====================
if (消息 == "测试弹窗") {
    $md = "# 🔔 确认操作\n\n您确定要执行此操作吗？";
    $rows = [
        [
            "buttons" => [
                [
                    "id" => "confirm_btn",
                    "render_data" => ["label" => "✅ 确认", "visited_label" => "已确认", "style" => 1],
                    "action" => ["type" => 1, "data" => "confirm", "permission" => ["type" => 2]]
                ],
                [
                    "id" => "cancel_btn",
                    "render_data" => ["label" => "❌ 取消", "visited_label" => "已取消", "style" => 0],
                    "action" => ["type" => 1, "data" => "cancel", "permission" => ["type" => 2]]
                ]
            ]
        ]
    ];
    原生按钮($md, $rows);
    return;
}

// ==================== 原生按钮测试 ====================
if (消息 == "测试原生按钮") {
    $md = "# 🎮 原生按钮测试\n\n点击下方按钮测试回调事件\n\n| 按钮 | 说明 |\n|------|------|\n| 按钮1 | 蓝色-回调 |\n| 按钮2 | 灰色-回调 |\n| 按钮3 | 红色-回调 |";
    $rows = [
        [
            "buttons" => [
                [
                    "id" => "nb_1",
                    "render_data" => ["label" => "按钮1", "visited_label" => "已点击", "style" => 1],
                    "action" => [
                        "type" => 1,
                        "data" => "按钮1数据",
                        "permission" => ["type" => 2],
                        "click_limit" => 1
                    ]
                ],
                [
                    "id" => "nb_2",
                    "render_data" => ["label" => "按钮2", "visited_label" => "已点击", "style" => 0],
                    "action" => [
                        "type" => 1,
                        "data" => "按钮2数据",
                        "permission" => ["type" => 2],
                        "click_limit" => 1
                    ]
                ],
                [
                    "id" => "nb_3",
                    "render_data" => ["label" => "按钮3", "visited_label" => "已点击", "style" => 2],
                    "action" => [
                        "type" => 1,
                        "data" => "按钮3数据",
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


// ==================== 辅助函数：从数据库提取REFIDX ====================
function 从数据库提取REFIDX($targetId, $sourceType, $userId) {
    try {
        // 查询最近的用户消息（排除当前消息）
        $messages = db()->fetchAll(
            "SELECT raw_data FROM messages 
             WHERE appid = ? AND target_id = ? AND source_type = ? AND direction = '接收'
             ORDER BY id DESC LIMIT 10",
            [appid, $targetId, $sourceType]
        );
        
        foreach ($messages as $msg) {
            if (empty($msg['raw_data'])) continue;
            $rawData = json_decode($msg['raw_data'], true);
            if (!$rawData) continue;
            
            $d = $rawData['d'] ?? $rawData;
            
            // 跳过机器人消息
            $isBot = $d['author']['bot'] ?? false;
            if ($isBot) continue;
            
            // 匹配用户ID
            $msgUserId = $d['author']['id'] ?? $d['openid'] ?? '';
            if ($userId && $msgUserId != $userId) continue;
            
            // 跳过当前消息（通过消息ID判断）
            $msgId = $d['id'] ?? '';
            if (defined('消息ID') && $msgId === 消息ID) continue;
            
            // 提取REFIDX
            if (isset($d['message_scene']['ext']) && is_array($d['message_scene']['ext'])) {
                foreach ($d['message_scene']['ext'] as $extStr) {
                    if (preg_match('/msg_idx=([^&]+)/', $extStr, $m)) {
                        return $m[1];
                    }
                }
            }
        }
    } catch (Exception $e) {
        wlog('[引用调试] 数据库查询失败: ' . $e->getMessage(), appid);
    }
    
    return '';
}

// ==================== 辅助函数：从当前消息提取REFIDX ====================
function 从当前消息提取REFIDX() {
    $d = raw["d"] ?? [];
    
    if (isset($d['message_scene']['ext']) && is_array($d['message_scene']['ext'])) {
        foreach ($d['message_scene']['ext'] as $extStr) {
            if (preg_match('/msg_idx=([^&]+)/', $extStr, $m)) {
                return $m[1];
            }
        }
    }
    
    return '';
}

// ==================== 引用测试（从数据库提取 REFIDX） ====================
if (消息 == "测试引用") {
    // 1. 尝试从数据库提取上一条消息的REFIDX
    $msgIdx = 从数据库提取REFIDX(来源, 消息来源, 用户);
    
    // 2. 如果数据库没有找到，尝试从当前消息提取
    if (empty($msgIdx)) {
        $msgIdx = 从当前消息提取REFIDX();
    }
    
    if (empty($msgIdx)) {
        文字("❌ 未找到可引用的消息\n\n请先发送一条普通消息，然后再发送「测试引用」\n\n💡 提示：引用功能需要消息场景中包含 msg_idx 信息");
        return;
    }
    
    wlog('[引用调试] 提取到REFIDX: ' . $msgIdx, appid);
    
    // 3. 发送引用消息
    $quoteResp = 引用($msgIdx, "📎 引用测试成功！\n\n⏰ 时间: " . date("Y-m-d H:i:s"));
    
    // 4. 解析响应
    $respData = json_decode($quoteResp, true);
    if ($respData && isset($respData['id'])) {
        文字("✅ 引用消息发送成功！\n\n使用的 REFIDX: " . substr($msgIdx, 0, 30) . "...");
    } else {
        $errMsg = $respData['message'] ?? $respData['msg'] ?? '未知错误';
        $errCode = $respData['code'] ?? '无';
        文字("⚠️ 引用消息发送结果\n\n错误代码: " . $errCode . "\n错误信息: " . $errMsg . "\n\nREFIDX: " . $msgIdx);
    }
    return;
}

// ==================== 手动引用指定REFIDX ====================
if (strpos(消息, "引用 ") === 0 || strpos(消息, "引用REFIDX ") === 0) {
    $refIdx = trim(str_replace(["引用 ", "引用REFIDX "], "", 消息));
    
    if (empty($refIdx)) {
        文字("❌ 请提供要引用的REFIDX\n\n格式：引用 REFIDX_xxxx");
        return;
    }
    
    $quoteResp = 引用($refIdx, "📎 手动引用测试");
    $respData = json_decode($quoteResp, true);
    
    if ($respData && isset($respData['id'])) {
        文字("✅ 引用成功！\n\nREFIDX: " . $refIdx);
    } else {
        $errMsg = $respData['message'] ?? $respData['msg'] ?? '未知错误';
        文字("❌ 引用失败\n\n错误: " . $errMsg . "\nREFIDX: " . $refIdx);
    }
    return;
}

// ==================== 撤回测试 ====================
if (消息 == "测试撤回") {
    // 1. 先发送一条消息
    $resp = 文字("⚠️ 这条消息会在3秒后自动撤回\n\n📝 测试时间: " . date("Y-m-d H:i:s"));
    $data = json_decode($resp, true);
    $msgId = $data['id'] ?? '';
    
    if ($msgId) {
        // 2. 延迟0.5秒（⚠️ sleep会阻塞进程，单进程QQ机器人模型下所有消息处理均会卡住）
        usleep(500000);
        
        // 3. 执行撤回
        $recallResp = 撤回($msgId);
        
        // 4. 判断撤回结果
        $recallData = json_decode($recallResp, true);
        if (
            (is_array($recallData) && isset($recallData['code']) && $recallData['code'] == 0) ||
            empty($recallResp) ||
            $recallResp === '""' ||
            strpos($recallResp, '"code":0') !== false
        ) {
            文字("✅ 撤回成功！\n\n📝 消息ID: `" . $msgId . "`");
        } else {
            $errMsg = $recallData['message'] ?? $recallData['msg'] ?? $recallResp;
            文字("❌ 撤回失败\n📝 错误信息: " . $errMsg);
        }
    } else {
        文字("❌ 发送失败，无法执行撤回测试\n\n响应: " . $resp);
    }
    return;
}

// ==================== 处理原生按钮点击（互动事件） ====================
if (消息来源 == "互动") {
    $buttonId = raw["d"]["data"]["resolved"]["button_id"] ?? "";
    $buttonData = raw["d"]["data"]["resolved"]["data"] ?? "";
    
    // 调试日志
    wlog('[调试] 按钮点击 - ID: ' . $buttonId . ', Data: ' . $buttonData, appid);
    
    // 根据按钮ID或按钮data响应
    $responseData = $buttonData ?: $buttonId;
    
    switch ($buttonId) {
        case "nb_1":
            文字("✅ 操作成功！您点击了按钮1\n\n📦 回调数据: " . $buttonData);
            break;
        case "nb_2":
            文字("✅ 操作成功！您点击了按钮2\n\n📦 回调数据: " . $buttonData);
            break;
        case "nb_3":
            文字("✅ 操作成功！您点击了按钮3\n\n📦 回调数据: " . $buttonData);
            break;
        case "confirm_btn":
            文字("✅ 已确认操作");
            break;
        case "cancel_btn":
            文字("❌ 已取消操作");
            break;
        default:
            // 如果没有匹配的按钮ID，尝试根据data响应
            if (!empty($buttonData) && $buttonData !== 'confirm' && $buttonData !== 'cancel') {
                // 直接发送按钮data作为消息触发对应功能
                // 这里不做处理，让其他插件处理
            }
            break;
    }
    return;
}

// ==================== 查看积分 ====================
if (消息 == "我的积分" || 消息 == "积分查询") {
    $totalKey = "sign_total_" . 用户;
    $totalPoints = 读("签到/" . appid, $totalKey, 0);
    文字("📊 我的积分\n\n🌟 累计积分: " . $totalPoints . "\n\n💡 每日发送 #签到 获取更多积分");
    return;
}

// ==================== 帮助信息 ====================
if (消息 == "全能帮助" || 消息 == "测试帮助") {
    $help = "# 🧪 全能测试插件帮助\n\n## 📝 可用命令\n\n| 命令 | 说明 |\n|------|------|\n| 全能菜单 | 显示功能菜单 |\n| 测试文字 | 发送文字消息 |\n| 测试图片 | 发送图片消息 |\n| 测试语音 | 发送语音消息 |\n| 测试视频 | 发送视频消息 |\n| 测试文件 | 发送文件消息 |\n| 测试按钮 | 发送官方按钮 |\n| 测试文卡 | 发送文本卡片 |\n| 测试大图 | 发送大图卡片 |\n| 测试跳转卡 | 发送跳转卡片 |\n| 测试流式 | 流式回复 |\n| 测试MD | Markdown消息 |\n| 测试原生按钮 | 原生自定义按钮 |\n| 测试弹窗 | 弹窗确认测试 |\n| 测试引用 | 引用最新消息（REFIDX） |\n| 引用 REFIDX | 手动引用指定索引 |\n| 测试撤回 | 撤回最新消息 |\n| #签到 | 每日签到 |\n| 我的积分 | 查看签到积分 |\n\n## 🔧 引用功能说明\n\n**测试引用** 会自动从数据库中查找最近的消息并引用。\n\n引用功能使用 **REFIDX_xxxx** 格式（msg_idx），而非消息ID。\n\n也可手动指定：发送「引用 REFIDX_xxxx」\n\n---\n\n✨ 发送「全能菜单」获取可视化按钮菜单";
    文字($help);
    return;
}
