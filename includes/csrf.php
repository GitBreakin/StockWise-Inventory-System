<?php
/**
 * csrf.php — Cross-Site Request Forgery protection.
 *
 * Each session gets a random token. Pages print it in a <meta> tag, and
 * jQuery sends it in the X-CSRF-Token header on every POST (see app.js).
 * A request without the correct token is rejected with 419.
 */

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || $expected === '' || !hash_equals($expected, $sent)) {
        if (is_api_request()) {
            json_error('Your session security token is invalid or expired. Please reload the page.', 419);
        }
        http_response_code(419);
        exit('Invalid security token. Please go back and reload the page.');
    }
}
