<?php
/** logout.php — POST only (with CSRF token) so a link on another site cannot log you out. */
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}
csrf_verify();
logout_user();
header('Location: login.php?logged_out=1');
exit;
