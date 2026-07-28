<?php
// 插件：点歌系统（适配框架版）
// 命令格式：
//   点歌 <歌曲名称>     - 搜索歌曲列表
//   播放 <序号>         - 播放列表中第N首
// 支持群聊和私聊，按用户ID保存记录
// 数据存储使用框架的 写()/读() 函数，命名空间格式：点歌/<appid>
//
// ⚠️ 安全说明：默认使用的第三方API (jcy.meiaodai.xyz) 不可信，
//    建议替换为自有或可信任的音乐API。可通过定义 MUSIC_API_URL 常量覆盖。

// 音乐API基础地址（可在配置文件中定义 MUSIC_API_URL 常量覆盖）
$musicApiBase = defined('MUSIC_API_URL') ? MUSIC_API_URL : 'https://jcy.meiaodai.xyz/api/api/qsyy.php';

// mb_str_split 兼容层（PHP < 7.4 没有此函数）
if (!function_exists('mb_str_split')) {
    function mb_str_split($string, $split_length = 1, $encoding = 'UTF-8') {
        if ($split_length < 1) return false;
        $result = [];
        $length = mb_strlen($string, $encoding);
        for ($i = 0; $i < $length; $i += $split_length) {
            $result[] = mb_substr($string, $i, $split_length, $encoding);
        }
        return $result;
    }
}

// ==================== 点歌命令（搜索列表） ====================
if (strpos(消息, "点歌") === 0) {
    $keyword = trim(substr(消息, strlen("点歌")));
    if (empty($keyword)) {
        文字("🎵 请输入歌曲名称\n例如：点歌 仙逆");
        return;
    }

    // 调用搜索API（不带n参数，返回列表）
    $api_url = $musicApiBase . "?msg=" . urlencode($keyword);

    // 添加加载提示
    文字("⏳ 正在搜索《{$keyword}》相关歌曲...");

    $response = curl($api_url, "GET", [], '');

    if (!$response) {
        文字("❌ 歌曲查询失败，请稍后再试");
        return;
    }

    // 解析JSON响应
    $data = json_decode($response, true);

    if (!$data || $data['code'] != 200 || empty($data['data'])) {
        文字("❌ 未找到《{$keyword}》的相关歌曲");
        return;
    }

    $songs = $data['data'];

    // 保存搜索记录（按用户ID隔离，使用框架命名空间格式）
    // 注意：框架的 写() 会自动序列化数组，读() 会自动反序列化，无需手动 json_encode
    $history = [
        'user' => 用户,
        'source' => 来源,
        'song_name' => $keyword,
        'songs' => $songs,
        'timestamp' => time()
    ];
    写("点歌/" . appid, "history_" . 用户, $history);

    // 构建结果消息
    $result = "🎵 找到 " . count($songs) . " 首《{$keyword}》相关歌曲\n\n";
    $result .= "📋 歌曲列表：\n";

    // 显示所有歌曲（最多30条，避免消息过长）
    $display_count = min(30, count($songs));
    for ($i = 0; $i < $display_count; $i++) {
        $song = $songs[$i];
        $result .= "{$song['n']}. {$song['title']} - {$song['singer']}\n";
    }

    if (count($songs) > 30) {
        $result .= "\n... 共" . count($songs) . "首，仅显示前30首\n";
    }

    // 添加提示信息
    $result .= "\n💡 发送「播放 序号」播放对应歌曲\n例如：播放 3";

    // 发送结果
    文字($result);

    return;
}

// ==================== 播放命令（从历史记录中播放） ====================
if (strpos(消息, "播放") === 0) {
    $input = trim(substr(消息, strlen("播放")));

    if (!is_numeric($input)) {
        文字("🎵 使用方式: 播放 [序号]\n例如：播放 3");
        return;
    }

    $song_num = (int)$input;

    // 读取历史记录（按用户ID，使用框架命名空间格式）
    // 框架的 读() 会自动反序列化JSON为数组，兼容数组或JSON字符串
    $history_data = 读("点歌/" . appid, "history_" . 用户, '');

    if (empty($history_data)) {
        文字("❌ 请先使用「点歌」指令搜索歌曲");
        return;
    }

    // 兼容框架自动反序列化（数组）和手动JSON字符串两种情况
    $history = is_array($history_data) ? $history_data : json_decode($history_data, true);

    if (!$history || !isset($history['songs'])) {
        文字("❌ 歌曲记录已过期，请重新搜索");
        return;
    }

    // 查找对应歌曲
    $selected_song = null;
    foreach ($history['songs'] as $song) {
        if ($song['n'] == $song_num) {
            $selected_song = $song;
            break;
        }
    }

    if (!$selected_song) {
        文字("❌ 未找到序号为 {$song_num} 的歌曲");
        return;
    }

    // 调用获取详情API（带n参数，返回音频链接）
    $song_name = $selected_song['title'];
    $song_n = $selected_song['n'];
    $detail_url = $musicApiBase . "?msg=" . urlencode($song_name) . "&n=" . $song_n;

    // 添加加载提示
    文字("🔍 正在获取《{$selected_song['title']}》...");

    $response = curl($detail_url, "GET", [], '');

    if (!$response) {
        文字("❌ 音乐获取失败，请稍后再试");
        return;
    }

    // 解析JSON响应
    $detail = json_decode($response, true);

    if (!$detail || empty($detail['music'])) {
        文字("❌ 获取歌曲详情失败: " . ($detail['msg'] ?? "未知错误"));
        return;
    }

    $playUrl = $detail['music'];
    $coverUrl = $detail['cover'] ?? '';
    $lyrics = $detail['lrc'] ?? '';
    $payStatus = $detail['pay'] ?? 'free';
    $title = $detail['title'] ?? $selected_song['title'];
    $singer = $detail['singer'] ?? $selected_song['singer'];

    // 发送歌曲信息
    $music_info = "🎵 正在播放\n\n";
    $music_info .= "📌 标题: {$title}\n";
    $music_info .= "🎤 歌手: {$singer}\n";

    if ($payStatus == 'free') {
        $music_info .= "✅ 免费歌曲\n";
    } else {
        $music_info .= "🔒 付费歌曲（试听片段）\n";
    }

    if (!empty($detail['link'])) {
        $music_info .= "🔗 [完整链接]({$detail['link']})\n";
    }

    // 发送封面图片
    if (!empty($coverUrl)) {
        图片($coverUrl, $title . " - " . $singer);
    } else {
        文字($music_info);
    }

    // 发送音频
    if (!empty($playUrl)) {
        语音($playUrl);
    } else {
        文字("❌ 音频链接获取失败，请稍后再试");
    }

    // 发送歌词（如果有且不太长）
    if (!empty($lyrics)) {
        $lyric_text = "📝 歌词\n" . $lyrics;
        if (mb_strlen($lyric_text) > 800) {
            $parts = mb_str_split($lyric_text, 800);
            foreach ($parts as $part) {
                文字($part);
            }
        } else {
            文字($lyric_text);
        }
    }

    return;
}
?>
