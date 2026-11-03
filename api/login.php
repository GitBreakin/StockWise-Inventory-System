<?php
/**
 * POST api/login.php   (JSON)  { "email": "...", "password": "..." }
 * Logs the user in and returns where to go next.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

set_exception_handler(function (Throwable $e): void {
    error_log('[API login] ' . $e->getMessage());
    json_error('Login is temporarily unavailable. Please try again.', 500);
});

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    json_error('Method not allowed.', 405);
}
csrf_verify();

$input = read_json_body();
$v = new Validator($input);
$email = $v->string('email', 3, 255);
$password = $v->string('password', 1, 200);
if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $v->error('email', 'Enter a valid email address.');
}
$v->abortIfInvalid();

[$ok, $message] = attempt_login($email, (string) $input['password']);
if (!$ok) {
    json_error($message, 401);
}

json_success($message, [
    'redirect'   => 'dashboard.php',
    'user'       => current_user(),
    'csrf_token' => csrf_token(),
]);
