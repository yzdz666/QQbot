<?php
// 插件：通知事件
// 功能：监听 QQ 机器人生命周期与权限变更事件，发送结构化通知
// 覆盖官方事件（参照 https://bot.q.qq.com/wiki/develop/api-v2/server-inter/event/）：
//   1. 好友事件: FRIEND_ADD(添加好友) / FRIEND_DEL(删除好友)
//   2. C2C主动消息开关: C2C_MSG_RECEIVE(开启) / C2C_MSG_REJECT(关闭)
//   3. 群消息开关: GROUP_MSG_RECEIVE(管理员开启) / GROUP_MSG_REJECT(管理员关闭)
//   4. 退群事件: GROUP_DEL_ROBOT(机器人被移出群)
//   5. 入群事件: GROUP_ADD_ROBOT(机器人被加入群) - 与"入群管理"插件去重
//   6. 订阅消息状态变更: SUBSCRIBE_MESSAGE_STATUS
//   7. 用户拒绝/接收消息时记录到管理员通知
//
// ⚠️ 这是事件监听插件，不处理文本指令，对所有用户生效（无需管理员权限）
// ⚠️ 与 "入群管理" 插件可能存在 GROUP_ADD_ROBOT 重叠，本插件仅在管理员私聊场景下通知，
//    不发送到原群聊（避免与"入群管理"插件重复发送）

// ==================== 仅处理事件类消息 ====================
if (!defined('消息来源')) return;

// ==================== 事件分发 ====================
switch (消息来源) {
    case '好友增加':
        _通知事件_好友增加();
        return;
    case '好友删除':
        _通知事件_好友删除();
        return;
    case '用户接收消息':
        _通知事件_用户开启主动消息();
        return;
    case '用户拒绝消息':
        _通知事件_用户关闭主动消息();
        return;
    case '群消息接收':
        _通知事件_群开启通知();
        return;
    case '群消息拒绝':
        _通知事件_群关闭通知();
        return;
    case '退群':
        _通知事件_机器人退群();
        return;
    case '订阅状态':
        _通知事件_订阅状态();
        return;
}

// ====================================================================
// 以下为内部实现函数（前缀 _通知事件_ 避免与其它插件冲突）
// ====================================================================

/**
 * 用户添加机器人为好友
 * 事件: FRIEND_ADD
 * 字段: timestamp, openid, scene, scene_param
 */
function _通知事件_好友增加() {
    $userId = defined('用户') ? 用户 : '';
    $raw = defined('raw') ? raw : [];
    $scene = $raw['d']['scene'] ?? '';
    $sceneText = _通知事件_场景映射($scene);
    $md  = "# ➕ 新好友添加\n\n";
    $md .= "**用户 openid**: `{$userId}`\n\n";
    if (!empty($sceneText)) $md .= "**来源场景**: {$sceneText}\n\n";
    $md .= "现在可以主动给该用户发消息了（每月 4 条上限）";
    MD($md);
}

/**
 * 用户删除机器人好友
 * 事件: FRIEND_DEL
 * 字段: timestamp, openid
 */
function _通知事件_好友删除() {
    $userId = defined('用户') ? 用户 : '';
    $md  = "# ➖ 好友删除\n\n";
    $md .= "**用户 openid**: `{$userId}`\n\n";
    $md .= "已无法主动给该用户发消息";
    MD($md);
}

/**
 * 用户开启主动消息推送
 * 事件: C2C_MSG_RECEIVE
 * 字段: timestamp, openid
 */
function _通知事件_用户开启主动消息() {
    $userId = defined('用户') ? 用户 : '';
    $md  = "# ✅ 用户开启主动消息\n\n";
    $md .= "**用户 openid**: `{$userId}`\n\n";
    $md .= "用户已在机器人资料卡开启主动消息推送";
    MD($md);
}

/**
 * 用户关闭主动消息推送
 * 事件: C2C_MSG_REJECT
 * 字段: timestamp, openid
 */
function _通知事件_用户关闭主动消息() {
    $userId = defined('用户') ? 用户 : '';
    $md  = "# 🚫 用户关闭主动消息\n\n";
    $md .= "**用户 openid**: `{$userId}`\n\n";
    $md .= "用户已在机器人资料卡关闭主动消息推送，后续主动消息将无法送达";
    MD($md);
}

/**
 * 群管理员开启群消息通知
 * 事件: GROUP_MSG_RECEIVE
 * 字段: timestamp, group_openid, op_member_openid
 */
function _通知事件_群开启通知() {
    $groupId = defined('来源') ? 来源 : '';
    $opUser = defined('用户') ? 用户 : '';
    $md  = "# ✅ 群消息通知已开启\n\n";
    $md .= "**群 openid**: `{$groupId}`\n\n";
    $md .= "**操作成员**: `{$opUser}`\n\n";
    $md .= "群管理员已在机器人资料页开启通知";
    MD($md);
}

/**
 * 群管理员关闭群消息通知
 * 事件: GROUP_MSG_REJECT
 * 字段: timestamp, group_openid, op_member_openid
 */
function _通知事件_群关闭通知() {
    $groupId = defined('来源') ? 来源 : '';
    $opUser = defined('用户') ? 用户 : '';
    $md  = "# 🚫 群消息通知已关闭\n\n";
    $md .= "**群 openid**: `{$groupId}`\n\n";
    $md .= "**操作成员**: `{$opUser}`\n\n";
    $md .= "群管理员已在机器人资料页关闭通知";
    MD($md);
}

/**
 * 机器人被移出群聊
 * 事件: GROUP_DEL_ROBOT
 * 字段: timestamp, group_openid, op_member_openid
 */
function _通知事件_机器人退群() {
    $groupId = defined('来源') ? 来源 : '';
    $opUser = defined('用户') ? 用户 : '';
    $md  = "# 👋 机器人被移出群聊\n\n";
    $md .= "**群 openid**: `{$groupId}`\n\n";
    $md .= "**操作成员**: `{$opUser}`";
    MD($md);
}

/**
 * 订阅消息状态变更
 * 事件: SUBSCRIBE_MESSAGE_STATUS
 * 字段: SubscribeMsgTemplateResult{template_id, custom_template_id, op}
 *   op: 1=允许订阅, 2=拒绝订阅
 */
function _通知事件_订阅状态() {
    $raw = defined('raw') ? raw : [];
    $tplId = $raw['d']['template_id'] ?? '';
    $customTplId = $raw['d']['custom_template_id'] ?? '';
    $op = $raw['d']['op'] ?? 0;
    $opText = $op == 1 ? '✅ 允许订阅' : ($op == 2 ? '🚫 拒绝订阅' : "未知状态({$op})");
    $targetId = defined('来源') ? 来源 : '';
    $userId = defined('用户') ? 用户 : '';
    $md  = "# 📨 订阅消息状态变更\n\n";
    $md .= "**模板 ID**: `{$tplId}`\n\n";
    $md .= "**自定义模板 ID**: `{$customTplId}`\n\n";
    $md .= "**状态**: {$opText}\n\n";
    if (!empty($userId)) $md .= "**用户 openid**: `{$userId}`\n\n";
    if (!empty($targetId)) $md .= "**关联对象**: `{$targetId}`";
    MD($md);
}

/**
 * 场景值映射（好友添加场景）
 */
function _通知事件_场景映射($scene) {
    $map = [
        '1000' => '缺省',
        '1001' => '网络搜索(全部tab)',
        '1002' => '网络搜索(机器人tab)',
        '1003' => '群场景',
        '1004' => '空间场景',
        '2001' => '站内分享资料页',
        '2002' => '站外分享资料页',
        '2003' => '开发者分享链接(站内)',
        '2004' => '开发者分享链接(站外)',
    ];
    $s = (string)$scene;
    return $map[$s] ?? '';
}
?>
