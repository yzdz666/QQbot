<?php /** 市场后台 - 公共底部 */ ?>
    </div>
  </div>
</div>
<script nonce="<?= market_nonce() ?>">
(function () {
  var side = document.getElementById('adminSide');
  var overlay = document.getElementById('adminSideOverlay');
  var toggle = document.getElementById('adminSideToggle');
  if (side) {
    function setOpen(open) {
      side.classList.toggle('open', open);
      if (overlay) overlay.classList.toggle('active', open);
      document.body.classList.toggle('admin-nav-open', open);
      if (toggle) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? '关闭菜单' : '打开菜单');
      }
    }
    // 移动端点击侧栏后自动收起
    side.querySelectorAll('a.nav-item').forEach(function (a) {
      a.addEventListener('click', function () {
        if (window.innerWidth <= 760) setOpen(false);
      });
    });
    if (toggle) toggle.addEventListener('click', function () {
      setOpen(!side.classList.contains('open'));
    });
    if (overlay) overlay.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && side.classList.contains('open')) setOpen(false);
    });
    var mq = window.matchMedia('(max-width: 760px)');
    var onChange = function () { if (!mq.matches) setOpen(false); };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange);
  }
  // CSP 兼容：不使用内联 onclick/onsubmit
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    var handler = function (e) {
      if (!window.confirm(el.getAttribute('data-confirm'))) {
        e.preventDefault();
        e.stopPropagation();
      }
    };
    if (el.tagName === 'FORM') el.addEventListener('submit', handler);
    else el.addEventListener('click', handler);
  });
})();
</script>
</body>
</html>
