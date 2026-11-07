(function () {
  var cfg = window.CulTourFav || {};
  if (!cfg.url) return;

  function showNote(btn, text) {
    var wrap = btn.closest('.ct-rail__fav-wrap');
    if (!wrap) return;
    var note = wrap.querySelector('[data-fav-note]');
    if (!note) return;
    note.textContent = text || '';
    note.classList.toggle('is-shown', !!text);
  }

  function paint(btn, on) {
    btn.classList.toggle('is-on', on);
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.setAttribute('aria-label', on ? 'Remove from favorites' : 'Add to favorites');
    var icon = btn.querySelector('i');
    if (icon) {
      icon.classList.toggle('bi-heart-fill', on);
      icon.classList.toggle('bi-heart', !on);
    }
  }

  function send(btn) {
    var body = new FormData();
    body.append('csrf_token', cfg.csrf || '');
    body.append(
      btn.getAttribute('data-fav-type') === 'business' ? 'business_id' : 'destination_id',
      btn.getAttribute('data-fav-id') || '0'
    );

    return fetch(cfg.url, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok) throw new Error((data && data.message) || 'Request failed');
        return !!data.favorited;
      });
  }

  // Capture phase: the rail's own click handler sits on .ct-rail__track and would
  // navigate to the detail page before a bubble-phase listener could stop it.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-fav-btn]');
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();

    if (btn.dataset.favBusy === '1') return;
    btn.dataset.favBusy = '1';

    var was = btn.classList.contains('is-on');
    paint(btn, !was);

    send(btn)
      .then(function (favorited) {
        paint(btn, favorited);
        showNote(btn, favorited ? 'Added to favorite' : 'Removed from favorites');
      })
      .catch(function () {
        paint(btn, was);
        showNote(btn, '');
      })
      .then(function () { btn.dataset.favBusy = '0'; });
  }, true);
})();
