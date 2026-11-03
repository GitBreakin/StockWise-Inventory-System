<?php
/**
 * create_admin.php — safely create a user account from the command line.
 *
 * There is no public registration page. Run this once to make the first
 * administrator (and later to add staff if you prefer the terminal):
 *
 *   php scripts/create_admin.php
 *   php scripts/create_admin.php --role=staff
 *
 * The password is hashed with password_hash() before it is stored.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/bootstrap.php';

$opts = getopt('', ['name:', 'email:', 'role:']);
$role = $opts['role'] ?? 'admin';
if (!in_array($role, ['admin', 'staff'], true)) {
    fwrite(STDERR, "Role must be admin or staff.\n");
    exit(1);
}

function ask(string $label, bool $hidden = false): string
{
    echo $label;
    $isWindows = PHP_OS_FAMILY === 'Windows';
    if ($hidden && !$isWindows) {
        shell_exec('stty -echo');
    }
    $value = trim((string) fgets(STDIN));
    if ($hidden && !$isWindows) {
        shell_exec('stty echo');
        echo PHP_EOL;
    }
    return $value;
}

$name = $opts['name'] ?? ask('Full name: ');
$email = $opts['email'] ?? ask('Email: ');
// For automated setups the password may come from an environment variable.
$password = getenv('NEW_USER_PASSWORD') ?: ask('Password (min 8 chars, letters + numbers): ', true);
if (getenv('NEW_USER_PASSWORD') === false) {
    $confirm = ask('Confirm password: ', true);
    if (!hash_equals($password, $confirm)) {
        fwrite(STDERR, "Passwords do not match.\n");
        exit(1);
    }
}

$errors = [];
if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
    $errors[] = 'Full name must be 2–120 characters.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email is not valid.';
}
if ($problem = password_problem($password)) {
    $errors[] = $problem;
}
if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

try {
    $stmt = db()->prepare(
        'INSERT INTO public.users (full_name, email, password_hash, role)
         VALUES (:name, :email, :hash, :role) RETURNING id'
    );
    $stmt->execute([
        'name'  => $name,
        'email' => strtolower($email),
        'hash'  => password_hash($password, PASSWORD_DEFAULT),
        'role'  => $role,
    ]);
    echo "Created $role account #" . $stmt->fetchColumn() . " for $email" . PHP_EOL;
} catch (PDOException $e) {
    if (pg_error_code($e) === '23505') {
        fwrite(STDERR, "An account with that email already exists.\n");
    } else {
        fwrite(STDERR, 'Database error: ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
