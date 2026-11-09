<?php
/**
 * 官鸡插件市场 - 站点配置
 *
 * 部署后建议修改：
 *   1. 首次登录后台后立即修改管理员密码（后台 → 安全设置）
 *   2. 如需迁移站点，可修改 site_url（留空自动识别）
 *
 * 注意：本文件包含初始管理员密码哈希，禁止通过 Web 直接访问（bootstrap 已拦截）。
 */

// 初始管理员账号（仅首次初始化数据库时写入，之后以数据库为准）
define('MARKET_ADMIN_USER', 'admin');
// 初始密码：JgMk-2026-Q7vL9pXe  (password_hash 生成，登录后请立即修改)
define('MARKET_ADMIN_HASH', '$2y$10$85Hga0WBTokBtEhup6oMA.YXBYyZnNqXoPWg1J3AKsqeAdbYzxYjm');

// 站点地址（留空自动推断；反向代理/HTTPS 场景可填 https://your.domain/market）
define('MARKET_SITE_URL', '');

// 数据目录（一般无需修改）
define('MARKET_DATA_DIR', __DIR__ . '/data');
define('MARKET_STORAGE_DIR', __DIR__ . '/storage');
