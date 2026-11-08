<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/map_helpers.php';
$user = require_login(ROLE_ADMIN);
$pageTitle = 'Categories';
$pageHeading = 'Categories';
$pageSub = 'Set pin colors for government sites and businesses';
$activeNav = 'categories';

ensure_category_pin_colors();
ensure_business_type_pin_colors();

$defaultColors = ['#0e8a7a', '#2b6cb0', '#c4a35a', '#38a169', '#9b2c2c', '#805ad5', '#dd6b20'];
$csrf = csrf_token();

$catRows = db()->query(
    'SELECT c.*, (SELECT COUNT(*) FROM destinations d WHERE d.category_id=c.id) AS dest_count
     FROM categories c ORDER BY c.name'
)->fetchAll();

$typeRows = db()->query(
    'SELECT bt.*, (SELECT COUNT(*) FROM businesses b WHERE b.business_type_id=bt.id) AS biz_count
     FROM business_types bt ORDER BY bt.name'
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <?php require __DIR__ . '/../includes/topbar.php'; ?>
    <div class="page-content">
      <div id="catAlert" class="alert alert-success d-none"></div>

      <div class="manage-twin">
        <!-- Destination categories -->
        <section class="glass manage-panel">
          <div class="manage-panel__head">
            <h3 class="panel-title mb-1">Government site categories</h3>
            <p class="small text-muted mb-0">Pin colors for government site markers on the map.</p>
          </div>
          <form id="catForm" class="manage-panel__form">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="manage-panel__fields">
              <div class="manage-panel__field manage-panel__field--name">
                <label class="form-label">Name</label>
                <input class="form-control" name="name" placeholder="e.g. Beach" required>
              </div>
              <div class="manage-panel__field manage-panel__field--color">
                <label class="form-label">Pin color</label>
                <div class="d-flex align-items-center gap-2">
                  <input type="color" class="form-control form-control-color" name="pin_color" id="pinColor" value="#0e8a7a">
                  <input type="text" class="form-control" id="pinColorText" maxlength="7" value="#0e8a7a">
                </div>
              </div>
              <div class="manage-panel__field manage-panel__field--action">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-ct w-100" type="submit">Add</button>
              </div>
            </div>
          </form>
          <div class="manage-panel__list">
            <table class="table table-ct manage-panel__table mb-0">
              <thead><tr><th style="width:4.5rem">Pin</th><th>Name</th><th style="width:4rem">Used</th><th style="width:5.5rem"></th></tr></thead>
              <tbody id="catTableBody">
                <?php foreach ($catRows as $i => $r):
                  $color = $r['pin_color'] ?? $defaultColors[$i % count($defaultColors)];
                  $title = trim((string) ($r['description'] ?? ''));
                ?>
                  <tr data-id="<?= (int) $r['id'] ?>">
                    <td>
                      <input type="color" class="form-control form-control-color cat-live-color"
                             value="<?= e($color) ?>" data-id="<?= (int) $r['id'] ?>">
                    </td>
                    <td>
                      <div class="manage-panel__name cat-name"<?= $title !== '' ? ' title="' . e($title) . '"' : '' ?>><?= e($r['name']) ?></div>
                    </td>
                    <td class="cat-count"><?= (int) $r['dest_count'] ?></td>
                    <td>
                      <button type="button" class="btn btn-sm btn-outline-danger cat-delete"
                              data-id="<?= (int) $r['id'] ?>"
                              <?= $r['dest_count'] > 0 ? 'disabled' : '' ?>>Delete</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$catRows): ?>
                  <tr class="cat-empty"><td colspan="4" class="text-muted">No categories yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="glass manage-panel">
          <div class="manage-panel__head">
            <h3 class="panel-title mb-1">Business categories</h3>
            <p class="small text-muted mb-0">Pin colors for business markers on the map.</p>
          </div>
          <form id="typeForm" class="manage-panel__form">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="manage-panel__fields">
              <div class="manage-panel__field manage-panel__field--name">
                <label class="form-label">Name</label>
                <input class="form-control" name="name" placeholder="e.g. Accommodation" required>
              </div>
              <div class="manage-panel__field manage-panel__field--color">
                <label class="form-label">Pin color</label>
                <div class="d-flex align-items-center gap-2">
                  <input type="color" class="form-control form-control-color" id="typePinColor" value="#c4a35a">
                  <input type="text" class="form-control" id="typePinColorText" maxlength="7" value="#c4a35a">
                </div>
              </div>
              <div class="manage-panel__field manage-panel__field--action">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-ct w-100" type="submit">Add</button>
              </div>
            </div>
          </form>
          <div class="manage-panel__list">
            <table class="table table-ct manage-panel__table mb-0">
              <thead><tr><th style="width:4.5rem">Pin</th><th>Name</th><th style="width:4rem">Used</th><th style="width:5.5rem"></th></tr></thead>
              <tbody id="typeTableBody">
                <?php foreach ($typeRows as $i => $r):
                  $color = $r['pin_color'] ?? $defaultColors[$i % count($defaultColors)];
                ?>
                  <tr data-id="<?= (int) $r['id'] ?>">
                    <td>
                      <input type="color" class="form-control form-control-color type-live-color"
                             value="<?= e($color) ?>" data-id="<?= (int) $r['id'] ?>">
                    </td>
                    <td>
                      <div class="manage-panel__name type-name"><?= e($r['name']) ?></div>
                    </td>
                    <td class="type-count"><?= (int) $r['biz_count'] ?></td>
                    <td>
                      <button type="button" class="btn btn-sm btn-outline-danger type-delete"
                              data-id="<?= (int) $r['id'] ?>"
                              <?= $r['biz_count'] > 0 ? 'disabled' : '' ?>>Delete</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$typeRows): ?>
                  <tr class="type-empty"><td colspan="4" class="text-muted">No business categories yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  const catUrl = <?= json_encode(APP_URL . '/api/categories/save.php') ?>;
  const typeUrl = <?= json_encode(APP_URL . '/api/business-types/save.php') ?>;
  const csrf = <?= json_encode($csrf) ?>;
  const alertEl = document.getElementById('catAlert');
  const catBody = document.getElementById('catTableBody');
  const typeBody = document.getElementById('typeTableBody');
  const catForm = document.getElementById('catForm');
  const typeForm = document.getElementById('typeForm');
  const color = document.getElementById('pinColor');
  const text = document.getElementById('pinColorText');
  const typeColor = document.getElementById('typePinColor');
  const typeText = document.getElementById('typePinColorText');

  function flash(msg, ok) {
    alertEl.textContent = msg;
    alertEl.className = 'alert alert-' + (ok ? 'success' : 'danger');
    alertEl.classList.remove('d-none');
    setTimeout(function () { alertEl.classList.add('d-none'); }, 2500);
  }

  function post(url, data) {
    const body = new FormData();
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    body.append('csrf_token', csrf);
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  function renderCatRows(categories) {
    if (!categories || !categories.length) {
      catBody.innerHTML = '<tr class="cat-empty"><td colspan="4" class="text-muted">No categories yet.</td></tr>';
      return;
    }
    const counts = {};
    catBody.querySelectorAll('tr[data-id]').forEach(function (tr) {
      counts[tr.getAttribute('data-id')] = tr.querySelector('.cat-count')?.textContent || '0';
    });
    catBody.innerHTML = categories.map(function (c) {
      const count = counts[String(c.id)] || '0';
      const disabled = parseInt(count, 10) > 0 ? ' disabled' : '';
      return '<tr data-id="' + c.id + '">' +
        '<td><input type="color" class="form-control form-control-color cat-live-color" value="' + c.color + '" data-id="' + c.id + '"></td>' +
        '<td><div class="manage-panel__name cat-name"></div></td>' +
        '<td class="cat-count">' + count + '</td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger cat-delete" data-id="' + c.id + '"' + disabled + '>Delete</button></td>' +
        '</tr>';
    }).join('');
    catBody.querySelectorAll('tr[data-id]').forEach(function (tr, i) {
      tr.querySelector('.cat-name').textContent = categories[i].name;
    });
  }

  function renderTypeRows(types) {
    if (!types || !types.length) {
      typeBody.innerHTML = '<tr class="type-empty"><td colspan="4" class="text-muted">No business categories yet.</td></tr>';
      return;
    }
    const counts = {};
    typeBody.querySelectorAll('tr[data-id]').forEach(function (tr) {
      counts[tr.getAttribute('data-id')] = tr.querySelector('.type-count')?.textContent || '0';
    });
    typeBody.innerHTML = types.map(function (t) {
      const count = counts[String(t.id)] || '0';
      const disabled = parseInt(count, 10) > 0 ? ' disabled' : '';
      return '<tr data-id="' + t.id + '">' +
        '<td><input type="color" class="form-control form-control-color type-live-color" value="' + t.color + '" data-id="' + t.id + '"></td>' +
        '<td><div class="manage-panel__name type-name"></div></td>' +
        '<td class="type-count">' + count + '</td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger type-delete" data-id="' + t.id + '"' + disabled + '>Delete</button></td>' +
        '</tr>';
    }).join('');
    typeBody.querySelectorAll('tr[data-id]').forEach(function (tr, i) {
      tr.querySelector('.type-name').textContent = types[i].name;
    });
  }

  function bindColorPair(picker, textEl) {
    if (!picker || !textEl) return;
    picker.addEventListener('input', function () { textEl.value = picker.value; });
    textEl.addEventListener('input', function () {
      if (/^#[0-9A-Fa-f]{6}$/.test(textEl.value)) picker.value = textEl.value;
    });
  }
  bindColorPair(color, text);
  bindColorPair(typeColor, typeText);

  catForm.addEventListener('submit', function (e) {
    e.preventDefault();
    const fd = new FormData(catForm);
    post(catUrl, {
      action: 'save',
      name: fd.get('name'),
      description: '',
      pin_color: color.value
    }).then(function (res) {
      if (!res.ok) { flash(res.message || 'Save failed', false); return; }
      flash('Category added', true);
      catForm.reset();
      color.value = '#0e8a7a';
      text.value = '#0e8a7a';
      renderCatRows(res.categories);
    }).catch(function () { flash('Network error', false); });
  });

  typeForm.addEventListener('submit', function (e) {
    e.preventDefault();
    const fd = new FormData(typeForm);
    post(typeUrl, {
      action: 'save',
      name: fd.get('name'),
      pin_color: typeColor.value
    }).then(function (res) {
      if (!res.ok) { flash(res.message || 'Save failed', false); return; }
      flash('Business category added', true);
      typeForm.reset();
      typeColor.value = '#c4a35a';
      typeText.value = '#c4a35a';
      renderTypeRows(res.business_types);
    }).catch(function () { flash('Network error', false); });
  });

  catBody.addEventListener('change', function (e) {
    const input = e.target.closest('.cat-live-color');
    if (!input) return;
    post(catUrl, { action: 'color', id: input.getAttribute('data-id'), pin_color: input.value })
      .then(function (res) {
        if (!res.ok) { flash(res.message || 'Color update failed', false); return; }
        flash('Government site pin color saved', true);
      });
  });

  typeBody.addEventListener('change', function (e) {
    const input = e.target.closest('.type-live-color');
    if (!input) return;
    post(typeUrl, { action: 'color', id: input.getAttribute('data-id'), pin_color: input.value })
      .then(function (res) {
        if (!res.ok) { flash(res.message || 'Color update failed', false); return; }
        flash('Business pin color saved', true);
      });
  });

  catBody.addEventListener('click', function (e) {
    const btn = e.target.closest('.cat-delete');
    if (!btn || btn.disabled) return;
    if (!confirm('Delete this category?')) return;
    post(catUrl, { action: 'delete', id: btn.getAttribute('data-id') }).then(function (res) {
      if (!res.ok) { flash(res.message || 'Delete failed', false); return; }
      flash('Category deleted', true);
      renderCatRows(res.categories);
    });
  });

  typeBody.addEventListener('click', function (e) {
    const btn = e.target.closest('.type-delete');
    if (!btn || btn.disabled) return;
    if (!confirm('Delete this business category?')) return;
    post(typeUrl, { action: 'delete', id: btn.getAttribute('data-id') }).then(function (res) {
      if (!res.ok) { flash(res.message || 'Delete failed', false); return; }
      flash('Business category deleted', true);
      renderTypeRows(res.business_types);
    });
  });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
