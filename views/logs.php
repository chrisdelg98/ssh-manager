<?php
use App\CsrfGuard;
/** @var array $logs @var array $servers @var array $filters @var int $page @var int $pages @var int $total */
include __DIR__ . '/layout.php';

$actionTypes = ['LOGIN','LOGOUT','LOGIN_FAIL','SSH_EXEC','TEMPLATE_RUN',
                'SERVER_ADD','SERVER_EDIT','SERVER_DELETE','COMMAND_COPY','SETTINGS_CHANGE'];
?>

<div class="page-header">
  <h2>Audit Logs</h2>
  <small class="text-muted"><?= number_format($total) ?> registros</small>
</div>

<!-- Filtros -->
<form method="GET" action="" class="filter-bar">
  <input type="hidden" name="action" value="logs">
  <select name="server_id">
    <option value="">Todos los servidores</option>
    <?php foreach ($servers as $s): ?>
    <option value="<?= (int)$s['id'] ?>" <?= ($filters['server_id'] == $s['id']) ? 'selected' : '' ?>>
      <?= htmlspecialchars($s['name']) ?>
    </option>
    <?php endforeach; ?>
  </select>
  <select name="action_type">
    <option value="">Todas las acciones</option>
    <?php foreach ($actionTypes as $at): ?>
    <option value="<?= $at ?>" <?= $filters['action_type'] === $at ? 'selected' : '' ?>><?= $at ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status">
    <option value="">Todos los estados</option>
    <option value="success" <?= $filters['status'] === 'success' ? 'selected' : '' ?>>success</option>
    <option value="failure" <?= $filters['status'] === 'failure' ? 'selected' : '' ?>>failure</option>
    <option value="error"   <?= $filters['status'] === 'error'   ? 'selected' : '' ?>>error</option>
  </select>
  <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>"
         placeholder="Desde">
  <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>"
         placeholder="Hasta">
  <button type="submit" class="btn btn-secondary">Filtrar</button>
  <a href="?action=logs" class="btn btn-secondary">Limpiar</a>
</form>

<div class="table-responsive">
<table class="table table-logs">
  <thead>
    <tr>
      <th>Fecha</th>
      <th>Usuario</th>
      <th>Servidor</th>
      <th>Acción</th>
      <th>Estado</th>
      <th>IP</th>
      <th>Detalle</th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($logs)): ?>
    <tr><td colspan="7" style="text-align:center;color:#888">Sin registros.</td></tr>
    <?php else: ?>
    <?php foreach ($logs as $log): ?>
    <tr class="log-row log-<?= $log['status'] ?>">
      <td class="log-date"><?= htmlspecialchars($log['created_at']) ?></td>
      <td><?= htmlspecialchars($log['username'] ?? '—') ?></td>
      <td><?= htmlspecialchars($log['server_name'] ?? '—') ?></td>
      <td><span class="badge badge-action"><?= htmlspecialchars($log['action_type']) ?></span></td>
      <td><span class="badge badge-status badge-<?= $log['status'] ?>"><?= $log['status'] ?></span></td>
      <td class="log-ip"><?= htmlspecialchars($log['ip_address']) ?></td>
      <td>
        <?php if ($log['detail']): ?>
        <?php
          $detail = $log['detail'];
          $decoded = json_decode($detail, true);
          $isJson  = json_last_error() === JSON_ERROR_NONE;
        ?>
        <button class="btn btn-sm btn-secondary" onclick="toggleDetail(this)">Ver</button>
        <div class="log-detail hidden">
          <?php if ($isJson && isset($decoded['command'])): ?>
          <strong>Cmd:</strong> <code><?= htmlspecialchars($decoded['command']) ?></code><br>
          <?php if (isset($decoded['output'])): ?>
          <pre class="log-output"><?= htmlspecialchars(substr($decoded['output'], 0, 500)) ?><?= strlen($decoded['output']) > 500 ? '...' : '' ?></pre>
          <?php endif; ?>
          <?php if (!empty($decoded['error'])): ?>
          <span class="text-error"><?= htmlspecialchars($decoded['error']) ?></span>
          <?php endif; ?>
          <?php elseif ($isJson): ?>
          <pre><?= htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
          <?php else: ?>
          <pre><?= htmlspecialchars($detail) ?></pre>
          <?php endif; ?>
        </div>
        <?php else: ?>—<?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>
</div>

<!-- Paginación -->
<?php if ($pages > 1): ?>
<div class="pagination">
  <?php for ($p = 1; $p <= $pages; $p++): ?>
  <a href="?action=logs&page=<?= $p ?>&<?= http_build_query(array_filter($filters)) ?>"
     class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-secondary' ?>"><?= $p ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<script>
function toggleDetail(btn) {
  const d = btn.nextElementSibling;
  d.classList.toggle('hidden');
  btn.textContent = d.classList.contains('hidden') ? 'Ver' : 'Ocultar';
}
</script>

<?php include __DIR__ . '/layout_end.php'; ?>
