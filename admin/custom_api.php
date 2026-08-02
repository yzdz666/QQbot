<?php
// 插件生成器 - custom_api.php（最终完整整合版 - 修复 md_to_html 内容丢失）
error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

ob_start();
if (ob_get_level() > 0) ob_clean();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

$pluginDir = dirname(__DIR__) . '/plugin';
if (!is_dir($pluginDir)) {
    if (!mkdir($pluginDir, 0755, true)) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['code'=>500, 'msg'=>'无法创建插件目录'], JSON_UNESCAPED_UNICODE));
    }
}

$isApi = ($_SERVER['REQUEST_METHOD'] === 'POST');

if ($isApi) {
    $_POST = [];
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        parse_str($rawBody, $_POST);
        if (empty($_POST)) {
            $json = json_decode($rawBody, true);
            if (is_array($json)) $_POST = $json;
        }
    }

    $action = $_POST['type'] ?? '';
    if (empty($action)) {
        $input = json_decode($rawBody, true);
        if ($input && !empty($input['blocks'])) {
            $code = generatePluginCode($input);
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            die(json_encode(['code'=>200, 'plugin_code'=>$code], JSON_UNESCAPED_UNICODE));
        }
        $isApi = false;
    }
}

if ($isApi && !empty($action)) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($action === 'generate') {
            $name = preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}]/u', '', $_POST['name'] ?? '');
            $name = trim($name);
            if (empty($name)) throw new Exception('插件名不合法');
            $adminId = $_POST['admin_id'] ?? '';
            $mode = $_POST['mode'] ?? 'block';

            if ($mode === 'quick') {
                $trigger = $_POST['trigger'] ?? '';
                $match = $_POST['match'] ?? 'keyword';
                $url = $_POST['url'] ?? '';
                $path = $_POST['path'] ?? '';
                $reply = $_POST['reply'] ?? '{response}';
                $send = $_POST['send'] ?? 'text';
                $method = $_POST['method'] ?? 'GET';
                $timeout = $_POST['timeout'] ?? 10;
                $headers = $_POST['headers'] ?? '{}';
                $body = $_POST['body'] ?? '';
                $code = generateQuickPluginCode($name, $adminId, $trigger, $match, $url, $path, $reply, $send, $method, $timeout, $headers, $body);
            } else {
                $events = json_decode($_POST['events'] ?? '[]', true);
                $triggerType = $_POST['trigger_type'] ?? 'equals';
                $triggerValue = $_POST['trigger_value'] ?? '';
                $blocks = json_decode($_POST['blocks'] ?? '[]', true);
                if (empty($blocks)) throw new Exception('动作块不能为空');
                $pluginConfig = [
                    'plugin_name' => $name,
                    'events'      => $events,
                    'trigger'     => ['type' => $triggerType, 'value' => $triggerValue],
                    'blocks'      => $blocks
                ];
                $code = generatePluginCode($pluginConfig, $adminId);
            }

            $filePath = $pluginDir . '/' . $name . '.php';
            if (!is_writable($pluginDir)) throw new Exception('插件目录不可写');
            if (file_put_contents($filePath, $code) === false) throw new Exception('无法写入插件文件');
            echo json_encode(['code'=>200, 'msg'=>"插件 {$name} 已生成（已启用）"], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('未知操作');
        }
    } catch (Exception $e) {
        while (ob_get_level() > 0) ob_end_clean();
        ob_start();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['code'=>400, 'msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

while (ob_get_level() > 0) ob_end_clean();

function generatePluginCode($config, $adminId = '') {
    $name    = trim($config['plugin_name'] ?? '未命名插件');
    $events  = $config['events'] ?? ['群聊', '私聊'];
    $trigger = $config['trigger'] ?? [];
    $blocks  = $config['blocks'] ?? [];

    $outputTypes = ['text','image','audio','video','file','md','md_template','button','inline_button',
                    'ark_text','ark_big','ark_jump','stream'];
    $hasOutput = false;
    $lastVar = null;
    foreach ($blocks as $block) {
        if (in_array($block['type'], $outputTypes)) $hasOutput = true;
        if (isset($block['var']) && !empty($block['var'])) $lastVar = $block['var'];
    }
    if (!$hasOutput && $lastVar !== null) {
        $blocks[] = ['type' => 'text', 'content' => '{$' . $lastVar . '}'];
    } elseif (!$hasOutput && !empty($blocks)) {
        $blocks[] = ['type' => 'text', 'content' => '操作完成'];
    }

    $code  = "<?php\n";
    $code .= "// 插件：{$name}\n";
    $code .= "// 生成时间：" . date('Y-m-d H:i:s') . "\n\n";

    if (!empty($adminId)) {
        $code .= "\$adminId = '" . addslashes($adminId) . "';\n";
        $code .= "if (\$adminId && 用户 != \$adminId) {\n    return;\n}\n\n";
    }

    if (!empty($events)) {
        if (count($events) === 1) {
            $code .= "if (消息来源 != '{$events[0]}') {\n    return;\n}\n\n";
        } else {
            $eventList = "'" . implode("', '", array_map('addslashes', $events)) . "'";
            $code .= "if (!in_array(消息来源, [{$eventList}])) {\n    return;\n}\n\n";
        }
    }

    if (!empty($trigger['type']) && in_array($trigger['type'], ['equals','prefix'])) {
        $cmd = addslashes($trigger['value'] ?? '');
        if ($trigger['type'] === 'equals') {
            $code .= "if (消息 == \"{$cmd}\") {\n";
        } else {
            $code .= "if (strpos(消息, \"{$cmd}\") === 0) {\n";
        }
        $code .= "    // 触发命令: {$cmd}\n";
    } else {
        $code .= "// 无条件执行\n";
    }

    foreach ($blocks as $block) {
        $code .= generateBlockCode($block, 1);
    }

    if (!empty($trigger['type']) && in_array($trigger['type'], ['equals','prefix'])) {
        $code .= "    return;\n}\n";
    } else {
        $code .= "return;\n";
    }
    return $code;
}

function generateBlockCode($block, $indentLevel = 1) {
    $indent = str_repeat('    ', $indentLevel);
    $code   = '';
    $type   = $block['type'] ?? 'text';

    switch ($type) {
        case 'text':
            $content = addslashes($block['content'] ?? '');
            $code .= "{$indent}文字(\"{$content}\");\n";
            break;
        case 'image':
            $url = addslashes($block['url'] ?? '');
            $caption = $block['caption'] ?? '';
            $code .= "{$indent}图片(\"{$url}\"";
            if (!empty($caption)) $code .= ", \"" . addslashes($caption) . "\"";
            $code .= ");\n";
            break;
        case 'audio':
            $url = addslashes($block['url'] ?? '');
            $code .= "{$indent}语音(\"{$url}\");\n";
            break;
        case 'video':
            $url = addslashes($block['url'] ?? '');
            $code .= "{$indent}视频(\"{$url}\");\n";
            break;
        case 'file':
            $url  = addslashes($block['url'] ?? '');
            $name = addslashes($block['filename'] ?? 'file.pdf');
            $code .= "{$indent}文件(\"{$url}\", \"{$name}\");\n";
            break;
        case 'md':
            $md = addslashes($block['content'] ?? '');
            $code .= "{$indent}MD(\"{$md}\");\n";
            break;
        case 'md_template':
            $id     = addslashes($block['template_id'] ?? '');
            $params = $block['params'] ?? [];
            $paramsStr = var_export($params, true);
            $code .= "{$indent}发MD(\"{$id}\", {$paramsStr});\n";
            break;
        case 'button':
            $keyboardId = addslashes($block['keyboard_id'] ?? '');
            $code .= "{$indent}按钮(\"{$keyboardId}\");\n";
            break;
        case 'inline_button':
            $md   = addslashes($block['md'] ?? '');
            $rows = var_export($block['rows'] ?? [], true);
            $code .= "{$indent}原生按钮(\"{$md}\", {$rows});\n";
            break;
        case 'ark_text':
            $items = $block['items'] ?? [];
            $args = [];
            foreach ($items as $item) $args[] = var_export($item, true);
            $code .= "{$indent}文卡(" . implode(', ', $args) . ");\n";
            break;
        case 'ark_big':
            $title    = addslashes($block['title'] ?? '');
            $subtitle = addslashes($block['subtitle'] ?? '');
            $img      = addslashes($block['image'] ?? '');
            $addTs    = !empty($block['add_timestamp']);
            if ($addTs) {
                $code .= "{$indent}大图(\"{$title}\", \"{$subtitle}\", \"{$img}\" . (strpos(\"{$img}\",'?')===false?'?':'&') . 't=' . time());\n";
            } else {
                $code .= "{$indent}大图(\"{$title}\", \"{$subtitle}\", \"{$img}\");\n";
            }
            break;
        case 'ark_jump':
            $title = addslashes($block['title'] ?? '');
            $desc  = addslashes($block['desc'] ?? '');
            $img   = addslashes($block['image'] ?? '');
            $url   = addslashes($block['url'] ?? '');
            $addTs = !empty($block['add_timestamp']);
            if ($addTs) {
                $code .= "{$indent}跳转卡(\"{$title}\", \"{$desc}\", \"{$img}\" . (strpos(\"{$img}\",'?')===false?'?':'&') . 't=' . time(), \"{$url}\");\n";
            } else {
                $code .= "{$indent}跳转卡(\"{$title}\", \"{$desc}\", \"{$img}\", \"{$url}\");\n";
            }
            break;
        case 'stream':
            $contents = $block['contents'] ?? [];
            $args = [];
            foreach ($contents as $c) $args[] = '"' . addslashes($c) . '"';
            $code .= "{$indent}流式(" . implode(', ', $args) . ");\n";
            break;
        case 'recall':
            $msgContent = addslashes($block['content'] ?? '这条消息会被自动撤回');
            $delay = intval($block['delay'] ?? 3);
            $code .= "{$indent}\$sendMsg = 文字(\"{$msgContent}\");\n";
            $code .= "{$indent}\$msgData = json_decode(\$sendMsg, true);\n";
            $code .= "{$indent}\$msgId = \$msgData['id'] ?? '';\n";
            $code .= "{$indent}if (\$msgId) {\n";
            $code .= "{$indent}    sleep({$delay});\n";
            $code .= "{$indent}    撤回(\$msgId);\n";
            $code .= "{$indent}    文字(\"自动撤回测试完成\");\n";
            $code .= "{$indent}} else {\n";
            $code .= "{$indent}    文字(\"发送失败，无法执行自动撤回\");\n";
            $code .= "{$indent}}\n";
            break;
        case 'api_request':
            $endpoint = addslashes($block['endpoint'] ?? '');
            $method   = addslashes($block['method'] ?? 'GET');
            $body     = addslashes($block['body'] ?? '');
            $code .= "{$indent}BOTAPI(\"{$endpoint}\", \"{$method}\", \"{$body}\");\n";
            break;
        case 'data_write':
            $file  = addslashes($block['file'] ?? 'data');
            $key   = addslashes($block['key'] ?? 'default');
            $value = addslashes($block['value'] ?? '0');
            $code .= "{$indent}写(\"{$file}\", \"{$key}\", \"{$value}\");\n";
            break;
        case 'data_read':
            $file    = addslashes($block['file'] ?? 'data');
            $key     = addslashes($block['key'] ?? 'default');
            $default = addslashes($block['default'] ?? '0');
            $var     = $block['var'] ?? 'data';
            $code .= "{$indent}\${$var} = 读(\"{$file}\", \"{$key}\", \"{$default}\");\n";
            break;
        case 'curl':
            $url     = addslashes($block['url'] ?? '');
            $method  = addslashes($block['method'] ?? 'GET');
            $headers = addslashes($block['headers'] ?? '');
            $params  = addslashes($block['params'] ?? '');
            $var     = $block['var'] ?? 'response';
            $code .= "{$indent}\${$var} = curl(\"{$url}\", \"{$method}\", \"{$headers}\", \"{$params}\");\n";
            break;
        case 'avatar':
            $userId = addslashes($block['user_id'] ?? '用户');
            $var    = $block['var'] ?? 'avatar';
            $code .= "{$indent}\${$var} = 头像({$userId});\n";
            break;
        case 'bot_info':
            $code .= "{$indent}\$botInfo = BOT信息();\n";
            $code .= "{$indent}if (is_array(\$botInfo)) {\n";
            $code .= "{$indent}    文字(\"BOT信息: \" . json_encode(\$botInfo, JSON_UNESCAPED_UNICODE));\n";
            $code .= "{$indent}} else {\n";
            $code .= "{$indent}    文字(\"BOT信息: {\$botInfo}\");\n";
            $code .= "{$indent}}\n";
            break;
        case 'qrcode':
            $content = addslashes($block['content'] ?? '');
            $var     = $block['var'] ?? 'qrcode';
            $caption = $block['caption'] ?? '二维码';
            $autoSend = isset($block['auto_send']) ? $block['auto_send'] : true;
            $code .= "{$indent}\${$var} = 二维码(\"{$content}\");\n";
            if ($autoSend) {
                $code .= "{$indent}图片(\${$var}, \"" . addslashes($caption) . "\");\n";
            }
            break;
        case 'md_to_html':
            $md  = addslashes($block['md'] ?? '');
            $var = $block['var'] ?? 'html';
            $code .= "{$indent}\${$var} = markdown转html(\"{$md}\");\n";
            break;
        case 'html_to_image':
            $html   = addslashes($block['html'] ?? '');
            $width  = intval($block['width'] ?? 800);
            $height = intval($block['height'] ?? 600);
            $var    = $block['var'] ?? 'image';
            $autoSend = !empty($block['auto_send']);
            $code .= "{$indent}\${$var} = HTML转图(\"{$html}\", {$width}, {$height});\n";
            if ($autoSend) {
                $caption = $block['caption'] ?? 'HTML转图';
                $code .= "{$indent}图片(\${$var}, \"" . addslashes($caption) . "\");\n";
            }
            break;
        case 'email':
            $title   = addslashes($block['title'] ?? '');
            $content = addslashes($block['content'] ?? '');
            $to      = addslashes($block['to'] ?? '');
            $from    = addslashes($block['from'] ?? '');
            $pass    = addslashes($block['pass'] ?? '');
            $code .= "{$indent}邮箱(\"{$title}\", \"{$content}\", \"{$to}\", \"{$from}\", \"{$pass}\");\n";
            break;
        case 'domain_capitalize':
            $input = addslashes($block['input'] ?? '');
            $var   = $block['var'] ?? 'result';
            $code .= "{$indent}\${$var} = 域名大写(\"{$input}\");\n";
            break;
        case 'gd_init':
            $w  = intval($block['width'] ?? 600);
            $h  = intval($block['height'] ?? 300);
            $bg = addslashes($block['bg'] ?? '#FFFFFF');
            $var = $block['var'] ?? 'img';
            $code .= "{$indent}\$gd = new 画布();\n";
            $code .= "{$indent}\${$var} = \$gd->创建({$w}, {$h}, \"{$bg}\");\n";
            break;
        case 'gd_text':
            $img   = addslashes($block['img'] ?? 'img');
            $text  = addslashes($block['text'] ?? '');
            $size  = intval($block['size'] ?? 24);
            $x     = intval($block['x'] ?? 50);
            $y     = intval($block['y'] ?? 80);
            $color = addslashes($block['color'] ?? '#000000');
            $font  = addslashes($block['font'] ?? '__DIR__ . "/../font.ttf"');
            $code .= "{$indent}\$gd->文字(\${$img}, \"{$text}\", {$size}, {$x}, {$y}, \"{$color}\", {$font});\n";
            break;
        case 'gd_output':
            $img = addslashes($block['img'] ?? 'img');
            $var = $block['var'] ?? 'imageData';
            $code .= "{$indent}\${$var} = \$gd->二进制输出(\${$img});\n";
            $code .= "{$indent}\$gd->销毁(\${$img});\n";
            break;
        case 'var_set':
            $var   = addslashes($block['var'] ?? 'v');
            $value = addslashes($block['value'] ?? '');
            $code .= "{$indent}\${$var} = \"{$value}\";\n";
            break;
        case 'api_fetch':
            $url     = addslashes($block['url'] ?? '');
            $path    = addslashes($block['path'] ?? '');
            $var     = addslashes($block['var'] ?? 'data');
            $method  = addslashes($block['method'] ?? 'GET');
            $headers = addslashes($block['headers'] ?? '');
            $body    = addslashes($block['body'] ?? '');
            $code .= "{$indent}\$raw = curl(\"{$url}\", \"{$method}\", \"{$headers}\", \"{$body}\");\n";
            $code .= "{$indent}\$json = json_decode(\$raw, true);\n";
            if ($path) {
                $code .= "{$indent}\${$var} = \$json['{$path}'] ?? \$raw;\n";
            } else {
                $code .= "{$indent}\${$var} = \$json ?? \$raw;\n";
            }
            break;
        default:
            $code .= "{$indent}// 未知动作类型: {$type}\n";
    }
    return $code;
}

function generateQuickPluginCode($name, $adminId, $trigger, $match, $url, $path, $reply, $send, $method, $timeout, $headers, $body) {
    $code  = "<?php\n";
    $code .= "// 快速API插件：{$name}\n";
    $code .= "// 生成时间：" . date('Y-m-d H:i:s') . "\n\n";

    if (!empty($adminId)) {
        $code .= "\$adminId = '" . addslashes($adminId) . "';\n";
        $code .= "if (\$adminId && 用户 != \$adminId) {\n    return;\n}\n\n";
    }

    if ($match === 'equals') {
        $code .= "if (消息 == \"" . addslashes($trigger) . "\") {\n";
    } elseif ($match === 'prefix') {
        $code .= "if (strpos(消息, \"" . addslashes($trigger) . "\") === 0) {\n";
    } else {
        $code .= "if (strpos(消息, \"" . addslashes($trigger) . "\") !== false) {\n";
    }

    $code .= "    \$url = \"" . addslashes($url) . "\";\n";
    $code .= "    \$ch = curl_init();\n";
    $code .= "    curl_setopt(\$ch, CURLOPT_URL, \$url);\n";
    $code .= "    curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);\n";
    $code .= "    curl_setopt(\$ch, CURLOPT_TIMEOUT, " . intval($timeout) . ");\n";
    $code .= "    curl_setopt(\$ch, CURLOPT_SSL_VERIFYPEER, false);\n";
    $code .= "    curl_setopt(\$ch, CURLOPT_SSL_VERIFYHOST, false);\n";
    $methodUpper = strtoupper($method);
    if ($methodUpper === 'POST') {
        $code .= "    curl_setopt(\$ch, CURLOPT_POST, true);\n";
        if (!empty($body)) {
            $code .= "    curl_setopt(\$ch, CURLOPT_POSTFIELDS, '" . addslashes($body) . "');\n";
        }
    }
    if (!empty($headers) && $headers !== '{}') {
        $code .= "    \$headersArr = json_decode('" . addslashes($headers) . "', true);\n";
        $code .= "    if (\$headersArr) {\n";
        $code .= "        \$headerLines = [];\n";
        $code .= "        foreach (\$headersArr as \$k => \$v) \$headerLines[] = \"\$k: \$v\";\n";
        $code .= "        curl_setopt(\$ch, CURLOPT_HTTPHEADER, \$headerLines);\n";
        $code .= "    }\n";
    }
    $code .= "    \$response = curl_exec(\$ch);\n";
    $code .= "    \$httpCode = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);\n";
    $code .= "    curl_close(\$ch);\n";
    $code .= "    if (\$response === false) {\n";
    $code .= "        文字(\"请求失败: \" . curl_error(\$ch));\n";
    $code .= "        return;\n";
    $code .= "    }\n";
    $code .= "    \$data = json_decode(\$response, true);\n";
    $code .= "    if (\$data === null) {\n";
    $code .= "        \$value = \$response;\n";
    $code .= "    } else {\n";
    if (!empty($path)) {
        $code .= "        \$value = \$data['" . addslashes($path) . "'] ?? \$response;\n";
    } else {
        $code .= "        \$value = \$data;\n";
    }
    $code .= "    }\n";
    $code .= "    \$reply = \"" . addslashes($reply) . "\";\n";
    $code .= "    if (is_string(\$value)) {\n";
    $code .= "        \$reply = str_replace('{response}', \$value, \$reply);\n";
    $code .= "    } elseif (is_array(\$value) || is_object(\$value)) {\n";
    $code .= "        \$reply = str_replace('{response}', json_encode(\$value, JSON_UNESCAPED_UNICODE), \$reply);\n";
    $code .= "    } elseif (is_numeric(\$value)) {\n";
    $code .= "        \$reply = str_replace('{response}', (string)\$value, \$reply);\n";
    $code .= "    }\n";
    $code .= "    if (is_array(\$data)) {\n";
    $code .= "        foreach (\$data as \$k => \$v) {\n";
    $code .= "            if (!is_array(\$v) && !is_object(\$v)) {\n";
    $code .= "                \$reply = str_replace('{'.\$k.'}', (string)\$v, \$reply);\n";
    $code .= "            }\n";
    $code .= "        }\n";
    $code .= "    }\n";
    switch ($send) {
        case 'text': $code .= "    文字(\$reply);\n"; break;
        case 'native_md': $code .= "    MD(\$reply);\n"; break;
        case 'image': $code .= "    图片(\$value);\n"; break;
        case 'video': $code .= "    视频(\$value);\n"; break;
        case 'card': $code .= "    文卡(\$value);\n"; break;
        case 'wenka': $code .= "    文卡([\"text\" => \$reply]);\n"; break;
        case 'datu': $code .= "    大图(\$reply, \"\", \"\");\n"; break;
        case 'tiaozhuan': $code .= "    跳转卡(\$reply, \"\", \"\", \"\");\n"; break;
        case 'button': $code .= "    按钮(\$reply);\n"; break;
        case 'stream': $code .= "    流式(\$reply);\n"; break;
        default: $code .= "    文字(\$reply);\n";
    }
    $code .= "    return;\n";
    $code .= "}\n";
    return $code;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>API插件生成器 · 月下独酌管机</title>
    <link rel="stylesheet" href="assets/icons.css">
    <style>
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
        :focus{outline:none}
        :root{--bg:#ffffff;--card:#ffffff;--border:#ececec;--text-main:#1a1a1a;--text-sub:#555555;--text-muted:#999999;--primary:#1f1f1f;--primary-hover:#000000;--success:#3a7a3a;--danger:#c23d2e;--sidebar-width:240px;--header-height:52px}
        body{background:var(--bg);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:var(--text-main);line-height:1.5;-webkit-text-size-adjust:100%;overflow-x:hidden;width:100%}
        .desktop-layout{display:flex;min-height:100vh}
        .sidebar{width:var(--sidebar-width);background:var(--card);border-right:1px solid var(--border);position:fixed;top:0;bottom:0;left:0;display:flex;flex-direction:column;z-index:100}
        .sidebar-header{padding:20px 24px;border-bottom:1px solid var(--border)}
        .sidebar-header h1{font-size:18px;font-weight:600}
        .sidebar-header p{font-size:12px;color:var(--text-muted);margin-top:4px}
        .sidebar-nav{flex:1;padding:16px 0;overflow-y:auto}
        .nav-item{display:flex;align-items:center;gap:12px;padding:10px 24px;color:var(--text-sub);text-decoration:none;font-size:14px;font-weight:500;transition:all 0.15s;cursor:pointer}
        .nav-item:hover{background:#f5f5f5;color:var(--primary)}
        .nav-item.active{background:#f5f5f5;color:var(--primary);border-left:3px solid var(--primary);padding-left:21px}
        .nav-item i{width:20px;font-size:15px}
        .sidebar-footer{padding:16px 24px;border-top:1px solid var(--border);font-size:11px;color:var(--text-muted)}
        .main-content{flex:1;margin-left:var(--sidebar-width);min-height:100vh;min-width:0;overflow-x:hidden}
        .top-bar{background:var(--card);border-bottom:1px solid var(--border);padding:0 32px;height:var(--header-height);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10}
        .page-title{font-size:15px;font-weight:500}
        .back-btn{padding:6px 14px;border-radius:8px;background:#f5f5f5;color:var(--text-sub);text-decoration:none;font-size:12px;display:inline-flex;align-items:center;gap:6px}
        .container{padding:28px 32px;max-width:1400px;margin:0 auto}
        .card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px;margin-bottom:24px;overflow-x:hidden}
        .btn{padding:8px 18px;border-radius:10px;border:none;cursor:pointer;font-weight:500;display:inline-flex;align-items:center;gap:8px;white-space:nowrap}
        .btn-primary{background:var(--primary);color:white}
        .btn-secondary{background:#f5f5f5;color:var(--text-sub);border:1px solid var(--border)}
        .btn-danger{background:var(--danger);color:white}
        .form-group{margin-bottom:16px}
        .form-group label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--text-sub)}
        .form-control,.form-select{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;font-size:14px;background:white;max-width:100%}
        textarea.form-control{min-height:86px;resize:vertical}
        .row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .row3{display:grid;grid-template-columns:1fr 1fr 140px;gap:10px}
        .block-list{margin-top:12px}
        .block-item{background:#ffffff;border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:10px}
        .notification{position:fixed;bottom:20px;right:20px;padding:10px 16px;border-radius:8px;font-size:13px;background:var(--text-main);color:white;z-index:1100;transform:translateX(120%);transition:transform 0.2s;max-width:calc(100vw - 40px)}
        .notification.show{transform:translateX(0)}
        .notification.success{background:var(--success)}.notification.error{background:var(--danger)}
        .mobile-header{display:none;padding:12px 16px;background:white;border-bottom:1px solid var(--border);align-items:center;justify-content:space-between}
        .menu-toggle{background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-main)}
        .tabs{display:flex;gap:8px;margin-bottom:16px}
        .tab{padding:8px 16px;border-radius:8px;background:#f5f5f5;color:var(--text-sub);cursor:pointer;font-size:13px;font-weight:500}
        .tab.active{background:var(--primary);color:white}
        .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:1000;align-items:center;justify-content:center;padding:20px}
        .modal-content{background:white;border-radius:12px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;overflow-x:hidden}
        .modal-header{padding:18px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
        .modal-header h3{font-size:16px;font-weight:600}
        .close-btn{background:none;border:none;font-size:18px;cursor:pointer;color:var(--text-muted);padding:4px}
        .modal-body{padding:20px}
        .modal-footer{padding:16px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:12px}
        .alert-tip{background:#fff3cd;border-left:4px solid #ffc107;color:#856404;padding:6px 10px;font-size:12px;margin-top:4px;border-radius:4px}
        @media(max-width:768px){
            .sidebar{transform:translateX(-100%);transition:transform 0.2s;z-index:200;box-shadow:2px 0 12px rgba(0,0,0,0.1)}
            .sidebar.open{transform:translateX(0)}
            .main-content{margin-left:0}
            .mobile-header{display:flex}
            .top-bar{display:none}
            .container{padding:16px}
            .form-control,.form-select{font-size:16px}
            .row,.row3{grid-template-columns:1fr}
        }
    </style>
</head>
<body>
<div class="mobile-header">
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <span style="font-weight:500;">月下独酌管机</span>
    <button type="button" class="btn btn-secondary" id="mobileShowExampleBtn" style="padding:6px 12px;font-size:13px;">
        <i class="fas fa-code"></i> 示例
    </button>
</div>
<div class="desktop-layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header"><h1>月下独酌管机</h1><p>机器人管理后台</p></div>
        <nav class="sidebar-nav">
            <a href="main.php" class="nav-item"><i class="fas fa-tachometer-alt"></i> 总览</a>
            <a href="#" class="nav-item" id="navAddBot"><i class="fas fa-plus-circle"></i> 添加机器人</a>
            <a href="set.php" class="nav-item"><i class="fas fa-user-cog"></i> 账号设置</a>
            <a href="simulate.php" class="nav-item"><i class="fas fa-vial"></i> 指令测试</a>
            <a href="cmdtest.php" class="nav-item"><i class="fas fa-paper-plane"></i> 主动消息</a>
            <a href="custom_api.php" class="nav-item active"><i class="fas fa-code-branch"></i> API插件生成器</a>
                <a href="aidev.php" class="nav-item"><i class="fas fa-robot"></i> AI 写插件</a>
            <a href="doc.php" class="nav-item"><i class="fas fa-file-alt"></i> 开发文档</a>
            <a href="update.php" class="nav-item"><i class="fas fa-cloud-upload-alt"></i> 在线更新</a>
            <a href="ws.php" class="nav-item"><i class="fas fa-sync-alt"></i> WebSocket 模式</a>
        </nav>
        <div class="sidebar-footer">保留 1.0 原有逻辑 · 简洁商务版</div>
    </aside>
    <main class="main-content">
        <div class="top-bar">
            <div class="page-title">API插件生成器</div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="btn btn-secondary" id="showExampleBtn"><i class="fas fa-code"></i> 查看示例代码</button>
                <a href="main.php" class="back-btn"><i class="fas fa-arrow-left"></i> 返回后台</a>
            </div>
        </div>
        <div class="container">
            <div class="tabs" id="modeTabs">
                <div class="tab active" data-mode="block">可视化构建</div>
                <div class="tab" data-mode="quick">快速API模式</div>
            </div>

            <div id="blockMode" class="mode-section">
                <div class="card">
                    <h3>插件基本信息</h3>
                    <div class="form-group"><label>插件名称</label><input type="text" id="pluginName" class="form-control" value="统计"></div>
                    <div class="form-group"><label>管理员ID（可选）</label><input type="text" id="adminId" class="form-control" placeholder="留空则所有人可用"></div>
                    <div class="form-group"><label>触发事件</label><select id="events" class="form-select" multiple style="height:100px;"><option value="群聊" selected>群聊</option><option value="私聊" selected>私聊</option><option value="加群">加群</option><option value="退群">退群</option></select></div>
                    <div class="form-group"><label>触发条件</label><select id="triggerType" class="form-select"><option value="equals">命令完全匹配</option><option value="prefix">前缀匹配</option></select><input type="text" id="triggerValue" class="form-control" placeholder="命令内容" value="统计" style="margin-top:6px;"></div>
                </div>
                <div class="card">
                    <h4>响应动作块 <button type="button" class="btn btn-secondary" id="addBlockBtn"><i class="fas fa-plus"></i> 添加动作</button></h4>
                    <div id="blockList" class="block-list"></div>
                    <div style="margin-top:20px;display:flex;gap:12px;"><button type="button" class="btn btn-primary" id="generateBlockBtn"><i class="fas fa-save"></i> 生成并保存</button></div>
                </div>
            </div>

            <div id="quickMode" class="mode-section" style="display:none;">
                <div class="card">
                    <h3>快速API插件</h3>
                    <div class="form-group"><label>插件名称</label><input type="text" id="qName" class="form-control" value="一言"></div>
                    <div class="row3"><input id="qTrigger" placeholder="触发词" class="form-control" value="一言"><select id="qMatch" class="form-select"><option value="equals">完全匹配</option><option value="prefix">前缀匹配</option><option value="keyword">包含触发</option></select></div>
                    <div class="row" style="margin-top:10px;"><input id="qUrl" placeholder="接口URL" class="form-control" value="https://v1.hitokoto.cn/"><input id="qPath" placeholder="返回字段" class="form-control" value="hitokoto"></div>
                    <div class="form-group" style="margin-top:10px;"><label>回复模板</label><textarea id="qReply" class="form-control" rows="4">{response}</textarea></div>
                    <div class="form-group" style="margin-top:10px;"><label>发送方式</label>
                        <select id="qSend" class="form-select">
                            <option value="text">文字</option>
                            <option value="native_md">原生MD</option>
                            <option value="image">图片</option>
                            <option value="video">视频</option>
                            <option value="wenka">文卡</option>
                            <option value="datu">大图</option>
                            <option value="tiaozhuan">跳转卡</option>
                            <option value="button">按钮</option>
                            <option value="stream">流式</option>
                            <option value="card">原有卡片</option>
                        </select>
                        <small style="color:var(--text-muted)">文卡/大图/跳转卡等仅私域机器人支持，参数默认使用API返回值</small>
                    </div>
                    <details style="margin-top:12px;"><summary>高级请求设置</summary><div class="row3" style="margin-top:10px;"><select id="qMethod" class="form-select"><option>GET</option><option>POST</option></select><input id="qTimeout" type="number" value="10" class="form-control"><input id="qHeaders" value="{}" placeholder="请求头JSON" class="form-control"></div><textarea id="qBody" class="form-control" style="margin-top:10px;" placeholder="POST请求体"></textarea></details>
                    <div style="margin-top:20px;"><button type="button" class="btn btn-primary" id="generateQuickBtn"><i class="fas fa-save"></i> 生成并保存</button></div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- 添加机器人模态框 -->
<div class="modal" id="addModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>添加机器人</h3>
            <button class="close-btn" data-close="addModal">&times;</button>
        </div>
        <form id="addForm">
            <div class="modal-body">
                <div class="form-group"><label>AppID</label><input type="text" class="form-control" id="addAppid" required placeholder="请输入机器人 AppID"></div>
                <div class="form-group"><label>Secret</label><input type="text" class="form-control" id="addSecret" required placeholder="请输入机器人 Secret"></div>
                <div class="form-group"><label>环境</label><select class="form-select" id="addEnvironment"><option value="正式">正式环境</option><option value="沙箱">沙箱环境</option></select></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close="addModal">取消</button>
                <button type="submit" class="btn btn-primary">添加</button>
            </div>
        </form>
    </div>
</div>

<!-- 示例代码模态框（全函数 + API请求） -->
<div class="modal" id="exampleModal">
    <div class="modal-content" style="max-width:700px;">
        <div class="modal-header">
            <h3>示例代码（全函数 + API请求）</h3>
            <button class="close-btn" data-close="exampleModal">&times;</button>
        </div>
        <div class="modal-body" style="font-size:13px;">
            <pre style="background:#1e293b;color:#ececec;padding:12px;border-radius:8px;overflow:auto;max-height:60vh;">
&lt;?php
// 触发命令示例（私聊或群聊发送）
if (消息 == "#测试文字") {
    文字("这是一条文字消息");
}
elseif (消息 == "#测试图片") {
    图片("https://picsum.photos/400/300", "示例图片");
}
elseif (消息 == "#测试语音") {
    语音("https://example.com/audio.mp3");
}
elseif (消息 == "#测试视频") {
    视频("https://example.com/video.mp4");
}
elseif (消息 == "#测试文卡") {
    文卡(["text" => "标题"], ["text" => "详情", "url" => "https://..."]);
}
elseif (消息 == "#测试大图") {
    大图("大标题", "小标题", "https://...");
}
elseif (消息 == "#测试跳转卡") {
    跳转卡("标题", "介绍", "https://...", "https://...");
}
elseif (消息 == "#测试流式") {
    流式("第一段", "第二段", "第三段");
}
elseif (消息 == "撤回") {
    $sendMsg = 文字("这条消息会被自动撤回");
    $msgData = json_decode($sendMsg, true);
    $msgId = $msgData['id'] ?? '';
    if ($msgId) { sleep(3); 撤回($msgId); }
    else 文字("发送失败");
}
elseif (消息 == "#测试文件") {
    文件("https://example.com/file.pdf");
}
elseif (消息 == "#测试MD") {
    MD("**粗体** *斜体*");
}
elseif (消息 == "#测试BOT信息") {
    $info = BOT信息();
    文字(is_array($info) ? json_encode($info) : $info);
}
elseif (消息 == "#测试头像") {
    $av = 头像(用户);
    文字($av);
}
elseif (消息 == "#测试二维码") {
    $qr = 二维码("https://...");
    图片($qr, "二维码");
}
elseif (消息 == "#测试域名大写") {
    $res = 域名大写("hello world");
    文字($res);
}
elseif (消息 == "#测试MD转HTML") {
    $html = markdown转html("# Hello");
    文字($html);
}
elseif (消息 == "#测试HTML转图") {
    $img = HTML转图("&lt;div&gt;测试&lt;/div&gt;", 400, 200);
    图片($img, "HTML转图");
}
elseif (消息 == "#测试邮箱") {
    邮箱("标题","内容","to@qq.com","from@qq.com","授权码");
}
elseif (消息 == "#测试Curl") {
    $resp = curl("https://httpbin.org/get", "GET", [], []);
    文字($resp);
}
elseif (消息 == "#测试API提取") {
    $raw = curl("https://api.example.com/data", "GET", [], []);
    $json = json_decode($raw, true);
    $value = $json['field'] ?? $raw;
    文字($value);
}
elseif (消息 == "#测试画布") {
    $gd = new 画布();
    $img = $gd->创建(400,300,"#FFF");
    $gd->文字($img, "Hello", 24, 50, 50, "#000", __DIR__."/font.ttf");
    $data = $gd->二进制输出($img);
    文件($data);
    $gd->销毁($img);
}
// 可根据需要添加更多...
?>
            </pre>
        </div>
    </div>
</div>

<div id="notification" class="notification"></div>

<script>
    const blockTypes = [
        {value:'text',label:'文字回复'},{value:'image',label:'图片'},{value:'audio',label:'语音'},
        {value:'video',label:'视频'},{value:'file',label:'文件'},{value:'md',label:'Markdown'},
        {value:'md_template',label:'模板Markdown'},{value:'button',label:'官方键盘按钮'},
        {value:'inline_button',label:'原生自定义按钮'},{value:'ark_text',label:'Ark文本列表卡'},
        {value:'ark_big',label:'大图卡'},{value:'ark_jump',label:'跳转卡'},{value:'stream',label:'流式回复'},
        {value:'recall',label:'撤回消息'},{value:'api_request',label:'BOTAPI请求'},
        {value:'api_fetch',label:'API请求并提取字段'},{value:'data_write',label:'写数据'},
        {value:'data_read',label:'读数据'},{value:'curl',label:'CURL请求'},
        {value:'avatar',label:'获取头像'},{value:'bot_info',label:'BOT信息'},
        {value:'qrcode',label:'生成二维码'},{value:'md_to_html',label:'MD转HTML'},
        {value:'html_to_image',label:'HTML转图'},{value:'email',label:'发送邮件'},
        {value:'domain_capitalize',label:'域名大写'},{value:'gd_init',label:'GD创建画布'},
        {value:'gd_text',label:'GD添加文字'},{value:'gd_output',label:'GD输出图片'},
        {value:'var_set',label:'设置变量'}
    ];

    let blocks = [];
    const $ = id => document.getElementById(id);

    function showMsg(text, success) {
        const el = $('notification');
        el.textContent = text;
        el.className = 'notification ' + (success ? 'success' : 'error') + ' show';
        setTimeout(() => el.classList.remove('show'), 2500);
    }

    function closeModal(id) { const modal = document.getElementById(id); if(modal) modal.style.display = 'none'; }

    document.querySelectorAll('#modeTabs .tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('#modeTabs .tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById('blockMode').style.display = tab.dataset.mode==='block' ? 'block' : 'none';
            document.getElementById('quickMode').style.display = tab.dataset.mode==='quick' ? 'block' : 'none';
        });
    });

    function createBlockHTML(index, type = 'text') {
        let fields = '';
        if (type === 'text' || type === 'md') {
            fields = `<textarea class="form-control block-content" placeholder="内容" rows="3"></textarea>`;
        } else if (type === 'stream') {
            fields = `<textarea class="form-control block-content" placeholder="用英文逗号分隔每段内容" rows="2">第一段,第二段</textarea>`;
        } else if (['image','audio','video','file'].includes(type)) {
            fields = `<input class="form-control block-url" placeholder="URL">`;
            if (type === 'image') fields += `<input class="form-control block-caption" placeholder="图片说明（可选）" style="margin-top:4px;">`;
            if (type === 'file') fields += `<input class="form-control block-filename" placeholder="文件名" style="margin-top:4px;">`;
        } else if (type === 'button') {
            fields = `<input class="form-control block-keyboard" placeholder="keyboard_id">`;
        } else if (type === 'md_template') {
            fields = `<input class="form-control block-templateid" placeholder="模板ID"><textarea class="form-control block-params" placeholder="参数JSON" rows="2" style="margin-top:4px;">[{"key":"k","values":["v"]}]</textarea>`;
        } else if (type === 'data_write') {
            fields = `<input class="form-control block-file" placeholder="文件名"><input class="form-control block-key" placeholder="键名" style="margin-top:4px;"><input class="form-control block-value" placeholder="值" style="margin-top:4px;">`;
        } else if (type === 'data_read') {
            fields = `<input class="form-control block-file" placeholder="文件名"><input class="form-control block-key" placeholder="键名" style="margin-top:4px;"><input class="form-control block-default" placeholder="默认值" style="margin-top:4px;"><input class="form-control block-var" placeholder="变量名" style="margin-top:4px;" value="data">`;
        } else if (type === 'api_fetch') {
            fields = `<input class="form-control block-url" placeholder="接口URL"><input class="form-control block-path" placeholder="提取字段路径" style="margin-top:4px;"><input class="form-control block-var" placeholder="存储变量名" style="margin-top:4px;" value="res"><input class="form-control block-method" placeholder="GET/POST" style="margin-top:4px;" value="GET"><input class="form-control block-headers" placeholder="请求头JSON" style="margin-top:4px;"><textarea class="form-control block-body" placeholder="POST请求体" rows="2" style="margin-top:4px;"></textarea>`;
        } else if (type === 'curl') {
            fields = `<input class="form-control block-url" placeholder="URL"><input class="form-control block-method" placeholder="GET/POST" style="margin-top:4px;" value="GET"><input class="form-control block-headers" placeholder="头部JSON" style="margin-top:4px;"><input class="form-control block-params" placeholder="参数" style="margin-top:4px;"><input class="form-control block-var" placeholder="变量名" style="margin-top:4px;" value="res">`;
        } else if (type === 'avatar') {
            fields = `<input class="form-control block-userid" placeholder="用户ID变量（默认：用户）" value="用户"><input class="form-control block-var" placeholder="变量名" style="margin-top:4px;" value="avatar">`;
        } else if (type === 'qrcode') {
            fields = `<input class="form-control block-content" placeholder="二维码内容"><input class="form-control block-var" placeholder="变量名" style="margin-top:4px;" value="qrcode"><label style="display:flex;align-items:center;gap:6px;margin-top:6px;"><input type="checkbox" class="block-auto-send" checked> 自动发送图片</label><input class="form-control block-caption" placeholder="图片说明（可选）" style="margin-top:4px;">`;
        } else if (type === 'md_to_html') {
            // 修复：正确处理 md 内容
            fields = `<input class="form-control block-md" placeholder="Markdown内容" value="# Hello"><input class="form-control block-var" placeholder="变量名" style="margin-top:4px;" value="html">`;
        } else if (type === 'html_to_image') {
            fields = `<textarea class="form-control block-html" placeholder="HTML内容" rows="3"></textarea>
                      <div class="row" style="margin-top:4px;"><input class="form-control block-width" placeholder="宽度" value="800"><input class="form-control block-height" placeholder="高度" value="600"></div>
                      <input class="form-control block-var" placeholder="变量名" style="margin-top:4px;" value="image">
                      <label style="display:flex;align-items:center;gap:6px;margin-top:6px;"><input type="checkbox" class="block-auto-send"> 自动发送图片</label>
                      <input class="form-control block-caption" placeholder="图片说明（可选）" style="margin-top:4px;">`;
        } else if (type === 'email') {
            fields = `<input class="form-control block-title" placeholder="标题"><input class="form-control block-content" placeholder="内容" style="margin-top:4px;"><input class="form-control block-to" placeholder="收件人" style="margin-top:4px;"><input class="form-control block-from" placeholder="发件人" style="margin-top:4px;"><input class="form-control block-pass" placeholder="授权码" style="margin-top:4px;">`;
        } else if (type === 'domain_capitalize') {
            fields = `<input class="form-control block-input" placeholder="输入URL"><input class="form-control block-var" placeholder="变量名" style="margin-top:4px;" value="result">`;
        } else if (type === 'gd_init') {
            fields = `<input class="form-control block-width" placeholder="宽度" value="600"><input class="form-control block-height" placeholder="高度" value="300" style="margin-top:4px;"><input class="form-control block-bg" placeholder="背景色" value="#FFFFFF" style="margin-top:4px;"><input class="form-control block-var" placeholder="画布变量名" value="img" style="margin-top:4px;">`;
        } else if (type === 'gd_text') {
            fields = `<input class="form-control block-img" placeholder="画布变量名" value="img"><input class="form-control block-text" placeholder="文字内容" style="margin-top:4px;"><input class="form-control block-size" placeholder="字号" value="24" style="margin-top:4px;"><input class="form-control block-x" placeholder="X" value="50" style="margin-top:4px;"><input class="form-control block-y" placeholder="Y" value="80" style="margin-top:4px;"><input class="form-control block-color" placeholder="颜色" value="#000000" style="margin-top:4px;"><input class="form-control block-font" placeholder="字体路径" value="__DIR__ . '/../font.ttf'" style="margin-top:4px;">`;
        } else if (type === 'gd_output') {
            fields = `<input class="form-control block-img" placeholder="画布变量名" value="img"><input class="form-control block-var" placeholder="输出变量名" value="imageData" style="margin-top:4px;">`;
        } else if (type === 'ark_text') {
            fields = `<textarea class="form-control block-ark-items" placeholder="JSON数组" rows="3">[{"text":"标题"},{"text":"详情","url":"https://..."}]</textarea><div class="alert-tip">⚠️ 仅私域机器人支持发送此卡片</div>`;
        } else if (type === 'ark_big') {
            fields = `<input class="form-control block-ark-title" placeholder="大标题"><input class="form-control block-ark-subtitle" placeholder="小标题" style="margin-top:4px;"><input class="form-control block-ark-image" placeholder="图片链接" style="margin-top:4px;">
                      <label style="display:flex;align-items:center;gap:6px;margin-top:6px;"><input type="checkbox" class="block-add-timestamp"> 添加时间戳（防缓存）</label>
                      <div class="alert-tip">⚠️ 仅私域机器人支持发送此卡片</div>`;
        } else if (type === 'ark_jump') {
            fields = `<input class="form-control block-ark-title" placeholder="标题"><input class="form-control block-ark-desc" placeholder="介绍" style="margin-top:4px;"><input class="form-control block-ark-image" placeholder="图片链接" style="margin-top:4px;"><input class="form-control block-ark-url" placeholder="跳转链接" style="margin-top:4px;">
                      <label style="display:flex;align-items:center;gap:6px;margin-top:6px;"><input type="checkbox" class="block-add-timestamp"> 添加时间戳（防缓存）</label>
                      <div class="alert-tip">⚠️ 仅私域机器人支持发送此卡片</div>`;
        } else if (type === 'recall') {
            fields = `<input class="form-control block-recall-content" placeholder="要发送并撤回的消息内容" value="这条消息会被自动撤回">
                      <input class="form-control block-recall-delay" placeholder="延时秒数" value="3" style="margin-top:4px;">
                      <div class="alert-tip">会自动发送一条消息，获取ID，延时后撤回</div>`;
        } else if (type === 'bot_info') {
            fields = `<div class="alert-tip">BOT信息将直接发送，无需额外文字块。</div>`;
        }
        if (!fields) fields = `<input class="form-control block-content" placeholder="内容">`;
        return `<div class="block-item" data-index="${index}">
            <div style="display:flex;gap:8px;align-items:center;">
                <select class="form-select block-type" style="flex:1;">${blockTypes.map(t => `<option value="${t.value}" ${t.value===type?'selected':''}>${t.label}</option>`).join('')}</select>
                <button type="button" class="btn btn-secondary remove-block"><i class="fas fa-trash"></i></button>
            </div>
            <div class="block-fields" style="margin-top:8px;">${fields}</div></div>`;
    }

    function addBlock(type = 'text') {
        blocks.push({type});
        $('blockList').insertAdjacentHTML('beforeend', createBlockHTML(blocks.length - 1, type));
    }
    function renderBlocks() {
        $('blockList').innerHTML = '';
        blocks.forEach((b, i) => $('blockList').insertAdjacentHTML('beforeend', createBlockHTML(i, b.type)));
    }

    function collectBlocks() {
        const items = document.querySelectorAll('#blockList .block-item');
        const result = [];
        items.forEach(item => {
            const type = item.querySelector('.block-type').value;
            const fields = {};

            const content = item.querySelector('.block-content'); if(content) fields.content = content.value;
            const url = item.querySelector('.block-url'); if(url) fields.url = url.value;
            const caption = item.querySelector('.block-caption'); if(caption) fields.caption = caption.value;
            const filename = item.querySelector('.block-filename'); if(filename) fields.filename = filename.value;
            const keyboard = item.querySelector('.block-keyboard'); if(keyboard) fields.keyboard_id = keyboard.value;
            const templateid = item.querySelector('.block-templateid'); if(templateid) fields.template_id = templateid.value;
            const params = item.querySelector('.block-params'); if(params) { try { fields.params = JSON.parse(params.value); } catch { fields.params = []; } }
            const file = item.querySelector('.block-file'); if(file) fields.file = file.value;
            const key = item.querySelector('.block-key'); if(key) fields.key = key.value;
            const value = item.querySelector('.block-value'); if(value) fields.value = value.value;
            const defaultVal = item.querySelector('.block-default'); if(defaultVal) fields.default = defaultVal.value;
            const varInput = item.querySelector('.block-var'); if(varInput) fields.var = varInput.value;
            const method = item.querySelector('.block-method'); if(method) fields.method = method.value;
            const headers = item.querySelector('.block-headers'); if(headers) fields.headers = headers.value;
            const bodyEl = item.querySelector('.block-body'); if(bodyEl) fields.body = bodyEl.value;
            const userid = item.querySelector('.block-userid'); if(userid) fields.user_id = userid.value;
            const title = item.querySelector('.block-title'); if(title) fields.title = title.value;
            const to = item.querySelector('.block-to'); if(to) fields.to = to.value;
            const from = item.querySelector('.block-from'); if(from) fields.from = from.value;
            const pass = item.querySelector('.block-pass'); if(pass) fields.pass = pass.value;
            const width = item.querySelector('.block-width'); if(width) fields.width = width.value;
            const height = item.querySelector('.block-height'); if(height) fields.height = height.value;
            const bg = item.querySelector('.block-bg'); if(bg) fields.bg = bg.value;
            const img = item.querySelector('.block-img'); if(img) fields.img = img.value;
            const text = item.querySelector('.block-text'); if(text) fields.text = text.value;
            const size = item.querySelector('.block-size'); if(size) fields.size = size.value;
            const x = item.querySelector('.block-x'); if(x) fields.x = x.value;
            const y = item.querySelector('.block-y'); if(y) fields.y = y.value;
            const color = item.querySelector('.block-color'); if(color) fields.color = color.value;
            const font = item.querySelector('.block-font'); if(font) fields.font = font.value;
            const path = item.querySelector('.block-path'); if(path) fields.path = path.value;
            const html = item.querySelector('.block-html'); if(html) fields.html = html.value;
            const input = item.querySelector('.block-input'); if(input) fields.input = input.value;
            const autoSend = item.querySelector('.block-auto-send'); if(autoSend) fields.auto_send = autoSend.checked;
            const addTimestamp = item.querySelector('.block-add-timestamp'); if(addTimestamp) fields.add_timestamp = addTimestamp.checked;
            const recallContent = item.querySelector('.block-recall-content'); if(recallContent) fields.content = recallContent.value;
            const recallDelay = item.querySelector('.block-recall-delay'); if(recallDelay) fields.delay = recallDelay.value;

            // 专用于 md_to_html 的字段修正
            const md = item.querySelector('.block-md'); if(md) fields.md = md.value;

            const arkItems = item.querySelector('.block-ark-items'); if(arkItems) { try { fields.items = JSON.parse(arkItems.value); } catch { fields.items = [{"text":"解析失败"}]; } }
            const arkTitle = item.querySelector('.block-ark-title'); if(arkTitle) fields.title = arkTitle.value;
            const arkSubtitle = item.querySelector('.block-ark-subtitle'); if(arkSubtitle) fields.subtitle = arkSubtitle.value;
            const arkImage = item.querySelector('.block-ark-image'); if(arkImage) fields.image = arkImage.value;
            const arkDesc = item.querySelector('.block-ark-desc'); if(arkDesc) fields.desc = arkDesc.value;
            const arkUrl = item.querySelector('.block-ark-url'); if(arkUrl) fields.url = arkUrl.value;

            if (type === 'stream' && content) fields.contents = content.value.split(',').map(s => s.trim()).filter(s => s);
            result.push({type, ...fields});
        });
        return result;
    }

    async function saveBlockPlugin() {
        const name = $('pluginName').value.trim().replace(/[^a-zA-Z0-9_\u4e00-\u9fa5]/g,'');
        if (!name) return showMsg('插件名不合法', false);
        const blks = collectBlocks();
        if (!blks.length) return showMsg('请添加动作块', false);
        const payload = new URLSearchParams({
            type: 'generate', mode: 'block', name,
            admin_id: $('adminId').value,
            events: JSON.stringify(Array.from($('events').selectedOptions).map(o => o.value)),
            trigger_type: $('triggerType').value,
            trigger_value: $('triggerValue').value,
            blocks: JSON.stringify(blks)
        });
        try {
            const res = await fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: payload });
            const data = await res.json();
            showMsg(data.msg, data.code === 200);
        } catch (e) { showMsg('失败: ' + e.message, false); }
    }

    $('addBlockBtn').onclick = () => addBlock('text');
    $('generateBlockBtn').onclick = saveBlockPlugin;
    $('blockList').addEventListener('click', e => {
        if (e.target.closest('.remove-block')) {
            const idx = parseInt(e.target.closest('.block-item').dataset.index);
            blocks.splice(idx, 1);
            renderBlocks();
        }
    });
    $('blockList').addEventListener('change', e => {
        if (e.target.classList.contains('block-type')) {
            const idx = parseInt(e.target.closest('.block-item').dataset.index);
            blocks[idx].type = e.target.value;
            renderBlocks();
        }
    });

    async function saveQuickPlugin() {
        const name = $('qName').value.trim().replace(/[^a-zA-Z0-9_\u4e00-\u9fa5]/g,'');
        if (!name) return showMsg('插件名不合法', false);
        const payload = new URLSearchParams({
            type: 'generate', mode: 'quick', name,
            admin_id: $('adminId').value,
            trigger: $('qTrigger').value, match: $('qMatch').value,
            url: $('qUrl').value, path: $('qPath').value,
            reply: $('qReply').value, send: $('qSend').value,
            method: $('qMethod').value, timeout: $('qTimeout').value,
            headers: $('qHeaders').value, body: $('qBody').value
        });
        try {
            const res = await fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: payload });
            const data = await res.json();
            showMsg(data.msg, data.code === 200);
        } catch (e) { showMsg('失败: ' + e.message, false); }
    }
    $('generateQuickBtn').onclick = saveQuickPlugin;

    // 示例代码按钮（桌面 + 移动端）
    function openExampleModal() { $('exampleModal').style.display = 'flex'; }
    $('showExampleBtn')?.addEventListener('click', openExampleModal);
    $('mobileShowExampleBtn')?.addEventListener('click', openExampleModal);

    document.querySelectorAll('[data-close]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.close)));
    window.addEventListener('click', e => { if (e.target.classList && e.target.classList.contains('modal')) e.target.style.display = 'none'; });

    // 添加机器人
    document.getElementById('navAddBot').addEventListener('click', function(e) {
        e.preventDefault();
        document.getElementById('addModal').style.display = 'flex';
    });
    document.getElementById('addForm')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const addAppid = document.getElementById('addAppid').value.trim();
        const addSecret = document.getElementById('addSecret').value.trim();
        const addEnv = document.getElementById('addEnvironment').value;
        if (!addAppid || !addSecret) { showMsg('请填写完整信息', false); return; }
        try {
            const res = await fetch(`api/bot.php?type=add&appid=${encodeURIComponent(addAppid)}&secret=${encodeURIComponent(addSecret)}&environment=${encodeURIComponent(addEnv)}`);
            const data = await res.json();
            if (data.code === 200) {
                showMsg('添加成功', true);
                closeModal('addModal');
                document.getElementById('addForm').reset();
            } else showMsg(data.msg || '添加失败', false);
        } catch (err) { showMsg('网络错误', false); }
    });

    // 移动端菜单
    $('menuToggle').onclick = e => { e.stopPropagation(); $('sidebar').classList.toggle('open'); };
    document.addEventListener('click', e => {
        if (window.innerWidth <= 768 && !$('sidebar').contains(e.target) && e.target !== $('menuToggle')) $('sidebar').classList.remove('open');
    });

    addBlock('text');
</script>
</body>
</html>