<?php
use App\CsrfGuard;
/** @var array $snippets @var array $cats */
$pageTitle = 'Snippets';
include __DIR__ . '/layout.php';
$q   = $_GET['q']   ?? '';
$cat = $_GET['cat'] ?? '';
?>

<div class="page-header">
  <div>
    <h1>Snippets</h1>
    <div class="page-subtitle">Comandos reutilizables, listos para copiar al portapapeles.</div>
  </div>
  <div class="header-actions">
    <a href="?action=snippet_add" class="btn btn-primary">+ Nuevo snippet</a>
  </div>
</div>

<form method="GET" action="" class="filter-bar">
  <input type="hidden" name="action" value="snippets">
  <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por título, comando o tag…" class="input-search">
  <select name="cat">
    <option value="">Todas las categorías</option>
    <?php foreach ($cats as $c): ?>
      <?php $name = is_array($c) ? $c['name'] : $c; ?>
      <option value="<?= htmlspecialchars($name) ?>" <?= $cat === $name ? 'selected' : '' ?>>
        <?= htmlspecialchars($name) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-secondary">Filtrar</button>
  <?php if ($q !== '' || $cat !== ''): ?>
    <a href="?action=snippets" class="btn btn-ghost">Limpiar</a>
  <?php endif; ?>
</form>

<?php if (empty($snippets)): ?>
  <div class="empty-state">
    <div class="empty-icon">{ }</div>
    <p>No hay snippets que coincidan.</p>
    <a href="?action=snippet_add" class="btn btn-primary">Crear primer snippet</a>
  </div>
<?php else: ?>
  <div class="snippet-grid">
    <?php foreach ($snippets as $s): ?>
      <article class="snippet-card">
        <div class="snippet-head">
          <span class="snippet-title"><?= htmlspecialchars($s['title']) ?></span>
          <span class="snippet-category"><?= htmlspecialchars($s['category']) ?></span>
        </div>
        <?php if (!empty($s['description'])): ?>
          <div class="snippet-desc"><?= htmlspecialchars($s['description']) ?></div>
        <?php endif; ?>
        <pre class="snippet-cmd"><?= htmlspecialchars($s['command']) ?></pre>
        <?php if (!empty($s['os_target'])): ?>
          <div class="mb-1"><span class="os-badge"><?= htmlspecialchars($s['os_target']) ?></span></div>
        <?php endif; ?>
        <div class="snippet-actions">
          <button type="button" class="btn btn-sm btn-primary"
                  data-cmd="<?= htmlspecialchars($s['command'], ENT_QUOTES) ?>"
                  onclick="copySnippet(this)">Copiar</button>
          <a href="?action=snippet_edit&id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-ghost">Editar</a>
          <form method="POST" action="?action=snippet_delete" style="display:inline"
                data-confirm-title="Eliminar snippet"
                data-confirm="Vas a eliminar &quot;<?= htmlspecialchars($s['title'], ENT_QUOTES) ?>&quot;.&#10;Esta acción no se puede deshacer."
                data-confirm-action="Sí, eliminar">
            <?= CsrfGuard::field() ?>
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
async function copySnippet(btn) {
  const cmd = btn.dataset.cmd;
  try {
    await navigator.clipboard.writeText(cmd);
    const old = btn.textContent;
    btn.textContent = '✓ Copiado';
    setTimeout(() => { btn.textContent = old; }, 1500);
  } catch (e) {
    alert('No se pudo copiar al portapapeles.');
  }
}
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
