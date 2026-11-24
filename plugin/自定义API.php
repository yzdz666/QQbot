<?php
/**
 * @name 自定义API
 * @version 1.2.0
 * @author 月下独酌
 * @description 在后台「开发 → 自定义 API」里可视化配置：指令或事件触发 → 调用接口 → 从返回内容取值 → 用文字、图片、语音、文卡、Ark、按钮等函数发送。无需写代码，改完即时生效。
 * @category 开发
 * @license MIT
 */

require_once __DIR__ . '/../function/customapi.php';

// 标记插件已加载：内核兜底看到这个标记就不再重复执行
$GLOBALS['__customapi_plugin_loaded'] = true;

customapi处理当前请求();
