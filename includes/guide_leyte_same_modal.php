<?php
$guideLeyteSameTitle = $guideLeyteSameTitle ?? 'Ormoc City residency required';
$guideLeyteSameBody = $guideLeyteSameBody
    ?? 'Only tour guides who currently live in Ormoc City can register on CulTour. Uncheck “Same as permanent address” and enter an Ormoc City current address.';
?>
<div class="login-role-backdrop" id="guideLeyteSameModal" hidden>
  <div class="login-role-card glass" role="dialog" aria-modal="true" aria-labelledby="guideLeyteSameTitle">
    <h3 class="h5 mb-2" id="guideLeyteSameTitle"><?= e($guideLeyteSameTitle) ?></h3>
    <p class="text-muted small mb-3 mb-md-4" id="guideLeyteSameBody"><?= e($guideLeyteSameBody) ?></p>
    <button type="button" class="btn btn-ct w-100" id="guideLeyteSameOk">Got it</button>
  </div>
</div>
