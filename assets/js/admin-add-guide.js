(function () {
  var form = document.getElementById('adminAddGuideForm');
  if (!form) return;

  var sameWrapEl = document.getElementById('sameAsAddressWrap');
  var GUIDE_LOCK_PROV = sameWrapEl
    ? (sameWrapEl.getAttribute('data-same-lock-province') || 'Leyte')
    : 'Leyte';
  var GUIDE_LOCK_CITY = sameWrapEl
    ? (sameWrapEl.getAttribute('data-same-lock-city') || 'Ormoc City')
    : 'Ormoc City';

  function capFirst(el) {
    if (!el) return;
    var v = el.value;
    if (!v) return;
    el.value = v.charAt(0).toUpperCase() + v.slice(1);
  }
  document.querySelectorAll('#adminAddGuideForm .name-cap').forEach(function (el) {
    el.addEventListener('blur', function () { capFirst(el); });
    el.addEventListener('input', function () {
      if (el.value.length === 1) capFirst(el);
    });
  });

  function nameLettersLabel(input) {
    var n = (input && input.name) || '';
    if (/first_name$/.test(n) || n === 'first_name') return 'first name';
    if (/middle_name$/.test(n) || n === 'middle_name') return 'middle name';
    if (/last_name$/.test(n) || n === 'last_name') return 'last name';
    if (/suffix$/.test(n) || n === 'name_suffix') return 'suffix';
    if (n === 'emergency_relationship') return 'relationship';
    return 'name';
  }
  function nameLettersMessage(input) {
    var v = (input && input.value) || '';
    if (!v) return '';
    if (/\d/.test(v) || !/^[A-Za-zÀ-ÖØ-öø-ÿÑñ\s.'-]+$/.test(v)) {
      return 'Enter a valid ' + nameLettersLabel(input) + '.';
    }
    return '';
  }
  document.querySelectorAll('#adminAddGuideForm .name-letters').forEach(function (input) {
    var warnEl = input.parentElement && input.parentElement.querySelector('.name-letters-warn');
    function show(msg) {
      if (msg) {
        if (warnEl) { warnEl.textContent = msg; warnEl.classList.remove('d-none'); }
        input.classList.add('is-invalid');
      } else {
        if (warnEl) { warnEl.textContent = ''; warnEl.classList.add('d-none'); }
        input.classList.remove('is-invalid');
      }
    }
    input.addEventListener('input', function () { show(nameLettersMessage(input)); });
    input.addEventListener('blur', function () { show(nameLettersMessage(input)); });
    input._showNameLettersWarn = show;
    input._nameLettersMessage = function () { return nameLettersMessage(input); };
  });

  document.querySelectorAll('#adminAddGuideForm .select-chevron > select').forEach(function (sel) {
    if (sel.getAttribute('data-chevron-bound') === '1') return;
    sel.setAttribute('data-chevron-bound', '1');
    var wrap = sel.closest('.select-chevron');
    if (!wrap) return;
    sel.addEventListener('mousedown', function () { wrap.classList.add('is-open'); });
    sel.addEventListener('blur', function () { wrap.classList.remove('is-open'); });
    sel.addEventListener('change', function () { wrap.classList.remove('is-open'); });
  });

  function emailMessage(v) {
    var email = (v || '').trim();
    if (!email) return '';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return 'Enter a valid email.';
    return '';
  }
  var appUrl = (window.CulTour && window.CulTour.appUrl) || '';
  var emailInput = document.getElementById('accountEmail');
  if (emailInput) {
    var warnEl = emailInput.parentElement && emailInput.parentElement.querySelector('.email-check-warn');
    var checkSeq = 0;
    function showEmail(msg) {
      if (msg) {
        if (warnEl) { warnEl.textContent = msg; warnEl.classList.remove('d-none'); }
        emailInput.classList.add('is-invalid');
      } else {
        if (warnEl) { warnEl.textContent = ''; warnEl.classList.add('d-none'); }
        emailInput.classList.remove('is-invalid');
      }
    }
    function checkExists() {
      var formatMsg = emailMessage(emailInput.value);
      if (formatMsg) { emailInput._emailTaken = false; showEmail(formatMsg); return; }
      var email = (emailInput.value || '').trim().toLowerCase();
      if (!email) { emailInput._emailTaken = false; showEmail(''); return; }
      var seq = ++checkSeq;
      var roleId = emailInput.getAttribute('data-role-id') || '';
      fetch(appUrl + '/api/auth/email_available.php?email=' + encodeURIComponent(email) + '&role_id=' + encodeURIComponent(roleId), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      }).then(function (r) { return r.json(); }).then(function (data) {
        if (seq !== checkSeq) return;
        if ((emailInput.value || '').trim().toLowerCase() !== email) return;
        if (data && data.available === false) {
          emailInput._emailTaken = true;
          showEmail((data && data.message) || 'This email is already registered for this account type.');
        } else {
          emailInput._emailTaken = false;
          showEmail('');
        }
      }).catch(function () {});
    }
    emailInput.addEventListener('input', function () {
      emailInput._emailTaken = false;
      var msg = emailMessage(emailInput.value);
      showEmail(msg);
      if (!msg && (emailInput.value || '').trim()) checkExists();
    });
    emailInput.addEventListener('blur', checkExists);
    emailInput._emailMessage = function () {
      var formatMsg = emailMessage(emailInput.value);
      if (formatMsg) return formatMsg;
      if (emailInput._emailTaken) return (warnEl && warnEl.textContent) || 'This email is already registered for this account type.';
      return '';
    };
    emailInput._showEmailWarn = showEmail;
  }

  function phoneMessage(d, onBlur) {
    if (!d) return '';
    if (d.charAt(0) !== '9') return 'Enter a valid mobile number.';
    if (onBlur && d.length !== 10) return 'Phone number must be exactly 10 digits.';
    return '';
  }
  function bindPhoneField(input, warnEl) {
    if (!input) return;
    function show(msg) {
      if (!warnEl) return;
      if (msg) {
        warnEl.textContent = msg;
        warnEl.classList.remove('d-none');
        input.classList.add('is-invalid');
      } else {
        warnEl.textContent = '';
        warnEl.classList.add('d-none');
        input.classList.remove('is-invalid');
      }
    }
    input.addEventListener('input', function () {
      input.value = String(input.value || '').replace(/\D+/g, '').slice(0, 10);
      show(phoneMessage(input.value, false));
      if (window.syncTouristEmergencyDupWarn) window.syncTouristEmergencyDupWarn();
    });
    input.addEventListener('blur', function () { show(phoneMessage(input.value, true)); });
    input._showPhoneWarn = show;
  }
  var phone = document.getElementById('phoneLocal');
  var emergPhone = document.getElementById('emergPhone');
  bindPhoneField(phone, document.getElementById('phoneLocalWarn'));
  bindPhoneField(emergPhone, document.getElementById('emergPhoneWarn'));

  var emergDupWarn = document.getElementById('emergDupWarn');
  var emergSuffixNote = document.getElementById('emergSuffixNote');
  function suffixPickerValue(base) {
    var picker = document.querySelector('[data-suffix-base="' + base + '"]');
    if (!picker) return '';
    var choice = picker.querySelector('.suffix-picker__choice');
    var other = picker.querySelector('.suffix-picker__other');
    var v = choice ? choice.value : '';
    if (!v || v === 'none') return '';
    if (v === 'other') return other ? other.value.trim() : '';
    return v;
  }
  function joinNameKey(first, middle, last, suffix) {
    return [first, middle, last, suffix]
      .map(function (p) { return String(p || '').trim().replace(/\s+/g, ' '); })
      .filter(Boolean)
      .join(' ')
      .toLowerCase();
  }
  function emergencyNameParts() {
    var selfFirst = document.getElementById('firstName');
    var selfLast = document.getElementById('lastName');
    var selfMiddle = form.querySelector('[name="middle_name"]');
    var emergFirstEl = document.getElementById('emergFirst');
    var emergLastEl = document.getElementById('emergLast');
    var emergMiddleEl = form.querySelector('[name="emergency_middle_name"]');
    if (!selfFirst || !selfLast || !emergFirstEl || !emergLastEl || !phone || !emergPhone) return null;
    return {
      selfBase: joinNameKey(selfFirst.value, selfMiddle && selfMiddle.value, selfLast.value, ''),
      emergBase: joinNameKey(emergFirstEl.value, emergMiddleEl && emergMiddleEl.value, emergLastEl.value, ''),
      selfFull: joinNameKey(selfFirst.value, selfMiddle && selfMiddle.value, selfLast.value, suffixPickerValue('name_suffix')),
      emergFull: joinNameKey(emergFirstEl.value, emergMiddleEl && emergMiddleEl.value, emergLastEl.value, suffixPickerValue('emergency_suffix')),
      selfSuffix: suffixPickerValue('name_suffix'),
      emergSuffix: suffixPickerValue('emergency_suffix'),
      selfDigits: String(phone.value || '').replace(/\D+/g, ''),
      emergDigits: String(emergPhone.value || '').replace(/\D+/g, '')
    };
  }
  function emergencyDupMessage() {
    var p = emergencyNameParts();
    if (!p) return '';
    var sameFullName = p.selfFull !== '' && p.emergFull !== '' && p.selfFull === p.emergFull;
    var samePhone = p.selfDigits.length === 10 && p.emergDigits.length === 10 && p.selfDigits === p.emergDigits;
    if (sameFullName && samePhone) return 'Emergency contact cannot use your same name and phone number.';
    if (sameFullName) return 'Emergency contact cannot have the same name as you.';
    if (samePhone) return 'Emergency contact cannot use your same phone number.';
    return '';
  }
  window.syncTouristEmergencyDupWarn = function () {
    var blockMsg = emergencyDupMessage();
    if (emergDupWarn) {
      if (blockMsg) { emergDupWarn.textContent = blockMsg; emergDupWarn.classList.remove('d-none'); }
      else { emergDupWarn.textContent = ''; emergDupWarn.classList.add('d-none'); }
    }
    var p = emergencyNameParts();
    var note = '';
    if (p && p.selfBase && p.emergBase && p.selfBase === p.emergBase && String(p.selfSuffix || '').toLowerCase() !== String(p.emergSuffix || '').toLowerCase()) {
      note = 'Note: The name and emergency contact name match except for the suffix.';
    }
    if (emergSuffixNote) {
      if (note && !blockMsg) { emergSuffixNote.textContent = note; emergSuffixNote.classList.remove('d-none'); }
      else { emergSuffixNote.classList.add('d-none'); }
    }
    return blockMsg;
  };
  ['firstName', 'lastName', 'emergFirst', 'emergLast'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', window.syncTouristEmergencyDupWarn);
  });

  var sameYes = document.getElementById('addrSameYes');
  var addrRegion = document.getElementById('addrRegion');
  var addrCity = document.getElementById('addrCity');
  var backdrop = document.getElementById('guideLeyteSameModal');
  var okBtn = document.getElementById('guideLeyteSameOk');
  function permanentProvince() {
    return addrRegion ? String(addrRegion.value || '').trim() : '';
  }
  function openLeyteSameModal() {
    if (!backdrop) return;
    backdrop.hidden = false;
    requestAnimationFrame(function () { backdrop.classList.add('is-open'); });
    document.body.classList.add('login-role-open');
  }
  function closeLeyteSameModal() {
    if (!backdrop) return;
    backdrop.classList.remove('is-open');
    document.body.classList.remove('login-role-open');
    setTimeout(function () {
      if (!backdrop.classList.contains('is-open')) backdrop.hidden = true;
    }, 200);
  }
  function permanentCity() {
    return addrCity ? String(addrCity.value || '').trim() : '';
  }
  function guideSameAsNeedsLeyte() {
    if (!sameYes || !sameYes.checked) return false;
    var prov = permanentProvince();
    var city = permanentCity();
    if (prov !== '' && prov.toLowerCase() !== GUIDE_LOCK_PROV.toLowerCase()) return true;
    return city !== '' && city.toLowerCase() !== GUIDE_LOCK_CITY.toLowerCase();
  }
  function enforceGuideSameAsLeyte() {
    if (!guideSameAsNeedsLeyte()) return false;
    if (sameYes) {
      sameYes.checked = false;
      if (window.CulTourPhAddress && typeof CulTourPhAddress.applySameAs === 'function') {
        CulTourPhAddress.applySameAs();
      }
    }
    openLeyteSameModal();
    return true;
  }
  window.guideSameAsNeedsLeyte = guideSameAsNeedsLeyte;
  window.enforceGuideSameAsLeyte = enforceGuideSameAsLeyte;
  if (sameYes) {
    sameYes.addEventListener('change', function () {
      if (sameYes.checked && enforceGuideSameAsLeyte()) return;
      if (window.CulTourPhAddress && typeof CulTourPhAddress.applySameAs === 'function') {
        CulTourPhAddress.applySameAs();
      }
    });
  }
  if (addrRegion) {
    addrRegion.addEventListener('change', function () {
      if (sameYes && sameYes.checked) enforceGuideSameAsLeyte();
    });
  }
  if (addrCity) {
    addrCity.addEventListener('change', function () {
      if (sameYes && sameYes.checked) enforceGuideSameAsLeyte();
    });
  }
  if (okBtn) okBtn.addEventListener('click', closeLeyteSameModal);
  if (backdrop) {
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) closeLeyteSameModal();
    });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && backdrop && backdrop.classList.contains('is-open')) closeLeyteSameModal();
  });

  var fileBags = {};
  function bindMultiFiles(input) {
    if (!input || !window.DataTransfer) return;
    var listId = input.getAttribute('data-list');
    var listEl = listId ? document.getElementById(listId) : null;
    var countEl = input.getAttribute('data-count') ? document.getElementById(input.getAttribute('data-count')) : null;
    var menuEl = input.getAttribute('data-menu') ? document.getElementById(input.getAttribute('data-menu')) : null;
    var toggleEl = input.getAttribute('data-toggle') ? document.getElementById(input.getAttribute('data-toggle')) : null;
    var emptyEl = menuEl ? menuEl.querySelector('.file-upload-field__empty') : null;
    var key = input.id || listId || input.name;
    fileBags[key] = [];
    function syncInput() {
      var dt = new DataTransfer();
      fileBags[key].forEach(function (f) { dt.items.add(f); });
      input.files = dt.files;
    }
    function setMenuOpen(open) {
      if (!menuEl || !toggleEl) return;
      menuEl.classList.toggle('d-none', !open);
      menuEl.hidden = !open;
      toggleEl.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggleEl.classList.toggle('is-open', open);
    }
    function updateCount() {
      var n = fileBags[key].length;
      if (countEl) countEl.textContent = String(n);
      if (emptyEl) emptyEl.classList.toggle('d-none', n > 0);
    }
    function render() {
      if (!listEl) return;
      listEl.innerHTML = '';
      fileBags[key].forEach(function (file, idx) {
        var li = document.createElement('li');
        li.className = 'file-upload-field__item';
        li.innerHTML = '<span></span><button type="button" class="btn btn-sm btn-link text-danger p-0" data-remove="' + idx + '">Remove</button>';
        li.querySelector('span').textContent = file.name;
        listEl.appendChild(li);
      });
      updateCount();
    }
    function hideResumeWarn() {
      if (input.id !== 'resumeFiles') return;
      var warn = document.getElementById('resumeFilesWarn');
      if (fileBags[key].length > 0) {
        input.classList.remove('is-invalid');
        if (warn) warn.classList.add('d-none');
      }
    }
    input.addEventListener('change', function () {
      Array.prototype.forEach.call(input.files || [], function (f) { fileBags[key].push(f); });
      syncInput();
      render();
      hideResumeWarn();
    });
    if (toggleEl) {
      toggleEl.addEventListener('click', function (e) {
        e.preventDefault();
        setMenuOpen(menuEl && menuEl.hidden);
      });
    }
    if (listEl) {
      listEl.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-remove]');
        if (!btn) return;
        fileBags[key].splice(parseInt(btn.getAttribute('data-remove'), 10), 1);
        syncInput();
        render();
        hideResumeWarn();
      });
    }
    render();
  }
  document.querySelectorAll('#adminAddGuideForm .js-multi-files').forEach(bindMultiFiles);

  form.addEventListener('submit', function (e) {
    document.querySelectorAll('#adminAddGuideForm .name-cap').forEach(capFirst);
    var nameInputs = Array.prototype.slice.call(form.querySelectorAll('.name-letters'));
    for (var i = 0; i < nameInputs.length; i++) {
      var el = nameInputs[i];
      if (el.disabled) continue;
      var msg = el._nameLettersMessage ? el._nameLettersMessage() : '';
      if (msg) {
        e.preventDefault();
        if (el._showNameLettersWarn) el._showNameLettersWarn(msg);
        el.focus();
        return;
      }
    }
    if (emailInput && emailInput._emailMessage) {
      var emsg = emailInput._emailMessage();
      if (emsg) {
        e.preventDefault();
        if (emailInput._showEmailWarn) emailInput._showEmailWarn(emsg);
        emailInput.focus();
        return;
      }
    }
    if (window.guideSameAsNeedsLeyte && window.guideSameAsNeedsLeyte()) {
      e.preventDefault();
      if (window.enforceGuideSameAsLeyte) window.enforceGuideSameAsLeyte();
      return;
    }
    var relPicker = form.querySelector('[data-suffix-base="emergency_relationship"]');
    if (relPicker) {
      var choice = relPicker.querySelector('.suffix-picker__choice');
      var other = relPicker.querySelector('.suffix-picker__other');
      var val = choice ? choice.value : '';
      if (!val || (val === 'other' && other && !other.value.trim())) {
        e.preventDefault();
        var menu = relPicker.querySelector('.suffix-picker__menu');
        var toggle = relPicker.querySelector('.suffix-picker__toggle');
        if (menu) menu.classList.remove('d-none');
        if (toggle) { toggle.setAttribute('aria-expanded', 'true'); toggle.focus(); }
        relPicker.classList.add('is-open');
        return;
      }
    }
    if (phone) {
      var pmsg = phoneMessage(phone.value, true);
      if (pmsg) {
        e.preventDefault();
        if (phone._showPhoneWarn) phone._showPhoneWarn(pmsg);
        phone.focus();
        return;
      }
    }
    if (emergPhone) {
      var epmsg = phoneMessage(emergPhone.value, true);
      if (epmsg) {
        e.preventDefault();
        if (emergPhone._showPhoneWarn) emergPhone._showPhoneWarn(epmsg);
        emergPhone.focus();
        return;
      }
    }
    var dupMsg = window.syncTouristEmergencyDupWarn ? window.syncTouristEmergencyDupWarn() : '';
    if (dupMsg) {
      e.preventDefault();
      if (emergDupWarn) emergDupWarn.scrollIntoView({ block: 'nearest' });
      return;
    }
    var resume = document.getElementById('resumeFiles');
    if (resume && (!resume.files || resume.files.length < 1)) {
      e.preventDefault();
      resume.classList.add('is-invalid');
      var resumeWarn = document.getElementById('resumeFilesWarn');
      if (resumeWarn) resumeWarn.classList.remove('d-none');
      resume.focus();
    }
  });
})();
