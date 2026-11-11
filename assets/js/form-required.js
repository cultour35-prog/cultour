(function () {
  const FIELD_SEL = 'input[required], select[required], textarea[required]';

  function anchorFor(el) {
    return el.closest('.select-chevron') || el.closest('.phone-input, .ph-search-select, .password-field') || el;
  }
  function warnFor(el, create) {
    const anchor = anchorFor(el);
    let warn = anchor.nextElementSibling;
    if (warn && warn.classList.contains('ct-req-warn')) return warn;
    if (!create) return null;
    warn = document.createElement('div');
    warn.className = 'form-text text-danger ct-req-warn';
    anchor.insertAdjacentElement('afterend', warn);
    return warn;
  }
  function searchInputFor(el) {
    const search = el.closest('.ph-search-select');
    return search ? search.querySelector('.ph-search-select__input') : null;
  }
  function mark(el, msg) {
    el.classList.add('is-invalid');
    const si = searchInputFor(el);
    if (si) si.classList.add('is-invalid');
    const warn = warnFor(el, true);
    warn.textContent = msg;
    warn.classList.remove('d-none');
  }
  function clear(el) {
    el.classList.remove('is-invalid');
    const si = searchInputFor(el);
    if (si) si.classList.remove('is-invalid');
    const warn = warnFor(el, false);
    if (warn) warn.classList.add('d-none');
  }
  function isActive(el) {
    if (el.type === 'hidden' || el.type === 'file') return false;
    var waitingOnParent = el.tagName === 'SELECT' && el.required
      && (el.hasAttribute('data-ph-city') || el.hasAttribute('data-ph-barangay'))
      && !el.closest('fieldset[disabled], .admin-locked-form.is-locked');
    if (el.disabled && !waitingOnParent) return false;
    return !el.closest('.d-none, [hidden]');
  }
  function messageFor(el) {
    const v = String(el.value || '').trim();
    const label = window.CulTourForms ? window.CulTourForms.label(el) : '';
    if (v === '') {
      if (el.tagName === 'SELECT') return 'Choose an option for ' + (label || 'this field') + '.';
      return (label || 'This field') + ' is required.';
    }
    if (el.closest('.phone-input')) {
      var phoneDigits = v.replace(/\D/g, '');
      if (phoneDigits.charAt(0) !== '9') return 'Enter a valid mobile number.';
      if (phoneDigits.length !== 10) return 'Phone number must be exactly 10 digits.';
    }
    if (el.type === 'email' && el.validity && el.validity.typeMismatch) return 'Enter a complete email address, for example name@gmail.com.';
    if (el.minLength > 0 && v.length < el.minLength) {
      return (label || 'This field') + ' needs at least ' + el.minLength + ' characters (you have ' + v.length + ').';
    }
    return '';
  }
  function validate(form) {
    let first = null;
    form.querySelectorAll(FIELD_SEL).forEach(function (el) {
      if (!isActive(el)) {
        clear(el);
        return;
      }
      const msg = messageFor(el);
      if (msg) {
        mark(el, msg);
        if (!first) first = el;
      } else {
        clear(el);
      }
    });
    return first;
  }
  function focusField(el) {
    anchorFor(el).scrollIntoView({ block: 'center', behavior: 'smooth' });
    const target = searchInputFor(el) || el;
    try { target.focus({ preventScroll: true }); } catch (err) {}
  }
  function normalizePhone(el) {
    let digits = String(el.value || '').replace(/\D+/g, '');
    if (digits.indexOf('63') === 0 && digits.length > 10) digits = digits.slice(2);
    while (digits.charAt(0) === '0') digits = digits.slice(1);
    var formatted = window.formatPhLocal ? window.formatPhLocal(digits) : digits.slice(0, 10);
    if (el.value !== formatted) el.value = formatted;
    return formatted.replace(/\D/g, '');
  }
  function bind(form) {
    if (form._reqBound) return;
    form._reqBound = true;
    form.noValidate = true;
    form.addEventListener('input', function (e) {
      const el = e.target;
      if (!el || !el.matches || !el.matches(FIELD_SEL) || !isActive(el)) return;
      if (el.closest('.phone-input')) {
        const digits = normalizePhone(el);
        if (digits && digits.charAt(0) !== '9') mark(el, 'Enter a valid mobile number.');
        else clear(el);
        return;
      }
      if (!el.classList.contains('is-invalid')) return;
      if (!messageFor(el)) clear(el);
    });
    form.addEventListener('change', function (e) {
      const el = e.target;
      if (!el || !el.matches || !el.matches(FIELD_SEL) || !el.classList.contains('is-invalid')) return;
      if (!messageFor(el)) clear(el);
    });
    form.addEventListener('focusout', function (e) {
      const el = e.target;
      if (!el || !el.matches || !el.matches(FIELD_SEL) || !el.closest('.phone-input') || !isActive(el)) return;
      normalizePhone(el);
      const msg = String(el.value || '').trim() ? messageFor(el) : '';
      if (msg) mark(el, msg);
      else clear(el);
    });
    form.addEventListener('submit', function (e) {
      const first = validate(form);
      if (first) {
        e.preventDefault();
        e.stopImmediatePropagation();
        focusField(first);
      }
    });
  }

  window.CulTourRequired = { bind: bind, validate: validate, focusField: focusField };

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-required-check]').forEach(bind);
  });
})();
