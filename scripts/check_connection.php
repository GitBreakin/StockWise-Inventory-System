<?php
/**
 * check_connection.php — verify that PHP can reach Supabase PostgreSQL.
 *
 *   php scripts/check_connection.php
 *
 * Prints the PHP version, whether pdo_pgsql is loaded, the server version,
 * whether SSL is in use, and which app tables exist. Never prints the password.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/bootstrap.php';

echo 'PHP version        : ' . PHP_VERSION . PHP_EOL;
echo 'pdo_pgsql loaded   : ' . (extension_loaded('pdo_pgsql') ? 'yes' : 'NO — enable extension=pdo_pgsql in php.ini') . PHP_EOL;
if (!extension_loaded('pdo_pgsql')) {
    exit(1);
}
echo 'DB host            : ' . (env('DB_HOST') ?? '(not set)') . PHP_EOL;
echo 'DB user            : ' . (env('DB_USER') ?? '(not set)') . PHP_EOL;

try {
    $pdo = db();
    echo 'Connection         : OK' . PHP_EOL;
    echo 'Server version     : ' . $pdo->query('SHOW server_version')->fetchColumn() . PHP_EOL;
    $ssl = $pdo->query("SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()")->fetchColumn();
    echo 'SSL in use         : ' . ($ssl === true || $ssl === 't' ? 'yes' : 'no/unknown (pooler may terminate SSL)') . PHP_EOL;
    $tables = $pdo->query(
        "SELECT string_agg(table_name, ', ' ORDER BY table_name) FROM information_schema.tables
         WHERE table_schema = 'public' AND table_type = 'BASE TABLE'"
    )->fetchColumn();
    echo 'Tables in public   : ' . ($tables ?: '(none — run database/schema.sql)') . PHP_EOL;
    $admins = $tables ? (int) $pdo->query("SELECT count(*) FROM public.users WHERE role = 'admin'")->fetchColumn() : 0;
    echo 'Admin accounts     : ' . $admins . ($admins === 0 ? '  (run php scripts/create_admin.php)' : '') . PHP_EOL;
} catch (Throwable $e) {
    echo 'Connection         : FAILED' . PHP_EOL;
    echo 'Reason             : ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
