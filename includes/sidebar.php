<?php
/** sidebar.php — navigation. Admin-only links are hidden from staff (and the pages also check the role server-side). */
declare(strict_types=1);

$nav = [
    ['dashboard',  'Dashboard',  'M3 13h8V3H3zM13 21h8v-8h-8zM3 21h8v-6H3zM13 3v8h8V3z', false],
    ['sales',      'Sales',      'M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6M10 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM18 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2z', false],
    ['products',   'Products',   'M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8', false],
    ['inventory',  'Inventory',  'M4 4h16v4H4zM5 8v12h14V8M10 12h4', false],
    ['categories', 'Categories', 'M4 6h6v6H4zM14 6h6v6h-6zM4 16h6v4H4zM14 16h6v4h-6z', false],
    ['reports',    'Reports',    'M4 20V10M10 20V4M16 20v-7M22 20H2', false],
    ['users',      'Users',      'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8', true],
];
?>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <a class="brand" href="dashboard.php">
        <span class="brand-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20"><path d="M4 8l8-4.5L20 8v8l-8 4.5L4 16z M4 8l8 4.5L20 8 M12 12.5V20.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
        </span>
        <span><?= e(APP_NAME) ?></span>
    </a>
    <nav>
        <ul class="nav">
        <?php foreach ($nav as [$key, $label, $path, $adminOnly]):
            if ($adminOnly && $user['role'] !== 'admin') {
                continue;
            } ?>
            <li>
                <a href="<?= e($key) ?>.php" class="nav-link<?= $activePage === $key ? ' active' : '' ?>"<?= $activePage === $key ? ' aria-current="page"' : '' ?>>
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="<?= e($path) ?>" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span><?= e($label) ?></span>
                    <?php if ($key === 'inventory'): ?><span class="nav-badge" data-low-stock-badge hidden></span><?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
        </ul>
    </nav>
    <form method="post" action="logout.php" class="logout-form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <button type="submit" class="nav-link logout-btn">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>Log out</span>
        </button>
    </form>
</aside>
