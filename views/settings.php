<?php
use App\CsrfGuard;
/** @var array $config @var \App\SessionManager $session */
$pageTitle = 'Configuración';
include __DIR__ . '/layout.php';
$ok    = $_SESSION['settings_ok']    ?? '';
$error = $_SESSION['settings_error'] ?? '';
unset($_SESSION['settings_ok'], $_SESSION['settings_error']);

$themes = [
    'matrix'   => ['Matrix',   'Hacker verde · oscuro'],
    'void'     => ['Void',     'Azul profundo · oscuro'],
    'daylight' => ['Daylight', 'Limpio · claro'],
    'dusk'     => ['Dusk',     'Púrpura tibio · medio'],
];
?>

<div class="page-header">
  <div>
    <h1>Configuración</h1>
    <div class="page-subtitle">Personalización, seguridad y datos de tu sesión.</div>
  </div>
</div>

<?php if ($ok):    ?><div class="alert alert-ok"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Apariencia -->
<section class="settings-card">
  <h3>Apariencia</h3>
  <p class="card-desc">Elige un tema. El cambio se aplica al instante y queda guardado para tu próximo login.</p>

  <div class="theme-grid">
    <?php foreach ($themes as $key => [$label, $desc]): ?>
      <button type="button" class="theme-swatch" data-preview="<?= $key ?>" aria-label="Tema <?= $label ?>">
        <div class="theme-swatch-preview"></div>
        <div class="theme-swatch-name"><?= htmlspecialchars($label) ?></div>
        <div class="theme-swatch-desc"><?= htmlspecialchars($desc) ?></div>
      </button>
    <?php endforeach; ?>
  </div>
</section>

<!-- Cambiar Master Password -->
<section class="settings-card">
  <h3>Cambiar Master Password</h3>
  <p class="card-desc">Al cambiarla, todas las credenciales y claves SSH se re-encriptan automáticamente con la nueva clave derivada.</p>
  <form method="POST" action="?action=settings_save" autocomplete="off"
        data-confirm="¿Seguro? Esto re-encripta TODAS las credenciales y claves SSH almacenadas. La operación es atómica pero no se puede deshacer.">
    <?= CsrfGuard::field() ?>
    <input type="hidden" name="settings_type" value="master_password">
    <div class="form-group">
      <label>Master Password actual</label>
      <input type="password" name="old_master" required autocomplete="current-password">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Nuevo Master Password</label>
        <input type="password" name="new_master" required autocomplete="new-password"
               minlength="12" placeholder="Mínimo 12 caracteres">
      </div>
      <div class="form-group">
        <label>Confirmar nuevo Master Password</label>
        <input type="password" name="confirm_master" required autocomplete="new-password"
               minlength="12">
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Cambiar Master Password</button>
    </div>
  </form>
</section>

<!-- Sesión -->
<section class="settings-card">
  <h3>Sesión actual</h3>
  <table class="table" style="max-width:560px; margin-bottom:1rem">
    <tr><td>Usuario</td><td class="text-strong"><?= htmlspecialchars($session->username() ?? '') ?></td></tr>
    <tr><td>Timeout de inactividad</td><td><?= (int)($config['app']['session_timeout'] / 60) ?> minutos</td></tr>
    <tr><td>Cifrado en reposo</td><td class="text-mono">AES-256-GCM</td></tr>
    <tr><td>Derivación de clave</td><td class="text-mono">PBKDF2-SHA256 · 310 000 iter</td></tr>
    <tr><td>Rate limit login</td><td><?= (int)$config['app']['max_login_attempts'] ?> intentos · bloqueo <?= (int)$config['app']['lockout_minutes'] ?> min</td></tr>
  </table>
  <a href="?action=logout" class="btn btn-danger">Cerrar sesión</a>
</section>

<!-- Capas de seguridad -->
<section class="settings-card">
  <h3>Capas de seguridad activas</h3>
  <p class="card-desc">Lista de mecanismos defensivos verificables en esta instalación.</p>
  <ul class="security-list">
    <li>HTTPS obligatorio con HSTS + preload</li>
    <li>Autenticación en 3 factores (contraseña + TOTP + Master Password)</li>
    <li>Credenciales SSH y claves privadas cifradas con AES-256-GCM (claves nunca tocan la DB)</li>
    <li>Username cifrado en DB (HMAC para lookup + AES-GCM para display)</li>
    <li>Tokens CSRF en todos los formularios y endpoints AJAX</li>
    <li>Session fingerprinting (UA + IP + session_id) + timeout automático</li>
    <li>Cookies HttpOnly · Secure · SameSite=Lax · path=/</li>
    <li>Logs de auditoría con detalle cifrado por usuario</li>
    <li>Cabeceras: CSP estricta, X-Frame-Options DENY, COOP/CORP, Referrer-Policy no-referrer</li>
    <li>X-Robots-Tag + robots.txt = bloqueo total a buscadores</li>
    <li>Rate-limit con bloqueo temporal por intentos fallidos</li>
    <li>.htaccess bloquea acceso a /src, /config, /database, /vendor, .env, dumps SQL y dotfiles</li>
    <li>Configuración fuera del repo (.env, permisos 600)</li>
  </ul>
</section>

<?php include __DIR__ . '/layout_end.php'; ?>
