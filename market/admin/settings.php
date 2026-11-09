<?php
/**
 * 插件市场 - 安全设置
 */
require_once dirname(__DIR__) . '/lib/bootstrap.php';
market_security_headers(true);
MarketAuth::requireAdmin();

$adminName = MarketAuth::current()['username'] ?? 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    market_same_origin_check();
    market_csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_site') {
        $ipAllow = trim((string)($_POST['admin_ip_allow'] ?? ''));
        // 防止把自己锁在后台外
        if ($ipAllow !== '') {
            $rules = preg_split('/[\s,]+/', $ipAllow);
            $ok = false;
            $valid = true;
            foreach ($rules as $rule) {
                $rule = trim($rule);
                if ($rule === '') continue;
                if (strpos($rule, '/') !== false) {
                    list($subnet, $bits) = array_pad(explode('/', $rule, 2), 2, null);
                    if ($bits === null || !ctype_digit($bits) || (int)$bits < 0 || (int)$bits > 32
                        || !filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $valid = false;
                        break;
                    }
                    $mask = ((int)$bits) === 0 ? 0 : (-1 << (32 - (int)$bits));
                    $ipLong = ip2long(market_client_ip());
                    if ($ipLong !== false && ($ipLong & $mask) === (ip2long($subnet) & $mask)) { $ok = true; }
                } else {
                    if (!filter_var($rule, FILTER_VALIDATE_IP)) { $valid = false; break; }
                    if ($rule === market_client_ip()) { $ok = true; }
                }
            }
            if (!$valid) {
                market_flash('danger', 'IP 白名单格式错误，仅支持 IP 或 IPv4 CIDR（如 1.2.3.4、10.0.0.0/8）。');
                header('Location: settings.php');
                exit;
            }
            if (!$ok) {
                market_flash('danger', '保存失败：当前 IP ' . market_client_ip() . ' 不在新的白名单内，会导致你无法登录后台。');
                header('Location: settings.php');
                exit;
            }
        }
        $maxMb = min(20, max(1, (int)($_POST['max_upload_mb'] ?? 2)));
        market_set_setting('site_name', trim((string)($_POST['site_name'] ?? '官鸡插件市场')));
        market_set_setting('site_desc', trim((string)($_POST['site_desc'] ?? '')));
        market_set_setting('site_notice', trim((string)($_POST['site_notice'] ?? '')));
        market_set_setting('upload_enabled', isset($_POST['upload_enabled']) ? '1' : '0');
        market_set_setting('auto_approve', isset($_POST['auto_approve']) ? '1' : '0');
        market_set_setting('maintenance', isset($_POST['maintenance']) ? '1' : '0');
        market_set_setting('allow_blocked', isset($_POST['allow_blocked']) ? '1' : '0');
        market_set_setting('max_upload', (string)($maxMb * 1048576));
        market_set_setting('admin_ip_allow', $ipAllow);
        market_audit($adminName, '保存站点设置', 'max_upload=' . $maxMb . 'MB');
        market_flash('ok', '设置已保存');
        header('Location: settings.php');
        exit;
    }

    if ($action === 'change_password') {
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if ($new !== $confirm) {
            market_flash('danger', '两次输入的新密码不一致');
        } else {
            $result = MarketAuth::changePassword($old, $new);
            if ($result['success']) {
                MarketAuth::logout();
                header('Location: login.php');
                exit;
            }
            market_flash('danger', $result['message']);
        }
        header('Location: settings.php');
        exit;
    }
}

$pageTitle = '安全设置';
require __DIR__ . '/header.php';
$uploadMb = max(1, (int)round((int)market_setting('max_upload', '2097152') / 1048576));
?>
<div class="review-grid">
  <div>
    <form class="panel" method="post" style="margin-bottom:18px;">
      <div class="panel-head"><h3>站点设置</h3></div>
      <div class="panel-body">
        <input type="hidden" name="csrf_token" value="<?= market_h(market_csrf_token()) ?>">
        <input type="hidden" name="action" value="save_site">
        <div class="form-row"><label>站点名称</label><input class="form-control" type="text" name="site_name" value="<?= market_h(market_setting('site_name', '官鸡插件市场')) ?>"></div>
        <div class="form-row"><label>站点简介</label><input class="form-control" type="text" name="site_desc" value="<?= market_h(market_setting('site_desc')) ?>"></div>
        <div class="form-row"><label>首页公告（留空不显示）</label><textarea class="form-control" name="site_notice" rows="2"><?= market_h(market_setting('site_notice')) ?></textarea></div>
        <div class="form-grid">
          <div class="form-row"><label>单文件大小上限（MB）</label><input class="form-control" type="number" name="max_upload_mb" min="1" max="20" value="<?= $uploadMb ?>"></div>
          <div class="form-row"><label>后台 IP 白名单</label><input class="form-control" type="text" name="admin_ip_allow" placeholder="留空不限制，例如 1.2.3.4, 10.0.0.0/8" value="<?= market_h(market_setting('admin_ip_allow')) ?>"><div class="form-hint">当前 IP：<?= market_h(market_client_ip()) ?>，保存时会校验。</div></div>
        </div>
        <label class="check" style="margin:6px 0;"><input type="checkbox" name="upload_enabled" <?= market_setting('upload_enabled', '1') === '1' ? 'checked' : '' ?>> 开放开发者提交插件</label>
        <label class="check" style="margin:6px 0;"><input type="checkbox" name="auto_approve" <?= market_setting('auto_approve', '0') === '1' ? 'checked' : '' ?>> 自动通过扫描无风险的版本（不建议）</label>
        <label class="check" style="margin:6px 0;"><input type="checkbox" name="maintenance" <?= market_setting('maintenance', '0') === '1' ? 'checked' : '' ?>> 维护模式（前台 503，后台可访问）</label>
        <label class="check" style="margin:6px 0;"><input type="checkbox" name="allow_blocked" <?= market_setting('allow_blocked', '0') === '1' ? 'checked' : '' ?>> 允许审核通过含高危代码的插件（仅限你已逐行审计）</label>
        <div class="alert alert-warn" style="margin:14px 0 0;">关闭「开放提交」后，已上架插件仍可被下载，但开发者无法提交新版本。</div>
        <button class="btn btn-primary" type="submit" style="margin-top:16px;">保存设置</button>
      </div>
    </form>

    <form class="panel" method="post">
      <div class="panel-head"><h3>修改管理员密码</h3></div>
      <div class="panel-body">
        <input type="hidden" name="csrf_token" value="<?= market_h(market_csrf_token()) ?>">
        <input type="hidden" name="action" value="change_password">
        <div class="form-row"><label>当前密码</label><input class="form-control" type="password" name="old_password" required autocomplete="current-password"></div>
        <div class="form-grid">
          <div class="form-row"><label>新密码（至少 8 位，含字母和数字）</label><input class="form-control" type="password" name="new_password" required autocomplete="new-password"></div>
          <div class="form-row"><label>确认新密码</label><input class="form-control" type="password" name="confirm_password" required autocomplete="new-password"></div>
        </div>
        <div class="alert alert-warn">修改成功后会自动退出登录，请使用新密码重新登录。</div>
        <button class="btn btn-primary" type="submit">修改密码</button>
      </div>
    </form>
  </div>

  <aside>
    <div class="panel">
      <div class="panel-head"><h3>运行环境</h3></div>
      <div class="panel-body">
        <div class="kv">
          <div><span>PHP 版本</span><b><?= market_h(PHP_VERSION) ?></b></div>
          <div><span>数据库</span><b>SQLite</b></div>
          <div><span>数据目录可写</span><b style="color:<?= is_writable(MARKET_DATA_DIR) ? '#34d399' : '#f87171' ?>"><?= is_writable(MARKET_DATA_DIR) ? '正常' : '不可写' ?></b></div>
          <div><span>存储目录可写</span><b style="color:<?= is_writable(MARKET_STORAGE_DIR) ? '#34d399' : '#f87171' ?>"><?= is_writable(MARKET_STORAGE_DIR) ? '正常' : '不可写' ?></b></div>
          <div><span>HTTPS</span><b style="color:<?= market_is_https() ? '#34d399' : '#fbbf24' ?>"><?= market_is_https() ? '已启用' : '未启用' ?></b></div>
          <div><span>ZipArchive</span><b><?= class_exists('ZipArchive') ? '可用' : '未安装' ?></b></div>
          <div><span>会话超时</span><b>2 小时</b></div>
          <div><span>登录限流</span><b>10 次 / 15 分钟</b></div>
        </div>
      </div>
    </div>

    <div class="panel" style="margin-top:18px;">
      <div class="panel-head"><h3>安全建议</h3></div>
      <div class="panel-body">
        <ul class="muted" style="margin:0;padding-left:18px;font-size:12.5px;line-height:2;">
          <li>使用独立强密码，不与机器人后台共用</li>
          <li>开启 HTTPS 并配置 IP 白名单</li>
          <li>定期查看审计日志中的异常登录</li>
          <li>Nginx 需手动禁止访问 <code>data/ storage/ seed/</code></li>
          <li>高危插件通过前务必逐行阅读源码</li>
        </ul>
      </div>
    </div>
  </aside>
</div>
<?php require __DIR__ . '/footer.php'; ?>
