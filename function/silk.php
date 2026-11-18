<?php
/**
 * 语音转 SILK 转码服务（可切换接口）
 *
 * 背景：QQ 机器人语音消息只接受 SILK 格式，框架原先写死调用 oiapi.net 的
 *       Mp32Silk 接口，该接口 2026-11 起已返回 404「接口不存在」，导致语音全部发不出。
 *       本文件把转码抽成可配置的多接口链路，后台「系统设置 → 语音转 Silk」可切换。
 *
 * 支持的接口类型：
 *   workbuddy  自建 SilkV3 服务（GET /api/v1/convert/url?url=...，直接返回 SILK 二进制）
 *   oiapi      旧公共接口（返回 JSON，message 字段是 SILK 链接；当前已失效，保留兼容）
 *   custom     自定义模板，支持占位符：{url} {urlenc} {sharecode} {sample_rate}
 *              {frame_ms} {complexity} {tencent}
 *
 * 响应会自动识别：SILK 二进制 / JSON 中的音频链接 / base64 编码的 SILK。
 */

if (!function_exists('silkDefaultConfig')) {
    /**
     * 默认配置：默认指向自建 SilkV3 服务（开箱可用）
     */
    function silkDefaultConfig() {
        return [
            'provider'    => 'workbuddy',
            'base_url'    => 'https://a9601a654d5c4fdb1.app.workbuddy.host',
            'sharecode'   => 'YNB7MWHTCB',
            'sample_rate' => 24000,
            'frame_ms'    => 20,
            'complexity'  => 1,
            'tencent'     => 1,
            'custom_url'  => '',
            'backup_url'  => '',
            'fallback'    => 1,
            'timeout'     => 40,
        ];
    }
}

if (!function_exists('silkProviders')) {
    /**
     * 内置服务商清单（供后台下拉与说明使用）
     */
    function silkProviders() {
        return [
            'workbuddy' => [
                'name' => '自建 SilkV3 服务',
                'desc' => '直接返回 SILK 二进制，支持采样率/帧长/复杂度调节，推荐',
                'alive' => true,
            ],
            'oiapi' => [
                'name' => 'oiapi 公共接口',
                'desc' => '旧默认接口，2026-11 起返回 404「接口不存在」，仅作兼容保留',
                'alive' => false,
            ],
            'custom' => [
                'name' => '自定义接口',
                'desc' => '用 URL 模板接入任意转码服务，支持 {urlenc} 等占位符',
                'alive' => true,
            ],
        ];
    }
}

if (!function_exists('silkNormalizeConfig')) {
    /**
     * 配置清洗：所有值都做类型/范围约束，避免脏配置把语音链路带崩
     *
     * @param array $raw
     * @return array
     */
    function silkNormalizeConfig($raw) {
        $def = silkDefaultConfig();
        if (!is_array($raw)) $raw = [];
        $cfg = array_merge($def, array_intersect_key($raw, $def));

        $providers = ['workbuddy', 'oiapi', 'custom'];
        if (!in_array($cfg['provider'], $providers, true)) $cfg['provider'] = $def['provider'];

        $cfg['base_url']  = trim((string)$cfg['base_url']);
        if ($cfg['base_url'] === '' || !preg_match('#^https?://#i', $cfg['base_url'])) {
            $cfg['base_url'] = $def['base_url'];
        }
        $cfg['base_url'] = rtrim($cfg['base_url'], '/');

        $cfg['sharecode'] = trim((string)$cfg['sharecode']);

        $sr = (int)$cfg['sample_rate'];
        $cfg['sample_rate'] = in_array($sr, [8000, 16000, 24000], true) ? $sr : $def['sample_rate'];

        $fm = (int)$cfg['frame_ms'];
        $cfg['frame_ms'] = in_array($fm, [20, 40, 60], true) ? $fm : $def['frame_ms'];

        $cx = (int)$cfg['complexity'];
        $cfg['complexity'] = ($cx >= 0 && $cx <= 2) ? $cx : $def['complexity'];

        $cfg['tencent']  = !empty($cfg['tencent']) ? 1 : 0;
        $cfg['fallback'] = !empty($cfg['fallback']) ? 1 : 0;

        foreach (['custom_url', 'backup_url'] as $k) {
            $v = trim((string)$cfg[$k]);
            // 模板必须带协议头，且至少含一个音频地址占位符，否则视为未配置
            if ($v !== '' && (!preg_match('#^https?://#i', $v) || !preg_match('#\{(url|urlenc|urlencoded)\}#', $v))) {
                $v = '';
            }
            $cfg[$k] = $v;
        }

        $t = (int)$cfg['timeout'];
        $cfg['timeout'] = ($t >= 10 && $t <= 110) ? $t : $def['timeout'];

        return $cfg;
    }
}

if (!function_exists('silkConfig')) {
    /**
     * 读取当前配置（settings.silk_config），缺省时返回默认值
     *
     * @param array $overrides 临时覆盖（后台测试时用未保存的表单值）
     * @return array
     */
    function silkConfig($overrides = []) {
        $raw = [];
        try {
            if (function_exists('db')) {
                $raw = db()->getSetting('silk_config', []);
            }
        } catch (Throwable $e) {
            $raw = [];
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $cfg = silkNormalizeConfig(is_array($raw) ? $raw : []);
        if (is_array($overrides) && $overrides) {
            $cfg = silkNormalizeConfig(array_merge($cfg, $overrides));
        }
        return $cfg;
    }
}

if (!function_exists('silkBuildUrl')) {
    /**
     * 按接口类型拼出请求地址
     *
     * @param array  $endpoint ['type' => workbuddy|oiapi|custom, 'url' => string]
     * @param string $audioUrl
     * @param array  $cfg
     * @return string
     */
    function silkBuildUrl($endpoint, $audioUrl, $cfg) {
        $type = $endpoint['type'] ?? 'custom';
        $base = $endpoint['url'] ?? '';

        if ($type === 'oiapi') {
            return rtrim($base !== '' ? $base : 'https://oiapi.net/API/Mp32Silk', '/')
                 . '?url=' . rawurlencode($audioUrl);
        }

        if ($type === 'workbuddy') {
            $query = [
                'url'         => $audioUrl,
                'sample_rate' => $cfg['sample_rate'],
                'tencent'     => $cfg['tencent'] ? 'true' : 'false',
                'frame_ms'    => $cfg['frame_ms'],
                'complexity'  => $cfg['complexity'],
            ];
            if ($cfg['sharecode'] !== '') $query['sharecode'] = $cfg['sharecode'];
            return rtrim($base, '/') . '/api/v1/convert/url?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        // custom：模板替换
        $map = [
            '{url}'         => $audioUrl,
            '{urlenc}'      => rawurlencode($audioUrl),
            '{urlencoded}'  => rawurlencode($audioUrl),
            '{sharecode}'   => rawurlencode($cfg['sharecode']),
            '{sample_rate}' => (string)$cfg['sample_rate'],
            '{frame_ms}'    => (string)$cfg['frame_ms'],
            '{complexity}'  => (string)$cfg['complexity'],
            '{tencent}'     => $cfg['tencent'] ? 'true' : 'false',
        ];
        return strtr($base, $map);
    }
}

if (!function_exists('silkIsSilk')) {
    /**
     * 判断二进制是否为 SILK（V3 头为 \x02#!SILK_V3，老版本为 #!SILK）
     */
    function silkIsSilk($body) {
        if (!is_string($body) || strlen($body) < 10) return false;
        return strpos(substr($body, 0, 16), '#!SILK') !== false;
    }
}

if (!function_exists('silkHttpGet')) {
    /**
     * 带独立超时的 GET（不复用 curl()，因为后者固定 30s，长音频转码可能不够）
     *
     * @return array ['ok'=>bool,'body'=>string,'code'=>int,'ctype'=>string,'error'=>string]
     */
    function silkHttpGet($url, $timeout = 40) {
        $sslInsecure = false;
        try {
            if (function_exists('db')) $sslInsecure = db()->getSetting('ssl_insecure', '0') === '1';
        } catch (Throwable $e) {
            $sslInsecure = false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(5, (int)$timeout),
            CURLOPT_SSL_VERIFYPEER => !$sslInsecure,
            CURLOPT_SSL_VERIFYHOST => $sslInsecure ? 0 : 2,
            CURLOPT_USERAGENT      => 'QQbot-Silk/1.0 (+php)',
            CURLOPT_HTTPHEADER     => ['Accept: */*'],
        ]);
        $body  = curl_exec($ch);
        $errNo = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($errNo !== 0) {
            return ['ok' => false, 'body' => '', 'code' => $code, 'ctype' => $ctype, 'error' => '网络错误: ' . $err];
        }
        if ($code < 200 || $code >= 300) {
            $snippet = '';
            if (is_string($body) && $body !== '') {
                // 优先从 JSON 错误体里抽 message，避免把整段 JSON 糊到后台提示上
                $j = json_decode($body, true);
                if (is_array($j)) {
                    foreach (['message', 'msg', 'detail', 'error'] as $k) {
                        if (!isset($j[$k])) continue;
                        $v = $j[$k];
                        if (is_array($v)) $v = $v['message'] ?? $v['msg'] ?? '';
                        if (is_string($v) && trim($v) !== '') {
                            $snippet = trim($v);
                            break;
                        }
                    }
                }
                if ($snippet === '') {
                    $snippet = mb_substr(preg_replace('#\s+#', ' ', strip_tags($body)), 0, 100);
                }
                $snippet = mb_substr($snippet, 0, 100);
            }
            return ['ok' => false, 'body' => (string)$body, 'code' => $code, 'ctype' => $ctype,
                    'error' => 'HTTP ' . $code . ($snippet !== '' ? '：' . $snippet : '')];
        }
        return ['ok' => true, 'body' => (string)$body, 'code' => $code, 'ctype' => $ctype, 'error' => ''];
    }
}

if (!function_exists('silkParseResponse')) {
    /**
     * 解析转码接口响应，统一成 SILK 二进制或 SILK 链接
     *
     * @return array ['ok'=>bool,'kind'=>'binary'|'url','data'=>string,'error'=>string]
     */
    function silkParseResponse($body, $ctype, $timeout = 30) {
        if (!is_string($body) || $body === '') {
            return ['ok' => false, 'kind' => '', 'data' => '', 'error' => '响应内容为空'];
        }

        // 1) 直接就是 SILK 二进制
        if (silkIsSilk($body)) {
            return ['ok' => true, 'kind' => 'binary', 'data' => $body, 'error' => ''];
        }

        $trim = ltrim($body);

        // 2) JSON：可能是错误信息，也可能是 SILK 链接 / base64
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $json = json_decode($trim, true);
            if (is_array($json)) {
                $code = $json['code'] ?? $json['status'] ?? $json['errcode'] ?? null;
                $okCode = ($code === null)
                       || in_array((string)$code, ['0', '200', '200000', 'success', 'ok'], true);
                $msg = (string)($json['message'] ?? $json['msg'] ?? $json['error'] ?? $json['detail'] ?? '');

                // 候选链接：message / url / silk_url / file_url / data.*
                $candidates = [];
                foreach (['message', 'msg', 'url', 'silk_url', 'file_url'] as $k) {
                    if (isset($json[$k]) && is_string($json[$k]) && preg_match('#^https?://#i', trim($json[$k]))) {
                        $candidates[] = trim($json[$k]);
                    }
                }
                if (isset($json['data'])) {
                    if (is_string($json['data'])) {
                        $s = trim($json['data']);
                        if (preg_match('#^https?://#i', $s)) {
                            $candidates[] = $s;
                        } elseif (strlen($s) > 32) {
                            $dec = base64_decode(preg_replace('#\s+#', '', $s), true);
                            if ($dec !== false && silkIsSilk($dec)) {
                                return ['ok' => true, 'kind' => 'binary', 'data' => $dec, 'error' => ''];
                            }
                        }
                    } elseif (is_array($json['data'])) {
                        foreach (['url', 'silk_url', 'file_url', 'file'] as $k) {
                            if (isset($json['data'][$k]) && is_string($json['data'][$k]) && preg_match('#^https?://#i', trim($json['data'][$k]))) {
                                $candidates[] = trim($json['data'][$k]);
                            }
                        }
                    }
                }

                foreach ($candidates as $url) {
                    $path = (string)parse_url($url, PHP_URL_PATH);
                    if (preg_match('#\.silk$#i', $path)) {
                        return ['ok' => true, 'kind' => 'url', 'data' => $url, 'error' => ''];
                    }
                    // 非 .silk 链接：下载回来验一下，避免把 mp3 当语音传给 QQ
                    $dl = silkHttpGet($url, min(30, max(5, (int)$timeout)));
                    if ($dl['ok'] && silkIsSilk($dl['body'])) {
                        return ['ok' => true, 'kind' => 'binary', 'data' => $dl['body'], 'error' => ''];
                    }
                }

                if (!$okCode) {
                    return ['ok' => false, 'kind' => '', 'data' => '',
                            'error' => '接口返回 code=' . $code . ($msg !== '' ? '：' . $msg : '')];
                }
                return ['ok' => false, 'kind' => '', 'data' => '',
                        'error' => '响应 JSON 中未找到 SILK 或音频链接' . ($msg !== '' ? '（' . $msg . '）' : '')];
            }
        }

        // 3) 纯 base64 文本
        if (preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $trim) && strlen($trim) > 32) {
            $dec = base64_decode(preg_replace('#\s+#', '', $trim), true);
            if ($dec !== false && silkIsSilk($dec)) {
                return ['ok' => true, 'kind' => 'binary', 'data' => $dec, 'error' => ''];
            }
        }

        // 4) 其他内容一律判失败（把头部打出来方便排错）
        return ['ok' => false, 'kind' => '', 'data' => '',
                'error' => '返回内容不是 SILK（' . ($ctype !== '' ? $ctype : '未知类型')
                         . '，前 12 字节: ' . bin2hex(substr($body, 0, 12)) . '）'];
    }
}

if (!function_exists('silkConvert')) {
    /**
     * 执行一次语音转码（主接口失败时按配置尝试备用接口）
     *
     * @param string $audioUrl 音频地址（mp3/m4a/wav/amr 等）
     * @param array|null $cfg  传入则覆盖数据库配置
     * @return array ['ok','kind','data','provider','bytes','ms','error','tries']
     */
    function silkConvert($audioUrl, $cfg = null) {
        $cfg = is_array($cfg) ? silkNormalizeConfig($cfg) : silkConfig();
        $audioUrl = trim((string)$audioUrl);

        $result = ['ok' => false, 'kind' => '', 'data' => '', 'provider' => '',
                   'bytes' => 0, 'ms' => 0, 'error' => '', 'tries' => []];

        if ($audioUrl === '' || !preg_match('#^https?://#i', $audioUrl)) {
            $result['error'] = '音频地址无效（仅支持 http/https）';
            return $result;
        }

        // 已转好的 silk 直链无需再转
        $path = (string)parse_url($audioUrl, PHP_URL_PATH);
        if (preg_match('#\.silk$#i', $path)) {
            return ['ok' => true, 'kind' => 'url', 'data' => $audioUrl, 'provider' => 'passthrough',
                    'bytes' => 0, 'ms' => 0, 'error' => '', 'tries' => []];
        }

        // 组装接口链：主接口 → 备用接口（可选）
        $endpoints = [];
        if ($cfg['provider'] === 'custom') {
            if ($cfg['custom_url'] !== '') {
                $endpoints[] = ['type' => 'custom', 'url' => $cfg['custom_url'], 'name' => '自定义接口'];
            }
        } elseif ($cfg['provider'] === 'oiapi') {
            $endpoints[] = ['type' => 'oiapi', 'url' => 'https://oiapi.net/API/Mp32Silk', 'name' => 'oiapi'];
        } else {
            $endpoints[] = ['type' => 'workbuddy', 'url' => $cfg['base_url'], 'name' => '自建 SilkV3 服务'];
        }
        if (!empty($cfg['fallback']) && $cfg['backup_url'] !== '') {
            $endpoints[] = ['type' => 'custom', 'url' => $cfg['backup_url'], 'name' => '备用接口'];
        }
        if (!$endpoints) {
            $result['error'] = '没有可用的转码接口，请先在后台配置';
            return $result;
        }

        $deadline = microtime(true) + max(10, (int)$cfg['timeout']);

        foreach ($endpoints as $ep) {
            $remain = $deadline - microtime(true);
            if ($remain < 3) {
                $result['tries'][] = ['provider' => $ep['name'], 'ok' => false, 'ms' => 0, 'error' => '超时预算已用完，跳过'];
                continue;
            }

            $t0  = microtime(true);
            $url = silkBuildUrl($ep, $audioUrl, $cfg);
            $res = silkHttpGet($url, (int)floor($remain));
            $ms  = (int)round((microtime(true) - $t0) * 1000);

            if (!$res['ok']) {
                $result['tries'][] = ['provider' => $ep['name'], 'ok' => false, 'ms' => $ms, 'error' => $res['error']];
                $result['error'] = $res['error'];
                continue;
            }

            $parsed = silkParseResponse($res['body'], $res['ctype'], (int)floor($remain));
            if (!$parsed['ok']) {
                $result['tries'][] = ['provider' => $ep['name'], 'ok' => false, 'ms' => $ms, 'error' => $parsed['error']];
                $result['error'] = $parsed['error'];
                continue;
            }

            $bytes = $parsed['kind'] === 'binary' ? strlen($parsed['data']) : (int)strlen($res['body']);
            $result['tries'][] = ['provider' => $ep['name'], 'ok' => true, 'ms' => $ms, 'error' => ''];
            $result['ok']       = true;
            $result['kind']     = $parsed['kind'];
            $result['data']     = $parsed['data'];
            $result['provider'] = $ep['name'];
            $result['bytes']    = $bytes;
            $result['ms']       = (int)round((microtime(true) - $t0) * 1000);
            $result['error']    = '';
            break;
        }

        if (!$result['ok'] && $result['error'] === '') {
            $result['error'] = '全部接口均转换失败';
        }

        // 写一条系统日志，方便排查（不记录含 sharecode 的完整请求地址）
        if (function_exists('wlog')) {
            $brief = [
                'ok'       => $result['ok'],
                'provider' => $result['provider'],
                'kind'     => $result['kind'],
                'bytes'    => $result['bytes'],
                'ms'       => $result['ms'],
                'tries'    => array_map(function ($t) {
                    return $t['provider'] . ($t['ok'] ? ':ok' : ':fail') . '(' . $t['ms'] . 'ms)'
                         . ($t['error'] !== '' ? ' ' . $t['error'] : '');
                }, $result['tries']),
            ];
            wlog('[语音转SILK] ' . json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                 defined('appid') ? appid : null);
        }

        return $result;
    }
}

if (!function_exists('silk')) {
    /**
     * 兼容旧调用：传入音频 URL，返回 SILK 二进制或 SILK 链接；失败返回空串
     * （插件里的 silk($url) 用法保持不变）
     */
    function silk($link) {
        $link = trim((string)$link);
        if ($link === '' || !preg_match('#^https?://#i', $link)) return '';

        $res = silkConvert($link);
        if (!empty($res['ok']) && is_string($res['data']) && $res['data'] !== '') {
            return $res['data'];
        }
        return '';
    }
}
