<?php
use App\CsrfGuard;
/** @var array $servers */
$pageTitle = 'Dashboard';
include __DIR__ . '/layout.php';

// Group servers by type
$grouped = [];
foreach ($servers as $s) {
    $grouped[$s['type']][] = $s;
}
$typeOrder = ['VPS', 'Reseller', 'Dedicated', 'Other'];

// Quick stats
$total       = count($servers);
$activeCount = count(array_filter($servers, fn($s) => (int)$s['active'] === 1));
$byType      = [];
foreach ($servers as $s) { $byType[$s['type']] = ($byType[$s['type']] ?? 0) + 1; }
arsort($byType);
$topType = $byType ? array_key_first($byType) : '—';
?>

<?php if (!empty($_SESSION['dashboard_error'])): ?>
  <div class="alert alert-error"><?= htmlspecialchars($_SESSION['dashboard_error']) ?></div>
<?php unset($_SESSION['dashboard_error']); endif; ?>
<?php if (!empty($_SESSION['dashboard_ok'])): ?>
  <div class="alert alert-ok"><?= htmlspecialchars($_SESSION['dashboard_ok']) ?></div>
<?php unset($_SESSION['dashboard_ok']); endif; ?>

<div class="page-header">
  <div>
    <h1>Servidores</h1>
    <div class="page-subtitle">Inventario cifrado de tus máquinas remotas.</div>
  </div>
  <div class="header-actions">
    <a href="?action=server_add" class="btn btn-primary">+ Nuevo servidor</a>
  </div>
</div>

<section class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">Total servidores</div>
    <div class="stat-value"><?= (int)$total ?></div>
    <div class="stat-sub">en inventario</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Activos</div>
    <div class="stat-value"><?= (int)$activeCount ?></div>
    <div class="stat-sub">listos para conectar</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Tipo principal</div>
    <div class="stat-value text-accent"><?= htmlspecialchars($topType) ?></div>
    <div class="stat-sub"><?= isset($byType[$topType]) ? (int)$byType[$topType] . ' instancia(s)' : '—' ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Seguridad</div>
    <div class="stat-value text-mono" style="font-size:1.3rem">AES-256-GCM</div>
    <div class="stat-sub">credenciales cifradas en reposo</div>
  </div>
</section>

<?php if (empty($servers)): ?>
  <div class="empty-state">
    <div class="empty-icon">$_</div>
    <p>No hay servidores registrados todavía.</p>
    <a href="?action=server_add" class="btn btn-primary">Agregar primer servidor</a>
  </div>
<?php else: ?>
  <?php foreach ($typeOrder as $type): ?>
    <?php if (empty($grouped[$type])) continue; ?>
    <section class="server-group">
      <h3 class="group-title"><?= htmlspecialchars($type) ?> — <?= count($grouped[$type]) ?></h3>
      <div class="server-grid">
        <?php foreach ($grouped[$type] as $s): ?>
          <div class="server-card <?= $s['active'] ? '' : 'server-inactive' ?>"
               style="border-left-color: <?= htmlspecialchars($s['color_tag']) ?>">
            <div class="server-card-head">
              <span class="server-name"><?= htmlspecialchars($s['name']) ?></span>
              <span class="server-type-badge"><?= htmlspecialchars($s['type']) ?></span>
            </div>
            <div class="server-host"><?= htmlspecialchars($s['host']) ?>:<?= (int)$s['port'] ?></div>
            <div class="server-auth">Auth: <?= htmlspecialchars($s['auth_type']) ?></div>
            <div class="server-actions">
              <a href="?action=terminal&id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-primary">Terminal</a>
              <a href="?action=server_edit&id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-ghost">Editar</a>
              <form method="POST" action="?action=server_delete" style="display:inline"
                    data-confirm-title="Eliminar servidor"
                    data-confirm="Vas a eliminar &quot;<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>&quot;.&#10;Esta acción es definitiva y no se puede deshacer."
                    data-confirm-action="Sí, eliminar">
                <?= CsrfGuard::field() ?>
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">Eliminar</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/layout_end.php'; ?>
