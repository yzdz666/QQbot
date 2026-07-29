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

/* 标题锚点滚动偏移 */
.markdown-body h1,
.markdown-body h2,
.markdown-body h3,
.markdown-body h4,
.markdown-body h5,
.markdown-body h6 {
    scroll-margin-top: 80px;
}

/* 移动端文档页面适配 */
@media (max-width: 768px) {
    .doc-card-body {
        padding: 16px;
    }
    .markdown-body {
        font-size: 13px;
    }
    .markdown-body h1 { font-size: 20px; }
    .markdown-body h2 { font-size: 17px; }
    .markdown-body h3 { font-size: 15px; }
    .markdown-body pre {
        padding: 10px;
        font-size: 12px;
    }
    .markdown-body pre code {
        font-size: 11px;
    }
    .markdown-body code {
        font-size: 11px;
        word-break: break-all;
    }
    .markdown-body table {
        font-size: 12px;
    }
    .markdown-body th, .markdown-body td {
        padding: 6px 8px;
    }
    .markdown-body ul, .markdown-body ol {
        margin-left: 16px;
    }
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

    // ==================== 为标题生成 ID（修复锚点跳转） ====================
    // Parsedown 默认不生成 heading id，需要手动添加
    function normalizeText(text) {
        // 去除常见中文标点和特殊字符，用于生成匹配 ID
        return text
            .replace(/[、，。！？：；""''（）【】《》〈〉「」『』·…—\s]/g, '')
            .toLowerCase();
    }

    var headingMap = {}; // 存储 normalizedId -> element 的映射

    document.querySelectorAll('.markdown-body h1, .markdown-body h2, .markdown-body h3, .markdown-body h4, .markdown-body h5, .markdown-body h6').forEach(function(heading) {
        var rawText = heading.textContent || heading.innerText;
        var normalizedId = normalizeText(rawText);

        // 如果已有 id 则使用已有的，否则用 normalizedId
        if (!heading.id) {
            heading.id = normalizedId;
        }

        // 存储映射，用于模糊匹配
        if (normalizedId) {
            headingMap[normalizedId] = heading;
        }
    });

    // ==================== 锚点链接点击处理 ====================
    function scrollToHeading(targetId) {
        // 1. 先尝试直接通过 getElementById 查找
        var target = document.getElementById(targetId);

        // 2. 如果没找到，尝试 normalize 后查找
        if (!target) {
            var normalized = normalizeText(targetId);
            target = headingMap[normalized];
        }

        // 3. 如果还是没找到，尝试模糊匹配（normalizedId 包含 targetId 的 normalized 版本）
        if (!target) {
            var normalizedTarget = normalizeText(targetId);
            for (var key in headingMap) {
                if (key.indexOf(normalizedTarget) !== -1 || normalizedTarget.indexOf(key) !== -1) {
                    target = headingMap[key];
                    break;
                }
            }
        }

        if (target) {
            // 考虑固定导航栏和侧边栏的偏移
            var headerOffset = 70;
            var elementPosition = target.getBoundingClientRect().top;
            var offsetPosition = elementPosition + window.pageYOffset - headerOffset;
            window.scrollTo({ top: offsetPosition, behavior: 'smooth' });

            // 高亮目标标题（短暂闪烁效果）
            target.style.transition = 'background-color 0.3s';
            target.style.backgroundColor = 'rgba(255, 235, 59, 0.3)';
            setTimeout(function() {
                target.style.backgroundColor = '';
            }, 1500);

            return true;
        }
        return false;
    }

    // 为所有内部锚点链接添加点击事件
    document.querySelectorAll('.markdown-body a[href^="#"]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            var href = this.getAttribute('href');
            if (href === '#' || href === '') return;

            var targetId = href.substring(1);
            if (scrollToHeading(targetId)) {
                e.preventDefault();
                // 更新 URL hash（不触发默认跳转）
                if (history.replaceState) {
                    history.replaceState(null, null, href);
                }
            }
        });
    });

    // 页面加载时如果 URL 有 hash，滚动到对应位置
    if (window.location.hash) {
        var hashTarget = window.location.hash.substring(1);
        // 延迟执行，确保所有标题 ID 已生成
        setTimeout(function() {
            scrollToHeading(hashTarget);
        }, 100);
    }

    // ==================== 表格溢出处理 ====================
    document.querySelectorAll('.markdown-body table').forEach(table => {
        if (table.parentElement.classList.contains('table-wrapper')) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'table-wrapper';
        wrapper.style.cssText = 'max-width:100%; overflow-x:auto; margin:16px 0; -webkit-overflow-scrolling:touch;';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    });

    // ==================== 图片自适应 ====================
    document.querySelectorAll('.markdown-body img').forEach(img => {
        img.style.maxWidth = '100%';
        img.style.height = 'auto';
    });
});
</script>

<?php require_once('footer.php'); ?>
