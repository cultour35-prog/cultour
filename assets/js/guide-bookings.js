/**
 * CulTour — live tour-guide bookings board (poll)
 */
(function () {
  function showToast(message) {
    var host = document.getElementById('guideLiveToastHost');
    if (!host) {
      host = document.createElement('div');
      host.id = 'guideLiveToastHost';
      host.className = 'guide-live-toast-host';
      document.body.appendChild(host);
    }
    var el = document.createElement('div');
    el.className = 'guide-live-toast';
    el.setAttribute('role', 'status');
    el.innerHTML =
      '<strong>New booking</strong><span>' +
      String(message || 'A tourist just requested a tour.') +
      '</span>';
    host.appendChild(el);
    requestAnimationFrame(function () {
      el.classList.add('is-show');
    });
    setTimeout(function () {
      el.classList.remove('is-show');
      setTimeout(function () {
        el.remove();
      }, 320);
    }, 4500);
    try {
      if (navigator.vibrate) navigator.vibrate(40);
    } catch (e) {}
  }

  function setPendingDot(count) {
    var n = Number(count) || 0;
    var el = document.getElementById('navBookingsPending');
    if (!el) return;
    if (n > 0) {
      el.hidden = false;
      el.removeAttribute('hidden');
    } else {
      el.hidden = true;
      el.setAttribute('hidden', '');
    }
  }

  function initGuideBookingsLive(cfg) {
    var root = document.getElementById('guideBookingsLive');
    if (!root || !cfg || !cfg.appUrl) return;

    var version = root.getAttribute('data-version') || '';
    var lastNewest = Number(root.getAttribute('data-newest-id') || 0) || 0;
    var busy = false;
    var url = cfg.appUrl + '/api/bookings/guide_live.php';
    if (window.CulTourLive) window.CulTourLive.bindSubmitGuard(root);

    function poll() {
      if (busy || document.hidden) return;
      busy = true;
      fetch(url + '?since=' + encodeURIComponent(version), {
        credentials: 'same-origin',
        cache: 'no-store',
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) return;
          setPendingDot(data.pending);
          if (!data.changed) return;

          var newest = Number(data.newest_id) || 0;
          var isNewRequest = newest > lastNewest && (Number(data.pending) || 0) > 0;
          if (data.html != null) {
            if (window.CulTourLive && window.CulTourLive.shouldDefer(root)) {
              return;
            }
            var snap = window.CulTourLive ? window.CulTourLive.capture(root) : null;
            if (window.CulTourLive) {
              window.CulTourLive.applyHtml(root, data.html, snap);
            } else {
              root.innerHTML = data.html;
            }
          }
          version = data.version || version;
          root.setAttribute('data-version', version);
          root.setAttribute('data-newest-id', String(newest));
          root.setAttribute('data-pending', String(data.pending || 0));
          if (isNewRequest) {
            showToast('A tourist booked you — review the new request below.');
            var firstNew = root.querySelector('[data-booking-new="1"]');
            if (firstNew) {
              firstNew.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
          }
          lastNewest = Math.max(lastNewest, newest);
        })
        .catch(function () {})
        .finally(function () {
          busy = false;
        });
    }

    poll();
    setInterval(poll, 2000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });
  }

  window.CulTourGuideBookings = { init: initGuideBookingsLive };

  document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('guideBookingsLive');
    if (!root) return;
    initGuideBookingsLive(window.CulTour || {});
  });
})();
