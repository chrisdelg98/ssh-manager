<?php
use App\CsrfGuard;
/** @var array $cmds @var array $cats @var ?array $editCmd */
include __DIR__ . '/layout.php';
$search  = htmlspecialchars($_GET['q']   ?? '');
$selCat  = htmlspecialchars($_GET['cat'] ?? '');
$selOs   = htmlspecialchars($_GET['os']  ?? '');
$osOptions = ['General','AlmaLinux','CentOS','Ubuntu','Debian','cPanel','Other'];
?>

<div class="page-header">
  <h2>Biblioteca de Comandos</h2>
  <button class="btn btn-primary" onclick="toggleForm('add-form')">+ Agregar Comando</button>
</div>

<!-- Formulario de agregar/editar -->
<div id="add-form" class="form-card <?= isset($editCmd) ? '' : 'hidden' ?>">
  <h3><?= isset($editCmd) ? 'Editar Comando' : 'Nuevo Comando' ?></h3>
  <form method="POST"
        action="?action=<?= isset($editCmd) ? 'command_edit&id='.(int)$editCmd['id'] : 'command_add' ?>">
    <?= CsrfGuard::field() ?>
    <div class="form-row">
      <div class="form-group">
        <label>Título *</label>
        <input type="text" name="title" required
               value="<?= htmlspecialchars($editCmd['title'] ?? '') ?>"
               placeholder="Verificar espacio en disco">
      </div>
      <div class="form-group form-group-sm">
        <label>Categoría *</label>
        <input type="text" name="category" required list="cat-list"
               value="<?= htmlspecialchars($editCmd['category'] ?? '') ?>"
               placeholder="Disco">
        <datalist id="cat-list">
          <?php foreach ($cats ?? [] as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>">
          <?php endforeach; ?>
          <option value="Disco"><option value="Actualizaciones"><option value="Servicios">
          <option value="Backups"><option value="Red"><option value="Logs"><option value="cPanel">
        </datalist>
      </div>
      <div class="form-group form-group-sm">
        <label>OS Target</label>
        <select name="os_target">
          <option value="">General</option>
          <?php foreach ($osOptions as $o): ?>
          <option value="<?= $o ?>" <?= ($editCmd['os_target'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label>Comando *</label>
      <textarea name="command" rows="3" required
                style="font-family:monospace"><?= htmlspecialchars($editCmd['command'] ?? '') ?></textarea>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Descripción</label>
        <input type="text" name="description"
               value="<?= htmlspecialchars($editCmd['description'] ?? '') ?>"
               placeholder="Qué hace este comando">
      </div>
      <div class="form-group">
        <label>Tags (coma separados)</label>
        <input type="text" name="tags"
               value="<?= htmlspecialchars($editCmd['tags'] ?? '') ?>"
               placeholder="disco, espacio, df">
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Guardar</button>
      <button type="button" class="btn btn-secondary" onclick="toggleForm('add-form')">Cancelar</button>
    </div>
  </form>
</div>

<!-- Búsqueda -->
<form method="GET" action="" class="search-bar">
  <input type="hidden" name="action" value="commands">
  <input type="text" name="q" value="<?= $search ?>" placeholder="Buscar comando..." class="input-search">
  <select name="cat">
    <option value="">Todas las categorías</option>
    <?php foreach ($cats ?? [] as $c): ?>
    <option value="<?= htmlspecialchars($c) ?>" <?= $selCat === htmlspecialchars($c) ? 'selected' : '' ?>>
      <?= htmlspecialchars($c) ?>
    </option>
    <?php endforeach; ?>
  </select>
  <select name="os">
    <option value="">Todos los OS</option>
    <?php foreach ($osOptions as $o): ?>
    <option value="<?= $o ?>" <?= $selOs === $o ? 'selected' : '' ?>><?= $o ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-secondary">Buscar</button>
</form>

<!-- Tabla de comandos -->
<div class="table-responsive">
<table class="table">
  <thead>
    <tr>
      <th>Título</th>
      <th>Comando</th>
      <th>Categoría</th>
      <th>OS</th>
      <th>Usos</th>
      <th>Acciones</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($cmds)): ?>
    <tr><td colspan="6" style="text-align:center;color:#888">No hay comandos<?= $search ? ' para esa búsqueda' : '' ?>.</td></tr>
    <?php else: ?>
    <?php foreach ($cmds as $c): ?>
    <tr>
      <td>
        <strong><?= htmlspecialchars($c['title']) ?></strong>
        <?php if ($c['description']): ?>
        <br><small class="text-muted"><?= htmlspecialchars($c['description']) ?></small>
        <?php endif; ?>
      </td>
      <td>
        <code class="cmd-code"><?= htmlspecialchars($c['command']) ?></code>
      </td>
      <td><span class="badge"><?= htmlspecialchars($c['category']) ?></span></td>
      <td><?= htmlspecialchars($c['os_target'] ?? 'General') ?></td>
      <td><?= (int)$c['usage_count'] ?></td>
      <td class="actions-cell">
        <button class="btn btn-sm btn-secondary"
                onclick="copyCommand(<?= htmlspecialchars(json_encode($c['command']), ENT_QUOTES) ?>, <?= (int)$c['id'] ?>)">
          Copiar
        </button>
        <a href="?action=command_edit&id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-secondary">Editar</a>
        <form method="POST" action="?action=command_delete" style="display:inline"
              onsubmit="return confirm('¿Eliminar este comando?')">
          <?= CsrfGuard::field() ?>
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button type="submit" class="btn btn-sm btn-danger">Borrar</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>
</div>

<script>
function toggleForm(id) {
  document.getElementById(id).classList.toggle('hidden');
}

function copyCommand(cmd, id) {
  navigator.clipboard.writeText(cmd).then(() => {
    // Increment usage counter silently
    fetch('?action=command_use&id=' + id);
  });
}
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
