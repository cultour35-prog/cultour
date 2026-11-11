<?php
/**
 * Admin live registration lists (tour guides + businesses).
 */

function admin_guides_split(array $rows): array
{
    $out = ['registered' => [], 'pending' => [], 'rejected' => [], 'suspended' => []];
    foreach ($rows as $r) {
        $approval = (string) ($r['approval_status'] ?? '');
        $userStatus = (string) ($r['user_status'] ?? '');
        if ($approval === 'pending') {
            $out['pending'][] = $r;
        } elseif ($approval === 'rejected') {
            $out['rejected'][] = $r;
        } elseif ($userStatus === 'suspended') {
            $out['suspended'][] = $r;
        } else {
            $out['registered'][] = $r;
        }
    }
    return $out;
}

function admin_registrations_split(array $rows): array
{
    $out = ['registered' => [], 'pending' => [], 'rejected' => [], 'inactive' => []];
    foreach ($rows as $r) {
        $status = $r['approval_status'] ?? '';
        if ($status === 'pending') {
            $out['pending'][] = $r;
        } elseif ($status === 'rejected') {
            $out['rejected'][] = $r;
        } elseif (($r['status'] ?? '') === 'inactive' || ($r['user_status'] ?? '') === 'suspended') {
            $out['inactive'][] = $r;
        } else {
            $out['registered'][] = $r;
        }
    }
    return $out;
}

function admin_approval_badge(string $approval): string
{
    $approval = strtolower(trim($approval));
    if ($approval === 'pending') {
        return '<span class="badge bg-secondary">Pending</span>';
    }
    return status_badge($approval);
}

function pending_guide_application_count(): int
{
    try {
        return (int) db()->query(
            "SELECT COUNT(*) FROM tour_guide_profiles WHERE approval_status='pending'"
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function pending_business_application_count(): int
{
    try {
        return (int) db()->query(
            "SELECT COUNT(*) FROM businesses WHERE approval_status='pending'"
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function admin_user_identity_line(array $r, string $addressKey = 'user_address'): string
{
    $bits = [];
    $nat = trim((string) ($r['nationality'] ?? ''));
    $addr = trim((string) ($r[$addressKey] ?? ''));
    $dob = trim((string) ($r['date_of_birth'] ?? ''));
    if ($nat !== '') {
        $bits[] = $nat;
    }
    $langs = trim((string) ($r['languages'] ?? ''));
    if ($langs !== '') {
        $bits[] = $langs;
    }
    if ($addr !== '') {
        $bits[] = $addr;
    }
    if ($dob !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        $ts = strtotime($dob);
        $bits[] = 'Born ' . ($ts ? date('M j, Y', $ts) : $dob);
    }
    return implode(' · ', $bits);
}

function admin_app_text(mixed $value): string
{
    $value = trim((string) $value);
    return $value !== '' ? e($value) : '—';
}

function admin_app_address(array $row, string $prefix = ''): string
{
    $parts = array_filter([
        trim((string) ($row[$prefix . 'address_street'] ?? '')),
        trim((string) ($row[$prefix . 'address_barangay'] ?? '')),
        trim((string) ($row[$prefix . 'address_city'] ?? '')),
        trim((string) ($row[$prefix . 'address_region'] ?? '')),
    ], static fn ($part) => $part !== '');
    if ($parts) {
        return implode(', ', $parts);
    }
    if ($prefix === 'current_') {
        return trim((string) ($row['current_address'] ?? ''));
    }
    return trim((string) ($row['address'] ?? $row['user_address'] ?? ''));
}

function admin_application_files_html(array $docs): string
{
    $links = [];
    foreach ($docs as $doc) {
        $path = trim((string) ($doc['file_path'] ?? ''));
        if ($path === '') {
            continue;
        }
        $label = trim((string) ($doc['original_name'] ?? ''));
        if ($label === '') {
            $label = basename($path);
        }
        $links[] = '<a href="' . e(APP_URL . '/uploads/' . ltrim($path, '/')) . '" target="_blank" rel="noopener">' . e($label) . '</a>';
    }
    return $links ? implode('<br>', $links) : '—';
}

/** @param array<string, string> $rows */
function admin_application_body_html(array $rows): string
{
    $html = '';
    foreach ($rows as $label => $value) {
        $html .= '<dt>' . e((string) $label) . '</dt><dd>' . $value . '</dd>';
    }
    return '<dl class="admin-app-card__dl">' . $html . '</dl>';
}

function admin_view_application_control(string $title, string $bodyHtml): string
{
    return '<button type="button" class="btn btn-sm btn-outline-primary me-1" data-view-application data-app-title="' . e($title) . '">View application</button>'
        . '<template class="admin-app-source">' . $bodyHtml . '</template>';
}

function admin_application_viewer_mount(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $ver = (string) (@filemtime(ROOT_PATH . '/assets/js/admin-application.js') ?: time());
    ?>
    <div id="adminAppCard" class="admin-biz-proof-overlay" hidden>
      <div class="admin-biz-proof-card admin-app-card" role="dialog" aria-modal="true" aria-labelledby="adminAppCardTitle">
        <div class="admin-biz-proof-card__head">
          <h2 class="h6 mb-0" id="adminAppCardTitle" data-app-card-title>Application</h2>
          <button type="button" class="admin-biz-proof-card__close" data-close-app-card aria-label="Close">&times;</button>
        </div>
        <div class="admin-biz-proof-card__body" data-app-card-body></div>
      </div>
    </div>
    <script src="<?= e(APP_URL) ?>/assets/js/admin-application.js?v=<?= e($ver) ?>"></script>
    <?php
}

function admin_guides_fetch_rows(): array
{
    ensure_guide_documents_schema();
    ensure_user_identity_schema();
    if (is_file(__DIR__ . '/inhouse_guides.php')) {
        require_once __DIR__ . '/inhouse_guides.php';
        inhouse_guides_ensure_schema();
    }
    return db()->query(
        "SELECT g.*, u.first_name, u.last_name, u.middle_name, u.name_suffix, u.sex, u.date_of_birth, u.nationality,
                biz.name AS affiliation_name,
                u.address AS user_address, u.address_region, u.address_city, u.address_barangay, u.address_street,
                u.current_same_as_permanent, u.current_address, u.current_address_region, u.current_address_city,
                u.current_address_barangay, u.current_address_street,
                u.emergency_contact_name, u.emergency_contact_relationship, u.emergency_contact_phone,
                u.email, u.phone, u.avatar, u.status AS user_status,
                (SELECT COUNT(*) FROM guide_ratings gr WHERE gr.guide_id = g.id) AS review_count
         FROM tour_guide_profiles g
         JOIN users u ON u.id=g.user_id
         LEFT JOIN businesses biz ON biz.id = g.affiliated_business_id
         ORDER BY g.created_at DESC"
    )->fetchAll() ?: [];
}

function admin_businesses_fetch_rows(): array
{
    ensure_business_documents_schema();
    ensure_business_listing_schema();
    ensure_user_identity_schema();
    require_once __DIR__ . '/business_engagement.php';
    ensure_business_reviews_schema();
    return db()->query(
        "SELECT b.*, bt.name AS type_name, u.first_name, u.last_name, u.middle_name, u.sex, u.date_of_birth,
                u.nationality, u.address AS user_address, u.email, u.phone AS user_phone, u.avatar, u.status AS user_status
         FROM businesses b
         JOIN business_types bt ON bt.id=b.business_type_id
         JOIN users u ON u.id=b.user_id
         ORDER BY b.created_at DESC"
    )->fetchAll() ?: [];
}

function admin_guides_version(array $rows): string
{
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = (int) $r['id'] . ':' . ($r['approval_status'] ?? '') . ':' . ($r['user_status'] ?? '') . ':' . ($r['guide_type'] ?? '') . ':' . ($r['affiliation_name'] ?? '') . ':' . ($r['updated_at'] ?? $r['created_at'] ?? '');
    }
    return md5(implode('|', $parts) . '|g|' . count($rows));
}

function admin_businesses_version(array $rows): string
{
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = (int) $r['id'] . ':' . ($r['approval_status'] ?? '') . ':' . ($r['status'] ?? '') . ':' . ($r['user_status'] ?? '') . ':' . (!empty($r['is_featured']) ? '1' : '0') . ':' . ($r['updated_at'] ?? $r['created_at'] ?? '');
    }
    return md5(implode('|', $parts) . '|b|' . count($rows));
}

function admin_suspend_button(int $userId, string $name, string $status): string
{
    if ($userId < 1 || $status === 'suspended' || $status === 'rejected') {
        return '';
    }
    ob_start();
    ?>
    <button type="button"
            class="btn btn-sm btn-warning"
            data-bs-toggle="modal"
            data-bs-target="#suspendModal"
            data-user-id="<?= $userId ?>"
            data-user-name="<?= e($name) ?>">
      Suspend
    </button>
    <?php
    return trim((string) ob_get_clean());
}

function admin_try_suspend_role_user(int $userId, int $adminId, int $expectedRoleId, string $reason): array
{
    if ($userId < 1) {
        return ['ok' => false, 'message' => 'User not found.'];
    }
    $st = db()->prepare('SELECT id FROM users WHERE id=? AND role_id=?');
    $st->execute([$userId, $expectedRoleId]);
    if (!$st->fetch()) {
        return ['ok' => false, 'message' => 'User not found.'];
    }
    return suspend_user($userId, $adminId, $reason);
}

function admin_render_guide_rows(array $list, array $docsByGuide, bool $showApprove, bool $showAffiliation = false): string
{
    if (!$list) {
        return '';
    }
    if ($showApprove && !$docsByGuide) {
        $pendingIds = [];
        foreach ($list as $row) {
            if (($row['approval_status'] ?? '') === 'pending') {
                $pendingIds[] = (int) $row['id'];
            }
        }
        $docsByGuide = guide_documents_map($pendingIds);
    }
    ob_start();
    foreach ($list as $r) {
        $name = full_name($r);
        $isPending = ($r['approval_status'] ?? '') === 'pending';
        $appControl = '';
        if ($isPending) {
            $currentAddress = !empty($r['current_same_as_permanent'])
                ? 'Same as permanent address'
                : admin_app_address($r, 'current_');
            $years = $r['years_experience'] ?? null;
            $appControl = admin_view_application_control($name, admin_application_body_html([
                'Name' => admin_app_text($name),
                'Email' => admin_app_text($r['email'] ?? ''),
                'Phone' => admin_app_text($r['phone'] ?? ''),
                'Gender' => admin_app_text(user_sex_label((string) ($r['sex'] ?? ''))),
                'Date of birth' => admin_app_text(format_date((string) ($r['date_of_birth'] ?? ''))),
                'Nationality' => admin_app_text($r['nationality'] ?? ''),
                'Permanent address' => admin_app_text(admin_app_address($r)),
                'Current address' => admin_app_text($currentAddress),
                'Languages' => admin_app_text($r['languages'] ?? ''),
                'Years of experience' => admin_app_text($years === null || $years === '' ? '' : (string) (int) $years),
                'Emergency contact' => admin_app_text($r['emergency_contact_name'] ?? ''),
                'Relationship' => admin_app_text($r['emergency_contact_relationship'] ?? ''),
                'Emergency phone' => admin_app_text($r['emergency_contact_phone'] ?? ''),
                'Resume / credentials' => admin_application_files_html($docsByGuide[(int) $r['id']] ?? []),
            ]));
        }
        $avatar = trim((string) ($r['avatar'] ?? ''));
        $avatarUrl = $avatar !== '' ? (APP_URL . '/uploads/' . $avatar) : '';
        ?>
        <tr>
          <td class="guide-col-guide">
            <div class="d-flex align-items-center gap-2 min-w-0">
              <?php if ($avatarUrl): ?>
                <img class="admin-guide-thumb" src="<?= e($avatarUrl) ?>" alt="">
              <?php else: ?>
                <span class="admin-guide-thumb admin-guide-thumb--empty" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($r['first_name'] ?? 'G', 0, 1))) ?></span>
              <?php endif; ?>
              <div class="min-w-0">
                <?php if ($isPending): ?>
                  <button type="button" class="fw-semibold text-truncate d-block admin-guide-name admin-app-name" data-view-application data-app-title="<?= e($name) ?>"><?= e($name) ?></button>
                <?php else: ?>
                  <a class="fw-semibold text-truncate d-block admin-guide-name" href="<?= APP_URL ?>/admin/guides.php?view=<?= (int) $r['id'] ?>"><?= e($name) ?></a>
                <?php endif; ?>
                <div class="small text-muted text-truncate"><?= e($r['email']) ?><?= $r['phone'] ? ' · ' . e($r['phone']) : '' ?></div>
              </div>
            </div>
          </td>
          <td class="guide-col-lang"><?= e($r['languages'] ?? '—') ?></td>
          <?php if ($showAffiliation): ?>
          <td><?= e(trim((string) ($r['affiliation_name'] ?? '')) !== '' ? (string) $r['affiliation_name'] : '—') ?></td>
          <?php endif; ?>
          <td class="guide-col-status">
            <?php
            $guideStatus = $r;
            $guideStatus['role_id'] = ROLE_TOUR_GUIDE;
            $guideStatus['status'] = (string) ($r['user_status'] ?? 'active');
            $guideStatus['guide_approval_status'] = (string) ($r['approval_status'] ?? '');
            $guideStatus['guide_id'] = (int) ($r['id'] ?? 0);
            echo admin_user_status_badge($guideStatus);
            ?>
          </td>
          <td class="guide-col-rate"><?= e(format_date($r['created_at'] ?? '')) ?></td>
          <td class="guide-col-actions text-nowrap">
            <?php if (!$showApprove): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/guides.php?edit=<?= (int) $r['id'] ?>">Edit</a>
            <?= admin_suspend_button((int) ($r['user_id'] ?? 0), $name, (string) ($r['user_status'] ?? '')) ?>
            <?php endif; ?>
            <?php if ($showApprove && $isPending): ?>
              <?= $appControl ?>
              <form method="post" class="d-inline-flex gap-1"><?= csrf_field() ?>
                <input type="hidden" name="guide_id" value="<?= (int) $r['id'] ?>">
                <button name="action" value="approve" class="btn btn-sm btn-success">Approve</button>
                <button name="action" value="reject" class="btn btn-sm btn-outline-danger">Reject</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php
    }
    return (string) ob_get_clean();
}

function admin_render_business_rows(array $list, array $docsByBiz, bool $showApprove): string
{
    if (!$list) {
        return '';
    }
    if ($showApprove && !$docsByBiz) {
        $pendingIds = [];
        foreach ($list as $row) {
            if (($row['approval_status'] ?? '') === 'pending') {
                $pendingIds[] = (int) $row['id'];
            }
        }
        $docsByBiz = business_documents_map($pendingIds);
    }
    ob_start();
    foreach ($list as $r) {
        $isPending = ($r['approval_status'] ?? '') === 'pending';
        $bizName = (string) ($r['name'] ?? '');
        $appControl = '';
        if ($isPending) {
            $ownerName = trim((string) ($r['owner_name'] ?? ''));
            if ($ownerName === '') {
                $ownerName = full_name($r);
            }
            $appControl = admin_view_application_control($bizName, admin_application_body_html([
                'Business name' => admin_app_text($bizName),
                'Business category' => admin_app_text($r['type_name'] ?? ''),
                'Representative' => admin_app_text($r['representative_name'] ?? ''),
                'Representative gender' => admin_app_text(user_sex_label((string) ($r['representative_sex'] ?? ''))),
                'Business owner' => admin_app_text($ownerName),
                'Owner gender' => admin_app_text(user_sex_label((string) ($r['sex'] ?? ''))),
                'Business email' => admin_app_text($r['email'] ?? ''),
                'Contact number' => admin_app_text($r['phone'] ?? ''),
                'Registration number' => admin_app_text($r['permit_number'] ?? ''),
                'Tax identification number' => admin_app_text($r['tax_id'] ?? ''),
                'Address' => admin_app_text((string) ($r['address'] ?? '') !== '' ? (string) $r['address'] : admin_app_address($r)),
                'Proof of business registration' => admin_application_files_html($docsByBiz[(int) $r['id']] ?? []),
            ]));
        }
        ?>
        <tr>
          <td class="biz-col-biz">
            <div class="d-flex align-items-center gap-2 min-w-0">
              <?= admin_list_photo_html(trim((string) ($r['logo'] ?? '')) !== '' ? (string) $r['logo'] : (string) ($r['cover_image'] ?? '')) ?>
              <div class="min-w-0">
                <?php if ($isPending): ?>
                  <button type="button" class="fw-semibold admin-app-name" data-view-application data-app-title="<?= e($bizName) ?>"><?= e($bizName) ?></button>
                <?php else: ?>
                  <a class="fw-semibold text-decoration-none" href="<?= APP_URL ?>/admin/business.php?id=<?= (int) $r['id'] ?>">
                    <?= e($bizName) ?>
                  </a>
                <?php endif; ?>
                <div class="small text-muted text-truncate"><?= e($r['address']) ?></div>
                <?php if (trim((string) ($r['permit_number'] ?? '')) !== ''): ?>
                  <div class="small text-muted text-truncate">Permit <?= e($r['permit_number']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td class="biz-col-type"><?= e($r['type_name']) ?></td>
          <td class="biz-col-owner">
            <?= e(trim((string) ($r['owner_name'] ?? '')) !== '' ? (string) $r['owner_name'] : full_name($r)) ?><br>
            <span class="small text-muted text-truncate d-inline-block" style="max-width:100%"><?= e($r['email']) ?></span>
            <?php $identLine = admin_user_identity_line($r); ?>
            <?php if ($identLine !== ''): ?>
              <div class="small text-muted text-truncate"><?= e($identLine) ?></div>
            <?php endif; ?>
          </td>
          <td class="biz-col-map">
            <?php
            $listingHidden = ($r['approval_status'] ?? '') === 'approved'
                && (($r['status'] ?? '') === 'inactive' || ($r['user_status'] ?? '') === 'suspended');
            if ($listingHidden): ?>
              <span class="badge text-bg-secondary">Hidden</span>
            <?php elseif ($r['latitude'] !== null && $r['longitude'] !== null): ?>
              <span class="badge text-bg-success">Pinned</span>
            <?php else: ?>
              <span class="badge text-bg-secondary">Missing</span>
            <?php endif; ?>
          </td>
          <td class="biz-col-status">
            <span class="biz-status-row">
            <?php
            if (($r['approval_status'] ?? '') === 'approved') {
                if (($r['user_status'] ?? '') === 'suspended') {
                    echo status_badge('suspended');
                } elseif (($r['status'] ?? '') === 'inactive') {
                    echo status_badge('inactive');
                } else {
                    echo status_badge('approved');
                }
                if (!empty($r['is_featured'])) {
                    echo '<span class="badge text-bg-primary">Featured</span>';
                }
            } else {
                echo admin_approval_badge((string) ($r['approval_status'] ?? ''));
            }
            ?>
            </span>
          </td>
          <td class="biz-col-actions text-nowrap">
            <?php if (($r['approval_status'] ?? '') !== 'pending'): ?>
              <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int) $r['id'] ?>">Edit</a>
            <?php endif; ?>
            <?php
            $bizApproved = ($r['approval_status'] ?? '') === 'approved';
            $bizInactive = ($r['status'] ?? '') === 'inactive';
            $ownerSuspended = ($r['user_status'] ?? '') === 'suspended';
            if ($bizApproved && !$bizInactive && !$ownerSuspended):
            ?>
              <form method="post" class="d-inline"><?= csrf_field() ?>
                <input type="hidden" name="business_id" value="<?= (int) $r['id'] ?>">
                <button name="action" value="deactivate" class="btn btn-sm btn-warning">Deactivate</button>
              </form>
            <?php elseif ($bizApproved && $bizInactive && !$ownerSuspended): ?>
              <form method="post" class="d-inline"><?= csrf_field() ?>
                <input type="hidden" name="business_id" value="<?= (int) $r['id'] ?>">
                <button name="action" value="activate" class="btn btn-sm btn-success">Activate</button>
              </form>
            <?php endif; ?>
            <?php if ($showApprove && $isPending): ?>
              <?= $appControl ?>
              <form method="post" class="d-inline-flex gap-1"><?= csrf_field() ?>
                <input type="hidden" name="business_id" value="<?= (int) $r['id'] ?>">
                <button name="action" value="approve" class="btn btn-sm btn-success">Approve</button>
                <button name="action" value="reject" class="btn btn-sm btn-outline-danger">Reject</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php
    }
    return (string) ob_get_clean();
}

function admin_tourists_fetch_rows(): array
{
    ensure_user_identity_schema();
    return db()->query(
        'SELECT u.* FROM users u WHERE u.role_id=' . ROLE_TOURIST . ' ORDER BY u.created_at DESC'
    )->fetchAll() ?: [];
}

function admin_tourists_split(array $rows): array
{
    $out = ['registered' => [], 'suspended' => []];
    foreach ($rows as $r) {
        $status = (string) ($r['status'] ?? 'active');
        if ($status === 'suspended') {
            $out['suspended'][] = $r;
        } elseif ($status !== 'rejected') {
            $out['registered'][] = $r;
        }
    }
    return $out;
}

function admin_tourists_version(array $rows): string
{
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = (int) $r['id'] . ':' . ($r['status'] ?? '') . ':' . ($r['updated_at'] ?? $r['created_at'] ?? '');
    }
    return md5(implode('|', $parts) . '|t|' . count($rows));
}

function admin_tourist_address_line(array $r, string $prefix = ''): string
{
    $parts = array_filter([
        trim((string) ($r[$prefix . 'address_street'] ?? '')),
        trim((string) ($r[$prefix . 'address_barangay'] ?? '')),
        trim((string) ($r[$prefix . 'address_city'] ?? '')),
        trim((string) ($r[$prefix . 'address_region'] ?? '')),
    ], static fn ($p) => $p !== '');
    if ($parts) {
        return implode(', ', $parts);
    }
    if ($prefix === '') {
        return trim((string) ($r['address'] ?? ''));
    }
    return trim((string) ($r['current_address'] ?? ''));
}

function admin_render_tourist_rows(array $list): string
{
    if (!$list) {
        return '';
    }
    ob_start();
    foreach ($list as $r) {
        $name = full_name($r);
        $avatar = trim((string) ($r['avatar'] ?? ''));
        $avatarUrl = $avatar !== '' ? (APP_URL . '/uploads/' . ltrim($avatar, '/')) : '';
        ?>
        <tr>
          <td class="guide-col-guide">
            <div class="d-flex align-items-center gap-2 min-w-0">
              <?php if ($avatarUrl): ?>
                <img class="admin-guide-thumb" src="<?= e($avatarUrl) ?>" alt="">
              <?php else: ?>
                <span class="admin-guide-thumb admin-guide-thumb--empty" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($r['first_name'] ?? 'T', 0, 1))) ?></span>
              <?php endif; ?>
              <div class="min-w-0">
                <a class="fw-semibold text-truncate d-block admin-guide-name" href="<?= APP_URL ?>/admin/tourists.php?view=<?= (int) $r['id'] ?>"><?= e($name) ?></a>
                <div class="small text-muted text-truncate"><?= e($r['email']) ?><?= !empty($r['phone']) ? ' · ' . e($r['phone']) : '' ?></div>
              </div>
            </div>
          </td>
          <td class="guide-col-lang"><?= e(trim((string) ($r['languages'] ?? '')) !== '' ? (string) $r['languages'] : '—') ?></td>
          <td class="guide-col-status">
            <?php
            $touristStatus = $r;
            $touristStatus['role_id'] = ROLE_TOURIST;
            echo admin_user_status_badge($touristStatus);
            ?>
          </td>
          <td class="guide-col-rate"><?= e(format_date($r['created_at'] ?? '')) ?></td>
          <td class="guide-col-actions text-nowrap">
            <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/tourists.php?edit=<?= (int) $r['id'] ?>">Edit</a>
            <?= admin_suspend_button((int) $r['id'], $name, (string) ($r['status'] ?? '')) ?>
          </td>
        </tr>
        <?php
    }
    return (string) ob_get_clean();
}

function admin_tourists_live_payload(): array
{
    $rows = admin_tourists_fetch_rows();
    $split = admin_tourists_split($rows);
    $counts = [
        'registered' => count($split['registered']),
        'suspended' => count($split['suspended']),
    ];
    return [
        'ok' => true,
        'kind' => 'tourists',
        'version' => admin_tourists_version($rows),
        'counts' => $counts,
        'pending' => 0,
        'registered' => $counts['registered'],
        'panels' => [
            'registered' => [
                'html' => admin_render_tourist_rows($split['registered']),
                'empty' => 'No registered tourists yet. Click Add tourist to create one.',
            ],
            'suspended' => [
                'html' => admin_render_tourist_rows($split['suspended']),
                'empty' => 'No suspended tourists.',
            ],
        ],
    ];
}

/**
 * @param array{q?:string,role?:string,status?:string,from?:string,to?:string} $filters
 */
function admin_users_fetch_rows(array $filters = []): array
{
    $allowedRoles = [
        'tourist' => ROLE_TOURIST,
        'tour_guide' => ROLE_TOUR_GUIDE,
        'business' => ROLE_BUSINESS,
    ];
    $allowedStatuses = ['pending', 'active', 'rejected', 'suspended'];

    $q = trim((string) ($filters['q'] ?? ''));
    $roleFilter = trim((string) ($filters['role'] ?? ''));
    $statusFilter = trim((string) ($filters['status'] ?? ''));
    $dateFrom = trim((string) ($filters['from'] ?? ''));
    $dateTo = trim((string) ($filters['to'] ?? ''));

    if ($roleFilter !== '' && !isset($allowedRoles[$roleFilter])) {
        $roleFilter = '';
    }
    if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
        $statusFilter = '';
    }
    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = '';
    }
    if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = '';
    }

    $sql = 'SELECT u.*, r.name AS role_name,
                   g.id AS guide_id,
                   g.bio AS guide_bio,
                   g.languages AS guide_languages,
                   g.hourly_rate AS guide_hourly_rate,
                   g.years_experience AS guide_years_experience,
                   g.approval_status AS guide_approval_status,
                   b.representative_name,
                   b.owner_name AS business_owner_name,
                   b.name AS business_name
            FROM users u
            JOIN roles r ON r.id=u.role_id
            LEFT JOIN tour_guide_profiles g ON g.user_id = u.id
            LEFT JOIN businesses b ON b.user_id = u.id
            WHERE 1=1';
    $params = [];

    if ($q !== '') {
        $sql .= ' AND (
            u.email LIKE ?
            OR u.first_name LIKE ?
            OR u.last_name LIKE ?
            OR CONCAT(u.first_name, \' \', u.last_name) LIKE ?
            OR CONCAT(u.last_name, \' \', u.first_name) LIKE ?
            OR b.representative_name LIKE ?
            OR b.owner_name LIKE ?
            OR b.name LIKE ?
        )';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($roleFilter !== '') {
        $sql .= ' AND u.role_id = ?';
        $params[] = $allowedRoles[$roleFilter];
    } else {
        $sql .= ' AND u.role_id <> ?';
        $params[] = ROLE_ADMIN;
    }
    if ($statusFilter !== '') {
        $sql .= ' AND u.status = ?';
        $params[] = $statusFilter;
    }
    if ($dateFrom !== '') {
        $sql .= ' AND DATE(u.created_at) >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $sql .= ' AND DATE(u.created_at) <= ?';
        $params[] = $dateTo;
    }

    $sql .= ' ORDER BY u.created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return admin_users_merge_linked_rows($stmt->fetchAll() ?: []);
}

/**
 * Combine Tourist + Tour Guide accounts that share an email into one list row.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function admin_users_merge_linked_rows(array $rows): array
{
    $linkable = array_flip(linkable_role_ids());
    $groups = [];
    $others = [];
    foreach ($rows as $row) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $roleId = (int) ($row['role_id'] ?? 0);
        if ($email !== '' && isset($linkable[$roleId])) {
            $groups[$email][] = $row;
        } else {
            $others[] = $row;
        }
    }

    $merged = [];
    foreach ($groups as $group) {
        if (count($group) < 2) {
            $merged[] = $group[0];
            continue;
        }
        usort($group, static function ($a, $b) {
            $ta = (string) ($a['created_at'] ?? '');
            $tb = (string) ($b['created_at'] ?? '');
            if ($ta === $tb) {
                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }
            return $ta <=> $tb;
        });
        $primary = $group[0];
        $labels = [];
        $ids = [];
        $avatar = trim((string) ($primary['avatar'] ?? ''));
        foreach ($group as $row) {
            $ids[] = (int) $row['id'];
            $roleName = trim((string) ($row['role_name'] ?? ''));
            if ($roleName !== '') {
                $labels[] = ucfirst(str_replace('_', ' ', $roleName));
            }
            if ($avatar === '') {
                $avatar = trim((string) ($row['avatar'] ?? ''));
                if ($avatar !== '') {
                    $primary['avatar'] = $avatar;
                    $primary['first_name'] = $row['first_name'] ?? $primary['first_name'];
                    $primary['last_name'] = $row['last_name'] ?? $primary['last_name'];
                    $primary['middle_name'] = $row['middle_name'] ?? ($primary['middle_name'] ?? '');
                }
            }
            if ((int) ($row['role_id'] ?? 0) === ROLE_TOUR_GUIDE) {
                foreach (['guide_id', 'guide_bio', 'guide_languages', 'guide_hourly_rate', 'guide_years_experience', 'guide_approval_status'] as $key) {
                    if (!empty($row[$key])) {
                        $primary[$key] = $row[$key];
                    }
                }
            }
        }
        $uniqueLabels = [];
        foreach ($labels as $label) {
            if (!in_array($label, $uniqueLabels, true)) {
                $uniqueLabels[] = $label;
            }
        }
        usort($uniqueLabels, static function ($a, $b) {
            $norm = static fn ($v) => strtolower(str_replace(' ', '_', $v));
            $order = ['tourist' => 0, 'tour_guide' => 1];
            return ($order[$norm($a)] ?? 9) <=> ($order[$norm($b)] ?? 9);
        });
        $primary['role_label'] = implode(' and ', $uniqueLabels);
        $primary['linked_user_ids'] = $ids;
        $merged[] = $primary;
    }

    $all = array_merge($merged, $others);
    usort($all, static function ($a, $b) {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });
    return $all;
}

function admin_users_version(array $rows): string
{
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = (int) $r['id']
            . ':' . ($r['status'] ?? '')
            . ':' . ($r['updated_at'] ?? $r['created_at'] ?? '')
            . ':' . trim((string) ($r['avatar'] ?? ''))
            . ':' . (guide_profile_is_complete($r) ? '1' : '0')
            . ':' . trim((string) ($r['guide_languages'] ?? ''))
            . ':' . (string) ($r['guide_hourly_rate'] ?? '')
            . ':' . trim((string) ($r['representative_name'] ?? ''));
    }
    return md5(implode('|', $parts) . '|u|' . count($rows));
}

function admin_user_list_name(array $u): string
{
    $rep = trim((string) ($u['representative_name'] ?? ''));
    if ((int) ($u['role_id'] ?? 0) === ROLE_BUSINESS && $rep !== '') {
        return $rep;
    }
    return full_name($u);
}

function admin_render_user_rows(array $list, int $adminId): string
{
    if (!$list) {
        return '';
    }
    ob_start();
    foreach ($list as $u) {
        $name = admin_user_list_name($u);
        $avatar = trim((string) ($u['avatar'] ?? ''));
        $avatarUrl = $avatar !== '' ? (APP_URL . '/uploads/' . ltrim($avatar, '/')) : '';
        $initialSource = $name !== '' && $name !== 'Guest' ? $name : (string) ($u['first_name'] ?? '?');
        $initial = mb_strtoupper(mb_substr(trim($initialSource) !== '' ? $initialSource : '?', 0, 1));
        ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2 min-w-0">
              <?php if ($avatarUrl): ?>
                <img class="admin-user-thumb" src="<?= e($avatarUrl) ?>" alt="">
              <?php else: ?>
                <span class="admin-user-thumb admin-user-thumb--empty" aria-hidden="true"><?= e($initial) ?></span>
              <?php endif; ?>
              <span class="fw-semibold text-truncate"><?= e($name) ?></span>
            </div>
          </td>
          <td><?= e($u['email']) ?></td>
          <td><?= e((string) ($u['role_label'] ?? ucfirst(str_replace('_', ' ', (string) ($u['role_name'] ?? ''))))) ?></td>
          <td><?= admin_user_status_badge($u) ?></td>
          <td><?= e(format_date($u['created_at'])) ?></td>
          <td class="text-nowrap">
            <?php if ((int) $u['id'] !== $adminId && (int) $u['role_id'] !== ROLE_ADMIN): ?>
              <?php if ($u['status'] !== 'suspended' && $u['status'] !== 'rejected'): ?>
                <button type="button"
                        class="btn btn-sm btn-warning"
                        data-bs-toggle="modal"
                        data-bs-target="#suspendModal"
                        data-user-id="<?= (int) $u['id'] ?>"
                        data-user-name="<?= e($name) ?>">
                  Suspend
                </button>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php
    }
    return (string) ob_get_clean();
}

/**
 * @param array{q?:string,role?:string,status?:string,from?:string,to?:string} $filters
 * @return array{ok:bool,kind:string,version:string,counts:array,panels:array,pending:int,total?:int,html?:string,empty?:string}
 */
function admin_users_live_payload(array $filters, int $adminId): array
{
    $rows = admin_users_fetch_rows($filters);
    $total = count($rows);

    return [
        'ok' => true,
        'kind' => 'users',
        'version' => admin_users_version($rows),
        'total' => $total,
        'counts' => ['total' => $total],
        'pending' => 0,
        'html' => admin_render_user_rows($rows, $adminId),
        'empty' => 'No users match these filters.',
        'panels' => [],
    ];
}

/**
 * @return array{ok:bool,kind:string,version:string,counts:array,panels:array,pending:int}
 */
function admin_registrations_live_payload(string $kind): array
{
    $kind = $kind === 'businesses' ? 'businesses' : 'guides';

    if ($kind === 'guides') {
        $rows = admin_guides_fetch_rows();
        $systemRows = [];
        $inhouseRows = [];
        foreach ($rows as $guideRow) {
            if ((string) ($guideRow['guide_type'] ?? 'olga') === 'in_house') {
                $inhouseRows[] = $guideRow;
            } else {
                $systemRows[] = $guideRow;
            }
        }
        $split = admin_guides_split($systemRows);
        $inhouseSplit = admin_guides_split($inhouseRows);
        $panels = [
            'registered' => [
                'html' => admin_render_guide_rows($split['registered'], [], false),
                'empty' => 'No active tour guides yet. Click Add tour guide to create one.',
            ],
            'pending' => [
                'html' => admin_render_guide_rows($split['pending'], [], true),
                'empty' => 'No pending applications.',
            ],
            'rejected' => [
                'html' => admin_render_guide_rows($split['rejected'], [], false),
                'empty' => 'No rejected applications.',
            ],
            'suspended' => [
                'html' => admin_render_guide_rows($split['suspended'], [], false),
                'empty' => 'No suspended tour guides.',
            ],
            'ih_registered' => [
                'html' => admin_render_guide_rows($inhouseSplit['registered'], [], false, true),
                'empty' => 'No active in-house guides.',
            ],
            'ih_pending' => [
                'html' => admin_render_guide_rows($inhouseSplit['pending'], [], true, true),
                'empty' => 'No pending in-house guides.',
            ],
            'ih_rejected' => [
                'html' => admin_render_guide_rows($inhouseSplit['rejected'], [], false, true),
                'empty' => 'No rejected in-house guides.',
            ],
            'ih_suspended' => [
                'html' => admin_render_guide_rows($inhouseSplit['suspended'], [], false, true),
                'empty' => 'No suspended in-house guides.',
            ],
        ];
        $version = admin_guides_version($rows);
    } else {
        $rows = admin_businesses_fetch_rows();
        $split = admin_registrations_split($rows);
        $panels = [
            'registered' => [
                'html' => admin_render_business_rows($split['registered'], [], false),
                'empty' => 'No active businesses yet. Click Add business to create one.',
            ],
            'pending' => [
                'html' => admin_render_business_rows($split['pending'], [], true),
                'empty' => 'No pending applications.',
            ],
            'rejected' => [
                'html' => admin_render_business_rows($split['rejected'], [], false),
                'empty' => 'No rejected applications.',
            ],
            'inactive' => [
                'html' => admin_render_business_rows($split['inactive'] ?? [], [], false),
                'empty' => 'No inactive businesses.',
            ],
        ];
        $version = admin_businesses_version($rows);
    }

    $counts = [
        'registered' => count($split['registered']),
        'pending' => count($split['pending']),
        'rejected' => count($split['rejected']),
        'suspended' => count($split['suspended'] ?? []),
        'inactive' => count($split['inactive'] ?? []),
    ];
    if ($kind === 'guides' && isset($inhouseSplit)) {
        $counts['ih_registered'] = count($inhouseSplit['registered']);
        $counts['ih_pending'] = count($inhouseSplit['pending']);
        $counts['ih_rejected'] = count($inhouseSplit['rejected']);
        $counts['ih_suspended'] = count($inhouseSplit['suspended']);
    }

    return [
        'ok' => true,
        'kind' => $kind,
        'version' => $version,
        'counts' => $counts,
        'pending' => $counts['pending'],
        'panels' => $panels,
    ];
}
