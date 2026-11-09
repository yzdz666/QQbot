<?php
/**
 * 官鸡插件市场 - 接入说明
 */
require_once __DIR__ . '/lib/bootstrap.php';
market_security_headers(false);
$pageTitle = '接入说明';
$activeNav = 'docs';
require __DIR__ . '/partials/header.php';
?>
<div class="container" style="max-width:900px;padding:40px 0 60px;">
  <div class="hero-badge"><i></i> 5 分钟接入</div>
  <h1 style="font-size:28px;margin:0 0 10px;">接入说明</h1>
  <p class="muted" style="font-size:14px;max-width:680px;">本市场与官鸡机器人框架（PHP + SQLite）配套使用。机器人后台已内置「插件市场」页面，填写市场地址后即可浏览、查看源码并一键安装。</p>

  <div class="panel" style="margin-top:26px;">
    <div class="panel-head"><h3>1. 机器人后台配置</h3></div>
    <div class="panel-body markdown">
      <ol>
        <li>将 <code>market/</code> 整个目录放在机器人根目录（与 <code>admin/</code>、<code>plugin/</code> 同级）。</li>
        <li>确保 <code>market/data</code>、<code>market/storage</code> 对 PHP 运行用户可写。</li>
        <li>登录机器人后台 → 左侧「插件市场」，市场地址默认为同域 <code>/market</code>；如部署在其他域名，填写完整 URL。</li>
        <li>点击「测试连接」，成功后即可浏览、安装与更新插件。</li>
      </ol>
      <div class="alert alert-warn">
        <strong>Nginx 用户注意：</strong>除 Apache 外，请手动禁止访问 <code>/market/data/</code>、<code>/market/storage/</code>、<code>/market/seed/</code>，否则数据库与待审源码可能被下载。
      </div>
    </div>
  </div>

  <div class="panel" style="margin-top:18px;">
    <div class="panel-head"><h3>2. 插件开发约定</h3></div>
    <div class="panel-body markdown">
      <p>插件是单文件 PHP，放在机器人 <code>plugin/</code> 目录，文件名即插件名。建议在文件头增加元信息，市场与后台会读取并展示：</p>
      <pre><code>&lt;?php
/**
 * @name 我的插件
 * @version 1.0.0
 * @author 你的昵称
 * @description 一句话说明
 * @category 工具
 * @license MIT
 */

if (!defined('消息')) return;

if (消息 === '你好') {
    文字('你好，' . 用户);
}</code></pre>
      <p>可用的常量与函数（<code>消息</code>、<code>用户</code>、<code>来源</code>、<code>文字()</code>、<code>图片()</code>、<code>MD()</code>、<code>按钮()</code> 等）详见机器人后台「开发文档」。</p>
      <div class="alert alert-danger">
        禁止使用 <code>eval</code>、<code>assert</code>、<code>system</code>、<code>shell_exec</code>、反引号等动态执行能力；扫描命中将直接拒绝上架。
      </div>
    </div>
  </div>

  <div class="panel" style="margin-top:18px;">
    <div class="panel-head"><h3>3. 市场 API</h3></div>
    <div class="panel-body markdown">
      <p class="table-hint">表格较宽，手机上可左右滑动查看完整说明。</p>
      <table>
        <thead><tr><th>接口</th><th>说明</th></tr></thead>
        <tbody>
          <tr><td><code>GET api.php?action=list</code></td><td>插件列表，支持 <code>q</code>、<code>category</code>、<code>sort</code>、<code>page</code></td></tr>
          <tr><td><code>GET api.php?action=detail&amp;id=1</code></td><td>插件详情与版本历史</td></tr>
          <tr><td><code>GET api.php?action=source&amp;id=1</code></td><td>获取已审核版本的源码</td></tr>
          <tr><td><code>GET api.php?action=check&amp;slug=music&amp;version=1.0.0</code></td><td>检查更新</td></tr>
          <tr><td><code>GET download.php?id=1&amp;format=php</code></td><td>下载单文件插件（附 SHA-256 响应头）</td></tr>
          <tr><td><code>GET download.php?id=1&amp;format=zip</code></td><td>下载含 manifest 的 zip 包</td></tr>
        </tbody>
      </table>
      <p class="small muted">所有下载均校验 SHA-256；机器人后台安装前会再次比对，并用 <code>token_get_all(TOKEN_PARSE)</code> 检查语法。</p>
    </div>
  </div>

  <div class="panel" style="margin-top:18px;">
    <div class="panel-head"><h3>4. 审核与安全</h3></div>
    <div class="panel-body markdown">
      <ul>
        <li>提交后进入人工审核队列，管理员可查看源码、扫描报告与版本差异后决定通过或拒绝。</li>
        <li>通过后的版本才可被公开下载；已上架插件可随时下架，不影响已安装的机器人。</li>
        <li>市场后台独立密码、登录限流、CSRF、IP 白名单、操作审计、CSP 安全头均已启用。</li>
        <li>市场侧存储统一使用 <code>.php.txt</code> 后缀，即使 Web 服务器误配也不会执行插件源码。</li>
      </ul>
    </div>
  </div>

  <div style="margin-top:26px;display:flex;gap:12px;flex-wrap:wrap;">
    <a class="btn btn-primary" href="<?= market_h(market_base_url()) ?>/submit.php">发布插件</a>
    <a class="btn btn-outline" href="<?= market_h(market_base_url()) ?>/index.php">返回广场</a>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
