<?php
/**
 * router.php — ONLY for PHP's built-in development server:
 *
 *     php -S localhost:8000 router.php
 *
 * The built-in server ignores .htaccess, so this file applies the same
 * protection: hidden files (.env, .git), server-side folders and SQL/docs
 * are never sent to the browser.
 */

declare(strict_types=1);

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

$blocked = preg_match('#(^|/)\.#', $path)                                       // .env, .git, .htaccess
    || preg_match('#^/(config|includes|database|scripts|docs|tests)(/|$)#i', $path)   // server-side folders
    || preg_match('#\.(sql|md|log|sh|ini|example)$#i', $path)
    || $path === '/router.php';

if ($blocked) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Not found';
    return true;
}

return false;   // let the built-in server handle the request normally
