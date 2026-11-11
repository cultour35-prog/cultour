(function () {
  var fields = document.querySelectorAll(".guide-profile-avail input[type='time']");
  if (!fields.length) return;

  var pop = document.createElement("div");
  pop.className = "avail-time-pop";
  pop.hidden = true;
  document.body.appendChild(pop);
  var openField = null;
  var minutes = [0, 15, 30, 45];

  fields.forEach(function (inp) {
    inp.readOnly = true;
    inp.addEventListener("mousedown", function (e) {
      if (inp.disabled) return;
      e.preventDefault();
      openPop(inp);
    });
  });

  function hour12(h24) {
    var ap = h24 >= 12 ? "PM" : "AM";
    var h = h24 % 12;
    if (h === 0) h = 12;
    return { h: h, ap: ap };
  }

  function read(inp) {
    var m = String(inp.value || "").match(/^(\d{1,2}):(\d{2})/);
    var h24 = m ? parseInt(m[1], 10) : 8;
    var min = m ? parseInt(m[2], 10) : 0;
    if (!minutes.includes(min)) min = 0;
    var parts = hour12(h24);
    return { h: parts.h, min: min, ap: parts.ap };
  }

  function write(inp, state) {
    var h24 = state.h % 12;
    if (state.ap === "PM") h24 += 12;
    inp.value = String(h24).padStart(2, "0") + ":" + String(state.min).padStart(2, "0");
    inp.dispatchEvent(new Event("input", { bubbles: true }));
    inp.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function col(title, items, current, onPick) {
    var wrap = document.createElement("div");
    wrap.className = "avail-time-pop__col";
    var label = document.createElement("div");
    label.className = "avail-time-pop__label";
    label.textContent = title;
    wrap.appendChild(label);
    var list = document.createElement("div");
    list.className = "avail-time-pop__list";
    items.forEach(function (item) {
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "avail-time-pop__opt" + (item.value === current ? " is-on" : "");
      btn.textContent = item.label;
      btn.addEventListener("click", function () { onPick(item.value); });
      list.appendChild(btn);
    });
    wrap.appendChild(list);
    return wrap;
  }

  function paint(inp) {
    var state = read(inp);
    pop.replaceChildren();
    var row = document.createElement("div");
    row.className = "avail-time-pop__row";
    var hours = [];
    for (var h = 1; h <= 12; h++) hours.push({ value: h, label: String(h) });
    row.appendChild(col("Hr", hours, state.h, function (h) {
      state.h = h;
      write(inp, state);
      paint(inp);
    }));
    row.appendChild(col("Min", minutes.map(function (min) {
      return { value: min, label: String(min).padStart(2, "0") };
    }), state.min, function (min) {
      state.min = min;
      write(inp, state);
      paint(inp);
    }));
    row.appendChild(col("", [
      { value: "AM", label: "AM" },
      { value: "PM", label: "PM" }
    ], state.ap, function (ap) {
      state.ap = ap;
      write(inp, state);
      paint(inp);
    }));
    pop.appendChild(row);
  }

  function openPop(inp) {
    openField = inp;
    paint(inp);
    pop.hidden = false;
    var rect = inp.getBoundingClientRect();
    var top = rect.bottom + 6;
    if (top + 260 > window.innerHeight) top = Math.max(8, rect.top - 260);
    pop.style.top = top + "px";
    pop.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 220)) + "px";
    var on = pop.querySelector(".is-on");
    if (on && on.parentNode) on.parentNode.scrollTop = Math.max(0, on.offsetTop - 36);
  }

  document.addEventListener("mousedown", function (e) {
    if (pop.hidden) return;
    if (pop.contains(e.target)) return;
    if (openField && (e.target === openField || openField.contains(e.target))) return;
    pop.hidden = true;
    openField = null;
  });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      pop.hidden = true;
      openField = null;
    }
  });
})();
