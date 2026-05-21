<?php
/**
 * Authenticated layout shell.
 * Provided by index.php scope: $config, $session, $action (current route key).
 * Optional: $pageTitle (string).
 */
use App\CsrfGuard;

/** @var array          $config */
/** @var \App\SessionManager $session */
/** @var string         $action */

$username   = $session->username() ?? '';
$pageTitle  = $pageTitle ?? 'SSH Manager';
$activeKey  = $action ?? 'dashboard';

// Theme from session (set at login from users.theme); fallback to matrix
$activeTheme = $_SESSION['theme'] ?? 'matrix';
$validThemes = ['matrix', 'void', 'daylight', 'dusk'];
if (!in_array($activeTheme, $validThemes, true)) { $activeTheme = 'matrix'; }

// Nav items: key => [label, group of $action values that should highlight this item, svg]
$nav = [
    'dashboard' => [
        'label'   => 'Dashboard',
        'href'    => '?action=dashboard',
        'matches' => ['dashboard', 'server_add', 'server_edit', 'server_delete', 'terminal'],
        'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>',
    ],
    'keys' => [
        'label'   => 'Claves SSH',
        'href'    => '?action=keys',
        'matches' => ['keys', 'key_add', 'key_edit', 'key_delete'],
        'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><path d="M10.85 12.15 19 4"/><path d="m18 5 3 3"/><path d="m15 8 3 3"/></svg>',
    ],
    'snippets' => [
        'label'   => 'Snippets',
        'href'    => '?action=snippets',
        'matches' => ['snippets', 'commands', 'snippet_add', 'snippet_edit', 'snippet_delete', 'command_add', 'command_edit', 'command_delete'],
        'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
    ],
    'templates' => [
        'label'   => 'Templates',
        'href'    => '?action=templates',
        'matches' => ['templates', 'template_add', 'template_edit', 'template_delete'],
        'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>',
    ],
    'logs' => [
        'label'   => 'Logs',
        'href'    => '?action=logs',
        'matches' => ['logs'],
        'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
    ],
    'settings' => [
        'label'   => 'Configuración',
        'href'    => '?action=settings',
        'matches' => ['settings', 'settings_save', 'settings_theme'],
        'icon'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
    ],
];

$isActive = function (array $item) use ($activeKey): bool {
    return in_array($activeKey, $item['matches'], true);
};
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= htmlspecialchars($activeTheme, ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
<meta name="referrer" content="no-referrer">
<meta name="csrf-token" content="<?= htmlspecialchars(CsrfGuard::token(), ENT_QUOTES) ?>">
<title><?= htmlspecialchars($pageTitle) ?> — SSH Manager</title>
<link rel="icon" type="image/png" href="assets/images/logo_sshmanager.png">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">

  <header class="topbar">
    <button type="button" class="sidebar-toggle" aria-label="Menú" onclick="document.body.classList.toggle('sidebar-open')">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <a href="?action=dashboard" class="topbar-brand">
      <img src="assets/images/logo_sshmanager.png" alt="">
      <span>SSH Manager</span><span class="brand-cursor">_</span>
    </a>

    <div class="topbar-spacer"></div>

    <div class="topbar-actions">
      <button type="button" class="icon-btn" id="themeToggleBtn" aria-label="Cambiar tema" title="Cambiar tema">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
      </button>
      <span class="topbar-user">
        <span><?= htmlspecialchars($username) ?></span>
      </span>
      <a href="?action=logout" class="icon-btn" title="Salir" aria-label="Cerrar sesión">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </a>
    </div>
  </header>

  <aside class="sidebar">
    <div class="sidebar-section">
      <div class="sidebar-label">General</div>
      <?php foreach ($nav as $key => $item): ?>
        <a href="<?= htmlspecialchars($item['href']) ?>"
           class="nav-item <?= $isActive($item) ? 'is-active' : '' ?>">
          <?= $item['icon'] ?>
          <span><?= htmlspecialchars($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </aside>

  <main class="main">
