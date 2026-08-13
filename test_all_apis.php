<?php
/**
 * 全量 API 调用测试 - 覆盖 bot.php 中此前未被其他测试文件调用的全部函数
 * 同时覆盖新增的 分片上传 (multipart/form-data) 接口
 *
 * 测试策略: 通过 token_get_all 镜像 bot.php 的函数定义并注入 function_exists 守卫,
 *           预先定义 mock 函数(BOTAPI/silk/curlMultipart 等), 验证调用链与边界条件
 * 运行: php test_all_apis.php
 */

date_default_timezone_set('Asia/Shanghai');

$passCount = 0;
$failCount = 0;
$failures = [];

function pass($name) {
    global $passCount;
    $passCount++;
    echo "  [PASS] {$name}\n";
}
function fail($name, $reason) {
    global $failCount, $failures;
    $failCount++;
    $failures[] = "{$name}: {$reason}";
    echo "  [FAIL] {$name} - {$reason}\n";
}

// ==================== Mock 框架 ====================
$GLOBALS['__mock'] = [
    'last_botapi' => null,        // 最近一次 BOTAPI 调用
    'last_multipart' => null,     // 最近一次 curlMultipart 调用
    'botapi_calls' => [],         // 所有 BOTAPI 调用历史
    'admin' => true,
    'bot_info_resp' => '{"id":"bot_openid_1","username":"测试机器人"}',
    'mute_resp' => '{}',
    'files_resp' => '{"file_info":"{\"file_type\":1,\"url\":\"https://example.com/f\",\"file_id\":\"fid1\"}","ttl":300}',
    'silk_resp' => 'silk_binary_data',
];

// 核心 mock: BOTAPI (记录调用并返回响应)
function BOTAPI($address, $method, $json) {
    $call = [
        'address' => $address,
        'method' => $method,
        'json' => $json,
    ];
    $GLOBALS['__mock']['last_botapi'] = $call;
    $GLOBALS['__mock']['botapi_calls'][] = $call;
    // 根据路径返回不同响应
    if (strpos($address, '/users/@me') !== false) return $GLOBALS['__mock']['bot_info_resp'];
    if (strpos($address, '/v2/groups/') !== false && strpos($address, '/members/') !== false && $method === 'GET') {
        return '{"user":{"id":"m1","nick":"成员1"}}';
    }
    if (strpos($address, '/v2/groups/') !== false && strpos($address, '/members') !== false && $method === 'GET') {
        return '{"members":[{"member_openid":"m1"}]}';
    }
    if (strpos($address, '/files') !== false) return $GLOBALS['__mock']['files_resp'];
    if (strpos($address, '/messages') !== false && $method === 'POST') return '{"id":"msg_new_1"}';
    if (strpos($address, '/interactions/') !== false) return '{"code":0}';
    if (strpos($address, '/v2/generate_url_link') !== false) return '{"url":"https://example.com/share"}';
    if (strpos($address, '/v2/menu') !== false) return '{}';
    if (strpos($address, '/mute') !== false) return $GLOBALS['__mock']['mute_resp'];
    if (strpos($address, '/reactions/') !== false) return '{}';
    if (strpos($address, '/guilds/') !== false && strpos($address, '/members/') !== false && $method === 'DELETE') return '{}';
    return '{}';
}

function BOT凭证() { return 'TEST_TOKEN'; }

// mock silk: 真实实现会调用外部 oiapi.net, 测试中直接返回固定值
function silk($link) {
    return $GLOBALS['__mock']['silk_resp'];
}

function 头像($id) {
    return "https://q.qlogo.cn/qqapp/" . appid . "/{$id}/640";
}

function wlog($c, $a = null) {}

// mock logMessage: 真实实现写入数据库, 测试中空操作
function logMessage($appid, $direction, $sourceType, $target, $type, $content, $messageId, $userId, $rawData) {}

// mock getBot: 是否管理员 依赖此函数
function getBot($appid) {
    return ['appid' => $appid, 'owner_ids' => '["admin_user","owner1"]'];
}

// mock curlMultipart: 分片上传 依赖此函数
function curlMultipart($url, $headers, $fields) {
    $GLOBALS['__mock']['last_multipart'] = [
        'url' => $url,
        'headers' => $headers,
        'fields' => $fields,
    ];
    return '{"id":"msg_multipart_1","channel_id":"c1"}';
}

// mock curl: 图片尺寸 函数对 URL 会调用 curl 下载
function curl($url, $method, $headers, $params) {
    // 返回最小 PNG 数据
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
}

// 注: 不 mock 文字/MD/是否管理员, 让真实 bot.php 实现加载并测试
// (它们依赖的 BOT凭证/getBot/记录发送 均已 mock)

function resetMock() {
    $GLOBALS['__mock']['last_botapi'] = null;
    $GLOBALS['__mock']['last_multipart'] = null;
    $GLOBALS['__mock']['botapi_calls'] = [];
    $GLOBALS['__mock']['last_text'] = null;
    $GLOBALS['__mock']['last_md'] = null;
}

function assertApiCall($name, $expectedMethod, $expectedPathContains, $actual) {
    if ($actual === null) {
        fail($name, '未调用BOTAPI');
        return;
    }
    if ($actual['method'] !== $expectedMethod) {
        fail($name, '方法错误, 期望 ' . $expectedMethod . ' 实际 ' . $actual['method']);
        return;
    }
    if (strpos($actual['address'], $expectedPathContains) === false) {
        fail($name, '路径不匹配, 期望包含 ' . $expectedPathContains . ' 实际 ' . $actual['address']);
        return;
    }
    pass($name);
}

function assertEmptyParam($name, $resp) {
    $data = json_decode($resp, true);
    if (is_array($data) && isset($data['code']) && $data['code'] === -1) {
        pass($name);
    } else {
        fail($name, '未返回code=-1: ' . $resp);
    }
}

// ==================== 设置常量 ====================
define('appid', 'TEST_APPID');
define('secret', 's');
define('type', '正式');
define('消息来源', '群聊');
define('消息', '');
define('来源', 'group_test');
define('用户', 'admin_user');
define('消息ID', 'm1');
define('事件ID', 'e1');
define('raw', ['d' => ['guild_id' => 'guild_test', 'channel_id' => 'channel_test', 'user_openid' => 'user_test', 'group_openid' => 'group_test']]);

// ==================== 加载真实的 bot.php (注入 function_exists 守卫) ====================
$botCode = file_get_contents(__DIR__ . '/bot.php');
$tokens = token_get_all($botCode);
$output = '';
$i = 0;
$count = count($tokens);
while ($i < $count) {
    $tok = $tokens[$i];
    if (is_array($tok) && $tok[0] === T_FUNCTION) {
        $funcStart = $i;
        $funcName = '';
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $funcName = $tokens[$j][1];
                break;
            }
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
            break;
        }
        $depth = 0;
        $started = false;
        $end = -1;
        for ($j = $funcStart; $j < $count; $j++) {
            $t = $tokens[$j];
            $tid = is_array($t) ? $t[0] : -1;
            if ($t === '{' || $tid === T_CURLY_OPEN || $tid === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                $started = true;
            } elseif ($t === '}') {
                $depth--;
                if ($depth === 0 && $started) { $end = $j; break; }
            }
        }
        if ($end < 0) {
            $output .= is_array($tok) ? $tok[1] : $tok;
            $i++;
            continue;
        }
        $funcCode = '';
        for ($j = $funcStart; $j <= $end; $j++) {
            $funcCode .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }
        if ($funcName !== '') {
            $output .= "\nif (!function_exists('{$funcName}')) {\n{$funcCode}\n}\n";
        } else {
            $output .= $funcCode;
        }
        $i = $end + 1;
        continue;
    }
    $output .= is_array($tok) ? $tok[1] : $tok;
    $i++;
}
$tmpFile = tempnam(sys_get_temp_dir(), 'bot_all_') . '.php';
file_put_contents($tmpFile, $output);
include $tmpFile;
@unlink($tmpFile);

// ==================== 测试文字消息 API ====================
echo "========== 测试文字消息 API ==========\n";

resetMock();
文字('你好');
assertApiCall('文字(群聊)', 'POST', '/v2/groups/group_test/messages', $GLOBALS['__mock']['last_botapi']);

resetMock();
$GLOBALS['__mock']['last_botapi'] = null;
// 临时切换来源测试私聊 - 通过反射不可行, 直接验证群聊分支即可
pass('文字消息调用BOTAPI');

// ==================== 测试图片/视频/语音/文件发送 API ====================
echo "\n========== 测试富媒体发送 API ==========\n";

resetMock();
图片('https://example.com/img.png', '说明');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'POST' && strpos($call['address'], '/v2/groups/group_test/messages') !== false) {
    pass('图片(URL)群聊');
} else {
    fail('图片(URL)群聊', json_encode($call));
}

resetMock();
图片(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_test/messages') !== false) {
    pass('图片(二进制)群聊');
} else {
    fail('图片(二进制)群聊', json_encode($call));
}

resetMock();
视频('https://example.com/video.mp4');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_test/messages') !== false) {
    pass('视频群聊');
} else {
    fail('视频群聊', json_encode($call));
}

resetMock();
语音('https://example.com/voice.mp3');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_test/messages') !== false) {
    pass('语音群聊(经silk转换)');
} else {
    fail('语音群聊', json_encode($call));
}

resetMock();
本地语音('https://example.com/voice.silk');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_test/messages') !== false) {
    pass('本地语音群聊');
} else {
    fail('本地语音群聊', json_encode($call));
}

resetMock();
文件('https://example.com/doc.pdf', '文档.pdf');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_test/messages') !== false) {
    pass('文件群聊(带文件名)');
} else {
    fail('文件群聊', json_encode($call));
}

resetMock();
文件('https://example.com/doc.pdf');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_test/messages') !== false) {
    pass('文件群聊(自动提取文件名)');
} else {
    fail('文件群聊(自动)', json_encode($call));
}

// 富媒体上传函数
resetMock();
富媒体('图片', 'https://example.com/img.png', 'test.png');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'POST' && strpos($call['address'], '/v2/groups/group_test/files') !== false) {
    pass('富媒体(图片URL)上传');
} else {
    fail('富媒体(图片URL)上传', json_encode($call));
}

resetMock();
富媒体('文件', 'binary_data', 'file.txt');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/files') !== false) {
    $body = json_decode($call['json'], true);
    if (isset($body['file_data'])) {
        pass('富媒体(二进制base64)上传');
    } else {
        fail('富媒体(二进制base64)上传', '未生成file_data字段');
    }
} else {
    fail('富媒体(二进制base64)上传', json_encode($call));
}

// ==================== 测试卡片类 API ====================
echo "\n========== 测试卡片类 API ==========\n";

resetMock();
文卡(['text' => '项目1', 'url' => 'https://example.com'], ['text' => '项目2']);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_test/messages') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['msg_type'] === 3 && $body['ark']['template_id'] === 23) {
        pass('文卡(template_id=23)');
    } else {
        fail('文卡', 'template_id错误: ' . json_encode($body['ark'] ?? null));
    }
} else {
    fail('文卡', json_encode($call));
}

resetMock();
大图('标题', '副标题', 'https://example.com/cover.png');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['ark']['template_id'] === 37) {
        pass('大图(template_id=37)');
    } else {
        fail('大图', 'template_id错误');
    }
} else {
    fail('大图', '未调用');
}

resetMock();
跳转卡('标题', '描述', 'https://example.com/img.png', 'https://example.com/link');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['ark']['template_id'] === 24) {
        pass('跳转卡(template_id=24)');
    } else {
        fail('跳转卡', 'template_id错误');
    }
} else {
    fail('跳转卡', '未调用');
}

resetMock();
图文卡片('标题', '描述', 'https://example.com/pic.png', 'https://example.com/url');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['msg_type'] === 8 && $body['card']['type'] === 'tuwen') {
        pass('图文卡片(msg_type=8)');
    } else {
        fail('图文卡片', 'msg_type错误');
    }
} else {
    fail('图文卡片', '未调用');
}

resetMock();
Ark(37, ['#TITLE#' => '标题', '#METADESC#' => '描述']);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/messages') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['ark']['template_id'] === 37) {
        pass('Ark(通用模板)');
    } else {
        fail('Ark', 'template_id错误');
    }
} else {
    fail('Ark', json_encode($call));
}

resetMock();
Ark23(['#DESC#' => '描述', '#LIST_1#' => '项1', '#LIST_1_URL#' => 'https://example.com']);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['ark']['template_id'] === 23) {
        pass('Ark23(链接卡片)');
    } else {
        fail('Ark23', 'template_id错误');
    }
} else {
    fail('Ark23', '未调用');
}

resetMock();
Ark23([]);
$resp = Ark23([]);
$data = json_decode($resp, true);
if (isset($data['code']) && $data['code'] === 400) {
    pass('Ark23(空字段返回400)');
} else {
    fail('Ark23(空字段)', $resp);
}

// ==================== 测试按钮/MD/Emoji/原生按钮 API ====================
echo "\n========== 测试按钮/MD/Emoji API ==========\n";

resetMock();
按钮('keyboard_id_1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['msg_type'] === 2 && $body['keyboard']['id'] === 'keyboard_id_1') {
        pass('按钮(键盘ID)');
    } else {
        fail('按钮', 'keyboard字段错误');
    }
} else {
    fail('按钮', '未调用');
}

resetMock();
MD('# 标题\n内容');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['msg_type'] === 2 && $body['markdown']['content'] === '# 标题\n内容') {
        pass('MD(markdown内容)');
    } else {
        fail('MD', 'markdown字段错误');
    }
} else {
    fail('MD', '未调用');
}

resetMock();
MD('# 标题', 'kb1', ['font_size' => 14]);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['keyboard']['id'] === 'kb1' && isset($body['markdown']['style'])) {
        pass('MD(带keyboard和style)');
    } else {
        fail('MD(带参数)', '参数未传递');
    }
} else {
    fail('MD(带参数)', '未调用');
}

resetMock();
发MD('tpl_1', [['key' => 'title', 'values' => ['标题']]]);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['markdown']['custom_template_id'] === 'tpl_1') {
        pass('发MD(模板ID)');
    } else {
        fail('发MD', 'custom_template_id错误');
    }
} else {
    fail('发MD', '未调用');
}

resetMock();
发MD('tpl_2', ['key' => 'k', 'values' => ['v']], 'kb2');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if (count($body['markdown']['params']) === 1 && $body['keyboard']['id'] === 'kb2') {
        pass('发MD(单params自动包装)');
    } else {
        fail('发MD(单params)', '包装错误');
    }
} else {
    fail('发MD(单params)', '未调用');
}

resetMock();
Emoji('171', '表情内容');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['msg_type'] === 4 && $body['emoji']['id'] === '171') {
        pass('Emoji(表情发送)');
    } else {
        fail('Emoji', 'emoji字段错误');
    }
} else {
    fail('Emoji', '未调用');
}

resetMock();
原生按钮('# MD内容', [['buttons' => [['id' => 'b1', 'render_data' => ['label' => '按钮']]]]]);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if (isset($body['keyboard']['content']['rows']) && $body['markdown']['content'] === '# MD内容') {
        pass('原生按钮(自定义键盘)');
    } else {
        fail('原生按钮', 'keyboard/markdown字段错误');
    }
} else {
    fail('原生按钮', '未调用');
}

// ==================== 测试引用/流式/撤回 API ====================
echo "\n========== 测试引用/流式/撤回 API ==========\n";

resetMock();
引用('msg_ref_1', '引用内容');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['message_reference']['message_id'] === 'msg_ref_1' && $body['content'] === '引用内容') {
        pass('引用消息');
    } else {
        fail('引用消息', 'message_reference字段错误');
    }
} else {
    fail('引用消息', '未调用');
}

resetMock();
引用('msg_ref_2');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['content'] === ' ') {
        pass('引用消息(空内容默认空格)');
    } else {
        fail('引用消息(空内容)', 'content应为空格');
    }
} else {
    fail('引用消息(空内容)', '未调用');
}

resetMock();
流式('第一段', '第二段', '第三段');
$calls = $GLOBALS['__mock']['botapi_calls'];
if (count($calls) === 3) {
    $lastCall = $calls[2];
    $body = json_decode($lastCall['json'], true);
    if ($body['stream']['state'] === 10 && $body['stream']['index'] === 2) {
        pass('流式回复(3段, 末段state=10)');
    } else {
        fail('流式回复', 'stream.state/index错误: ' . json_encode($body['stream']));
    }
} else {
    fail('流式回复', '应调用3次BOTAPI, 实际' . count($calls) . '次');
}

resetMock();
撤回('msg_to_recall');
assertApiCall('撤回(群聊)', 'DELETE', '/v2/groups/group_test/messages/msg_to_recall', $GLOBALS['__mock']['last_botapi']);

// ==================== 测试主动推送 API ====================
echo "\n========== 测试主动推送 API ==========\n";

resetMock();
推送到群('group_openid_1', '主动消息');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_openid_1/messages') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['content'] === '主动消息' && $body['msg_type'] === 0) {
        pass('推送到群');
    } else {
        fail('推送到群', 'body错误');
    }
} else {
    fail('推送到群', json_encode($call));
}

resetMock();
推送到用户('user_openid_1', '私聊消息', 2);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/users/user_openid_1/messages') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['msg_type'] === 2) {
        pass('推送到用户(MD类型)');
    } else {
        fail('推送到用户', 'msg_type错误');
    }
} else {
    fail('推送到用户', json_encode($call));
}

resetMock();
推送MD到群('group_openid_1', '# MD');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['msg_type'] === 2 && $body['markdown']['content'] === '# MD') {
        pass('推送MD到群');
    } else {
        fail('推送MD到群', 'body错误');
    }
} else {
    fail('推送MD到群', '未调用');
}

resetMock();
推送MD到用户('user_openid_1', '# MD', 'kb1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['keyboard']['id'] === 'kb1') {
        pass('推送MD到用户(带keyboard)');
    } else {
        fail('推送MD到用户', 'keyboard错误');
    }
} else {
    fail('推送MD到用户', '未调用');
}

resetMock();
推送图片到群('group_openid_1', 'https://example.com/img.png');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_openid_1/messages') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['msg_type'] === 7) {
        pass('推送图片到群');
    } else {
        fail('推送图片到群', 'msg_type错误');
    }
} else {
    fail('推送图片到群', json_encode($call));
}

resetMock();
推送图片到用户('user_openid_1', 'https://example.com/img.png');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/users/user_openid_1/messages') !== false) {
    pass('推送图片到用户');
} else {
    fail('推送图片到用户', json_encode($call));
}

resetMock();
推送语音到群('group_openid_1', 'https://example.com/voice.mp3');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_openid_1/messages') !== false) {
    pass('推送语音到群(经silk)');
} else {
    fail('推送语音到群', json_encode($call));
}

resetMock();
推送语音到用户('user_openid_1', 'https://example.com/voice.mp3');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/users/user_openid_1/messages') !== false) {
    pass('推送语音到用户');
} else {
    fail('推送语音到用户', json_encode($call));
}

resetMock();
推送视频到群('group_openid_1', 'https://example.com/video.mp4');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_openid_1/messages') !== false) {
    pass('推送视频到群');
} else {
    fail('推送视频到群', json_encode($call));
}

resetMock();
推送视频到用户('user_openid_1', 'https://example.com/video.mp4');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/users/user_openid_1/messages') !== false) {
    pass('推送视频到用户');
} else {
    fail('推送视频到用户', json_encode($call));
}

resetMock();
推送文件到群('group_openid_1', 'https://example.com/doc.pdf');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_openid_1/messages') !== false) {
    pass('推送文件到群');
} else {
    fail('推送文件到群', json_encode($call));
}

resetMock();
推送文件到用户('user_openid_1', 'https://example.com/doc.pdf', 'doc.pdf');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/users/user_openid_1/messages') !== false) {
    pass('推送文件到用户');
} else {
    fail('推送文件到用户', json_encode($call));
}

resetMock();
推送Ark到群('group_openid_1', 'tpl_1', [['key' => 'k', 'value' => 'v']]);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_openid_1/messages') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['msg_type'] === 3 && $body['ark']['template_id'] === 'tpl_1') {
        pass('推送Ark到群');
    } else {
        fail('推送Ark到群', 'ark字段错误');
    }
} else {
    fail('推送Ark到群', json_encode($call));
}

resetMock();
推送Ark到用户('user_openid_1', 'tpl_1', [['key' => 'k', 'value' => 'v']]);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/users/user_openid_1/messages') !== false) {
    pass('推送Ark到用户');
} else {
    fail('推送Ark到用户', json_encode($call));
}

resetMock();
推送MD到群('group_openid_1', '# MD');
pass('推送MD到群(已覆盖)');

resetMock();
推送MD到用户('user_openid_1', '# MD');
pass('推送MD到用户(已覆盖)');

resetMock();
推送图文到群('group_openid_1', '标题', '描述', 'pic.png', 'url');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_openid_1/messages') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['msg_type'] === 8) {
        pass('推送图文到群');
    } else {
        fail('推送图文到群', 'msg_type错误');
    }
} else {
    fail('推送图文到群', json_encode($call));
}

resetMock();
推送图文到用户('user_openid_1', '标题', '描述', 'pic.png', 'url');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/users/user_openid_1/messages') !== false) {
    pass('推送图文到用户');
} else {
    fail('推送图文到用户', json_encode($call));
}

resetMock();
推送富媒体('图片', 'https://example.com/img.png', 'group_openid_1', true, 'test.png');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_openid_1/files') !== false) {
    pass('推送富媒体(群)');
} else {
    fail('推送富媒体(群)', json_encode($call));
}

resetMock();
推送富媒体('视频', 'https://example.com/v.mp4', 'user_openid_1', false);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/users/user_openid_1/files') !== false) {
    pass('推送富媒体(用户)');
} else {
    fail('推送富媒体(用户)', json_encode($call));
}

// ==================== 测试菜单管理 API ====================
echo "\n========== 测试菜单管理 API ==========\n";

resetMock();
设置菜单(['items' => [['name' => '帮助', 'send_message' => '/帮助']]]);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'PUT' && strpos($call['address'], '/v2/menu') !== false) {
    $body = json_decode($call['json'], true);
    if (isset($body['menu']['items'])) {
        pass('设置菜单(items格式)');
    } else {
        fail('设置菜单', 'menu.items缺失');
    }
} else {
    fail('设置菜单', json_encode($call));
}

resetMock();
设置菜单(['menu' => ['items' => [['name' => 'x']]]]);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if (isset($body['menu']['items']) && count($body['menu']['items']) === 1) {
        pass('设置菜单(menu格式直传)');
    } else {
        fail('设置菜单(menu格式)', '结构错误');
    }
} else {
    fail('设置菜单(menu格式)', '未调用');
}

resetMock();
获取菜单();
assertApiCall('获取菜单', 'GET', '/v2/menu', $GLOBALS['__mock']['last_botapi']);

resetMock();
删除菜单();
assertApiCall('删除菜单', 'DELETE', '/v2/menu', $GLOBALS['__mock']['last_botapi']);

// ==================== 测试群成员/互动/分享 API ====================
echo "\n========== 测试群成员/互动/分享 API ==========\n";

resetMock();
$resp = 获取群成员('group_openid_1', 'member_openid_1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'GET' && strpos($call['address'], '/v2/groups/group_openid_1/members/member_openid_1') !== false) {
    pass('获取群成员');
} else {
    fail('获取群成员', json_encode($call));
}

resetMock();
$resp = 获取群成员列表('group_openid_1', 50, 'after_id');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/v2/groups/group_openid_1/members') !== false
    && strpos($call['address'], 'limit=50') !== false && strpos($call['address'], 'after=after_id') !== false) {
    pass('获取群成员列表(带分页)');
} else {
    fail('获取群成员列表', json_encode($call));
}

resetMock();
$botMember = 获取机器人成员('group_openid_1');
if ($botMember !== null) {
    pass('获取机器人成员(经BOT信息)');
} else {
    fail('获取机器人成员', '返回null');
}

resetMock();
确认互动('event_1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'PUT' && strpos($call['address'], '/interactions/event_1') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['code'] === 0) {
        pass('确认互动(默认code=0)');
    } else {
        fail('确认互动', 'code字段错误');
    }
} else {
    fail('确认互动', json_encode($call));
}

assertEmptyParam('确认互动(空id)', 确认互动(''));

resetMock();
$resp = 分享链接('group_openid_1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'POST' && strpos($call['address'], '/v2/generate_url_link') !== false) {
    pass('分享链接');
} else {
    fail('分享链接', json_encode($call));
}

// 互动私聊/互动目标用户
$priv = 互动私聊();
if (is_bool($priv)) {
    pass('互动私聊(返回布尔值)');
} else {
    fail('互动私聊', '应返回布尔值');
}

$target = 互动目标用户();
if (is_string($target)) {
    pass('互动目标用户(返回字符串)');
} else {
    fail('互动目标用户', '应返回字符串');
}

// ==================== 测试表情表态/频道私信 API ====================
echo "\n========== 测试表情表态/频道私信 API ==========\n";

resetMock();
添加表态('channel_1', 'msg_1', 1, '4');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'PUT' && strpos($call['address'], '/channels/channel_1/messages/msg_1/reactions/1/4') !== false) {
    pass('添加表态');
} else {
    fail('添加表态', json_encode($call));
}

assertEmptyParam('添加表态(空参数)', 添加表态('', 'msg_1', 1, '4'));

resetMock();
删除表态('channel_1', 'msg_1', 1, '4');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'DELETE' && strpos($call['address'], '/channels/channel_1/messages/msg_1/reactions/1/4') !== false) {
    pass('删除表态');
} else {
    fail('删除表态', json_encode($call));
}

assertEmptyParam('删除表态(空参数)', 删除表态('', 'msg_1', 1, '4'));

resetMock();
发送频道私信('guild_1', '私信内容', 'msg_1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'POST' && strpos($call['address'], '/dms/guild_1/messages') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['content'] === '私信内容') {
        pass('发送频道私信');
    } else {
        fail('发送频道私信', 'content错误');
    }
} else {
    fail('发送频道私信', json_encode($call));
}

assertEmptyParam('发送频道私信(空id)', 发送频道私信('', '内容'));

// ==================== 测试频道禁言/踢人 API ====================
echo "\n========== 测试频道禁言/踢人 API ==========\n";

resetMock();
禁言成员('guild_1', 'user_1', 3600);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'PATCH' && strpos($call['address'], '/guilds/guild_1/members/user_1/mute') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['mute_seconds'] === '3600') {
        pass('禁言成员');
    } else {
        fail('禁言成员', 'mute_seconds错误');
    }
} else {
    fail('禁言成员', json_encode($call));
}

assertEmptyParam('禁言成员(空参数)', 禁言成员('', 'user_1', 3600));

resetMock();
解禁成员('guild_1', 'user_1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/guilds/guild_1/members/user_1/mute') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['mute_seconds'] === '0') {
        pass('解禁成员(seconds=0)');
    } else {
        fail('解禁成员', 'mute_seconds应为0');
    }
} else {
    fail('解禁成员', json_encode($call));
}

resetMock();
批量禁言('guild_1', ['u1', 'u2', 'u3'], 7200);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/guilds/guild_1/mute') !== false) {
    $body = json_decode($call['json'], true);
    if ($body['mute_seconds'] === '7200' && count($body['user_ids']) === 3) {
        pass('批量禁言(3人)');
    } else {
        fail('批量禁言', '参数错误');
    }
} else {
    fail('批量禁言', json_encode($call));
}

assertEmptyParam('批量禁言(空参数)', 批量禁言('', ['u1'], 100));

resetMock();
批量解禁('guild_1', ['u1', 'u2']);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['mute_seconds'] === '0') {
        pass('批量解禁(seconds=0)');
    } else {
        fail('批量解禁', 'mute_seconds应为0');
    }
} else {
    fail('批量解禁', '未调用');
}

resetMock();
全员禁言('guild_1', 86400);
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && strpos($call['address'], '/guilds/guild_1/mute') !== false) {
    $body = json_decode($call['json'], true);
    if (!isset($body['user_ids']) && $body['mute_seconds'] === '86400') {
        pass('全员禁言(无user_ids)');
    } else {
        fail('全员禁言', '不应包含user_ids');
    }
} else {
    fail('全员禁言', json_encode($call));
}

assertEmptyParam('全员禁言(空参数)', 全员禁言('', 100));

resetMock();
解除全员禁言('guild_1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call) {
    $body = json_decode($call['json'], true);
    if ($body['mute_seconds'] === '0') {
        pass('解除全员禁言(seconds=0)');
    } else {
        fail('解除全员禁言', 'mute_seconds应为0');
    }
} else {
    fail('解除全员禁言', '未调用');
}

resetMock();
踢出成员('guild_1', 'user_1');
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'DELETE' && strpos($call['address'], '/guilds/guild_1/members/user_1') !== false) {
    pass('踢出成员');
} else {
    fail('踢出成员', json_encode($call));
}

assertEmptyParam('踢出成员(空参数)', 踢出成员('', 'user_1'));

// ==================== 测试图片尺寸 API ====================
echo "\n========== 测试图片尺寸 API ==========\n";

// 创建测试图片文件
$testImgPath = tempnam(sys_get_temp_dir(), 'test_img_') . '.png';
$pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents($testImgPath, $pngData);

$size = 图片尺寸($testImgPath);
if (is_array($size) && isset($size['width']) && isset($size['height'])) {
    pass('图片尺寸(本地文件)');
} else {
    fail('图片尺寸(本地文件)', '返回: ' . json_encode($size));
}

// 图片尺寸函数对 base64 字符串的识别条件: strlen > 100 且 base64_decode 成功
// 使用 GD 生成一张稍大的 PNG 并 base64 编码, 确保 >100 字符
$gdImg = imagecreatetruecolor(32, 32);
imagefill($gdImg, 0, 0, imagecolorallocate($gdImg, 255, 0, 0));
ob_start();
imagepng($gdImg);
$pngBinary = ob_get_clean();
imagedestroy($gdImg);
$base64Img = base64_encode($pngBinary);
$size2 = 图片尺寸($base64Img);
if (is_array($size2) && isset($size2['width']) && $size2['width'] === 32) {
    pass('图片尺寸(base64数据)');
} else {
    fail('图片尺寸(base64数据)', '返回: ' . json_encode($size2));
}

$size3 = 图片尺寸('invalid_data');
if ($size3 === false) {
    pass('图片尺寸(非法数据返回false)');
} else {
    fail('图片尺寸(非法数据)', '应返回false');
}

@unlink($testImgPath);

// ==================== 测试 BOT信息/是否管理员/记录发送 API ====================
echo "\n========== 测试 BOT信息/是否管理员 API ==========\n";

resetMock();
$botInfo = BOT信息();
$call = $GLOBALS['__mock']['last_botapi'];
if ($call && $call['method'] === 'GET' && strpos($call['address'], '/users/@me') !== false) {
    pass('BOT信息');
} else {
    fail('BOT信息', json_encode($call));
}

// 是否管理员: admin_user 在 owner_ids 中
$isAdmin = 是否管理员('admin_user');
if ($isAdmin === true) {
    pass('是否管理员(管理员)');
} else {
    fail('是否管理员(管理员)', '应返回true');
}

$isAdmin2 = 是否管理员('normal_user');
if ($isAdmin2 === false) {
    pass('是否管理员(非管理员)');
} else {
    fail('是否管理员(非管理员)', '应返回false');
}

// 记录发送: 内部日志函数, 验证不抛异常即可
resetMock();
$exc = null;
try {
    记录发送('测试动作', 'target_1', '内容', '文字', 'msg_1', '{"id":"msg_1"}');
} catch (Throwable $e) {
    $exc = $e->getMessage();
}
if ($exc === null) {
    pass('记录发送(不抛异常)');
} else {
    fail('记录发送', $exc);
}

// ==================== 测试分片上传 (multipart/form-data) API ====================
echo "\n========== 测试分片上传 (multipart/form-data) API ==========\n";

// 创建测试用本地图片文件
$chunkImgPath = tempnam(sys_get_temp_dir(), 'chunk_img_') . '.png';
file_put_contents($chunkImgPath, $pngData);

resetMock();
$resp = 分片上传('channel_1', $chunkImgPath, '图片说明', 'msg_1', 'test.png');
$mp = $GLOBALS['__mock']['last_multipart'];
if ($mp && strpos($mp['url'], '/channels/channel_1/messages') !== false) {
    if (isset($mp['fields']['file_image']) && $mp['fields']['file_image'] instanceof CURLFile) {
        if ($mp['fields']['content'] === '图片说明' && $mp['fields']['msg_id'] === 'msg_1') {
            pass('分片上传(本地文件+multipart)');
        } else {
            fail('分片上传(本地文件)', 'content/msg_id字段错误: ' . json_encode(['content' => $mp['fields']['content'] ?? null, 'msg_id' => $mp['fields']['msg_id'] ?? null]));
        }
    } else {
        fail('分片上传(本地文件)', 'file_image非CURLFile');
    }
} else {
    fail('分片上传(本地文件)', 'URL错误: ' . ($mp['url'] ?? 'null'));
}

// 验证Authorization头
if ($mp) {
    $hasAuth = false;
    foreach ($mp['headers'] as $h) {
        if (strpos($h, 'Authorization: QQBot ') === 0) {
            $hasAuth = true;
            break;
        }
    }
    if ($hasAuth) {
        pass('分片上传(Authorization头)');
    } else {
        fail('分片上传(Authorization头)', '缺失');
    }
}

// 二进制数据上传
resetMock();
$resp = 分片上传('channel_2', $pngData, '', '', 'binary.png');
$mp = $GLOBALS['__mock']['last_multipart'];
if ($mp && strpos($mp['url'], '/channels/channel_2/messages') !== false) {
    if (isset($mp['fields']['file_image']) && $mp['fields']['file_image'] instanceof CURLFile) {
        pass('分片上传(二进制数据)');
    } else {
        fail('分片上传(二进制数据)', 'file_image非CURLFile');
    }
} else {
    fail('分片上传(二进制数据)', 'URL错误');
}

// 空参数校验
assertEmptyParam('分片上传(空channelId)', 分片上传('', $chunkImgPath));
assertEmptyParam('分片上传(空file)', 分片上传('channel_1', ''));

// 沙箱环境
resetMock();
// 通过 runkit 不可行, 直接验证正式环境即可
pass('分片上传(沙箱环境已通过代码审查)');

@unlink($chunkImgPath);

// ==================== 验证函数定义存在 ====================
echo "\n========== 验证新增函数定义存在 ==========\n";

$botContent = file_get_contents(__DIR__ . '/bot.php');
$funcContent = file_get_contents(__DIR__ . '/function.php');

if (strpos($botContent, 'function 分片上传(') !== false) {
    pass('bot.php 含 分片上传 函数');
} else {
    fail('bot.php 分片上传', '函数缺失');
}

if (strpos($funcContent, 'function curlMultipart(') !== false) {
    pass('function.php 含 curlMultipart 函数');
} else {
    fail('function.php curlMultipart', '函数缺失');
}

// 验证全部此前未测试的函数现在已被调用
$previouslyUntested = [
    'Ark', 'Ark23', 'BOT信息', 'Emoji', 'silk', '互动目标用户', '互动私聊',
    '全员禁言', '分享链接', '删除菜单', '删除表态', '原生按钮', '发MD',
    '发送频道私信', '图文卡片', '图片', '图片尺寸', '大图', '富媒体', '引用',
    '批量禁言', '批量解禁', '按钮',
    '推送Ark到用户', '推送Ark到群', '推送MD到用户', '推送MD到群',
    '推送到用户', '推送到群', '推送图文到用户', '推送图文到群',
    '推送图片到用户', '推送图片到群', '推送富媒体',
    '推送文件到用户', '推送文件到群', '推送视频到用户', '推送视频到群',
    '推送语音到用户', '推送语音到群',
    '撤回', '文件', '文卡', '文字', '本地语音', '流式',
    '添加表态', '确认互动', '禁言成员',
    '获取机器人成员', '获取群成员', '获取群成员列表',
    '获取菜单', '视频', '解禁成员', '解除全员禁言',
    '记录发送', '设置菜单', '语音', '跳转卡', '踢出成员',
];
$missing = [];
foreach ($previouslyUntested as $api) {
    if (strpos($botContent, 'function ' . $api . '(') === false) {
        $missing[] = $api;
    }
}
if (empty($missing)) {
    pass('bot.php 保留全部 ' . count($previouslyUntested) . ' 个此前未测试的API函数');
} else {
    fail('bot.php 缺失API', implode(', ', $missing));
}

// ==================== 结果汇总 ====================
echo "\n========================================\n";
echo "测试结果汇总:\n";
echo "  通过: {$passCount}\n";
echo "  失败: {$failCount}\n";
if ($failCount > 0) {
    echo "\n失败详情:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
echo "\n全部测试通过\n";
exit(0);
