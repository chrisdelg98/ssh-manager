<?php
use App\CsrfGuard;
/** @var string $error */
$pageTitle = 'Nueva clave SSH';
$error = $error ?? '';
include __DIR__ . '/layout.php';
?>

<div class="page-header">
  <div>
    <h1>Nueva clave SSH</h1>
    <div class="page-subtitle">Se cifra con tu Master Password antes de tocar el disco.</div>
  </div>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="POST" action="?action=key_add" class="form-card" autocomplete="off">
  <?= CsrfGuard::field() ?>

  <div class="form-group">
    <label for="key_name">Nombre identificable</label>
    <input id="key_name" type="text" name="name" required maxlength="100"
           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
           placeholder="ej. deploy@servidor-prod">
    <small class="hint">Solo para identificar la clave en el listado. No se sube a ningún servidor.</small>
  </div>

  <div class="form-group">
    <label for="key_priv">Clave privada</label>
    <textarea id="key_priv" name="private_key" required rows="12"
              placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;b3BlbnNzaC1rZXktdjEAAAA...&#10;-----END OPENSSH PRIVATE KEY-----"
              spellcheck="false"><?= htmlspecialchars($_POST['private_key'] ?? '') ?></textarea>
    <small class="hint">Soportadas: RSA, Ed25519, ECDSA, DSA. Formatos OpenSSH y PEM.</small>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label for="key_pass">Passphrase (si la clave la tiene)</label>
      <input id="key_pass" type="password" name="passphrase" autocomplete="new-password"
             placeholder="Opcional">
      <small class="hint">Se guarda también cifrada, junto con la clave.</small>
    </div>
  </div>

  <div class="form-group">
    <label for="key_notes">Notas (opcional)</label>
    <textarea id="key_notes" name="notes" rows="2"
              placeholder="ej. clave generada el 12/02/2026 para CI/CD"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">Guardar clave</button>
    <a href="?action=keys" class="btn btn-ghost">Cancelar</a>
  </div>
</form>

<?php include __DIR__ . '/layout_end.php'; ?>
