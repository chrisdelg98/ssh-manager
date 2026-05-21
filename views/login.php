<?php
use App\CsrfGuard;
$step = $_GET['step'] ?? 'password';

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

if ($error === '') {
    $err = $_GET['err'] ?? '';
    if ($err === 'csrf')        { $error = 'Sesión expirada. Por favor recarga la página e intenta de nuevo.'; }
    elseif ($err === 'invalid') { $error = 'Usuario o contraseña incorrectos.'; }
    elseif ($err === 'locked')  { $mins = (int)($_GET['mins'] ?? 15); $error = "Cuenta bloqueada. Espera {$mins} minuto(s) e intenta de nuevo."; }
    elseif ($err === 'expired') { $error = 'Sesión expirada. Inicia sesión de nuevo.'; }
}

// Persisted theme (cookie-less: read from a non-essential cookie set on login screen)
$theme = $_COOKIE['sshmgr_theme_pref'] ?? 'matrix';
if (!in_array($theme, ['matrix','void','daylight','dusk'], true)) {
    $theme = 'matrix';
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= htmlspecialchars($theme, ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<meta name="referrer" content="no-referrer">
<title>SSH Manager — Acceso</title>
<link rel="icon" type="image/png" href="assets/images/logo_sshmanager.png">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">
<main class="login-box">
  <img src="assets/images/logo_sshmanager.png" alt="" class="login-logo">
  <h1 class="login-title">SSH MANAGER</h1>
  <p class="login-tagline">Controla tus servidores. Desde cualquier lugar.</p>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($step === 'password'): ?>
    <p class="login-step-label">Paso 1 de 3 — Credenciales</p>
    <form method="POST" action="?action=login_step2" autocomplete="off" novalidate>
      <?= CsrfGuard::field() ?>
      <div class="form-group">
        <label>Usuario</label>
        <input type="text" name="username" required autofocus autocomplete="off" spellcheck="false">
      </div>
      <div class="form-group">
        <label>Contraseña</label>
        <input type="password" name="password" required autocomplete="off">
      </div>
      <button type="submit" class="btn btn-primary btn-full">Continuar →</button>
    </form>

  <?php else: ?>
    <p class="login-step-label">Pasos 2 y 3 — Autenticador + Master Password</p>
    <form method="POST" action="?action=login_step3" autocomplete="off" novalidate>
      <?= CsrfGuard::field() ?>
      <div class="form-group">
        <label>Código 2FA</label>
        <input type="text" name="totp_code" inputmode="numeric" pattern="\d{6}"
               maxlength="6" required autofocus autocomplete="one-time-code" placeholder="000000">
        <small class="hint">Código de 6 dígitos de Google / Authy. Cambia cada 30 s.</small>
      </div>
      <div class="form-group">
        <label>Master Password</label>
        <input type="password" name="master_password" required autocomplete="off"
               placeholder="Mínimo 12 caracteres">
        <small class="hint">Descifra tus credenciales SSH. Nunca se almacena.</small>
      </div>
      <button type="submit" class="btn btn-primary btn-full">Ingresar →</button>
    </form>
    <p class="mt-3" style="text-align:center">
      <a href="?action=login" class="text-muted">← Volver al inicio</a>
    </p>
  <?php endif; ?>
</main>

<script>
// Allow theme cycling on the login screen too (purely cosmetic preview).
(function () {
  const themes = ['matrix','void','daylight','dusk'];
  const html = document.documentElement;
  document.addEventListener('keydown', e => {
    if (e.altKey && e.key.toLowerCase() === 't') {
      const cur = html.getAttribute('data-theme') || 'matrix';
      const next = themes[(themes.indexOf(cur) + 1) % themes.length];
      html.setAttribute('data-theme', next);
      document.cookie = 'sshmgr_theme_pref=' + next + '; path=/; SameSite=Lax; Secure';
    }
  });
})();
</script>
</body>
</html>
