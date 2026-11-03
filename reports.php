<?php
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';
$user = require_login();
$pageTitle = 'Reports';
$activePage = 'reports';
$pageScripts = ['reports.js'];
require __DIR__ . '/includes/header.php';
?>
<section class="card">
    <form id="report-form" class="card-body" novalidate style="display:grid;gap:14px">
        <div class="toolbar">
            <div class="field">
                <label for="r-type">Report</label>
                <select id="r-type" name="type">
                    <option value="sales">Sales summary</option>
                    <option value="best_sellers">Best-selling products</option>
                    <option value="slow_moving">Slow-moving products</option>
                    <option value="valuation">Inventory valuation</option>
                    <option value="movements">Stock-in / stock-out history</option>
                    <option value="low_stock">Low stock</option>
                    <option value="transactions">Sales transaction details</option>
                </select>
            </div>
            <div class="field" data-for="sales">
                <label for="r-group">Group by</label>
                <select id="r-group" name="group">
                    <option value="day">Day (daily)</option>
                    <option value="week">Week (weekly)</option>
                    <option value="month">Month (monthly)</option>
                </select>
            </div>
            <div class="field" data-for="sales best_sellers movements transactions"><label for="r-from">From</label><input type="date" id="r-from" name="from"></div>
            <div class="field" data-for="sales best_sellers movements transactions"><label for="r-to">To</label><input type="date" id="r-to" name="to"></div>
            <div class="field" data-for="best_sellers">
                <label for="r-limit">Top</label>
                <select id="r-limit" name="limit"><option>5</option><option selected>10</option><option>20</option><option>50</option></select>
            </div>
            <div class="field" data-for="movements">
                <label for="r-mtype">Type</label>
                <select id="r-mtype" name="movement_type">
                    <option value="">All</option><option value="stock_in">Stock in</option><option value="stock_out">Stock out</option>
                    <option value="adjustment">Adjustment</option><option value="sale">Sale</option><option value="sale_void">Sale voided</option><option value="initial">Opening stock</option>
                </select>
            </div>
            <div class="field" data-for="transactions">
                <label for="r-status">Status</label>
                <select id="r-status" name="status"><option value="">All</option><option value="completed">Completed</option><option value="voided">Voided</option></select>
            </div>
            <div class="field" data-for="slow_moving">
                <label for="r-period">Analysis period (days)</label>
                <input type="number" id="r-period" name="period_days" min="7" max="365" value="30">
            </div>
            <div class="field" data-for="slow_moving">
                <label for="r-coverage">Slow if stock lasts over (days)</label>
                <input type="number" id="r-coverage" name="coverage_days" min="14" max="730" value="90">
            </div>
        </div>
        <div class="toolbar">
            <div class="segmented" id="quick-range" data-for="sales best_sellers movements transactions" role="group" aria-label="Quick date range">
                <button type="button" data-range="today">Today</button>
                <button type="button" data-range="7">7 days</button>
                <button type="button" data-range="month">This month</button>
                <button type="button" data-range="30" class="active">30 days</button>
            </div>
            <div style="margin-left:auto;display:flex;gap:8px">
                <button type="button" class="btn btn-secondary" id="export-csv">Export CSV</button>
                <button type="submit" class="btn btn-primary">Generate report</button>
            </div>
        </div>
    </form>
</section>

<section class="card" id="report-output">
    <div class="card-header"><div><h2 id="report-title">Report</h2><small class="muted" id="report-meta"></small></div></div>
    <div id="report-extra"></div>
    <div class="table-wrap">
        <table class="responsive" id="report-table"><thead></thead><tbody></tbody><tfoot></tfoot></table>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
