<?php
/**
 * 管理后台 - 素材管理 API
 * 官方 20261021 新增：/v2/media-images/*
 *
 * 动作：
 *   list            查询素材列表（cursor 翻页）
 *   upload          小文件整包上传（≤1.5MB 时前端走这里）
 *   upload_chunk    大文件分片上传（避开 PHP upload_max_filesize 限制）
 *   upload_complete 合并分片并上传到平台
 *   delete          删除素材，释放 100 张配额
 *
 * 所有素材按 appid 隔离，接口只接受 bots 表中已存在的机器人。
 */
date_default_timezone_set('Asia/Shanghai');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_level() > 0) ob_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '致命错误: ' . $error['message']], JSON_UNESCAPED_UNICODE);
    }
});

require_once(__DIR__ . '/../../function.php');
require_once(__DIR__ . '/../../auth.php');

Auth::requireWriteProtection();

if (!Auth::isInstalled()) {
    json_response(['success' => false, 'message' => '系统未安装'], 503);
}
if (!Auth::check()) {
    json_response(['success' => false, 'message' => '未登录或会话已过期'], 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * 根据 appid 建立 bot.php 运行上下文（素材接口只需要 appid/secret/env）
 */
function material_boot_bot($appid) {
    $bot = getBot($appid);
    if (!$bot) {
        json_response(['success' => false, 'message' => '机器人不存在或已被删除']);
    }
    if (!defined('appid')) define('appid', $bot['appid']);
    if (!defined('secret')) define('secret', $bot['secret']);
    if (!defined('type')) define('type', $bot['env']);
    if (!defined('消息来源')) define('消息来源', '群聊');
    if (!defined('来源')) define('来源', '');
    if (!defined('消息ID')) define('消息ID', '');
    if (!defined('事件ID')) define('事件ID', '');
    require_once(__DIR__ . '/../../bot.php');
    return $bot;
}

/**
 * 检测图片 MIME，并归一化为平台接受的四种类型
 * @return array{type:string,ext:string}|null
 */
function material_image_mime($path) {
    $map = [
        'image/png'  => ['image/png', 'png'],
        'image/jpeg' => ['image/jpeg', 'jpg'],
        'image/jpg'  => ['image/jpeg', 'jpg'],
        'image/gif'  => ['image/gif', 'gif'],
        'image/webp' => ['image/webp', 'webp'],
    ];
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $fi ? finfo_file($fi, $path) : '';
        if ($fi) finfo_close($fi);
        if (is_string($detected)) $mime = strtolower($detected);
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = strtolower((string)mime_content_type($path));
    }
    if (!isset($map[$mime])) {
        // 部分环境会把 webp 识别为 application/octet-stream，用 getimagesize 兜底
        $info = @getimagesize($path);
        $imgMime = is_array($info) ? strtolower((string)($info['mime'] ?? '')) : '';
        if (isset($map[$imgMime])) $mime = $imgMime;
    }
    if (!isset($map[$mime])) return null;
    return ['type' => $map[$mime][0], 'ext' => $map[$mime][1]];
}

function material_clean_name($name) {
    $name = trim((string)$name);
    if ($name === '') return '';
    $name = basename(str_replace('\\', '/', $name));
    return mb_substr($name, 0, 120, 'UTF-8');
}

try {
    switch ($action) {
        // ==================== 素材列表 ====================
        case 'list':
            $appid = trim((string)($_GET['appid'] ?? $_POST['appid'] ?? ''));
            if ($appid === '') json_response(['success' => false, 'message' => '缺少 appid']);
            material_boot_bot($appid);
            $cursor = trim((string)($_GET['cursor'] ?? $_POST['cursor'] ?? ''));
            $res = 素材列表($cursor);
            if (empty($res['success'])) {
                json_response(['success' => false, 'message' => $res['message'] ?? '获取失败', 'code' => $res['code'] ?? -1]);
            }
            json_response([
                'success'     => true,
                'images'      => $res['images'],
                'next_cursor' => $res['next_cursor'],
                'total'       => $res['total'],
                'limit'       => 100,
            ]);
            break;

        // ==================== 整包上传（小图） ====================
        case 'upload': {
            $appid = trim((string)($_POST['appid'] ?? ''));
            if ($appid === '') json_response(['success' => false, 'message' => '缺少 appid']);
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $err = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
                $msg = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? '文件超过服务器 upload_max_filesize 限制，请使用分片上传'
                    : '文件上传失败（错误码 ' . (int)$err . '）';
                json_response(['success' => false, 'message' => $msg]);
            }
            $tmp = $_FILES['file']['tmp_name'];
            if (!is_uploaded_file($tmp)) json_response(['success' => false, 'message' => '非法上传']);
            $size = (int)filesize($tmp);
            if ($size <= 0) json_response(['success' => false, 'message' => '文件内容为空']);
            if ($size > 10 * 1024 * 1024) json_response(['success' => false, 'message' => '图片超过 10MB 上限']);

            $mime = material_image_mime($tmp);
            if ($mime === null) json_response(['success' => false, 'message' => '仅支持 png / jpeg / gif / webp 图片']);

            material_boot_bot($appid);
            $fileData = file_get_contents($tmp);
            $fileName = material_clean_name($_POST['file_name'] ?? ($_FILES['file']['name'] ?? 'image'));
            $res = 素材上传($fileData, $fileName, $mime['type']);
            if (empty($res['success'])) {
                json_response(['success' => false, 'message' => $res['message'] ?? '上传失败', 'code' => $res['code'] ?? -1]);
            }
            后台操作记录('上传素材', $res['file_name'] . ' (' . $res['image_id'] . ')', $appid);
            json_response(['success' => true, 'data' => $res]);
            break;
        }

        // ==================== 分片上传：写入单片 ====================
        case 'upload_chunk': {
            $appid = trim((string)($_POST['appid'] ?? ''));
            $appidSafe = preg_replace('/[^A-Za-z0-9_\-]/', '', $appid);
            $uploadId = trim((string)($_POST['upload_id'] ?? ''));
            $chunkIndex = intval($_POST['chunk_index'] ?? -1);
            $chunkTotal = intval($_POST['chunk_total'] ?? 0);
            if ($appidSafe === '' || !preg_match('/^[A-Za-z0-9]{16,64}$/', $uploadId)) {
                json_response(['success' => false, 'message' => '参数不合法']);
            }
            if ($chunkTotal < 1 || $chunkTotal > 64 || $chunkIndex < 0 || $chunkIndex >= $chunkTotal) {
                json_response(['success' => false, 'message' => '分片序号不合法']);
            }
            if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
                json_response(['success' => false, 'message' => '分片数据缺失或超过服务器 upload_max_filesize 限制']);
            }
            $tmpDir = __DIR__ . '/../../data/uploads/tmp';
            if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0755, true)) {
                json_response(['success' => false, 'message' => '无法创建临时目录']);
            }
            // 2% 概率清理 24 小时前的素材分片
            if (mt_rand(1, 50) <= 1) {
                foreach (glob($tmpDir . '/mat_*') ?: [] as $old) {
                    if (is_file($old) && filemtime($old) < time() - 86400) @unlink($old);
                }
            }
            $prefix = 'mat_' . $appidSafe . '_' . $uploadId . '.part_';
            $existingTotal = 0;
            foreach (glob($tmpDir . '/' . $prefix . '*') ?: [] as $pf) {
                if (is_file($pf)) $existingTotal += filesize($pf);
            }
            $incomingSize = (int)filesize($_FILES['chunk']['tmp_name']);
            if ($existingTotal + $incomingSize > 10 * 1024 * 1024) {
                json_response(['success' => false, 'message' => '图片超过 10MB 上限']);
            }
            $partFile = $tmpDir . '/' . $prefix . $chunkIndex;
            if (!@move_uploaded_file($_FILES['chunk']['tmp_name'], $partFile)) {
                json_response(['success' => false, 'message' => '分片写入失败']);
            }
            $received = count(glob($tmpDir . '/' . $prefix . '*') ?: []);
            json_response(['success' => true, 'received' => $received, 'chunk_total' => $chunkTotal]);
            break;
        }

        // ==================== 分片上传：合并并上传平台 ====================
        case 'upload_complete': {
            $appid = trim((string)($_POST['appid'] ?? ''));
            $appidSafe = preg_replace('/[^A-Za-z0-9_\-]/', '', $appid);
            $uploadId = trim((string)($_POST['upload_id'] ?? ''));
            $chunkTotal = intval($_POST['chunk_total'] ?? 0);
            $originalName = material_clean_name($_POST['file_name'] ?? 'image');
            if ($appidSafe === '' || !preg_match('/^[A-Za-z0-9]{16,64}$/', $uploadId)
                || $chunkTotal < 1 || $chunkTotal > 64) {
                json_response(['success' => false, 'message' => '缺少必要参数或参数不合法']);
            }
            $tmpDir = __DIR__ . '/../../data/uploads/tmp';
            $prefix = 'mat_' . $appidSafe . '_' . $uploadId . '.part_';
            $totalSize = 0;
            for ($i = 0; $i < $chunkTotal; $i++) {
                $p = $tmpDir . '/' . $prefix . $i;
                if (!is_file($p)) json_response(['success' => false, 'message' => "缺少分片 {$i}，请重新上传"]);
                $totalSize += filesize($p);
            }
            if ($totalSize <= 0) json_response(['success' => false, 'message' => '文件内容为空']);
            if ($totalSize > 10 * 1024 * 1024) {
                foreach (glob($tmpDir . '/' . $prefix . '*') ?: [] as $p) @unlink($p);
                json_response(['success' => false, 'message' => '图片超过 10MB 上限']);
            }

            $mergedPath = $tmpDir . '/mat_' . $appidSafe . '_' . $uploadId . '.merged';
            $out = @fopen($mergedPath, 'wb');
            if (!$out) json_response(['success' => false, 'message' => '合并临时文件创建失败']);
            $okMerge = true;
            for ($i = 0; $i < $chunkTotal; $i++) {
                $in = @fopen($tmpDir . '/' . $prefix . $i, 'rb');
                if (!$in) { $okMerge = false; break; }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
            fclose($out);
            if (!$okMerge || filesize($mergedPath) !== $totalSize) {
                @unlink($mergedPath);
                foreach (glob($tmpDir . '/' . $prefix . '*') ?: [] as $p) @unlink($p);
                json_response(['success' => false, 'message' => '分片合并失败，请重新上传']);
            }

            $mime = material_image_mime($mergedPath);
            if ($mime === null) {
                @unlink($mergedPath);
                foreach (glob($tmpDir . '/' . $prefix . '*') ?: [] as $p) @unlink($p);
                json_response(['success' => false, 'message' => '仅支持 png / jpeg / gif / webp 图片']);
            }

            $fileData = file_get_contents($mergedPath);
            @unlink($mergedPath);
            foreach (glob($tmpDir . '/' . $prefix . '*') ?: [] as $p) @unlink($p);

            material_boot_bot($appid);
            $res = 素材上传($fileData, $originalName, $mime['type']);
            if (empty($res['success'])) {
                json_response(['success' => false, 'message' => $res['message'] ?? '上传失败', 'code' => $res['code'] ?? -1]);
            }
            后台操作记录('上传素材', $res['file_name'] . ' (' . $res['image_id'] . ')', $appid);
            json_response(['success' => true, 'data' => $res]);
            break;
        }

        // ==================== 删除素材 ====================
        case 'delete': {
            $appid = trim((string)($_POST['appid'] ?? ''));
            $imageId = trim((string)($_POST['image_id'] ?? ''));
            if ($appid === '' || $imageId === '') json_response(['success' => false, 'message' => '缺少参数']);
            material_boot_bot($appid);
            $res = 素材删除($imageId);
            if (empty($res['success'])) {
                json_response(['success' => false, 'message' => $res['message'] ?? '删除失败', 'code' => $res['code'] ?? -1]);
            }
            后台操作记录('删除素材', $imageId, $appid);
            json_response(['success' => true, 'message' => '已删除']);
            break;
        }

        default:
            json_response(['success' => false, 'message' => '未知操作: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')]);
    }
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => '服务器错误: ' . $e->getMessage()]);
}
