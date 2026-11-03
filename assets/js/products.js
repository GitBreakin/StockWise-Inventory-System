/** products.js — product list, search/filter/sort/paginate and CRUD, all through AJAX + JSON. */
$(function () {
    const esc = App.esc;
    const $table = $('#product-table tbody');
    const $form = $('#product-form');
    const state = { page: 1, sort: 'name', dir: 'asc' };
    let request = null;   // last list request (so an old slow response can't overwrite a newer one)

    // ---- Categories for the filter and the form ----
    function loadCategories() {
        return App.api('GET', 'api/categories.php').done((res) => {
            const options = res.data.items.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
            $('#f-category').append(options);
            $('#p-category').append(options);
        });
    }

    // ---- List ----
    function filters() {
        return $.extend(App.formData($('#product-filters')), { page: state.page, sort: state.sort, dir: state.dir, per_page: 10 });
    }

    function load() {
        $table.html(App.loadingRow(7));
        const thisRequest = request = App.api('GET', 'api/products.php', filters());
        thisRequest
            .done((res) => {
                if (thisRequest !== request) return;
                render(res.data.items);
                App.pagination($('#product-pagination'), res.data.pagination, (p) => { state.page = p; load(); });
            })
            .fail((err) => thisRequest === request && $table.html(App.errorRow(7, err.message)));
    }

    function render(items) {
        if (!items.length) {
            const filtered = $('#f-search').val() || $('#f-category').val() || $('#f-stock').val() !== 'all';
            $table.html(App.emptyRow(7, filtered ? 'No products match your filters' : 'No products yet',
                filtered ? ' Try a different search or filter.' : (App.isAdmin() ? ' Click “Add product” to create one.' : '')));
            return;
        }
        $table.html(items.map((p) => {
            let actions = `<button type="button" class="btn btn-ghost btn-sm" data-action="view" data-id="${p.id}">View</button>`;
            if (App.isAdmin()) {
                actions += p.is_active
                    ? `<button type="button" class="btn btn-secondary btn-sm" data-action="edit" data-id="${p.id}">Edit</button>
                       <button type="button" class="btn btn-ghost btn-sm" data-action="archive" data-id="${p.id}" data-name="${esc(p.name)}">Archive</button>`
                    : `<button type="button" class="btn btn-secondary btn-sm" data-action="restore" data-id="${p.id}">Restore</button>
                       <button type="button" class="btn btn-ghost btn-sm" data-action="delete" data-id="${p.id}" data-name="${esc(p.name)}">Delete</button>`;
            }
            return `<tr class="${p.is_active ? '' : 'is-archived'}">
                <td data-label="SKU" class="mono">${esc(p.sku)}</td>
                <td data-label="Product"><strong>${esc(p.name)}</strong></td>
                <td data-label="Category">${esc(p.category_name)}</td>
                <td data-label="Price" class="num">${App.money(p.selling_price)}</td>
                <td data-label="Stock" class="num"><span>${App.number(p.quantity)} <span class="muted">/ ${p.reorder_level}</span></span></td>
                <td data-label="Status">${p.is_active ? App.stockBadge(p.quantity, p.reorder_level) : '<span class="badge">Archived</span>'}</td>
                <td data-label="Actions"><div class="row-actions">${actions}</div></td>
            </tr>`;
        }).join(''));
    }

    // Live search (debounced) and filters
    $('#f-search').on('input', App.debounce(() => { state.page = 1; load(); }, 350));
    $('#product-filters').on('change', 'select', () => { state.page = 1; load(); })
        .on('submit', (e) => { e.preventDefault(); state.page = 1; load(); });

    // Sortable column headers
    $('#product-table').on('click', 'th.sortable', function () {
        const key = $(this).data('sort');
        state.dir = state.sort === key && state.dir === 'asc' ? 'desc' : 'asc';
        state.sort = key;
        $('#product-table th.sortable').removeAttr('data-dir');
        $(this).attr('data-dir', state.dir);
        load();
    });

    // ---- Add / edit ----
    function openForm(product) {
        $form[0].reset();
        App.clearErrors($form);
        $form.find('[name=id]').val(product ? product.id : '');
        $('#product-modal-title').text(product ? 'Edit product' : 'Add product');
        $('#opening-qty-field').toggle(!product);
        $('#qty-note').prop('hidden', !product);
        if (product) {
            $.each(['sku', 'category_id', 'name', 'description', 'purchase_price', 'selling_price', 'reorder_level'], (_, k) => {
                $form.find(`[name=${k}]`).val(product[k] ?? '');
            });
        }
        updateMargin();
        App.modal.open('#product-modal');
    }

    function updateMargin() {
        const cost = parseFloat($('#p-purchase').val()), price = parseFloat($('#p-selling').val());
        if (cost >= 0 && price > 0) {
            const margin = ((price - cost) / price) * 100;
            $('#margin-hint').text(`Margin: ${margin.toFixed(1)}% (${App.money(price - cost)} per unit)`).css('color', margin < 0 ? 'var(--danger)' : '');
        } else {
            $('#margin-hint').text('');
        }
    }
    $('#p-purchase, #p-selling').on('input', updateMargin);

    // Client-side checks for instant feedback; the PHP API validates everything again.
    function validate(data) {
        const errors = {};
        if (!/^[A-Za-z0-9][A-Za-z0-9-]{1,39}$/.test(data.sku)) errors.sku = 'Use 2–40 letters, numbers or dashes.';
        if (!data.category_id) errors.category_id = 'Choose a category.';
        if (data.name.trim().length < 2) errors.name = 'Name must be at least 2 characters.';
        ['purchase_price', 'selling_price'].forEach((k) => {
            if (data[k] === '' || isNaN(data[k]) || Number(data[k]) < 0) errors[k] = 'Enter a valid amount (0 or more).';
        });
        if (!errors.selling_price && !errors.purchase_price && Number(data.selling_price) < Number(data.purchase_price)) {
            errors.selling_price = 'Selling price cannot be lower than the purchase price.';
        }
        if (data.reorder_level === '' || !/^\d+$/.test(data.reorder_level)) errors.reorder_level = 'Enter a whole number (0 or more).';
        if (data.quantity !== undefined && data.quantity !== '' && !/^\d+$/.test(data.quantity)) errors.quantity = 'Enter a whole number (0 or more).';
        return errors;
    }

    $('#add-product').on('click', () => openForm(null));

    $form.on('submit', function (e) {
        e.preventDefault();
        const $btn = $form.find('[type=submit]');
        if ($btn.prop('disabled')) return;   // already saving

        const data = App.formData($form);
        const isEdit = !!data.id;
        if (isEdit) delete data.quantity; else delete data.id;
        data.sku = data.sku.trim().toUpperCase();

        const errors = validate(data);
        if (Object.keys(errors).length) return App.showErrors($form, errors);

        data.action = isEdit ? 'update' : 'create';
        App.busy($btn, true);
        App.api('POST', 'api/products.php', data)
            .done((res) => {
                App.toast('success', res.message);
                App.modal.close('#product-modal');
                load();
            })
            .fail((err) => {
                App.showErrors($form, err.errors);
                App.toast('error', err.message);
            })
            .always(() => App.busy($btn, false));
    });

    // ---- Row actions (event delegation: rows are created dynamically) ----
    $table.on('click', '[data-action]', function () {
        const $btn = $(this);
        const id = $btn.data('id');
        const action = $btn.data('action');

        if (action === 'view') return viewProduct(id);

        if (action === 'edit') {
            App.busy($btn, true);
            App.api('GET', 'api/products.php', { id })
                .done((res) => openForm(res.data.product))
                .fail((err) => App.toast('error', err.message))
                .always(() => App.busy($btn, false));
            return;
        }

        const name = $btn.data('name');
        const prompts = {
            archive: { title: 'Archive product?', message: `“${name}” will be hidden from sales but its history is kept. You can restore it later.`, confirmText: 'Archive', danger: true },
            delete: { title: 'Delete product permanently?', message: `“${name}” will be deleted. This only works for products that were never sold or moved.`, confirmText: 'Delete', danger: true },
            restore: null,
        };
        const run = () => {
            App.busy($btn, true);
            App.api('POST', 'api/products.php', { action, id })
                .done((res) => { App.toast('success', res.message); load(); })
                .fail((err) => { App.toast('error', err.message); App.busy($btn, false); });
        };
        prompts[action] ? App.confirm(prompts[action]).then((ok) => ok && run()) : run();
    });

    function viewProduct(id) {
        $('#view-body').html('<div class="loading-block"><span class="spinner"></span> Loading…</div>');
        App.modal.open('#view-modal');
        App.api('GET', 'api/products.php', { id })
            .done((res) => {
                const p = res.data.product, s = res.data.sales_summary;
                $('#view-title').text(p.name);
                const moves = res.data.recent_movements.length
                    ? res.data.recent_movements.map((m) => `<tr><td>${App.dateTime(m.created_at)}</td><td>${App.movementBadge(m.movement_type)}</td>
                        <td class="num">${App.signed(m.quantity)}</td><td class="num">${m.previous_quantity} → ${m.resulting_quantity}</td>
                        <td>${esc(m.user_name)}</td><td>${esc(m.transaction_number || m.notes || '')}</td></tr>`).join('')
                    : '<tr><td colspan="6" class="muted">No movements yet.</td></tr>';
                $('#view-body').html(`
                    <dl class="detail-grid">
                        <div><dt>SKU</dt><dd class="mono">${esc(p.sku)}</dd></div>
                        <div><dt>Category</dt><dd>${esc(p.category_name)}</dd></div>
                        <div><dt>Purchase price</dt><dd>${App.money(p.purchase_price)}</dd></div>
                        <div><dt>Selling price</dt><dd>${App.money(p.selling_price)}</dd></div>
                        <div><dt>In stock</dt><dd>${App.number(p.quantity)} ${App.stockBadge(p.quantity, p.reorder_level)}</dd></div>
                        <div><dt>Reorder level</dt><dd>${p.reorder_level}</dd></div>
                        <div><dt>Stock value (cost)</dt><dd>${App.money(p.stock_value_at_cost)}</dd></div>
                        <div><dt>Status</dt><dd>${p.is_active ? 'Active' : 'Archived'}</dd></div>
                        <div><dt>Units sold (all time)</dt><dd>${App.number(s.units_sold)}</dd></div>
                        <div><dt>Revenue (all time)</dt><dd>${App.money(s.revenue)}</dd></div>
                        <div><dt>Last sale</dt><dd>${App.dateTime(s.last_sale_at)}</dd></div>
                        <div><dt>Last updated</dt><dd>${App.dateTime(p.updated_at)}</dd></div>
                    </dl>
                    ${p.description ? `<p style="margin-top:14px">${esc(p.description)}</p>` : ''}
                    <h3 style="margin:20px 0 8px">Recent stock movements</h3>
                    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Type</th><th class="num">Change</th><th class="num">Qty</th><th>By</th><th>Reference</th></tr></thead>
                    <tbody>${moves}</tbody></table></div>`);
            })
            .fail((err) => $('#view-body').html(`<div class="alert alert-error">${esc(err.message)}</div>`));
    }

    // Allow links like products.php?stock=low or ?category_id=3 from other pages
    const params = new URLSearchParams(window.location.search);
    if (params.get('stock')) $('#f-stock').val(params.get('stock'));

    loadCategories().always(() => {
        if (params.get('category_id')) $('#f-category').val(params.get('category_id'));
        load();
    });
});
