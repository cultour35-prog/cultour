  <script>
    window.CulTour = {
      appUrl: <?= json_encode(APP_URL) ?>,
      csrf: <?= json_encode(!empty($user) ? csrf_token() : '') ?>,
      roleId: <?= (int) ($user['role_id'] ?? 0) ?>,
      dashboardUrl: <?= json_encode(!empty($user) ? role_dashboard((int) $user['role_id']) : '') ?>,
      liveUnread: <?= json_encode(!empty($user)) ?>,
      liveAccount: <?= json_encode(!empty($user)) ?>,
      liveAppeals: <?= json_encode(
          !empty($user)
          && defined('ROLE_ADMIN')
          && (int) ($user['role_id'] ?? 0) === ROLE_ADMIN
          && !empty($activeNav)
          && $activeNav === 'appeals'
      ) ?>,
      liveRegistrations: <?= json_encode(
          !empty($liveRegistrations) && is_string($liveRegistrations) ? $liveRegistrations : ''
      ) ?>
    };
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">    </script>
  <?php if (!empty($loadLangTags)): ?>
  <script src="https://cdn.jsdelivr.net/npm/@yaireo/tagify@4.31.6/dist/tagify.min.js"></script>
  <script src="<?= APP_URL ?>/assets/js/lang-tags.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/lang-tags.js') ?: time() ?>"></script>
  <?php endif; ?>
  <script src="<?= APP_URL ?>/assets/js/app.js?v=<?= (@filemtime(ROOT_PATH . '/assets/js/app.js') ?: time()) . '-a7' ?>"></script>
  <?php if (!empty($user)): ?>
  <script src="<?= APP_URL ?>/assets/js/live-page.js?v=<?= (@filemtime(ROOT_PATH . '/assets/js/live-page.js') ?: time()) ?>"></script>
  <?php endif; ?>
  <script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(<?= json_encode(APP_URL . '/sw.js?v=4') ?>).catch(function () {});
    });
  }
  </script>
  <?php if (!empty($extraScripts)) echo $extraScripts; ?>
  <?php if (!empty($user) && function_exists('booking_replacement_popup')
      && in_array((int) ($user['role_id'] ?? 0), [ROLE_TOURIST, ROLE_TOUR_GUIDE], true)
      && !user_is_suspended($user)): ?>
    <?php $replPopup = booking_replacement_popup($user); ?>
    <div id="ctReplacementHost" data-key="<?= e($replPopup['key']) ?>" data-audience="<?= e($replPopup['audience']) ?>"
         data-poll-url="<?= e(APP_URL . '/api/bookings/replacement_popup.php') ?>"><?= $replPopup['html'] ?></div>
    <script src="<?= APP_URL ?>/assets/js/replacement-popup.js?v=<?= (@filemtime(ROOT_PATH . '/assets/js/replacement-popup.js') ?: time()) ?>"></script>
  <?php endif; ?>
  <?php if (!empty($user) && function_exists('refund_tourist_popup_html')
      && (int) ($user['role_id'] ?? 0) === ROLE_TOURIST && !user_is_suspended($user)): ?>
    <?= refund_tourist_popup_html($user) ?>
  <?php endif; ?>
  <?php if (!empty($user) && function_exists('render_guide_setup_modal') && render_guide_setup_modal($user)): ?>
  <script>
  (function () {
    var el = document.getElementById('guideSetupModal');
    if (!el || typeof bootstrap === 'undefined') return;
    bootstrap.Modal.getOrCreateInstance(el).show();
  })();
  </script>
  <?php endif; ?>
  <?php if (!empty($user) && function_exists('render_business_setup_modal') && render_business_setup_modal($user)): ?>
  <script>
  (function () {
    var el = document.getElementById('businessSetupModal');
    if (!el || typeof bootstrap === 'undefined') return;
    bootstrap.Modal.getOrCreateInstance(el).show();
  })();
  </script>
  <?php endif; ?>
</body>
</html>
