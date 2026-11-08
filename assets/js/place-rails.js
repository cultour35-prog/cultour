(function () {
  var SLIDE_MS = 620;

  function initRail(rail) {
    var viewport = rail.querySelector('.ct-rail__viewport');
    var track = rail.querySelector('.ct-rail__track');
    var prev = rail.querySelector('.ct-rail__arrow--prev');
    var next = rail.querySelector('.ct-rail__arrow--next');
    if (!track) return;

    var busy = false;

    function cards() {
      return Array.prototype.slice.call(track.querySelectorAll('.ct-rail__card'));
    }

    function gapPx() {
      return parseFloat(getComputedStyle(track).gap) || 16;
    }

    function markFirstActive() {
      cards().forEach(function (c, i) {
        c.classList.toggle('is-active', i === 0);
      });
      track.scrollLeft = 0;
    }

    function unlock() {
      if (viewport) viewport.classList.remove('is-locked');
      busy = false;
    }

    function afterLayout(fn) {
      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(fn);
      });
    }

    function waitSlide(onDone) {
      var done = false;
      function finish(e) {
        if (e) {
          if (e.target !== track) return;
          if (e.propertyName && e.propertyName !== 'transform') return;
        }
        if (done) return;
        done = true;
        track.removeEventListener('transitionend', finish);
        onDone();
      }
      track.addEventListener('transitionend', finish);
      window.setTimeout(finish, SLIDE_MS);
    }

    function slideNextOnce(then) {
      var list = cards();
      if (list.length < 2) {
        if (then) then();
        return;
      }
      var delta = -Math.round(list[1].getBoundingClientRect().width + gapPx());
      list[0].classList.remove('is-active');
      list[1].classList.add('is-active');
      afterLayout(function () {
        track.classList.add('is-sliding');
        track.style.transform = 'translateX(' + delta + 'px)';
        waitSlide(function () {
          track.classList.remove('is-sliding');
          track.style.transition = 'none';
          track.style.transform = 'translateX(0)';
          track.appendChild(cards()[0]);
          markFirstActive();
          void track.offsetWidth;
          track.style.transition = '';
          if (then) then();
        });
      });
    }

    function rotate(dir) {
      var list = cards();
      if (busy || list.length < 2) return;
      busy = true;
      if (viewport) viewport.classList.add('is-locked');

      if (dir > 0) {
        slideNextOnce(unlock);
        return;
      }

      var last = list[list.length - 1];
      var back = -Math.round(last.getBoundingClientRect().width + gapPx());
      list[0].classList.remove('is-active');
      track.style.transition = 'none';
      track.insertBefore(last, track.firstChild);
      track.style.transform = 'translateX(' + back + 'px)';
      void track.offsetWidth;
      cards()[0].classList.add('is-active');
      afterLayout(function () {
        track.classList.add('is-sliding');
        track.style.transition = '';
        track.style.transform = 'translateX(0)';
        waitSlide(function () {
          track.classList.remove('is-sliding');
          track.style.transform = 'translateX(0)';
          markFirstActive();
          unlock();
        });
      });
    }

    function bringToFront(steps) {
      if (busy || steps <= 0) return;
      busy = true;
      if (viewport) viewport.classList.add('is-locked');

      function step() {
        if (steps <= 0) {
          markFirstActive();
          unlock();
          return;
        }
        slideNextOnce(function () {
          steps -= 1;
          step();
        });
      }
      step();
    }

    if (prev) prev.addEventListener('click', function () { rotate(-1); });
    if (next) next.addEventListener('click', function () { rotate(1); });

    track.addEventListener('click', function (e) {
      if (e.target.closest('[data-fav-btn]')) return;
      var card = e.target.closest('.ct-rail__card');
      if (!card || !track.contains(card)) return;
      var list = cards();
      var i = list.indexOf(card);
      if (i < 0) return;
      if (i === 0) {
        var href = card.getAttribute('data-href');
        if (href) window.location.href = href;
        return;
      }
      bringToFront(i);
    });

    track.addEventListener('keydown', function (e) {
      if (e.target.closest('[data-fav-btn]')) return;
      var card = e.target.closest('.ct-rail__card');
      if (!card) return;
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        card.click();
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        rotate(1);
      } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        rotate(-1);
      }
    });

    markFirstActive();
  }

  document.querySelectorAll('[data-focus-rail]').forEach(initRail);
  document.addEventListener('cultour:live-replaced', function (e) {
    if (e.target && e.target.querySelectorAll) e.target.querySelectorAll('[data-focus-rail]').forEach(initRail);
  });
})();
