<?php
declare(strict_types=1);

// Loaded by index.php / setup.php after the autoloader is ready.
if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit('Forbidden');
}

\App\Env::load(APP_ROOT . '/.env');

return [
    'db' => [
        'host'    => \App\Env::required('DB_HOST'),
        'name'    => \App\Env::required('DB_NAME'),
        'user'    => \App\Env::required('DB_USER'),
        'pass'    => \App\Env::required('DB_PASS'),
        'charset' => \App\Env::get('DB_CHARSET', 'utf8mb4'),
    ],
    'app' => [
        'name'               => \App\Env::get('APP_NAME', 'SSH Manager'),
        'env'                => \App\Env::get('APP_ENV', 'production'),
        'debug'              => \App\Env::bool('APP_DEBUG', false),
        'session_timeout'    => \App\Env::int('SESSION_TIMEOUT', 1800),
        'max_login_attempts' => \App\Env::int('MAX_LOGIN_ATTEMPTS', 5),
        'lockout_minutes'    => \App\Env::int('LOCKOUT_MINUTES', 15),
        'log_retention_days' => \App\Env::int('LOG_RETENTION_DAYS', 90),
        'ssh_timeout'        => \App\Env::int('SSH_TIMEOUT', 15),
        'ssh_command_timeout'=> \App\Env::int('SSH_COMMAND_TIMEOUT', 600),
        'ssh_output_limit'   => \App\Env::int('SSH_OUTPUT_LIMIT', 51200),
    ],
    'ip_allowlist' => \App\Env::arrayCsv('IP_ALLOWLIST'),
];
