<?php
$pageTitle = '提交插件';
require_once('header.php');
?>
<style>
.step-guide { max-width: 800px; margin: 0 auto; }
.step-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 24px; margin-bottom: 20px; }
.step-card .step-num { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; background: var(--primary); color: #fff; font-weight: 700; font-size: 14px; margin-right: 12px; }
.step-card h2 { display: flex; align-items: center; font-size: 18px; margin-bottom: 12px; }
.step-card p { color: var(--text-secondary); font-size: 14px; line-height: 1.7; margin-bottom: 8px; }
.step-card code { background: var(--bg); padding: 2px 6px; border-radius: 4px; font-size: 13px; }
.step-card .code-block { background: #1e1e2e; color: #cdd6f4; padding: 16px; border-radius: var(--radius-sm); font-size: 13px; line-height: 1.6; overflow-x: auto; margin: 12px 0; white-space: pre; }
.github-status { display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 20px; }
.github-status img { width: 40px; height: 40px; border-radius: 50%; }
.github-status .info { flex: 1; }
.github-status .info .name { font-weight: 600; }
.github-status .info .login { color: var(--text-muted); font-size: 12px; }
.repo-banner { background: linear-gradient(135deg, #1a1a2e, #16213e); color: #fff; border-radius: var(--radius); padding: 24px; margin-bottom: 24px; text-align: center; }
.repo-banner h3 { font-size: 20px; margin-bottom: 8px; }
.repo-banner p { color: #a0aec0; font-size: 14px; margin-bottom: 16px; }
.repo-banner .btn { margin: 0 6px; }

/* PR 列表 */
.pr-list { margin-top: 16px; }
.pr-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); margin-bottom: 8px; }
.pr-item:hover { border-color: var(--border-hover); }
.pr-item .pr-info { flex: 1; }
.pr-item .pr-title { font-weight: 500; font-size: 14px; }
.pr-item .pr-meta { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.pr-state { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.pr-state.open { background: #d4edda; color: #155724; }
.pr-state.closed { background: #f8d7da; color: #721c24; }
.pr-state.merged { background: #cce5ff; color: #004085; }
.fork-status { display: flex; align-items: center; gap: 12px; padding: 16px; border-radius: var(--radius); margin-bottom: 16px; }
.fork-status.forked { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
.fork-status.not-forked { background: #fff3cd; border: 1px solid #ffc107; color: #856404; }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h1>提交插件</h1>
    <p class="page-desc">通过 GitHub Fork + Pull Request 发布你的插件</p>
  </div>
</div>

<!-- GitHub 登录状态 -->
<div id="githubStatus">
  <div class="loading">检查GitHub登录状态...</div>
</div>

<div class="step-guide">

  <!-- 官方仓库信息 -->
  <div id="repoBanner" class="repo-banner">
    <div class="loading">加载仓库信息...</div>
  </div>

  <!-- 步骤 1：Fork 仓库 -->
  <div class="step-card">
    <h2><span class="step-num">1</span> Fork 官方插件仓库</h2>
    <p>点击下方按钮将官方插件仓库 Fork 到你的 GitHub 账号下，作为你的插件工作副本。</p>
    <div id="forkStatus" class="fork-status not-forked" style="display:none;">
      <span id="forkStatusText">检查中...</span>
    </div>
    <p>
      <a id="forkBtn" class="btn btn-primary" href="#" target="_blank"><i class="fas fa-code-branch"></i> Fork 官方仓库</a>
      <a id="repoLinkBtn" class="btn btn-secondary" href="#" target="_blank"><i class="fas fa-link"></i> 查看官方仓库</a>
    </p>
  </div>

  <!-- 步骤 2：添加插件 -->
  <div class="step-card">
    <h2><span class="step-num">2</span> 在你的 Fork 中创建插件</h2>
    <p>在你 Fork 后的仓库中，按以下结构添加你的插件：</p>
    <div class="code-block">your-fork/
├── plugins/
│   └── your-plugin-name/
│       ├── plugin.json    &lt;-- 插件清单文件（必填）
│       └── your-plugin.php &lt;-- 插件主文件（必填）</div>
    <p><strong>plugin.json</strong> 格式要求：</p>
    <div class="code-block">{
  "name": "your-plugin-name",
  "title": "你的插件标题",
  "description": "插件功能描述",
  "version": "1.0.0",
  "author": "你的名字",
  "main": "your-plugin.php",
  "license": "MIT",
  "tags": ["标签1", "标签2"],
  "category": "功能|娱乐|管理|工具",
  "min_framework_version": "1.0.0"
}</div>
    <p>详细规范请参考 <a href="../PLUGIN_SPEC.md" target="_blank">PLUGIN_SPEC.md</a></p>
  </div>

  <!-- 步骤 3：提交 PR -->
  <div class="step-card">
    <h2><span class="step-num">3</span> 提交 Pull Request</h2>
    <p>完成插件开发后，从你的 Fork 仓库向官方仓库提交 Pull Request。PR 提交后，管理员会在 GitHub 上审核你的代码。</p>
    <p>
      <a id="createPrBtn" class="btn btn-primary" href="#" target="_blank"><i class="fas fa-code-pull-request"></i> 创建 Pull Request</a>
    <div class="info-box" style="margin-top: 12px; padding: 12px; background: var(--bg); border-radius: var(--radius-sm); border-left: 3px solid var(--primary);">
      <strong><i class="fas fa-lightbulb"></i> 提示：</strong> PR 提交后，可以在下方查看审核进度。审核通过并合并后，管理员会同步更新插件市场。
    </div>
  </div>

  <!-- 步骤 4：查看 PR 状态 -->
  <div class="step-card">
    <h2><span class="step-num">4</span> 我的 Pull Request</h2>
    <div id="prContainer">
      <div class="loading">加载PR列表...</div>
    </div>
  </div>

</div>

<script>
function checkGithubStatus() {
    fetch('api/github_auth.php?type=status')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var container = document.getElementById('githubStatus');
            if (data.logged_in) {
                var u = data.user;
                container.innerHTML = '<div class="github-status">' +
                    '<img src="' + u.avatar + '" alt="avatar">' +
                    '<div class="info">' +
                        '<div class="name">' + (u.name || u.login) + '</div>' +
                        '<div class="login">@' + u.login + '</div>' +
                    '</div>' +
                    '<span class="status-badge status-approved">已绑定</span>' +
                '</div>';
                loadRepoInfo();
            } else {
                container.innerHTML = '<div class="github-status">' +
                    '<div style="flex:1; color: var(--text-muted);">需要登录GitHub才能提交插件</div>' +
                    '<button class="btn btn-primary" onclick="loginGithub()"><i class="fas fa-link"></i> 登录GitHub</button>' +
                '</div>';
                document.getElementById('prContainer').innerHTML = '<div class="empty-state">请先登录GitHub</div>';
                document.getElementById('repoBanner').innerHTML = '';
            }
        });
}

function loginGithub() {
    fetch('api/github_auth.php?type=login_url')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200 && data.url) {
                var w = window.open(data.url, 'github-auth', 'width=800,height=700');
                var timer = setInterval(function() {
                    fetch('api/github_auth.php?type=status')
                        .then(function(r) { return r.json(); })
                        .then(function(s) {
                            if (s.logged_in) {
                                clearInterval(timer);
                                if (w) w.close();
                                checkGithubStatus();
                            }
                        });
                }, 2000);
                setTimeout(function() { clearInterval(timer); }, 30000);
            } else {
                alert('获取授权地址失败');
            }
        });
}

function loadRepoInfo() {
    fetch('api/github_auth.php?type=repo_info')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200) {
                var banner = document.getElementById('repoBanner');
                banner.innerHTML = '<h3><i class="fas fa-file-alt"></i> ' + data.owner + '/' + data.repo + '</h3>' +
                    '<p>官方插件仓库 -- Fork 后提交你的插件</p>' +
                    '<a class="btn btn-primary" href="' + data.fork_url + '" target="_blank"><i class="fas fa-code-branch"></i> Fork 仓库</a>' +
                    '<a class="btn btn-secondary" href="' + data.html_url + '" target="_blank"><i class="fas fa-link"></i> 查看仓库</a>' +
                    '<a class="btn btn-secondary" href="' + data.pulls_url + '" target="_blank"><i class="fas fa-code-pull-request"></i> 查看PR</a>';

                document.getElementById('forkBtn').href = data.fork_url;
                document.getElementById('repoLinkBtn').href = data.html_url;
                document.getElementById('createPrBtn').href = data.pulls_url;

                checkForkStatus();
                loadMyPrs();
            }
        });
}

function checkForkStatus() {
    fetch('api/github_auth.php?type=my_forks')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var el = document.getElementById('forkStatus');
            var text = document.getElementById('forkStatusText');
            if (data.forked) {
                el.style.display = 'flex';
                el.className = 'fork-status forked';
                text.innerHTML = '<i class="fas fa-check-circle"></i> 已 Fork！你的仓库：<a href="' + data.fork_url + '" target="_blank">' + data.fork_url + '</a>';
            } else {
                el.style.display = 'flex';
                el.className = 'fork-status not-forked';
                text.innerHTML = '<i class="fas fa-exclamation-triangle"></i> 尚未 Fork，请点击上方按钮 Fork 仓库';
            }
        })
        .catch(function() {
            document.getElementById('forkStatus').style.display = 'none';
        });
}

function loadMyPrs() {
    fetch('api/github_auth.php?type=my_prs')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var container = document.getElementById('prContainer');
            if (!data.prs || data.prs.length === 0) {
                container.innerHTML = '<div class="empty-state">还没有提交过 Pull Request</div>';
                return;
            }

            var html = '<div class="pr-list">';
            for (var i = 0; i < data.prs.length; i++) {
                var pr = data.prs[i];
                var stateClass = pr.state;
                var stateText = {'open': '打开中', 'closed': '已关闭', 'merged': '已合并'}[pr.state] || pr.state;

                html += '<div class="pr-item">';
                html += '<div class="pr-info">';
                html += '<div class="pr-title"><a href="' + pr.html_url + '" target="_blank">' + pr.title + '</a></div>';
                html += '<div class="pr-meta">#' + pr.id + ' · ' + pr.updated_at + '</div>';
                html += '</div>';
                html += '<span class="pr-state ' + stateClass + '">' + stateText + '</span>';
                html += '</div>';
            }
            html += '</div>';
            container.innerHTML = html;
        })
        .catch(function() {
            document.getElementById('prContainer').innerHTML = '<div class="error">加载PR列表失败</div>';
        });
}

checkGithubStatus();
</script>

<?php require_once('footer.php'); ?>