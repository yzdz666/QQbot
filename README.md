月下独酌管机 - 机器人管理后台系统

📋 项目概述

月下独酌管机是一个基于 PHP 的 QQ 机器人管理后台系统，提供完整的机器人接入、插件管理、日志监控、在线更新等功能。系统采用模块化设计，支持多机器人并行管理。

✨ 核心功能

1. 🤖 机器人管理

· 添加机器人：支持通过 AppID 和 Secret 接入 QQ 机器人
· 环境切换：正式/沙箱环境一键切换
· 机器人列表：统一管理所有接入的机器人
· 实时统计：展示群聊、私聊、加群、成员增减等数据

2. 🔌 插件系统

· 插件管理：启用/禁用/编辑/删除插件
· API插件生成器：可视化构建插件，无需编程基础
· 快速API模式：通过配置接口 URL 和触发词快速生成插件
· 丰富的动作块：支持文字、图片、语音、视频、文件、Markdown、卡片、按钮、流式回复等
· 数据操作：支持读写数据库、CURL 请求、API 调用

3. 💬 聊天记录

· 会话列表：按私聊/群聊分类展示
· 消息历史：查看完整聊天记录
· 消息操作：引用回复、撤回消息（2分钟内）
· 消息筛选：按日期、类型筛选日志
· 头像显示：自动获取用户 QQ 头像

4. 📊 日志管理

· 日志查看：按日期查看机器人运行日志
· 日志统计：记录数、文件数、事件类型统计
· 日志搜索：按昵称/内容/原始数据搜索
· 日志删除：支持删除指定日志文件
· 事件支持：群成员增加/删除、好友添加/删除等

5. 📦 在线更新

· 版本检查：自动检测 GitHub 最新版本
· 历史版本：支持选择历史版本下载
· 自动备份：更新前自动备份关键文件
· 一键回滚：支持恢复到任意备份版本
· 备份管理：清理旧备份、查看备份列表

6. 🧪 指令测试

· 模拟触发：模拟用户发送指令测试插件
· 实时响应：显示机器人所有回复内容
· 多媒体支持：文字、图片、音频、视频、Markdown

7. 🔐 安全与权限

· 登录验证：基于 Cookie 的管理员认证
· 账号设置：可修改管理员账号密码
· 操作确认：关键操作（删除、更新、回滚）需二次确认

🛠️ 技术特性

后端技术

· 语言：PHP 8.3
· 架构：MVC 模式，RESTful API
· 存储：JSON 文件数据库，无需 MySQL
· 依赖：cURL、ZipArchive、sodium 扩展

前端技术

· 框架：原生 JavaScript
· UI：响应式设计，适配移动端
· 交互：Markdown 渲染、代码高亮、实时搜索

📁 目录结构

```
/
├── admin/                    # 后台管理页面
│   ├── main.php             # 机器人总览
│   ├── chat.php             # 聊天记录
│   ├── log.php              # 日志管理
│   ├── plugin.php           # 插件管理
│   ├── update.php           # 在线更新
│   ├── set.php              # 账号设置
│   ├── simulate.php         # 指令测试
│   ├── cmdtest.php          # 主动消息
│   ├── custom_api.php       # API插件生成器
│   ├── doc.php              # 开发文档
│   └── index.php            # 登录页面
│
├── api/                     # API接口
│   ├── login.php           # 登录认证
│   ├── bot.php             # 机器人管理
│   ├── plugin.php          # 插件管理
│   ├── chat.php            # 聊天记录API
│   ├── log.php             # 日志API
│   ├── info.php            # 信息查询
│   ├── update.php          # 更新API
│   └── simulate.php        # 指令测试API
│
├── function/               # 核心函数库
│   ├── GD.php              # 图像处理
│   ├── qrcode.php          # 二维码生成
│   ├── Parsedown.php       # Markdown解析
│   └── Mail/               # 邮件发送
│
├── plugin/                 # 插件目录
├── database/               # 数据存储
├── Log/                    # 日志存储
├── assets/                 # 静态资源
├── bot.php                 # 机器人核心功能
├── function.php            # 全局函数
├── index.php               # 入口文件
├── config.json             # 配置文件
├── main.json               # 机器人配置
└── version.php             # 版本信息
```

🎯 核心功能流程

机器人消息处理流程

1. 用户发送消息 → QQ 平台回调
2. index.php 接收并验证请求
3. 解析事件类型（群聊/私聊/事件）
4. 加载对应插件（plugin/*.php）
5. 插件处理业务逻辑并返回响应
6. 通过 bot.php 函数发送回复

插件执行流程

1. 用户在后台创建插件（可视化/快速模式）
2. 系统生成 PHP 代码并保存到 plugin/ 目录
3. 机器人收到消息时自动加载并执行匹配插件
4. 插件可调用系统函数（文字/图片/MD/API等）

📊 支持的机器人事件

事件类型 描述
GROUP_AT_MESSAGE_CREATE 群聊 @ 消息
GROUP_MESSAGE_CREATE 群聊普通消息
C2C_MESSAGE_CREATE 私聊消息
GROUP_ADD_ROBOT 机器人被加入群聊
GROUP_DEL_ROBOT 机器人退出群聊
GROUP_MEMBER_ADD 群成员增加
GROUP_MEMBER_REMOVE 群成员移除
INTERACTION_CREATE 按钮/互动事件
FRIEND_ADD 添加好友
FRIEND_DEL 删除好友

🔧 核心函数

bot.php 核心函数

· 文字($content) - 发送文字消息
· 图片($image, $content) - 发送图片
· 语音($url) - 发送语音
· 视频($url) - 发送视频
· 文件($url, $name) - 发送文件
· MD($md, $keyboard) - 发送 Markdown
· 流式(...$msgs) - 流式回复
· 撤回($id) - 撤回消息
· 引用($msgId, $content) - 引用回复
· 按钮($key) - 发送官方按钮
· 原生按钮($md, $rows) - 自定义按钮
· 文卡(...$items) - 文本卡片
· 大图($title, $xtitle, $iurl) - 大图卡片
· 跳转卡($title, $desc, $image, $tz) - 跳转卡片

function.php 核心函数

· 写($文件, $键, $值) - 写入 JSON 数据
· 读($文件, $键, $默认值) - 读取 JSON 数据
· wlog($content) - 写日志
· curl($url, $method, $headers, $params) - HTTP 请求
· 二维码($content) - 生成二维码
· markdown转html($markdown) - MD 转 HTML
· HTML转图($html, $long, $width) - HTML 转图片
· 邮箱($title, $content, $to, $from, $password) - 发送邮件

📦 更新与备份机制

更新流程

1. 检查 GitHub 最新版本
2. 用户选择版本下载
3. 自动创建备份（backup_YYYYmmdd_HHMMSS/）
4. 下载并解压更新包
5. 应用更新（保留 plugin/、main.json、config.json）
6. 更新版本信息

备份与回滚

· 自动备份：每次更新前自动备份
· 手动回滚：选择历史备份一键恢复
· 备份清理：保留最近 5 个备份
· 备份内容：admin/、api/、function/、assets/ 等核心文件

🚀 快速开始

环境要求

· PHP 7.4+
· PHP 扩展：sodium, curl, zip
· 服务器支持：Apache/Nginx

安装步骤

1. 上传所有文件到服务器
2. 确保目录权限（plugin/、database/、Log/ 可写）
3. 访问 index.php 登录后台
4. 默认账号：admin / admin
5. 添加机器人配置（AppID/Secret）
6. 开始管理机器人和插件

安全建议

· 修改默认管理员密码
· 定期备份数据库和配置文件
· 使用 HTTPS 访问后台
· 限制后台访问 IP

📝 开发文档

详细开发文档请查看 doc.php 或 文档.md，包含：

· 插件开发指南
· API 接口说明
· 事件处理机制
· 函数使用示例

⚠️ 注意事项

1. 权限设置：确保 plugin/、database/、Log/ 目录可写
2. sodium 扩展：必须安装 sodium 扩展用于签名验证
3. 安全提示：修改默认密码，定期更新系统
4. 备份重要数据：更新前自动备份，但建议手动额外备份
5. API 限流：注意 QQ 官方 API 调用频率限制

📞 技术支持

· 项目文档：查看 doc.php
· 问题反馈：GitHub Issues
· 版本更新：GitHub Releases

---
q官方群:973942141
版本：1.0.0
更新日期：2026-06-18
作者：月下独酌,3139606844
描述：月下独酌管机 - 机器人管理后台系统