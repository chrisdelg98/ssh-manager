<?php
use App\CsrfGuard;
/** @var array|null $server @var string $error */
$isEdit = $server !== null;
include __DIR__ . '/layout.php';
?>

<div class="page-header">
  <h2><?= $isEdit ? 'Editar Servidor' : 'Agregar Servidor' ?></h2>
  <a href="?action=dashboard" class="btn btn-secondary">← Volver</a>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" action="?action=<?= $isEdit ? 'server_edit&id=' . (int)($server['id'] ?? 0) : 'server_add' ?>"
      class="form-card" autocomplete="off">
  <?= CsrfGuard::field() ?>

  <div class="form-row">
    <div class="form-group">
      <label>Nombre del Servidor *</label>
      <input type="text" name="name" required
             value="<?= htmlspecialchars($server['name'] ?? '') ?>"
             placeholder="Mi VPS Principal">
    </div>
    <div class="form-group">
      <label>Tipo *</label>
      <select name="type">
        <?php foreach (['VPS','Reseller','Dedicated','Other'] as $t): ?>
        <option value="<?= $t ?>" <?= ($server['type'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label>Host / IP *</label>
      <input type="text" name="host" required
             value="<?= htmlspecialchars($server['host'] ?? '') ?>"
             placeholder="203.161.44.21 o hostname.com">
    </div>
    <div class="form-group form-group-sm">
      <label>Puerto *</label>
      <input type="number" name="port" min="1" max="65535"
             value="<?= (int)($server['port'] ?? 22) ?>">
    </div>
    <div class="form-group form-group-sm">
      <label>Color</label>
      <input type="color" name="color_tag"
             value="<?= htmlspecialchars($server['color_tag'] ?? '#4A90D9') ?>">
    </div>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label>Usuario SSH *</label>
      <input type="text" name="ssh_user" required autocomplete="off"
             value="<?= htmlspecialchars($server['ssh_user'] ?? '') ?>"
             placeholder="root">
    </div>
    <div class="form-group">
      <label>Tipo de Autenticación *</label>
      <select name="auth_type" id="auth_type" onchange="toggleAuthType()">
        <option value="password" <?= ($server['auth_type'] ?? '') === 'password' ? 'selected' : '' ?>>Contraseña</option>
        <option value="key"      <?= ($server['auth_type'] ?? '') === 'key'      ? 'selected' : '' ?>>Llave Privada (PEM)</option>
      </select>
    </div>
  </div>

  <div class="form-group" id="field_password">
    <label>Contraseña SSH<?= $isEdit ? ' (dejar vacío para no cambiar)' : ' *' ?></label>
    <input type="password" name="credential_pass" <?= $isEdit ? '' : 'required' ?>
           autocomplete="new-password" id="input_credential_pass">
  </div>

  <div class="form-group" id="field_key" style="display:none">
    <label>Llave Privada (contenido PEM)<?= $isEdit ? ' (dejar vacío para no cambiar)' : ' *' ?></label>
    <textarea name="credential_key" rows="6" id="input_credential_key"
              placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;...&#10;-----END OPENSSH PRIVATE KEY-----"></textarea>
  </div>

  <div class="form-group">
    <label>Notas (opcional)</label>
    <textarea name="notes" rows="3"
              placeholder="Panel de control, servicios corriendo, etc."><?= htmlspecialchars($server['notes'] ?? '') ?></textarea>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Guardar cambios' : 'Agregar servidor' ?></button>
    <a href="?action=dashboard" class="btn btn-secondary">Cancelar</a>
  </div>
</form>

<script>
function toggleAuthType() {
  const t = document.getElementById('auth_type').value;
  document.getElementById('field_password').style.display = t === 'password' ? '' : 'none';
  document.getElementById('field_key').style.display      = t === 'key'      ? '' : 'none';
}
toggleAuthType();
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
