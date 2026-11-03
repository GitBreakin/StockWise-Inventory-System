<?php
/**
 * header.php — top of every logged-in page.
 * Expects: $pageTitle (string), $activePage (string), $user (array)
 */
declare(strict_types=1);
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%230f766e'/><path d='M9 12l7-4 7 4v8l-7 4-7-4z' fill='none' stroke='white' stroke-width='2'/></svg>">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body data-role="<?= e($user['role']) ?>">
<div class="app">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" data-sidebar-close></div>
    <main class="main">
        <header class="topbar">
            <button type="button" class="icon-btn sidebar-toggle" data-sidebar-toggle aria-label="Open menu">
                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
            <h1 class="page-title"><?= e($pageTitle) ?></h1>
            <div class="topbar-user">
                <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
                <span class="topbar-user-text">
                    <strong><?= e($user['full_name']) ?></strong>
                    <small><?= e(ucfirst($user['role'])) ?></small>
                </span>
            </div>
        </header>
        <div class="content">
<?php if (isset($_GET['denied'])): ?>
            <div class="alert alert-error">That page is for administrators only.</div>
<?php endif; ?>
