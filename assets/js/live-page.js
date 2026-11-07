/**
 * CulTour live page polls — refresh lists without wiping open forms,
 * file pickers, Bootstrap modals, proof overlays, or Leaflet maps.
 */
(function () {
  function isOverlayOpen() {
    return (
      document.body.classList.contains('admin-biz-proof-open') ||
      !!document.querySelector('.admin-biz-proof-overlay:not([hidden])') ||
      !!document.querySelector('.modal.show, .modal.showing')
    );
  }

  function hasChosenFile(root) {
    var found = false;
    root.querySelectorAll('input[type="file"]').forEach(function (inp) {
      if (inp.files && inp.files.length) found = true;
    });
    return found;
  }

  function isEditing(root) {
    var ae = document.activeElement;
    if (!ae || !root.contains(ae)) return false;
    if (ae.isContentEditable) return true;
    var tag = (ae.tagName || '').toLowerCase();
    if (tag === 'textarea' || tag === 'select') return true;
    if (tag === 'input') {
      var t = (ae.type || '').toLowerCase();
      return t !== 'hidden' && t !== 'submit' && t !== 'button' && t !== 'reset' && t !== 'image';
    }
    return false;
  }

  function isSubmitting(root) {
    return !!root.querySelector('form.ct-submitting');
  }

  function isDirty(root) {
    var dirty = false;
    root.querySelectorAll('input, textarea, select').forEach(function (el) {
      var t = (el.type || '').toLowerCase();
      if (t === 'hidden' || t === 'submit' || t === 'button' || t === 'reset' || t === 'image') return;
      if (t === 'file') {
        if (el.files && el.files.length) dirty = true;
        return;
      }
      if (t === 'checkbox' || t === 'radio') {
        if (el.checked !== el.defaultChecked) dirty = true;
        return;
      }
      if (String(el.value) !== String(el.defaultValue)) dirty = true;
    });
    return dirty;
  }

  function hasOpenCollapse(root) {
    return !!root.querySelector('.collapse.show');
  }

  function shouldDefer(root) {
    if (!root) return true;
    return (
      isOverlayOpen() ||
      isEditing(root) ||
      isSubmitting(root) ||
      hasChosenFile(root) ||
      isDirty(root) ||
      hasOpenCollapse(root)
    );
  }

  function fieldKey(el, i) {
    var form = el.form || el.closest('form');
    var idInp =
      form &&
      form.querySelector('[name="id"], [name="booking_id"], [name="appeal_id"], [name="document_id"]');
    return (
      (idInp ? String(idInp.value) : 'x') +
      '|' +
      (el.name || el.id || ('i' + i)) +
      '|' +
      (el.type || el.tagName) +
      '|' +
      (el.value && (el.type === 'radio' || el.type === 'checkbox') ? el.value : '')
    );
  }

  function capture(root) {
    var snap = { fields: {}, shown: [], scroll: window.scrollY };
    if (!root) return snap;
    root.querySelectorAll('input, textarea, select').forEach(function (el, i) {
      if (!el.name) return;
      var t = (el.type || '').toLowerCase();
      if (t === 'file' || t === 'hidden') return;
      var key = fieldKey(el, i);
      if (t === 'checkbox' || t === 'radio') {
        snap.fields[key] = { kind: t, checked: !!el.checked, value: el.value };
      } else {
        snap.fields[key] = { kind: 'value', value: el.value };
      }
    });
    root.querySelectorAll('.collapse.show').forEach(function (el) {
      if (el.id) snap.shown.push(el.id);
    });
    return snap;
  }

  function restore(root, snap) {
    if (!root || !snap) return;
    Object.keys(snap.fields || {}).forEach(function (key) {
      var saved = snap.fields[key];
      root.querySelectorAll('input, textarea, select').forEach(function (el, i) {
        if (!el.name) return;
        if (fieldKey(el, i) !== key) return;
        if (saved.kind === 'checkbox' || saved.kind === 'radio') {
          el.checked = !!saved.checked;
        } else if (saved.kind === 'value') {
          el.value = saved.value;
        }
      });
    });
    (snap.shown || []).forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.classList.add('show');
    });
  }

  function stopAnnCarousels(root) {
    if (!root || !root.querySelectorAll) return;
    root.querySelectorAll('[data-ann-carousel]').forEach(function (el) {
      if (typeof el._annStop === 'function') el._annStop();
    });
  }

  function afterReplace(root) {
    if (window.CulTourBindAnnCarousels) window.CulTourBindAnnCarousels(root);
    try {
      root.dispatchEvent(new CustomEvent('cultour:live-replaced', { bubbles: true }));
    } catch (e) {}
  }

  function applyHtml(root, html, snap) {
    if (!root || html == null) return;
    var keep = snap || capture(root);
    stopAnnCarousels(root);
    root.innerHTML = html;
    restore(root, keep);
    afterReplace(root);
  }

  function applySlots(slots, root) {
    if (!slots) return true;
    var ok = true;
    Object.keys(slots).forEach(function (slot) {
      var el =
        (root && root.querySelector('[data-live-slot="' + slot + '"]')) ||
        document.querySelector('[data-live-slot="' + slot + '"]');
      if (!el || slots[slot] == null) return;
      if (shouldDefer(el)) {
        ok = false;
        return;
      }
      var snap = capture(el);
      stopAnnCarousels(el);
      el.innerHTML = slots[slot];
      restore(el, snap);
      afterReplace(el);
    });
    return ok;
  }

  function markSubmitting(form) {
    if (form && form.tagName === 'FORM') form.classList.add('ct-submitting');
  }

  function bindSubmitGuard(root) {
    if (!root || root._liveSubmitBound) return;
    root._liveSubmitBound = true;
    root.addEventListener(
      'submit',
      function (e) {
        markSubmitting(e.target);
      },
      true
    );
  }

  function parseJson(res, stop) {
    if (res.status === 401) {
      if (stop) stop();
      return { ok: false, unauthorized: true };
    }
    return res.json();
  }

  function startPage(root) {
    var cfg = window.CulTour || {};
    if (!root || !cfg.appUrl) return;
    var page = root.getAttribute('data-live-page');
    if (!page) return;
    bindSubmitGuard(root);
    var version = root.getAttribute('data-live-version') || '';
    var interval = Number(root.getAttribute('data-live-interval') || 2500) || 2500;
    var busy = false;
    var stopped = false;

    function url() {
      var q = root.getAttribute('data-live-query') || '';
      return (
        cfg.appUrl +
        '/api/live/page.php?page=' +
        encodeURIComponent(page) +
        '&since=' +
        encodeURIComponent(version) +
        (q ? '&' + q : '')
      );
    }

    function poll() {
      if (stopped || busy || document.hidden) return;
      busy = true;
      fetch(url(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (res) {
          return parseJson(res, function () {
            stopped = true;
          });
        })
        .then(function (data) {
          if (!data || data.unauthorized) return;
          if (!data.ok) return;
          if (data.pending != null && window.CulTourUnread) {
            window.CulTourUnread.apply({ ok: true, pending_bookings: data.pending });
          }
          if (data.unread_notifications != null && window.CulTourUnread) {
            window.CulTourUnread.apply({ ok: true, notifications: data.unread_notifications });
          }
          if (data.stats) applyStats(data.stats);
          if (!data.changed) {
            if (data.version) version = data.version;
            return;
          }
          if (shouldDefer(root)) return;
          if (data.html != null) {
            var snap = capture(root);
            applyHtml(root, data.html, snap);
          }
          if (!applySlots(data.slots, root)) return;
          version = data.version || version;
          root.setAttribute('data-live-version', version);
          if (typeof data.pending === 'number') {
            root.setAttribute('data-pending', String(data.pending));
          }
        })
        .catch(function () {})
        .finally(function () {
          busy = false;
        });
    }

    poll();
    setInterval(poll, interval);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });
  }

  function applyStats(stats) {
    if (!stats) return;
    Object.keys(stats).forEach(function (key) {
      document.querySelectorAll('[data-stat="' + key + '"]').forEach(function (el) {
        el.textContent = String(stats[key]);
      });
    });
  }

  function startStats(root) {
    var cfg = window.CulTour || {};
    if (!root || !cfg.appUrl) return;
    var page = root.getAttribute('data-live-stats');
    if (!page) return;
    var version = root.getAttribute('data-live-version') || '';
    var interval = Number(root.getAttribute('data-live-interval') || 4000) || 4000;
    var busy = false;
    var stopped = false;

    function poll() {
      if (stopped || busy || document.hidden) return;
      busy = true;
      var q = root.getAttribute('data-live-query') || '';
      fetch(
        cfg.appUrl +
          '/api/live/page.php?page=' +
          encodeURIComponent(page) +
          '&since=' +
          encodeURIComponent(version) +
          (q ? '&' + q : ''),
        { credentials: 'same-origin', cache: 'no-store' }
      )
        .then(function (res) {
          return parseJson(res, function () {
            stopped = true;
          });
        })
        .then(function (data) {
          if (!data || !data.ok || data.unauthorized) return;
          if (data.version && data.version === version && !data.changed) return;
          if (data.stats) applyStats(data.stats);
          if (!applySlots(data.slots, root)) return;
          version = data.version || version;
          root.setAttribute('data-live-version', version);
        })
        .catch(function () {})
        .finally(function () {
          busy = false;
        });
    }

    poll();
    setInterval(poll, interval);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });
  }

  // Heart toggles update the DOM locally; ignore them so a tap doesn't force a region swap.
  function selfFingerprint(html) {
    return String(html || '')
      .replace(/<button[^>]*data-fav-btn[\s\S]*?<\/button>/g, '')
      .replace(/\s+/g, ' ');
  }

  /**
   * Re-fetch the current URL and swap [data-live-self="id"] when the server HTML
   * for that region changes. For pages without a dedicated live payload.
   */
  function startSelf(root) {
    var id = root.getAttribute('data-live-self');
    if (!id) return;
    bindSubmitGuard(root);
    var interval = Number(root.getAttribute('data-live-interval') || 5000) || 5000;
    var last = null;
    var busy = false;
    var stopped = false;

    function poll() {
      if (stopped || busy || document.hidden) return;
      busy = true;
      fetch(window.location.href, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-CulTour-Live': '1' },
      })
        .then(function (res) {
          if (res.status === 401) stopped = true;
          if (!res.ok || res.redirected) return null;
          return res.text();
        })
        .then(function (text) {
          if (!text) return;
          var doc = new DOMParser().parseFromString(text, 'text/html');
          var fresh = doc.querySelector('[data-live-self="' + id + '"]');
          if (!fresh) return;
          var html = fresh.innerHTML;
          var print = selfFingerprint(html);
          if (last === null) {
            last = print;
            return;
          }
          if (print === last) return;
          if (shouldDefer(root) || root.querySelector('.is-locked, .is-sliding')) return;
          last = print;
          applyHtml(root, html);
        })
        .catch(function () {})
        .finally(function () {
          busy = false;
        });
    }

    poll();
    setInterval(poll, interval);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-live-page]').forEach(startPage);
    document.querySelectorAll('[data-live-stats]').forEach(startStats);
    document.querySelectorAll('[data-live-self]').forEach(startSelf);
  });

  window.CulTourLive = {
    shouldDefer: shouldDefer,
    capture: capture,
    restore: restore,
    applyHtml: applyHtml,
    applySlots: applySlots,
    bindSubmitGuard: bindSubmitGuard,
    markSubmitting: markSubmitting,
  };
})();
