<?php
/**
 * database.php — one shared PDO connection to Supabase PostgreSQL.
 *
 * Connection details come from .env (see .env.example). Use the
 * "Session pooler" values from Supabase → Connect.
 */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env('DB_HOST');
    $port = env('DB_PORT', '5432');
    $name = env('DB_NAME', 'postgres');
    $user = env('DB_USER');
    $pass = env('DB_PASSWORD', '');
    $sslmode = env('DB_SSLMODE', 'require');

    if ($host === null || $user === null) {
        throw new RuntimeException('Database is not configured. Copy .env.example to .env and fill in the values.');
    }

    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=%s', $host, $port, $name, $sslmode);
    $rootCert = env('DB_SSLROOTCERT');
    if ($rootCert !== null) {
        $dsn .= ';sslrootcert=' . $rootCert;
    }

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // errors become exceptions
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,                  // real prepared statements
        PDO::ATTR_TIMEOUT            => 10,
    ]);

    // Make date grouping in reports follow the business's local time.
    $tz = $pdo->quote(date_default_timezone_get());
    $pdo->exec("SET TIME ZONE $tz");

    return $pdo;
}
