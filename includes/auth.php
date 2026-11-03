<?php
/**
 * auth.php — login, logout, sessions and role checks.
 *
 * Roles:
 *   admin — everything, including categories, product archive/delete,
 *           inventory adjustments, voiding sales, and user management
 *   staff — view data, create sales, record stock-in / stock-out
 */

declare(strict_types=1);

const SESSION_IDLE_SECONDS = 1800;   // log out after 30 minutes of inactivity
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCK_SECONDS = 60;

/** The logged-in user (fresh from the database), or null. */
function current_user(): ?array
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    $cached = null;

    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId)) {
        return null;
    }

    // Idle timeout
    $last = $_SESSION['last_activity'] ?? 0;
    if (time() - $last > SESSION_IDLE_SECONDS) {
        logout_user();
        return null;
    }
    $_SESSION['last_activity'] = time();

    // Re-check the database so a deactivated user loses access immediately.
    $stmt = db()->prepare('SELECT id, full_name, email, role FROM public.users WHERE id = :id AND is_active');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if ($user === false) {
        logout_user();
        return null;
    }
    $user['id'] = (int) $user['id'];
    $cached = $user;
    return $cached;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? null) === 'admin';
}

/** Pages: redirect to login. APIs: 401 JSON. */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        if (is_api_request()) {
            json_error('Your session has expired. Please log in again.', 401);
        }
        header('Location: login.php');
        exit;
    }
    return $user;
}

function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        if (is_api_request()) {
            json_error('You do not have permission to do that.', 403);
        }
        http_response_code(403);
        header('Location: dashboard.php?denied=1');
        exit;
    }
    return $user;
}

/**
 * Check email + password. Returns [ok, message].
 * Uses a short session-based lockout after repeated failures.
 */
function attempt_login(string $email, string $password): array
{
    $lockedUntil = $_SESSION['login_locked_until'] ?? 0;
    if ($lockedUntil > time()) {
        return [false, 'Too many failed attempts. Try again in ' . ($lockedUntil - time()) . ' seconds.'];
    }

    $stmt = db()->prepare('SELECT id, password_hash, is_active FROM public.users WHERE lower(email) = lower(:email)');
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();

    // Always run password_verify so response time doesn't reveal which emails exist.
    $hash = $row['password_hash'] ?? '$2y$10$gGjuRQS5UDUXf1i.IiGfouHW1rGkm8SdWZLb3iJyQrT6UZaLS1q.a';
    $valid = password_verify($password, $hash);

    if ($row === false || !$valid || !$row['is_active']) {
        $_SESSION['login_failures'] = ($_SESSION['login_failures'] ?? 0) + 1;
        if ($_SESSION['login_failures'] >= LOGIN_MAX_ATTEMPTS) {
            $_SESSION['login_locked_until'] = time() + LOGIN_LOCK_SECONDS;
            $_SESSION['login_failures'] = 0;
        }
        return [false, 'Incorrect email or password.'];
    }

    // Upgrade the hash automatically if PHP's default algorithm changes.
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE public.users SET password_hash = :h WHERE id = :id')
            ->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $row['id']]);
    }

    session_regenerate_id(true);   // prevent session fixation
    unset($_SESSION['login_failures'], $_SESSION['login_locked_until']);
    $_SESSION['user_id'] = (int) $row['id'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    db()->prepare('UPDATE public.users SET last_login_at = now() WHERE id = :id')->execute(['id' => $row['id']]);
    return [true, 'Welcome back!'];
}

function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        session_destroy();
    }
}

/** Password rule shared by the admin script and user management. */
function password_problem(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (strlen($password) > 72) {
        return 'Password must be at most 72 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}
