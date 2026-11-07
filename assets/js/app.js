/**
 * CulTour front-end behaviors — unread badges, live suspension/appeals
 */
(function () {
  const root = document.documentElement;
  root.removeAttribute('data-theme');
  try { localStorage.removeItem('cultour-theme'); } catch (e) {}

  document.addEventListener('scroll', function (e) {
    var el = e.target;
    if (!el || !el.classList || !el.classList.contains('ct-scroll')) return;
    el.classList.add('is-scrolling');
    clearTimeout(el._ctScrollTimer);
    el._ctScrollTimer = setTimeout(function () {
      el.classList.remove('is-scrolling');
    }, 700);
  }, true);

  function setBadge(el, count, max) {
    if (!el) return;
    const n = Number(count) || 0;
    const cap = max || 99;
    if (n > 0) {
      el.textContent = n > cap ? cap + '+' : String(n);
      el.hidden = false;
      el.removeAttribute('hidden');
    } else {
      el.textContent = '0';
      el.hidden = true;
      el.setAttribute('hidden', '');
    }
  }

  function setDot(el, count) {
    if (!el) return;
    const n = Number(count) || 0;
    el.textContent = '';
    if (n > 0) {
      el.hidden = false;
      el.removeAttribute('hidden');
    } else {
      el.hidden = true;
      el.setAttribute('hidden', '');
    }
  }

  function applyUnread(data) {
    if (!data || !data.ok) return;
    if (data.messages != null) {
      setBadge(document.getElementById('navChatUnread'), data.messages, 9);
      setBadge(document.getElementById('navChatUnreadTop'), data.messages, 9);
      setDot(document.getElementById('navChatUnreadSide'), data.messages);
      setDot(document.getElementById('navChatUnreadMobile'), data.messages);
    }
    if (data.notifications != null) {
      setBadge(document.getElementById('navNotifUnread'), data.notifications, 9);
      setBadge(document.getElementById('navNotifUnreadMenu'), data.notifications, 99);
    }
    if (data.pending_bookings != null) {
      setDot(document.getElementById('navBookingsPending'), data.pending_bookings);
    }
    if (data.notif_html != null) {
      document.querySelectorAll('[data-live-slot="notif_dropdown"]').forEach(function (el) {
        if (data.notif_version && el.getAttribute('data-live-version') === data.notif_version) return;
        el.innerHTML = data.notif_html;
        if (data.notif_version) el.setAttribute('data-live-version', data.notif_version);
      });
    }
  }

  window.CulTourUnread = { apply: applyUnread };

  function bindAppealForm(rootEl) {
    const form = (rootEl || document).querySelector('#appealForm[data-live-appeal]');
    if (!form || form._bound) return;
    form._bound = true;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      const cfg = window.CulTour || {};
      const err = form.querySelector('[data-appeal-error]');
      const btn = form.querySelector('button[type="submit"]');
      if (err) {
        err.classList.add('d-none');
        err.textContent = '';
      }
      if (btn) btn.disabled = true;
      const fd = new FormData(form);
      if (cfg.csrf && !fd.get('csrf_token')) fd.set('csrf_token', cfg.csrf);
      fetch(cfg.appUrl + '/api/account/appeal.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) {
            if (err) {
              err.textContent = (data && data.message) || 'Could not submit appeal.';
              err.classList.remove('d-none');
            }
            return;
          }
          const box = document.getElementById('accountStatusLive');
          if (box && data.html != null) {
            box.innerHTML = data.html;
            box.setAttribute('data-status-version', data.version || '');
            box.setAttribute('data-account-suspended', data.suspended ? '1' : '0');
            bindAppealForm(box);
            bindDismiss(box);
          }
        })
        .catch(function () {
          if (err) {
            err.textContent = 'Network error. Try again.';
            err.classList.remove('d-none');
          }
        })
        .finally(function () {
          if (btn) btn.disabled = false;
        });
    });
  }

  function dismissAppealKey(key, removeEl) {
    if (!key) return;
    const cfg = window.CulTour || {};
    if (!cfg.appUrl) return;
    const params = new URLSearchParams();
    params.set('dismiss', key);
    if (cfg.csrf) params.set('csrf_token', cfg.csrf);
    const url = cfg.appUrl + '/api/account/status.php';
    const body = params.toString();
    // Prefer beacon so dismiss still saves when navigating away / logging out
    let sent = false;
    if (navigator.sendBeacon) {
      try {
        sent = navigator.sendBeacon(
          url,
          new Blob([body], { type: 'application/x-www-form-urlencoded' })
        );
      } catch (e) {
        sent = false;
      }
    }
    if (!sent) {
      fetch(url, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      }).catch(function () {});
    }
    if (removeEl) {
      const alert = removeEl.closest ? removeEl.closest('.alert') : null;
      if (alert) alert.remove();
      else if (removeEl.remove) removeEl.remove();
    }
  }

  function dismissVisibleAppealNotices() {
    const seen = {};
    document.querySelectorAll('[data-dismiss-appeal], [data-appeal-notice]').forEach(function (el) {
      const key =
        el.getAttribute('data-dismiss-appeal') ||
        (el.getAttribute('data-appeal-notice')
          ? 'appeal_' + el.getAttribute('data-appeal-notice')
          : '');
      if (!key || seen[key]) return;
      seen[key] = true;
      const btn = el.matches('[data-dismiss-appeal]')
        ? el
        : el.querySelector('[data-dismiss-appeal]') || el;
      dismissAppealKey(key, btn);
    });
  }

  function bindDismiss(rootEl) {
    (rootEl || document).querySelectorAll('[data-dismiss-appeal]').forEach(function (btn) {
      if (btn._bound) return;
      btn._bound = true;
      btn.addEventListener('click', function () {
        dismissAppealKey(btn.getAttribute('data-dismiss-appeal'), btn);
      });
    });
  }

  function bindAutoClearAppealBanner() {
    if (bindAutoClearAppealBanner._bound) return;
    bindAutoClearAppealBanner._bound = true;

    // Leaving via sidebar / topbar / logout (any in-app nav link)
    document.addEventListener(
      'click',
      function (e) {
        const link = e.target.closest('a[href]');
        if (!link) return;
        if (link.getAttribute('target') === '_blank') return;
        if (link.hasAttribute('download')) return;
        const href = link.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
        if (link.hasAttribute('data-appeals-filter') || link.hasAttribute('data-map-filter')) return;
        if (!document.querySelector('[data-appeal-notice], [data-dismiss-appeal]')) return;
        dismissVisibleAppealNotices();
      },
      true
    );

    // Back/forward / tab close / any unload
    window.addEventListener('pagehide', function () {
      dismissVisibleAppealNotices();
    });
  }

  function applyAdminNavDots(data) {
    if (!data || !data.ok) return;
    if (data.pending_appeals != null) {
      setDot(document.getElementById('navAppealsUnread'), data.pending_appeals);
    }
    if (data.pending_guides != null) {
      setDot(document.getElementById('navGuidesPending'), data.pending_guides);
    }
    if (data.pending_businesses != null) {
      setDot(document.getElementById('navBusinessesPending'), data.pending_businesses);
    }
  }

  function startAccountLive() {
    const cfg = window.CulTour || {};
    if (!cfg.liveAccount || !cfg.appUrl) return;

    const statusUrl = cfg.appUrl + '/api/account/status.php';
    let lastVersion = null;
    let wasSuspended = null;
    const box = document.getElementById('accountStatusLive');
    if (box) {
      lastVersion = box.getAttribute('data-status-version');
      wasSuspended = box.getAttribute('data-account-suspended') === '1';
      bindAppealForm(box);
      bindDismiss(box);
      if (window.CulTourLive) window.CulTourLive.bindSubmitGuard(box);
    }
    bindAutoClearAppealBanner();

    function applyStatus(data) {
      if (!data || !data.ok) return;

      // Admin: only refresh sidebar attention dots
      if (data.version === 'admin') {
        applyAdminNavDots(data);
        return;
      }

      const suspended = !!data.suspended;
      const onDashboard = !!document.getElementById('accountStatusLive');

      // Newly suspended while browsing elsewhere → go to dashboard
      if (suspended && wasSuspended === false && !onDashboard && data.dashboard_url) {
        window.location.href = data.dashboard_url;
        return;
      }

      // Restored while on suspended-only dashboard → reload full dashboard
      if (!suspended && wasSuspended === true && onDashboard) {
        window.location.reload();
        return;
      }

      if (data.version && data.version !== lastVersion) {
        const live = document.getElementById('accountStatusLive');
        if (live && window.CulTourLive && window.CulTourLive.shouldDefer(live)) {
          return;
        }
        const ae = document.activeElement;
        const editing =
          live &&
          ae &&
          live.contains(ae) &&
          ((ae.tagName || '').toLowerCase() === 'textarea' ||
            (ae.tagName || '').toLowerCase() === 'input');
        if (editing) {
          return;
        }
        lastVersion = data.version;
        if (live && data.html != null) {
          var draft = '';
          var oldTa = live.querySelector('textarea[name="appeal_message"]');
          if (oldTa) draft = oldTa.value || '';
          live.innerHTML = data.html;
          live.setAttribute('data-status-version', data.version);
          live.setAttribute('data-account-suspended', suspended ? '1' : '0');
          if (draft) {
            var newTa = live.querySelector('textarea[name="appeal_message"]');
            if (newTa) newTa.value = draft;
          }
          bindAppealForm(live);
          bindDismiss(live);
        } else if (suspended && data.dashboard_url && !onDashboard) {
          window.location.href = data.dashboard_url;
          return;
        }
      }

      wasSuspended = suspended;
    }

    function poll() {
      fetch(statusUrl, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) {
          return r.json();
        })
        .then(applyStatus)
        .catch(function () {});
    }

    poll();
    setInterval(poll, 2000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });
  }

  function startAppealsLive() {
    const cfg = window.CulTour || {};
    if (!cfg.liveAppeals || !cfg.appUrl) return;

    const listEl = document.getElementById('appealsLiveList');
    if (!listEl) return;
    const api = cfg.appUrl + '/api/admin/appeals.php';
    let filter = listEl.getAttribute('data-appeals-filter') || 'suspended';
    let version = listEl.getAttribute('data-appeals-version') || '';

    function setPendingBadge(n) {
      setDot(document.getElementById('navAppealsUnread'), n);
    }

    function flashMsg(msg, ok) {
      const host = document.getElementById('appealsFlash');
      if (!host || !msg) return;
      host.innerHTML =
        '<div class="alert alert-' +
        (ok ? 'success' : 'danger') +
        '">' +
        String(msg)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;') +
        '</div>';
      setTimeout(function () {
        host.innerHTML = '';
      }, 4000);
    }

    function isEditingResponse() {
      if (!listEl) return false;
      const ae = document.activeElement;
      if (!ae || !listEl.contains(ae)) return false;
      const tag = (ae.tagName || '').toLowerCase();
      return tag === 'textarea' || tag === 'input' || tag === 'select';
    }

    function captureDrafts() {
      const drafts = {};
      if (!listEl) return drafts;
      listEl.querySelectorAll('textarea[name="admin_response"]').forEach(function (ta) {
        const form = ta.closest('form');
        const idInput = form && form.querySelector('[name="appeal_id"]');
        if (idInput && String(ta.value || '').length) {
          drafts[String(idInput.value)] = ta.value;
        }
      });
      return drafts;
    }

    function restoreDrafts(drafts) {
      if (!listEl || !drafts) return;
      Object.keys(drafts).forEach(function (id) {
        const idInput = listEl.querySelector('input[name="appeal_id"][value="' + id + '"]');
        const form = idInput && idInput.closest('form');
        const ta = form && form.querySelector('textarea[name="admin_response"]');
        if (ta) ta.value = drafts[id];
      });
    }

    function applyList(data, force) {
      if (!data || !data.ok) return;
      if (data.pending != null) setPendingBadge(data.pending);
      if (!listEl) return;
      if (!force && data.version && data.version === version) return;
      // Don't wipe the response box while the admin is typing / submitting
      if (!force && window.CulTourLive && window.CulTourLive.shouldDefer(listEl)) return;
      if (!force && isEditingResponse()) return;
      // Don't replace DOM while a submit is in flight (avoids eating Activate clicks)
      if (!force && listEl.querySelector('form.ct-submitting')) return;
      const drafts = captureDrafts();
      version = data.version || version;
      if (data.html != null) {
        listEl.innerHTML = data.html;
        listEl.setAttribute('data-appeals-version', version);
        listEl.setAttribute('data-appeals-filter', filter);
        restoreDrafts(drafts);
      }
    }

    function poll() {
      fetch(api + '?status=' + encodeURIComponent(filter), {
        credentials: 'same-origin',
        cache: 'no-store',
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          applyList(data, false);
        })
        .catch(function () {});
    }

    // Mark forms as submitting so live poll won't steal the click
    listEl.addEventListener('submit', function (e) {
      const form = e.target;
      if (!form || form.tagName !== 'FORM') return;
      form.classList.add('ct-submitting');
      // Do NOT disable the submit button here — that can cancel the native POST
    });

    // Filter chips: normal navigation (no preventDefault — that only bolded the tab)
    // Live list still polls on the current tab after full page loads.

    poll();
    setInterval(poll, 4000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });
  }

  function startRegistrationsLive() {
    const cfg = window.CulTour || {};
    const kind = cfg.liveRegistrations;
    if (!kind || !cfg.appUrl) return;
    if (kind !== 'guides' && kind !== 'businesses' && kind !== 'users' && kind !== 'tourists') return;

    const tabsRoot = document.querySelector('[data-live-regs="' + kind + '"]');
    if (!tabsRoot) return;

    let version = tabsRoot.getAttribute('data-live-version') || '';
    let pendingCount = null;
    let totalCount = null;
    const label = kind === 'guides' ? 'tour guide' : kind === 'businesses' ? 'business' : kind === 'tourists' ? 'tourist' : 'user';

    function apiUrl() {
      const query = tabsRoot.getAttribute('data-live-query') || '';
      return (
        cfg.appUrl +
        '/api/admin/registrations.php?kind=' +
        encodeURIComponent(kind) +
        (query ? '&' + query : '')
      );
    }
    function flashNew(msg) {
      const host = document.getElementById('registrationsFlash');
      if (!host || !msg) return;
      host.innerHTML =
        '<div class="alert alert-warning fade-up mb-3"><i class="bi bi-bell me-1"></i> ' +
        String(msg)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;') +
        '</div>';
      setTimeout(function () {
        if (host.firstChild) host.innerHTML = '';
      }, 6000);
    }

    function setCount(sel, n) {
      document.querySelectorAll(sel).forEach(function (el) {
        el.textContent = String(n);
      });
    }

    function fillTbody(id, panel) {
      const tbody = document.querySelector('[data-live-tbody="' + id + '"]');
      if (!tbody || !panel) return;
      const html = panel.html || '';
      const empty = tbody.getAttribute('data-live-empty') || 'None yet.';
      const cols = tbody.getAttribute('data-live-cols') || '6';
      if (html) {
        tbody.innerHTML = html;
      } else {
        tbody.innerHTML =
          '<tr class="live-empty-row"><td colspan="' +
          cols +
          '" class="text-muted">' +
          empty.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') +
          '</td></tr>';
      }
    }

    function applyUsers(data) {
      if (!data || !data.ok) return;
      if (data.version && data.version === version) return;
      if (window.CulTourLive && window.CulTourLive.shouldDefer(tabsRoot)) return;

      const nextTotal = data.total != null ? Number(data.total) : null;
      if (totalCount != null && nextTotal != null && nextTotal > totalCount) {
        const added = nextTotal - totalCount;
        flashNew(
          (added === 1 ? 'New user registered' : added + ' new users registered') +
            ' — the list updated automatically.'
        );
      }
      totalCount = nextTotal;

      version = data.version || version;
      tabsRoot.setAttribute('data-live-version', version);

      if (nextTotal != null) {
        setCount('[data-live-count="total"]', nextTotal);
        const countEl = tabsRoot.querySelector('[data-live-count="total"]');
        if (countEl && countEl.parentElement) {
          const parent = countEl.parentElement;
          const suffix = nextTotal === 1 ? ' result' : ' results';
          // Keep the span, rewrite surrounding text safely
          parent.innerHTML = '';
          const span = document.createElement('span');
          span.setAttribute('data-live-count', 'total');
          span.textContent = String(nextTotal);
          parent.appendChild(span);
          parent.appendChild(document.createTextNode(suffix));
        }
      }

      fillTbody('users', {
        html: data.html || '',
        empty: data.empty || 'No users match these filters.',
      });
    }

    function apply(data) {
      if (!data || !data.ok) return;
      if (kind === 'users') {
        applyUsers(data);
        return;
      }
      if (data.version && data.version === version) return;
      if (window.CulTourLive && window.CulTourLive.shouldDefer(tabsRoot)) return;

      if (kind === 'tourists') {
        const nextReg = data.registered != null ? Number(data.registered) : (data.counts && data.counts.registered != null ? Number(data.counts.registered) : null);
        if (pendingCount != null && nextReg != null && nextReg > pendingCount) {
          const added = nextReg - pendingCount;
          flashNew(
            (added === 1 ? 'New tourist registered' : added + ' new tourists registered') +
              ' — the list updated automatically.'
          );
        }
        pendingCount = nextReg;
      } else {
        const nextPending = data.pending != null ? Number(data.pending) : null;
        if (pendingCount != null && nextPending != null && nextPending > pendingCount) {
          const added = nextPending - pendingCount;
          flashNew(
            (added === 1 ? 'New ' + label + ' application' : added + ' new ' + label + ' applications') +
              ' received — check Pending applications.'
          );
          const pendingBtn = document.querySelector(
            '[data-guide-tab="pending"], [data-biz-tab="pending"]'
          );
          if (pendingBtn) pendingBtn.click();
        }
        pendingCount = nextPending;
      }

      version = data.version || version;
      tabsRoot.setAttribute('data-live-version', version);

      const counts = data.counts || {};
      ['registered', 'pending', 'rejected', 'suspended', 'inactive'].forEach(function (id) {
        if (counts[id] == null) return;
        setCount('[data-live-count="' + id + '"]', counts[id]);
        setCount('[data-live-panel-count="' + id + '"]', counts[id]);
      });

      if (counts.pending != null) {
        if (kind === 'guides') {
          setDot(document.getElementById('navGuidesPending'), counts.pending);
        } else if (kind === 'businesses') {
          setDot(document.getElementById('navBusinessesPending'), counts.pending);
        }
      }

      const panels = data.panels || {};
      ['registered', 'pending', 'rejected', 'suspended', 'inactive'].forEach(function (id) {
        if (panels[id]) fillTbody(id, panels[id]);
      });
    }

    function poll() {
      // Stay in sync with AJAX filter updates (search/role/status) on the users page
      version = tabsRoot.getAttribute('data-live-version') || version;
      const totalBadgeNow = tabsRoot.querySelector('[data-live-count="total"]');
      if (totalBadgeNow) totalCount = Number(totalBadgeNow.textContent) || 0;

      fetch(apiUrl(), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) {
          return r.json();
        })
        .then(apply)
        .catch(function () {});
    }

    // Seed pending from current badge if present
    const pendingBadge = tabsRoot.querySelector(
      kind === 'tourists' ? '[data-live-count="registered"]' : '[data-live-count="pending"]'
    );
    if (pendingBadge) pendingCount = Number(pendingBadge.textContent) || 0;
    const totalBadge = tabsRoot.querySelector('[data-live-count="total"]');
    if (totalBadge) totalCount = Number(totalBadge.textContent) || 0;
    if (window.CulTourLive) window.CulTourLive.bindSubmitGuard(tabsRoot);

    poll();
    setInterval(poll, 2000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });
  }

  function bindUnsavedGuards() {
    var modalEl = document.getElementById('unsavedChangesModal');
    var forms = Array.prototype.slice.call(document.querySelectorAll('form[data-unsaved-guard]'));
    if (!modalEl || !forms.length) return;

    var pendingUrl = null;
    var bypass = false;
    var userEdited = false;
    var ready = false;
    var snapshots = new Map();
    var backdropEl = null;

    function formSnapshot(form) {
      var parts = [];
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name || el.name === 'return_to') return;
        if (el.getAttribute('data-ph-search-input')) return;
        if (el.type === 'file') {
          var files = el.files
            ? Array.prototype.map.call(el.files, function (f) { return f.name + ':' + f.size; }).join(',')
            : '';
          parts.push(el.name + '=file:' + files);
          return;
        }
        if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
        parts.push(el.name + '=' + String(el.value || ''));
      });
      parts.sort();
      return parts.join('\n');
    }

    function refreshSnapshots() {
      forms.forEach(function (form) {
        snapshots.set(form, formSnapshot(form));
      });
    }

    // Edits made before arming would be baked into the baseline, so keep the flag fallback then.
    function armGuards() {
      if (userEdited) return;
      refreshSnapshots();
      ready = true;
    }

    function isDirty() {
      if (!ready) return userEdited;
      return forms.some(function (form) {
        return formSnapshot(form) !== snapshots.get(form);
      });
    }

    function dirtyForm() {
      var found = null;
      forms.forEach(function (form) {
        if (!found && formSnapshot(form) !== snapshots.get(form)) found = form;
      });
      return found || forms[0];
    }

    function getBsModal() {
      if (typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
      return bootstrap.Modal.getOrCreateInstance(modalEl);
    }

    function ensureBackdrop() {
      if (backdropEl) return backdropEl;
      backdropEl = document.createElement('div');
      backdropEl.className = 'itin-leave-backdrop';
      backdropEl.setAttribute('aria-hidden', 'true');
      document.body.appendChild(backdropEl);
      backdropEl.addEventListener('click', closeLeave);
      return backdropEl;
    }

    function openLeave(url) {
      pendingUrl = url || null;
      var bs = getBsModal();
      if (bs) {
        bs.show();
        return;
      }
      ensureBackdrop();
      backdropEl.classList.add('is-open');
      modalEl.classList.add('show', 'd-block');
      modalEl.style.display = 'block';
      modalEl.removeAttribute('aria-hidden');
      modalEl.setAttribute('aria-modal', 'true');
      document.body.classList.add('itin-leave-open');
    }

    function closeLeave() {
      var bs = getBsModal();
      if (bs) {
        bs.hide();
        return;
      }
      modalEl.classList.remove('show', 'd-block');
      modalEl.style.display = 'none';
      modalEl.setAttribute('aria-hidden', 'true');
      modalEl.removeAttribute('aria-modal');
      if (backdropEl) backdropEl.classList.remove('is-open');
      document.body.classList.remove('itin-leave-open');
    }

    function goPending() {
      bypass = true;
      if (pendingUrl) {
        window.location.href = pendingUrl;
        return;
      }
      window.location.reload();
    }

    function samePage(href) {
      try {
        var next = new URL(href, window.location.href);
        var cur = new URL(window.location.href);
        return next.origin === cur.origin && next.pathname === cur.pathname && next.search === cur.search;
      } catch (err) {
        return false;
      }
    }

    forms.forEach(function (form) {
      form.addEventListener('input', function (e) {
        if (e && e.isTrusted) userEdited = true;
      });
      form.addEventListener('change', function (e) {
        if (e && e.isTrusted) userEdited = true;
      });
    });
    refreshSnapshots();
    window.requestAnimationFrame(function () {
      refreshSnapshots();
    });
    document.addEventListener('cultour:form-ready', function () {
      armGuards();
    });
    window.setTimeout(armGuards, 1600);

    document.addEventListener('click', function (e) {
      var a = e.target.closest('a[href]');
      if (!a || bypass || !isDirty()) return;
      if (a.target === '_blank' || a.hasAttribute('download')) return;
      if (a.getAttribute('data-bs-toggle') === 'modal') return;
      if (a.closest('#unsavedChangesModal') || a.closest('.admin-biz-proof-overlay')) return;
      var href = a.getAttribute('href') || '';
      if (!href || href.charAt(0) === '#') return;
      try {
        var next = new URL(href, window.location.href);
        var cur = new URL(window.location.href);
        if (next.origin !== cur.origin) return;
        if (samePage(href)) return;
      } catch (err) {
        return;
      }
      e.preventDefault();
      openLeave(a.href);
    }, true);

    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (form.hasAttribute('data-unsaved-guard') || form.hasAttribute('data-unsaved-ignore')) {
        bypass = true;
      }
    }, true);

    var saveBtn = modalEl.querySelector('[data-unsaved-save]');
    var discardBtn = modalEl.querySelector('[data-unsaved-discard]');
    var keepBtn = modalEl.querySelector('[data-unsaved-keep]');
    if (keepBtn) {
      keepBtn.addEventListener('click', function () {
        pendingUrl = null;
        closeLeave();
      });
    }
    modalEl.addEventListener('hidden.bs.modal', function () {
      if (bypass) return;
      pendingUrl = null;
    });
    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        var form = dirtyForm();
        if (!form) {
          goPending();
          return;
        }
        if (typeof form.reportValidity === 'function' && !form.checkValidity()) {
          closeLeave();
          form.reportValidity();
          return;
        }
        bypass = true;
        var input = form.querySelector('input[name="return_to"]');
        if (!input) {
          input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'return_to';
          form.appendChild(input);
        }
        input.value = pendingUrl || '';
        form.submit();
      });
    }
    if (discardBtn) {
      discardBtn.addEventListener('click', function () {
        goPending();
      });
    }
  }

  /* ---------- Forms: loading state, friendly validation, accessibility ---------- */

  var liveRegion = null;
  function announce(msg) {
    if (!document.body) return;
    if (!liveRegion) {
      liveRegion = document.createElement('div');
      liveRegion.className = 'visually-hidden';
      liveRegion.setAttribute('aria-live', 'polite');
      liveRegion.setAttribute('aria-atomic', 'true');
      document.body.appendChild(liveRegion);
    }
    liveRegion.textContent = '';
    setTimeout(function () { liveRegion.textContent = msg; }, 50);
  }

  var topProgress = null;
  function showTopProgress(show) {
    if (!document.body) return;
    if (!topProgress) {
      topProgress = document.createElement('div');
      topProgress.className = 'ct-top-progress';
      topProgress.setAttribute('aria-hidden', 'true');
      topProgress.innerHTML = '<span class="ct-top-progress__bar"></span>';
      document.body.appendChild(topProgress);
    }
    topProgress.classList.toggle('is-active', show !== false);
  }

  function setButtonBusy(btn, busy, label) {
    if (!btn) return;
    var isInput = btn.tagName === 'INPUT';
    if (busy) {
      if (btn.getAttribute('data-ct-busy') === '1') return;
      btn.setAttribute('data-ct-busy', '1');
      btn._ctIdle = { html: btn.innerHTML, value: btn.value, disabled: btn.disabled, minWidth: btn.style.minWidth };
      if (btn.offsetWidth) btn.style.minWidth = btn.offsetWidth + 'px';
      btn.disabled = true;
      btn.classList.add('is-loading');
      btn.setAttribute('aria-busy', 'true');
      var text = label || btn.getAttribute('data-loading-text') || '';
      if (isInput) {
        if (text) btn.value = text;
        return;
      }
      var spinner = '<span class="spinner-border spinner-border-sm ct-btn-spinner" aria-hidden="true"></span>';
      var hasText = (btn.textContent || '').trim() !== '';
      if (text) {
        var span = document.createElement('span');
        span.textContent = text;
        btn.innerHTML = spinner + span.outerHTML;
      } else {
        btn.innerHTML = hasText ? spinner + btn.innerHTML : spinner;
      }
      return;
    }
    if (btn.getAttribute('data-ct-busy') !== '1') return;
    btn.removeAttribute('data-ct-busy');
    btn.classList.remove('is-loading');
    btn.removeAttribute('aria-busy');
    var idle = btn._ctIdle || {};
    if (isInput) {
      if (idle.value != null) btn.value = idle.value;
    } else if (idle.html != null) {
      btn.innerHTML = idle.html;
    }
    btn.disabled = !!idle.disabled;
    btn.style.minWidth = idle.minWidth || '';
    btn._ctIdle = null;
  }

  function submitButtonsOf(form) {
    return Array.prototype.filter.call(form.elements || [], function (el) {
      return el.type === 'submit' || el.type === 'image';
    });
  }

  function resetFormBusy(form) {
    if (!form || form.getAttribute('data-ct-submitting') !== '1') return;
    form.removeAttribute('data-ct-submitting');
    form.removeAttribute('aria-busy');
    form.classList.remove('ct-submitting');
    clearTimeout(form._ctBusyTimer);
    submitButtonsOf(form).forEach(function (b) { setButtonBusy(b, false); });
    if (!document.querySelector('form[data-ct-submitting="1"]')) showTopProgress(false);
  }

  function markFormBusy(form, submitter) {
    form.setAttribute('data-ct-submitting', '1');
    form.setAttribute('aria-busy', 'true');
    form.classList.add('ct-submitting');
    var buttons = submitButtonsOf(form);
    var main = (submitter && buttons.indexOf(submitter) !== -1) ? submitter : buttons[0];
    buttons.forEach(function (b) {
      if (b === main) {
        setButtonBusy(b, true);
      } else if (!b.disabled) {
        b._ctIdle = { html: b.innerHTML, value: b.value, disabled: false, minWidth: b.style.minWidth };
        b.setAttribute('data-ct-busy', '1');
        b.disabled = true;
      }
    });
    showTopProgress(true);
    announce('Submitting, please wait.');
    clearTimeout(form._ctBusyTimer);
    // Downloads and same-page responses never unload the page, so give the buttons back eventually.
    form._ctBusyTimer = setTimeout(function () { resetFormBusy(form); }, 30000);
  }

  function bindSubmitLoading() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (form.getAttribute('data-ct-submitting') === '1') {
        e.preventDefault();
        e.stopImmediatePropagation();
      }
    }, true);

    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      var submitter = e.submitter || null;
      // Disabling the button inside the submit event would drop its name/value from the POST.
      setTimeout(function () {
        if (e.defaultPrevented) return;
        if (form.hasAttribute('data-no-loading')) return;
        var target = (submitter && submitter.getAttribute('formtarget')) || form.getAttribute('target') || '';
        if (target && target !== '_self') return;
        markFormBusy(form, submitter);
      }, 0);
    });

    window.addEventListener('pageshow', function (e) {
      if (!e.persisted) return;
      document.querySelectorAll('form[data-ct-submitting="1"]').forEach(resetFormBusy);
      showTopProgress(false);
    });
  }

  function fieldLabel(el) {
    var text = '';
    if (el.labels && el.labels.length) text = el.labels[0].textContent;
    if (!text && el.getAttribute('aria-label')) text = el.getAttribute('aria-label');
    if (!text) {
      var box = el.closest('.mb-3, .mb-2, .form-group');
      var lab = box && box.querySelector('label, .form-label');
      if (lab) text = lab.textContent;
    }
    if (!text && el.getAttribute('placeholder')) text = el.getAttribute('placeholder');
    return String(text || '').replace(/\*/g, '').replace(/\((optional|required)\)/ig, '').replace(/\s+/g, ' ').trim();
  }

  function prettyDate(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(v || ''));
    if (!m) return v;
    var d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function friendlyMessage(el) {
    var v = el.validity;
    if (!v || v.valid) return '';
    var label = fieldLabel(el);
    var name = label || 'This field';
    var type = (el.type || '').toLowerCase();
    if (v.customError) return el.validationMessage;
    if (v.valueMissing) {
      if (type === 'checkbox') return 'Tick this box to continue.';
      if (type === 'radio') return 'Choose one of the options' + (label ? ' for ' + label : '') + '.';
      if (type === 'file') return 'Choose a file to upload' + (label ? ' for ' + label : '') + '.';
      if (el.tagName === 'SELECT') return 'Choose an option for ' + (label || 'this field') + '.';
      return name + ' is required.';
    }
    if (v.typeMismatch) {
      if (type === 'email') return 'Enter a complete email address, for example name@gmail.com.';
      if (type === 'url') return 'Enter a full web address that starts with https://';
      return 'Check the format of ' + name + '.';
    }
    if (v.badInput) return type === 'number' ? 'Enter numbers only for ' + name + '.' : 'Enter a valid value for ' + name + '.';
    if (v.tooShort) return name + ' needs at least ' + el.minLength + ' characters (you have ' + String(el.value || '').length + ').';
    if (v.tooLong) return name + ' can be at most ' + el.maxLength + ' characters.';
    if (v.rangeUnderflow) {
      if (type === 'date') return 'Choose a date on or after ' + prettyDate(el.min) + '.';
      if (type === 'time') return 'Choose a time at or after ' + el.min + '.';
      return name + ' must be ' + el.min + ' or more.';
    }
    if (v.rangeOverflow) {
      if (type === 'date') return 'Choose a date on or before ' + prettyDate(el.max) + '.';
      if (type === 'time') return 'Choose a time at or before ' + el.max + '.';
      return name + ' must be ' + el.max + ' or less.';
    }
    if (v.stepMismatch) {
      var step = String(el.step || '');
      return (step === '' || step === '1') ? name + ' must be a whole number.' : name + ' must go up in steps of ' + step + '.';
    }
    if (v.patternMismatch) return el.getAttribute('title') || ('Check the format of ' + name + '.');
    return el.validationMessage || ('Check ' + name + '.');
  }

  function errorAnchor(el) {
    return el.closest('.input-group, .select-chevron, .phone-input, .ph-search-select, .password-field, .form-check') || el;
  }

  var fieldErrSeq = 0;
  function showFieldError(el, msg) {
    var anchor = errorAnchor(el);
    var warn = anchor.nextElementSibling;
    if (!warn || !warn.classList.contains('ct-req-warn')) {
      warn = document.createElement('div');
      warn.className = 'form-text text-danger ct-req-warn';
      anchor.insertAdjacentElement('afterend', warn);
    }
    if (!warn.id) warn.id = 'ctFieldErr' + (++fieldErrSeq);
    warn.textContent = msg;
    warn.classList.remove('d-none');
    el.classList.add('is-invalid');
    el.setAttribute('aria-invalid', 'true');
    var described = (el.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
    if (described.indexOf(warn.id) === -1) {
      described.push(warn.id);
      el.setAttribute('aria-describedby', described.join(' '));
    }
  }

  function clearFieldError(el) {
    el.classList.remove('is-invalid');
    el.removeAttribute('aria-invalid');
    var anchor = errorAnchor(el);
    var warn = anchor.nextElementSibling;
    if (warn && warn.classList.contains('ct-req-warn')) warn.classList.add('d-none');
  }

  function liveValidateEnabled(form) {
    if (!form || form.hasAttribute('data-no-live-validate')) return false;
    if (form.hasAttribute('data-live-validate') || form.hasAttribute('data-required-check')) return true;
    return !form.noValidate;
  }

  function isControl(el) {
    return el && el.matches && el.matches('input, select, textarea') && el.type !== 'hidden' && el.type !== 'submit' && el.type !== 'button';
  }

  function bindFriendlyValidation() {
    var silent = false;
    var focusQueued = false;

    document.addEventListener('invalid', function (e) {
      var el = e.target;
      if (!isControl(el) || !el.form || !liveValidateEnabled(el.form)) return;
      e.preventDefault();
      showFieldError(el, friendlyMessage(el));
      if (silent || focusQueued) return;
      focusQueued = true;
      setTimeout(function () { focusQueued = false; }, 0);
      var anchor = errorAnchor(el);
      try { anchor.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (err) {}
      try { el.focus({ preventScroll: true }); } catch (err) {}
      announce(friendlyMessage(el));
    }, true);

    function recheck(el) {
      if (!el.willValidate) return;
      silent = true;
      var ok = el.checkValidity();
      silent = false;
      if (ok) clearFieldError(el);
    }

    ['input', 'change'].forEach(function (evt) {
      document.addEventListener(evt, function (e) {
        var el = e.target;
        if (!isControl(el) || !el.form || !liveValidateEnabled(el.form)) return;
        el.setAttribute('data-ct-touched', '1');
        if (el.getAttribute('aria-invalid') === 'true' || el.classList.contains('is-invalid')) recheck(el);
      }, true);
    });

    document.addEventListener('focusout', function (e) {
      var el = e.target;
      if (!isControl(el) || !el.form || !liveValidateEnabled(el.form)) return;
      if (el.getAttribute('data-ct-touched') !== '1') return;
      if (el.type === 'file' || el.type === 'radio' || el.type === 'checkbox') return;
      recheck(el);
    }, true);
  }

  var ctlSeq = 0;
  function enhanceAccessibility(root) {
    root = root || document;
    var CONTROL = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]), select, textarea';

    root.querySelectorAll('label.form-label:not([for])').forEach(function (lab) {
      if (lab.querySelector(CONTROL)) return;
      var ctl = null;
      var sib = lab.nextElementSibling;
      for (var i = 0; sib && i < 3 && !ctl; i++) {
        if (sib.matches('label')) break;
        ctl = sib.matches(CONTROL) ? sib : sib.querySelector(CONTROL);
        sib = sib.nextElementSibling;
      }
      if (!ctl || (ctl.labels && ctl.labels.length) || ctl.hasAttribute('aria-label')) return;
      if (!ctl.id) ctl.id = 'ctField' + (++ctlSeq);
      lab.htmlFor = ctl.id;
    });

    root.querySelectorAll('button, a.btn, [role="button"]').forEach(function (btn) {
      if (btn.hasAttribute('aria-label') || btn.hasAttribute('aria-labelledby')) return;
      if ((btn.textContent || '').trim() !== '') return;
      var name = btn.getAttribute('title') || btn.getAttribute('data-tip') || btn.getAttribute('data-bs-title') || '';
      if (name) btn.setAttribute('aria-label', name);
    });

    root.querySelectorAll('i.bi:not([aria-hidden]), i[class^="bi-"]:not([aria-hidden])').forEach(function (i) {
      i.setAttribute('aria-hidden', 'true');
    });

    root.querySelectorAll('.alert:not([role])').forEach(function (a) {
      a.setAttribute('role', a.matches('.alert-danger, .alert-warning') ? 'alert' : 'status');
    });
  }

  function bindSkipLink() {
    var link = document.querySelector('.skip-link');
    if (!link) return;
    var target = document.getElementById('main-content')
      || document.querySelector('main, .page-content, .tourist-content, .main-wrap, .auth-panel, .public-main');
    if (!target) {
      link.remove();
      return;
    }
    if (!target.id) target.id = 'main-content';
    link.setAttribute('href', '#' + target.id);
    if (!target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
    link.addEventListener('click', function () {
      setTimeout(function () {
        try { target.focus({ preventScroll: false }); } catch (err) {}
      }, 0);
    });
  }

  window.CulTourForms = {
    setBusy: setButtonBusy,
    reset: resetFormBusy,
    showError: showFieldError,
    clearError: clearFieldError,
    message: friendlyMessage,
    label: fieldLabel,
    announce: announce,
    enhance: enhanceAccessibility
  };

  function bindConfirmCard() {
    var wrap = null;
    var titleEl = null;
    var bodyEl = null;
    var okBtn = null;
    var pending = null;

    function ensure() {
      if (wrap) return;
      wrap = document.createElement('div');
      wrap.className = 'modal fade tour-end-modal tour-end-modal--form';
      wrap.id = 'ctConfirmModal';
      wrap.setAttribute('tabindex', '-1');
      wrap.setAttribute('aria-labelledby', 'ctConfirmTitle');
      wrap.setAttribute('aria-hidden', 'true');
      wrap.innerHTML =
        '<div class="modal-dialog modal-dialog-centered">' +
          '<div class="modal-content tour-end-card ct-confirm-card">' +
            '<div class="modal-header border-0 pb-1">' +
              '<div><div class="small text-muted">Please confirm</div>' +
              '<h2 class="h5 mb-0" id="ctConfirmTitle">Confirm</h2></div>' +
              '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '</div>' +
            '<div class="modal-body pt-2">' +
              '<p class="ct-confirm-card__body mb-0" id="ctConfirmBody"></p>' +
            '</div>' +
            '<div class="modal-footer border-0 pt-0">' +
              '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>' +
              '<button type="button" class="btn btn-ct" id="ctConfirmOk">Confirm</button>' +
            '</div>' +
          '</div>' +
        '</div>';
      document.body.appendChild(wrap);
      titleEl = wrap.querySelector('#ctConfirmTitle');
      bodyEl = wrap.querySelector('#ctConfirmBody');
      okBtn = wrap.querySelector('#ctConfirmOk');
      wrap.addEventListener('hidden.bs.modal', function () {
        pending = null;
      });
      okBtn.addEventListener('click', function () {
        var form = pending;
        pending = null;
        if (window.bootstrap && bootstrap.Modal) {
          var inst = bootstrap.Modal.getInstance(wrap);
          if (inst) inst.hide();
        }
        if (!form) return;
        form.setAttribute('data-ct-confirm-ok', '1');
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      });
    }

    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form || form.nodeName !== 'FORM') return;
      if (form.getAttribute('data-ct-confirm-ok') === '1') {
        form.removeAttribute('data-ct-confirm-ok');
        return;
      }
      var msg = form.getAttribute('data-ct-confirm');
      if (!msg) return;
      e.preventDefault();
      e.stopImmediatePropagation();
      ensure();
      pending = form;
      titleEl.textContent = form.getAttribute('data-ct-confirm-title') || 'Please confirm';
      bodyEl.textContent = msg;
      okBtn.textContent = form.getAttribute('data-ct-confirm-ok') || 'Confirm';
      if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(wrap).show();
      }
    }, true);
  }

  bindSubmitLoading();
  bindFriendlyValidation();
  bindConfirmCard();
  document.addEventListener('cultour:live-replaced', function (e) {
    enhanceAccessibility(e.target && e.target.querySelectorAll ? e.target : document);
  });

  function capNameStart(el) {
    if (!el || el.disabled || el.readOnly) return;
    var v = el.value || '';
    if (!v) return;
    var next = v.charAt(0).toUpperCase() + v.slice(1);
    if (next !== v) el.value = next;
  }

  function bindNameCaps(root) {
    var sel = [
      '.name-cap',
      'input[name="first_name"]',
      'input[name="middle_name"]',
      'input[name="last_name"]',
      'input[name="emergency_first_name"]',
      'input[name="emergency_middle_name"]',
      'input[name="emergency_last_name"]',
      'input[name="owner_first_name"]',
      'input[name="owner_middle_name"]',
      'input[name="owner_last_name"]',
      'input[name="representative_first_name"]',
      'input[name="representative_middle_name"]',
      'input[name="representative_last_name"]'
    ].join(',');
    (root || document).querySelectorAll(sel).forEach(function (el) {
      if (el.getAttribute('data-name-cap') === '1') return;
      el.setAttribute('data-name-cap', '1');
      capNameStart(el);
      el.addEventListener('input', function () {
        if ((el.value || '').length <= 1) capNameStart(el);
      });
      el.addEventListener('blur', function () { capNameStart(el); });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindNameCaps(document);
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('appSidebar');
    if (sidebarToggle && sidebar) {
      sidebarToggle.addEventListener('click', function () {
        sidebar.classList.toggle('show');
      });
    }

    // Eye icon: show / hide password on every password field
    document.querySelectorAll('input[type="password"]').forEach(function (input) {
      if (input.closest('.password-field') || input.hasAttribute('data-no-toggle')) return;
      const wrap = document.createElement('div');
      wrap.className = 'password-field';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'password-toggle';
      btn.setAttribute('aria-label', 'Show password');
      btn.setAttribute('title', 'Show password');
      btn.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i>';
      wrap.appendChild(btn);

      btn.addEventListener('mousedown', function (e) {
        // Keep focus where it is — don't blur the input (avoids password-rules pop).
        e.preventDefault();
      });
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.setAttribute('title', show ? 'Hide password' : 'Show password');
        btn.innerHTML = show
          ? '<i class="bi bi-eye-slash" aria-hidden="true"></i>'
          : '<i class="bi bi-eye" aria-hidden="true"></i>';
      });
    });

    // Rotating chevron on wrapped native selects
    document.querySelectorAll('.select-chevron > select').forEach(function (sel) {
      if (sel.getAttribute('data-chevron-bound') === '1') return;
      sel.setAttribute('data-chevron-bound', '1');
      const wrap = sel.closest('.select-chevron');
      if (!wrap) return;
      sel.addEventListener('mousedown', function () {
        wrap.classList.add('is-open');
      });
      sel.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
          wrap.classList.add('is-open');
        }
      });
      sel.addEventListener('blur', function () {
        wrap.classList.remove('is-open');
      });
      sel.addEventListener('change', function () {
        wrap.classList.remove('is-open');
      });
    });

    const cfg = window.CulTour || {};
    if (cfg.liveUnread && cfg.appUrl) {
      const url = cfg.appUrl + '/api/chat/unread.php';
      function pollUnread() {
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) {
            return r.json();
          })
          .then(applyUnread)
          .catch(function () {});
      }
      pollUnread();
      setInterval(pollUnread, 2000);
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) pollUnread();
      });
    }

    // Opening the bell marks notifications read and clears unread highlights
    const notifBell = document.getElementById('notifBellBtn');
    if (notifBell && cfg.appUrl) {
      notifBell.addEventListener('show.bs.dropdown', function () {
        const badge = document.getElementById('navNotifUnread');
        const hasUnread =
          (badge && !badge.hidden && (Number(badge.textContent) > 0 || /9\+/.test(badge.textContent || ''))) ||
          document.querySelector('.notif-panel__item.is-unread');
        if (!hasUnread) return;

        applyUnread({ ok: true, notifications: 0 });
        document.querySelectorAll('.notif-panel__item.is-unread').forEach(function (el) {
          el.classList.remove('is-unread');
        });
        const sub = document.querySelector('.notif-panel__head .small.text-muted');
        if (sub) sub.textContent = 'No new alerts';
        const markAll = document.querySelector('.notif-panel__head a[href*="read_all"]');
        if (markAll) markAll.remove();

        const body = new URLSearchParams();
        body.set('all', '1');
        if (cfg.csrf) body.set('csrf_token', cfg.csrf);
        fetch(cfg.appUrl + '/api/notifications/read.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
          cache: 'no-store',
        }).catch(function () {});
      });
    }

    function bindAvatarPreviews() {
      document.querySelectorAll('[data-avatar-preview]').forEach(function (wrap) {
        if (wrap._avatarPreviewBound) return;
        wrap._avatarPreviewBound = true;
        const input = wrap.querySelector('input[type="file"][name="avatar"], input[type="file"][data-avatar-input]');
        const frame = wrap.querySelector('[data-avatar-frame]');
        if (!input || !frame) return;
        const original = frame.innerHTML;
        let objectUrl = null;
        input.addEventListener('change', function () {
          if (objectUrl) {
            try {
              URL.revokeObjectURL(objectUrl);
            } catch (e) {}
            objectUrl = null;
          }
          const file = input.files && input.files[0];
          if (!file || !file.type || file.type.indexOf('image/') !== 0) {
            frame.innerHTML = original;
            return;
          }
          objectUrl = URL.createObjectURL(file);
          frame.innerHTML = '<img src="' + objectUrl + '" alt="Preview" class="avatar-preview__img">';
        });
      });
    }

    bindAvatarPreviews();
    bindUnsavedGuards();
    enhanceAccessibility(document);
    bindSkipLink();
    document.querySelectorAll('[data-autohide]').forEach(function (el) {
      window.setTimeout(function () {
        el.style.transition = 'opacity .4s ease';
        el.style.opacity = '0';
        window.setTimeout(function () { el.remove(); }, 400);
      }, 4000);
    });

    function bindAnnCarousels(scope) {
      const rootEl = scope && scope.querySelectorAll ? scope : document;
      rootEl.querySelectorAll('[data-ann-carousel]').forEach(function (root) {
        if (root._annBound) return;
        const slides = Array.prototype.slice.call(root.querySelectorAll('.ann-carousel__slide'));
        if (slides.length < 2) return;
        root._annBound = true;
        const dots = Array.prototype.slice.call(root.querySelectorAll('[data-ann-dot]'));
        const pauseBtn = root.querySelector('[data-ann-pause]');
        let index = slides.findIndex(function (s) {
          return s.classList.contains('is-active');
        });
        if (index < 0) index = 0;
        let paused = false;
        let timer = null;

        function go(to) {
          index = ((to % slides.length) + slides.length) % slides.length;
          slides.forEach(function (s, i) {
            s.classList.toggle('is-active', i === index);
          });
          dots.forEach(function (d, i) {
            d.classList.toggle('is-active', i === index);
          });
        }

        function tick() {
          if (!paused) go(index + 1);
        }

        function start() {
          stop();
          timer = setInterval(tick, 5000);
        }

        function stop() {
          if (timer) {
            clearInterval(timer);
            timer = null;
          }
        }

        root._annStop = stop;

        dots.forEach(function (dot) {
          dot.addEventListener('click', function () {
            const to = Number(dot.getAttribute('data-ann-dot'));
            if (!Number.isNaN(to)) go(to);
            start();
          });
        });

        if (pauseBtn) {
          pauseBtn.addEventListener('click', function () {
            paused = !paused;
            pauseBtn.setAttribute('aria-label', paused ? 'Play slideshow' : 'Pause slideshow');
            pauseBtn.setAttribute('title', paused ? 'Play' : 'Pause');
            pauseBtn.innerHTML = paused
              ? '<i class="bi bi-play-fill"></i>'
              : '<i class="bi bi-pause-fill"></i>';
            if (!paused) start();
          });
        }

        root.addEventListener('mouseenter', stop);
        root.addEventListener('mouseleave', function () {
          if (!paused) start();
        });

        start();
      });
    }
    window.CulTourBindAnnCarousels = bindAnnCarousels;
    bindAnnCarousels(document);

    startAccountLive();
    startAppealsLive();
    startRegistrationsLive();

    document.querySelectorAll('.suffix-picker').forEach(function (picker) {
      if (picker.getAttribute('data-bound') === '1') return;
      picker.setAttribute('data-bound', '1');
      var toggle = picker.querySelector('.suffix-picker__toggle');
      var menu = picker.querySelector('.suffix-picker__menu');
      var choice = picker.querySelector('.suffix-picker__choice');
      var label = picker.querySelector('.suffix-picker__label');
      var specify = picker.querySelector('.suffix-picker__specify');
      var other = picker.querySelector('.suffix-picker__other');
      var emptyLabel = picker.getAttribute('data-empty-label') || 'None';
      var labels = {};
      picker.querySelectorAll('.suffix-picker__option').forEach(function (btn) {
        labels[btn.getAttribute('data-value') || ''] = (btn.textContent || '').trim();
      });
      labels.none = labels.none || 'None';
      labels.other = labels.other || 'Other';
      labels[''] = emptyLabel;
      function setOpen(open) {
        if (!menu || !toggle) return;
        menu.classList.toggle('d-none', !open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        picker.classList.toggle('is-open', open);
      }
      if (toggle) {
        toggle.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          setOpen(menu.classList.contains('d-none'));
        });
      }
      picker.querySelectorAll('.suffix-picker__option').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var value = btn.getAttribute('data-value') || '';
          if (choice) choice.value = value;
          picker.querySelectorAll('.suffix-picker__option').forEach(function (b) {
            b.classList.toggle('is-active', b === btn);
          });
          if (value === 'other') {
            if (specify) specify.classList.remove('d-none');
            if (label) label.textContent = (other && other.value.trim()) ? other.value.trim() : 'Other';
            setOpen(true);
            if (other) other.focus();
          } else {
            if (specify) specify.classList.add('d-none');
            if (other) other.value = '';
            if (label) label.textContent = labels[value] || emptyLabel;
            setOpen(false);
          }
        });
      });
      if (other) {
        other.addEventListener('click', function (e) { e.stopPropagation(); });
        other.addEventListener('input', function () {
          if (choice && choice.value === 'other' && label) label.textContent = other.value.trim() || 'Other';
        });
      }
    });
    document.addEventListener('click', function (e) {
      document.querySelectorAll('.suffix-picker.is-open').forEach(function (picker) {
        if (!picker.contains(e.target)) {
          var menu = picker.querySelector('.suffix-picker__menu');
          var toggle = picker.querySelector('.suffix-picker__toggle');
          if (menu) menu.classList.add('d-none');
          if (toggle) toggle.setAttribute('aria-expanded', 'false');
          picker.classList.remove('is-open');
        }
      });
    });

    document.querySelectorAll('[data-avail-day-toggle]').forEach(function (cb) {
      var row = cb.closest('.guide-profile-avail__row');
      if (!row) return;
      var allDay = row.querySelector('[data-avail-24h]');
      function sync() {
        var on = !!cb.checked;
        var is24 = on && !!(allDay && allDay.checked);
        row.classList.toggle('is-on', on);
        row.classList.toggle('is-24h', is24);
        if (allDay && !on) allDay.checked = false;
        row.querySelectorAll('input[type="time"]').forEach(function (inp) {
          var timesOn = on && !is24;
          inp.disabled = !timesOn;
          if (timesOn) inp.setAttribute('required', 'required');
          else inp.removeAttribute('required');
        });
      }
      cb.addEventListener('change', sync);
      if (allDay) {
        allDay.addEventListener('change', function () {
          if (allDay.checked) cb.checked = true;
          sync();
        });
      }
      sync();
    });

    document.querySelectorAll('[data-visit-duration]').forEach(bindVisitDuration);
  });

  var VISIT_MAX_HOURS = 12;

  function visitClockFormat(raw) {
    var digits = String(raw || '').replace(/\D/g, '').slice(0, 4);
    if (!digits) return '';
    var d0 = digits.charAt(0);
    var hourStr;
    var rest;
    if (d0 === '0') {
      if (digits.length === 1) return '0';
      hourStr = digits.charAt(1);
      rest = digits.slice(2);
    } else if (d0 === '1') {
      if (digits.length === 1) return '1';
      var d1 = digits.charAt(1);
      if (d1 === '0' || d1 === '1' || d1 === '2') {
        hourStr = '1' + d1;
        rest = digits.slice(2);
      } else {
        hourStr = '1';
        rest = digits.slice(1);
      }
    } else {
      hourStr = d0;
      rest = digits.slice(1);
    }
    var h = Math.min(VISIT_MAX_HOURS, parseInt(hourStr, 10) || 0);
    var minStr = rest.slice(0, 2);
    if (minStr.length >= 1 && minStr.charAt(0) > '5') minStr = '5' + minStr.slice(1);
    if (h === VISIT_MAX_HOURS && minStr !== '' && parseInt(minStr, 10) > 0) minStr = '00';
    if (!minStr) return (d0 === '0' || h >= 10) ? String(h).padStart(2, '0') : String(h);
    return String(h).padStart(2, '0') + ':' + minStr;
  }

  function visitClockPad(raw) {
    var m = String(visitClockFormat(raw) || '').match(/^(\d{1,2})(?::(\d{1,2}))?$/);
    if (!m) return '';
    var h = Math.min(VISIT_MAX_HOURS, parseInt(m[1], 10) || 0);
    var min = m[2] !== undefined ? Math.min(59, parseInt(m[2], 10) || 0) : 0;
    if (h === VISIT_MAX_HOURS) min = 0;
    return String(h).padStart(2, '0') + ':' + String(min).padStart(2, '0');
  }

  function visitClockMinutes(value) {
    var m = String(value || '').trim().match(/^(\d{1,2}):(\d{2})$/);
    if (!m) return NaN;
    return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
  }

  function bindVisitDuration(root) {
    var clock = root.querySelector('[data-visit-duration-clock]');
    var noLimit = root.querySelector('[data-visit-no-limit]');
    var warn = root.querySelector('[data-visit-duration-warn]');
    if (!clock || !noLimit) return;
    var wrap = clock.closest('.itin-visit-clock-wrap');
    var unit = wrap ? wrap.querySelector('[data-itin-visit-unit]') : null;
    var lastValue = clock.value || '01:00';

    function paintUnit() {
      var raw = String(clock.value || '').trim();
      var label = '';
      if (raw && !clock.disabled) {
        var h = parseInt(raw, 10) || 0;
        label = h < 1 ? 'mins' : (h === 1 ? 'hour' : 'hours');
      }
      if (unit) unit.textContent = label;
      if (wrap) wrap.classList.toggle('is-empty', label === '');
    }
    function setWarn(msg) {
      if (!warn) return;
      warn.textContent = msg || '';
      warn.classList.toggle('d-none', !msg);
      clock.classList.toggle('is-invalid', !!msg);
    }
    function syncNoLimit() {
      var off = !!noLimit.checked;
      if (off) {
        if (clock.value) lastValue = clock.value;
        clock.value = '';
        setWarn('');
      } else if (!clock.value) {
        clock.value = lastValue;
      }
      clock.disabled = off;
      root.classList.toggle('is-no-limit', off);
      paintUnit();
    }

    clock.addEventListener('input', function () {
      var f = visitClockFormat(clock.value);
      if (f !== clock.value) clock.value = f;
      setWarn('');
      paintUnit();
    });
    clock.addEventListener('blur', function () {
      if (String(clock.value || '').trim()) clock.value = visitClockPad(clock.value);
      paintUnit();
    });
    noLimit.addEventListener('change', syncNoLimit);
    syncNoLimit();

    var form = clock.form || root.closest('form');
    if (form) {
      form.addEventListener('submit', function (e) {
        if (noLimit.checked) return;
        if (String(clock.value || '').trim()) clock.value = visitClockPad(clock.value);
        var mins = visitClockMinutes(clock.value);
        var msg = '';
        if (!Number.isFinite(mins)) msg = 'Enter a visit duration (HH:MM) or check No limit.';
        else if (mins < 15) msg = 'Visit duration must be at least 15 minutes.';
        else if (mins > VISIT_MAX_HOURS * 60) msg = 'Visit duration can be up to ' + VISIT_MAX_HOURS + ' hours.';
        if (msg) {
          e.preventDefault();
          e.stopImmediatePropagation();
          setWarn(msg);
          clock.focus();
        }
      });
    }
  }

  document.addEventListener('input', function (e) {
    var el = e.target;
    if (el && el.classList && el.classList.contains('is-incomplete') && String(el.value || '').trim() !== '') {
      el.classList.remove('is-incomplete');
    }
    if (!el || !el.closest || !el.closest('.phone-input')) return;
    var digits = String(el.value || '').replace(/\D/g, '');
    if (digits.indexOf('63') === 0 && digits.length > 10) digits = digits.slice(2);
    digits = digits.replace(/^0+/, '').slice(0, 10);
    if (el.value !== digits) el.value = digits;
  });

  document.addEventListener('change', function (e) {
    var input = e.target;
    if (input && input.type === 'file' && input.files && input.files.length) {
      var block = input.closest('.is-incomplete');
      if (block) {
        var group = block.getAttribute('data-incomplete-group');
        if (group) {
          document.querySelectorAll('[data-incomplete-group="' + group + '"]').forEach(function (node) {
            node.classList.remove('is-incomplete');
          });
        } else {
          block.classList.remove('is-incomplete');
        }
      }
    }
    if (input && input.matches && input.matches('[data-avail-day-toggle]') && input.checked) {
      var avail = input.closest('.guide-profile-avail');
      if (avail) avail.classList.remove('is-incomplete');
    }
    if (!input || input.type !== 'file' || !input.closest || !input.closest('.stop-checkin__form')) {
      return;
    }
    var form = input.form || input.closest('form');
    var img = form ? form.querySelector('[data-preview]') : null;
    if (!img) return;
    if (input.files && input.files[0]) {
      img.src = URL.createObjectURL(input.files[0]);
      img.hidden = false;
    } else {
      img.removeAttribute('src');
      img.hidden = true;
    }
  });

  function startNavProgress() {
    try { sessionStorage.setItem('ctNavLoad', '1'); } catch (err) {}
    showTopProgress(true);
  }
  function finishNavProgress() {
    try { sessionStorage.removeItem('ctNavLoad'); } catch (err) {}
    if (!document.querySelector('form[data-ct-submitting="1"]')) showTopProgress(false);
  }
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest('a');
    if (!a) return;
    if (!a.closest('.tourist-topnav, .tourist-bottomnav, .sidebar')) return;
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#' || a.target === '_blank' || a.hasAttribute('download')) return;
    if (a.hasAttribute('data-bs-toggle')) return;
    try {
      var next = new URL(a.href, location.href);
      if (next.origin === location.origin && next.pathname === location.pathname && next.search === location.search) return;
    } catch (err) {}
    startNavProgress();
  });
  try {
    if (sessionStorage.getItem('ctNavLoad') === '1') {
      showTopProgress(true);
      if (document.readyState === 'complete') {
        finishNavProgress();
      } else {
        window.addEventListener('load', finishNavProgress);
        window.setTimeout(finishNavProgress, 8000);
      }
    }
  } catch (err) {}
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) finishNavProgress();
  });
})();
