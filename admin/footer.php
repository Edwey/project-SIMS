      </main>
      <footer class="bg-white border-top py-3 text-center text-muted small">
        © <?php echo date('Y'); ?> University Management Admin Console
      </footer>
    </div>
  </div>
  <?php if (!defined('ADMIN_FOOTER_SCRIPTS_LOADED')): ?>
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>
    <?php define('ADMIN_FOOTER_SCRIPTS_LOADED', true); ?>
  <?php endif; ?>
  <?php if (isset($pageScripts) && is_array($pageScripts)): foreach ($pageScripts as $src): ?>
    <script src="<?php echo htmlspecialchars($src, ENT_QUOTES, 'UTF-8'); ?>"></script>
  <?php endforeach; endif; ?>
</body>
</html>
