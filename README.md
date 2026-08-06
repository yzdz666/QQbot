# 官鸡机器人管理后台

> 基于 PHP + SQLite 的 QQ 机器人管理框架，支持 WebHook 和 WebSocket 双模式，自带可视化后台管理。

QQ群：973942141（进群请备注“开源”）
注意：禁止倒卖，仅供学习与二次开发。

## 功能特性

- **双模式运行**：支持 WebHook 回调与 WebSocket 主动连接，适配不同服务器环境
- **可视化管理后台**：机器人管理、消息日志、插件管理、聊天记录、指令测试等一站式管理
- **插件系统**：热插拔插件架构，支持 AI 辅助编写插件
- **安全防护**：SHA-256 加盐密码、IP 自动封禁、会话管理
- **移动端适配**：响应式设计，完美适配手机/平板/桌面
- **零依赖部署**：PHP + SQLite，无需 MySQL，宝塔面板一键部署
- **受限环境兼容**：支持禁用 `shell_exec`/`proc_open` 等函数的服务器（通过定时任务管理守护进程）

## 环境要求

| 组件 | 要求 |
|------|------|
| PHP | >= 7.4（推荐 8.0+）|
| PDO SQLite | 必需 |
| cURL | 必需 |
| mbstring | 必需 |
| GD | 必需（验证码/图片处理）|
| openssl | 必需 |
| sockets | 推荐（WebSocket 模式）|

## 快速开始

### 1. 部署

将整个目录上传到服务器 Web 根目录（如 `/www/wwwroot/你的域名/`）。

### 2. 安装

浏览器访问 `http://你的域名/install.php`，按向导完成：
1. 环境检测
2. 设置管理员账号密码
3. 配置机器人（AppID、Secret、环境）
4. 完成

### 3. 启动 WebSocket 模式（可选）

**方式一：命令行直接运行**
```bash
php ws_client.php
```

**方式二：后台守护进程**
在管理后台 → 系统设置 → WebSocket 模式中点击「启动守护进程」。

**方式三：定时任务（受限环境推荐）**
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
│   ├── api/             # API 接口
│   ├── assets/          # 静态资源（代码高亮、Markdown样式）
│   ├── *.php            # 后台页面
│   └── style.css        # 后台样式
├── data/                # 数据目录（SQLite数据库、日志、PID文件）
├── function/            # 核心函数库
│   ├── Mail/            # PHPMailer 邮件
│   ├── font/            # 字体文件
│   ├── GD.php           # 图片处理
│   ├── Parsedown.php    # Markdown 解析
│   └── qrcode.php       # 二维码生成
├── plugin/              # 插件目录
│   ├── Ark卡片.php
│   ├── 发送测试.php
│   ├── 名言.php
│   └── 音乐.php
├── auth.php             # 认证系统
├── bot.php              # 机器人核心
├── db.php               # 数据库抽象层
├── function.php         # 全局函数
├── install.php          # 安装向导
├── router.php           # 路由入口
├── ws_client.php        # WebSocket 客户端
├── ws_event_handler.php # WebSocket 事件处理
├── ws_monitor.sh        # WS 守护进程监控脚本
└── 文档.md              # 开发文档
```

## 插件开发

插件放在 `plugin/` 目录下，每个 `.php` 文件为一个插件。基本结构：

```php
<?php

if (消息 == "测试图片") {
    图片("https://picsum.photos/400/300", "📸 示例图片");
    return;
}
```

详细开发文档请参考管理后台 → 开发文档页面，或查看 `文档.md`。

## 技术栈

- **后端**：PHP 7.4+ / SQLite（PDO）
- **前端**：原生 HTML/CSS/JS，无框架依赖
- **协议**：QQ 开放平台 Bot API（WebHook + WebSocket）
- **安全**：SHA-256 加盐哈希、IP 防护、会话管理

## 许可证

本项目仅供学习和个人使用。

## 致谢

- [Parsedown](https://github.com/erusev/parsedown) - Markdown 解析器
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) - 邮件发送
- [highlight.js](https://highlightjs.org/) - 代码高亮
