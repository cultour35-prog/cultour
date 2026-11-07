/**
 * CulTour itinerary maker click-through tutorial (once per account).
 * Tourist vs tour-guide copy is chosen from [data-itin-tutorial].
 */
(function () {
  var STORAGE_KEY = 'cultour-itin-tutorial-v1';
  var SESSION_KEY = 'cultour-itin-tutorial-step';
  var SPOT_PAD = 8;

  var TOURIST_STEPS = [
    {
      id: 'welcome',
      title: 'Welcome',
      body: 'This page is where you plan a tour in Ormoc.',
      target: null
    },
    {
      id: 'new-tour',
      title: '+ New tour',
      body: 'Start a blank itinerary.',
      target: '[data-itin-new-tour]'
    },
    {
      id: 'suggested',
      title: 'Suggested tours',
      body: 'Or pick a ready-made Ormoc itinerary from this row.',
      target: '[data-itin-tour="suggested"]'
    },
    {
      id: 'use-suggested',
      title: 'Use this tour',
      body: 'Copy a suggested tour into your list so you can edit dates and book.',
      target: '[data-itin-tour="use-suggested"]'
    },
    {
      id: 'title',
      title: 'Tour name',
      body: 'Name it before booking.',
      target: '[data-itin-tour="title"]'
    },
    {
      id: 'guests',
      title: 'Guests',
      body: 'How many people are going.',
      target: '[data-itin-tour="guests"]'
    },
    {
      id: 'guide',
      title: 'Tour guide',
      body: 'Pick a guide (required to book).',
      target: '[data-itin-tour="guide"]'
    },
    {
      id: 'start-date',
      title: 'Tour start date',
      body: 'Set when the tour starts; optional until booking.',
      target: '[data-itin-tour="start-date"]'
    },
    {
      id: 'multiday',
      title: 'Multi-day',
      body: 'Turn on extra days if needed.',
      target: '[data-itin-tour="multiday"]'
    },
    {
      id: 'place',
      title: 'Add a stop',
      body: 'Choose a destination or business.',
      target: '[data-itin-tour="place"]'
    },
    {
      id: 'visit-add',
      title: 'Visit time + Add',
      body: 'How long to stay, then add the stop.',
      target: '[data-itin-tour="visit-add"]'
    },
    {
      id: 'auto-generate',
      title: 'Auto-generate',
      body: 'Fill a suggested route from your dates and meetup.',
      target: '[data-itin-tour="auto-generate"]'
    },
    {
      id: 'stops',
      title: 'Route stops',
      body: 'Your day-by-day list; meetup is the first pin.',
      target: '[data-itin-tour="stops"]'
    },
    {
      id: 'map',
      title: 'Route map',
      body: 'Pins and the road line from Ormoc Port to your stops.',
      target: '[data-itin-tour="map"]'
    },
    {
      id: 'book',
      title: 'Book',
      body: 'When name, date, guide, and stops are ready.',
      target: '[data-itin-tour="book"]'
    }
  ];

  var GUIDE_STEPS = [
    {
      id: 'welcome',
      title: 'Welcome',
      body: 'This page is where you build sample tours tourists can copy.',
      target: null
    },
    {
      id: 'new-tour',
      title: '+ New tour',
      body: 'Start a blank suggested tour.',
      target: '[data-itin-new-tour]'
    },
    {
      id: 'title',
      title: 'Tour name',
      body: 'Name it so tourists know what this tour is.',
      target: '[data-itin-tour="title"]'
    },
    {
      id: 'drafts',
      title: 'View drafts',
      body: 'Unpublished tours stay here until you Save.',
      target: '[data-itin-tour="drafts"]'
    },
    {
      id: 'meetup',
      title: 'Meetup time',
      body: 'Default start time at Ormoc Port. Tourists pick their own date.',
      target: '[data-itin-tour="meetup"]'
    },
    {
      id: 'multiday',
      title: 'Multi-day',
      body: 'Turn on extra days if this template spans more than one day.',
      target: '[data-itin-tour="multiday"]'
    },
    {
      id: 'place',
      title: 'Add a stop',
      body: 'Choose a destination or business.',
      target: '[data-itin-tour="place"]'
    },
    {
      id: 'visit-add',
      title: 'Visit time + Add',
      body: 'How long tourists stay, then add the stop.',
      target: '[data-itin-tour="visit-add"]'
    },
    {
      id: 'auto-generate',
      title: 'Auto-generate',
      body: 'Fill a suggested route from meetup time and days.',
      target: '[data-itin-tour="auto-generate"]'
    },
    {
      id: 'stops',
      title: 'Route stops',
      body: 'The day-by-day list; meetup is the first pin.',
      target: '[data-itin-tour="stops"]'
    },
    {
      id: 'map',
      title: 'Route map',
      body: 'Pins and the road line from Ormoc Port to the stops.',
      target: '[data-itin-tour="map"]'
    },
    {
      id: 'save',
      title: 'Save',
      body: 'Save so tourists can use this tour.',
      target: '[data-itin-tour="save"]'
    }
  ];

  var STEPS = TOURIST_STEPS;

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
  var forceRun = false;
  var stepIndex = 0;
  var visible = [];

  function tutorialKind() {
    var el = document.querySelector('[data-itin-tutorial-user]');
    return (el && el.getAttribute('data-itin-tutorial') === 'guide') ? 'guide' : 'tourist';
  }

  function configureVariant() {
    if (tutorialKind() === 'guide') {
      STEPS = GUIDE_STEPS;
      STORAGE_KEY = 'cultour-guide-tutorial-v1';
      SESSION_KEY = 'cultour-guide-tutorial-step';
    } else {
      STEPS = TOURIST_STEPS;
      STORAGE_KEY = 'cultour-itin-tutorial-v1';
      SESSION_KEY = 'cultour-itin-tutorial-step';
    }
  }

  function userId() {
    var el = document.querySelector('[data-itin-tutorial-user]');
    return el ? String(el.getAttribute('data-itin-tutorial-user') || '') : '';
  }

  function loadStore() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      var data = raw ? JSON.parse(raw) : {};
      return data && typeof data === 'object' && !Array.isArray(data) ? data : {};
    } catch (e) {
      return {};
    }
  }

  function storeRow() {
    var uid = userId();
    if (!uid) return null;
    var row = loadStore()[uid];
    return row && typeof row === 'object' ? row : null;
  }

  function isSeen() {
    var row = storeRow();
    return !!(row && (row.seen || row.done));
  }

  function isComplete() {
    var row = storeRow();
    return !!(row && row.done);
  }

  function saveRow(patch) {
    var uid = userId();
    if (!uid) return;
    try {
      var data = loadStore();
      var row = data[uid] && typeof data[uid] === 'object' ? data[uid] : {};
      data[uid] = Object.assign(row, patch);
      localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
    } catch (e) {}
  }

  function markSeen() {
    saveRow({ seen: true });
  }

  function markComplete() {
    saveRow({ seen: true, done: true });
  }

  function setResume(index) {
    try {
      sessionStorage.setItem(SESSION_KEY, String(index));
    } catch (e) {}
  }

  function takeResume() {
    try {
      var raw = sessionStorage.getItem(SESSION_KEY);
      if (raw == null || raw === '') return null;
      sessionStorage.removeItem(SESSION_KEY);
      var n = parseInt(raw, 10);
      return isNaN(n) ? null : n;
    } catch (e) {
      return null;
    }
  }

  function stepTarget(step) {
    if (!step || !step.target) return null;
    return document.querySelector(step.target);
  }

  function rebuildVisible() {
    visible = [];
    STEPS.forEach(function (step, i) {
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
      '<div class="itin-tutorial__dim" data-itin-tutorial-dim></div>' +
      '<div class="itin-tutorial__spot" hidden></div>' +
      '<div class="itin-tutorial__card" role="dialog" aria-modal="true" aria-labelledby="itinTutorialTitle">' +
        '<div class="itin-tutorial__copy">' +
          '<div class="itin-tutorial__count" data-itin-tutorial-count></div>' +
          '<h2 class="itin-tutorial__title" id="itinTutorialTitle"></h2>' +
          '<p class="itin-tutorial__text"></p>' +
        '</div>' +
        '<div class="itin-tutorial__actions">' +
          '<button type="button" class="itin-tutorial__btn" data-itin-tutorial-skip>Skip</button>' +
          '<button type="button" class="itin-tutorial__btn" data-itin-tutorial-back>Back</button>' +
          '<button type="button" class="itin-tutorial__btn itin-tutorial__btn--primary" data-itin-tutorial-next>Next</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(root);
    dimEl = root.querySelector('[data-itin-tutorial-dim]');
    spotEl = root.querySelector('.itin-tutorial__spot');
    cardEl = root.querySelector('.itin-tutorial__card');
    stepEl = root.querySelector('[data-itin-tutorial-count]');
    titleEl = root.querySelector('.itin-tutorial__title');
    bodyEl = root.querySelector('.itin-tutorial__text');
    backBtn = root.querySelector('[data-itin-tutorial-back]');
    nextBtn = root.querySelector('[data-itin-tutorial-next]');

    root.querySelector('[data-itin-tutorial-skip]').addEventListener('click', skip);
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
    } else if (e.key === 'ArrowLeft') {
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
    var step = STEPS[stepIndex];
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
    var step = STEPS[stepIndex];
    var target = stepTarget(step);
    var gap = 12;
    var cw = cardEl.offsetWidth || 300;
    var ch = cardEl.offsetHeight || 180;
    var left;
    var top;
    cardEl.style.transform = '';
    if (!target) {
      left = Math.max(12, (window.innerWidth - cw) / 2);
      top = Math.max(12, (window.innerHeight - ch) / 2);
    } else {
      var r = target.getBoundingClientRect();
      left = r.left;
      top = r.bottom + gap;
      if (top + ch > window.innerHeight - 12) {
        top = r.top - ch - gap;
      }
      if (top < 12) top = 12;
      if (left + cw > window.innerWidth - 12) {
        left = window.innerWidth - cw - 12;
      }
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
    var step = STEPS[stepIndex];
    var pos = visiblePos();
    var last = isLastVisible();

    stepEl.textContent = (pos + 1) + ' / ' + visible.length;
    titleEl.textContent = step.title;
    bodyEl.textContent = step.body;
    backBtn.hidden = pos <= 0;
    nextBtn.textContent = last ? 'Done' : 'Next';

    var target = stepTarget(step);
    if (target && typeof target.scrollIntoView === 'function') {
      target.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' });
    }
    window.requestAnimationFrame(function () {
      paintSpot();
      placeCard();
      window.setTimeout(function () {
        paintSpot();
        placeCard();
      }, 220);
    });
    nextBtn.focus();
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
    var last = isLastVisible();
    if (!last) {
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
    try { sessionStorage.removeItem(SESSION_KEY); } catch (e) {}
    dismiss();
  }

  function dismiss() {
    active = false;
    forceRun = false;
    if (!root) return;
    root.hidden = true;
    document.documentElement.classList.remove('itin-tutorial-open');
  }

  function start(fromIndex, opts) {
    ensureDom();
    rebuildVisible();
    if (!visible.length) return;
    forceRun = !!(opts && opts.force);
    var idx = fromIndex == null ? visible[0] : fromIndex;
    if (visible.indexOf(idx) === -1) {
      var next = visible.find(function (i) { return i >= idx; });
      idx = next != null ? next : visible[0];
    }
    stepIndex = idx;
    active = true;
    root.hidden = false;
    document.documentElement.classList.add('itin-tutorial-open');
    render();
  }

  function bindReplay() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-itin-tutorial-replay]');
      if (!btn) return;
      e.preventDefault();
      start(0, { force: true });
    });
  }

  function bindNewTourResume() {
    document.addEventListener('submit', function (e) {
      if (!e.target || !e.target.closest) return;
      if (!active && isComplete()) return;
      if (e.target.closest('[data-itin-new-tour]')) {
        var titleIdx = STEPS.findIndex(function (s) { return s.id === 'title'; });
        if (titleIdx < 0) titleIdx = stepIndex + 1;
        setResume(titleIdx);
        return;
      }
      if (e.target.closest('[data-itin-tour="use-suggested"]')) {
        var after = STEPS.findIndex(function (s) { return s.id === 'title'; });
        if (after < 0) after = stepIndex + 1;
        setResume(after);
      }
    });
    document.addEventListener('click', function (e) {
      var chip = e.target.closest('[data-itin-tour="suggested"] a');
      if (!chip) return;
      if (!active && isComplete()) return;
      var useIdx = STEPS.findIndex(function (s) { return s.id === 'use-suggested'; });
      if (useIdx < 0) useIdx = stepIndex + 1;
      setResume(useIdx);
    });
  }

  function boot() {
    if (!document.querySelector('[data-itin-tutorial-user]')) return;
    configureVariant();
    ensureDom();
    bindReplay();
    bindNewTourResume();
    rebuildVisible();
    var resume = takeResume();
    if (resume != null && !isComplete()) {
      start(resume);
      return;
    }
    if (!isSeen()) {
      markSeen();
      start(0);
    }
  }

  window.CulTourItinTutorial = {
    start: function () { start(0, { force: true }); },
    isComplete: isComplete
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
