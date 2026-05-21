<?php
use App\CsrfGuard;
$isEdit = isset($editTmpl) && $editTmpl !== null;
include __DIR__ . '/layout.php';
?>

<div class="page-header">
  <h2><?= $isEdit ? 'Editar Template' : 'Nuevo Template' ?></h2>
  <a href="?action=templates" class="btn btn-secondary">← Volver</a>
</div>

<form method="POST"
      action="?action=<?= $isEdit ? 'template_edit&id='.(int)$editTmpl['id'] : 'template_add' ?>"
      class="form-card" id="tmpl-form">
  <?= CsrfGuard::field() ?>

  <div class="form-row">
    <div class="form-group">
      <label>Nombre del Template *</label>
      <input type="text" name="name" required
             value="<?= htmlspecialchars($editTmpl['name'] ?? '') ?>"
             placeholder="Actualización AlmaLinux">
    </div>
    <div class="form-group form-group-sm">
      <label>OS Target</label>
      <select name="os_target">
        <?php foreach (['','General','AlmaLinux','CentOS','Ubuntu','Debian','cPanel','Other'] as $o): ?>
        <option value="<?= $o ?>" <?= ($editTmpl['os_target'] ?? '') === $o ? 'selected' : '' ?>>
          <?= $o ?: 'Sin especificar' ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="form-group">
    <label>Descripción</label>
    <input type="text" name="description"
           value="<?= htmlspecialchars($editTmpl['description'] ?? '') ?>"
           placeholder="Qué hace este template">
  </div>

  <h3 style="margin-top:1.5rem">Pasos <button type="button" class="btn btn-sm btn-secondary" onclick="addStep()">+ Agregar paso</button></h3>

  <div id="steps-container">
    <?php $steps = $editTmpl['steps'] ?? [['command'=>'','description'=>'','stop_on_error'=>false]]; ?>
    <?php foreach ($steps as $i => $step): ?>
    <div class="step-row" id="step-<?= $i ?>">
      <span class="step-number"><?= $i + 1 ?></span>
      <div class="step-fields">
        <input type="text" name="step_command[]" required
               value="<?= htmlspecialchars($step['command']) ?>"
               placeholder="Comando" style="font-family:monospace">
        <input type="text" name="step_desc[]"
               value="<?= htmlspecialchars($step['description']) ?>"
               placeholder="Descripción del paso">
        <label class="checkbox-label">
          <input type="checkbox" name="step_stop[<?= $i ?>]" <?= ($step['stop_on_error'] ?? false) ? 'checked' : '' ?>>
          Detener si falla
        </label>
      </div>
      <button type="button" class="btn btn-sm btn-danger" onclick="removeStep(<?= $i ?>)">×</button>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="form-actions" style="margin-top:1.5rem">
    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Guardar cambios' : 'Crear template' ?></button>
    <a href="?action=templates" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<script>
let stepCount = <?= count($steps) ?>;

function addStep() {
  const i = stepCount++;
  const div = document.createElement('div');
  div.className = 'step-row';
  div.id = 'step-' + i;
  div.innerHTML = `
    <span class="step-number">${document.querySelectorAll('.step-row').length + 1}</span>
    <div class="step-fields">
      <input type="text" name="step_command[]" required placeholder="Comando" style="font-family:monospace">
      <input type="text" name="step_desc[]" placeholder="Descripción del paso">
      <label class="checkbox-label">
        <input type="checkbox" name="step_stop[${i}]"> Detener si falla
      </label>
    </div>
    <button type="button" class="btn btn-sm btn-danger" onclick="removeStep(${i})">×</button>
  `;
  document.getElementById('steps-container').appendChild(div);
}

function removeStep(i) {
  const el = document.getElementById('step-' + i);
  if (el) el.remove();
}
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
