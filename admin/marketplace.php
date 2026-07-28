<?php
$pageTitle = '插件市场';
require_once('header.php');
?>
<style>
.marketplace-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 1px solid var(--border);
    padding-bottom: 12px;
}
.tab-btn {
    padding: 8px 20px;
    border: 1px solid var(--border);
    background: var(--card-bg);
    border-radius: var(--radius);
    cursor: pointer;
    font-size: 13px;
    color: var(--text-secondary);
    transition: var(--transition);
}
.tab-btn:hover { border-color: var(--primary); color: var(--text); }
.tab-btn.active { background: var(--primary); color: white; border-color: var(--primary); }

.plugin-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 16px;
}
.plugin-card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    transition: var(--transition);
}
.plugin-card:hover {
    box-shadow: var(--shadow-hover);
    border-color: var(--border-hover);
}
.plugin-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}
.plugin-card-header h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
}
.plugin-desc {
    color: var(--text-secondary);
    font-size: 13px;
    margin-bottom: 12px;
    line-height: 1.5;
}
.plugin-meta {
    display: flex;
    gap: 16px;
    font-size: 12px;
    color: var(--text-muted);
    margin-bottom: 8px;
}
.plugin-tags {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.plugin-tag {
    display: inline-block;
    padding: 2px 8px;
    background: #e8f5e9;
    color: #2e7d32;
    border-radius: 10px;
    font-size: 11px;
}
.plugin-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}
.version-current {
    color: var(--success);
    font-size: 13px;
}
.badge-success {
    background: #e8f5e9;
    color: #2e7d32;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
    white-space: nowrap;
}
.loading, .error, .empty-state {
    grid-column: 1 / -1;
    text-align: center;
    padding: 40px;
    color: var(--text-muted);
}

/* 同步提示 */
.sync-banner {
    background: linear-gradient(135deg, #1a1a2e, #16213e);
    color: #fff;
    border-radius: var(--radius);
    padding: 20px 24px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.sync-banner .sync-info { flex: 1; }
.sync-banner h3 { margin: 0 0 4px 0; font-size: 16px; }
.sync-banner p { margin: 0; font-size: 13px; color: #a0aec0; }
.sync-banner .btn { flex-shrink: 0; }
#syncMsg { font-size: 12px; margin-top: 4px; }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h1>插件市场</h1>
    <p class="page-desc">从 GitHub 仓库安装和管理插件</p>
  </div>
  <div class="page-header-right">
    <div class="search-box">
      <input type="text" id="searchInput" placeholder="搜索插件..." oninput="filterPlugins()">
    </div>
    <button class="btn btn-primary" onclick="location.href='submit_plugin.php'"><i class="fas fa-upload"></i> 提交插件</button>
    <button class="btn btn-secondary" onclick="loadPlugins()"><i class="fas fa-sync-alt"></i> 刷新</button>
  </div>
</div>

<!-- 同步横幅 -->
<div id="syncBanner" class="sync-banner" style="display:none;">
  <div class="sync-info">
    <h3 id="syncRepoName"><i class="fas fa-box"></i> 官方插件仓库</h3>
    <p id="syncRepoDesc">插件来源于 GitHub 仓库，审核通过并合并后同步到市场</p>
    <div id="syncMsg"></div>
  </div>
  <div style="display:flex; gap:8px; flex-shrink:0;">
    <a id="githubPrLink" class="btn btn-secondary" href="#" target="_blank"><i class="fas fa-clipboard-list"></i> 审核 PR</a>
    <button class="btn btn-primary" onclick="syncFromGithub()"><i class="fas fa-sync-alt"></i> 从 GitHub 同步</button>
  </div>
</div>

<div class="marketplace-tabs">
  <button class="tab-btn active" data-tab="all" onclick="switchTab('all')">全部插件</button>
  <button class="tab-btn" data-tab="installed" onclick="switchTab('installed')">已安装</button>
  <button class="tab-btn" data-tab="updatable" onclick="switchTab('updatable')">可更新</button>
</div>

<div id="pluginGrid" class="plugin-grid">
  <div class="loading">加载中...</div>
</div>

<div id="confirmModal" class="modal-overlay" style="display:none">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modalTitle">确认操作</h3>
    </div>
    <div class="modal-body" id="modalBody">确定要执行此操作吗？</div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal()">取消</button>
      <button class="btn btn-danger" id="modalConfirmBtn" onclick="confirmAction()">确认</button>
    </div>
  </div>
</div>

<script>
var API = 'api/marketplace_api.php';
var allPlugins = [];
var currentTab = 'all';

function loadPlugins() {
    document.getElementById('pluginGrid').innerHTML = '<div class="loading">加载中...</div>';
    fetch(API + '?type=list')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200) {
                allPlugins = data.plugins || [];
                renderPlugins();
            } else {
                document.getElementById('pluginGrid').innerHTML = '<div class="error">加载失败</div>';
            }
        })
        .catch(function(e) {
            document.getElementById('pluginGrid').innerHTML = '<div class="error">网络错误: ' + e.message + '</div>';
        });
}

function renderPlugins() {
    var searchVal = document.getElementById('searchInput').value.toLowerCase();
    var filtered = allPlugins.filter(function(p) {
        if (searchVal && p.title.toLowerCase().indexOf(searchVal) === -1 && 
            p.name.toLowerCase().indexOf(searchVal) === -1 &&
            p.description.toLowerCase().indexOf(searchVal) === -1) {
            return false;
        }
        if (currentTab === 'installed' && !p.installed) return false;
        if (currentTab === 'updatable' && !p.has_update) return false;
        return true;
    });

    var grid = document.getElementById('pluginGrid');
    if (filtered.length === 0) {
        grid.innerHTML = '<div class="empty-state">没有找到匹配的插件</div>';
        return;
    }

    grid.innerHTML = filtered.map(function(p) { return createPluginCard(p); }).join('');
}

function createPluginCard(p) {
    var tagsHtml = (p.tags || []).map(function(t) { return '<span class="plugin-tag">' + t + '</span>'; }).join('');
    var actionBtn = '';
    var statusBadge = '';

    if (p.installed) {
        statusBadge = '<span class="badge-success">v' + p.installed_version + '</span>';
        if (p.has_update) {
            actionBtn = '<button class="btn btn-primary btn-sm" onclick="updatePlugin(\'' + p.name + '\')"><i class="fas fa-sync-alt"></i> 更新到 v' + p.version + '</button>';
            actionBtn += '<button class="btn btn-secondary btn-sm" onclick="uninstallPlugin(\'' + p.name + '\')"><i class="fas fa-trash-alt"></i> 卸载</button>';
        } else {
            actionBtn = '<span class="version-current"><i class="fas fa-check-circle"></i> 已安装</span>';
            actionBtn += '<button class="btn btn-secondary btn-sm" onclick="uninstallPlugin(\'' + p.name + '\')"><i class="fas fa-trash-alt"></i> 卸载</button>';
        }
    } else {
        actionBtn = '<button class="btn btn-primary btn-sm" onclick="installPlugin(\'' + p.name + '\')"><i class="fas fa-download"></i> 安装</button>';
    }

    return '<div class="plugin-card">' +
        '<div class="plugin-card-header">' +
            '<h3>' + (p.title || p.name) + '</h3>' +
            statusBadge +
        '</div>' +
        '<p class="plugin-desc">' + (p.description || '暂无描述') + '</p>' +
        '<div class="plugin-meta">' +
            '<span class="plugin-author"><i class="fas fa-user"></i> ' + (p.author || '未知') + '</span>' +
            '<span class="plugin-version"><i class="fas fa-tag"></i> v' + p.version + '</span>' +
        '</div>' +
        '<div class="plugin-tags">' + tagsHtml + '</div>' +
        '<div class="plugin-actions">' + actionBtn + '</div>' +
    '</div>';
}

function installPlugin(name) {
    var btn = event.target;
    btn.disabled = true;
    btn.textContent = '安装中...';

    fetch(API, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'type=install&plugin_name=' + encodeURIComponent(name) + '&csrf_token=' + getCsrfToken()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.code === 200) {
            alert('安装成功！版本: ' + data.version);
        } else {
            alert('安装失败: ' + data.msg);
        }
        loadPlugins();
    })
    .catch(function() {
        alert('网络错误');
        loadPlugins();
    });
}

function updatePlugin(name) {
    var btn = event.target;
    btn.disabled = true;
    btn.textContent = '更新中...';

    fetch(API, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'type=update&plugin_name=' + encodeURIComponent(name) + '&csrf_token=' + getCsrfToken()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.code === 200) {
            alert('更新成功！');
        } else {
            alert('更新失败: ' + data.msg);
        }
        loadPlugins();
    })
    .catch(function() {
        alert('网络错误');
        loadPlugins();
    });
}

function uninstallPlugin(name) {
    if (!confirm('确定要卸载 "' + name + '" 插件吗？\n此操作不可撤销！')) return;

    fetch(API, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'type=uninstall&plugin_name=' + encodeURIComponent(name) + '&csrf_token=' + getCsrfToken()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.code === 200) {
            alert('卸载成功');
        } else {
            alert('卸载失败: ' + data.msg);
        }
        loadPlugins();
    });
}

function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.tab-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.tab === tab);
    });
    renderPlugins();
}

function filterPlugins() {
    renderPlugins();
}

function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

function loadRepoInfo() {
    fetch('api/github_auth.php?type=repo_info')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200) {
                document.getElementById('syncBanner').style.display = 'flex';
                document.getElementById('syncRepoName').innerHTML = '<i class="fas fa-box"></i> ' + data.owner + '/' + data.repo;
                document.getElementById('githubPrLink').href = data.pulls_url;
            }
        })
        .catch(function() {
            document.getElementById('syncBanner').style.display = 'none';
        });
}

function syncFromGithub() {
    var btn = event.target;
    btn.disabled = true;
    btn.textContent = '同步中...';
    document.getElementById('syncMsg').innerHTML = '正在从GitHub同步...';

    fetch('api/github_auth.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'type=sync_from_github&csrf_token=' + getCsrfToken()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var msg = document.getElementById('syncMsg');
        if (data.code === 200) {
            msg.innerHTML = '<span style="color: var(--success);"><i class="fas fa-check-circle"></i> ' + data.msg + '</span>';
            loadPlugins();
        } else {
            msg.innerHTML = '<span style="color: var(--danger);"><i class="fas fa-times-circle"></i> ' + data.msg + '</span>';
        }
    })
    .catch(function() {
        document.getElementById('syncMsg').innerHTML = '<span style="color: var(--danger);"><i class="fas fa-times-circle"></i> 同步失败</span>';
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync-alt"></i> 从 GitHub 同步';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    loadPlugins();
    loadRepoInfo();
});
</script>

<?php require_once('footer.php'); ?>