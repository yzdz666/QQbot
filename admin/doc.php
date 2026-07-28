<?php
/**
 * 管理后台 - 开发文档
 * 使用共享头部/底部，保持侧边栏一致性
 */
if (!class_exists('Parsedown')) {
    require dirname(__DIR__) . '/function/Parsedown.php';
}
$parsedown = new Parsedown();
$parsedown->setMarkupEscaped(true);
$parsedown->setBreaksEnabled(true);
$markdown = file_get_contents(dirname(__DIR__) . '/文档.md');
$html = $parsedown->text($markdown);

$pageTitle = '开发文档';
require_once('header.php');
?>

<link rel="stylesheet" href="assets/markdown.css">
<link rel="stylesheet" href="assets/highlight/default.min.css">

<style>
.doc-container {
    max-width: 100%;
    overflow-x: hidden;
}
.doc-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.doc-card-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.doc-card-header h3 {
    font-size: 15px;
    font-weight: 600;
}
.doc-card-header p {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 2px;
}
.doc-card-body {
    padding: 24px;
    overflow-x: hidden;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

/* Markdown 内容 */
.markdown-body {
    font-size: 14px;
    line-height: 1.7;
    word-wrap: break-word;
    overflow-wrap: break-word;
    word-break: break-word;
    max-width: 100%;
    overflow-x: hidden;
}
.markdown-body * {
    max-width: 100%;
    box-sizing: border-box;
}
.markdown-body img {
    max-width: 100%;
    height: auto;
    display: block;
}
.markdown-body h1 {
    font-size: 24px; margin: 24px 0 16px;
    padding-bottom: 8px; border-bottom: 1px solid var(--border);
}
.markdown-body h2 {
    font-size: 20px; margin: 20px 0 12px;
    padding-bottom: 6px; border-bottom: 1px solid var(--border);
}
.markdown-body h3 {
    font-size: 18px; margin: 18px 0 10px;
}
.markdown-body p {
    margin: 0 0 16px; color: var(--text-secondary);
}
.markdown-body pre {
    background: #f1f5f9;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 14px;
    margin: 16px 0;
    max-width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    white-space: pre;
    word-wrap: normal;
    word-break: normal;
}
.markdown-body pre code {
    background: none;
    padding: 0;
    font-family: 'SF Mono', Monaco, 'Cascadia Code', monospace;
    font-size: 13px;
    white-space: pre;
    word-wrap: normal;
    word-break: normal;
}
.markdown-body code {
    background: #f1f5f9;
    padding: 2px 6px;
    border-radius: 4px;
    font-family: 'SF Mono', Monaco, monospace;
    font-size: 13px;
    word-wrap: break-word;
    word-break: break-all;
}
.markdown-body table {
    border-collapse: collapse;
    margin: 16px 0;
    width: auto;
    max-width: 100%;
    display: table;
}
.markdown-body th, .markdown-body td {
    border: 1px solid var(--border);
    padding: 8px 12px;
    text-align: left;
    word-wrap: break-word;
    word-break: break-word;
}
.markdown-body th {
    background: var(--bg);
    font-weight: 600;
    white-space: nowrap;
}
.markdown-body ul, .markdown-body ol {
    margin: 0 0 16px 24px;
    padding-left: 0;
}
.markdown-body li {
    margin: 4px 0;
    word-wrap: break-word;
}
.markdown-body a {
    word-break: break-all;
    word-wrap: break-word;
}
.markdown-body blockquote {
    border-left: 3px solid var(--border);
    padding: 8px 16px;
    margin: 16px 0;
    color: var(--text-secondary);
}

.doc-toolbar {
    display: flex;
    gap: 8px;
}
</style>

<div class="page-header">
  <h2>开发文档</h2>
  <div class="actions">
    <a href="../文档.md" class="btn btn-outline btn-sm" target="_blank">查看原文</a>
  </div>
</div>

<div class="doc-container">
  <div class="doc-card">
    <div class="doc-card-header">
      <div>
        <h3>文档内容</h3>
        <p>支持代码高亮、表格滚动和移动端阅读</p>
      </div>
    </div>
    <div class="doc-card-body">
      <div class="markdown-body">
        <?= $html ?>
      </div>
    </div>
  </div>
</div>

<script src="assets/highlight/highlight.min.js"></script>
<script src="assets/highlight/php.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // 代码高亮
    document.querySelectorAll('pre code').forEach(el => {
        try { hljs.highlightElement(el); } catch (e) {}
    });

    // 表格溢出处理
    document.querySelectorAll('.markdown-body table').forEach(table => {
        if (table.parentElement.classList.contains('table-wrapper')) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'table-wrapper';
        wrapper.style.cssText = 'max-width:100%; overflow-x:auto; margin:16px 0; -webkit-overflow-scrolling:touch;';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    });

    // 图片自适应
    document.querySelectorAll('.markdown-body img').forEach(img => {
        img.style.maxWidth = '100%';
        img.style.height = 'auto';
    });
});
</script>

<?php require_once('footer.php'); ?>
