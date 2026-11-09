<?php
/**
 * @name 系统指令
 * @version 1.2.0
 * @author 官鸡官方
 * @description 内置帮助菜单、运行状态、插件清单、OpenID 查询等常用指令，支持主人权限控制。
 * @category 系统
 * @license MIT
 */

if (!defined('消息')) return;

$系统指令_原始 = trim((string)消息);
if ($系统指令_原始 === '') return;

// 去掉常见前缀：/ ／ ! ！ #
$系统指令_命令 = preg_replace('/^[\/／!！#]+/u', '', $系统指令_原始);
$系统指令_命令 = trim($系统指令_命令);

// 帮助菜单
if (in_array($系统指令_命令, ['帮助', 'help', '菜单', '指令'], true)) {
    $系统指令_内容 = "## 官鸡机器人 · 指令中心\n\n"
        . "| 指令 | 说明 |\n|---|---|\n"
        . "| 帮助 | 显示本菜单 |\n"
        . "| 状态 | 查看机器人与数据库概况 |\n"
        . "| 我的ID | 查看当前会话 OpenID |\n"
        . "| 插件 | 查看已启用插件（仅主人） |\n"
        . "| 版本 | 查看框架与插件版本 |\n"
        . "| ping | 连通性测试 |\n\n"
        . "> 其他功能指令由已安装插件提供，可在「插件管理」中查看。";
    文字($系统指令_内容);
    return;
}

// 连通性测试
if (strcasecmp($系统指令_命令, 'ping') === 0) {
    文字('Pong! 服务正常 · ' . date('H:i:s'));
    return;
}

// OpenID 查询（排障常用）
if (in_array($系统指令_命令, ['我的ID', '我的id', 'myid', 'ID', 'id'], true)) {
    $系统指令_来源 = defined('来源') ? 来源 : '';
    $系统指令_用户 = defined('用户') ? 用户 : '';
    $系统指令_类型 = defined('消息来源') ? 消息来源 : '';
    文字("会话类型：{$系统指令_类型}\n会话 ID：{$系统指令_来源}\n用户 OpenID：{$系统指令_用户}");
    return;
}

// 运行状态
if (in_array($系统指令_命令, ['状态', 'status'], true)) {
    $系统指令_统计 = ['消息' => 0, '用户' => 0, '群组' => 0, '插件' => 0];
    try {
        $系统指令_统计['消息'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM messages");
        $系统指令_统计['用户'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM users");
        $系统指令_统计['群组'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM groups");
        $系统指令_插件目录 = APP_ROOT . 'plugin/';
        $系统指令_统计['插件'] = is_dir($系统指令_插件目录) ? count(glob($系统指令_插件目录 . '*.php')) : 0;
    } catch (Throwable $e) {
        // 忽略统计异常
    }
    $系统指令_运行模式 = defined('消息ID') && 消息ID !== '' ? 'WebHook / 事件' : 'WebSocket / 事件';
    $系统指令_内容 = "### 机器人运行状态\n\n"
        . "- 当前机器人：`" . (defined('appid') ? appid : '未知') . "`\n"
        . "- 接入模式：{$系统指令_运行模式}\n"
        . "- 累计消息：{$系统指令_统计['消息']} 条\n"
        . "- 用户 / 群组：" . $系统指令_统计['用户'] . " / " . $系统指令_统计['群组'] . "\n"
        . "- 已安装插件：{$系统指令_统计['插件']} 个\n"
        . "- 服务器时间：" . date('Y-m-d H:i:s');
    文字($系统指令_内容);
    return;
}

// 版本信息
if (in_array($系统指令_命令, ['版本', 'version', '关于'], true)) {
    文字("官鸡机器人框架\n插件引擎：v2\n系统指令插件：v1.2.0\nPHP：" . PHP_VERSION);
    return;
}

// 插件清单（仅主人）
if (in_array($系统指令_命令, ['插件', 'plugins'], true)) {
    if (!function_exists('是否管理员') || !是否管理员()) {
        文字('该指令仅机器人主人可用。');
        return;
    }
    $系统指令_目录 = APP_ROOT . 'plugin/';
    $系统指令_文件 = is_dir($系统指令_目录) ? glob($系统指令_目录 . '*.php') : [];
    $系统指令_列表 = [];
    foreach ($系统指令_文件 as $系统指令_文件项) {
        $系统指令_名称 = basename($系统指令_文件项, '.php');
        $系统指令_启用 = true;
        if (defined('plugin') && is_array(plugin)) {
            $系统指令_启用 = !empty(plugin[$系统指令_名称]);
        }
        if (!$系统指令_启用) continue;
        if (function_exists('插件元信息')) {
            $系统指令_元 = 插件元信息($系统指令_文件项);
            $系统指令_版本 = $系统指令_元['version'] !== '' ? ' v' . $系统指令_元['version'] : '';
        } else {
            $系统指令_版本 = '';
        }
        $系统指令_列表[] = '· ' . $系统指令_名称 . $系统指令_版本;
    }
    if (empty($系统指令_列表)) {
        文字('当前没有启用中的插件。');
        return;
    }
    文字("### 已启用插件（" . count($系统指令_列表) . "）\n" . implode("\n", array_slice($系统指令_列表, 0, 40))
        . (count($系统指令_列表) > 40 ? "\n… 其余 " . (count($系统指令_列表) - 40) . " 个已省略" : ''));
    return;
}
