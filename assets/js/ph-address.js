/**
 * Cascading PH address selects: province → city → barangay; street is free text.
 * Visible selects support type-to-search when the menu is open.
 * Optional: data-ph-lock-province, data-ph-hide-province, data-ph-lock-city, data-ph-hide-city
 */
(function () {
  function option(value, label, selected) {
    var o = document.createElement('option');
    o.value = value;
    o.textContent = label;
    if (selected) o.selected = true;
    return o;
  }

  function placeholderText(sel) {
    var first = sel && sel.options && sel.options[0];
    if (first && first.disabled && first.value === '') return first.textContent || 'Select';
    return 'Select';
  }

  function selectedLabel(sel) {
    if (!sel || sel.selectedIndex < 0) return '';
    var opt = sel.options[sel.selectedIndex];
    if (!opt || opt.value === '') return '';
    return opt.textContent || opt.value;
  }

  function closeAllSearchMenus(except) {
    document.querySelectorAll('.ph-search-select.is-open').forEach(function (wrap) {
      if (except && wrap === except) return;
      closeSearchMenu(wrap);
    });
  }

  function closeSearchMenu(wrap) {
    if (!wrap) return;
    wrap.classList.remove('is-open');
    var menu = wrap.querySelector('.ph-search-select__menu');
    var input = wrap.querySelector('.ph-search-select__input');
    var sel = wrap.querySelector('select');
    if (menu) menu.classList.add('d-none');
    if (input && sel) {
      input.value = selectedLabel(sel);
      input.setAttribute('aria-expanded', 'false');
    }
  }

  function openSearchMenu(wrap) {
    if (!wrap || wrap.classList.contains('is-disabled')) return;
    var sel = wrap.querySelector('select');
    var input = wrap.querySelector('.ph-search-select__input');
    if (!sel || sel.disabled) return;
    closeAllSearchMenus(wrap);
    wrap.classList.add('is-open');
    var menu = wrap.querySelector('.ph-search-select__menu');
    if (menu) menu.classList.remove('d-none');
    if (input) {
      input.setAttribute('aria-expanded', 'true');
      filterSearchOptions(wrap, '');
      try {
        input.select();
      } catch (e) { /* ignore */ }
    }
  }

  function filterSearchOptions(wrap, query) {
    var menu = wrap.querySelector('.ph-search-select__menu');
    if (!menu) return;
    var q = String(query || '').trim().toLowerCase();
    var buttons = menu.querySelectorAll('.ph-search-select__option');
    var visible = 0;
    buttons.forEach(function (btn) {
      var label = (btn.textContent || '').toLowerCase();
      var show = !q || label.indexOf(q) !== -1;
      btn.hidden = !show;
      if (show) visible += 1;
    });
    var empty = menu.querySelector('.ph-search-select__empty');
    if (empty) empty.classList.toggle('d-none', visible > 0);
  }

  function rebuildSearchMenu(sel) {
    var wrap = sel && sel.closest('.ph-search-select');
    if (!wrap) return;
    var menu = wrap.querySelector('.ph-search-select__menu');
    var input = wrap.querySelector('.ph-search-select__input');
    if (!menu || !input) return;
    menu.innerHTML = '';
    Array.prototype.forEach.call(sel.options, function (opt) {
      if (opt.disabled && opt.value === '') return;
      if (opt.value === '') return;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ph-search-select__option' + (opt.selected ? ' is-active' : '');
      btn.setAttribute('role', 'option');
      btn.setAttribute('data-value', opt.value);
      btn.textContent = opt.textContent || opt.value;
      menu.appendChild(btn);
    });
    var empty = document.createElement('div');
    empty.className = 'ph-search-select__empty small text-muted d-none';
    empty.textContent = 'No matches';
    menu.appendChild(empty);
    input.placeholder = placeholderText(sel);
    input.disabled = !!sel.disabled;
    input.readOnly = sel.classList.contains('pe-none') || sel.getAttribute('aria-readonly') === 'true';
    wrap.classList.toggle('is-disabled', !!sel.disabled || input.readOnly);
    wrap.classList.toggle('is-locked', !!input.readOnly);
    var icon = wrap.querySelector('.select-chevron__icon');
    if (icon) {
      icon.classList.toggle('bi-chevron-down', !input.readOnly);
      icon.classList.toggle('bi-lock-fill', !!input.readOnly);
    }
    syncSearchInput(sel);
  }

  function syncSearchInput(sel) {
    var wrap = sel && sel.closest('.ph-search-select');
    if (!wrap) return;
    var input = wrap.querySelector('.ph-search-select__input');
    if (!input) return;
    if (!wrap.classList.contains('is-open')) {
      input.value = selectedLabel(sel);
    }
    input.disabled = !!sel.disabled;
  }

  function pickSearchValue(wrap, value) {
    var sel = wrap.querySelector('select');
    if (!sel) return;
    sel.value = value;
    if (typeof sel.dispatchEvent === 'function') {
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    closeSearchMenu(wrap);
  }

  function ensureSearchable(sel) {
    if (!sel || sel.tagName !== 'SELECT') return;
    var wrap = sel.closest('.select-chevron');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'select-chevron';
      sel.parentNode.insertBefore(wrap, sel);
      wrap.appendChild(sel);
    }
    if (!wrap.classList.contains('ph-search-select')) {
      wrap.classList.add('ph-search-select');
      sel.classList.add('ph-search-select__native');
      sel.setAttribute('tabindex', '-1');
      sel.setAttribute('aria-hidden', 'true');

      var input = document.createElement('input');
      input.type = 'text';
      input.className = 'form-control form-select ph-search-select__input';
      input.setAttribute('autocomplete', 'off');
      input.setAttribute('spellcheck', 'false');
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-expanded', 'false');
      input.setAttribute('aria-autocomplete', 'list');
      if (sel.id) input.setAttribute('aria-controls', sel.id + '_menu');
      input.setAttribute('data-ph-search-input', '1');

      var menu = document.createElement('div');
      menu.className = 'ph-search-select__menu d-none';
      menu.setAttribute('role', 'listbox');
      if (sel.id) menu.id = sel.id + '_menu';

      var chevron = wrap.querySelector('.select-chevron__icon');
      if (chevron) {
        wrap.insertBefore(input, chevron);
      } else {
        wrap.appendChild(input);
      }
      wrap.appendChild(menu);

      input.addEventListener('focus', function () {
        if (!input.readOnly && !input.disabled) openSearchMenu(wrap);
      });
      input.addEventListener('click', function () {
        if (!input.readOnly && !input.disabled) openSearchMenu(wrap);
      });
      input.addEventListener('input', function () {
        if (!wrap.classList.contains('is-open')) openSearchMenu(wrap);
        filterSearchOptions(wrap, input.value);
      });
      input.addEventListener('keydown', function (e) {
        if (input.readOnly || input.disabled) return;
        if (e.key === 'Escape') {
          closeSearchMenu(wrap);
          input.blur();
          return;
        }
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
          if (!wrap.classList.contains('is-open')) {
            e.preventDefault();
            openSearchMenu(wrap);
            return;
          }
        }
        if (e.key === 'Enter') {
          var first = wrap.querySelector('.ph-search-select__option:not([hidden])');
          if (first) {
            e.preventDefault();
            pickSearchValue(wrap, first.getAttribute('data-value') || '');
          }
        }
      });

      menu.addEventListener('mousedown', function (e) {
        var btn = e.target.closest('.ph-search-select__option');
        if (!btn || !menu.contains(btn)) return;
        e.preventDefault();
        pickSearchValue(wrap, btn.getAttribute('data-value') || '');
      });

      if (!document.documentElement._phSearchOutside) {
        document.documentElement._phSearchOutside = true;
        document.addEventListener('mousedown', function (e) {
          document.querySelectorAll('.ph-search-select.is-open').forEach(function (openWrap) {
            if (!openWrap.contains(e.target)) closeSearchMenu(openWrap);
          });
        });
      }

      sel.addEventListener('invalid', function () {
        if (input && !input.disabled) {
          input.focus();
          openSearchMenu(wrap);
        }
      });
    }

    rebuildSearchMenu(sel);
  }

  function fillSelect(sel, values, selected, placeholder, enabled, lockOnly) {
    if (!sel || sel.tagName !== 'SELECT') return;
    sel.innerHTML = '';
    if (!lockOnly) {
      sel.appendChild(option('', placeholder, !selected));
      var first = sel.options[0];
      if (first) first.disabled = true;
    }
    (values || []).forEach(function (v) {
      sel.appendChild(option(v, v, v === selected));
    });
    sel.disabled = !!(sel.closest && (
      sel.closest('fieldset[disabled]') || sel.closest('.admin-locked-form.is-locked')
    )) || !enabled;
    if (selected && values && values.length && values.indexOf(selected) === -1 && !lockOnly) {
      sel.value = '';
    }
    ensureSearchable(sel);
  }

  function bindRoot(root, tree) {
    var provinceEl = root.querySelector('[data-ph-region], [data-ph-province]');
    var cityEl = root.querySelector('[data-ph-city]');
    var brgyEl = root.querySelector('[data-ph-barangay]');
    if (!brgyEl) return;

    var hideProvince = root.hasAttribute('data-ph-hide-province') || (provinceEl && provinceEl.type === 'hidden');
    var hideCity = root.hasAttribute('data-ph-hide-city') || (cityEl && cityEl.type === 'hidden');
    var initCity = (cityEl && cityEl.getAttribute('data-initial')) || '';
    var initBrgy = brgyEl.getAttribute('data-initial') || '';

    function lockProvince() {
      return (root.getAttribute('data-ph-lock-province') || '').trim();
    }
    function lockCity() {
      return (root.getAttribute('data-ph-lock-city') || (root.getAttribute('data-ph-default-city') || '')).trim();
    }

    function provinceValue(forced) {
      if (forced) return forced;
      var lock = lockProvince();
      if (lock) return lock;
      if (provinceEl) return provinceEl.value || '';
      return '';
    }

    function cityValue() {
      var lock = lockCity();
      if (lock && (hideCity || (cityEl && cityEl.tagName === 'SELECT'))) return lock;
      if (cityEl) return cityEl.value || lock || '';
      return lock || '';
    }

    function apply() {
      initCity = (cityEl && cityEl.getAttribute('data-initial')) || '';
      initBrgy = (brgyEl && brgyEl.getAttribute('data-initial')) || '';
      var lockP = lockProvince();
      var lockC = lockCity();
      var initProvince = lockP || (provinceEl && provinceEl.getAttribute('data-initial')) || provinceValue() || '';
      if (provinceEl && provinceEl.tagName === 'SELECT') {
        var provinces = Object.keys(tree || {});
        if (lockP) {
          provinceEl.classList.add('pe-none');
          provinceEl.setAttribute('aria-readonly', 'true');
          fillSelect(provinceEl, [lockP], lockP, 'Select province', true, true);
        } else {
          provinceEl.classList.remove('pe-none');
          provinceEl.removeAttribute('aria-readonly');
          fillSelect(provinceEl, provinces, initProvince, 'Select province', true, false);
        }
      } else if (provinceEl && lockP) {
        provinceEl.value = lockP;
      }
      if (!initCity && lockC) initCity = lockC;
      if (cityEl && cityEl.type === 'hidden' && lockC) {
        cityEl.value = lockC;
      }
      onProvinceChange(true, lockP || initProvince);
    }

    function onProvinceChange(preserveCity, forcedProvince) {
      var province = provinceValue(forcedProvince);
      var cities = province && tree[province] ? Object.keys(tree[province]) : [];
      var lockC = lockCity();
      var keepCity = hideCity && lockC ? lockC : (preserveCity ? initCity : '');
      if (cityEl && cityEl.tagName === 'SELECT' && !hideCity) {
        if (lockC) {
          cityEl.classList.add('pe-none');
          cityEl.setAttribute('aria-readonly', 'true');
          fillSelect(cityEl, [lockC], lockC, 'Select city / municipality', !!province, true);
        } else {
          cityEl.classList.remove('pe-none');
          cityEl.removeAttribute('aria-readonly');
          fillSelect(cityEl, cities, keepCity && cities.indexOf(keepCity) !== -1 ? keepCity : '', 'Select city / municipality', !!province, false);
        }
      } else if (cityEl && lockC) {
        cityEl.value = lockC;
      }
      onCityChange(preserveCity, province);
    }

    function onCityChange(preserveBrgy, forcedProvince) {
      var province = provinceValue(forcedProvince);
      var city = cityValue();
      var list = province && city && tree[province] && tree[province][city] ? tree[province][city] : [];
      var keep = preserveBrgy ? initBrgy : '';
      fillSelect(brgyEl, list, keep && list.indexOf(keep) !== -1 ? keep : '', 'Select barangay', !!city, false);
    }

    if (!root._phBound) {
      root._phBound = true;
      if (provinceEl && provinceEl.tagName === 'SELECT' && !hideProvince) {
        provinceEl.addEventListener('change', function () {
          initCity = '';
          initBrgy = '';
          onProvinceChange(false);
        });
      }
      if (cityEl && cityEl.tagName === 'SELECT' && !hideCity) {
        cityEl.addEventListener('change', function () {
          initBrgy = '';
          onCityChange(false);
        });
      }
    }

    apply();
    root._phApply = apply;
  }

  function named(root, name) {
    return root ? root.querySelector('[name="' + name + '"]') : null;
  }

  function sameAsOn(wrap) {
    var yes = wrap.querySelector('input[type="checkbox"][name="current_same_as_permanent"][value="1"]');
    return !!(yes && yes.checked && !yes.disabled);
  }

  function copyPermanentToCurrent(wrap) {
    var perm = wrap.querySelector('[data-same-permanent]');
    var cur = wrap.querySelector('[data-same-current]');
    if (!perm || !cur) return;
    var lock = (wrap.getAttribute('data-same-lock-province') || '').trim();
    var lockCity = (wrap.getAttribute('data-same-lock-city') || '').trim();
    var pRegion = named(perm, 'address_region');
    var pCity = named(perm, 'address_city');
    var pBrgy = named(perm, 'address_barangay');
    var pStreet = named(perm, 'address_street');
    var cRegion = named(cur, 'current_address_region');
    var cCity = named(cur, 'current_address_city');
    var cBrgy = named(cur, 'current_address_barangay');
    var cStreet = named(cur, 'current_address_street');
    var regionVal = pRegion ? String(pRegion.value || '').trim() : '';
    if (lock && regionVal && regionVal.toLowerCase() !== lock.toLowerCase()) {
      return false;
    }
    var cityVal = pCity ? String(pCity.value || '').trim() : '';
    if (lockCity && cityVal && cityVal.toLowerCase() !== lockCity.toLowerCase()) {
      return false;
    }
    var brgyVal = pBrgy ? String(pBrgy.value || '').trim() : '';
    var streetVal = pStreet ? String(pStreet.value || '') : '';
    if (cRegion) {
      cRegion.setAttribute('data-initial', lock || regionVal);
      if (cRegion.tagName === 'SELECT' || cRegion.type === 'hidden') cRegion.value = lock || regionVal;
    }
    if (cCity) cCity.setAttribute('data-initial', cityVal);
    if (cBrgy) cBrgy.setAttribute('data-initial', brgyVal);
    if (typeof cur._phApply === 'function') cur._phApply();
    if (cStreet) cStreet.value = streetVal;
    return true;
  }

  function setCurrentLocked(wrap, locked) {
    var cur = wrap.querySelector('[data-same-current]');
    if (!cur) return;
    ['current_address_region', 'current_address_city', 'current_address_barangay', 'current_address_street'].forEach(function (name) {
      var el = named(cur, name);
      if (!el) return;
      var lockProv = (cur.getAttribute('data-ph-lock-province') || '').trim();
      var keepEnabled = name === 'current_address_region' && lockProv && !locked;
      if (locked) {
        el.required = false;
        if (el.tagName === 'SELECT' && lockProv && name === 'current_address_region') {
          el.classList.add('pe-none');
        } else {
          el.disabled = true;
        }
      } else {
        el.disabled = false;
        if (!(lockProv && name === 'current_address_region')) {
          el.required = true;
        } else {
          el.required = true;
          el.classList.add('pe-none');
        }
        if (keepEnabled) el.disabled = false;
      }
      var searchWrap = el.closest && el.closest('.ph-search-select');
      if (searchWrap) {
        var input = searchWrap.querySelector('.ph-search-select__input');
        var lockedVisual = !!el.disabled || el.classList.contains('pe-none') || el.getAttribute('aria-readonly') === 'true';
        if (input) {
          input.disabled = lockedVisual;
          input.readOnly = el.classList.contains('pe-none') || el.getAttribute('aria-readonly') === 'true';
          if (!searchWrap.classList.contains('is-open')) {
            var opt = el.selectedIndex >= 0 ? el.options[el.selectedIndex] : null;
            input.value = opt && opt.value ? (opt.textContent || '') : '';
          }
        }
        searchWrap.classList.toggle('is-disabled', lockedVisual);
        searchWrap.classList.toggle('is-locked', !!(input && input.readOnly));
      }
    });
  }

  function bindLeyteSameModal() {
    var backdrop = document.getElementById('guideLeyteSameModal');
    if (!backdrop || backdrop._leyteBound) return;
    backdrop._leyteBound = true;
    var okBtn = document.getElementById('guideLeyteSameOk');
    function closeModal() {
      backdrop.classList.remove('is-open');
      document.body.classList.remove('login-role-open');
      setTimeout(function () {
        if (!backdrop.classList.contains('is-open')) backdrop.hidden = true;
      }, 200);
    }
    if (okBtn) okBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) closeModal();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && backdrop.classList.contains('is-open')) closeModal();
    });
    backdrop._leyteClose = closeModal;
  }

  function openLeyteSameModal() {
    bindLeyteSameModal();
    var backdrop = document.getElementById('guideLeyteSameModal');
    if (!backdrop) return false;
    backdrop.hidden = false;
    requestAnimationFrame(function () {
      backdrop.classList.add('is-open');
    });
    document.body.classList.add('login-role-open');
    return true;
  }

  function showLeyteSameWarning(wrap) {
    if (wrap) {
      var yes = wrap.querySelector('input[type="checkbox"][name="current_same_as_permanent"][value="1"]');
      if (yes) yes.checked = false;
    }
    if (typeof window.enforceGuideSameAsLeyte === 'function') {
      window.enforceGuideSameAsLeyte(true);
      return;
    }
    openLeyteSameModal();
  }

  function applySameWrap(wrap) {
    if (!wrap) return;
    var on = sameAsOn(wrap);
    if (on) {
      if (copyPermanentToCurrent(wrap) === false) {
        showLeyteSameWarning(wrap);
        on = false;
      }
    }
    setCurrentLocked(wrap, on);
    if (!on && wrap.querySelector('[data-same-current]') && wrap.querySelector('[data-same-current]')._phApply) {
      wrap.querySelector('[data-same-current]')._phApply();
    }
  }

  function bindSameAs() {
    document.querySelectorAll('[data-same-as-permanent]').forEach(function (wrap) {
      if (!wrap._sameBound) {
        wrap._sameBound = true;
        wrap.querySelectorAll('input[type="checkbox"][name="current_same_as_permanent"][value="1"]').forEach(function (el) {
          el.addEventListener('change', function () {
            applySameWrap(wrap);
          });
        });
        var perm = wrap.querySelector('[data-same-permanent]');
        if (perm) {
          perm.addEventListener('change', function () {
            if (sameAsOn(wrap)) applySameWrap(wrap);
          });
          perm.addEventListener('input', function (e) {
            if (sameAsOn(wrap) && e.target && e.target.name === 'address_street') applySameWrap(wrap);
          });
        }
      }
      applySameWrap(wrap);
    });
  }

  function boot(tree) {
    document.querySelectorAll('[data-ph-address]').forEach(function (root) {
      bindRoot(root, tree);
    });
    bindSameAs();
    document.dispatchEvent(new Event('cultour:form-ready'));
  }

  window.CulTourPhAddress = {
    init: function (dataUrl) {
      if (window.__cultourPhAddressReady) {
        boot(window.__cultourPhAddressTree || {});
        return;
      }
      fetch(dataUrl, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (tree) {
          window.__cultourPhAddressTree = tree || {};
          window.__cultourPhAddressReady = true;
          boot(window.__cultourPhAddressTree);
        })
        .catch(function () {
          window.__cultourPhAddressTree = {};
          boot({});
        });
    },
    refresh: function () {
      boot(window.__cultourPhAddressTree || {});
    },
    applySameAs: function () {
      document.querySelectorAll('[data-same-as-permanent]').forEach(applySameWrap);
    },
  };
})();
