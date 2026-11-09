<?php

// ==================== 日志记录函数（数据库 + 文件双写，与原版兼容） ====================

// 发送富媒体日志 → 结构化附件: 从日志类型+展示URL构造 attachments 数组 (聊天记录页回放媒体用)
// 展示URL支持 http(s) 绝对链与站内相对路径(api/chat_api.php?action=media&f=...); 二进制占位符([上传图片]等)无URL不构造
function 发送媒体附件结构($媒体类型, $displayUrl, $name = '') {
    $displayUrl = trim((string)$displayUrl, "`\t\n\r ");
    if ($displayUrl === '') return null;
    if (!preg_match('/^https?:\/\//i', $displayUrl) && $displayUrl[0] !== '/' && strpos($displayUrl, 'api/') !== 0) return null;
    $map = ['图片' => 'image', '语音' => 'voice', '视频' => 'video', '文件' => 'file'];
    $type = $map[$媒体类型] ?? 'file';
    return [
        ['type' => $type, 'url' => $displayUrl, 'wav_url' => '', 'asr_text' => '',
         'filename' => (string)$name, 'content_type' => $type,
         'width' => null, 'height' => null, 'size' => null]
    ];
}

// 发送日志 raw_data 增强: 把日志类型对应的展示URL注入 raw_data.d.attachments,
// 使后台聊天页/消息日志页无需解析文本即可渲染媒体。发送响应原有字段(id/ext_info等)全部保留。
function 发送日志富媒体原始数据($logType, $logContent, $rawData) {
    $map = ['图片' => '图片', '引用图片' => '图片', '引用图文' => '图片',
            '语音' => '语音', '引用语音' => '语音',
            '视频' => '视频', '引用视频' => '视频',
            '文件' => '文件', '引用文件' => '文件'];
    if (!isset($map[$logType])) return $rawData;
    $content = trim((string)$logContent);
    if ($content === '') return $rawData;
    // 展示URL = 首段(到第一个空白为止); 其余部分为配文(引用系以 "+ 配文" 拼接)
    if (!preg_match('/^(https?:\/\/\S+|api\/[^\s]+|\/[^\s]+)(?:[ \t]+(.+))?$/s', $content, $m)) {
        return $rawData;  // [上传图片]等占位符或纯文字: 无可回放URL
    }
    $url = rtrim($m[1], "`\t\n\r ");
    $att = 发送媒体附件结构($map[$logType], $url);
    if ($att === null) return $rawData;
    $data = json_decode((string)$rawData, true);
    if (!is_array($data)) $data = [];
    if (!isset($data['d']) || !is_array($data['d'])) $data['d'] = [];
    if (empty($data['d']['attachments'])) $data['d']['attachments'] = $att;
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function 记录发送($action, $target, $content, $type = "文字", $messageId = null, $rawData = null) {
    $sourceType = defined('消息来源') ? 消息来源 : 'unknown';
    $appidVal = defined('appid') ? appid : 'unknown';
    $userId = defined('用户') ? 用户 : null;

    // 富媒体/引用富媒体日志: 注入结构化附件, 后台可回看图片/语音/视频/文件
    $rawData = 发送日志富媒体原始数据($type, $content, $rawData);

    // 写入数据库
    logMessage($appidVal, '发送', $sourceType, $target, $type, $content, $messageId, $userId, $rawData);

    // 写入日志文件（与原版格式完全一致，通过 wlog 实现双写）
    $logEntry = [
        "direction" => "发送",
        "action" => $action,
        "source_type" => $sourceType,
        "target_id" => $target,
        "content_type" => $type,
        "content" => $content,
        "time" => date("Y-m-d H:i:s")
    ];
    if (!empty($messageId)) {
        $logEntry["id"] = $messageId;
    }
    wlog(json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function BOT凭证(){
       $time=读("function/".appid,"time",0);
       if (time() < $time) {
         return 读("function/".appid,"Access","");
       } else {
         $url=getApiTokenUrl();
         $appid=appid;
         $secret=secret;
         $json=json_encode([
         "appId"=>"{$appid}",
         "clientSecret"=>$secret
         ]);
         $header=['Content-Type: application/json'];
         $fw=curl($url,"POST",$header,$json);
         $fw=json_decode($fw,true);
         if (!isset($fw["access_token"])) {
            wlog("获取Access Token失败: " . json_encode($fw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), appid);
            return "";
         }
         $Access=$fw["access_token"];
         $time=$fw["expires_in"] ?? 7200;
         写("function/".appid,"time",time()+$time-60);
         写("function/".appid,"Access",$Access);
         return $Access;
      }
}


// 确保消息相关常量已定义（防止未定义错误）
if (!defined('消息ID')) define('消息ID', '');
if (!defined('事件ID')) define('事件ID', '');
if (!defined('消息来源')) define('消息来源', '');
if (!defined('来源')) define('来源', '');
if (!defined('用户')) define('用户', '');
// 引用ID: 本条消息的引用索引(REFIDX_xxx), 官方 message_reference.message_id 规范取值
// 正常由 ws_event_handler.php / index.php 从 message_scene.ext 的 msg_idx 定义; 兜底回退消息ID
if (!defined('引用ID')) define('引用ID', defined('消息ID') ? 消息ID : '');

function BOTAPI($Address,$me,$json){
    // 统一使用官方API域名 api.bot.qq.com（旧版域名已停用移除）
    $env = defined('type') ? type : '正式';
    if (!in_array($env, ['正式', '沙箱'], true)) $env = '正式';
    $url = getApiBase($env).$Address;
    $header = ["Authorization: QQBot ".BOT凭证(), 'Content-Type: application/json'];
    // 统一清理空的msg_id/event_id字段（主动消息不需要锚点，空值会导致invalid request）
    if (!empty($json) && is_string($json)) {
        $data = json_decode($json, true);
        if (is_array($data)) {
            $changed = false;
            if (isset($data['msg_id']) && $data['msg_id'] === '') { unset($data['msg_id']); $changed = true; }
            if (isset($data['event_id']) && $data['event_id'] === '') { unset($data['event_id']); $changed = true; }
            if ($changed) $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
    }
    $curl=curl($url,$me,$header,$json);
    return $curl;
}

function 文字($content) {
   switch (消息来源) {
     case "群聊":
        $json = json_encode([
        "content" => "{$content}",
        "msg_type" => 0,
        "msg_id" => 消息ID,
        "msg_seq" => rand(1,99999)
         ]);
         $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送文字", 来源, $content, "文字", $messageId, $resp);
         return $resp;
         break;
     case "私聊":
     case "好友增加":   // C2C生命周期事件, 使用私聊端点
     case "好友删除":
        $jsonData = [
        "content" => "{$content}",
        "msg_type" => 0,
        "msg_seq" => rand(1,99999)
         ];
         // 只有非空时才包含锚点ID（主动消息不需要锚点，空值会导致invalid request）
         $evId = defined('事件ID') ? 事件ID : '';
         $msgId = defined('消息ID') ? 消息ID : '';
         if (!empty($evId)) $jsonData["event_id"] = $evId;
         elseif (!empty($msgId)) $jsonData["msg_id"] = $msgId;
         $resp = BOTAPI("/v2/users/".来源."/messages","POST",json_encode($jsonData));
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送文字", 来源, $content, "文字", $messageId, $resp);
         return $resp;
         break;
     case "加群":
     case "退群":
     case "群成员增加":
     case "群成员移除":
     case "入群申请":   // 群生命周期事件, 使用群聊端点
     case "群消息拒绝":
     case "群消息接收":
     case "互动":
        $json = json_encode([
        "content" => "{$content}",
        "msg_type" => 0,
        "event_id" => 事件ID,
        "msg_seq" => rand(1,99999)
         ]);
         $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送文字", 来源, $content, "文字", $messageId, $resp);
         return $resp;
         break;
     case "文字子频道":
     case "频道":   // 频道消息
         $json = json_encode([
         "content" => $content,
         "msg_id" => 消息ID
         ]);
         $resp = BOTAPI("/channels/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送文字", 来源, $content, "文字", $messageId, $resp);
         return $resp;
         break;
     case "频道私信":   // 频道私信(DM)
         $json = json_encode([
         "content" => $content,
         "msg_id" => 消息ID
         ]);
         $resp = BOTAPI("/dms/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送文字", 来源, $content, "文字", $messageId, $resp);
         return $resp;
         break;
   }
}


function 富媒体($type,$image,$name = null) {
    $types = ["图片" => 1, "视频" => 2, "语音" => 3 , "文件" => 4];
    $t = $types[$type] ?? 1;
    if (preg_match('/^http(s)?:\/\//i', $image)) {
        $jsonData = [
            "file_type" => $t,
            "url" => $image,
            "file_name" => $name,
            "srv_send_msg" => false
        ];
    } else {
        $jsonData = [
            "file_type" => $t,
            "file_data" => base64_encode($image),
            "file_name" => $name,
            "srv_send_msg" => false
        ];
    }
    $json = json_encode($jsonData);
        switch (消息来源) {
           case "加群":
           case "退群":
           case "群成员增加":   // 新增
           case "群成员移除":   // 新增
           case "入群申请":   // 群生命周期事件
           case "群消息拒绝":
           case "群消息接收":
           case "群聊":
           case "互动":
               return json_decode(BOTAPI("/v2/groups/".来源."/files", "POST",$json),true);
               break;
           case "私聊":
           case "好友增加":   // C2C生命周期事件
           case "好友删除":
               return json_decode(BOTAPI("/v2/users/".来源."/files", "POST",$json),true);
               break;
        }
}


function 图片($image,$content=null,$logMedia=null) {
   // 记录实际图片URL，而非占位符；二进制数据用占位符代替
   // $logMedia: 展示URL覆盖(后台上传二进制时传入本地留存URL api/chat_api.php?action=media&f=...)
   $mediaDisp = ($logMedia !== null && trim((string)$logMedia) !== '') ? trim((string)$logMedia)
              : ((is_string($image) && preg_match('/^http/', $image)) ? $image : "[上传图片]");
   $logContent = $mediaDisp;
   if ($content !== null && trim((string)$content) !== '') $logContent = $logContent . " " . trim((string)$content);
   switch (消息来源) {
     case "群聊":
        $file_info =富媒体("图片",$image);
        if (isset($file_info['message'])) {
          return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
        "content" => $content !== null ? "\n{$content}" : "",
        "msg_type" => 7,
        "msg_id" => 消息ID,
        "msg_seq" => mt_rand(1, 9999),
        "media" => ["file_info" => $file]
        ]);
        $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送图片", 来源, $logContent, "图片", $messageId, $resp);
        return $resp;
        break;
     case "私聊":
     case "好友增加":   // C2C生命周期事件
     case "好友删除":
        $file_info =富媒体("图片",$image);
        if (isset($file_info['message'])) {
          return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
        "content" => "{$content}",
        "msg_type" => 7,
        "msg_id" => 消息ID,
        "msg_seq" => mt_rand(1, 9999),
        "media" => ["file_info" => $file]
        ]);
        $resp = BOTAPI("/v2/users/".来源."/messages","POST",$json);
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送图片", 来源, $logContent, "图片", $messageId, $resp);
        return $resp;
        break;
     case "加群":
     case "退群":
     case "群成员增加":   // 新增
     case "群成员移除":   // 新增
     case "入群申请":   // 群生命周期事件
     case "群消息拒绝":
     case "群消息接收":
     case "互动":
        $file_info =富媒体("图片",$image);
        if (isset($file_info['message'])) {
          return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
        "content" => "{$content}",
        "msg_type" => 7,
        "event_id" => 事件ID,
        "msg_seq" => mt_rand(1, 9999),
        "media" => ["file_info" => $file]
        ]);
        $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送图片", 来源, $logContent, "图片", $messageId, $resp);
        return $resp;
        break;
     case "文字子频道":
     case "频道":   // 频道消息
         $json = json_encode([
             "content" => $content,
             "file_image" => $image,
             "msg_id" => 消息ID
         ]);
         $resp = BOTAPI("/channels/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送图片", 来源, $logContent, "图片", $messageId, $resp);
         return $resp;
         break;
   }
}


function silk($link){
    $link = str_replace("&","%26",$link);
    $url = "https://oiapi.net/API/Mp32Silk?url=".$link;
    $r = json_decode(curl($url,"GET",[],''), true);
    return $r["message"] ?? '';
}

// ==================== 本地语音功能 ====================
function 本地语音($yy, $logMedia = null) {
   $mediaDisp = ($logMedia !== null && trim((string)$logMedia) !== '') ? trim((string)$logMedia)
              : ((is_string($yy) && preg_match('/^http/', $yy)) ? $yy : "[本地语音数据]");
   $logContent = $mediaDisp;
   switch (消息来源) {
     case "群聊":
        $file_info = 富媒体("语音",$yy);
        if (isset($file_info['message'])) {
         return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
          "msg_type" => 7,
          "msg_id" => 消息ID,
          "msg_seq" => mt_rand(1, 9999),
          "media" => ["file_info" => $file]
         ]);
         $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送本地语音", 来源, $logContent, "语音", $messageId, $resp);
         return $resp;
         break;
     case "私聊":
     case "好友增加":   // C2C生命周期事件
     case "好友删除":
       $file_info = 富媒体("语音",$yy);
         if (isset($file_info['message'])) {
         return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
          "msg_type" => 7,
          "msg_id" => 消息ID,
          "msg_seq" => mt_rand(1, 9999),
          "media" => ["file_info" => $file]
         ]);
         $resp = BOTAPI("/v2/users/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送本地语音", 来源, $logContent, "语音", $messageId, $resp);
         return $resp;
         break;
     case "加群":
     case "退群":
     case "群成员增加":   // 新增
     case "群成员移除":   // 新增
     case "入群申请":   // 群生命周期事件
     case "群消息拒绝":
     case "群消息接收":
     case "互动":
      $file_info = 富媒体("语音",$yy);
          if (isset($file_info['message'])) {
         return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
          "msg_type" => 7,
          "event_id" => 事件ID,
          "msg_seq" => mt_rand(1, 9999),
          "media" => ["file_info" => $file]
         ]);
         $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送本地语音", 来源, $logContent, "语音", $messageId, $resp);
         return $resp;
         break;
   }
}

function 语音($yy, $logMedia = null) {
   $logContent = ($logMedia !== null && trim((string)$logMedia) !== '') ? trim((string)$logMedia) : $yy;
   switch (消息来源) {
     case "群聊":
        $silk = silk($yy);
        $file_info = 富媒体("语音",$silk);
        if (isset($file_info['message'])) {
         return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
          "msg_type" => 7,
          "msg_id" => 消息ID,
          "msg_seq" => mt_rand(1, 9999),
          "media" => ["file_info" => $file]
         ]);
         $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送语音", 来源, $logContent, "语音", $messageId, $resp);
         return $resp;
         break;
     case "私聊":
     case "好友增加":   // C2C生命周期事件
     case "好友删除":
        $silk = silk($yy);
        $file_info = 富媒体("语音",$silk);
        if (isset($file_info['message'])) {
         return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
          "msg_type" => 7,
          "msg_id" => 消息ID,
          "msg_seq" => mt_rand(1, 9999),
          "media" => ["file_info" => $file]
         ]);
         $resp = BOTAPI("/v2/users/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送语音", 来源, $logContent, "语音", $messageId, $resp);
         return $resp;
         break;
     case "加群":
     case "退群":
     case "群成员增加":   // 新增
     case "群成员移除":   // 新增
     case "入群申请":   // 群生命周期事件
     case "群消息拒绝":
     case "群消息接收":
     case "互动":
        $silk = silk($yy);
        $file_info = 富媒体("语音",$silk);
        if (isset($file_info['message'])) {
         return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
          "msg_type" => 7,
          "event_id" => 事件ID,
          "msg_seq" => mt_rand(1, 9999),
          "media" => ["file_info" => $file]
         ]);
         $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送语音", 来源, $logContent, "语音", $messageId, $resp);
         return $resp;
         break;
   }
}

function 文件($yy, $nm = null, $logMedia = null) {
   $mediaDisp = ($logMedia !== null && trim((string)$logMedia) !== '') ? trim((string)$logMedia)
              : ((is_string($yy) && preg_match('/^http/', $yy)) ? $yy : "[上传文件:" . ($nm ?? 'file') . "]");
   $logContent = $mediaDisp;
   if ($nm !== null && trim((string)$nm) !== '' && strpos($mediaDisp, '[') !== 0) $logContent .= ' (' . $nm . ')';
    // 自动提取文件名（如果未提供）
    if ($nm === null) {
        $nm = 'file';
        $path = parse_url($yy, PHP_URL_PATH);
        if ($path) {
            $basename = basename($path);
            if ($basename && strpos($basename, '.') !== false) {
                $nm = $basename;
            }
        }
        // 去除查询参数（如 ?token=xxx）
        $nm = preg_replace('/\?.*$/', '', $nm);
        if (empty($nm)) $nm = 'file';
    }
    
   switch (消息来源) {
     case "群聊":
        $file_info = 富媒体("文件",$yy,$nm);
        if (isset($file_info['message'])) {
          return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
          "msg_type" => 7,
          "msg_id" => 消息ID,
          "msg_seq" => mt_rand(1, 9999),
          "media" => ["file_info" => $file]
         ]);
         $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送文件", 来源, $logContent, "文件", $messageId, $resp);
         return $resp;
         break;
     case "私聊":
     case "好友增加":   // C2C生命周期事件
     case "好友删除":
        $file_info = 富媒体("文件",$yy,$nm);
        if (isset($file_info['message'])) {
          return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
          "msg_type" => 7,
          "msg_id" => 消息ID,
          "msg_seq" => mt_rand(1, 9999),
          "media" => ["file_info" => $file]
         ]);
         $resp = BOTAPI("/v2/users/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送文件", 来源, $logContent, "文件", $messageId, $resp);
         return $resp;
         break;
     case "加群":
     case "退群":
     case "群成员增加":   // 新增
     case "群成员移除":   // 新增
     case "入群申请":   // 群生命周期事件
     case "群消息拒绝":
     case "群消息接收":
     case "互动":
        $file_info = 富媒体("文件",$yy,$nm);
        if (isset($file_info['message'])) {
          return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
          "msg_type" => 7,
          "event_id" => 事件ID,
          "msg_seq" => mt_rand(1, 9999),
          "media" => ["file_info" => $file]
         ]);
         $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送文件", 来源, $logContent, "文件", $messageId, $resp);
         return $resp;
         break;
   }
}


function 视频($video, $logMedia = null) {
   $mediaDisp = ($logMedia !== null && trim((string)$logMedia) !== '') ? trim((string)$logMedia)
              : ((is_string($video) && preg_match('/^http/', $video)) ? $video : "[上传视频]");
   $logContent = $mediaDisp;
   switch (消息来源) {
     case "群聊":
        $file_info =富媒体("视频",$video);
        if (isset($file_info['message'])) {
          return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
        "msg_type" => 7,
        "msg_id" => 消息ID,
        "msg_seq" => mt_rand(1, 9999),
        "media" => ["file_info" => $file]
        ]);
        $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送视频", 来源, $logContent, "视频", $messageId, $resp);
        return $resp;
        break;
     case "私聊":
     case "好友增加":   // C2C生命周期事件
     case "好友删除":
        $file_info =富媒体("视频",$video);
        if (isset($file_info['message'])) {
          return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
        "msg_type" => 7,
        "msg_id" => 消息ID,
        "msg_seq" => mt_rand(1, 9999),
        "media" => ["file_info" => $file]
        ]);
        $resp = BOTAPI("/v2/users/".来源."/messages","POST",$json);
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送视频", 来源, $logContent, "视频", $messageId, $resp);
        return $resp;
        break;
     case "加群":
     case "退群":
     case "群成员增加":   // 新增
     case "群成员移除":   // 新增
     case "入群申请":   // 群生命周期事件
     case "群消息拒绝":
     case "群消息接收":
     case "互动":
        $file_info =富媒体("视频",$video);
        if (isset($file_info['message'])) {
          return 文字($file_info['message']);
        }
        $file = $file_info['file_info'];
        $json = json_encode([
        "msg_type" => 7,
        "event_id" => 事件ID,
        "msg_seq" => mt_rand(1, 9999),
        "media" => ["file_info" => $file]
        ]);
        $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送视频", 来源, $logContent, "视频", $messageId, $resp);
        return $resp;
        break;
   }
}


function 按钮($key) {
   switch (消息来源) {
     case "群聊":
         $json = json_encode([
         "msg_type" => 2,
         "msg_id" => 消息ID,
         "msg_seq" => mt_rand(1, 9999),
         "keyboard" => [
           "id" => $key
           ]
         ]);
         $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送按钮", 来源, "[按钮ID: {$key}]", "按钮", $messageId, $resp);
         return $resp;
         break;
     case "私聊":
     case "好友增加":   // C2C生命周期事件
     case "好友删除":
        $json = json_encode([
         "msg_type" => 2,
         "msg_id" => 消息ID,
         "msg_seq" => mt_rand(1, 9999),
         "keyboard" => [
           "id" => $key
           ]
         ]);
         $resp = BOTAPI("/v2/users/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送按钮", 来源, "[按钮ID: {$key}]", "按钮", $messageId, $resp);
         return $resp;
         break;
     case "加群":
     case "退群":
     case "群成员增加":   // 新增
     case "群成员移除":   // 新增
     case "入群申请":   // 群生命周期事件
     case "群消息拒绝":
     case "群消息接收":
     case "互动":
        $json = json_encode([
         "msg_type" => 2,
         "event_id" => 事件ID,
         "msg_seq" => mt_rand(1, 9999),
         "keyboard" => [
           "id" => $key
           ]
         ]);
         $resp = BOTAPI("/v2/groups/".来源."/messages","POST",$json);
         $data = json_decode($resp, true);
         $messageId = $data['id'] ?? '';
         记录发送("发送按钮", 来源, "[按钮ID: {$key}]", "按钮", $messageId, $resp);
         return $resp;
         break;
   }
}

function 头像($id){
   return "https://q.qlogo.cn/qqapp/".appid."/{$id}/640";
}

function BOT信息(){
  return BOTAPI("/users/@me","GET",0);
}

function 文卡(...$items) {
    // 构建日志内容
    $itemTexts = [];
    foreach ($items as $item) {
        $itemTexts[] = $item['text'] ?? '[文本]';
    }
    
    $list_items = [];
    foreach ($items as $item) {
        if (isset($item['url'])) {
            $list_items[] = [
                "obj_kv" => [
                    ["key" => "desc", "value" => $item['text']],
                    ["key" => "link", "value" => $item['url']]
                ]
            ];
        } else {
            $list_items[] = [
                "obj_kv" => [
                    ["key" => "desc", "value" => $item['text']]
                ]
            ];
        }
    }
    $json = [
        "msg_type" => 3,
        "msg_seq" => mt_rand(1, 9999),
        "ark" => [
            "template_id" => 23,
            "kv" => [
                ["key" => "#DESC#", "value" => "忘了吧"],
                ["key" => "#PROMPT#", "value" => "忘了吧"],
                ["key" => "#LIST#", "obj" => $list_items]
            ]
        ]
    ];
    // 存储完整Ark卡片数据（JSON），供聊天界面渲染
    $wenkaLogData = ['template_id' => 23, 'kv' => $json['ark']['kv']];
    $wenkaLogJson = json_encode($wenkaLogData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    switch (消息来源) {
         case "群聊":
           $evId = defined("事件ID") ? 事件ID : "";
           $msgId = defined("消息ID") ? 消息ID : "";
           if (!empty($evId)) $json["event_id"] = $evId;
           elseif (!empty($msgId)) $json["msg_id"] = $msgId;
           $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($json));
           $data = json_decode($resp, true);
           $messageId = $data['id'] ?? '';
           记录发送("发送文卡", 来源, $wenkaLogJson, "Ark", $messageId, $resp);
           return $resp;
         break;
         case "私聊":
         case "好友增加":   // C2C生命周期事件
         case "好友删除":
           $evId = defined("事件ID") ? 事件ID : "";
           $msgId = defined("消息ID") ? 消息ID : "";
           if (!empty($evId)) $json["event_id"] = $evId;
           elseif (!empty($msgId)) $json["msg_id"] = $msgId;
           $resp = BOTAPI("/v2/users/".来源."/messages", "POST", json_encode($json));
           $data = json_decode($resp, true);
           $messageId = $data['id'] ?? '';
           记录发送("发送文卡", 来源, $wenkaLogJson, "Ark", $messageId, $resp);
           return $resp;
         break;
         case "加群":
         case "退群":
         case "群成员增加":   // 新增
         case "群成员移除":   // 新增
         case "入群申请":   // 群生命周期事件
         case "群消息拒绝":
         case "群消息接收":
         case "互动":
           $json["event_id"] = 事件ID;
           $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($json));
           $data = json_decode($resp, true);
           $messageId = $data['id'] ?? '';
           记录发送("发送文卡", 来源, $wenkaLogJson, "Ark", $messageId, $resp);
           return $resp;
         break;
    }
}

function 大图($title,$xtitle,$iurl){
    $json = [
        "msg_type" => 3,
        "msg_seq" => mt_rand(1, 9999),
        "ark" => [
            "template_id" => 37,
            "kv" => [
                ["key" => "#METATITLE#", "value" => $title],
                ["key" => "#METASUBTITLE#", "value" => $xtitle],
                ["key" => "#PROMPT#", "value" => "忘了吧"],
                ["key" => "#METACOVER#", "value" => $iurl]
            ]
        ]
    ];
    // 存储完整Ark卡片数据（JSON），供聊天界面渲染
    $datuLogData = ['template_id' => 37, 'kv' => $json['ark']['kv']];
    $datuLogJson = json_encode($datuLogData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    switch (消息来源) {
         case "群聊":
           $evId = defined("事件ID") ? 事件ID : "";
           $msgId = defined("消息ID") ? 消息ID : "";
           if (!empty($evId)) $json["event_id"] = $evId;
           elseif (!empty($msgId)) $json["msg_id"] = $msgId;
           $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($json));
           $data = json_decode($resp, true);
           $messageId = $data['id'] ?? '';
           记录发送("发送大图卡片", 来源, $datuLogJson, "Ark", $messageId, $resp);
           return $resp;
         break;
         case "私聊":
         case "好友增加":   // C2C生命周期事件
         case "好友删除":
           $evId = defined("事件ID") ? 事件ID : "";
           $msgId = defined("消息ID") ? 消息ID : "";
           if (!empty($evId)) $json["event_id"] = $evId;
           elseif (!empty($msgId)) $json["msg_id"] = $msgId;
           $resp = BOTAPI("/v2/users/".来源."/messages", "POST", json_encode($json));
           $data = json_decode($resp, true);
           $messageId = $data['id'] ?? '';
           记录发送("发送大图卡片", 来源, $datuLogJson, "Ark", $messageId, $resp);
           return $resp;
         break;
         case "加群":
         case "退群":
         case "群成员增加":   // 新增
         case "群成员移除":   // 新增
         case "入群申请":   // 群生命周期事件
         case "群消息拒绝":
         case "群消息接收":
         case "互动":
           $json["event_id"] = 事件ID;
           $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($json));
           $data = json_decode($resp, true);
           $messageId = $data['id'] ?? '';
           记录发送("发送大图卡片", 来源, $datuLogJson, "Ark", $messageId, $resp);
           return $resp;
         break;
    }
}

function 跳转卡($title,$desc,$image,$tz){
    $json = [
        "msg_type" => 3,
        "msg_seq" => mt_rand(1, 9999),
        "ark" => [
            "template_id" => 24,
            "kv" => [
                ["key" => "#DESC#", "value" => "忘了吧"],
                ["key" => "#PROMPT#", "value" => "忘了吧"],
                ["key" => "#TITLE#", "value" => $title],
                ["key" => "#METADESC#", "value" => $desc],
                ["key" => "#IMG#", "value" => $image],
                ["key" => "#LINK#", "value" => $tz],
                ["key" => "#SUBTITLE#", "value" => "忘了吧"]
            ]
        ]
    ];
    // 存储完整Ark卡片数据（JSON），供聊天界面渲染
    $tzLogData = ['template_id' => 24, 'kv' => $json['ark']['kv']];
    $tzLogJson = json_encode($tzLogData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    switch (消息来源) {
         case "群聊":
           $evId = defined("事件ID") ? 事件ID : "";
           $msgId = defined("消息ID") ? 消息ID : "";
           if (!empty($evId)) $json["event_id"] = $evId;
           elseif (!empty($msgId)) $json["msg_id"] = $msgId;
           $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($json));
           $data = json_decode($resp, true);
           $messageId = $data['id'] ?? '';
           记录发送("发送跳转卡片", 来源, $tzLogJson, "Ark", $messageId, $resp);
           return $resp;
         break;
         case "私聊":
         case "好友增加":   // C2C生命周期事件
         case "好友删除":
           $evId = defined("事件ID") ? 事件ID : "";
           $msgId = defined("消息ID") ? 消息ID : "";
           if (!empty($evId)) $json["event_id"] = $evId;
           elseif (!empty($msgId)) $json["msg_id"] = $msgId;
           $resp = BOTAPI("/v2/users/".来源."/messages", "POST", json_encode($json));
           $data = json_decode($resp, true);
           $messageId = $data['id'] ?? '';
           记录发送("发送跳转卡片", 来源, $tzLogJson, "Ark", $messageId, $resp);
           return $resp;
         break;
         case "加群":
         case "退群":
         case "群成员增加":   // 新增
         case "群成员移除":   // 新增
         case "入群申请":   // 群生命周期事件
         case "群消息拒绝":
         case "群消息接收":
         case "互动":
           $json["event_id"] = 事件ID;
           $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($json));
           $data = json_decode($resp, true);
           $messageId = $data['id'] ?? '';
           记录发送("发送跳转卡片", 来源, $tzLogJson, "Ark", $messageId, $resp);
           return $resp;
         break;
    }
}

function 流式(...$msgs){
    if (!defined('来源') || 来源 === '') {
        return json_encode(['code' => -1, 'message' => '当前上下文没有会话来源'], JSON_UNESCAPED_UNICODE);
    }
    $msgs = array_values($msgs);
    $sourceType = defined('消息来源') ? 消息来源 : '';
    $msgId = defined('消息ID') ? 消息ID : '';
    $eventId = defined('事件ID') ? 事件ID : '';

    // 私聊/互动私聊：使用官方新的 /stream_messages 流式接口（2025 新增）
    if ($sourceType === '私聊' || $sourceType === '互动') {
        return 流式发送单聊(来源, $msgs, $msgId, $eventId, 'append', 'markdown');
    }

    // 群聊：保持原有的消息分片方式（官方群聊暂未开放 stream_messages）
    $id = null;
    $index = 0;
    $total = count($msgs);
    $lastResp = null;
    foreach ($msgs as $msg) {
        $isLast = ($index === $total - 1);
        $json = [
            "content" => (string)$msg,
            "msg_id" => $msgId,
            "msg_seq" => random_int(1, 99999),
            "stream" => [
                "state" => $isLast ? 10 : 1,
                "id" => $id,
                "index" => $index,
                "reset" => false
            ]
        ];
        $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
        $lastResp = $resp;
        $decoded = json_decode($resp, true);
        $id = $decoded["id"] ?? null;
        $index++;
    }
    $content_preview = implode(" ", array_slice($msgs, 0, 2));
    记录发送("流式回复", 来源, $content_preview . (count($msgs) > 2 ? " ..." : ""), "流式", $id, $lastResp);
    return $lastResp;
}

function 撤回($id){
   // 不记录发送日志，撤回操作由 chat_api.php 更新原消息状态为[已撤回]
   // 映射消息来源到API端点类型；未支持的来源返回错误JSON而不是抛出未定义索引
   $type = [
      "群聊"=>"groups",
      "私聊"=>"users",
      "加群"=>"groups",
      "退群"=>"groups",
      "群成员增加"=>"groups",   // 新增
      "群成员移除"=>"groups",    // 新增
      "入群申请"=>"groups",      // 群生命周期事件
      "群消息拒绝"=>"groups",
      "群消息接收"=>"groups",
      "好友增加"=>"users",       // C2C生命周期事件
      "好友删除"=>"users"
   ];
   $sourceType = defined('消息来源') ? 消息来源 : '';
   if (!isset($type[$sourceType]) || !defined('来源') || 来源 === '') {
      return json_encode([
         "code" => -1,
         "message" => "当前消息来源[{$sourceType}]不支持撤回操作"
      ], JSON_UNESCAPED_UNICODE);
   }
   return BOTAPI("/v2/{$type[$sourceType]}/".来源."/messages/".$id,"DELETE","");
}

function 互动私聊(){
   return 消息来源 == "互动" && (
      (raw["d"]["scene"] ?? "") == "c2c" ||
      (string)(raw["d"]["chat_type"] ?? "") == "2" ||
      (!isset(raw["d"]["group_openid"]) && isset(raw["d"]["user_openid"]))
   );
}

function 互动目标用户(){
   return raw["d"]["user_openid"] ?? 来源;
}

// ==================== 引用消息函数（官方规范版） ====================
// 官方"发送群聊消息"接口规范 (bot.q.qq.com/wiki/develop/api-v2):
//   message_reference.message_id = 被引用消息ID, 填 REFIDX_xxx 时消息以引用形式展示:
//     - 引用"别人发的消息": 该消息事件 message_scene.ext 数组中的 msg_idx 字段 → 框架已定义为常量 引用ID
//     - 引用"机器人自己发的消息": 发送响应 ext_info.ref_idx → 用 辅助函数 发送响应引用IDX($resp) 提取
//   msg_id / event_id = 被动回复锚点 (5分钟内有效, 每条消息最多回复5次), 与 message_reference 互不冲突
//   msg_seq 与 msg_id 联合去重, 相同组合重复发送会被官方拒绝
//   msg_type: 0=纯文本(content)  2=Markdown(markdown对象)  7=富媒体(media)
//   注意: msg_type=2 时 content 必须为空; msg_type=7 时 content 可选(作为媒体旁的图文说明)

// 统一引用发送入口: 按 消息来源 补被动回复锚点并选择API地址, 发送并记录日志
// 返回原始响应 JSON; 消息来源不支持时返回 null (由各引用函数自行降级)
function 引用发送($payload, $logAction, $logType, $logContent) {
    $anchorMsgId = defined("消息ID") ? 消息ID : "";
    $anchorEvId  = defined("事件ID") ? 事件ID : "";
    $seq = mt_rand(1, 99999);
    switch (消息来源) {
        case "群聊":
            if ($anchorEvId !== '') { $payload["event_id"] = $anchorEvId; }
            elseif ($anchorMsgId !== '') { $payload["msg_id"] = $anchorMsgId; $payload["msg_seq"] = $seq; }
            $url = "/v2/groups/".来源."/messages";
            break;
        case "私聊":
        case "好友增加":
        case "好友删除":
            if ($anchorEvId !== '') { $payload["event_id"] = $anchorEvId; }
            elseif ($anchorMsgId !== '') { $payload["msg_id"] = $anchorMsgId; $payload["msg_seq"] = $seq; }
            $url = "/v2/users/".来源."/messages";
            break;
        case "加群":
        case "退群":
        case "群成员增加":
        case "群成员移除":
        case "入群申请":
        case "群消息拒绝":
        case "群消息接收":
        case "互动":
            $payload["event_id"] = $anchorEvId;
            if (isset($payload["event_id"]) && $payload["event_id"] === '') unset($payload["event_id"]);
            $url = "/v2/groups/".来源."/messages";
            break;
        case "文字子频道":
        case "频道":
            if ($anchorMsgId !== '') $payload["msg_id"] = $anchorMsgId;
            $url = "/channels/".来源."/messages";
            break;
        case "频道私信":
            if ($anchorMsgId !== '') $payload["msg_id"] = $anchorMsgId;
            $url = "/dms/".来源."/messages";
            break;
        default:
            return null;
    }
    $resp = BOTAPI($url, "POST", json_encode($payload, JSON_UNESCAPED_UNICODE));
    $data = json_decode($resp, true);
    $returnedMsgId = is_array($data) ? ($data['id'] ?? '') : '';
    记录发送($logAction, 来源, $logContent, $logType, $returnedMsgId, $resp);
    return $resp;
}

// 引用发送文字 (兼容旧版用法: 引用($msgId, $content))
// $msgId 推荐传 引用ID 常量(别人的消息) 或 发送响应引用IDX 提取值(自己的消息)
function 引用($msgId, $content = '') {
    // 确保有内容，API要求content不能为空
    if (empty($content)) {
        $content = " ";
    }
    $payload = [
        "msg_type" => 0,
        "content" => $content,
        "message_reference" => [
            "message_id" => $msgId,
            "ignore_get_message_error" => true
        ]
    ];
    $resp = 引用发送($payload, "发送引用消息", "引用", $content);
    if ($resp === null) {
        // 不支持的来源，降级为文字发送
        return 文字($content);
    }
    return $resp;
}

// 引用发送图片: 引用图片($msgId, $image)
// 引用发"图片+文字": 引用图片($msgId, $image, '配文') (官方 msg_type=7 + media + content)
// $image 支持 URL 或二进制数据; $logMedia 展示URL覆盖(后台上传二进制时传本地留存URL); 失败时降级为纯文字提示(与 图片() 行为一致)
function 引用图片($msgId, $image, $content = null, $logMedia = null) {
    $hasText = ($content !== null && trim((string)$content) !== '');
    $dispUrl = ($logMedia !== null && trim((string)$logMedia) !== '') ? trim((string)$logMedia)
             : ((is_string($image) && preg_match('/^http/i', $image)) ? $image : "[上传图片]");
    $logContent = $hasText ? ($dispUrl . " + " . trim((string)$content)) : $dispUrl;
    $logType  = $hasText ? "引用图文" : "引用图片";
    $logAction = $hasText ? "发送引用图文" : "发送引用图片";

    // 频道消息不走 /files 富媒体接口, 用 file_image 直传 (与 图片() 同策略)
    if (defined('消息来源') && in_array(消息来源, ["文字子频道", "频道"], true)) {
        $payload = [
            "msg_type" => 0,
            "content" => (string)$content,
            "file_image" => $image,
            "message_reference" => ["message_id" => $msgId, "ignore_get_message_error" => true]
        ];
        $resp = 引用发送($payload, $logAction, $logType, $logContent);
        if ($resp === null) return 图片($image, $content);
        return $resp;
    }

    $up = 富媒体("图片", $image);
    if (!is_array($up) || empty($up['file_info'])) {
        // 上传失败: 把官方错误信息回复给用户
        $errMsg = is_array($up) && isset($up['message']) ? $up['message'] : "图片上传失败";
        return 文字($errMsg);
    }
    $payload = [
        "msg_type" => 7,
        "content" => $hasText ? "\n{$content}" : "",
        "media" => ["file_info" => $up['file_info']],
        "message_reference" => ["message_id" => $msgId, "ignore_get_message_error" => true]
    ];
    $resp = 引用发送($payload, $logAction, $logType, $logContent);
    if ($resp === null) return 图片($image, $content);
    return $resp;
}

// 引用发送 Markdown: 引用MD($msgId, $md, $keyboard = null, $style = null)
// 官方 msg_type=2 + markdown 对象(content必填), 传 markdown 后 content 必须为空(本函数不传content)
// $keyboard 支持键盘模板ID(短形式); $style 与 MD() 一致
function 引用MD($msgId, $md, $keyboard = null, $style = null) {
    $mdObj = ["content" => $md];
    if ($style !== null && is_array($style)) $mdObj["style"] = $style;
    $payload = [
        "msg_type" => 2,
        "markdown" => $mdObj,
        "message_reference" => ["message_id" => $msgId, "ignore_get_message_error" => true]
    ];
    if ($keyboard !== null) $payload["keyboard"] = ["id" => $keyboard];
    $resp = 引用发送($payload, "发送引用MD", "引用MD", $md);
    if ($resp === null) return MD($md, $keyboard, $style);
    return $resp;
}

// ==================== 引用发送富媒体（语音/视频/文件） ====================
// 通用实现: 官方 msg_type=7 + media(file_info) + message_reference, 与 引用图片 同构
// $媒体类型: 语音|视频|文件; $source 支持 URL 或二进制; $name 为文件名(文件类型用)
// content 可选(媒体旁的说明文字); 频道消息不支持富媒体引用形态, 返回 null 由包装函数降级直发
function 引用富媒体($msgId, $媒体类型, $source, $content = null, $name = null, $logMedia = null) {
    if (defined('消息来源') && in_array(消息来源, ["文字子频道", "频道", "频道私信"], true)) {
        return null;
    }
    $hasText = ($content !== null && trim((string)$content) !== '');
    $dispUrl = ($logMedia !== null && trim((string)$logMedia) !== '') ? trim((string)$logMedia)
             : ((is_string($source) && preg_match('/^http/i', $source)) ? $source : "[上传{$媒体类型}]");
    $logContent = $hasText ? ($dispUrl . " + " . trim((string)$content)) : $dispUrl;
    $logType  = "引用{$媒体类型}";
    $logAction = "发送引用{$媒体类型}";

    // 语音需先转 silk (与 语音() 同策略: URL 音频经外部转换服务)
    // 展示URL保留原始http链(浏览器可直接播放), 不再覆盖为 [silk语音] 占位
    if ($媒体类型 === '语音' && is_string($source) && preg_match('/^http/i', $source)) {
        $silk = silk($source);
        if ($silk === '') {
            return 文字("语音转换失败，无法发送");
        }
        $source = $silk;
    }

    $up = 富媒体($媒体类型, $source, $name);
    if (!is_array($up) || empty($up['file_info'])) {
        // 上传失败: 把官方错误信息回复给用户 (与 图片() 行为一致)
        $errMsg = is_array($up) && isset($up['message']) ? $up['message'] : "{$媒体类型}上传失败";
        return 文字($errMsg);
    }
    $payload = [
        "msg_type" => 7,
        "content" => $hasText ? "\n{$content}" : "",
        "media" => ["file_info" => $up['file_info']],
        "message_reference" => ["message_id" => $msgId, "ignore_get_message_error" => true]
    ];
    return 引用发送($payload, $logAction, $logType, $logContent);
}

// 引用发送语音: 引用语音($msgId, $url) / 引用语音($msgId, $url, '配文')
// URL 语音自动 silk 转换; 来源不支持引用时降级为无引用直发
function 引用语音($msgId, $url, $content = null) {
    $resp = 引用富媒体($msgId, "语音", $url, $content);
    if ($resp === null) return 语音($url);
    return $resp;
}

// 引用发送视频: 引用视频($msgId, $url) / 引用视频($msgId, $url, '配文')
function 引用视频($msgId, $url, $content = null) {
    $resp = 引用富媒体($msgId, "视频", $url, $content);
    if ($resp === null) return 视频($url);
    return $resp;
}

// 引用发送文件: 引用文件($msgId, $url, $name = null, $content = null)
function 引用文件($msgId, $url, $name = null, $content = null) {
    $resp = 引用富媒体($msgId, "文件", $url, $content, $name);
    if ($resp === null) return 文件($url, $name);
    return $resp;
}

// 从发送响应中提取机器人自己消息的引用IDX (官方响应 ext_info.ref_idx 字段)
// 用法: $resp = 文字('你好'); 引用(发送响应引用IDX($resp), '引用机器人刚发的这条');
function 发送响应引用IDX($resp) {
    $data = is_array($resp) ? $resp : json_decode((string)$resp, true);
    if (!is_array($data)) return '';
    if (!empty($data['ext_info']['ref_idx'])) return (string)$data['ext_info']['ref_idx'];
    if (!empty($data['ref_idx'])) return (string)$data['ref_idx'];
    return '';
}

// ==================== MD函数（已升级，支持style参数） ====================
function MD($md, $keyboard = null, $style = null) {
   $json = [
       "msg_type" => 2,
       "msg_seq" => rand(1, 9999),
       "markdown" => [
           "content" => $md
       ]
   ];
   
   // 添加 style 参数支持
   if ($style !== null && is_array($style)) {
       $json["markdown"]["style"] = $style;
   }
   
   if ($keyboard !== null) {
       $json["keyboard"] = ["id" => $keyboard];
   }
   
   switch (消息来源) {
     case "群聊":
        $evId = defined("事件ID") ? 事件ID : "";
           $msgId = defined("消息ID") ? 消息ID : "";
           if (!empty($evId)) $json["event_id"] = $evId;
           elseif (!empty($msgId)) $json["msg_id"] = $msgId;
        $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送MD", 来源, $md, "MD", $messageId, $resp);
        return $resp;
        break;
     case "私聊":
     case "好友增加":   // C2C生命周期事件
     case "好友删除":
        $evId = defined("事件ID") ? 事件ID : "";
           $msgId = defined("消息ID") ? 消息ID : "";
           if (!empty($evId)) $json["event_id"] = $evId;
           elseif (!empty($msgId)) $json["msg_id"] = $msgId;
        $resp = BOTAPI("/v2/users/".来源."/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送MD", 来源, $md, "MD", $messageId, $resp);
        return $resp;
        break;
     case "加群":
     case "退群":
     case "群成员增加":   // 新增
     case "群成员移除":   // 新增
     case "入群申请":   // 群生命周期事件
     case "群消息拒绝":
     case "群消息接收":
        $json["event_id"] = 事件ID;
        $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送MD", 来源, $md, "MD", $messageId, $resp);
        return $resp;
        break;
     case "互动":
        $json["event_id"] = 事件ID;
        if (互动私聊()) {
           $resp = BOTAPI("/v2/users/".互动目标用户()."/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
        } else {
           $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
        }
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送MD", 来源, $md, "MD", $messageId, $resp);
        return $resp;
        break;
   }
}

function 原生按钮($md, $rows) {
   $json = [
       "msg_type" => 2,
       "msg_seq" => rand(1, 9999),
       "markdown" => [
           "content" => $md
       ],
       "keyboard" => [
           "content" => [
               "rows" => $rows
           ]
       ]
   ];

   switch (消息来源) {
     case "群聊":
        $json["msg_id"] = 消息ID;
        $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送原生自定义按钮", 来源, $md, "原生按钮", $messageId, $resp);
        return $resp;
        break;
     case "私聊":
     case "好友增加":   // C2C生命周期事件
     case "好友删除":
        $json["msg_id"] = 消息ID;
        $resp = BOTAPI("/v2/users/".来源."/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送原生自定义按钮", 来源, $md, "原生按钮", $messageId, $resp);
        return $resp;
        break;
     case "加群":
     case "退群":
     case "群成员增加":   // 新增
     case "群成员移除":   // 新增
     case "入群申请":   // 群生命周期事件
     case "群消息拒绝":
     case "群消息接收":
        $json["event_id"] = 事件ID;
        $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送原生自定义按钮", 来源, $md, "原生按钮", $messageId, $resp);
        return $resp;
        break;
     case "互动":
        $json["event_id"] = 事件ID;
        if (互动私聊()) {
           $resp = BOTAPI("/v2/users/".互动目标用户()."/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
        } else {
           $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
        }
        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        记录发送("发送原生自定义按钮", 来源, $md, "原生按钮", $messageId, $resp);
        return $resp;
        break;
   }
}

// ==================== 发MD函数（已升级，支持style参数） ====================
function 发MD($template_id, $params, $keyboard_id = null, $style = null) {
    if (isset($params['key']) && isset($params['values'])) {
        $params = [$params];
    }
    
    $json_data = [
        "content" => "",
        "msg_type" => 2,
        "msg_seq" => mt_rand(1, 99999),
        "markdown" => [
            "custom_template_id" => $template_id,
            "params" => $params
        ]
    ];
    
    // 添加 style 参数支持
    if ($style !== null && is_array($style)) {
        $json_data["markdown"]["style"] = $style;
    }
    
    if (!empty($keyboard_id)) {
        $json_data["keyboard"] = ["id" => $keyboard_id];
    }
    
    // 根据来源设置 event_id 或 msg_id
    if (in_array(消息来源, ["加群", "退群", "群成员增加", "群成员移除", "互动"])) {  // 新增群成员增加/移除
        $json_data["event_id"] = 事件ID;
    } else {
        $json_data["msg_id"] = 消息ID;
    }
    
    switch (消息来源) {
        case "群聊":
        case "加群":
        case "退群":
        case "群成员增加":   // 新增
        case "群成员移除":   // 新增
        case "入群申请":   // 群生命周期事件
        case "群消息拒绝":
        case "群消息接收":
        case "互动":
            $api_url = "/v2/groups/" . 来源 . "/messages";
            break;
        case "私聊":
        case "好友增加":   // C2C生命周期事件
        case "好友删除":
            $api_url = "/v2/users/" . 来源 . "/messages";
            break;
        case "文字子频道":
        case "频道":   // 频道消息
            $api_url = "/channels/" . 来源 . "/messages";
            break;
        default:
            return "错误：消息来源不支持";
    }
    
    $resp = BOTAPI($api_url, "POST", json_encode($json_data, JSON_UNESCAPED_UNICODE));
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    
    // 构建日志内容
    $logParams = [];
    if (isset($params['key']) && isset($params['values'])) {
        $logParams[] = $params['key'] . ":" . implode(",", $params['values']);
    } elseif (is_array($params)) {
        foreach ($params as $p) {
            if (isset($p['key'])) {
                $logParams[] = $p['key'];
            }
        }
    }
    记录发送("发送自定义MD", 来源, "模板: {$template_id} " . implode(" ", $logParams), "自定义MD", $messageId, $resp);
    return $resp;
}

// ==================== Emoji表情发送 ====================
function Emoji($emojiId, $content = '') {
    $json = [
        "content" => $content ?: "",
        "msg_type" => 4,
        "msg_seq" => rand(1, 99999),
        "emoji" => ["type" => 1, "id" => $emojiId]
    ];
    if (in_array(消息来源, ["加群", "退群", "群成员增加", "群成员移除", "互动"])) {
        $json["event_id"] = 事件ID;
    } else {
        $json["msg_id"] = 消息ID;
    }
    switch (消息来源) {
        case "群聊":
        case "加群":
        case "退群":
        case "群成员增加":
        case "群成员移除":
        case "入群申请":   // 群生命周期事件
        case "群消息拒绝":
        case "群消息接收":
        case "互动":
            $api = "/v2/groups/" . 来源 . "/messages";
            break;
        case "私聊":
        case "好友增加":   // C2C生命周期事件
        case "好友删除":
            $api = "/v2/users/" . 来源 . "/messages";
            break;
        default:
            return;
    }
    $resp = BOTAPI($api, "POST", json_encode($json));
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    记录发送("发送Emoji", 来源, "emoji_id: {$emojiId}", "Emoji", $messageId, $resp);
    return $resp;
}

// ==================== Ark23 链接卡片发送（聊天界面专用） ====================
// 前端传入 kv 对象，包含 #DESC#、#PROMPT# 以及 #LIST_1#、#LIST_1_URL# 等
function Ark23($kv) {
    // 提取链接列表项，构建 obj 结构
    $listItems = [];
    $idx = 1;
    while (isset($kv['#LIST_' . $idx . '#']) || isset($kv['#LIST_' . $idx . '_URL#'])) {
        $desc = $kv['#LIST_' . $idx . '#'] ?? '';
        $link = $kv['#LIST_' . $idx . '_URL#'] ?? '';
        if (!empty($desc)) {
            $item = ["obj_kv" => [["key" => "desc", "value" => $desc]]];
            if (!empty($link)) {
                $item["obj_kv"][] = ["key" => "link", "value" => $link];
            }
            $listItems[] = $item;
        }
        $idx++;
    }

    $arkKv = [];
    // #DESC#
    if (isset($kv['#DESC#']) && $kv['#DESC#'] !== '') {
        $arkKv[] = ["key" => "#DESC#", "value" => $kv['#DESC#']];
    }
    // #PROMPT#
    if (isset($kv['#PROMPT#']) && $kv['#PROMPT#'] !== '') {
        $arkKv[] = ["key" => "#PROMPT#", "value" => $kv['#PROMPT#']];
    }
    // #LIST#
    if (!empty($listItems)) {
        $arkKv[] = ["key" => "#LIST#", "obj" => $listItems];
    }

    if (empty($arkKv)) {
        return json_encode(["code" => 400, "message" => "Ark23至少需要填写一个字段"]);
    }

    $json = [
        "msg_type" => 3,
        "msg_seq" => rand(1, 99999),
        "ark" => [
            "template_id" => 23,
            "kv" => $arkKv
        ]
    ];
    if (in_array(消息来源, ["加群", "退群", "群成员增加", "群成员移除", "互动"])) {
        $json["event_id"] = 事件ID;
    } else {
        $json["msg_id"] = 消息ID;
    }
    switch (消息来源) {
        case "群聊":
        case "加群":
        case "退群":
        case "群成员增加":
        case "群成员移除":
        case "入群申请":   // 群生命周期事件
        case "群消息拒绝":
        case "群消息接收":
        case "互动":
            $api = "/v2/groups/" . 来源 . "/messages";
            break;
        case "私聊":
        case "好友增加":   // C2C生命周期事件
        case "好友删除":
            $api = "/v2/users/" . 来源 . "/messages";
            break;
        case "文字子频道":
        case "频道":   // 频道消息
            $api = "/channels/" . 来源 . "/messages";
            break;
        default:
            return "错误：消息来源不支持";
    }
    $resp = BOTAPI($api, "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    $arkLogData = ['template_id' => 23, 'kv' => $arkKv];
    记录发送("发送Ark23", 来源, json_encode($arkLogData, JSON_UNESCAPED_UNICODE), "Ark", $messageId, $resp);
    return $resp;
}

// ==================== 通用Ark模板发送 ====================
function Ark($template_id, $kv) {
    $arkKv = [];
    if (isset($kv[0]) && is_array($kv[0]) && isset($kv[0]['key'])) {
        $arkKv = $kv;
    } else {
        foreach ($kv as $k => $v) {
            $arkKv[] = ["key" => $k, "value" => $v];
        }
    }
    $json = [
        "msg_type" => 3,
        "msg_seq" => rand(1, 99999),
        "ark" => [
            "template_id" => $template_id,
            "kv" => $arkKv
        ]
    ];
    if (in_array(消息来源, ["加群", "退群", "群成员增加", "群成员移除", "互动"])) {
        $json["event_id"] = 事件ID;
    } else {
        $json["msg_id"] = 消息ID;
    }
    switch (消息来源) {
        case "群聊":
        case "加群":
        case "退群":
        case "群成员增加":
        case "群成员移除":
        case "入群申请":   // 群生命周期事件
        case "群消息拒绝":
        case "群消息接收":
        case "互动":
            $api = "/v2/groups/" . 来源 . "/messages";
            break;
        case "私聊":
        case "好友增加":   // C2C生命周期事件
        case "好友删除":
            $api = "/v2/users/" . 来源 . "/messages";
            break;
        case "文字子频道":
        case "频道":   // 频道消息
            $api = "/channels/" . 来源 . "/messages";
            break;
        default:
            return "错误：消息来源不支持";
    }
    $resp = BOTAPI($api, "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    // 存储完整Ark卡片数据（JSON），供聊天界面渲染
    $arkLogData = ['template_id' => $template_id, 'kv' => $arkKv];
    记录发送("发送Ark", 来源, json_encode($arkLogData, JSON_UNESCAPED_UNICODE), "Ark", $messageId, $resp);
    return $resp;
}

// ==================== 主动推送消息到群 ====================
function 推送到群($groupOpenid, $content, $msgType = 0) {
    $json = json_encode([
        "content" => (string)$content,
        "msg_type" => $msgType,
        "msg_seq" => rand(1, 99999)
    ]);
    $resp = BOTAPI("/v2/groups/{$groupOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '群聊', $groupOpenid, $msgType == 2 ? 'MD' : '文字', $content, $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送消息到用户 ====================
function 推送到用户($userOpenid, $content, $msgType = 0) {
    $json = json_encode([
        "content" => (string)$content,
        "msg_type" => $msgType,
        "msg_seq" => rand(1, 99999)
    ]);
    $resp = BOTAPI("/v2/users/{$userOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '私聊', $userOpenid, $msgType == 2 ? 'MD' : '文字', $content, $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送MD到群 ====================
function 推送MD到群($groupOpenid, $md, $keyboard = null) {
    $json = [
        "msg_type" => 2,
        "msg_seq" => rand(1, 99999),
        "markdown" => ["content" => $md]
    ];
    if ($keyboard !== null) {
        $json["keyboard"] = ["id" => $keyboard];
    }
    $resp = BOTAPI("/v2/groups/{$groupOpenid}/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '群聊', $groupOpenid, 'MD', $md, $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送MD到用户 ====================
function 推送MD到用户($userOpenid, $md, $keyboard = null) {
    $json = [
        "msg_type" => 2,
        "msg_seq" => rand(1, 99999),
        "markdown" => ["content" => $md]
    ];
    if ($keyboard !== null) {
        $json["keyboard"] = ["id" => $keyboard];
    }
    $resp = BOTAPI("/v2/users/{$userOpenid}/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '私聊', $userOpenid, 'MD', $md, $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送图片到群 ====================
function 推送图片到群($groupOpenid, $image) {
    $file_info = 推送富媒体("图片", $image, $groupOpenid, true);
    if (isset($file_info['message'])) {
        return 推送到群($groupOpenid, $file_info['message']);
    }
    $file = $file_info['file_info'];
    $json = json_encode([
        "content" => "",
        "msg_type" => 7,
        "msg_seq" => rand(1, 99999),
        "media" => ["file_info" => $file]
    ]);
    $resp = BOTAPI("/v2/groups/{$groupOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '群聊', $groupOpenid, '图片', $image, $messageId, null, $resp);
    return $resp;
}

// ==================== 生成分享链接 ====================
function 分享链接($groupOpenid) {
    $json = json_encode([
        "group_openid" => $groupOpenid
    ]);
    $resp = BOTAPI("/v2/generate_url_link", "POST", $json);
    return json_decode($resp, true);
}

// ==================== 群成员管理 - 查询群成员信息 (GET /v2/groups/{group_openid}/members/{member_openid}) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_group_openid_members_member_openid.get.html
// 20260903 变更记录新增的群成员管理接口，该能力正在内邀接入中（未开通的机器人返回错误码 11253）。
// 返回: member_openid(成员openid) / username(昵称) / member_role(member普通成员,owner群主,admin管理员)
//       bot(是否机器人) / joined_at(入群时间RFC3339) / union_openid(平台统一标识,如有)
function 获取群成员信息($groupOpenid, $memberOpenid) {
    if (empty($groupOpenid) || empty($memberOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 member_openid 为空']);
    }
    $resp = BOTAPI("/v2/groups/{$groupOpenid}/members/{$memberOpenid}", "GET", "");
    return $resp;
}

// ==================== 群成员管理 - 获取群成员列表 (GET /v2/groups/{group_openid}/members) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_group_openid_members.get.html
// 20260903 变更记录新增的群成员管理接口（内邀中，错误码 11253 表示未开通）。
// 每次最多返回 30 条，支持 cursor 分页；接口频率限制 60 QPM。
// 参数:
//   $groupOpenid - 群 OpenID
//   $cursor      - 分页游标，首次请求传空串；后续传上一次响应的 next_cursor
// 返回: {"members":[{member_openid,username,member_role,bot,joined_at,union_openid}], "next_cursor":""}
//       next_cursor 为空串表示已到末页
function 获取群成员列表($groupOpenid, $cursor = '') {
    if (empty($groupOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 为空']);
    }
    $query = empty($cursor) ? '' : '?cursor=' . urlencode($cursor);
    $resp = BOTAPI("/v2/groups/{$groupOpenid}/members{$query}", "GET", "");
    return $resp;
}

// ==================== 群成员管理 - 封装: 拉取全部群成员 ====================
// 沿 next_cursor 自动分页拉取全部成员（每页最多30条）
// 参数:
//   $groupOpenid - 群 OpenID
//   $maxPages    - 最大分页数保护（默认50页，防止死循环）
// 返回: {"code":0,"members":[...],"pages":N} 或 {"code":-1,"message":"..."}
function 获取全部群成员($groupOpenid, $maxPages = 50) {
    if (empty($groupOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 为空']);
    }
    $all = [];
    $cursor = '';
    $pages = 0;
    while ($pages < $maxPages) {
        $resp = 获取群成员列表($groupOpenid, $cursor);
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            return json_encode(['code' => -1, 'message' => '接口返回格式错误', 'raw' => $resp], JSON_UNESCAPED_UNICODE);
        }
        if (isset($data['code']) && $data['code'] != 0) {
            return $resp;
        }
        if (isset($data['members']) && is_array($data['members'])) {
            foreach ($data['members'] as $m) {
                $all[] = $m;
            }
        }
        $pages++;
        $cursor = $data['next_cursor'] ?? '';
        if ($cursor === '' || $cursor === null) {
            break;
        }
    }
    return json_encode(['code' => 0, 'members' => $all, 'pages' => $pages], JSON_UNESCAPED_UNICODE);
}

// ==================== 群成员管理 - 群成员批量移除 (POST /v2/groups/{group_openid}/batch_remove_members) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_group_openid_batch_remove_members.post.html
// 20260903 变更记录新增的群成员管理接口（内邀中，错误码 11253 表示未开通）。
// 单次最多移除 20 个成员，可选择同时加入群黑名单；接口频率限制 30 QPM。机器人需群管理员身份。
// 参数:
//   $groupOpenid        - 群 OpenID
//   $memberOpenids      - 待移除成员的 member_openid 数组（最多20个，超过自动分批）
//   $addToBlacklist     - 是否同时加入群黑名单（默认 false）
// 返回: {"remove_members_result":"success","add_to_member_blacklist_fail_openids":[]}
//       多批时返回 {"code":0,"message":"batch done","results":[...]}
function 群成员批量移除($groupOpenid, $memberOpenids, $addToBlacklist = false) {
    if (empty($groupOpenid) || empty($memberOpenids) || !is_array($memberOpenids)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 member_openids 为空']);
    }
    $memberOpenids = array_values(array_filter($memberOpenids, function ($v) {
        return is_string($v) && trim($v) !== '';
    }));
    if (empty($memberOpenids)) {
        return json_encode(['code' => -1, 'message' => 'member_openids 列表为空']);
    }
    $results = [];
    // 每批最多20个（API限制）
    foreach (array_chunk($memberOpenids, 20) as $batch) {
        $data = ['member_openids' => array_values($batch)];
        if ($addToBlacklist) {
            $data['add_to_member_blacklist'] = true;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $resp = BOTAPI("/v2/groups/{$groupOpenid}/batch_remove_members", "POST", $json);
        wlog("[群成员批量移除] group={$groupOpenid} 请求: " . $json . " 响应: " . $resp, defined('appid') ? appid : null);
        $results[] = $resp;
    }
    if (count($results) === 1) return $results[0];
    return json_encode(['code' => 0, 'message' => 'batch done', 'results' => $results], JSON_UNESCAPED_UNICODE);
}

// ==================== 群成员管理 - 群黑名单查询 (GET /v2/groups/{group_openid}/member_blacklist) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_group_openid_member_blacklist.get.html
// 20260903 变更记录新增的群成员管理接口（内邀中，错误码 11253 表示未开通）。
// 参数:
//   $groupOpenid - 群 OpenID
//   $cursor      - 分页游标，首次请求传空串
//   $limit       - 单页数量，默认 20，最大 100
// 返回: {"users":[{union_openid,member_openid,username,banned_at,bot}], "next_cursor":""}
//       next_cursor 为空串表示已到末页。接口频率限制 30 QPM。
function 查询群黑名单($groupOpenid, $cursor = '', $limit = 20) {
    if (empty($groupOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 为空']);
    }
    $limit = max(1, min(100, intval($limit)));
    $query = 'limit=' . $limit;
    if (!empty($cursor)) {
        $query .= '&cursor=' . urlencode($cursor);
    }
    return BOTAPI("/v2/groups/{$groupOpenid}/member_blacklist?{$query}", "GET", "");
}

// ==================== 群成员管理 - 群黑名单操作 (POST /v2/groups/{group_openid}/member_blacklist) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_group_openid_member_blacklist.post.html
// 20260903 变更记录新增的群成员管理接口（内邀中，错误码 11253 表示未开通）。
// 注意: 只有目标用户不在群中时才能加入群黑名单（如需移除并拉黑，可用 群成员批量移除 的 add_to_member_blacklist 一次完成）。
// 参数:
//   $groupOpenid   - 群 OpenID
//   $op            - 操作类型: add 加入黑名单 / del 移出黑名单
//   $memberOpenids - 目标成员 openid 列表，单次最多 20 个（超过自动分批）
// 返回: {"fail_openids":[]} 拉黑/移出失败的 openid 列表。接口频率限制 60 QPM。
function 操作群黑名单($groupOpenid, $op, $memberOpenids) {
    if (empty($groupOpenid) || empty($memberOpenids) || !is_array($memberOpenids)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 member_openids 为空']);
    }
    if (!in_array($op, ['add', 'del'], true)) {
        return json_encode(['code' => -1, 'message' => 'op 仅支持 add(加入黑名单) / del(移出黑名单)']);
    }
    $memberOpenids = array_values(array_filter($memberOpenids, function ($v) {
        return is_string($v) && trim($v) !== '';
    }));
    if (empty($memberOpenids)) {
        return json_encode(['code' => -1, 'message' => 'member_openids 列表为空']);
    }
    $results = [];
    // 每批最多20个（API限制）
    foreach (array_chunk($memberOpenids, 20) as $batch) {
        $json = json_encode(['op' => $op, 'member_openids' => array_values($batch)], JSON_UNESCAPED_UNICODE);
        $resp = BOTAPI("/v2/groups/{$groupOpenid}/member_blacklist", "POST", $json);
        wlog("[操作群黑名单] group={$groupOpenid} op={$op} 请求: " . $json . " 响应: " . $resp, defined('appid') ? appid : null);
        $results[] = $resp;
    }
    if (count($results) === 1) return $results[0];
    return json_encode(['code' => 0, 'message' => 'batch done', 'results' => $results], JSON_UNESCAPED_UNICODE);
}

// ==================== 群成员管理 - 封装: 加入群黑名单 ====================
function 群黑名单拉黑($groupOpenid, $memberOpenids) {
    return 操作群黑名单($groupOpenid, 'add', $memberOpenids);
}

// ==================== 群成员管理 - 封装: 移出群黑名单 ====================
function 群黑名单移出($groupOpenid, $memberOpenids) {
    return 操作群黑名单($groupOpenid, 'del', $memberOpenids);
}

// ==================== 查询群成员信息（旧版别名，保持兼容） ====================
function 获取群成员($groupOpenid, $memberOpenid) {
    $resp = 获取群成员信息($groupOpenid, $memberOpenid);
    return json_decode($resp, true);
}

// ==================== 确认互动回调 (PUT /interactions/{interaction_id}) ====================
// 参照 ElainaBot_v2 框架 sender.ack_interaction: PUT /interactions/{iid}, body={"code":0}
// 注意: QQ Bot API 的 interactions 端点没有 /v2/ 前缀 (与 groups/users 不同)
// 官方文档: PUT https://api.bot.qq.com/interactions/{interaction_id}（域名统一后）
// ElainaBot_v2: await self.put(f'/interactions/{iid}', json={'code': code})
function 确认互动($eventId, $body = '') {
    if (empty($eventId)) {
        return json_encode(['code' => -1, 'message' => 'interaction_id 为空']);
    }
    // 默认 body 使用 {"code": 0}, 与 ElainaBot_v2 框架一致
    $json = $body ?: json_encode(["code" => 0]);
    // 注意: 路径为 /interactions/{id}, 不带 /v2/ 前缀
    $resp = BOTAPI("/interactions/{$eventId}", "PUT", $json);
    return $resp;
}

// ==================== 处理入群申请 (POST /v2/groups/{group_openid}/approval_join_request/{member_openid}) ====================
// 参照官方文档: v2_groups_group_openid_approval_join_request_member_openid.post
// 参数:
//   $groupOpenid  - 群 openid
//   $memberOpenid - 申请入群的成员 openid
//   $approve      - true=同意, false=拒绝
//   $reason       - 拒绝原因 (仅拒绝时有效, 可选)
//   $blacklist    - 是否同时加入黑名单 (可选)
function 处理入群申请($groupOpenid, $memberOpenid, $approve, $reason = '', $blacklist = false) {
    if (empty($groupOpenid) || empty($memberOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 member_openid 为空']);
    }
    $data = ["approve" => (bool)$approve];
    if (!$approve && !empty($reason)) {
        $data["reason"] = $reason;
    }
    if ($blacklist) {
        $data["blacklist"] = true;
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $resp = BOTAPI("/v2/groups/{$groupOpenid}/approval_join_request/{$memberOpenid}", "POST", $json);
    return $resp;
}

// ==================== 发送频道私信 (POST /dms/{guild_id}/messages) ====================
// 参照 ElainaBot_v2 框架: 频道私信(DM)消息发送端点
// 用于频道场景下的私信消息发送
// 参数:
//   $guildId    - 频道 ID
//   $content    - 消息内容
//   $msgId      - 回复的消息 ID (可选, 用于被动消息)
function 发送频道私信($guildId, $content, $msgId = '') {
    if (empty($guildId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 为空']);
    }
    $data = [
        "content" => $content,
        "msg_type" => 0
    ];
    if (!empty($msgId)) {
        $data["msg_id"] = $msgId;
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $resp = BOTAPI("/dms/{$guildId}/messages", "POST", $json);
    return $resp;
}

// ==================== 添加表情表态 (PUT /channels/{channel_id}/messages/{message_id}/reactions/{type}/{id}) ====================
// 参照 QQ Bot API 官方文档: 表情表态接口 (仅频道可用)
// 参数:
//   $channelId - 频道 ID
//   $messageId - 消息 ID
//   $type      - 表情类型 (1=系统表情, 2=自定义表情)
//   $emojiId   - 表情 ID
function 添加表态($channelId, $messageId, $type, $emojiId) {
    if (empty($channelId) || empty($messageId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 或 message_id 为空']);
    }
    $resp = BOTAPI("/channels/{$channelId}/messages/{$messageId}/reactions/{$type}/{$emojiId}", "PUT", "");
    return $resp;
}

// ==================== 删除表情表态 (DELETE /channels/{channel_id}/messages/{message_id}/reactions/{type}/{id}) ====================
// 参照 QQ Bot API 官方文档: 表情表态接口 (仅频道可用)
// 参数:
//   $channelId - 频道 ID
//   $messageId - 消息 ID
//   $type      - 表情类型 (1=系统表情, 2=自定义表情)
//   $emojiId   - 表情 ID
function 删除表态($channelId, $messageId, $type, $emojiId) {
    if (empty($channelId) || empty($messageId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 或 message_id 为空']);
    }
    $resp = BOTAPI("/channels/{$channelId}/messages/{$messageId}/reactions/{$type}/{$emojiId}", "DELETE", "");
    return $resp;
}

// ==================== 获取图片尺寸 ====================
function 图片尺寸($imageSource) {
    if (preg_match('/^https?:\/\//i', $imageSource)) {
        $tempFile = tempnam(sys_get_temp_dir(), 'img_size_');
        $imgData = curl($imageSource, "GET", [], '');
        file_put_contents($tempFile, $imgData);
        $info = @getimagesize($tempFile);
        @unlink($tempFile);
    } elseif (is_file($imageSource)) {
        $info = @getimagesize($imageSource);
    } elseif (strlen($imageSource) > 100 && base64_decode($imageSource, true) !== false) {
        $tempFile = tempnam(sys_get_temp_dir(), 'img_size_');
        file_put_contents($tempFile, base64_decode($imageSource));
        $info = @getimagesize($tempFile);
        @unlink($tempFile);
    } else {
        return false;
    }
    if (!$info) return false;
    return ['width' => $info[0], 'height' => $info[1], 'type' => $info['mime']];
}

// ==================== 图文卡片回复 (msg_type=8, 对应Python reply_tuwen) ====================
function 图文卡片($title, $description, $pic_url, $url) {
    $json = [
        "msg_type" => 8,
        "msg_seq" => mt_rand(1, 99999),
        "card" => [
            "type" => "tuwen",
            "content" => [
                "title" => $title,
                "description" => $description,
                "pic_url" => $pic_url,
                "url" => $url
            ]
        ]
    ];
    switch (消息来源) {
        case "群聊":
            $evId = defined("事件ID") ? 事件ID : "";
           $msgId = defined("消息ID") ? 消息ID : "";
           if (!empty($evId)) $json["event_id"] = $evId;
           elseif (!empty($msgId)) $json["msg_id"] = $msgId;
            $resp = BOTAPI("/v2/groups/" . 来源 . "/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
            break;
        case "私聊":
        case "好友增加":   // C2C生命周期事件
        case "好友删除":
            $evId = defined("事件ID") ? 事件ID : "";
           $msgId = defined("消息ID") ? 消息ID : "";
           if (!empty($evId)) $json["event_id"] = $evId;
           elseif (!empty($msgId)) $json["msg_id"] = $msgId;
            $resp = BOTAPI("/v2/users/" . 来源 . "/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
            break;
        case "加群":
        case "退群":
        case "群成员增加":
        case "群成员移除":
        case "入群申请":   // 群生命周期事件
        case "群消息拒绝":
        case "群消息接收":
            $json["event_id"] = 事件ID;
            $resp = BOTAPI("/v2/groups/" . 来源 . "/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
            break;
        case "互动":
            $json["event_id"] = 事件ID;
            if (互动私聊()) {
                $resp = BOTAPI("/v2/users/" . 互动目标用户() . "/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
            } else {
                $resp = BOTAPI("/v2/groups/" . 来源 . "/messages", "POST", json_encode($json, JSON_UNESCAPED_UNICODE));
            }
            break;
        default:
            return "错误：消息来源不支持";
    }
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    // 存储完整图文卡片数据（JSON），供聊天界面渲染
    $tuwenLogData = ['title' => $title, 'description' => $description, 'pic_url' => $pic_url, 'url' => $url];
    记录发送("发送图文卡片", 来源, json_encode($tuwenLogData, JSON_UNESCAPED_UNICODE), "图文卡片", $messageId, $resp);
    return $resp;
}

// ==================== 通用推送富媒体上传 (不依赖消息来源常量, 对应Python upload_media) ====================
function 推送富媒体($type, $image, $target, $isGroup, $name = null) {
    $types = ["图片" => 1, "视频" => 2, "语音" => 3, "文件" => 4];
    $t = $types[$type] ?? 1;
    if (preg_match('/^http(s)?:\/\//i', $image)) {
        $jsonData = [
            "file_type" => $t,
            "url" => $image,
            "file_name" => $name,
            "srv_send_msg" => false
        ];
    } else {
        $jsonData = [
            "file_type" => $t,
            "file_data" => base64_encode($image),
            "file_name" => $name,
            "srv_send_msg" => false
        ];
    }
    $json = json_encode($jsonData);
    $prefix = $isGroup ? "groups" : "users";
    return json_decode(BOTAPI("/v2/{$prefix}/{$target}/files", "POST", $json), true);
}

// ==================== 主动推送图片到用户 (对应Python send_image user) ====================
function 推送图片到用户($userOpenid, $image) {
    $file_info = 推送富媒体("图片", $image, $userOpenid, false);
    if (isset($file_info['message'])) {
        return 推送到用户($userOpenid, $file_info['message']);
    }
    $file = $file_info['file_info'];
    $json = json_encode([
        "content" => "",
        "msg_type" => 7,
        "msg_seq" => rand(1, 99999),
        "media" => ["file_info" => $file]
    ]);
    $resp = BOTAPI("/v2/users/{$userOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '私聊', $userOpenid, '图片', $image, $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送语音到群 ====================
function 推送语音到群($groupOpenid, $voice) {
    $silk = silk($voice);
    $file_info = 推送富媒体("语音", $silk, $groupOpenid, true);
    if (isset($file_info['message'])) {
        return 推送到群($groupOpenid, $file_info['message']);
    }
    $file = $file_info['file_info'];
    $json = json_encode([
        "content" => "",
        "msg_type" => 7,
        "msg_seq" => rand(1, 99999),
        "media" => ["file_info" => $file]
    ]);
    $resp = BOTAPI("/v2/groups/{$groupOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '群聊', $groupOpenid, '语音', $voice, $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送语音到用户 ====================
function 推送语音到用户($userOpenid, $voice) {
    $silk = silk($voice);
    $file_info = 推送富媒体("语音", $silk, $userOpenid, false);
    if (isset($file_info['message'])) {
        return 推送到用户($userOpenid, $file_info['message']);
    }
    $file = $file_info['file_info'];
    $json = json_encode([
        "content" => "",
        "msg_type" => 7,
        "msg_seq" => rand(1, 99999),
        "media" => ["file_info" => $file]
    ]);
    $resp = BOTAPI("/v2/users/{$userOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '私聊', $userOpenid, '语音', $voice, $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送视频到群 ====================
function 推送视频到群($groupOpenid, $video) {
    $file_info = 推送富媒体("视频", $video, $groupOpenid, true);
    if (isset($file_info['message'])) {
        return 推送到群($groupOpenid, $file_info['message']);
    }
    $file = $file_info['file_info'];
    $json = json_encode([
        "content" => "",
        "msg_type" => 7,
        "msg_seq" => rand(1, 99999),
        "media" => ["file_info" => $file]
    ]);
    $resp = BOTAPI("/v2/groups/{$groupOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '群聊', $groupOpenid, '视频', $video, $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送视频到用户 ====================
function 推送视频到用户($userOpenid, $video) {
    $file_info = 推送富媒体("视频", $video, $userOpenid, false);
    if (isset($file_info['message'])) {
        return 推送到用户($userOpenid, $file_info['message']);
    }
    $file = $file_info['file_info'];
    $json = json_encode([
        "content" => "",
        "msg_type" => 7,
        "msg_seq" => rand(1, 99999),
        "media" => ["file_info" => $file]
    ]);
    $resp = BOTAPI("/v2/users/{$userOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '私聊', $userOpenid, '视频', $video, $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送文件到群 ====================
function 推送文件到群($groupOpenid, $file, $name = null) {
    if ($name === null) {
        $name = 'file';
        $path = parse_url($file, PHP_URL_PATH);
        if ($path) {
            $basename = basename($path);
            if ($basename && strpos($basename, '.') !== false) $name = $basename;
        }
        $name = preg_replace('/\?.*$/', '', $name);
        if (empty($name)) $name = 'file';
    }
    $file_info = 推送富媒体("文件", $file, $groupOpenid, true, $name);
    if (isset($file_info['message'])) {
        return 推送到群($groupOpenid, $file_info['message']);
    }
    $fileInfo = $file_info['file_info'];
    $json = json_encode([
        "content" => "",
        "msg_type" => 7,
        "msg_seq" => rand(1, 99999),
        "media" => ["file_info" => $fileInfo]
    ]);
    $resp = BOTAPI("/v2/groups/{$groupOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '群聊', $groupOpenid, '文件', $file, $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送文件到用户 ====================
function 推送文件到用户($userOpenid, $file, $name = null) {
    if ($name === null) {
        $name = 'file';
        $path = parse_url($file, PHP_URL_PATH);
        if ($path) {
            $basename = basename($path);
            if ($basename && strpos($basename, '.') !== false) $name = $basename;
        }
        $name = preg_replace('/\?.*$/', '', $name);
        if (empty($name)) $name = 'file';
    }
    $file_info = 推送富媒体("文件", $file, $userOpenid, false, $name);
    if (isset($file_info['message'])) {
        return 推送到用户($userOpenid, $file_info['message']);
    }
    $fileInfo = $file_info['file_info'];
    $json = json_encode([
        "content" => "",
        "msg_type" => 7,
        "msg_seq" => rand(1, 99999),
        "media" => ["file_info" => $fileInfo]
    ]);
    $resp = BOTAPI("/v2/users/{$userOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '私聊', $userOpenid, '文件', $file, $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送Ark卡片到群 ====================
function 推送Ark到群($groupOpenid, $template_id, $kv) {
    $arkKv = [];
    if (isset($kv[0]) && is_array($kv[0]) && isset($kv[0]['key'])) {
        $arkKv = $kv;
    } else {
        foreach ($kv as $k => $v) {
            $arkKv[] = ["key" => $k, "value" => $v];
        }
    }
    $json = json_encode([
        "msg_type" => 3,
        "msg_seq" => rand(1, 99999),
        "ark" => [
            "template_id" => $template_id,
            "kv" => $arkKv
        ]
    ], JSON_UNESCAPED_UNICODE);
    $resp = BOTAPI("/v2/groups/{$groupOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '群聊', $groupOpenid, 'Ark', "模板: {$template_id}", $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送Ark卡片到用户 ====================
function 推送Ark到用户($userOpenid, $template_id, $kv) {
    $arkKv = [];
    if (isset($kv[0]) && is_array($kv[0]) && isset($kv[0]['key'])) {
        $arkKv = $kv;
    } else {
        foreach ($kv as $k => $v) {
            $arkKv[] = ["key" => $k, "value" => $v];
        }
    }
    $json = json_encode([
        "msg_type" => 3,
        "msg_seq" => rand(1, 99999),
        "ark" => [
            "template_id" => $template_id,
            "kv" => $arkKv
        ]
    ], JSON_UNESCAPED_UNICODE);
    $resp = BOTAPI("/v2/users/{$userOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '私聊', $userOpenid, 'Ark', "模板: {$template_id}", $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送图文卡片到群 ====================
function 推送图文到群($groupOpenid, $title, $description, $pic_url, $url) {
    $json = json_encode([
        "msg_type" => 8,
        "msg_seq" => rand(1, 99999),
        "card" => [
            "type" => "tuwen",
            "content" => [
                "title" => $title,
                "description" => $description,
                "pic_url" => $pic_url,
                "url" => $url
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
    $resp = BOTAPI("/v2/groups/{$groupOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '群聊', $groupOpenid, '图文卡片', "标题: {$title}", $messageId, null, $resp);
    return $resp;
}

// ==================== 主动推送图文卡片到用户 ====================
function 推送图文到用户($userOpenid, $title, $description, $pic_url, $url) {
    $json = json_encode([
        "msg_type" => 8,
        "msg_seq" => rand(1, 99999),
        "card" => [
            "type" => "tuwen",
            "content" => [
                "title" => $title,
                "description" => $description,
                "pic_url" => $pic_url,
                "url" => $url
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);
    $resp = BOTAPI("/v2/users/{$userOpenid}/messages", "POST", $json);
    $data = json_decode($resp, true);
    $messageId = $data['id'] ?? '';
    logMessage(appid, '发送', '私聊', $userOpenid, '图文卡片', "标题: {$title}", $messageId, null, $resp);
    return $resp;
}

// ==================== 获取机器人在群中的成员信息 (对应Python get_bot_member) ====================
function 获取机器人成员($groupOpenid) {
    $botInfo = BOT信息();
    $botData = json_decode($botInfo, true);
    $botOpenid = $botData['id'] ?? '';
    if (empty($botOpenid)) return null;
    return 获取群成员($groupOpenid, $botOpenid);
}

// ==================== 管理员判断 (基于 bots 表 owner_ids 字段) ====================
// 参照官方文档: 机器人拥有者可在管理后台「机器人设置-管理员」配置
// owner_ids 为 JSON 数组，存储拥有管理员权限的用户 openid
// 参数:
//   $userId - 待检测的用户 openid，默认取当前事件「用户」常量
// 返回: true=是管理员, false=非管理员
function 是否管理员($userId = null) {
    if ($userId === null && defined('用户')) {
        $userId = 用户;
    }
    if (empty($userId) || !defined('appid')) {
        return false;
    }
    $bot = getBot(appid);
    if (!$bot) {
        return false;
    }
    $ownerIdsRaw = $bot['owner_ids'] ?? '[]';
    $ownerIds = json_decode($ownerIdsRaw, true);
    if (!is_array($ownerIds)) {
        return false;
    }
    return in_array((string)$userId, array_map('strval', $ownerIds), true);
}

// ==================== 设置机器人自定义菜单 (PUT /v2/menu) ====================
// 参照官方文档: 自定义菜单（单聊场景下的"快捷菜单"面板）
// 支持的菜单项类型:
//   send_message - 发送消息按钮 (字段: name, send_message, 可选 icon)
//   link         - 链接跳转按钮 (字段: name, link，link 必须 https://)
//   switch       - 开关按钮 (字段: name, switch:{switch_id, default})
//   menu         - 折叠子菜单 (字段: name, sub_menu_items:[...])
// 参数:
//   $menuData - 菜单结构数组，如 ["items" => [...]] 或 ["menu" => ["items" => [...]]]
// 返回: API 原始响应
function 设置菜单($menuData) {
    // 兼容两种传入格式: ["items"=>[...]] 或 ["menu"=>["items"=>[...]]]
    if (isset($menuData['menu'])) {
        $payload = $menuData;
    } else {
        $payload = ['menu' => $menuData];
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $resp = BOTAPI("/v2/menu", "PUT", $json);
    wlog("[设置菜单] 请求: " . $json . " 响应: " . $resp, defined('appid') ? appid : null);
    return $resp;
}

// ==================== 获取机器人自定义菜单 (GET /v2/menu) ====================
// 返回: 当前已设置的菜单结构（JSON 字符串）
function 获取菜单() {
    return BOTAPI("/v2/menu", "GET", "");
}

// ==================== 删除机器人自定义菜单 (DELETE /v2/menu) ====================
function 删除菜单() {
    return BOTAPI("/v2/menu", "DELETE", "");
}

// ==================== 频道指定成员禁言 (PATCH /guilds/{guild_id}/members/{user_id}/mute) ====================
// 参照官方文档: 频道指定成员禁言（需机器人被添加为频道管理员）
// 参数:
//   $guildId  - 频道 ID
//   $userId   - 被禁言成员的 user_id
//   $seconds  - 禁言时长（秒），传 0 表示解除禁言
// 返回: API 原始响应
function 禁言成员($guildId, $userId, $seconds) {
    if (empty($guildId) || empty($userId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 或 user_id 为空']);
    }
    $json = json_encode(['mute_seconds' => (string)$seconds], JSON_UNESCAPED_UNICODE);
    return BOTAPI("/guilds/{$guildId}/members/{$userId}/mute", "PATCH", $json);
}

// ==================== 频道解除成员禁言 ====================
// 禁言成员的快捷封装，seconds=0 即解除禁言
function 解禁成员($guildId, $userId) {
    return 禁言成员($guildId, $userId, 0);
}

// ==================== 频道批量成员禁言 (PATCH /guilds/{guild_id}/mute) ====================
// 参照官方文档: 频道批量成员禁言（同样可用于批量解除禁言，seconds=0）
// 参数:
//   $guildId  - 频道 ID
//   $userIds  - 成员 user_id 数组
//   $seconds  - 禁言时长（秒），0 表示解除禁言
function 批量禁言($guildId, $userIds, $seconds) {
    if (empty($guildId) || empty($userIds) || !is_array($userIds)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 或 user_ids 为空']);
    }
    $json = json_encode([
        'mute_seconds' => (string)$seconds,
        'user_ids' => array_values($userIds)
    ], JSON_UNESCAPED_UNICODE);
    return BOTAPI("/guilds/{$guildId}/mute", "PATCH", $json);
}

// ==================== 频道批量解除禁言 ====================
function 批量解禁($guildId, $userIds) {
    return 批量禁言($guildId, $userIds, 0);
}

// ==================== 频道全员禁言 (PATCH /guilds/{guild_id}/mute) ====================
// 不传 user_ids 即对整个频道禁言
// 参数:
//   $guildId  - 频道 ID
//   $seconds  - 禁言时长（秒），0 表示解除全员禁言
function 全员禁言($guildId, $seconds) {
    if (empty($guildId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 为空']);
    }
    $json = json_encode(['mute_seconds' => (string)$seconds], JSON_UNESCAPED_UNICODE);
    return BOTAPI("/guilds/{$guildId}/mute", "PATCH", $json);
}

// ==================== 频道解除全员禁言 ====================
function 解除全员禁言($guildId) {
    return 全员禁言($guildId, 0);
}

// ==================== 频道移除成员 (DELETE /guilds/{guild_id}/members/{user_id}) ====================
// 参照官方文档: 删除频道成员（踢出成员，需管理员权限）
// 参数:
//   $guildId  - 频道 ID
//   $userId   - 被移除成员的 user_id
function 踢出成员($guildId, $userId) {
    if (empty($guildId) || empty($userId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 或 user_id 为空']);
    }
    return BOTAPI("/guilds/{$guildId}/members/{$userId}", "DELETE", "");
}

// ==================== 群聊禁言 - 设置群成员禁言 (POST /v2/groups/{group_openid}/restrict_chat_setting) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_group_openid_restrict_chat_setting.post.html
// 机器人需拥有群管理员身份。单次设置不能超过10个成员。
// 参数:
//   $groupOpenid - 群 OpenID
//   $members     - 禁言操作列表，每项: ["op"=>"add|update|del", "member_openid"=>"xxx", "mute_expire_at"=>"RFC3339时间"]
//                  op: add 增加禁言, update 更新禁言到期时间, del 解除禁言
//                  mute_expire_at: 禁言到期时间(RFC3339格式)，op=del 时可传空串立即解除
// 返回: API 原始响应（成功返回 {}）
function 设置群成员禁言($groupOpenid, $members) {
    if (empty($groupOpenid) || empty($members) || !is_array($members)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 members 为空']);
    }
    if (count($members) > 10) {
        return json_encode(['code' => -1, 'message' => '单次设置不能超过10个成员']);
    }
    $json = json_encode(['members' => array_values($members)], JSON_UNESCAPED_UNICODE);
    $resp = BOTAPI("/v2/groups/{$groupOpenid}/restrict_chat_setting", "POST", $json);
    wlog("[设置群成员禁言] group={$groupOpenid} 请求: " . $json . " 响应: " . $resp, defined('appid') ? appid : null);
    return $resp;
}

// ==================== 群聊禁言 - 查询群禁言状态 (GET /v2/groups/{group_openid}/restrict_chat_setting) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_group_openid_restrict_chat_setting.get.html
// 返回: global_rule(全员禁言配置 mode/schedule_rules/recurring_rules) + members(禁言中的用户列表)
function 查询群禁言状态($groupOpenid) {
    if (empty($groupOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 为空']);
    }
    return BOTAPI("/v2/groups/{$groupOpenid}/restrict_chat_setting", "GET", "");
}

// ==================== 群聊禁言 - 封装: 禁言单个群成员 ====================
// 将秒数转为 RFC3339 格式的到期时间，调用 设置群成员禁言(op=add)
// 参数:
//   $groupOpenid  - 群 OpenID
//   $memberOpenid - 被禁言成员的 openid（只能操作普通成员，不能操作群主/管理员/机器人）
//   $seconds      - 禁言时长（秒），0 表示立即解除
function 群禁言成员($groupOpenid, $memberOpenid, $seconds) {
    if (empty($groupOpenid) || empty($memberOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 member_openid 为空']);
    }
    if ($seconds <= 0) {
        return 群解禁成员($groupOpenid, $memberOpenid);
    }
    $expireAt = date('c', time() + intval($seconds)); // RFC3339 格式
    $members = [['op' => 'add', 'member_openid' => $memberOpenid, 'mute_expire_at' => $expireAt]];
    return 设置群成员禁言($groupOpenid, $members);
}

// ==================== 群聊禁言 - 封装: 解除单个群成员禁言 ====================
function 群解禁成员($groupOpenid, $memberOpenid) {
    if (empty($groupOpenid) || empty($memberOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 member_openid 为空']);
    }
    $members = [['op' => 'del', 'member_openid' => $memberOpenid, 'mute_expire_at' => '']];
    return 设置群成员禁言($groupOpenid, $members);
}

// ==================== 群聊禁言 - 封装: 批量禁言群成员 ====================
// 单次最多10个成员（API限制），超过自动分批
function 群批量禁言($groupOpenid, $memberOpenids, $seconds) {
    if (empty($groupOpenid) || empty($memberOpenids) || !is_array($memberOpenids)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 member_openids 为空']);
    }
    if ($seconds <= 0) {
        return 群批量解禁($groupOpenid, $memberOpenids);
    }
    $expireAt = date('c', time() + intval($seconds));
    $memberOpenids = array_values($memberOpenids);
    $results = [];
    // 每10个一批
    foreach (array_chunk($memberOpenids, 10) as $batch) {
        $members = [];
        foreach ($batch as $oid) {
            $members[] = ['op' => 'add', 'member_openid' => $oid, 'mute_expire_at' => $expireAt];
        }
        $results[] = 设置群成员禁言($groupOpenid, $members);
    }
    // 单批直接返回，多批返回汇总
    if (count($results) === 1) return $results[0];
    return json_encode(['code' => 0, 'message' => 'batch done', 'results' => $results], JSON_UNESCAPED_UNICODE);
}

// ==================== 群聊禁言 - 封装: 批量解除禁言 ====================
function 群批量解禁($groupOpenid, $memberOpenids) {
    if (empty($groupOpenid) || empty($memberOpenids) || !is_array($memberOpenids)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 member_openids 为空']);
    }
    $memberOpenids = array_values($memberOpenids);
    $results = [];
    foreach (array_chunk($memberOpenids, 10) as $batch) {
        $members = [];
        foreach ($batch as $oid) {
            $members[] = ['op' => 'del', 'member_openid' => $oid, 'mute_expire_at' => ''];
        }
        $results[] = 设置群成员禁言($groupOpenid, $members);
    }
    if (count($results) === 1) return $results[0];
    return json_encode(['code' => 0, 'message' => 'batch done', 'results' => $results], JSON_UNESCAPED_UNICODE);
}

// ==================== 指令面板 - 创建 (POST /v2/panels) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels.post.html
// 支持 c2c/group/channel/dm 四种场景。一个机器人最多20个面板。
// 参数 $data 结构:
//   scope: c2c|group|channel|dm (必填)
//   target_type: all|specific (c2c/group 支持 specific，channel/dm 仅 all)
//   user_openids: []string (c2c+specific 时有效，最多20个)
//   group_openids: []string (group+specific 时有效，最多20个)
//   panel: {items:[{name,desc,type:command|link,only_admin,link}], remark, version} (items最多20个)
// 返回: {"panel_id":"p_xxx"}
function 创建指令面板($data) {
    if (empty($data) || !is_array($data)) {
        return json_encode(['code' => -1, 'message' => '面板数据为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $resp = BOTAPI("/v2/panels", "POST", $json);
    wlog("[创建指令面板] 请求: " . $json . " 响应: " . $resp, defined('appid') ? appid : null);
    return $resp;
}

// ==================== 指令面板 - 查询列表 (GET /v2/panels) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels.get.html
// 参数:
//   $scope  - 生效场景 c2c|group|channel|dm (必填)
//   $cursor - 分页游标(可选)
//   $limit  - 每页条数(可选,默认20,最大50)
function 查询面板列表($scope, $cursor = '', $limit = 20) {
    if (empty($scope)) {
        return json_encode(['code' => -1, 'message' => 'scope 为空']);
    }
    $query = "scope=" . urlencode($scope) . "&limit=" . intval($limit);
    if ($cursor !== '') {
        $query .= "&cursor=" . urlencode($cursor);
    }
    return BOTAPI("/v2/panels?{$query}", "GET", "");
}

// ==================== 指令面板 - 查询详情 (GET /v2/panels/{panel_id}) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels_panel_id.get.html
function 查询面板详情($panelId) {
    if (empty($panelId)) {
        return json_encode(['code' => -1, 'message' => 'panel_id 为空']);
    }
    return BOTAPI("/v2/panels/{$panelId}", "GET", "");
}

// ==================== 指令面板 - 修改 (PUT /v2/panels/{panel_id}) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels_panel_id.put.html
// 参数 $data: panel 配置内容(同创建时的 panel 字段结构)
function 修改指令面板($panelId, $data) {
    if (empty($panelId) || empty($data)) {
        return json_encode(['code' => -1, 'message' => 'panel_id 或数据为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/v2/panels/{$panelId}", "PUT", $json);
}

// ==================== 指令面板 - 删除 (DELETE /v2/panels/{panel_id}) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels_panel_id.delete.html
function 删除指令面板($panelId) {
    if (empty($panelId)) {
        return json_encode(['code' => -1, 'message' => 'panel_id 为空']);
    }
    return BOTAPI("/v2/panels/{$panelId}", "DELETE", "");
}

// ==================== 指令面板 - 修改关联对象 (PUT /v2/panels/{panel_id}/target) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_panels_panel_id_target.put.html
// 用于增删面板关联的用户/群 openid(仅 target_type=specific 的面板支持)
// 参数 $data: 关联对象配置
function 修改面板关联对象($panelId, $data) {
    if (empty($panelId) || empty($data)) {
        return json_encode(['code' => -1, 'message' => 'panel_id 或数据为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/v2/panels/{$panelId}/target", "PUT", $json);
}

// ==================== 入群审批策略 - 创建 (POST /v2/groups/join_approval_strategy) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_join_approval_strategy.post.html
// 一个机器人最多20个策略。机器人需群管理员身份才生效。
// 参数 $data:
//   group_openids: []string (与 group_ids 二选一，最多100个)
//   group_ids: []uint64 (与 group_openids 互斥)
//   is_enable: "on"|"off" (默认 on)
//   expire_at: RFC3339格式过期时间(不传默认一年)
//   remark: 备注(最多255汉字)
// 返回: {"strategy_id":"st_xxx","is_enable":"on","expire_at":"..."}
function 创建入群审批策略($data) {
    if (empty($data) || !is_array($data)) {
        return json_encode(['code' => -1, 'message' => '策略数据为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $resp = BOTAPI("/v2/groups/join_approval_strategy", "POST", $json);
    wlog("[创建入群审批策略] 请求: " . $json . " 响应: " . $resp, defined('appid') ? appid : null);
    return $resp;
}

// ==================== 入群审批策略 - 查询列表 (GET /v2/groups/join_approval_strategy) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_join_approval_strategy.get.html
// 参数:
//   $cursor - 分页游标(可选)
//   $limit  - 每页条数(可选,默认20,最大100)
function 查询入群审批策略列表($cursor = '', $limit = 20) {
    $query = "limit=" . intval($limit);
    if ($cursor !== '') {
        $query .= "&cursor=" . urlencode($cursor);
    }
    return BOTAPI("/v2/groups/join_approval_strategy?{$query}", "GET", "");
}

// ==================== 入群审批策略 - 修改 (PATCH /v2/groups/join_approval_strategy/{strategy_id}) ====================
// 参照官方文档: https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_join_approval_strategy_strategy_id.patch.html
function 修改入群审批策略($strategyId, $data) {
    if (empty($strategyId) || empty($data)) {
        return json_encode(['code' => -1, 'message' => 'strategy_id 或数据为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/v2/groups/join_approval_strategy/{$strategyId}", "PATCH", $json);
}

// ==================== 入群审批策略 - 删除 (DELETE /v2/groups/join_approval_strategy/{strategy_id}) ====================
function 删除入群审批策略($strategyId) {
    if (empty($strategyId)) {
        return json_encode(['code' => -1, 'message' => 'strategy_id 为空']);
    }
    return BOTAPI("/v2/groups/join_approval_strategy/{$strategyId}", "DELETE", "");
}

// ============================================================================
// 频道信息 (Guild) - 参照 https://bot.q.qq.com/wiki/develop/api-v2/server-inter/channel/guild-controller.html
// ============================================================================

// ==================== 获取频道详情 (GET /guilds/{guild_id}) ====================
function 获取频道详情($guildId) {
    if (empty($guildId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 为空']);
    }
    return BOTAPI("/guilds/{$guildId}", "GET", "");
}

// ==================== 修改频道信息 (PATCH /guilds/{guild_id}) ====================
// 参数 $data: name / icon / message_notify (/.guild) 任意子集
function 修改频道信息($guildId, $data) {
    if (empty($guildId) || empty($data)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 或数据为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/guilds/{$guildId}", "PATCH", $json);
}

// ==================== 获取机器人加入的频道列表 (GET /users/@me/guilds) ====================
// 参数 $before / $after 互斥, $limit 1-100 默认 100
function 获取机器人频道列表($before = '', $after = '', $limit = 100) {
    $query = "limit=" . max(1, min(100, intval($limit)));
    if ($before !== '') $query .= "&before=" . urlencode($before);
    elseif ($after !== '') $query .= "&after=" . urlencode($after);
    return BOTAPI("/users/@me/guilds?{$query}", "GET", "");
}

// ============================================================================
// 频道成员 (Member) - 参照 user-controller.html
// ============================================================================

// ==================== 获取频道成员详情 (GET /guilds/{guild_id}/members/{user_id}) ====================
function 获取频道成员($guildId, $userId) {
    if (empty($guildId) || empty($userId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 或 user_id 为空']);
    }
    return BOTAPI("/guilds/{$guildId}/members/{$userId}", "GET", "");
}

// ==================== 获取频道成员列表 (GET /guilds/{guild_id}/members) ====================
// 参数 $after 上次回包最后一个 member 的 user_id, 首次填 0; $limit 1-400 默认 1
function 获取频道成员列表($guildId, $after = '0', $limit = 1) {
    if (empty($guildId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 为空']);
    }
    $query = "limit=" . max(1, min(400, intval($limit))) . "&after=" . urlencode($after);
    return BOTAPI("/guilds/{$guildId}/members?{$query}", "GET", "");
}

// ==================== 获取身份组成员列表 (GET /guilds/{guild_id}/roles/{role_id}/members) ====================
// 参数 $startIndex 上次回包 next, 首次填 0; $limit 1-400 默认 1
function 获取身份组成员列表($guildId, $roleId, $startIndex = '0', $limit = 1) {
    if (empty($guildId) || empty($roleId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 或 role_id 为空']);
    }
    $query = "limit=" . max(1, min(400, intval($limit))) . "&start_index=" . urlencode($startIndex);
    return BOTAPI("/guilds/{$guildId}/roles/{$roleId}/members?{$query}", "GET", "");
}

// ==================== 删除频道成员 (DELETE /guilds/{guild_id}/members/{user_id}) ====================
// $addBlacklist bool 是否同时加入黑名单
// $deleteHistoryMsgDays int 0=不撤回 / 3,7,15,30=固定天数 / -1=撤回全部
function 移除频道成员($guildId, $userId, $addBlacklist = false, $deleteHistoryMsgDays = 0) {
    if (empty($guildId) || empty($userId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 或 user_id 为空']);
    }
    $data = [
        'add_blacklist' => $addBlacklist ? true : false,
        'delete_history_msg_days' => intval($deleteHistoryMsgDays),
    ];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/guilds/{$guildId}/members/{$userId}", "DELETE", $json);
}

// ============================================================================
// 身份组 (Role) - 参照 role-controller.html
// ============================================================================

// ==================== 获取身份组列表 (GET /guilds/{guild_id}/roles) ====================
function 获取身份组列表($guildId) {
    if (empty($guildId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 为空']);
    }
    return BOTAPI("/guilds/{$guildId}/roles", "GET", "");
}

// ==================== 创建身份组 (POST /guilds/{guild_id}/roles) ====================
// 参数 $data: name(名称) / color(ARGB HEX 转十进制) / hoist(0 否 1 是) 任意子集但至少一个
function 创建身份组($guildId, $data) {
    if (empty($guildId) || empty($data)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 或数据为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/guilds/{$guildId}/roles", "POST", $json);
}

// ==================== 修改身份组 (PATCH /guilds/{guild_id}/roles/{role_id}) ====================
function 修改身份组($guildId, $roleId, $data) {
    if (empty($guildId) || empty($roleId) || empty($data)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/guilds/{$guildId}/roles/{$roleId}", "PATCH", $json);
}

// ==================== 删除身份组 (DELETE /guilds/{guild_id}/roles/{role_id}) ====================
function 删除身份组($guildId, $roleId) {
    if (empty($guildId) || empty($roleId)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    return BOTAPI("/guilds/{$guildId}/roles/{$roleId}", "DELETE", "");
}

// ==================== 增加成员身份组 (PUT /guilds/{guild_id}/members/{user_id}/roles/{role_id}) ====================
// 参数 $channelId: 仅当 role_id=5 (子频道管理员) 时需指定子频道
function 增加成员身份组($guildId, $userId, $roleId, $channelId = '') {
    if (empty($guildId) || empty($userId) || empty($roleId)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    $data = [];
    if (!empty($channelId)) {
        $data['channel'] = ['id' => $channelId];
    }
    $json = empty($data) ? '' : json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/guilds/{$guildId}/members/{$userId}/roles/{$roleId}", "PUT", $json);
}

// ==================== 删除成员身份组 (DELETE /guilds/{guild_id}/members/{user_id}/roles/{role_id}) ====================
function 删除成员身份组($guildId, $userId, $roleId, $channelId = '') {
    if (empty($guildId) || empty($userId) || empty($roleId)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    $data = [];
    if (!empty($channelId)) {
        $data['channel'] = ['id' => $channelId];
    }
    $json = empty($data) ? '' : json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/guilds/{$guildId}/members/{$userId}/roles/{$roleId}", "DELETE", $json);
}

// ============================================================================
// 子频道 (Channel) - 参照 channel-controller.html
// ============================================================================

// ==================== 获取子频道列表 (GET /guilds/{guild_id}/channels) ====================
function 获取子频道列表($guildId) {
    if (empty($guildId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 为空']);
    }
    return BOTAPI("/guilds/{$guildId}/channels", "GET", "");
}

// ==================== 获取子频道详情 (GET /channels/{channel_id}) ====================
function 获取子频道详情($channelId) {
    if (empty($channelId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 为空']);
    }
    return BOTAPI("/channels/{$channelId}", "GET", "");
}

// ==================== 创建子频道 (POST /guilds/{guild_id}/channels) ====================
// 参数 $data: name / type / sub_type / position / parent_id / private_type / private_user_ids / speak_permission / application_id
function 创建子频道($guildId, $data) {
    if (empty($guildId) || empty($data)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/guilds/{$guildId}/channels", "POST", $json);
}

// ==================== 修改子频道 (PATCH /channels/{channel_id}) ====================
function 修改子频道($channelId, $data) {
    if (empty($channelId) || empty($data)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/channels/{$channelId}", "PATCH", $json);
}

// ==================== 删除子频道 (DELETE /channels/{channel_id}) ====================
function 删除子频道($channelId) {
    if (empty($channelId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 为空']);
    }
    return BOTAPI("/channels/{$channelId}", "DELETE", "");
}

// ============================================================================
// 公告 (Announces) - 参照 announces-controller.html
// ============================================================================

// ==================== 创建频道全局公告 (POST /guilds/{guild_id}/announces) ====================
// 参数 $data:
//   message_id (string, 选填) 消息 id; 有值时优先创建消息类型公告
//   channel_id (string, 选填) 子频道 id; message_id 有值则必填
//   announces_type (uint32, 选填) 0=成员公告 1=欢迎公告
//   recommend_channels ([]RecommendChannel, 选填) 推荐子频道列表 [{channel_id, introduce}]
function 创建频道公告($guildId, $data) {
    if (empty($guildId) || empty($data)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/guilds/{$guildId}/announces", "POST", $json);
}

// ==================== 删除频道全局公告 (DELETE /guilds/{guild_id}/announces/{message_id}) ====================
// message_id=all 时不校验直接删除全部
function 删除频道公告($guildId, $messageId = 'all') {
    if (empty($guildId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 为空']);
    }
    return BOTAPI("/guilds/{$guildId}/announces/{$messageId}", "DELETE", "");
}

// ============================================================================
// 音频 (Audio) - 参照 audio-controller.html
// ============================================================================

// ==================== 音频控制 (POST /channels/{channel_id}/audio) ====================
// 参数 $status: 0=开始播放(需 audio_url, text 可选) 1=暂停 2=继续 3=停止
// 参数 $audioUrl: 音频 url (status=0 时必填)
// 参数 $text: 状态文本 (status=0 时可选, 其他状态不传)
function 音频控制($channelId, $status, $audioUrl = '', $text = '') {
    if (empty($channelId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 为空']);
    }
    $data = ['status' => intval($status)];
    if (intval($status) === 0) {
        if (!empty($audioUrl)) $data['audio_url'] = $audioUrl;
        if (!empty($text)) $data['text'] = $text;
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/channels/{$channelId}/audio", "POST", $json);
}

// ==================== 机器人上麦 (PUT /channels/{channel_id}/mic) ====================
function 机器人上麦($channelId) {
    if (empty($channelId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 为空']);
    }
    return BOTAPI("/channels/{$channelId}/mic", "PUT", "");
}

// ==================== 机器人下麦 (DELETE /channels/{channel_id}/mic) ====================
function 机器人下麦($channelId) {
    if (empty($channelId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 为空']);
    }
    return BOTAPI("/channels/{$channelId}/mic", "DELETE", "");
}

// ============================================================================
// 子频道权限 (Permissions) - 参照 permissions-controller.html
// ============================================================================

// ==================== 获取子频道用户权限 (GET /channels/{channel_id}/members/{user_id}/permissions) ====================
function 获取子频道用户权限($channelId, $userId) {
    if (empty($channelId) || empty($userId)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    return BOTAPI("/channels/{$channelId}/members/{$userId}/permissions", "GET", "");
}

// ==================== 获取子频道身份组权限 (GET /channels/{channel_id}/roles/{role_id}/permissions) ====================
function 获取子频道身份组权限($channelId, $roleId) {
    if (empty($channelId) || empty($roleId)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    return BOTAPI("/channels/{$channelId}/roles/{$roleId}/permissions", "GET", "");
}

// ==================== 修改子频道用户权限 (PUT /channels/{channel_id}/members/{user_id}/permissions) ====================
// 参数 $add / $remove: 位图字符串(十进制) 表示赋予/删除的权限 (1=可查看 2=可管理 4=可发言)
// 注意: 不支持修改"可管理子频道"权限
function 修改子频道用户权限($channelId, $userId, $add = '', $remove = '') {
    if (empty($channelId) || empty($userId)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    $data = [];
    if ($add !== '') $data['add'] = (string)$add;
    if ($remove !== '') $data['remove'] = (string)$remove;
    if (empty($data)) {
        return json_encode(['code' => -1, 'message' => 'add/remove 至少传一个']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/channels/{$channelId}/members/{$userId}/permissions", "PUT", $json);
}

// ==================== 修改子频道身份组权限 (PUT /channels/{channel_id}/roles/{role_id}/permissions) ====================
function 修改子频道身份组权限($channelId, $roleId, $add = '', $remove = '') {
    if (empty($channelId) || empty($roleId)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    $data = [];
    if ($add !== '') $data['add'] = (string)$add;
    if ($remove !== '') $data['remove'] = (string)$remove;
    if (empty($data)) {
        return json_encode(['code' => -1, 'message' => 'add/remove 至少传一个']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/channels/{$channelId}/roles/{$roleId}/permissions", "PUT", $json);
}

// ============================================================================
// 频道消息频率设置 (MessageSetting) - 参照 mute-controller.html
// ============================================================================

// ==================== 获取频道消息频率设置 (GET /guilds/{guild_id}/message/setting) ====================
function 获取频道消息频率($guildId) {
    if (empty($guildId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 为空']);
    }
    return BOTAPI("/guilds/{$guildId}/message/setting", "GET", "");
}

// ============================================================================
// 日程 (Schedule) - 参照 schedule-controller.html
// ============================================================================

// ==================== 获取日程列表 (GET /channels/{channel_id}/schedules) ====================
// 参数 $since: 起始时间戳(ms), 不传返回当天日程
function 获取日程列表($channelId, $since = 0) {
    if (empty($channelId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 为空']);
    }
    $query = $since > 0 ? "?since=" . intval($since) : "";
    return BOTAPI("/channels/{$channelId}/schedules{$query}", "GET", "");
}

// ==================== 获取日程详情 (GET /channels/{channel_id}/schedules/{schedule_id}) ====================
function 获取日程详情($channelId, $scheduleId) {
    if (empty($channelId) || empty($scheduleId)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    return BOTAPI("/channels/{$channelId}/schedules/{$scheduleId}", "GET", "");
}

// ==================== 创建日程 (POST /channels/{channel_id}/schedules) ====================
// 参数 $schedule: 日程对象 (name, description, start_timestamp, end_timestamp, jump_channel_id, remind_type)
function 创建日程($channelId, $schedule) {
    if (empty($channelId) || empty($schedule)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    $json = json_encode(['schedule' => $schedule], JSON_UNESCAPED_UNICODE);
    return BOTAPI("/channels/{$channelId}/schedules", "POST", $json);
}

// ==================== 修改日程 (PATCH /channels/{channel_id}/schedules/{schedule_id}) ====================
function 修改日程($channelId, $scheduleId, $schedule) {
    if (empty($channelId) || empty($scheduleId) || empty($schedule)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    $json = json_encode(['schedule' => $schedule], JSON_UNESCAPED_UNICODE);
    return BOTAPI("/channels/{$channelId}/schedules/{$scheduleId}", "PATCH", $json);
}

// ==================== 删除日程 (DELETE /channels/{channel_id}/schedules/{schedule_id}) ====================
function 删除日程($channelId, $scheduleId) {
    if (empty($channelId) || empty($scheduleId)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    return BOTAPI("/channels/{$channelId}/schedules/{$scheduleId}", "DELETE", "");
}

// ============================================================================
// 子频道消息发送 (POST /channels/{channel_id}/messages) - 参照 post_messages.html
// ============================================================================

// ==================== 发送子频道消息 (POST /channels/{channel_id}/messages) ====================
// 参数 $data:
//   content (string, 选填) 文本内容
//   embed (object, 选填) embed 消息
//   ark (object, 选填) ark 消息
//   message_reference (object, 选填) 引用消息对象
//   image (string, 选填) 图片 URL(域名需报备)
//   msg_id (string, 选填) 被动回复的消息 ID(取自 AT_MESSAGE_CREATE 事件 d.id, 5 分钟有效)
//   event_id (string, 选填) 被动回复事件 ID
//   markdown (object, 选填) markdown 消息对象
// content/embed/ark/image/markdown 至少传一个
function 发送子频道消息($channelId, $data) {
    if (empty($channelId) || empty($data) || !is_array($data)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 或数据为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $resp = BOTAPI("/channels/{$channelId}/messages", "POST", $json);
    wlog("[发送子频道消息] channel={$channelId} 请求: " . $json . " 响应: " . $resp, defined('appid') ? appid : null);
    return $resp;
}

// ==================== 发送子频道文本消息 (快捷封装) ====================
function 发送子频道文字($channelId, $content, $msgId = '') {
    if (empty($channelId) || $content === '') {
        return json_encode(['code' => -1, 'message' => 'channel_id 或 content 为空']);
    }
    $data = ['content' => $content];
    if (!empty($msgId)) $data['msg_id'] = $msgId;
    return 发送子频道消息($channelId, $data);
}

// ==================== 发送子频道图片消息 (快捷封装, 通过 URL) ====================
function 发送子频道图片($channelId, $imageUrl, $msgId = '') {
    if (empty($channelId) || empty($imageUrl)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 或 image_url 为空']);
    }
    $data = ['image' => $imageUrl];
    if (!empty($msgId)) $data['msg_id'] = $msgId;
    return 发送子频道消息($channelId, $data);
}

// ============================================================================
// 精华消息 (Pins Message) - 公告的替代方案 (子频道公告已废弃)
// ============================================================================

// ==================== 获取精华消息列表 (GET /channels/{channel_id}/pins) ====================
function 获取精华消息($channelId) {
    if (empty($channelId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 为空']);
    }
    return BOTAPI("/channels/{$channelId}/pins", "GET", "");
}

// ==================== 添加精华消息 (PUT /channels/{channel_id}/pins/{message_id}) ====================
function 添加精华消息($channelId, $messageId) {
    if (empty($channelId) || empty($messageId)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    return BOTAPI("/channels/{$channelId}/pins/{$messageId}", "PUT", "");
}

// ==================== 删除精华消息 (DELETE /channels/{channel_id}/pins/{message_id}) ====================
function 删除精华消息($channelId, $messageId) {
    if (empty($channelId) || empty($messageId)) {
        return json_encode(['code' => -1, 'message' => '参数为空']);
    }
    return BOTAPI("/channels/{$channelId}/pins/{$messageId}", "DELETE", "");
}

// ============================================================================
// 频道私信会话管理 (Dms) - 参照 dms-controller.html
// ============================================================================

// ==================== 创建频道私信会话 (POST /users/@me/dms) ====================
// 参数 $guildId: 用于创建私信会话的频道 ID
// 返回: { guild_id: "#CHANNEL_ID", ... } 实际是私信会话 ID
function 创建频道私信($guildId) {
    if (empty($guildId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 为空']);
    }
    $json = json_encode(['source_guild_id' => $guildId], JSON_UNESCAPED_UNICODE);
    return BOTAPI("/users/@me/dms", "POST", $json);
}

// ============================================================================
// 表情表态用户列表 (Reactions - GET) - 参照 reaction/list-user.html
// ============================================================================

// ==================== 获取表情表态用户列表 (GET /channels/{channel_id}/messages/{message_id}/reactions/{type}/{id}) ====================
// 参数:
//   $channelId - 子频道 ID
//   $messageId - 消息 ID
//   $type      - 表情类型 (1=系统表情, 2=自定义表情)
//   $emojiId   - 表情 ID
//   $cookie    - 翻页 cookie (上次返回的 cookie, 首次为空)
function 获取表态用户列表($channelId, $messageId, $type, $emojiId, $cookie = '') {
    if (empty($channelId) || empty($messageId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 或 message_id 为空']);
    }
    $address = "/channels/{$channelId}/messages/{$messageId}/reactions/{$type}/{$emojiId}";
    if (!empty($cookie)) {
        $address .= "?cookie=" . urlencode($cookie);
    }
    return BOTAPI($address, "GET", "");
}

// ============================================================================
// 子频道消息管理 (GET/PATCH) - 参照 message/get.html, message/patch.html
// ============================================================================

// ==================== 获取指定子频道消息 (GET /channels/{channel_id}/messages/{message_id}) ====================
function 获取子频道消息($channelId, $messageId) {
    if (empty($channelId) || empty($messageId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 或 message_id 为空']);
    }
    return BOTAPI("/channels/{$channelId}/messages/{$messageId}", "GET", "");
}

// ==================== 修改子频道消息 (PATCH /channels/{channel_id}/messages/{message_id}) ====================
// 用于修改已发送的 markdown 消息 (仅 markdown 类型消息可修改)
function 修改子频道消息($channelId, $messageId, $data) {
    if (empty($channelId) || empty($messageId) || empty($data)) {
        return json_encode(['code' => -1, 'message' => 'channel_id, message_id 或 data 为空']);
    }
    if (!is_array($data)) {
        return json_encode(['code' => -1, 'message' => 'data 必须为数组']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/channels/{$channelId}/messages/{$messageId}", "PATCH", $json);
}

// ============================================================================
// 消息撤回 - 参照官方文档 reset.html
// 4 种场景: 群聊 / 单聊(C2C) / 子频道 / 频道私信
// 群聊/单聊: 2 分钟内可撤回; 群管理员可撤回成员消息
// 频道私信: 仅可撤回机器人自己发送的私信
// ============================================================================

// ==================== 撤回子频道消息 (DELETE /channels/{channel_id}/messages/{message_id}) ====================
function 撤回子频道消息($channelId, $messageId) {
    if (empty($channelId) || empty($messageId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 或 message_id 为空']);
    }
    return BOTAPI("/channels/{$channelId}/messages/{$messageId}", "DELETE", "");
}

// ==================== 撤回频道私信消息 (DELETE /dms/{guild_id}/messages/{message_id}) ====================
// 仅可撤回机器人自己发送的私信
function 撤回频道私信($guildId, $messageId) {
    if (empty($guildId) || empty($messageId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 或 message_id 为空']);
    }
    return BOTAPI("/dms/{$guildId}/messages/{$messageId}", "DELETE", "");
}

// ==================== 撤回单聊消息 (DELETE /v2/users/{openid}/messages/{message_id}) ====================
// 2 分钟内可撤回
function 撤回单聊消息($userOpenid, $messageId) {
    if (empty($userOpenid) || empty($messageId)) {
        return json_encode(['code' => -1, 'message' => 'user_openid 或 message_id 为空']);
    }
    return BOTAPI("/v2/users/{$userOpenid}/messages/{$messageId}", "DELETE", "");
}

// ==================== 撤回群聊消息 (DELETE /v2/groups/{group_openid}/messages/{message_id}) ====================
// 2 分钟内可撤回; 群管理员可撤回成员消息
function 撤回群聊消息($groupOpenid, $messageId) {
    if (empty($groupOpenid) || empty($messageId)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 或 message_id 为空']);
    }
    return BOTAPI("/v2/groups/{$groupOpenid}/messages/{$messageId}", "DELETE", "");
}

// ==================== 获取通用 WSS 接入点 (GET /gateway) ====================
// 无分片信息, 与 /gateway/bot(带分片) 不同
function 获取通用网关() {
    return BOTAPI("/gateway", "GET", "");
}

// ==================== 获取音视频/直播子频道在线成员数 (GET /channels/{channel_id}/online_nums) ====================
function 获取在线成员数($channelId) {
    if (empty($channelId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 为空']);
    }
    return BOTAPI("/channels/{$channelId}/online_nums", "GET", "");
}

// ==================== 拉取子频道消息列表 (GET /channels/{channel_id}/messages) ====================
// 私域机器人接口, 支持分页
// 参数:
//   $channelId - 子频道 ID
//   $query     - 查询参数 [before/after/limit]
function 获取子频道消息列表($channelId, $query = []) {
    if (empty($channelId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 为空']);
    }
    $address = "/channels/{$channelId}/messages";
    if (!empty($query) && is_array($query)) {
        $address .= "?" . http_build_query($query);
    }
    return BOTAPI($address, "GET", "");
}

// ============================================================================
// 群管理扩展 (Group Info / Bot State / Join Request List)
// 参照 autogen: v2_groups_group_openid_info / bot_state / join_request_list
// ============================================================================

// ==================== 获取群信息 (GET /v2/groups/{group_openid}/info) ====================
function 获取群信息($groupOpenid) {
    if (empty($groupOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 为空']);
    }
    return BOTAPI("/v2/groups/{$groupOpenid}/info", "GET", "");
}

// ==================== 获取机器人群内状态 (GET /v2/groups/{group_openid}/bot_state) ====================
function 获取机器人群状态($groupOpenid) {
    if (empty($groupOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 为空']);
    }
    return BOTAPI("/v2/groups/{$groupOpenid}/bot_state", "GET", "");
}

// ==================== 获取入群申请列表 (GET /v2/groups/{group_openid}/join_request_list) ====================
// 参数:
//   $groupOpenid - 群 openid
//   $cursor      - 翻页游标 (首次为空)
//   $limit       - 每页数量 (默认 20)
function 获取入群申请列表($groupOpenid, $cursor = '', $limit = 20) {
    if (empty($groupOpenid)) {
        return json_encode(['code' => -1, 'message' => 'group_openid 为空']);
    }
    $address = "/v2/groups/{$groupOpenid}/join_request_list?limit=" . intval($limit);
    if (!empty($cursor)) {
        $address .= "&cursor=" . urlencode($cursor);
    }
    return BOTAPI($address, "GET", "");
}

// ============================================================================
// 语音子频道成员 (Voice Members) - 参照 channel/voice.html
// ============================================================================

// ==================== 获取语音子频道成员列表 (GET /channels/{channel_id}/voice/members) ====================
function 获取语音成员($channelId) {
    if (empty($channelId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 为空']);
    }
    return BOTAPI("/channels/{$channelId}/voice/members", "GET", "");
}

// ============================================================================
// 入群审批策略扩展 (Execute / Whitelist)
// 参照 autogen: join_approval_strategy execute / whitelist_users
// ============================================================================

// ==================== 执行入群审批策略 (POST /v2/groups/join_approval_strategy/{strategy_id}/execute) ====================
// 全量扫描并审批, 异步执行约 10 分钟
function 执行审批策略($strategyId) {
    if (empty($strategyId)) {
        return json_encode(['code' => -1, 'message' => 'strategy_id 为空']);
    }
    return BOTAPI("/v2/groups/join_approval_strategy/{$strategyId}/execute", "POST", "");
}

// ==================== 修改审批策略白名单 (POST /v2/groups/join_approval_strategy/{strategy_id}/whitelist_users) ====================
// 参数:
//   $strategyId - 策略 ID
//   $data       - { op: "add"|"del", whitelist_users: ["号码1","号码2",...] } 单次最多 1 万
function 修改审批策略白名单($strategyId, $data) {
    if (empty($strategyId) || empty($data) || !is_array($data)) {
        return json_encode(['code' => -1, 'message' => 'strategy_id 或 data 为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/v2/groups/join_approval_strategy/{$strategyId}/whitelist_users", "POST", $json);
}

// ============================================================================
// API 权限申请 (Api Permission) - 参照 api-permission/get.html, api-permission/post.html
// ============================================================================

// ==================== 获取 API 权限列表 (GET /guilds/{guild_id}/api_permission) ====================
// 返回机器人可申请的 API 权限列表
function 获取API权限列表($guildId) {
    if (empty($guildId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 为空']);
    }
    return BOTAPI("/guilds/{$guildId}/api_permission", "GET", "");
}

// ==================== 申请 API 权限 (POST /guilds/{guild_id}/api_permission/demand) ====================
// 参数:
//   $guildId    - 频道 ID
//   $channelId  - 子频道 ID (用于接收权限申请结果的事件)
//   $apiIdentify - 权限标识 { path: "/channels/{channel_id}/messages", method: "GET" }
function 申请API权限($guildId, $channelId, $apiIdentify) {
    if (empty($guildId) || empty($channelId)) {
        return json_encode(['code' => -1, 'message' => 'guild_id 或 channel_id 为空']);
    }
    if (empty($apiIdentify) || !is_array($apiIdentify)) {
        return json_encode(['code' => -1, 'message' => 'api_identify 为空或非数组']);
    }
    $data = [
        'channel_id' => $channelId,
        'api_identify' => $apiIdentify,
    ];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/guilds/{$guildId}/api_permission/demand", "POST", $json);
}

// ============================================================================
// 论坛帖子 (Forum Threads) - 参照 forum/list.html, forum/get.html, forum/create.html, forum/delete.html
// ============================================================================

// ==================== 获取论坛帖子列表 (GET /channels/{channel_id}/threads) ====================
// 参数:
//   $channelId - 论坛子频道 ID
//   $cursor    - 翻页游标 (首次为空)
function 获取帖子列表($channelId, $cursor = '') {
    if (empty($channelId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 为空']);
    }
    $address = "/channels/{$channelId}/threads";
    if (!empty($cursor)) {
        $address .= "?cursor=" . urlencode($cursor);
    }
    return BOTAPI($address, "GET", "");
}

// ==================== 获取论坛帖子详情 (GET /channels/{channel_id}/threads/{thread_id}) ====================
function 获取帖子详情($channelId, $threadId) {
    if (empty($channelId) || empty($threadId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 或 thread_id 为空']);
    }
    return BOTAPI("/channels/{$channelId}/threads/{$threadId}", "GET", "");
}

// ==================== 发表论坛帖子 (PUT /channels/{channel_id}/threads) ====================
// 参数 $data: { title: "...", content: "...", format: 0|1|2|3|4 }
function 发表帖子($channelId, $data) {
    if (empty($channelId) || empty($data) || !is_array($data)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 或 data 为空']);
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    return BOTAPI("/channels/{$channelId}/threads", "PUT", $json);
}

// ==================== 删除论坛帖子 (DELETE /channels/{channel_id}/threads/{thread_id}) ====================
function 删除帖子($channelId, $threadId) {
    if (empty($channelId) || empty($threadId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 或 thread_id 为空']);
    }
    return BOTAPI("/channels/{$channelId}/threads/{$threadId}", "DELETE", "");
}

// ============================================================================
// 分片上传 (multipart/form-data) - 用于频道/子频道图片消息的二进制文件流上传
// 参照官方文档: POST /channels/{channel_id}/messages (multipart/form-data)
// 与图片()函数的 JSON+base64 方式不同，本函数使用 multipart 直传二进制文件
// 适用于本地大文件上传，避免 base64 编码导致的体积膨胀(约+33%)与性能损耗
// 支持两种入参:
//   1. $file 为本地文件路径 -> 自动包装为 CURLFile 对象
//   2. $file 为二进制字符串 -> 写入临时文件后上传
// 参数:
//   $channelId - 子频道 ID
//   $file      - 本地文件路径 或 二进制数据
//   $content   - 附加文字内容(可选)
//   $msgId     - 被动回复消息 ID(可选)
//   $filename  - 上传文件名(可选, 默认 auto.png)
// 返回: API 原始响应
// ============================================================================
function 分片上传($channelId, $file, $content = '', $msgId = '', $filename = '') {
    if (empty($channelId)) {
        return json_encode(['code' => -1, 'message' => 'channel_id 为空']);
    }
    if (empty($file)) {
        return json_encode(['code' => -1, 'message' => 'file 为空']);
    }

    // 判断入参类型：本地文件路径 or 二进制数据
    $isLocalFile = is_string($file) && is_file($file);
    $tempFile = '';
    if ($isLocalFile) {
        $curlFile = new CURLFile($file, '', $filename ?: basename($file));
    } else {
        // 二进制数据写入临时文件
        $tempFile = tempnam(sys_get_temp_dir(), 'bot_chunk_');
        file_put_contents($tempFile, $file);
        $curlFile = new CURLFile($tempFile, '', $filename ?: 'auto.png');
    }

    try {
        // 组装 multipart 表单字段
        $fields = [];
        if ($content !== '') $fields['content'] = (string)$content;
        if (!empty($msgId)) $fields['msg_id'] = (string)$msgId;
        $fields['file_image'] = $curlFile;

        // 构建完整 URL（与 BOTAPI 一致的环境选择 + 后台域名切换）
        $env = defined('type') ? type : '正式';
        if (!in_array($env, ['正式', '沙箱'], true)) $env = '正式';
        $url = getApiBase($env) . "/channels/{$channelId}/messages";

        $header = ["Authorization: QQBot " . BOT凭证()];
        $resp = curlMultipart($url, $header, $fields);

        $data = json_decode($resp, true);
        $messageId = $data['id'] ?? '';
        $logContent = $isLocalFile ? ("[分片上传文件:" . basename($file) . "]") : "[分片上传二进制数据]";
        if ($content !== '') $logContent .= " " . $content;
        记录发送("分片上传图片", $channelId, $logContent, "图片", $messageId, $resp);
        wlog("[分片上传] channel={$channelId} 响应: " . $resp, defined('appid') ? appid : null);
        return $resp;
    } finally {
        // 清理临时文件
        if ($tempFile !== '' && file_exists($tempFile)) {
            @unlink($tempFile);
        }
    }
}
// ============================================================================
// 官方新增接口：流式发送单聊消息
// POST /v2/users/{user_openid}/stream_messages
// 文档: https://bot.qq.com/wiki/develop/api-v2/autogen/api/v2_users_user_openid_stream_messages.post.html
// 说明:
//   - 每个分片共享 stream_msg_id（首片由服务端返回 id，后续分片带上）
//   - input_mode: append(默认,增量拼接) / replace(全量覆盖,必须与已下发前缀一致)
//   - input_state: 1=生成中, 10=生成结束
//   - content_type: text / markdown
// 参数:
//   $userOpenid - 单聊用户 openid
//   $chunks     - 文本数组（按顺序发送）
//   $msgId      - 被动回复的消息 ID（与 event_id 二选一）
//   $eventId    - 被动回复的事件 ID
//   $mode       - append|replace
//   $contentType- text|markdown
// 返回: 最后一片的 API 响应（字符串）
// ============================================================================
function 流式发送单聊($userOpenid, $chunks, $msgId = '', $eventId = '', $mode = 'append', $contentType = 'markdown') {
    if (empty($userOpenid)) {
        return json_encode(['code' => -1, 'message' => 'user_openid 为空'], JSON_UNESCAPED_UNICODE);
    }
    if (!is_array($chunks)) $chunks = [$chunks];
    if (empty($chunks)) {
        return json_encode(['code' => -1, 'message' => '消息内容为空'], JSON_UNESCAPED_UNICODE);
    }
    $chunks = array_values($chunks);
    $total = count($chunks);
    $streamId = '';
    $lastResp = '';
    $msgSeq = random_int(1, 999999);

    foreach ($chunks as $i => $chunk) {
        $isLast = ($i === $total - 1);
        $payload = [
            'input_mode'   => $mode === 'replace' ? 'replace' : 'append',
            'input_state'  => $isLast ? 10 : 1,
            'index'        => $i,
            'content_type' => $contentType === 'text' ? 'text' : 'markdown',
            'content_raw'  => (string)$chunk,
            'msg_seq'      => $msgSeq,
        ];
        if ($msgId !== '')  $payload['msg_id'] = (string)$msgId;
        if ($eventId !== '') $payload['event_id'] = (string)$eventId;
        if ($streamId !== '') $payload['stream_msg_id'] = $streamId;

        $resp = BOTAPI("/v2/users/{$userOpenid}/stream_messages", 'POST', json_encode($payload, JSON_UNESCAPED_UNICODE));
        $lastResp = $resp;
        $data = json_decode($resp, true);
        // 首片响应 id 即 stream_msg_id；部分环境下字段名可能为 stream_msg_id
        if ($streamId === '') {
            $streamId = $data['stream_msg_id'] ?? ($data['id'] ?? '');
        }
        if (isset($data['code']) && $data['code'] != 0) {
            // 出错立即停止，避免继续发送无效分片
            break;
        }
    }

    $preview = mb_substr((string)$chunks[0], 0, 60, 'UTF-8');
    记录发送('流式单聊', $userOpenid, $preview, '流式', $streamId, $lastResp);
    return $lastResp;
}

// ============================================================================
// 官方新增接口：群/单聊富媒体分片上传（预上传）
// POST /v2/groups/{group_openid}/upload_prepare
// POST /v2/users/{user_openid}/upload_prepare
// 文档: https://bot.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_group_id_upload_prepare.post.html
// 返回: API 原始响应（含 upload_id / block_size / parts[].presigned_url）
// 说明: 拿到 parts 后，将对应分片 PUT 到 presigned_url，再调用 分片上传完成()
// ============================================================================
function 分片上传预上传($targetId, $filePath, $fileType = 4, $isGroup = true, $fileName = '') {
    if (empty($targetId) || empty($filePath) || !is_file($filePath)) {
        return json_encode(['code' => -1, 'message' => '目标或文件无效'], JSON_UNESCAPED_UNICODE);
    }
    $size = filesize($filePath);
    if ($size <= 0) {
        return json_encode(['code' => -1, 'message' => '文件为空'], JSON_UNESCAPED_UNICODE);
    }
    $name = $fileName !== '' ? $fileName : basename($filePath);
    $md5 = hash_file('md5', $filePath);
    $sha1 = hash_file('sha1', $filePath);
    // 前 10002432 字节（约 10MB）的 MD5
    $fh = fopen($filePath, 'rb');
    $head = $fh ? fread($fh, 10002432) : '';
    if ($fh) fclose($fh);
    $md5_10m = md5((string)$head);

    $payload = [
        'file_type' => (int)$fileType,
        'file_size' => (string)$size,
        'file_name' => $name,
        'md5'       => $md5,
        'sha1'      => $sha1,
        'md5_10m'   => $md5_10m,
    ];
    $path = $isGroup ? 'groups' : 'users';
    $resp = BOTAPI("/v2/{$path}/{$targetId}/upload_prepare", 'POST', json_encode($payload, JSON_UNESCAPED_UNICODE));
    wlog("[分片预上传] {$path}={$targetId} file={$name} size={$size} resp=" . $resp, defined('appid') ? appid : null);
    return $resp;
}

// ============================================================================
// 官方新增接口：分片上传完成通知
// POST /v2/groups/{group_openid}/upload_part_finish
// POST /v2/users/{user_openid}/upload_part_finish
// 参数:
//   $uploadId  - 预上传返回的 upload_id
//   $partIndex - 分片序号（从 0 开始）
//   $blockSize - 该分片实际大小（字节）
//   $partData  - 该分片二进制内容（用于计算 MD5）；也可用 $md5 直接传入
// 返回: API 原始响应
// ============================================================================
function 分片上传完成($targetId, $uploadId, $partIndex, $blockSize, $partData = '', $md5 = '', $isGroup = true) {
    if (empty($targetId) || empty($uploadId)) {
        return json_encode(['code' => -1, 'message' => '目标或 upload_id 为空'], JSON_UNESCAPED_UNICODE);
    }
    if ($md5 === '' && $partData !== '') {
        $md5 = md5($partData);
    }
    $payload = [
        'upload_id'   => (string)$uploadId,
        'part_index'  => (int)$partIndex,
        'block_size'  => (string)(int)$blockSize,
        'md5'         => (string)$md5,
    ];
    $path = $isGroup ? 'groups' : 'users';
    return BOTAPI("/v2/{$path}/{$targetId}/upload_part_finish", 'POST', json_encode($payload, JSON_UNESCAPED_UNICODE));
}

// ============================================================================
// 官方新增接口：PUT 上传单个分片到预签名 URL
// 说明: 预签名 URL 通常指向对象存储，不能带 QQ Bot 的 Authorization 头
// 返回: HTTP 状态码（200/201 视为成功）
// ============================================================================
function 上传分片到预签名URL($presignedUrl, $data, $timeout = 60) {
    if (empty($presignedUrl)) return 0;
    $ch = curl_init($presignedUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Length: ' . strlen($data)]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status;
}
