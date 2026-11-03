<?php
/**
 * functions.php — small shared helpers (output escaping + JSON responses).
 */

declare(strict_types=1);

/** Escape text before printing it into HTML (prevents XSS). */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_api_request(): bool
{
    return str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
}

/**
 * Send a JSON response in the app's standard envelope and stop.
 *
 *   { "success": bool, "message": string, "data": mixed, "errors": object|array }
 */
function json_response(bool $success, string $message, mixed $data = null, array $errors = [], int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data ?? new stdClass(),
        // {} when there are field errors, [] when there are none
        'errors'  => $errors === [] ? [] : (object) $errors,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    exit;
}

function json_success(string $message, mixed $data = null, int $status = 200): never
{
    json_response(true, $message, $data, [], $status);
}

function json_error(string $message, int $status = 400, array $errors = []): never
{
    json_response(false, $message, null, $errors, $status);
}

/**
 * Read and decode a JSON request body (json_decode).
 * Returns 400 for malformed JSON and 415 for the wrong content type.
 */
function read_json_body(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!str_starts_with(strtolower($contentType), 'application/json')) {
        json_error('Requests must be sent as JSON (Content-Type: application/json).', 415);
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        json_error('Request body is empty.', 400);
    }
    if (strlen($raw) > 100_000) {
        json_error('Request body is too large.', 413);
    }
    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_error('Malformed JSON in request body.', 400);
    }
    if (!is_array($data) || array_is_list($data) && $data !== []) {
        json_error('JSON body must be an object.', 400);
    }
    return $data;
}

/**
 * Common setup for every file in /api: JSON-only output, login required,
 * and a safety net that turns unexpected errors into a generic 500 JSON
 * response (the real error goes to the server log, never to the browser).
 */
function api_bootstrap(): array
{
    set_exception_handler(function (Throwable $e): void {
        // Business-rule errors (e.g. not enough stock) are safe to show.
        if ($e instanceof AppException) {
            json_error($e->getMessage(), $e->status, $e->errors);
        }
        error_log('[API] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if ($e instanceof PDOException) {
            // Constraint violations become friendly 4xx messages; nothing internal is leaked.
            $code = pg_error_code($e);
            if ($code === '23505') {
                json_error('That record already exists.', 409);
            }
            if ($code === '23503') {
                json_error('This record is still referenced by other data.', 409);
            }
            if ($code === '23514') {
                json_error('One of the values is not allowed.', 422);
            }
            json_error('A database error occurred. Please try again.', 500);
        }
        if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'not configured')) {
            json_error('The server database connection is not configured.', 500);
        }
        json_error('An unexpected server error occurred.', 500);
    });

    $user = require_login();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST');
        json_error('Method not allowed.', 405);
    }
    if ($method === 'POST') {
        csrf_verify();
    }
    return $user;
}

/**
 * Run $work inside a database transaction: COMMIT if it succeeds,
 * ROLLBACK if anything throws (so no half-saved sales or stock changes).
 */
function transaction(callable $work): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $work($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Convert a NUMERIC string like "52.50" to integer cents (5250) to avoid float rounding. */
function to_cents(string|int|float $amount): int
{
    return (int) round(((float) $amount) * 100);
}

function from_cents(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

/** Read the JSON body for POST requests and return [action, data]. */
function post_action(array $allowed): array
{
    $data = read_json_body();
    $action = $data['action'] ?? null;
    if (!is_string($action) || !in_array($action, $allowed, true)) {
        json_error('Unknown or missing action.', 400, ['action' => 'Allowed: ' . implode(', ', $allowed)]);
    }
    return [$action, $data];
}

/** Postgres error code for unique_violation / foreign_key_violation / check_violation. */
function pg_error_code(PDOException $e): string
{
    return (string) ($e->errorInfo[0] ?? $e->getCode());
}

function money(mixed $value): string
{
    return number_format((float) $value, 2, '.', '');
}

/** Clamp pagination input to safe bounds. */
function pagination(array $query, int $defaultSize = 10, int $maxSize = 100): array
{
    $page = max(1, (int) ($query['page'] ?? 1));
    $size = (int) ($query['per_page'] ?? $defaultSize);
    $size = min(max($size, 1), $maxSize);
    return [$page, $size, ($page - 1) * $size];
}

function pagination_meta(int $total, int $page, int $size): array
{
    return [
        'page'        => $page,
        'per_page'    => $size,
        'total'       => $total,
        'total_pages' => max(1, (int) ceil($total / $size)),
    ];
}
