<?php
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';
$user = require_login();
$pageTitle = 'Inventory';
$activePage = 'inventory';
$pageScripts = ['inventory.js'];
require __DIR__ . '/includes/header.php';
?>
<div class="grid grid-main-side">
    <section class="card" id="low-stock">
        <div class="card-header">
            <h2>Low-stock alerts</h2>
            <span class="muted actions" style="font-size:.82rem">Quantity at or below the reorder level</span>
        </div>
        <div class="table-wrap">
            <table class="responsive" id="low-stock-table">
                <thead><tr><th>Product</th><th class="num">On hand</th><th class="num">Reorder at</th><th class="num">Suggested</th><th>Status</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

    <section class="card" id="stock-panel">
        <div class="tabs" role="tablist" style="padding:0 12px">
            <button type="button" class="tab active" data-mode="stock_in" role="tab">Stock in</button>
            <button type="button" class="tab" data-mode="stock_out" role="tab">Stock out</button>
            <?php if (is_admin()): ?>
                <button type="button" class="tab" data-mode="adjustment" role="tab">Adjust</button>
            <?php endif; ?>
        </div>
        <form id="stock-form" class="card-body" style="display:grid;gap:14px" novalidate>
            <p class="muted" id="mode-help" style="margin:0"></p>
            <div class="field" style="position:relative">
                <label for="product-search">Product <span class="req">*</span></label>
                <input type="search" id="product-search" placeholder="Type a name or SKU…" autocomplete="off">
                <input type="hidden" name="product_id">
                <div class="card product-results" id="product-results" hidden style="position:absolute;top:100%;left:0;right:0;z-index:5"></div>
            </div>
            <div class="alert alert-info" id="selected-product" hidden></div>

            <div class="field" data-for="stock_in stock_out">
                <label for="s-quantity">Quantity <span class="req">*</span></label>
                <input type="number" id="s-quantity" name="quantity" min="1" step="1" inputmode="numeric">
            </div>
            <div class="field" data-for="adjustment" hidden>
                <label for="s-counted">Counted quantity <span class="req">*</span></label>
                <input type="number" id="s-counted" name="counted_quantity" min="0" step="1" inputmode="numeric">
                <span class="hint" id="adjust-preview"></span>
            </div>
            <div class="field" data-for="stock_out" hidden>
                <label for="s-reason-out">Reason <span class="req">*</span></label>
                <select id="s-reason-out" name="reason">
                    <option value="">Select…</option>
                    <option value="damaged">Damaged</option>
                    <option value="expired">Expired</option>
                    <option value="returned_to_supplier">Returned to supplier</option>
                    <option value="internal_use">Internal use</option>
                    <option value="lost">Lost / missing</option>
                    <option value="other">Other (explain in notes)</option>
                </select>
            </div>
            <div class="field" data-for="adjustment" hidden>
                <label for="s-reason-adj">Reason <span class="req">*</span></label>
                <select id="s-reason-adj" name="reason" disabled>
                    <option value="">Select…</option>
                    <option value="physical_count">Physical count</option>
                    <option value="data_correction">Data correction</option>
                    <option value="found_stock">Found stock</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="field">
                <label for="s-notes">Notes <span class="req" id="notes-req" hidden>*</span></label>
                <textarea id="s-notes" name="notes" rows="2" maxlength="400" placeholder="e.g. supplier delivery receipt no."></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Record stock in</button>
        </form>
    </section>
</div>

<section class="card">
    <div class="card-header">
        <h2>Stock movement history</h2>
        <form class="toolbar" id="movement-filters" style="width:100%">
            <div class="field field-grow">
                <label for="m-search">Search</label>
                <input type="search" id="m-search" name="search" placeholder="Product, SKU or notes">
            </div>
            <div class="field">
                <label for="m-type">Type</label>
                <select id="m-type" name="movement_type">
                    <option value="">All types</option>
                    <option value="stock_in">Stock in</option>
                    <option value="stock_out">Stock out</option>
                    <option value="adjustment">Adjustment</option>
                    <option value="sale">Sale</option>
                    <option value="sale_void">Sale voided</option>
                    <option value="initial">Opening stock</option>
                </select>
            </div>
            <div class="field"><label for="m-from">From</label><input type="date" id="m-from" name="from"></div>
            <div class="field"><label for="m-to">To</label><input type="date" id="m-to" name="to"></div>
        </form>
    </div>
    <div class="table-wrap">
        <table class="responsive" id="movement-table">
            <thead><tr><th>Date</th><th>Product</th><th>Type</th><th class="num">Change</th><th class="num">Before → After</th><th>By</th><th>Notes / reference</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
    <div class="card-footer pagination" id="movement-pagination"></div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
