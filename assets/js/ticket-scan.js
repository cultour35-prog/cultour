/**
 * Meetup check-in: scan the tourist ticket QR and reject tickets for a different tour.
 */
(function () {
  var overlay = null;
  var stream = null;
  var timer = null;
  var activeForm = null;
  var canvas = null;
  var ctx = null;
  var jsQrReady = null;
  var openedAt = 0;
  var busy = false;
  var lastTry = 0;

  function parseTicket(raw) {
    raw = String(raw || '').trim();
    var id = 0;
    var code = '';
    var urlMatch = raw.match(/ticket-scan\.php\?([^#\s]+)/i);
    var query = '';
    if (urlMatch) query = urlMatch[1];
    else {
      try {
        var u = new URL(raw);
        query = u.search || '';
        if (query.charAt(0) === '?') query = query.slice(1);
      } catch (e) {}
    }
    if (query) {
      var params = new URLSearchParams(query);
      id = parseInt(params.get('id') || '0', 10) || 0;
      code = String(params.get('code') || '').toUpperCase();
    }
    if (!code) {
      var m = raw.match(/\b(CT[A-Z0-9]{6,})\b/i);
      if (m) code = m[1].toUpperCase();
    }
    return { id: id, code: code, raw: raw };
  }

  function setStatus(form, ok, message) {
    var el = form.querySelector('[data-ticket-status]');
    if (!el) return;
    el.textContent = message;
    el.className = 'small mb-2 ' + (ok ? 'text-success' : 'text-danger');
  }

  function setOverlayStatus(ok, message) {
    if (!overlay) return;
    var el = overlay.querySelector('[data-scan-live]');
    if (!el) return;
    el.textContent = message;
    el.className = 'ticket-scan-overlay__live' + (ok === true ? ' is-ok' : ok === false ? ' is-bad' : '');
    overlay.classList.toggle('is-ok', ok === true);
    overlay.classList.toggle('is-bad', ok === false);
  }

  function applyScan(form, raw) {
    var wantId = parseInt(form.getAttribute('data-booking-id') || '0', 10) || 0;
    var wantCode = String(form.getAttribute('data-booking-code') || '').toUpperCase();
    var parsed = parseTicket(raw);
    var hidden = form.querySelector('[data-ticket-scan]');
    if (!parsed.id && !parsed.code) {
      if (hidden) hidden.value = '';
      setStatus(form, false, 'This is not a CulTour ticket.');
      return false;
    }
    var idOk = !parsed.id || parsed.id === wantId;
    var codeOk = !parsed.code || parsed.code === wantCode;
    var matched = (parsed.id === wantId && wantId > 0) || (parsed.code === wantCode && wantCode !== '');
    if (!idOk || !codeOk || !matched) {
      if (hidden) hidden.value = '';
      setStatus(form, false, 'This ticket is not for this tour.');
      return false;
    }
    if (hidden) hidden.value = raw;
    setStatus(form, true, 'Ticket matched ' + wantCode + '.');
    return true;
  }

  function stopCamera() {
    busy = false;
    if (timer) {
      cancelAnimationFrame(timer);
      timer = null;
    }
    if (stream) {
      stream.getTracks().forEach(function (t) { t.stop(); });
      stream = null;
    }
    if (overlay) {
      overlay.remove();
      overlay = null;
    }
    activeForm = null;
    canvas = null;
    ctx = null;
  }

  function loadJsQr() {
    if (window.jsQR) return Promise.resolve();
    if (jsQrReady) return jsQrReady;
    var base = (window.CulTour && CulTour.appUrl) ? String(CulTour.appUrl).replace(/\/$/, '') : '';
    jsQrReady = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = base + '/assets/js/jsQR.min.js';
      s.onload = function () { resolve(); };
      s.onerror = function () { reject(new Error('jsQR')); };
      document.head.appendChild(s);
    });
    return jsQrReady;
  }

  function readFrame(video) {
    if (!overlay) return;
    timer = requestAnimationFrame(function () { readFrame(video); });
    if (busy || !video || video.readyState < 2 || !activeForm || !window.jsQR) return;
    var now = Date.now();
    if (now - lastTry < 120) return;
    lastTry = now;

    var vw = video.videoWidth || 0;
    var vh = video.videoHeight || 0;
    if (vw < 40 || vh < 40) return;

    var maxSide = 480;
    var scale = Math.min(1, maxSide / Math.max(vw, vh));
    var w = Math.max(40, Math.round(vw * scale));
    var h = Math.max(40, Math.round(vh * scale));
    if (!canvas) {
      canvas = document.createElement('canvas');
      ctx = canvas.getContext('2d', { willReadFrequently: true });
    }
    canvas.width = w;
    canvas.height = h;
    ctx.drawImage(video, 0, 0, w, h);
    var img = ctx.getImageData(0, 0, w, h);
    var result = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' });
    if (!result || !result.data) return;

    busy = true;
    var ok = applyScan(activeForm, result.data);
    if (ok) {
      setOverlayStatus(true, 'Ticket matched. You can take the meetup photo.');
      setTimeout(stopCamera, 900);
    } else {
      setOverlayStatus(false, 'This ticket is not for this tour. Scan the ticket for this booking.');
      setTimeout(function () {
        busy = false;
        if (overlay) setOverlayStatus(null, 'Hold the QR inside the frame');
      }, 1400);
    }
  }

  function openStream() {
    var videoFn = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
    return videoFn({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false }).catch(function () {
      return videoFn({ video: true, audio: false });
    });
  }

  function startCamera(form) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setStatus(form, false, 'Camera is not available. Type the booking code instead.');
      return;
    }
    stopCamera();
    activeForm = form;
    openedAt = Date.now();
    overlay = document.createElement('div');
    overlay.className = 'ticket-scan-overlay';
    overlay.innerHTML = '<div class="ticket-scan-overlay__card">'
      + '<div class="fw-semibold mb-1">Scan tourist ticket</div>'
      + '<p class="small text-muted mb-2">Hold the ticket QR inside the frame.</p>'
      + '<div class="ticket-scan-view">'
      + '<video playsinline autoplay muted></video>'
      + '<div class="ticket-scan-view__frame" aria-hidden="true">'
      + '<span></span><span></span><span></span><span></span>'
      + '<i class="ticket-scan-view__laser"></i>'
      + '</div>'
      + '</div>'
      + '<div class="ticket-scan-overlay__live" data-scan-live>Scanning…</div>'
      + '<button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-ticket-scan-close>Cancel</button>'
      + '</div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('click', function (e) {
      if (Date.now() - openedAt < 400) return;
      if (e.target === overlay || e.target.closest('[data-ticket-scan-close]')) stopCamera();
    });
    var video = overlay.querySelector('video');
    loadJsQr()
      .then(function () { return openStream(); })
      .then(function (s) {
        if (!overlay) {
          s.getTracks().forEach(function (t) { t.stop(); });
          return;
        }
        stream = s;
        video.srcObject = stream;
        video.setAttribute('playsinline', 'true');
        return video.play();
      })
      .then(function () {
        if (!overlay) return;
        setOverlayStatus(null, 'Hold the QR inside the frame');
        timer = requestAnimationFrame(function () { readFrame(video); });
      })
      .catch(function () {
        setStatus(form, false, 'Could not open the camera. Allow camera access, or type the booking code instead.');
        stopCamera();
      });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-ticket-scan-start]');
    if (!btn) return;
    e.preventDefault();
    var form = btn.closest('[data-meetup-ticket]');
    if (form) startCamera(form);
  });

  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el || !el.matches || !el.matches('[data-ticket-code]')) return;
    var form = el.closest('[data-meetup-ticket]');
    if (!form) return;
    var raw = el.value.trim();
    if (raw.length < 8) {
      var hidden = form.querySelector('[data-ticket-scan]');
      if (hidden) hidden.value = '';
      setStatus(form, false, '');
      return;
    }
    applyScan(form, raw);
  });

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches('[data-meetup-ticket]')) return;
    var hidden = form.querySelector('[data-ticket-scan]');
    var typed = form.querySelector('[data-ticket-code]');
    var raw = (hidden && hidden.value) ? hidden.value : (typed ? typed.value : '');
    if (!applyScan(form, raw)) {
      e.preventDefault();
    }
  });
})();
