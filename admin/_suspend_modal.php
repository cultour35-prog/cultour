<?php
/** @var string $suspendFormAction */
$suspendFormAction = (string) ($suspendFormAction ?? '');
if ($suspendFormAction === '') {
    return;
}
?>
<div class="modal fade" id="suspendModal" tabindex="-1" aria-labelledby="suspendModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content" action="<?= e($suspendFormAction) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="suspend">
      <input type="hidden" name="user_id" id="suspendUserId" value="">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="suspendModalLabel">Suspend account</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Suspending <strong id="suspendUserName"></strong>. They can still log in to see this reason and submit an appeal.</p>
        <label class="form-label" for="suspendReason">Reason <span class="text-danger">*</span></label>
        <textarea class="form-control" id="suspendReason" name="suspend_reason" rows="4" required maxlength="2000" placeholder="Explain why this account is being suspended…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-warning">Suspend account</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('suspendModal')?.addEventListener('show.bs.modal', function (event) {
  const btn = event.relatedTarget;
  if (!btn) return;
  document.getElementById('suspendUserId').value = btn.getAttribute('data-user-id') || '';
  document.getElementById('suspendUserName').textContent = btn.getAttribute('data-user-name') || 'this user';
  document.getElementById('suspendReason').value = '';
});
</script>
