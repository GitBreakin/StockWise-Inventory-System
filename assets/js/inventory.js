/** inventory.js — low-stock alerts, stock in/out/adjust and movement history (AJAX + JSON). */
$(function () {
    const esc = App.esc;
    const $form = $('#stock-form');
    const $results = $('#product-results');
    let mode = 'stock_in';
    let selected = null;
    let page = 1;

    const help = {
        stock_in: 'Add received stock (e.g. a supplier delivery).',
        stock_out: 'Remove stock that left without a sale (damaged, expired, returned…).',
        adjustment: 'Set the quantity to what was physically counted. The difference is recorded.',
    };
    const buttonText = { stock_in: 'Record stock in', stock_out: 'Record stock out', adjustment: 'Save adjustment' };

    // ---- Mode tabs ----
    function setMode(m) {
        mode = m;
        $('.tab').removeClass('active').filter(`[data-mode="${m}"]`).addClass('active');
        $('#mode-help').text(help[m]);
        $form.find('[data-for]').each(function () {
            const show = $(this).data('for').split(' ').includes(m);
            $(this).prop('hidden', !show).find('input,select').prop('disabled', !show);
        });
        $('#notes-req').prop('hidden', m !== 'adjustment');
        $('#s-notes').attr('placeholder', {
            stock_in: 'e.g. Supplier delivery receipt no. 1234',
            stock_out: 'e.g. Box crushed during delivery',
            adjustment: 'e.g. Monthly shelf count by Maria',
        }[m]);
        $form.find('[type=submit]').text(buttonText[m]);
        App.clearErrors($form);
        updatePreview();
    }
    $('.tabs').on('click', '.tab', function () { setMode($(this).data('mode')); });

    // ---- Product search (AJAX autocomplete) ----
    $('#product-search').on('input', App.debounce(function () {
        const q = $(this).val().trim();
        if (q.length < 1) { $results.prop('hidden', true); return; }
        App.api('GET', 'api/products.php', { lookup: 1, q }).done((res) => {
            $results.html(res.data.items.length
                ? res.data.items.map((p) => `<button type="button" class="product-pick" data-product='${esc(JSON.stringify(p))}'>
                    <span class="grow"><strong>${esc(p.name)}</strong><br><small class="mono">${esc(p.sku)}</small></span>
                    <span>${p.quantity} in stock</span></button>`).join('')
                : '<div class="empty-state">No matching active products.</div>').prop('hidden', false);
        });
    }, 250));

    $results.on('click', '.product-pick', function () {
        selectProduct($(this).data('product'));   // jQuery parses the JSON data attribute
    });
    $(document).on('click', (e) => { if (!$(e.target).closest('#product-search, #product-results').length) $results.prop('hidden', true); });

    function selectProduct(p) {
        selected = p;
        $form.find('[name=product_id]').val(p.id);
        $('#product-search').val(`${p.name} (${p.sku})`).removeClass('is-invalid');
        $form.find('[name=product_id]').removeClass('is-invalid').next('.field-error').remove();
        $results.prop('hidden', true);
        $('#selected-product').html(`<strong>${esc(p.name)}</strong> — currently <strong>${p.quantity}</strong> in stock (reorder at ${p.reorder_level})`).prop('hidden', false);
        updatePreview();
    }

    function updatePreview() {
        const counted = $('#s-counted').val();
        $('#adjust-preview').text(selected && counted !== '' && mode === 'adjustment'
            ? `Change: ${App.signed(Number(counted) - selected.quantity)} (from ${selected.quantity} to ${counted})` : '');
    }
    $('#s-counted').on('input', updatePreview);

    // ---- Submit stock change ----
    $form.on('submit', function (e) {
        e.preventDefault();
        const $btn = $form.find('[type=submit]');
        if ($btn.prop('disabled')) return;
        const data = App.formData($form);   // disabled (hidden) fields are skipped automatically
        data.action = mode;

        const errors = {};
        if (!data.product_id) errors.product_id = 'Choose a product.';
        if (mode !== 'adjustment' && !/^[1-9]\d*$/.test(data.quantity || '')) errors.quantity = 'Enter a whole number greater than 0.';
        if (mode === 'stock_out' && selected && Number(data.quantity) > selected.quantity) errors.quantity = `Only ${selected.quantity} in stock.`;
        if (mode === 'adjustment' && !/^\d+$/.test(data.counted_quantity || '')) errors.counted_quantity = 'Enter the counted quantity (0 or more).';
        if (mode !== 'stock_in' && !data.reason) errors.reason = 'Choose a reason.';
        if (mode === 'adjustment' && !data.notes.trim()) errors.notes = 'Explain the adjustment.';
        if (Object.keys(errors).length) {
            App.showErrors($form, errors);
            if (errors.product_id) $('#product-search').addClass('is-invalid').trigger('focus');
            return;
        }

        App.busy($btn, true);
        App.api('POST', 'api/inventory.php', data)
            .done((res) => {
                App.toast('success', res.message);
                selected.quantity = res.data.resulting_quantity;
                selectProduct(selected);
                $form.find('[name=quantity], [name=counted_quantity], [name=notes]').val('');
                $form.find('select').val('');
                updatePreview();
                loadLowStock();
                page = 1; loadMovements();
            })
            .fail((err) => {
                App.showErrors($form, err.errors);
                App.toast('error', err.message);
            })
            .always(() => App.busy($btn, false));
    });

    // ---- Low-stock alerts ----
    function loadLowStock() {
        const $tb = $('#low-stock-table tbody').html(App.loadingRow(6));
        App.api('GET', 'api/inventory.php', { view: 'low_stock' })
            .done((res) => {
                const items = res.data.items;
                $('[data-low-stock-badge]').text(items.length).prop('hidden', items.length === 0);
                if (!items.length) {
                    $tb.html(App.emptyRow(6, 'No low-stock products', ' Everything is above its reorder level.'));
                    return;
                }
                $tb.html(items.map((p) => `<tr>
                    <td data-label="Product"><strong>${esc(p.name)}</strong><br><small class="mono muted">${esc(p.sku)}</small></td>
                    <td data-label="On hand" class="num">${p.quantity}</td>
                    <td data-label="Reorder at" class="num">${p.reorder_level}</td>
                    <td data-label="Suggested" class="num" title="${esc(p.suggestion_basis)}">${p.suggested_quantity !== null ? '+' + p.suggested_quantity : '—'}
                        ${p.estimated_cost ? `<br><small class="muted">≈ ${App.money(p.estimated_cost)}</small>` : ''}</td>
                    <td data-label="Status">${App.stockBadge(p.quantity, p.reorder_level)}</td>
                    <td data-label=""><button type="button" class="btn btn-secondary btn-sm" data-restock='${esc(JSON.stringify({ id: p.id, sku: p.sku, name: p.name, quantity: p.quantity, reorder_level: p.reorder_level, suggested: p.suggested_quantity }))}'>Restock</button></td>
                </tr>`).join(''));
            })
            .fail((err) => $tb.html(App.errorRow(6, err.message)));
    }

    // "Restock" fills the stock-in form with the suggested quantity
    $('#low-stock-table').on('click', '[data-restock]', function () {
        const p = $(this).data('restock');
        setMode('stock_in');
        selectProduct(p);
        $('#s-quantity').val(p.suggested || '').trigger('focus');
        $('html, body').animate({ scrollTop: $('#stock-panel').offset().top - 80 }, 250);
    });

    // ---- Movement history ----
    function loadMovements() {
        const $tb = $('#movement-table tbody').html(App.loadingRow(7));
        const params = $.extend(App.formData($('#movement-filters')), { page, per_page: 15 });
        App.api('GET', 'api/inventory.php', params)
            .done((res) => {
                const items = res.data.items;
                $tb.html(items.length ? items.map((m) => `<tr>
                    <td data-label="Date" class="nowrap">${App.dateTime(m.created_at)}</td>
                    <td data-label="Product"><strong>${esc(m.product_name)}</strong><br><small class="mono muted">${esc(m.sku)}</small></td>
                    <td data-label="Type">${App.movementBadge(m.movement_type)}</td>
                    <td data-label="Change" class="num" style="color:${m.quantity > 0 ? 'var(--success)' : 'var(--danger)'}">${App.signed(m.quantity)}</td>
                    <td data-label="Before → After" class="num">${m.previous_quantity} → ${m.resulting_quantity}</td>
                    <td data-label="By">${esc(m.user_name)}</td>
                    <td data-label="Notes">${m.transaction_number ? `<span class="mono">${esc(m.transaction_number)}</span><br>` : ''}<small>${esc(m.notes || '')}</small></td>
                </tr>`).join('') : App.emptyRow(7, 'No movements found', ' Try a different filter or date range.'));
                App.pagination($('#movement-pagination'), res.data.pagination, (p) => { page = p; loadMovements(); });
            })
            .fail((err) => {
                $tb.html(App.errorRow(7, err.message));
                App.showErrors($('#movement-filters'), err.errors);
            });
    }

    $('#movement-filters')
        .on('input', '#m-search', App.debounce(() => { page = 1; loadMovements(); }, 350))
        .on('change', 'select, input[type=date]', () => { page = 1; loadMovements(); })
        .on('submit', (e) => { e.preventDefault(); page = 1; loadMovements(); });

    setMode('stock_in');
    loadLowStock();
    loadMovements();
});
