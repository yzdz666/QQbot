<?php /** 管理后台 - 共享底部 */ ?>
<?php if (basename($_SERVER['SCRIPT_NAME']) !== 'login.php'): ?>
  </div><!-- /.main-content -->
</div><!-- /.layout -->
<script>
function toggleSidebar() {
  var sb = document.querySelector('.sidebar');
  var ov = document.getElementById('sidebarOverlay');
  sb.classList.toggle('open');
  ov.classList.toggle('active');
}
function closeSidebar() {
  var sb = document.querySelector('.sidebar');
  var ov = document.getElementById('sidebarOverlay');
  sb.classList.remove('open');
  ov.classList.remove('active');
}
// 点击导航链接后自动关闭侧边栏（移动端）
document.querySelectorAll('.sidebar .nav-item').forEach(function(item) {
  item.addEventListener('click', function() {
    if (window.innerWidth <= 768) closeSidebar();
  });
});
</script>
<?php endif; ?>
</body>
</html>
