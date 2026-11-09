<?php
/**
 * 群成员管理 API
 * 对应 20260903 官方新增的群成员管理接口：
 *   获取群成员列表 / 获取群成员信息 / 群成员批量移除 / 群黑名单查询 / 群黑名单操作
 * 文档: https://bot.q.qq.com/wiki/develop/api-v2/changelog.html
 */
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');

// 捕获致命错误，返回 JSON（不使用ob_start，避免与json_response的缓冲区清理冲突）
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level() > 0) ob_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => 500, 'msg' => '致命错误: ' . $error['message'] . ' (文件: ' . basename($error['file']) . ' 行: ' . $error['line'] . ')'], JSON_UNESCAPED_UNICODE);
    }
});

require_once(__DIR__ . '/../../function.php');
require_once(__DIR__ . '/../../auth.php');

// 同源校验：拦截跨站 POST（与 SameSite Cookie 双重防护）
Auth::requireWriteProtection();

// 认证检查
if (!Auth::check()) {
    json_response(['code' => 401, 'msg' => '未登录或会话已过期'], 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * 解析成员 openid 列表参数: 支持 JSON 数组 / 逗号 / 换行 / 空格分隔
 * @param mixed $raw
 * @return array
 */
function parseOpenidList($raw) {
    if (is_array($raw)) return $raw;
    $raw = trim((string)$raw);
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) return $decoded;
    return preg_split('/[\s,，;；]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
}

/**
 * 注入 bot.php 运行上下文常量并引入 bot.php
 */
function initBotContext($bot) {
    if (!defined('appid')) define('appid', $bot['appid']);
    if (!defined('secret')) define('secret', $bot['secret']);
    if (!defined('type')) define('type', $bot['env']);
    require_once(__DIR__ . '/../../bot.php');
}

try {
    switch ($action) {

        // ==================== 获取机器人所在的群列表（本地数据库） ====================
        case 'get_groups':
            $appid = trim($_GET['appid'] ?? $_POST['appid'] ?? '');
            if (empty($appid)) {
                json_response(['code' => 400, 'msg' => '缺少 appid 参数']);
            }
            $groups = db()->fetchAll(
                "SELECT group_id, group_name, remark, group_member_num, last_active
                 FROM groups WHERE appid = ?
                 ORDER BY last_active DESC",
                [$appid]
            );
            $list = [];
            foreach ($groups as $g) {
                $list[] = [
                    'group_openid' => $g['group_id'],
                    'name'         => !empty($g['remark']) ? $g['remark'] : ($g['group_name'] ?: $g['group_id']),
                    'group_name'   => $g['group_name'],
                    'remark'       => $g['remark'],
                    'member_num'   => intval($g['group_member_num'] ?? 0),
                    'last_active'  => $g['last_active']
                ];
            }
            json_response(['code' => 0, 'msg' => 'success', 'success' => true, 'data' => ['groups' => $list]]);
            break;

        // ==================== 获取群成员列表 ====================
        case 'get_members':
            $appid       = trim($_GET['appid'] ?? $_POST['appid'] ?? '');
            $groupOpenid = trim($_GET['group_openid'] ?? $_POST['group_openid'] ?? $_POST['target_id'] ?? '');
            $cursor      = trim($_GET['cursor'] ?? $_POST['cursor'] ?? '');
            $fetchAll    = !empty($_GET['fetch_all'] ?? $_POST['fetch_all'] ?? '');

            if (empty($appid) || empty($groupOpenid)) {
                json_response(['code' => 400, 'msg' => '缺少 appid 或 group_openid 参数']);
            }

            $bot = getBot($appid);
            if (!$bot) {
                json_response(['code' => 404, 'msg' => '机器人不存在']);
            }
            initBotContext($bot);

            if ($fetchAll) {
                // 沿 next_cursor 自动拉取全部成员
                $resp = 获取全部群成员($groupOpenid);
                $data = json_decode($resp, true);
                if (!is_array($data)) {
                    json_response(['code' => 500, 'msg' => '接口返回格式错误', 'raw' => $resp]);
                }
                if (isset($data['code']) && $data['code'] != 0) {
                    json_response([
                        'code' => $data['code'],
                        'msg'  => '获取群成员列表失败: ' . ($data['message'] ?? '未知错误') . ($data['code'] == 11253 ? '（该接口处于内邀阶段，需向平台申请权限）' : ''),
                        'success' => false
                    ]);
                }
                json_response(['code' => 0, 'msg' => 'success', 'success' => true, 'data' => $data]);
            }

            $resp = 获取群成员列表($groupOpenid, $cursor);
            $data = json_decode($resp, true);
            if (!is_array($data)) {
                json_response(['code' => 500, 'msg' => '接口返回格式错误', 'raw' => $resp]);
            }
            if (isset($data['code']) && $data['code'] != 0) {
                json_response([
                    'code' => $data['code'],
                    'msg'  => '获取群成员列表失败: ' . ($data['message'] ?? '未知错误') . ($data['code'] == 11253 ? '（该接口处于内邀阶段，需向平台申请权限）' : ''),
                    'success' => false
                ]);
            }
            json_response(['code' => 0, 'msg' => 'success', 'success' => true, 'data' => $data]);
            break;

        // ==================== 获取群成员信息 ====================
        case 'get_member_info':
            $appid        = trim($_GET['appid'] ?? $_POST['appid'] ?? '');
            $groupOpenid  = trim($_GET['group_openid'] ?? $_POST['group_openid'] ?? $_POST['target_id'] ?? '');
            $memberOpenid = trim($_GET['member_openid'] ?? $_POST['member_openid'] ?? $_POST['user_id'] ?? '');

            if (empty($appid) || empty($groupOpenid) || empty($memberOpenid)) {
                json_response(['code' => 400, 'msg' => '缺少 appid、group_openid 或 member_openid 参数']);
            }

            $bot = getBot($appid);
            if (!$bot) {
                json_response(['code' => 404, 'msg' => '机器人不存在']);
            }
            initBotContext($bot);

            $resp = 获取群成员信息($groupOpenid, $memberOpenid);
            $data = json_decode($resp, true);
            if (!is_array($data)) {
                json_response(['code' => 500, 'msg' => '接口返回格式错误', 'raw' => $resp]);
            }
            if (isset($data['code']) && $data['code'] != 0) {
                json_response([
                    'code' => $data['code'],
                    'msg'  => '获取群成员信息失败: ' . ($data['message'] ?? '未知错误') . ($data['code'] == 11253 ? '（该接口处于内邀阶段，需向平台申请权限）' : ''),
                    'success' => false
                ]);
            }
            json_response(['code' => 0, 'msg' => 'success', 'success' => true, 'data' => $data]);
            break;

        // ==================== 群成员批量移除 ====================
        case 'batch_remove_members':
            $appid         = trim($_POST['appid'] ?? '');
            $groupOpenid   = trim($_POST['group_openid'] ?? $_POST['target_id'] ?? '');
            $memberOpenids = parseOpenidList($_POST['member_openids'] ?? $_POST['user_ids'] ?? '');
            $addToBlk      = !empty($_POST['add_to_blacklist'] ?? '');

            if (empty($appid) || empty($groupOpenid) || empty($memberOpenids) || !is_array($memberOpenids)) {
                json_response(['code' => 400, 'msg' => '缺少 appid、group_openid 或 member_openids 参数']);
            }

            $bot = getBot($appid);
            if (!$bot) {
                json_response(['code' => 404, 'msg' => '机器人不存在']);
            }
            initBotContext($bot);

            $resp = 群成员批量移除($groupOpenid, $memberOpenids, $addToBlk);
            $data = json_decode($resp, true);
            if (!is_array($data)) {
                json_response(['code' => 500, 'msg' => '接口返回格式错误', 'raw' => $resp]);
            }
            if (isset($data['code']) && $data['code'] != 0) {
                json_response([
                    'code' => $data['code'],
                    'msg'  => '批量移除失败: ' . ($data['message'] ?? '未知错误') . ($data['code'] == 11253 ? '（该接口处于内邀阶段，需向平台申请权限）' : ''),
                    'success' => false
                ]);
            }
            $failBlk = $data['add_to_member_blacklist_fail_openids'] ?? [];
            $msg = '批量移除操作已执行';
            if ($addToBlk && !empty($failBlk)) {
                $msg .= '，其中 ' . count($failBlk) . ' 个成员拉黑失败';
            }
            json_response(['code' => 0, 'msg' => $msg, 'success' => true, 'data' => $data]);
            break;

        // ==================== 群黑名单查询 ====================
        case 'get_blacklist':
            $appid       = trim($_GET['appid'] ?? $_POST['appid'] ?? '');
            $groupOpenid = trim($_GET['group_openid'] ?? $_POST['group_openid'] ?? $_POST['target_id'] ?? '');
            $cursor      = trim($_GET['cursor'] ?? $_POST['cursor'] ?? '');
            $limit       = intval($_GET['limit'] ?? $_POST['limit'] ?? 20);

            if (empty($appid) || empty($groupOpenid)) {
                json_response(['code' => 400, 'msg' => '缺少 appid 或 group_openid 参数']);
            }

            $bot = getBot($appid);
            if (!$bot) {
                json_response(['code' => 404, 'msg' => '机器人不存在']);
            }
            initBotContext($bot);

            $resp = 查询群黑名单($groupOpenid, $cursor, $limit);
            $data = json_decode($resp, true);
            if (!is_array($data)) {
                json_response(['code' => 500, 'msg' => '接口返回格式错误', 'raw' => $resp]);
            }
            if (isset($data['code']) && $data['code'] != 0) {
                json_response([
                    'code' => $data['code'],
                    'msg'  => '查询群黑名单失败: ' . ($data['message'] ?? '未知错误') . ($data['code'] == 11253 ? '（该接口处于内邀阶段，需向平台申请权限）' : ''),
                    'success' => false
                ]);
            }
            json_response(['code' => 0, 'msg' => 'success', 'success' => true, 'data' => $data]);
            break;

        // ==================== 群黑名单操作 ====================
        case 'blacklist_op':
            $appid        = trim($_POST['appid'] ?? '');
            $groupOpenid  = trim($_POST['group_openid'] ?? $_POST['target_id'] ?? '');
            $op           = trim($_POST['op'] ?? '');
            $memberOpenids = parseOpenidList($_POST['member_openids'] ?? $_POST['user_ids'] ?? '');

            if (empty($appid) || empty($groupOpenid) || empty($memberOpenids) || !is_array($memberOpenids)) {
                json_response(['code' => 400, 'msg' => '缺少 appid、group_openid 或 member_openids 参数']);
            }
            if (!in_array($op, ['add', 'del'], true)) {
                json_response(['code' => 400, 'msg' => 'op 仅支持 add(加入黑名单) / del(移出黑名单)']);
            }

            $bot = getBot($appid);
            if (!$bot) {
                json_response(['code' => 404, 'msg' => '机器人不存在']);
            }
            initBotContext($bot);

            $resp = 操作群黑名单($groupOpenid, $op, $memberOpenids);
            $data = json_decode($resp, true);
            if (!is_array($data)) {
                json_response(['code' => 500, 'msg' => '接口返回格式错误', 'raw' => $resp]);
            }
            if (isset($data['code']) && $data['code'] != 0) {
                json_response([
                    'code' => $data['code'],
                    'msg'  => '黑名单操作失败: ' . ($data['message'] ?? '未知错误') . ($data['code'] == 11253 ? '（该接口处于内邀阶段，需向平台申请权限）' : ''),
                    'success' => false
                ]);
            }
            $fail = $data['fail_openids'] ?? [];
            $msg = $op === 'add' ? '已加入群黑名单' : '已移出群黑名单';
            if (!empty($fail)) {
                $msg .= '，其中 ' . count($fail) . ' 个失败';
            }
            json_response(['code' => 0, 'msg' => $msg, 'success' => true, 'data' => $data]);
            break;

        // ==================== 入群申请列表（官方 20260810） ====================
        case 'join_requests':
            $appid = trim($_GET['appid'] ?? $_POST['appid'] ?? '');
            $groupOpenid = trim($_GET['group_openid'] ?? $_POST['group_openid'] ?? '');
            $cursor = trim($_GET['cursor'] ?? $_POST['cursor'] ?? '');
            $limit = min(50, max(1, intval($_GET['limit'] ?? $_POST['limit'] ?? 20)));
            if (empty($appid) || empty($groupOpenid)) {
                json_response(['code' => 400, 'msg' => '缺少 appid 或 group_openid']);
            }
            $bot = getBot($appid);
            if (!$bot) json_response(['code' => 404, 'msg' => '机器人不存在']);
            initBotContext($bot);
            $resp = 获取入群申请列表($groupOpenid, $cursor, $limit);
            $data = json_decode($resp, true);
            if (!is_array($data) || (isset($data['code']) && (int)$data['code'] !== 0)) {
                $code = is_array($data) ? (int)($data['code'] ?? 0) : 0;
                $msg = is_array($data) ? ($data['message'] ?? '获取入群申请失败') : '平台返回格式错误';
                if ($code === 11253) $msg .= '（该接口处于内邀阶段，需向平台申请权限）';
                json_response(['code' => 500, 'msg' => $msg, 'raw' => $resp]);
            }
            json_response([
                'code' => 0,
                'msg' => 'success',
                'success' => true,
                'data' => [
                    'list' => $data['list'] ?? [],
                    'next_cursor' => $data['next_cursor'] ?? '',
                ],
            ]);
            break;

        // ==================== 审批入群申请（官方 20260810） ====================
        case 'join_approve':
            $appid = trim($_POST['appid'] ?? '');
            $groupOpenid = trim($_POST['group_openid'] ?? '');
            $memberOpenid = trim($_POST['member_openid'] ?? '');
            $op = trim($_POST['op'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            $blacklist = !empty($_POST['blacklist']) && $_POST['blacklist'] !== '0';
            $joinRequestId = trim($_POST['join_request_id'] ?? '');
            if (empty($appid) || empty($groupOpenid) || empty($memberOpenid)) {
                json_response(['code' => 400, 'msg' => '缺少 appid / group_openid / member_openid']);
            }
            if (!in_array($op, ['approve', 'decline'], true)) {
                json_response(['code' => 400, 'msg' => 'op 仅支持 approve（通过）或 decline（拒绝）']);
            }
            if (mb_strlen($reason, 'UTF-8') > 200) {
                json_response(['code' => 400, 'msg' => '拒绝理由最多 200 个字']);
            }
            $bot = getBot($appid);
            if (!$bot) json_response(['code' => 404, 'msg' => '机器人不存在']);
            initBotContext($bot);
            $resp = 处理入群申请($groupOpenid, $memberOpenid, $op === 'approve', $reason, $blacklist, $joinRequestId);
            $data = json_decode($resp, true);
            if (is_array($data) && isset($data['code']) && (int)$data['code'] !== 0) {
                $code = (int)$data['code'];
                $msg = (string)($data['message'] ?? '审批失败');
                if ($code === 11253) $msg .= '（该接口处于内邀阶段，需向平台申请权限）';
                json_response(['code' => 500, 'msg' => $msg, 'raw' => $resp]);
            }
            后台操作记录($op === 'approve' ? '通过入群申请' : '拒绝入群申请', $groupOpenid . ' / ' . $memberOpenid, $appid);
            json_response(['code' => 0, 'msg' => $op === 'approve' ? '已通过入群申请' : '已拒绝入群申请', 'success' => true]);
            break;

        default:
            json_response(['code' => 400, 'msg' => '未知操作类型: ' . htmlspecialchars($action)]);
    }
} catch (Exception $e) {
    json_response(['code' => 500, 'msg' => '服务器错误: ' . $e->getMessage()]);
} catch (Throwable $e) {
    json_response(['code' => 500, 'msg' => '服务器异常: ' . $e->getMessage()]);
}
