(function () {
  function bindLoop(board) {
    var source = board.querySelector("[data-pkg-source]");
    if (!source) return;
    var all = Array.prototype.slice.call(source.querySelectorAll("[data-pick-card]"));
    var center = board.querySelector("[data-slot=center]");
    var right = board.querySelector("[data-slot=right]");
    var empty = board.querySelector("[data-pick-empty]");
    var index = 0;
    var selected = String(board.getAttribute("data-selected") || "");
    var visible = all.slice();
    all.forEach(function (card, i) {
      if (selected && card.getAttribute("data-pick-card") === selected) index = i;
    });
    function roomsFor(id) {
      board.querySelectorAll("[data-pick-rooms]").forEach(function (row) {
        row.hidden = String(row.getAttribute("data-pick-rooms")) !== String(id);
      });
      var shelf = board.querySelector("[data-pick-shelf]");
      if (shelf) shelf.hidden = !id;
    }
    function paint() {
      if (!center) return;
      all.forEach(function (card) {
        if (card.parentElement !== source) source.appendChild(card);
      });
      center.replaceChildren();
      if (!visible.length) {
        if (empty) empty.hidden = false;
        roomsFor("");
        return;
      }
      if (empty) empty.hidden = true;
      if (index >= visible.length) index = 0;
      var current = visible[index];
      center.appendChild(current);
      if (visible.length > 1 && right) {
        var nxt = visible[(index + 1) % visible.length];
        var arrow = right.querySelector("button");
        right.insertBefore(nxt, arrow || null);
      }
      roomsFor(current.getAttribute("data-pick-card"));
    }
    var next = board.querySelector(".pick-focus__arrow--next");
    var prev = board.querySelector(".pick-focus__arrow--prev");
    if (next) next.addEventListener("click", function () { index = (index + 1) % Math.max(visible.length, 1); paint(); });
    if (prev) prev.addEventListener("click", function () { index = (index - 1 + visible.length) % Math.max(visible.length, 1); paint(); });
    var search = board.querySelector("[data-pkg-search]");
    if (search) {
      search.addEventListener("input", function () {
        var q = search.value.trim().toLowerCase();
        visible = all.filter(function (card) {
          return !q || (card.textContent || "").toLowerCase().indexOf(q) !== -1;
        });
        index = 0;
        paint();
      });
    }
    board.addEventListener("click", function (event) {
      var offer = event.target.closest("[data-pkg-offer]");
      if (!offer || !board.contains(offer)) return;
      board.querySelectorAll("[data-pkg-offer]").forEach(function (el) { el.classList.remove("is-selected"); });
      offer.classList.add("is-selected");
      var form = board.closest("form");
      if (!form) return;
      form.querySelector("[data-offer-business]").value = offer.getAttribute("data-business-id") || "";
      form.querySelector("[data-offer-service]").value = offer.getAttribute("data-service-id") || "";
      form.querySelector("[data-offer-declined]").value = "0";
      var none = form.querySelector("[data-offer-none]");
      if (none) none.checked = false;
    });
    var noneBox = board.querySelector("[data-offer-none]");
    if (noneBox) {
      noneBox.addEventListener("change", function () {
        var form = board.closest("form");
        if (!form) return;
        form.querySelector("[data-offer-declined]").value = noneBox.checked ? "1" : "0";
        if (noneBox.checked) {
          form.querySelector("[data-offer-business]").value = "0";
          form.querySelector("[data-offer-service]").value = "0";
          board.querySelectorAll("[data-pkg-offer]").forEach(function (el) { el.classList.remove("is-selected"); });
        }
      });
    }
    paint();
  }

  document.querySelectorAll("[data-pkg-loop]").forEach(bindLoop);

  var form = document.getElementById("pkgAddStop");
  var list = document.getElementById("pkgPlaceList");
  var keyInput = document.getElementById("pkgPlaceKey");
  var search = document.getElementById("pkgPlaceSearch");
  var pop = document.getElementById("pkgPlacePop");
  var pendingKey = "";
  if (search && list) {
    search.addEventListener("input", function () {
      var q = search.value.trim().toLowerCase();
      Array.prototype.forEach.call(list.querySelectorAll("[data-place-key]"), function (btn) {
        btn.hidden = q !== "" && (btn.textContent || "").toLowerCase().indexOf(q) === -1;
      });
    });
  }
  function closePop() {
    if (pop) pop.hidden = true;
    pendingKey = "";
  }
  if (pop) {
    pop.querySelectorAll("[data-pkg-pop-close]").forEach(function (btn) {
      btn.addEventListener("click", closePop);
    });
  }
  function fillPop(place) {
    var title = document.getElementById("pkgPopTitle");
    var kind = document.getElementById("pkgPopKind");
    var photo = document.getElementById("pkgPopPhoto");
    var menu = document.getElementById("pkgPopMenu");
    if (title) title.textContent = place.name || "Place";
    if (kind) kind.textContent = place.subtitle || "";
    if (photo) {
      photo.replaceChildren();
      if (place.photo) {
        var img = document.createElement("img");
        img.alt = "";
        img.src = place.photo;
        photo.appendChild(img);
      }
    }
    if (menu) {
      menu.replaceChildren();
      (place.menu || []).forEach(function (item) {
        var card = document.createElement("article");
        card.className = "pkg-pop-menu__item";
        var name = document.createElement("strong");
        name.textContent = item.name || "Item";
        var price = document.createElement("span");
        var amount = Number(item.price || 0);
        price.textContent = "₱" + amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        card.appendChild(name);
        card.appendChild(price);
        menu.appendChild(card);
      });
      if (!place.menu || !place.menu.length) {
        var empty = document.createElement("p");
        empty.className = "small text-muted mb-0";
        empty.textContent = "No menu listed yet. You can still add this place.";
        menu.appendChild(empty);
      }
    }
    if (pop) pop.hidden = false;
  }
  if (list && form && keyInput) {
    list.addEventListener("click", function (event) {
      var btn = event.target.closest("[data-place-key]");
      if (!btn) return;
      var key = btn.getAttribute("data-place-key") || "";
      if (btn.getAttribute("data-place-popup") === "1") {
        pendingKey = key;
        var raw = btn.getAttribute("data-place-json") || "{}";
        try { fillPop(JSON.parse(raw)); } catch (err) { fillPop({ name: btn.getAttribute("data-place-name") }); }
        return;
      }
      keyInput.value = key;
      form.submit();
    });
  }
  var addBtn = document.getElementById("pkgPopAdd");
  if (addBtn && form && keyInput) {
    addBtn.addEventListener("click", function () {
      if (!pendingKey) return;
      keyInput.value = pendingKey;
      form.submit();
    });
  }
})();
