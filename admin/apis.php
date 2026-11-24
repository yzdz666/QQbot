<?php
/**
 * 管理后台 - 自定义 API（可视化配置：指令 / 事件 → 请求 → 取值 → 发送）
 */
$pageTitle = '自定义 API';
require_once('header.php');

$caSendTypes = function_exists('customapi发送类型') ? customapi发送类型() : [];
$caEventList = function_exists('customapi事件列表') ? customapi事件列表() : [];
?>

<style>
.ca-modal { width: 900px; max-width: 97%; max-height: 92vh; }
.ca-intro { border-left: 2px solid var(--ink); }
.ca-steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; }
.ca-step { padding: 2px 18px; border-right: 1px solid var(--line); }
.ca-step:last-child { border-right: 0; }
.ca-step b { display: block; font-size: 12.5px; margin-bottom: 5px; letter-spacing: -.01em; }
.ca-step span { display: block; font-size: 11.5px; line-height: 1.65; color: var(--ink-3); }
.ca-step em { font-style: normal; font-family: var(--mono); font-size: 11px; background: var(--surface-3); padding: 1px 4px; border-radius: 2px; }
@media (max-width: 860px) {
  .ca-steps { grid-template-columns: 1fr 1fr; }
  .ca-step { border-right: 0; border-bottom: 1px solid var(--line); padding: 10px 12px; }
  .ca-step:nth-child(odd) { border-right: 1px solid var(--line); }
  .ca-step:nth-child(n+3) { border-bottom: 0; }
}
.ca-toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.ca-toolbar .form-control { height: 32px; font-size: 12.5px; }
.ca-toolbar #caSearch { width: 220px; }
.ca-toolbar #caCat { width: 140px; }
.ca-item { display: flex; align-items: flex-start; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--line); }
.ca-item:last-child { border-bottom: 0; }
.ca-item:hover { background: #f6f6f4; }
.ca-item-ico { width: 34px; height: 34px; flex: 0 0 34px; border: 1px solid var(--line-2); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 16px; background: var(--surface-2); }
.ca-item-main { flex: 1 1 auto; min-width: 0; }
.ca-item-title { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ca-item-title strong { font-size: 13px; font-weight: 620; letter-spacing: -.01em; }
.ca-item-desc { font-size: 11.5px; color: var(--ink-3); margin-top: 4px; line-height: 1.6; }
.ca-item-meta { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 7px; font-size: 11px; color: var(--ink-2); }
.ca-item-meta code { font-size: 10.5px; background: var(--surface-3); padding: 1px 4px; border-radius: 2px; color: var(--ink-2); }
.ca-item-side { display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
.ca-sec { margin: 0 0 6px; padding: 18px 0 8px; font-size: 12px; font-weight: 650; letter-spacing: .02em; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.ca-sec:first-child { padding-top: 0; }
.ca-sec small { font-weight: 400; color: var(--ink-3); font-size: 11px; }
.ca-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 14px; }
.ca-grid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0 14px; }
@media (max-width: 720px) { .ca-grid2, .ca-grid3 { grid-template-columns: 1fr; } }
.ca-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
.ca-chip { border: 1px solid var(--line); background: var(--surface-2); color: var(--ink-2); font-family: var(--mono); font-size: 10.5px; padding: 2px 6px; border-radius: 2px; cursor: pointer; }
.ca-chip:hover { background: var(--ink); color: #fff; border-color: var(--ink); }
.ca-extracts { width: 100%; border-collapse: collapse; }
.ca-extracts th { text-align: left; font-size: 10.5px; color: var(--ink-3); font-weight: 500; padding: 0 6px 5px; }
.ca-extracts td { padding: 3px 6px; vertical-align: middle; }
.ca-extracts .form-control { height: 30px; font-size: 12px; font-family: var(--mono); }
.ca-extracts .ca-del { border: 0; background: transparent; color: var(--ink-3); cursor: pointer; font-size: 15px; line-height: 1; padding: 4px 6px; }
.ca-extracts .ca-del:hover { color: var(--danger); }
.ca-path { display: flex; gap: 8px; align-items: center; width: 100%; text-align: left; border: 1px solid var(--line); background: var(--surface); padding: 6px 9px; border-radius: 2px; cursor: pointer; font-size: 11px; margin-bottom: 5px; }
.ca-path:hover { border-color: var(--ink); background: var(--surface-2); }
.ca-path code { font-size: 10.5px; color: var(--ink); flex: 0 0 auto; }
.ca-path span { color: var(--ink-3); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ca-path b { margin-left: auto; font-weight: 500; font-size: 10px; color: var(--ink-3); flex: 0 0 auto; }
.ca-out { margin-top: 12px; border: 1px solid var(--line); background: var(--surface-3); padding: 10px 12px; font-family: var(--mono); font-size: 11px; line-height: 1.75; white-space: pre-wrap; word-break: break-all; max-height: 260px; overflow: auto; display: none; }
.ca-empty { text-align: center; padding: 52px 20px; }
.ca-empty h3 { font-size: 14px; margin: 0 0 8px; }
.ca-empty p { font-size: 12px; color: var(--ink-3); margin: 0 0 16px; line-height: 1.7; }
.ca-note { font-size: 11.5px; color: var(--ink-3); line-height: 1.7; background: var(--surface-2); border: 1px solid var(--line); padding: 9px 11px; border-radius: 2px; }
.ca-note code { font-size: 10.5px; background: var(--surface-3); padding: 1px 4px; }
.ca-foot-status { font-size: 12px; margin-right: auto; display: none; }
</style>

<div class="page-header">
  <div>
    <h1>自定义 API</h1>
    <p class="page-desc">填表单就能把任意接口接成机器人能力：消息指令或任意事件触发 → 请求接口 → 从返回内容取值 → 用文字、图片、语音、文卡、Ark、按钮等函数发送。不用写代码，保存即生效。</p>
  </div>
  <div class="actions">
    <button type="button" class="btn btn-outline" onclick="openEditor(0, true)">从模板新建</button>
    <button type="button" class="btn btn-primary" onclick="openEditor(0)">+ 新建 API</button>
  </div>
</div>

<div class="card mb-3 ca-intro">
  <div class="card-body" style="padding:0;">
    <div class="ca-steps">
      <div class="ca-step">
        <b>① 定触发</b>
        <span>填触发词，比如 <em>天气</em>；也可以选「事件触发」，进群、退群、表态都能触发。</span>
      </div>
      <div class="ca-step">
        <b>② 填接口</b>
        <span>把接口文档里的地址粘进来，用 <em>{参数}</em> 代替要传的值；POST 就在 Body 里写 JSON。</span>
      </div>
      <div class="ca-step">
        <b>③ 取字段</b>
        <span>返回 <em>{"data":{"text":"晴"}}</em> 就填 <em>data.text</em>，变量名写「结果」。</span>
      </div>
      <div class="ca-step">
        <b>④ 选发送方式</b>
        <span>文字、图片、语音、文卡、Ark、按钮……也可以「组合发送」，一条指令连发多条。</span>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header" style="flex-wrap:wrap; gap:10px;">
    <h3>API 列表 <span class="badge badge-secondary" id="caCount" style="margin-left:6px;">0</span></h3>
    <div class="ca-toolbar">
      <input type="text" class="form-control" id="caSearch" placeholder="搜索名称 / 指令 / 地址" oninput="renderList()">
      <select class="form-control" id="caCat" onchange="renderList()">
        <option value="">全部分类</option>
      </select>
    </div>
  </div>
  <div class="card-body no-padding" id="caListWrap">
    <div class="ca-empty" style="padding:34px 20px; color:var(--ink-3); font-size:12px;">加载中…</div>
  </div>
</div>

<!-- ==================== 编辑器 ==================== -->
<div class="modal-overlay" id="caMask" style="display:none;" onclick="if(event.target===this)closeEditor()">
  <div class="modal ca-modal" role="dialog" aria-modal="true" aria-label="编辑自定义 API">
    <div class="modal-header">
      <h3 id="caModalTitle">新建 API</h3>
      <div style="display:flex; align-items:center; gap:10px;">
        <select class="form-control" id="caTpl" style="height:28px; font-size:11.5px; width:auto; display:none;" onchange="applyTemplate(this.value)">
          <option value="">套用示例模板…</option>
          <option value="hitokoto">示例：一言（GET + 纯文本）</option>
          <option value="json">结构：GET + JSON 取字段</option>
          <option value="post">结构：POST JSON + 取字段</option>
        </select>
        <button type="button" class="modal-close" onclick="closeEditor()" aria-label="关闭">&times;</button>
      </div>
    </div>

    <div class="modal-body">
      <div class="ca-sec">基本信息</div>
      <div class="ca-grid2">
        <div class="form-group">
          <label for="caName">名称 <span style="color:var(--danger)">*</span></label>
          <input type="text" class="form-control" id="caName" placeholder="例如：天气查询">
        </div>
        <div class="form-group">
          <label for="caCategory">分类</label>
          <input type="text" class="form-control" id="caCategory" placeholder="例如：生活、娱乐（可留空）">
        </div>
      </div>
      <div class="ca-grid2">
        <div class="form-group">
          <label for="caIcon">图标</label>
          <input type="text" class="form-control" id="caIcon" placeholder="一个 emoji，例如 ☀️" maxlength="8">
        </div>
        <div class="form-group">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin-top:24px;">
            <input type="checkbox" id="caEnabled" checked>
            <span>启用这条 API</span>
          </label>
        </div>
      </div>
      <div class="form-group">
        <label for="caDesc">说明（可选）</label>
        <input type="text" class="form-control" id="caDesc" placeholder="一句话说明这个指令做什么，方便以后自己看懂">
      </div>

      <div class="ca-sec">触发方式 <small>收到指定消息，或发生指定事件时调用</small></div>
      <div class="form-group">
        <label for="caTriggerType">什么时候调用</label>
        <select class="form-control" id="caTriggerType" onchange="onTriggerTypeChange()" style="max-width:340px;">
          <option value="message">收到指定消息（指令）</option>
          <option value="event">发生指定事件（进群、退群、表态…）</option>
        </select>
      </div>

      <div id="caMsgTrigger">
        <div class="form-group">
          <label for="caTrigger">触发词 <span style="color:var(--danger)">*</span></label>
          <input type="text" class="form-control" id="caTrigger" placeholder="天气, 查天气">
          <div class="form-hint">多个别名用逗号隔开。消息前后的 <code>/</code> 会自动忽略。</div>
        </div>
        <div class="form-group">
          <label for="caMatch">匹配方式</label>
          <select class="form-control" id="caMatch" style="max-width:340px;">
            <option value="exact">完全相等</option>
            <option value="prefix">开头匹配（带参数）</option>
            <option value="regex">正则匹配</option>
          </select>
        </div>
      </div>

      <div id="caEventTrigger" style="display:none;">
        <div class="ca-grid2">
          <div class="form-group">
            <label for="caEventGroup">事件分类</label>
            <select class="form-control" id="caEventGroup" onchange="onEventGroupChange()">
              <?php foreach (array_keys($caEventList) as $g): ?>
                <option value="<?= htmlspecialchars($g, ENT_QUOTES) ?>"><?= htmlspecialchars($g, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="caEventType">监听事件 <span style="color:var(--danger)">*</span></label>
            <select class="form-control" id="caEventType"></select>
          </div>
        </div>
        <div class="form-group">
          <label for="caEventFilter">触发条件（可选）</label>
          <textarea class="form-control" id="caEventFilter" rows="2" placeholder="d.group_openid != ''"></textarea>
          <div class="form-hint">一行一条，全部满足才触发，例如 <code>d.dismiss = false</code>、<code>d.content 包含 你好</code>。留空表示这个事件都触发。</div>
        </div>
        <div class="ca-note" style="margin-bottom:14px;">
          事件变量：<code>{事件}</code> 事件名、<code>{场景}</code> 场景名、<code>{事件.d.字段}</code> 事件 JSON 里的任意字段，
          例如进群事件用 <code>{事件.d.group_openid}</code> 取群、<code>{事件.d.member_openid}</code> 取人。
        </div>
      </div>

      <div class="ca-grid2">
        <div class="form-group">
          <label for="caScope">生效场景</label>
          <select class="form-control" id="caScope">
            <option value="all">群聊 + 私聊</option>
            <option value="group">仅群聊（含群事件）</option>
            <option value="private">仅私聊</option>
          </select>
        </div>
        <div class="form-group">
          <label for="caPerm">谁能用</label>
          <select class="form-control" id="caPerm">
            <option value="all">所有人</option>
            <option value="admin">仅机器人管理员</option>
          </select>
        </div>
      </div>

      <div class="ca-sec">请求接口 <small>支持 GET / POST，超时最长 60 秒</small></div>
      <div class="ca-grid3">
        <div class="form-group">
          <label for="caMethod">请求方式</label>
          <select class="form-control" id="caMethod">
            <option value="GET">GET</option>
            <option value="POST">POST</option>
          </select>
        </div>
        <div class="form-group" style="grid-column: span 2;">
          <label for="caTimeout">超时（秒）</label>
          <input type="number" class="form-control" id="caTimeout" value="20" min="3" max="60">
        </div>
      </div>
      <div class="form-group">
        <label for="caUrl">请求地址 <span style="color:var(--danger)">*</span></label>
        <input type="text" class="form-control" id="caUrl" placeholder="https://api.example.com/query?q={参数}">
        <div class="ca-chips">
          <span class="ca-chip" onclick="insertVar('参数')">{参数}</span>
          <span class="ca-chip" onclick="insertVar('1')">{1}</span>
          <span class="ca-chip" onclick="insertVar('2')">{2}</span>
          <span class="ca-chip" onclick="insertVar('用户')">{用户}</span>
          <span class="ca-chip" onclick="insertVar('群号')">{群号}</span>
          <span class="ca-chip" onclick="insertVar('消息')">{消息}</span>
          <span class="ca-chip" onclick="insertVar('时间')">{时间}</span>
          <span class="ca-chip" onclick="insertVar('结果')">{结果}</span>
        </div>
        <div class="form-hint">地址里的变量会自动做 URL 编码。点上面的标签插入到光标处。</div>
      </div>
      <div class="form-group">
        <label for="caHeaders">请求头（可选）</label>
        <textarea class="form-control" id="caHeaders" rows="2" placeholder="Authorization: Bearer 你的密钥&#10;Content-Type: application/json"></textarea>
        <div class="form-hint">每行一条，格式 <code>Key: Value</code>。密钥只存在本地数据库。</div>
      </div>
      <div class="form-group">
        <label for="caBody">请求 Body（POST 时用，可选）</label>
        <textarea class="form-control" id="caBody" rows="3" placeholder='{"q":"{参数}"}'></textarea>
        <div class="form-hint">没写 <code>Content-Type</code> 时默认按 JSON 发送；表单接口可写 <code>q={参数}&amp;lang=zh</code> 并自己加 Content-Type。</div>
      </div>

      <div class="ca-sec">
        返回取值
        <button type="button" class="btn btn-outline btn-sm" id="caProbeBtn" onclick="probeApi()">探测返回结构</button>
      </div>
      <div class="form-group">
        <label for="caParse">返回类型</label>
        <select class="form-control" id="caParse" style="max-width:280px;">
          <option value="json">JSON（按路径取字段）</option>
          <option value="text">纯文本（整段或正则）</option>
        </select>
      </div>
      <div class="form-group">
        <label>取值表</label>
        <table class="ca-extracts">
          <thead>
            <tr>
              <th style="width:120px;">变量名</th>
              <th>取值路径</th>
              <th style="width:120px;">默认值</th>
              <th style="width:30px;"></th>
            </tr>
          </thead>
          <tbody id="caExtracts"></tbody>
        </table>
        <button type="button" class="btn btn-outline btn-sm" style="margin-top:6px;" onclick="addExtract()">+ 添加取值</button>
        <div class="ca-note" style="margin-top:10px;">
          路径写法：<code>data.text</code> 取嵌套字段，<code>result.0.url</code> 取数组第一项，<code>list.*.url</code> 取数组里第一个有值的，<code>@body</code> 用整段返回，<code>re:正则</code> 用正则提取。
          变量名叫 <code>结果</code> 时，回复模板里的 <code>{结果}</code> 直接用它。
        </div>
      </div>
      <div id="caProbeOut" style="display:none; margin-bottom:12px;">
        <div class="ca-note" id="caProbeMeta" style="margin-bottom:8px;"></div>
        <div id="caProbePaths"></div>
        <pre class="ca-out" id="caProbeRaw" style="display:none;"></pre>
      </div>

      <div class="ca-sec">发送内容 <small>选一个发送函数，模板里用 {变量} 拼接</small></div>
      <div class="ca-grid2">
        <div class="form-group">
          <label for="caReplyType">发送方式</label>
          <select class="form-control" id="caReplyType" onchange="onReplyTypeChange()">
            <?php
            $caGroups = [];
            foreach ($caSendTypes as $k => $t) {
                $g = $t['group'] ?? '其他';
                $caGroups[$g][$k] = $t['label'];
            }
            foreach ($caGroups as $g => $items) {
                echo '<optgroup label="' . htmlspecialchars($g, ENT_QUOTES) . '">';
                foreach ($items as $k => $label) {
                    echo '<option value="' . htmlspecialchars($k, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
                }
                echo '</optgroup>';
            }
            ?>
          </select>
        </div>
        <div class="form-group">
          <label for="caResultVar">默认结果变量</label>
          <input type="text" class="form-control" id="caResultVar" value="结果" readonly>
          <div class="form-hint">取值表里叫「结果」的那一行会填进 <code>{结果}</code>。</div>
        </div>
      </div>
      <div class="ca-note" id="caSendHint"></div>
      <div id="caComboHelp" style="display:none; margin:8px 0 4px;">
        <div class="ca-chips" id="caComboChips"></div>
        <div class="form-hint">点一下插入一行骨架，再把参数换成变量或固定内容。格式：<code>函数名|参数1|参数2</code>，一行调用一个函数。</div>
      </div>
      <div class="form-group" style="margin-top:12px;">
        <label for="caReplyTpl">发送模板</label>
        <textarea class="form-control" id="caReplyTpl" rows="3" placeholder="例如：{结果}（数据来自公开接口）"></textarea>
        <div class="ca-chips">
          <span class="ca-chip" onclick="insertTplVar('结果')">{结果}</span>
          <span class="ca-chip" onclick="insertTplVar('参数')">{参数}</span>
          <span class="ca-chip" onclick="insertTplVar('用户')">{用户}</span>
          <span class="ca-chip" onclick="insertTplVar('消息ID')">{消息ID}</span>
          <span class="ca-chip" onclick="insertTplVar('时间')">{时间}</span>
          <span class="ca-chip" onclick="insertTplVar('状态码')">{状态码}</span>
        </div>
        <div class="form-hint" id="caTplHint">留空时默认发 <code>{结果}</code>。</div>
      </div>
      <div class="form-group">
        <label for="caErrorTpl">失败提示（可选）</label>
        <input type="text" class="form-control" id="caErrorTpl" placeholder="接口暂时不可用，请稍后再试">
        <div class="form-hint">HTTP 非 2xx、超时或返回为空时发这句。留空用默认提示。</div>
      </div>

      <div class="ca-sec">测试 <small>不会真的发消息，只在这里看结果</small></div>
      <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <div class="form-group" style="flex:1 1 220px; margin-bottom:10px;">
          <label for="caTestArgs">模拟参数</label>
          <input type="text" class="form-control" id="caTestArgs" placeholder="例如：北京（会填进 {参数}）">
          <div class="form-hint" id="caTestHint" style="display:none;">事件触发模式下，后台测试没有真实事件，<code>{事件.d.xxx}</code> 会是空值；保存后真实事件触发时会自动填充。</div>
        </div>
        <div class="form-group" style="margin-bottom:10px;">
          <button type="button" class="btn btn-outline" id="caTestBtn" onclick="testApi()">运行测试</button>
        </div>
      </div>
      <pre class="ca-out" id="caTestOut"></pre>
    </div>

    <div class="modal-footer">
      <span class="ca-foot-status" id="caFootMsg"></span>
      <button type="button" class="btn btn-danger" id="caDelBtn" style="display:none; margin-right:auto;" onclick="deleteApi()">删除</button>
      <button type="button" class="btn btn-outline" onclick="closeEditor()">取消</button>
      <button type="button" class="btn btn-primary" id="caSaveBtn" onclick="saveApi()">保存</button>
    </div>
  </div>
</div>

<script>
// ==================== 通用 AJAX ====================
function caCall(action, data, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php?action=' + encodeURIComponent(action), true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        var res;
        try { res = JSON.parse(xhr.responseText); }
        catch (e) { res = { success: false, message: '响应解析失败' }; }
        callback(res);
    };
    var params = [];
    for (var k in data) {
        if (data.hasOwnProperty(k)) params.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
    }
    xhr.send(params.join('&'));
}

function caEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function caVal(id) {
    var el = document.getElementById(id);
    return el ? String(el.value || '').trim() : '';
}

var CA = { list: [], current: null, editing: 0, lastFocus: null };
var CA_TYPES  = <?= json_encode($caSendTypes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var CA_EVENTS = <?= json_encode($caEventList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

// ==================== 列表 ====================
function loadList() {
    caCall('customapi_list', {}, function (res) {
        if (!res.success) {
            document.getElementById('caListWrap').innerHTML =
                '<div class="ca-empty"><p>加载失败：' + caEsc(res.message || '') + '</p></div>';
            return;
        }
        CA.list = res.list || [];
        document.getElementById('caCount').textContent = CA.list.length;

        var catSel = document.getElementById('caCat');
        var cur = catSel.value;
        catSel.innerHTML = '<option value="">全部分类</option>';
        (res.cats || []).forEach(function (c) {
            var o = document.createElement('option');
            o.value = c; o.textContent = c;
            if (c === cur) o.selected = true;
            catSel.appendChild(o);
        });

        renderList();
    });
}

function renderList() {
    var wrap = document.getElementById('caListWrap');
    var kw = caVal('caSearch').toLowerCase();
    var cat = caVal('caCat');
    var list = CA.list.filter(function (it) {
        if (cat && (it.category || '') !== cat) return false;
        if (!kw) return true;
        var hay = [it.name, it.trigger, it.url, it.description, it.category].join(' ').toLowerCase();
        return hay.indexOf(kw) >= 0;
    });

    if (!CA.list.length) {
        wrap.innerHTML =
            '<div class="ca-empty">' +
            '<h3>还没有自定义 API</h3>' +
            '<p>用模板一键生成一个能跑的例子，再改成自己的接口最容易上手。</p>' +
            '<button type="button" class="btn btn-primary" onclick="openEditor(0, true)">从模板新建</button>' +
            '</div>';
        return;
    }
    if (!list.length) {
        wrap.innerHTML = '<div class="ca-empty"><p>没有匹配的 API，换个关键词试试。</p></div>';
        return;
    }

    var html = '';
    list.forEach(function (it) {
        var triggers = String(it.trigger || '').split(/[\r\n,，、]+/).filter(function (s) { return s.trim(); });
        var t0 = triggers[0] || '';
        var more = triggers.length > 1 ? ' +' + (triggers.length - 1) : '';
        var triggerMeta = (it.trigger_type === 'event')
            ? '<span>事件 <code>' + caEsc(it.event_type || '未选择') + '</code></span>'
            : '<span>指令 <code>' + caEsc(t0) + more + '</code></span>';
        html += '<div class="ca-item">' +
            '<div class="ca-item-ico">' + caEsc(it.icon || '⌘') + '</div>' +
            '<div class="ca-item-main">' +
                '<div class="ca-item-title">' +
                    '<strong>' + caEsc(it.name) + '</strong>' +
                    (it.category ? '<span class="badge badge-secondary">' + caEsc(it.category) + '</span>' : '') +
                    (it.enabled == 1 ? '' : '<span class="badge badge-warning">已停用</span>') +
                '</div>' +
                (it.description ? '<div class="ca-item-desc">' + caEsc(it.description) + '</div>' : '') +
                '<div class="ca-item-meta">' +
                    triggerMeta +
                    '<span>' + caEsc(it.method) + ' <code>' + caEsc(shortUrl(it.url)) + '</code></span>' +
                    '<span>' + caEsc(replyLabel(it.reply_type)) + '</span>' +
                '</div>' +
            '</div>' +
            '<div class="ca-item-side">' +
                '<label class="switch" title="启用 / 停用">' +
                    '<input type="checkbox" ' + (it.enabled == 1 ? 'checked' : '') +
                    ' onchange="toggleApi(' + it.id + ', this.checked)">' +
                    '<span class="slider"></span>' +
                '</label>' +
                '<button type="button" class="btn btn-outline btn-sm" onclick="openEditor(' + it.id + ')">编辑</button>' +
            '</div>' +
        '</div>';
    });
    wrap.innerHTML = html;
}

function shortUrl(u) {
    u = String(u || '');
    try {
        var x = new URL(u);
        return x.host + x.pathname;
    } catch (e) {
        return u.length > 42 ? u.slice(0, 42) + '…' : u;
    }
}

function replyLabel(t) {
    return (CA_TYPES[t] && CA_TYPES[t].label) ? CA_TYPES[t].label : '文字';
}

// ==================== 触发方式切换 ====================
function onTriggerTypeChange() {
    var t = caVal('caTriggerType') || 'message';
    document.getElementById('caMsgTrigger').style.display = t === 'event' ? 'none' : '';
    document.getElementById('caEventTrigger').style.display = t === 'event' ? '' : 'none';
    document.getElementById('caTestHint').style.display = t === 'event' ? '' : 'none';
}

function onEventGroupChange(selected) {
    var g = caVal('caEventGroup');
    var sel = document.getElementById('caEventType');
    var items = CA_EVENTS[g] || {};
    sel.innerHTML = '';
    Object.keys(items).forEach(function (k) {
        var o = document.createElement('option');
        o.value = k;
        o.textContent = items[k] + '（' + k + '）';
        if (selected && k === selected) o.selected = true;
        sel.appendChild(o);
    });
}

function pickEvent(eventName) {
    var found = '';
    Object.keys(CA_EVENTS).forEach(function (g) {
        if (CA_EVENTS[g][eventName] != null) found = g;
    });
    if (!found) return;
    document.getElementById('caEventGroup').value = found;
    onEventGroupChange(eventName);
}

// ==================== 发送方式提示 ====================
function onReplyTypeChange() {
    var key  = caVal('caReplyType') || 'text';
    var t    = CA_TYPES[key] || CA_TYPES.text || {};
    var hint = document.getElementById('caSendHint');
    var tpl  = document.getElementById('caReplyTpl');
    var combo = key === 'combo';

    document.getElementById('caComboHelp').style.display = combo ? '' : 'none';

    if (key === 'none') {
        hint.innerHTML = '只请求接口，不发任何消息，适合埋点或通知类接口。';
    } else if (combo) {
        hint.innerHTML = '每行调用一个函数：<code>函数名|参数1|参数2</code>。变量照常可用，例如 <code>文字|{结果}</code>、<code>文卡|{结果}|点我查看|{封面}|https://example.com</code>。';
    } else {
        var fields = (t.fields || []).map(function (f) { return '<code>' + caEsc(f) + '</code>'; }).join(' · ');
        var demo   = caEsc(t.demo || '').replace(/\n/g, ' ↵ ');
        hint.innerHTML = '<div>参数顺序：' + (fields || '无') + '</div>' +
                         '<div style="margin-top:4px;">' + caEsc(t.usage || '') + '</div>' +
                         (demo ? '<div style="margin-top:6px;">示例：<code>' + demo + '</code></div>' : '');
    }

    if (tpl) {
        tpl.placeholder = t.demo || '';
        tpl.rows = combo ? 7 : 3;
    }
    if (combo) renderComboChips();
}

function renderComboChips() {
    var box = document.getElementById('caComboChips');
    if (!box || box.childNodes.length) return;
    Object.keys(CA_TYPES).forEach(function (k) {
        if (k === 'combo' || k === 'none') return;
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ca-chip';
        b.textContent = CA_COMBO_NAMES[k] || CA_TYPES[k].label;
        b.onclick = function () { insertComboLine(k); };
        box.appendChild(b);
    });
}

var CA_COMBO_NAMES = {
    text: '文字', markdown: 'Markdown', image: '图片', voice: '语音', video: '视频', file: '文件',
    card: '文卡', bigimage: '大图', linkcard: '跳转卡', ark: 'Ark', ark23: 'Ark23',
    keyboard: '原生按钮', button: '按钮', emoji: '表情', quote: '引用', quote_image: '引用图片',
    quote_md: '引用MD', quote_voice: '引用语音', quote_video: '引用视频', quote_file: '引用文件',
    recall: '撤回', stream: '流式'
};

function insertComboLine(key) {
    var tpl = document.getElementById('caReplyTpl');
    var name = CA_COMBO_NAMES[key] || key;
    insertAt(tpl, (tpl.value && tpl.value.slice(-1) !== '\n' ? '\n' : '') + name + '|');
}

function toggleApi(id, on) {
    caCall('customapi_toggle', { id: id, enabled: on ? '1' : '0' }, function (res) {
        if (!res.success) { alert(res.message || '操作失败'); }
        loadList();
    });
}

// ==================== 编辑器 ====================
function openEditor(id, withTpl) {
    var it = null;
    if (id) {
        for (var i = 0; i < CA.list.length; i++) {
            if (parseInt(CA.list[i].id, 10) === parseInt(id, 10)) { it = CA.list[i]; break; }
        }
    }
    CA.editing = id || 0;
    CA.current = it;

    document.getElementById('caModalTitle').textContent = id ? ('编辑：' + (it ? it.name : '')) : '新建 API';
    document.getElementById('caDelBtn').style.display = id ? '' : 'none';
    document.getElementById('caTpl').style.display = id ? 'none' : '';
    document.getElementById('caTpl').value = '';

    var d = it || {
        name: '', category: '', icon: '', description: '', enabled: 1,
        trigger_type: 'message', event_type: '', event_filter: '',
        trigger: '', match_mode: 'exact', scope: 'all', permission: 'all',
        method: 'GET', url: '', headers: '', body: '', timeout: 20,
        parse: 'json', extracts: '[]', reply_type: 'text',
        reply_template: '', error_template: ''
    };

    document.getElementById('caName').value = d.name || '';
    document.getElementById('caCategory').value = d.category || '';
    document.getElementById('caIcon').value = d.icon || '';
    document.getElementById('caDesc').value = d.description || '';
    document.getElementById('caEnabled').checked = d.enabled == 1;
    document.getElementById('caTriggerType').value = (d.trigger_type === 'event') ? 'event' : 'message';
    if (d.event_type) { pickEvent(d.event_type); } else { onEventGroupChange(); }
    document.getElementById('caEventFilter').value = d.event_filter || '';
    onTriggerTypeChange();
    document.getElementById('caTrigger').value = d.trigger || '';
    document.getElementById('caMatch').value = d.match_mode || 'exact';
    document.getElementById('caScope').value = d.scope || 'all';
    document.getElementById('caPerm').value = d.permission || 'all';
    document.getElementById('caMethod').value = (d.method || 'GET').toUpperCase() === 'POST' ? 'POST' : 'GET';
    document.getElementById('caUrl').value = d.url || '';
    document.getElementById('caHeaders').value = d.headers || '';
    document.getElementById('caBody').value = d.body || '';
    document.getElementById('caTimeout').value = d.timeout || 20;
    document.getElementById('caParse').value = d.parse || 'json';
    document.getElementById('caReplyType').value = d.reply_type || 'text';
    document.getElementById('caReplyTpl').value = d.reply_template || '';
    document.getElementById('caErrorTpl').value = d.error_template || '';
    onReplyTypeChange();

    var rows = [];
    try { rows = JSON.parse(d.extracts || '[]'); } catch (e) { rows = []; }
    if (!rows.length) rows = [{ name: '结果', path: 'data.text', default: '' }];
    renderExtracts(rows);

    document.getElementById('caTestOut').style.display = 'none';
    document.getElementById('caTestOut').textContent = '';
    document.getElementById('caProbeOut').style.display = 'none';
    document.getElementById('caFootMsg').style.display = 'none';

    document.getElementById('caMask').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    setTimeout(function () { document.getElementById('caName').focus(); }, 60);

    if (withTpl) {
        document.getElementById('caTpl').value = 'hitokoto';
        applyTemplate('hitokoto');
    }
}

function closeEditor() {
    document.getElementById('caMask').style.display = 'none';
    document.body.style.overflow = '';
}

function insertAt(el, text) {
    if (!el) return;
    var start = el.selectionStart != null ? el.selectionStart : el.value.length;
    var end = el.selectionEnd != null ? el.selectionEnd : el.value.length;
    el.value = el.value.slice(0, start) + text + el.value.slice(end);
    el.selectionStart = el.selectionEnd = start + text.length;
    el.focus();
}

function insertVar(name) {
    insertAt(document.getElementById('caUrl'), '{' + name + '}');
}

function insertTplVar(name) {
    insertAt(document.getElementById('caReplyTpl'), '{' + name + '}');
}

// ==================== 取值表 ====================
function renderExtracts(rows) {
    var tb = document.getElementById('caExtracts');
    tb.innerHTML = '';
    (rows || []).forEach(function (r) { addExtract(r.name, r.path, r.default, true); });
    if (!tb.children.length) addExtract();
}

function addExtract(name, path, def, skipRender) {
    var tb = document.getElementById('caExtracts');
    var tr = document.createElement('tr');
    tr.innerHTML =
        '<td><input type="text" class="form-control ca-ex-name" value="' + caEsc(name || '') + '" placeholder="结果"></td>' +
        '<td><input type="text" class="form-control ca-ex-path" value="' + caEsc(path || '') + '" placeholder="data.text"></td>' +
        '<td><input type="text" class="form-control ca-ex-def" value="' + caEsc(def || '') + '" placeholder="留空"></td>' +
        '<td><button type="button" class="ca-del" title="删除" onclick="this.closest(\'tr\').remove()">&times;</button></td>';
    tb.appendChild(tr);
    if (!skipRender) {
        var inputs = tr.querySelectorAll('input');
        if (inputs[0]) inputs[0].focus();
    }
}

function collectExtracts() {
    var out = [];
    document.querySelectorAll('#caExtracts tr').forEach(function (tr) {
        var name = tr.querySelector('.ca-ex-name').value.trim();
        var path = tr.querySelector('.ca-ex-path').value.trim();
        if (!name || !path) return;
        out.push({ name: name, path: path, default: tr.querySelector('.ca-ex-def').value });
    });
    return out;
}

// ==================== 表单收集 ====================
function collectForm() {
    return {
        id: CA.editing || 0,
        name: caVal('caName'),
        category: caVal('caCategory'),
        icon: caVal('caIcon'),
        description: caVal('caDesc'),
        enabled: document.getElementById('caEnabled').checked ? 1 : 0,
        trigger_type: caVal('caTriggerType') || 'message',
        event_type: caVal('caTriggerType') === 'event' ? caVal('caEventType') : '',
        event_filter: caVal('caTriggerType') === 'event' ? document.getElementById('caEventFilter').value : '',
        trigger: caVal('caTrigger'),
        match_mode: caVal('caMatch'),
        scope: caVal('caScope'),
        permission: caVal('caPerm'),
        method: caVal('caMethod'),
        url: caVal('caUrl'),
        headers: document.getElementById('caHeaders').value,
        body: document.getElementById('caBody').value,
        timeout: caVal('caTimeout'),
        parse: caVal('caParse'),
        extracts: JSON.stringify(collectExtracts()),
        reply_type: caVal('caReplyType'),
        reply_template: document.getElementById('caReplyTpl').value,
        error_template: document.getElementById('caErrorTpl').value
    };
}

function footMsg(text, ok) {
    var el = document.getElementById('caFootMsg');
    el.textContent = text;
    el.style.color = ok ? 'var(--ok)' : 'var(--danger)';
    el.style.display = '';
    clearTimeout(el.__t);
    el.__t = setTimeout(function () { el.style.display = 'none'; }, 4000);
}

function saveApi() {
    var form = collectForm();
    if (!form.name) { footMsg('请填写名称', false); document.getElementById('caName').focus(); return; }
    if (form.trigger_type === 'event') {
        if (!form.event_type) { footMsg('请选择要监听的事件', false); return; }
    } else if (!form.trigger) {
        footMsg('请填写触发词', false); document.getElementById('caTrigger').focus(); return;
    }
    if (!/^https?:\/\//i.test(form.url)) { footMsg('请求地址要以 http(s):// 开头', false); document.getElementById('caUrl').focus(); return; }

    var btn = document.getElementById('caSaveBtn');
    btn.disabled = true;
    btn.textContent = '保存中…';
    caCall('customapi_save', { form: JSON.stringify(form) }, function (res) {
        btn.disabled = false;
        btn.textContent = '保存';
        if (!res.success) { footMsg(res.message || '保存失败', false); return; }
        closeEditor();
        loadList();
    });
}

function deleteApi() {
    if (!CA.editing) return;
    if (!confirm('确定删除这条 API？删除后对应指令立即失效。')) return;
    caCall('customapi_delete', { id: CA.editing }, function (res) {
        if (!res.success) { footMsg(res.message || '删除失败', false); return; }
        closeEditor();
        loadList();
    });
}

// ==================== 测试 / 探测 ====================
function testApi() {
    var form = collectForm();
    var out = document.getElementById('caTestOut');
    var btn = document.getElementById('caTestBtn');

    btn.disabled = true;
    btn.textContent = '请求中…';
    out.style.display = 'block';
    out.textContent = '正在请求接口，最长等 ' + (form.timeout || 20) + ' 秒…';

    caCall('customapi_test', { form: JSON.stringify(form), args: caVal('caTestArgs') }, function (res) {
        btn.disabled = false;
        btn.textContent = '运行测试';
        var d = res.data || {};
        var lines = [];
        lines.push('结果：' + (d.ok ? '成功' : '失败' + (d.error ? '（' + d.error + '）' : '')));
        if (d.http) {
            lines.push('状态：HTTP ' + (d.http.code || '-') + ' · ' + (d.http.elapsed_ms || 0) + ' ms · ' + (d.http.bytes || 0) + ' 字节');
        }
        if (d.request) lines.push('请求：' + d.request.method + ' ' + d.request.url);
        if (d.values) {
            var keys = Object.keys(d.values).filter(function (k) { return k !== '响应'; });
            if (keys.length) {
                lines.push('取值：');
                keys.forEach(function (k) {
                    var v = String(d.values[k] == null ? '' : d.values[k]);
                    if (v.length > 200) v = v.slice(0, 200) + '…';
                    lines.push('  {' + k + '} = ' + v);
                });
            }
        }
        lines.push('');
        lines.push('最终回复：');
        lines.push(d.text ? d.text : '（空，将不会发送）');
        if (d.send) {
            lines.push('');
            lines.push('发送计划（' + form.reply_type + '）：');
            if (d.send.actions && d.send.actions.length) {
                d.send.actions.forEach(function (a, i) {
                    lines.push('  ' + (i + 1) + '. ' + a.label + ' → ' + a.fn + '( ' + (a.args || []).join(' | ') + ' )');
                });
            } else {
                lines.push('  （不会发送任何消息）');
            }
            if (d.send.errors && d.send.errors.length) {
                lines.push('  ⚠ ' + d.send.errors.join('；'));
            }
        }
        if (d.raw) {
            lines.push('');
            lines.push('原始返回：');
            lines.push(d.raw);
        }
        out.textContent = lines.join('\n');
    });
}

function probeApi() {
    var form = collectForm();
    var btn = document.getElementById('caProbeBtn');
    var box = document.getElementById('caProbeOut');
    var meta = document.getElementById('caProbeMeta');
    var paths = document.getElementById('caProbePaths');
    var raw = document.getElementById('caProbeRaw');

    btn.disabled = true;
    btn.textContent = '探测中…';
    box.style.display = 'block';
    meta.textContent = '正在请求接口…';
    paths.innerHTML = '';
    raw.style.display = 'none';

    caCall('customapi_probe', { form: JSON.stringify(form), args: caVal('caTestArgs') }, function (res) {
        btn.disabled = false;
        btn.textContent = '探测返回结构';
        var d = res.data || {};
        var typeMap = { json: 'JSON', text: '纯文本', url: '纯文本 URL', binary: '二进制', empty: '空内容' };

        meta.innerHTML = (res.success ? '✓ ' : '✕ ') + caEsc(res.message || '') +
            (d.http ? ' · HTTP ' + caEsc(d.http.code) + ' · ' + caEsc(d.http.elapsed_ms) + ' ms · ' + caEsc(d.http.bytes) + ' 字节' : '') +
            ' · 类型：' + caEsc(typeMap[d.type] || d.type || '-');

        if (d.paths && d.paths.length) {
            var tip = document.createElement('div');
            tip.className = 'ca-note';
            tip.style.marginBottom = '8px';
            tip.textContent = '点一下路径就填进取值表（先自动填第一空行）：';
            paths.appendChild(tip);

            d.paths.forEach(function (p) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'ca-path';
                b.innerHTML = '<code>' + caEsc(p.path) + '</code><span>' + caEsc(p.sample || '') + '</span>' +
                              '<b>' + caEsc(p.kind === 'url' ? '链接' : (p.kind === 'base64' ? 'base64' : '文本')) + '</b>';
                b.onclick = function () { usePath(p.path); };
                paths.appendChild(b);
            });
        } else if (res.success) {
            var none = document.createElement('div');
            none.className = 'ca-note';
            none.textContent = '没有找到可直接取值的字段。纯文本接口可以在取值表里用 @body，或返回类型选「纯文本」。';
            paths.appendChild(none);
        }

        if (d.preview) {
            raw.textContent = d.preview;
            raw.style.display = 'block';
        }
    });
}

function usePath(path) {
    var rows = document.querySelectorAll('#caExtracts tr');
    var target = null;
    for (var i = 0; i < rows.length; i++) {
        var p = rows[i].querySelector('.ca-ex-path').value.trim();
        var n = rows[i].querySelector('.ca-ex-name').value.trim();
        if (!p || !n) { target = rows[i]; break; }
    }
    if (!target) {
        addExtract(path.split('.').pop(), path, '', true);
        target = document.getElementById('caExtracts').lastElementChild;
    }
    var nameInput = target.querySelector('.ca-ex-name');
    var pathInput = target.querySelector('.ca-ex-path');
    pathInput.value = path;
    if (!nameInput.value.trim()) {
        var seg = path.replace(/^@/, '').split('.').filter(function (s) { return s && s !== '*'; }).pop() || '字段';
        nameInput.value = seg === 'body' ? '结果' : seg;
    }
    target.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    nameInput.focus();
}

// ==================== 模板 ====================
function applyTemplate(key) {
    if (!key) return;
    var t = null;
    if (key === 'hitokoto') {
        t = {
            name: '一言', category: '娱乐', icon: '💬',
            description: '随机返回一句语录，演示「GET + 纯文本」的最简接法',
            trigger: '一言, 来句话', match_mode: 'exact',
            method: 'GET', url: 'https://v1.hitokoto.cn/?encode=text&c=d',
            parse: 'text', extracts: [{ name: '结果', path: '@body', default: '' }],
            reply_type: 'text', reply_template: '{结果}'
        };
    } else if (key === 'json') {
        t = {
            name: '查询示例', category: '示例', icon: '🔎',
            description: 'GET 带参数 + 从 JSON 取字段的通用结构，把地址和路径换成自己的',
            trigger: '查询', match_mode: 'prefix',
            method: 'GET', url: 'https://api.example.com/query?q={参数}',
            parse: 'json', extracts: [{ name: '结果', path: 'data.text', default: '' }],
            reply_type: 'text', reply_template: '{结果}'
        };
    } else if (key === 'post') {
        t = {
            name: '对话示例', category: '示例', icon: '🤖',
            description: 'POST JSON 的通用结构，适合各类 AI / 翻译接口',
            trigger: '问', match_mode: 'prefix',
            method: 'POST', url: 'https://api.example.com/v1/chat',
            headers: 'Authorization: Bearer 你的密钥',
            body: '{"q":"{参数}"}',
            parse: 'json', extracts: [{ name: '结果', path: 'data.answer', default: '' }],
            reply_type: 'text', reply_template: '{结果}'
        };
    }
    if (!t) return;
    document.getElementById('caName').value = t.name;
    document.getElementById('caCategory').value = t.category || '';
    document.getElementById('caIcon').value = t.icon || '';
    document.getElementById('caDesc').value = t.description || '';
    document.getElementById('caTriggerType').value = 'message';
    onTriggerTypeChange();
    document.getElementById('caTrigger').value = t.trigger || '';
    document.getElementById('caMatch').value = t.match_mode || 'exact';
    document.getElementById('caMethod').value = t.method || 'GET';
    document.getElementById('caUrl').value = t.url || '';
    document.getElementById('caHeaders').value = t.headers || '';
    document.getElementById('caBody').value = t.body || '';
    document.getElementById('caParse').value = t.parse || 'json';
    document.getElementById('caReplyType').value = t.reply_type || 'text';
    document.getElementById('caReplyTpl').value = t.reply_template || '';
    onReplyTypeChange();
    renderExtracts(t.extracts || []);
}

// 关闭弹窗的 Esc
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('caMask').style.display === 'flex') {
        closeEditor();
    }
});

loadList();
</script>

<?php require_once('footer.php'); ?>
