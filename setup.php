<?php
/**
 * SSH Manager — Web Setup (First Run Only)
 *
 * Auto-disables once a user exists in the DB.
 * Access via browser: https://yourdomain.com/setup.php
 */

define('APP_ROOT', __DIR__);

// ── Helpers (defined first so they're available immediately) ─────────────────

function renderError(string $msg): never
{
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
    <title>Error — SSH Manager Setup</title>
    <style>
      body{background:#0f1117;color:#e2e4ef;font-family:system-ui,sans-serif;
           display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0}
      .b{background:#1a1d27;border:1px solid #2d3147;border-radius:12px;padding:2rem;max-width:520px;width:100%}
      h2{color:#e05252;margin-bottom:.75rem}code{background:#222635;padding:.2rem .45rem;border-radius:4px;font-size:.88rem}
      a{color:#4a8af4}
    </style></head><body><div class="b">
    <h2>Error de configuracion</h2><p>' . $msg . '</p>
    </div></body></html>';
    exit;
}

function seedData(PDO $db): void
{
    if ((int)$db->query('SELECT COUNT(*) FROM command_library')->fetchColumn() > 0) {
        return;
    }

    $commands = [
        ['Verificar estado de servicios',        'systemctl status',                                                            'Servicios',      'General',   'Estado general de todos los servicios del sistema',       'systemctl, servicios, status'],
        ['Ver servicios fallidos',                'systemctl --failed',                                                          'Servicios',      'General',   'Muestra los servicios que han fallado',                   'systemctl, fallados, failed'],
        ['Reset servicios fallidos',              'systemctl reset-failed',                                                      'Servicios',      'General',   'Limpia el estado de error de servicios fallidos',         'systemctl, reset, failed'],
        ['Verificar actualizaciones (AlmaLinux)', 'sudo dnf check-update',                                                      'Actualizaciones','AlmaLinux', 'Lista paquetes con actualizaciones en AlmaLinux',         'dnf, update, almalinux'],
        ['Instalar actualizaciones (AlmaLinux)',  'sudo dnf update -y',                                                         'Actualizaciones','AlmaLinux', 'Instala todas las actualizaciones en AlmaLinux',          'dnf, update, almalinux'],
        ['Verificar actualizaciones (CentOS)',    'yum check-update',                                                           'Actualizaciones','CentOS',    'Lista paquetes con actualizaciones en CentOS',            'yum, update, centos'],
        ['Instalar actualizaciones (CentOS)',     'yum update -y',                                                              'Actualizaciones','CentOS',    'Instala todas las actualizaciones en CentOS',             'yum, update, centos'],
        ['Actualizar cPanel/WHM',                 '/scripts/upcp',                                                              'Actualizaciones','cPanel',    'Actualiza cPanel/WHM a la última versión estable',        'cpanel, whm, upcp, update'],
        ['Ver uso de disco (resumen)',             'df -h',                                                                      'Disco',          'General',   'Uso de disco de todas las particiones',                   'df, disco, espacio'],
        ['Carpetas con mayor peso',               'du -h / | sort -hr | head -n 20',                                            'Disco',          'General',   'Lista las 20 carpetas más grandes del sistema',           'du, disco, peso, carpetas'],
        ['Archivos >500MB en /backup',            "find /backup/ -type f -size +500M -exec du -h {} \\; | sort -hr",            'Disco',          'General',   'Archivos grandes dentro de /backup',                     'find, backup, archivos, grandes'],
        ['Archivos >1GB en todo el sistema',      "find / -type f -size +1000M -exec ls -lh --time=ctime {} + | sort -rh -k 5", 'Disco',          'General',   'Archivos mayores a 1GB en todo el sistema',               'find, archivos, grandes, 1gb'],
        ['Ver conexiones de red activas',         'ss -tulnp',                                                                  'Red',            'General',   'Puertos abiertos y conexiones activas',                   'ss, puertos, red, conexiones'],
        ['Ver IP pública del servidor',           'curl -s ifconfig.me',                                                        'Red',            'General',   'IP pública del servidor',                                 'ip, publica, ifconfig'],
        ['Ver auth.log (AlmaLinux)',              'tail -n 100 /var/log/secure',                                                'Logs',           'AlmaLinux', 'Últimas 100 líneas del log de autenticación',            'log, auth, secure, ssh'],
        ['Ver intentos SSH fallidos',             "grep 'Failed password' /var/log/secure | tail -n 50",                       'Logs',           'AlmaLinux', 'Últimos intentos de login SSH fallidos',                  'ssh, failed, brute force, log'],
        ['Ver log de errores cPanel',             'tail -n 100 /usr/local/cpanel/logs/error_log',                              'Logs',           'cPanel',    'Últimas 100 líneas del log de errores de cPanel',        'cpanel, error, log'],
        ['Ver procesos activos (top)',             'top -bn1 | head -n 30',                                                     'Procesos',       'General',   'Vista rápida de procesos con CPU/RAM',                    'top, procesos, cpu, ram'],
        ['Ver uso de memoria RAM',                'free -h',                                                                    'Procesos',       'General',   'Uso de memoria RAM del sistema',                          'ram, memoria, free'],
        ['Descargar archivo vía SCP',             'scp -v root@HOST:/ruta/archivo.tar.gz ~/Downloads/',                        'Transferencia',  'General',   'Descargar archivo de un servidor al local',               'scp, descargar, transferencia'],
        ['Sincronizar directorio con rsync',      'rsync -avz --progress -e "ssh -p 22" root@HOST:/home ~/Downloads/',         'Transferencia',  'General',   'Sincronizar directorio remoto localmente',                 'rsync, sync, transferencia'],
    ];

    $ins = $db->prepare(
        'INSERT IGNORE INTO command_library (title, command, category, os_target, description, tags)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($commands as [$t, $c, $cat, $os, $d, $tags]) {
        $ins->execute([$t, $c, $cat, $os, $d, $tags]);
    }

    $templates = [
        ['Actualización completa AlmaLinux', 'Actualiza todo el sistema en AlmaLinux', 'AlmaLinux', [
            ['command' => 'sudo dnf check-update', 'description' => 'Verificar actualizaciones',      'stop_on_error' => false],
            ['command' => 'sudo dnf update -y',    'description' => 'Instalar actualizaciones',        'stop_on_error' => false],
            ['command' => 'systemctl --failed',    'description' => 'Verificar servicios post-update', 'stop_on_error' => false],
        ]],
        ['Actualización completa CentOS', 'Actualiza todo el sistema en CentOS', 'CentOS', [
            ['command' => 'yum check-update',      'description' => 'Verificar actualizaciones',       'stop_on_error' => false],
            ['command' => 'yum update -y',         'description' => 'Instalar actualizaciones',        'stop_on_error' => false],
            ['command' => 'systemctl --failed',    'description' => 'Verificar servicios',             'stop_on_error' => false],
        ]],
        ['Actualización cPanel/WHM', 'Actualiza cPanel a la última versión', 'cPanel', [
            ['command' => '/scripts/upcp',         'description' => 'Actualizar cPanel/WHM',           'stop_on_error' => false],
            ['command' => 'systemctl --failed',    'description' => 'Verificar estado',                'stop_on_error' => false],
        ]],
        ['Revisión de disco y espacio', 'Auditoría del uso de disco del servidor', 'General', [
            ['command' => 'df -h',                                                             'description' => 'Particiones',             'stop_on_error' => false],
            ['command' => 'du -h / | sort -hr | head -n 20',                                  'description' => 'Top 20 carpetas pesadas',  'stop_on_error' => false],
            ['command' => "find /backup/ -type f -size +500M -exec du -h {} \\; | sort -hr",  'description' => 'Archivos >500MB en /backup','stop_on_error' => false],
        ]],
        ['Health check de servicios', 'Verificación rápida de todos los servicios', 'General', [
            ['command' => 'systemctl status',       'description' => 'Estado general',         'stop_on_error' => false],
            ['command' => 'systemctl --failed',     'description' => 'Servicios fallidos',     'stop_on_error' => false],
            ['command' => 'systemctl reset-failed', 'description' => 'Limpiar errores',        'stop_on_error' => false],
            ['command' => 'free -h',                'description' => 'Uso de RAM',             'stop_on_error' => false],
        ]],
    ];

    $insTmpl = $db->prepare(
        'INSERT IGNORE INTO templates (name, description, os_target, steps) VALUES (?, ?, ?, ?)'
    );
    foreach ($templates as [$name, $desc, $os, $steps]) {
        $insTmpl->execute([$name, $desc, $os, json_encode($steps)]);
    }
}

function tryGenerateQrSvg(string $uri): ?string
{
    if (!class_exists('chillerlan\\QRCode\\QRCode')) {
        return null;
    }
    try {
        $options = new \chillerlan\QRCode\QROptions;
        // Compatible with chillerlan/php-qrcode v4 and v5
        if (defined('chillerlan\\QRCode\\QRCode::OUTPUT_MARKUP_SVG')) {
            $options->outputType = \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG;
        }
        if (defined('chillerlan\\QRCode\\QRCode::ECC_M')) {
            $options->eccLevel = \chillerlan\QRCode\QRCode::ECC_M;
        }
        $options->imageBase64     = false;
        $options->svgAddXmlHeader = false;
        return (new \chillerlan\QRCode\QRCode($options))->render($uri);
    } catch (\Throwable) {
        return null;
    }
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    renderError('No se encontró <code>vendor/autoload.php</code>. Sube la carpeta <code>vendor/</code> o ejecuta <code>composer install</code> en el servidor.');
}

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\Auth;
use App\SessionManager;
use App\RateLimiter;
use OTPHP\TOTP;

$config = require __DIR__ . '/config/config.php';

// ── Force HTTPS ───────────────────────────────────────────────────────────────
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$isLocalhost && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit;
}

// ── Connect & init DB ─────────────────────────────────────────────────────────
try {
    $db = Database::connect($config['db']);
} catch (\Throwable $e) {
    renderError('No se puede conectar a la base de datos. Verifica <code>config/config.php</code>.<br><small>' . htmlspecialchars($e->getMessage()) . '</small>');
}

try {
    $sql = file_get_contents(__DIR__ . '/database/schema.sql');
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $db->exec($stmt);
    }
} catch (\Throwable $e) {
    renderError('Error al crear las tablas: <code>' . htmlspecialchars($e->getMessage()) . '</code>');
}

// ── Auto-block if already set up ──────────────────────────────────────────────
try {
    $userCount = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (\Throwable) {
    $userCount = 0;
}

if ($userCount > 0) {
    header('Location: index.php?action=login');
    exit;
}

// ── Handle POST ───────────────────────────────────────────────────────────────
$error  = '';
$qrData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username']      ?? '');
    $password  = $_POST['password']           ?? '';
    $masterPwd = $_POST['master_password']    ?? '';
    $confirm   = $_POST['confirm_master']     ?? '';

    if (empty($username) || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'El usuario debe tener entre 3 y 30 caracteres (letras, números, guión bajo).';
    } elseif (strlen($password) < 12) {
        $error = 'La contraseña de login debe tener mínimo 12 caracteres.';
    } elseif (strlen($masterPwd) < 12) {
        $error = 'El Master Password debe tener mínimo 12 caracteres.';
    } elseif ($masterPwd !== $confirm) {
        $error = 'Los Master Password no coinciden.';
    } elseif ($masterPwd === $password) {
        $error = 'El Master Password debe ser DIFERENTE a la contraseña de login.';
    } else {
        try {
            $totp = TOTP::generate();
            $totp->setLabel($username);
            $totp->setIssuer('SSH Manager');
            $totpSecret = $totp->getSecret();
            $totpUri    = $totp->getProvisioningUri();

            $appKeys = \App\Encryption::deriveAppKeys($config['db']['pass']);
            $session = new SessionManager();
            $limiter = new RateLimiter($db);
            $auth    = new Auth($db, $session, $limiter, $config['app'], $appKeys);
            $auth->createUser($username, $password, $masterPwd, $totpSecret);

            seedData($db);

            $qrData = [
                'username' => $username,
                'secret'   => $totpSecret,
                'uri'      => $totpUri,
                'qr_svg'   => tryGenerateQrSvg($totpUri),
            ];
        } catch (\Throwable $e) {
            $error = 'Error al crear el usuario: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title>SSH Manager — Configuración Inicial</title>
<link rel="icon" type="image/png" href="assets/images/logo_sshmanager.png">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg: #0f1117; --bg2: #1a1d27; --bg3: #222635;
  --border: #2d3147; --text: #e2e4ef; --muted: #8890aa;
  --primary: #4a8af4; --danger: #e05252; --ok: #3ec97c;
}
body { background: var(--bg); color: var(--text);
       font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
       display: flex; justify-content: center; align-items: flex-start;
       min-height: 100vh; padding: 2.5rem 1rem; }
.box { background: var(--bg2); border: 1px solid var(--border); border-radius: 14px;
       padding: 2.5rem 2rem; width: 100%; max-width: 480px; }
.box.wide { max-width: 540px; }
h1 { font-size: 1.4rem; font-weight: 700; color: var(--primary); margin-bottom: .25rem; }
.subtitle { color: var(--muted); font-size: .88rem; margin-bottom: 2rem; }
.once-badge { display: inline-block; background: var(--bg3); border: 1px solid var(--border);
              color: var(--muted); font-size: .73rem; padding: .18rem .55rem;
              border-radius: 20px; margin-bottom: 1.5rem; }
.form-group { display: flex; flex-direction: column; gap: .3rem; margin-bottom: .9rem; }
label { font-size: .82rem; color: var(--muted); font-weight: 500; }
small { font-size: .75rem; color: var(--muted); }
input {
  background: var(--bg3); border: 1px solid var(--border); border-radius: 7px;
  padding: .5rem .75rem; color: var(--text); font-size: .9rem;
  width: 100%; outline: none; transition: border-color .15s;
}
input:focus { border-color: var(--primary); }
.divider { border: none; border-top: 1px solid var(--border); margin: 1.2rem 0; }
.btn { display: flex; align-items: center; justify-content: center;
       padding: .55rem 1.2rem; border-radius: 7px; border: none;
       cursor: pointer; font-size: .92rem; font-weight: 600;
       transition: filter .15s; width: 100%; margin-top: .75rem;
       text-decoration: none; }
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { filter: brightness(1.12); }
.alert-error { background: #3d1a1a; color: var(--danger); border: 1px solid #5e2a2a;
               border-radius: 7px; padding: .7rem 1rem; margin-bottom: 1rem; font-size: .87rem; }
.ok-banner { background: #1a3e2a; color: var(--ok); border: 1px solid #2a6044;
             border-radius: 7px; padding: .6rem 1rem; font-size: .88rem;
             margin-bottom: 1.5rem; text-align: center; }
.qr-section { text-align: center; }
.qr-wrap { display: inline-block; background: #ffffff; padding: 14px;
           border-radius: 10px; margin: 1rem 0; }
.qr-wrap svg { display: block; width: 210px; height: 210px; }
.qr-fallback { background: var(--bg3); border: 1px solid var(--border);
               border-radius: 7px; padding: 1rem; margin: 1rem 0; font-size: .8rem;
               color: var(--muted); word-break: break-all; text-align: left; }
.qr-fallback strong { color: var(--text); display: block; margin-bottom: .4rem; }
.secret-label { font-size: .78rem; color: var(--muted); margin-bottom: .3rem; }
.secret-box { background: var(--bg3); border: 1px solid var(--border); border-radius: 7px;
              padding: .6rem 1rem; font-family: 'Consolas', monospace; font-size: .85rem;
              color: #a8d1ff; word-break: break-all; text-align: center;
              cursor: pointer; transition: border-color .15s; }
.secret-box:hover { border-color: var(--primary); }
.info-table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: .85rem; }
.info-table td { padding: .45rem .6rem; border-bottom: 1px solid var(--border); }
.info-table td:first-child { color: var(--muted); width: 38%; }
.info-table td:last-child  { color: var(--text); font-weight: 500; }
.warn-note { font-size: .77rem; color: var(--muted); margin-top: 1rem; line-height: 1.5; text-align: center; }
</style>
</head>
<body>

<?php if ($qrData): ?>
<!-- ── PASO 2: QR / Secreto TOTP ──────────────────────────────────────────── -->
<div class="box wide qr-section">
  <div class="ok-banner">Usuario creado correctamente — Configura tu autenticador ahora</div>
  <h1>Configura Google Authenticator</h1>
  <p class="subtitle">Abre Google Authenticator &rarr; + &rarr; Escanear código QR</p>

  <?php if ($qrData['qr_svg']): ?>
  <div class="qr-wrap"><?= $qrData['qr_svg'] ?></div>
  <?php else: ?>
  <div class="qr-fallback">
    <strong>No se pudo generar el QR — usa la clave manual:</strong>
    En Google Authenticator: + &rarr; Introducir clave de configuración &rarr; pega la clave de abajo.
  </div>
  <?php endif; ?>

  <p class="secret-label">Clave de configuración (para entrada manual o copia de seguridad):</p>
  <div class="secret-box" id="secretBox" onclick="copySecret()" title="Click para copiar">
    <?= htmlspecialchars($qrData['secret']) ?>
  </div>
  <small style="display:block;text-align:center;margin-bottom:1rem">Click en el código para copiar</small>

  <hr class="divider">

  <table class="info-table">
    <tr><td>Usuario</td>    <td><?= htmlspecialchars($qrData['username']) ?></td></tr>
    <tr><td>2do factor</td> <td>Google Authenticator (código 6 dígitos)</td></tr>
    <tr><td>Login</td>      <td>Contraseña + Código 2FA + Master Password</td></tr>
    <tr><td>Comandos</td>   <td>21 comandos pre-cargados</td></tr>
    <tr><td>Templates</td>  <td>5 templates de mantenimiento</td></tr>
  </table>

  <a href="index.php?action=login" class="btn btn-primary">Ir al Login &rarr;</a>

  <p class="warn-note">
    Guarda la clave de texto de arriba en un lugar seguro.<br>
    Si pierdes el teléfono y no tienes este código, no podrás acceder al sistema.
  </p>
</div>

<script>
function copySecret() {
  const el = document.getElementById('secretBox');
  navigator.clipboard.writeText(el.textContent.trim()).then(() => {
    el.style.borderColor = 'var(--ok)';
    el.title = 'Copiado';
    setTimeout(() => { el.style.borderColor = ''; el.title = 'Click para copiar'; }, 2000);
  });
}
</script>

<?php else: ?>
<!-- ── PASO 1: Formulario ──────────────────────────────────────────────────── -->
<div class="box">
  <img src="assets/images/logo_sshmanager.png" alt="SSH Manager" style="width:64px;display:block;margin:0 auto 1rem">
  <h1>SSH Manager</h1>
  <p class="subtitle">Configuración inicial — crea el usuario administrador</p>
  <span class="once-badge">Solo funciona una vez</span>

  <?php if ($error): ?>
  <div class="alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">

    <div class="form-group">
      <label>Usuario</label>
      <input type="text" name="username" required autofocus autocomplete="off"
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
             placeholder="admin" pattern="[a-zA-Z0-9_]{3,30}">
      <small>3–30 caracteres · solo letras, números y _</small>
    </div>

    <div class="form-group">
      <label>Contraseña de Login</label>
      <input type="password" name="password" required autocomplete="new-password"
             minlength="12" placeholder="Mínimo 12 caracteres">
      <small>Para ingresar al sistema (paso 1 del login)</small>
    </div>

    <hr class="divider">

    <div class="form-group">
      <label>Master Password</label>
      <input type="password" name="master_password" required autocomplete="new-password"
             minlength="12" placeholder="Mínimo 12 caracteres">
      <small>Desencripta tus credenciales SSH — debe ser DIFERENTE a la contraseña de login.</small>
    </div>

    <div class="form-group">
      <label>Confirmar Master Password</label>
      <input type="password" name="confirm_master" required autocomplete="new-password"
             minlength="12" placeholder="Repite el master password">
    </div>

    <button type="submit" class="btn btn-primary">Crear usuario &rarr;</button>
  </form>
</div>
<?php endif; ?>

</body>
</html>
