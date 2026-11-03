<?php
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';

try {
    if (current_user()) {
        header('Location: dashboard.php');
        exit;
    }
    $dbError = null;
} catch (Throwable $e) {
    error_log('[login page] ' . $e->getMessage());
    $dbError = 'Cannot connect to the database. Check the .env settings (see README).';
}
header('X-Frame-Options: DENY');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title>Log in · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%230f766e'/><path d='M9 12l7-4 7 4v8l-7 4-7-4z' fill='none' stroke='white' stroke-width='2'/></svg>">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="login-page">
    <div class="card login-card">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="20" height="20"><path d="M4 8l8-4.5L20 8v8l-8 4.5L4 16z M4 8l8 4.5L20 8 M12 12.5V20.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
            </span>
            <span><?= e(APP_NAME) ?></span>
        </div>
        <h1>Sign in</h1>
        <p class="muted">Inventory and Sales Management System</p>

        <?php if ($dbError): ?>
            <div class="alert alert-error"><?= e($dbError) ?></div>
        <?php elseif (isset($_GET['logged_out'])): ?>
            <div class="alert alert-info">You have been logged out.</div>
        <?php endif; ?>

        <form id="login-form" novalidate>
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" autocomplete="username" required maxlength="255">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required maxlength="200">
            </div>
            <div class="alert alert-error" id="login-error" hidden></div>
            <button type="submit" class="btn btn-primary btn-block">Sign in</button>
        </form>
        <p class="muted" style="margin-top:16px;font-size:.82rem">Accounts are created by an administrator. There is no public sign-up.</p>
    </div>
</div>
<div class="toast-stack" id="toast-stack"></div>
<script src="assets/vendor/jquery-3.7.1.min.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/login.js"></script>
</body>
</html>
