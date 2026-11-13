<?php
/**
 * 管理后台 - 聊天记录
 * 支持@功能、引用、昵称显示、所有发送函数功能
 * 手机版自适应：侧边栏可切换显示
 */
$pageTitle = '聊天记录';
require_once('header.php');

// 获取机器人列表
$bots = getBots();
$defaultAppid = '';
if (!empty($bots)) {
    $defaultAppid = $bots[0]['appid'];
}
?>

<style>
/* 聊天布局 - 干净的左右排布（无外层card包裹） */
.chat-layout {
    display: flex;
    height: calc(100vh - 180px);
    min-height: 420px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    background: var(--card-bg);
    box-shadow: var(--shadow);
    position: relative;
}
.chat-sidebar {
    width: 280px;
    flex-shrink: 0;
    border-right: 1px solid var(--border);
    border-top-left-radius: var(--radius);
    border-bottom-left-radius: var(--radius);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--card-bg);
}
/* 左面板独立标题区域 */
.chat-sidebar-title {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.chat-sidebar-title .sidebar-title-icon {
    width: 30px;
    height: 30px;
    border-radius: 7px;
    background: var(--primary);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}
.chat-sidebar-title .sidebar-title-text {
    min-width: 0;
}
.chat-sidebar-title h3 {
    font-size: 15px;
    font-weight: 600;
    line-height: 1.2;
}
.chat-sidebar-title .sidebar-title-sub {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 2px;
}
.chat-sidebar-header {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    gap: 8px;
    flex-shrink: 0;
}
.chat-sidebar-header .form-control {
    font-size: 13px;
    padding: 7px 10px;
}
.chat-bot-info {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    background: var(--bg);
    border-radius: 6px;
    margin-top: 2px;
}
.chat-bot-info-avatar {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    flex-shrink: 0;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--border);
    color: var(--text-secondary);
    font-size: 12px;
}
.chat-bot-info-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.chat-bot-info-text {
    min-width: 0;
    flex: 1;
}
.chat-bot-info-name {
    font-size: 13px;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.chat-bot-info-qq {
    font-size: 11px;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.chat-session-list {
    flex: 1;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}
.chat-session-item {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: var(--transition);
    display: flex;
    gap: 10px;
    align-items: flex-start;
}
.chat-session-item:hover {
    background: var(--bg);
}
.chat-session-item.active {
    background: var(--bg);
    border-left: 3px solid var(--primary);
}
.chat-session-avatar {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
    background: var(--border);
    color: var(--text-secondary);
    overflow: hidden;
}
.chat-session-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.chat-session-info {
    flex: 1;
    min-width: 0;
}
.chat-session-name {
    font-size: 14px;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.chat-session-preview {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 2px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.chat-session-time {
    font-size: 11px;
    color: var(--text-muted);
    white-space: nowrap;
    flex-shrink: 0;
}

/* 聊天主区域 */
.chat-main {
    flex: 1;
    min-width: 0;
    border-top-right-radius: var(--radius);
    border-bottom-right-radius: var(--radius);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    position: relative;
    background: var(--card-bg);
}
.chat-main-header {
    padding: 12px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--card-bg);
}
.chat-main-header h3 {
    font-size: 15px;
    font-weight: 600;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.chat-main-header .chat-info {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 2px;
    /* 防止长ID/简介撑破布局：允许断行并限制最多2行 */
    word-break: break-all;
    overflow-wrap: anywhere;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.4;
}
/* 紧凑群资料条：单行展示成员数/分类/标签，不占大空间 */
.gp-strip {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 4px 8px;
    margin-top: 4px;
    font-size: 12px;
    color: var(--text-muted);
    max-width: 100%;
}
.gp-strip .gp-item {
    white-space: nowrap;
    max-width: 160px;
    overflow: hidden;
    text-overflow: ellipsis;
}
.gp-strip .gp-tag {
    display: inline-block;
    padding: 0 6px;
    border-radius: 8px;
    background: var(--bg-alt, rgba(0,0,0,0.05));
    color: var(--text-secondary);
    font-size: 11px;
    line-height: 18px;
    max-width: 110px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.gp-strip .gp-toggle {
    color: var(--primary);
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
    font-size: 12px;
}
.gp-strip .gp-toggle:hover { text-decoration: underline; }
/* 可展开的群详情（默认收起，展开后限高滚动） */
.gp-full {
    margin-top: 6px;
    padding: 8px 10px;
    background: var(--bg-alt, rgba(0,0,0,0.04));
    border-radius: 6px;
    font-size: 12px;
    color: var(--text-secondary);
    line-height: 1.6;
    max-width: 100%;
    max-height: 130px;
    overflow-y: auto;
    word-break: break-all;
    overflow-wrap: anywhere;
}
.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px 20px;
    -webkit-overflow-scrolling: touch;
    /* GPU加速滚动 */
    will-change: scroll-position;
}
.chat-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: var(--text-muted);
    font-size: 14px;
}

/* 消息气泡 */
.chat-msg {
    display: flex;
    gap: 10px;
    margin-bottom: 16px;
    align-items: flex-start;
    /* 虚拟滚动优化：允许浏览器跳过不可见消息的渲染 */
    content-visibility: auto;
    contain-intrinsic-size: 80px;
}
.chat-msg.sent {
    flex-direction: row-reverse;
}
.chat-msg-avatar {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
    overflow: hidden;
}
.chat-msg-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.chat-msg.sent .chat-msg-avatar {
    background: #e8f5e9;
    color: #2e7d32;
}
.chat-msg.received .chat-msg-avatar {
    background: #e3f2fd;
    color: #1565c0;
}
.chat-msg-body {
    max-width: 70%;
}
.chat-msg-sender {
    font-size: 12px;
    color: var(--text-muted);
    margin-bottom: 4px;
}
.chat-msg.sent .chat-msg-sender {
    text-align: right;
}
.chat-msg-bubble {
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 14px;
    line-height: 1.5;
    word-break: break-word;
    white-space: pre-wrap;
}
.chat-msg.received .chat-msg-bubble {
    background: var(--bg);
    border: 1px solid var(--border);
}
.chat-msg.sent .chat-msg-bubble {
    background: var(--primary);
    color: #fff;
}
.chat-msg-time {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 4px;
}
.chat-msg.sent .chat-msg-time {
    text-align: right;
}
/* 已撤回消息标记样式 */
.chat-msg-retracted {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: #e53935;
    background: #ffebee;
    border-radius: 4px;
    padding: 2px 8px;
    margin-top: 4px;
    width: fit-content;
}
.chat-msg.sent .chat-msg-retracted {
    margin-left: auto;
}
.chat-retracted-icon {
    font-size: 12px;
}
/* 撤回消息气泡半透明效果 */
.chat-msg.is-retracted .chat-msg-bubble {
    opacity: 0.5;
    text-decoration: line-through;
    text-decoration-color: var(--text-muted);
}
/* 系统事件消息样式（退群、群成员移除等） */
.chat-msg.system-event {
    justify-content: center;
    margin: 4px 0;
}
.chat-system-event {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    background: var(--bg);
    border-radius: 12px;
    font-size: 12px;
    color: var(--text-muted);
    max-width: 80%;
    flex-wrap: wrap;
}
.chat-system-avatar {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    flex-shrink: 0;
    overflow: hidden;
    background: var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
}
.chat-system-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.chat-system-icon {
    font-size: 14px;
    flex-shrink: 0;
}
.chat-system-text {
    flex: 1;
    min-width: 0;
    word-break: break-all;
}
.chat-system-time {
    font-size: 10px;
    color: var(--text-muted);
    opacity: 0.7;
    flex-shrink: 0;
}
.chat-msg-actions {
    display: flex;
    gap: 4px;
    margin-top: 4px;
}
.chat-msg.sent .chat-msg-actions {
    justify-content: flex-end;
}
.chat-msg-action-btn {
    font-size: 11px;
    color: var(--text-muted);
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 3px;
    border: none;
    background: none;
}
.chat-msg-action-btn:hover {
    color: var(--danger);
    background: var(--bg);
}

/* 消息类型标签 */
.chat-msg-type {
    font-size: 11px;
    padding: 1px 6px;
    border-radius: 3px;
    margin-bottom: 4px;
    display: inline-block;
}

/* 图片消息 */
.chat-msg-image {
    max-width: 200px;
    max-height: 200px;
    border-radius: 4px;
    cursor: pointer;
}

/* 媒体加载失败/原件未留存占位块 */
.chat-media-fallback {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 14px;
    background: var(--bg, #f5f6f8);
    border: 1px dashed var(--border, #d8dde6);
    border-radius: 8px;
    color: var(--text-muted, #8a94a6);
    font-size: 12px;
    min-width: 120px;
    justify-content: center;
}

/* 多图片网格 */
.chat-msg-images-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 4px;
    max-width: 300px;
}
.chat-msg-images-grid > div {
    position: relative;
    cursor: pointer;
    overflow: hidden;
    border-radius: 4px;
    aspect-ratio: 1;
}
.chat-msg-images-grid img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* 视频消息 */
.chat-msg-video {
    max-width: 250px;
    max-height: 200px;
    border-radius: 4px;
    display: block;
}

/* 语音消息 */
.chat-msg-audio {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* 媒体查看器模态框 */
.chat-media-viewer {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.85);
    z-index: 3000;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.chat-media-viewer.show {
    display: flex;
}
.chat-media-viewer-content {
    position: relative;
    max-width: 90vw;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.chat-media-viewer-close {
    position: absolute;
    top: -40px;
    right: 0;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255,255,255,0.15);
    border: none;
    color: #fff;
    font-size: 22px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s;
}
.chat-media-viewer-close:hover {
    background: rgba(255,255,255,0.3);
}
.chat-media-viewer-body {
    display: flex;
    align-items: center;
    justify-content: center;
}
.chat-media-viewer-body img {
    max-width: 90vw;
    max-height: 85vh;
    border-radius: 8px;
    object-fit: contain;
}
.chat-media-viewer-body video {
    max-width: 90vw;
    max-height: 85vh;
    border-radius: 8px;
}
.chat-media-viewer-body audio {
    width: 400px;
    max-width: 90vw;
}
.chat-media-viewer-footer {
    margin-top: 12px;
    display: flex;
    gap: 12px;
    align-items: center;
}
.chat-media-viewer-footer a {
    color: #fff;
    text-decoration: none;
    font-size: 13px;
    padding: 6px 16px;
    border-radius: 6px;
    background: rgba(255,255,255,0.15);
    transition: background 0.15s;
}
.chat-media-viewer-footer a:hover {
    background: rgba(255,255,255,0.25);
}
.chat-media-clickable {
    cursor: pointer;
    position: relative;
}
.chat-media-clickable::after {
    content: '\1F50D';
    position: absolute;
    top: 4px;
    right: 4px;
    font-size: 14px;
    opacity: 0;
    transition: opacity 0.2s;
}
.chat-media-clickable:hover::after {
    opacity: 0.8;
}

/* @提及高亮 */
.chat-at-user {
    color: #2c6b9e;
    font-weight: 500;
    background: rgba(44, 107, 158, 0.08);
    padding: 0 4px;
    border-radius: 3px;
}
.chat-msg.sent .chat-at-user {
    color: #a8d4ff;
    background: rgba(255, 255, 255, 0.15);
}

/* 群成员角色徽章 */
.chat-role-badge {
    display: inline-block;
    font-size: 10px;
    padding: 1px 5px;
    border-radius: 3px;
    margin-left: 6px;
    font-weight: 500;
    vertical-align: middle;
}
.chat-role-badge.role-owner {
    background: #fff3e0;
    color: #e65100;
    border: 1px solid #ffb74d;
}
.chat-role-badge.role-admin {
    background: #e3f2fd;
    color: #1565c0;
    border: 1px solid #64b5f6;
}
.chat-role-badge.role-member {
    background: #f5f5f5;
    color: #757575;
    border: 1px solid #e0e0e0;
}
.chat-role-badge.role-bot {
    background: #f3e5f5;
    color: #7b1fa2;
    border: 1px solid #ce93d8;
}
.chat-msg.sent .chat-role-badge.role-bot {
    background: rgba(255,255,255,0.15);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.3);
}

/* 引用消息样式 */
.chat-msg-quote {
    border-left: 3px solid var(--border);
    padding: 6px 10px;
    margin-bottom: 6px;
    background: rgba(0,0,0,0.03);
    border-radius: 0 4px 4px 0;
    font-size: 12px;
    color: var(--text-secondary);
    cursor: pointer;
}
.chat-msg.sent .chat-msg-quote {
    background: rgba(255,255,255,0.1);
    border-left-color: rgba(255,255,255,0.3);
    color: rgba(255,255,255,0.8);
}
.chat-msg-quote-sender {
    font-weight: 600;
    margin-bottom: 2px;
}
.chat-msg-quote-content {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* 引用块媒体缩略预览 */
.chat-msg-quote-thumbs {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 4px;
    align-items: center;
}
.chat-msg-quote-thumb {
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: 4px;
    cursor: zoom-in;
    border: 1px solid var(--border);
    display: block;
}
.chat-msg-quote-file {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    padding: 2px 8px;
    background: rgba(0,0,0,0.04);
    border-radius: 10px;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.chat-msg.sent .chat-msg-quote-file {
    background: rgba(255,255,255,0.12);
}

/* Markdown内容 */
.chat-md-content {
    line-height: 1.6;
}
.chat-md-content h1, .chat-md-content h2, .chat-md-content h3 {
    margin: 8px 0 4px;
    font-weight: 600;
}
.chat-md-content h1 { font-size: 16px; }
.chat-md-content h2 { font-size: 15px; }
.chat-md-content h3 { font-size: 14px; }
.chat-md-content ul, .chat-md-content ol {
    padding-left: 20px;
    margin: 4px 0;
}
.chat-md-content code {
    background: rgba(0,0,0,0.06);
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 12px;
}
.chat-msg.sent .chat-md-content code {
    background: rgba(255,255,255,0.2);
}
.chat-md-content pre {
    background: rgba(0,0,0,0.06);
    padding: 8px;
    border-radius: 4px;
    overflow-x: auto;
    margin: 4px 0;
}
.chat-msg.sent .chat-md-content pre {
    background: rgba(255,255,255,0.15);
}
.chat-md-content strong { font-weight: 600; }
.chat-md-content a { color: inherit; text-decoration: underline; }
.chat-md-content blockquote {
    border-left: 3px solid var(--border);
    padding: 4px 12px;
    margin: 4px 0;
    color: var(--text-secondary);
    background: rgba(0,0,0,0.03);
}
.chat-msg.sent .chat-md-content blockquote {
    background: rgba(255,255,255,0.1);
    border-left-color: rgba(255,255,255,0.3);
}
.chat-md-content hr {
    border: none;
    border-top: 1px solid var(--border);
    margin: 8px 0;
}
.chat-md-content del { text-decoration: line-through; opacity: 0.7; }
.chat-md-content img { max-width: 100%; border-radius: 4px; }
/* MD图片 ![alt](url)，支持QQ尺寸参数(#30px #30px)，点击看大图 */
.chat-md-content img.chat-md-img {
    max-width: 240px;
    max-height: 240px;
    border-radius: 6px;
    display: block;
    margin: 4px 0;
    cursor: zoom-in;
}
/* QQ客户端内联按钮(mqqapi:// 等伪协议链接，浏览器中仅展示) */
.chat-md-btn {
    display: inline-block;
    padding: 3px 14px;
    margin: 2px 4px 2px 0;
    border: 1px solid var(--border, #d8dde6);
    border-radius: 16px;
    background: var(--bg, #f5f6f8);
    font-size: 13px;
    cursor: pointer;
    user-select: none;
    transition: background .15s;
}
.chat-md-btn:hover { background: rgba(0,0,0,0.08); }
.chat-msg.sent .chat-md-btn {
    border-color: rgba(255,255,255,0.4);
    background: rgba(255,255,255,0.14);
}
.chat-msg.sent .chat-md-btn:hover { background: rgba(255,255,255,0.24); }
/* MD伪协议按钮点击提示 */
#mdBtnTip {
    position: fixed;
    left: 50%;
    bottom: 90px;
    transform: translateX(-50%) translateY(8px);
    background: rgba(20,24,34,0.92);
    color: #fff;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    opacity: 0;
    pointer-events: none;
    transition: opacity .2s, transform .2s;
    z-index: 99999;
}
#mdBtnTip.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* 输入区域 */
.chat-input-area {
    padding: 12px 20px;
    border-top: 1px solid var(--border);
    display: flex;
    gap: 10px;
    align-items: flex-end;
    background: var(--card-bg);
}
.chat-input-area textarea {
    flex: 1;
    resize: none;
    min-height: 38px;
    max-height: 120px;
    font-size: 14px;
    padding: 8px 12px;
}
.chat-input-area .btn {
    height: 38px;
    flex-shrink: 0;
}

/* 发送类型选择 */
.chat-send-options {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.chat-send-type {
    padding: 4px 10px;
    font-size: 12px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--card-bg);
    cursor: pointer;
    transition: var(--transition);
}
.chat-send-type.active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
}

/* 引用预览 */
.chat-quote-preview {
    display: none;
    width: 100%;
    padding: 8px 12px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 4px;
    margin-bottom: 8px;
    font-size: 12px;
    color: var(--text-secondary);
    position: relative;
    box-sizing: border-box;
}
.chat-quote-preview.show {
    display: block;
}
.chat-quote-preview-close {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: var(--danger);
    font-size: 16px;
    border: none;
    background: none;
    padding: 0 4px;
    line-height: 1;
}

/* 图片链接就绪chip */
.chat-image-chip {
    display: none;
    width: 100%;
    align-items: center;
    gap: 10px;
    padding: 7px 12px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 4px;
    margin-bottom: 8px;
    box-sizing: border-box;
    position: relative;
}
.chat-image-chip img {
    width: 38px;
    height: 38px;
    object-fit: cover;
    border-radius: 6px;
    background: var(--border);
    flex-shrink: 0;
}
.chat-image-chip-info {
    flex: 1;
    min-width: 0;
    padding-right: 24px;
}
.chat-image-chip-title {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-primary);
}
.chat-image-chip-url {
    font-size: 11px;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.chat-quote-preview-thumb {
    width: 34px;
    height: 34px;
    object-fit: cover;
    border-radius: 5px;
    vertical-align: middle;
    margin-right: 8px;
    border: 1px solid var(--border);
    background: var(--border);
}

/* @提及弹窗 */
.chat-mention-popup {
    display: none;
    position: absolute;
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    box-shadow: var(--shadow-hover);
    max-height: 200px;
    overflow-y: auto;
    z-index: 100;
    min-width: 180px;
}
.chat-mention-item {
    padding: 8px 12px;
    cursor: pointer;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: var(--transition);
}
.chat-mention-item:hover, .chat-mention-item.hover {
    background: var(--bg);
}
.chat-mention-item img {
    width: 20px;
    height: 20px;
    border-radius: 3px;
}

/* 加载更多 */
.chat-load-more {
    text-align: center;
    padding: 8px;
}
.chat-load-more .btn {
    font-size: 12px;
}

/* 筛选栏 */
.chat-filter-bar {
    padding: 8px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    gap: 8px;
    align-items: center;
}
.chat-filter-bar .form-control {
    font-size: 12px;
    padding: 5px 8px;
}

/* 类型徽章颜色 */
.type-text { background: #f0f0f0; color: #666; }
.type-image { background: #fff3e0; color: #e65100; }
.type-voice { background: #e8f5e9; color: #2e7d32; }
.type-video { background: #fce4ec; color: #c62828; }
.type-file { background: #e3f2fd; color: #1565c0; }
.type-md { background: #f3e5f5; color: #7b1fa2; }
.type-quote { background: #fff8e1; color: #f57c00; }
.type-button { background: #e0f7fa; color: #00838f; }
.type-json { background: #f3e5f5; color: #7b1fa2; }
.type-unknown { background: #eceff1; color: #546e7a; }

/* 移动端会话切换按钮 */
.chat-mobile-toggle {
    display: none;
}

/* 响应式 - 手机版 */
@media (max-width: 900px) {
    .chat-sidebar {
        width: 240px;
    }
}
@media (max-width: 768px) {
    .chat-layout {
        height: calc(100vh - 140px);
    }
    .chat-sidebar {
        position: absolute;
        left: 0;
        top: 0;
        width: 85%;
        max-width: 300px;
        height: 100%;
        z-index: 50;
        background: var(--card-bg);
        box-shadow: var(--shadow-hover);
        transform: translateX(-100%);
        transition: transform 0.3s ease;
        border-radius: 0;
    }
    .chat-sidebar.mobile-show {
        transform: translateX(0);
    }
    .chat-sidebar-overlay {
        display: none;
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.3);
        z-index: 49;
    }
    .chat-sidebar-overlay.show {
        display: block;
    }
    .chat-mobile-toggle {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border: 1px solid var(--border);
        border-radius: 4px;
        background: var(--card-bg);
        cursor: pointer;
        font-size: 16px;
    }
    .chat-msg-body {
        max-width: 85%;
    }
    .chat-main-header {
        flex-wrap: wrap;
        gap: 8px;
        padding: 10px 12px;
    }
    .chat-main-header h3 {
        font-size: 14px;
    }
    /* 手机版：头部文字区占据剩余宽度，群资料条/信息行不撑破布局 */
    .chat-main-header > div:first-child {
        flex: 1 1 100%;
        min-width: 0;
    }
    .chat-main-header .gp-strip .gp-item {
        max-width: 120px;
    }
    .chat-main-header .gp-full {
        max-height: 110px;
    }
    #chatTargetActions {
        flex-wrap: wrap;
        gap: 4px !important;
    }
    #chatTargetActions .btn {
        padding: 4px 8px;
        font-size: 11px;
        min-width: auto;
    }
    .chat-input-area {
        flex-wrap: wrap;
        gap: 6px;
    }
    .chat-send-options {
        width: 100%;
        margin-bottom: 4px;
    }
    .chat-input-area textarea {
        flex: 1;
        min-width: 0;
    }
    .chat-input-area .btn {
        flex-shrink: 0;
        margin-left: 0;
    }
}

/* ===== 快捷操作：@全体成员 / 素材 ===== */
.chat-quick-btn {
    padding: 4px 10px;
    font-size: 12px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--card-bg);
    color: var(--text-secondary);
    cursor: pointer;
    transition: var(--transition);
}
.chat-quick-btn:hover { border-color: var(--warn); color: var(--warn); background: var(--warn-soft); }
.chat-force-verify {
    display: none;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: var(--text-muted);
    user-select: none;
    white-space: nowrap;
}
.chat-force-verify input { margin: 0; }
.chat-at-user.everyone {
    color: var(--warn);
    background: var(--warn-soft);
    border: 1px solid #e6d3a8;
}
.chat-msg.sent .chat-at-user.everyone {
    color: #4a3408;
    background: #f0d49a;
    border-color: #f0d49a;
}
.chat-msg.sent .chat-md-media {
    background: rgba(255, 255, 255, .12);
    border-color: rgba(255, 255, 255, .32);
    color: #e9e4d8;
}
.chat-msg.sent .chat-md-media b { color: #fff; }
.chat-md-media {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    max-width: 240px;
    margin: 4px 0;
    padding: 6px 9px;
    border: 1px dashed var(--line-2);
    background: var(--surface-2);
    color: var(--ink-3);
    font-size: 11px;
    border-radius: 4px;
    vertical-align: middle;
}
.chat-md-media b { font-weight: 600; color: var(--ink-2); }

/* ===== 聊天内素材选择器 ===== */
.cmp-toolbar { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.cmp-toolbar .form-control { flex: 1; min-width: 140px; font-size: 12px; padding: 6px 9px; }
.cmp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(132px, 1fr)); gap: 8px; max-height: 52vh; overflow: auto; overscroll-behavior: contain; }
.cmp-item { border: 1px solid var(--line); background: var(--surface); padding: 0; cursor: pointer; text-align: left; min-width: 0; transition: var(--transition); }
.cmp-item:hover { border-color: var(--brand); }
.cmp-item img { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; display: block; background: var(--surface-3); }
.cmp-item .cmp-name { display: block; padding: 5px 7px; font-size: 11px; color: var(--ink-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cmp-empty { padding: 28px 12px; text-align: center; color: var(--ink-3); font-size: 12px; }
.cmp-hint { margin-top: 9px; font-size: 11px; color: var(--ink-3); }
@media (max-width: 768px) {
  .cmp-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); max-height: 46vh; }
  .chat-quick-btn { padding: 4px 7px; }
}
</style>

<div class="page-header">
  <h2>聊天记录</h2>
</div>

<div class="chat-layout">
    <!-- 侧边栏遮罩（手机版） -->
    <div class="chat-sidebar-overlay" id="chatSidebarOverlay" onclick="hideSessionList()"></div>

    <!-- 左侧会话列表 -->
    <div class="chat-sidebar" id="chatSidebar">
      <div class="chat-sidebar-title">
        <span class="sidebar-title-icon">&#128172;</span>
        <div class="sidebar-title-text">
          <h3>聊天会话</h3>
          <div class="sidebar-title-sub">选择会话查看记录</div>
        </div>
      </div>
      <div class="chat-sidebar-header">
        <div class="form-group" style="margin-bottom:0;">
          <select id="chatBotFilter" class="form-control" onchange="onBotChanged()">
            <?php if (empty($bots)): ?>
            <option value="">请先添加机器人</option>
            <?php else: ?>
            <?php foreach ($bots as $bot):
              $label = $bot['appid'];
              if (!empty($bot['nickname'])) $label .= ' (' . $bot['nickname'] . ')';
            ?>
            <option value="<?= htmlspecialchars($bot['appid']) ?>" <?= ($defaultAppid === $bot['appid']) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>
        <div id="chatBotInfo" class="chat-bot-info" style="display:none;">
          <div class="chat-bot-info-avatar" id="chatBotInfoAvatar"></div>
          <div class="chat-bot-info-text">
            <div class="chat-bot-info-name" id="chatBotInfoName"></div>
            <div class="chat-bot-info-qq" id="chatBotInfoQq"></div>
          </div>
        </div>
        <div class="form-group mt-2" style="margin-bottom:0;">
          <input type="text" id="chatSessionSearch" class="form-control" placeholder="搜索会话..." oninput="filterSessions()">
        </div>
      </div>
      <div class="chat-filter-bar">
        <select id="chatTypeFilter" class="form-control" onchange="loadSessions()">
          <option value="">全部类型</option>
          <option value="群聊">群聊</option>
          <option value="私聊">私聊</option>
        </select>
      </div>
      <div class="chat-session-list" id="chatSessionList">
        <div class="chat-empty">加载中...</div>
      </div>
    </div>

    <!-- 右侧聊天区域 -->
    <div class="chat-main" id="chatMain">
      <div class="chat-main-header">
        <div style="display:flex; align-items:center; gap:10px;">
          <button class="chat-mobile-toggle" onclick="showSessionList()">&#9776;</button>
          <div class="chat-header-avatar" id="chatHeaderAvatar" style="width:40px;height:40px;border-radius:8px;display:none;overflow:hidden;flex-shrink:0;background:var(--border);align-items:center;justify-content:center;font-size:18px;color:var(--text-secondary);"></div>
          <div style="flex:1; min-width:0;">
            <h3 id="chatTargetName">选择一个会话</h3>
            <div class="chat-info" id="chatTargetInfo"></div>
            <div id="chatGroupDetail" style="display:none;"></div>
          </div>
        </div>
        <div id="chatTargetActions" style="display:none; gap:6px;">
          <button class="btn btn-outline btn-sm" onclick="showRemarkModal()">设置备注</button>
          <button class="btn btn-outline btn-sm" id="btnGroupInfo" onclick="refreshGroupInfo()" style="display:none;">刷新群信息</button>
          <button class="btn btn-outline btn-sm" id="btnMuteManage" onclick="showMuteModal()" style="display:none;">禁言管理</button>
          <button class="btn btn-outline btn-sm" onclick="retractLastMsg()">撤回最后消息</button>
        </div>
      </div>
      <div class="chat-messages" id="chatMessages">
        <div class="chat-empty">
          <div>
            <div style="font-size:48px; opacity:0.3; margin-bottom:12px;">&#9993;</div>
            <p>点击左上角菜单选择会话</p>
          </div>
        </div>
      </div>
      <div class="chat-input-area" id="chatInputArea" style="display:none; flex-wrap:wrap;">
        <div class="chat-send-options" style="width:100%; margin-bottom:6px; flex-wrap:wrap; gap:4px;">
          <span class="chat-send-type active" data-type="text" onclick="selectSendType('text')">文字</span>
          <span class="chat-send-type" data-type="md" onclick="selectSendType('md')">MD</span>
          <span class="chat-send-type" data-type="image" onclick="selectSendType('image')">图片</span>
          <span class="chat-send-type" data-type="voice" onclick="selectSendType('voice')">语音</span>
          <span class="chat-send-type" data-type="video" onclick="selectSendType('video')">视频</span>
          <span class="chat-send-type" data-type="file" onclick="selectSendType('file')">文件</span>
          <span class="chat-send-type" data-type="ark" onclick="selectSendType('ark')">Ark</span>
          <span class="chat-send-type" data-type="tuwen" onclick="selectSendType('tuwen')">图文卡片</span>
          <button type="button" class="chat-quick-btn" onclick="insertAtEveryone()" title="群聊专用：机器人需为群管理员，每个群每天仅可发送 1 次">＠全体成员</button>
          <label class="chat-force-verify" id="chatForceVerifyWrap" title="官方参数 force_verify_image_resource：Markdown 中的图片转存失败时中止发送">
            <input type="checkbox" id="chatForceVerify"> 图片失败即中止
          </label>
          <span style="font-size:11px; color:var(--text-muted); margin-left:auto;">输入@可提及用户</span>
        </div>
        <div class="chat-quote-preview" id="chatQuotePreview" style="width:100%;">
          <div style="font-weight:600; margin-bottom:2px;">引用消息</div>
          <div id="chatQuoteContent" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; padding-right:24px;"></div>
          <button class="chat-quote-preview-close" onclick="cancelQuote()">&times;</button>
        </div>
        <div class="chat-image-chip" id="chatImageChip" style="display:none;">
          <img id="chatImageChipThumb" alt="" onerror="this.style.opacity='0.25'">
          <div class="chat-image-chip-info">
            <div class="chat-image-chip-title">图片已就绪（可继续输入配文一起发送）</div>
            <div class="chat-image-chip-url" id="chatImageChipUrl"></div>
          </div>
          <button class="chat-quote-preview-close" onclick="clearPendingImageUrl()" title="移除图片">&times;</button>
        </div>
        <textarea id="chatInputMsg" class="form-control" placeholder="输入消息..." rows="1" onkeydown="handleChatKeydown(event)" oninput="handleChatInput(event)"></textarea>
        <div id="chatMentionPopup" class="chat-mention-popup"></div>
        <input type="file" id="chatFileInput" style="display:none;" onchange="handleFileUpload(this)">
        <button class="btn btn-outline" onclick="triggerFileUpload()" title="上传文件发送" style="height:38px;width:38px;padding:0;display:flex;align-items:center;justify-content:center;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
          </svg>
        </button>
        <button class="btn btn-outline" onclick="openMaterialPicker()" title="插入素材图片（Markdown 引用）" style="height:38px;width:38px;padding:0;display:flex;align-items:center;justify-content:center;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>
          </svg>
        </button>
        <button class="btn btn-primary" onclick="sendChatMessage(event)">发送</button>
      </div>
  </div>
</div>

<!-- ==================== 设置备注/头像 模态框 ==================== -->
<div class="modal-overlay" id="remarkModal" style="display:none;">
  <div class="modal">
    <div class="modal-header" id="remarkModalTitle">设置备注</div>
    <div class="modal-body">
      <div class="form-group">
        <label for="remarkInput">备注名称（昵称）</label>
        <input type="text" id="remarkInput" class="form-control" placeholder="输入备注名称（留空清除备注）">
        <div class="form-hint" id="remarkHint">设置后将优先显示此备注名</div>
      </div>
      <div class="form-group" id="groupAvatarGroup" style="display:none;">
        <label for="groupAvatarInput">群头像URL</label>
        <div style="display:flex; gap:8px; align-items:center;">
          <input type="text" id="groupAvatarInput" class="form-control" placeholder="https://... 或留空使用默认群头像" style="flex:1;">
          <div id="groupAvatarPreview" style="width:40px;height:40px;border-radius:6px;background:var(--border);overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px;color:var(--text-secondary);"></div>
        </div>
        <div class="form-hint">设置自定义群头像，留空则使用QQ默认群头像</div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeRemarkModal()">取消</button>
      <button class="btn btn-primary" id="remarkSaveBtn" onclick="saveRemark()">保存</button>
    </div>
  </div>
</div>

<!-- ==================== 禁言管理模态框 ==================== -->
<div class="modal-overlay" id="muteModal" style="display:none;">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header">禁言管理</div>
    <div class="modal-body" style="padding:16px;">
      <!-- 禁言操作区 -->
      <div class="form-group" style="margin-bottom:12px;">
        <label>禁言成员</label>
        <input type="text" id="muteMemberInput" class="form-control" placeholder="成员 openid（可从消息右键复制）" style="margin-bottom:8px;">
        <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
          <select id="muteDurationSelect" class="form-control" style="width:auto; min-width:100px;">
            <option value="60">1分钟</option>
            <option value="600">10分钟</option>
            <option value="3600" selected>1小时</option>
            <option value="21600">6小时</option>
            <option value="43200">12小时</option>
            <option value="86400">1天</option>
            <option value="604800">7天</option>
            <option value="custom">自定义</option>
          </select>
          <input type="text" id="muteDurationCustom" class="form-control" placeholder="如 30s/10m/1h/7d" style="display:none; flex:1; min-width:120px;">
          <button class="btn btn-primary btn-sm" onclick="doMuteMember()">禁言</button>
          <button class="btn btn-outline btn-sm" onclick="doUnmuteMember()">解禁</button>
        </div>
      </div>
      <!-- 批量禁言区 -->
      <div class="form-group" style="margin-bottom:12px;">
        <label>批量禁言（每行一个 openid，单次最多10人）</label>
        <textarea id="batchMuteInput" class="form-control" rows="3" placeholder="成员 openid 1&#10;成员 openid 2" style="font-family:monospace; font-size:12px;"></textarea>
        <div style="display:flex; gap:6px; align-items:center; margin-top:6px;">
          <select id="batchMuteDurationSelect" class="form-control" style="width:auto; min-width:100px;">
            <option value="60">1分钟</option>
            <option value="600">10分钟</option>
            <option value="3600" selected>1小时</option>
            <option value="43200">12小时</option>
            <option value="86400">1天</option>
            <option value="604800">7天</option>
          </select>
          <button class="btn btn-primary btn-sm" onclick="doBatchMute()">批量禁言</button>
        </div>
      </div>
      <!-- 当前禁言状态 -->
      <div class="form-group">
        <label>当前禁言列表</label>
        <button class="btn btn-outline btn-sm" onclick="loadMuteStatus()" style="margin-bottom:8px;">刷新禁言状态</button>
        <div id="muteStatusList" style="max-height:200px; overflow-y:auto; font-size:13px;">
          <div style="color:var(--text-secondary); padding:8px;">点击"刷新禁言状态"查看</div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeMuteModal()">关闭</button>
    </div>
  </div>
</div>

<!-- ==================== Ark消息编辑器模态框 ==================== -->
<div class="modal-overlay" id="arkModal" style="display:none;">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">发送 Ark 消息</div>
    <div class="modal-body" style="padding:16px;">
      <!-- 模板选择器 -->
      <div class="form-group" style="margin-bottom:12px;">
        <label style="font-size:13px;font-weight:600;margin-bottom:6px;display:block;">选择模板</label>
        <select id="arkTemplateSelect" class="form-control" onchange="onArkTemplateChange()" style="width:100%;">
          <option value="37">37 - 大图卡片</option>
          <option value="24">24 - 文本卡片</option>
          <option value="23">23 - 链接卡片</option>
        </select>
      </div>
      <!-- 动态字段容器 -->
      <div id="arkFieldsContainer"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeArkModal()">取消</button>
      <button class="btn btn-primary" id="arkSendBtn" onclick="sendArkMessage()">发送 ARK</button>
    </div>
  </div>
</div>

<!-- ==================== 文件预览模态框 ==================== -->
<div class="modal-overlay" id="filePreviewModal" style="display:none;">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">文件预览</div>
    <div class="modal-body" style="padding:16px;">
      <div id="filePreviewArea" style="text-align:center;margin-bottom:12px;"></div>
      <div id="filePreviewInfo" style="font-size:13px;color:var(--text-muted);text-align:center;margin-bottom:12px;"></div>
      <div id="filePreviewTextRow" style="display:none;">
        <label style="font-size:13px;font-weight:600;margin-bottom:6px;display:block;">附带文字（可选）</label>
        <textarea id="filePreviewText" class="form-control" rows="2" placeholder="输入要随图片一起发送的文字内容..." style="font-size:13px;width:100%;"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeFilePreviewModal()">取消</button>
      <button class="btn btn-primary" onclick="confirmFileSend()">确认发送</button>
    </div>
  </div>
</div>

<!-- ==================== 图片链接发送模态框 ==================== -->
<div class="modal-overlay" id="imageUrlModal" style="display:none;">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header">发送图片</div>
    <div class="modal-body" style="padding:16px;">
      <div class="form-group">
        <label for="imageUrlInput">图片链接 URL</label>
        <input type="text" id="imageUrlInput" class="form-control" placeholder="https://example.com/image.png" oninput="previewImageUrl(this.value)">
        <div class="form-hint">支持 http/https 图片直链（QQ服务器会自动拉取）；确定后仍可在输入框输入配文一起发送</div>
      </div>
      <div id="imageUrlPreviewBox" style="display:none; text-align:center;">
        <img id="imageUrlPreviewImg" style="max-width:100%; max-height:180px; border-radius:8px; border:1px solid var(--border);" alt="预览">
        <div id="imageUrlPreviewTip" style="font-size:11px; color:var(--text-muted); margin-top:4px;">预览加载成功，可直接发送</div>
      </div>
      <div id="imageUrlPreviewError" style="display:none; font-size:12px; color:#c0392b; text-align:center;">预览加载失败（链接仍可尝试发送，QQ服务器可能可以访问）</div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeImageUrlModal()">取消</button>
      <button class="btn btn-primary" id="imageUrlConfirmBtn" onclick="confirmImageUrl()">确定</button>
    </div>
  </div>
</div>

<!-- ==================== 素材选择器（插入 Markdown） ==================== -->
<div class="modal-overlay" id="materialPickerModal" style="display:none;">
  <div class="modal" style="width:100%;max-width:760px;">
    <div class="modal-header">插入素材图片</div>
    <div class="modal-body" style="padding:16px;">
      <div class="cmp-toolbar">
        <input type="text" id="cmpFilter" class="form-control" placeholder="按文件名筛选" oninput="renderPickerMaterials()">
        <button class="btn btn-outline btn-sm" type="button" onclick="loadPickerMaterials(true)">刷新</button>
      </div>
      <div class="cmp-grid" id="cmpGrid"></div>
      <div class="cmp-empty" id="cmpEmpty" style="display:none;">还没有素材，请先到「素材管理」上传</div>
      <div class="cmp-hint"><span id="cmpCount">—</span> · 点击图片插入 <code>![文件名](qqbot-media://...)</code>，会自动切换为 MD 模式；素材需与当前机器人属于同一 AppID。</div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" id="cmpMoreBtn" type="button" style="display:none;margin-right:auto;" onclick="loadPickerMaterials(false)">加载更多</button>
      <a class="btn btn-outline" href="material.php" target="_blank" rel="noopener">打开素材管理</a>
      <button class="btn btn-outline" onclick="closeMaterialPicker()">取消</button>
    </div>
  </div>
</div>

<!-- ==================== 图文卡片编辑器模态框 ==================== -->
<div class="modal-overlay" id="tuwenModal" style="display:none;">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header">发送图文卡片</div>
    <div class="modal-body" style="padding:16px;">
      <div class="form-group" style="margin-bottom:12px;">
        <label style="font-size:13px;font-weight:600;margin-bottom:6px;display:block;">卡片标题（1–50 字，必填）</label>
        <input type="text" id="tuwenTitle" class="form-control" placeholder="输入卡片标题" style="width:100%;">
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label style="font-size:13px;font-weight:600;margin-bottom:6px;display:block;">卡片描述（1–200 字，必填）</label>
        <textarea id="tuwenDesc" class="form-control" rows="3" placeholder="输入卡片描述内容" style="width:100%;"></textarea>
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label style="font-size:13px;font-weight:600;margin-bottom:6px;display:block;">图片 URL（https，必填）</label>
        <input type="text" id="tuwenImage" class="form-control" placeholder="https://图片链接" style="width:100%;">
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label style="font-size:13px;font-weight:600;margin-bottom:6px;display:block;">跳转链接（https，必填）</label>
        <input type="text" id="tuwenLink" class="form-control" placeholder="https://点击卡片跳转的链接" style="width:100%;">
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label style="font-size:13px;font-weight:600;margin-bottom:6px;display:block;">本地预览备注</label>
        <input type="text" id="tuwenPrompt" class="form-control" placeholder="仅用于本地预览，不会随卡片发送" style="width:100%;">
      </div>
      <div id="tuwenPreview" style="margin-top:12px;border:1px solid var(--border);border-radius:8px;overflow:hidden;max-width:300px;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeTuwenModal()">取消</button>
      <button class="btn btn-primary" id="tuwenSendBtn" onclick="sendTuwenMessage()">发送图文卡片</button>
    </div>
  </div>
</div>

<script>
// 机器人数据（包含头像、昵称等）
var botsData = <?= json_encode($bots, JSON_UNESCAPED_UNICODE) ?>;

// 状态
var sessions = [];
var currentSession = null;
var allSessions = [];
var loading = false;
var currentSendType = 'text';
var mentionUsers = [];
var mentionQuery = '';
var mentionStartPos = 0;
var currentQuoteMsg = null; // 引用的消息
var pendingImageUrl = null; // 图片模式下待发送的图片链接（弹窗输入）
var nicknameCache = {}; // 昵称缓存
var groupAvatarCache = {}; // 群自定义头像缓存
var remarkCache = {}; // 备注缓存（用户和群）
var groupProfileExpanded = false; // 群资料详情展开状态（默认收起，不占空间）
var groupProfileData = null;      // 当前会话的群资料缓存

// ==================== 群资料工具函数 ====================
// 清理QQ接口返回文本：解码HTML实体（&nbsp;等）并压缩空白
function cleanGroupText(str) {
    if (str === null || str === undefined) return '';
    var s = String(str);
    if (!s) return '';
    try {
        var t = document.createElement('textarea');
        t.innerHTML = s;
        s = t.value;
        // 二次解码防止 &amp;nbsp; 之类的嵌套编码
        if (s.indexOf('&') !== -1) {
            t.innerHTML = s;
            s = t.value;
        }
    } catch (e) {
        s = String(s).replace(/&nbsp;/gi, ' ').replace(/&amp;/g, '&');
    }
    return s.replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
}
// 长OpenID缩略显示（保留首尾便于辨识）
function shortOpenid(id) {
    var s = String(id || '');
    if (s.length <= 14) return s;
    return s.slice(0, 8) + '…' + s.slice(-4);
}
// 渲染紧凑群资料条 + 可折叠详情（点击「详情」展开/收起）
function renderGroupProfile(gi) {
    var detailEl = document.getElementById('chatGroupDetail');
    if (!detailEl) return;
    if (!gi) {
        detailEl.style.display = 'none';
        detailEl.innerHTML = '';
        groupProfileData = null;
        return;
    }
    groupProfileData = gi;
    var memberNum = parseInt(gi.group_member_num, 10) || 0;
    var classText = cleanGroupText(gi.group_class_text);
    var tags = cleanGroupText(gi.group_tags);
    var memo = cleanGroupText(gi.group_finger_memo);
    var groupName = cleanGroupText(gi.group_name);

    // 紧凑条：成员数 · 分类 · 标签徽章 · 详情开关
    var html = '<div class="gp-strip">';
    if (memberNum > 0) html += '<span class="gp-item">' + memberNum + '人</span>';
    if (classText) html += '<span class="gp-item">' + escapeHtml(classText) + '</span>';
    if (tags) {
        var tagArr = tags.split(',').map(function (t) { return t.trim(); }).filter(function (t) { return t; });
        tagArr.slice(0, 4).forEach(function (t) {
            html += '<span class="gp-tag" title="' + escapeAttr(t) + '">' + escapeHtml(t) + '</span>';
        });
        if (tagArr.length > 4) html += '<span class="gp-item">+' + (tagArr.length - 4) + '</span>';
    }
    html += '<span class="gp-toggle" onclick="toggleGroupProfile()">' + (groupProfileExpanded ? '收起 ▴' : '详情 ▾') + '</span>';
    html += '</div>';

    // 展开区：完整群资料（限高滚动，超长简介不占屏）
    if (groupProfileExpanded) {
        var full = '<div class="gp-full">';
        if (groupName) full += '<div><b>群名称:</b> ' + escapeHtml(groupName) + '</div>';
        if (memberNum > 0) full += '<div><b>成员数:</b> ' + memberNum + '</div>';
        if (classText) full += '<div><b>群分类:</b> ' + escapeHtml(classText) + '</div>';
        if (tags) {
            var tagArr2 = tags.split(',').map(function (t) { return t.trim(); }).filter(function (t) { return t; });
            if (tagArr2.length > 0) {
                full += '<div><b>群标签:</b> ';
                tagArr2.forEach(function (t) { full += '<span class="gp-tag" style="margin-right:4px;">' + escapeHtml(t) + '</span>'; });
                full += '</div>';
            }
        }
        if (memo) full += '<div><b>群简介:</b> ' + escapeHtml(memo) + '</div>';
        full += '</div>';
        html += full;
    }
    detailEl.innerHTML = html;
    detailEl.style.display = 'block';
}
function toggleGroupProfile() {
    groupProfileExpanded = !groupProfileExpanded;
    renderGroupProfile(groupProfileData);
}

// ==================== 分页加载状态 ====================
var PAGE_SIZE = 50;       // 每页加载的消息数
var currentMsgOffset = 0;  // 当前已加载的消息偏移量
var totalMsgCount = 0;     // 会话总消息数
var displayedMsgIds = {};  // 已显示的消息ID集合（用于去重）
var currentLoadedMsgs = []; // 当前已加载的消息数组（从新到旧）
var isLoadingMore = false;  // 是否正在加载更多
var hasMoreMessages = false; // 是否还有更多消息可加载

// 从URL或cookie中获取认证token
var authToken = '';
(function() {
    var urlParams = new URLSearchParams(window.location.search);
    authToken = urlParams.get('token') || '';
    if (!authToken) {
        var match = document.cookie.match(/admin_token=([^;]+)/);
        if (match) authToken = match[1];
    }
})();

// ==================== 通用 AJAX ====================
function apiCall(url, data, callback, method) {
    var xhr = new XMLHttpRequest();
    // 自动添加token到URL
    if (authToken && url.indexOf('token=') === -1) {
        url += (url.indexOf('?') > -1 ? '&' : '?') + 'token=' + encodeURIComponent(authToken);
    }
    xhr.open(method || 'POST', url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = 30000; // 30秒超时
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            var res;
            try { res = JSON.parse(xhr.responseText); }
            catch (e) { res = { success: false, message: '响应解析失败: ' + xhr.responseText.substring(0, 200) }; }
            callback(res);
        }
    };
    xhr.ontimeout = function() {
        callback({ success: false, message: '请求超时，请检查网络连接' });
    };
    xhr.onerror = function() {
        callback({ success: false, message: '网络错误，请检查服务器状态' });
    };
    var params = [];
    for (var key in data) {
        if (data.hasOwnProperty(key)) {
            params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
        }
    }
    xhr.send(params.join('&'));
}

function apiGet(url, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.timeout = 30000; // 30秒超时
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            var res;
            try { res = JSON.parse(xhr.responseText); }
            catch (e) { res = { success: false, message: '响应解析失败' }; }
            callback(res);
        }
    };
    xhr.ontimeout = function() {
        callback({ success: false, message: '请求超时，请检查网络连接' });
    };
    xhr.onerror = function() {
        callback({ success: false, message: '网络错误，请检查服务器状态' });
    };
    xhr.send();
}

// ==================== 获取当前机器人ID ====================
function getCurrentBotId() {
    var botId = document.getElementById('chatBotFilter').value;
    if (!botId && allSessions.length > 0 && allSessions[0].appid) {
        botId = allSessions[0].appid;
    }
    if (!botId) {
        var botSelect = document.getElementById('chatBotFilter');
        if (botSelect.options.length > 0) {
            botId = botSelect.options[0].value;
        }
    }
    return botId;
}

// ==================== 机器人切换时更新信息显示 ====================
function onBotChanged() {
    updateBotInfoDisplay();
    loadSessions();
}

function updateBotInfoDisplay() {
    var botId = document.getElementById('chatBotFilter').value;
    var infoDiv = document.getElementById('chatBotInfo');
    if (!botId) {
        infoDiv.style.display = 'none';
        return;
    }
    var bot = null;
    for (var i = 0; i < botsData.length; i++) {
        if (botsData[i].appid === botId) { bot = botsData[i]; break; }
    }
    if (!bot) {
        infoDiv.style.display = 'none';
        return;
    }
    var nickname = (bot.nickname || '').trim();
    var avatar = (bot.avatar || '').trim();
    var robotQq = (bot.robot_qq || '').trim();

    if (!nickname && !avatar && !robotQq) {
        infoDiv.style.display = 'none';
        return;
    }

    infoDiv.style.display = 'flex';

    // 头像
    var avatarDiv = document.getElementById('chatBotInfoAvatar');
    avatarDiv.innerHTML = '';
    if (avatar) {
        avatarDiv.innerHTML = '<img src="' + htmlspecialchars(avatar) + '" onerror="this.style.display=\'none\'; this.parentElement.innerText=\'' + botId.substring(0,4) + '\'">';
    } else {
        avatarDiv.innerText = botId.substring(0, 4);
    }

    // 昵称
    document.getElementById('chatBotInfoName').textContent = nickname || botId;

    // QQ号
    var qqText = '';
    if (robotQq) qqText = 'QQ: ' + robotQq;
    if (!nickname) qqText = qqText || botId;
    document.getElementById('chatBotInfoQq').textContent = qqText;
}

function htmlspecialchars(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ==================== 发送类型选择 ====================
function selectSendType(type) {
    currentSendType = type;
    var types = document.querySelectorAll('.chat-send-type');
    types.forEach(function(el) {
        el.classList.toggle('active', el.getAttribute('data-type') === type);
    });
    var textarea = document.getElementById('chatInputMsg');
    var hints = {
        'text': '输入消息... 输入@提及用户',
        'md': '输入 Markdown 内容... 支持代码块、引用、列表；@全体成员用 <qqbot-at-everyone />',
        'image': '点击"图片"标签输入图片链接，或直接粘贴图片URL；可另加配文一起发送',
        'voice': '输入语音URL，或点击📎上传本地音频',
        'video': '输入视频URL，或点击📎上传本地视频',
        'file': '输入文件URL，或点击📎上传本地文件',
        'ark': 'Ark编辑器已打开，请在弹窗中编辑...',
        'tuwen': '图文卡片编辑器已打开，请在弹窗中编辑...'
    };
    textarea.placeholder = hints[type] || '输入消息...';

    // 离开图片模式时清除待发送图片链接
    if (type !== 'image' && pendingImageUrl) {
        clearPendingImageUrl();
    }

    // 官方 force_verify_image_resource 仅对 MD 消息生效
    var forceWrap = document.getElementById('chatForceVerifyWrap');
    if (forceWrap) forceWrap.style.display = (type === 'md') ? 'inline-flex' : 'none';

    // Ark / 图文卡片 类型时，直接打开编辑器
    // 先关闭所有编辑器模态框
    var arkModalEl = document.getElementById('arkModal');
    var tuwenModalEl = document.getElementById('tuwenModal');
    if (arkModalEl) arkModalEl.style.display = 'none';
    if (tuwenModalEl) tuwenModalEl.style.display = 'none';

    if (type === 'ark') {
        textarea.readOnly = true;
        textarea.style.opacity = '0.6';
        showArkModal();
    } else if (type === 'tuwen') {
        textarea.readOnly = true;
        textarea.style.opacity = '0.6';
        showTuwenModal();
    } else {
        textarea.readOnly = false;
        textarea.style.opacity = '1';
        // 点击"图片"标签：弹出链接输入窗（已就绪时不重复弹出）
        if (type === 'image' && !pendingImageUrl) {
            showImageUrlModal();
        }
    }
}

// ==================== 图片链接发送（弹窗输入 → 就绪chip → 可加配文） ====================
function showImageUrlModal() {
    var modal = document.getElementById('imageUrlModal');
    if (!modal) return;
    var input = document.getElementById('imageUrlInput');
    if (!pendingImageUrl) {
        input.value = '';
        resetImageUrlPreview();
    } else {
        input.value = pendingImageUrl;
        previewImageUrl(pendingImageUrl);
    }
    modal.style.display = 'flex';
    setTimeout(function() { input.focus(); }, 50);
}

function closeImageUrlModal() {
    document.getElementById('imageUrlModal').style.display = 'none';
}

function resetImageUrlPreview() {
    document.getElementById('imageUrlPreviewBox').style.display = 'none';
    document.getElementById('imageUrlPreviewError').style.display = 'none';
    document.getElementById('imageUrlPreviewImg').removeAttribute('src');
}

function previewImageUrl(url) {
    var box = document.getElementById('imageUrlPreviewBox');
    var errBox = document.getElementById('imageUrlPreviewError');
    var img = document.getElementById('imageUrlPreviewImg');
    url = (url || '').trim();
    if (!/^https?:\/\//i.test(url)) {
        box.style.display = 'none';
        errBox.style.display = 'none';
        img.removeAttribute('src');
        return;
    }
    errBox.style.display = 'none';
    box.style.display = 'block';
    img.style.opacity = '1';
    img.onload = function() {
        document.getElementById('imageUrlPreviewTip').style.display = 'block';
    };
    img.onerror = function() {
        // 预览失败不阻断发送：QQ服务器可能仍可访问该链接
        document.getElementById('imageUrlPreviewTip').style.display = 'none';
        errBox.style.display = 'block';
    };
    img.src = url;
}

function confirmImageUrl() {
    var url = document.getElementById('imageUrlInput').value.trim();
    if (!url) { alert('请输入图片链接'); return; }
    if (!/^https?:\/\//i.test(url)) {
        if (!confirm('链接不是 http/https 开头，可能无法被QQ服务器拉取。仍要使用吗？')) return;
    }
    pendingImageUrl = url;
    updateImageChip();
    closeImageUrlModal();
    var textarea = document.getElementById('chatInputMsg');
    textarea.placeholder = '图片已就绪，输入随图一起发送的文字（可留空）...';
    textarea.focus();
}

function updateImageChip() {
    var chip = document.getElementById('chatImageChip');
    if (!pendingImageUrl) {
        chip.style.display = 'none';
        return;
    }
    var thumb = document.getElementById('chatImageChipThumb');
    thumb.style.opacity = '1';
    thumb.onload = function() { thumb.style.opacity = '1'; };
    thumb.onerror = function() { thumb.style.opacity = '0.25'; };
    thumb.src = pendingImageUrl;
    document.getElementById('chatImageChipUrl').textContent = pendingImageUrl;
    chip.style.display = 'flex';
}

function clearPendingImageUrl() {
    pendingImageUrl = null;
    updateImageChip();
    if (currentSendType === 'image') {
        document.getElementById('chatInputMsg').placeholder = '点击"图片"标签输入图片链接，或直接粘贴图片URL；可另加配文一起发送';
    }
}

// ==================== @提及功能 ====================
function handleChatInput(e) {
    var textarea = e.target;
    var val = textarea.value;
    var cursorPos = textarea.selectionStart;

    var beforeCursor = val.substring(0, cursorPos);
    var atMatch = beforeCursor.match(/@([^\s@]*)$/);

    if (atMatch) {
        mentionQuery = atMatch[1];
        mentionStartPos = cursorPos - atMatch[0].length;
        showMentionPopup(textarea);
    } else {
        hideMentionPopup();
    }
}

function showMentionPopup(textarea) {
    if (!currentSession) {
        hideMentionPopup();
        return;
    }
    if (mentionUsers.length === 0) {
        loadMentionUsers(function() {
            renderMentionPopup(textarea);
        });
    } else {
        renderMentionPopup(textarea);
    }
}

function loadMentionUsers(callback) {
    var botId = getCurrentBotId();
    if (!botId || !currentSession) {
        mentionUsers = [];
        if (callback) callback();
        return;
    }
    apiGet('api/chat_api.php?action=get_messages&appid=' + encodeURIComponent(botId) +
           '&target_id=' + encodeURIComponent(currentSession.target_id) +
           '&source_type=' + encodeURIComponent(currentSession.source_type) +
           '&offset=0&limit=500', function(res) {
        if (res.success && res.data && res.data.messages) {
            var userMap = {};
            res.data.messages.forEach(function(m) {
                if (m.user_id && !userMap[m.user_id]) {
                    userMap[m.user_id] = {
                        id: m.user_id,
                        name: m.group_nickname || m.user_name || nicknameCache[m.user_id] || m.user_id,
                        avatar: m.avatar || ('https://q.qlogo.cn/qqapp/' + botId + '/' + m.user_id + '/640')
                    };
                }
            });
            mentionUsers = Object.values(userMap);
        }
        if (callback) callback();
    });
}

function renderMentionPopup(textarea) {
    var popup = document.getElementById('chatMentionPopup');
    var filtered = mentionUsers.filter(function(u) {
        if (!mentionQuery) return true;
        return (u.name || '').toLowerCase().indexOf(mentionQuery.toLowerCase()) > -1 ||
               (u.id || '').toLowerCase().indexOf(mentionQuery.toLowerCase()) > -1;
    });

    if (filtered.length === 0) {
        hideMentionPopup();
        return;
    }

    var html = '';
    filtered.forEach(function(u, idx) {
        var avatarHtml = u.avatar
            ? '<img src="' + escapeAttr(u.avatar) + '" onerror="this.style.display=\'none\'">'
            : '<span style="font-size:14px;">&#128100;</span>';
        html += '<div class="chat-mention-item" onclick="insertMention(' + idx + ')" data-idx="' + idx + '">' +
                avatarHtml + '<span>' + escapeHtml(u.name || u.id) + '</span></div>';
    });
    popup.innerHTML = html;
    popup.style.display = 'block';

    var rect = textarea.getBoundingClientRect();
    var containerRect = document.getElementById('chatMain').getBoundingClientRect();
    popup.style.left = (rect.left - containerRect.left + 10) + 'px';
    popup.style.bottom = (containerRect.bottom - rect.top + 4) + 'px';
    popup._filteredUsers = filtered;
}

function hideMentionPopup() {
    var popup = document.getElementById('chatMentionPopup');
    if (popup) popup.style.display = 'none';
    mentionQuery = '';
}

function insertMention(idx) {
    var popup = document.getElementById('chatMentionPopup');
    var users = popup._filteredUsers || [];
    if (idx >= users.length) return;

    var user = users[idx];
    var textarea = document.getElementById('chatInputMsg');
    var val = textarea.value;
    var before = val.substring(0, mentionStartPos);
    var after = val.substring(textarea.selectionStart);
    var mentionTag = '<qqbot-at-user id="' + user.id + '" /> ';

    textarea.value = before + mentionTag + after;
    var newPos = before.length + mentionTag.length;
    textarea.focus();
    textarea.setSelectionRange(newPos, newPos);
    hideMentionPopup();
}

// ==================== 在光标处插入文本（@全体成员 / 素材） ====================
function insertTextAtCursor(text) {
    var ta = document.getElementById('chatInputMsg');
    if (!ta) return;
    var start = typeof ta.selectionStart === 'number' ? ta.selectionStart : ta.value.length;
    var end = typeof ta.selectionEnd === 'number' ? ta.selectionEnd : ta.value.length;
    var val = ta.value;
    ta.value = val.slice(0, start) + text + val.slice(end);
    var pos = start + text.length;
    ta.focus();
    ta.setSelectionRange(pos, pos);
    ta.dispatchEvent(new Event('input'));
}

// 群聊 @全体成员：官方 20261026 起支持文本/Markdown 消息使用 <qqbot-at-everyone />
function insertAtEveryone() {
    if (!currentSession) { alert('请先选择会话'); return; }
    if ((currentSession.source_type || '群聊') !== '群聊') {
        alert('@全体成员仅支持群聊会话；单聊消息会被平台拒绝。');
        return;
    }
    if (currentSendType !== 'md') selectSendType('md');
    insertTextAtCursor('<qqbot-at-everyone /> ');
}

// ==================== 聊天内素材选择器 ====================
var cmpImages = [];
var cmpLoading = false;
var cmpCursor = '';

function openMaterialPicker() {
    if (!currentSession) { alert('请先选择会话'); return; }
    document.getElementById('materialPickerModal').style.display = 'flex';
    loadPickerMaterials(true);
}

function closeMaterialPicker() {
    document.getElementById('materialPickerModal').style.display = 'none';
}

function loadPickerMaterials(reset) {
    if (cmpLoading) return;
    var appid = getCurrentBotId();
    if (!appid) { alert('请先选择机器人'); return; }
    if (reset) { cmpImages = []; cmpCursor = ''; }
    cmpLoading = true;
    var url = 'api/material_api.php?action=list&appid=' + encodeURIComponent(appid);
    if (!reset && cmpCursor) url += '&cursor=' + encodeURIComponent(cmpCursor);
    apiGet(url, function (res) {
        cmpLoading = false;
        if (!res.success) {
            if (reset) document.getElementById('cmpGrid').innerHTML = '';
            var empty = document.getElementById('cmpEmpty');
            empty.style.display = 'block';
            empty.textContent = res.message || '素材加载失败';
            document.getElementById('cmpCount').textContent = '加载失败';
            document.getElementById('cmpMoreBtn').style.display = 'none';
            return;
        }
        var incoming = res.images || [];
        cmpImages = reset ? incoming : cmpImages.concat(incoming);
        cmpCursor = res.next_cursor || '';
        document.getElementById('cmpCount').textContent = '已加载 ' + cmpImages.length + ' 张 / 共 ' + (res.total || cmpImages.length) + ' 张';
        document.getElementById('cmpMoreBtn').style.display = cmpCursor ? 'inline-flex' : 'none';
        renderPickerMaterials();
    });
}

function renderPickerMaterials() {
    var grid = document.getElementById('cmpGrid');
    var empty = document.getElementById('cmpEmpty');
    var filter = (document.getElementById('cmpFilter').value || '').trim().toLowerCase();
    var list = cmpImages.filter(function (it) {
        return !filter || String(it.file_name || '').toLowerCase().indexOf(filter) > -1;
    });
    if (!list.length) {
        grid.innerHTML = '';
        empty.style.display = 'block';
        empty.textContent = filter ? '没有匹配的素材' : '还没有素材，请先到「素材管理」上传';
        return;
    }
    empty.style.display = 'none';
    var html = '';
    list.forEach(function (it, idx) {
        var name = it.file_name || '未命名图片';
        html += '<button type="button" class="cmp-item" data-idx="' + idx + '" title="' + escapeAttr(name) + '">'
             + (it.preview_url ? '<img src="' + escapeAttr(it.preview_url) + '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '<div style="aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;color:var(--ink-3);font-size:11px;">无预览</div>')
             + '<span class="cmp-name">' + escapeHtml(name) + '</span>'
             + '</button>';
    });
    grid.innerHTML = html;
    Array.prototype.forEach.call(grid.querySelectorAll('.cmp-item'), function (btn) {
        btn.addEventListener('click', function () {
            var idx = parseInt(btn.getAttribute('data-idx'), 10);
            var item = list[idx];
            if (!item) return;
            if (currentSendType !== 'md') selectSendType('md');
            insertTextAtCursor('![' + (item.file_name || '图片') + '](' + item.url + ')\n');
            closeMaterialPicker();
        });
    });
}

document.getElementById('materialPickerModal').addEventListener('click', function (e) {
    if (e.target === this) closeMaterialPicker();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var m = document.getElementById('materialPickerModal');
        if (m && m.style.display === 'flex') closeMaterialPicker();
    }
});

document.addEventListener('click', function (e) {
    var popup = document.getElementById('chatMentionPopup');
    if (popup && !popup.contains(e.target) && e.target.id !== 'chatInputMsg') {
        hideMentionPopup();
    }
});

// ==================== 加载会话列表 ====================
function loadSessions() {
    var botId = getCurrentBotId();
    var typeFilter = document.getElementById('chatTypeFilter').value;
    var postData = { action: 'get_sessions' };
    if (botId) postData.appid = botId;
    if (typeFilter) postData.source_type = typeFilter;

    apiCall('api/chat_api.php', postData, function(res) {
        if (res.success && res.data) {
            allSessions = res.data;
            sessions = allSessions;
            renderSessions(sessions);
        } else {
            document.getElementById('chatSessionList').innerHTML =
                '<div class="chat-empty">' + (res.msg || res.message || '加载失败') + '</div>';
        }
    });
}

// ==================== 渲染会话列表 ====================
function renderSessions(list) {
    var container = document.getElementById('chatSessionList');
    if (!list || list.length === 0) {
        container.innerHTML = '<div class="chat-empty">暂无会话记录</div>';
        return;
    }
    var botId = getCurrentBotId();
    var html = '';
    for (var i = 0; i < list.length; i++) {
        var s = list[i];
        var isActive = currentSession && currentSession.target_id === s.target_id && currentSession.source_type === s.source_type;
        var typeIcon = s.source_type === '群聊' ? '&#128101;' : '&#128100;';
        var avatarBg = s.source_type === '群聊' ? '#e3f2fd' : '#e8f5e9';
        var avatarColor = s.source_type === '群聊' ? '#1565c0' : '#2e7d32';

        // 优先使用自定义群头像，其次QQ默认头像
        var avatarHtml = '';
        var customAvatar = s.custom_avatar || groupAvatarCache[s.target_id] || '';
        if (customAvatar) {
            avatarHtml = '<img src="' + escapeAttr(customAvatar) + '" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'' + typeIcon + '\';this.parentElement.style.background=\'' + avatarBg + '\';this.parentElement.style.color=\'' + avatarColor + '\';" style="width:100%;height:100%;object-fit:cover;">';
        } else if (botId && s.target_id) {
            var avatarUrl = 'https://q.qlogo.cn/qqapp/' + botId + '/' + s.target_id + '/640';
            avatarHtml = '<img src="' + escapeAttr(avatarUrl) + '" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'' + typeIcon + '\';this.parentElement.style.background=\'' + avatarBg + '\';this.parentElement.style.color=\'' + avatarColor + '\';" style="width:100%;height:100%;object-fit:cover;">';
        } else {
            avatarHtml = typeIcon;
        }

        // 显示昵称，没有则显示ID
        var displayName = s.name || s.target_id;
        html += '<div class="chat-session-item' + (isActive ? ' active' : '') + '" onclick="selectSession(\'' + escapeAttr(s.target_id) + '\',\'' + escapeAttr(s.source_type || '') + '\', this)">';
        html += '<div class="chat-session-avatar" style="background:' + avatarBg + ';color:' + avatarColor + ';">' + avatarHtml + '</div>';
        html += '<div class="chat-session-info">';
        html += '<div class="chat-session-name">' + escapeHtml(displayName) + '</div>';
        html += '<div class="chat-session-preview">' + escapeHtml(s.last_msg || '') + '</div>';
        html += '</div>';
        html += '<div class="chat-session-time">' + escapeHtml(s.last_time || '') + '</div>';
        html += '</div>';
    }
    container.innerHTML = html;
}

// ==================== 搜索过滤会话 ====================
function filterSessions() {
    var q = document.getElementById('chatSessionSearch').value.trim().toLowerCase();
    if (!q) {
        sessions = allSessions;
    } else {
        sessions = allSessions.filter(function(s) {
            return (s.name || '').toLowerCase().indexOf(q) > -1 ||
                   (s.target_id || '').toLowerCase().indexOf(q) > -1;
        });
    }
    renderSessions(sessions);
}

// ==================== 选择会话 ====================
function selectSession(targetId, sourceType, elem) {
    currentSession = { target_id: targetId, source_type: sourceType };
    mentionUsers = [];
    groupProfileExpanded = false; // 切换会话时收起群资料详情
    groupProfileData = null;
    var items = document.querySelectorAll('.chat-session-item');
    items.forEach(function(item) { item.classList.remove('active'); });
    if (elem) elem.classList.add('active');

    // 手机版隐藏侧边栏
    if (window.innerWidth <= 768) {
        hideSessionList();
    }

    loadMessages(targetId, sourceType);
    document.getElementById('chatInputArea').style.display = 'flex';
    document.getElementById('chatTargetActions').style.display = 'flex';
    // 禁言管理按钮仅群聊显示
    var btnMute = document.getElementById('btnMuteManage');
    if (btnMute) btnMute.style.display = (sourceType === '群聊') ? 'inline-block' : 'none';
    // 刷新群信息按钮仅群聊显示
    var btnGroupInfo = document.getElementById('btnGroupInfo');
    if (btnGroupInfo) btnGroupInfo.style.display = (sourceType === '群聊') ? 'inline-block' : 'none';
}

// ==================== 刷新群信息（群名称等） ====================
function refreshGroupInfo() {
    var botId = getCurrentBotId();
    var targetId = currentSession ? currentSession.target_id : '';
    if (!botId || !targetId) {
        alert('请先选择机器人和群聊会话');
        return;
    }
    var btn = document.getElementById('btnGroupInfo');
    if (btn) { btn.disabled = true; btn.textContent = '刷新中...'; }
    apiCall('api.php', {
        action: 'group_get_info',
        appid: botId,
        group_openid: targetId
    }, function(res) {
        if (btn) { btn.disabled = false; btn.textContent = '刷新群信息'; }
        if (res.success) {
            var d = res.data || {};
            var newName = cleanGroupText(d.group_name);
            var memberNum = parseInt(d.group_member_num, 10) || 0;
            var memo = cleanGroupText(d.group_finger_memo);
            var classText = cleanGroupText(d.group_class_text);
            var tags = cleanGroupText(d.group_tags);

            // 更新顶部标题区
            var nameEl = document.getElementById('chatTargetName');
            if (nameEl && newName) nameEl.textContent = newName;

            // 更新副信息行（保持精简，完整资料放群资料条）
            var infoParts = ['群聊 · ' + shortOpenid(targetId)];
            if (memberNum > 0) infoParts.push(memberNum + '人');
            if (classText) infoParts.push('分类:' + classText);
            if (tags) infoParts.push('标签:' + tags);
            var infoEl = document.getElementById('chatTargetInfo');
            if (infoEl) infoEl.textContent = infoParts.join(' · ');

            // 紧凑群资料条（保留当前展开状态）
            renderGroupProfile({
                group_name: newName,
                group_member_num: memberNum,
                group_class_text: classText,
                group_tags: tags,
                group_finger_memo: memo
            });

            // 刷新会话列表
            loadSessions();
            var tip = '群信息已更新\n群名称: ' + newName + (memberNum ? '\n成员数: ' + memberNum : '') + (classText ? '\n群分类: ' + classText : '') + (tags ? '\n群标签: ' + tags : '');
            if (memo) tip += '\n群简介: ' + (memo.length > 60 ? memo.slice(0, 60) + '…' : memo);
            alert(tip);
        } else {
            alert('刷新失败: ' + (res.message || '未知错误'));
        }
    });
}

// ==================== 加载消息 ====================
function loadMessages(targetId, sourceType) {
    var botId = getCurrentBotId();

    document.getElementById('chatMessages').innerHTML = '<div class="chat-empty"><div style="margin-top:8px;">加载中...</div></div>';
    document.getElementById('chatTargetName').textContent = '加载中...';
    document.getElementById('chatTargetInfo').textContent = '';
    renderGroupProfile(null); // 清空上一会话的群资料

    // 重置分页状态
    currentMsgOffset = 0;
    totalMsgCount = 0;
    displayedMsgIds = {};
    currentLoadedMsgs = [];
    hasMoreMessages = false;

    var postData = {
        action: 'get_messages',
        target_id: targetId,
        source_type: sourceType || '',
        offset: 0,
        limit: PAGE_SIZE  // 分页加载，不再一次性加载全部
    };
    if (botId) postData.appid = botId;

    apiCall('api/chat_api.php', postData, function(res) {
        if (res.success && res.data) {
            var info = res.data.info || {};
            var msgs = res.data.messages || [];
            totalMsgCount = info.total || msgs.length;
            currentMsgOffset = msgs.length;
            hasMoreMessages = currentMsgOffset < totalMsgCount;
            
            // 显示昵称（优先备注）
            var displayName = info.remark || info.name || targetId;
            document.getElementById('chatTargetName').textContent = displayName;
            // 副信息行保持精简：类型·短ID·消息数，长简介等放入群资料条，避免占屏
            var infoParts = [(info.source_type || '') + ' · ' + shortOpenid(targetId)];
            if (info.total) infoParts.push('共' + info.total + '条消息');
            var gi = info.group_info;
            if (sourceType === '群聊' && gi) {
                if (parseInt(gi.group_member_num, 10) > 0) infoParts.push(parseInt(gi.group_member_num, 10) + '人');
                var clsText = cleanGroupText(gi.group_class_text);
                if (clsText) infoParts.push('分类:' + clsText);
                var tagText = cleanGroupText(gi.group_tags);
                if (tagText) infoParts.push('标签:' + tagText);
            }
            document.getElementById('chatTargetInfo').textContent = infoParts.join(' · ');

            // 群聊时以紧凑群资料条展示（点击「详情」展开完整资料）
            renderGroupProfile((sourceType === '群聊') ? (gi || null) : null);

            // 显示聊天头部头像
            var headerAvatar = document.getElementById('chatHeaderAvatar');
            var botId2 = getCurrentBotId();
            var customAvatar = info.custom_avatar || '';
            if (sourceType === '群聊') {
                headerAvatar.style.display = 'flex';
                var avatarSrc = customAvatar || ('https://q.qlogo.cn/qqapp/' + botId2 + '/' + targetId + '/640');
                headerAvatar.innerHTML = '<img src="' + escapeAttr(avatarSrc) + '" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'&#128101;\';" style="width:100%;height:100%;object-fit:cover;">';
            } else {
                headerAvatar.style.display = 'flex';
                var userAvatarSrc = 'https://q.qlogo.cn/qqapp/' + botId2 + '/' + targetId + '/640';
                headerAvatar.innerHTML = '<img src="' + escapeAttr(userAvatarSrc) + '" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'&#128100;\';" style="width:100%;height:100%;object-fit:cover;">';
            }

            // 缓存已加载的消息和ID
            currentLoadedMsgs = msgs;
            msgs.forEach(function(m) {
                var mid = m.id || m.msg_id || '';
                if (mid) displayedMsgIds[mid] = true;
            });

            renderMessages(msgs, false, botId);
            lastMsgCount = msgs.length;
            lastMsgLatestId = msgs.length > 0 ? (msgs[0].id || msgs[0].msg_id || '') : '';
            
            // 如果还有更多消息，添加"加载更多"按钮
            if (hasMoreMessages) {
                addLoadMoreButton();
            }
        } else {
            document.getElementById('chatMessages').innerHTML =
                '<div class="chat-empty">' + (res.msg || res.message || '加载失败') + '</div>';
        }
    });
}

// ==================== 加载更多历史消息 ====================
function loadMoreMessages() {
    if (isLoadingMore || !hasMoreMessages || !currentSession) return;
    isLoadingMore = true;
    
    var botId = getCurrentBotId();
    var postData = {
        action: 'get_messages',
        target_id: currentSession.target_id,
        source_type: currentSession.source_type || '',
        offset: currentMsgOffset,
        limit: PAGE_SIZE
    };
    if (botId) postData.appid = botId;

    apiCall('api/chat_api.php', postData, function(res) {
        isLoadingMore = false;
        if (res.success && res.data && res.data.messages) {
            var msgs = res.data.messages || [];
            // 过滤掉已显示的消息（去重）
            var newMsgs = [];
            msgs.forEach(function(m) {
                var mid = m.id || m.msg_id || '';
                if (mid && !displayedMsgIds[mid]) {
                    displayedMsgIds[mid] = true;
                    newMsgs.push(m);
                }
            });
            
            if (newMsgs.length === 0) {
                hasMoreMessages = false;
                removeLoadMoreButton();
                return;
            }

            // 记录当前滚动位置和高度
            var container = document.getElementById('chatMessages');
            var oldScrollHeight = container.scrollHeight;
            var oldScrollTop = container.scrollTop;

            // 将新加载的旧消息添加到数组末尾（数组从新到旧排序）
            currentLoadedMsgs = currentLoadedMsgs.concat(newMsgs);
            currentMsgOffset += msgs.length;
            hasMoreMessages = currentMsgOffset < totalMsgCount;

            // 构建旧消息的HTML并插入到"加载更多"按钮之后（即消息列表顶部）
            var loadMoreBtn = document.getElementById('loadMoreBtn');
            var html = '';
            // 从旧到新渲染（newMsgs是从新到旧，需要反转）
            for (var i = newMsgs.length - 1; i >= 0; i--) {
                html += buildMsgHtml(newMsgs[i], botId);
            }
            
            if (loadMoreBtn) {
                loadMoreBtn.insertAdjacentHTML('afterend', html);
                // 更新"加载更多"按钮状态
                if (!hasMoreMessages) {
                    removeLoadMoreButton();
                } else {
                    var btnEl = loadMoreBtn.querySelector('button');
                    if (btnEl) {
                        btnEl.disabled = false;
                        btnEl.textContent = '加载更多消息 (剩余' + (totalMsgCount - currentMsgOffset) + '条)';
                    }
                }
            } else {
                // 没有按钮，直接在顶部插入
                container.insertAdjacentHTML('afterbegin', html);
            }

            // 保持滚动位置（插入旧消息后，滚动条位置需要调整）
            var newScrollHeight = container.scrollHeight;
            container.scrollTop = oldScrollTop + (newScrollHeight - oldScrollHeight);

            // 批量加载新消息中的昵称
            if (botId) {
                var userIdsToLoad = [];
                newMsgs.forEach(function(m) {
                    if (m.user_id && !nicknameCache[m.user_id] && !queriedNicknameIds[m.user_id] && userIdsToLoad.indexOf(m.user_id) === -1) {
                        userIdsToLoad.push(m.user_id);
                    }
                });
                if (userIdsToLoad.length > 0) {
                    loadNicknames(botId, userIdsToLoad);
                }
            }
        }
    });
}

// ==================== 添加/移除"加载更多"按钮 ====================
function addLoadMoreButton() {
    removeLoadMoreButton(); // 先移除已有的
    var container = document.getElementById('chatMessages');
    var btnDiv = document.createElement('div');
    btnDiv.id = 'loadMoreBtn';
    btnDiv.className = 'chat-load-more';
    btnDiv.style.cssText = 'text-align:center; padding:8px;';
    var remaining = totalMsgCount - currentMsgOffset;
    btnDiv.innerHTML = '<button class="btn btn-outline btn-sm" onclick="loadMoreMessages()" style="font-size:12px;">加载更多消息 (剩余' + remaining + '条)</button>';
    container.insertBefore(btnDiv, container.firstChild);
}

function removeLoadMoreButton() {
    var btn = document.getElementById('loadMoreBtn');
    if (btn) btn.remove();
}

// ==================== 渲染消息 ====================
function renderMessages(msgs, append, botId) {
    var container = document.getElementById('chatMessages');
    var html = '';

    // 先处理 mentions 数据，填充 nicknameCache（在渲染前完成，确保@提及能正确显示）
    msgs.forEach(function(m) {
        if (m.mentions && Array.isArray(m.mentions)) {
            m.mentions.forEach(function(mention) {
                var mentionId = mention.id || mention.member_openid || '';
                var mentionName = mention.username || '';
                // 清理不可见字符
                mentionName = mentionName.replace(/[\u00AD\u061C\u180E\u200B-\u200F\u202A-\u202E\u2060-\u2069\uFEFF\uFFA0]/g, '').trim();
                if (mentionId && mentionName) {
                    // 只有当缓存中没有有效值时才填充
                    if (!nicknameCache[mentionId]) {
                        nicknameCache[mentionId] = mentionName;
                    }
                }
            });
        }
    });

    for (var i = msgs.length - 1; i >= 0; i--) {
        var m = msgs[i];
        html += buildMsgHtml(m, botId);
    }
    container.innerHTML = html;
    // 滚动到底部（使用双重requestAnimationFrame确保content-visibility布局完成后再滚动）
    scrollToBottomForce(container);

    // 收集所有未缓存的user_id，批量加载昵称
    if (botId) {
        var userIdsToLoad = [];
        msgs.forEach(function(m) {
            if (m.user_id && !nicknameCache[m.user_id] && userIdsToLoad.indexOf(m.user_id) === -1) {
                userIdsToLoad.push(m.user_id);
            }
            if (m.target_id && !nicknameCache[m.target_id] && userIdsToLoad.indexOf(m.target_id) === -1) {
                userIdsToLoad.push(m.target_id);
            }
            // 从消息内容中提取 <@USERID> 格式的@提及用户ID
            var rawContent = m.content || '';
            // 匹配 <@后面跟字母数字的ID，以>结尾
            var atMatches = rawContent.match(/<@([A-Fa-f0-9]{20,})>/g);
            if (atMatches) {
                atMatches.forEach(function(atMatch) {
                    var atUserId = atMatch.replace(/<@|>/g, '');
                    if (atUserId && !nicknameCache[atUserId] && userIdsToLoad.indexOf(atUserId) === -1) {
                        userIdsToLoad.push(atUserId);
                    }
                });
            }
            // 也匹配 <qqbot-at-user id="..." /> 格式
            var qqbotAtMatches = rawContent.match(/<qqbot-at-user\s+id="([^"]+)"\s*\/>/g);
            if (qqbotAtMatches) {
                qqbotAtMatches.forEach(function(qbMatch) {
                    var qbUserId = qbMatch.match(/id="([^"]+)"/);
                    if (qbUserId && qbUserId[1]) {
                        var atUserId = qbUserId[1];
                        if (!nicknameCache[atUserId] && userIdsToLoad.indexOf(atUserId) === -1) {
                            userIdsToLoad.push(atUserId);
                        }
                    }
                });
            }
            // 从 mentions 数组中提取@提及用户ID
            if (m.mentions && Array.isArray(m.mentions)) {
                m.mentions.forEach(function(mention) {
                    var mentionId = mention.id || mention.member_openid || '';
                    if (mentionId && !nicknameCache[mentionId] && userIdsToLoad.indexOf(mentionId) === -1) {
                        userIdsToLoad.push(mentionId);
                    }
                });
            }
        });
        if (userIdsToLoad.length > 0) {
            loadNicknames(botId, userIdsToLoad);
        }
    }
}

function buildMsgHtml(m, botId) {
    var isSent = (m.direction === '发送');
    var cls = isSent ? 'sent' : 'received';

    // ==================== 系统事件消息（退群、群成员移除、加群、群成员增加、入群申请等） ====================
    var systemEventTypes = ['退群', '群成员移除', '加群', '群成员增加', '入群申请', '群消息拒绝', '群消息接收', '好友增加', '好友删除', '订阅状态', '频道', '频道私信', '表情表态', '频道更新'];
    var sourceType = m.source_type || '';
    if (systemEventTypes.indexOf(sourceType) > -1) {
        var eventIcon = '';
        var eventText = '';
        var eventUser = m.user_id || '';
        var eventUserName = m.group_nickname || m.username || nicknameCache[eventUser] || (eventUser ? eventUser.substring(0, 12) + '...' : '未知用户');
        if (sourceType === '退群') {
            // 官方新增 dismiss：群聊解散导致的移除，与「被踢出」区分展示
            var isDismiss = (m.content || '').indexOf('[群解散]') > -1;
            if (isDismiss) {
                eventIcon = '🗑';
                eventText = '群聊已解散，机器人被移除';
            } else {
                eventIcon = '👋';
                eventText = eventUserName + ' 退出了群聊';
            }
        } else if (sourceType === '群成员移除') {
            eventIcon = '🚫';
            eventText = eventUserName + ' 被移出群聊';
        } else if (sourceType === '加群') {
            eventIcon = '🎉';
            eventText = '机器人加入群聊';
        } else if (sourceType === '群成员增加') {
            eventIcon = '➕';
            eventText = eventUserName + ' 加入群聊';
        } else if (sourceType === '入群申请') {
            eventIcon = '📝';
            eventText = eventUserName + ' 申请加入群聊';
        } else if (sourceType === '群消息拒绝') {
            eventIcon = '🔇';
            eventText = '群管理员关闭了通知';
        } else if (sourceType === '群消息接收') {
            eventIcon = '🔔';
            eventText = '群管理员开启了通知';
        } else if (sourceType === '好友增加') {
            eventIcon = '🤝';
            eventText = eventUserName + ' 添加了机器人为好友';
        } else if (sourceType === '好友删除') {
            eventIcon = '💔';
            eventText = eventUserName + ' 删除了机器人好友';
        } else if (sourceType === '订阅状态') {
            eventIcon = '📡';
            eventText = '订阅消息状态变更';
        } else if (sourceType === '频道') {
            eventIcon = '📢';
            eventText = eventUserName + ' 发送了频道消息';
        } else if (sourceType === '频道私信') {
            eventIcon = '📬';
            eventText = eventUserName + ' 发送了频道私信';
        } else if (sourceType === '表情表态') {
            eventIcon = '😊';
            eventText = eventUserName + ' 进行了表情表态';
        } else if (sourceType === '频道更新') {
            eventIcon = '🔄';
            eventText = '频道信息更新';
        }

        // 生成系统事件头像
        var sysAvatarHtml = '';
        if (sourceType === '加群') {
            // 机器人加群，显示机器人头像
            var sysBotAvatar = '';
            var sysBotRobotQq = '';
            if (typeof botsData !== 'undefined' && botId) {
                for (var sbi = 0; sbi < botsData.length; sbi++) {
                    if (botsData[sbi].appid === botId) {
                        sysBotAvatar = botsData[sbi].avatar || '';
                        sysBotRobotQq = botsData[sbi].robot_qq || '';
                        break;
                    }
                }
            }
            if (sysBotAvatar) {
                sysAvatarHtml = '<img src="' + escapeAttr(sysBotAvatar) + '" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'&#129302;\';">';
            } else if (sysBotRobotQq) {
                sysAvatarHtml = '<img src="https://q1.qlogo.cn/g?b=qq&nk=' + encodeURIComponent(sysBotRobotQq) + '&s=640" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'&#129302;\';">';
            } else {
                sysAvatarHtml = '&#129302;';
            }
        } else if (eventUser && botId) {
            // 群成员事件，显示对应用户头像
            sysAvatarHtml = '<img src="https://q.qlogo.cn/qqapp/' + escapeAttr(botId) + '/' + escapeAttr(eventUser) + '/640" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'&#128100;\';">';
        } else {
            sysAvatarHtml = '&#128100;';
        }

        var sysHtml = '<div class="chat-msg system-event">';
        sysHtml += '<div class="chat-system-event">';
        sysHtml += '<span class="chat-system-avatar">' + sysAvatarHtml + '</span>';
        sysHtml += '<span class="chat-system-icon">' + eventIcon + '</span>';
        sysHtml += '<span class="chat-system-text">' + escapeHtml(eventText) + '</span>';
        sysHtml += '<span class="chat-system-time">' + escapeHtml(m.created_at || '') + '</span>';
        sysHtml += '</div></div>';
        return sysHtml;
    }

    // 获取昵称 - 优先使用 raw_data 中的 username，其次查缓存
    var senderName = '';
    var memberRole = m.member_role || '';
    if (isSent) {
        // 发送方为机器人：优先使用机器人昵称，其次备注，最后 appid
        var botInfo = null;
        if (typeof botsData !== 'undefined' && botId) {
            for (var bi = 0; bi < botsData.length; bi++) {
                if (botsData[bi].appid === botId) { botInfo = botsData[bi]; break; }
            }
        }
        if (botInfo && botInfo.nickname) {
            senderName = botInfo.nickname;
        } else if (botInfo && botInfo.robot_qq) {
            senderName = botInfo.robot_qq;
        } else if (botId) {
            senderName = botId.length > 16 ? botId.substring(0, 12) + '...' : botId;
        } else {
            senderName = '机器人';
        }
    } else {
        // 群聊优先显示群昵称，其次用户昵称（官方 v2 私聊已下发 username）
        var preferredName = m.group_nickname || m.user_name || m.username || '';
        if (preferredName) {
            // 清理不可见字符
            var cleanedUsername = preferredName.replace(/[\u00AD\u061C\u180E\u200B-\u200F\u202A-\u202E\u2060-\u2069\uFEFF\uFFA0]/g, '').trim();
            if (cleanedUsername) {
                senderName = cleanedUsername;
                // 只有当缓存中没有有效值时才更新
                if (m.user_id && !nicknameCache[m.user_id]) {
                    nicknameCache[m.user_id] = cleanedUsername;
                }
            } else if (m.user_id && nicknameCache[m.user_id]) {
                senderName = nicknameCache[m.user_id];
            } else if (m.user_id) {
                senderName = m.user_id.substring(0, 12) + '...';
            } else {
                senderName = '用户';
            }
        } else if (m.user_id && nicknameCache[m.user_id]) {
            senderName = nicknameCache[m.user_id];
        } else if (m.user_id) {
            senderName = m.user_id.substring(0, 12) + '...';
        } else {
            senderName = '用户';
        }
    }

    // 角色标签映射
    var roleBadge = '';
    if (memberRole === 'owner') {
        roleBadge = '<span class="chat-role-badge role-owner">群主</span>';
    } else if (memberRole === 'admin') {
        roleBadge = '<span class="chat-role-badge role-admin">管理员</span>';
    } else if (memberRole === 'member') {
        roleBadge = '<span class="chat-role-badge role-member">群成员</span>';
    }
    // 机器人标签：当 raw_data 中 author.bot=true 时显示
    if (m.bot === true) {
        roleBadge = '<span class="chat-role-badge role-bot">机器人</span>' + roleBadge;
    }

    // 生成头像
    var avatarHtml = '';
    var avatarUrl = '';
    if (botId) {
        if (isSent) {
            // 发送方为机器人：优先使用机器人头像，其次通过 robot_qq 生成，最后回退到 emoji
            var botAvatar = '';
            var botRobotQq = '';
            if (typeof botsData !== 'undefined') {
                for (var ba = 0; ba < botsData.length; ba++) {
                    if (botsData[ba].appid === botId) {
                        botAvatar = botsData[ba].avatar || '';
                        botRobotQq = botsData[ba].robot_qq || '';
                        break;
                    }
                }
            }
            if (botAvatar) {
                avatarHtml = '<img src="' + escapeAttr(botAvatar) + '" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'&#129302;\';">';
            } else if (botRobotQq) {
                // 通过QQ号生成头像
                avatarUrl = 'https://q1.qlogo.cn/g?b=qq&nk=' + encodeURIComponent(botRobotQq) + '&s=640';
                avatarHtml = '<img src="' + escapeAttr(avatarUrl) + '" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'&#129302;\';">';
            } else {
                avatarHtml = '&#129302;';
            }
        } else if (m.avatar || m.user_avatar) {
            // 官方 v2 直接下发头像链接，优先使用（对 openid 拼接头像不生效的场景尤其重要）
            avatarUrl = m.avatar || m.user_avatar;
            avatarHtml = '<img src="' + escapeAttr(avatarUrl) + '" referrerpolicy="no-referrer" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'&#128100;\';">';
        } else if (m.user_id) {
            avatarUrl = 'https://q.qlogo.cn/qqapp/' + botId + '/' + m.user_id + '/640';
            avatarHtml = '<img src="' + escapeAttr(avatarUrl) + '" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'&#128100;\';">';
        } else {
            avatarHtml = '&#128100;';
        }
    } else {
        avatarHtml = isSent ? '&#8593;' : '&#8595;';
    }

    var msgDataAttr = escapeAttr(JSON.stringify({
        id: m.id || '',
        appid: m.appid || botId || '',
        msg_id: m.msg_id || '',
        ref_idx: m.ref_idx || '',
        msg_idx: m.msg_idx || '',
        quote: m.quote || null,
        target_id: m.target_id || '',
        content: m.content || '',
        media_url: m.media_url || '',
        attachments: m.attachments || [],
        content_type: m.content_type || 'text',
        direction: m.direction || '',
        user_id: m.user_id || '',
        sender_name: senderName,
        created_at: m.created_at || '',
        is_retracted: m.is_retracted || 0
    }));

    var html = '<div class="chat-msg ' + cls + '" data-msg-id="' + (m.id || '') + '" data-appid="' + escapeAttr(m.appid || '') + '" data-msg-data="' + msgDataAttr + '">';
    html += '<div class="chat-msg-avatar">' + avatarHtml + '</div>';
    html += '<div class="chat-msg-body">';
    html += '<div class="chat-msg-sender">' + escapeHtml(senderName) + roleBadge + '</div>';

    var contentType = (m.content_type || 'text');
    // 归一化: bot.php 日志用大写 MD, 渲染分支用小写
    if (contentType === 'MD') contentType = 'md';
    else if (contentType === 'Markdown') contentType = 'markdown';
    // 存量数据兜底: 早期入库的 QQ markdown 消息被记为"文字", 检测到强 MD 特征时按 MD 渲染
    // (强特征: 图片语法 ![alt](url / QQ内联按钮 ](mqqapi: , 普通聊天文本几乎不会出现, 误判率极低)
    if ((contentType === 'text' || contentType === '文字') && typeof m.content === 'string'
        && /!\[[^\]\n]*\]\s*\(|\]\(mqqapi:/i.test(m.content)) {
        contentType = 'md';
    }
    // 统一类型映射为中文标签
    var typeLabelMap = {
        'text': '文字', '文字': '文字',
        'md': 'MD', 'markdown': 'MD',
        'image': '图片', '图片': '图片',
        'video': '视频', '视频': '视频',
        'voice': '语音', '语音': '语音',
        'file': '文件', '文件': '文件',
        'ark': 'Ark', 'Ark': 'Ark',
        'embed': '图文卡片', '图文卡片': '图文卡片',
        '引用图片': '引用图片', '引用图文': '引用图文',
        '引用语音': '引用语音', '引用视频': '引用视频',
        '引用文件': '引用文件', '引用MD': '引用MD', '引用': '引用'
    };
    var typeLabel = typeLabelMap[contentType] || contentType;
    var typeCls = 'type-' + (['image','video','voice','file','md','ark','embed'].indexOf(contentType) >= 0 ? contentType : (contentType === '图片' ? 'image' : contentType === '视频' ? 'video' : contentType === '语音' ? 'voice' : contentType === '文件' ? 'file' : 'text'));
    // 引用系类型徽章沿用引用橙色样式 (引用图片/引用图文/引用语音/引用视频/引用文件/引用MD/引用)
    if (contentType.indexOf('引用') === 0) typeCls = 'type-quote';
    // 引用消息（回复他人）：类型徽章显示“引用”，并在气泡前渲染被引用内容块（含媒体缩略图）
    var quoteHtml = '';
    if (m.quote && (m.quote.content || (m.quote.attachments && m.quote.attachments.length))) {
        typeLabel = '引用';
        typeCls = 'type-quote';
        quoteHtml = '<div class="chat-msg-quote" title="点击引用这条消息" onclick="quoteMsg(this)">';
        quoteHtml += '<div class="chat-msg-quote-sender">引用消息</div>';
        if (m.quote.content) {
            quoteHtml += '<div class="chat-msg-quote-content" title="' + escapeAttr(m.quote.content) + '">' + escapeHtml(m.quote.content) + '</div>';
        }
        // 被引用消息的媒体缩略预览（图片看大图，视频/语音/文件用图标标签）
        var qAtts = (m.quote.attachments || []).filter(function(a) { return a && a.url; });
        if (qAtts.length) {
            quoteHtml += '<div class="chat-msg-quote-thumbs">';
            qAtts.forEach(function(a) {
                if (a.type === 'image') {
                    quoteHtml += '<img class="chat-msg-quote-thumb" src="' + escapeAttr(a.url) + '" onclick="event.stopPropagation();openMediaViewer(\'image\',\'' + escapeAttr(a.url) + '\')" onerror="this.style.display=\'none\'" referrerpolicy="no-referrer">';
                } else {
                    var qIcon = a.type === 'video' ? '&#127909;' : (a.type === 'voice' ? '&#127925;' : '&#128206;');
                    var qName = a.filename || (a.type === 'video' ? '视频' : (a.type === 'voice' ? '语音' : '文件'));
                    quoteHtml += '<span class="chat-msg-quote-file">' + qIcon + ' ' + escapeHtml(qName) + '</span>';
                }
            });
            quoteHtml += '</div>';
        }
        quoteHtml += '</div>';
    }
    html += '<div class="chat-msg-type ' + typeCls + '">' + escapeHtml(typeLabel) + '</div>';
    if (quoteHtml) html += quoteHtml;

    // 内容渲染 - 优先使用attachments数组，其次media_url
    var content = (m.content || '(无内容)');
    // 去除content两端的反引号
    content = content.replace(/^`+|`+$/g, '').trim();
    var mediaUrl = (m.media_url || '');
    // 去除media_url两端的反引号
    mediaUrl = mediaUrl.replace(/^`+|`+$/g, '').trim();
    // 文件名（从API传入）
    var fileName = m.file_name || '';
    // 附件数组（支持多图片/多文件）
    var attachments = m.attachments || [];

    // 旧格式兑底: 引用图片/引用图文/引用语音/引用视频/引用文件 的日志 content 为 "URL + 配文"/"URL",
    // 无结构化附件时从 content 解析出媒体与配文, 转为统一附件渲染 (兼容历史存量记录)
    if (!attachments.length && (contentType === '引用图片' || contentType === '引用图文' || contentType === '引用语音' || contentType === '引用视频' || contentType === '引用文件')) {
        var legacy = parseMediaCaption(content);
        if (legacy) {
            var legacyType = (contentType === '引用图片' || contentType === '引用图文') ? 'image'
                           : (contentType === '引用语音' ? 'voice' : (contentType === '引用视频' ? 'video' : 'file'));
            attachments = [{type: legacyType, url: legacy.url, filename: '', wav_url: '', asr_text: ''}];
            mediaUrl = legacy.url;
            content = legacy.caption;
        }
    }

    // 多附件渲染（图片/视频/语音/文件混合）
    if (attachments.length > 0) {
        // 分离图片附件和其他附件
        var imageAtts = attachments.filter(function(a) { return a.type === 'image'; });
        var otherAtts = attachments.filter(function(a) { return a.type !== 'image'; });

        // 渲染图片（单张或多张网格布局）
        if (imageAtts.length > 0) {
            if (imageAtts.length === 1) {
                // 单张图片
                var imgUrl = imageAtts[0].url.replace(/^`+|`+$/g, '').trim();
                html += '<div class="chat-msg-bubble" style="padding:4px;background:none;border:none;">';
                html += '<img class="chat-msg-image chat-media-clickable" src="' + escapeAttr(imgUrl) + '" onclick="openMediaViewer(\'image\',\'' + escapeAttr(imgUrl) + '\')" onerror="mediaImgError(this)" referrerpolicy="no-referrer">';
                html += '</div>';
            } else {
                // 多张图片：网格布局
                html += '<div class="chat-msg-bubble" style="padding:4px;background:none;border:none;">';
                html += '<div class="chat-msg-images-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:4px;max-width:300px;">';
                imageAtts.forEach(function(att) {
                    var url = att.url.replace(/^`+|`+$/g, '').trim();
                    html += '<div style="position:relative;cursor:pointer;overflow:hidden;border-radius:4px;aspect-ratio:1;">';
                    html += '<img class="chat-media-clickable" style="width:100%;height:100%;object-fit:cover;" src="' + escapeAttr(url) + '" onclick="openMediaViewer(\'image\',\'' + escapeAttr(url) + '\')" onerror="this.parentElement.style.display=\'none\'" referrerpolicy="no-referrer">';
                    html += '</div>';
                });
                html += '</div>';
                html += '</div>';
            }
        }

        // 渲染其他附件（视频/语音/文件）
        otherAtts.forEach(function(att) {
            var attUrl = (att.url || '').replace(/^`+|`+$/g, '').trim();
            if (att.type === 'video') {
                html += '<div class="chat-msg-bubble" style="padding:4px;background:none;border:none;">';
                html += '<video class="chat-msg-video chat-media-clickable" preload="metadata" controls style="max-width:250px;max-height:200px;border-radius:4px;" onclick="openMediaViewer(\'video\',\'' + escapeAttr(attUrl) + '\')">';
                html += '<source src="' + escapeAttr(attUrl) + '">';
                html += '</video></div>';
            } else if (att.type === 'voice') {
                // 优先使用WAV URL（浏览器兼容），其次使用原始URL
                var voiceUrl = (att.wav_url || attUrl || '').replace(/^`+|`+$/g, '').trim();
                html += '<div class="chat-msg-bubble" style="padding:10px 14px;min-width:200px;">';
                html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
                html += '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>';
                html += '<span style="font-size:13px;font-weight:500;">语音消息</span>';
                html += '<a href="' + escapeAttr(voiceUrl) + '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" style="margin-left:auto;font-size:11px;color:var(--text-muted);text-decoration:none;">下载</a>';
                html += '</div>';
                // 使用audio标签播放，直接设置src让浏览器自动检测格式
                if (voiceUrl) {
                    html += '<audio controls preload="metadata" src="' + escapeAttr(voiceUrl) + '" style="width:100%;max-width:300px;outline:none;"></audio>';
                }
                // 显示ASR语音转文字
                if (att.asr_text) {
                    html += '<div style="margin-top:8px;padding:6px 10px;background:var(--bg);border-radius:6px;font-size:12px;color:var(--text-secondary);line-height:1.5;border-left:2px solid var(--border);">' + escapeHtml(att.asr_text) + '</div>';
                }
                html += '</div>';
            } else if (att.type === 'file') {
                var fName = resolveFileName(att.filename, attUrl, content);
                var fileInfo = detectFileUrl(attUrl);
                var iconHtml = fileInfo ? getFileTypeIcon(fileInfo.extension) : '<span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;background:#1565c0;color:#fff;font-size:10px;font-weight:700;flex-shrink:0;">FILE</span>';
                var extLabel = fileInfo ? fileInfo.extension.toUpperCase() : '';
                html += '<div class="chat-msg-bubble" style="padding:8px 12px;">';
                html += '<a href="' + escapeAttr(attUrl) + '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit;">';
                html += iconHtml;
                html += '<div style="min-width:0;"><div style="font-weight:500;font-size:13px;word-break:break-all;">' + escapeHtml(fName) + '</div>';
                html += '<div style="font-size:11px;color:var(--text-muted);">点击下载' + (extLabel ? ' (' + escapeHtml(extLabel) + ')' : '') + '</div></div></a></div>';
            }
        });

        // 如果有文字内容（图片+文字消息），在附件下方显示文字
        // 剔除内容中出现的附件URL与 "+ " 分隔符后, 剩余文本即为配文 (兼容 "URL + 配文"与"URL 配文"两种日志格式)
        var textOnly = content;
        attachments.forEach(function(a) {
            if (a && a.url) { try { textOnly = textOnly.split(a.url).join(''); } catch(e) {} }
        });
        textOnly = textOnly.replace(/^\s*\+\s*/, '').trim();
        var isUrlOnly = /^https?:\/\/\S+$/i.test(textOnly);
        if (textOnly && !isUrlOnly) {
            // 显示文字内容（处理@提及）
            var processedContent = escapeHtml(textOnly);
            processedContent = replaceAtMentions(processedContent);
            html += '<div class="chat-msg-bubble">' + processedContent + '</div>';
        }
    } else if ((contentType === 'image' || contentType === '图片') && (mediaUrl || content)) {
        var rawImgContent = (mediaUrl || content).replace(/^`+|`+$/g, '').trim();
        var imgPart = parseMediaCaption(rawImgContent);
        if (!imgPart && rawImgContent.indexOf('[') === 0) {
            // 旧版二进制上传占位符: 原件未留存, 无法回放 (新版本已本地留存, 不再产生此类记录)
            html += '<div class="chat-msg-bubble"><div class="chat-media-fallback">🖼 图片（上传原件未留存）</div></div>';
        } else {
            var imgUrl = imgPart ? imgPart.url : rawImgContent;
            var imgCaption = imgPart ? imgPart.caption : '';
            html += '<div class="chat-msg-bubble" style="padding:4px;background:none;border:none;">';
            html += '<img class="chat-msg-image chat-media-clickable" src="' + escapeAttr(imgUrl) + '" onclick="openMediaViewer(\'image\',\'' + escapeAttr(imgUrl) + '\')" onerror="mediaImgError(this)" referrerpolicy="no-referrer">';
            html += '</div>';
            if (imgCaption) {
                var processedImgCaption = replaceAtMentions(escapeHtml(imgCaption));
                html += '<div class="chat-msg-bubble">' + processedImgCaption + '</div>';
            }
        }
    } else if ((contentType === 'video' || contentType === '视频') && (mediaUrl || content)) {
        var vidUrl = mediaUrl || content;
        html += '<div class="chat-msg-bubble" style="padding:4px;background:none;border:none;">';
        html += '<video class="chat-msg-video chat-media-clickable" preload="metadata" controls style="max-width:250px;max-height:200px;border-radius:4px;" onclick="openMediaViewer(\'video\',\'' + escapeAttr(vidUrl) + '\')">';
        html += '<source src="' + escapeAttr(vidUrl) + '">';
        html += '</video></div>';
    } else if ((contentType === 'voice' || contentType === '语音') && (mediaUrl || content)) {
        var audUrl = (mediaUrl || content).replace(/^`+|`+$/g, '').trim();
        html += '<div class="chat-msg-bubble" style="padding:10px 14px;min-width:200px;">';
        html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">';
        html += '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>';
        html += '<span style="font-size:13px;font-weight:500;">语音消息</span>';
        html += '<a href="' + escapeAttr(audUrl) + '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" style="margin-left:auto;font-size:11px;color:var(--text-muted);text-decoration:none;">下载</a>';
        html += '</div>';
        html += '<audio controls preload="metadata" src="' + escapeAttr(audUrl) + '" style="width:100%;max-width:300px;outline:none;"></audio>';
        html += '</div>';
    } else if ((contentType === 'file' || contentType === '文件') && (mediaUrl || content)) {
        var fileUrl = mediaUrl || content;
        // 统一文件名解析: API传入 > 日志括号内真实名 > fname/f 参数 > URL路径
        var fName = resolveFileName(fileName, fileUrl, content);
        // 使用带颜色的文件类型徽章
        var fileInfo = detectFileUrl(fileUrl);
        var iconHtml = fileInfo ? getFileTypeIcon(fileInfo.extension) : '<span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;background:#1565c0;color:#fff;font-size:10px;font-weight:700;flex-shrink:0;">FILE</span>';
        var extLabel = fileInfo ? fileInfo.extension.toUpperCase() : '';
        html += '<div class="chat-msg-bubble" style="padding:8px 12px;">';
        html += '<a href="' + escapeAttr(fileUrl) + '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit;">';
        html += iconHtml;
        html += '<div style="min-width:0;"><div style="font-weight:500;font-size:13px;word-break:break-all;">' + escapeHtml(fName) + '</div>';
        html += '<div style="font-size:11px;color:var(--text-muted);">点击下载' + (extLabel ? ' (' + escapeHtml(extLabel) + ')' : '') + '</div></div></a></div>';
    } else if (contentType === 'md' || contentType === 'markdown' || contentType === '引用MD') {
        html += '<div class="chat-msg-bubble chat-md-content">' + renderMarkdown(content) + '</div>';
    } else if (contentType === 'ark' || contentType === 'Ark') {
        // Ark 消息渲染
        html += '<div class="chat-msg-bubble" style="border-left:3px solid #1565c0;padding:10px 14px;min-width:200px;">';
        html += '<div style="font-size:11px;color:#1565c0;font-weight:600;margin-bottom:6px;">Ark消息</div>';
        // 尝试解析内容中的模板信息
        var arkContent = content;
        var arkData = null;
        try {
            // content 可能是 "模板: XX" 或 JSON
            if (arkContent.indexOf('{') === 0) {
                arkData = JSON.parse(arkContent);
            }
        } catch(e) {}
        if (arkData && arkData.template_id) {
            html += '<div style="font-size:12px;margin-bottom:6px;color:var(--text-muted);">模板: ' + escapeHtml(arkData.template_id) + '</div>';
            if (arkData.kv && Array.isArray(arkData.kv)) {
                html += '<div style="font-size:13px;">';
                arkData.kv.forEach(function(item) {
                    if (item.key && item.value) {
                        var val = String(item.value);
                        // 如果值是图片URL，显示图片
                        if (val.indexOf('http') === 0 && /\.(jpg|jpeg|png|gif|webp)/i.test(val)) {
                            html += '<div style="margin:6px 0;"><img src="' + escapeAttr(val.replace(/`/g, '')) + '" style="max-width:100%;border-radius:6px;" referrerpolicy="no-referrer" onerror="this.style.display=\'none\'"></div>';
                        } else if (val.indexOf('http') === 0) {
                            html += '<div style="margin:3px 0;"><span style="color:var(--text-muted);font-size:11px;">' + escapeHtml(item.key) + ':</span> <a href="' + escapeAttr(val.replace(/`/g, '')) + '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" style="color:#1565c0;text-decoration:none;word-break:break-all;">' + escapeHtml(val) + '</a></div>';
                        } else {
                            html += '<div style="margin:3px 0;"><span style="color:var(--text-muted);font-size:11px;">' + escapeHtml(item.key) + ':</span> ' + escapeHtml(val) + '</div>';
                        }
                    }
                });
                html += '</div>';
            }
        } else {
            html += '<div style="font-size:12px;word-break:break-all;">' + escapeHtml(arkContent) + '</div>';
        }
        html += '</div>';
    } else if (contentType === '图文卡片' || contentType === 'embed') {
        // 图文卡片消息渲染
        var tuwenContent = content;
        var tuwenData = null;
        try {
            if (tuwenContent.indexOf('{') === 0) {
                tuwenData = JSON.parse(tuwenContent);
            }
        } catch(e) {}
        html += '<div class="chat-msg-bubble" style="padding:0;overflow:hidden;max-width:300px;border-radius:8px;">';
        if (tuwenData) {
            if (tuwenData.pic_url) {
                html += '<img src="' + escapeAttr(String(tuwenData.pic_url).replace(/`/g, '')) + '" style="width:100%;height:150px;object-fit:cover;" referrerpolicy="no-referrer" onerror="this.style.display=\'none\'">';
            }
            html += '<div style="padding:10px 12px;">';
            if (tuwenData.title) html += '<div style="font-size:14px;font-weight:600;margin-bottom:4px;">' + escapeHtml(tuwenData.title) + '</div>';
            if (tuwenData.description) html += '<div style="font-size:12px;color:var(--text-secondary);line-height:1.4;">' + escapeHtml(tuwenData.description) + '</div>';
            if (tuwenData.url) {
                html += '<a href="' + escapeAttr(String(tuwenData.url).replace(/`/g, '')) + '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" style="display:inline-block;margin-top:8px;font-size:12px;color:#1565c0;text-decoration:none;">查看详情 →</a>';
            }
            html += '</div>';
        } else {
            html += '<div style="padding:10px 12px;font-size:12px;word-break:break-all;">' + escapeHtml(tuwenContent) + '</div>';
        }
        html += '</div>';
    } else {
        // 文本内容：先检查是否为文件URL（如 ftn.qq.com 的文件链接），再按纯文本渲染
        var trimmedText = (content || '').trim();
        var isPureUrl = /^https?:\/\/\S+$/i.test(trimmedText);
        if (isPureUrl) {
            var fileInfo = detectFileUrl(trimmedText);
            if (fileInfo && fileInfo.type !== 'link') {
                // 视频/音频/图片/文件 → 使用对应渲染器
                html += renderFileContent(trimmedText, fileInfo);
            } else {
                // 普通URL → 可点击链接
                html += '<div class="chat-msg-bubble"><a href="' + escapeAttr(trimmedText) + '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" style="color:inherit;text-decoration:underline;word-break:break-all;">' + escapeHtml(trimmedText) + '</a></div>';
            }
        } else {
            // 普通文本（处理@提及）
            var processedContent = escapeHtml(content);
            processedContent = replaceAtMentions(processedContent);
            html += '<div class="chat-msg-bubble">' + processedContent + '</div>';
        }
    }

    html += '<div class="chat-msg-time">' + escapeHtml(m.created_at || '') + '</div>';

    // 已撤回标记（在消息内容下方显示，不覆盖原消息）
    if (m.is_retracted) {
        html += '<div class="chat-msg-retracted"><span class="chat-retracted-icon">&#128683;</span> 此消息已被撤回</div>';
    }

    // 操作按钮
    html += '<div class="chat-msg-actions">';
    html += '<button class="chat-msg-action-btn" onclick="quoteMsg(this)">引用</button>';
    // 已撤回的消息不再显示撤回按钮
    if (!m.is_retracted) {
        html += '<button class="chat-msg-action-btn" onclick="retractMsg(\'' + escapeAttr(m.appid || botId || '') + '\',\'' + escapeAttr(m.msg_id || '') + '\',\'' + escapeAttr(m.target_id || '') + '\',' + (parseInt(m.id, 10) || 0) + ')">撤回</button>';
    }
    // 群聊接收消息显示禁言/解禁按钮
    if (!isSent && m.user_id && currentSession && currentSession.source_type === '群聊') {
        html += '<button class="chat-msg-action-btn" onclick="quickMuteMember(this)" data-user-id="' + escapeAttr(m.user_id) + '" data-target-id="' + escapeAttr(m.target_id || '') + '">禁言</button>';
        html += '<button class="chat-msg-action-btn" onclick="quickUnmuteMember(this)" data-user-id="' + escapeAttr(m.user_id) + '" data-target-id="' + escapeAttr(m.target_id || '') + '">解禁</button>';
    }
    html += '<button class="chat-msg-action-btn" onclick="copyText(this)" data-text="' + escapeAttr(m.content || '') + '">复制</button>';
    html += '</div>';

    html += '</div></div>';
    return html;
}

// ==================== 引用消息（简化操作，和撤回一样简单） ====================
function quoteMsg(btn) {
    var msgEl = btn.closest('.chat-msg');
    if (!msgEl) return;
    var msgData = msgEl.getAttribute('data-msg-data');
    if (!msgData) return;
    try {
        currentQuoteMsg = JSON.parse(msgData);
    } catch(e) {
        currentQuoteMsg = null;
        return;
    }
    // 引用这条消息应使用它自身的索引: msg_idx (本条) > ref_idx (兼容旧数据) > msg_id
    var quoteId = currentQuoteMsg.msg_idx || currentQuoteMsg.ref_idx || currentQuoteMsg.msg_id || '';
    if (!quoteId) {
        alert('该消息没有可引用的ID');
        return;
    }
    currentQuoteMsg.quote_ref_id = quoteId;
    var preview = document.getElementById('chatQuotePreview');
    var content = document.getElementById('chatQuoteContent');
    var previewText = currentQuoteMsg.content || '';
    if (previewText.length > 60) previewText = previewText.substring(0, 60) + '...';
    // 被引用消息带图片附件时，预览行内显示缩略图（引用发图场景更直观）
    var thumbHtml = '';
    if (Array.isArray(currentQuoteMsg.attachments)) {
        for (var i = 0; i < currentQuoteMsg.attachments.length; i++) {
            if (currentQuoteMsg.attachments[i] && currentQuoteMsg.attachments[i].type === 'image' && currentQuoteMsg.attachments[i].url) {
                thumbHtml = '<img class="chat-quote-preview-thumb" src="' + escapeAttr(currentQuoteMsg.attachments[i].url) + '" onerror="this.style.display=\'none\'">';
                break;
            }
        }
    }
    content.innerHTML = thumbHtml + escapeHtml(currentQuoteMsg.sender_name + ': ' + previewText);
    preview.classList.add('show');
    document.getElementById('chatInputMsg').focus();
}

function cancelQuote() {
    currentQuoteMsg = null;
    document.getElementById('chatQuotePreview').classList.remove('show');
}

// ==================== @提及替换函数 ====================
// 处理已HTML转义后的内容中的@提及标签
// 支持两种格式：
// 1. &lt;qqbot-at-user id="USERID" /&gt; （QQ Bot XML标签格式，转义后id的引号变为&quot;）
// 2. &lt;@USERID&gt; （简单@提及格式）
function getMentionDisplay(userId) {
    var cachedNick = nicknameCache[userId] || '';
    // 清理不可见字符
    cachedNick = cachedNick.replace(/[\u00AD\u061C\u180E\u200B-\u200F\u202A-\u202E\u2060-\u2069\uFEFF\uFFA0]/g, '').trim();
    if (cachedNick) {
        return cachedNick;
    }
    // 回退：显示缩短的ID
    return userId.length > 8 ? userId.substring(0, 8) + '...' : userId;
}

function replaceAtMentions(escapedHtml) {
    // 处理 <qqbot-at-user id="..." /> 格式
    // escapeHtml将"转为&quot;，所以正则需匹配 &quot;
    escapedHtml = escapedHtml.replace(/&lt;qqbot-at-user\s+id=&quot;([^&]*)&quot;\s*\/?&gt;/g, function(match, userId) {
        var nick = getMentionDisplay(userId);
        return '<span class="chat-at-user">@' + escapeHtml(nick) + '</span>';
    });
    // 群聊 @全体成员（官方 20261026）：<qqbot-at-everyone />
    escapedHtml = escapedHtml.replace(/&lt;qqbot-at-everyone\s*\/?&gt;/gi, '<span class="chat-at-user everyone">@全体成员</span>');
    // 处理 <@USERID> 格式（转义后为 &lt;@USERID&gt;）
    // USERID 通常是32位十六进制字符串
    escapedHtml = escapedHtml.replace(/&lt;@([A-Fa-f0-9]{16,})&gt;/g, function(match, userId) {
        var nick = getMentionDisplay(userId);
        return '<span class="chat-at-user">@' + escapeHtml(nick) + '</span>';
    });
    return escapedHtml;
}

// ==================== 简易Markdown渲染 ====================
// 支持: 代码块/行内代码(占位符保护不被二次加工)/标题/引用/粗体/斜体/删除线/
//       图片![alt](url)(含QQ尺寸参数 #30px #30px, 点击看大图, 失败占位)/
//       链接(http正常跳转; mqqapi://等伪协议渲染为按钮chip+点击提示)/
//       有无序列表/分割线/@提及(<qqbot-at-user>、<@id>、[@昵称]三种形态)
function renderMarkdown(md) {
    if (!md) return '';
    var html = escapeHtml(md);

    // 0. 保护代码内容(块/行内), 占位符统一在末尾还原
    var codeStore = [];
    html = html.replace(/```(\w*)\n?([\s\S]*?)```/g, function(match, lang, code) {
        codeStore.push('<pre><code>' + code.replace(/\n$/, '') + '</code></pre>');
        return '\uE000C' + (codeStore.length - 1) + '\uE000';
    });
    html = html.replace(/`([^`\n]+)`/g, function(match, code) {
        codeStore.push('<code>' + code + '</code>');
        return '\uE000C' + (codeStore.length - 1) + '\uE000';
    });

    // 标题
    html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
    html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
    html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');

    // 引用块
    html = html.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');
    html = html.replace(/(<blockquote>[\s\S]*?<\/blockquote>)/g, function(m) {
        return m.replace(/<\/blockquote>\n<blockquote>/g, '\n');
    });

    // 粗体、斜体
    html = html.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');

    // 删除线
    html = html.replace(/~~(.+?)~~/g, '<del>$1</del>');

    // 图片 ![alt](url) — 必须在链接规则之前, 否则被当普通链接渲染成文字
    // 容忍换行拆断: QQ md 卡片的 alt 与 URL 之间、URL 内部可能被换行拆开(采集端折行/拼接),
    // alt 与 url 间的空白由 \s* 容忍; url 捕获后去除所有空白(URL 不含合法空白字符)
    // QQ特有: alt 可为尺寸参数 "#30px #30px"(宽 高), 转为固定显示尺寸
    html = html.replace(/!\[([^\]\n]*)\]\s*\(\s*([^)]*?)\s*\)/g, function(match, alt, url) {
        url = url.replace(/\s+/g, '');
        // 素材引用 qqbot-media:// 仅 QQ 客户端可解析，后台显示为占位条
        if (/^qqbot-media:\/\//i.test(url)) {
            var altText = String(alt || '').replace(/#\d+(?:\.\d+)?px/gi, '').trim();
            return '<span class="chat-md-media" title="' + escapeAttr(url) + '">'
                 + '<b>素材图片</b>' + (altText ? ' · ' + escapeHtml(altText) : '') + ' · 在 QQ 内可见</span>';
        }
        if (!url || !/^(https?:\/\/|\/|api\/)/i.test(url)) return match;
        var style = '';
        var wh = [], re = /#(\d+(?:\.\d+)?)px/gi, m2;
        while ((m2 = re.exec(alt)) !== null) wh.push(parseFloat(m2[1]));
        if (wh.length >= 1) {
            var w = Math.min(Math.max(Math.round(wh[0]), 16), 240);
            style = ' style="width:' + w + 'px'
                 + (wh.length >= 2 ? ';height:' + Math.min(Math.max(Math.round(wh[1]), 16), 240) + 'px' : '')
                 + ';object-fit:cover"';
        }
        return '<img class="chat-md-img" src="' + url + '" alt="图片" loading="lazy"' + style
             + ' title="点击查看大图"'
             + ' onclick="openMediaViewer(\'image\', this.src)"'
             + ' onerror="mediaImgError(this, \'图片加载失败或已过期\')">';
    });

    // @提及: [@昵称] 形态(QQ markdown 消息常用), 须在链接规则之前
    html = html.replace(/\[@([^\[\]\n]{1,64})\]/g, '<span class="chat-at-user">@$1</span>');

    // 链接 [text](url) — http(s)/mailto 正常跳转; mqqapi:// 等伪协议渲染为按钮样式(仅QQ客户端可点)
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(match, text, url) {
        if (/^(https?:)?\/\//i.test(url) || /^mailto:/i.test(url)) {
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + text + '</a>';
        }
        return '<span class="chat-md-btn" role="button" tabindex="0"'
             + ' title="QQ客户端内联按钮, 需在QQ中点击生效"'
             + ' onclick="showMdBtnTip()">' + text + '</span>';
    });

    // 无序列表
    html = html.replace(/^[-*] (.+)$/gm, '<li>$1</li>');
    html = html.replace(/(<li>[\s\S]*?<\/li>)/g, function(m) {
        var items = m.split(/<\/li>\s*(?=<li>)/).join('</li>');
        return '<ul>' + items + '</ul>';
    });
    html = html.replace(/<\/ul>\s*<ul>/g, '');

    // 有序列表
    html = html.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');

    // 分割线
    html = html.replace(/^---+$/gm, '<hr>');

    // @提及标签替换
    html = html.replace(/&lt;qqbot-at-user id="([^"]*)" \/&gt;/g, function(match, userId) {
        var nick = getMentionDisplay(userId);
        return '<span class="chat-at-user">@' + escapeHtml(nick) + '</span>';
    });

    // 通用@提及替换
    html = replaceAtMentions(html);

    // 换行
    html = html.replace(/\n/g, '<br>');

    // 清理多余的列表嵌套
    html = html.replace(/<br>(<li>)/g, '$1');
    html = html.replace(/(<\/li>)<br>/g, '$1');

    // 还原代码内容
    html = html.replace(/\uE000C(\d+)\uE000/g, function(m, i) {
        return typeof codeStore[+i] !== 'undefined' ? codeStore[+i] : m;
    });

    return html;
}

// MD伪协议按钮(mqqapi://等)点击提示: 浏览器无法打开QQ内部协议
function showMdBtnTip() {
    var t = document.getElementById('mdBtnTip');
    if (!t) {
        t = document.createElement('div');
        t.id = 'mdBtnTip';
        t.setAttribute('role', 'status');
        document.body.appendChild(t);
    }
    t.textContent = '该按钮为QQ客户端内联按钮，需在QQ中点击生效';
    t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(function() { t.classList.remove('show'); }, 1800);
}

// ==================== 发送消息 ====================
function sendChatMessage(evt) {
    var botId = getCurrentBotId();
    if (!botId) { alert('请先在左侧选择机器人'); return; }
    if (!currentSession) { alert('请先选择会话'); return; }

    // Ark 类型：打开Ark编辑器模态框
    if (currentSendType === 'ark') {
        showArkModal();
        return;
    }

    // 图文卡片类型：打开图文卡片编辑器
    if (currentSendType === 'tuwen') {
        showTuwenModal();
        return;
    }

    var msg = document.getElementById('chatInputMsg').value.trim();
    if (!msg && currentSendType !== 'image') { alert('请输入消息'); return; }

    // 如果消息中包含@提及标签，自动使用MD格式发送（仅纯文字模式，图片/MD模式不劫持）
    var effectiveSendType = currentSendType;
    if (currentSendType === 'text' && (msg.indexOf('<qqbot-at-user') > -1 || msg.indexOf('<qqbot-at-everyone') > -1)) {
        effectiveSendType = 'md';
    }

    // @全体成员是不可撤回的高影响操作：每个群每天仅 1 次，发送前二次确认
    if (/<qqbot-at-everyone\s*\/?>/i.test(msg)) {
        if ((currentSession.source_type || '群聊') !== '群聊') {
            alert('@全体成员仅支持群聊会话。');
            return;
        }
        if (!confirm('这条消息会 @全体成员。\n\n平台限制：机器人需为群管理员，且每个群每天只能发送 1 次。\n确认发送？')) {
            return;
        }
    }

    var btn = evt ? evt.currentTarget : document.querySelector('.chat-input-area .btn-primary');

    var data = {
        appid: botId,
        target_id: currentSession.target_id,
        source_type: currentSession.source_type || '群聊',
        msg_type: effectiveSendType,
        message: msg
    };

    // 官方 force_verify_image_resource：仅 MD 消息有效
    var forceEl = document.getElementById('chatForceVerify');
    if (effectiveSendType === 'md' && forceEl && forceEl.checked) {
        data.force_verify = 1;
    }

    // 图片模式：弹窗链接优先，其次输入框直接填URL；输入框其余文字作为配文一起发送
    if (effectiveSendType === 'image') {
        var imgUrl = pendingImageUrl || '';
        var caption = msg;
        if (!imgUrl && /^https?:\/\//i.test(msg)) {
            imgUrl = msg;   // 直接粘贴URL → 纯图片
            caption = '';
        }
        if (!imgUrl) {
            alert(caption
                ? '请先点击"图片"标签填写图片链接，或点击📎上传图片（输入框文字将作为配文一起发送）'
                : '请点击"图片"标签输入图片链接，直接粘贴URL，或点击📎上传图片');
            return;
        }
        data.message = imgUrl;
        if (caption) data.image_content = caption;   // 图文同发（msg_type=7 + media + content）
    }

    // 如果有引用，添加引用参数（按类型选择官方报文形态）
    if (currentQuoteMsg && currentQuoteMsg.quote_ref_id) {
        if (effectiveSendType === 'quote') {
            // 已是引用类型（不会走到这里，防御性保留）
            data.ref_msg_id = currentQuoteMsg.quote_ref_id;
        } else if (effectiveSendType === 'text') {
            // 文字引用: 官方 msg_type=0 + message_reference
            data.msg_type = 'quote';
            data.ref_msg_id = currentQuoteMsg.quote_ref_id;
            data.quote_content = msg;
        } else {
            // 图片→引用图片/引用图文，MD→引用MD，语音/视频/文件→引用富媒体
            // 统一保持原类型 + ref_msg_id，由后端选择对应函数
            data.ref_msg_id = currentQuoteMsg.quote_ref_id;
        }
    }

    btn.disabled = true;
    var origText = btn.textContent;
    btn.textContent = '发送中...';

    apiCall('api/chat_api.php?action=send', data, function(res) {
        btn.disabled = false;
        btn.textContent = origText;
        if (res.success) {
            document.getElementById('chatInputMsg').value = '';
            hideMentionPopup();
            cancelQuote();
            clearPendingImageUrl();
            // 轻量刷新：只追加新消息，不重新加载整个消息列表
            refreshAfterSend();
        } else {
            alert(res.message || '发送失败');
        }
    });
}

// ==================== 文件上传发送 ====================
function triggerFileUpload() {
    if (!currentSession) { alert('请先选择会话'); return; }
    if (!getCurrentBotId()) { alert('请先选择机器人'); return; }

    var fileInput = document.getElementById('chatFileInput');
    // 根据当前发送类型设置 accept
    var acceptMap = {
        'image': 'image/*',
        'voice': 'audio/*',
        'video': 'video/*',
        'file': '*/*',
        'text': '*/*',
        'md': '*/*',
        'tuwen': '*/*'
    };
    fileInput.setAttribute('accept', acceptMap[currentSendType] || '*/*');
    fileInput.value = '';
    fileInput.click();
}

function handleFileUpload(input) {
    if (!input.files || input.files.length === 0) return;

    var file = input.files[0];
    var botId = getCurrentBotId();
    if (!botId || !currentSession) return;

    // 文件大小限制 (512MB; >1.5MB 自动走分片上传, 可发送大文件)
    var maxSize = 512 * 1024 * 1024;
    if (file.size > maxSize) {
        alert('文件大小不能超过512MB');
        return;
    }

    // 根据文件类型或当前发送类型判断上传类型
    var uploadType = currentSendType;
    if (uploadType === 'text' || uploadType === 'md' || uploadType === 'tuwen') {
        // 自动推断类型
        if (file.type.startsWith('image/')) uploadType = 'image';
        else if (file.type.startsWith('video/')) uploadType = 'video';
        else if (file.type.startsWith('audio/')) uploadType = 'voice';
        else uploadType = 'file';
    }

    // 检查文件类型是否匹配
    if (uploadType === 'image' && !file.type.startsWith('image/')) {
        if (!confirm('当前选择图片发送模式，但文件不是图片。是否仍要发送？')) return;
        uploadType = 'file';
    }

    // 显示文件预览模态框，让用户确认后再发送
    showFilePreviewModal(file, uploadType);
}

// ==================== 文件预览模态框 ====================
var pendingFile = null;
var pendingUploadType = null;

function showFilePreviewModal(file, uploadType) {
    pendingFile = file;
    pendingUploadType = uploadType;

    var modal = document.getElementById('filePreviewModal');
    var previewArea = document.getElementById('filePreviewArea');
    var fileInfo = document.getElementById('filePreviewInfo');
    var textRow = document.getElementById('filePreviewTextRow');

    previewArea.innerHTML = '';
    var sizeStr = (file.size / 1024).toFixed(1) + ' KB';
    if (file.size > 1024 * 1024) sizeStr = (file.size / 1024 / 1024).toFixed(1) + ' MB';

    // 根据文件类型显示预览
    if (file.type.startsWith('image/')) {
        var reader = new FileReader();
        reader.onload = function(e) {
            previewArea.innerHTML = '<img src="' + e.target.result + '" style="max-width:100%;max-height:300px;border-radius:8px;">';
        };
        reader.readAsDataURL(file);
        fileInfo.textContent = file.name + ' · ' + sizeStr + ' · 图片';
        // 图片类型显示文字输入框（支持图片+文字）
        textRow.style.display = 'block';
    } else if (file.type.startsWith('video/')) {
        var reader2 = new FileReader();
        reader2.onload = function(e) {
            previewArea.innerHTML = '<video controls style="max-width:100%;max-height:300px;border-radius:8px;"><source src="' + e.target.result + '"></video>';
        };
        reader2.readAsDataURL(file);
        fileInfo.textContent = file.name + ' · ' + sizeStr + ' · 视频';
        textRow.style.display = 'none';
    } else if (file.type.startsWith('audio/')) {
        var reader3 = new FileReader();
        reader3.onload = function(e) {
            previewArea.innerHTML = '<audio controls style="width:100%;"><source src="' + e.target.result + '"></audio>';
        };
        reader3.readAsDataURL(file);
        fileInfo.textContent = file.name + ' · ' + sizeStr + ' · 语音';
        textRow.style.display = 'none';
    } else {
        // 其他文件类型
        var ext = file.name.split('.').pop().toUpperCase();
        previewArea.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;gap:12px;padding:24px;background:var(--bg-secondary);border-radius:8px;">' +
            '<span style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:8px;background:#1565c0;color:#fff;font-size:14px;font-weight:700;">' + ext + '</span>' +
            '<span style="font-size:14px;color:var(--text-muted);">点击下方按钮发送文件</span></div>';
        fileInfo.textContent = file.name + ' · ' + sizeStr + ' · 文件';
        textRow.style.display = 'none';
    }

    modal.style.display = 'flex';
}

function closeFilePreviewModal() {
    document.getElementById('filePreviewModal').style.display = 'none';
    pendingFile = null;
    pendingUploadType = null;
}

function confirmFileSend() {
    if (!pendingFile) return;

    var file = pendingFile;
    var uploadType = pendingUploadType;
    var botId = getCurrentBotId();
    if (!botId || !currentSession) return;

    // 大文件分片上传: >1.5MB 自动分片(服务器 upload_max_filesize 通常 2M, 1MB/片安全)
    var CHUNK_THRESHOLD = 1.5 * 1024 * 1024;
    if (file.size > CHUNK_THRESHOLD) {
        // 采集与整包上传一致的附加参数后进入分片流程
        var refId = (currentQuoteMsg && currentQuoteMsg.quote_ref_id) ? currentQuoteMsg.quote_ref_id : '';
        var txt = '';
        if (uploadType === 'image') {
            txt = document.getElementById('filePreviewText').value.trim();
            if (!txt) txt = document.getElementById('chatInputMsg').value.trim();
        }
        closeFilePreviewModal();
        sendFileInChunks(file, uploadType, refId, txt);
        return;
    }

    var formData = new FormData();
    formData.append('action', 'send');
    formData.append('appid', botId);
    formData.append('target_id', currentSession.target_id);
    formData.append('source_type', currentSession.source_type || '群聊');
    formData.append('msg_type', uploadType);
    formData.append('upload_type', uploadType);
    formData.append('upload_file', file, file.name);

    // 有引用时附带引用锚点: 图片→引用图片/引用图文 (官方 message_reference)
    if (currentQuoteMsg && currentQuoteMsg.quote_ref_id) {
        formData.append('ref_msg_id', currentQuoteMsg.quote_ref_id);
    }

    // 图片类型支持附带文字
    var textContent = '';
    if (uploadType === 'image') {
        textContent = document.getElementById('filePreviewText').value.trim();
        if (textContent) {
            formData.append('text_content', textContent);
        }
    }
    // 同时检查主输入框的文字
    var mainText = document.getElementById('chatInputMsg').value.trim();
    if (mainText && uploadType === 'image' && !textContent) {
        formData.append('text_content', mainText);
    }

    // 关闭预览框
    closeFilePreviewModal();

    // 显示发送状态
    var sendBtn = document.querySelector('.chat-input-area .btn-primary');
    var origText = sendBtn.textContent;
    sendBtn.disabled = true;
    sendBtn.textContent = '上传中...';

    // 构建带认证token的URL
    var uploadUrl = 'api/chat_api.php?action=send';
    if (authToken) {
        uploadUrl += '&token=' + encodeURIComponent(authToken);
    }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', uploadUrl);

    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            sendBtn.disabled = false;
            sendBtn.textContent = origText;
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success || res.code === 0) {
                    document.getElementById('chatInputMsg').value = '';
                    // 轻量刷新：只追加新消息，不重新加载整个消息列表
                    refreshAfterSend();
                } else {
                    alert(res.message || res.msg || '发送失败');
                }
            } catch(e) {
                var errSnippet = xhr.responseText ? xhr.responseText.substring(0, 300) : '(空响应)';
                alert('发送失败: 服务器返回异常\n\nHTTP状态: ' + xhr.status + '\n响应内容: ' + errSnippet);
            }
        }
    };

    xhr.ontimeout = function() {
        sendBtn.disabled = false;
        sendBtn.textContent = origText;
        alert('上传超时，请检查网络连接或减小文件大小');
    };

    xhr.onerror = function() {
        sendBtn.disabled = false;
        sendBtn.textContent = origText;
        alert('网络错误，请检查服务器状态');
    };

    xhr.timeout = 60000;
    xhr.send(formData);
}

// ==================== 分片上传发送 (>1.5MB 大文件) ====================
// 逐片 POST upload_chunk (1MB/片, 覆盖写幂等可重试), 全部完成后 upload_complete 由后端
// 流式合并、留存到 data/uploads/ 并走与整包上传相同的发送链路(官方 /files 接口)
var CHUNK_SIZE = 1024 * 1024; // 1MB/片 (服务器 upload_max_filesize 通常 2M, 含表单开销留余量)

function sendFileInChunks(file, uploadType, refMsgId, textContent) {
    var botId = getCurrentBotId();
    if (!botId || !currentSession) return;

    var total = Math.ceil(file.size / CHUNK_SIZE);
    var uploadId = '';
    for (var i = 0; i < 32; i++) uploadId += Math.floor(Math.random() * 16).toString(16);

    var sendBtn = document.querySelector('.chat-input-area .btn-primary');
    var origText = sendBtn.textContent;
    sendBtn.disabled = true;

    var apiUrl = 'api/chat_api.php';
    if (authToken) apiUrl += '?token=' + encodeURIComponent(authToken);

    // 上传结束统一恢复按钮
    function done() {
        sendBtn.disabled = false;
        sendBtn.textContent = origText;
    }

    // 阶段2: 全部分片完成后通知后端合并并发送
    function completeUpload() {
        sendBtn.textContent = '发送中...';
        var fd = new FormData();
        fd.append('action', 'upload_complete');
        fd.append('appid', botId);
        fd.append('target_id', currentSession.target_id);
        fd.append('source_type', currentSession.source_type || '群聊');
        fd.append('upload_id', uploadId);
        fd.append('chunk_total', total);
        fd.append('file_name', file.name);
        fd.append('file_size', file.size);
        fd.append('upload_type', uploadType);
        fd.append('msg_type', uploadType);
        if (refMsgId) fd.append('ref_msg_id', refMsgId);
        if (textContent) fd.append('text_content', textContent);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', apiUrl);
        xhr.timeout = 300000; // 大文件经官方 /files 接口发送耗时较长
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            done();
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success || res.code === 0) {
                    document.getElementById('chatInputMsg').value = '';
                    refreshAfterSend();
                } else {
                    alert(res.message || res.msg || '发送失败');
                }
            } catch(e) {
                var snippet = xhr.responseText ? xhr.responseText.substring(0, 300) : '(空响应)';
                alert('发送失败: 服务器返回异常\n\nHTTP状态: ' + xhr.status + '\n响应内容: ' + snippet);
            }
        };
        xhr.onerror = function() { done(); alert('网络错误，请检查服务器状态'); };
        xhr.ontimeout = function() { done(); alert('发送超时，文件已上传到服务器，请稍后在聊天记录中查看'); };
        xhr.send(fd);
    }

    // 阶段1: 逐片顺序上传(覆盖写, 失败可整体重试)
    function sendChunk(idx) {
        var pct = Math.min(100, Math.round((idx / total) * 100));
        sendBtn.textContent = '上传中 ' + pct + '%';
        var start = idx * CHUNK_SIZE;
        var blob = file.slice(start, Math.min(start + CHUNK_SIZE, file.size));
        var fd = new FormData();
        fd.append('action', 'upload_chunk');
        fd.append('appid', botId);
        fd.append('upload_id', uploadId);
        fd.append('chunk_index', idx);
        fd.append('chunk_total', total);
        fd.append('file_name', file.name);
        fd.append('chunk', blob, 'blob');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', apiUrl);
        xhr.timeout = 60000;
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success || res.code === 0) {
                    if (idx + 1 < total) {
                        sendChunk(idx + 1);
                    } else {
                        completeUpload();
                    }
                } else {
                    done();
                    alert(res.message || res.msg || ('分片上传失败(第 ' + (idx + 1) + '/' + total + ' 片)，请重试'));
                }
            } catch(e) {
                done();
                alert('分片上传失败(第 ' + (idx + 1) + '/' + total + ' 片): 服务器返回异常\n\nHTTP状态: ' + xhr.status);
            }
        };
        xhr.onerror = function() { done(); alert('分片上传网络错误(第 ' + (idx + 1) + '/' + total + ' 片)，请重试'); };
        xhr.ontimeout = function() { done(); alert('分片上传超时(第 ' + (idx + 1) + '/' + total + ' 片)，请重试'); };
        xhr.send(fd);
    }

    sendChunk(0);
}

// ==================== 撤回消息 ====================
// rowId 为消息行主键(messages.id): 同一会话中 QQ 的 message_id 可能为空/重复,
// 按 message_id 定位会误标多条消息, 优先传主键精确定位
function retractMsg(appid, msgId, targetId, rowId) {
    if (!confirm('确定要撤回这条消息吗？')) return;
    var sourceType = (currentSession && currentSession.source_type) ? currentSession.source_type : '群聊';
    var payload = {
        appid: appid,
        msg_id: msgId,
        target_id: targetId,
        source_type: sourceType
    };
    if (rowId) payload.row_id = rowId;
    apiCall('api/chat_api.php?action=retract', payload, function(res) {
        if (res.success) {
            alert('撤回成功');
            // 直接在DOM中更新被撤回的消息，不重新加载整个消息列表
            var container = document.getElementById('chatMessages');
            var msgElements = container.querySelectorAll('.chat-msg[data-msg-id]');
            var updated = false;
            msgElements.forEach(function(el) {
                if (updated) return;
                try {
                    var msgData = JSON.parse(el.getAttribute('data-msg-data') || '{}');
                    // 优先按行主键匹配；无主键时按 msg_id 匹配（避免空/重复 msg_id 误标多条）
                    var match = false;
                    if (rowId) {
                        match = (parseInt(msgData.id, 10) === parseInt(rowId, 10));
                    } else if (msgId) {
                        match = (msgData.msg_id === msgId && msgId !== '');
                    }
                    if (match) {
                        // 标记消息为已撤回状态（添加CSS类，使气泡半透明+删除线）
                        el.classList.add('is-retracted');
                        // 更新 data-msg-data 中的 is_retracted 标记
                        msgData.is_retracted = 1;
                        el.setAttribute('data-msg-data', escapeAttr(JSON.stringify(msgData)));
                        // 在时间后面插入"已撤回"标记（如果尚未存在）
                        var existingRetracted = el.querySelector('.chat-msg-retracted');
                        if (!existingRetracted) {
                            var timeEl = el.querySelector('.chat-msg-time');
                            if (timeEl) {
                                var retractedDiv = document.createElement('div');
                                retractedDiv.className = 'chat-msg-retracted';
                                retractedDiv.innerHTML = '<span class="chat-retracted-icon">&#128683;</span> 此消息已被撤回';
                                timeEl.insertAdjacentElement('afterend', retractedDiv);
                            }
                        }
                        // 移除撤回按钮（已撤回的消息不再显示撤回按钮）
                        var retractBtn = null;
                        el.querySelectorAll('.chat-msg-action-btn').forEach(function(btn) {
                            if (btn.textContent === '撤回') retractBtn = btn;
                        });
                        if (retractBtn) retractBtn.remove();
                        updated = true;
                    }
                } catch(e) {}
            });
            // 如果DOM更新失败，回退到轻量刷新
            if (!updated && currentSession) {
                refreshAfterSend();
            }
        } else {
            alert(res.message || '撤回失败');
        }
    });
}

function retractLastMsg() {
    if (!currentSession) return;
    var botId = getCurrentBotId();
    if (!botId) { alert('请先选择机器人'); return; }
    apiCall('api/chat_api.php?action=retract_last', {
        appid: botId,
        target_id: currentSession.target_id
    }, function(res) {
        if (res.success) {
            alert('撤回成功');
            // 轻量刷新，不重新加载整个消息列表
            refreshAfterSend();
        } else {
            alert(res.message || '撤回失败');
        }
    });
}

// ==================== 禁言管理 ====================

function showMuteModal() {
    if (!currentSession) return;
    if (currentSession.source_type !== '群聊') {
        alert('禁言管理仅支持群聊会话');
        return;
    }
    document.getElementById('muteModal').style.display = 'flex';
    // 自动加载禁言状态
    loadMuteStatus();
}

function closeMuteModal() {
    document.getElementById('muteModal').style.display = 'none';
}

// 获取禁言时长（秒）
function getMuteDuration(selectId, customId) {
    var sel = document.getElementById(selectId);
    var val = sel ? sel.value : '3600';
    if (val === 'custom') {
        var customInput = document.getElementById(customId);
        if (customInput) customInput.style.display = 'block';
        return null; // 返回null表示需要自定义输入
    }
    var customInput = document.getElementById(customId);
    if (customInput) customInput.style.display = 'none';
    return parseInt(val);
}

// 监听下拉框变化
document.getElementById('muteDurationSelect').addEventListener('change', function() {
    var customInput = document.getElementById('muteDurationCustom');
    if (this.value === 'custom') {
        customInput.style.display = 'block';
    } else {
        customInput.style.display = 'none';
    }
});

// 禁言单个成员
function doMuteMember() {
    if (!currentSession) return;
    var botId = getCurrentBotId();
    if (!botId) { alert('请先选择机器人'); return; }
    var memberOpenid = document.getElementById('muteMemberInput').value.trim();
    if (!memberOpenid) { alert('请输入成员 openid'); return; }

    var seconds = getMuteDuration('muteDurationSelect', 'muteDurationCustom');
    if (seconds === null) {
        var customVal = document.getElementById('muteDurationCustom').value.trim();
        if (!customVal) { alert('请输入自定义时长'); return; }
        // 传递时长字符串让后端解析
        apiCall('api/chat_api.php?action=mute_member', {
            appid: botId,
            group_openid: currentSession.target_id,
            member_openid: memberOpenid,
            duration: customVal
        }, muteCallback);
        return;
    }
    apiCall('api/chat_api.php?action=mute_member', {
        appid: botId,
        group_openid: currentSession.target_id,
        member_openid: memberOpenid,
        seconds: seconds
    }, muteCallback);
}

// 解禁单个成员
function doUnmuteMember() {
    if (!currentSession) return;
    var botId = getCurrentBotId();
    if (!botId) { alert('请先选择机器人'); return; }
    var memberOpenid = document.getElementById('muteMemberInput').value.trim();
    if (!memberOpenid) { alert('请输入成员 openid'); return; }
    apiCall('api/chat_api.php?action=unmute_member', {
        appid: botId,
        group_openid: currentSession.target_id,
        member_openid: memberOpenid
    }, muteCallback);
}

// 批量禁言
function doBatchMute() {
    if (!currentSession) return;
    var botId = getCurrentBotId();
    if (!botId) { alert('请先选择机器人'); return; }
    var text = document.getElementById('batchMuteInput').value.trim();
    if (!text) { alert('请输入成员 openid'); return; }
    var ids = text.split('\n').map(function(s) { return s.trim(); }).filter(function(s) { return s; });
    if (ids.length === 0) { alert('请输入至少一个成员 openid'); return; }
    if (ids.length > 10) { alert('单次最多禁言10人'); return; }
    var seconds = parseInt(document.getElementById('batchMuteDurationSelect').value) || 3600;
    apiCall('api/chat_api.php?action=batch_mute', {
        appid: botId,
        group_openid: currentSession.target_id,
        member_openids: JSON.stringify(ids),
        seconds: seconds
    }, muteCallback);
}

// 禁言操作回调
function muteCallback(res) {
    if (res.success) {
        alert(res.message || '操作成功');
        loadMuteStatus();
    } else {
        alert(res.message || '操作失败');
    }
}

// 加载禁言状态
function loadMuteStatus() {
    if (!currentSession) return;
    var botId = getCurrentBotId();
    if (!botId) { alert('请先选择机器人'); return; }
    var listEl = document.getElementById('muteStatusList');
    listEl.innerHTML = '<div style="color:var(--text-secondary); padding:8px;">加载中...</div>';
    apiCall('api/chat_api.php?action=query_mute', {
        appid: botId,
        group_openid: currentSession.target_id
    }, function(res) {
        if (res.success && res.data) {
            var data = res.data;
            var html = '';
            // 全员禁言状态
            if (data.global_rule) {
                var mode = data.global_rule.mode || 'none';
                var modeText = { 'none': '未开启', 'all': '全员禁言' }[mode] || mode;
                html += '<div style="padding:6px 8px; border-bottom:1px solid var(--border);"><strong>全员禁言:</strong> ' + escapeHtml(modeText) + '</div>';
            }
            // 成员禁言列表
            var members = data.members || [];
            if (members.length === 0) {
                html += '<div style="color:var(--text-secondary); padding:8px;">暂无被禁言的成员</div>';
            } else {
                members.forEach(function(m) {
                    var oid = m.member_openid || m.openid || '未知';
                    var expire = m.mute_expire_at || '';
                    html += '<div style="padding:6px 8px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">';
                    html += '<span style="word-break:break-all;">' + escapeHtml(oid) + (expire ? ' <span style="color:var(--text-secondary); font-size:11px;">至 ' + escapeHtml(expire) + '</span>' : '') + '</span>';
                    html += '<button class="btn btn-outline btn-sm" style="padding:2px 8px; font-size:11px;" onclick="unmuteFromList(\'' + escapeAttr(oid) + '\')">解禁</button>';
                    html += '</div>';
                });
            }
            listEl.innerHTML = html;
        } else {
            listEl.innerHTML = '<div style="color:var(--danger); padding:8px;">' + escapeHtml(res.message || '查询失败') + '</div>';
        }
    });
}

// 从禁言列表中解禁
function unmuteFromList(memberOpenid) {
    if (!currentSession) return;
    var botId = getCurrentBotId();
    if (!botId) return;
    if (!confirm('确定解禁 ' + memberOpenid + ' ？')) return;
    apiCall('api/chat_api.php?action=unmute_member', {
        appid: botId,
        group_openid: currentSession.target_id,
        member_openid: memberOpenid
    }, muteCallback);
}

// 消息快捷禁言
function quickMuteMember(btn) {
    if (!currentSession) return;
    var botId = getCurrentBotId();
    if (!botId) { alert('请先选择机器人'); return; }
    var userId = btn.getAttribute('data-user-id');
    var targetId = btn.getAttribute('data-target-id');
    if (!userId) { alert('无法获取成员 openid'); return; }
    var duration = prompt('请输入禁言时长（分钟），默认60分钟：', '60');
    if (duration === null) return;
    var minutes = parseInt(duration) || 60;
    var seconds = minutes * 60;
    apiCall('api/chat_api.php?action=mute_member', {
        appid: botId,
        group_openid: targetId || currentSession.target_id,
        member_openid: userId,
        seconds: seconds
    }, function(res) {
        if (res.success) {
            alert('已禁言 ' + userId + ' ' + minutes + ' 分钟');
        } else {
            alert(res.message || '禁言失败');
        }
    });
}

// 消息快捷解禁
function quickUnmuteMember(btn) {
    if (!currentSession) return;
    var botId = getCurrentBotId();
    if (!botId) { alert('请先选择机器人'); return; }
    var userId = btn.getAttribute('data-user-id');
    var targetId = btn.getAttribute('data-target-id');
    if (!userId) { alert('无法获取成员 openid'); return; }
    if (!confirm('确定解禁 ' + userId + ' ？')) return;
    apiCall('api/chat_api.php?action=unmute_member', {
        appid: botId,
        group_openid: targetId || currentSession.target_id,
        member_openid: userId
    }, function(res) {
        if (res.success) {
            alert('已解禁 ' + userId);
        } else {
            alert(res.message || '解禁失败');
        }
    });
}

// ==================== 复制文本 ====================
function copyText(btn) {
    var text = btn.getAttribute('data-text');
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
    btn.textContent = '已复制';
    setTimeout(function() { btn.textContent = '复制'; }, 1500);
}

// ==================== 键盘事件 ====================
function handleChatKeydown(e) {
    var popup = document.getElementById('chatMentionPopup');
    if (popup && popup.style.display === 'block') {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var items = popup.querySelectorAll('.chat-mention-item');
            var activeIdx = -1;
            items.forEach(function(item, idx) {
                if (item.classList.contains('hover')) activeIdx = idx;
            });
            items.forEach(function(item) { item.classList.remove('hover'); });
            if (e.key === 'ArrowDown') {
                activeIdx = (activeIdx + 1) % items.length;
            } else {
                activeIdx = activeIdx <= 0 ? items.length - 1 : activeIdx - 1;
            }
            if (items[activeIdx]) {
                items[activeIdx].classList.add('hover');
                items[activeIdx].scrollIntoView({ block: 'nearest' });
            }
            return;
        }
        if (e.key === 'Enter' && !e.shiftKey) {
            var hoverItem = popup.querySelector('.chat-mention-item.hover');
            if (hoverItem) {
                e.preventDefault();
                hoverItem.click();
                return;
            }
        }
        if (e.key === 'Escape') {
            hideMentionPopup();
            return;
        }
    }

    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendChatMessage();
    }
}

// ==================== 手机版侧边栏控制 ====================
function showSessionList() {
    document.getElementById('chatSidebar').classList.add('mobile-show');
    document.getElementById('chatSidebarOverlay').classList.add('show');
}

function hideSessionList() {
    document.getElementById('chatSidebar').classList.remove('mobile-show');
    document.getElementById('chatSidebarOverlay').classList.remove('show');
}

// ==================== 工具函数 ====================
// 媒体图片加载失败兜底: 替换为占位提示块(不再静默隐藏, 避免空白气泡)
function mediaImgError(el, tip) {
    if (!el || !el.parentElement) return;
    var d = document.createElement('div');
    d.className = 'chat-media-fallback';
    d.textContent = tip || '图片加载失败或已过期';
    el.replaceWith(d);
}

// 从 "URL + 配文" / "URL 配文" / "URL" 格式的日志 content 中解析出媒体URL与配文
// 兼容发送日志旧格式(引用图片/引用图文等)与新格式的文本字段; 非URL开头返回 null
function parseMediaCaption(txt) {
    txt = (txt || '').replace(/^`+|`+$/g, '').trim();
    var m = txt.match(/^(https?:\/\/\S+|api\/[^\s]+|\/[^\s]+)(?:[ \t]*\+[ \t]*|[ \t]+)?([\s\S]*)$/);
    if (!m) return null;
    var url = m[1];
    if (!/^https?:\/\//i.test(url) && url.charAt(0) !== '/' && url.indexOf('api/') !== 0) return null;
    var caption = (m[2] || '').trim();
    if (caption.indexOf('+ ') === 0) caption = caption.slice(2).trim();
    return {url: url, caption: caption};
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function escapeAttr(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/'/g, '&#39;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// ==================== 文件URL识别 ====================
// 统一文件名解析(多附件与单文件分支共用):
// 优先级: 显式文件名 > 日志 content 末尾括号内真实文件名("URL (名字)"格式) > fname/f 参数 > URL路径最后一段
function resolveFileName(explicitName, url, logContent) {
    if (explicitName) return String(explicitName);
    url = String(url || '').trim();
    // 1. 日志 content 末尾括号部分 (发送日志记录的原始文件名, 排除URL/路径)
    if (logContent) {
        var pm = String(logContent).match(/\(([^)]+)\)\s*$/);
        if (pm && !/^https?:/i.test(pm[1]) && pm[1].indexOf('api/') !== 0 && pm[1].indexOf('/') === -1) {
            return pm[1];
        }
    }
    // 2. fname 参数 (ftn.qq.com 链接) / f 参数 (后台本地媒体, 值为 appid/年月/文件名)
    var fnameMatch = url.match(/[?&]fname=([^&]+)/);
    if (fnameMatch) {
        try { return decodeURIComponent(fnameMatch[1]); } catch(e) { return fnameMatch[1]; }
    }
    var fMatch = url.match(/[?&]f=([^&]+)/);
    if (fMatch) {
        try { return decodeURIComponent(fMatch[1]).split('/').pop(); } catch(e) { return fMatch[1].split('/').pop(); }
    }
    // 3. URL路径最后一段
    return url.split('/').pop().split('?')[0] || '文件';
}

// 识别URL对应的文件类型，支持从 fname 参数或路径中提取文件名
// 返回 { type: 'video'|'audio'|'image'|'file'|'link', fileName: 'xxx', extension: 'mp4' }
function detectFileUrl(url) {
    if (!url) return null;
    url = String(url).trim();
    // 去除URL两端的反引号
    url = url.replace(/^`+|`+$/g, '');
    // 支持后台本地媒体相对路径 (api/chat_api.php?action=media&f=...)
    var isLocalMedia = url.indexOf('api/') === 0;
    if (!/^https?:\/\//i.test(url) && !isLocalMedia) return null;

    // 1. 优先从 fname / f 参数获取文件名 (ftn.qq.com 链接用 fname, 后台本地媒体用 f)
    var fileName = '';
    var fnameMatch = url.match(/[?&]fname=([^&]+)/);
    if (fnameMatch) {
        try {
            fileName = decodeURIComponent(fnameMatch[1]);
        } catch (e) {
            fileName = fnameMatch[1];
        }
    }
    if (!fileName) {
        var fMatch = url.match(/[?&]f=([^&]+)/);
        if (fMatch) {
            try {
                fileName = decodeURIComponent(fMatch[1]).split('/').pop();
            } catch (e) {
                fileName = fMatch[1].split('/').pop();
            }
        }
    }
    // 2. 其次从URL路径的最后一段获取
    if (!fileName) {
        var pathPart = url.split('?')[0].split('#')[0];
        var lastSegment = pathPart.split('/').pop();
        if (lastSegment) {
            try {
                fileName = decodeURIComponent(lastSegment);
            } catch (e) {
                fileName = lastSegment;
            }
        }
    }
    if (!fileName) fileName = url;

    // 3. 提取扩展名（小写）
    var extension = '';
    var dotIdx = fileName.lastIndexOf('.');
    if (dotIdx > 0 && dotIdx < fileName.length - 1) {
        extension = fileName.substring(dotIdx + 1).toLowerCase();
    }

    // 4. 根据扩展名判断类型
    var videoExts = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'flv', 'wmv', 'm4v', '3gp', 'mpeg', 'mpg', 'ts', 'vob'];
    var audioExts = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'wma', 'opus', 'aiff', 'amr', 'mid', 'midi'];
    var imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico', 'tiff', 'tif', 'heic', 'heif', 'raw', 'psd', 'ai'];
    // 扩展所有文件后缀：代码、文档、压缩包、字体、应用等
    var fileExts  = [
        // 文档类
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'md',
        // 代码类
        'js', 'ts', 'jsx', 'tsx', 'mjs', 'cjs', 'css', 'scss', 'sass', 'less', 'styl',
        'html', 'htm', 'xhtml', 'xml', 'json', 'yaml', 'yml', 'toml', 'ini', 'conf', 'cfg', 'properties',
        'php', 'py', 'java', 'c', 'cpp', 'cc', 'cxx', 'h', 'hpp', 'hxx', 'cs', 'go', 'rs', 'rb', 'swift',
        'kt', 'kts', 'dart', 'lua', 'r', 'scala', 'clj', 'ex', 'exs', 'erl', 'hs', 'ml', 'fs', 'fsx',
        'sh', 'bash', 'zsh', 'bat', 'cmd', 'ps1', 'psm1', 'fish', 'sql', 'graphql', 'gql', 'proto',
        'vue', 'svelte', 'astro', 'makefile', 'cmake', 'gradle', 'groovy', 'jenkinsfile',
        'dockerfile', 'gitignore', 'editorconfig', 'env',
        // 压缩包
        'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'lz', 'lzma', 'zst', 'tgz', 'tbz2',
        // 字体
        'ttf', 'otf', 'woff', 'woff2', 'eot',
        // 应用/安装包
        'exe', 'msi', 'apk', 'ipa', 'deb', 'rpm', 'dmg', 'pkg', 'app', 'jar', 'war', 'ear',
        'dll', 'so', 'dylib', 'lib', 'a',
        // 电子书
        'epub', 'mobi', 'azw', 'azw3', 'fb2', 'lit', 'lrf',
        // 系统镜像
        'iso', 'img', 'vmdk', 'vdi', 'qcow2',
        // 设计文件
        'sketch', 'fig', 'xd', 'svgz', 'indd', 'idml',
        // 其他
        'log', 'dat', 'db', 'sqlite', 'sqlite3', 'mdb', 'accdb', 'plist', 'key', 'numbers', 'pages'
    ];

    var type = 'link';
    if (videoExts.indexOf(extension) > -1) type = 'video';
    else if (audioExts.indexOf(extension) > -1) type = 'audio';
    else if (imageExts.indexOf(extension) > -1) type = 'image';
    else if (fileExts.indexOf(extension) > -1) type = 'file';
    else if (extension) type = 'file'; // 未识别的扩展名也当作文件处理，确保所有文件后缀都能显示

    return { type: type, fileName: fileName, extension: extension };
}

// 根据扩展名返回带颜色的文件类型徽章图标
function getFileTypeIcon(ext) {
    ext = (ext || '').toLowerCase();
    var color = '#1565c0';
    // 文档
    if (['pdf'].indexOf(ext) > -1) color = '#e53935';
    else if (['doc', 'docx', 'rtf', 'odt'].indexOf(ext) > -1) color = '#1976d2';
    else if (['xls', 'xlsx', 'ods', 'csv'].indexOf(ext) > -1) color = '#2e7d32';
    else if (['ppt', 'pptx', 'odp'].indexOf(ext) > -1) color = '#ef6c00';
    else if (['txt', 'md', 'log'].indexOf(ext) > -1) color = '#546e7a';
    // 代码
    else if (['js', 'mjs', 'cjs'].indexOf(ext) > -1) color = '#f7df1e';
    else if (['ts', 'tsx', 'jsx'].indexOf(ext) > -1) color = '#3178c6';
    else if (['css', 'scss', 'sass', 'less', 'styl'].indexOf(ext) > -1) color = '#c6538c';
    else if (['html', 'htm', 'xhtml'].indexOf(ext) > -1) color = '#e44d26';
    else if (['php'].indexOf(ext) > -1) color = '#777bb4';
    else if (['py'].indexOf(ext) > -1) color = '#3776ab';
    else if (['java'].indexOf(ext) > -1) color = '#f89820';
    else if (['c', 'cpp', 'h', 'hpp', 'cc', 'cxx', 'hxx'].indexOf(ext) > -1) color = '#00599c';
    else if (['go'].indexOf(ext) > -1) color = '#00add8';
    else if (['rs'].indexOf(ext) > -1) color = '#dea584';
    else if (['rb'].indexOf(ext) > -1) color = '#cc342d';
    else if (['swift'].indexOf(ext) > -1) color = '#fa7343';
    else if (['kt', 'kts'].indexOf(ext) > -1) color = '#7f52ff';
    else if (['sh', 'bash', 'zsh'].indexOf(ext) > -1) color = '#4eaa25';
    else if (['sql'].indexOf(ext) > -1) color = '#e38c00';
    else if (['vue'].indexOf(ext) > -1) color = '#42b883';
    else if (['json', 'xml', 'yaml', 'yml', 'toml', 'ini', 'conf'].indexOf(ext) > -1) color = '#00838f';
    else if (['dockerfile', 'env'].indexOf(ext) > -1) color = '#2496ed';
    // 压缩包
    else if (['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'zst', 'tgz'].indexOf(ext) > -1) color = '#7b1fa2';
    // 应用
    else if (['exe', 'msi', 'apk', 'ipa', 'deb', 'rpm', 'dmg', 'pkg'].indexOf(ext) > -1) color = '#455a64';
    else if (['jar', 'war', 'ear'].indexOf(ext) > -1) color = '#e76f00';
    // 电子书
    else if (['epub', 'mobi', 'azw3'].indexOf(ext) > -1) color = '#689f38';
    // 字体
    else if (['ttf', 'otf', 'woff', 'woff2', 'eot'].indexOf(ext) > -1) color = '#5c6bc0';
    // 镜像
    else if (['iso', 'img'].indexOf(ext) > -1) color = '#607d8b';
    var label = ext ? ext.toUpperCase() : 'FILE';
    var textColor = (ext === 'js' || ext === 'mjs' || ext === 'cjs' || ext === 'rs') ? '#000' : '#fff';
    return '<span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;background:' + color + ';color:' + textColor + ';font-size:10px;font-weight:700;flex-shrink:0;">' + escapeHtml(label) + '</span>';
}

// 渲染文件URL内容（视频/音频/图片/文件下载链接）
function renderFileContent(url, fileInfo) {
    var safeUrl = escapeAttr(url);
    var fileName = (fileInfo && fileInfo.fileName) ? fileInfo.fileName : url;
    var safeName = escapeHtml(fileName);
    var ext = (fileInfo && fileInfo.extension) ? fileInfo.extension.toLowerCase() : '';
    var html = '';

    if (fileInfo.type === 'image') {
        html += '<div class="chat-msg-bubble" style="padding:4px;background:none;border:none;">';
        html += '<img class="chat-msg-image chat-media-clickable" src="' + safeUrl + '" onclick="openMediaViewer(\'image\',\'' + safeUrl + '\')" onerror="this.style.display=\'none\'" referrerpolicy="no-referrer">';
        html += '</div>';
    } else if (fileInfo.type === 'video') {
        html += '<div class="chat-msg-bubble" style="padding:4px;background:none;border:none;">';
        html += '<video class="chat-msg-video chat-media-clickable" preload="metadata" style="max-width:250px;max-height:200px;border-radius:4px;" onclick="openMediaViewer(\'video\',\'' + safeUrl + '\')">';
        html += '<source src="' + safeUrl + '">';
        html += '</video></div>';
    } else if (fileInfo.type === 'audio') {
        html += '<div class="chat-msg-bubble chat-media-clickable" style="padding:8px 12px;" onclick="openMediaViewer(\'audio\',\'' + safeUrl + '\')">';
        html += '<div style="display:flex;align-items:center;gap:8px;">';
        html += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>';
        html += '<span style="font-size:12px;color:var(--text-muted);">' + safeName + '</span>';
        html += '</div></div>';
    } else if (fileInfo.type === 'file') {
        var icon = getFileTypeIcon(ext);
        html += '<div class="chat-msg-bubble" style="padding:8px 12px;">';
        html += '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit;">';
        html += icon;
        html += '<div style="min-width:0;">';
        html += '<div style="font-weight:500;font-size:13px;word-break:break-all;">' + safeName + '</div>';
        html += '<div style="font-size:11px;color:var(--text-muted);">点击下载' + (ext ? ' (' + escapeHtml(ext.toUpperCase()) + ')' : '') + '</div>';
        html += '</div></a></div>';
    }
    return html;
}

// ==================== 批量加载昵称 ====================
var nicknameLoading = false; // 防止重复加载
var queriedNicknameIds = {}; // 已查询过的ID，防止重复查询
function loadNicknames(botId, userIds) {
    if (!botId || !userIds || userIds.length === 0 || nicknameLoading) return;
    var uniqueIds = [];
    userIds.forEach(function(id) {
        // 只查询未查询过的ID（包括缓存为空的）
        if (id && !queriedNicknameIds[id] && uniqueIds.indexOf(id) === -1) {
            uniqueIds.push(id);
        }
    });
    // 标记为已查询
    uniqueIds.forEach(function(id) {
        queriedNicknameIds[id] = true;
        if (!nicknameCache[id]) nicknameCache[id] = '';
    });
    if (uniqueIds.length === 0) return;

    nicknameLoading = true;
    apiCall('api/chat_api.php?action=get_nicknames', {
        appid: botId,
        user_ids: JSON.stringify(uniqueIds)
    }, function(res) {
        nicknameLoading = false;
        if (res.success && res.data) {
            var nicknames = res.data.user_nicknames || {};
            var groupNames = res.data.group_names || {};
            var userRemarks = res.data.user_remarks || {};
            var groupRemarks = res.data.group_remarks || {};
            var groupAvatars = res.data.group_avatars || {};
            var hasNewNicknames = false;
            for (var id in nicknames) {
                if (nicknames[id]) {
                    nicknameCache[id] = nicknames[id];
                    hasNewNicknames = true;
                }
            }
            for (var gid in groupNames) {
                if (groupNames[gid]) {
                    nicknameCache[gid] = groupNames[gid];
                    hasNewNicknames = true;
                }
            }
            // 缓存备注
            for (var id in userRemarks) {
                if (userRemarks[id]) {
                    remarkCache[id] = userRemarks[id];
                    nicknameCache[id] = userRemarks[id]; // 备注覆盖昵称
                    hasNewNicknames = true;
                }
            }
            for (var gid in groupRemarks) {
                if (groupRemarks[gid]) {
                    remarkCache[gid] = groupRemarks[gid];
                    nicknameCache[gid] = groupRemarks[gid]; // 备注覆盖群名
                    hasNewNicknames = true;
                }
            }
            // 缓存群自定义头像
            var hasNewAvatars = false;
            for (var gid in groupAvatars) {
                if (groupAvatars[gid] && groupAvatarCache[gid] !== groupAvatars[gid]) {
                    groupAvatarCache[gid] = groupAvatars[gid];
                    hasNewAvatars = true;
                }
            }
            // 只有获取到新昵称时才更新DOM，不再重新加载消息
            if ((hasNewNicknames || hasNewAvatars) && currentSession) {
                // 直接更新DOM中已显示消息的昵称，避免重新加载全部消息
                if (hasNewNicknames) {
                    updateNicknamesInDOM();
                }
                // 如果有新群头像，重新渲染会话列表
                if (hasNewAvatars) {
                    renderSessions(sessions);
                }
            }
        }
    });
}

// ==================== 原地更新DOM中的昵称（避免重新加载全部消息）====================
function updateNicknamesInDOM() {
    var botId = getCurrentBotId();
    if (!botId) return;
    var container = document.getElementById('chatMessages');
    if (!container) return;
    
    // 遍历所有消息元素，更新发送者昵称
    var msgElements = container.querySelectorAll('.chat-msg[data-msg-id]');
    msgElements.forEach(function(msgEl) {
        var msgDataStr = msgEl.getAttribute('data-msg-data');
        if (!msgDataStr) return;
        try {
            var msgData = JSON.parse(msgDataStr);
            var userId = msgData.user_id || '';
            var isSent = (msgData.direction === '发送');
            
            if (!isSent && userId && nicknameCache[userId]) {
                // 更新接收方消息的昵称
                var senderEl = msgEl.querySelector('.chat-msg-sender');
                if (senderEl) {
                    // 保留角色徽章
                    var roleBadge = senderEl.querySelector('.chat-role-badge');
                    var roleBadgeHtml = roleBadge ? roleBadge.outerHTML : '';
                    var nick = nicknameCache[userId];
                    // 清理不可见字符
                    nick = nick.replace(/[\u00AD\u061C\u180E\u200B-\u200F\u202A-\u202E\u2060-\u2069\uFEFF\uFFA0]/g, '').trim();
                    if (nick) {
                        senderEl.innerHTML = escapeHtml(nick) + roleBadgeHtml;
                    }
                }
            }
        } catch(e) {}
    });
    
    // 更新@提及显示
    var bubbleElements = container.querySelectorAll('.chat-msg-bubble');
    bubbleElements.forEach(function(bubble) {
        var html = bubble.innerHTML;
        var hasAtMention = html.indexOf('chat-at-user') === -1 && (
            html.indexOf('&lt;qqbot-at-user') > -1 || 
            html.indexOf('&lt;@') > -1
        );
        if (hasAtMention) {
            // 重新处理@提及替换
            html = html.replace(/&lt;qqbot-at-user\s+id=&quot;([^&]*)&quot;\s*\/?&gt;/g, function(match, userId) {
                var nick = getMentionDisplay(userId);
                return '<span class="chat-at-user">@' + escapeHtml(nick) + '</span>';
            });
            html = html.replace(/&lt;@([A-Fa-f0-9]{16,})&gt;/g, function(match, userId) {
                var nick = getMentionDisplay(userId);
                return '<span class="chat-at-user">@' + escapeHtml(nick) + '</span>';
            });
            bubble.innerHTML = html;
        }
    });
}

// ==================== 设置备注/头像 ====================
function showRemarkModal() {
    if (!currentSession) {
        alert('请先选择一个会话');
        return;
    }
    var botId = getCurrentBotId();
    var targetId = currentSession.target_id;
    var sourceType = currentSession.source_type;

    // 设置标题
    var titleText = sourceType === '群聊' ? '设置群备注' : '设置用户备注';
    document.getElementById('remarkModalTitle').textContent = titleText;

    // 群聊显示头像设置项
    var avatarGroup = document.getElementById('groupAvatarGroup');
    if (sourceType === '群聊') {
        avatarGroup.style.display = 'block';
    } else {
        avatarGroup.style.display = 'none';
    }

    // 加载当前备注和头像
    apiCall('api/chat_api.php?action=get_remarks', {
        appid: botId,
        user_ids: sourceType === '群聊' ? '' : JSON.stringify([targetId]),
        group_ids: sourceType === '群聊' ? JSON.stringify([targetId]) : ''
    }, function(res) {
        var currentRemark = '';
        var currentAvatar = '';
        if (res.success && res.data) {
            if (sourceType === '群聊') {
                currentRemark = (res.data.group_remarks && res.data.group_remarks[targetId]) || '';
                currentAvatar = (res.data.group_avatars && res.data.group_avatars[targetId]) || '';
            } else {
                currentRemark = (res.data.user_remarks && res.data.user_remarks[targetId]) || '';
            }
        }
        document.getElementById('remarkInput').value = currentRemark;
        document.getElementById('groupAvatarInput').value = currentAvatar;

        // 更新头像预览
        updateAvatarPreview();
    });

    document.getElementById('remarkModal').style.display = 'flex';
    document.getElementById('remarkInput').focus();
}

function closeRemarkModal() {
    document.getElementById('remarkModal').style.display = 'none';
}

function updateAvatarPreview() {
    var avatarUrl = document.getElementById('groupAvatarInput').value.trim();
    var preview = document.getElementById('groupAvatarPreview');
    if (avatarUrl) {
        preview.innerHTML = '<img src="' + escapeAttr(avatarUrl) + '" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'&#128101;\';" style="width:100%;height:100%;object-fit:cover;">';
    } else {
        preview.innerHTML = '&#128101;';
    }
}

// 监听头像URL输入变化
document.getElementById('groupAvatarInput').addEventListener('input', function() {
    updateAvatarPreview();
});

// 点击遮罩层关闭
document.getElementById('remarkModal').addEventListener('click', function(e) {
    if (e.target === this) closeRemarkModal();
});
document.getElementById('muteModal').addEventListener('click', function(e) {
    if (e.target === this) closeMuteModal();
});

function saveRemark() {
    if (!currentSession) {
        alert('请先选择一个会话');
        return;
    }
    var botId = getCurrentBotId();
    var targetId = currentSession.target_id;
    var sourceType = currentSession.source_type;
    var remark = document.getElementById('remarkInput').value.trim();
    var avatarUrl = document.getElementById('groupAvatarInput').value.trim();

    var btn = document.getElementById('remarkSaveBtn');
    var originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '保存中...';

    // 保存备注
    apiCall('api/chat_api.php?action=set_remark', {
        appid: botId,
        target_id: targetId,
        source_type: sourceType,
        remark: remark
    }, function(res) {
        if (!res.success) {
            btn.disabled = false;
            btn.textContent = originalText;
            alert(res.msg || res.message || '保存失败');
            return;
        }

        // 如果是群聊，还要保存头像
        if (sourceType === '群聊') {
            apiCall('api/chat_api.php?action=set_group_avatar', {
                appid: botId,
                target_id: targetId,
                avatar_url: avatarUrl
            }, function(res2) {
                btn.disabled = false;
                btn.textContent = originalText;
                if (res2.success) {
                    // 更新缓存
                    remarkCache[targetId] = remark;
                    nicknameCache[targetId] = remark;
                    groupAvatarCache[targetId] = avatarUrl;
                    closeRemarkModal();
                    alert('保存成功');
                    // 重新加载会话和消息
                    loadSessions();
                    if (currentSession) {
                        loadMessages(currentSession.target_id, currentSession.source_type);
                    }
                } else {
                    alert(res2.msg || res2.message || '头像保存失败');
                }
            });
        } else {
            btn.disabled = false;
            btn.textContent = originalText;
            // 更新缓存
            remarkCache[targetId] = remark;
            nicknameCache[targetId] = remark;
            closeRemarkModal();
            alert('保存成功');
            // 重新加载会话和消息
            loadSessions();
            if (currentSession) {
                loadMessages(currentSession.target_id, currentSession.source_type);
            }
        }
    });
}

// ==================== 强制滚动到底部 ====================
// 使用双重requestAnimationFrame + setTimeoutfallback确保content-visibility布局完成
function scrollToBottomForce(container) {
    if (!container) container = document.getElementById('chatMessages');
    if (!container) return;
    // 立即滚动一次（应对无content-visibility的简单场景）
    container.scrollTop = container.scrollHeight;
    // rAF后再次滚动（等待浏览器完成布局计算）
    requestAnimationFrame(function() {
        container.scrollTop = container.scrollHeight;
        // 双重rAF确保content-visibility的contain-intrinsic-size被实际内容替换
        requestAnimationFrame(function() {
            container.scrollTop = container.scrollHeight;
        });
    });
    // setTimeout兜底：图片/媒体加载后再次滚动
    setTimeout(function() {
        container.scrollTop = container.scrollHeight;
    }, 100);
}

// ==================== 发送后轻量刷新（不重新加载全部消息） ====================
function refreshAfterSend() {
    if (!currentSession) return;
    var botId = getCurrentBotId();
    if (!botId) return;

    // 轻量级获取最新消息，只追加新消息到列表底部
    var postData = {
        action: 'get_messages',
        target_id: currentSession.target_id,
        source_type: currentSession.source_type || '',
        offset: 0,
        limit: 10
    };
    if (botId) postData.appid = botId;

    apiCall('api/chat_api.php', postData, function(res) {
        if (res.success && res.data && res.data.messages) {
            var msgs = res.data.messages;
            var newLatestId = msgs.length > 0 ? (msgs[0].id || msgs[0].msg_id || '') : '';

            // 找出真正的新消息（ID不在已显示集合中的）
            var newMsgs = [];
            msgs.forEach(function(m) {
                var mid = m.id || m.msg_id || '';
                if (mid && !displayedMsgIds[mid]) {
                    displayedMsgIds[mid] = true;
                    newMsgs.push(m);
                }
            });

            if (newMsgs.length > 0) {
                lastMsgCount = currentLoadedMsgs.length + newMsgs.length;
                lastMsgLatestId = newLatestId;
                totalMsgCount = res.data.info ? (res.data.info.total || totalMsgCount) : totalMsgCount;

                // 将新消息添加到已加载数组前面（数组从新到旧排序）
                currentLoadedMsgs = newMsgs.concat(currentLoadedMsgs);

                // 构建新消息HTML并追加到容器底部
                var container = document.getElementById('chatMessages');
                var html = '';
                for (var i = newMsgs.length - 1; i >= 0; i--) {
                    html += buildMsgHtml(newMsgs[i], botId);
                }
                container.insertAdjacentHTML('beforeend', html);

                // 发送后始终强制滚动到底部
                scrollToBottomForce(container);

                // 批量加载新消息中的昵称
                var userIdsToLoad = [];
                newMsgs.forEach(function(m) {
                    if (m.user_id && !nicknameCache[m.user_id] && !queriedNicknameIds[m.user_id] && userIdsToLoad.indexOf(m.user_id) === -1) {
                        userIdsToLoad.push(m.user_id);
                    }
                });
                if (userIdsToLoad.length > 0) {
                    loadNicknames(botId, userIdsToLoad);
                }
            } else {
                // 没有新消息也要确保滚动到底部
                scrollToBottomForce();
            }
        }
    });

    // 静默更新会话列表（不重新加载消息）
    autoRefreshSessions();
}

// ==================== 自动刷新 ====================
var autoRefreshTimer = null;
var sessionRefreshTimer = null;
var autoRefreshEnabled = true;
var lastMsgCount = 0;
var lastMsgLatestId = '';

function startAutoRefresh() {
    stopAutoRefresh();
    if (!autoRefreshEnabled) return;
    // 每5秒自动刷新当前会话消息（轻量级，只获取最新少量消息）
    autoRefreshTimer = setInterval(function() {
        if (currentSession && !loading) {
            autoRefreshMessages();
        }
    }, 5000);
    // 每10秒自动刷新会话列表
    sessionRefreshTimer = setInterval(function() {
        autoRefreshSessions();
    }, 10000);
}

function stopAutoRefresh() {
    if (autoRefreshTimer) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }
    if (sessionRefreshTimer) {
        clearInterval(sessionRefreshTimer);
        sessionRefreshTimer = null;
    }
}

function autoRefreshSessions() {
    var botId = getCurrentBotId();
    if (!botId) return;
    var typeFilter = document.getElementById('chatTypeFilter').value;
    var postData = { action: 'get_sessions', appid: botId };
    if (typeFilter) postData.source_type = typeFilter;
    
    apiCall('api/chat_api.php', postData, function(res) {
        if (res.success && res.data) {
            allSessions = res.data;
            // 重新过滤
            var searchInput = document.getElementById('chatSessionSearch');
            var q = searchInput ? searchInput.value.trim().toLowerCase() : '';
            if (q) {
                sessions = allSessions.filter(function(s) {
                    return (s.name || '').toLowerCase().indexOf(q) > -1 ||
                           (s.target_id || '').toLowerCase().indexOf(q) > -1;
                });
            } else {
                sessions = allSessions;
            }
            // 保留当前选中状态，不重新触发选中事件
            renderSessions(sessions);
        }
    });
}

function autoRefreshMessages() {
    if (!currentSession) return;
    var botId = getCurrentBotId();
    if (!botId) return;
    
    // 轻量级刷新：只获取最新的少量消息，检查是否有新消息
    var postData = {
        action: 'get_messages',
        target_id: currentSession.target_id,
        source_type: currentSession.source_type || '',
        offset: 0,
        limit: 10  // 只获取最新10条，检查是否有新消息
    };
    if (botId) postData.appid = botId;
    
    apiCall('api/chat_api.php', postData, function(res) {
        if (res.success && res.data && res.data.messages) {
            var msgs = res.data.messages;
            var newLatestId = msgs.length > 0 ? (msgs[0].id || msgs[0].msg_id || '') : '';
            var oldLatestId = lastMsgLatestId || '';
            
            // 如果最新消息ID没变，不需要更新
            if (newLatestId === oldLatestId && msgs.length === lastMsgCount) return;
            
            // 找出真正的新消息（ID不在已显示集合中的）
            var newMsgs = [];
            msgs.forEach(function(m) {
                var mid = m.id || m.msg_id || '';
                if (mid && !displayedMsgIds[mid]) {
                    displayedMsgIds[mid] = true;
                    newMsgs.push(m);
                }
            });
            
            if (newMsgs.length > 0) {
                // 有新消息，插入到消息列表底部（最新的在底部）
                lastMsgCount = currentLoadedMsgs.length + newMsgs.length;
                lastMsgLatestId = newLatestId;
                
                // 更新总消息数
                totalMsgCount = res.data.info ? (res.data.info.total || totalMsgCount) : totalMsgCount;
                
                // 将新消息添加到已加载数组的前面（数组从新到旧排序）
                currentLoadedMsgs = newMsgs.concat(currentLoadedMsgs);
                
                // 构建新消息的HTML并追加到容器底部
                var container = document.getElementById('chatMessages');
                var html = '';
                // 从旧到新渲染（newMsgs是从新到旧，需要反转后追加到末尾）
                for (var i = newMsgs.length - 1; i >= 0; i--) {
                    html += buildMsgHtml(newMsgs[i], botId);
                }
                container.insertAdjacentHTML('beforeend', html);
                
                // 自动滚动到底部（仅当用户已在底部附近时）
                var isNearBottom = (container.scrollHeight - container.scrollTop - container.clientHeight) < 100;
                if (isNearBottom) {
                    container.scrollTop = container.scrollHeight;
                }
                
                // 批量加载新消息中的昵称
                var userIdsToLoad = [];
                newMsgs.forEach(function(m) {
                    if (m.user_id && !nicknameCache[m.user_id] && !queriedNicknameIds[m.user_id] && userIdsToLoad.indexOf(m.user_id) === -1) {
                        userIdsToLoad.push(m.user_id);
                    }
                    // 从消息内容中提取@提及用户ID
                    var rawContent = m.content || '';
                    var atMatches = rawContent.match(/<@([A-Fa-f0-9]{20,})>/g);
                    if (atMatches) {
                        atMatches.forEach(function(atMatch) {
                            var atUserId = atMatch.replace(/<@|>/g, '');
                            if (atUserId && !nicknameCache[atUserId] && !queriedNicknameIds[atUserId] && userIdsToLoad.indexOf(atUserId) === -1) {
                                userIdsToLoad.push(atUserId);
                            }
                        });
                    }
                });
                if (userIdsToLoad.length > 0) {
                    loadNicknames(botId, userIdsToLoad);
                }
            } else {
                // 没有新消息，但可能消息数量变了（如撤回）
                if (msgs.length !== lastMsgCount) {
                    lastMsgCount = msgs.length;
                    lastMsgLatestId = newLatestId;
                }
            }
        }
    });
}

// ==================== 初始化 ====================
(function() {
    var botSelect = document.getElementById('chatBotFilter');
    if (botSelect.options.length > 0 && !botSelect.value) {
        botSelect.selectedIndex = 0;
    }
    if (botSelect.value) {
        updateBotInfoDisplay();
        loadSessions();
    } else {
        document.getElementById('chatSessionList').innerHTML = '<div class="chat-empty">请先添加机器人</div>';
    }
    // 启动自动刷新
    startAutoRefresh();
    
    // 页面可见性变化时暂停/恢复自动刷新
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopAutoRefresh();
        } else {
            startAutoRefresh();
            // 页面重新可见时立即刷新一次
            if (currentSession) {
                autoRefreshMessages();
            }
            autoRefreshSessions();
        }
    });
})();
</script>

<!-- ==================== Ark消息脚本 ==================== -->
<script>
// Ark 模板定义 - 每个模板有不同的字段配置
var arkTemplates = {
    37: {
        name: '大图卡片',
        fields: [
            { key: '#PROMPT#', label: '提示', type: 'text', placeholder: '提示文本' },
            { key: '#METATITLE#', label: '标题', type: 'text', placeholder: '卡片标题' },
            { key: '#METASUBTITLE#', label: '副标题', type: 'text', placeholder: '卡片副标题' },
            { key: '#METACOVER#', label: '封面URL', type: 'text', placeholder: 'https://图片链接' },
            { key: '#METAURL#', label: '跳转URL', type: 'text', placeholder: 'https://跳转链接' }
        ]
    },
    24: {
        name: '文本卡片',
        fields: [
            { key: '#DESC#', label: '描述', type: 'text', placeholder: '描述内容' },
            { key: '#PROMPT#', label: '提示', type: 'text', placeholder: '提示文本' },
            { key: '#TITLE#', label: '标题', type: 'text', placeholder: '卡片标题' },
            { key: '#METADESC#', label: '详情', type: 'text', placeholder: '详细描述' },
            { key: '#IMG#', label: '图片URL', type: 'text', placeholder: 'https://图片链接' },
            { key: '#LINK#', label: '跳转URL', type: 'text', placeholder: 'https://跳转链接' },
            { key: '#SUBTITLE#', label: '副标题', type: 'text', placeholder: '副标题' }
        ]
    },
    23: {
        name: '链接卡片',
        fields: [
            { key: '#DESC#', label: '描述', type: 'text', placeholder: '描述内容' },
            { key: '#PROMPT#', label: '提示', type: 'text', placeholder: '提示文本' },
            { key: '#LINKLIST#', label: '链接列表', type: 'linklist', placeholder: '每行一条: 文字|链接URL\n描述1|https://example.com\n描述2|https://example.com' }
        ]
    }
};

function showArkModal() {
    document.getElementById('arkModal').style.display = 'flex';
    // 默认加载第一个模板
    if (!document.getElementById('arkTemplateSelect').value) {
        document.getElementById('arkTemplateSelect').value = '37';
    }
    onArkTemplateChange();
}

function closeArkModal() {
    document.getElementById('arkModal').style.display = 'none';
}

function onArkTemplateChange() {
    var templateId = document.getElementById('arkTemplateSelect').value;
    var container = document.getElementById('arkFieldsContainer');
    container.innerHTML = '';

    var tpl = arkTemplates[templateId];
    if (!tpl) return;

    tpl.fields.forEach(function(field) {
        var group = document.createElement('div');
        group.style.cssText = 'margin-bottom:10px;';

        if (field.type === 'linklist') {
            // 链接列表特殊字段
            var label = document.createElement('div');
            label.style.cssText = 'font-size:12px;color:var(--text-muted);margin-bottom:4px;';
            label.textContent = '链接列表 (每行一条: 文字|链接URL)';
            group.appendChild(label);

            var textarea = document.createElement('textarea');
            textarea.id = 'ark_field_' + field.key.replace(/#/g, '');
            textarea.className = 'form-control';
            textarea.rows = 5;
            textarea.placeholder = field.placeholder;
            textarea.style.cssText = 'font-size:13px;width:100%;';
            group.appendChild(textarea);
        } else {
            // 普通文本字段
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:8px;background:var(--bg-secondary);border-radius:6px;padding:8px 12px;';

            var tag = document.createElement('span');
            tag.style.cssText = 'font-size:12px;color:var(--text-muted);font-family:monospace;min-width:90px;flex-shrink:0;';
            tag.textContent = field.key;
            row.appendChild(tag);

            var input = document.createElement('input');
            input.type = 'text';
            input.id = 'ark_field_' + field.key.replace(/#/g, '');
            input.className = 'form-control';
            input.placeholder = field.label;
            input.style.cssText = 'flex:1;border:none;background:transparent;padding:0;font-size:13px;box-shadow:none;';
            row.appendChild(input);

            group.appendChild(row);
        }

        container.appendChild(group);
    });
}

function sendArkMessage() {
    var botId = getCurrentBotId();
    if (!botId) { alert('请先选择机器人'); return; }
    if (!currentSession) { alert('请先选择会话'); return; }

    var templateId = document.getElementById('arkTemplateSelect').value;
    if (!templateId) { alert('请选择模板'); return; }

    var tpl = arkTemplates[templateId];
    if (!tpl) { alert('模板不存在'); return; }

    // 构建 kv 对象
    var kv = {};
    var hasContent = false;
    var linkListData = null;

    tpl.fields.forEach(function(field) {
        var fieldId = 'ark_field_' + field.key.replace(/#/g, '');
        var el = document.getElementById(fieldId);
        if (!el) return;
        var val = el.value.trim();

        if (field.type === 'linklist') {
            linkListData = val;
            if (val) hasContent = true;
        } else {
            if (val) {
                kv[field.key] = val;
                hasContent = true;
            }
        }
    });

    if (!hasContent) { alert('请至少填写一个字段'); return; }

    // 如果是链接卡片模板(23)，解析链接列表
    if (templateId === '23' && linkListData) {
        var lines = linkListData.split('\n').filter(function(l) { return l.trim(); });
        lines.forEach(function(line, idx) {
            var parts = line.split('|');
            if (parts.length >= 2) {
                kv['#LIST_' + (idx + 1) + '#'] = parts[0].trim();
                kv['#LIST_' + (idx + 1) + '_URL#'] = parts[1].trim();
            }
        });
    }

    var btn = document.getElementById('arkSendBtn');
    btn.disabled = true;
    var origText = btn.textContent;
    btn.textContent = '发送中...';

    apiCall('api/chat_api.php?action=send', {
        appid: botId,
        target_id: currentSession.target_id,
        source_type: currentSession.source_type || '群聊',
        msg_type: 'ark',
        template_id: templateId,
        kv: JSON.stringify(kv)
    }, function(res) {
        btn.disabled = false;
        btn.textContent = origText;
        if (res.success || res.code === 0) {
            closeArkModal();
            // 轻量刷新：只追加新消息，不重新加载整个消息列表
            refreshAfterSend();
        } else {
            alert(res.message || res.msg || '发送失败');
        }
    });
}

// 点击遮罩关闭Ark模态框
document.getElementById('arkModal').addEventListener('click', function(e) {
    if (e.target === this) closeArkModal();
});

// ESC键关闭Ark模态框
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var modal = document.getElementById('arkModal');
        if (modal.style.display === 'flex') {
            closeArkModal();
        }
        var tuwenModal = document.getElementById('tuwenModal');
        if (tuwenModal.style.display === 'flex') {
            closeTuwenModal();
        }
        var fileModal = document.getElementById('filePreviewModal');
        if (fileModal.style.display === 'flex') {
            closeFilePreviewModal();
        }
    }
});

// ==================== 图文卡片功能 ====================
function showTuwenModal() {
    document.getElementById('tuwenModal').style.display = 'flex';
    updateTuwenPreview();
}

function closeTuwenModal() {
    document.getElementById('tuwenModal').style.display = 'none';
}

function updateTuwenPreview() {
    var title = document.getElementById('tuwenTitle').value;
    var desc = document.getElementById('tuwenDesc').value;
    var img = document.getElementById('tuwenImage').value;
    var prompt = document.getElementById('tuwenPrompt').value;

    var html = '';
    if (img) {
        html += '<img src="' + escapeAttr(img) + '" style="width:100%;height:150px;object-fit:cover;" onerror="this.style.display=\'none\'" referrerpolicy="no-referrer">';
    }
    html += '<div style="padding:10px 12px;">';
    if (title) html += '<div style="font-size:14px;font-weight:600;margin-bottom:4px;">' + escapeHtml(title) + '</div>';
    if (desc) html += '<div style="font-size:12px;color:#666;line-height:1.4;">' + escapeHtml(desc) + '</div>';
    if (prompt) html += '<div style="font-size:11px;color:#999;margin-top:6px;">' + escapeHtml(prompt) + '</div>';
    html += '</div>';
    document.getElementById('tuwenPreview').innerHTML = html;
}

function sendTuwenMessage() {
    var botId = getCurrentBotId();
    if (!botId) { alert('请先选择机器人'); return; }
    if (!currentSession) { alert('请先选择会话'); return; }

    var title = document.getElementById('tuwenTitle').value.trim();
    var desc = document.getElementById('tuwenDesc').value.trim();
    var img = document.getElementById('tuwenImage').value.trim();
    var link = document.getElementById('tuwenLink').value.trim();
    var prompt = document.getElementById('tuwenPrompt').value.trim();

    if (!title || !desc || !img || !link) {
        alert('官方图文卡片要求四个字段全部填写：标题、描述、图片 URL、跳转链接。');
        return;
    }
    if (Array.from(title).length > 50) { alert('标题最多 50 个字'); return; }
    if (Array.from(desc).length > 200) { alert('描述最多 200 个字'); return; }
    if (!/^https:\/\//i.test(img) || !/^https:\/\//i.test(link)) {
        alert('图片 URL 与跳转链接必须使用 https://');
        return;
    }
    if (img.length > 1024 || link.length > 1024) { alert('链接长度不能超过 1024 个字符'); return; }

    // 使用图文卡片函数发送（msg_type=8）
    var btn = document.getElementById('tuwenSendBtn');
    btn.disabled = true;
    var origText = btn.textContent;
    btn.textContent = '发送中...';

    apiCall('api/chat_api.php?action=send', {
        appid: botId,
        target_id: currentSession.target_id,
        source_type: currentSession.source_type || '群聊',
        msg_type: 'tuwen',
        title: title,
        desc: desc,
        img: img,
        link: link,
        prompt: prompt
    }, function(res) {
        btn.disabled = false;
        btn.textContent = origText;
        if (res.success || res.code === 0) {
            closeTuwenModal();
            // 清空输入
            document.getElementById('tuwenTitle').value = '';
            document.getElementById('tuwenDesc').value = '';
            document.getElementById('tuwenImage').value = '';
            document.getElementById('tuwenLink').value = '';
            document.getElementById('tuwenPrompt').value = '';
            // 轻量刷新：只追加新消息，不重新加载整个消息列表
            refreshAfterSend();
        } else {
            alert(res.message || res.msg || '发送失败');
        }
    });
}

// 图文卡片输入框实时预览
document.addEventListener('DOMContentLoaded', function() {
    ['tuwenTitle', 'tuwenDesc', 'tuwenImage', 'tuwenPrompt'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', updateTuwenPreview);
    });
});

// 点击遮罩关闭图文卡片和文件预览模态框
document.getElementById('tuwenModal').addEventListener('click', function(e) {
    if (e.target === this) closeTuwenModal();
});
document.getElementById('filePreviewModal').addEventListener('click', function(e) {
    if (e.target === this) closeFilePreviewModal();
});
</script>

<!-- ==================== 媒体查看器模态框 ==================== -->
<div class="chat-media-viewer" id="chatMediaViewer" onclick="if(event.target===this)closeMediaViewer()">
    <div class="chat-media-viewer-content">
        <button class="chat-media-viewer-close" onclick="closeMediaViewer()">&times;</button>
        <div class="chat-media-viewer-body" id="chatMediaViewerBody"></div>
        <div class="chat-media-viewer-footer" id="chatMediaViewerFooter"></div>
    </div>
</div>

<script>
// ==================== 媒体查看器 ====================
function openMediaViewer(type, url) {
    var viewer = document.getElementById('chatMediaViewer');
    var body = document.getElementById('chatMediaViewerBody');
    var footer = document.getElementById('chatMediaViewerFooter');
    
    var html = '';
    var footerHtml = '<a href="' + escapeAttr(url) + '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">在新标签打开</a>';
    
    if (type === 'image') {
        html = '<img src="' + escapeAttr(url) + '" onerror="this.style.display=\'none\';this.parentElement.innerHTML=\'<p style=\\\'color:#fff;\\\'>图片加载失败</p>\'" referrerpolicy="no-referrer">';
    } else if (type === 'video') {
        html = '<video controls autoplay preload="metadata">';
        html += '<source src="' + escapeAttr(url) + '">';
        html += '</video>';
    } else if (type === 'audio') {
        html = '<audio controls autoplay preload="metadata">';
        html += '<source src="' + escapeAttr(url) + '">';
        html += '</audio>';
    }
    
    body.innerHTML = html;
    footer.innerHTML = footerHtml;
    viewer.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeMediaViewer() {
    var viewer = document.getElementById('chatMediaViewer');
    var body = document.getElementById('chatMediaViewerBody');
    viewer.classList.remove('show');
    // 清空内容以停止视频/音频播放
    body.innerHTML = '';
    document.body.style.overflow = '';
}

// ESC键关闭媒体查看器
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var viewer = document.getElementById('chatMediaViewer');
        if (viewer.classList.contains('show')) {
            closeMediaViewer();
        }
    }
});
</script>

<?php require_once('footer.php'); ?>
