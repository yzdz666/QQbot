<?php
/**
 * 管理后台 - 机器人管理
 */
$pageTitle = '机器人管理';
require_once('header.php');

// 获取所有机器人
$bots = getBots();
?>

<div class="page-header">
  <h2>机器人管理</h2>
  <div class="actions">
    <button class="btn btn-primary" onclick="showAddModal()">+ 添加机器人</button>
  </div>
</div>

<?php if (isset($_GET['msg'])): ?>
<div class="alert alert-success"><?= htmlspecialchars($_GET['msg']) ?></div>
<?php endif; ?>

<!-- ==================== 机器人列表 ==================== -->
<div class="card">
  <div class="card-header">
    <h3>机器人列表（共 <?= count($bots) ?> 个）</h3>
  </div>
  <div class="card-body no-padding">
    <?php if (empty($bots)): ?>
      <div class="empty-state">
        <div class="empty-icon">--</div>
        <p>暂无机器人，点击右上角“添加机器人”开始创建</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>机器人</th>
            <th>环境</th>
            <th>状态</th>
            <th>WebSocket状态</th>
            <th>创建时间</th>
            <th class="text-right">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bots as $bot):
            $enabled    = intval($bot['enabled']) === 1;
            $wsEnabled  = intval($bot['ws_enabled']) === 1;
            $appid      = htmlspecialchars($bot['appid']);
            $env        = htmlspecialchars($bot['env'] ?? '正式');
            $createdAt  = htmlspecialchars($bot['created_at'] ?? '');
            $nickname   = trim($bot['nickname'] ?? '');
            $robotQq    = trim($bot['robot_qq'] ?? '');
            $avatar     = trim($bot['avatar'] ?? '');
          ?>
          <tr>
            <td>
              <div style="display:flex; align-items:center; gap:10px;">
                <div class="bot-avatar">
                  <?php if (!empty($avatar)): ?>
                    <img src="<?= htmlspecialchars($avatar) ?>" alt="头像" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="bot-avatar-default" style="display:none;"><?= mb_substr($appid, 0, 2) ?></div>
                  <?php else: ?>
                    <div class="bot-avatar-default"><?= mb_substr($appid, 0, 2) ?></div>
                  <?php endif; ?>
                </div>
                <div>
                  <div style="font-weight:500;"><?= $appid ?></div>
                  <?php if ($nickname !== '' || $robotQq !== ''): ?>
                    <div class="text-muted" style="font-size:12px;">
                      <?php if ($nickname !== ''): ?><?= htmlspecialchars($nickname) ?><?php endif; ?>
                      <?php if ($nickname !== '' && $robotQq !== ''): ?> · <?php endif; ?>
                      <?php if ($robotQq !== ''): ?>QQ:<?= htmlspecialchars($robotQq) ?><?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <?php if ($env === '沙箱'): ?>
                <span class="badge badge-warning">沙箱</span>
              <?php else: ?>
                <span class="badge badge-info">正式</span>
              <?php endif; ?>
            </td>
            <td>
              <label class="switch">
                <input type="checkbox" <?= $enabled ? 'checked' : '' ?> onchange="toggleBot('<?= $appid ?>', 'enabled', this)">
                <span class="slider"></span>
              </label>
              <span class="badge <?= $enabled ? 'badge-success' : 'badge-secondary' ?>" style="margin-left:6px;">
                <?= $enabled ? '启用' : '禁用' ?>
              </span>
            </td>
            <td>
              <label class="switch">
                <input type="checkbox" <?= $wsEnabled ? 'checked' : '' ?> onchange="toggleBot('<?= $appid ?>', 'ws_enabled', this)">
                <span class="slider"></span>
              </label>
              <span class="badge <?= $wsEnabled ? 'badge-success' : 'badge-secondary' ?>" style="margin-left:6px;">
                <?= $wsEnabled ? '已开启' : '未开启' ?>
              </span>
            </td>
            <td class="text-muted" style="white-space:nowrap;"><?= $createdAt ?></td>
            <td class="text-right">
              <button class="btn btn-outline btn-sm" onclick="fetchBotInfo('<?= $appid ?>', this)">获取信息</button>
              <button class="btn btn-outline btn-sm" onclick="showEditModal('<?= $appid ?>')">编辑</button>
              <button class="btn btn-danger btn-sm" onclick="deleteBotConfirm('<?= $appid ?>')">删除</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ==================== 添加/编辑 模态框 ==================== -->
<div class="modal-overlay" id="botModal" style="display:none;">
  <div class="modal">
    <div class="modal-header" id="modalTitle">添加机器人</div>
    <div class="modal-body">
      <div class="form-group">
        <label for="fld-appid">AppID <span class="text-danger">*</span></label>
        <input type="text" id="fld-appid" class="form-control" placeholder="请输入机器人 AppID">
        <div class="form-hint">机器人的唯一标识，添加后不可修改</div>
      </div>
      <div class="form-group">
        <label for="fld-secret">Secret <span class="text-danger">*</span></label>
        <div style="display:flex; gap:8px;">
          <input type="password" id="fld-secret" class="form-control" placeholder="请输入机器人 Secret" style="flex:1;">
          <button type="button" class="btn btn-outline btn-sm" onclick="toggleSecretVisibility()" style="white-space:nowrap;">显示</button>
        </div>
      </div>
      <div class="form-group">
        <label for="fld-env">环境</label>
        <select id="fld-env" class="form-control">
          <option value="正式">正式</option>
          <option value="沙箱">沙箱</option>
        </select>
      </div>
      <div class="form-group">
        <label for="fld-avatar">头像URL</label>
        <div style="display:flex; gap:8px;">
          <input type="text" id="fld-avatar" class="form-control" placeholder="https://q.qlogo.cn/..." style="flex:1;">
          <button type="button" class="btn btn-outline btn-sm" onclick="autoFetchInModal()" style="white-space:nowrap;" id="fld-fetch-btn">自动获取</button>
        </div>
        <div class="form-hint">机器人头像图片地址，留空则显示默认头像。点击"自动获取"从QQ API获取</div>
      </div>
      <div class="form-group">
        <label for="fld-nickname">昵称</label>
        <input type="text" id="fld-nickname" class="form-control" placeholder="机器人昵称（可选）">
        <div class="form-hint">显示在机器人列表中，方便区分</div>
      </div>
      <div class="form-group">
        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
          <input type="checkbox" id="fld-ws-enabled" style="width:auto;">
          启用 WebSocket（主动接入模式）
        </label>
        <div class="form-hint">勾选后机器人将通过 WebSocket 主动连接服务端</div>
      </div>
      <div class="form-group">
        <label for="fld-ws-url">WebSocket 地址</label>
        <input type="text" id="fld-ws-url" class="form-control" placeholder="例如 wss://api.sgroup.qq.com/websocket">
        <div class="form-hint">仅当启用 WebSocket 时生效</div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal()">取消</button>
      <button class="btn btn-primary" id="saveBtn" onclick="saveBot()">保存</button>
    </div>
  </div>
</div>

<script>
// 将 PHP 数据传递给 JavaScript
var botsData = <?= json_encode($bots, JSON_UNESCAPED_UNICODE) ?>;
var editingAppid = null; // null 表示新增模式

// ==================== 通用 AJAX 调用 ====================
function apiCall(action, data, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php?action=' + encodeURIComponent(action), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            var res;
            try {
                res = JSON.parse(xhr.responseText);
            } catch (e) {
                res = { success: false, message: '响应解析失败' };
            }
            callback(res);
        }
    };
    var params = [];
    for (var key in data) {
        if (data.hasOwnProperty(key)) {
            params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
        }
    }
    xhr.send(params.join('&'));
}

// ==================== 模态框控制 ====================
function showAddModal() {
    editingAppid = null;
    document.getElementById('modalTitle').textContent = '添加机器人';
    var appidInput = document.getElementById('fld-appid');
    appidInput.value = '';
    appidInput.disabled = false;
    document.getElementById('fld-secret').value = '';
    document.getElementById('fld-secret').type = 'password';
    document.getElementById('fld-env').value = '正式';
    document.getElementById('fld-avatar').value = '';
    document.getElementById('fld-nickname').value = '';
    document.getElementById('fld-ws-enabled').checked = false;
    document.getElementById('fld-ws-url').value = '';
    document.getElementById('botModal').style.display = 'flex';
    appidInput.focus();
}

function showEditModal(appid) {
    var bot = null;
    for (var i = 0; i < botsData.length; i++) {
        if (botsData[i].appid === appid) { bot = botsData[i]; break; }
    }
    if (!bot) { alert('未找到机器人信息'); return; }
    editingAppid = appid;
    document.getElementById('modalTitle').textContent = '编辑机器人';
    var appidInput = document.getElementById('fld-appid');
    appidInput.value = bot.appid;
    appidInput.disabled = true;
    document.getElementById('fld-secret').value = bot.secret || '';
    document.getElementById('fld-secret').type = 'password';
    document.getElementById('fld-env').value = bot.env || '正式';
    document.getElementById('fld-avatar').value = bot.avatar || '';
    document.getElementById('fld-nickname').value = bot.nickname || '';
    document.getElementById('fld-ws-enabled').checked = (parseInt(bot.ws_enabled) === 1);
    document.getElementById('fld-ws-url').value = bot.ws_url || '';
    document.getElementById('botModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('botModal').style.display = 'none';
}

// 点击遮罩层关闭
document.getElementById('botModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// ESC 键关闭
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});

// ==================== Secret 显示/隐藏 ====================
function toggleSecretVisibility() {
    var input = document.getElementById('fld-secret');
    input.type = (input.type === 'password') ? 'text' : 'password';
}

// ==================== 保存机器人（新增/编辑）====================
function saveBot() {
    var appid = document.getElementById('fld-appid').value.trim();
    var secret = document.getElementById('fld-secret').value.trim();
    var env = document.getElementById('fld-env').value;
    var avatar = document.getElementById('fld-avatar').value.trim();
    var nickname = document.getElementById('fld-nickname').value.trim();
    var wsEnabled = document.getElementById('fld-ws-enabled').checked ? 1 : 0;
    var wsUrl = document.getElementById('fld-ws-url').value.trim();

    if (!appid || !secret) {
        alert('AppID 和 Secret 不能为空');
        return;
    }

    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = '保存中...';

    if (editingAppid === null) {
        // 新增：先添加基本信息，再更新其他设置
        apiCall('bot_add', { appid: appid, secret: secret, env: env }, function(res) {
            if (!res.success) {
                btn.disabled = false;
                btn.textContent = originalText;
                alert(res.message || '添加失败');
                return;
            }
            // 更新头像、昵称、WebSocket 相关字段
            apiCall('bot_update', { appid: appid, ws_enabled: wsEnabled, ws_url: wsUrl, avatar: avatar, nickname: nickname }, function(res2) {
                if (res2.success) {
                    // 自动获取机器人信息（头像、昵称等）
                    btn.textContent = '获取信息中...';
                    apiCall('bot_get_info', { appid: appid }, function(res3) {
                        btn.disabled = false;
                        btn.textContent = originalText;
                        if (res3.success) {
                            var msg = '机器人添加成功！\n已自动获取信息：\n';
                            msg += '昵称: ' + (res3.data.nickname || '（空）') + '\n';
                            msg += '头像: ' + (res3.data.avatar ? '已获取' : '（空）') + '\n';
                            msg += 'QQ号: ' + (res3.data.robot_qq || '（空）');
                            alert(msg);
                        } else {
                            alert('机器人添加成功，但自动获取信息失败：' + (res3.message || '请稍后手动点击"获取信息"'));
                        }
                        location.reload();
                    });
                } else {
                    btn.disabled = false;
                    btn.textContent = originalText;
                    alert('机器人已添加，但部分设置未保存：' + (res2.message || '未知错误'));
                    location.reload();
                }
            });
        });
    } else {
        // 编辑：一次性更新所有字段
        apiCall('bot_update', {
            appid: editingAppid,
            secret: secret,
            env: env,
            ws_enabled: wsEnabled,
            ws_url: wsUrl,
            avatar: avatar,
            nickname: nickname
        }, function(res) {
            btn.disabled = false;
            btn.textContent = originalText;
            if (res.success) {
                alert('更新成功');
                location.reload();
            } else {
                alert(res.message || '更新失败');
            }
        });
    }
}

// ==================== 删除机器人 ====================
function deleteBotConfirm(appid) {
    if (!confirm('确定要删除机器人「' + appid + '」吗？\n该操作将同时清除其插件配置，且不可恢复。')) {
        return;
    }
    apiCall('bot_delete', { appid: appid }, function(res) {
        if (res.success) {
            alert('已删除');
            location.reload();
        } else {
            alert(res.message || '删除失败');
        }
    });
}

// ==================== 切换状态（启用/禁用、WebSocket 开关）====================
function toggleBot(appid, field, checkbox) {
    var value = checkbox.checked ? 1 : 0;
    // 先记录原始状态，失败时回滚
    apiCall('bot_toggle', { appid: appid, field: field, value: value }, function(res) {
        if (!res.success) {
            checkbox.checked = !checkbox.checked; // 回滚
            alert(res.message || '状态更新失败');
        }
    });
}

// ==================== 获取机器人信息（头像、昵称）====================
function fetchBotInfo(appid, btn) {
    if (!confirm('确定要从QQ API获取机器人「' + appid + '」的头像和昵称吗？\n这将自动更新该机器人的信息。')) {
        return;
    }
    var originalText = btn ? btn.textContent : '获取信息';
    if (btn) { btn.disabled = true; btn.textContent = '获取中...'; }

    apiCall('bot_get_info', { appid: appid }, function(res) {
        if (btn) { btn.disabled = false; btn.textContent = originalText; }
        if (res.success) {
            var msg = '获取成功！\n';
            msg += '昵称: ' + (res.data.nickname || '（空）') + '\n';
            msg += '头像: ' + (res.data.avatar ? '已获取' : '（空）') + '\n';
            msg += 'QQ号: ' + (res.data.robot_qq || '（空）');
            alert(msg);
            location.reload();
        } else {
            alert(res.message || '获取失败');
        }
    });
}

// ==================== 在编辑模态框中自动获取信息 =====================
function autoFetchInModal() {
    var appid = document.getElementById('fld-appid').value.trim();
    var secret = document.getElementById('fld-secret').value.trim();
    if (!appid) {
        alert('请先填写AppID');
        return;
    }
    if (!secret) {
        alert('请先填写Secret');
        return;
    }

    var btn = document.getElementById('fld-fetch-btn');
    var originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '获取中...';

    // 先保存机器人信息（确保 secret 是最新的），再获取信息
    apiCall('bot_update', { appid: appid, secret: secret }, function(res) {
        if (!res.success) {
            // 如果更新失败，可能是因为机器人还不存在（新增模式）
            // 直接尝试获取
        }
        apiCall('bot_get_info', { appid: appid }, function(res2) {
            btn.disabled = false;
            btn.textContent = originalText;
            if (res2.success) {
                if (res2.data.avatar) {
                    document.getElementById('fld-avatar').value = res2.data.avatar;
                }
                if (res2.data.nickname) {
                    document.getElementById('fld-nickname').value = res2.data.nickname;
                }
                alert('获取成功！\n昵称: ' + (res2.data.nickname || '（空）') + '\n头像: ' + (res2.data.avatar ? '已获取' : '（空）'));
            } else {
                alert(res2.message || '获取失败，请检查AppID、Secret和环境设置');
            }
        });
    });
}
</script>

<?php require_once('footer.php'); ?>
