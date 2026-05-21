<?php
use App\CsrfGuard;
/** @var array|null $editSnippet @var string $error */
$editing  = !empty($editSnippet);
$pageTitle = $editing ? 'Editar snippet' : 'Nuevo snippet';
$error    = $error ?? '';
include __DIR__ . '/layout.php';
$action = $editing
    ? '?action=snippet_edit&id=' . (int)$editSnippet['id']
    : '?action=snippet_add';
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

<form method="POST" action="<?= $action ?>" class="form-card" autocomplete="off">
  <?= CsrfGuard::field() ?>
  <div class="form-row">
    <div class="form-group">
      <label>Título</label>
      <input type="text" name="title" required maxlength="150"
             value="<?= htmlspecialchars($editSnippet['title'] ?? '') ?>">
    </div>
    <div class="form-group form-group-sm">
      <label>Categoría</label>
      <input type="text" name="category" maxlength="50" placeholder="General"
             value="<?= htmlspecialchars($editSnippet['category'] ?? 'General') ?>">
    </div>
  </div>

  <div class="form-group">
    <label>Comando</label>
    <textarea name="command" required rows="3"
              placeholder="systemctl status nginx"><?= htmlspecialchars($editSnippet['command'] ?? '') ?></textarea>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label>OS / Plataforma (opcional)</label>
      <input type="text" name="os_target" maxlength="50" placeholder="AlmaLinux / Ubuntu / cPanel"
             value="<?= htmlspecialchars($editSnippet['os_target'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Tags (opcional)</label>
      <input type="text" name="tags" maxlength="255" placeholder="nginx, logs, debug"
             value="<?= htmlspecialchars($editSnippet['tags'] ?? '') ?>">
    </div>
  </div>

  <div class="form-group">
    <label>Descripción (opcional)</label>
    <textarea name="description" rows="2"><?= htmlspecialchars($editSnippet['description'] ?? '') ?></textarea>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary"><?= $editing ? 'Guardar cambios' : 'Crear snippet' ?></button>
    <a href="?action=snippets" class="btn btn-ghost">Cancelar</a>
  </div>
</form>

<?php include __DIR__ . '/layout_end.php'; ?>
