<?php /** 市场后台 - 公共底部 */ ?>
    </div>
  </div>
</div>
<script nonce="<?= market_nonce() ?>">
(function () {
  // 移动端点击侧栏后自动收起
  document.querySelectorAll('.admin-side a.nav-item').forEach(function (a) {
    a.addEventListener('click', function () {
      if (window.innerWidth <= 760) document.querySelector('.admin-side').classList.remove('open');
    });
  });
  var toggle = document.getElementById('adminSideToggle');
  if (toggle) toggle.addEventListener('click', function () {
    document.querySelector('.admin-side').classList.toggle('open');
  });
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
