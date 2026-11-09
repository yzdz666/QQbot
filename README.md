# 官鸡机器人管理后台

> 基于 PHP + SQLite 的 QQ 机器人管理框架，支持 WebHook 和 WebSocket 双模式，自带可视化后台管理与**插件市场**。

QQ群：973942141（进群请备注“开源”）
注意：禁止倒卖，仅供学习与二次开发。

## 功能特性

- **双模式运行**：支持 WebHook 回调与 WebSocket 主动连接，适配不同服务器环境
- **可视化管理后台**：机器人管理、消息日志、插件管理、聊天记录、指令测试等一站式管理
- **插件系统**：热插拔插件架构，支持 AI 辅助编写插件
- **插件市场**：自带独立市场站点（前台 + 审核后台），机器人后台可浏览、查看源码、一键安装/更新/卸载
- **官方 API v2**：已接入单聊流式消息 `stream_messages`、富媒体分片上传 `upload_prepare / upload_part_finish`
- **安全防护**：bcrypt 密码、全站 CSRF、同源校验、CSP、登录限流、IP 封禁/白名单、安装向导锁定
- **移动端适配**：响应式设计，完美适配手机/平板/桌面
- **零依赖部署**：PHP + SQLite，无需 MySQL，宝塔面板一键部署
- **受限环境兼容**：支持禁用 `shell_exec`/`proc_open` 等函数的服务器（通过定时任务管理守护进程）

## 环境要求

| 组件 | 要求 |
|------|------|
| PHP | >= 7.4（推荐 8.0+，已在 8.3 测试）|
| PDO SQLite | 必需 |
| cURL | 必需 |
| mbstring | 必需 |
| GD | 必需（验证码/图片处理）|
| openssl | 必需 |
| zip | 市场 zip 投稿/下载需要 |
| sockets | 推荐（WebSocket 模式）|

## 快速开始

### 1. 部署

将整个目录上传到服务器 Web 根目录（如 `/www/wwwroot/你的域名/`），确保 `data/`、`market/data/`、`market/storage/` 可写。

### 2. 安装

浏览器访问 `http://你的域名/install.php`，按向导完成：
1. 环境检测
2. 设置管理员账号密码
3. 配置机器人（AppID、Secret、环境）
4. 完成

> 完成后安装向导会自动锁定（`setup_complete`），再次访问会跳转后台登录，无法被用来重置管理员。

### 3. 插件市场

| 入口 | 地址 | 账号 |
|------|------|------|
| 市场前台 | `/market/` | 无需登录 |
| 市场审核后台 | `/market/admin/` | `admin` / `JgMk-2026-Q7vL9pXe` |
| 机器人后台 | `/admin/market.php` | 安装向导中设置的管理员 |

- **首次登录市场后台后请立即修改密码**，并同步更新 `market/config.php` 的 `MARKET_ADMIN_HASH`
- 市场首次访问会自动建表并写入 4 个示例插件
- 详细说明见 `文档.md` 第 19 章（含 Nginx 防护配置、API、审核流程）

### 4. 启动 WebSocket 模式（可选）

**方式一：命令行直接运行**
```bash
php ws_client.php
```

**方式二：systemd 守护进程（Linux 推荐）**
不要使用 root 直接运行 `php ws_client.php`，否则 SQLite 数据库/WAL 文件可能变成 root 属主，导致后台出现 `unable to open database file`。
推荐执行：
```bash
cd /www/wwwroot/你的路径
WEB_USER=www ./install_ws_service.sh
```
之后由 systemd 自动启动、断线自动重启、服务器重启自动恢复。

**方式三：后台守护进程**
在管理后台 → 系统设置 → WebSocket 模式中点击「启动守护进程」。服务器允许 PHP CLI 后台进程时可直接启动；受限环境请使用 systemd 或 cron。

**方式四：定时任务（受限环境推荐）**
当服务器禁用 `shell_exec`/`popen`/`proc_open` 时：
```bash
# 添加到 crontab
* * * * * /bin/bash /你的路径/ws_monitor.sh
```
然后在后台点击「启动守护进程」，系统将通过定时任务自动管理 WS 进程。

## 目录结构

```
官鸡/
├── admin/                # 管理后台
│   ├── api/             # API 接口（含 market.php 市场对接）
│   ├── assets/          # 静态资源（代码高亮、Markdown样式）
│   ├── market.php       # 插件市场（浏览/安装/更新/源码）
│   ├── *.php            # 后台页面
│   └── style.css        # 后台样式
├── market/               # 插件市场站点（可独立部署）
│   ├── admin/           # 市场审核后台
│   ├── lib/             # 数据库、鉴权、渲染、审核逻辑
│   ├── partials/        # 页头页脚
│   ├── assets/          # 市场样式
│   ├── data/            # 市场数据库（禁止 Web 访问）
│   ├── storage/         # 投稿包与已发布源码（禁止 Web 访问）
│   ├── seed/            # 示例插件（禁止 Web 访问）
│   ├── api.php          # 市场 API
│   ├── download.php     # 下载（带 SHA-256）
│   ├── submit.php       # 作者投稿
│   └── config.php       # 市场配置（管理员哈希、站点地址）
├── data/                # 数据目录（SQLite、日志、插件备份）
│   └── plugin_backups/  # 市场安装/卸载前的旧文件备份
├── function/            # 核心函数库
│   ├── Mail/            # PHPMailer 邮件
│   ├── Parsedown.php    # Markdown 解析
│   ├── GD.php           # 图片处理
│   └── qrcode.php       # 二维码生成
├── plugin/              # 插件目录
├── tools/               # 运维工具
│   └── reset_admin.php  # 命令行重置管理员密码
├── auth.php             # 认证系统（bcrypt / CSRF / 同源）
├── bot.php              # 机器人核心（含官方 v2 新接口）
├── db.php               # 数据库抽象层
├── function.php         # 全局函数
├── install.php          # 安装向导
├── router.php           # 路由入口
├── ws_client.php        # WebSocket 客户端
├── ws_event_handler.php # WebSocket 事件处理
├── ws_monitor.sh        # WS 守护进程监控脚本
└── 文档.md              # 开发文档（第 19 章为插件市场）
```

## 插件开发

插件放在 `plugin/` 目录下，每个 `.php` 文件为一个插件。基本结构：

```php
<?php
/**
 * @name 示例插件
 * @version 1.0.0
 * @author 你的昵称
 */

if (消息 == "测试图片") {
    图片("https://picsum.photos/400/300", "📸 示例图片");
    return;
}
```

详细开发文档请参考管理后台 → 开发文档页面，或查看 `文档.md`。

## 忘记后台密码

```bash
php tools/reset_admin.php --user=admin --pass=NewPass123
```

仅命令行可执行（Web 访问 403），执行后所有会话失效。市场后台密码通过替换 `market/config.php` 中的 `MARKET_ADMIN_HASH` 重置。

## 部署安全清单

- [ ] 浏览器访问 `/data/bot.db`、`/market/data/market.db` 应返回 403（Nginx 需手动配置，见 `文档.md` 19.7）
- [ ] 修改市场后台默认密码
- [ ] 全站启用 HTTPS（市场远程拉取依赖 TLS）
- [ ] `install.php` 完成后确认已锁定（访问 `install.php?step=2` 应跳转登录）
- [ ] 定期备份 `data/` 与 `market/data/`

## 技术栈

- **后端**：PHP 7.4+ / SQLite（PDO）
- **前端**：原生 HTML/CSS/JS，无框架依赖
- **协议**：QQ 开放平台 Bot API v2（WebHook + WebSocket + 流式消息 + 分片上传）
- **安全**：bcrypt 哈希、CSRF Token、同源校验、CSP、登录限流、IP 防护

## 许可证

本项目仅供学习和个人使用。

## 致谢

- [Parsedown](https://github.com/erusev/parsedown) - Markdown 解析器
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) - 邮件发送
- [highlight.js](https://highlightjs.org/) - 代码高亮
