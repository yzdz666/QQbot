<?php

// ==================== URL安全验证（防止SSRF攻击） ====================
function isSafeUrl($url) {
    $host = parse_url($url, PHP_URL_HOST);
    if ($host === false || $host === null) return false;
    $ip = gethostbyname($host);
    if ($ip !== $host) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }
    return true;
}

// ==================== 日志记录函数（数据库 + 文件双写，与原版兼容） ====================
function 记录发送($action, $target, $content, $type = "文字", $messageId = null, $rawData = null) {
    $sourceType = defined('消息来源') ? 消息来源 : 'unknown';
    $appidVal = defined('appid') ? appid : 'unknown';
    $userId = defined('用户') ? 用户 : null;

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
    wlog(json_encode($logEntry, JSON_UNESCAPED_UNICODE));
}

function BOT凭证(){
       $time=读("function/".appid,"time",0);
       if (time() < $time) {
         return 读("function/".appid,"Access","");
       } else {
         $url="https://bots.qq.com/app/getAppAccessToken";
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
            wlog("获取Access Token失败: " . json_encode($fw, JSON_UNESCAPED_UNICODE), appid);
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

function BOTAPI($Address,$me,$json){
    $urls=[
    "正式"=>"https://api.sgroup.qq.com",
    "沙箱"=>"https://sandbox.api.sgroup.qq.com"
    ];
    // 安全保护：确保type常量有效，防止URL拼接错误
    $env = defined('type') ? type : '正式';
    if (!isset($urls[$env])) $env = '正式';
    $url = $urls[$env].$Address;
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
     case "群成员增加":   // 新增
     case "群成员移除":   // 新增
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
           case "群聊":
           case "互动":
               return json_decode(BOTAPI("/v2/groups/".来源."/files", "POST",$json),true);
               break;
           case "私聊":
               return json_decode(BOTAPI("/v2/users/".来源."/files", "POST",$json),true);
               break;
        }
}


function 图片($image,$content=null) {
   // 记录实际图片URL，而非占位符；二进制数据用占位符代替
   $logContent = (is_string($image) && preg_match('/^http/', $image)) ? $image : "[上传图片]";
   if ($content !== null) $logContent = $logContent . " " . $content;
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
    $link = urlencode($link);
    $url = "https://oiapi.net/API/Mp32Silk?url=".$link;
    $r = json_decode(curl($url,"GET",[],''), true);
    return $r["message"] ?? '';
}

// ==================== 本地语音功能 ====================
function 本地语音($yy) {
   $logContent = (is_string($yy) && preg_match('/^http/', $yy)) ? $yy : "[本地语音数据]";
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

function 语音($yy) {
   $logContent = $yy;
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

function 文件($yy, $nm = null) {
   $logContent = (is_string($yy) && preg_match('/^http/', $yy)) ? $yy : "[上传文件:" . ($nm ?? 'file') . "]";
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


function 视频($video) {
   $logContent = (is_string($video) && preg_match('/^http/', $video)) ? $video : "[上传视频]";
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
    $wenkaLogJson = json_encode($wenkaLogData, JSON_UNESCAPED_UNICODE);
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
    $datuLogJson = json_encode($datuLogData, JSON_UNESCAPED_UNICODE);
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
    $tzLogJson = json_encode($tzLogData, JSON_UNESCAPED_UNICODE);
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
    $id = null;
    $index = 0;
    $total = count($msgs);
    $lastResp = null;
    foreach ($msgs as $msg) {
        $isLast = ($index === $total - 1);
        $json = [
            "content" => (string)$msg,
            "msg_id" => 消息ID,
            "msg_seq" => rand(1, 99999),
            "stream" => [
                "state" => $isLast ? 10 : 1,
                "id" => $id,
                "index" => $index,
                "reset" => false
            ]
        ];
        $resp = BOTAPI("/v2/users/".来源."/messages", "POST", json_encode($json));
        $lastResp = $resp;
        $json = json_decode($resp, true);
        $id = $json["id"] ?? null;
        $index++;
    }
    $content_preview = implode(" ", array_slice($msgs, 0, 2));
    记录发送("流式回复", 来源, $content_preview . (count($msgs) > 2 ? " ..." : ""), "流式", $id, $lastResp);
    return $lastResp;
}

function 撤回($id){
   // 不记录发送日志，撤回操作由 chat_api.php 更新原消息状态为[已撤回]
   $type = [
      "群聊"=>"groups",
      "私聊"=>"users",
      "加群"=>"groups",
      "退群"=>"groups",
      "群成员增加"=>"groups",   // 新增
      "群成员移除"=>"groups"    // 新增
   ];
   $type = $type[消息来源];
   return BOTAPI("/v2/{$type}/".来源."/messages/".$id,"DELETE","");
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

// ==================== 引用消息函数 ====================
// 用法和撤回一样简单：引用($msgId, $content)
// $msgId 可以是消息ID或REFIDX（从消息场景中提取）
function 引用($msgId, $content = '') {
    // 确保有内容，API要求content不能为空
    if (empty($content)) {
        $content = " ";
    }
    
    $payload = [
        "msg_type" => 0,  // 文本类型（API必填字段）
        "content" => $content,
        "message_reference" => [
            "message_id" => $msgId,
            "ignore_get_message_error" => true
        ]
    ];
    
    // 根据消息来源设置不同的参数
    switch (消息来源) {
        case "群聊":
            $payload["msg_id"] = 消息ID;
            $payload["msg_seq"] = rand(1, 99999);
            $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($payload, JSON_UNESCAPED_UNICODE));
            break;
            
        case "私聊":
            $payload["msg_id"] = 消息ID;
            $payload["msg_seq"] = rand(1, 99999);
            $resp = BOTAPI("/v2/users/".来源."/messages", "POST", json_encode($payload, JSON_UNESCAPED_UNICODE));
            break;
            
        case "加群":
        case "退群":
        case "群成员增加":
        case "群成员移除":
        case "互动":
            $payload["event_id"] = 事件ID;
            $payload["msg_seq"] = rand(1, 99999);
            $resp = BOTAPI("/v2/groups/".来源."/messages", "POST", json_encode($payload, JSON_UNESCAPED_UNICODE));
            break;
            
        case "文字子频道":
            $payload["msg_id"] = 消息ID;
            $resp = BOTAPI("/channels/".来源."/messages", "POST", json_encode($payload, JSON_UNESCAPED_UNICODE));
            break;
            
        default:
            // 不支持的来源，降级为文字发送
            return 文字($content);
    }
    
    // 解析响应，获取返回的消息ID
    $data = json_decode($resp, true);
    $returnedMsgId = $data['id'] ?? '';
    
    // 记录日志，带上返回的消息ID
    记录发送("发送引用消息", 来源, $content, "引用", $returnedMsgId, $resp);
    
    return $resp;
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
        case "互动":
            $api_url = "/v2/groups/" . 来源 . "/messages";
            break;
        case "私聊":
            $api_url = "/v2/users/" . 来源 . "/messages";
            break;
        case "文字子频道":
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
        case "互动":
            $api = "/v2/groups/" . 来源 . "/messages";
            break;
        case "私聊":
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
        case "互动":
            $api = "/v2/groups/" . 来源 . "/messages";
            break;
        case "私聊":
            $api = "/v2/users/" . 来源 . "/messages";
            break;
        case "文字子频道":
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
        case "互动":
            $api = "/v2/groups/" . 来源 . "/messages";
            break;
        case "私聊":
            $api = "/v2/users/" . 来源 . "/messages";
            break;
        case "文字子频道":
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

// ==================== 查询群成员信息 ====================
function 获取群成员($groupOpenid, $memberOpenid) {
    $resp = BOTAPI("/v2/groups/{$groupOpenid}/members/{$memberOpenid}", "GET", "");
    return json_decode($resp, true);
}

// ==================== 获取群成员列表 ====================
function 获取群成员列表($groupOpenid, $limit = 20, $after = '') {
    $url = "/v2/groups/{$groupOpenid}/members?limit={$limit}";
    if (!empty($after)) {
        $url .= "&after={$after}";
    }
    $resp = BOTAPI($url, "GET", "");
    return json_decode($resp, true);
}

// ==================== 确认互动回调 ====================
function 确认互动($eventId, $body = '') {
    $json = $body ?: json_encode(["content" => ""]);
    $resp = BOTAPI("/v2/interactions/{$eventId}", "PUT", $json);
    return $resp;
}

// ==================== 获取图片尺寸 ====================
function 图片尺寸($imageSource) {
    if (preg_match('/^https?:\/\//i', $imageSource)) {
        if (!isSafeUrl($imageSource)) return false;
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