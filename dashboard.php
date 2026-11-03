<?php
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';
$user = require_login();
$pageTitle = 'Dashboard';
$activePage = 'dashboard';
$pageScripts = ['dashboard.js'];
require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <div>
        <strong>Hello, <?= e(explode(' ', $user['full_name'])[0]) ?>!</strong>
        <div class="muted" id="period-label">Loading figures from the database…</div>
    </div>
    <div class="segmented" id="period-filter" role="group" aria-label="Sales period" style="margin-left:auto">
        <button type="button" data-period="today" class="active">Today</button>
        <button type="button" data-period="week">7 days</button>
        <button type="button" data-period="month">This month</button>
        <button type="button" data-period="30d">30 days</button>
    </div>
</div>

<section class="stat-grid" id="stats" aria-label="Key figures">
    <?php foreach (['Sales', 'Transactions', 'Products', 'Categories', 'Inventory value', 'Low stock'] as $label): ?>
        <div class="stat"><span class="stat-label"><?= e($label) ?></span><span class="stat-value"><span class="spinner"></span></span></div>
    <?php endforeach; ?>
</section>

<div class="grid grid-main-side">
    <section class="card">
        <div class="card-header"><h2>Sales — last 14 days</h2><span class="muted actions" id="chart-total"></span></div>
        <div class="card-body"><div class="bar-chart" id="sales-chart"><div class="loading-block"><span class="spinner"></span></div></div></div>
    </section>
    <section class="card">
        <div class="card-header"><h2>Top sellers</h2><span class="muted actions" id="top-period"></span></div>
        <ul class="list" id="top-sellers"><li class="loading-block"><span class="spinner"></span></li></ul>
    </section>
</div>

<div class="grid grid-2">
    <section class="card">
        <div class="card-header">
            <h2>Low-stock alerts</h2>
            <div class="actions"><a class="btn btn-secondary btn-sm" href="inventory.php#low-stock">Restock</a></div>
        </div>
        <ul class="list" id="low-stock"><li class="loading-block"><span class="spinner"></span></li></ul>
    </section>
    <section class="card">
        <div class="card-header">
            <h2>Slow-moving products</h2>
            <div class="actions"><a class="btn btn-secondary btn-sm" href="reports.php?type=slow_moving">Full analysis</a></div>
        </div>
        <div id="slow-moving"><div class="loading-block"><span class="spinner"></span></div></div>
    </section>
</div>

<div class="grid grid-2">
    <section class="card">
        <div class="card-header"><h2>Recent sales</h2><div class="actions"><a class="btn btn-ghost btn-sm" href="sales.php#history">View all</a></div></div>
        <ul class="list" id="recent-sales"><li class="loading-block"><span class="spinner"></span></li></ul>
    </section>
    <section class="card">
        <div class="card-header"><h2>Recent stock movements</h2><div class="actions"><a class="btn btn-ghost btn-sm" href="inventory.php">View all</a></div></div>
        <ul class="list" id="recent-moves"><li class="loading-block"><span class="spinner"></span></li></ul>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
