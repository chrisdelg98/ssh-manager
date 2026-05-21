<?php
use App\CsrfGuard;
/** @var array|null $editSnippet @var string $error @var array $cats @var array $osTargets @var array $allTags */
$editing    = !empty($editSnippet);
$pageTitle  = $editing ? 'Editar snippet' : 'Nuevo snippet';
$error      = $error      ?? '';
$cats       = $cats       ?? [];
$osTargets  = $osTargets  ?? [];
$allTags    = $allTags    ?? [];
include __DIR__ . '/layout.php';

$formAction = $editing
    ? '?action=snippet_edit&id=' . (int)$editSnippet['id']
    : '?action=snippet_add';

$selectedCatId = (int)($editSnippet['category_id']  ?? 0);
$selectedOsId  = (int)($editSnippet['os_target_id'] ?? 0);
$selectedTags  = $editSnippet['tag_ids'] ?? [];
?>

<div class="page-header">
  <div>
    <h1><?= $editing ? 'Editar snippet' : 'Nuevo snippet' ?></h1>
    <div class="page-subtitle">Guarda comandos que usas con frecuencia.</div>
  </div>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $formAction ?>" class="form-card" autocomplete="off">
  <?= CsrfGuard::field() ?>

  <!-- Título + Categoría -->
  <div class="form-row">
    <div class="form-group">
      <label>Título <span class="req">*</span></label>
      <input type="text" name="title" required maxlength="150"
             value="<?= htmlspecialchars($editSnippet['title'] ?? '') ?>">
    </div>
    <div class="form-group" style="max-width:280px">
      <label>Categoría <span class="req">*</span></label>
      <select name="category_id" id="cat-select" required>
        <?php foreach ($cats as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $selectedCatId === (int)$c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
        <option value="__new__">+ Nueva categoría…</option>
      </select>
      <input type="text" name="category_new" id="cat-new"
             placeholder="Nombre de la nueva categoría"
             class="inline-new hidden" maxlength="50">
    </div>
  </div>

  <!-- Comando -->
  <div class="form-group">
    <label>Comando <span class="req">*</span></label>
    <textarea name="command" required rows="3"
              placeholder="systemctl status nginx"><?= htmlspecialchars($editSnippet['command'] ?? '') ?></textarea>
  </div>

  <!-- OS / Plataforma + Tags -->
  <div class="form-row">
    <div class="form-group" style="max-width:280px">
      <label>OS / Plataforma <span class="text-muted">(opcional)</span></label>
      <select name="os_target_id" id="os-select">
        <option value="">— Cualquiera —</option>
        <?php foreach ($osTargets as $o): ?>
          <option value="<?= (int)$o['id'] ?>" <?= $selectedOsId === (int)$o['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($o['name']) ?>
          </option>
        <?php endforeach; ?>
        <option value="__new__">+ Nuevo OS…</option>
      </select>
      <input type="text" name="os_target_new" id="os-new"
             placeholder="Nombre del nuevo OS"
             class="inline-new hidden" maxlength="50">
    </div>

    <div class="form-group">
      <label>Tags <span class="text-muted">(opcional · multi-selección)</span></label>
      <div class="multiselect" id="tag-multiselect">
        <button type="button" class="ms-field" id="ms-field" aria-haspopup="listbox" aria-expanded="false">
          <span class="ms-chips" id="ms-chips">
            <span class="ms-placeholder">Selecciona o crea tags…</span>
          </span>
          <span class="ms-caret" aria-hidden="true">
            <svg viewBox="0 0 12 12" width="12" height="12"><path d="M2 4l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
        </button>

        <div class="ms-dropdown" id="ms-dropdown" role="listbox" hidden>
          <div class="ms-search">
            <svg class="ms-search-icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
              <circle cx="7" cy="7" r="5" fill="none" stroke="currentColor" stroke-width="1.6"/>
              <path d="M11 11l3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
            <input type="text" id="ms-input" placeholder="Buscar tag o crear una nueva…" autocomplete="off">
          </div>

          <div class="ms-options" id="ms-options">
            <?php foreach ($allTags as $t): ?>
              <label class="ms-option" data-tag-name="<?= htmlspecialchars(strtolower($t['name']), ENT_QUOTES) ?>">
                <input type="checkbox" name="tag_ids[]"
                       value="<?= (int)$t['id'] ?>"
                       data-name="<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>"
                       <?= in_array((int)$t['id'], $selectedTags, true) ? 'checked' : '' ?>>
                <span class="ms-option-text"><?= htmlspecialchars($t['name']) ?></span>
                <span class="ms-option-check" aria-hidden="true">
                  <svg viewBox="0 0 12 12" width="12" height="12"><path d="M2 6l3 3 5-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
              </label>
            <?php endforeach; ?>
          </div>

          <div class="ms-empty hidden" id="ms-empty">Sin coincidencias.</div>

          <div class="ms-create hidden" id="ms-create">
            <span class="ms-create-plus">+</span>
            <span>Crear nueva tag: <strong id="ms-create-name"></strong></span>
          </div>
        </div>

        <input type="hidden" name="tags_new" id="tags-new-input">
      </div>
    </div>
  </div>

  <!-- Descripción -->
  <div class="form-group">
    <label>Descripción <span class="text-muted">(opcional)</span></label>
    <textarea name="description" rows="2"
              placeholder="Qué hace este comando y cuándo usarlo."><?= htmlspecialchars($editSnippet['description'] ?? '') ?></textarea>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">
      <?= $editing ? 'Guardar cambios' : 'Crear snippet' ?>
    </button>
    <a href="?action=snippets" class="btn btn-ghost">Cancelar</a>
  </div>
</form>

<script>
// ── Inline "new" reveal for category & OS selects ────────────────────
function wireInlineNew(selectId, inputId) {
  const sel = document.getElementById(selectId);
  const inp = document.getElementById(inputId);
  if (!sel || !inp) return;
  const sync = () => {
    const isNew = sel.value === '__new__';
    inp.classList.toggle('hidden', !isNew);
    inp.required = isNew;
    if (isNew) inp.focus();
  };
  sel.addEventListener('change', sync);
  sync();
}
wireInlineNew('cat-select', 'cat-new');
wireInlineNew('os-select',  'os-new');

// ── Tags multiselect (Mobiscroll-style) ──────────────────────────────
(function () {
  const root      = document.getElementById('tag-multiselect');
  const field     = document.getElementById('ms-field');
  const dropdown  = document.getElementById('ms-dropdown');
  const optionsEl = document.getElementById('ms-options');
  const chipsEl   = document.getElementById('ms-chips');
  const input     = document.getElementById('ms-input');
  const createEl  = document.getElementById('ms-create');
  const createN   = document.getElementById('ms-create-name');
  const emptyEl   = document.getElementById('ms-empty');
  const newInput  = document.getElementById('tags-new-input');

  // User-entered tags that don't exist in DB yet (Set of lowercased names)
  const newTags = new Set();

  const existingNames = () =>
    Array.from(optionsEl.querySelectorAll('input[type=checkbox]'))
      .map(cb => cb.dataset.name.toLowerCase());

  function renderChips() {
    chipsEl.innerHTML = '';
    const checked = optionsEl.querySelectorAll('input[type=checkbox]:checked');
    const totalCount = checked.length + newTags.size;

    if (totalCount === 0) {
      const ph = document.createElement('span');
      ph.className = 'ms-placeholder';
      ph.textContent = 'Selecciona o crea tags…';
      chipsEl.appendChild(ph);
      return;
    }

    checked.forEach(cb => {
      const chip = makeChip(cb.dataset.name, false, () => {
        cb.checked = false;
        cb.closest('.ms-option').classList.remove('is-checked');
        renderChips();
      });
      chipsEl.appendChild(chip);
    });

    newTags.forEach(name => {
      const chip = makeChip(name, true, () => {
        newTags.delete(name);
        syncNewInput();
        renderChips();
      });
      chipsEl.appendChild(chip);
    });
  }

  function makeChip(label, isNew, onRemove) {
    const chip = document.createElement('span');
    chip.className = 'ms-chip' + (isNew ? ' is-new' : '');
    const text = document.createElement('span');
    text.textContent = label;
    const x = document.createElement('button');
    x.type = 'button';
    x.className = 'ms-chip-x';
    x.setAttribute('aria-label', 'Quitar ' + label);
    x.innerHTML = '<svg viewBox="0 0 10 10" width="10" height="10"><path d="M2 2l6 6M8 2l-6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
    x.addEventListener('click', (e) => { e.stopPropagation(); onRemove(); });
    chip.appendChild(text);
    chip.appendChild(x);
    return chip;
  }

  function syncNewInput() {
    newInput.value = Array.from(newTags).join(',');
  }

  function filterOptions(q) {
    const lq = q.toLowerCase();
    let visible = 0;
    optionsEl.querySelectorAll('.ms-option').forEach(opt => {
      const match = !lq || opt.dataset.tagName.includes(lq);
      opt.hidden = !match;
      if (match) visible++;
    });
    const isExisting = existingNames().includes(lq);
    const isAlreadyNew = newTags.has(lq);

    if (lq && !isExisting && !isAlreadyNew) {
      createEl.classList.remove('hidden');
      createN.textContent = lq;
    } else {
      createEl.classList.add('hidden');
    }

    // Empty state only when no matches AND no create option
    emptyEl.classList.toggle('hidden', visible > 0 || !!lq);
  }

  function openDropdown() {
    dropdown.hidden = false;
    root.classList.add('is-open');
    field.setAttribute('aria-expanded', 'true');
    setTimeout(() => input.focus(), 0);
  }
  function closeDropdown() {
    dropdown.hidden = true;
    root.classList.remove('is-open');
    field.setAttribute('aria-expanded', 'false');
    input.value = '';
    filterOptions('');
  }

  // Initial state
  optionsEl.querySelectorAll('input[type=checkbox]:checked').forEach(cb => {
    cb.closest('.ms-option').classList.add('is-checked');
  });
  renderChips();

  // Open on field click
  field.addEventListener('click', (e) => {
    e.preventDefault();
    if (dropdown.hidden) openDropdown(); else closeDropdown();
  });

  // Toggle options
  optionsEl.addEventListener('change', (e) => {
    const cb = e.target;
    if (cb.type !== 'checkbox') return;
    cb.closest('.ms-option').classList.toggle('is-checked', cb.checked);
    renderChips();
  });

  // Search input
  input.addEventListener('input', () => filterOptions(input.value.trim()));

  // Keyboard shortcuts inside search
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      const q = input.value.trim().toLowerCase();
      if (!q) return;
      if (existingNames().includes(q)) {
        const cb = Array.from(optionsEl.querySelectorAll('input[type=checkbox]'))
          .find(c => c.dataset.name.toLowerCase() === q);
        if (cb && !cb.checked) {
          cb.checked = true;
          cb.closest('.ms-option').classList.add('is-checked');
        }
      } else if (!newTags.has(q)) {
        newTags.add(q);
        syncNewInput();
      }
      input.value = '';
      filterOptions('');
      renderChips();
    } else if (e.key === 'Escape') {
      closeDropdown();
      field.focus();
    }
  });

  // Click create row
  createEl.addEventListener('click', () => {
    const name = createN.textContent.trim().toLowerCase();
    if (name && !newTags.has(name)) {
      newTags.add(name);
      syncNewInput();
      input.value = '';
      filterOptions('');
      renderChips();
      input.focus();
    }
  });

  // Close on outside click
  document.addEventListener('mousedown', (e) => {
    if (!root.contains(e.target) && !dropdown.hidden) closeDropdown();
  });
})();
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
