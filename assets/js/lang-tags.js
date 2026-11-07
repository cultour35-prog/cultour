(function () {
  function sync(input) {
    if (!input || !input._ctTagify) return;
    input._ctTagify.setReadonly(!!(input.disabled || input.readOnly));
  }

  function load(input) {
    if (!input || !input._ctTagify) return;
    input._ctTagify.loadOriginalValues();
    sync(input);
  }

  function bind(input) {
    if (!input || input._ctTagify || typeof Tagify === 'undefined') return;
    input._ctTagify = new Tagify(input, {
      delimiters: ',',
      maxTags: input.classList.contains('js-tag-input') ? 12 : 15,
      trim: true,
      duplicates: false,
      dropdown: { enabled: 0 },
      editTags: { clicks: 2, keepInvalid: false },
      originalInputValueFormat: function (values) {
        return values.map(function (item) { return item.value; }).join(', ');
      }
    });
    if (input.classList.contains('js-tag-input') && input._ctTagify.DOM && input._ctTagify.DOM.scope) {
      input._ctTagify.DOM.scope.classList.add('tagify--wrap');
      input._ctTagify.DOM.scope.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        e.stopPropagation();
      }, true);
    }
    sync(input);
  }

  function boot() {
    document.querySelectorAll('input.js-lang-tags, input.js-tag-input').forEach(bind);
  }

  window.CulTourLangTags = { sync: sync, load: load, boot: boot };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
