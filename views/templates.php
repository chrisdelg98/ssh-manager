<?php
use App\CsrfGuard;
/** @var array $tmpl */
include __DIR__ . '/layout.php';
?>

<div class="page-header">
  <h2>Templates de Mantenimiento</h2>
  <a href="?action=template_add" class="btn btn-primary">+ Nuevo Template</a>
</div>

<?php if (empty($tmpl)): ?>
<div class="empty-state">
  <p>No hay templates. Crea tu primero o carga el seed de datos.</p>
  <a href="?action=template_add" class="btn btn-primary">Crear template</a>
</div>
<?php else: ?>
<div class="templates-grid">
  <?php foreach ($tmpl as $t): ?>
  <div class="template-card">
    <div class="template-card-head">
      <span class="template-name"><?= htmlspecialchars($t['name']) ?></span>
      <?php if ($t['os_target']): ?>
      <span class="os-badge"><?= htmlspecialchars($t['os_target']) ?></span>
      <?php endif; ?>
    </div>
    <?php if ($t['description']): ?>
    <p class="template-desc"><?= htmlspecialchars($t['description']) ?></p>
    <?php endif; ?>
    <ol class="step-list">
      <?php foreach ($t['steps'] as $step): ?>
      <li>
        <code><?= htmlspecialchars($step['command']) ?></code>
        <?php if ($step['description']): ?>
        <span class="step-desc"> — <?= htmlspecialchars($step['description']) ?></span>
        <?php endif; ?>
        <?php if ($step['stop_on_error'] ?? false): ?>
        <span class="badge badge-warn" title="Detiene si hay error">stop_on_error</span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>
    <div class="template-actions">
      <a href="?action=template_edit&id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-secondary">Editar</a>
      <form method="POST" action="?action=template_delete" style="display:inline"
            onsubmit="return confirm('¿Eliminar este template?')">
        <?= CsrfGuard::field() ?>
        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/layout_end.php'; ?>
