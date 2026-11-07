<?php
/** @var list<array> $rows */
/** @var int $pendingPayCount */
/** @var string $paymentFilter */
/** @var string $sort */
/** @var string $sortDir */
$sort = $sort ?? 'date';
$sortDir = $sortDir ?? 'desc';
$bookingListQuery = static function (array $extra) use ($sort, $sortDir, $paymentFilter): string {
    $q = ['sort' => $sort, 'dir' => $sortDir];
    if ($paymentFilter === 'pending') {
        $q['payment'] = 'pending';
    }
    $q = array_merge($q, $extra);
    return APP_URL . '/admin/bookings.php?' . http_build_query($q);
};
$sortHead = static function (string $key, string $label) use ($sort, $sortDir, $paymentFilter): string {
    $active = $sort === $key;
    $icon = $active
        ? ($sortDir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill')
        : 'bi-caret-down-fill';
    $class = 'bk-sort' . ($active ? ' is-active' : '');
    $href = admin_bookings_sort_href($key, $sort, $sortDir, $paymentFilter);
    return '<a class="' . $class . '" href="' . e($href) . '">' . e($label)
        . ' <i class="bi ' . $icon . ($active ? '' : ' bk-sort__idle') . '" aria-hidden="true"></i></a>';
};
?>
      <?php if ($pendingPayCount > 0): ?>
        <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
          <span><?= (int) $pendingPayCount ?> GCash reference<?= $pendingPayCount === 1 ? '' : 's' ?> waiting for Tourism Office confirmation. Demo checkout is not automatic.</span>
          <?php if ($paymentFilter === 'pending'): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e($bookingListQuery(['payment' => null])) ?>">Show all bookings</a>
          <?php else: ?>
            <a class="btn btn-sm btn-ct" href="<?= e($bookingListQuery(['payment' => 'pending'])) ?>">Review queue</a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="glass p-3">
        <div class="table-responsive table-sticky-scroll">
          <table class="table table-ct align-middle mb-0">
            <thead><tr>
              <th><?= $sortHead('code', 'Code') ?></th>
              <th><?= $sortHead('tourist', 'Tourist') ?></th>
              <th><?= $sortHead('guide', 'Guide') ?></th>
              <th><?= $sortHead('date', 'Date') ?></th>
              <th><?= $sortHead('itinerary', 'Itinerary') ?></th>
              <th><?= $sortHead('status', 'Status') ?></th>
              <th><?= $sortHead('payment', 'Payment') ?></th>
              <th></th>
            </tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><code><?= e($r['booking_code']) ?></code></td>
                  <td>
                    <?= e($r['tourist_name']) ?>
                    <?= booking_accessibility_html($r) ?>
                  </td>
                  <td>
                    <?= e($r['guide_name']) ?>
                    <?php if (!empty($r['original_guide_id']) && (int) $r['original_guide_id'] !== (int) $r['guide_id'] && !empty($r['original_guide_name'])): ?>
                      <div class="small text-muted">Was <?= e($r['original_guide_name']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td><?= e(format_date($r['booking_date'])) ?> <?= e(format_time_12h($r['start_time'] ?? null)) ?></td>
                  <td>
                    <?php if (!empty($r['itinerary_id']) && !empty($r['itinerary_title'])): ?>
                      <a href="<?= APP_URL ?>/itinerary-share.php?id=<?= (int) $r['itinerary_id'] ?>" target="_blank" rel="noopener"><?= e($r['itinerary_title']) ?></a>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                  <td>
                    <?= status_badge($r['status']) ?>
                    <?php if (booking_needs_replacement($r)): ?>
                      <div class="mt-1"><span class="badge bg-warning text-dark">Needs replacement</span></div>
                    <?php endif; ?>
                    <?php if ($r['status'] === 'rejected' && !empty($r['rejection_reason'])): ?>
                      <div class="small text-muted"><?= e($r['rejection_reason']) ?></div>
                    <?php endif; ?>
                    <?php if ($r['status'] === 'cancelled' && !empty($r['rejection_reason'])): ?>
                      <div class="small text-muted"><?= e($r['rejection_reason']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="small">
                    <?= e(ucwords(str_replace('_', ' ', (string) ($r['payment_status'] ?? 'unpaid')))) ?>
                    <?php if (!empty($r['payment_reference'])): ?>
                      <div class="text-muted">Ref <?= e($r['payment_reference']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-primary" data-booking-detail="<?= (int) $r['id'] ?>" data-booking-code="<?= e($r['booking_code']) ?>">View details</button>
                    <?php if ($r['status'] === 'pending'): ?>
                      <form method="post" class="d-inline"><?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button name="action" value="approve" class="btn btn-sm btn-success">Approve</button>
                        <button name="action" value="cancel" class="btn btn-sm btn-outline-danger">Cancel</button>
                      </form>
                    <?php endif; ?>
                    <?php if (in_array($r['status'], ['approved', 'completed'], true)): ?>
                      <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/booking-ticket.php?id=<?= (int)$r['id'] ?>" target="_blank">Ticket</a>
                    <?php endif; ?>
                    <?php if (($r['payment_status'] ?? 'unpaid') === 'pending_review'): ?>
                      <form method="post" class="d-inline-flex gap-1 mt-1"><?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input class="form-control form-control-sm" style="width:120px" name="payment_reference" maxlength="120" value="<?= e($r['payment_reference'] ?? '') ?>">
                        <button name="action" value="confirm_gcash" class="btn btn-sm btn-success">Confirm GCash</button>
                        <button name="action" value="pay_at_office" class="btn btn-sm btn-outline-secondary">Office</button>
                      </form>
                    <?php elseif (($r['payment_status'] ?? 'unpaid') !== 'paid' && in_array($r['status'], ['pending', 'approved'], true)): ?>
                      <form method="post" class="d-inline-flex gap-1 mt-1"><?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input class="form-control form-control-sm" style="width:100px" name="payment_reference" maxlength="120" placeholder="Ref" value="<?= e($r['payment_reference'] ?? '') ?>">
                        <button name="action" value="mark_paid" class="btn btn-sm btn-outline-success">Paid</button>
                        <button name="action" value="pay_at_office" class="btn btn-sm btn-outline-secondary">Office</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php if (booking_needs_replacement($r) && ($r['status'] ?? '') === 'approved'): ?>
                <tr>
                  <td colspan="8" class="bg-light">
                    <?= booking_replacement_picker_html(
                        $r,
                        '<input type="hidden" name="id" value="' . (int) $r['id'] . '">',
                        'admin'
                    ) ?>
                  </td>
                </tr>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if (!$rows): ?><tr><td colspan="8" class="text-muted">No bookings yet.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
