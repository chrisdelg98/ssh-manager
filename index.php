<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);

require_once __DIR__ . '/vendor/autoload.php';

use App\Database;
use App\SessionManager;
use App\Auth;
use App\RateLimiter;
use App\CsrfGuard;
use App\ServerManager;
use App\SSHManager;
use App\CommandLibrary;
use App\TemplateManager;
use App\Logger;
use App\SshKeyManager;

// ── Bootstrap ────────────────────────────────────────────────────────────────
$config = require __DIR__ . '/config/config.php';

// IP allowlist enforcement
if (!empty($config['ip_allowlist'])) {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($clientIp, $config['ip_allowlist'], true)) {
        http_response_code(404);
        exit('Not Found');
    }
}

$db      = Database::connect($config['db']);
$appKeys = \App\Encryption::deriveAppKeys($config['db']['pass']);
$session = new SessionManager($config['app']['session_timeout']);
$session->start();

// ── Routing ──────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? ($_POST['action'] ?? 'dashboard');
$action = preg_replace('/[^a-z0-9_]/', '', strtolower($action));

// Public actions (no auth required)
$publicActions = ['login', 'login_step2', 'login_step3', 'logout'];

if (!in_array($action, $publicActions, true)) {
    if (!$session->isAuthenticated()) {
        header('Location: ?action=login');
        exit;
    }
    // Guard against a session that has lost (or never had) a usable enc_key.
    // Without it nothing can be decrypted or audited correctly — force a clean re-login.
    if ($session->encKey() === null) {
        $session->destroy();
        $_SESSION['login_error'] = 'Tu sesión expiró o quedó incompleta. Inicia sesión de nuevo.';
        header('Location: ?action=login&err=expired');
        exit;
    }
}

// ── Service factory (only after auth) ────────────────────────────────────────
function getServices(PDO $db, SessionManager $session, array $config, array $appKeys): array
{
    $encKey  = $session->encKey();
    $userId  = $session->userId();
    $logger  = new Logger($db, $encKey, $appKeys);
    return [
        'encKey'   => $encKey,
        'userId'   => $userId,
        'servers'  => new ServerManager($db, $encKey),
        'ssh'      => new SSHManager($config['app']['ssh_timeout'], $config['app']['ssh_output_limit']),
        'commands' => new CommandLibrary($db),
        'templates'=> new TemplateManager($db),
        'logger'   => $logger,
    ];
}

// ── AJAX responses ───────────────────────────────────────────────────────────
function jsonResponse(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function requireCsrf(): void
{
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!CsrfGuard::validate($token)) {
        jsonResponse(['error' => 'Invalid CSRF token'], 403);
    }
}

// ── Dispatch ─────────────────────────────────────────────────────────────────
switch ($action) {

    // ── Auth ─────────────────────────────────────────────────────────────
    case 'login':
        require __DIR__ . '/views/login.php';
        break;

    case 'login_step2':
        $csrfToken = $_POST['_csrf'] ?? '';
        if (!CsrfGuard::validate($csrfToken)) {
            $_SESSION['login_error'] = 'Sesión expirada. Por favor intenta de nuevo.';
            header('Location: ?action=login&err=csrf');
            exit;
        }

        $limiter = new RateLimiter($db, $config['app']['max_login_attempts'], $config['app']['lockout_minutes']);
        $auth    = new Auth($db, $session, $limiter, $config['app'], $appKeys);

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $user = $auth->verifyPassword($username, $password);

        if (!$user) {
            $usernameHash = \App\Encryption::hashUsername($username, $appKeys['lookup']);
            $remaining = $limiter->lockoutRemainingSeconds($usernameHash);
            if ($remaining > 0) {
                $mins = (int) ceil($remaining / 60);
                $_SESSION['login_error'] = "Cuenta bloqueada por intentos fallidos. Espera {$mins} minuto(s) e intenta de nuevo.";
                header('Location: ?action=login&err=locked&mins=' . $mins);
            } else {
                $_SESSION['login_error'] = 'Usuario o contraseña incorrectos.';
                header('Location: ?action=login&err=invalid');
            }
            exit;
        }

        // Store partial auth state
        $_SESSION['auth_pending_user']  = $user;
        $_SESSION['auth_step']          = 'totp';
        header('Location: ?action=login&step=totp');
        exit;

    case 'login_step3':
        $csrfToken = $_POST['_csrf'] ?? '';
        if (!CsrfGuard::validate($csrfToken)) {
            $_SESSION['login_error'] = 'Sesión expirada. Por favor intenta de nuevo.';
            header('Location: ?action=login');
            exit;
        }
        if (($_SESSION['auth_step'] ?? '') !== 'totp') {
            header('Location: ?action=login');
            exit;
        }

        $user    = $_SESSION['auth_pending_user'];
        $code    = trim($_POST['totp_code'] ?? '');
        $limiter = new RateLimiter($db, $config['app']['max_login_attempts'], $config['app']['lockout_minutes']);
        $auth    = new Auth($db, $session, $limiter, $config['app'], $appKeys);

        $masterPassword = $_POST['master_password'] ?? '';
        $encKey = $auth->verifyMasterPassword($masterPassword, $user['master_salt']);
        if (!$encKey) {
            $_SESSION['login_error'] = 'El Master Password debe tener mínimo 12 caracteres.';
            header('Location: ?action=login&step=totp');
            exit;
        }

        // Decrypt TOTP secret separately so we can distinguish wrong master password
        // from wrong TOTP code (verifyTotp swallows both as the same error)
        try {
            $totpSecret = \App\Encryption::decrypt($user['totp_secret_enc'], $encKey);
        } catch (\Throwable) {
            $_SESSION['login_error'] = 'Master Password incorrecto.';
            header('Location: ?action=login&step=totp');
            exit;
        }

        // Verify TOTP code with ±60s tolerance to handle minor clock drift
        $totp = \OTPHP\TOTP::createFromSecret($totpSecret);
        if (!$totp->verify($code, null, 2)) {
            $_SESSION['login_error'] = 'Código 2FA incorrecto. Asegúrate de que la hora del teléfono esté sincronizada.';
            header('Location: ?action=login&step=totp');
            exit;
        }

        // All 3 factors verified
        $auth->completeLogin($user, $encKey);

        // Log the successful login
        $logger = new Logger($db, $encKey, $appKeys);
        $logger->log((int)$user['id'], Logger::LOGIN, 'success', null, 'Login from ' . ($_SERVER['REMOTE_ADDR'] ?? ''));

        unset($_SESSION['auth_pending_user'], $_SESSION['auth_step'], $_SESSION['login_error']);
        header('Location: ?action=dashboard');
        exit;

    case 'logout':
        if ($session->isAuthenticated()) {
            $svc = getServices($db, $session, $config, $appKeys);
            $svc['logger']->log($svc['userId'], Logger::LOGOUT, 'success');
        }
        $session->destroy();
        header('Location: ?action=login');
        exit;

    // ── Dashboard ────────────────────────────────────────────────────────
    case 'dashboard':
        $svc     = getServices($db, $session, $config, $appKeys);
        $servers = $svc['servers']->listAll();
        require __DIR__ . '/views/dashboard.php';
        break;

    // ── Servers ──────────────────────────────────────────────────────────
    case 'server_add':
        $svc = getServices($db, $session, $config, $appKeys);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireCsrf();
            try {
                $id = $svc['servers']->create(
                    trim($_POST['name']),
                    $_POST['type'],
                    trim($_POST['host']),
                    (int)($_POST['port'] ?? 22),
                    trim($_POST['ssh_user']),
                    $_POST['auth_type'],
                    $_POST['credential'],
                    trim($_POST['notes'] ?? ''),
                    $_POST['color_tag'] ?? '#4A90D9'
                );
                $svc['logger']->log($svc['userId'], Logger::SERVER_ADD, 'success', $id, "Added server: {$_POST['name']}");
                header('Location: ?action=dashboard');
            } catch (\Throwable $e) {
                $error = 'Error saving server.';
                require __DIR__ . '/views/server_form.php';
            }
            exit;
        }
        $server = null;
        require __DIR__ . '/views/server_form.php';
        break;

    case 'server_edit':
        $svc      = getServices($db, $session, $config, $appKeys);
        $serverId = (int)($_GET['id'] ?? 0);
        try {
            $server = $svc['servers']->get($serverId);
        } catch (\Throwable) {
            // Decryption failed — server was encrypted with a different master password
            $_SESSION['dashboard_error'] = 'No se puede descifrar el servidor. Fue cifrado con un Master Password diferente. Elimínalo y agrégalo de nuevo.';
            header('Location: ?action=dashboard');
            exit;
        }
        if (!$server) {
            header('Location: ?action=dashboard');
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireCsrf();
            try {
                $svc['servers']->update(
                    $serverId,
                    trim($_POST['name']),
                    $_POST['type'],
                    trim($_POST['host']),
                    (int)($_POST['port'] ?? 22),
                    trim($_POST['ssh_user']),
                    $_POST['auth_type'],
                    !empty($_POST['credential']) ? $_POST['credential'] : null,
                    trim($_POST['notes'] ?? ''),
                    $_POST['color_tag'] ?? '#4A90D9'
                );
                $svc['logger']->log($svc['userId'], Logger::SERVER_EDIT, 'success', $serverId, "Edited server: {$_POST['name']}");
                header('Location: ?action=dashboard');
            } catch (\Throwable $e) {
                $error = 'Error updating server.';
                require __DIR__ . '/views/server_form.php';
            }
            exit;
        }
        require __DIR__ . '/views/server_form.php';
        break;

    case 'server_delete':
        requireCsrf();
        $svc      = getServices($db, $session, $config, $appKeys);
        $serverId = (int)($_POST['id'] ?? 0);
        $svc['servers']->delete($serverId);
        $svc['logger']->log($svc['userId'], Logger::SERVER_DELETE, 'success', $serverId, "Deleted server ID $serverId");
        header('Location: ?action=dashboard');
        exit;

    // ── Terminal / SSH ────────────────────────────────────────────────────
    case 'terminal':
        $svc      = getServices($db, $session, $config, $appKeys);
        $serverId = (int)($_GET['id'] ?? 0);
        try {
            $server = $svc['servers']->get($serverId);
        } catch (\Throwable) {
            $_SESSION['dashboard_error'] = 'No se puede descifrar el servidor. Fue cifrado con un Master Password diferente.';
            header('Location: ?action=dashboard');
            exit;
        }
        if (!$server) {
            header('Location: ?action=dashboard');
            exit;
        }
        $templates = $svc['templates']->getAll();
        $commands  = $svc['commands']->getAll();
        require __DIR__ . '/views/terminal.php';
        break;

    case 'ssh_exec':
        requireCsrf();
        $svc      = getServices($db, $session, $config, $appKeys);
        $serverId = (int)($_POST['server_id'] ?? 0);
        $command  = trim($_POST['command'] ?? '');

        if (!$serverId || $command === '') {
            jsonResponse(['error' => 'Missing parameters'], 400);
        }

        $server = $svc['servers']->get($serverId);
        if (!$server) {
            jsonResponse(['error' => 'Server not found'], 404);
        }

        $result = $svc['ssh']->execute($server, $command);

        $status = $result['error'] ? 'error' : ($result['exit_code'] === 0 ? 'success' : 'failure');
        $detail = json_encode([
            'command'   => $command,
            'output'    => $result['output'],
            'exit_code' => $result['exit_code'],
            'error'     => $result['error'],
        ]);
        $svc['logger']->log($svc['userId'], Logger::SSH_EXEC, $status, $serverId, $detail);

        jsonResponse($result);

    case 'template_run':
        requireCsrf();
        $svc        = getServices($db, $session, $config, $appKeys);
        $serverId   = (int)($_POST['server_id'] ?? 0);
        $templateId = (int)($_POST['template_id'] ?? 0);

        $server   = $svc['servers']->get($serverId);
        $template = $svc['templates']->get($templateId);

        if (!$server || !$template) {
            jsonResponse(['error' => 'Server or template not found'], 404);
        }

        $results = $svc['ssh']->executeTemplate($server, $template['steps']);
        $hasError = array_filter($results, fn($r) => $r['error'] !== null);

        $svc['logger']->log(
            $svc['userId'],
            Logger::TEMPLATE_RUN,
            $hasError ? 'failure' : 'success',
            $serverId,
            json_encode(['template' => $template['name'], 'results' => $results])
        );

        jsonResponse(['results' => $results]);

    // ── Command Library ──────────────────────────────────────────────────
    case 'commands':
        $svc    = getServices($db, $session, $config, $appKeys);
        $search = trim($_GET['q'] ?? '');
        $cat    = $_GET['cat'] ?? '';
        $os     = $_GET['os'] ?? '';
        $cmds   = $svc['commands']->search($search, $cat, $os);
        $cats   = $svc['commands']->getCategories();
        require __DIR__ . '/views/commands.php';
        break;

    case 'command_add':
        $svc = getServices($db, $session, $config, $appKeys);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireCsrf();
            $svc['commands']->create(
                trim($_POST['title']),
                trim($_POST['command']),
                trim($_POST['category']),
                trim($_POST['os_target'] ?? '') ?: null,
                trim($_POST['description'] ?? '') ?: null,
                trim($_POST['tags'] ?? '') ?: null
            );
            header('Location: ?action=commands');
            exit;
        }
        $editCmd = null;
        require __DIR__ . '/views/commands.php';
        break;

    case 'command_edit':
        $svc   = getServices($db, $session, $config, $appKeys);
        $cmdId = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireCsrf();
            $svc['commands']->update(
                $cmdId,
                trim($_POST['title']),
                trim($_POST['command']),
                trim($_POST['category']),
                trim($_POST['os_target'] ?? '') ?: null,
                trim($_POST['description'] ?? '') ?: null,
                trim($_POST['tags'] ?? '') ?: null
            );
            header('Location: ?action=commands');
            exit;
        }
        $editCmd = $svc['commands']->get($cmdId);
        $cmds    = $svc['commands']->getAll();
        $cats    = $svc['commands']->getCategories();
        require __DIR__ . '/views/commands.php';
        break;

    case 'command_delete':
        requireCsrf();
        $svc = getServices($db, $session, $config, $appKeys);
        $svc['commands']->delete((int)($_POST['id'] ?? 0));
        header('Location: ?action=commands');
        exit;

    case 'command_use':
        $svc = getServices($db, $session, $config, $appKeys);
        $svc['commands']->incrementUsage((int)($_GET['id'] ?? 0));
        $svc['logger']->log($svc['userId'], Logger::COMMAND_COPY, 'success', null, "Command ID: " . $_GET['id']);
        jsonResponse(['ok' => true]);

    // ── Templates ────────────────────────────────────────────────────────
    case 'templates':
        $svc  = getServices($db, $session, $config, $appKeys);
        $tmpl = $svc['templates']->getAll();
        require __DIR__ . '/views/templates.php';
        break;

    case 'template_add':
        $svc = getServices($db, $session, $config, $appKeys);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireCsrf();
            $steps = [];
            $cmds  = $_POST['step_command'] ?? [];
            $descs = $_POST['step_desc']    ?? [];
            $stops = $_POST['step_stop']    ?? [];
            foreach ($cmds as $i => $cmd) {
                if (trim($cmd) === '') continue;
                $steps[] = [
                    'command'       => trim($cmd),
                    'description'   => trim($descs[$i] ?? ''),
                    'stop_on_error' => isset($stops[$i]),
                ];
            }
            $svc['templates']->create(
                trim($_POST['name']),
                trim($_POST['description'] ?? '') ?: null,
                trim($_POST['os_target'] ?? '') ?: null,
                $steps
            );
            header('Location: ?action=templates');
            exit;
        }
        $editTmpl = null;
        require __DIR__ . '/views/template_form.php';
        break;

    case 'template_edit':
        $svc    = getServices($db, $session, $config, $appKeys);
        $tmplId = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireCsrf();
            $steps = [];
            $cmds  = $_POST['step_command'] ?? [];
            $descs = $_POST['step_desc']    ?? [];
            $stops = $_POST['step_stop']    ?? [];
            foreach ($cmds as $i => $cmd) {
                if (trim($cmd) === '') continue;
                $steps[] = [
                    'command'       => trim($cmd),
                    'description'   => trim($descs[$i] ?? ''),
                    'stop_on_error' => isset($stops[$i]),
                ];
            }
            $svc['templates']->update(
                $tmplId,
                trim($_POST['name']),
                trim($_POST['description'] ?? '') ?: null,
                trim($_POST['os_target'] ?? '') ?: null,
                $steps
            );
            header('Location: ?action=templates');
            exit;
        }
        $editTmpl = $svc['templates']->get($tmplId);
        require __DIR__ . '/views/template_form.php';
        break;

    case 'template_delete':
        requireCsrf();
        $svc = getServices($db, $session, $config, $appKeys);
        $svc['templates']->delete((int)($_POST['id'] ?? 0));
        header('Location: ?action=templates');
        exit;

    // ── Logs ─────────────────────────────────────────────────────────────
    case 'logs':
        $svc     = getServices($db, $session, $config, $appKeys);
        $filters = [
            'server_id'   => $_GET['server_id'] ?? '',
            'action_type' => $_GET['action_type'] ?? '',
            'status'      => $_GET['status'] ?? '',
            'date_from'   => $_GET['date_from'] ?? '',
            'date_to'     => $_GET['date_to'] ?? '',
        ];
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 50;
        $logs    = $svc['logger']->getPage($page, $perPage, $filters);
        $total   = $svc['logger']->count($filters);
        $pages   = (int)ceil($total / $perPage);
        $servers = $svc['servers']->listAll();
        require __DIR__ . '/views/logs.php';
        break;

    // ── Settings ─────────────────────────────────────────────────────────
    case 'settings':
        $svc = getServices($db, $session, $config, $appKeys);
        require __DIR__ . '/views/settings.php';
        break;

    case 'settings_save':
        requireCsrf();
        $svc = getServices($db, $session, $config, $appKeys);
        $type = $_POST['settings_type'] ?? '';

        if ($type === 'master_password') {
            $auth    = new Auth($db, $session, new RateLimiter($db), $config['app'], $appKeys);
            $oldPwd  = $_POST['old_master'] ?? '';
            $newPwd  = $_POST['new_master'] ?? '';
            $confirm = $_POST['confirm_master'] ?? '';

            if ($newPwd !== $confirm) {
                $_SESSION['settings_error'] = 'New master passwords do not match.';
                header('Location: ?action=settings');
                exit;
            }
            if (strlen($newPwd) < 12) {
                $_SESSION['settings_error'] = 'Master password must be at least 12 characters.';
                header('Location: ?action=settings');
                exit;
            }

            $salt = $auth->getUserSalt($svc['userId']);
            if ($auth->changeMasterPassword($svc['userId'], $oldPwd, $newPwd, $salt)) {
                $svc['logger']->log($svc['userId'], Logger::SETTINGS_CHANGE, 'success', null, 'Master password changed');
                $_SESSION['settings_ok'] = 'Master password updated successfully.';
            } else {
                $_SESSION['settings_error'] = 'Old master password is incorrect.';
            }
        }

        header('Location: ?action=settings');
        exit;

    // ── Snippets (modern UI on top of command_library backend) ───────────
    case 'snippets':
        $svc      = getServices($db, $session, $config, $appKeys);
        $search   = trim($_GET['q'] ?? '');
        $cat      = $_GET['cat'] ?? '';
        $snippets = $svc['commands']->search($search, $cat, '');
        $cats     = $svc['commands']->getCategories();
        require __DIR__ . '/views/snippets.php';
        break;

    case 'snippet_add':
        $svc   = getServices($db, $session, $config, $appKeys);
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireCsrf();
            try {
                $svc['commands']->create(
                    trim($_POST['title']),
                    trim($_POST['command']),
                    trim($_POST['category']) ?: 'General',
                    trim($_POST['os_target'] ?? '') ?: null,
                    trim($_POST['description'] ?? '') ?: null,
                    trim($_POST['tags'] ?? '') ?: null
                );
                header('Location: ?action=snippets');
                exit;
            } catch (\Throwable $e) {
                $error = 'No se pudo guardar el snippet.';
            }
        }
        $editSnippet = null;
        require __DIR__ . '/views/snippet_form.php';
        break;

    case 'snippet_edit':
        $svc   = getServices($db, $session, $config, $appKeys);
        $sid   = (int)($_GET['id'] ?? 0);
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireCsrf();
            try {
                $svc['commands']->update(
                    $sid,
                    trim($_POST['title']),
                    trim($_POST['command']),
                    trim($_POST['category']) ?: 'General',
                    trim($_POST['os_target'] ?? '') ?: null,
                    trim($_POST['description'] ?? '') ?: null,
                    trim($_POST['tags'] ?? '') ?: null
                );
                header('Location: ?action=snippets');
                exit;
            } catch (\Throwable $e) {
                $error = 'No se pudo actualizar el snippet.';
            }
        }
        $editSnippet = $svc['commands']->get($sid);
        if (!$editSnippet) { header('Location: ?action=snippets'); exit; }
        require __DIR__ . '/views/snippet_form.php';
        break;

    case 'snippet_delete':
        requireCsrf();
        $svc = getServices($db, $session, $config, $appKeys);
        $svc['commands']->delete((int)($_POST['id'] ?? 0));
        header('Location: ?action=snippets');
        exit;

    // ── SSH Keys ─────────────────────────────────────────────────────────
    case 'keys':
        $svc  = getServices($db, $session, $config, $appKeys);
        $keys = (new SshKeyManager($db, $svc['encKey']))->listAll();
        require __DIR__ . '/views/keys.php';
        break;

    case 'key_add':
        $svc  = getServices($db, $session, $config, $appKeys);
        $mgr  = new SshKeyManager($db, $svc['encKey']);
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireCsrf();
            try {
                $name       = trim($_POST['name'] ?? '');
                $privKey    = trim($_POST['private_key'] ?? '');
                $passphrase = $_POST['passphrase'] ?? '';
                $notes      = trim($_POST['notes'] ?? '');

                if ($name === '' || $privKey === '') {
                    throw new \RuntimeException('Nombre y clave privada son obligatorios.');
                }
                $meta = $mgr->create($name, $privKey, $passphrase !== '' ? $passphrase : null, $notes !== '' ? $notes : null);
                $svc['logger']->log($svc['userId'], Logger::KEY_ADD, 'success', null, "Added key: {$name} ({$meta['fingerprint']})");
                $_SESSION['dashboard_ok'] = 'Clave SSH agregada correctamente.';
                header('Location: ?action=keys');
                exit;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        require __DIR__ . '/views/key_form.php';
        break;

    case 'key_delete':
        requireCsrf();
        $svc   = getServices($db, $session, $config, $appKeys);
        $mgr   = new SshKeyManager($db, $svc['encKey']);
        $keyId = (int)($_POST['id'] ?? 0);
        $linked = $mgr->countLinkedServers($keyId);
        if ($linked > 0) {
            $_SESSION['dashboard_error'] = "No se puede eliminar: {$linked} servidor(es) usan esta clave.";
        } else {
            $mgr->delete($keyId);
            $svc['logger']->log($svc['userId'], Logger::KEY_DELETE, 'success', null, "Deleted key ID {$keyId}");
            $_SESSION['dashboard_ok'] = 'Clave eliminada.';
        }
        header('Location: ?action=keys');
        exit;

    case 'settings_theme':
        // Lightweight JSON endpoint to persist the chosen theme.
        $token = $_POST['_csrf'] ?? '';
        if (!CsrfGuard::validate($token)) {
            jsonResponse(['error' => 'CSRF'], 403);
        }
        $theme = $_POST['theme'] ?? 'matrix';
        $auth  = new Auth($db, $session, new RateLimiter($db), $config['app'], $appKeys);
        $auth->updateTheme((int)$session->userId(), $theme);
        jsonResponse(['ok' => true, 'theme' => $_SESSION['theme'] ?? 'matrix']);

    default:
        header('Location: ?action=dashboard');
        exit;
}
