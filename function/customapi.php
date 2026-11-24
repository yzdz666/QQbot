<?php
/**
 * 自定义 API —— 可视化配置的「指令 → 请求 → 取值 → 回复」流水线
 *
 * 一条自定义 API 由四部分组成：
 *   1. 触发：指令词（多个别名）+ 匹配方式（完全 / 前缀 / 正则）+ 场景与权限
 *   2. 请求：GET / POST，URL 与 Body 里可用 {参数} {用户} 等占位符
 *   3. 取值：从返回内容里按路径取出字段，命名成变量（如 data.text → {结果}）
 *   4. 回复：模板里拼变量，按文字 / Markdown / 图片 / 语音发出去
 *
 * 后台页面：admin/apis.php；前台入口：plugin/自定义API.php
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__) . '/');
}

/** 数据表（老库首次访问自动补建；db.php 的 initTables 也建一份） */
function customapi_ensure_table() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->execute("CREATE TABLE IF NOT EXISTS custom_apis (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL DEFAULT '',
            category TEXT DEFAULT '',
            icon TEXT DEFAULT '',
            description TEXT DEFAULT '',
            enabled INTEGER DEFAULT 1,
            trigger_type TEXT DEFAULT 'message',
            event_type TEXT DEFAULT '',
            event_filter TEXT DEFAULT '',
            trigger TEXT DEFAULT '',
            match_mode TEXT DEFAULT 'exact',
            scope TEXT DEFAULT 'all',
            permission TEXT DEFAULT 'all',
            method TEXT DEFAULT 'GET',
            url TEXT DEFAULT '',
            headers TEXT DEFAULT '',
            body TEXT DEFAULT '',
            timeout INTEGER DEFAULT 20,
            parse TEXT DEFAULT 'json',
            extracts TEXT DEFAULT '[]',
            reply_type TEXT DEFAULT 'text',
            reply_template TEXT DEFAULT '',
            error_template TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        )");

        // 老库补列：CREATE TABLE IF NOT EXISTS 不会给已存在的表加字段
        $cols = [];
        foreach ((array)db()->fetchAll("PRAGMA table_info(custom_apis)") as $c) {
            $cols[(string)($c['name'] ?? '')] = true;
        }
        $add = [
            'trigger_type' => "TEXT DEFAULT 'message'",
            'event_type'   => "TEXT DEFAULT ''",
            'event_filter' => "TEXT DEFAULT ''",
        ];
        foreach ($add as $name => $type) {
            if (!isset($cols[$name])) {
                db()->execute("ALTER TABLE custom_apis ADD COLUMN {$name} {$type}");
            }
        }

        // 调用记录：排查「机器人没回复」时，能直接看到有没有命中、接口返回什么、发送是否成功
        db()->execute("CREATE TABLE IF NOT EXISTS customapi_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            appid TEXT DEFAULT '',
            api_id INTEGER DEFAULT 0,
            api_name TEXT DEFAULT '',
            trigger_type TEXT DEFAULT '',
            trigger_text TEXT DEFAULT '',
            http_code INTEGER DEFAULT 0,
            elapsed_ms INTEGER DEFAULT 0,
            sent INTEGER DEFAULT 0,
            errors TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now','localtime'))
        )");
    } catch (Throwable $e) {
        // 表已存在或数据库只读：交由调用方兜底
    }
}

/** 一条 API 的完整字段（缺字段自动补默认） */
function customapi默认字段() {
    return [
        'id'             => 0,
        'name'           => '',
        'category'       => '',
        'icon'           => '',
        'description'    => '',
        'enabled'        => 1,
        'trigger_type'   => 'message',
        'event_type'     => '',
        'event_filter'   => '',
        'trigger'        => '',
        'match_mode'     => 'exact',
        'scope'          => 'all',
        'permission'     => 'all',
        'method'         => 'GET',
        'url'            => '',
        'headers'        => '',
        'body'           => '',
        'timeout'        => 20,
        'parse'          => 'json',
        'extracts'       => '[]',
        'reply_type'     => 'text',
        'reply_template' => '',
        'error_template' => '',
    ];
}

/**
 * 支持的全部发送方式（后台下拉、前台解析、测试预览共用）
 *
 *   label    后台显示名
 *   group    下拉分组
 *   fn       调用的框架函数（空 = 特殊方式）
 *   fields   参数名，按顺序用 | 分隔
 *   optional 可省略的参数下标
 *   json     需要先解析成数组的参数下标
 *   lines    true = 整个模板按行拆成多段（流式）
 *   usage    参数说明
 *   demo     模板示例
 */
function customapi发送类型() {
    static $types = null;
    if ($types !== null) return $types;

    $types = [
        // ---------- 常用 ----------
        'text' => [
            'label'  => '文字',
            'group'  => '常用',
            'fn'     => '文字',
            'fields' => ['内容'],
            'usage'  => '整段原样发送，支持换行和 emoji。',
            'demo'   => '查询结果：{结果}',
        ],
        'markdown' => [
            'label'  => 'Markdown',
            'group'  => '常用',
            'fn'     => 'MD',
            'fields' => ['Markdown 内容'],
            'usage'  => '支持 # 标题、**加粗**、[链接](地址)；需要机器人有 Markdown 权限。',
            'demo'   => "## {结果}\n\n> 数据来自公开接口",
        ],
        'image' => [
            'label'  => '图片',
            'group'  => '常用',
            'fn'     => '图片',
            'fields' => ['图片地址'],
            'usage'  => '填 http(s) 图片直链，也支持本地图片路径。',
            'demo'   => '{结果}',
        ],
        'voice' => [
            'label'  => '语音',
            'group'  => '常用',
            'fn'     => '语音',
            'fields' => ['音频地址'],
            'usage'  => 'mp3 / wav / silk 直链，框架会自动转成 Silk。',
            'demo'   => '{结果}',
        ],
        'video' => [
            'label'  => '视频',
            'group'  => '常用',
            'fn'     => '视频',
            'fields' => ['视频地址'],
            'usage'  => '视频直链，建议 mp4。',
            'demo'   => '{结果}',
        ],
        'file' => [
            'label'  => '文件',
            'group'  => '常用',
            'fn'     => '文件',
            'fields' => ['文件地址', '文件名'],
            'optional' => [1],
            'usage'  => '文件名可省略；不填时按地址里的文件名发送。',
            'demo'   => '{结果}|资料.pdf',
        ],

        // ---------- 卡片 ----------
        'card' => [
            'label'  => '图文卡片（文卡）',
            'group'  => '卡片',
            'fn'     => '图文卡片',
            'fields' => ['标题', '描述', '图片地址', '跳转链接'],
            'usage'  => '四个字段用 | 隔开；图片和跳转链接必须是 https，标题 ≤50 字、描述 ≤200 字。',
            'demo'   => '{结果}|点我查看详情|https://example.com/cover.png|https://example.com',
        ],
        'bigimage' => [
            'label'  => '大图',
            'group'  => '卡片',
            'fn'     => '大图',
            'fields' => ['标题', '副标题', '图片地址'],
            'usage'  => '展示一张大图，附带标题和副标题。',
            'demo'   => '今日推荐|{结果}|https://example.com/cover.png',
        ],
        'linkcard' => [
            'label'  => '跳转卡',
            'group'  => '卡片',
            'fn'     => '跳转卡',
            'fields' => ['标题', '描述', '图片地址', '跳转链接'],
            'usage'  => '四个字段用 | 隔开，点击整张卡片跳到链接。',
            'demo'   => '{结果}|点击查看全文|https://example.com/cover.png|https://example.com',
        ],
        'ark' => [
            'label'  => 'Ark 卡片（模板 ID + 参数）',
            'group'  => '卡片',
            'fn'     => 'Ark',
            'fields' => ['模板 ID', '参数 JSON'],
            'json'   => [1],
            'usage'  => '模板 ID 从 QQ 开放平台拿；参数 JSON 写成 {"#TITLE#":"...","#DESC#":"..."}。',
            'demo'   => '23|{"#TITLE#":"{结果}","#DESC#":"点我查看"}',
        ],
        'ark23' => [
            'label'  => 'Ark23 链接卡片',
            'group'  => '卡片',
            'fn'     => 'Ark23',
            'fields' => ['参数 JSON'],
            'json'   => [0],
            'usage'  => '链接受众卡片，常用 #DESC#、#PROMPT# 以及 #LIST_1# / #LIST_1_URL#。',
            'demo'   => '{"#DESC#":"{结果}","#PROMPT#":"详情"}',
        ],
        'keyboard' => [
            'label'  => 'Markdown + 原生按钮',
            'group'  => '卡片',
            'fn'     => '原生按钮',
            'fields' => ['Markdown 内容', '按钮 JSON'],
            'json'   => [1],
            'usage'  => '按钮 JSON 是二维数组，一行一个数组，每个按钮含 text / data / type。',
            'demo'   => '**{结果}**|[[{"text":"再来一次","data":"再来一次","type":2}]]',
        ],
        'button' => [
            'label'  => '按钮（模板 ID）',
            'group'  => '卡片',
            'fn'     => '按钮',
            'fields' => ['按钮模板 ID'],
            'usage'  => '发一条只带按钮的消息，按钮模板先在开放平台建好。',
            'demo'   => '你的按钮模板 ID',
        ],

        // ---------- 互动 ----------
        'emoji' => [
            'label'  => '表情表态',
            'group'  => '互动',
            'fn'     => 'Emoji',
            'fields' => ['表情 ID', '附带文字'],
            'optional' => [1],
            'usage'  => '给当前消息贴一个表情，表情 ID 用官方表情编号。',
            'demo'   => '1|{结果}',
        ],
        'quote' => [
            'label'  => '引用回复（文字）',
            'group'  => '互动',
            'fn'     => '引用',
            'fields' => ['消息 ID', '内容'],
            'usage'  => '引用一条消息再回复，消息 ID 可用 {消息ID}。',
            'demo'   => '{消息ID}|{结果}',
        ],
        'quote_image' => [
            'label'  => '引用回复（图片）',
            'group'  => '互动',
            'fn'     => '引用图片',
            'fields' => ['消息 ID', '图片地址', '附带文字'],
            'optional' => [2],
            'usage'  => '引用消息并附一张图。',
            'demo'   => '{消息ID}|{结果}',
        ],
        'quote_md' => [
            'label'  => '引用回复（Markdown）',
            'group'  => '互动',
            'fn'     => '引用MD',
            'fields' => ['消息 ID', 'Markdown 内容'],
            'usage'  => '引用消息并回复 Markdown。',
            'demo'   => '{消息ID}|## {结果}',
        ],
        'quote_voice' => [
            'label'  => '引用回复（语音）',
            'group'  => '互动',
            'fn'     => '引用语音',
            'fields' => ['消息 ID', '音频地址', '附带文字'],
            'optional' => [2],
            'usage'  => '引用消息并回一条语音。',
            'demo'   => '{消息ID}|{结果}',
        ],
        'quote_video' => [
            'label'  => '引用回复（视频）',
            'group'  => '互动',
            'fn'     => '引用视频',
            'fields' => ['消息 ID', '视频地址', '附带文字'],
            'optional' => [2],
            'usage'  => '引用消息并回一个视频。',
            'demo'   => '{消息ID}|{结果}',
        ],
        'quote_file' => [
            'label'  => '引用回复（文件）',
            'group'  => '互动',
            'fn'     => '引用文件',
            'fields' => ['消息 ID', '文件地址', '文件名', '附带文字'],
            'optional' => [2, 3],
            'usage'  => '引用消息并回一个文件，文件名和文字都可省略。',
            'demo'   => '{消息ID}|{结果}|资料.pdf',
        ],
        'recall' => [
            'label'  => '撤回消息',
            'group'  => '互动',
            'fn'     => '撤回',
            'fields' => ['消息 ID'],
            'usage'  => '撤回指定消息，通常填 {消息ID} 撤回触发的那条。',
            'demo'   => '{消息ID}',
        ],
        'stream' => [
            'label'  => '流式回复',
            'group'  => '互动',
            'fn'     => '流式',
            'fields' => ['第一段', '第二段', '…'],
            'lines'  => true,
            'usage'  => '每行一段，按顺序流式发出，适合 AI 长回复。',
            'demo'   => "正在查询…\n{结果}",
        ],

        // ---------- 高级 ----------
        'combo' => [
            'label'  => '组合发送（一行调用一个函数）',
            'group'  => '高级',
            'fn'     => '',
            'fields' => [],
            'usage'  => '每行一个函数，格式「函数名|参数1|参数2」。可混用文字、图片、语音、文卡、Ark、按钮、引用、撤回等全部发送函数。',
            'demo'   => "文字|查询结果：{结果}\n图片|{封面}\n文卡|{结果}|点我查看|{封面}|https://example.com",
        ],
        'none' => [
            'label'  => '不发送（只调用接口）',
            'group'  => '高级',
            'fn'     => '',
            'fields' => [],
            'usage'  => '只请求接口、不回复任何消息，适合埋点或通知类接口。',
            'demo'   => '',
        ],
    ];
    return $types;
}

/** 发送方式 key => 显示名（保存校验、列表展示用） */
function customapi回复类型列表() {
    $out = [];
    foreach (customapi发送类型() as $k => $t) {
        $out[$k] = $t['label'];
    }
    return $out;
}

/** 组合发送里可用的函数别名（不区分大小写） */
function customapi发送别名() {
    return [
        '文字' => 'text', '文本' => 'text', 'text' => 'text',
        'md' => 'markdown', 'markdown' => 'markdown',
        '图片' => 'image', 'image' => 'image',
        '语音' => 'voice', 'voice' => 'voice',
        '视频' => 'video', 'video' => 'video',
        '文件' => 'file', 'file' => 'file',
        '文卡' => 'card', '卡片' => 'card', '图文' => 'card', '图文卡片' => 'card', 'card' => 'card',
        '大图' => 'bigimage', 'bigimage' => 'bigimage',
        '跳转卡' => 'linkcard', '链接卡片' => 'linkcard', 'linkcard' => 'linkcard',
        'ark' => 'ark',
        'ark23' => 'ark23',
        '原生按钮' => 'keyboard', '按钮组' => 'keyboard', 'keyboard' => 'keyboard',
        '按钮' => 'button', 'button' => 'button',
        '表情' => 'emoji', '表态' => 'emoji', 'emoji' => 'emoji',
        '引用' => 'quote', 'quote' => 'quote',
        '引用图片' => 'quote_image',
        '引用md' => 'quote_md', '引用markdown' => 'quote_md',
        '引用语音' => 'quote_voice',
        '引用视频' => 'quote_video',
        '引用文件' => 'quote_file',
        '撤回' => 'recall', 'recall' => 'recall',
        '流式' => 'stream', 'stream' => 'stream',
    ];
}

/** 把「文字 / 文卡 / card」统一识别成发送方式 key */
function customapi识别发送类型($name) {
    $name = trim((string)$name);
    if ($name === '') return '';
    $types = customapi发送类型();
    if (isset($types[$name])) return $name;
    $lower = strtolower($name);
    if (isset($types[$lower])) return $lower;
    $alias = customapi发送别名();
    if (isset($alias[$lower])) return $alias[$lower];
    if (isset($alias[$name])) return $alias[$name];
    return '';
}

/** 下拉选项（后台与校验共用） */
function customapi选项() {
    return [
        'trigger_type' => [
            'message' => '消息指令（收到指定文字才触发）',
            'event'   => '事件触发（发生指定事件就调用）',
        ],
        'match_mode' => [
            'exact'  => '完全相等（发「天气」才触发）',
            'prefix' => '开头匹配（发「天气 北京」也能触发，后面算参数）',
            'regex'  => '正则匹配（高级，捕获组会变成 {1} {2}）',
        ],
        'scope' => [
            'all'     => '群聊 + 私聊',
            'group'   => '仅群聊',
            'private' => '仅私聊',
        ],
        'permission' => [
            'all'   => '所有人',
            'admin' => '仅机器人管理员',
        ],
        'parse' => [
            'json' => 'JSON（按路径取字段）',
            'text' => '纯文本（整段返回或正则取值）',
        ],
        'reply_type' => customapi回复类型列表(),
    ];
}

/**
 * 全部可监听事件（分组 => [事件名 => 说明]）
 * 与 index.php / ws_event_handler.php 的事件分发保持一致。
 */
function customapi事件列表() {
    return [
        '消息' => [
            'GROUP_AT_MESSAGE_CREATE' => '群聊 @机器人 消息',
            'GROUP_MESSAGE_CREATE'    => '群聊消息（全量）',
            'C2C_MESSAGE_CREATE'      => '私聊消息',
            'AT_MESSAGE_CREATE'       => '频道 @消息',
            'MESSAGE_CREATE'          => '频道消息',
            'DIRECT_MESSAGE_CREATE'   => '频道私信',
        ],
        '群与好友' => [
            'GROUP_ADD_ROBOT'          => '机器人被加入群',
            'GROUP_DEL_ROBOT'          => '机器人被移出群 / 群解散',
            'GROUP_MEMBER_ADD'         => '群成员增加',
            'GROUP_MEMBER_REMOVE'      => '群成员减少',
            'GROUP_JOIN_REQUEST'       => '入群申请',
            'FRIEND_ADD'               => '添加好友',
            'FRIEND_DEL'               => '删除好友',
            'GROUP_MSG_REJECT'         => '群聊拒绝主动消息',
            'GROUP_MSG_RECEIVE'        => '群聊接收主动消息',
            'SUBSCRIBE_MESSAGE_STATUS' => '订阅消息状态变更',
        ],
        '互动' => [
            'INTERACTION_CREATE' => '按钮 / 菜单互动回调',
        ],
        '频道与子频道' => [
            'GUILD_CREATE'         => '频道创建',
            'GUILD_UPDATE'         => '频道信息更新',
            'GUILD_DELETE'         => '频道删除',
            'CHANNEL_CREATE'       => '子频道创建',
            'CHANNEL_UPDATE'       => '子频道更新',
            'CHANNEL_DELETE'       => '子频道删除',
            'GUILD_MEMBER_ADD'     => '频道成员增加',
            'GUILD_MEMBER_UPDATE'  => '频道成员更新',
            'GUILD_MEMBER_REMOVE'  => '频道成员移除',
        ],
        '消息管理' => [
            'MESSAGE_DELETE'         => '消息删除',
            'PUBLIC_MESSAGE_DELETE'  => '公域消息删除',
            'DIRECT_MESSAGE_DELETE'  => '私信删除',
            'MESSAGE_AUDIT_PASS'     => '消息审核通过',
            'MESSAGE_AUDIT_REJECT'   => '消息审核拒绝',
            'MESSAGE_REACTION_ADD'   => '表情表态添加',
            'MESSAGE_REACTION_REMOVE'=> '表情表态移除',
        ],
        '论坛' => [
            'OPEN_FORUM_THREAD_CREATE' => '论坛主题创建',
            'OPEN_FORUM_THREAD_UPDATE' => '论坛主题更新',
            'OPEN_FORUM_THREAD_DELETE' => '论坛主题删除',
            'OPEN_FORUM_POST_CREATE'   => '论坛回帖创建',
            'OPEN_FORUM_POST_DELETE'   => '论坛回帖删除',
            'OPEN_FORUM_REPLY_CREATE'  => '论坛评论创建',
            'OPEN_FORUM_REPLY_DELETE'  => '论坛评论删除',
            'FORUM_PUBLISH_AUDIT_RESULT' => '论坛发表审核结果',
        ],
        '音频' => [
            'AUDIO_START'                          => '音频开始',
            'AUDIO_FINISH'                         => '音频结束',
            'AUDIO_ON_MIC'                         => '上麦',
            'AUDIO_OFF_MIC'                        => '下麦',
            'AUDIO_OR_LIVE_CHANNEL_MEMBER_ENTER'   => '音视频成员进入',
            'AUDIO_OR_LIVE_CHANNEL_MEMBER_EXIT'    => '音视频成员离开',
        ],
    ];
}

/** 归一化一行记录 */
function customapi规范化($row) {
    $row = array_merge(customapi默认字段(), is_array($row) ? $row : []);
    $row['id']       = (int)$row['id'];
    $row['enabled']  = (int)$row['enabled'];
    $row['timeout']  = (int)$row['timeout'];
    if ($row['timeout'] < 3)   $row['timeout'] = 3;
    if ($row['timeout'] > 60)  $row['timeout'] = 60;
    if (!is_array(json_decode((string)$row['extracts'], true))) $row['extracts'] = '[]';
    foreach (['trigger_type', 'match_mode', 'scope', 'permission', 'method', 'parse', 'reply_type'] as $k) {
        $row[$k] = trim((string)$row[$k]);
    }
    if (!in_array($row['trigger_type'], ['message', 'event'], true)) $row['trigger_type'] = 'message';
    $row['event_type']   = trim((string)$row['event_type']);
    $row['event_filter'] = trim((string)$row['event_filter']);
    $row['method'] = strtoupper($row['method']) === 'POST' ? 'POST' : 'GET';
    return $row;
}

/** 列表（$onlyEnabled = true 只取启用的，供前台匹配） */
function customapi列表($onlyEnabled = false) {
    customapi_ensure_table();
    try {
        $sql = "SELECT * FROM custom_apis" . ($onlyEnabled ? " WHERE enabled = 1" : "") . " ORDER BY id DESC";
        $rows = db()->fetchAll($sql);
    } catch (Throwable $e) {
        return [];
    }
    return array_map('customapi规范化', is_array($rows) ? $rows : []);
}

/** 单条 */
function customapi取($id) {
    customapi_ensure_table();
    try {
        $row = db()->fetch("SELECT * FROM custom_apis WHERE id = ?", [(int)$id]);
    } catch (Throwable $e) {
        return null;
    }
    return $row ? customapi规范化($row) : null;
}

/** 校验并保存（$id = 0 为新建）；返回 [bool, id|错误信息] */
function customapi保存($data, $id = 0) {
    customapi_ensure_table();
    $id  = (int)$id;
    $row = [];
    $def = customapi默认字段();
    foreach ($def as $k => $v) {
        if ($k === 'id') continue;
        $row[$k] = array_key_exists($k, $data) ? $data[$k] : $v;
    }

    $row['name'] = trim((string)$row['name']);
    if ($row['name'] === '') return [false, '请填写 API 名称'];

    $row['trigger_type'] = in_array((string)$row['trigger_type'], ['message', 'event'], true)
        ? (string)$row['trigger_type'] : 'message';
    $row['event_type']   = trim((string)$row['event_type']);
    $row['event_filter'] = trim((string)$row['event_filter']);

    $row['trigger'] = trim((string)$row['trigger']);
    if ($row['trigger_type'] === 'event') {
        if ($row['event_type'] === '') return [false, '请选择要监听的事件'];
    } elseif ($row['trigger'] === '') {
        return [false, '请至少填一个触发指令'];
    }

    $row['url'] = trim((string)$row['url']);
    if (!preg_match('/^https?:\/\//i', $row['url'])) {
        return [false, '请求地址需要以 http:// 或 https:// 开头'];
    }

    if (!array_key_exists($row['match_mode'], customapi选项()['match_mode'])) $row['match_mode'] = 'exact';
    if (!array_key_exists($row['scope'], customapi选项()['scope'])) $row['scope'] = 'all';
    if (!array_key_exists($row['permission'], customapi选项()['permission'])) $row['permission'] = 'all';
    if (!array_key_exists($row['parse'], customapi选项()['parse'])) $row['parse'] = 'json';
    if (!array_key_exists($row['reply_type'], customapi选项()['reply_type'])) $row['reply_type'] = 'text';

    $row['enabled'] = (int)!empty($row['enabled']);
    $row['timeout'] = max(3, min(60, (int)$row['timeout']));
    $row['method']  = strtoupper((string)$row['method']) === 'POST' ? 'POST' : 'GET';

    // 取值表：只保留合法行
    $extracts = json_decode((string)$row['extracts'], true);
    if (!is_array($extracts)) $extracts = [];
    $clean = [];
    foreach ($extracts as $ex) {
        if (!is_array($ex)) continue;
        $name = trim((string)($ex['name'] ?? ''));
        $path = trim((string)($ex['path'] ?? ''));
        if ($name === '' || $path === '') continue;
        $clean[] = [
            'name'    => preg_replace('/[^0-9A-Za-z_\x{4e00}-\x{9fa5}]/u', '', $name) ?: '字段',
            'path'    => $path,
            'default' => (string)($ex['default'] ?? ''),
        ];
    }
    $row['extracts'] = json_encode($clean, JSON_UNESCAPED_UNICODE);

    $row['updated_at'] = date('Y-m-d H:i:s');

    try {
        if ($id > 0) {
            $set = [];
            $params = [];
            foreach ($row as $k => $v) {
                $set[] = "{$k} = ?";
                $params[] = $v;
            }
            $params[] = $id;
            db()->execute("UPDATE custom_apis SET " . implode(', ', $set) . " WHERE id = ?", $params);
            return [true, $id];
        }
        $cols = array_keys($row);
        $holders = array_fill(0, count($cols), '?');
        db()->execute(
            "INSERT INTO custom_apis (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $holders) . ")",
            array_values($row)
        );
        return [true, (int)db()->lastInsertId()];
    } catch (Throwable $e) {
        return [false, '保存失败：' . $e->getMessage()];
    }
}

function customapi删除($id) {
    customapi_ensure_table();
    try {
        db()->execute("DELETE FROM custom_apis WHERE id = ?", [(int)$id]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function customapi启用($id, $enabled) {
    customapi_ensure_table();
    try {
        db()->execute("UPDATE custom_apis SET enabled = ?, updated_at = ? WHERE id = ?",
                      [$enabled ? 1 : 0, date('Y-m-d H:i:s'), (int)$id]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 触发词：换行 / 逗号 / 顿号都算分隔 */
function customapi触发词($raw) {
    $parts = preg_split('/[\r\n,，、]+/u', (string)$raw);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $out[] = $p;
    }
    return array_values(array_unique($out));
}

/**
 * 清洗用户发来的指令文本
 *
 * 框架入口已经用 清理消息内容() 洗过一遍；这里再兜一层，
 * 保证只替换了插件文件的旧站点也能正确匹配。
 */
function customapi清洗消息($text) {
    $text = (string)$text;
    if ($text === '') return '';

    // 1. 零宽字符
    $text = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text);
    // 2. <@!123> / <@123> 提及
    $text = preg_replace('/<@!?[A-Za-z0-9_\-]+>/', '', $text);
    // 3. 少数客户端把 @昵称 当纯文本发过来（要求后面还有正文）
    $stripped = preg_replace('/^@[^\s\x{3000}]{1,32}[\s\x{3000}]+/u', '', (string)$text);
    if (is_string($stripped) && trim($stripped) !== '') {
        $text = $stripped;
    }
    // 4. 首尾空白（含全角空格）与指令斜杠（含全角）
    $text = preg_replace('/^[\s\x{3000}\x{00A0}\/／]+|[\s\x{3000}\x{00A0}\/／]+$/u', '', (string)$text);

    return trim((string)$text);
}

/**
 * 匹配一条消息
 * @return array [api|null, args]  args = ['全部'=>..., '列表'=>[...]]
 */
function customapi匹配($text) {
    $text = customapi清洗消息($text);
    if ($text === '') return [null, []];

    foreach (customapi列表(true) as $api) {
        if (($api['trigger_type'] ?? 'message') !== 'message') continue;
        foreach (customapi触发词($api['trigger']) as $t) {
            $args = customapi匹配单个($api, $t, $text);
            if ($args !== null) return [$api, $args];
        }
    }
    return [null, []];
}

/** 当前事件名（原始 t）；取不到返回空串 */
function customapi当前事件() {
    if (defined('raw') && is_array(raw) && !empty(raw['t'])) {
        return (string)raw['t'];
    }
    return '';
}

/**
 * 事件触发匹配：事件名 + 条件过滤
 * @return array|null 命中的第一个 API（兼容旧调用）
 */
function customapi匹配事件($eventName = null, $raw = null) {
    $list = customapi匹配事件全部($eventName, $raw);
    return $list ? $list[0] : null;
}

/**
 * 事件触发匹配：同一个事件可以挂多条 API，按创建顺序全部返回
 * @return array
 */
function customapi匹配事件全部($eventName = null, $raw = null) {
    if ($eventName === null) $eventName = customapi当前事件();
    if ($raw === null) $raw = defined('raw') && is_array(raw) ? raw : [];
    if ($eventName === '') return [];

    $hits = [];
    foreach (customapi列表(true) as $api) {
        if (($api['trigger_type'] ?? 'message') !== 'event') continue;

        $types = preg_split('/[\s,，]+/', (string)$api['event_type']);
        $hit = false;
        foreach ($types as $t) {
            $t = trim($t);
            if ($t === '') continue;
            if ($t === '*') { $hit = true; break; }
            if (strcasecmp($t, $eventName) === 0) { $hit = true; break; }
        }
        if (!$hit) continue;

        if (!customapi事件条件满足($api['event_filter'], $raw)) continue;
        $hits[] = $api;
    }
    return $hits;
}

/**
 * 事件条件：一行一条，支持
 *   d.dismiss = false
 *   d.group_openid != ''
 *   d.content 包含 关键词
 * 全部满足才触发；留空不限制。
 */
function customapi事件条件满足($filter, $raw = null) {
    $filter = trim((string)$filter);
    if ($filter === '') return true;
    if ($raw === null) $raw = defined('raw') && is_array(raw) ? raw : [];
    if (!is_array($raw)) return false;

    foreach (preg_split('/\r\n|\r|\n/', $filter) as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (preg_match('/^(.+?)\s*(!=|=|包含|不包含)\s*(.*)$/u', $line, $m)) {
            $path   = trim($m[1]);
            $op     = $m[2];
            $expect = trim($m[3], " \t\"'");
        } else {
            continue;
        }

        $path = preg_replace('/^raw\./i', '', $path);
        $actual = customapi深取($raw, explode('.', $path));
        if (is_bool($actual)) $actual = $actual ? 'true' : 'false';
        if ($actual === null) $actual = '';
        if (is_array($actual) || is_object($actual)) {
            $actual = json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $actual = (string)$actual;

        if ($op === '=' && $actual !== $expect) return false;
        if ($op === '!=' && $actual === $expect) return false;
        if ($op === '包含' && mb_strpos($actual, $expect, 0, 'UTF-8') === false) return false;
        if ($op === '不包含' && mb_strpos($actual, $expect, 0, 'UTF-8') !== false) return false;
    }
    return true;
}

/** 把事件 JSON 展开成 {事件.xxx} 变量，最多 3 层 */
function customapi展开事件字段($arr, $prefix, &$vars, $depth = 3) {
    if (!is_array($arr) || $depth < 0) return;
    foreach ($arr as $k => $v) {
        if (is_array($v)) {
            customapi展开事件字段($v, $prefix . '.' . $k, $vars, $depth - 1);
        } elseif (is_scalar($v) && $v !== null) {
            $vars[$prefix . '.' . $k] = (string)$v;
        }
        if (count($vars) > 120) return;
    }
}

/** 事件相关变量：{事件} {场景} {描述} {原始JSON} {事件.d.xxx} */
function customapi事件变量($raw = null) {
    if ($raw === null) $raw = defined('raw') && is_array(raw) ? raw : [];
    $vars = [
        '事件' => '',
        '场景' => defined('消息来源') ? (string)消息来源 : '',
        '描述' => defined('消息') ? (string)消息 : '',
    ];
    if (!is_array($raw) || !$raw) {
        $vars['原始JSON'] = '';
        return $vars;
    }
    $vars['事件'] = (string)($raw['t'] ?? '');
    $json = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $vars['原始JSON'] = mb_strlen($json, 'UTF-8') > 20000 ? mb_substr($json, 0, 20000, 'UTF-8') . '…' : $json;
    if (isset($raw['d']) && is_array($raw['d'])) {
        customapi展开事件字段($raw['d'], '事件.d', $vars, 3);
    }
    return $vars;
}

function customapi匹配单个($api, $trigger, $text) {
    $mode = $api['match_mode'];

    if ($mode === 'regex') {
        $re = '#' . str_replace('#', '\\#', $trigger) . '#u';
        if (@preg_match($re, $text, $m)) {
            $list = [];
            foreach ($m as $i => $v) {
                if ($i === 0) continue;
                $list[] = (string)$v;
            }
            return ['全部' => isset($list[0]) ? $list[0] : trim($text), '列表' => $list, '完整' => $text];
        }
        return null;
    }

    if ($mode === 'prefix') {
        $len = mb_strlen($trigger, 'UTF-8');
        // ASCII 指令不区分大小写（Weather / weather 都能触发），中文不受影响
        if ($len > 0 && mb_substr(mb_strtolower($text, 'UTF-8'), 0, $len, 'UTF-8') === mb_strtolower($trigger, 'UTF-8')) {
            $rest = trim(mb_substr($text, $len, null, 'UTF-8'));
            $rest = preg_replace('/^[\/／,，:：\s\x{3000}]+/u', '', $rest);
            return ['全部' => $rest, '列表' => customapi拆参数($rest), '完整' => $text];
        }
        return null;
    }

    // exact
    if (mb_strtolower($text, 'UTF-8') === mb_strtolower($trigger, 'UTF-8')) {
        return ['全部' => '', '列表' => [], '完整' => $text];
    }
    return null;
}

/** 参数按空白拆开，支持 "北京 朝阳" → ['北京','朝阳'] */
function customapi拆参数($rest) {
    $rest = trim((string)$rest);
    if ($rest === '') return [];
    $parts = preg_split('/\s+/u', $rest);
    return array_values(array_filter(array_map('trim', $parts), 'strlen'));
}

/** 基础变量：消息里能拿到的东西 + 用户传的参数 */
function customapi基础变量($args = [], $ctx = []) {
    $now   = time();
    $all   = (string)($args['全部'] ?? '');
    $list  = is_array($args['列表'] ?? null) ? $args['列表'] : [];
    $isGrp = customapi是群场景();

    $vars = [
        '参数'  => $all,
        'args'  => $all,
        '0'     => $all,
        '消息'  => defined('消息') ? (string)消息 : '',
        '完整'  => (string)($args['完整'] ?? (defined('消息') ? 消息 : '')),
        '用户'  => defined('用户') ? (string)用户 : '',
        '昵称'  => (string)($ctx['nickname'] ?? ''),
        '群号'  => $isGrp && defined('来源') ? (string)来源 : '',
        '群openid' => $isGrp && defined('来源') ? (string)来源 : '',
        '用户openid' => defined('用户') ? (string)用户 : '',
        '来源'  => defined('来源') ? (string)来源 : '',
        '消息ID' => defined('消息ID') ? (string)消息ID : '',
        '事件ID' => defined('事件ID') ? (string)事件ID : '',
        'appid' => defined('appid') ? (string)appid : '',
        '时间'  => date('Y-m-d H:i:s', $now),
        '日期'  => date('Y-m-d', $now),
        '时间戳' => (string)$now,
    ];
    foreach ($list as $i => $v) {
        $n = $i + 1;
        if ($n > 9) break;
        $vars[(string)$n] = (string)$v;
    }
    return array_merge($vars, customapi事件变量());
}

/** 模板渲染：{变量} 一次性替换（strtr 不会二次展开） */
function customapi渲染($tpl, $vars, $encode = false) {
    $tpl = (string)$tpl;
    if ($tpl === '' || !$vars) return $tpl;
    $map = [];
    foreach ($vars as $k => $v) {
        if (is_array($v) || is_object($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $map['{' . $k . '}'] = $encode ? rawurlencode((string)$v) : (string)$v;
    }
    return strtr($tpl, $map);
}

/**
 * 按路径取值
 *   data.text        普通嵌套
 *   result.0.url     数组下标
 *   list.*.url       数组通配（取第一个有值的）
 *   @body            原始响应体
 *   @json            整个 JSON 序列化
 *   re:/正则/         正则第一个捕获组（无捕获组取整个匹配）
 */
function customapi取路径($body, $json, $path) {
    $path = trim((string)$path);
    if ($path === '') return null;

    if ($path === '@body') return $body;
    if ($path === '@json') {
        return is_array($json) ? json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $body;
    }
    if (strpos($path, 're:') === 0) {
        $re = substr($path, 3);
        if ($re === '') return null;
        $pattern = '#' . str_replace('#', '\\#', $re) . '#u';
        if (@preg_match($pattern, (string)$body, $m)) {
            return isset($m[1]) ? $m[1] : (isset($m[0]) ? $m[0] : null);
        }
        // 正则本身带定界符时再试一次
        if (@preg_match($re, (string)$body, $m)) {
            return isset($m[1]) ? $m[1] : (isset($m[0]) ? $m[0] : null);
        }
        return null;
    }

    if ($json === null) return null;
    if ($path === '$') return $json;
    if (strpos($path, '$.') === 0) $path = substr($path, 2);
    $path = ltrim($path, '$');
    $path = ltrim($path, '.');
    if ($path === '') return $json;

    $segs = explode('.', $path);
    return customapi深取($json, $segs);
}

function customapi深取($cur, $segs) {
    if (!$segs) return $cur;
    $seg = array_shift($segs);

    if ($seg === '*') {
        if (!is_array($cur)) return null;
        foreach ($cur as $v) {
            $r = customapi深取($v, $segs);
            if ($r !== null && $r !== '') return $r;
        }
        return null;
    }

    if (is_array($cur) && array_key_exists($seg, $cur)) {
        return customapi深取($cur[$seg], $segs);
    }
    if (is_object($cur) && isset($cur->$seg)) {
        return customapi深取($cur->$seg, $segs);
    }
    return null;
}

/** 从响应里按「取值表」取变量；未配置时自动兜底 */
function customapi取值($body, $api) {
    $json = json_decode((string)$body, true);
    $vars = [];

    $rows = json_decode((string)($api['extracts'] ?? '[]'), true);
    if (!is_array($rows)) $rows = [];

    foreach ($rows as $row) {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') continue;
        $val = customapi取路径($body, $json, (string)($row['path'] ?? ''));
        if ($val === null || $val === '') $val = (string)($row['default'] ?? '');
        if (is_array($val) || is_object($val)) {
            $val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        // 接口常在文本前后带空白，取值时抹掉（内部换行保留）
        $vars[$name] = is_string($val) ? trim($val) : (string)$val;
    }

    // 没配取值，或配了但没叫「结果」时，给 {结果} 一个合理的默认
    if (!isset($vars['结果']) || $vars['结果'] === '') {
        $auto = '';
        if (is_array($json)) {
            foreach (['data.text', 'text', 'message', 'msg', 'content', 'data', 'result', 'answer', 'reply'] as $guess) {
                $v = customapi取路径($body, $json, $guess);
                if (is_string($v) && trim($v) !== '') { $auto = $v; break; }
                if (is_array($v)) { $auto = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); break; }
            }
            if ($auto === '') {
                $auto = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        } else {
            $auto = trim((string)$body);
        }
        $vars['结果'] = $auto;
    }

    return $vars;
}

/** 通用 HTTP 请求（独立于全局 curl，超时可配、支持自定义头） */
function customapi_http($url, $method = 'GET', $headers = [], $body = '', $timeout = 20) {
    $method = strtoupper($method) === 'POST' ? 'POST' : 'GET';
    $hdrs = is_array($headers) ? array_values(array_filter($headers, 'strlen')) : [];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, max(3, (int)$timeout));
    if ($hdrs) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    curl_setopt($ch, CURLOPT_USERAGENT, 'QQbot-CustomAPI/1.0');

    $insecure = false;
    try { $insecure = db()->getSetting('ssl_insecure', '0') === '1'; } catch (Throwable $e) {}
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$insecure);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $insecure ? 0 : 2);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$body);
        // 没写 Content-Type 时按 JSON 发，绝大多数接口都认
        $hasCT = false;
        foreach ($hdrs as $h) {
            if (stripos($h, 'content-type:') === 0) { $hasCT = true; break; }
        }
        if (!$hasCT) curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($hdrs, ['Content-Type: application/json']));
    }

    $t0   = microtime(true);
    $resp = curl_exec($ch);
    $ms   = (int)round((microtime(true) - $t0) * 1000);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $raw = $resp === false ? '' : (string)$resp;
    $truncated = false;
    if (strlen($raw) > 4 * 1024 * 1024) {
        $raw = substr($raw, 0, 4 * 1024 * 1024);
        $truncated = true;
    }

    return [
        'body'      => $raw,
        'code'      => $code,
        'error'     => $err,
        'bytes'     => strlen($raw),
        'elapsed_ms'=> $ms,
        'truncated' => $truncated,
    ];
}

/** 构造请求：URL 里的变量按 URL 编码，Header / Body 原样 */
function customapi构造请求($api, $vars) {
    $url = trim((string)$api['url']);
    $url = customapi渲染($url, $vars, true);
    // 模板里已经手写了 %XX 的，避免二次编码
    $url = str_replace('%257B', '%7B', $url);

    $headers = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$api['headers']) as $line) {
        $line = trim($line);
        if ($line !== '' && strpos($line, ':') !== false) {
            $headers[] = customapi渲染($line, $vars, false);
        }
    }

    $body = (string)$api['body'];
    if ($body !== '') $body = customapi渲染($body, $vars, false);

    return [
        'url'     => $url,
        'method'  => strtoupper((string)$api['method']) === 'POST' ? 'POST' : 'GET',
        'headers' => $headers,
        'body'    => $body,
    ];
}

/**
 * 执行一次（不发送消息，只返回结果）
 * @param array $api
 * @param array $args   customapi匹配() 的第二个返回值
 * @param array $opts   ['ctx'=>['nickname'=>'']]
 */
function customapi执行($api, $args = [], $opts = []) {
    $vars = customapi基础变量($args, $opts['ctx'] ?? []);
    $req  = customapi构造请求($api, $vars);

    $fail = function ($msg) use ($req) {
        return [
            'ok'      => false,
            'error'   => $msg,
            'request' => ['url' => $req['url'], 'method' => $req['method']],
            'http'    => ['code' => 0, 'error' => $msg, 'bytes' => 0, 'elapsed_ms' => 0],
            'raw'     => '',
            'values'  => [],
            'text'    => '',
        ];
    };

    if (!preg_match('/^https?:\/\//i', $req['url'])) {
        return $fail('请求地址为空或不是 http(s) 开头，请检查 URL 模板');
    }

    $r = customapi_http($req['url'], $req['method'], $req['headers'], $req['body'], $api['timeout']);

    $vars = array_merge($vars, customapi取值($r['body'], $api));
    $vars['状态码'] = (string)$r['code'];
    $vars['耗时']   = $r['elapsed_ms'] . 'ms';
    $vars['响应']   = (string)$r['body'];

    $ok  = $r['code'] >= 200 && $r['code'] < 300 && $r['body'] !== '';
    $tpl = $ok
        ? ((string)$api['reply_template'] !== '' ? (string)$api['reply_template'] : '{结果}')
        : ((string)$api['error_template'] !== '' ? (string)$api['error_template'] : '接口暂时不可用，请稍后再试');

    $text = trim(customapi渲染($tpl, $vars));
    if ($ok && $text === '') {
        $text = trim(customapi渲染('{结果}', $vars));
    }

    return [
        'ok'      => $ok,
        'error'   => $ok ? '' : ($r['error'] !== '' ? $r['error'] : ('HTTP ' . $r['code'])),
        'request' => ['url' => $req['url'], 'method' => $req['method']],
        'http'    => [
            'code'       => $r['code'],
            'error'      => $r['error'],
            'bytes'      => $r['bytes'],
            'elapsed_ms' => $r['elapsed_ms'],
            'truncated'  => $r['truncated'],
        ],
        'raw'     => $r['body'],
        'values'  => $vars,
        'vars'    => $vars,
        'text'    => $text,
    ];
}

/**
 * 把模板解析成「发送动作」列表
 *
 * 单参数方式（文字 / 图片 / 语音…）直接用渲染后的整段；
 * 多参数方式（文卡 / 大图 / Ark…）先按 | 拆分再逐个渲染，
 * 这样变量内容里带换行或竖线也不会把参数冲乱。
 *
 * @param array  $api
 * @param array  $vars
 * @param string $rendered 已经整体渲染好的文本（单参数方式优先用）
 * @param array  $errors   解析中发现的问题
 * @return array [['type'=>..,'fn'=>..,'args'=>[..]], ...]
 */
function customapi解析发送($api, $vars = [], $rendered = '', &$errors = null) {
    if ($errors === null) $errors = [];

    $types = customapi发送类型();
    $type  = (string)($api['reply_type'] ?? 'text');
    if (!isset($types[$type])) $type = 'text';
    if ($type === 'none') return [];
    if ($type === 'combo') {
        $raw = (string)($api['reply_template'] ?? '');
        if (trim($raw) === '') $raw = '文字|{结果}';
        return customapi解析组合发送($raw, $vars, $errors);
    }

    $def = $types[$type];
    $raw = (string)($api['reply_template'] ?? '');
    if (trim($raw) === '') $raw = '{结果}';

    // 流式：一行一段
    if (!empty($def['lines'])) {
        $args = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim(customapi渲染($line, $vars));
            if ($line !== '') $args[] = $line;
        }
        if (!$args) {
            $errors[] = '流式回复至少要写一行内容';
            return [];
        }
        return [['type' => $type, 'fn' => $def['fn'], 'args' => $args]];
    }

    $n = count($def['fields']);

    // 单参数：整段发送，不拆 |（避免正文里的竖线被误当分隔符）
    if ($n <= 1) {
        $val = trim($rendered !== '' ? (string)$rendered : customapi渲染($raw, $vars));
        list($ok, $ret) = customapi校对发送参数($def, [$val]);
        if (!$ok) { $errors[] = $ret; return []; }
        return [['type' => $type, 'fn' => $def['fn'], 'args' => $ret]];
    }

    // 多参数：先拆后渲染
    $parts = preg_split('/\s*\|\s*/u', $raw, $n);
    $args  = [];
    foreach ($parts as $p) $args[] = trim(customapi渲染($p, $vars));

    list($ok, $ret) = customapi校对发送参数($def, $args);
    if (!$ok) { $errors[] = $ret; return []; }
    return [['type' => $type, 'fn' => $def['fn'], 'args' => $ret]];
}

/** 组合发送：一行一个函数，格式「函数名|参数1|参数2」 */
function customapi解析组合发送($raw, $vars = [], &$errors = null) {
    if ($errors === null) $errors = [];
    $types = customapi发送类型();
    $out   = [];
    $lines = preg_split('/\r\n|\r|\n/', (string)$raw);

    foreach ($lines as $i => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '//') === 0) continue;

        $parts = explode('|', $line);
        $name  = trim((string)array_shift($parts));
        $key   = customapi识别发送类型($name);
        if ($key === '' || !isset($types[$key]) || in_array($key, ['combo', 'none'], true)) {
            $errors[] = '第 ' . ($i + 1) . ' 行「' . $name . '」不是可用的发送函数';
            continue;
        }

        $def = $types[$key];
        $n   = count($def['fields']);

        // 单参数函数把后面的 | 原样还给正文
        if ($n === 1) {
            $args = [trim(customapi渲染(implode('|', $parts), $vars))];
        } else {
            $args = [];
            foreach ($parts as $p) $args[] = trim(customapi渲染($p, $vars));
            // 参数多了就并进最后一格，少了下行会补空
            if (count($args) > $n) {
                $extra = array_splice($args, $n - 1);
                $args[$n - 1] = implode('|', $extra);
            }
        }

        list($ok, $ret) = customapi校对发送参数($def, $args);
        if (!$ok) {
            $errors[] = '第 ' . ($i + 1) . ' 行「' . $name . '」：' . $ret;
            continue;
        }
        $out[] = ['type' => $key, 'fn' => $def['fn'], 'args' => $ret];
    }
    return $out;
}

/** 参数补齐 + 必填校验 + JSON 校验 */
function customapi校对发送参数($def, $args) {
    $n        = count($def['fields']);
    $optional = $def['optional'] ?? [];
    $args     = array_slice((array)$args, 0, $n);
    $args     = array_pad($args, $n, '');

    foreach ($def['fields'] as $i => $name) {
        $val = trim((string)$args[$i]);
        if ($val === '' && !in_array($i, $optional, true)) {
            return [false, '缺少「' . $name . '」'];
        }
        $args[$i] = $val;
    }

    foreach (($def['json'] ?? []) as $i) {
        $val = trim((string)$args[$i]);
        if ($val === '') continue;
        $decoded = json_decode($val, true);
        if (!is_array($decoded)) {
            return [false, '「' . $def['fields'][$i] . '」不是合法的 JSON'];
        }
    }
    return [true, $args];
}

/** 真正调用框架函数；返回 [ok, error] */
function customapi调用发送($type, $args) {
    $types = customapi发送类型();
    if (!isset($types[$type])) return ['ok' => false, 'error' => '不支持的发送方式'];

    $fn = (string)$types[$type]['fn'];
    if ($fn === '' || !function_exists($fn)) {
        return ['ok' => false, 'error' => '当前框架没有 ' . ($fn !== '' ? $fn . '()' : '这个发送函数')];
    }

    $call = array_values((array)$args);
    foreach (($types[$type]['json'] ?? []) as $i) {
        $decoded = json_decode((string)($call[$i] ?? ''), true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => '「' . $types[$type]['fields'][$i] . '」JSON 解析失败'];
        }
        $call[$i] = $decoded;
    }

    try {
        $ret = call_user_func_array($fn, $call);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    // 框架函数失败时一般返回 {"code":400,"message":"..."}（字符串或数组）
    $decoded = is_array($ret) ? $ret : (is_string($ret) && $ret !== '' ? json_decode($ret, true) : null);
    if (is_array($decoded) && isset($decoded['code']) && (int)$decoded['code'] >= 400) {
        return ['ok' => false, 'error' => (string)($decoded['message'] ?? ('code ' . $decoded['code'])), 'result' => $ret];
    }
    if ($ret === false) {
        return ['ok' => false, 'error' => '发送函数返回失败'];
    }
    return ['ok' => true, 'error' => '', 'result' => $ret];
}

/**
 * 按配置发出去
 * @param array $api
 * @param array $result customapi执行() 的返回
 * @return array ['sent'=>成功条数, 'actions'=>解析出的动作, 'errors'=>问题]
 */
function customapi发送($api, $result) {
    $errors  = [];
    $sent    = 0;
    $actions = [];

    // 接口失败：不管配置的是图片还是文卡，都按文字发失败提示
    if (empty($result['ok'])) {
        $text = trim((string)($result['text'] ?? ''));
        if ($text !== '') {
            $r = customapi调用发送('text', [$text]);
            if ($r['ok']) $sent++;
            else $errors[] = $r['error'];
        }
        return ['sent' => $sent, 'actions' => [], 'errors' => $errors];
    }

    $actions = customapi解析发送($api, $result['vars'] ?? [], (string)($result['text'] ?? ''), $errors);
    foreach ($actions as $a) {
        $r = customapi调用发送($a['type'], $a['args']);
        if ($r['ok']) {
            $sent++;
        } else {
            $errors[] = customapi发送类型()[$a['type']]['label'] . '：' . $r['error'];
        }
    }

    // 一条都没发出去时给个明确回执：静默比报错更难排查
    if ($sent === 0 && !empty($errors) && ($api['reply_type'] ?? '') !== 'none') {
        $isAdmin = function_exists('是否管理员') ? 是否管理员() : false;
        $msg = $isAdmin
            ? '消息发送失败：' . implode('；', $errors)
            : '消息发送失败，请联系管理员检查「自定义 API」配置。';
        $msg = mb_substr($msg, 0, 300, 'UTF-8');
        $r = customapi调用发送('text', [$msg]);
        if ($r['ok']) $sent++;
    }

    return ['sent' => $sent, 'actions' => $actions, 'errors' => $errors];
}

/** 后台测试用：只解析、不发送，返回发送计划 */
function customapi预览发送($api, $result) {
    $errors  = [];
    $actions = customapi解析发送($api, $result['vars'] ?? [], (string)($result['text'] ?? ''), $errors);
    $types   = customapi发送类型();
    $out     = [];
    foreach ($actions as $a) {
        $args = [];
        foreach ($a['args'] as $v) {
            $v = (string)$v;
            if (mb_strlen($v, 'UTF-8') > 160) $v = mb_substr($v, 0, 160, 'UTF-8') . '…';
            $args[] = $v;
        }
        $out[] = [
            'type'  => $a['type'],
            'label' => $types[$a['type']]['label'] ?? $a['type'],
            'fn'    => $a['fn'],
            'args'  => $args,
        ];
    }
    return ['actions' => $out, 'errors' => $errors];
}

/** 当前是不是群场景（群聊消息 + 各类群事件） */
function customapi是群场景($type = null) {
    if ($type === null) $type = defined('消息来源') ? (string)消息来源 : '';
    return in_array($type, [
        '群聊', '加群', '退群', '群成员增加', '群成员移除',
        '入群申请', '群消息拒绝', '群消息接收',
    ], true);
}

/** 当前是不是私聊场景（私聊消息 + 好友事件） */
function customapi是私聊场景($type = null) {
    if ($type === null) $type = defined('消息来源') ? (string)消息来源 : '';
    return in_array($type, ['私聊', '好友增加', '好友删除'], true);
}

/**
 * 前台运行：范围 / 权限 / 限频 / 执行 / 发送
 * @return bool 是否处理了这条消息
 */
function customapi运行($api, $args = []) {
    if (!$api) return false;

    if ($api['scope'] === 'group' && !customapi是群场景()) return false;
    if ($api['scope'] === 'private' && !customapi是私聊场景()) return false;

    if ($api['permission'] === 'admin') {
        $isAdmin = function_exists('是否管理员') ? 是否管理员() : false;
        if (!$isAdmin) {
            if (function_exists('文字')) {
                文字('该指令仅机器人管理员可用。');
            }
            return true;
        }
    }

    // 不再按「人」限频：同一条消息重复触发由框架的事件去重负责，
    // 这里限频反而会吞掉用户 2 秒内连发的两次指令（例如连点两次「一言」）。

    try {
        $r = customapi执行($api, $args);
    } catch (Throwable $e) {
        if (function_exists('wlog')) {
            wlog(json_encode(['custom_api' => $api['name'], 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
                 defined('appid') ? appid : null);
        }
        if (function_exists('文字')) 文字('接口调用出错了，请稍后再试。');
        customapi写日志($api, [
            'http' => ['code' => 0, 'elapsed_ms' => 0],
            'errors' => [$e->getMessage()],
            'sent' => 0,
        ]);
        return true;
    }

    $send = customapi发送($api, $r);

    if (function_exists('wlog')) {
        wlog(json_encode([
            'custom_api' => $api['name'],
            'http'       => $r['http']['code'],
            'ms'         => $r['http']['elapsed_ms'],
            'sent'       => $send['sent'],
            'errors'     => $send['errors'],
        ], JSON_UNESCAPED_UNICODE), defined('appid') ? appid : null);
    }

    customapi写日志($api, [
        'http'   => $r['http'],
        'sent'   => $send['sent'],
        'errors' => $send['errors'],
    ]);

    return true;
}

/** 探测返回结构：给后台「探测」按钮用，列出可取的字段路径 */
function customapi探测($api, $args = []) {
    $vars = customapi基础变量($args, []);
    $req  = customapi构造请求($api, $vars);
    if (!preg_match('/^https?:\/\//i', $req['url'])) {
        return ['ok' => false, 'error' => '请求地址为空或不是 http(s) 开头'];
    }

    $r    = customapi_http($req['url'], $req['method'], $req['headers'], $req['body'], $api['timeout']);
    $json = json_decode($r['body'], true);
    $body = (string)$r['body'];

    $paths = [];
    if (is_array($json)) {
        $paths = customapi候选路径($json);
    }

    $type = 'text';
    if (strpos($body, '#!SILK') !== false) {
        $type = 'binary';
    } elseif (is_array($json)) {
        $type = 'json';
    } elseif (preg_match('/^https?:\/\//i', trim($body))) {
        $type = 'url';
    } elseif ($body === '') {
        $type = 'empty';
    }

    return [
        'ok'      => $r['code'] >= 200 && $r['code'] < 300,
        'type'    => $type,
        'request' => ['url' => $req['url'], 'method' => $req['method']],
        'http'    => [
            'code'       => $r['code'],
            'error'      => $r['error'],
            'bytes'      => $r['bytes'],
            'elapsed_ms' => $r['elapsed_ms'],
        ],
        'preview' => mb_substr($body, 0, 4000, 'UTF-8'),
        'json'    => is_array($json) ? $json : null,
        'paths'   => $paths,
        'error'   => $r['code'] >= 200 && $r['code'] < 300 ? '' : ($r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['code']),
    ];
}

/** 列出 JSON 里所有「像结果」的字符串字段，供后台点选 */
function customapi候选路径($data, $prefix = '', $depth = 0, &$out = null) {
    if ($out === null) $out = [];
    if ($depth > 6 || count($out) >= 40) return $out;

    if (!is_array($data)) return $out;

    foreach ($data as $k => $v) {
        $path = $prefix === '' ? (string)$k : $prefix . '.' . $k;
        if (is_array($v)) {
            customapi候选路径($v, $path, $depth + 1, $out);
            continue;
        }
        if (!is_string($v)) continue;

        $val = trim($v);
        if ($val === '') continue;

        $kind = '';
        if (preg_match('/^https?:\/\//i', $val)) {
            $kind = 'url';
        } elseif (preg_match('#^data:audio/#i', $val)) {
            $kind = 'base64';
        } elseif (strlen($val) > 120 && preg_match('/^[A-Za-z0-9+\/=]+$/', $val)) {
            $kind = 'base64';
        } elseif (mb_strlen($val, 'UTF-8') <= 600) {
            $kind = 'text';
        }
        if ($kind === '') continue;

        $out[] = [
            'path'   => $path,
            'kind'   => $kind,
            'sample' => mb_substr($val, 0, 100, 'UTF-8'),
        ];
    }
    return $out;
}

/* =========================================================================
 * 运行入口、自检与调用记录
 * ========================================================================= */

/**
 * 写入一条调用记录（只保留最近 300 条，避免库无限增长）
 * 后台「运行状态」和排查「机器人没回复」都靠它。
 */
function customapi写日志($api, $result) {
    try {
        customapi_ensure_table();
        $errors = $result['errors'] ?? [];
        if (is_array($errors)) $errors = implode('；', array_map('strval', $errors));
        db()->execute(
            "INSERT INTO customapi_logs (appid, api_id, api_name, trigger_type, trigger_text, http_code, elapsed_ms, sent, errors)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                defined('appid') ? (string)appid : '',
                (int)($api['id'] ?? 0),
                (string)($api['name'] ?? ''),
                (string)($api['trigger_type'] ?? 'message'),
                (string)($api['trigger'] ?? ''),
                (int)($result['http']['code'] ?? 0),
                (int)($result['http']['elapsed_ms'] ?? 0),
                (int)($result['sent'] ?? 0),
                mb_substr((string)$errors, 0, 1000, 'UTF-8'),
            ]
        );
        // 概率清理，避免每条消息都做一次 DELETE
        if (mt_rand(1, 20) === 1) {
            db()->execute("DELETE FROM customapi_logs WHERE id NOT IN (SELECT id FROM customapi_logs ORDER BY id DESC LIMIT 300)");
        }
    } catch (Throwable $e) {
        // 记录失败不影响正常回复
    }
}

/** 最近调用记录（后台展示） */
function customapi最近日志($limit = 10) {
    customapi_ensure_table();
    try {
        $rows = db()->fetchAll(
            "SELECT * FROM customapi_logs ORDER BY id DESC LIMIT " . max(1, min(50, (int)$limit))
        );
    } catch (Throwable $e) {
        return [];
    }
    return is_array($rows) ? $rows : [];
}

/**
 * 处理当前请求（事件 + 消息），同一请求只执行一次
 *
 * 插件文件和内核兜底都调用它：插件正常加载时由插件调用，
 * 文件缺失/加载失败时由内核补位，避免「后台配好了但机器人不说话」。
 *
 * @return bool 是否命中并处理了 API
 */
function customapi处理当前请求() {
    static $done = false;
    if ($done) return false;
    $done = true;

    $handled = false;

    // ---------- 事件触发 ----------
    $event = customapi当前事件();
    if ($event !== '') {
        foreach (customapi匹配事件全部($event) as $api) {
            customapi运行($api, ['全部' => '', '列表' => [], '完整' => '']);
            $handled = true;
        }
    }

    // ---------- 消息指令 ----------
    if (!defined('消息')) return $handled;
    $text = customapi清洗消息((string)消息);
    if ($text === '') return $handled;

    list($api, $args) = customapi匹配($text);
    if (!$api) return $handled;

    customapi运行($api, $args);
    return true;
}

/**
 * 内核兜底：插件文件缺失时也能工作；插件被显式禁用时尊重用户设置
 */
function customapi核心兜底() {
    if (!empty($GLOBALS['__customapi_plugin_loaded'])) return false;

    $file = rtrim(APP_ROOT, '/\\') . '/plugin/自定义API.php';
    if (is_file($file) && defined('plugin') && is_array(plugin) && empty(plugin['自定义API'])) {
        return false; // 用户在插件管理里明确关掉了
    }
    return customapi处理当前请求();
}

/** 插件文件状态 */
function customapi插件状态() {
    $file = rtrim(APP_ROOT, '/\\') . '/plugin/自定义API.php';
    $exists = is_file($file);
    $enabled = true;
    if (defined('plugin') && is_array(plugin)) {
        $enabled = !empty(plugin['自定义API']);
    }
    return [
        'exists'   => $exists,
        'enabled'  => $enabled,
        'writable' => is_dir(dirname($file)) && is_writable(dirname($file)) && (!$exists || is_writable($file)),
        'path'     => $file,
        'bytes'    => $exists ? (int)filesize($file) : 0,
    ];
}

/** 插件文件内容（重建用，与发布包中的文件保持一致） */
function customapi插件源码() {
    $tpl = <<<'PHP'
<?php
/**
 * @name 自定义API
 * @version 1.2.0
 * @author 月下独酌
 * @description 在后台「开发 → 自定义 API」里可视化配置：指令或事件触发 → 调用接口 → 从返回内容取值 → 用文字、图片、语音、文卡、Ark、按钮等函数发送。无需写代码，改完即时生效。
 * @category 开发
 * @license MIT
 */

require_once __DIR__ . '/../function/customapi.php';

// 标记插件已加载：内核兜底看到这个标记就不再重复执行
$GLOBALS['__customapi_plugin_loaded'] = true;

customapi处理当前请求();
PHP;

    return $tpl . "\n";
}

/**
 * 重建插件文件
 * @return array [bool, 提示]
 */
function customapi确保插件文件($overwrite = false) {
    $state = customapi插件状态();
    if ($state['exists'] && !$overwrite) return [true, '插件文件已存在'];

    $dir = dirname($state['path']);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) return [false, 'plugin 目录不存在且创建失败'];
    }
    if (!is_writable($dir)) return [false, 'plugin 目录不可写，请检查目录权限'];

    $ok = @file_put_contents($state['path'], customapi插件源码());
    if ($ok === false) return [false, '写入 plugin/自定义API.php 失败'];
    if (function_exists('opcache_invalidate')) @opcache_invalidate($state['path'], true);

    return [true, '已重建 plugin/自定义API.php'];
}

/** 在当前机器人下启用插件 */
function customapi插件启用($appid) {
    $appid = trim((string)$appid);
    if ($appid === '') return [false, '请选择机器人'];
    try {
        db()->execute("INSERT OR IGNORE INTO plugin_status (appid, plugin_name, enabled) VALUES (?, ?, 1)", [$appid, '自定义API']);
        db()->execute("UPDATE plugin_status SET enabled = 1 WHERE appid = ? AND plugin_name = ?", [$appid, '自定义API']);
        return [true, '已启用'];
    } catch (Throwable $e) {
        return [false, '启用失败：' . $e->getMessage()];
    }
}
