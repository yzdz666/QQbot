<?php
// ============================================================
// 插件：入群管理
// 功能：自动处理群成员入群申请（GROUP_JOIN_REQUEST 事件）
//
// 支持模式：
//   自动通过  - 所有入群申请自动同意
//   自动拒绝  - 所有入群申请自动拒绝
//   手动审核  - 通知管理员，等待手动同意/拒绝
//   白名单    - 仅白名单内用户自动通过，其余拒绝
//   黑名单    - 黑名单内用户拒绝，其余自动通过
//
// 管理员命令（仅管理员可用）：
//   入群设置                  - 查看当前设置
//   入群设置 自动通过         - 切换为自动通过模式
//   入群设置 自动拒绝         - 切换为自动拒绝模式
//   入群设置 手动审核         - 切换为手动审核模式
//   入群设置 白名单           - 切换为白名单模式
//   入群设置 黑名单           - 切换为黑名单模式
//   入群设置 通知开关         - 开关入群通知
//   入群白名单                - 查看白名单列表
//   入群白名单 添加 openid    - 添加白名单用户
//   入群白名单 删除 openid    - 移除白名单用户
//   入群黑名单                - 查看黑名单列表
//   入群黑名单 添加 openid    - 添加黑名单用户
//   入群黑名单 删除 openid    - 移除黑名单用户
//   入群记录                  - 查看最近入群记录
//   同意入群 openid           - 手动同意指定用户入群
//   拒绝入群 openid 理由      - 手动拒绝指定用户入群
//
// 数据存储命名空间：入群管理/<appid>
// ============================================================

// ==================== 管理员列表配置 ====================
// 在此填入管理员的 member_openid 或 user_openid
// 也可通过「入群设置 管理员 添加 openid」命令动态添加
$入群管理员默认 = [];

// ==================== 数据存储辅助 ====================
$ns = "入群管理_" . appid;

/**
 * 读取入群管理配置
 */
function 入群_读配置($ns) {
    $config = 读($ns, "config", [
        'mode' => '手动审核',        // 模式: 自动通过/自动拒绝/手动审核/白名单/黑名单
        'notify' => true,            // 是否通知管理员
        'admins' => [],              // 管理员列表
        'reject_reason' => '抱歉，您的入群申请未通过审核', // 默认拒绝理由
    ]);
    // 确保所有字段存在
    if (!isset($config['mode'])) $config['mode'] = '手动审核';
    if (!isset($config['notify'])) $config['notify'] = true;
    if (!isset($config['admins']) || !is_array($config['admins'])) $config['admins'] = [];
    if (!isset($config['reject_reason'])) $config['reject_reason'] = '抱歉，您的入群申请未通过审核';
    return $config;
}

/**
 * 写入入群管理配置
 */
function 入群_写配置($ns, $config) {
    写($ns, "config", $config);
}

/**
 * 读取白名单
 */
function 入群_读白名单($ns) {
    $list = 读($ns, "whitelist", []);
    return is_array($list) ? $list : [];
}

/**
 * 读取黑名单
 */
function 入群_读黑名单($ns) {
    $list = 读($ns, "blacklist", []);
    return is_array($list) ? $list : [];
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
function 入群_添加记录($ns, $groupOpenid, $memberOpenid, $action, $reason = '') {
    $logs = 入群_读记录($ns);
    array_unshift($logs, [
        'time' => date('Y-m-d H:i:s'),
        'group' => $groupOpenid,
        'member' => $memberOpenid,
        'action' => $action,
        'reason' => $reason,
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

/**
 * 发送通知给管理员
 */
function 入群_通知管理员($config, $defaultAdmins, $message) {
    if (!$config['notify']) return;
    $admins = array_merge($config['admins'], $defaultAdmins);
    // 管理员通知通过当前会话发送（因为入群申请事件本身就是群事件）
    // 如果有多个管理员，可以在群内发送通知
    // 这里直接在当前群发送通知消息
    文字($message);
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
        wlog('[入群管理] 缺少 group_openid 或 member_openid', appid);
        return;
    }

    $mode = $config['mode'];
    $approved = false;
    $rejectReason = '';
    $actionText = '';

    switch ($mode) {
        case '自动通过':
            $approved = true;
            $actionText = '自动通过';
            break;

        case '自动拒绝':
            $approved = false;
            $rejectReason = $config['reject_reason'];
            $actionText = '自动拒绝';
            break;

        case '白名单':
            $whitelist = 入群_读白名单($ns);
            if (in_array($memberOpenid, $whitelist)) {
                $approved = true;
                $actionText = '白名单通过';
            } else {
                $approved = false;
                $rejectReason = '您不在白名单内，入群申请被拒绝';
                $actionText = '白名单拒绝';
            }
            break;

        case '黑名单':
            $blacklist = 入群_读黑名单($ns);
            if (in_array($memberOpenid, $blacklist)) {
                $approved = false;
                $rejectReason = '您在黑名单内，入群申请被拒绝';
                $actionText = '黑名单拒绝';
            } else {
                $approved = true;
                $actionText = '黑名单通过';
            }
            break;

        case '手动审核':
        default:
            // 手动审核模式：通知管理员，记录待处理请求
            $pendingKey = "pending_" . $memberOpenid;
            $pendingData = [
                'group' => $groupOpenid,
                'member' => $memberOpenid,
                'time' => date('Y-m-d H:i:s'),
                'event_id' => 事件ID,
            ];
            写($ns, $pendingKey, $pendingData);

            $shortId = substr($memberOpenid, 0, 16) . '...';
            $notifyMsg = "📋 收到新的入群申请\n\n";
            $notifyMsg .= "👤 用户: " . $shortId . "\n";
            $notifyMsg .= "🕐 时间: " . date('Y-m-d H:i:s') . "\n";
            $notifyMsg .= "📌 状态: 等待审核\n\n";
            $notifyMsg .= "请管理员执行以下操作：\n";
            $notifyMsg .= "• 同意入群 " . $shortId . "\n";
            $notifyMsg .= "• 拒绝入群 " . $shortId . " 理由";

            入群_通知管理员($config, $入群管理员默认, $notifyMsg);
            入群_添加记录($ns, $groupOpenid, $memberOpenid, '待审核', '');
            return; // 手动模式不自动处理
    }

    // 调用 API 处理入群申请
    $result = 处理入群申请($groupOpenid, $memberOpenid, $approved, $rejectReason);
    $resultData = json_decode($result, true);

    // 判断处理结果
    $success = false;
    if ($resultData) {
        $code = $resultData['code'] ?? -1;
        // QQ API 成功时通常返回 code=0 或 HTTP 200 空响应
        if ($code == 0 || !isset($resultData['code']) || $code == 200) {
            $success = true;
        }
    } elseif (empty($result) || $result === 'null' || $result === '""') {
        // 空响应通常表示成功（HTTP 204）
        $success = true;
    }

    // 记录处理结果
    $logAction = $approved ? '通过' : '拒绝';
    if (!$success) {
        $logAction .= '(失败)';
    }
    入群_添加记录($ns, $groupOpenid, $memberOpenid, $logAction, $rejectReason);

    // 发送通知
    $shortId = substr($memberOpenid, 0, 16) . '...';
    $statusEmoji = $approved ? '✅' : '❌';
    $notifyMsg = "📋 入群申请处理结果\n\n";
    $notifyMsg .= "👤 用户: " . $shortId . "\n";
    $notifyMsg .= $statusEmoji . " 操作: " . ($approved ? '已通过' : '已拒绝') . "\n";
    $notifyMsg .= "🔧 模式: " . $mode . "\n";
    if (!$approved && !empty($rejectReason)) {
        $notifyMsg .= "📝 理由: " . $rejectReason . "\n";
    }
    if (!$success) {
        $notifyMsg .= "⚠️ API调用可能失败，请检查日志\n";
        $notifyMsg .= "响应: " . substr($result, 0, 200) . "\n";
    }

    入群_通知管理员($config, $入群管理员默认, $notifyMsg);
    return;
}

// ==================== 管理员命令处理 ====================
// 仅在群聊或私聊消息中响应管理员命令
if (消息来源 == "群聊" || 消息来源 == "私聊") {
    $config = 入群_读配置($ns);
    $isAdmin = 入群_是否管理员($config, 用户, $入群管理员默认);

    // --- 入群设置 命令 ---
    if (消息 == "入群设置" || strpos(消息, "入群设置 ") === 0) {
        if (!$isAdmin) return;

        $args = trim(str_replace("入群设置", "", 消息));

        if (empty($args)) {
            // 显示当前设置
            $whitelist = 入群_读白名单($ns);
            $blacklist = 入群_读黑名单($ns);
            $logs = 入群_读记录($ns);

            $settings = "📋 入群管理设置\n\n";
            $settings .= "🔧 当前模式: " . $config['mode'] . "\n";
            $settings .= "🔔 通知开关: " . ($config['notify'] ? '开启' : '关闭') . "\n";
            $settings .= "📝 拒绝理由: " . $config['reject_reason'] . "\n";
            $settings .= "👥 管理员数: " . count($config['admins']) . "\n";
            $settings .= "✅ 白名单数: " . count($whitelist) . "\n";
            $settings .= "❌ 黑名单数: " . count($blacklist) . "\n";
            $settings .= "📊 总记录数: " . count($logs) . "\n\n";
            $settings .= "可用模式:\n";
            $settings .= "• 自动通过 - 所有申请自动同意\n";
            $settings .= "• 自动拒绝 - 所有申请自动拒绝\n";
            $settings .= "• 手动审核 - 通知管理员手动处理\n";
            $settings .= "• 白名单   - 仅白名单用户通过\n";
            $settings .= "• 黑名单   - 黑名单用户拒绝，其余通过\n\n";
            $settings .= "命令格式:\n";
            $settings .= "• 入群设置 [模式名]\n";
            $settings .= "• 入群设置 通知开关\n";
            $settings .= "• 入群设置 拒绝理由 [理由]";

            文字($settings);
            return;
        }

        $parts = explode(" ", $args, 2);
        $subCmd = $parts[0];
        $subArg = $parts[1] ?? "";

        switch ($subCmd) {
            case '自动通过':
            case '自动同意':
                $config['mode'] = '自动通过';
                入群_写配置($ns, $config);
                文字("✅ 已切换为「自动通过」模式\n\n所有入群申请将自动同意");
                return;

            case '自动拒绝':
                $config['mode'] = '自动拒绝';
                入群_写配置($ns, $config);
                文字("❌ 已切换为「自动拒绝」模式\n\n所有入群申请将自动拒绝");
                return;

            case '手动审核':
            case '手动':
                $config['mode'] = '手动审核';
                入群_写配置($ns, $config);
                文字("📋 已切换为「手动审核」模式\n\n收到入群申请时将通知管理员手动处理");
                return;

            case '白名单':
                $config['mode'] = '白名单';
                入群_写配置($ns, $config);
                文字("✅ 已切换为「白名单」模式\n\n仅白名单内用户可自动通过，其余拒绝\n使用「入群白名单 添加 openid」管理白名单");
                return;

            case '黑名单':
                $config['mode'] = '黑名单';
                入群_写配置($ns, $config);
                文字("✅ 已切换为「黑名单」模式\n\n黑名单内用户将被拒绝，其余自动通过\n使用「入群黑名单 添加 openid」管理黑名单");
                return;

            case '通知开关':
            case '通知':
                $config['notify'] = !$config['notify'];
                入群_写配置($ns, $config);
                文字("🔔 入群通知已" . ($config['notify'] ? '开启' : '关闭'));
                return;

            case '拒绝理由':
                if (!empty($subArg)) {
                    $config['reject_reason'] = $subArg;
                    入群_写配置($ns, $config);
                    文字("📝 拒绝理由已更新为:\n\n" . $subArg);
                } else {
                    文字("📝 当前拒绝理由:\n\n" . $config['reject_reason'] . "\n\n使用「入群设置 拒绝理由 新理由」修改");
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
                    if (empty($config['admins'])) {
                        $list .= "暂无管理员（使用「入群设置 管理员 添加 openid」添加）";
                    } else {
                        foreach ($config['admins'] as $i => $admin) {
                            $list .= ($i + 1) . ". " . substr($admin, 0, 20) . "...\n";
                        }
                    }
                    文字($list);
                }
                return;

            default:
                文字("❌ 未知设置项: " . $subCmd . "\n\n可用设置:\n• 自动通过/自动拒绝/手动审核/白名单/黑名单\n• 通知开关\n• 拒绝理由 [理由]\n• 管理员 添加/删除/列表");
                return;
        }
    }

    // --- 入群白名单 命令 ---
    if (消息 == "入群白名单" || strpos(消息, "入群白名单 ") === 0) {
        if (!$isAdmin) return;

        $args = trim(str_replace("入群白名单", "", 消息));
        $whitelist = 入群_读白名单($ns);

        if (empty($args)) {
            $list = "✅ 入群白名单\n\n";
            if (empty($whitelist)) {
                $list .= "白名单为空\n\n使用「入群白名单 添加 openid」添加";
            } else {
                foreach ($whitelist as $i => $openid) {
                    $list .= ($i + 1) . ". " . substr($openid, 0, 20) . "...\n";
                }
                $list .= "\n共 " . count($whitelist) . " 人";
            }
            文字($list);
            return;
        }

        $parts = explode(" ", $args, 2);
        $action = $parts[0];
        $openid = $parts[1] ?? "";

        if ($action == "添加" && $openid) {
            if (!in_array($openid, $whitelist)) {
                $whitelist[] = $openid;
                写($ns, "whitelist", $whitelist);
                文字("✅ 已添加白名单: " . substr($openid, 0, 16) . "...");
            } else {
                文字("⚠️ 该用户已在白名单中");
            }
        } elseif ($action == "删除" && $openid) {
            $whitelist = array_values(array_diff($whitelist, [$openid]));
            写($ns, "whitelist", $whitelist);
            文字("✅ 已移除白名单: " . substr($openid, 0, 16) . "...");
        } else {
            文字("❌ 格式: 入群白名单 添加/删除 openid");
        }
        return;
    }

    // --- 入群黑名单 命令 ---
    if (消息 == "入群黑名单" || strpos(消息, "入群黑名单 ") === 0) {
        if (!$isAdmin) return;

        $args = trim(str_replace("入群黑名单", "", 消息));
        $blacklist = 入群_读黑名单($ns);

        if (empty($args)) {
            $list = "❌ 入群黑名单\n\n";
            if (empty($blacklist)) {
                $list .= "黑名单为空\n\n使用「入群黑名单 添加 openid」添加";
            } else {
                foreach ($blacklist as $i => $openid) {
                    $list .= ($i + 1) . ". " . substr($openid, 0, 20) . "...\n";
                }
                $list .= "\n共 " . count($blacklist) . " 人";
            }
            文字($list);
            return;
        }

        $parts = explode(" ", $args, 2);
        $action = $parts[0];
        $openid = $parts[1] ?? "";

        if ($action == "添加" && $openid) {
            if (!in_array($openid, $blacklist)) {
                $blacklist[] = $openid;
                写($ns, "blacklist", $blacklist);
                文字("✅ 已添加黑名单: " . substr($openid, 0, 16) . "...");
            } else {
                文字("⚠️ 该用户已在黑名单中");
            }
        } elseif ($action == "删除" && $openid) {
            $blacklist = array_values(array_diff($blacklist, [$openid]));
            写($ns, "blacklist", $blacklist);
            文字("✅ 已移除黑名单: " . substr($openid, 0, 16) . "...");
        } else {
            文字("❌ 格式: 入群黑名单 添加/删除 openid");
        }
        return;
    }

    // --- 入群记录 命令 ---
    if (消息 == "入群记录") {
        if (!$isAdmin) return;

        $logs = 入群_读记录($ns);
        $result = "📊 入群申请记录（最近" . min(count($logs), 20) . "条）\n\n";

        if (empty($logs)) {
            $result .= "暂无记录";
        } else {
            $display = array_slice($logs, 0, 20);
            foreach ($display as $i => $log) {
                $emoji = '📋';
                if ($log['action'] == '通过') $emoji = '✅';
                elseif ($log['action'] == '拒绝') $emoji = '❌';
                elseif ($log['action'] == '待审核') $emoji = '⏳';
                elseif (strpos($log['action'], '失败') !== false) $emoji = '⚠️';

                $result .= $emoji . " " . $log['time'] . "\n";
                $result .= "   用户: " . substr($log['member'], 0, 16) . "...\n";
                $result .= "   操作: " . $log['action'];
                if (!empty($log['reason'])) {
                    $result .= " (" . $log['reason'] . ")";
                }
                $result .= "\n";
                if ($i < count($display) - 1) $result .= "\n";
            }
            $result .= "\n共 " . count($logs) . " 条记录（最多保留100条）";
        }

        文字($result);
        return;
    }

    // --- 同意入群 命令 ---
    if (strpos(消息, "同意入群 ") === 0) {
        if (!$isAdmin) return;

        $memberOpenid = trim(str_replace("同意入群 ", "", 消息));
        if (empty($memberOpenid)) {
            文字("❌ 格式: 同意入群 openid");
            return;
        }

        // 尝试从待处理记录中获取 group_openid
        $pendingKey = "pending_" . $memberOpenid;
        $pending = 读($ns, $pendingKey, null);
        $groupOpenid = '';

        if ($pending && is_array($pending)) {
            $groupOpenid = $pending['group'] ?? 来源;
        } else {
            // 如果没有待处理记录，使用当前会话的来源
            $groupOpenid = 来源;
        }

        $result = 处理入群申请($groupOpenid, $memberOpenid, true);
        $resultData = json_decode($result, true);

        $success = false;
        if ($resultData) {
            $code = $resultData['code'] ?? -1;
            if ($code == 0 || !isset($resultData['code']) || $code == 200) {
                $success = true;
            }
        } elseif (empty($result) || $result === 'null' || $result === '""') {
            $success = true;
        }

        入群_添加记录($ns, $groupOpenid, $memberOpenid, $success ? '手动通过' : '手动通过(失败)', '');

        if ($success) {
            // 清除待处理记录
            删($ns, $pendingKey);
            文字("✅ 已同意用户入群\n\n👤 用户: " . substr($memberOpenid, 0, 16) . "...");
        } else {
            $errMsg = $resultData['message'] ?? $resultData['msg'] ?? '未知错误';
            文字("❌ 同意入群失败\n\n📝 错误: " . $errMsg . "\n\n响应: " . substr($result, 0, 200));
        }
        return;
    }

    // --- 拒绝入群 命令 ---
    if (strpos(消息, "拒绝入群 ") === 0) {
        if (!$isAdmin) return;

        $args = trim(str_replace("拒绝入群 ", "", 消息));
        $parts = explode(" ", $args, 2);
        $memberOpenid = $parts[0] ?? "";
        $reason = $parts[1] ?? $config['reject_reason'];

        if (empty($memberOpenid)) {
            文字("❌ 格式: 拒绝入群 openid [理由]");
            return;
        }

        // 尝试从待处理记录中获取 group_openid
        $pendingKey = "pending_" . $memberOpenid;
        $pending = 读($ns, $pendingKey, null);
        $groupOpenid = '';

        if ($pending && is_array($pending)) {
            $groupOpenid = $pending['group'] ?? 来源;
        } else {
            $groupOpenid = 来源;
        }

        $result = 处理入群申请($groupOpenid, $memberOpenid, false, $reason);
        $resultData = json_decode($result, true);

        $success = false;
        if ($resultData) {
            $code = $resultData['code'] ?? -1;
            if ($code == 0 || !isset($resultData['code']) || $code == 200) {
                $success = true;
            }
        } elseif (empty($result) || $result === 'null' || $result === '""') {
            $success = true;
        }

        入群_添加记录($ns, $groupOpenid, $memberOpenid, $success ? '手动拒绝' : '手动拒绝(失败)', $reason);

        if ($success) {
            删($ns, $pendingKey);
            文字("❌ 已拒绝用户入群\n\n👤 用户: " . substr($memberOpenid, 0, 16) . "...\n📝 理由: " . $reason);
        } else {
            $errMsg = $resultData['message'] ?? $resultData['msg'] ?? '未知错误';
            文字("❌ 拒绝入群失败\n\n📝 错误: " . $errMsg);
        }
        return;
    }
}
