(function () {
  function card() {
    return document.getElementById('adminAppCard');
  }
  function closeCard() {
    const el = card();
    if (!el || el.hidden) return;
    el.hidden = true;
    const body = el.querySelector('[data-app-card-body]');
    if (body) body.scrollTop = 0;
    if (!document.querySelector('.admin-biz-proof-overlay:not([hidden])')) {
      document.body.classList.remove('admin-biz-proof-open');
    }
  }
  function openFrom(btn) {
    const el = card();
    if (!el) return;
    const row = btn.closest('tr');
    const tpl = row ? row.querySelector('.admin-app-source') : null;
    const title = el.querySelector('[data-app-card-title]');
    const body = el.querySelector('[data-app-card-body]');
    if (title) title.textContent = btn.getAttribute('data-app-title') || 'Application';
    if (body) {
      body.innerHTML = tpl ? tpl.innerHTML : '';
      body.scrollTop = 0;
    }
    if (el.parentElement !== document.body) document.body.appendChild(el);
    el.hidden = false;
    document.body.classList.add('admin-biz-proof-open');
  }
  document.addEventListener('click', function (e) {
    const btn = e.target.closest('[data-view-application]');
    if (btn) {
      e.preventDefault();
      openFrom(btn);
      return;
    }
    const el = card();
    if (!el || el.hidden) return;
    if (e.target === el || e.target.closest('[data-close-app-card]')) closeCard();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeCard();
  });
})();
