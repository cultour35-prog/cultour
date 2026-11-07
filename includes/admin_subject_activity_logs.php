<?php
/**
 * Activity log popup for an admin profile.
 * Expects: $activityLogs (list of activity_logs rows).
 */
$activityLogs = is_array($activityLogs ?? null) ? $activityLogs : [];
?>
<div class="modal fade tour-end-modal" id="activityLogModal" tabindex="-1" aria-labelledby="activityLogModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered activity-log-modal">
    <div class="modal-content tour-end-card">
      <div class="modal-header border-0 pb-1">
        <h2 class="h5 mb-0" id="activityLogModalLabel">Activity logs</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body pt-2 activity-log-modal__body">
        <div class="table-responsive table-sticky-scroll">
          <table class="table table-ct align-middle mb-0">
            <thead>
              <tr>
                <th>When</th>
                <th>Action</th>
                <th>Module</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($activityLogs as $log): ?>
                <tr>
                  <td class="text-nowrap"><?= e(format_date($log['created_at'] ?? '', 'M j, Y g:i A')) ?></td>
                  <td><?= e((string) ($log['action'] ?? '')) ?></td>
                  <td><?= e((string) ($log['module'] ?? '') !== '' ? (string) $log['module'] : '—') ?></td>
                  <td><?php
                    $logDetails = format_activity_log_details($log);
                    echo e($logDetails !== '' ? $logDetails : '—');
                  ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$activityLogs): ?>
                <tr><td colspan="4" class="text-muted">No activity recorded for this account yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('activityLogModal');
  if (modal && modal.parentElement !== document.body) document.body.appendChild(modal);
})();
</script>
