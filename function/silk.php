<?php
/**
 * 语音转 Silk —— 多接口适配
 *
 * 支持四种模式（后台「系统设置 → 语音转 Silk」切换）：
 *   oiapi     oiapi.net 的 Mp32Silk，返回 JSON 里的音频 URL（历史默认）
 *   workbuddy 直接返回 SILK 二进制，参数可配（采样率 / 帧长 / 复杂度 / 腾讯模式 / 访问码）
 *   custom    后台自定义：URL 模板 + 请求方式 + 请求头 + Body + 解析方式
 *   off       关闭转换，原样返回（音源本身已是 Silk 时使用）
 *
 * 约定：silk转换() 返回「音频 URL 字符串」或「SILK 二进制」；失败返回空串。
 * 上层 富媒体() 会自动识别 http 前缀走 URL 上传，否则按 file_data 走 base64 上传。
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__) . '/');
}

function silk设置($key, $default = '') {
    try {
        $v = db()->getSetting($key, $default);
        return $v === null ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}

function silk是二进制($data) {
    return is_string($data) && $data !== '' && strpos(substr($data, 0, 16), '#!SILK') !== false;
}

function silkProvider列表() {
    return [
        'oiapi'     => 'oiapi（JSON 返回音频 URL）',
        'workbuddy' => 'WorkBuddy（直接返回 SILK 二进制）',
        'custom'    => '自定义 API',
        'off'       => '不转换（音源已是 Silk）',
    ];
}

/**
 * Silk 转换专用 HTTP 请求（独立于全局 curl()，超时可配、支持自定义请求头）
 * @return string 响应体；失败返回空串
 */
function silk_http($url, $method = 'GET', $headers = [], $body = '', $timeout = 60) {
    $method = strtoupper($method);
    $hdrs = is_array($headers) ? array_values(array_filter($headers, 'strlen')) : [];
    if (!$hdrs) $hdrs = ['Content-Type: application/json'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, max(5, (int)$timeout));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    curl_setopt($ch, CURLOPT_USERAGENT, 'QQbot-Silk/1.0');

    // 与全局 curl() 保持一致的证书策略
    $insecure = false;
    try { $insecure = db()->getSetting('ssl_insecure', '0') === '1'; } catch (Throwable $e) {}
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$insecure);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $insecure ? 0 : 2);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // 诊断信息供后台「测试」按钮与单元测试使用（headers/body 仅在内部保留）
    $GLOBALS['__silk_http_last'] = [
        'url'     => $url,
        'method'  => $method,
        'headers' => $hdrs,
        'body'    => $body,
        'code'    => $code,
        'error'   => $err,
        'bytes'   => is_string($resp) ? strlen($resp) : 0,
    ];

    return $resp === false ? '' : $resp;
}

/** oiapi：{code:1, data:{url:"..."}}，地址可换成镜像 */
function silk_oiapi($link) {
    $base = trim(silk设置('silk_oiapi_base', 'https://oiapi.net/api/Mp32Silk'));
    if ($base === '') return '';
    $url = $base . (strpos($base, '?') !== false ? '&' : '?') . 'url=' . urlencode($link);
    $r = json_decode(silk_http($url, 'GET', [], '', 60), true);
    if (!is_array($r) || (int)($r['code'] ?? 0) !== 1) return '';
    $u = $r['data']['url'] ?? '';
    return is_string($u) ? $u : '';
}

/** WorkBuddy：成功直接返回 #!SILK_V3 二进制；失败返回 {ok:false,error:{...}} */
function silk_workbuddy($link) {
    $base = trim(silk设置('silk_wb_base', 'https://a9601a654d5c4fdb1.app.workbuddy.host/api/v1/convert/url'));
    if ($base === '') return '';

    $q = [
        'url'         => $link,
        'sample_rate' => silk设置('silk_wb_sample_rate', '24000'),
        'tencent'     => silk设置('silk_wb_tencent', '1') === '1' ? 'true' : 'false',
        'frame_ms'    => silk设置('silk_wb_frame_ms', '20'),
        'complexity'  => silk设置('silk_wb_complexity', '1'),
    ];
    $sc = trim(silk设置('silk_wb_sharecode', ''));
    if ($sc !== '') $q['sharecode'] = $sc;

    $url = $base . (strpos($base, '?') !== false ? '&' : '?') . http_build_query($q);
    $resp = silk_http($url, 'GET', [], '', (int)silk设置('silk_wb_timeout', '90'));

    if (silk是二进制($resp)) return $resp;

    // 兼容返回 JSON 里带 URL 的部署
    $j = json_decode($resp, true);
    if (is_array($j)) {
        $u = $j['data']['url'] ?? ($j['url'] ?? ($j['audio'] ?? ''));
        if (is_string($u) && preg_match('/^https?:\/\//i', $u)) return $u;
    }
    return '';
}

/** 按 a.b.0.c 取嵌套字段 */
function silk_pick($data, $path) {
    $path = trim((string)$path);
    if ($path === '') return null;
    $cur = $data;
    foreach (explode('.', $path) as $seg) {
        if (is_array($cur) && array_key_exists($seg, $cur)) {
            $cur = $cur[$seg];
        } elseif (is_object($cur) && isset($cur->$seg)) {
            $cur = $cur->$seg;
        } else {
            return null;
        }
    }
    return $cur;
}

/** 自定义 API：{url} 为 urlencode 后的地址，{url_raw} 为原始地址 */
function silk_custom($link) {
    $tpl = trim(silk设置('silk_custom_url', ''));
    if ($tpl === '') return '';

    $repl = ['{url_raw}' => $link, '{url}' => urlencode($link)];
    $url  = str_replace(array_keys($repl), array_values($repl), $tpl);
    if (!preg_match('/^https?:\/\//i', $url)) return '';

    $headers = [];
    $rawH = silk设置('silk_custom_headers', '');
    if ($rawH !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $rawH) as $line) {
            $line = trim($line);
            if ($line !== '' && strpos($line, ':') !== false) $headers[] = $line;
        }
    }

    $body = '';
    $bt = silk设置('silk_custom_body', '');
    if ($bt !== '') $body = str_replace(array_keys($repl), array_values($repl), $bt);

    $method = strtoupper(silk设置('silk_custom_method', 'GET'));
    // POST 且带 Body 时，若用户没写 Content-Type 则补一个默认值
    if ($method === 'POST' && $body !== '') {
        $hasCT = false;
        foreach ($headers as $h) {
            if (stripos($h, 'content-type:') === 0) { $hasCT = true; break; }
        }
        if (!$hasCT) $headers[] = 'Content-Type: application/json';
    }

    $resp = silk_http($url, $method, $headers, $body,
                      (int)silk设置('silk_custom_timeout', '60'));

    if (silk是二进制($resp)) return $resp;
    if (silk设置('silk_custom_parse', 'json') === 'binary') return '';

    $j = json_decode($resp, true);
    if (!is_array($j)) return '';
    $u = silk_pick($j, silk设置('silk_custom_field', 'data.url'));
    return (is_string($u) && $u !== '') ? $u : '';
}

/** 按后台配置分发 */
function silk转换($link) {
    switch (silk设置('silk_provider', 'oiapi')) {
        case 'off':       return $link;
        case 'workbuddy': return silk_workbuddy($link);
        case 'custom':    return silk_custom($link);
        case 'oiapi':
        default:          return silk_oiapi($link);
    }
}

/** 后台「测试」按钮：跑一次转换并返回诊断信息 */
function silk测试($link) {
    $GLOBALS['__silk_http_last'] = null;
    $t0  = microtime(true);
    $out = silk转换($link);
    $ms  = (int)round((microtime(true) - $t0) * 1000);

    $isBin = silk是二进制($out);

    // 不把请求头与 Body 回传给前端（可能含密钥）
    $http = $GLOBALS['__silk_http_last'];
    if (is_array($http)) {
        unset($http['headers'], $http['body']);
    }

    return [
        'provider'   => silk设置('silk_provider', 'oiapi'),
        'elapsed_ms' => $ms,
        'type'       => $out === '' ? 'empty' : ($isBin ? 'silk' : 'url'),
        'bytes'      => strlen($out),
        'url'        => $isBin ? '' : $out,
        'http'       => $http,
    ];
}
