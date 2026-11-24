<?php /** 管理后台 - 共享底部 */ ?>
<?php if (basename($_SERVER['SCRIPT_NAME']) !== 'login.php'): ?>
  </div><!-- /.main-content -->
</div><!-- /.layout -->

<div class="cmd-panel" id="cmdPanel" hidden>
  <div class="cmd-mask" data-cmd-close></div>
  <div class="cmd-dialog" role="dialog" aria-modal="true" aria-label="快速跳转">
    <div class="cmd-input-row">
      <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><circle cx="7" cy="7" r="4.6" stroke="currentColor" stroke-width="1.6"/><path d="M10.5 10.5L14 14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      <input type="text" id="cmdInput" placeholder="搜索页面、功能…" autocomplete="off" spellcheck="false">
      <kbd>ESC</kbd>
    </div>
    <div class="cmd-list" id="cmdList"></div>
    <div class="cmd-foot"><span>↑↓ 选择</span><span>↵ 打开</span><span>ESC 关闭</span></div>
  </div>
</div>
<script>
(function () {
  var sidebar = document.getElementById('adminSidebar');
  var overlay = document.getElementById('sidebarOverlay');
  var button  = document.getElementById('mobileMenuBtn');
  if (!sidebar || !overlay) return;

  function isMobile() { return window.matchMedia('(max-width: 768px)').matches; }

  function setOpen(open) {
    sidebar.classList.toggle('open', open);
    overlay.classList.toggle('active', open);
    document.body.classList.toggle('nav-open', open);
    if (button) button.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (button) button.setAttribute('aria-label', open ? '关闭菜单' : '打开菜单');
  }

  window.toggleSidebar = function () { setOpen(!sidebar.classList.contains('open')); };
  window.closeSidebar  = function () { setOpen(false); };

  // 点击导航后自动收起（移动端）
  sidebar.querySelectorAll('.nav-item').forEach(function (item) {
    item.addEventListener('click', function () {
      if (isMobile()) setOpen(false);
    });
  });

  // Esc 关闭
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sidebar.classList.contains('open')) setOpen(false);
  });

  // 视口变宽后复位，避免桌面端残留遮罩
  var mq = window.matchMedia('(max-width: 768px)');
  var onChange = function () { if (!mq.matches) setOpen(false); };
  if (mq.addEventListener) mq.addEventListener('change', onChange);
  else if (mq.addListener) mq.addListener(onChange);
})();
</script>
<script>
/* 统一头像降级链：官方下发头像 → qlogo 640（appid + openid）→ 首字色块
   qlogo 对 openid 只会返回 40×40 默认剪影，按 naturalWidth 判定后继续降级，避免"扁平灰头像" */
window.avtNext = function (img) {
  if (!img || img.__avtDone) return;
  var list = String(img.getAttribute('data-next') || '').split('||');
  var pick = '';
  while (list.length && !pick) pick = list.shift();
  if (!pick) { img.__avtDone = 1; img.remove(); return; }
  var p = pick.indexOf('|');
  var kind = p > -1 ? pick.slice(0, p) : 'u';
  var url = p > -1 ? pick.slice(p + 1) : pick;
  img.setAttribute('data-kind', kind);
  img.setAttribute('data-next', list.join('||'));
  img.src = url;
};
window.avtLoaded = function (img) {
  if (!img || img.__avtDone) return;
  if (img.getAttribute('data-kind') === 'q' && img.naturalWidth > 0 && img.naturalWidth <= 40) {
    window.avtNext(img);
  }
};
</script>
<script>
/* 界面微交互：滚动进度、顶栏收缩、实时时钟、数字滚动
   全部为渐进增强，任一元素缺失都不会影响页面功能 */
(function () {
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var doc = document.documentElement;
  var progress = document.getElementById('scrollProgress');
  var topbar = document.querySelector('.topbar');

  function onScroll() {
    var max = doc.scrollHeight - window.innerHeight;
    if (progress) {
      if (max <= 4) {
        progress.style.width = '0';
        progress.style.opacity = '0';
      } else {
        progress.style.opacity = '1';
        progress.style.width = (Math.min(1, Math.max(0, window.scrollY / max)) * 100).toFixed(2) + '%';
      }
    }
    if (topbar) topbar.classList.toggle('is-scrolled', window.scrollY > 6);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  onScroll();

  // 顶栏时钟
  var clock = document.getElementById('topbarClock');
  if (clock) {
    var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
    var tick = function () {
      var d = new Date();
      clock.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    };
    tick();
    setInterval(tick, 1000);
  }

  // 数字滚动：只处理纯整数，含斜杠/小数/中文的一律跳过
  function countUp(el) {
    var raw = (el.textContent || '').trim();
    if (!/^\d{1,9}$/.test(raw)) return;
    var target = parseInt(raw, 10);
    if (!target) return;
    var start = 0, dur = 760;
    el.textContent = '0';
    function step(now) {
      if (!start) start = now;
      var p = Math.min(1, (now - start) / dur);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = String(Math.round(target * eased));
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  if (!reduce) {
    var targets = document.querySelectorAll('.stat-value, .dashboard-stat .ds-text strong');
    for (var i = 0; i < targets.length; i++) countUp(targets[i]);
  }
})();
</script>
<script>
/* 命令面板：Ctrl/⌘ + K 唤起，模糊过滤侧栏导航，↑↓ 选择，Enter 跳转 */
(function () {
  var trigger = document.getElementById('cmdTrigger');
  var panel = document.getElementById('cmdPanel');
  if (!trigger || !panel) return;

  var input = document.getElementById('cmdInput');
  var list = document.getElementById('cmdList');
  var kbd = document.getElementById('cmdKbd');
  var isMac = /Mac|iPhone|iPad/i.test(navigator.platform || '');
  if (kbd) kbd.textContent = isMac ? '⌘ K' : 'Ctrl K';

  var items = [];
  var filtered = [];
  var cursor = 0;

  var menu = document.querySelector('.sidebar .nav-menu');
  if (menu) {
    var group = '';
    Array.prototype.forEach.call(menu.children, function (el) {
      if (el.classList.contains('nav-section')) {
        group = (el.textContent || '').trim();
      } else if (el.classList.contains('nav-item')) {
        var clone = el.cloneNode(true);
        var badge = clone.querySelector('.nav-badge');
        if (badge) badge.parentNode.removeChild(badge);
        items.push({
          label: (clone.textContent || '').replace(/\s+/g, ' ').trim(),
          group: group,
          href: el.getAttribute('href') || '#'
        });
      }
    });
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function paint() {
    if (!filtered.length) {
      list.innerHTML = '<div class="cmd-empty">没有匹配的页面</div>';
      return;
    }
    var html = '';
    for (var i = 0; i < filtered.length; i++) {
      var it = filtered[i];
      html += '<a class="cmd-item' + (i === cursor ? ' active' : '') + '" href="' + esc(it.href) + '">'
        + '<span>' + esc(it.label) + '</span>'
        + (it.group ? '<span class="cmd-group">' + esc(it.group) + '</span>' : '')
        + '<span class="cmd-arrow">↵</span></a>';
    }
    list.innerHTML = html;
    var active = list.querySelector('.cmd-item.active');
    if (active && active.scrollIntoView) active.scrollIntoView({ block: 'nearest' });
  }

  function filter(kw) {
    kw = (kw || '').trim().toLowerCase();
    filtered = [];
    for (var i = 0; i < items.length; i++) {
      if (!kw || items[i].label.toLowerCase().indexOf(kw) > -1 || (items[i].group || '').toLowerCase().indexOf(kw) > -1) {
        filtered.push(items[i]);
      }
    }
    cursor = 0;
    paint();
  }

  function open() {
    panel.hidden = false;
    document.body.classList.add('nav-open');
    input.value = '';
    filter('');
    setTimeout(function () { input.focus(); }, 30);
  }

  function close() {
    panel.hidden = true;
    document.body.classList.remove('nav-open');
    trigger.focus();
  }

  function move(step) {
    if (!filtered.length) return;
    cursor = (cursor + step + filtered.length) % filtered.length;
    paint();
  }

  trigger.addEventListener('click', open);
  var mask = panel.querySelector('[data-cmd-close]');
  if (mask) mask.addEventListener('click', close);

  input.addEventListener('input', function () { filter(input.value); });

  list.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('.cmd-item') : null;
    if (a) close();
  });

  document.addEventListener('keydown', function (e) {
    var key = e.key || '';
    if ((e.ctrlKey || e.metaKey) && (key === 'k' || key === 'K')) {
      e.preventDefault();
      if (panel.hidden) open(); else close();
      return;
    }
    if (panel.hidden) return;
    if (key === 'Escape') { e.preventDefault(); close(); }
    else if (key === 'ArrowDown') { e.preventDefault(); move(1); }
    else if (key === 'ArrowUp') { e.preventDefault(); move(-1); }
    else if (key === 'Enter') {
      var active = list.querySelector('.cmd-item.active');
      if (active) {
        e.preventDefault();
        var href = active.getAttribute('href');
        close();
        if (href && href !== '#') location.href = href;
      }
    }
  });

  // 点击顶栏按钮以外的空白：面板外点击关闭（mask 已覆盖，兜底）
  window.addEventListener('hashchange', function () { if (!panel.hidden) close(); });
})();
</script>
<script>
/* 新版本提醒：后台每次打开时静默检查一次（服务端缓存 6 小时），
   有更新就在内容区顶部显示一条提醒；关闭只对本次会话生效。 */
(function () {
  var bar = document.getElementById('verbar');
  if (!bar) return;
  var titleEl = document.getElementById('verbarTitle');
  var subEl = document.getElementById('verbarSub');
  var goEl = document.getElementById('verbarGo');
  var closeEl = document.getElementById('verbarClose');
  var badge = document.getElementById('navUpdBadge');
  var version = '';

  function remember(v) {
    try { sessionStorage.setItem('verbar_skip', v); } catch (e) {}
  }
  function skipped(v) {
    try { return sessionStorage.getItem('verbar_skip') === v; } catch (e) { return false; }
  }

  function close() {
    bar.hidden = true;
    if (version) remember(version);
  }
  if (closeEl) closeEl.addEventListener('click', close);

  // 更新页自己会展示版本信息，不再叠一条提醒
  if (/\/update\.php$/.test(location.pathname)) return;

  fetch('api/update.php?action=notice', {
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' },
    cache: 'no-store'
  }).then(function (r) {
    return r.json();
  }).then(function (res) {
    if (!res || !res.success || !res.data) return;
    var d = res.data;
    if (!d.has_update || !d.version) return;
    version = d.version;
    if (badge) badge.hidden = false;
    if (skipped(version)) return;

    titleEl.textContent = '发现新版本 v' + d.version;
    subEl.textContent = '当前 v' + d.current + (d.notes ? ' · 更新内容：' + d.notes : ' · 点击右侧按钮可一键更新');
    goEl.textContent = '查看并更新';
    bar.hidden = false;
  }).catch(function () { /* 网络不通时静默，不影响后台使用 */ });
})();
</script>
<?php endif; ?>
</body>
</html>
