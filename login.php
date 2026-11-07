<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/suspension.php';
ensure_password_schema();
start_app_session();
try_remember_login();
if ($u = current_user()) {
    if ($setup = account_setup_url($u)) {
        redirect($setup);
    }
    redirect(role_dashboard((int) $u['role_id']));
}

$error = null;
$next = trim((string) ($_GET['next'] ?? $_POST['next'] ?? ''));
// Only allow relative in-app paths
if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '://')) {
    $next = '';
}

$emailPosted = (string) ($_POST['email'] ?? '');
$wantsJson = (
    str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
    || strcasecmp((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0
);

function login_redirect_for(array $logged, string $next): string
{
    if ($setup = account_setup_url($logged)) {
        return $setup;
    }
    if (($logged['status'] ?? '') === 'suspended') {
        return role_dashboard((int) $logged['role_id']);
    }
    if ($next !== '' && (int) $logged['role_id'] === ROLE_TOURIST) {
        return APP_URL . $next;
    }
    return role_dashboard((int) $logged['role_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = csrf_error_message();
        if ($wantsJson) {
            json_response(['ok' => false, 'message' => $error], 400);
        }
    } else {
        $rolePosted = (int) ($_POST['role_id'] ?? 0);
        $result = attempt_login(
            $_POST['email'] ?? '',
            $_POST['password'] ?? '',
            isset($_POST['remember']),
            $rolePosted > 0 ? $rolePosted : null,
            'public'
        );
        if ($result['ok']) {
            $logged = $result['user'];
            $dest = login_redirect_for($logged, $next);
            if ($wantsJson) {
                json_response(['ok' => true, 'redirect' => $dest]);
            }
            redirect($dest);
        }
        $error = $result['message'] ?? 'Invalid email or password.';
        if ($wantsJson) {
            if (!empty($result['need_role']) && !empty($result['roles']) && is_array($result['roles'])) {
                json_response([
                    'ok' => false,
                    'need_role' => true,
                    'message' => $error,
                    'roles' => $result['roles'],
                ]);
            }
            json_response(['ok' => false, 'message' => $error, 'locked' => !empty($result['locked'])], !empty($result['locked']) ? 429 : 401);
        }
    }
}

$pageTitle = 'Login';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-shell auth-shell--back">
  <a class="auth-back" href="<?= APP_URL ?>/">
    <i class="bi bi-arrow-left" aria-hidden="true"></i>
    <span>Back</span>
  </a>
  <div class="auth-hero d-none d-lg-flex">
    <div>
      <div class="small text-uppercase opacity-75 mb-2">Ormoc City Tourism Office</div>
      <h1>CulTour</h1>
      <p class="mb-0 opacity-90" style="max-width:28rem">Itinerary planning, guide booking, and local business discovery for Ormoc.</p>
    </div>
  </div>
  <div class="auth-panel">
    <div class="glass p-4 p-md-5 w-100 fade-up" style="max-width:440px">
      <h2 class="h3 mb-1">Welcome back</h2>
      <p class="text-muted mb-4">Sign in to your CulTour account.</p>

      <?php if ($msg = flash('success')): ?>
        <div class="alert alert-success" data-autohide="1"><?= e($msg) ?></div>
      <?php endif; ?>
      <?php if ($msg = flash('error')): ?>
        <div class="alert alert-danger"><?= e($msg) ?></div>
      <?php endif; ?>
      <div class="alert alert-danger<?= $error ? '' : ' d-none' ?>" id="loginError" role="alert"><?= $error ? e($error) : '' ?></div>

      <form method="post" novalidate data-live-validate id="loginForm">
        <?= csrf_field() ?>
        <?php if ($next !== ''): ?>
          <input type="hidden" name="next" value="<?= e($next) ?>">
        <?php endif; ?>
        <input type="hidden" name="role_id" id="loginRoleId" value="">
        <div class="mb-3">
          <label class="form-label" for="loginEmail">Email</label>
          <input type="email" name="email" id="loginEmail" class="form-control" required maxlength="190" value="<?= e($emailPosted) ?>" autocomplete="username">
        </div>
        <div class="mb-3">
          <label class="form-label" for="loginPassword">Password</label>
          <input type="password" name="password" id="loginPassword" class="form-control" required autocomplete="current-password" aria-describedby="loginCapsHint">
          <div class="form-text text-warning d-none" id="loginCapsHint" aria-live="polite">Caps Lock is on.</div>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="remember" id="remember">
            <label class="form-check-label" for="remember">Remember me</label>
          </div>
          <a href="<?= APP_URL ?>/forgot-password.php" class="small">Forgot password?</a>
        </div>
        <button class="btn btn-ct w-100 mb-3" type="submit" id="loginSubmit">Login</button>
      </form>
      <button type="button" class="btn btn-outline-secondary w-100 mb-3" id="loginLinkAccountBtn">
        <i class="bi bi-link-45deg" aria-hidden="true"></i>
        Link account
      </button>
      <p class="mb-0 small text-center">No account? <a href="<?= APP_URL ?>/register.php">Register</a></p>
    </div>
  </div>
</div>

<div class="login-role-backdrop" id="loginRoleBackdrop" hidden>
  <div class="login-role-card glass" role="dialog" aria-modal="true" aria-labelledby="loginRoleTitle">
    <h3 class="h5 mb-1" id="loginRoleTitle">Choose an account</h3>
    <p class="text-muted small mb-3" id="loginRoleHint">This email is linked to more than one CulTour account. Pick which one to open now — you can switch later from your profile menu.</p>
    <div class="login-role-options" id="loginRoleOptions"></div>
    <button type="button" class="btn btn-link btn-sm text-muted mt-3 w-100" id="loginRoleCancel">Cancel</button>
  </div>
</div>

<div class="login-role-backdrop" id="loginLinkModal" hidden>
  <div class="login-role-card login-role-card--form glass" id="loginLinkChoiceCard" role="dialog" aria-modal="true" aria-labelledby="loginLinkChoiceTitle">
    <div class="login-role-card__head">
      <div>
        <h3 class="h5 mb-1" id="loginLinkChoiceTitle">Link an existing account</h3>
        <p class="small text-muted mb-0">Choose which way you want to register the other role. Business accounts cannot be linked.</p>
      </div>
      <button type="button" class="login-role-card__close" id="loginLinkChoiceClose" aria-label="Close">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>
    <div class="login-role-options">
      <button type="button" class="login-role-option" id="loginLinkToGuide" data-target-role="<?= ROLE_TOUR_GUIDE ?>">
        <i class="bi bi-compass" aria-hidden="true"></i>
        <span class="login-role-option__copy">
          <span>Tourist to Tour Guide</span>
          <small>I have a Tourist account and want to register as a Tour Guide</small>
        </span>
        <i class="bi bi-chevron-right login-role-option__chevron" aria-hidden="true"></i>
      </button>
      <button type="button" class="login-role-option" id="loginLinkToTourist" data-target-role="<?= ROLE_TOURIST ?>">
        <i class="bi bi-person" aria-hidden="true"></i>
        <span class="login-role-option__copy">
          <span>Tour Guide to Tourist</span>
          <small>I have a Tour Guide account and want to register as a Tourist</small>
        </span>
        <i class="bi bi-chevron-right login-role-option__chevron" aria-hidden="true"></i>
      </button>
    </div>
    <button type="button" class="btn btn-link btn-sm text-muted mt-3 w-100" id="loginLinkChoiceCancel">Cancel</button>
  </div>

  <div class="login-role-card login-role-card--form glass d-none" id="loginLinkFormCard" role="dialog" aria-modal="true" aria-labelledby="loginLinkFormTitle">
    <div class="login-role-card__head">
      <div>
        <h3 class="h5 mb-1" id="loginLinkFormTitle">Sign in to your existing account</h3>
        <p class="small text-muted mb-0" id="loginLinkFormHint">Enter that account’s email and password.</p>
      </div>
      <button type="button" class="login-role-card__close" id="loginLinkFormClose" aria-label="Close">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>
    <div class="alert alert-danger py-2 small d-none" id="loginLinkError" role="alert"></div>
    <div class="mb-3">
      <label class="form-label" for="loginLinkEmail">Email</label>
      <input type="email" class="form-control" id="loginLinkEmail" autocomplete="username" maxlength="190">
    </div>
    <div class="mb-3">
      <label class="form-label" for="loginLinkPassword">Password</label>
      <input type="password" class="form-control" id="loginLinkPassword" autocomplete="current-password">
    </div>
    <button type="button" class="btn btn-ct w-100" id="loginLinkContinue">Continue</button>
    <button type="button" class="btn btn-link btn-sm text-muted mt-2 w-100" id="loginLinkFormBack">Back</button>
  </div>

  <div class="login-role-card login-role-card--form glass d-none" id="loginLinkConfirmCard" role="dialog" aria-modal="true" aria-labelledby="loginLinkConfirmTitle">
    <div class="login-role-card__head">
      <div>
        <h3 class="h5 mb-1" id="loginLinkConfirmTitle">Confirm account link</h3>
        <p class="small text-muted mb-0">Please confirm before we open registration.</p>
      </div>
      <button type="button" class="login-role-card__close" id="loginLinkConfirmClose" aria-label="Close">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>
    <p class="mb-3" id="loginLinkConfirmBody">Link this account to your new registration?</p>
    <div class="border rounded-3 p-3 small mb-3">
      <div class="text-muted">Account</div>
      <div class="fw-semibold" id="loginLinkConfirmName">—</div>
      <div id="loginLinkConfirmEmail">—</div>
      <div class="text-muted mt-1" id="loginLinkConfirmRole">—</div>
    </div>
    <p class="small text-muted mb-3" id="loginLinkConfirmNote">Shared name, phone, and address will be filled in. You still need to complete this role’s required fields.</p>
    <button type="button" class="btn btn-ct w-100" id="loginLinkConfirmOk">Confirm link</button>
    <button type="button" class="btn btn-link btn-sm text-muted mt-2 w-100" id="loginLinkConfirmBack">Back</button>
  </div>

  <div class="login-role-card login-role-card--form glass d-none" id="loginLinkMissingCard" role="dialog" aria-modal="true" aria-labelledby="loginLinkMissingTitle">
    <div class="login-role-card__head">
      <div>
        <h3 class="h5 mb-1" id="loginLinkMissingTitle">A few details are still needed</h3>
        <p class="small text-muted mb-0" id="loginLinkMissingLead">Your existing account filled in the shared fields. Complete these for the new role.</p>
      </div>
      <button type="button" class="login-role-card__close" id="loginLinkMissingClose" aria-label="Close">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>
    <ul class="login-link-missing-list mb-3" id="loginLinkMissingList"></ul>
    <p class="small text-muted mb-2" id="loginLinkMissingExtra"></p>
    <p class="small text-muted mb-3">We’ll open registration so you can fill these in. Shared details stay locked.</p>
    <button type="button" class="btn btn-ct w-100" id="loginLinkMissingOk">Continue to registration</button>
    <button type="button" class="btn btn-link btn-sm text-muted mt-2 w-100" id="loginLinkMissingBack">Back</button>
  </div>
</div>

<script>
(function () {
  const form = document.getElementById('loginForm');
  const errorEl = document.getElementById('loginError');
  const roleInput = document.getElementById('loginRoleId');
  const backdrop = document.getElementById('loginRoleBackdrop');
  const optionsEl = document.getElementById('loginRoleOptions');
  const cancelBtn = document.getElementById('loginRoleCancel');
  const submitBtn = document.getElementById('loginSubmit');
  if (!form || !backdrop || !optionsEl) return;

  const roleIcons = {
    '<?= ROLE_TOURIST ?>': 'bi-person',
    '<?= ROLE_TOUR_GUIDE ?>': 'bi-compass',
    '<?= ROLE_BUSINESS ?>': 'bi-shop'
  };

  function showError(msg) {
    if (!errorEl) return;
    if (msg) {
      errorEl.textContent = msg;
      errorEl.classList.remove('d-none');
    } else {
      errorEl.textContent = '';
      errorEl.classList.add('d-none');
    }
  }

  function openRoleModal(roles) {
    optionsEl.innerHTML = '';
    (roles || []).forEach(function (opt) {
      const rid = String(opt.role_id || '');
      const label = opt.label || 'Account';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'login-role-option';
      btn.setAttribute('data-role-id', rid);
      const icon = document.createElement('i');
      icon.className = 'bi ' + (roleIcons[rid] || 'bi-person-badge');
      icon.setAttribute('aria-hidden', 'true');
      const text = document.createElement('span');
      text.textContent = label;
      const chev = document.createElement('i');
      chev.className = 'bi bi-chevron-right login-role-option__chevron';
      chev.setAttribute('aria-hidden', 'true');
      btn.appendChild(icon);
      btn.appendChild(text);
      btn.appendChild(chev);
      btn.addEventListener('click', function () {
        roleInput.value = rid;
        closeRoleModal();
        submitLogin(true);
      });
      optionsEl.appendChild(btn);
    });
    backdrop.hidden = false;
    requestAnimationFrame(function () {
      backdrop.classList.add('is-open');
    });
    document.body.classList.add('login-role-open');
  }

  function closeRoleModal() {
    backdrop.classList.remove('is-open');
    document.body.classList.remove('login-role-open');
    window.setTimeout(function () {
      if (!backdrop.classList.contains('is-open')) backdrop.hidden = true;
    }, 180);
  }

  if (cancelBtn) {
    cancelBtn.addEventListener('click', function () {
      roleInput.value = '';
      closeRoleModal();
    });
  }
  backdrop.addEventListener('click', function (e) {
    if (e.target === backdrop) {
      roleInput.value = '';
      closeRoleModal();
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && backdrop.classList.contains('is-open')) {
      roleInput.value = '';
      closeRoleModal();
    }
  });

  const forms = function () { return window.CulTourForms || null; };
  function setSubmitBusy(busy) {
    if (!submitBtn) return;
    const cf = forms();
    if (cf) {
      cf.setBusy(submitBtn, busy, busy ? 'Signing in…' : '');
    } else {
      submitBtn.disabled = busy;
      submitBtn.textContent = busy ? 'Signing in…' : 'Login';
    }
  }

  function fieldsValid() {
    let firstBad = null;
    [document.getElementById('loginEmail'), document.getElementById('loginPassword')].forEach(function (el) {
      if (!el) return;
      const cf = forms();
      if (!el.checkValidity()) {
        if (cf) cf.showError(el, cf.message(el));
        if (!firstBad) firstBad = el;
      } else if (cf) {
        cf.clearError(el);
      }
    });
    if (firstBad) {
      try { firstBad.focus(); } catch (err) {}
      return false;
    }
    return true;
  }

  const passInput = document.getElementById('loginPassword');
  const capsHint = document.getElementById('loginCapsHint');
  if (passInput && capsHint) {
    ['keydown', 'keyup'].forEach(function (evt) {
      passInput.addEventListener(evt, function (e) {
        if (typeof e.getModifierState !== 'function') return;
        capsHint.classList.toggle('d-none', !e.getModifierState('CapsLock'));
      });
    });
    passInput.addEventListener('blur', function () { capsHint.classList.add('d-none'); });
  }

  function submitLogin(fromRolePick) {
    showError('');
    if (!fromRolePick && !fieldsValid()) return;
    setSubmitBusy(true);
    const data = new FormData(form);
    fetch(form.getAttribute('action') || window.location.href, {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (res) {
        const body = res.body || {};
        if (body.ok && body.redirect) {
          window.location.href = body.redirect;
          return true;
        }
        if (body.need_role && body.roles && body.roles.length) {
          openRoleModal(body.roles);
          return false;
        }
        showError(body.message || 'Incorrect email or password.');
        roleInput.value = '';
        return false;
      })
      .catch(function () {
        showError('CulTour could not be reached. Check your internet connection, then try signing in again.');
        roleInput.value = '';
        return false;
      })
      .then(function (leaving) {
        if (!leaving) setSubmitBusy(false);
      });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    roleInput.value = '';
    submitLogin(false);
  });
})();
</script>
<script>
(function () {
  const ROLE_TOURIST = '<?= ROLE_TOURIST ?>';
  const ROLE_GUIDE = '<?= ROLE_TOUR_GUIDE ?>';
  const csrf = <?= json_encode(csrf_token()) ?>;
  const linkUrl = <?= json_encode(APP_URL . '/api/auth/link_account.php') ?>;
  const stashUrl = <?= json_encode(APP_URL . '/api/auth/stash_link.php') ?>;
  const registerUrl = <?= json_encode(APP_URL . '/register.php') ?>;
  const openBtn = document.getElementById('loginLinkAccountBtn');
  const backdrop = document.getElementById('loginLinkModal');
  const choiceCard = document.getElementById('loginLinkChoiceCard');
  const formCard = document.getElementById('loginLinkFormCard');
  const confirmCard = document.getElementById('loginLinkConfirmCard');
  const missingCard = document.getElementById('loginLinkMissingCard');
  const missingLead = document.getElementById('loginLinkMissingLead');
  const missingList = document.getElementById('loginLinkMissingList');
  const missingExtra = document.getElementById('loginLinkMissingExtra');
  const errEl = document.getElementById('loginLinkError');
  const emailEl = document.getElementById('loginLinkEmail');
  const passEl = document.getElementById('loginLinkPassword');
  const continueBtn = document.getElementById('loginLinkContinue');
  const formTitle = document.getElementById('loginLinkFormTitle');
  const formHint = document.getElementById('loginLinkFormHint');
  const confirmBody = document.getElementById('loginLinkConfirmBody');
  const confirmName = document.getElementById('loginLinkConfirmName');
  const confirmEmail = document.getElementById('loginLinkConfirmEmail');
  const confirmRole = document.getElementById('loginLinkConfirmRole');
  const confirmNote = document.getElementById('loginLinkConfirmNote');
  const loginEmail = document.getElementById('loginEmail');
  let targetRole = '';
  let pendingLink = null;

  function roleLabel(role) {
    return String(role) === ROLE_GUIDE ? 'Tour Guide' : 'Tourist';
  }
  function sourceLabel(role) {
    return String(role) === ROLE_GUIDE ? 'Tourist' : 'Tour Guide';
  }
  function showCard(card) {
    [choiceCard, formCard, confirmCard, missingCard].forEach(function (el) {
      if (!el) return;
      el.classList.toggle('d-none', el !== card);
    });
  }
  function showError(msg) {
    if (!errEl) return;
    if (msg) {
      errEl.textContent = msg;
      errEl.classList.remove('d-none');
    } else {
      errEl.textContent = '';
      errEl.classList.add('d-none');
    }
  }
  function openModal() {
    if (!backdrop) return;
    pendingLink = null;
    targetRole = '';
    showError('');
    if (passEl) passEl.value = '';
    if (emailEl && loginEmail && loginEmail.value && !emailEl.value) {
      emailEl.value = loginEmail.value;
    }
    showCard(choiceCard);
    backdrop.hidden = false;
    requestAnimationFrame(function () { backdrop.classList.add('is-open'); });
    document.body.classList.add('login-role-open');
  }
  function closeModal() {
    pendingLink = null;
    targetRole = '';
    if (!backdrop) return;
    backdrop.classList.remove('is-open');
    document.body.classList.remove('login-role-open');
    setTimeout(function () {
      if (!backdrop.classList.contains('is-open')) {
        backdrop.hidden = true;
        showCard(choiceCard);
        showError('');
        if (passEl) passEl.value = '';
      }
    }, 200);
  }
  function goToSignIn(role) {
    targetRole = String(role || '');
    pendingLink = null;
    showError('');
    if (formTitle) formTitle.textContent = 'Sign in to your ' + sourceLabel(targetRole) + ' account';
    if (formHint) {
      formHint.textContent = targetRole === ROLE_GUIDE
        ? 'We’ll reuse your Tourist details. You still need an Ormoc City current address, languages, and credentials.'
        : 'We’ll reuse your Tour Guide details. Tourist validations still apply, including emergency contact.';
    }
    showCard(formCard);
    if (emailEl && emailEl.value) {
      if (passEl) passEl.focus();
    } else if (emailEl) {
      emailEl.focus();
    }
  }

  if (openBtn) openBtn.addEventListener('click', openModal);
  ['loginLinkChoiceClose', 'loginLinkChoiceCancel', 'loginLinkFormClose', 'loginLinkConfirmClose'].forEach(function (id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', closeModal);
  });
  const missingClose = document.getElementById('loginLinkMissingClose');
  if (missingClose) {
    missingClose.addEventListener('click', function () {
      goToRegister();
    });
  }
  ['loginLinkToGuide', 'loginLinkToTourist'].forEach(function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', function () {
      goToSignIn(el.getAttribute('data-target-role'));
    });
  });
  const formBack = document.getElementById('loginLinkFormBack');
  if (formBack) {
    formBack.addEventListener('click', function () {
      pendingLink = null;
      showError('');
      showCard(choiceCard);
    });
  }
  if (backdrop) {
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) closeModal();
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && backdrop && backdrop.classList.contains('is-open')) {
      closeModal();
    }
  });

  if (continueBtn) {
    continueBtn.addEventListener('click', function () {
      if (!targetRole) {
        showCard(choiceCard);
        return;
      }
      const email = emailEl ? String(emailEl.value || '').trim() : '';
      const passwordVal = passEl ? String(passEl.value || '') : '';
      showError('');
      continueBtn.disabled = true;
      fetch(linkUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          csrf_token: csrf,
          email: email,
          password: passwordVal,
          target_role_id: Number(targetRole)
        })
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          continueBtn.disabled = false;
          if (!data || !data.ok) {
            showError((data && data.message) || 'Could not link that account.');
            return;
          }
          const ident = data.identity || {};
          ident.email = data.email || ident.email || email;
          pendingLink = { role: targetRole, data: data, ident: ident, missing: Array.isArray(data.missing) ? data.missing : [] };
          const fromLabel = data.source_role_label || sourceLabel(targetRole);
          const toLabel = roleLabel(targetRole);
          const fullName = [ident.first_name, ident.middle_name, ident.last_name, ident.name_suffix]
            .filter(function (p) { return String(p || '').trim(); })
            .join(' ') || '—';
          if (confirmBody) {
            confirmBody.textContent = 'Link this ' + fromLabel + ' account to your new ' + toLabel + ' registration?';
          }
          if (confirmName) confirmName.textContent = fullName;
          if (confirmEmail) confirmEmail.textContent = data.email || email || '—';
          if (confirmRole) confirmRole.textContent = fromLabel + ' account';
          if (confirmNote) {
            confirmNote.textContent = pendingLink.missing.length
              ? 'Shared details will be filled in. We’ll tell you if this role still needs extra information.'
              : 'Shared details will be filled in. Nothing extra is required beyond reviewing and creating the account.';
          }
          showCard(confirmCard);
          const ok = document.getElementById('loginLinkConfirmOk');
          if (ok) ok.focus();
        })
        .catch(function () {
          continueBtn.disabled = false;
          showError('Could not reach CulTour. Try again.');
        });
    });
  }

  function setBusy(btn, busy, idleLabel, busyLabel) {
    if (!btn) return;
    btn.disabled = !!busy;
    if (busy && busyLabel) btn.textContent = busyLabel;
    else if (!busy && idleLabel) btn.textContent = idleLabel;
  }

  function goToRegister() {
    if (!pendingLink) {
      showCard(formCard);
      return;
    }
    const payload = {
      csrf_token: csrf,
      action: 'stash',
      token: pendingLink.data.token || '',
      source_user_id: pendingLink.data.source_user_id || '',
      target_role_id: Number(pendingLink.role),
      complete: !(pendingLink.missing && pendingLink.missing.length)
    };
    const handoff = {
      role: pendingLink.role,
      token: pendingLink.data.token || '',
      source_user_id: pendingLink.data.source_user_id || '',
      email: pendingLink.data.email || pendingLink.ident.email || '',
      source_role_label: pendingLink.data.source_role_label || sourceLabel(pendingLink.role),
      ident: pendingLink.ident,
      missing: pendingLink.missing || []
    };
    try {
      sessionStorage.setItem('cultour_registration_link', JSON.stringify(handoff));
    } catch (err) {}
    const confirmOk = document.getElementById('loginLinkConfirmOk');
    const missingOk = document.getElementById('loginLinkMissingOk');
    setBusy(confirmOk, true, 'Confirm link', 'Continuing…');
    setBusy(missingOk, true, 'Continue to registration', 'Continuing…');
    fetch(stashUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok && data.redirect) {
          window.location.href = data.redirect;
          return;
        }
        setBusy(confirmOk, false, 'Confirm link');
        setBusy(missingOk, false, 'Continue to registration');
        showError((data && data.message) || 'Could not continue to registration. Try again.');
        showCard(formCard);
      })
      .catch(function () {
        setBusy(confirmOk, false, 'Confirm link');
        setBusy(missingOk, false, 'Continue to registration');
        window.location.href = registerUrl + '?role=' + encodeURIComponent(pendingLink.role);
      });
  }

  function showMissingCard() {
    const items = (pendingLink && pendingLink.missing) || [];
    const fromLabel = (pendingLink && pendingLink.data && pendingLink.data.source_role_label) || sourceLabel(targetRole);
    const toLabel = roleLabel(targetRole);
    if (missingLead) {
      missingLead.textContent = 'Your ' + fromLabel + ' details were copied. You still need these for your ' + toLabel + ' registration:';
    }
    if (missingList) {
      missingList.innerHTML = '';
      items.forEach(function (item) {
        const li = document.createElement('li');
        li.textContent = (item && item.label) || String(item || '');
        missingList.appendChild(li);
      });
    }
    if (missingExtra) {
      missingExtra.textContent = String(targetRole) === ROLE_GUIDE
        ? 'Years of experience is optional. After Tourism Office approval, tourists still cannot book you until you add a photo, bio, languages, and weekly availability. The Tourism Office sets the daily rate.'
        : '';
    }
    showCard(missingCard);
    const ok = document.getElementById('loginLinkMissingOk');
    if (ok) ok.focus();
  }

  const confirmOk = document.getElementById('loginLinkConfirmOk');
  if (confirmOk) {
    confirmOk.addEventListener('click', function () {
      if (!pendingLink) {
        showCard(formCard);
        return;
      }
      if (pendingLink.missing && pendingLink.missing.length) {
        showMissingCard();
        return;
      }
      goToRegister();
    });
  }
  const confirmBack = document.getElementById('loginLinkConfirmBack');
  if (confirmBack) {
    confirmBack.addEventListener('click', function () {
      pendingLink = null;
      showCard(formCard);
      if (passEl) passEl.focus();
    });
  }
  const missingOk = document.getElementById('loginLinkMissingOk');
  if (missingOk) {
    missingOk.addEventListener('click', goToRegister);
  }
  const missingBack = document.getElementById('loginLinkMissingBack');
  if (missingBack) {
    missingBack.addEventListener('click', function () {
      showCard(confirmCard);
      if (confirmOk) confirmOk.focus();
    });
  }
  if (passEl) {
    passEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (confirmCard && !confirmCard.classList.contains('d-none')) return;
        if (continueBtn) continueBtn.click();
      }
    });
  }
  if (emailEl) {
    emailEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (passEl && !String(passEl.value || '')) passEl.focus();
        else if (continueBtn) continueBtn.click();
      }
    });
  }
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
