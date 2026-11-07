<?php
/**
 * Shared page head / top includes
 * Expects: $pageTitle, $user (optional), $bodyClass (optional)
 */
require_once __DIR__ . '/auth.php';
if (!headers_sent() && extension_loaded('zlib') && !ini_get('zlib.output_compression')
    && !in_array('ob_gzhandler', ob_list_handlers(), true)) {
    ob_start('ob_gzhandler');
}
start_app_session();
try_remember_login();
$user = $user ?? current_user();
$pageTitle = $pageTitle ?? APP_NAME;
$bodyClass = $bodyClass ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
  <meta name="theme-color" content="#061214">
  <link rel="manifest" href="<?= APP_URL ?>/manifest.webmanifest">
  <link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/icons/icon-192.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <?php if (!empty($loadLangTags)): ?>
  <link href="https://cdn.jsdelivr.net/npm/@yaireo/tagify@4.31.6/dist/tagify.css" rel="stylesheet">
  <?php endif; ?>
  <link href="<?= APP_URL ?>/assets/css/style.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/style.css') ?: time() ?>" rel="stylesheet">
</head>
<body class="<?= e($bodyClass) ?>">
<a class="skip-link" href="#main-content">Skip to main content</a>
<script>
(function () {
  function pad(n) { return n < 10 ? '0' + n : String(n); }
  function syncLaptopClock() {
    var d = new Date();
    var ymd = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    var mins = d.getHours() * 60 + d.getMinutes();
    document.cookie = 'cultour_local_clock=' + encodeURIComponent('tz|' + d.getTimezoneOffset())
      + '; path=/; max-age=86400; SameSite=Lax';
    document.querySelectorAll('[data-itin-today]').forEach(function (el) {
      el.setAttribute('data-itin-today', ymd);
    });
    document.querySelectorAll('[data-itin-now-mins]').forEach(function (el) {
      el.setAttribute('data-itin-now-mins', String(mins));
    });
  }
  syncLaptopClock();
  setInterval(syncLaptopClock, 30000);
})();
</script>
