<?php
/** @var list<array> $rows */
if (!$rows): ?>
        <div class="glass p-4 text-muted">No bookings yet. <a href="<?= APP_URL ?>/tourist/itineraries.php?book=1">Book a guide from an itinerary</a></div>
<?php else: ?>
        <div class="row g-3">
          <?php foreach ($rows as $r): ?>
            <?php
              $status = (string) $r['status'];
              $ticketReady = in_array($status, ['approved', 'completed'], true);
              $payOpen = in_array($status, ['pending', 'approved'], true) && ($r['payment_status'] ?? '') !== 'paid';
              $alreadyRated = (int) ($r['rated'] ?? 0) > 0;
            ?>
            <div class="col-12">
              <div class="glass p-3">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                  <div>
                    <div class="fw-semibold"><code><?= e($r['booking_code']) ?></code><?php if (trim((string) ($r['guide_name'] ?? '')) !== ''): ?> · Tour guide · <?= e($r['guide_name']) ?><?php endif; ?></div>
                    <div class="small text-muted">
                      <?= e(format_date($r['booking_date'])) ?> · <?= e(format_time_12h($r['start_time'] ?? null)) ?>
                      · <?= (int) ($r['party_size'] ?? 1) ?> guest<?= (int) ($r['party_size'] ?? 1) === 1 ? '' : 's' ?>
                    </div>
                    <?= booking_accessibility_html($r) ?>
                    <?php if (!empty($r['original_guide_id']) && (int) $r['original_guide_id'] !== (int) $r['guide_id'] && !empty($r['original_guide_name'])): ?>
                      <div class="small text-muted">Originally with <?= e($r['original_guide_name']) ?></div>
                    <?php endif; ?>
                  </div>
                  <div class="text-end">
                    <?= status_badge($status) ?>
                    <?php if (booking_needs_replacement($r)): ?>
                      <div class="mt-1"><span class="badge bg-warning text-dark">Needs replacement</span></div>
                    <?php endif; ?>
                    <?php if ($status === 'pending'): ?>
                      <div class="small text-muted mt-1">Waiting for guide</div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="row g-2 small mb-2">
                  <div class="col-md-6">
                    <span class="text-muted">Itinerary</span>
                    <div>
                      <?php if (!empty($r['itinerary_id']) && !empty($r['itinerary_title'])): ?>
                        <?= e($r['itinerary_title']) ?>
                        <div class="mt-1">
                          <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/itinerary-share.php?id=<?= (int) $r['itinerary_id'] ?>" target="_blank" rel="noopener">View itinerary</a>
                        </div>
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <span class="text-muted">Payment</span>
                    <div>
                      <?= e(ucwords(str_replace('_', ' ', (string) ($r['payment_status'] ?? 'unpaid')))) ?>
                      <?php if (!empty($r['payment_reference'])): ?>
                        <span class="text-muted">· Ref <?= e($r['payment_reference']) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php if ($status === 'rejected' && !empty($r['rejection_reason'])): ?>
                    <div class="col-12 text-danger">Declined: <?= e($r['rejection_reason']) ?></div>
                  <?php endif; ?>
                  <?php if ($status === 'cancelled' && !empty($r['rejection_reason'])): ?>
                    <div class="col-12 text-danger">Cancelled: <?= e($r['rejection_reason']) ?></div>
                  <?php endif; ?>
                  <?= refund_tourist_status_html($r) ?>
                </div>

                <?php if (booking_needs_replacement($r) && $status === 'approved'): ?>
                  <div class="mt-2 mb-2">
                    <?= booking_replacement_tourist_status_html($r) ?>
                  </div>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-2 align-items-start">
                  <?php if (!empty($r['itinerary_id']) || in_array($status, ['approved', 'completed'], true)): ?>
                    <?= booking_history_details_html($r) ?>
                  <?php endif; ?>
                  <?php if ($ticketReady): ?>
                    <a class="btn btn-sm btn-ct booking-pair-btn" href="<?= APP_URL ?>/booking-ticket.php?id=<?= (int) $r['id'] ?>" target="_blank">
                      <i class="bi bi-qr-code"></i> Ticket / QR
                    </a>
                  <?php elseif ($status === 'pending'): ?>
                    <span class="btn btn-sm btn-outline-secondary booking-pair-btn booking-ticket-wait" aria-disabled="true">Ticket after approval</span>
                  <?php endif; ?>

                  <?php if (booking_tourist_can_cancel($r)): ?>
                    <?php if (in_array($status, ['pending', 'approved'], true) && ($r['payment_status'] ?? '') === 'paid'): ?>
                      <button type="button" class="btn btn-sm btn-outline-danger booking-pair-btn" data-bs-toggle="modal" data-bs-target="#touristRefundModal" data-refund-booking="<?= (int) $r['id'] ?>"><?= booking_needs_replacement($r) ? 'Refund remaining days' : 'Cancel and refund' ?></button>
                    <?php else: ?>
                    <form method="post" class="d-inline-flex m-0" onsubmit="return confirm(<?= $status === 'pending'
                        ? json_encode('Cancel this booking request?')
                        : json_encode('Cancel the remaining days of this tour? Finished days stay as they are.')
                    ?>);"><?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <button name="action" value="cancel" class="btn btn-sm btn-outline-danger booking-pair-btn"><?= $status === 'pending' ? 'Cancel request' : 'Cancel remaining days' ?></button>
                    </form>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php if ($status === 'completed'): ?>
                    <?php if ($alreadyRated): ?>
                      <span class="ct-rate__done">
                        <i class="bi bi-check-circle-fill"></i> You already rated this tour
                      </span>
                    <?php else: ?>
                      <form method="post" class="ct-rate"><?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <div class="ct-rate__top">
                          <span class="ct-rate__label">How was your guide?</span>
                          <div class="ct-stars" role="radiogroup" aria-label="Rating">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                              <?php $starId = 'rate-' . (int) $r['id'] . '-' . $i; ?>
                              <input class="ct-stars__input" type="radio" name="rating" id="<?= $starId ?>"
                                     value="<?= $i ?>"<?= $i === 5 ? ' checked' : '' ?>>
                              <label class="ct-stars__star" for="<?= $starId ?>"
                                     title="<?= $i ?> star<?= $i === 1 ? '' : 's' ?>">
                                <i class="bi bi-star-fill"></i>
                                <span class="visually-hidden"><?= $i ?> star<?= $i === 1 ? '' : 's' ?></span>
                              </label>
                            <?php endfor; ?>
                          </div>
                        </div>
                        <div class="ct-rate__row">
                          <input name="comment" class="form-control form-control-sm ct-rate__comment"
                                 placeholder="Add a comment (optional)">
                          <button name="action" value="rate" class="btn btn-ct ct-rate__submit">Rate guide</button>
                        </div>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>

                <?php if ($payOpen): ?>
                  <div class="mt-3 border-top pt-3">
                    <div class="small text-muted mb-2">Payment</div>
                    <div class="d-flex flex-wrap gap-1 mb-2">
                      <a class="btn btn-sm btn-ct" href="<?= APP_URL ?>/tourist/pay.php?booking_id=<?= (int) $r['id'] ?>">
                        <i class="bi bi-wallet2"></i> Pay with GCash
                      </a>
                    </div>
                    <form method="post"><?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                      <input type="hidden" name="action" value="payment_ref">
                      <div class="d-flex flex-wrap gap-1">
                        <input class="form-control form-control-sm" style="max-width:220px" name="payment_reference" maxlength="120" placeholder="Or save GCash / Maya reference" value="<?= e($r['payment_reference'] ?? '') ?>">
                        <button class="btn btn-sm btn-outline-secondary" name="payment_mode" value="ref">Save reference</button>
                        <button class="btn btn-sm btn-outline-secondary" name="payment_mode" value="pay_at_office">Pay at Tourism Office</button>
                      </div>
                    </form>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
<?php endif; ?>
