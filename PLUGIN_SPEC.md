# 月下PHP 插件清单规范 (plugin.json)

每个发布到插件市场的 GitHub 仓库必须包含 `plugin.json` 文件于仓库根目录。

---

## plugin.json 格式

```json
{
  "name": "名言",
  "title": "每日名言/全能测试",
  "description": "包含每日签到、名言警句、撤回测试、按钮交互等功能",
  "version": "1.0.0",
  "author": "作者名",
  "license": "MIT",
  "main": "名言.php",
  "keywords": ["签到", "名言", "工具"],
  "min_framework_version": "1.0.0",
  "max_framework_version": null,
  "settings": [],
  "requires": []
}
```

## 字段说明

| 字段 | 必填 | 类型 | 说明 |
|------|------|------|------|
| `name` | 是 | string | 唯一标识名，**必须与主文件名一致**（不含 `.php`） |
| `title` | 是 | string | 在管理后台显示的插件名称 |
| `description` | 是 | string | 插件功能简要描述（建议 50-200 字） |
| `version` | 是 | string | 语义化版本号，遵循 semver 规范 |
| `author` | 是 | string | 作者名或组织名 |
| `license` | 否 | string | 开源协议标识（如 `MIT`, `GPL-3.0`, `Apache-2.0`） |
| `main` | 是 | string | 插件入口文件名（相对于仓库根目录） |
| `keywords` | 否 | string[] | 关键词数组，用于市场搜索和分类 |
| `min_framework_version` | 否 | string | 兼容的最低框架版本 |
| `max_framework_version` | 否 | string | 兼容的最高框架版本（null 表示无上限） |
| `settings` | 否 | object[] | 插件自定义设置项（保留给未来版本） |
| `requires` | 否 | string[] | 依赖的其他插件名称列表 |

## GitHub 仓库目录结构示例

```
plugin-quote/
├── plugin.json          # ← 清单文件（必须）
├── 名言.php             # ← 插件主文件（由 main 字段指定）
├── README.md            # 说明文档（推荐）
└── screenshot.png       # 截图（可选，用于市场展示）
```

## 版本号规范

- 使用语义化版本号: `主版本.次版本.修订号`
- 市场通过 `version_compare()` 比较版本高低以判断是否有更新
- 每次发布新版本时，必须同时更新 GitHub Release 和 `plugin.json` 中的版本号

## 将插件提交到市场

1. 将插件代码推送到 GitHub 仓库
2. 在仓库根目录创建 `plugin.json`
3. 提交 Pull Request 到本项目的 `marketplace.json`，在 `plugins` 数组中添加你的插件条目

---

## 已有插件的 plugin.json 示例

### 插件: 名言 (`plugin/名言/plugin.json`)

```json
{
  "name": "名言",
  "title": "每日名言/全能测试",
  "description": "包含每日签到、名言警句、撤回测试、按钮交互等全能测试功能",
  "version": "1.0.0",
  "author": "月下",
  "license": "MIT",
  "main": "名言.php",
  "keywords": ["签到", "名言", "测试", "按钮"],
  "min_framework_version": "1.0.0"
}
```

### 插件: 音乐 (`plugin/音乐/plugin.json`)

```json
{
  "name": "音乐",
  "title": "点歌系统",
  "description": "支持搜索和播放音乐，包含歌词展示、音乐卡片等功能",
  "version": "1.0.0",
  "author": "月下",
  "license": "MIT",
  "main": "音乐.php",
  "keywords": ["音乐", "点歌", "娱乐"],
  "min_framework_version": "1.0.0"
}
```

---

## 市场架构示意图

```
┌─ 月下PHP 项目根目录 ─────────────────────┐
│                                            │
│  marketplace.json  ← 市场注册中心          │
│       │                                    │
│       │  plugins[]:                        │
│       │    - name: "名言"                  │
│       │      repository:                   │
│       │        url: github.com/...         │
│       │    - name: "音乐"                  │
│       │      repository:                   │
│       │        url: github.com/...         │
│       │    ...                             │
│                                            │
│  plugin/  ← 本地插件目录                    │
│    ├── 名言.php                            │
│    ├── 音乐.php                            │
│    └── ...                                 │
│                                            │
└────────────────────────────────────────────┘
         │
         ▼ (安装/更新时)
┌─ GitHub 远程仓库 ─────────────────────────┐
│                                            │
│  example/plugin-quote/                     │
│    ├── plugin.json  ← 插件清单             │
│    ├── 名言.php                            │
│    └── README.md                           │
│                                            │
└────────────────────────────────────────────┘
```