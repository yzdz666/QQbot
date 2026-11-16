<?php /** 管理后台 - 共享底部 */ ?>
<?php if (basename($_SERVER['SCRIPT_NAME']) !== 'login.php'): ?>
  </div><!-- /.main-content -->
</div><!-- /.layout -->
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
<?php endif; ?>
</body>
</html>
