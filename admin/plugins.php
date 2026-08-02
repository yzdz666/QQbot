<?php
/**
 * 管理后台 - 插件管理
 */
// 禁止浏览器缓存，确保每次刷新都从服务器获取最新状态
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pageTitle = '插件管理';
require_once('header.php');

// 获取所有机器人
$bots = getBots();

// 当前选中的机器人 AppID
$selectedAppid = isset($_GET['appid']) ? $_GET['appid'] : '';

// 校验选中的 AppID 是否真实存在
$selectedBot = null;
if ($selectedAppid !== '') {
    foreach ($bots as $b) {
        if ($b['appid'] === $selectedAppid) {
            $selectedBot = $b;
            break;
        }
    }
    if (!$selectedBot) {
        $selectedAppid = '';
    }
}

// 扫描插件目录（../plugin/*.php 相对于 admin/ 目录）
$pluginDir = __DIR__ . '/../plugin/';
$plugins = [];
if (is_dir($pluginDir)) {
    foreach (glob($pluginDir . '*.php') as $file) {
        $plugins[] = [
            'name'     => basename($file, '.php'),
            'filename' => basename($file),
            'size'     => filesize($file),
            'mtime'    => filemtime($file),
        ];
    }
    // 按插件名排序
    usort($plugins, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
}

// 获取当前机器人各插件的启用状态：无记录视为默认启用(1)
$pluginStatus = [];
if ($selectedAppid !== '') {
    $rows = db()->fetchAll(
        "SELECT plugin_name, enabled FROM plugin_status WHERE appid = ?",
        [$selectedAppid]
    );
    foreach ($rows as $row) {
        $pluginStatus[$row['plugin_name']] = intval($row['enabled']);
    }
}

// 文件大小格式化
function formatFileSize($bytes)
{
    $bytes = intval($bytes);
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
?>

<div class="page-header">
  <h2>插件管理</h2>
  <div class="actions">
    <button class="btn btn-outline" onclick="showCreateModal()">+ 新建插件</button>
    <button class="btn btn-primary" onclick="showUploadModal()">+ 上传插件</button>
  </div>
</div>

<!-- ==================== 机器人选择器 ==================== -->
<div class="card mb-3">
  <div class="card-body">
    <div class="form-group" style="margin-bottom:0;">
      <label for="botSelector">选择机器人</label>
      <select id="botSelector" class="form-control" onchange="selectBot(this.value)" style="max-width:420px;">
        <option value="">-- 请选择机器人 --</option>
        <?php foreach ($bots as $bot):
          $optLabel = $bot['appid'];
          if (!empty($bot['nickname'])) {
            $optLabel .= ' (' . $bot['nickname'] . ')';
          }
        ?>
        <option value="<?= htmlspecialchars($bot['appid']) ?>" <?= $selectedAppid === $bot['appid'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($optLabel) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <div class="form-hint">选择机器人后，可在下方为该机器人单独启用或禁用插件；未选择时仅可查看与删除插件文件。</div>
    </div>
  </div>
</div>

<!-- ==================== 插件列表 ==================== -->
<div class="card">
  <div class="card-header">
    <h3>
      插件列表（共 <?= count($plugins) ?> 个）
      <?php if ($selectedBot): ?>
        <span class="text-muted" style="font-size:13px; font-weight:400; margin-left:4px;">
          · 当前机器人：<?= htmlspecialchars($selectedBot['appid']) ?>
          <?php if (!empty($selectedBot['nickname'])): ?>（<?= htmlspecialchars($selectedBot['nickname']) ?>）<?php endif; ?>
        </span>
      <?php endif; ?>
    </h3>
  </div>
  <div class="card-body no-padding">
    <?php if (empty($plugins)): ?>
      <div class="empty-state">
        <div class="empty-icon">--</div>
        <p>暂无插件，点击右上角"上传插件"添加</p>
      </div>
    <?php else: ?>
      <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>插件名</th>
            <th>文件大小</th>
            <th>最后修改时间</th>
            <?php if ($selectedAppid !== ''): ?>
            <th>状态</th>
            <?php endif; ?>
            <th class="text-right">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($plugins as $plugin):
            $displayName = $plugin['name'];
            $enabled     = isset($pluginStatus[$plugin['name']]) ? intval($pluginStatus[$plugin['name']]) : 1;
            $isOn        = ($enabled === 1);
            $nameJs      = json_encode($displayName, JSON_UNESCAPED_UNICODE);
          ?>
          <tr>
            <td>
              <span style="font-weight:500;"><?= htmlspecialchars($displayName) ?></span>
              <div class="text-muted" style="font-size:12px;"><?= htmlspecialchars($plugin['filename']) ?></div>
            </td>
            <td class="text-muted"><?= formatFileSize($plugin['size']) ?></td>
            <td class="text-muted" style="white-space:nowrap;"><?= date('Y-m-d H:i:s', $plugin['mtime']) ?></td>
            <?php if ($selectedAppid !== ''): ?>
            <td>
              <label class="switch">
                <input type="checkbox" <?= $isOn ? 'checked' : '' ?> onchange='togglePlugin(<?= $nameJs ?>, this)'>
                <span class="slider"></span>
              </label>
              <span class="badge <?= $isOn ? 'badge-success' : 'badge-secondary' ?>" style="margin-left:6px;">
                <?= $isOn ? '已启用' : '已禁用' ?>
              </span>
            </td>
            <?php endif; ?>
            <td class="text-right">
              <button class="btn btn-outline btn-sm" onclick='openEditModal(<?= $nameJs ?>)'>编辑</button>
              <button class="btn btn-danger btn-sm" onclick='deletePluginConfirm(<?= $nameJs ?>)'>删除</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ==================== 上传插件 模态框 ==================== -->
<div class="modal-overlay" id="uploadModal" style="display:none;">
  <div class="modal">
    <div class="modal-header">上传插件</div>
    <div class="modal-body">
      <div class="form-group">
        <label for="plugin_file">插件文件 <span class="text-danger">*</span></label>
        <input type="file" id="plugin_file" class="form-control" accept=".php">
        <div class="form-hint">仅支持 .php 文件，文件名只允许字母、数字、横杠、下划线和中文。同名文件将被覆盖。</div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeUploadModal()">取消</button>
      <button class="btn btn-primary" id="uploadBtn" onclick="uploadPlugin()">上传</button>
    </div>
  </div>
</div>

<!-- ==================== 编辑插件 模态框 ==================== -->
<div class="modal-overlay" id="editModal" style="display:none;">
  <div class="modal" style="max-width:800px;">
    <div class="modal-header">编辑插件 - <span id="editPluginName"></span></div>
    <div class="modal-body">
      <div class="form-group">
        <label>插件代码</label>
        <textarea id="pluginContent" class="form-control" style="min-height:400px; font-family:'SF Mono','Consolas','Monaco',monospace; font-size:12px; resize:vertical;" placeholder="加载中..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeEditModal()">取消</button>
      <button class="btn btn-primary" id="savePluginBtn" onclick="savePlugin()">保存</button>
    </div>
  </div>
</div>

<!-- ==================== 新建插件 模态框 ==================== -->
<div class="modal-overlay" id="createModal" style="display:none;">
  <div class="modal">
    <div class="modal-header">新建插件</div>
    <div class="modal-body">
      <div class="form-group">
        <label>插件名称 <span class="text-danger">*</span></label>
        <input type="text" id="createPluginName" class="form-control" placeholder="例如：my_plugin 或 测试插件" required>
        <div class="form-hint">仅允许字母、数字、下划线、横杠和中文，不需加 .php 后缀</div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeCreateModal()">取消</button>
      <button class="btn btn-primary" id="createBtn" onclick="createPlugin()">创建</button>
    </div>
  </div>
</div>

<script>
// 当前选中的机器人 AppID（供切换状态使用）
var selectedAppid = <?= json_encode($selectedAppid, JSON_UNESCAPED_UNICODE) ?>;

// ==================== 通用 AJAX 调用 ====================
// isForm 为 true 时，data 为 FormData 对象，使用 multipart/form-data 方式发送
function apiCall(action, data, callback, isForm) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php?action=' + encodeURIComponent(action), true);
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
            // HTTP 401 表示会话过期，需要重新登录
            if (xhr.status === 401) {
                alert('登录已过期，请重新登录');
                window.location.href = 'login.php';
                return;
            }
            var res;
            try {
                res = JSON.parse(xhr.responseText);
            } catch (e) {
                // 提供更详细的错误信息，包含响应内容片段和状态码
                var snippet = (xhr.responseText || '').substring(0, 300);
                res = { success: false, message: '响应解析失败 (HTTP ' + xhr.status + '): ' + snippet };
            }
            callback(res);
        }
    };
    xhr.onerror = function() {
        callback({ success: false, message: '网络请求失败，请检查网络连接' });
    };
    if (isForm) {
        xhr.send(data); // FormData 自动设置 multipart/form-data 边界
    } else {
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        var params = [];
        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
            }
        }
        xhr.send(params.join('&'));
    }
}

// ==================== 机器人选择 ====================
function selectBot(appid) {
    if (appid) {
        window.location.href = 'plugins.php?appid=' + encodeURIComponent(appid);
    } else {
        window.location.href = 'plugins.php';
    }
}

// ==================== 切换插件启用状态 ====================
function togglePlugin(pluginName, checkbox) {
    if (!selectedAppid) {
        checkbox.checked = !checkbox.checked;
        alert('请先选择机器人');
        return;
    }
    var enabled = checkbox.checked ? 1 : 0;
    // 禁用开关防止重复点击
    checkbox.disabled = true;
    apiCall('plugin_toggle', {
        appid: selectedAppid,
        plugin_name: pluginName,
        enabled: enabled
    }, function (res) {
        checkbox.disabled = false;
        if (!res.success) {
            checkbox.checked = !checkbox.checked; // 回滚
            alert(res.message || '状态更新失败');
        } else {
            // 验证服务器返回的实际状态
            var actualEnabled = (res.enabled !== undefined) ? res.enabled : enabled;
            checkbox.checked = (actualEnabled == 1);
            // 同步行内徽章文字
            var cell = checkbox.closest('td');
            if (cell) {
                var badge = cell.querySelector('.badge');
                if (badge) {
                    if (checkbox.checked) {
                        badge.className = 'badge badge-success';
                        badge.textContent = '已启用';
                    } else {
                        badge.className = 'badge badge-secondary';
                        badge.textContent = '已禁用';
                    }
                }
            }
        }
    });
}

// ==================== 删除插件 ====================
function deletePluginConfirm(pluginName) {
    if (!confirm('确定要删除插件「' + pluginName + '」吗？\n该操作将删除插件文件，并清除所有机器人的该插件状态，且不可恢复。')) {
        return;
    }
    apiCall('plugin_delete', { plugin_name: pluginName }, function (res) {
        if (res.success) {
            alert('插件已删除');
            location.reload();
        } else {
            alert(res.message || '删除失败');
        }
    });
}

// ==================== 上传插件 模态框 ====================
function showUploadModal() {
    document.getElementById('plugin_file').value = '';
    document.getElementById('uploadModal').style.display = 'flex';
}

function closeUploadModal() {
    document.getElementById('uploadModal').style.display = 'none';
}

// 点击遮罩层关闭上传模态框
document.getElementById('uploadModal').addEventListener('click', function (e) {
    if (e.target === this) closeUploadModal();
});

// ==================== 新建插件 模态框 ====================
function showCreateModal() {
    document.getElementById('createPluginName').value = '';
    document.getElementById('createModal').style.display = 'flex';
    setTimeout(function() { document.getElementById('createPluginName').focus(); }, 100);
}

function closeCreateModal() {
    document.getElementById('createModal').style.display = 'none';
}

document.getElementById('createModal').addEventListener('click', function (e) {
    if (e.target === this) closeCreateModal();
});

function createPlugin() {
    var pluginName = document.getElementById('createPluginName').value.trim();
    if (!pluginName) {
        alert('请输入插件名称');
        return;
    }
    if (!/^[\w\-\u4e00-\u9fff\u3400-\u4dbf]+$/.test(pluginName)) {
        alert('插件名只允许字母、数字、下划线、横杠和中文');
        return;
    }

    var btn = document.getElementById('createBtn');
    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = '创建中...';

    apiCall('plugin_create', { plugin_name: pluginName }, function (res) {
        btn.disabled = false;
        btn.textContent = originalText;
        if (res.success) {
            alert('插件创建成功');
            closeCreateModal();
            location.reload();
        } else {
            alert(res.message || '创建失败');
        }
    });
}

// 回车键提交新建插件
document.getElementById('createPluginName').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        createPlugin();
    }
});

// ESC 键关闭
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeUploadModal();
        closeEditModal();
        closeCreateModal();
    }
});

function uploadPlugin() {
    var fileInput = document.getElementById('plugin_file');
    if (!fileInput.files || !fileInput.files.length) {
        alert('请选择插件文件');
        return;
    }
    var file = fileInput.files[0];
    // 客户端校验文件名（与服务端规则一致，支持中文）
    if (!/^[\w\-\u4e00-\u9fff\u3400-\u4dbf]+\.php$/.test(file.name)) {
        alert('文件名只允许字母、数字、横杠、下划线和中文，且必须为 .php 后缀');
        return;
    }
    var formData = new FormData();
    formData.append('plugin_file', file);

    var btn = document.getElementById('uploadBtn');
    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = '上传中...';

    apiCall('plugin_upload', formData, function (res) {
        btn.disabled = false;
        btn.textContent = originalText;
        if (res.success) {
            alert('插件上传成功');
            location.reload();
        } else {
            alert(res.message || '上传失败');
        }
    }, true);
}

// ==================== 编辑插件 ====================
var currentEditingPlugin = null;

function openEditModal(pluginName) {
    currentEditingPlugin = pluginName;
    document.getElementById('editPluginName').textContent = pluginName;
    document.getElementById('pluginContent').value = '加载中...';
    document.getElementById('editModal').style.display = 'flex';

    // 加载插件内容
    apiCall('plugin_read', { plugin_name: pluginName }, function (res) {
        if (res.success) {
            document.getElementById('pluginContent').value = res.content;
        } else {
            document.getElementById('pluginContent').value = '';
            alert(res.message || '读取失败');
        }
    });
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    currentEditingPlugin = null;
}

// 点击遮罩层关闭编辑模态框
document.getElementById('editModal').addEventListener('click', function (e) {
    if (e.target === this) closeEditModal();
});

function savePlugin() {
    if (!currentEditingPlugin) return;
    var content = document.getElementById('pluginContent').value;
    var btn = document.getElementById('savePluginBtn');
    btn.disabled = true;
    var originalText = btn.textContent;
    btn.textContent = '保存中...';

    // 使用base64编码内容，确保中文和特殊字符正确传输
    var encodedContent = btoa(unescape(encodeURIComponent(content)));
    var formData = new FormData();
    formData.append('plugin_name', currentEditingPlugin);
    formData.append('content', encodedContent);
    formData.append('encoding', 'base64');

    apiCall('plugin_write', formData, function (res) {
        btn.disabled = false;
        btn.textContent = originalText;
        if (res.success) {
            alert('保存成功');
            closeEditModal();
        } else {
            alert(res.message || '保存失败');
        }
    }, true);
}
</script>

<?php require_once('footer.php'); ?>
