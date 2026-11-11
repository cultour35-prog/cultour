(function () {
  function paint(input) {
    var label = input.parentNode && input.parentNode.querySelector(".mdy-date__label");
    if (!label) return;
    var value = input.value || "";
    var parts = value.split("-");
    var text = label.querySelector(".mdy-date__label") || label;
    if (parts.length !== 3 || parts[0].length < 4) {
      text.textContent = "mm/dd/yy";
      label.classList.add("is-empty");
      return;
    }
    text.textContent = parts[1] + "/" + parts[2] + "/" + parts[0].slice(-2);
    label.classList.remove("is-empty");
  }

  function wire(input) {
    if (!input || input.dataset.mdyReady === "1" || input.type !== "date") return;
    if (input.closest(".mdy-date") || input.hasAttribute("data-itin-day-date-input")) return;
    input.dataset.mdyReady = "1";
    var wrap = document.createElement("span");
    wrap.className = "mdy-date";
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var label = document.createElement("span");
    label.className = input.className + " mdy-date__face is-empty";
    label.innerHTML = "<span class=\"mdy-date__label\">mm/dd/yy</span><i class=\"bi bi-calendar3\" aria-hidden=\"true\"></i>";
    wrap.insertBefore(label, input);
    input.addEventListener("click", function () {
      if (input.disabled || typeof input.showPicker !== "function") return;
      try { input.showPicker(); } catch (err) {}
    });
    input.addEventListener("input", function () { paint(input); });
    input.addEventListener("change", function () {
      paint(input);
      label.classList.remove("is-invalid");
    });
    input.addEventListener("invalid", function () {
      label.classList.add("is-invalid");
    });
    var proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, "value");
    if (proto && proto.get && proto.set) {
      Object.defineProperty(input, "value", {
        configurable: true,
        get: function () { return proto.get.call(this); },
        set: function (next) {
          proto.set.call(this, next);
          paint(this);
        }
      });
    }
    paint(input);
  }

  function scan(root) {
    var scope = root && root.querySelectorAll ? root : document;
    if (scope.matches && scope.matches("input[type='date']")) wire(scope);
    scope.querySelectorAll("input[type='date']").forEach(wire);
  }

  scan(document);
  var observer = new MutationObserver(function (records) {
    records.forEach(function (record) {
      record.addedNodes.forEach(function (node) {
        if (node.nodeType === 1) scan(node);
      });
    });
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });
})();
