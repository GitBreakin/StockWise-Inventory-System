<?php
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';
$user = require_login();
$pageTitle = 'Products';
$activePage = 'products';
$pageScripts = ['products.js'];
require __DIR__ . '/includes/header.php';
?>
<section class="card">
    <div class="card-header">
        <form class="toolbar" id="product-filters" role="search" style="flex:1">
            <div class="field field-grow">
                <label for="f-search">Search</label>
                <input type="search" id="f-search" name="search" placeholder="Name, SKU or description" maxlength="100">
            </div>
            <div class="field">
                <label for="f-category">Category</label>
                <select id="f-category" name="category_id"><option value="">All categories</option></select>
            </div>
            <div class="field">
                <label for="f-stock">Stock</label>
                <select id="f-stock" name="stock">
                    <option value="all">All levels</option>
                    <option value="in_stock">In stock</option>
                    <option value="low">Low stock</option>
                    <option value="out">Out of stock</option>
                </select>
            </div>
            <div class="field">
                <label for="f-status">Status</label>
                <select id="f-status" name="status">
                    <option value="active">Active</option>
                    <option value="archived">Archived</option>
                    <option value="all">All</option>
                </select>
            </div>
        </form>
        <?php if (is_admin()): ?>
            <div class="actions"><button type="button" class="btn btn-primary" id="add-product">+ Add product</button></div>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="responsive" id="product-table">
            <thead>
            <tr>
                <th class="sortable" data-sort="sku">SKU</th>
                <th class="sortable" data-sort="name" data-dir="asc">Product</th>
                <th class="sortable" data-sort="category">Category</th>
                <th class="sortable num" data-sort="selling_price">Price</th>
                <th class="sortable num" data-sort="quantity">Stock</th>
                <th>Status</th>
                <th class="text-right">Actions</th>
            </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <div class="card-footer pagination" id="product-pagination"></div>
</section>

<!-- Add / edit product -->
<div class="modal" id="product-modal" role="dialog" aria-modal="true" aria-labelledby="product-modal-title" hidden>
    <div class="modal-dialog">
        <form id="product-form" novalidate>
            <div class="modal-header">
                <h2 id="product-modal-title">Add product</h2>
                <button type="button" class="icon-btn" data-modal-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id">
                <div class="form-grid">
                    <div class="field">
                        <label for="p-sku">SKU <span class="req">*</span></label>
                        <input id="p-sku" name="sku" required maxlength="40" placeholder="e.g. BEV-005" style="text-transform:uppercase">
                        <span class="hint">Letters, numbers and dashes. Must be unique.</span>
                    </div>
                    <div class="field">
                        <label for="p-category">Category <span class="req">*</span></label>
                        <select id="p-category" name="category_id" required><option value="">Select…</option></select>
                    </div>
                    <div class="field span-2">
                        <label for="p-name">Product name <span class="req">*</span></label>
                        <input id="p-name" name="name" required maxlength="150">
                    </div>
                    <div class="field span-2">
                        <label for="p-description">Description</label>
                        <textarea id="p-description" name="description" maxlength="1000" rows="2"></textarea>
                    </div>
                    <div class="field">
                        <label for="p-purchase">Purchase price (₱) <span class="req">*</span></label>
                        <input id="p-purchase" name="purchase_price" type="number" min="0" step="0.01" required inputmode="decimal">
                    </div>
                    <div class="field">
                        <label for="p-selling">Selling price (₱) <span class="req">*</span></label>
                        <input id="p-selling" name="selling_price" type="number" min="0" step="0.01" required inputmode="decimal">
                        <span class="hint" id="margin-hint"></span>
                    </div>
                    <div class="field" id="opening-qty-field">
                        <label for="p-quantity">Opening quantity</label>
                        <input id="p-quantity" name="quantity" type="number" min="0" step="1" value="0" inputmode="numeric">
                        <span class="hint">Recorded as an "Opening stock" movement.</span>
                    </div>
                    <div class="field">
                        <label for="p-reorder">Reorder level <span class="req">*</span></label>
                        <input id="p-reorder" name="reorder_level" type="number" min="0" step="1" required inputmode="numeric">
                        <span class="hint">Alert when stock falls to this number or below.</span>
                    </div>
                </div>
                <p class="alert alert-info" id="qty-note" hidden style="margin-top:14px">To change the quantity, use Inventory → Stock in / Stock out / Adjust. Every change is recorded.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save product</button>
            </div>
        </form>
    </div>
</div>

<!-- Product details -->
<div class="modal" id="view-modal" role="dialog" aria-modal="true" aria-labelledby="view-title" hidden>
    <div class="modal-dialog modal-lg">
        <div class="modal-header">
            <h2 id="view-title">Product</h2>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body" id="view-body"></div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
