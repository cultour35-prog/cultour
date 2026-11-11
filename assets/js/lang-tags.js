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

  function sentenceTag(value) {
    var text = String(value || '').trim().toLocaleLowerCase();
    if (!text) return '';
    return text.charAt(0).toLocaleUpperCase() + text.slice(1);
  }

  function chipBind(input) {
    if (!input || input.dataset.ctTags === '1') return;
    input.dataset.ctTags = '1';
    var box = document.createElement('div');
    box.className = 'tag-input lang-tag-input';
    var entry = document.createElement('input');
    entry.type = 'text';
    entry.className = 'tag-input__entry';
    entry.placeholder = input.getAttribute('placeholder') || 'Type a language, then press Enter';
    entry.setAttribute('aria-label', input.getAttribute('aria-label') || 'Languages');
    var tags = [];
    function paint() {
      box.querySelectorAll('.tag-input__chip').forEach(function (node) { node.remove(); });
      tags.forEach(function (text, index) {
        var chip = document.createElement('span');
        chip.className = 'tag-input__chip';
        chip.appendChild(document.createTextNode(text));
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tag-input__remove';
        btn.setAttribute('aria-label', 'Remove ' + text);
        btn.textContent = '×';
        btn.addEventListener('click', function () {
          tags.splice(index, 1);
          paint();
        });
        chip.appendChild(btn);
        box.insertBefore(chip, entry);
      });
      input.value = tags.join(', ');
    }
    function add(raw) {
      String(raw || '').split(',').forEach(function (part) {
        var text = sentenceTag(part);
        if (!text || tags.some(function (tag) { return tag.toLowerCase() === text.toLowerCase(); })) return;
        if (tags.length >= 15) return;
        tags.push(text);
      });
      paint();
    }
    add(input.value);
    input.hidden = true;
    input.setAttribute('tabindex', '-1');
    entry.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        add(entry.value);
        entry.value = '';
      } else if (e.key === 'Backspace' && entry.value === '' && tags.length) {
        tags.pop();
        paint();
      }
    });
    entry.addEventListener('blur', function () {
      if (!entry.value.trim()) return;
      add(entry.value);
      entry.value = '';
    });
    input.insertAdjacentElement('afterend', box);
    box.appendChild(entry);
  }

  function bind(input) {
    if (!input || input._ctTagify || input.dataset.ctTags === '1') return;
    if (typeof Tagify === 'undefined') {
      chipBind(input);
      return;
    }
    input._ctTagify = new Tagify(input, {
      delimiters: ',',
      maxTags: input.classList.contains('js-tag-input') ? 12 : 15,
      trim: true,
      duplicates: false,
      dropdown: { enabled: 0 },
      editTags: { clicks: 2, keepInvalid: false },
      transformTag: function (tagData) {
        tagData.value = sentenceTag(tagData.value);
      },
      originalInputValueFormat: function (values) {
        return values.map(function (item) { return item.value; }).join(', ');
      }
    });
    if (input._ctTagify.DOM && input._ctTagify.DOM.scope) {
      input._ctTagify.DOM.scope.classList.add('tagify--wrap');
      var field = input._ctTagify.DOM.input;
      if (field) {
        function syncPlaceholder() {
          var text = String(field.textContent || '').replace(/\u200B/g, '').trim();
          field.classList.toggle('is-typing', text.length > 0);
        }
        field.addEventListener('input', syncPlaceholder);
        input._ctTagify.on('add', syncPlaceholder);
        input._ctTagify.on('remove', syncPlaceholder);
        syncPlaceholder();
        field.addEventListener('keydown', function (e) {
          if (e.key !== ',' && e.key !== 'Enter') return;
          var typed = sentenceTag(field.textContent || '');
          if (!typed) return;
          e.preventDefault();
          e.stopPropagation();
          input._ctTagify.addTags([typed]);
          field.textContent = '';
        }, true);
      }
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
