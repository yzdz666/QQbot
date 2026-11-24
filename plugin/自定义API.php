<?php
/**
 * @name 自定义API
 * @version 1.1.0
 * @author 月下独酌
 * @description 在后台「开发 → 自定义 API」里可视化配置：指令或事件触发 → 调用接口 → 从返回内容取值 → 用文字、图片、语音、文卡、Ark、按钮等函数发送。无需写代码，改完即时生效。
 * @category 开发
 * @license MIT
 */

require_once __DIR__ . '/../function/customapi.php';

// ==================== 事件触发 ====================
// 群成员增减、入群申请、表情表态、频道事件等全部可作为触发源；
// 同一个事件可以挂多条 API，按创建顺序依次执行
if (customapi当前事件() !== '') {
    foreach (customapi匹配事件全部() as $__ca_事件Api) {
        customapi运行($__ca_事件Api, ['全部' => '', '列表' => [], '完整' => '']);
    }
}

// ==================== 消息指令 ====================
if (!defined('消息')) return;

$__ca_消息 = trim((string)消息);
if ($__ca_消息 === '') return;

list($__ca_api, $__ca_args) = customapi匹配($__ca_消息);
if (!$__ca_api) return;

customapi运行($__ca_api, $__ca_args);
