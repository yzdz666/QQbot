<?php
// ============================================================
// 插件：入群管理
// 功能：处理群成员入群相关事件，发送欢迎/通知消息
//
// 处理的事件：
//   入群申请 (GROUP_JOIN_REQUEST) - 有人申请入群时通知管理员
//   群成员增加 (GROUP_MEMBER_ADD) - 新成员入群时发送欢迎消息
//   加群       (GROUP_ADD_ROBOT)  - 机器人被邀请加入群聊时致谢
//
// 管理员命令（仅管理员可用，在群聊/私聊中响应）：
//   入群设置                  - 查看当前设置
//   入群设置 通知开关         - 开关入群通知
//   入群设置 欢迎语 内容      - 设置自定义欢迎语
//   入群设置 欢迎语 默认      - 恢复默认欢迎语
//   入群设置 管理员 添加 openid - 添加管理员
//   入群设置 管理员 删除 openid - 移除管理员
//   入群设置 管理员 列表      - 查看管理员列表
//   入群记录                  - 查看最近入群记录
//
// 数据存储命名空间：入群管理_<appid>
// ============================================================

// ==================== 管理员列表配置 ====================
// 在此填入管理员的 member_openid 或 user_openid
// 也可通过「入群设置 管理员 添加 openid」命令动态添加
$入群管理员默认 = ["1F3EF8A650E371CCAFB073A5E11E0752"];

// ==================== 数据存储辅助 ====================
$ns = "入群管理_" . appid;

/**
 * 读取入群管理配置
 */
function 入群_读配置($ns) {
    $config = 读($ns, "config", [
        'notify' => true,            // 是否通知管理员入群申请
        'welcome_msg' => '',         // 自定义欢迎语（空=默认）
        'admins' => [],              // 管理员列表
    ]);
    if (!isset($config['notify'])) $config['notify'] = true;
    if (!isset($config['welcome_msg'])) $config['welcome_msg'] = '';
    if (!isset($config['admins']) || !is_array($config['admins'])) $config['admins'] = [];
    return $config;
}

/**
 * 写入入群管理配置
 */
function 入群_写配置($ns, $config) {
    写($ns, "config", $config);
}

/**
 * 读取入群记录
 */
function 入群_读记录($ns) {
    $logs = 读($ns, "logs", []);
    return is_array($logs) ? $logs : [];
}

/**
 * 添加入群记录
 */
function 入群_添加记录($ns, $groupOpenid, $memberOpenid, $action) {
    $logs = 入群_读记录($ns);
    array_unshift($logs, [
        'time' => date('Y-m-d H:i:s'),
        'group' => $groupOpenid,
        'member' => $memberOpenid,
        'action' => $action,
    ]);
    // 最多保留 100 条记录
    $logs = array_slice($logs, 0, 100);
    写($ns, "logs", $logs);
}

/**
 * 检查是否是管理员
 */
function 入群_是否管理员($config, $userId, $defaultAdmins) {
    $admins = array_merge($config['admins'], $defaultAdmins);
    return in_array($userId, $admins);
}

// ==================== 处理入群申请事件 ====================
if (消息来源 == "入群申请") {
    $config = 入群_读配置($ns);
    $groupOpenid = 来源;
    $memberOpenid = 用户;

    // 获取原始事件数据中的字段
    $rawData = raw["d"] ?? [];
    $groupOpenid = $rawData["group_openid"] ?? $groupOpenid;
    $memberOpenid = $rawData["member_openid"] ?? $memberOpenid;

    if (empty($groupOpenid) || empty($memberOpenid)) {
        return;
    }

    // 记录入群申请
    入群_添加记录($ns, $groupOpenid, $memberOpenid, '申请入群');

    // 通知管理员（入群申请事件只能发送消息，无法通过API直接同意/拒绝）
    if ($config['notify']) {
        $shortId = substr($memberOpenid, 0, 16) . '...';
        $avatarUrl = 头像($memberOpenid);

        $md  = "# 📝 收到新的入群申请\n\n";
        $md .= "![#80px #80px]({$avatarUrl})\n\n";
        $md .= "**用户ID**: `{$shortId}`\n\n";
        $md .= "**时间**: " . date('Y-m-d H:i:s') . "\n\n";
        $md .= "---\n";
        $md .= "> 请管理员在QQ群设置中处理此申请\n\n";
        $md .= "> ⚠️ 入群申请事件不支持API直接同意/拒绝，需手动在QQ群中操作";

        MD($md);
    }
    return;
}

// ==================== 处理新成员入群事件 ====================
if (消息来源 == "群成员增加") {
    $config = 入群_读配置($ns);
    $memberOpenid = 用户;
    $groupOpenid = 来源;

    // 获取原始事件数据
    $rawData = raw["d"] ?? [];
    $memberOpenid = $rawData["member_openid"] ?? $memberOpenid;
    $groupOpenid = $rawData["group_openid"] ?? $groupOpenid;

    if (empty($memberOpenid)) {
        return;
    }

    // 记录入群
    入群_添加记录($ns, $groupOpenid, $memberOpenid, '入群');

    // 获取用户头像
    $avatarUrl = 头像($memberOpenid);

    // 构造欢迎消息
    if (!empty($config['welcome_msg'])) {
        // 使用自定义欢迎语
        $welcomeText = $config['welcome_msg'];
        $md = "# 👋 欢迎新成员加入！\n\n";
        $md .= "![#200px #200px]({$avatarUrl})\n\n";
        $md .= $welcomeText;
    } else {
        // 默认欢迎语
        $md  = "# 👋 欢迎新成员加入！\n\n";
        $md .= "![#200px #200px]({$avatarUrl})\n\n";
        $md .= "欢迎来到本群，请遵守群规～";
    }

    MD($md);
    return;
}

// ==================== 处理机器人加群事件 ====================
if (消息来源 == "加群") {
    $avatarUrl = 头像(用户);
    $md = "# 🎉 感谢邀请我加入群聊！\n\n";
    $md .= "![#200px #200px]({$avatarUrl})\n\n";
    $md .= "大家好，我是机器人，很高兴来到这里～";
    MD($md);
    return;
}

// ==================== 管理员命令处理 ====================
if (消息来源 == "群聊" || 消息来源 == "私聊") {
    $config = 入群_读配置($ns);
    $isAdmin = 入群_是否管理员($config, 用户, $入群管理员默认);

    // --- 入群设置 命令 ---
    if (消息 == "入群设置" || strpos(消息, "入群设置 ") === 0) {
        if (!$isAdmin) return;

        $args = trim(str_replace("入群设置", "", 消息));

        if (empty($args)) {
            // 显示当前设置
            $logs = 入群_读记录($ns);

            $settings  = "📋 入群管理设置\n\n";
            $settings .= "🔔 通知开关: " . ($config['notify'] ? '开启' : '关闭') . "\n";
            $settings .= "📝 欢迎语: " . (empty($config['welcome_msg']) ? '默认' : $config['welcome_msg']) . "\n";
            $settings .= "👥 管理员数: " . (count($config['admins']) + count($入群管理员默认)) . "\n";
            $settings .= "📊 总记录数: " . count($logs) . "\n\n";
            $settings .= "命令格式:\n";
            $settings .= "• 入群设置 通知开关\n";
            $settings .= "• 入群设置 欢迎语 [内容/默认]\n";
            $settings .= "• 入群设置 管理员 添加/删除/列表\n";
            $settings .= "• 入群记录";

            文字($settings);
            return;
        }

        $parts = explode(" ", $args, 2);
        $subCmd = $parts[0];
        $subArg = $parts[1] ?? "";

        switch ($subCmd) {
            case '通知开关':
            case '通知':
                $config['notify'] = !$config['notify'];
                入群_写配置($ns, $config);
                文字("🔔 入群通知已" . ($config['notify'] ? '开启' : '关闭'));
                return;

            case '欢迎语':
                if (empty($subArg)) {
                    $current = empty($config['welcome_msg']) ? '默认' : $config['welcome_msg'];
                    文字("📝 当前欢迎语:\n\n" . $current . "\n\n使用「入群设置 欢迎语 内容」修改\n使用「入群设置 欢迎语 默认」恢复默认");
                } elseif ($subArg == '默认' || $subArg == 'default') {
                    $config['welcome_msg'] = '';
                    入群_写配置($ns, $config);
                    文字("✅ 欢迎语已恢复为默认");
                } else {
                    $config['welcome_msg'] = $subArg;
                    入群_写配置($ns, $config);
                    文字("✅ 欢迎语已更新为:\n\n" . $subArg);
                }
                return;

            case '管理员':
                $subParts = explode(" ", $subArg, 2);
                $action = $subParts[0] ?? "";
                $openid = $subParts[1] ?? "";
                if ($action == "添加" && $openid) {
                    if (!in_array($openid, $config['admins'])) {
                        $config['admins'][] = $openid;
                        入群_写配置($ns, $config);
                        文字("✅ 已添加管理员: " . substr($openid, 0, 16) . "...");
                    } else {
                        文字("⚠️ 该用户已是管理员");
                    }
                } elseif ($action == "删除" && $openid) {
                    $config['admins'] = array_values(array_diff($config['admins'], [$openid]));
                    入群_写配置($ns, $config);
                    文字("✅ 已移除管理员: " . substr($openid, 0, 16) . "...");
                } elseif ($action == "列表" || empty($action)) {
                    $list = "👥 入群管理员列表\n\n";
                    $allAdmins = array_merge($config['admins'], $入群管理员默认);
                    $allAdmins = array_unique($allAdmins);
                    if (empty($allAdmins)) {
                        $list .= "暂无管理员";
                    } else {
                        foreach ($allAdmins as $i => $admin) {
                            $tag = in_array($admin, $入群管理员默认) ? ' (内置)' : '';
                            $list .= ($i + 1) . ". " . substr($admin, 0, 20) . "..." . $tag . "\n";
                        }
                    }
                    文字($list);
                }
                return;

            default:
                文字("❌ 未知设置项: " . $subCmd . "\n\n可用设置:\n• 通知开关\n• 欢迎语 [内容/默认]\n• 管理员 添加/删除/列表");
                return;
        }
    }

    // --- 入群记录 命令 ---
    if (消息 == "入群记录") {
        if (!$isAdmin) return;

        $logs = 入群_读记录($ns);
        $result = "📊 入群记录（最近" . min(count($logs), 20) . "条）\n\n";

        if (empty($logs)) {
            $result .= "暂无记录";
        } else {
            $display = array_slice($logs, 0, 20);
            foreach ($display as $i => $log) {
                $emoji = '📋';
                if ($log['action'] == '入群') $emoji = '✅';
                elseif ($log['action'] == '申请入群') $emoji = '📝';

                $result .= $emoji . " " . $log['time'] . "\n";
                $result .= "   用户: " . substr($log['member'], 0, 16) . "...\n";
                $result .= "   操作: " . $log['action'] . "\n";
                if ($i < count($display) - 1) $result .= "\n";
            }
            $result .= "\n共 " . count($logs) . " 条记录（最多保留100条）";
        }

        文字($result);
        return;
    }
}
?>
