<?php
/**
 * ws全能测试.php - WebSocket 模式测试插件
 *
 * 配套文档：ws模式测试文档.md（项目根目录 文档.md）
 * 接入机器人：AppID 102625949
 *
 * 触发方式：
 *   - 群聊 @ 机器人发送 "ws菜单" 弹出原生按钮菜单
 *   - 私聊发送 "ws菜单" 同样弹出菜单
 *   - 直接发送 "ws xxx" 或 "xxx" 命令触发对应函数
 *   - 点击按钮（互动事件）触发对应功能
 *
 * 覆盖测试文档中列出的全部函数：
 *   文字 / 图片 / 语音 / 本地语音 / 视频 / 文件 / 按钮 / 文卡 / 大图 / 跳转卡
 *   流式 / 撤回 / 引用 / MD / 原生按钮
 *   头像 / BOT信息 / 读 / 写 / curl / 二维码 / 域名大写
 *   markdown转html / 邮箱 / HTML转图 / 画布
 *
 * 兼容 WS 模式（ws.php）与 Webhook 模式（index.php），统一通过常量 消息 / 消息来源 / 用户 / 来源 / 事件ID 处理。
 */

// ============== 菜单入口 ==============
if (消息 == "ws菜单" || 消息 == "全能菜单") {
    $md = "# \u{1F9EA} WS 模式全函数测试菜单\n\n"
        . "AppID `102625949` \u{2705} 已接入\n"
        . "WS 模式 \u{1F50C} 后台保活中\n\n"
        . "点击下方按钮即可触发对应函数，或直接发送 `ws xxx` 命令。";
    $rows = [
        [
            "buttons" => [
                ["id" => "ws_btn_text",      "render_data" => ["label" => "文字",       "style" => 1], "action" => ["type" => 2, "data" => "ws 文字",       "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_image",     "render_data" => ["label" => "图片",       "style" => 1], "action" => ["type" => 2, "data" => "ws 图片",       "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_voice",     "render_data" => ["label" => "语音",       "style" => 1], "action" => ["type" => 2, "data" => "ws 语音",       "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_video",     "render_data" => ["label" => "视频",       "style" => 1], "action" => ["type" => 2, "data" => "ws 视频",       "enter" => false, "permission" => ["type" => 2]]],
            ]
        ],
        [
            "buttons" => [
                ["id" => "ws_btn_file",      "render_data" => ["label" => "文件",       "style" => 1], "action" => ["type" => 2, "data" => "ws 文件",       "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_button",    "render_data" => ["label" => "官方按钮",    "style" => 1], "action" => ["type" => 2, "data" => "ws 按钮",       "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_wencard",   "render_data" => ["label" => "文卡",       "style" => 1], "action" => ["type" => 2, "data" => "ws 文卡",       "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_bigimg",    "render_data" => ["label" => "大图卡",      "style" => 1], "action" => ["type" => 2, "data" => "ws 大图",       "enter" => false, "permission" => ["type" => 2]]],
            ]
        ],
        [
            "buttons" => [
                ["id" => "ws_btn_jump",      "render_data" => ["label" => "跳转卡",      "style" => 1], "action" => ["type" => 2, "data" => "ws 跳转卡",     "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_stream",    "render_data" => ["label" => "流式回复",    "style" => 1], "action" => ["type" => 2, "data" => "ws 流式",       "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_recall",    "render_data" => ["label" => "撤回",        "style" => 2], "action" => ["type" => 2, "data" => "ws 撤回",       "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_quote",     "render_data" => ["label" => "引用",        "style" => 1], "action" => ["type" => 2, "data" => "ws 引用",       "enter" => false, "permission" => ["type" => 2]]],
            ]
        ],
        [
            "buttons" => [
                ["id" => "ws_btn_md",        "render_data" => ["label" => "MD 基础",    "style" => 1], "action" => ["type" => 2, "data" => "ws md基础",     "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_md_btn",    "render_data" => ["label" => "MD 按钮",    "style" => 1], "action" => ["type" => 2, "data" => "ws md按钮",     "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_md_style",  "render_data" => ["label" => "MD 样式",    "style" => 1], "action" => ["type" => 2, "data" => "ws md样式",     "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_native",    "render_data" => ["label" => "原生按钮",    "style" => 1], "action" => ["type" => 2, "data" => "ws 原生按钮",   "enter" => false, "permission" => ["type" => 2]]],
            ]
        ],
        [
            "buttons" => [
                ["id" => "ws_btn_avatar",    "render_data" => ["label" => "头像",        "style" => 1], "action" => ["type" => 2, "data" => "ws 头像",       "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_botinfo",   "render_data" => ["label" => "BOT信息",     "style" => 1], "action" => ["type" => 2, "data" => "ws BOT信息",    "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_read",      "render_data" => ["label" => "读数据",      "style" => 1], "action" => ["type" => 2, "data" => "ws 读数据",     "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_write",     "render_data" => ["label" => "写数据",      "style" => 1], "action" => ["type" => 2, "data" => "ws 写数据",     "enter" => false, "permission" => ["type" => 2]]],
            ]
        ],
        [
            "buttons" => [
                ["id" => "ws_btn_curl",      "render_data" => ["label" => "curl 请求",  "style" => 1], "action" => ["type" => 2, "data" => "ws curl",       "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_qrcode",    "render_data" => ["label" => "二维码",      "style" => 1], "action" => ["type" => 2, "data" => "ws 二维码",     "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_html2img",  "render_data" => ["label" => "HTML转图",    "style" => 1], "action" => ["type" => 2, "data" => "ws HTML转图",   "enter" => false, "permission" => ["type" => 2]]],
                ["id" => "ws_btn_canvas",    "render_data" => ["label" => "画布",        "style" => 1], "action" => ["type" => 2, "data" => "ws 画布",       "enter" => false, "permission" => ["type" => 2]]],
            ]
        ]
    ];
    原生按钮($md, $rows);
}

// ============== 互动事件统一处理（按钮点击） ==============
if (消息来源 == "互动") {
    $buttonId   = raw["d"]["data"]["resolved"]["button_id"] ?? raw["d"]["data"]["button_id"] ?? raw["d"]["button_id"] ?? "";
    $buttonData = raw["d"]["data"]["resolved"]["button_data"] ?? raw["d"]["data"]["button_data"] ?? raw["d"]["data"] ?? "";
    // 按钮点击若带了 data 文字，相当于触发对应文字命令
    if (is_string($buttonData) && strpos($buttonData, "ws ") === 0) {
        ws_route_text($buttonData);
    } elseif (is_string($buttonData) && $buttonData) {
        文字("\u{1F442} 收到互动事件 data=" . $buttonData);
    } else {
        文字("\u{1F442} 收到互动事件 button_id=" . $buttonId);
    }
}

// ============== 文字命令路由 ==============
ws_route_text(消息);

/**
 * 文字命令路由
 * - 接受 "ws xxx" 或 "xxx" 两种格式
 * - 互动事件中 消息 常量为 "[互动]"，故互动入口需主动传入 button_data
 */
function ws_route_text(string $rawCmd): void
{
    $cmd = trim($rawCmd);
    // 兼容 "ws xxx" 前缀
    if (strpos($cmd, "ws ") === 0) {
        $cmd = substr($cmd, 3);
    }
    $cmd = trim($cmd);

    switch ($cmd) {
        case "文字":
        case "text":
            文字("\u{1F44D} 文字函数 OK\n来自 AppID 102625949 / WS 模式");
            break;

        case "图片":
        case "image":
            图片("https://www.gstatic.com/webp/gallery/1.jpg", "\u{1F4F7} 测试图片 - 来自 gstatic");
            break;

        case "语音":
        case "voice":
            语音("https://www.w3schools.com/html/horse.mp3");
            break;

        case "本地语音":
        case "silk":
            本地语音("https://oiapi.net/demo/sample.silk");
            break;

        case "视频":
        case "video":
            视频("https://www.w3schools.com/html/mov_bbb.mp4");
            break;

        case "文件":
        case "file":
            文件("https://www.w3.org/TR/PNG/iso_8859-1.txt", "ws测试文件.txt");
            break;

        case "按钮":
        case "button":
            // 官方模板按钮（需要在管理后台预先申请 keyboard_id）
            按钮("ws_test_keyboard_001");
            break;

        case "文卡":
        case "wencard":
            文卡(
                ["text" => "WS 模式文卡测试"],
                ["text" => "查看文档", "url" => "https://bot.q.qq.com/wiki"]
            );
            break;

        case "大图":
        case "bigimg":
            大图("WS 模式大图卡片", "测试副标题 - 102625949", "https://www.gstatic.com/webp/gallery/2.jpg");
            break;

        case "跳转卡":
        case "jump":
            跳转卡("WS 测试跳转标题", "跳转到 QQ 机器人文档", "https://www.gstatic.com/webp/gallery/3.jpg", "https://bot.q.qq.com/wiki");
            break;

        case "流式":
        case "stream":
            流式("正在查询...", "查询完成", "\u{2705} WS 流式回复测试结束 - 102625949");
            break;

        case "撤回":
        case "recall":
            $resp = 文字("这条消息会撤回测试 \u{23F3}");
            $data = json_decode($resp, true);
            $msgId = $data['id'] ?? '';
            if ($msgId) {
                // WS 模式下避免 sleep 阻塞事件循环，直接调用撤回
                撤回($msgId);
                文字("\u{2705} 撤回函数已调用\n原消息ID: {$msgId}");
            }
            break;

        case "引用":
        case "quote":
            $msgIdx = defined('事件ID') ? 事件ID : (defined('消息ID') ? 消息ID : "");
            引用($msgIdx, "\u{1F4AC} 引用消息测试 - 来自 WS 模式");
            break;

        case "md基础":
        case "md":
            MD("# WS Markdown 测试\n## 子标题\n- 列表项 1\n- 列表项 2\n[链接](https://bot.q.qq.com/wiki)\n```\ncode block\n```");
            break;

        case "md按钮":
        case "md_btn":
            MD("# MD + 按钮\n点击下方按钮测试", "ws_test_keyboard_001");
            break;

        case "md样式":
        case "md_style":
            MD(
                "# 系统通知\nWS 模式 Markdown 样式测试\n居中并隐藏头像",
                null,
                ["layout" => "hide_avatar_and_center", "main_font_size" => "small"]
            );
            break;

        case "md完整":
        case "md_full":
            MD(
                "# 确认操作\nWS 模式完整 MD 测试",
                "ws_test_keyboard_001",
                ["layout" => "hide_avatar_and_center", "main_font_size" => "normal"]
            );
            break;

        case "原生按钮":
        case "native":
            $md = "# \u{1F518} 原生按钮测试\n请选择操作：";
            $rows = [
                [
                    "buttons" => [
                        ["id" => "btn_yes", "render_data" => ["label" => "确认", "visited_label" => "已确认", "style" => 1], "action" => ["type" => 2, "data" => "ws 文字", "enter" => false, "permission" => ["type" => 2]]],
                        ["id" => "btn_no",  "render_data" => ["label" => "取消", "visited_label" => "已取消", "style" => 2], "action" => ["type" => 2, "data" => "ws 文字", "enter" => false, "permission" => ["type" => 2]]]
                    ]
                ]
            ];
            原生按钮($md, $rows);
            break;

        case "头像":
        case "avatar":
            $url = 头像(用户);
            文字("你的头像地址:\n" . $url);
            break;

        case "BOT信息":
        case "botinfo":
            $info = BOT信息();
            文字("BOT 原始返回:\n" . $info);
            break;

        case "读数据":
        case "read":
            $count = 读("ws_test_data", "visit_count", 0);
            文字("当前访问次数: " . $count);
            break;

        case "写数据":
        case "write":
            写("ws_test_data", "visit_count", 1);
            文字("\u{2705} 已写入 ws_test_data/visit_count = 1");
            break;

        case "curl":
        case "curl_ip":
            $resp = curl("https://api.ipify.org", "GET", [], "");
            文字("curl 请求公网 IP:\n" . $resp);
            break;

        case "二维码":
        case "qrcode":
            $qr = 二维码("https://bot.q.qq.com/wiki?from=ws_" . 用户);
            图片($qr, "\u{1F4F1} 你的专属二维码");
            break;

        case "域名大写":
            $result = 域名大写("hello world ws test");
            文字("转换结果: " . $result);
            break;

        case "markdown转html":
            $html = markdown转html("# 标题\n内容");
            文字("MD 转 HTML:\n" . $html);
            break;

        case "邮箱":
            文字("\u26A0\uFE0F 邮箱测试需要 SMTP 授权码\n请发送 \"邮箱_full 收件人@xx.com 授权码\" 触发");
            break;

        case "HTML转图":
            $html = '<div style="width:400px;height:200px;background:#1f1f1f;color:#fff;padding:20px;font-family:sans-serif;border-radius:8px;"><h2 style="margin:0;">WS 测试</h2><p>HTML 转图片成功</p></div>';
            $img = HTML转图($html, 400, 200);
            图片($img, "HTML 转图片 - 102625949");
            break;

        case "画布":
            $gd = new 画布();
            $img = $gd->创建(600, 300, "#FFFFFF");
            $gd->文字($img, "WS 模式画布测试", 24, 80, 80, "#1f1f1f", __DIR__ . "/../function/font/雅黑.ttf");
            $gd->矩形($img, 40, 40, 560, 260, "#1f1f1f");
            $gd->填充圆($img, 100, 200, 40, 40, "#c23d2e");
            $gd->填充圆($img, 200, 200, 40, 40, "#3a7a3a");
            $gd->填充圆($img, 300, 200, 40, 40, "#1f6feb");
            $png = $gd->二进制输出($img);
            图片($png, "画布图片 - 102625949");
            $gd->销毁($img);
            break;

        case "帮助":
        case "help":
            文字("WS 模式测试插件 - 命令列表\n"
               . "ws菜单 / 全能菜单 - 弹出原生按钮菜单\n"
               . "ws 文字 / ws 图片 / ws 语音 / ws 视频 / ws 文件\n"
               . "ws 按钮 / ws 文卡 / ws 大图 / ws 跳转卡 / ws 流式\n"
               . "ws 撤回 / ws 引用 / ws md基础 / ws md按钮 / ws md样式 / ws 原生按钮\n"
               . "ws 头像 / ws BOT信息 / ws 读数据 / ws 写数据\n"
               . "ws curl / ws 二维码 / ws HTML转图 / ws 画布");
            break;

        default:
            // 不匹配任何命令时静默（避免对正常对话造成干扰）
            break;
    }
}
?>
