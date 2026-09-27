<?php
// Footer for staff pages — skip when responding to AJAX
if (isset($_GET['ajax'])) return;
?>
</div><!-- /.page-content -->
</main>

<footer style="margin-left:var(--sidebar-w);text-align:center;padding:18px 0;color:#888;font-size:.75rem;transition:margin-left var(--ease)">
  &copy; <?php echo date('Y'); ?> Apostolic Vicariate of San Jose &mdash; Staff Panel
</footer>

<script>
// Sidebar toggle for mobile
document.addEventListener('DOMContentLoaded', function() {
  var sidebar = document.getElementById('sidebar');
  var toggle  = document.getElementById('sbToggle');
  var overlay = document.getElementById('overlay');
  if (sidebar && toggle) {
    toggle.addEventListener('click', function() {
      sidebar.classList.toggle('open');
      if (overlay) overlay.classList.toggle('on', sidebar.classList.contains('open'));
    });
    document.addEventListener('click', function(e) {
      if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
        if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
          sidebar.classList.remove('open');
          if (overlay) overlay.classList.remove('on');
        }
      }
    });
  }
});

// Modal helpers
function openModal(id) {
  document.getElementById(id).classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

// Toast helper
function showToast(msg, type) {
  type = type || 'success';
  var t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.className = 'toast toast-' + type + ' show';
  setTimeout(function() { t.classList.remove('show'); }, 3800);
}

// Loading overlay helper
function setLoading(on) {
  var el = document.getElementById('loadingOverlay');
  if (el) el.classList.toggle('show', on);
}
</script>
</body>
</html>
