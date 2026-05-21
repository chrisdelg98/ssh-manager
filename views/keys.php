<?php
use App\CsrfGuard;
/** @var array $keys */
$pageTitle = 'Claves SSH';
include __DIR__ . '/layout.php';
?>

<?php if (!empty($_SESSION['dashboard_ok'])): ?>
  <div class="alert alert-ok"><?= htmlspecialchars($_SESSION['dashboard_ok']) ?></div>
<?php unset($_SESSION['dashboard_ok']); endif; ?>
<?php if (!empty($_SESSION['dashboard_error'])): ?>
  <div class="alert alert-error"><?= htmlspecialchars($_SESSION['dashboard_error']) ?></div>
<?php unset($_SESSION['dashboard_error']); endif; ?>

<div class="page-header">
  <div>
    <h1>Claves SSH</h1>
    <div class="page-subtitle">Llaves privadas cifradas en reposo con tu Master Password.</div>
  </div>
  <div class="header-actions">
    <a href="?action=key_add" class="btn btn-primary">+ Nueva clave</a>
  </div>
</div>

<?php if (empty($keys)): ?>
  <div class="empty-state">
    <div class="empty-icon">~/.ssh</div>
    <p>Aún no tienes claves almacenadas.</p>
    <p class="hint">Sube una clave privada existente o pega su contenido para guardarla cifrada.</p>
    <a href="?action=key_add" class="btn btn-primary mt-2">Agregar primera clave</a>
  </div>
<?php else: ?>
  <div class="key-grid">
    <?php foreach ($keys as $k): ?>
      <article class="key-card">
        <div class="key-card-head">
          <span class="key-name"><?= htmlspecialchars($k['name']) ?></span>
          <span class="key-type-badge"><?= htmlspecialchars($k['key_type']) ?><?= $k['bits'] ? ' · ' . (int)$k['bits'] . 'b' : '' ?></span>
        </div>
        <div class="key-fingerprint" title="SHA256 fingerprint"><?= htmlspecialchars($k['fingerprint']) ?></div>
        <div class="key-meta">Agregada: <?= htmlspecialchars($k['created_at']) ?></div>
        <div class="key-actions">
          <form method="POST" action="?action=key_delete" style="display:inline"
                data-confirm-title="Eliminar clave SSH"
                data-confirm="Vas a eliminar la clave &quot;<?= htmlspecialchars($k['name'], ENT_QUOTES) ?>&quot;.&#10;Los servidores que la tengan asignada perderán la referencia (no se desactivan).&#10;Esta acción no se puede deshacer."
                data-confirm-action="Sí, eliminar">
            <?= CsrfGuard::field() ?>
            <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/layout_end.php'; ?>
