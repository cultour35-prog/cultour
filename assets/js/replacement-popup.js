/* Replacement-guide pop-ups: guide offer (15-minute timer) and tourist confirmation. */
(function () {
  var host = document.getElementById('ctReplacementHost');
  if (!host || typeof bootstrap === 'undefined') return;

  var POLL_MS = 4000;
  var timerId = null;
  var busy = false;
  var LOADING_LABELS = {
    guide_accept: 'Accepting…',
    guide_decline: 'Declining…',
    tourist_accept: 'Confirming…',
    tourist_other: 'Finding another guide…',
    tourist_move: 'Moving days…'
  };

  function modalEl() {
    return host.querySelector('#ctReplacementModal');
  }

  function fmt(secs) {
    secs = Math.max(0, secs);
    var m = Math.floor(secs / 60);
    var s = secs % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function startCountdown(el) {
    if (timerId) { clearInterval(timerId); timerId = null; }
    var box = el.querySelector('[data-repl-timer]');
    if (!box) return;
    var out = box.querySelector('[data-repl-countdown]');
    var endsAt = Date.now() + (parseInt(box.getAttribute('data-repl-secs') || '0', 10) * 1000);
    function tick() {
      var left = Math.round((endsAt - Date.now()) / 1000);
      if (out) out.textContent = fmt(left);
      box.classList.toggle('is-urgent', left <= 120);
      if (left <= 0) {
        clearInterval(timerId);
        timerId = null;
        box.innerHTML = '<i class="bi bi-hourglass-bottom"></i> This request expired. No action is needed.';
        el.querySelectorAll('[data-repl-actions] button').forEach(function (b) { b.disabled = true; });
        setTimeout(function () { poll(true); }, 1500);
      }
    }
    tick();
    timerId = setInterval(tick, 1000);
  }

  function show() {
    var el = modalEl();
    if (!el) return;
    startCountdown(el);
    bootstrap.Modal.getOrCreateInstance(el).show();
  }

  function replace(data) {
    var old = modalEl();
    if (old) {
      var inst = bootstrap.Modal.getInstance(old);
      if (inst) inst.hide();
      document.querySelectorAll('.modal-backdrop').forEach(function (b) {
        if (!document.querySelector('.modal.show:not(#ctReplacementModal)')) b.remove();
      });
      document.body.classList.remove('modal-open');
      document.body.style.removeProperty('overflow');
      document.body.style.removeProperty('padding-right');
    }
    if (timerId) { clearInterval(timerId); timerId = null; }
    host.setAttribute('data-key', data.key || '');
    host.setAttribute('data-audience', data.audience || '');
    host.innerHTML = data.html || '';
    if (data.key) show();
  }

  function poll(force) {
    if (busy) return;
    if (!force && document.hidden) return;
    var el = modalEl();
    if (el && (el.querySelector('[data-repl-done]') || el.querySelector('[data-repl-form].is-sending'))) return;
    busy = true;
    fetch(host.getAttribute('data-poll-url'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.ok) return;
        if ((data.key || '') !== (host.getAttribute('data-key') || '')) replace(data);
      })
      .catch(function () { /* offline: try again next tick */ })
      .then(function () { busy = false; });
  }

  function showResult(el, ok, message) {
    el.classList.remove('is-busy');
    var msg = el.querySelector('[data-repl-msg]');
    var actions = el.querySelector('[data-repl-actions]');
    if (msg) {
      msg.hidden = false;
      msg.className = 'ct-repl__msg alert ' + (ok ? 'alert-success' : 'alert-danger') + ' py-2 small mb-0 mt-2';
      msg.textContent = message || (ok ? 'Done.' : 'Something went wrong.');
    }
    if (actions) {
      actions.innerHTML = '<button type="button" class="btn btn-ct" data-repl-done>Close</button>';
    }
    var timer = el.querySelector('[data-repl-timer]');
    if (timer) timer.hidden = true;
    if (timerId) { clearInterval(timerId); timerId = null; }
    el.querySelectorAll('[data-repl-body] form').forEach(function (f) { f.hidden = true; });
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches('#ctReplacementModal [data-repl-form]')) return;
    e.preventDefault();
    var el = modalEl();
    if (!el || form.classList.contains('is-sending')) return;
    form.classList.add('is-sending');
    var actionInput = form.querySelector('input[name="action"]');
    var label = LOADING_LABELS[actionInput ? actionInput.value : ''] || 'Please wait…';
    var btn = e.submitter || form.querySelector('button[type="submit"], button:not([type])');
    el.querySelectorAll('[data-repl-form] button').forEach(function (b) { b.disabled = true; });
    if (btn) {
      btn.classList.add('is-loading');
      btn.setAttribute('aria-busy', 'true');
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + label;
    }
    el.classList.add('is-busy');
    // form.action is shadowed by the hidden <input name="action">.
    fetch(form.getAttribute('action'), {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unexpected reply. Refresh and try again.' }; }); })
      .then(function (res) { showResult(el, !!res.ok, res.message); })
      .catch(function () { showResult(el, false, 'Could not reach CulTour. Check your connection and try again.'); });
  });

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-repl-done]')) {
      window.location.reload();
      return;
    }
    if (e.target.closest('[data-repl-open]')) {
      var el = modalEl();
      if (el) {
        bootstrap.Modal.getOrCreateInstance(el).show();
      } else {
        poll(true);
      }
    }
  });

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) poll(true);
  });
  window.addEventListener('focus', function () { poll(true); });

  show();
  setInterval(function () { poll(false); }, POLL_MS);
})();
