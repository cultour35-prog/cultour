<?php
/**
 * Side-by-side demo of SQL injection and XSS: an unprotected version vs how CulTour does it.
 * Only opens when SECURITY_DEMO_ENABLED is true, and only from this PC (.htaccess "Require local").
 * The unprotected SQL runs on a separate connection that allows one statement only, and nothing is written.
 */
require_once __DIR__ . '/includes/functions.php';

if (!SECURITY_DEMO_ENABLED) {
    http_response_code(404);
    exit('Not found.');
}

$sqlEmail = (string) ($_POST['sql_email'] ?? '');
$sqlPassword = (string) ($_POST['sql_password'] ?? '');
$xssText = (string) ($_POST['xss_text'] ?? '');
$ran = $_SERVER['REQUEST_METHOD'] === 'POST';

$unsafeSql = null;
$unsafeRows = [];
$unsafeError = null;
$safeRows = [];
if ($ran && isset($_POST['run_sql'])) {
    $unsafeSql = "SELECT id, email, role_id FROM users WHERE email = '" . $sqlEmail . "' AND password_hash = '" . $sqlPassword . "'";
    try {
        $demoDb = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]
        );
        $demoDb->exec('SET SESSION TRANSACTION READ ONLY');
        $unsafeRows = $demoDb->query($unsafeSql)->fetchAll();
    } catch (Throwable $e) {
        $unsafeError = $e->getMessage();
    }

    $st = db()->prepare('SELECT id, email, role_id, password_hash FROM users WHERE email = ?');
    $st->execute([$sqlEmail]);
    foreach ($st->fetchAll() as $row) {
        if (password_verify($sqlPassword, (string) $row['password_hash'])) {
            unset($row['password_hash']);
            $safeRows[] = $row;
        }
    }
}

$pageTitle = 'Security demo';
require __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="max-width:1100px">
  <div class="alert alert-warning">
    <strong>Testing page.</strong> The "Unprotected" boxes show what would happen if CulTour skipped its protections.
    CulTour itself never runs the unprotected code. Turn this page off again by setting
    <code>SECURITY_DEMO_ENABLED</code> to <code>false</code> in <code>config/app.php</code>.
  </div>

  <h1 class="h3 mb-3">1. SQL injection (login)</h1>
  <form method="post" class="glass p-4 mb-3">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label" for="sqlEmail">Email</label>
        <input class="form-control" id="sqlEmail" name="sql_email" value="<?= e($sqlEmail) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="sqlPassword">Password</label>
        <input class="form-control" id="sqlPassword" name="sql_password" value="<?= e($sqlPassword) ?>" data-no-toggle>
      </div>
    </div>
    <p class="small text-muted mt-3 mb-2">Try email <code>admin@cultour.ormoc.gov.ph' -- </code> (note the space after <code>--</code>) with any password, or email <code>' OR '1'='1' -- </code>.</p>
    <button class="btn btn-ct" name="run_sql" value="1">Try to sign in</button>
  </form>

  <?php if ($unsafeSql !== null): ?>
    <div class="row g-3 mb-5">
      <div class="col-md-6">
        <div class="glass p-3 h-100">
          <h2 class="h6 text-danger">Unprotected (text pasted into the SQL)</h2>
          <pre class="small text-wrap mb-2"><?= e($unsafeSql) ?></pre>
          <?php if ($unsafeError): ?>
            <div class="alert alert-danger small mb-0">Database error: <?= e($unsafeError) ?></div>
          <?php elseif ($unsafeRows): ?>
            <div class="alert alert-danger small mb-2"><strong>Attack worked:</strong> signed in without the password as:</div>
            <ul class="small mb-0"><?php foreach ($unsafeRows as $r): ?><li><?= e($r['email']) ?> (<?= e(role_label((int) $r['role_id'])) ?>)</li><?php endforeach; ?></ul>
          <?php else: ?>
            <div class="alert alert-success small mb-0">No account matched.</div>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-md-6">
        <div class="glass p-3 h-100">
          <h2 class="h6 text-success">CulTour (prepared statement + password hash check)</h2>
          <pre class="small text-wrap mb-2">SELECT ... FROM users WHERE email = ?   -- value sent separately</pre>
          <?php if ($safeRows): ?>
            <div class="alert alert-success small mb-0">Correct email and password: signed in as <?= e($safeRows[0]['email']) ?>.</div>
          <?php else: ?>
            <div class="alert alert-success small mb-0"><strong>Attack blocked:</strong> the text was treated as a plain email, so no account matched.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <h1 class="h3 mb-3">2. Script injection (XSS)</h1>
  <form method="post" class="glass p-4 mb-3">
    <label class="form-label" for="xssText">Review text</label>
    <textarea class="form-control" id="xssText" name="xss_text" rows="2"><?= e($xssText) ?></textarea>
    <p class="small text-muted mt-3 mb-2">Try <code>&lt;script&gt;alert('Hacked!')&lt;/script&gt;</code> or <code>&lt;img src=x onerror="alert('Hacked!')"&gt;</code></p>
    <button class="btn btn-ct" name="run_xss" value="1">Post review</button>
  </form>

  <?php if ($ran && isset($_POST['run_xss'])): ?>
    <div class="row g-3 mb-5">
      <div class="col-md-6">
        <div class="glass p-3 h-100">
          <h2 class="h6 text-danger">Unprotected (printed as-is)</h2>
          <div class="border rounded p-2"><?= $xssText ?></div>
          <p class="small text-muted mt-2 mb-0">If a pop-up appeared, the script ran in the visitor's browser.</p>
        </div>
      </div>
      <div class="col-md-6">
        <div class="glass p-3 h-100">
          <h2 class="h6 text-success">CulTour (escaped with e())</h2>
          <div class="border rounded p-2"><?= e($xssText) ?></div>
          <p class="small text-muted mt-2 mb-0">Shown as plain text; nothing runs.</p>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
