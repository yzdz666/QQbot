<?php
/**
 * WebSocket 事件处理器 - 独立子进程处理单个事件
 *
 * 由 ws_client.php 通过后台 exec 调用
 * 对应 Python (ElainaBot_v2) 的 asyncio.create_task 事件分发机制
 *
 * 用法: php ws_event_handler.php <appid> <event_json>
 *
 * 每个事件在独立进程中处理, 确保:
 * - 常量隔离 (define 不会冲突, 每个进程全新)
 * - require_once 正常工作 (每个进程只加载一次)
 * - 插件错误不影响 WS 连接稳定性
 * - 内存自动回收 (进程结束即释放)
 */

if (php_sapi_name() !== 'cli') die('此脚本只能在命令行运行');

require_once(__DIR__ . '/function.php');

date_default_timezone_set('Asia/Shanghai');
set_time_limit(60); // 单个事件最多处理60秒

$appid = $argv[1] ?? '';
$eventJson = $argv[2] ?? '';

if (!$appid || !$eventJson) {
    fwrite(STDERR, "Usage: php ws_event_handler.php <appid> <event_json>\n");
    exit(1);
}

// 获取机器人配置
$bot = getBot($appid);
if (!$bot) {
    fwrite(STDERR, "机器人 {$appid} 不存在\n");
    exit(1);
}

// 解析事件 (WS payload: {op, s, t, id, d})
$raw = json_decode($eventJson, true);
if (!is_array($raw)) {
    fwrite(STDERR, "事件JSON解析失败\n");
    exit(1);
}

// ==================== 初始化全局常量 ====================
define('appid', $bot['appid']);
define('secret', $bot['secret']);
define('type', $bot['env']);

$eventType = $raw['t'] ?? '';
$d = $raw['d'] ?? [];

// ==================== 事件去重 (顶层id, 参照 index.php) ====================
$eventId = $raw['id'] ?? ($d['id'] ?? '');
if ($eventId && isEventProcessed($eventId)) {
    fwrite(STDOUT, "[" . date('Y-m-d H:i:s') . "] 事件 {$eventType}({$eventId}) 已处理, 跳过\n");
    exit(0);
}
if ($eventId) {
    markEventProcessed($eventId, $appid);
}

// 记录原始事件
wlog(json_encode($raw, JSON_UNESCAPED_UNICODE), $appid);

// 定义 raw 常量 (bot.php 中互动/私聊等函数需要)
define('raw', $raw);

// ==================== 解析事件类型并设置上下文 ====================
// 参照 Python Event.from_websocket + parsers/{group,direct,interaction,lifecycle}.py
// 参照 index.php Main() 函数
switch ($eventType) {
    case 'GROUP_AT_MESSAGE_CREATE':
    case 'GROUP_MESSAGE_CREATE':
        define('消息来源', '群聊');
        define('消息ID', $d['id'] ?? '');
        // 内容清洗: 去首尾空白 + 去@前缀 (参照 Python MessageUtils.sanitize_content)
        $content = trim($d['content'] ?? '', '/ ');
        $content = preg_replace('/<@!?[A-Za-z0-9]+>/', '', $content);
        $content = trim($content);
        define('消息', $content);
        // 参照 Python base.py: group_openid 优先, group_id 兜底
        define('来源', $d['group_openid'] ?? ($d['group_id'] ?? ''));
        define('用户', $d['author']['member_openid'] ?? ($d['author']['id'] ?? ''));
        break;

    case 'C2C_MESSAGE_CREATE':
        define('消息来源', '私聊');
        define('消息ID', $d['id'] ?? '');
        $content = trim($d['content'] ?? '', '/ ');
        define('消息', $content);
        define('来源', $d['author']['user_openid'] ?? ($d['author']['id'] ?? ''));
        define('用户', $d['author']['user_openid'] ?? ($d['author']['id'] ?? ''));
        break;

    case 'INTERACTION_CREATE':
        define('消息来源', '互动');
        // 官方文档: d.id 是"事件ID，用于被动消息发送和互动回调"
        // d.id 同时用于: 1.消息API的event_id  2.PUT /interactions/{id}的interaction_id
        // 注意: 顶层 raw.id 格式为 "INTERACTION_CREATE:{uuid}"，不能作为 event_id
        define('事件ID', $d['id'] ?? '');
        // 参照 Python InteractionParser: 根据 chat_type/scene 判断群聊/私聊
        $chatType = $d['chat_type'] ?? null;
        $scene = $d['scene'] ?? '';
        if ($chatType === 1 || $scene === 'group') {
            define('来源', $d['group_openid'] ?? ($d['group_id'] ?? ''));
            define('用户', $d['group_member_openid'] ?? ($d['author']['id'] ?? ''));
        } elseif ($chatType === 2 || $scene === 'c2c') {
            define('来源', $d['user_openid'] ?? '');
            define('用户', $d['user_openid'] ?? ($d['author']['id'] ?? ''));
        } else {
            $gid = $d['group_openid'] ?? ($d['group_id'] ?? '');
            define('来源', $gid);
            define('用户', $d['group_member_openid'] ?? ($d['user_openid'] ?? ($d['author']['id'] ?? '')));
        }
        // 互动内容: 参照 Python InteractionParser -> resolved.button_data
        $buttonData = $d['data']['resolved']['button_data'] ?? '';
        define('消息', $buttonData ?: '[互动]');
        break;

    case 'GROUP_ADD_ROBOT':
        // 参照 Python GroupAddRobotParser: op_member_openid
        define('消息来源', '加群');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[加群]');
        define('来源', $d['group_openid'] ?? '');
        define('用户', $d['op_member_openid'] ?? '');
        break;

    case 'GROUP_DEL_ROBOT':
        // 参照 Python GroupDelRobotParser: op_member_openid
        define('消息来源', '退群');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[退群]');
        define('来源', $d['group_openid'] ?? '');
        define('用户', $d['op_member_openid'] ?? '');
        break;

    case 'GROUP_MEMBER_ADD':
        // 参照 Python GroupMemberAddParser: member_openid
        define('消息来源', '群成员增加');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[群成员增加]');
        define('来源', $d['group_openid'] ?? '');
        define('用户', $d['member_openid'] ?? '');
        break;

    case 'GROUP_MEMBER_REMOVE':
        // 参照 Python GroupMemberRemoveParser: member_openid
        define('消息来源', '群成员移除');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[群成员移除]');
        define('来源', $d['group_openid'] ?? '');
        define('用户', $d['member_openid'] ?? '');
        break;

    case 'GROUP_JOIN_REQUEST':
        // 参照 Python GroupJoinRequestParser: 成员申请入群
        define('消息来源', '入群申请');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[入群申请]');
        define('来源', $d['group_openid'] ?? '');
        define('用户', $d['member_openid'] ?? '');
        break;

    case 'FRIEND_ADD':
        // 参照 Python FriendAddParser: openid, scene, scene_param
        define('消息来源', '好友增加');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[好友增加]');
        define('来源', $d['openid'] ?? '');
        define('用户', $d['openid'] ?? '');
        break;

    case 'FRIEND_DEL':
        // 参照 Python FriendDelParser: openid
        define('消息来源', '好友删除');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[好友删除]');
        define('来源', $d['openid'] ?? '');
        define('用户', $d['openid'] ?? '');
        break;

    case 'GROUP_MSG_REJECT':
        // 参照 Python GroupMsgRejectParser: 群聊拒绝主动消息
        define('消息来源', '群消息拒绝');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[群消息拒绝]');
        define('来源', $d['group_openid'] ?? '');
        define('用户', $d['op_member_openid'] ?? '');
        break;

    case 'GROUP_MSG_RECEIVE':
        // 参照 Python GroupMsgReceiveParser: 群聊接收主动消息
        define('消息来源', '群消息接收');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[群消息接收]');
        define('来源', $d['group_openid'] ?? '');
        define('用户', $d['op_member_openid'] ?? '');
        break;

    case 'SUBSCRIBE_MESSAGE_STATUS':
        // 参照 Python SubscribeStatusParser: 订阅消息状态
        define('消息来源', '订阅状态');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[订阅状态变更]');
        define('来源', $d['group_openid'] ?? ($d['openid'] ?? ''));
        define('用户', $d['openid'] ?? ($d['op_member_openid'] ?? ''));
        break;

    // ==================== 频道消息事件 (参照 Python ChannelMessageParser) ====================
    case 'AT_MESSAGE_CREATE':
        // 频道@消息 (公域)
        define('消息来源', '频道');
        define('消息ID', $d['id'] ?? '');
        $content = trim($d['content'] ?? '', '/ ');
        $content = preg_replace('/<@!?[A-Za-z0-9]+>/', '', $content);
        $content = trim($content);
        define('消息', $content);
        define('来源', $d['channel_id'] ?? ($d['guild_id'] ?? ''));
        define('用户', $d['author']['id'] ?? ($d['author']['member_openid'] ?? ''));
        break;

    case 'MESSAGE_CREATE':
        // 频道消息 (私域)
        define('消息来源', '频道');
        define('消息ID', $d['id'] ?? '');
        $content = trim($d['content'] ?? '', '/ ');
        define('消息', $content);
        define('来源', $d['channel_id'] ?? ($d['guild_id'] ?? ''));
        define('用户', $d['author']['id'] ?? '');
        break;

    case 'DIRECT_MESSAGE_CREATE':
        // 频道私信
        define('消息来源', '频道私信');
        define('消息ID', $d['id'] ?? '');
        $content = trim($d['content'] ?? '', '/ ');
        define('消息', $content);
        define('来源', $d['guild_id'] ?? ($d['channel_id'] ?? ''));
        define('用户', $d['author']['id'] ?? '');
        break;

    // ==================== 表态事件 (参照 Python SILENT_TYPES, 仅记录不分发) ====================
    case 'MESSAGE_REACTION_ADD':
        // 消息表情表态-添加 (静默事件)
        define('消息来源', '表情表态');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[表情表态添加]');
        define('来源', $d['channel_id'] ?? ($d['group_openid'] ?? ''));
        define('用户', $d['user_id'] ?? ($d['op_member_openid'] ?? ''));
        break;

    case 'MESSAGE_REACTION_REMOVE':
        // 消息表情表态-移除 (静默事件)
        define('消息来源', '表情表态');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[表情表态移除]');
        define('来源', $d['channel_id'] ?? ($d['group_openid'] ?? ''));
        define('用户', $d['user_id'] ?? ($d['op_member_openid'] ?? ''));
        break;

    case 'GUILD_UPDATE':
        // 频道信息更新 (静默事件)
        define('消息来源', '频道更新');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[频道信息更新]');
        define('来源', $d['id'] ?? '');
        define('用户', '');
        break;

    // ==================== 频道/子频道变动事件 (GUILDS, 参照官方文档) ====================
    case 'GUILD_CREATE':
        // 机器人加入频道
        define('消息来源', '频道创建');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[机器人加入频道]');
        define('来源', $d['id'] ?? '');
        define('用户', $d['owner_id'] ?? '');
        break;

    case 'GUILD_DELETE':
        // 机器人离开频道
        define('消息来源', '频道删除');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[机器人离开频道]');
        define('来源', $d['id'] ?? '');
        define('用户', $d['owner_id'] ?? '');
        break;

    case 'CHANNEL_CREATE':
        // 子频道创建
        define('消息来源', '子频道创建');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[子频道创建]');
        define('来源', $d['id'] ?? ($d['guild_id'] ?? ''));
        define('用户', '');
        break;

    case 'CHANNEL_UPDATE':
        // 子频道更新
        define('消息来源', '子频道更新');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[子频道更新]');
        define('来源', $d['id'] ?? ($d['guild_id'] ?? ''));
        define('用户', '');
        break;

    case 'CHANNEL_DELETE':
        // 子频道删除
        define('消息来源', '子频道删除');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[子频道删除]');
        define('来源', $d['id'] ?? ($d['guild_id'] ?? ''));
        define('用户', '');
        break;

    // ==================== 频道成员事件 (GUILD_MEMBERS) ====================
    case 'GUILD_MEMBER_ADD':
        // 频道成员加入
        define('消息来源', '频道成员增加');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[频道成员加入]');
        define('来源', $d['guild_id'] ?? '');
        define('用户', $d['user']['id'] ?? '');
        break;

    case 'GUILD_MEMBER_UPDATE':
        // 频道成员资料更新（含禁言/解禁状态变化）
        // d.user.id: 成员ID; d.guild_id: 频道ID; d.roles: 角色列表; d.mute_end_timestamp: 禁言结束时间戳
        define('消息来源', '频道成员更新');
        define('事件ID', $raw['id'] ?? '');
        $muteTs = $d['mute_end_timestamp'] ?? '0';
        define('消息', '[频道成员更新] 禁言结束:' . $muteTs);
        define('来源', $d['guild_id'] ?? '');
        define('用户', $d['user']['id'] ?? '');
        break;

    case 'GUILD_MEMBER_REMOVE':
        // 频道成员退出
        define('消息来源', '频道成员移除');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[频道成员退出]');
        define('来源', $d['guild_id'] ?? '');
        define('用户', $d['user']['id'] ?? '');
        break;

    // ==================== 消息删除事件 ====================
    case 'MESSAGE_DELETE':
        // 频道私域消息删除
        define('消息来源', '消息删除');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[消息删除]');
        define('来源', $d['channel_id'] ?? ($d['guild_id'] ?? ''));
        define('用户', $d['user_id'] ?? '');
        break;

    case 'PUBLIC_MESSAGE_DELETE':
        // 频道公域消息删除
        define('消息来源', '公域消息删除');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[公域消息删除]');
        define('来源', $d['channel_id'] ?? ($d['guild_id'] ?? ''));
        define('用户', $d['user_id'] ?? '');
        break;

    case 'DIRECT_MESSAGE_DELETE':
        // 频道私信删除
        define('消息来源', '私信删除');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[频道私信删除]');
        define('来源', $d['guild_id'] ?? ($d['channel_id'] ?? ''));
        define('用户', $d['user_id'] ?? '');
        break;

    // ==================== 消息审核事件 (MESSAGE_AUDIT) ====================
    case 'MESSAGE_AUDIT_PASS':
        // 消息审核通过
        define('消息来源', '消息审核通过');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[消息审核通过]');
        define('来源', $d['guild_id'] ?? ($d['channel_id'] ?? ''));
        define('用户', $d['user_id'] ?? '');
        break;

    case 'MESSAGE_AUDIT_REJECT':
        // 消息审核不通过
        define('消息来源', '消息审核拒绝');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[消息审核不通过]');
        define('来源', $d['guild_id'] ?? ($d['channel_id'] ?? ''));
        define('用户', $d['user_id'] ?? '');
        break;

    // ==================== 论坛事件 (FORUMS_EVENT, 公域) ====================
    case 'OPEN_FORUM_THREAD_CREATE':
        // 论坛主题创建
        define('消息来源', '论坛主题创建');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[论坛主题创建] ' . ($d['content'] ?? ''));
        define('来源', $d['guild_id'] ?? '');
        define('用户', $d['author_id'] ?? '');
        break;

    case 'OPEN_FORUM_THREAD_UPDATE':
        // 论坛主题更新
        define('消息来源', '论坛主题更新');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[论坛主题更新]');
        define('来源', $d['guild_id'] ?? '');
        define('用户', $d['author_id'] ?? '');
        break;

    case 'OPEN_FORUM_THREAD_DELETE':
        // 论坛主题删除
        define('消息来源', '论坛主题删除');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[论坛主题删除]');
        define('来源', $d['guild_id'] ?? '');
        define('用户', $d['author_id'] ?? '');
        break;

    case 'OPEN_FORUM_POST_CREATE':
        // 论坛回帖创建
        define('消息来源', '论坛回帖创建');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[论坛回帖创建] ' . ($d['content'] ?? ''));
        define('来源', $d['guild_id'] ?? '');
        define('用户', $d['author_id'] ?? '');
        break;

    case 'OPEN_FORUM_POST_DELETE':
        // 论坛回帖删除
        define('消息来源', '论坛回帖删除');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[论坛回帖删除]');
        define('来源', $d['guild_id'] ?? '');
        define('用户', $d['author_id'] ?? '');
        break;

    case 'OPEN_FORUM_REPLY_CREATE':
        // 论坛评论回复创建
        define('消息来源', '论坛评论创建');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[论坛评论回复创建] ' . ($d['content'] ?? ''));
        define('来源', $d['guild_id'] ?? '');
        define('用户', $d['author_id'] ?? '');
        break;

    case 'OPEN_FORUM_REPLY_DELETE':
        // 论坛评论回复删除
        define('消息来源', '论坛评论删除');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[论坛评论回复删除]');
        define('来源', $d['guild_id'] ?? '');
        define('用户', $d['author_id'] ?? '');
        break;

    // ==================== 音频/直播事件 (AUDIO_ACTION / AUDIO_OR_LIVE_CHANNEL_MEMBER) ====================
    case 'AUDIO_START':
        // 音频开始播放
        define('消息来源', '音频开始');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[音频开始播放]');
        define('来源', $d['guild_id'] ?? ($d['channel_id'] ?? ''));
        define('用户', '');
        break;

    case 'AUDIO_FINISH':
        // 音频播放结束
        define('消息来源', '音频结束');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[音频播放结束]');
        define('来源', $d['guild_id'] ?? ($d['channel_id'] ?? ''));
        define('用户', '');
        break;

    case 'AUDIO_ON_MIC':
        // 上麦
        define('消息来源', '上麦');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[成员上麦]');
        define('来源', $d['guild_id'] ?? ($d['channel_id'] ?? ''));
        define('用户', $d['user_id'] ?? '');
        break;

    case 'AUDIO_OFF_MIC':
        // 下麦
        define('消息来源', '下麦');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[成员下麦]');
        define('来源', $d['guild_id'] ?? ($d['channel_id'] ?? ''));
        define('用户', $d['user_id'] ?? '');
        break;

    case 'AUDIO_OR_LIVE_CHANNEL_MEMBER_ENTER':
        // 音频/直播频道成员进入
        define('消息来源', '音视频成员进入');
        define('事件ID', $raw['id'] ?? '');
        define('消息', '[音视频频道成员进入]');
        define('来源', $d['guild_id'] ?? ($d['channel_id'] ?? ''));
        define('用户', $d['user_id'] ?? '');
        break;

    default:
        fwrite(STDOUT, "[" . date('Y-m-d H:i:s') . "] 未处理的事件类型: {$eventType}\n");
        exit(0);
}

// ==================== 记录用户和群组 ====================
$userId = defined('用户') ? 用户 : '';
$targetId = defined('来源') ? 来源 : '';
$sourceType = 消息来源;
// 参照 index.php: 优先使用 author.username 作为昵称
$nickname = $d['author']['username'] ?? ($d['username'] ?? '');
if ($userId) recordUser($appid, $userId, $nickname);
if ($targetId && in_array($sourceType, ['群聊', '加群', '退群', '群成员增加', '群成员移除', '入群申请', '群消息拒绝', '群消息接收', '互动'])) {
    recordGroup($appid, $targetId);
}

// ==================== 记录消息到数据库 ====================
$content = defined('消息') ? 消息 : '';
// 解析附件，正确识别图片/视频/语音/文件类型和URL
$parsedMsg = parseMessageAttachment($raw);
$logContent = !empty($parsedMsg['content']) ? $parsedMsg['content'] : $content;
$logContentType = $parsedMsg['content_type'];
$isBotMsg = !empty($raw['d']['author']['bot']);
// 使用消息ID(d.id)而非事件ID(raw.id)进行匹配，与index.php保持一致
$msgId = $d['id'] ?? '';

// 如果是机器人自己发送的消息（webhook回传），更新已有的发送记录，不创建重复的接收记录
if ($isBotMsg && $msgId) {
    $existing = db()->fetch(
        "SELECT id FROM messages WHERE appid = ? AND message_id = ? AND direction = '发送' LIMIT 1",
        [$appid, $msgId]
    );
    if ($existing) {
        db()->execute(
            "UPDATE messages SET raw_data = ?, content = ?, content_type = ? WHERE id = ?",
            [json_encode($raw, JSON_UNESCAPED_UNICODE), $logContent, $logContentType, $existing['id']]
        );
        // 跳过插件处理，直接返回
        exit(0);
    }
}

logMessage($appid, '接收', $sourceType, $targetId, $logContentType, $logContent, $msgId, $userId, json_encode($raw, JSON_UNESCAPED_UNICODE));

// ==================== 获取插件配置 ====================
// 与 index.php initAppContext 一致: 默认启用所有存在的插件, 除非被显式禁用
$pluginConfig = [];
$pluginDir = APP_ROOT . 'plugin/';
$pluginFiles = is_dir($pluginDir) ? glob($pluginDir . '*.php') : [];
$disabledPlugins = [];
$allStatus = db()->fetchAll("SELECT plugin_name, enabled FROM plugin_status WHERE appid = ?", [$appid]);
foreach ($allStatus as $row) {
    if (intval($row['enabled']) === 0) {
        $disabledPlugins[$row['plugin_name']] = true;
    }
}
foreach ($pluginFiles as $file) {
    $pluginName = basename($file, '.php');
    if (!isset($disabledPlugins[$pluginName])) {
        $pluginConfig[$pluginName] = true;
    }
}
define('plugin', $pluginConfig);

// ==================== 加载 bot.php ====================
require __DIR__ . '/bot.php';

// ==================== 加载插件 ====================
// 注意: 互动ACK (PUT /interactions) 必须在插件发送消息之后执行,
//       因为 ACK 会"消费" interaction_id, 导致后续消息API的 event_id 失效
//       参照 ElainaBot_v2: ACK 由框架在插件分发结束后统一调用, 而非提前调用
if (is_dir($pluginDir)) {
    $all = glob($pluginDir . '*.php');
    foreach ($all as $name) {
        $plugin_name = basename($name, '.php');
        if (defined('plugin') && is_array(plugin) && isset(plugin[$plugin_name]) && plugin[$plugin_name]) {
            try {
                require_once($name);
            } catch (Throwable $e) {
                $error = json_encode([
                    "plat_error" => "[{$name}]运行出错: " . $e->getMessage() . " 行数:" . $e->getLine()
                ], JSON_UNESCAPED_UNICODE);
                wlog($error, $appid);
                continue;
            }
        }
    }
}

// ==================== 互动回调确认 (在插件执行完毕后调用) ====================
// 官方文档: 收到 INTERACTION_CREATE 事件后需调用 PUT /interactions/{interaction_id} 回应
// 否则客户端会一直 loading 直到超时 (显示"请求第三方失败")
// 必须在插件发送消息之后执行, 确保 event_id 在消息发送时仍然有效
// interaction_id = d.id = 事件ID, 与消息API的 event_id 使用同一个值
if ($eventType === 'INTERACTION_CREATE' && defined('事件ID') && 事件ID) {
    确认互动(事件ID);
}

fwrite(STDOUT, "[" . date('Y-m-d H:i:s') . "] [{$appid}] 事件 {$eventType} 处理完成\n");
exit(0);
