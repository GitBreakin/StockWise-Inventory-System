<?php
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';
$user = require_login();
$pageTitle = 'Sales';
$activePage = 'sales';
$pageScripts = ['sales.js'];
require __DIR__ . '/includes/header.php';
?>
<div class="tabs" role="tablist">
    <button type="button" class="tab active" data-tab="pos" role="tab">New sale</button>
    <button type="button" class="tab" data-tab="history" role="tab">Sales history</button>
</div>

<!-- ============ New sale (point of sale) ============ -->
<div id="tab-pos" class="pos">
    <section class="card">
        <div class="card-header">
            <div class="field field-grow" style="flex:1">
                <label for="pos-search">Find product</label>
                <input type="search" id="pos-search" placeholder="Type a product name or SKU…" autocomplete="off">
            </div>
        </div>
        <div class="product-results" id="pos-results"></div>
    </section>

    <section class="card">
        <div class="card-header"><h2>Cart</h2><div class="actions"><button type="button" class="btn btn-ghost btn-sm" id="clear-cart">Clear</button></div></div>
        <div class="table-wrap">
            <table id="cart-table">
                <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Total</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <form id="checkout-form" class="card-body" style="display:grid;gap:12px" novalidate>
            <div class="form-grid">
                <div class="field">
                    <label for="discount">Discount (₱)</label>
                    <input type="number" id="discount" name="discount" min="0" step="0.01" value="0" inputmode="decimal">
                </div>
                <div class="field">
                    <label for="payment">Payment <span class="req">*</span></label>
                    <select id="payment" name="payment_method">
                        <option value="cash">Cash</option>
                        <option value="gcash">GCash</option>
                        <option value="card">Card</option>
                    </select>
                </div>
                <div class="field span-2" id="tendered-field">
                    <label for="tendered">Amount tendered (₱)</label>
                    <input type="number" id="tendered" name="amount_tendered" min="0" step="0.01" inputmode="decimal" placeholder="Optional — to compute change">
                </div>
            </div>
            <div>
                <div class="summary-row"><span>Subtotal</span><span id="sum-subtotal">₱0.00</span></div>
                <div class="summary-row"><span>Discount</span><span id="sum-discount">−₱0.00</span></div>
                <div class="summary-row summary-total"><span>Total</span><span id="sum-total">₱0.00</span></div>
                <div class="summary-row muted" id="sum-change-row" hidden><span>Change</span><span id="sum-change"></span></div>
                <small class="muted">Prices and totals are recalculated by the server when you complete the sale.</small>
            </div>
            <button type="submit" class="btn btn-primary btn-block" id="complete-sale" disabled>Complete sale</button>
        </form>
    </section>
</div>

<!-- ============ Sales history ============ -->
<section class="card" id="tab-history" hidden>
    <div class="card-header">
        <form class="toolbar" id="sales-filters" style="width:100%">
            <div class="field field-grow"><label for="h-search">Transaction no.</label><input type="search" id="h-search" name="search" placeholder="TXN-…" maxlength="40"></div>
            <div class="field"><label for="h-from">From</label><input type="date" id="h-from" name="from"></div>
            <div class="field"><label for="h-to">To</label><input type="date" id="h-to" name="to"></div>
            <div class="field">
                <label for="h-status">Status</label>
                <select id="h-status" name="status"><option value="">All</option><option value="completed">Completed</option><option value="voided">Voided</option></select>
            </div>
        </form>
    </div>
    <div class="table-wrap">
        <table class="responsive" id="sales-table">
            <thead><tr><th>Transaction</th><th>Date</th><th>Cashier</th><th class="num">Items</th><th>Payment</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
    <div class="card-footer pagination" id="sales-pagination"></div>
</section>

<!-- Sale details / receipt -->
<div class="modal" id="sale-modal" role="dialog" aria-modal="true" aria-labelledby="sale-title" hidden>
    <div class="modal-dialog">
        <div class="modal-header">
            <h2 id="sale-title">Sale</h2>
            <button type="button" class="icon-btn" data-modal-close aria-label="Close">&times;</button>
        </div>
        <div class="modal-body" id="sale-body"></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
            <?php if (is_admin()): ?><button type="button" class="btn btn-danger" id="void-sale" hidden>Void sale</button><?php endif; ?>
            <button type="button" class="btn btn-primary" data-modal-close>Done</button>
        </div>
    </div>
</div>

<?php if (is_admin()): ?>
<div class="modal" id="void-modal" role="dialog" aria-modal="true" aria-labelledby="void-title" hidden>
    <div class="modal-dialog modal-sm">
        <form id="void-form" novalidate>
            <div class="modal-header"><h2 id="void-title">Void sale</h2><button type="button" class="icon-btn" data-modal-close aria-label="Close">&times;</button></div>
            <div class="modal-body" style="display:grid;gap:12px">
                <p class="muted" style="margin:0">The items will be returned to stock and the sale will be excluded from sales totals. This cannot be undone.</p>
                <input type="hidden" name="id">
                <div class="field">
                    <label for="void-reason">Reason <span class="req">*</span></label>
                    <textarea id="void-reason" name="reason" rows="3" maxlength="255" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-danger">Void sale</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
