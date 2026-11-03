<?php
declare(strict_types=1);

/**
 * bootstrap.php — loaded first by every page and API endpoint.
 */

define('APP_ROOT', dirname(__DIR__));

/**
 * Load environment variables from .env.
 */
function load_env(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));

        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

load_env(APP_ROOT . '/.env');

define('APP_ENV', env('APP_ENV', 'production'));
define('APP_NAME', env('APP_NAME', 'StockWise Inventory'));

date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Manila'));

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Load database connection before starting the session.
require_once APP_ROOT . '/config/database.php';

// Use Supabase PostgreSQL for PHP sessions.
require_once APP_ROOT . '/config/database_session_handler.php';

// Session security and configuration
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_name('INVSESSID');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $sessionHandler = new DatabaseSessionHandler();

    if (!session_set_save_handler($sessionHandler, true)) {
        throw new RuntimeException('Could not register the database session handler.');
    }

    session_start();
}

// Load the remaining application files.
require_once APP_ROOT . '/includes/functions.php';
require_once APP_ROOT . '/includes/validator.php';
require_once APP_ROOT . '/includes/csrf.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/inventory.php';
