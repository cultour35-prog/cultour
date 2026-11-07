/**
 * One-time walkthrough for tourist, tour guide, and business shells.
 * Replay from the How to use button.
 */
(function () {
  var SPOT_PAD = 8;
  var STEPS = {
    tourist: [
      { title: 'Welcome', body: 'This is your CulTour home. Find places, plan a tour, and book a guide.', target: null },
      { title: 'Dashboard', body: 'Your home. Places of interest and your trip summary start here.', target: '[data-app-tour="dashboard"]' },
      { title: 'Map', body: 'See destinations and businesses on the map.', target: '[data-app-tour="map"]' },
      { title: 'Destinations', body: 'Browse places of interest in Ormoc.', target: '[data-app-tour="destinations"]', fallback: '[data-app-tour-fallback~="destinations"]' },
      { title: 'Businesses', body: 'See shops, food, and other local businesses.', target: '[data-app-tour="businesses"]', fallback: '[data-app-tour-fallback~="businesses"]' },
      { title: 'Itineraries', body: 'Build a tour, pick a guide, and pay.', target: '[data-app-tour="itineraries"]' },
      { title: 'Tour package', body: 'Browse packages uploaded by tour and travel businesses.', target: '[data-app-tour="packages"]' },
      { title: 'Bookings', body: 'After you pay, the guide still accepts the tour. Your ticket is here once they do.', target: '[data-app-tour="bookings"]' },
      { title: 'How to use', body: 'Open this walkthrough again any time with this button.', target: '[data-app-tour="help"]' },
      { title: 'Messages', body: 'Chat with your tour guide from here.', target: '[data-app-tour="chat"]' },
      { title: 'Favorites', body: 'Save places you want to come back to.', target: '[data-app-tour="favorites"]' }
    ],
    guide: [
      { title: 'Welcome', body: 'Paid tours stay pending until you accept them. They are not added to your schedule automatically.', target: null },
      { title: 'Dashboard', body: 'Your home. Upcoming tours and requests show here.', target: '[data-app-tour="dashboard"]' },
      { title: 'Schedule', body: 'Pending requests stay yellow. Accepted tours turn green.', target: '[data-app-tour="schedule"]' },
      { title: 'Check-in', body: 'On a tour day, check in at each stop with a photo.', target: '[data-app-tour="checkin"]' },
      { title: 'Bookings', body: 'Accept or decline a paid request. Declining a paid tour refunds the tourist.', target: '[data-app-tour="bookings"]' },
      { title: 'Availability', body: 'Set your weekly hours so tourists can book you.', target: '[data-app-tour="availability"]' },
      { title: 'Tours', body: 'Build suggested tours that tourists can copy.', target: '[data-app-tour="itineraries"]' },
      { title: 'Ratings', body: 'See what tourists said after a tour.', target: '[data-app-tour="ratings"]' },
      { title: 'How to use', body: 'Open this walkthrough again any time with this button.', target: '[data-app-tour="help"]' },
      { title: 'Messages', body: 'Chat with tourists from here.', target: '[data-app-tour="chat"]' }
    ],
    business: [
      { title: 'Welcome', body: 'This is where you keep your Ormoc listing ready for visitors.', target: null },
      { title: 'Dashboard', body: 'Your home. Your listing and location start here.', target: '[data-app-tour="dashboard"]' },
      { title: 'Listing', body: 'Add what your business offers.', target: '[data-app-tour="services"]' },
      { title: 'Photos', body: 'Upload photos so tourists can see the place.', target: '[data-app-tour="photos"]' },
      { title: 'Hours', body: 'Set when you are open.', target: '[data-app-tour="hours"]' },
      { title: 'How to use', body: 'Open this walkthrough again any time with this button.', target: '[data-app-tour="help"]' },
      { title: 'Messages', body: 'Reply to tourists from here.', target: '[data-app-tour="chat"]' }
    ]
  };

  var host = null;
  var kind = '';
  var steps = [];
  var storageKey = '';
  var root = null;
  var dimEl = null;
  var spotEl = null;
  var cardEl = null;
  var stepEl = null;
  var titleEl = null;
  var bodyEl = null;
  var backBtn = null;
  var nextBtn = null;
  var active = false;
  var stepIndex = 0;
  var visible = [];

  function bootHost() {
    host = document.querySelector('[data-app-tutorial]');
    if (!host) return false;
    kind = host.getAttribute('data-app-tutorial') || '';
    steps = (STEPS[kind] || []).slice();
    if (kind === 'business') {
      var catalog = host.getAttribute('data-catalog-label') || '';
      if (catalog) {
        steps = steps.map(function (step) {
          if (step.target !== '[data-app-tour="services"]') return step;
          return {
            title: catalog,
            body: 'Add your ' + catalog.toLowerCase() + '.',
            target: step.target
          };
        });
      }
    }
    storageKey = 'cultour-app-tutorial-' + kind + '-v1';
    return steps.length > 0;
  }

  function userId() {
    return host ? String(host.getAttribute('data-app-tutorial-user') || '') : '';
  }

  function loadStore() {
    try {
      var raw = localStorage.getItem(storageKey);
      var data = raw ? JSON.parse(raw) : {};
      return data && typeof data === 'object' && !Array.isArray(data) ? data : {};
    } catch (e) {
      return {};
    }
  }

  function isComplete() {
    var uid = userId();
    if (!uid) return false;
    var row = loadStore()[uid];
    return !!(row && row.done);
  }

  function markComplete() {
    var uid = userId();
    if (!uid) return;
    try {
      var data = loadStore();
      data[uid] = { done: true };
      localStorage.setItem(storageKey, JSON.stringify(data));
    } catch (e) {}
  }

  function isVisibleBox(el) {
    if (!el || !el.getBoundingClientRect) return false;
    var r = el.getBoundingClientRect();
    return r.width > 8 && r.height > 8;
  }

  function firstVisible(selector) {
    if (!selector) return null;
    var nodes = document.querySelectorAll(selector);
    var i;
    for (i = 0; i < nodes.length; i++) {
      if (isVisibleBox(nodes[i])) return nodes[i];
    }
    return null;
  }

  function stepTarget(step) {
    if (!step || !step.target) return null;
    return firstVisible(step.target) || firstVisible(step.fallback);
  }

  function rebuildVisible() {
    visible = [];
    steps.forEach(function (step, i) {
      if (!step.target || stepTarget(step)) visible.push(i);
    });
  }

  function visiblePos() {
    return visible.indexOf(stepIndex);
  }

  function isLastVisible() {
    return visible.length > 0 && visiblePos() === visible.length - 1;
  }

  function ensureDom() {
    if (root) return;
    root = document.createElement('div');
    root.className = 'itin-tutorial';
    root.hidden = true;
    root.innerHTML =
      '<div class="itin-tutorial__dim" data-app-tutorial-dim></div>' +
      '<div class="itin-tutorial__spot" hidden></div>' +
      '<div class="itin-tutorial__card" role="dialog" aria-modal="true" aria-labelledby="appTutorialTitle">' +
        '<div class="itin-tutorial__copy">' +
          '<div class="itin-tutorial__count" data-app-tutorial-count></div>' +
          '<h2 class="itin-tutorial__title" id="appTutorialTitle"></h2>' +
          '<p class="itin-tutorial__text"></p>' +
        '</div>' +
        '<div class="itin-tutorial__actions">' +
          '<button type="button" class="itin-tutorial__btn" data-app-tutorial-skip>Skip</button>' +
          '<button type="button" class="itin-tutorial__btn" data-app-tutorial-back>Back</button>' +
          '<button type="button" class="itin-tutorial__btn itin-tutorial__btn--primary" data-app-tutorial-next>Next</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(root);
    dimEl = root.querySelector('[data-app-tutorial-dim]');
    spotEl = root.querySelector('.itin-tutorial__spot');
    cardEl = root.querySelector('.itin-tutorial__card');
    stepEl = root.querySelector('[data-app-tutorial-count]');
    titleEl = root.querySelector('.itin-tutorial__title');
    bodyEl = root.querySelector('.itin-tutorial__text');
    backBtn = root.querySelector('[data-app-tutorial-back]');
    nextBtn = root.querySelector('[data-app-tutorial-next]');
    root.querySelector('[data-app-tutorial-skip]').addEventListener('click', skip);
    backBtn.addEventListener('click', function () { go(-1); });
    nextBtn.addEventListener('click', function () { go(1); });
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', onLayout, { passive: true });
    window.addEventListener('scroll', onLayout, true);
  }

  function onKey(e) {
    if (!active) return;
    if (e.key === 'ArrowRight' || e.key === 'Enter') {
      e.preventDefault();
      go(1);
    } else     if (e.key === 'ArrowLeft') {
      e.preventDefault();
      go(-1);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      skip();
    }
  }

  function onLayout() {
    if (!active) return;
    paintSpot();
    placeCard();
  }

  function paintSpot() {
    var step = steps[stepIndex];
    var target = stepTarget(step);
    if (!target) {
      spotEl.hidden = true;
      dimEl.classList.add('is-full');
      return;
    }
    dimEl.classList.remove('is-full');
    var r = target.getBoundingClientRect();
    spotEl.hidden = false;
    spotEl.style.top = Math.round(r.top - SPOT_PAD) + 'px';
    spotEl.style.left = Math.round(r.left - SPOT_PAD) + 'px';
    spotEl.style.width = Math.round(r.width + SPOT_PAD * 2) + 'px';
    spotEl.style.height = Math.round(r.height + SPOT_PAD * 2) + 'px';
  }

  function placeCard() {
    var step = steps[stepIndex];
    var target = stepTarget(step);
    var gap = 12;
    var cw = cardEl.offsetWidth || 300;
    var ch = cardEl.offsetHeight || 180;
    var left;
    var top;
    if (!target) {
      left = Math.max(12, (window.innerWidth - cw) / 2);
      top = Math.max(12, (window.innerHeight - ch) / 2);
    } else {
      var r = target.getBoundingClientRect();
      left = r.left;
      top = r.bottom + gap;
      if (top + ch > window.innerHeight - 12) top = r.top - ch - gap;
      if (top < 12) top = 12;
      if (left + cw > window.innerWidth - 12) left = window.innerWidth - cw - 12;
      if (left < 12) left = 12;
    }
    cardEl.style.left = Math.round(left) + 'px';
    cardEl.style.top = Math.round(top) + 'px';
  }

  function render() {
    rebuildVisible();
    if (!visible.length) {
      dismiss();
      return;
    }
    if (visible.indexOf(stepIndex) === -1) {
      var next = visible.find(function (i) { return i >= stepIndex; });
      stepIndex = next != null ? next : visible[visible.length - 1];
    }
    var step = steps[stepIndex];
    var pos = visiblePos();
    var last = isLastVisible();
    stepEl.textContent = (pos + 1) + ' / ' + visible.length;
    titleEl.textContent = step.title;
    bodyEl.textContent = step.body;
    backBtn.hidden = pos <= 0;
    nextBtn.textContent = last ? 'Done' : 'Next';
    var target = stepTarget(step);
    if (target && typeof target.scrollIntoView === 'function') {
      var nav = target.closest('.tourist-topnav__links');
      if (nav) {
        target.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      }
    }
    window.requestAnimationFrame(function () {
      paintSpot();
      placeCard();
    });
  }

  function go(dir) {
    if (!active) return;
    rebuildVisible();
    var pos = visiblePos();
    if (dir < 0) {
      if (pos > 0) {
        stepIndex = visible[pos - 1];
        render();
      }
      return;
    }
    if (!isLastVisible()) {
      stepIndex = visible[pos + 1];
      render();
      return;
    }
    markComplete();
    dismiss();
  }

  function skip() {
    if (!active) return;
    markComplete();
    dismiss();
  }

  function dismiss() {
    active = false;
    if (!root) return;
    root.hidden = true;
    document.documentElement.classList.remove('itin-tutorial-open');
  }

  function start(fromIndex) {
    ensureDom();
    rebuildVisible();
    if (!visible.length) return;
    var idx = fromIndex == null ? visible[0] : fromIndex;
    if (visible.indexOf(idx) === -1) idx = visible[0];
    stepIndex = idx;
    active = true;
    root.hidden = false;
    document.documentElement.classList.add('itin-tutorial-open');
    render();
  }

  function maybeAuto() {
    if (!host || host.getAttribute('data-app-tutorial-auto') !== '1') return;
    if (isComplete()) return;
    var modal = document.getElementById('guideSetupModal') || document.getElementById('businessSetupModal');
    if (modal && (modal.getAttribute('data-guide-setup') === 'incomplete' || modal.getAttribute('data-business-setup') === 'incomplete')) {
      return;
    }
    var begin = function () {
      if (isComplete()) return;
      markComplete();
      start(0);
    };
    if (!modal) {
      begin();
      return;
    }
    var started = false;
    var run = function () {
      if (started) return;
      started = true;
      begin();
    };
    modal.addEventListener('hidden.bs.modal', run, { once: true });
    window.setTimeout(function () {
      if (modal.classList.contains('show')) return;
      run();
    }, 700);
  }

  function bindReplay() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-app-tutorial-replay]');
      if (!btn) return;
      e.preventDefault();
      start(0);
    });
  }

  function boot() {
    if (!bootHost()) return;
    bindReplay();
    maybeAuto();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
