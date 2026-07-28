<?php
$pageTitle = 'GitHub设置';
require_once('header.php');
?>
<style>
.github-setup { max-width: 600px; margin: 0 auto; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; margin-bottom: 6px; font-weight: 500; color: var(--text); }
.form-group .help-text { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
.form-group select, .form-group input { width: 100%; }
.github-status { display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 20px; }
.github-status img { width: 40px; height: 40px; border-radius: 50%; }
.github-status .info { flex: 1; }
.github-status .info .name { font-weight: 600; }
.github-status .info .login { color: var(--text-muted); font-size: 12px; }
.mirror-options { display: flex; flex-direction: column; gap: 10px; }
.mirror-presets { display: flex; gap: 8px; flex-wrap: wrap; }
.mirror-preset-btn { padding: 6px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--card-bg); cursor: pointer; font-size: 12px; transition: var(--transition); }
.mirror-preset-btn:hover { border-color: var(--primary); }
.mirror-preset-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
</style>

<div class="page-header">
  <div class="page-header-left">
    <h1>GitHub设置</h1>
    <p class="page-desc">配置GitHub OAuth和镜像站，用于插件市场的发布和下载</p>
  </div>
</div>

<div class="github-setup">
  <!-- GitHub账号状态 -->
  <div id="githubStatus">
    <div class="loading">检查GitHub登录状态...</div>
  </div>

  <!-- 镜像站设置 -->
  <div class="card" style="margin-bottom: 20px;">
    <div class="card-header"><h3>镜像站配置</h3></div>
    <div class="card-body">
      <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px;">
        选择GitHub镜像站可以加速国内服务器的插件下载：
      </p>
      <div class="mirror-options">
        <div class="mirror-presets" id="mirrorPresets">
          <button class="mirror-preset-btn" data-url="https://github.com"><i class="fas fa-globe"></i> GitHub官方</button>
          <button class="mirror-preset-btn" data-url="https://gitclone.com/github.com"><i class="fas fa-rocket"></i> GitClone</button>
          <button class="mirror-preset-btn" data-url="https://hub.fastgit.xyz"><i class="fas fa-bolt"></i> FastGit</button>
          <button class="mirror-preset-btn" data-url="https://github.moeyy.xyz"><i class="fas fa-microscope"></i> Moeyy</button>
          <button class="mirror-preset-btn" data-url="custom"><i class="fas fa-pen"></i> 自定义</button>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <input type="text" id="mirrorUrl" placeholder="https://github.com" value="https://github.com">
        </div>
        <button class="btn btn-primary" onclick="saveMirrorSetting()">保存镜像设置</button>
        <div id="mirrorSaveMsg" style="font-size: 12px; margin-top: 4px;"></div>
      </div>
    </div>
  </div>

  <!-- OAuth设置 -->
  <div class="card" style="margin-bottom: 20px;">
    <div class="card-header"><h3>官方插件仓库</h3></div>
    <div class="card-body">
      <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px;">
        插件市场的官方 GitHub 仓库地址（owner/repo），用于 Fork 提交和同步
      </p>
      <div class="form-group">
        <label>仓库地址</label>
        <div style="display:flex; gap:8px;">
          <span style="line-height: 36px; color: var(--text-muted);">github.com/</span>
          <input type="text" id="officialRepo" placeholder="yuexia-php/plugins" style="flex:1;">
        </div>
        <div class="help-text">格式：owner/repo，例如 yuexia-php/plugins</div>
      </div>
      <button class="btn btn-primary" onclick="saveOfficialRepo()">保存仓库设置</button>
      <div id="repoSaveMsg" style="font-size: 12px; margin-top: 4px;"></div>
    </div>
  </div>

  <!-- OAuth设置 -->
  <div class="card">
    <div class="card-header"><h3>GitHub OAuth配置</h3></div>
    <div class="card-body">
      <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px;">
        需要在 <a href="https://github.com/settings/developers" target="_blank">GitHub Developer Settings</a> 创建OAuth App
      </p>
      <div class="form-group">
        <label>Client ID</label>
        <input type="text" id="clientId" placeholder="输入GitHub OAuth App的Client ID">
      </div>
      <div class="form-group">
        <label>Client Secret</label>
        <input type="password" id="clientSecret" placeholder="输入Client Secret">
        <div class="help-text">已保存的Secret将会被加密存储</div>
      </div>
      <div class="form-group">
        <label>Redirect URI</label>
        <input type="text" id="redirectUri" placeholder="如不填则自动使用当前地址">
        <div class="help-text">GitHub OAuth回调地址，默认使用当前服务器地址</div>
      </div>
      <button class="btn btn-primary" onclick="saveOAuthSettings()">保存OAuth设置</button>
      <div id="oauthSaveMsg" style="font-size: 12px; margin-top: 4px;"></div>
    </div>
  </div>
</div>

<script>
function loadGithubStatus() {
    fetch('api/github_auth.php?type=status')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var html = '';
            if (data.logged_in) {
                var u = data.user;
                html = '<div class="github-status">' +
                    '<img src="' + u.avatar + '" alt="avatar">' +
                    '<div class="info">' +
                        '<div class="name">' + (u.name || u.login) + '</div>' +
                        '<div class="login">@' + u.login + '</div>' +
                    '</div>' +
                    '<button class="btn btn-secondary" onclick="disconnectGithub()">断开连接</button>' +
                '</div>';
            } else {
                html = '<div class="github-status">' +
                    '<div style="flex:1; color: var(--text-muted);">未绑定GitHub账号</div>' +
                    '<button class="btn btn-primary" onclick="loginGithub()"><i class="fas fa-link"></i> 登录GitHub</button>' +
                '</div>';
            }
            document.getElementById('githubStatus').innerHTML = html;
        })
        .catch(function() {
            document.getElementById('githubStatus').innerHTML = '<div class="error">加载失败</div>';
        });
}

function loginGithub() {
    fetch('api/github_auth.php?type=login_url')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200 && data.url) {
                var authWindow = window.open(data.url, 'github-auth', 'width=800,height=700');
                var checkTimer = setInterval(function() {
                    fetch('api/github_auth.php?type=status')
                        .then(function(r) { return r.json(); })
                        .then(function(status) {
                            if (status.logged_in) {
                                clearInterval(checkTimer);
                                if (authWindow) authWindow.close();
                                loadGithubStatus();
                            }
                        });
                }, 2000);
                setTimeout(function() { clearInterval(checkTimer); }, 30000);
            } else {
                alert('获取授权地址失败: ' + (data.msg || '未知错误'));
            }
        });
}

function disconnectGithub() {
    if (!confirm('确定要断开GitHub账号连接吗？')) return;
    fetch('api/github_auth.php?type=logout')
        .then(function() { loadGithubStatus(); });
}

function loadSettings() {
    fetch('api/github_auth.php?type=get_settings')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.code === 200) {
                var s = data.settings;
                document.getElementById('clientId').value = s.github_client_id || '';
                if (s.github_client_secret_masked) {
                    document.getElementById('clientSecret').value = s.github_client_secret_masked;
                    document.getElementById('clientSecret').dataset.masked = '1';
                }
                document.getElementById('redirectUri').value = s.github_redirect_uri || '';
                document.getElementById('mirrorUrl').value = s.mirror_url || 'https://github.com';
                highlightMirrorPreset(s.mirror_url || 'https://github.com');
                document.getElementById('officialRepo').value = s.official_repo || 'yuexia-php/plugins';
            }
        });
}

function saveOAuthSettings() {
    var btn = event.target;
    btn.disabled = true;
    btn.textContent = '保存中...';

    var formData = 'type=save_settings&csrf_token=' + getCsrfToken();
    formData += '&github_client_id=' + encodeURIComponent(document.getElementById('clientId').value);

    var secret = document.getElementById('clientSecret').value;
    if (!document.getElementById('clientSecret').dataset.masked) {
        formData += '&github_client_secret=' + encodeURIComponent(secret);
    }

    formData += '&github_redirect_uri=' + encodeURIComponent(document.getElementById('redirectUri').value);
    formData += '&mirror_url=' + encodeURIComponent(document.getElementById('mirrorUrl').value);
    formData += '&official_repo=' + encodeURIComponent(document.getElementById('officialRepo').value);

    fetch('api/github_auth.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var msg = document.getElementById('oauthSaveMsg');
        if (data.code === 200) {
            msg.innerHTML = '<span style="color: var(--success);"><i class="fas fa-check-circle"></i> 保存成功</span>';
        } else {
            msg.innerHTML = '<span style="color: var(--danger);"><i class="fas fa-times-circle"></i> ' + data.msg + '</span>';
        }
        setTimeout(function() { msg.innerHTML = ''; }, 3000);
    })
    .catch(function() {
        document.getElementById('oauthSaveMsg').innerHTML = '<span style="color: var(--danger);"><i class="fas fa-times-circle"></i> 保存失败</span>';
    })
    .finally(function() {
        btn.disabled = false;
        btn.textContent = '保存OAuth设置';
    });
}

function saveMirrorSetting() {
    var url = document.getElementById('mirrorUrl').value.trim();
    if (!url) { alert('请输入镜像站地址'); return; }

    fetch('api/github_auth.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'type=save_settings&csrf_token=' + getCsrfToken() + '&mirror_url=' + encodeURIComponent(url)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var msg = document.getElementById('mirrorSaveMsg');
        if (data.code === 200) {
            msg.innerHTML = '<span style="color: var(--success);"><i class="fas fa-check-circle"></i> 镜像站已设置为: ' + url + '</span>';
            highlightMirrorPreset(url);
        } else {
            msg.innerHTML = '<span style="color: var(--danger);"><i class="fas fa-times-circle"></i> ' + data.msg + '</span>';
        }
        setTimeout(function() { msg.innerHTML = ''; }, 3000);
    });
}

function highlightMirrorPreset(url) {
    document.querySelectorAll('.mirror-preset-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.url === url);
    });
}

function saveOfficialRepo() {
    var repo = document.getElementById('officialRepo').value.trim();
    if (!repo) { alert('请输入仓库地址'); return; }
    if (repo.split('/').length !== 2) { alert('格式错误，请输入 owner/repo 格式'); return; }

    var btn = event.target;
    btn.disabled = true;
    btn.textContent = '保存中...';

    fetch('api/github_auth.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'type=save_settings&csrf_token=' + getCsrfToken() + '&official_repo=' + encodeURIComponent(repo)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var msg = document.getElementById('repoSaveMsg');
        if (data.code === 200) {
            msg.innerHTML = '<span style="color: var(--success);"><i class="fas fa-check-circle"></i> 仓库已设置为: ' + repo + '</span>';
        } else {
            msg.innerHTML = '<span style="color: var(--danger);"><i class="fas fa-times-circle"></i> ' + data.msg + '</span>';
        }
        setTimeout(function() { msg.innerHTML = ''; }, 3000);
    })
    .catch(function() {
        document.getElementById('repoSaveMsg').innerHTML = '<span style="color: var(--danger);"><i class="fas fa-times-circle"></i> 保存失败</span>';
    })
    .finally(function() {
        btn.disabled = false;
        btn.textContent = '保存仓库设置';
    });
}

document.getElementById('mirrorPresets').addEventListener('click', function(e) {
    if (e.target.classList.contains('mirror-preset-btn')) {
        var url = e.target.dataset.url;
        if (url === 'custom') {
            document.getElementById('mirrorUrl').focus();
            return;
        }
        document.getElementById('mirrorUrl').value = url;
        highlightMirrorPreset(url);
    }
});

function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

loadGithubStatus();
loadSettings();
</script>

<?php require_once('footer.php'); ?>
