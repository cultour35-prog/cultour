<?php
/**
 * Announcement helpers — schema migrate + fetch for dashboards
 */

function ensure_announcements_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = db()->query('SHOW COLUMNS FROM announcements')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('kind', $cols, true)) {
            db()->exec("ALTER TABLE announcements ADD COLUMN kind ENUM('header','card') NOT NULL DEFAULT 'card' AFTER body");
        }
        if (!in_array('banner_image', $cols, true)) {
            db()->exec('ALTER TABLE announcements ADD COLUMN banner_image VARCHAR(255) NULL AFTER kind');
        }
        if (!in_array('send_notify', $cols, true)) {
            db()->exec('ALTER TABLE announcements ADD COLUMN send_notify TINYINT(1) NOT NULL DEFAULT 0 AFTER is_published');
        }
        if (!in_array('is_pinned', $cols, true)) {
            db()->exec('ALTER TABLE announcements ADD COLUMN is_pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER send_notify');
        }
        if (!in_array('pinned_at', $cols, true)) {
            db()->exec('ALTER TABLE announcements ADD COLUMN pinned_at DATETIME NULL AFTER is_pinned');
        }
        if (!in_array('target_user_id', $cols, true)) {
            db()->exec('ALTER TABLE announcements ADD COLUMN target_user_id INT UNSIGNED NULL AFTER audience');
        }
        $audience = db()->query("SHOW COLUMNS FROM announcements LIKE 'audience'")->fetch(PDO::FETCH_ASSOC) ?: [];
        $audienceType = (string) ($audience['Type'] ?? '');
        $missing = array_filter(
            ['all', 'tourist', 'tour_guide', 'business', 'user'],
            static fn (string $v): bool => !str_contains($audienceType, "'" . $v . "'")
        );
        if ($missing) {
            db()->exec(
                "ALTER TABLE announcements
                 MODIFY audience ENUM('all','tourist','tour_guide','business','user') NOT NULL DEFAULT 'all'"
            );
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Audience keys matching announcements.audience for a role.
 */
function announcement_audiences_for_role(int $roleId): array
{
    return match ($roleId) {
        ROLE_TOURIST => ['all', 'tourist'],
        ROLE_TOUR_GUIDE => ['all', 'tour_guide'],
        ROLE_BUSINESS => ['all', 'business'],
        default => ['all'],
    };
}

/**
 * Published announcements for a role: header banners (carousel) + text cards.
 * @return array{headers: array, header: ?array, cards: array}
 */
function announcements_for_role(int $roleId, int $userId = 0): array
{
    ensure_announcements_schema();
    $aud = announcement_audiences_for_role($roleId);
    $placeholders = implode(',', array_fill(0, count($aud), '?'));
    $mine = $userId > 0 ? ' OR target_user_id = ?' : '';
    $params = $aud;
    if ($userId > 0) {
        $params[] = $userId;
    }
    $match = "((audience IN ($placeholders) AND target_user_id IS NULL)$mine)";

    $headers = db()->prepare(
        "SELECT * FROM announcements
         WHERE is_published=1 AND kind='header' AND $match
           AND banner_image IS NOT NULL AND banner_image != ''
         ORDER BY COALESCE(published_at, created_at) DESC LIMIT 8"
    );
    $headers->execute($params);
    $headerRows = $headers->fetchAll();

    $cards = db()->prepare(
        "SELECT * FROM announcements
         WHERE is_published=1 AND kind='card' AND $match
         ORDER BY is_pinned DESC, pinned_at DESC, COALESCE(published_at, created_at) DESC LIMIT 8"
    );
    $cards->execute($params);

    return [
        'headers' => $headerRows,
        'header' => $headerRows[0] ?? null,
        'cards' => $cards->fetchAll(),
    ];
}

/**
 * Notify audience users about an announcement.
 * Kept for compatibility; posts no longer call this by default.
 */
function notify_announcement_audience(string $audience, string $title, string $body, int $targetUserId = 0): void
{
    $roleMap = [
        'tourist' => ROLE_TOURIST,
        'tour_guide' => ROLE_TOUR_GUIDE,
        'business' => ROLE_BUSINESS,
    ];
    if ($audience === 'user') {
        if ($targetUserId < 1) {
            return;
        }
        $st = db()->prepare("SELECT id, role_id FROM users WHERE id=? AND status='active' AND role_id != ?");
        $st->execute([$targetUserId, ROLE_ADMIN]);
        $users = $st->fetchAll();
    } elseif ($audience === 'all') {
        $st = db()->prepare("SELECT id, role_id FROM users WHERE status='active' AND role_id != ?");
        $st->execute([ROLE_ADMIN]);
        $users = $st->fetchAll();
    } else {
        $rid = $roleMap[$audience] ?? 0;
        $st = db()->prepare("SELECT id, role_id FROM users WHERE role_id=? AND status='active'");
        $st->execute([$rid]);
        $users = $st->fetchAll();
    }

    $dashLinks = [
        ROLE_TOURIST => APP_URL . '/tourist/dashboard.php',
        ROLE_TOUR_GUIDE => APP_URL . '/tourguide/dashboard.php',
        ROLE_BUSINESS => APP_URL . '/business/dashboard.php',
    ];
    foreach ($users as $u) {
        $roleId = (int) $u['role_id'];
        $link = $dashLinks[$roleId] ?? APP_URL . '/notifications.php';
        notify_user((int) $u['id'], 'announcement', $title, $body, $link);
    }
}

/**
 * Announcements on the left, banner on the right (no framed background card on the banner).
 * @param 'all'|'banners'|'cards' $parts
 * @param array<int, array{0:string,1:int|string,2:string,3:string}> $miniStats label, value, icon, href
 */
function render_announcements_board(array $annBundle, string $parts = 'all', array $miniStats = []): void
{
    $headers = $annBundle['headers'] ?? [];
    if (!$headers && !empty($annBundle['header'])) {
        $headers = [$annBundle['header']];
    }
    $headers = array_values(array_filter(
        $headers,
        static fn ($h) => !empty($h['banner_image'])
    ));
    $cards = $annBundle['cards'] ?? [];
    $showBanners = ($parts === 'all' || $parts === 'banners') && $headers;
    $showCards = ($parts === 'all' || $parts === 'cards') && $cards;
    $showStats = $miniStats !== [];
    $showSide = $showCards || $showStats;
    if (!$showBanners && !$showSide) {
        return;
    }
    $carouselId = 'annCarousel_' . bin2hex(random_bytes(4));
    $split = $showBanners && $showSide;
    ?>
    <section class="ann-board mb-4 fade-up<?= $split ? ' ann-board--split' : ($showBanners ? ' ann-board--banner' : ' ann-board--cards') ?>">
      <?php if ($showSide): ?>
        <div class="ann-board__side">
          <div class="ann-board__side-fill">
          <h3 class="panel-title mb-0">Announcements</h3>
          <?php if ($showCards): ?>
            <div class="ann-side-list ct-scroll">
              <?php foreach ($cards as $a): ?>
                <article class="ann-side-item">
                  <div class="ann-side-item__date"><?= e(format_date($a['published_at'] ?? $a['created_at'], 'M j, Y g:i A')) ?></div>
                  <h4 class="ann-side-item__title">
                    <?php if (!empty($a['is_pinned'])): ?><i class="bi bi-pin-angle-fill ann-side-item__pin" aria-hidden="true"></i><?php endif; ?>
                    <?= e($a['title']) ?>
                  </h4>
                  <p class="ann-side-item__body mb-0"><?= nl2br(e($a['body'])) ?></p>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="text-muted small mb-0 ann-side-empty">No announcements yet.</p>
          <?php endif; ?>
          <?php if ($showStats): ?>
            <div class="ann-mini-stats">
              <?php foreach ($miniStats as $stat):
                  [$label, $value, $icon, $href] = $stat;
                  $statKey = $stat[4] ?? strtolower((string) $label);
              ?>
                <a class="ann-mini-stat" href="<?= e($href) ?>">
                  <span class="ann-mini-stat__icon"><i class="bi <?= e($icon) ?>"></i></span>
                  <span class="ann-mini-stat__meta">
                    <span class="ann-mini-stat__label"><?= e($label) ?></span>
                    <span class="ann-mini-stat__value" data-stat="<?= e($statKey) ?>"><?= (int) $value ?></span>
                  </span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($showBanners): ?>
        <div class="ann-board__banner">
          <div class="ann-carousel" id="<?= e($carouselId) ?>" data-ann-carousel>
            <div class="ann-carousel__viewport">
              <?php foreach ($headers as $i => $h): ?>
                <div class="ann-carousel__slide<?= $i === 0 ? ' is-active' : '' ?>">
                  <img
                    class="ann-carousel__img"
                    src="<?= APP_URL ?>/uploads/<?= e($h['banner_image']) ?>"
                    alt="Announcement banner"
                    loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
                </div>
              <?php endforeach; ?>
            </div>
            <?php if (count($headers) > 1): ?>
              <div class="ann-carousel__controls">
                <button type="button" class="ann-carousel__pause" data-ann-pause aria-label="Pause slideshow" title="Pause">
                  <i class="bi bi-pause-fill"></i>
                </button>
              </div>
              <div class="ann-carousel__dots" data-ann-dots>
                <?php foreach ($headers as $i => $_): ?>
                  <button type="button"
                          class="ann-carousel__dot<?= $i === 0 ? ' is-active' : '' ?>"
                          data-ann-dot="<?= (int) $i ?>"
                          aria-label="Go to slide <?= (int) ($i + 1) ?>"></button>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

function admin_announcements_rows(): array
{
    ensure_announcements_schema();
    return db()->query(
        "SELECT a.*, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS target_name, u.email AS target_email
         FROM announcements a
         LEFT JOIN users u ON u.id = a.target_user_id
         ORDER BY a.is_pinned DESC, a.pinned_at DESC, a.created_at DESC"
    )->fetchAll() ?: [];
}

function admin_announcements_version(array $rows): string
{
    $parts = [];
    foreach ($rows as $r) {
        $parts[] = (int) ($r['id'] ?? 0)
            . ':' . ($r['kind'] ?? '')
            . ':' . ($r['title'] ?? '')
            . ':' . ($r['body'] ?? '')
            . ':' . ($r['banner_image'] ?? '')
            . ':' . ($r['audience'] ?? '')
            . ':' . ($r['target_user_id'] ?? '')
            . ':' . ($r['is_published'] ?? '')
            . ':' . ($r['send_notify'] ?? '')
            . ':' . ($r['is_pinned'] ?? '')
            . ':' . ($r['pinned_at'] ?? '')
            . ':' . ($r['created_at'] ?? '');
    }
    return md5(implode('|', $parts) . '|n=' . count($parts));
}

function admin_announcements_list_html(array $rows): string
{
    ob_start();
    if (!$rows) {
        echo '<p class="text-muted mb-0">No posts yet.</p>';
        return (string) ob_get_clean();
    }
    foreach ($rows as $r) {
        ?>
        <div class="ann-admin-row">
          <div class="d-flex justify-content-between gap-2 flex-wrap">
            <div>
              <span class="badge <?= ($r['kind'] ?? 'card') === 'header' ? 'text-bg-warning' : 'text-bg-secondary' ?>">
                <?= ($r['kind'] ?? 'card') === 'header' ? 'Banner' : 'Announcement' ?>
              </span>
              <?php if (!empty($r['is_published'])): ?>
                <span class="badge text-bg-success">On board</span>
              <?php else: ?>
                <span class="badge text-bg-dark">Hidden</span>
              <?php endif; ?>
              <?php if (!empty($r['is_pinned'])): ?>
                <span class="badge text-bg-warning"><i class="bi bi-pin-angle-fill"></i> Pinned</span>
              <?php endif; ?>
              <?php if (!empty($r['send_notify'])): ?>
                <span class="badge text-bg-info">Notified</span>
              <?php endif; ?>
              <span class="small text-muted ms-1">Audience: <?= e(announcement_audience_label($r)) ?></span>
              <?php if (($r['kind'] ?? '') === 'card'): ?>
                <strong class="ms-1"><?= e($r['title']) ?></strong>
              <?php endif; ?>
            </div>
            <span class="small text-muted"><?= e(format_date($r['created_at'], 'M j, Y g:i A')) ?></span>
          </div>
          <?php if (!empty($r['banner_image'])): ?>
            <img src="<?= APP_URL ?>/uploads/<?= e($r['banner_image']) ?>" alt="" class="ann-admin-thumb mb-2 mt-2">
          <?php elseif (trim((string) ($r['body'] ?? '')) !== ''): ?>
            <p class="mb-2 mt-1"><?= nl2br(e($r['body'])) ?></p>
          <?php endif; ?>
          <?php if (($r['kind'] ?? '') === 'card'): ?>
          <form method="post" class="d-inline"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button name="action" value="pin" class="btn btn-sm <?= !empty($r['is_pinned']) ? 'btn-warning' : 'btn-outline-warning' ?>">
              <i class="bi bi-pin-angle<?= !empty($r['is_pinned']) ? '-fill' : '' ?>"></i>
              <?= !empty($r['is_pinned']) ? 'Unpin' : 'Pin to top' ?>
            </button>
          </form>
          <?php endif; ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Delete this post?')"><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button name="action" value="delete" class="btn btn-sm btn-outline-danger">Delete</button>
          </form>
        </div>
        <?php
    }
    return (string) ob_get_clean();
}

function announcement_audience_label(array $row): string
{
    if (($row['audience'] ?? '') === 'user') {
        $name = trim((string) ($row['target_name'] ?? ''));
        return $name !== '' ? $name : 'One user';
    }
    return match ((string) ($row['audience'] ?? '')) {
        'all' => 'All users',
        'tourist' => 'Tourists',
        'tour_guide' => 'Tour guides',
        'business' => 'Businesses',
        default => (string) ($row['audience'] ?? ''),
    };
}

function announcement_board_version(array $bundle): string
{
    $parts = [];
    foreach (array_merge($bundle['headers'] ?? [], $bundle['cards'] ?? []) as $r) {
        $parts[] = (int) ($r['id'] ?? 0)
            . ':' . ($r['kind'] ?? '')
            . ':' . ($r['title'] ?? '')
            . ':' . ($r['body'] ?? '')
            . ':' . ($r['banner_image'] ?? '')
            . ':' . ($r['is_pinned'] ?? '')
            . ':' . ($r['pinned_at'] ?? '')
            . ':' . ($r['published_at'] ?? '')
            . ':' . ($r['created_at'] ?? '');
    }
    return md5(implode('|', $parts) . '|n=' . count($parts));
}
