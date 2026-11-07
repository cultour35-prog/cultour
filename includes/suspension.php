<?php
/**
 * Account suspension + appeal helpers
 */

function ensure_suspension_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = db()->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('suspend_reason', $cols, true)) {
            db()->exec('ALTER TABLE users ADD COLUMN suspend_reason TEXT NULL AFTER status');
        }
        if (!in_array('suspended_at', $cols, true)) {
            db()->exec('ALTER TABLE users ADD COLUMN suspended_at DATETIME NULL AFTER suspend_reason');
        }
        if (!in_array('suspended_by', $cols, true)) {
            db()->exec('ALTER TABLE users ADD COLUMN suspended_by INT UNSIGNED NULL AFTER suspended_at');
        }

        // Older installs may lack "suspended" on users.status ENUM
        $statusCol = db()->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch();
        $type = (string) ($statusCol['Type'] ?? '');
        if ($type !== '' && stripos($type, 'suspended') === false) {
            db()->exec(
                "ALTER TABLE users MODIFY status ENUM('pending','active','suspended','rejected') NOT NULL DEFAULT 'pending'"
            );
        }

        db()->exec(
            "CREATE TABLE IF NOT EXISTS suspension_appeals (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                appeal_message TEXT NOT NULL,
                status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
                admin_response TEXT NULL,
                reviewed_by INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                user_dismissed_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_appeal_user (user_id),
                INDEX idx_appeal_status (status),
                CONSTRAINT fk_appeal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_appeal_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB"
        );

        $appealCols = db()->query('SHOW COLUMNS FROM suspension_appeals')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('user_dismissed_at', $appealCols, true)) {
            db()->exec(
                'ALTER TABLE suspension_appeals ADD COLUMN user_dismissed_at DATETIME NULL AFTER reviewed_at'
            );
        }

        // Repair only the orphan case: appeal was approved for the *current*
        // suspension (reviewed at/after suspended_at) but status was left suspended.
        // Do NOT undo a later re-suspension that happened after an old approval.
        $orphans = db()->query(
            "SELECT u.id
             FROM users u
             INNER JOIN suspension_appeals a ON a.user_id = u.id
             WHERE u.status = 'suspended'
               AND a.status = 'approved'
               AND a.reviewed_at IS NOT NULL
               AND (u.suspended_at IS NULL OR a.reviewed_at >= u.suspended_at)
               AND a.id = (
                 SELECT a2.id FROM suspension_appeals a2
                 WHERE a2.user_id = u.id
                 ORDER BY COALESCE(a2.reviewed_at, a2.created_at) DESC, a2.id DESC
                 LIMIT 1
               )"
        )->fetchAll();
        foreach ($orphans as $row) {
            activate_user_account((int) $row['id'], null, null, false);
        }
    } catch (Throwable $e) {
        // ignore migrate errors on restricted hosts
    }
}

/**
 * Permanently hide an appeal result notice for this user (survives logout).
 */
function dismiss_appeal_notice(int $userId, string $dismissKey): bool
{
    ensure_suspension_schema();
    if (!preg_match('/^appeal_(\d+)$/', $dismissKey, $m)) {
        return false;
    }
    $appealId = (int) $m[1];
    start_app_session();
    $_SESSION['_dismiss_' . $dismissKey] = 1;
    try {
        db()->prepare(
            'UPDATE suspension_appeals
             SET user_dismissed_at = COALESCE(user_dismissed_at, NOW())
             WHERE id = ? AND user_id = ? AND status IN (\'approved\', \'denied\')'
        )->execute([$appealId, $userId]);
    } catch (Throwable $e) {
        // ignore
    }
    return true;
}

/**
 * On logout / leave: dismiss any visible approved/denied appeal notices for the user.
 */
function dismiss_open_appeal_notices_for_user(int $userId): void
{
    ensure_suspension_schema();
    try {
        db()->prepare(
            "UPDATE suspension_appeals
             SET user_dismissed_at = COALESCE(user_dismissed_at, NOW())
             WHERE user_id = ? AND status IN ('approved', 'denied') AND user_dismissed_at IS NULL"
        )->execute([$userId]);
    } catch (Throwable $e) {
        // ignore
    }
}

function appeal_notice_is_dismissed(array $appeal): bool
{
    $dismissKey = 'appeal_' . (int) $appeal['id'];
    start_app_session();
    if (!empty($_SESSION['_dismiss_' . $dismissKey])) {
        return true;
    }
    return !empty($appeal['user_dismissed_at']);
}

function user_is_suspended(?array $user): bool
{
    return $user && ($user['status'] ?? '') === 'suspended';
}

/**
 * Latest appeal for a user (any status).
 */
function latest_suspension_appeal(int $userId): ?array
{
    ensure_suspension_schema();
    $stmt = db()->prepare(
        'SELECT * FROM suspension_appeals WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function pending_suspension_appeal(int $userId): ?array
{
    ensure_suspension_schema();
    $stmt = db()->prepare(
        "SELECT * FROM suspension_appeals WHERE user_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Count pending appeals for admin badge.
 */
function pending_appeal_count(): int
{
    ensure_suspension_schema();
    try {
        return (int) db()->query(
            "SELECT COUNT(*) FROM suspension_appeals WHERE status='pending'"
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Count currently suspended user accounts.
 */
function suspended_user_count(): int
{
    ensure_suspension_schema();
    try {
        return (int) db()->query(
            "SELECT COUNT(*) FROM users WHERE status='suspended'"
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Admin nav badge: suspended accounts + pending appeals needing attention.
 */
function admin_suspended_nav_count(): int
{
    return suspended_user_count() + pending_appeal_count();
}

/**
 * Suspend a user with a required reason.
 */
function suspend_user(int $userId, int $adminId, string $reason): array
{
    ensure_suspension_schema();
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'message' => 'A suspension reason is required.'];
    }
    $len = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
    if ($len > 2000) {
        return ['ok' => false, 'message' => 'Reason is too long (max 2000 characters).'];
    }

    $stmt = db()->prepare('SELECT id, status, role_id, email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target) {
        return ['ok' => false, 'message' => 'User not found.'];
    }
    if ((int) $target['role_id'] === ROLE_ADMIN) {
        return ['ok' => false, 'message' => 'Administrators cannot be suspended.'];
    }
    if (($target['status'] ?? '') === 'suspended') {
        return ['ok' => false, 'message' => 'User is already suspended.'];
    }

    $ids = [$userId];
    if (in_array((int) $target['role_id'], linkable_role_ids(), true)) {
        foreach (linked_role_accounts((string) ($target['email'] ?? ''), $userId) as $linked) {
            $lid = (int) ($linked['id'] ?? 0);
            if ($lid > 0 && ($linked['status'] ?? '') !== 'suspended') {
                $ids[] = $lid;
            }
        }
    }
    $ids = array_values(array_unique($ids));

    $upd = db()->prepare(
        "UPDATE users SET status='suspended', suspend_reason=?, suspended_at=NOW(), suspended_by=?, remember_token=NULL WHERE id=?"
    );
    foreach ($ids as $id) {
        $upd->execute([$reason, $adminId, $id]);
    }

    // Hide public business listing while the owner is suspended
    if ((int) $target['role_id'] === ROLE_BUSINESS) {
        db()->prepare("UPDATE businesses SET status='inactive' WHERE user_id=?")->execute([$userId]);
    }

    notify_user(
        $userId,
        'account',
        'Account suspended',
        $reason,
        role_dashboard((int) $target['role_id'])
    );

    return ['ok' => true, 'message' => 'User suspended.'];
}

/**
 * Reactivate a suspended (or inactive) user and clear suspension fields.
 * @return array{ok:bool,message:string}
 */
function activate_user_account(
    int $userId,
    ?int $adminId = null,
    ?string $note = null,
    bool $notify = true
): array {
    ensure_suspension_schema();

    $st = db()->prepare('SELECT id, role_id, status FROM users WHERE id=? LIMIT 1');
    $st->execute([$userId]);
    $target = $st->fetch();
    if (!$target) {
        return ['ok' => false, 'message' => 'User not found.'];
    }

    $newStatus = 'active';
    $role = (int) $target['role_id'];
    if ($role === ROLE_TOUR_GUIDE || $role === ROLE_BUSINESS) {
        $gate = registration_approval_gate([
            'id' => $userId,
            'role_id' => $role,
            'status' => $target['status'],
        ]);
        if (!$gate['ok']) {
            // Still lift the suspension; leave them pending registration approval
            $newStatus = 'pending';
        }
    }

    db()->prepare(
        'UPDATE users SET status=?, suspend_reason=NULL, suspended_at=NULL, suspended_by=NULL WHERE id=?'
    )->execute([$newStatus, $userId]);

    // Restore approved business listings when the owner is reactivated
    if ($role === ROLE_BUSINESS) {
        db()->prepare(
            "UPDATE businesses SET status='active' WHERE user_id=? AND approval_status='approved'"
        )->execute([$userId]);
    }

    // Close any open appeals as approved if reactivating outside appeal review
    if ($adminId) {
        db()->prepare(
            "UPDATE suspension_appeals
             SET status='approved',
                 admin_response=COALESCE(NULLIF(?, ''), 'Your account has been reactivated.'),
                 reviewed_by=?,
                 reviewed_at=NOW()
             WHERE user_id=? AND status='pending'"
        )->execute([$note, $adminId, $userId]);
    }

    if ($newStatus === 'pending') {
        $where = $role === ROLE_TOUR_GUIDE ? 'Tour Guides' : 'Businesses';
        if ($notify) {
            notify_user(
                $userId,
                'account',
                'Suspension lifted',
                'Your suspension was lifted, but your registration is still pending Tourism Office approval.',
                APP_URL . '/login.php'
            );
        }
        return [
            'ok' => true,
            'message' => 'Suspension lifted. Account is pending registration approval — finish approving it under Admin → ' . $where . '.',
        ];
    }

    if ($notify) {
        notify_user(
            $userId,
            'account',
            'Account activated',
            $note ?: 'Your CulTour account is active again. You can use the app normally.',
            APP_URL . '/login.php'
        );
    }

    return ['ok' => true, 'message' => 'User activated.'];
}

/**
 * Fingerprint for live polling of a user's account/appeal state.
 */
function account_status_version(array $user, ?array $appeal = null): string
{
    $appeal = $appeal ?? latest_suspension_appeal((int) $user['id']);
    return md5(json_encode([
        $user['status'] ?? '',
        $user['suspend_reason'] ?? '',
        $user['suspended_at'] ?? '',
        $appeal['id'] ?? 0,
        $appeal['status'] ?? '',
        $appeal['admin_response'] ?? '',
        $appeal['updated'] ?? ($appeal['reviewed_at'] ?? ''),
        $appeal['created_at'] ?? '',
        $appeal['user_dismissed_at'] ?? '',
    ]));
}

/**
 * HTML for suspension / appeal panels (no outer wrapper).
 */
function account_status_panel_inner_html(array $user): string
{
    ensure_suspension_schema();
    $appeal = latest_suspension_appeal((int) $user['id']);
    $pending = ($appeal && $appeal['status'] === 'pending') ? $appeal : null;
    ob_start();

    if (user_is_suspended($user)): ?>
      <div class="glass p-4 mb-4 fade-up" style="border-left:4px solid #c0392b">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
          <h2 class="h5 text-danger mb-0"><i class="bi bi-shield-exclamation me-1"></i> Account suspended</h2>
          <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Log out</a>
        </div>
        <p class="mb-1 fw-semibold">Reason from the Tourism Office:</p>
        <p class="mb-3"><?= e($user['suspend_reason'] ?: 'No reason was provided. Please contact the Tourism Office.') ?></p>
        <?php if (!empty($user['suspended_at'])): ?>
          <p class="small text-muted mb-3">Suspended <?= e(format_date($user['suspended_at'], 'M j, Y g:i A')) ?></p>
        <?php endif; ?>

        <?php if ($pending): ?>
          <div class="alert alert-warning mb-0">
            <strong>Appeal submitted</strong> — waiting for admin review.
            <div class="small mt-1"><?= e($pending['appeal_message']) ?></div>
          </div>
        <?php else: ?>
          <?php if ($appeal && $appeal['status'] === 'denied'): ?>
            <div class="alert alert-danger mb-3">
              <strong>Appeal denied</strong>
              <?php if (!empty($appeal['admin_response'])): ?>
                <div class="mt-1"><?= e($appeal['admin_response']) ?></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <form method="post" action="<?= APP_URL ?>/appeal.php" id="appealForm" data-live-appeal="1">
            <?= csrf_field() ?>
            <label class="form-label"><?= ($appeal && $appeal['status'] === 'denied') ? 'Submit another appeal' : 'Appeal this suspension' ?></label>
            <textarea class="form-control mb-2" name="appeal_message" rows="4" required maxlength="2000" placeholder="Explain why your account should be restored…"></textarea>
            <button type="submit" class="btn btn-warning">Submit appeal</button>
            <div class="small text-danger mt-2 d-none" data-appeal-error></div>
          </form>
        <?php endif; ?>
      </div>
    <?php elseif ($appeal && in_array($appeal['status'], ['approved', 'denied'], true) && !appeal_notice_is_dismissed($appeal)):
        $dismissKey = 'appeal_' . (int) $appeal['id'];
        $ok = $appeal['status'] === 'approved';
    ?>
      <div class="alert <?= $ok ? 'alert-success' : 'alert-warning' ?> alert-dismissible fade show fade-up" role="alert" data-appeal-notice="<?= (int) $appeal['id'] ?>">
        <strong><?= $ok ? 'Appeal approved — account restored' : 'Appeal review update' ?></strong>
        <div class="mt-1">
          <?= e($appeal['admin_response'] ?: ($ok ? 'Your CulTour account is active again.' : 'Please contact the Tourism Office for details.')) ?>
        </div>
        <button type="button" class="btn-close" data-dismiss-appeal="<?= e($dismissKey) ?>" aria-label="Dismiss"></button>
      </div>
    <?php
    endif;

    return (string) ob_get_clean();
}

/**
 * Live payload for account status polling.
 */
function account_status_payload(array $user): array
{
    ensure_suspension_schema();
    // Fresh row (avoid stale session cache during long requests)
    $stmt = db()->prepare(
        'SELECT u.*, r.name AS role_name FROM users u
         JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1'
    );
    $stmt->execute([(int) $user['id']]);
    $fresh = $stmt->fetch() ?: $user;
    $appeal = latest_suspension_appeal((int) $fresh['id']);

    return [
        'ok' => true,
        'status' => $fresh['status'],
        'suspended' => user_is_suspended($fresh),
        'suspend_reason' => $fresh['suspend_reason'] ?? null,
        'suspended_at' => $fresh['suspended_at'] ?? null,
        'dashboard_url' => role_dashboard((int) $fresh['role_id']),
        'version' => account_status_version($fresh, $appeal),
        'appeal' => $appeal ? [
            'id' => (int) $appeal['id'],
            'status' => $appeal['status'],
            'message' => $appeal['appeal_message'],
            'admin_response' => $appeal['admin_response'],
        ] : null,
        'html' => account_status_panel_inner_html($fresh),
    ];
}

/**
 * Review a suspension appeal (approve / deny).
 */
function review_suspension_appeal(int $appealId, int $adminId, string $action, string $response = ''): array
{
    ensure_suspension_schema();
    $stmt = db()->prepare(
        "SELECT a.*, u.role_id
         FROM suspension_appeals a
         JOIN users u ON u.id = a.user_id
         WHERE a.id = ? LIMIT 1"
    );
    $stmt->execute([$appealId]);
    $appeal = $stmt->fetch();
    if (!$appeal || $appeal['status'] !== 'pending') {
        return ['ok' => false, 'message' => 'Appeal not found or already reviewed.'];
    }

    $response = trim($response);
    if ($response === '') {
        $response = $action === 'approve'
            ? 'Your appeal was approved. Your CulTour account is active again.'
            : 'Your appeal was denied. Your account remains suspended.';
    }

    if ($action === 'approve') {
        $uid = (int) $appeal['user_id'];
        $role = (int) $appeal['role_id'];

        db()->prepare(
            "UPDATE suspension_appeals
             SET status='approved', admin_response=?, reviewed_by=?, reviewed_at=NOW()
             WHERE id=?"
        )->execute([$response, $adminId, $appealId]);

        // Lift suspension using the same rules as Activate
        $activated = activate_user_account($uid, null, $response, false);
        if (empty($activated['ok'])) {
            // Hard fallback — never leave an "approved" appeal on a still-suspended user
            db()->prepare(
                "UPDATE users SET status='active', suspend_reason=NULL, suspended_at=NULL, suspended_by=NULL WHERE id=?"
            )->execute([$uid]);
            if ($role === ROLE_BUSINESS) {
                db()->prepare(
                    "UPDATE businesses SET status='active' WHERE user_id=? AND approval_status='approved'"
                )->execute([$uid]);
            }
            $activated = ['ok' => true, 'message' => 'User activated.'];
        }

        // Confirm user is no longer suspended
        $st = db()->prepare('SELECT status FROM users WHERE id=?');
        $st->execute([$uid]);
        if ($st->fetchColumn() === 'suspended') {
            db()->prepare(
                "UPDATE users SET status='active', suspend_reason=NULL, suspended_at=NULL, suspended_by=NULL WHERE id=?"
            )->execute([$uid]);
            if ($role === ROLE_BUSINESS) {
                db()->prepare(
                    "UPDATE businesses SET status='active' WHERE user_id=? AND approval_status='approved'"
                )->execute([$uid]);
            }
        }

        notify_user(
            $uid,
            'account',
            'Appeal approved',
            $response,
            role_dashboard($role)
        );
        log_activity($adminId, 'approve_appeal', 'users', 'Appeal #' . $appealId);
        return [
            'ok' => true,
            'message' => 'Appeal approved. ' . ($activated['message'] ?? 'User reactivated.'),
        ];
    }

    if ($action === 'deny') {
        db()->prepare(
            "UPDATE suspension_appeals
             SET status='denied', admin_response=?, reviewed_by=?, reviewed_at=NOW()
             WHERE id=?"
        )->execute([$response, $adminId, $appealId]);
        notify_user(
            (int) $appeal['user_id'],
            'account',
            'Appeal denied',
            $response,
            role_dashboard((int) $appeal['role_id'])
        );
        log_activity($adminId, 'deny_appeal', 'users', 'Appeal #' . $appealId);
        return ['ok' => true, 'message' => 'Appeal denied. User remains suspended.'];
    }

    return ['ok' => false, 'message' => 'Invalid action.'];
}

/**
 * Admin appeals / suspended list HTML + version for live refresh.
 * Filters: suspended | pending | approved | denied | all
 */
function admin_appeals_list_html(string $filter = 'suspended'): array
{
    ensure_suspension_schema();
    if (!in_array($filter, ['suspended', 'pending', 'approved', 'denied', 'all'], true)) {
        $filter = 'suspended';
    }

    if ($filter === 'suspended') {
        return admin_suspended_accounts_list_html();
    }

    $sql = "SELECT a.*, u.first_name, u.last_name, u.email, u.suspend_reason, u.status AS user_status, r.name AS role_name,
                   rev.first_name AS rev_first, rev.last_name AS rev_last
            FROM suspension_appeals a
            JOIN users u ON u.id = a.user_id
            JOIN roles r ON r.id = u.role_id
            LEFT JOIN users rev ON rev.id = a.reviewed_by";
    $params = [];
    if ($filter !== 'all') {
        $sql .= ' WHERE a.status = ?';
        $params[] = $filter;
    }
    $sql .= " ORDER BY FIELD(a.status, 'pending', 'denied', 'approved'), a.created_at DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $appeals = $stmt->fetchAll();

    $verStmt = db()->query(
        "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(MAX(reviewed_at),'0'), COALESCE(MAX(created_at),'0')
         FROM suspension_appeals"
    )->fetch(PDO::FETCH_NUM);
    $version = md5(json_encode([$filter, $verStmt, pending_appeal_count(), suspended_user_count()]));

    ob_start();
    if (!$appeals): ?>
      <div class="glass p-4 text-muted">No appeals in this list.</div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($appeals as $a): ?>
          <div class="col-lg-6">
            <div class="glass p-3 p-md-4 h-100">
              <div class="d-flex justify-content-between gap-2 mb-2">
                <div>
                  <div class="fw-semibold"><?= e(trim($a['first_name'] . ' ' . $a['last_name'])) ?></div>
                  <div class="small text-muted"><?= e($a['email']) ?> · <?= e(ucfirst(str_replace('_', ' ', $a['role_name']))) ?></div>
                </div>
                <?= status_badge($a['status']) ?>
              </div>
              <div class="small text-muted mb-1">Original suspension reason</div>
              <p class="mb-3"><?= e($a['suspend_reason'] ?: '—') ?></p>
              <div class="small text-muted mb-1">User appeal</div>
              <p class="mb-3"><?= e($a['appeal_message']) ?></p>
              <div class="small text-muted mb-3">Submitted <?= e(format_date($a['created_at'], 'M j, Y g:i A')) ?></div>
              <?php if ($a['status'] === 'pending'): ?>
                <form method="post" class="border-top pt-3" action="<?= APP_URL ?>/admin/appeals.php?status=pending">
                  <?= csrf_field() ?>
                  <input type="hidden" name="appeal_id" value="<?= (int) $a['id'] ?>">
                  <label class="form-label">Response to user</label>
                  <textarea class="form-control mb-2" name="admin_response" rows="3" maxlength="2000" placeholder="Optional note shown on their dashboard…"></textarea>
                  <div class="d-flex flex-wrap gap-2">
                    <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">Approve &amp; reactivate</button>
                    <button type="submit" name="action" value="deny" class="btn btn-outline-danger btn-sm">Deny appeal</button>
                  </div>
                </form>
              <?php else: ?>
                <div class="border-top pt-3">
                  <div class="small text-muted mb-1">Admin response</div>
                  <p class="mb-1"><?= e($a['admin_response'] ?: '—') ?></p>
                  <div class="small text-muted">
                    Reviewed <?= e(format_date($a['reviewed_at'], 'M j, Y g:i A')) ?>
                    <?php if ($a['rev_first']): ?> by <?= e(trim($a['rev_first'] . ' ' . $a['rev_last'])) ?><?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif;

    return [
        'html' => (string) ob_get_clean(),
        'version' => $version,
        'pending' => pending_appeal_count(),
        'filter' => $filter,
    ];
}

/**
 * List currently suspended accounts with Activate action.
 */
function admin_suspended_accounts_list_html(): array
{
    ensure_suspension_schema();

    $rows = db()->query(
        "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.avatar, u.suspend_reason, u.suspended_at, u.role_id,
                r.name AS role_name,
                (SELECT a.id FROM suspension_appeals a
                 WHERE a.user_id = u.id AND a.status = 'pending'
                 ORDER BY a.created_at DESC LIMIT 1) AS pending_appeal_id
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.status = 'suspended'
         ORDER BY u.suspended_at DESC, u.id DESC"
    )->fetchAll() ?: [];

    $verParts = db()->query(
        "SELECT COUNT(*), COALESCE(MAX(suspended_at),'0'), COALESCE(MAX(id),0) FROM users WHERE status='suspended'"
    )->fetch(PDO::FETCH_NUM);
    $version = md5(json_encode(['suspended', $verParts]));

    ob_start();
    if (!$rows): ?>
      <div class="glass p-4 text-muted">No suspended accounts right now.</div>
    <?php else: ?>
      <div class="table-responsive glass p-2 p-md-3">
        <table class="table table-ct align-middle mb-0">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Role</th>
              <th>Reason</th>
              <th>Suspended</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $u):
                $name = full_name($u);
                $avatar = trim((string) ($u['avatar'] ?? ''));
                $avatarUrl = $avatar !== '' ? (APP_URL . '/uploads/' . ltrim($avatar, '/')) : '';
                $initialSource = $name !== '' && $name !== 'Guest' ? $name : (string) ($u['first_name'] ?? '?');
                $initial = mb_strtoupper(mb_substr(trim($initialSource) !== '' ? $initialSource : '?', 0, 1));
            ?>
              <tr>
                <td>
                  <div class="d-flex align-items-center gap-2 min-w-0">
                    <?php if ($avatarUrl): ?>
                      <img class="admin-guide-thumb" src="<?= e($avatarUrl) ?>" alt="">
                    <?php else: ?>
                      <span class="admin-guide-thumb admin-guide-thumb--empty" aria-hidden="true"><?= e($initial) ?></span>
                    <?php endif; ?>
                    <div class="min-w-0">
                      <div class="fw-semibold text-truncate"><?= e($name) ?></div>
                      <?php if (!empty($u['pending_appeal_id'])): ?>
                        <span class="badge text-bg-warning">Appeal pending</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td><?= e($u['email']) ?></td>
                <td><?= e(ucfirst(str_replace('_', ' ', $u['role_name']))) ?></td>
                <td style="max-width:240px">
                  <span class="small"><?= e(function_exists('mb_strimwidth')
                      ? mb_strimwidth((string) ($u['suspend_reason'] ?? '—'), 0, 90, '…')
                      : ((strlen((string) ($u['suspend_reason'] ?? '')) > 90)
                          ? substr((string) $u['suspend_reason'], 0, 89) . '…'
                          : ((string) ($u['suspend_reason'] ?? '—')))) ?></span>
                </td>
                <td class="text-nowrap"><?= e(format_date($u['suspended_at'], 'M j, Y')) ?></td>
                <td class="text-nowrap">
                  <form method="post" class="d-inline" action="<?= APP_URL ?>/admin/appeals.php?status=suspended">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="activate_user">
                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success" name="activate_btn" value="1">Activate</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif;

    return [
        'html' => (string) ob_get_clean(),
        'version' => $version,
        'pending' => pending_appeal_count(),
        'filter' => 'suspended',
    ];
}

/**
 * Render suspension / appeal result panels on role dashboards.
 */
function render_account_status_panel(array $user): void
{
    ensure_suspension_schema();

    if (!empty($_GET['dismiss']) && str_starts_with((string) $_GET['dismiss'], 'appeal_')) {
        dismiss_appeal_notice((int) $user['id'], (string) $_GET['dismiss']);
    }

    $appeal = latest_suspension_appeal((int) $user['id']);
    $version = account_status_version($user, $appeal);
    echo '<div id="accountStatusLive" data-status-version="' . e($version) . '" data-account-suspended="' . (user_is_suspended($user) ? '1' : '0') . '">';
    echo account_status_panel_inner_html($user);
    echo '</div>';
}