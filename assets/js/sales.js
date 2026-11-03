/** sales.js — point of sale cart, checkout, sales history and voids (AJAX + JSON). */
$(function () {
    const esc = App.esc;
    const cart = new Map();   // product_id -> { id, sku, name, price, stock, qty }
    let historyPage = 1;
    let historyLoaded = false;

    // ---------------- Tabs ----------------
    function showTab(tab) {
        $('.tab').removeClass('active').filter(`[data-tab="${tab}"]`).addClass('active');
        $('#tab-pos').prop('hidden', tab !== 'pos');
        $('#tab-history').prop('hidden', tab !== 'history');
        if (tab === 'history' && !historyLoaded) { historyLoaded = true; loadHistory(); }
        history.replaceState(null, '', tab === 'history' ? '#history' : '#');
    }
    $('.tabs').on('click', '.tab', function () { showTab($(this).data('tab')); });

    // ---------------- Product search ----------------
    function searchProducts(q) {
        $('#pos-results').html('<div class="loading-block"><span class="spinner"></span></div>');
        App.api('GET', 'api/products.php', { lookup: 1, q })
            .done((res) => {
                const items = res.data.items;
                $('#pos-results').html(items.length ? items.map((p) => {
                    const inCart = cart.has(p.id) ? cart.get(p.id).qty : 0;
                    const left = p.quantity - inCart;
                    return `<button type="button" class="product-pick" ${left <= 0 ? 'disabled' : ''}
                        data-product='${esc(JSON.stringify({ id: p.id, sku: p.sku, name: p.name, price: p.selling_price, stock: p.quantity }))}'>
                        <span class="grow"><strong>${esc(p.name)}</strong><br><small class="mono muted">${esc(p.sku)}</small></span>
                        <span class="text-right"><strong>${App.money(p.selling_price)}</strong><br>
                        <small class="${left <= 0 ? 'trend-down' : 'muted'}">${left <= 0 ? 'Out of stock' : left + ' available'}</small></span>
                    </button>`;
                }).join('') : `<div class="empty-state"><strong>No products found</strong>Try another name or SKU.</div>`);
            })
            .fail((err) => $('#pos-results').html(`<div class="alert alert-error">${esc(err.message)}</div>`));
    }
    $('#pos-search').on('input', App.debounce(function () { searchProducts($(this).val().trim()); }, 250));

    $('#pos-results').on('click', '.product-pick', function () {
        const p = $(this).data('product');
        const line = cart.get(p.id) || { ...p, qty: 0 };
        if (line.qty >= p.stock) return App.toast('warning', `Only ${p.stock} of ${p.name} in stock.`);
        line.qty += 1;
        cart.set(p.id, line);
        renderCart();
        searchProducts($('#pos-search').val().trim());   // refresh "available" counts
    });

    // ---------------- Cart ----------------
    function cents(n) { return Math.round(Number(n) * 100); }

    function renderCart() {
        const $tb = $('#cart-table tbody');
        if (!cart.size) {
            $tb.html('<tr><td colspan="4"><div class="empty-state"><strong>Cart is empty</strong>Search and click a product to add it.</div></td></tr>');
        } else {
            $tb.html([...cart.values()].map((l) => `<tr data-id="${l.id}">
                <td><strong>${esc(l.name)}</strong><br><small class="muted">${App.money(l.price)} each</small></td>
                <td class="num"><input type="number" class="cart-qty" min="1" max="${l.stock}" step="1" value="${l.qty}" aria-label="Quantity of ${esc(l.name)}"></td>
                <td class="num">${App.money(cents(l.price) * l.qty / 100)}</td>
                <td><button type="button" class="icon-btn" data-remove aria-label="Remove ${esc(l.name)}">&times;</button></td>
            </tr>`).join(''));
        }
        updateTotals();
    }

    function updateTotals() {
        let sub = 0;
        cart.forEach((l) => { sub += cents(l.price) * l.qty; });
        const disc = Math.max(0, cents($('#discount').val() || 0));
        const total = Math.max(0, sub - disc);
        $('#sum-subtotal').text(App.money(sub / 100));
        $('#sum-discount').text('−' + App.money(disc / 100));
        $('#sum-total').text(App.money(total / 100));
        const tendered = $('#tendered').val();
        const showChange = $('#payment').val() === 'cash' && tendered !== '';
        $('#sum-change-row').prop('hidden', !showChange);
        if (showChange) $('#sum-change').text(App.money((cents(tendered) - total) / 100));
        $('#complete-sale').prop('disabled', cart.size === 0);
        return { sub, disc, total };
    }

    $('#cart-table')
        .on('change', '.cart-qty', function () {
            const id = $(this).closest('tr').data('id');
            const line = cart.get(id);
            let qty = parseInt($(this).val(), 10);
            if (!qty || qty < 1) qty = 1;
            if (qty > line.stock) { qty = line.stock; App.toast('warning', `Only ${line.stock} in stock.`); }
            line.qty = qty;
            renderCart();
        })
        .on('click', '[data-remove]', function () {
            cart.delete($(this).closest('tr').data('id'));
            renderCart();
            searchProducts($('#pos-search').val().trim());
        });

    $('#clear-cart').on('click', () => {
        if (!cart.size) return;
        App.confirm({ title: 'Clear the cart?', message: 'All items will be removed from this sale.', confirmText: 'Clear' })
            .then((ok) => { if (ok) { cart.clear(); renderCart(); searchProducts($('#pos-search').val().trim()); } });
    });

    $('#discount, #tendered').on('input', updateTotals);
    $('#payment').on('change', function () {
        $('#tendered-field').toggle($(this).val() === 'cash');
        if ($(this).val() !== 'cash') $('#tendered').val('');
        updateTotals();
    });

    // ---------------- Checkout ----------------
    $('#checkout-form').on('submit', function (e) {
        e.preventDefault();
        const $form = $(this);
        const $btn = $('#complete-sale');
        if ($btn.prop('disabled') || !cart.size) return;

        const t = updateTotals();
        const errors = {};
        if (t.disc > t.sub) errors.discount = 'Discount cannot be more than the subtotal.';
        const tendered = $('#tendered').val();
        if ($('#payment').val() === 'cash' && tendered !== '' && cents(tendered) < t.total) errors.amount_tendered = 'Amount tendered is less than the total.';
        if (Object.keys(errors).length) return App.showErrors($form, errors);
        App.clearErrors($form);

        // Only product IDs and quantities are sent — the server looks up prices itself.
        const payload = {
            action: 'create',
            items: [...cart.values()].map((l) => ({ product_id: l.id, quantity: l.qty })),
            discount: $('#discount').val() || '0',
            payment_method: $('#payment').val(),
        };
        if (tendered !== '') payload.amount_tendered = tendered;

        App.busy($btn, true);
        App.api('POST', 'api/sales.php', payload)
            .done((res) => {
                App.toast('success', res.message);
                cart.clear();
                $form[0].reset();
                $('#tendered-field').show();
                renderCart();
                searchProducts($('#pos-search').val().trim());
                showSale(res.data.sale);
                historyLoaded = false;
                $('[data-low-stock-badge]').length && App.api('GET', 'api/inventory.php', { view: 'low_stock' })
                    .done((r) => $('[data-low-stock-badge]').text(r.data.items.length).prop('hidden', !r.data.items.length));
            })
            .fail((err) => {
                App.showErrors($form, err.errors);
                // Item-level problems (e.g. someone else just bought the last unit)
                const itemErrors = Object.entries(err.errors || {}).filter(([k]) => k.startsWith('product_')).map(([, v]) => v);
                App.toast('error', itemErrors.length ? err.message + ' ' + itemErrors.join(' ') : err.message, 8000);
                searchProducts($('#pos-search').val().trim());
            })
            .always(() => App.busy($btn, false));
    });

    // ---------------- Receipt / details ----------------
    function showSale(s) {
        $('#sale-title').text(s.transaction_number);
        const rows = s.items.map((i) => `<tr><td>${esc(i.name)}<br><small>${i.quantity} × ${App.money(i.unit_price)}</small></td>
            <td class="num">${App.money(i.line_total)}</td></tr>`).join('');
        $('#sale-body').html(`
            ${s.status === 'voided' ? `<div class="alert alert-error" style="margin-bottom:12px"><strong>Voided</strong> ${App.dateTime(s.voided_at)} by ${esc(s.voided_by_name)} — ${esc(s.void_reason)}</div>` : ''}
            <div class="receipt">
                <div style="text-align:center;margin-bottom:8px"><strong>${esc($('.brand span:last').text())}</strong><br>
                ${App.dateTime(s.created_at)}<br>Cashier: ${esc(s.cashier)}</div>
                <table><tbody>${rows}</tbody><tfoot>
                    <tr><td>Subtotal</td><td class="num">${App.money(s.subtotal)}</td></tr>
                    <tr><td>Discount</td><td class="num">−${App.money(s.discount)}</td></tr>
                    <tr><td><strong>TOTAL</strong></td><td class="num"><strong>${App.money(s.total_amount)}</strong></td></tr>
                    <tr><td>Paid by</td><td class="num">${esc(s.payment_method.toUpperCase())}</td></tr>
                    ${s.amount_tendered ? `<tr><td>Tendered</td><td class="num">${App.money(s.amount_tendered)}</td></tr>
                    <tr><td>Change</td><td class="num">${App.money(s.change)}</td></tr>` : ''}
                </tfoot></table>
            </div>`);
        $('#void-sale').prop('hidden', s.status !== 'completed').data('id', s.id);
        App.modal.open('#sale-modal');
    }

    // ---------------- History ----------------
    function loadHistory() {
        const $tb = $('#sales-table tbody').html(App.loadingRow(8));
        App.clearErrors($('#sales-filters'));
        App.api('GET', 'api/sales.php', $.extend(App.formData($('#sales-filters')), { page: historyPage, per_page: 15 }))
            .done((res) => {
                const items = res.data.items;
                $tb.html(items.length ? items.map((s) => `<tr>
                    <td data-label="Transaction" class="mono">${esc(s.transaction_number)}</td>
                    <td data-label="Date" class="nowrap">${App.dateTime(s.created_at)}</td>
                    <td data-label="Cashier">${esc(s.cashier)}</td>
                    <td data-label="Items" class="num">${s.item_count}</td>
                    <td data-label="Payment">${esc(s.payment_method)}</td>
                    <td data-label="Total" class="num">${App.money(s.total_amount)}</td>
                    <td data-label="Status">${s.status === 'completed' ? '<span class="badge badge-success">Completed</span>' : '<span class="badge badge-danger">Voided</span>'}</td>
                    <td data-label=""><button type="button" class="btn btn-ghost btn-sm" data-view="${s.id}">View</button></td>
                </tr>`).join('') : App.emptyRow(8, 'No sales found', ' Adjust the filters or record a sale.'));
                const meta = res.data.pagination;
                App.pagination($('#sales-pagination'), meta, (p) => { historyPage = p; loadHistory(); });
                $('#sales-pagination .info').append(` · completed total ${App.money(res.data.completed_total)}`);
            })
            .fail((err) => {
                $tb.html(App.errorRow(8, err.message));
                App.showErrors($('#sales-filters'), err.errors);
            });
    }

    $('#sales-filters')
        .on('input', '#h-search', App.debounce(() => { historyPage = 1; loadHistory(); }, 350))
        .on('change', 'select, input[type=date]', () => { historyPage = 1; loadHistory(); })
        .on('submit', (e) => { e.preventDefault(); historyPage = 1; loadHistory(); });

    $('#sales-table').on('click', '[data-view]', function () {
        const $btn = $(this);
        App.busy($btn, true);
        App.api('GET', 'api/sales.php', { id: $btn.data('view') })
            .done((res) => showSale(res.data.sale))
            .fail((err) => App.toast('error', err.message))
            .always(() => App.busy($btn, false));
    });

    // ---------------- Void (admin) ----------------
    $('#void-sale').on('click', function () {
        $('#void-form')[0].reset();
        App.clearErrors($('#void-form'));
        $('#void-form [name=id]').val($(this).data('id'));
        App.modal.open('#void-modal');
    });

    $('#void-form').on('submit', function (e) {
        e.preventDefault();
        const $form = $(this), $btn = $form.find('[type=submit]');
        if ($btn.prop('disabled')) return;
        const data = App.formData($form);
        if (data.reason.trim().length < 3) return App.showErrors($form, { reason: 'Please give a reason (at least 3 characters).' });
        App.busy($btn, true);
        App.api('POST', 'api/sales.php', { action: 'void', id: Number(data.id), reason: data.reason })
            .done((res) => {
                App.toast('success', res.message);
                App.modal.close('#void-modal');
                showSale(res.data.sale);
                loadHistory();
            })
            .fail((err) => { App.showErrors($form, err.errors); App.toast('error', err.message); })
            .always(() => App.busy($btn, false));
    });

    // ---------------- Start ----------------
    $('#h-from').val(App.today(-29));
    $('#h-to').val(App.today());
    renderCart();
    searchProducts('');
    showTab(window.location.hash === '#history' ? 'history' : 'pos');
});
