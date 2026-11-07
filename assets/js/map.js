/**
 * CulTour Leaflet map helpers — Ormoc-locked maps, category pin colors, live legend
 */
(function (global) {
  /* Matches Google Maps place: Ormoc City, Leyte (@11.0545641,124.6054762,~12z) */
  const DEFAULT_CENTER = [11.0545641, 124.6054762];
  const DEFAULT_ZOOM = 12;
  const MIN_ZOOM = 10;
  const MAX_ZOOM = 18;

  /* Official Ormoc City boundary — Google Maps / OSM relation 5426241 */
  const ORMOC_POLYGON = [
    [11.07931, 124.47717],
    [11.05117, 124.49589],
    [11.04085, 124.50195],
    [11.03303, 124.5069],
    [11.02477, 124.51242],
    [11.01527, 124.5183],
    [10.99798, 124.5303],
    [10.99404, 124.53259],
    [10.98066, 124.54036],
    [10.9801, 124.54102],
    [10.8781395, 124.6169004],
    [10.94532, 124.67073],
    [10.96146, 124.68395],
    [10.962114, 124.684491],
    [10.97978, 124.69912],
    [10.99824, 124.71352],
    [11.0000537, 124.7384729],
    [11.0037794, 124.7897305],
    [11.03055, 124.78374],
    [11.04824, 124.7766],
    [11.05267, 124.7757],
    [11.06397, 124.76493],
    [11.08335, 124.74796],
    [11.10243, 124.72938],
    [11.10729, 124.72382],
    [11.11338, 124.71822],
    [11.12075, 124.71143],
    [11.12766, 124.70439],
    [11.13692, 124.69567],
    [11.15655, 124.67773],
    [11.15323, 124.65743],
    [11.1418, 124.642],
    [11.13319, 124.62904],
    [11.12496, 124.62552],
    [11.10852, 124.62075],
    [11.10653, 124.61909],
    [11.10677, 124.61288],
    [11.10934, 124.60291],
    [11.11114, 124.58908],
    [11.11243, 124.58109],
    [11.1161, 124.56098],
    [11.12443, 124.55516],
    [11.12832, 124.55329],
    [11.13305, 124.55179],
    [11.13678, 124.54964],
    [11.1392, 124.54729],
    [11.14032, 124.54425],
    [11.14032, 124.54127],
    [11.13966, 124.5384],
    [11.13816, 124.53349],
    [11.13686, 124.5295],
    [11.13298, 124.52016],
    [11.12914, 124.51141],
    [11.12082, 124.50555],
    [11.11298, 124.50038],
    [11.1124596, 124.4999737],
    [11.10463, 124.49386],
    [11.07931, 124.47717]
  ];

  const ORMOC_BOUNDS = L.latLngBounds(ORMOC_POLYGON);
  const VIEW_BOUNDS = ORMOC_BOUNDS.pad(0.22);

  function ensureLeaflet(cb) {
    if (typeof L !== 'undefined') {
      cb();
      return;
    }
    console.error('Leaflet is not loaded');
  }

  function clampToOrmoc(lat, lng) {
    const point = L.latLng(lat, lng);
    if (pointInOrmoc(point)) return [lat, lng];
    const sw = ORMOC_BOUNDS.getSouthWest();
    const ne = ORMOC_BOUNDS.getNorthEast();
    return [
      Math.min(Math.max(lat, sw.lat), ne.lat),
      Math.min(Math.max(lng, sw.lng), ne.lng),
    ];
  }

  function pointInOrmoc(latlng) {
    const x = latlng.lng;
    const y = latlng.lat;
    let inside = false;
    for (let i = 0, j = ORMOC_POLYGON.length - 1; i < ORMOC_POLYGON.length; j = i++) {
      const yi = ORMOC_POLYGON[i][0];
      const xi = ORMOC_POLYGON[i][1];
      const yj = ORMOC_POLYGON[j][0];
      const xj = ORMOC_POLYGON[j][1];
      const intersect =
        yi > y !== yj > y &&
        x < ((xj - xi) * (y - yi)) / (yj - yi + 0.00000001) + xi;
      if (intersect) inside = !inside;
    }
    return inside;
  }

  function highlightOrmocArea(map) {
    /* Soft dim outside the city (Google Maps place view) */
    L.polygon(
      [
        [
          [-90, -180],
          [-90, 180],
          [90, 180],
          [90, -180],
        ],
        ORMOC_POLYGON,
      ],
      {
        stroke: false,
        fillColor: '#dfe3e6',
        fillOpacity: 0.45,
        interactive: false,
      }
    ).addTo(map);

    /* Light green wash inside, pink dashed outline */
    L.polygon(ORMOC_POLYGON, {
      color: '#E07A7A',
      weight: 2,
      opacity: 0.95,
      dashArray: '7 7',
      fillColor: '#B7D9A8',
      fillOpacity: 0.22,
      interactive: false,
      lineJoin: 'round',
      lineCap: 'round',
    }).addTo(map);
  }

  function createMap(el, opts) {
    opts = opts || {};
    let center = opts.center || DEFAULT_CENTER;
    center = clampToOrmoc(center[0], center[1]);
    const zoom = opts.zoom != null ? opts.zoom : DEFAULT_ZOOM;

    const map = L.map(el, {
      scrollWheelZoom: true,
      minZoom: MIN_ZOOM,
      maxZoom: MAX_ZOOM,
      maxBounds: VIEW_BOUNDS,
      maxBoundsViscosity: 0.95,
    }).setView(center, Math.max(zoom, MIN_ZOOM));

    el._leaflet_map = map;

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: MAX_ZOOM,
      attribution: '&copy; OpenStreetMap · Ormoc City',
    }).addTo(map);

    highlightOrmocArea(map);

    map.on('drag', function () {
      map.panInsideBounds(VIEW_BOUNDS, { animate: false });
    });

    setTimeout(function () {
      map.invalidateSize();
    }, 120);
    setTimeout(function () {
      map.invalidateSize();
    }, 400);
    if (typeof ResizeObserver !== 'undefined' && !el._ctMapResizeObs) {
      el._ctMapResizeObs = new ResizeObserver(function () {
        map.invalidateSize();
      });
      el._ctMapResizeObs.observe(el);
    }
    return map;
  }

  function largeMarkerIcon(color, label, status) {
    let fill = color || '#1aad6f';
    if (!/^#[0-9A-Fa-f]{6}$/i.test(String(fill))) fill = '#1aad6f';
    const statusHtml = status
      ? '<span class="ct-map-pin__status">' + escapeHtml(status) + '</span>'
      : '';
    return L.divIcon({
      className: 'ct-map-pin ct-map-pin--lg' + (status ? ' ct-map-pin--status' : ''),
      html:
        statusHtml +
        '<span class="ct-map-pin__dot ct-map-pin__dot--lg" style="background:' +
        fill +
        '"><span class="ct-map-pin__num">' +
        escapeHtml(label || '') +
        '</span></span>',
      iconSize: status ? [72, 68] : [46, 46],
      iconAnchor: status ? [36, 64] : [23, 42],
    });
  }

  function markerIcon(kind, color, label) {
    const defaults = {
      destination: '#0e8a7a',
      business: '#c4a35a',
      pin: '#c44536',
    };
    let fill = color || defaults[kind] || defaults.destination;
    if (!/^#[0-9A-Fa-f]{6}$/i.test(String(fill))) {
      fill = defaults[kind] || defaults.destination;
    }
    const labelHtml = label
      ? '<span class="ct-map-pin__label" style="font-size:9px;padding:1px 5px;line-height:1.2;font-weight:600">' +
        escapeHtml(label) +
        '</span>'
      : '';
    return L.divIcon({
      className: 'ct-map-pin' + (label ? ' ct-map-pin--labeled' : ''),
      html:
        '<span class="ct-map-pin__dot" style="background:' +
        fill +
        '"></span>' +
        labelHtml,
      iconSize: label ? [72, 22] : [22, 22],
      iconAnchor: label ? [11, 18] : [11, 11],
    });
  }

  /** Show pin text labels only when zoomed in enough (or while searching) */
  const PIN_LABEL_MIN_ZOOM = 14;
  function syncPinLabels(map, forceShow) {
    if (!map || !map.getContainer) return;
    const hide = !forceShow && map.getZoom() < PIN_LABEL_MIN_ZOOM;
    map.getContainer().classList.toggle('ct-hide-pin-labels', hide);
  }
  function bindPinLabelZoom(map) {
    if (!map || map._ctPinLabelsBound) return;
    map._ctPinLabelsBound = true;
    map.on('zoom zoomend', function () {
      const state = map._ctExploreState;
      const searching = !!(state && String(state.searchQuery || '').trim());
      syncPinLabels(map, searching);
    });
    syncPinLabels(map);
  }

  function pinLabelText(m) {
    const raw = String(m.label || m.title || '').trim();
    if (!raw) return '';
    return raw.length > 28 ? raw.slice(0, 27) + '…' : raw;
  }

  function appUrl() {
    return (global.CULTOUR && global.CULTOUR.appUrl) || '';
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function escapeAttr(s) {
    return escapeHtml(s).replace(/'/g, '&#39;');
  }

  function ensureLegendEl(mapEl, mode) {
    if (!mapEl) return null;

    // Place legend beside the map in a shared row wrapper
    let row = mapEl.closest('.map-with-legend');
    if (!row) {
      row = document.createElement('div');
      row.className = 'map-with-legend';
      const parent = mapEl.parentElement;
      if (!parent) return null;
      parent.insertBefore(row, mapEl);
      row.appendChild(mapEl);
    }
    if (mode === 'nearby') {
      row.classList.add('map-with-legend--nearby');
      mapEl.classList.add('ct-map--nearby');
    }

    let legend = row.querySelector('.ct-cat-legend');
    if (!legend) {
      legend = document.createElement('div');
      legend.className = 'ct-cat-legend';
      if (mode === 'nearby') {
        legend.innerHTML =
          '<div class="ct-cat-legend__block">' +
          '<div class="ct-cat-legend__title">Nearby places</div>' +
          '<div class="ct-cat-legend__items" data-legend="nearby"></div>' +
          '</div>';
      } else {
        legend.innerHTML =
          '<div class="ct-cat-legend__block">' +
          '<div class="ct-cat-legend__title">Destination categories</div>' +
          '<div class="ct-cat-legend__items" data-legend="categories"></div>' +
          '</div>' +
          '<div class="ct-cat-legend__block">' +
          '<div class="ct-cat-legend__title">Business categories</div>' +
          '<div class="ct-cat-legend__items" data-legend="types"></div>' +
          '</div>';
      }
      row.appendChild(legend);
    }
    return legend;
  }

  function renderLegendGroup(box, items, emptyText) {
    if (!box) return;
    if (!items || !items.length) {
      box.innerHTML =
        '<span class="ct-cat-legend__empty">' +
        escapeHtml(emptyText || 'None yet') +
        '</span>';
      return;
    }
    box.innerHTML = items
      .map(function (c) {
        const color = c.color || '#0e8a7a';
        const name = escapeHtml(c.name || '');
        const label = c.href
          ? '<a class="ct-cat-legend__link" href="' + escapeAttr(c.href) + '">' + name + '</a>'
          : name;
        const sub = c.sub
          ? '<span class="ct-cat-legend__sub">' + escapeHtml(c.sub) + '</span>'
          : '';
        return (
          '<span class="ct-cat-legend__item">' +
          '<span class="ct-cat-legend__swatch" style="background:' +
          escapeAttr(color) +
          '"></span>' +
          '<span class="ct-cat-legend__name">' +
          label +
          sub +
          '</span></span>'
        );
      })
      .join('');
  }

  function renderMapLegend(legendEl, categories, businessTypes) {
    if (!legendEl) return;
    renderLegendGroup(
      legendEl.querySelector('[data-legend="categories"]'),
      categories
    );
    renderLegendGroup(
      legendEl.querySelector('[data-legend="types"]'),
      businessTypes
    );
  }

  function optionHtml(value, label, color) {
    return (
      '<option value="' +
      escapeAttr(value) +
      '" data-color="' +
      escapeAttr(color || '') +
      '">' +
      escapeHtml(label) +
      '</option>'
    );
  }

  function syncFilterDropdowns(state, categories, businessTypes) {
    const host = state.filterExtra;
    if (!host) return;

    state.categories = categories || [];
    state.businessTypes = businessTypes || [];

    if (!host._built) {
      host.className = 'map-subfilter';
      host.innerHTML =
        '<div class="map-subfilter__row" data-sub="dest" hidden>' +
        '<label class="map-subfilter__label">Category</label>' +
        '<select class="form-select form-select-sm map-subfilter__select" data-sub-select="dest"></select>' +
        '</div>' +
        '<div class="map-subfilter__row" data-sub="biz" hidden>' +
        '<label class="map-subfilter__label">Business category</label>' +
        '<select class="form-select form-select-sm map-subfilter__select" data-sub-select="biz"></select>' +
        '</div>';
      host._built = true;

      host.addEventListener('change', function (e) {
        const sel = e.target.closest('[data-sub-select]');
        if (!sel) return;
        setMapFilter(state, sel.value || 'all');
      });
    }

    const destSel = host.querySelector('[data-sub-select="dest"]');
    const bizSel = host.querySelector('[data-sub-select="biz"]');
    if (destSel) {
      destSel.innerHTML =
        optionHtml('destinations', 'All destination categories', '') +
        state.categories
          .map(function (c) {
            return optionHtml('cat:' + c.id, c.name, c.color);
          })
          .join('');
    }
    if (bizSel) {
      bizSel.innerHTML =
        optionHtml('businesses', 'All business categories', '') +
        state.businessTypes
          .map(function (t) {
            return optionHtml('type:' + t.id, t.name, t.color);
          })
          .join('');
    }

    updateSubfilterVisibility(state);
  }

  function updateSubfilterVisibility(state) {
    const host = state.filterExtra;
    if (!host) return;
    const filter = state.filter || 'all';
    const destRow = host.querySelector('[data-sub="dest"]');
    const bizRow = host.querySelector('[data-sub="biz"]');
    const destSel = host.querySelector('[data-sub-select="dest"]');
    const bizSel = host.querySelector('[data-sub-select="biz"]');

    const showDest = filter === 'destinations' || filter.indexOf('cat:') === 0;
    const showBiz = filter === 'businesses' || filter.indexOf('type:') === 0;

    if (destRow) destRow.hidden = !showDest;
    if (bizRow) bizRow.hidden = !showBiz;

    if (destSel && showDest) {
      const val =
        filter.indexOf('cat:') === 0 ? filter : 'destinations';
      if (destSel.value !== val) destSel.value = val;
    }
    if (bizSel && showBiz) {
      const val = filter.indexOf('type:') === 0 ? filter : 'businesses';
      if (bizSel.value !== val) bizSel.value = val;
    }
  }

  function primaryFilterKey(filter) {
    if (!filter || filter === 'all') return 'all';
    if (filter === 'destinations' || filter.indexOf('cat:') === 0) {
      return 'destinations';
    }
    if (filter === 'businesses' || filter.indexOf('type:') === 0) {
      return 'businesses';
    }
    return filter;
  }

  function popupHtml(m) {
    let html = '<strong>' + escapeHtml(m.title || 'Place') + '</strong>';
    if (m.subtitle) {
      html +=
        '<div class="small text-muted">' + escapeHtml(m.subtitle) + '</div>';
    }
    if (m.href) {
      html +=
        '<a class="ct-map-popup-link" href="' +
        escapeAttr(m.href) +
        '">View details</a>';
    }
    return html;
  }

  function addExploreMarker(map, m, layerGroup) {
    if (m.lat == null || m.lng == null) return null;
    const lat = Number(m.lat);
    const lng = Number(m.lng);
    if (Number.isNaN(lat) || Number.isNaN(lng)) return null;
    if (!pointInOrmoc(L.latLng(lat, lng))) return null;
    const label = pinLabelText(m);
    const marker = L.marker([lat, lng], {
      icon: markerIcon(m.kind || 'destination', m.color, label),
      zIndexOffset: label ? 500 : 0,
    });
    marker.bindPopup(popupHtml(m));
    marker._ctMeta = {
      id: m.id,
      category_id: m.category_id,
      type_id: m.type_id,
      color: m.color,
      kind: m.kind || 'destination',
      label: label,
      title: m.title || '',
      subtitle: m.subtitle || '',
      search: ((m.title || '') + ' ' + (m.subtitle || '') + ' ' + label).toLowerCase(),
    };
    marker.addTo(layerGroup || map);
    return marker;
  }

  function syncMarkerGroup(state, list, kind, prefix) {
    const seen = {};
    const layer =
      kind === 'business' ? state.bizLayer : state.destLayer;
    (list || []).forEach(function (m) {
      m.kind = kind;
      const key = prefix + m.id;
      seen[key] = true;
      const existing = state.markerIndex[key];
      if (existing) {
        const nextLabel = pinLabelText(m);
        if (existing._ctMeta.color !== m.color || existing._ctMeta.label !== nextLabel) {
          existing.setIcon(markerIcon(kind, m.color, nextLabel));
          existing._ctMeta.color = m.color;
          existing._ctMeta.label = nextLabel;
        }
        existing._ctMeta.category_id = m.category_id;
        existing._ctMeta.type_id = m.type_id;
        existing._ctMeta.title = m.title || '';
        existing._ctMeta.subtitle = m.subtitle || '';
        existing._ctMeta.search =
          ((m.title || '') + ' ' + (m.subtitle || '') + ' ' + nextLabel).toLowerCase();
        existing.setPopupContent(popupHtml(m));
        const ll = existing.getLatLng();
        if (
          m.lat != null &&
          m.lng != null &&
          (ll.lat !== Number(m.lat) || ll.lng !== Number(m.lng))
        ) {
          existing.setLatLng([Number(m.lat), Number(m.lng)]);
        }
      } else {
        const mk = addExploreMarker(state.map, m, layer);
        if (mk) state.markerIndex[key] = mk;
      }
    });
    Object.keys(state.markerIndex).forEach(function (key) {
      if (key.indexOf(prefix) !== 0 || seen[key]) return;
      const mk = state.markerIndex[key];
      if (mk && layer) layer.removeLayer(mk);
      delete state.markerIndex[key];
    });
    setMapFilter(state, state.filter || 'all');
  }

  function applyLiveState(state, data) {
    if (!data || !data.ok) return;

    const chipKey = JSON.stringify([
      data.categories || [],
      data.business_types || [],
    ]);
    if (chipKey !== state.chipKey) {
      state.chipKey = chipKey;
      renderMapLegend(
        state.legend,
        data.categories || [],
        data.business_types || []
      );
      syncFilterDropdowns(state, data.categories || [], data.business_types || []);
    }

    const colorByCat = {};
    (data.categories || []).forEach(function (c) {
      colorByCat[String(c.id)] = c.color;
    });
    const colorByType = {};
    (data.business_types || []).forEach(function (t) {
      colorByType[String(t.id)] = t.color;
    });

    if (data.version && data.version === state.version) {
      return;
    }
    state.version = data.version || state.version;

    const destinations = (data.destinations || []).map(function (m) {
      if (m.category_id != null && colorByCat[String(m.category_id)]) {
        m.color = colorByCat[String(m.category_id)];
      }
      return m;
    });
    const businesses = (data.businesses || []).map(function (m) {
      if (m.type_id != null && colorByType[String(m.type_id)]) {
        m.color = colorByType[String(m.type_id)];
      }
      return m;
    });
    syncMarkerGroup(state, destinations, 'destination', 'd:');
    syncMarkerGroup(state, businesses, 'business', 'b:');
  }

  function markerMatchesFilter(meta, filter, query) {
    if (!markerMatchesKindFilter(meta, filter)) return false;
    const q = (query || '').trim().toLowerCase();
    if (!q) return true;
    const hay = meta.search || ((meta.title || '') + ' ' + (meta.subtitle || '')).toLowerCase();
    return hay.indexOf(q) !== -1;
  }

  function markerMatchesKindFilter(meta, filter) {
    if (!filter || filter === 'all') return true;
    if (filter === 'destinations') return meta.kind === 'destination';
    if (filter === 'businesses') return meta.kind === 'business';
    if (filter.indexOf('cat:') === 0) {
      return (
        meta.kind === 'destination' &&
        String(meta.category_id) === filter.slice(4)
      );
    }
    if (filter.indexOf('type:') === 0) {
      return meta.kind === 'business' && String(meta.type_id) === filter.slice(5);
    }
    return true;
  }

  function setMapFilter(state, filter) {
    const next = filter || 'all';
    state.filter = next;

    // Keep both layer groups on the map; show/hide individual markers
    if (!state.map.hasLayer(state.destLayer)) state.destLayer.addTo(state.map);
    if (!state.map.hasLayer(state.bizLayer)) state.bizLayer.addTo(state.map);

    const query = state.searchQuery || '';
    Object.keys(state.markerIndex).forEach(function (key) {
      const mk = state.markerIndex[key];
      if (!mk || !mk._ctMeta) return;
      const meta = mk._ctMeta;
      const layer = meta.kind === 'business' ? state.bizLayer : state.destLayer;
      const show = markerMatchesFilter(meta, next, query);
      if (show) {
        if (!layer.hasLayer(mk)) layer.addLayer(mk);
      } else if (layer.hasLayer(mk)) {
        layer.removeLayer(mk);
      }
    });

    // While searching, always show name labels on matching pins
    syncPinLabels(state.map, !!String(query).trim());

    updateSubfilterVisibility(state);

    const primary = primaryFilterKey(next);
    if (state.filterRoot) {
      state.filterRoot.querySelectorAll('[data-map-filter]').forEach(function (btn) {
        const key = btn.getAttribute('data-map-filter');
        const active = key === primary || key === next;
        btn.classList.toggle('is-active', active);
        btn.classList.toggle('fw-semibold', active);
        if (btn.tagName === 'BUTTON') {
          btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        }
      });
    }
  }

  function startLivePolling(state, opts) {
    const live = opts.live !== false;
    const pollMs = opts.pollMs || 2500;

    function poll() {
      fetch(appUrl() + '/api/map/state.php', {
        credentials: 'same-origin',
        cache: 'no-store',
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          applyLiveState(state, data);
        })
        .catch(function () {});
    }

    if (!live) return;
    poll();
    if (state.timer) clearInterval(state.timer);
    state.timer = setInterval(poll, pollMs);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });
  }

  /**
   * Explore map + live category legend beside the map (stacks below on small screens).
   * opts.filter: 'all' | 'destinations' | 'businesses'
   * opts.filterRoot: selector/element containing [data-map-filter] buttons
   */
  function renderExploreMap(containerId, markers, opts) {
    opts = opts || {};
    ensureLeaflet(function () {
      const el = document.getElementById(containerId);
      if (!el) return;
      const nearbyMode = Array.isArray(opts.nearbyPlaces);
      const legend = ensureLegendEl(el, nearbyMode ? 'nearby' : 'categories');
      if (nearbyMode) {
        renderLegendGroup(
          legend.querySelector('[data-legend="nearby"]'),
          opts.nearbyPlaces,
          'None nearby'
        );
      } else if (opts.categories || opts.businessTypes) {
        renderMapLegend(legend, opts.categories || [], opts.businessTypes || []);
      }
      const map = createMap(el, opts);
      setTimeout(function () {
        map.invalidateSize();
      }, 50);
      const destLayer = L.layerGroup();
      const bizLayer = L.layerGroup();
      const markerIndex = {};
      const points = [];
      let openMarker = null;

      (markers || []).forEach(function (m) {
        const kind = m.kind || 'destination';
        const layer = kind === 'business' ? bizLayer : destLayer;
        const mk = addExploreMarker(map, m, layer);
        if (!mk) return;
        if (m.id != null) {
          markerIndex[(kind === 'business' ? 'b:' : 'd:') + m.id] = mk;
        }
        points.push(mk.getLatLng());
        if (m.open) openMarker = mk;
      });

      if (opts.fit !== false) {
        // Default: full Ormoc City polygon (same framing as Google Maps place view)
        map.fitBounds(ORMOC_BOUNDS, {
          padding: [28, 28],
          maxZoom: DEFAULT_ZOOM,
          animate: false,
        });
        if (opts.fitMarkers && points.length === 1) {
          map.setView(points[0], 15);
        } else if (opts.fitMarkers && points.length > 1) {
          map.fitBounds(L.latLngBounds(points).pad(0.12), {
            padding: [40, 40],
            maxZoom: 15,
            animate: false,
          });
          if (map.getZoom() < MIN_ZOOM) map.setZoom(MIN_ZOOM);
          map.panInsideBounds(ORMOC_BOUNDS, { animate: false });
        }
      }

      if (openMarker) {
        setTimeout(function () {
          openMarker.openPopup();
        }, 200);
      }

      bindPinLabelZoom(map);
      // After fitBounds, zoom may change — re-sync label visibility
      setTimeout(function () {
        syncPinLabels(map);
      }, 250);

      let filterRoot = null;
      if (opts.filterRoot) {
        filterRoot =
          typeof opts.filterRoot === 'string'
            ? document.querySelector(opts.filterRoot)
            : opts.filterRoot;
      }
      let filterExtra = null;
      if (filterRoot) {
        filterExtra = filterRoot.querySelector('[data-map-filter-extra]');
        if (!filterExtra) {
          filterExtra = document.createElement('div');
          filterExtra.setAttribute('data-map-filter-extra', '1');
          filterRoot.appendChild(filterExtra);
        }
      }

      const state = {
        map: map,
        destLayer: destLayer,
        bizLayer: bizLayer,
        layer: destLayer,
        markerIndex: markerIndex,
        legend: legend,
        filterRoot: filterRoot,
        filterExtra: filterExtra,
        filter: opts.filter || 'all',
        searchQuery: '',
        categories: [],
        businessTypes: [],
        version: null,
        chipKey: null,
        timer: null,
      };
      el._ctLive = state;
      el._leaflet_map = map;
      map._ctExploreState = state;

      setMapFilter(state, state.filter);

      if (filterRoot) {
        filterRoot.addEventListener('click', function (e) {
          const btn = e.target.closest('[data-map-filter]');
          if (!btn || !filterRoot.contains(btn)) return;
          // Ignore links (Pin destinations, Full map, etc.)
          if (btn.tagName === 'A') return;
          e.preventDefault();
          setMapFilter(state, btn.getAttribute('data-map-filter'));
        });

        const searchInput = filterRoot.querySelector('[data-map-search]');
        if (searchInput) {
          let searchTimer = null;
          const applySearch = function () {
            state.searchQuery = String(searchInput.value || '');
            setMapFilter(state, state.filter || 'all');
          };
          searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(applySearch, 120);
          });
          searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
              searchInput.value = '';
              applySearch();
              searchInput.blur();
            }
          });
        }
      }

      startLivePolling(state, opts);
    });
  }

  function renderPinPicker(containerId, opts) {
    opts = opts || {};
    ensureLeaflet(function () {
      const el = document.getElementById(containerId);
      if (!el) return;
      const latInput = opts.latInput
        ? document.querySelector(opts.latInput)
        : null;
      const lngInput = opts.lngInput
        ? document.querySelector(opts.lngInput)
        : null;

      let lat =
        opts.initialLat != null
          ? Number(opts.initialLat)
          : latInput && latInput.value
            ? Number(latInput.value)
            : null;
      let lng =
        opts.initialLng != null
          ? Number(opts.initialLng)
          : lngInput && lngInput.value
            ? Number(lngInput.value)
            : null;

      if (lat != null && lng != null && !Number.isNaN(lat) && !Number.isNaN(lng)) {
        const clamped = clampToOrmoc(lat, lng);
        lat = clamped[0];
        lng = clamped[1];
      }

      const hasPin =
        lat != null && lng != null && !Number.isNaN(lat) && !Number.isNaN(lng);
      const map = createMap(el, {
        center: hasPin ? [lat, lng] : DEFAULT_CENTER,
        zoom: hasPin ? 15 : DEFAULT_ZOOM,
      });

      const forcedReadOnly = !!opts.readOnly;
      const canEdit = !forcedReadOnly;
      let locked = forcedReadOnly || !!opts.lockUntilEdit;
      const pinKind = opts.kind === 'business' ? 'business' : 'destination';
      const colorSelect = opts.colorSelect ? document.querySelector(opts.colorSelect) : null;

      function activePinColor() {
        if (colorSelect && colorSelect.selectedOptions && colorSelect.selectedOptions[0]) {
          const fromOpt = colorSelect.selectedOptions[0].getAttribute('data-pin-color');
          if (fromOpt) return fromOpt;
        }
        return opts.color || null;
      }

      let pin = null;
      let hint = el.querySelector('.ct-map-hint');
      let editBtn = el.querySelector('.ct-map-edit-pin');

      function onMapClick(e) {
        if (locked) return;
        if (!pointInOrmoc(e.latlng)) return;
        setPin(e.latlng.lat, e.latlng.lng, true);
      }

      function bindDrag(marker) {
        marker.on('drag', function (e) {
          if (locked) return;
          const p = e.target.getLatLng();
          if (!pointInOrmoc(p)) {
            const c = clampToOrmoc(p.lat, p.lng);
            e.target.setLatLng(c);
          }
        });
        marker.on('dragend', function () {
          if (locked) return;
          const p = pin.getLatLng();
          const c = clampToOrmoc(p.lat, p.lng);
          pin.setLatLng(c);
          sync(c[0], c[1]);
        });
      }

      function setPin(newLat, newLng, writeInputs) {
        const clamped = clampToOrmoc(newLat, newLng);
        lat = clamped[0];
        lng = clamped[1];
        if (pin) {
          pin.setLatLng([lat, lng]);
        } else {
          pin = L.marker([lat, lng], {
            icon: markerIcon(pinKind, activePinColor()),
            draggable: canEdit && !locked,
          }).addTo(map);
          bindDrag(pin);
        }
        applyPinLock();
        if (writeInputs) sync(lat, lng);
      }

      function sync(a, b) {
        if (latInput) latInput.value = Number(a).toFixed(7);
        if (lngInput) lngInput.value = Number(b).toFixed(7);
        if (typeof opts.onChange === 'function') opts.onChange(a, b);
      }

      function hintText() {
        if (forcedReadOnly) {
          return 'Location preview · pin is locked';
        }
        if (locked) {
          return 'Pin is locked · click Edit pin to move it';
        }
        return 'Pink dashed line = Ormoc City boundary · click inside to pin';
      }

      function applyPinLock() {
        el.classList.toggle('is-pin-locked', locked);
        if (pin && pin.dragging) {
          if (canEdit && !locked) pin.dragging.enable();
          else pin.dragging.disable();
        }
        map.off('click', onMapClick);
        if (!locked) map.on('click', onMapClick);
        if (hint) hint.textContent = hintText();
        if (editBtn) {
          editBtn.hidden = !canEdit;
          editBtn.setAttribute('aria-pressed', locked ? 'false' : 'true');
          editBtn.textContent = locked ? 'Edit pin' : 'Done';
        }
      }

      el.classList.add('ct-map--picker');
      if (!hint) {
        hint = document.createElement('div');
        hint.className = 'ct-map-hint';
        el.appendChild(hint);
      }
      if (canEdit && opts.lockUntilEdit && !editBtn) {
        editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'ct-map-edit-pin';
        editBtn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          locked = !locked;
          applyPinLock();
        });
        el.appendChild(editBtn);
      }

      if (hasPin) setPin(lat, lng, false);
      applyPinLock();
      if (colorSelect) {
        colorSelect.addEventListener('change', function () {
          if (!pin) return;
          pin.setIcon(markerIcon(pinKind, activePinColor()));
        });
      }
    });
  }

  /** Ormoc City Port — default meetup / tour start */
  const ORMOC_PORT = {
    lat: 11.0039142,
    lng: 124.6058115,
    title: 'Ormoc City Port (Meetup)',
  };

  /**
   * Itinerary builder map: Port meetup → destinations with road routing (OSRM).
   * stops: [{ title, lat, lng }, ...]
   */
  function renderItineraryRouteMap(elId, stops, opts) {
    opts = opts || {};
    ensureLeaflet(function () {
      const el = typeof elId === 'string' ? document.getElementById(elId) : elId;
      if (!el) return;

      const meetup = opts.meetup || ORMOC_PORT;
      const dayMeetups = Array.isArray(opts.meetups) ? opts.meetups : [];
      let dayCount = Math.max(1, parseInt(opts.dayCount, 10) || 1);
      let isMultiday = dayCount > 1;
      let allStops = Array.isArray(stops) ? stops : [];
      const dayColors = ['#0e8a7a', '#c4a35a', '#3d6e9c', '#c44536', '#6b5b95', '#2a9d8f', '#e76f51'];

      const map = createMap(el, {
        center: [meetup.lat, meetup.lng],
        zoom: opts.zoom != null ? opts.zoom : 13,
      });

      const layers = L.layerGroup().addTo(map);
      const routeLayer = L.layerGroup().addTo(map);

      // Day filter chips (multi-day only)
      let daysRow = el.parentNode
        ? el.parentNode.querySelector('[data-itin-map-days-row]')
        : null;
      let filterBar = daysRow
        ? daysRow.querySelector('[data-itin-map-days]')
        : el.parentNode
          ? el.parentNode.querySelector('[data-itin-map-days]')
          : null;
      let activeFilter = isMultiday ? '1' : 'all';

      function addChip(value, text) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'itin-map-days__chip';
        btn.setAttribute('data-day', value);
        btn.textContent = text;
        btn.onclick = function () {
          activeFilter = btn.getAttribute('data-day');
          filterBar.querySelectorAll('[data-day]').forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-day') === activeFilter);
          });
          revealActiveChip();
          redraw();
        };
        filterBar.appendChild(btn);
      }

      function scrollChipStrip(dir) {
        if (!filterBar) return;
        const amount = Math.max(140, Math.round(filterBar.clientWidth * 0.7));
        filterBar.scrollBy({ left: dir * amount, behavior: 'smooth' });
      }

      function updateDayArrows() {
        if (!daysRow || !filterBar) return;
        const prev = daysRow.querySelector('[data-itin-map-days-prev]');
        const next = daysRow.querySelector('[data-itin-map-days-next]');
        const max = filterBar.scrollWidth - filterBar.clientWidth;
        const overflow = max > 4;
        if (prev) prev.disabled = !overflow || filterBar.scrollLeft <= 2;
        if (next) next.disabled = !overflow || filterBar.scrollLeft >= max - 2;
      }

      function revealActiveChip() {
        if (!filterBar) return;
        const active = filterBar.querySelector('[data-day="' + activeFilter + '"]');
        if (!active) {
          updateDayArrows();
          return;
        }
        const view = filterBar.getBoundingClientRect();
        const chip = active.getBoundingClientRect();
        const pad = 6;
        if (chip.left < view.left + pad || chip.right > view.right - pad) {
          const delta = chip.left - view.left - (view.width - chip.width) / 2;
          filterBar.scrollBy({ left: delta, behavior: 'smooth' });
        }
        window.setTimeout(updateDayArrows, 280);
        updateDayArrows();
      }

      function ensureDaysRow() {
        if (daysRow && filterBar) return;
        daysRow = document.createElement('div');
        daysRow.className = 'itin-map-days-row';
        daysRow.setAttribute('data-itin-map-days-row', '1');
        const prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'itin-map-days__arrow';
        prev.setAttribute('data-itin-map-days-prev', '');
        prev.setAttribute('aria-label', 'Earlier days');
        prev.innerHTML = '<i class="bi bi-chevron-left" aria-hidden="true"></i>';
        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'itin-map-days__arrow';
        next.setAttribute('data-itin-map-days-next', '');
        next.setAttribute('aria-label', 'Later days');
        next.innerHTML = '<i class="bi bi-chevron-right" aria-hidden="true"></i>';
        filterBar = document.createElement('div');
        filterBar.className = 'itin-map-days';
        filterBar.setAttribute('data-itin-map-days', '1');
        const label = document.createElement('span');
        label.className = 'itin-map-days__label';
        label.textContent = 'Show route';
        filterBar.appendChild(label);
        prev.addEventListener('click', function () {
          scrollChipStrip(-1);
        });
        next.addEventListener('click', function () {
          scrollChipStrip(1);
        });
        filterBar.addEventListener('scroll', updateDayArrows);
        daysRow.appendChild(prev);
        daysRow.appendChild(filterBar);
        daysRow.appendChild(next);
        el.parentNode.insertBefore(daysRow, el);
      }

      function syncFilterBar() {
        isMultiday = dayCount > 1;
        if (!el.parentNode) return;
        if (!isMultiday) {
          if (daysRow) {
            daysRow.remove();
            daysRow = null;
            filterBar = null;
          }
          activeFilter = 'all';
          return;
        }
        ensureDaysRow();
        filterBar.querySelectorAll('[data-day]').forEach(function (btn) {
          btn.remove();
        });
        addChip('all', 'All days');
        for (let d = 1; d <= dayCount; d++) {
          addChip(String(d), 'Day ' + d);
        }
        if (!filterBar.querySelector('[data-day="' + activeFilter + '"]')) {
          activeFilter = '1';
        }
        filterBar.querySelectorAll('[data-day]').forEach(function (btn) {
          btn.classList.toggle('is-active', btn.getAttribute('data-day') === activeFilter);
        });
        revealActiveChip();
      }

      syncFilterBar();

      const subtitle = el.parentNode
        ? el.parentNode.querySelector('[data-itin-map-sub]')
        : null;

      function stopsForFilter(filter) {
        if (filter === 'all') return allStops.slice();
        const dayNum = parseInt(filter, 10);
        return allStops.filter(function (s) {
          return (parseInt(s.day, 10) || 1) === dayNum;
        });
      }

      function placeStopMarker(layer, s, index, color, prefix) {
        const lat = Number(s.lat);
        const lng = Number(s.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        const pinFill =
          s.pin === 'skipped' ? '#e04545' : s.pin === 'done' ? '#2f6fed' : s.pin === 'current' ? '#1aad6f' : s.pin === 'waiting' ? '#e0b341' : s.done ? '#2f6fed' : color;
        const icon = opts.largePins
          ? largeMarkerIcon(pinFill, s.label || String(index + 1), s.status || '')
          : markerIcon('destination', pinFill, s.label || String(index + 1));
        const marker = L.marker([lat, lng], { icon: icon });
        if (typeof opts.onStopClick === 'function') {
          marker.on('click', function () {
            opts.onStopClick(s);
          });
        } else {
          marker.bindPopup(stopPopupHtml(s, index, prefix));
        }
        marker.addTo(layer);
      }

      function stopPopupHtml(s, index, prefix) {
        var html =
          '<strong>' +
          prefix +
          'Stop ' +
          (index + 1) +
          '</strong><br>' +
          escapeHtml(s.title || 'Destination');
        if (s.done) html += '<br>Checked in';
        if (s.id) {
          html += '<br><a href="#stop-' + encodeURIComponent(String(s.id)) + '">Open this stop</a>';
        }
        return html;
      }

      function meetupForDay(dayNum) {
        const found = dayMeetups.find(function (m) {
          return (parseInt(m.day, 10) || 0) === dayNum;
        });
        if (found && Number.isFinite(Number(found.lat)) && Number.isFinite(Number(found.lng))) {
          return {
            lat: Number(found.lat),
            lng: Number(found.lng),
            title: found.title || 'Meetup',
            day: dayNum,
          };
        }
        if (!dayMeetups.length && dayNum === 1) {
          return { lat: meetup.lat, lng: meetup.lng, title: meetup.title, day: 1 };
        }
        return null;
      }

      function addMeetupMarker(layer, point, seen, openNow) {
        const key = point.lat.toFixed(5) + ',' + point.lng.toFixed(5);
        if (seen[key]) return;
        seen[key] = true;
        const meetupIcon = L.divIcon({
          className: 'ct-map-pin ct-map-pin--labeled',
          html:
            '<span class="ct-map-pin__dot" style="background:#c44536"></span>' +
            '<span class="ct-map-pin__label">Meetup</span>',
          iconSize: [72, 22],
          iconAnchor: [11, 18],
        });
        const marker = L.marker([point.lat, point.lng], { icon: meetupIcon });
        marker
          .bindPopup(
            '<strong>' +
              escapeHtml(point.title) +
              '</strong><br>Day ' +
              point.day +
              ' meetup'
          )
          .addTo(layer);
        if (openNow) marker.openPopup();
      }

      function buildWaypoints(list, meetupPoint) {
        const waypoints = [];
        if (meetupPoint) {
          waypoints.push([meetupPoint.lat, meetupPoint.lng]);
        }
        list.forEach(function (s) {
          const lat = Number(s.lat);
          const lng = Number(s.lng);
          if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
          waypoints.push([lat, lng]);
        });
        return waypoints;
      }

      function fitPoints(points) {
        if (!points.length) {
          map.setView([meetup.lat, meetup.lng], 13);
          return;
        }
        const b = L.latLngBounds(
          points.map(function (p) {
            return L.latLng(p[0], p[1]);
          })
        );
        if (b.isValid()) {
          map.fitBounds(b.pad(0.2));
        }
      }

      function drawRouteLine(waypoints, color, onDone) {
        if (waypoints.length < 2) {
          if (onDone) onDone();
          return;
        }
        const coordStr = waypoints
          .map(function (p) {
            return p[1] + ',' + p[0];
          })
          .join(';');
        const url =
          'https://router.project-osrm.org/route/v1/driving/' +
          coordStr +
          '?overview=full&geometries=geojson';

        fetch(url)
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (!data || data.code !== 'Ok' || !data.routes || !data.routes[0]) {
              L.polyline(waypoints, {
                color: color,
                weight: 4,
                opacity: 0.75,
                dashArray: '6 8',
              }).addTo(routeLayer);
              if (onDone) onDone();
              return;
            }
            const geo = data.routes[0].geometry;
            const latlngs = (geo.coordinates || []).map(function (c) {
              return [c[1], c[0]];
            });
            L.polyline(latlngs, {
              color: color,
              weight: 5,
              opacity: 0.9,
              lineJoin: 'round',
              lineCap: 'round',
            }).addTo(routeLayer);
            if (onDone) onDone();
          })
          .catch(function () {
            L.polyline(waypoints, {
              color: color,
              weight: 4,
              opacity: 0.75,
              dashArray: '6 8',
            }).addTo(routeLayer);
            if (onDone) onDone();
          });
      }

      function redraw() {
        layers.clearLayers();
        routeLayer.clearLayers();

        const filter = activeFilter;
        const fitBucket = [];

        if (subtitle) {
          if (filter === 'all') {
            subtitle.textContent = isMultiday
              ? 'All days on the map'
              : 'Road line from Port → your stops';
          } else {
            const dayPoint = meetupForDay(parseInt(filter, 10) || 1);
            subtitle.textContent = dayPoint
              ? 'Day ' + filter + ' · meetup → stops'
              : 'Day ' + filter + ' · stops for that day';
          }
        }

        if (filter === 'all' && isMultiday) {
          const seenMeetups = {};
          let pending = 0;
          let started = false;
          function maybeFit() {
            if (!started) return;
            if (pending === 0) fitPoints(fitBucket);
          }

          // Draw each day as its own route — never connect Day N → Day N+1
          for (let d = 1; d <= dayCount; d++) {
            const dayStops = stopsForFilter(String(d));
            const dayMeetup = opts.largePins ? null : meetupForDay(d);
            if (!dayStops.length && !dayMeetup) continue;
            if (dayMeetup) {
              addMeetupMarker(layers, dayMeetup, seenMeetups, false);
              fitBucket.push([dayMeetup.lat, dayMeetup.lng]);
            }
            const color = dayColors[(d - 1) % dayColors.length];
            dayStops.forEach(function (s, i) {
              const lat = Number(s.lat);
              const lng = Number(s.lng);
              if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
              fitBucket.push([lat, lng]);
              placeStopMarker(layers, s, i, color, 'Day ' + d + ' · ');
            });
            const waypoints = buildWaypoints(dayStops, dayMeetup);
            waypoints.forEach(function (p) {
              fitBucket.push(p);
            });
            if (waypoints.length >= 2) {
              pending++;
              drawRouteLine(waypoints, color, function () {
                pending--;
                maybeFit();
              });
            }
          }
          started = true;
          if (pending === 0) fitPoints(fitBucket);
          return;
        }

        const list = stopsForFilter(filter);
        const dayNum = filter === 'all' ? 1 : parseInt(filter, 10) || 1;
        const color = dayColors[(dayNum - 1) % dayColors.length];
        const dayMeetup = opts.largePins ? null : meetupForDay(dayNum);

        if (dayMeetup) {
          addMeetupMarker(layers, dayMeetup, {}, true);
          fitBucket.push([dayMeetup.lat, dayMeetup.lng]);
        }

        list.forEach(function (s, i) {
          const lat = Number(s.lat);
          const lng = Number(s.lng);
          if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
          fitBucket.push([lat, lng]);
          placeStopMarker(
            layers,
            s,
            i,
            color,
            isMultiday && filter !== 'all' ? 'Day ' + filter + ' · ' : ''
          );
        });

        const waypoints = buildWaypoints(list, dayMeetup);
        if (waypoints.length >= 2) {
          drawRouteLine(waypoints, color, function () {
            fitPoints(fitBucket);
          });
        } else {
          fitPoints(fitBucket);
        }
      }

      redraw();
      setTimeout(function () {
        map.invalidateSize();
      }, 200);

      // Live update after drag-reorder (no full page reload)
      el._ctUpdateItineraryStops = function (nextStops) {
        allStops = Array.isArray(nextStops) ? nextStops : [];
        redraw();
        setTimeout(function () {
          map.invalidateSize();
        }, 80);
      };

      el._ctSetDayCount = function (nextCount) {
        const n = Math.max(1, parseInt(nextCount, 10) || 1);
        if (n === dayCount) return;
        dayCount = n;
        syncFilterBar();
        redraw();
      };
      if (el._ctPendingDayCount) {
        el._ctSetDayCount(el._ctPendingDayCount);
        el._ctPendingDayCount = 0;
      }

      // Sync map day filter from day-by-day plan scroll / chips
      el._ctSetDayFilter = function (dayValue, opts) {
        opts = opts || {};
        if (!isMultiday) return;
        const next = dayValue == null || dayValue === '' ? 'all' : String(dayValue);
        if (next === activeFilter && !opts.force) {
          revealActiveChip();
          return;
        }
        activeFilter = next;
        if (filterBar) {
          filterBar.querySelectorAll('[data-day]').forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-day') === activeFilter);
          });
        }
        revealActiveChip();
        redraw();
        setTimeout(function () {
          map.invalidateSize();
        }, 80);
      };
    });
  }

  /**
   * Driving minutes between two points via OSRM (ceil). Returns Promise<number>.
   * Falls back to 45 on failure.
   */
  function fetchTravelMinutes(fromLat, fromLng, toLat, toLng) {
    const fallback = 45;
    const a = Number(fromLat);
    const b = Number(fromLng);
    const c = Number(toLat);
    const d = Number(toLng);
    if (![a, b, c, d].every(Number.isFinite)) {
      return Promise.resolve(fallback);
    }
    const url =
      'https://router.project-osrm.org/route/v1/driving/' +
      b +
      ',' +
      a +
      ';' +
      d +
      ',' +
      c +
      '?overview=false';
    return fetch(url)
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || data.code !== 'Ok' || !data.routes || !data.routes[0]) {
          return fallback;
        }
        return Math.max(1, Math.ceil(Number(data.routes[0].duration) / 60));
      })
      .catch(function () {
        return fallback;
      });
  }

  /** Standard 12-hour display, e.g. "8:00 AM". */
  function minutesToTimeStr(mins) {
    const m = Math.max(0, Math.min(23 * 60 + 59, Math.round(mins)));
    let h = Math.floor(m / 60);
    const mm = m % 60;
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    if (h === 0) h = 12;
    return h + ':' + String(mm).padStart(2, '0') + ' ' + ampm;
  }

  /** Parse 12h ("8:00 AM") or 24h ("08:00") into minutes from midnight. */
  function timeStrToMinutes(t) {
    if (!t) return null;
    const s = String(t).trim();
    const ampmMatch = s.match(/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)$/i);
    if (ampmMatch) {
      let h = parseInt(ampmMatch[1], 10);
      const m = ampmMatch[2] != null ? parseInt(ampmMatch[2], 10) : 0;
      const ampm = ampmMatch[3].toUpperCase();
      if (!Number.isFinite(h) || !Number.isFinite(m) || h < 1 || h > 12 || m < 0 || m > 59) {
        return null;
      }
      if (ampm === 'AM') {
        h = h === 12 ? 0 : h;
      } else {
        h = h === 12 ? 12 : h + 12;
      }
      return h * 60 + m;
    }
    const parts = s.split(':');
    if (parts.length < 2) return null;
    const h = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10);
    if (!Number.isFinite(h) || !Number.isFinite(m) || h < 0 || h > 23 || m < 0 || m > 59) {
      return null;
    }
    return h * 60 + m;
  }

  /**
   * Wire an itinerary add-stop form to OSRM travel suggestions.
   * formEl: form with [name=stop_key], [name=start_time], [name=travel_minutes], [name=visit_minutes]
   * opts: { places: { 'd:1': {lat,lng,name}, ... }, prev: {lat,lng,leaveMins,label}, hintEl }
   */
  function parseHoursSlotRanges(slot) {
    const s = String(slot || '').trim();
    if (!s || /^closed$/i.test(s)) return [];
    return s.split(/\s*,\s*/).reduce(function (acc, part) {
      const m = String(part).trim().match(/^(.+?)\s*-\s*(.+)$/);
      if (!m) return acc;
      const a = timeStrToMinutes(m[1].trim());
      const b = timeStrToMinutes(m[2].trim());
      if (a === null || b === null) return acc;
      acc.push({ open: a, close: b });
      return acc;
    }, []);
  }

  function bindItineraryStopTiming(formEl, opts) {
    opts = opts || {};
    if (!formEl) return;
    const places = opts.places || {};
    let dayKey = opts.dayKey || 'mon';
    const dayKeys = opts.dayKeys || {};
    const dayPrevs = opts.dayPrevs || null;
    let prev = Object.assign(
      {
        lat: ORMOC_PORT.lat,
        lng: ORMOC_PORT.lng,
        leaveMins: 8 * 60,
        label: ORMOC_PORT.title,
        skipTravel: false,
        suggestMins: 8 * 60,
      },
      opts.prev || {}
    );
    const stopSelect = formEl.querySelector('[name="stop_key"]');
    const daySelect = formEl.querySelector('[name="day_number"]');
    const timeInput = formEl.querySelector('[name="start_time"]');
    const travelInput = formEl.querySelector('[name="travel_minutes"]');
    const visitInput = formEl.querySelector('[name="visit_minutes"]');
    const hintEl =
      opts.hintEl ||
      formEl.querySelector('[data-itin-travel-hint]') ||
      null;
    const warnEl =
      opts.warnEl ||
      formEl.querySelector('[data-itin-travel-warn]') ||
      null;
    // Keep the markup empty-state copy; do not inject Port / “day starts fresh” blurbs
    const emptyHintDefault = hintEl ? String(hintEl.textContent || '').trim() : '';

    function applyDayContext() {
      if (!daySelect || !dayPrevs) return;
      const key = String(daySelect.value || '1');
      const next = dayPrevs[key] || dayPrevs[Number(key)];
      if (next) {
        prev = Object.assign({}, prev, next);
      }
      if (dayKeys[key]) {
        dayKey = dayKeys[key];
      }
    }

    function setHint(text) {
      if (hintEl) hintEl.textContent = text || '';
    }

    function setWarn(text) {
      if (!warnEl) return;
      if (text) {
        warnEl.textContent = text;
        warnEl.classList.remove('d-none');
      } else {
        warnEl.textContent = '';
        warnEl.classList.add('d-none');
      }
    }

    function refreshSuggestion() {
      applyDayContext();
      if (!stopSelect || !stopSelect.value) {
        setHint(emptyHintDefault);
        setWarn('');
        if (timeInput && !timeInput.dataset.userEdited) {
          const baseMins = prev.skipTravel
            ? (prev.suggestMins != null ? prev.suggestMins : prev.leaveMins || 8 * 60)
            : (prev.leaveMins != null ? prev.leaveMins : 8 * 60);
          timeInput.value = minutesToTimeStr(baseMins);
        }
        if (travelInput) travelInput.value = prev.skipTravel ? '0' : '45';
        return;
      }
      const place = places[stopSelect.value];

      // First stop on Day 2+: no Port travel lock
      if (prev.skipTravel) {
        if (travelInput) travelInput.value = '0';
        const suggestedMins =
          prev.suggestMins != null ? prev.suggestMins : prev.leaveMins || 8 * 60;
        const suggested = minutesToTimeStr(suggestedMins);
        const hoursNote = place && place.hoursLabel ? ' Hours that day: ' + place.hoursLabel + '.' : '';
        setHint(
          'New day — start at this stop (no drive from Port). Suggested arrival: ' +
            suggested +
            '.' +
            hoursNote
        );
        if (timeInput && !timeInput.dataset.userEdited) {
          timeInput.value = suggested;
        }
        checkTight();
        return;
      }

      if (!place || !Number.isFinite(Number(place.lat)) || !Number.isFinite(Number(place.lng))) {
        setHint('No map pin for this place — using ~45 min estimate.');
        if (travelInput) travelInput.value = '45';
        const suggested = minutesToTimeStr((prev.leaveMins || 0) + 45);
        if (timeInput && !timeInput.dataset.userEdited) {
          timeInput.value = suggested;
        }
        checkTight();
        return;
      }
      setHint('Estimating drive time…');
      fetchTravelMinutes(prev.lat, prev.lng, place.lat, place.lng).then(function (mins) {
        if (travelInput) travelInput.value = String(mins);
        const suggestedMins = (prev.leaveMins || 0) + mins;
        const suggested = minutesToTimeStr(suggestedMins);
        const hoursNote = place.hoursLabel ? ' Hours today: ' + place.hoursLabel + '.' : '';
        setHint(
          'About ' +
            mins +
            ' min drive from ' +
            (prev.label || 'previous stop') +
            '. Suggested arrival: ' +
            suggested +
            '.' +
            hoursNote
        );
        if (timeInput && !timeInput.dataset.userEdited) {
          timeInput.value = suggested;
        }
        checkTight();
      });
    }

    function checkTight() {
      if (!timeInput || !travelInput) {
        setWarn('');
        return;
      }
      const startMins = timeStrToMinutes(timeInput.value);
      const travel = parseInt(travelInput.value || '0', 10) || 0;
      const visit = parseInt((visitInput && visitInput.value) || '60', 10) || 60;
      if (startMins === null) {
        setWarn('');
        return;
      }
      if (!prev.skipTravel) {
        const earliest = (prev.leaveMins || 0) + travel;
        if (startMins < earliest) {
          setWarn(
            'Too tight to save: travel from ' +
              (prev.label || 'previous stop') +
              ' is about ' +
              travel +
              ' min. Earliest allowed arrival is ' +
              minutesToTimeStr(earliest) +
              '.'
          );
          return;
        }
      }

      const place = stopSelect && stopSelect.value ? places[stopSelect.value] : null;
      if (place && place.hours) {
        const slot = place.hours[dayKey] || 'Closed';
        const label = place.hoursLabel || slot;
        const name = place.name || 'This place';
        if (/^closed$/i.test(String(slot).trim())) {
          setWarn(name + ' is closed that day (' + label + ').');
          return;
        }
        const ranges = parseHoursSlotRanges(slot);
        const leaveMins = Math.min(23 * 60 + 59, startMins + Math.max(1, visit));
        const arriveOk = ranges.some(function (r) {
          return startMins >= r.open && startMins < r.close;
        });
        const leaveOk = ranges.some(function (r) {
          return leaveMins >= r.open && leaveMins <= r.close;
        });
        if (!arriveOk) {
          setWarn(
            name +
              ' is closed at your arrival time (hours: ' +
              label +
              '). Choose a time before closing.'
          );
          return;
        }
        if (!leaveOk) {
          setWarn(
            'Leave time falls outside ' +
              name +
              ' operating hours (hours: ' +
              label +
              '). Shorten the visit or arrive earlier.'
          );
          return;
        }
      }
      setWarn('');
    }

    if (daySelect) {
      daySelect.addEventListener('change', function () {
        if (timeInput) delete timeInput.dataset.userEdited;
        refreshSuggestion();
      });
    }
    if (stopSelect) {
      stopSelect.addEventListener('change', function () {
        if (timeInput) delete timeInput.dataset.userEdited;
        refreshSuggestion();
      });
    }
    if (timeInput) {
      timeInput.addEventListener('change', function () {
        timeInput.dataset.userEdited = '1';
        checkTight();
      });
      timeInput.addEventListener('input', function () {
        timeInput.dataset.userEdited = '1';
        checkTight();
      });
    }
    if (visitInput) {
      visitInput.addEventListener('change', function () {
        visitInput.dataset.userEdited = '1';
        checkTight();
      });
      visitInput.addEventListener('input', function () {
        visitInput.dataset.userEdited = '1';
        checkTight();
      });
    }
    refreshSuggestion();
  }

  global.CulTourMap = {
    DEFAULT_CENTER: DEFAULT_CENTER,
    ORMOC_BOUNDS: ORMOC_BOUNDS,
    ORMOC_PORT: ORMOC_PORT,
    renderExploreMap: renderExploreMap,
    renderPinPicker: renderPinPicker,
    renderItineraryRouteMap: renderItineraryRouteMap,
    fetchTravelMinutes: fetchTravelMinutes,
    bindItineraryStopTiming: bindItineraryStopTiming,
    minutesToTimeStr: minutesToTimeStr,
    timeStrToMinutes: timeStrToMinutes,
  };
})(window);
