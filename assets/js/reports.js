/** reports.js — builds report requests, renders the JSON result as a table, exports CSV. */
$(function () {
    const esc = App.esc;
    const $form = $('#report-form');
    const moneyCols = ['gross_sales', 'discounts', 'net_sales', 'average_sale', 'revenue', 'cost_at_current_price', 'value_at_cost',
        'value_at_retail', 'potential_profit', 'stock_value_at_cost', 'estimated_cost', 'unit_price', 'line_total', 'subtotal', 'discount', 'total_amount'];
    const numCols = moneyCols.concat(['transactions', 'units_sold', 'products', 'units', 'quantity', 'previous_quantity', 'resulting_quantity',
        'reorder_level', 'sold_last_30_days', 'suggested_quantity', 'sold_in_period', 'days_since_last_sale', 'days_of_stock']);

    function toggleFields() {
        const type = $('#r-type').val();
        $form.find('[data-for]').each(function () {
            const show = $(this).data('for').split(' ').includes(type);
            $(this).prop('hidden', !show).find('input,select').addBack('input,select').prop('disabled', !show);
        });
    }

    function setRange(range) {
        let from;
        if (range === 'today') from = App.today();
        else if (range === 'month') from = App.today().slice(0, 8) + '01';
        else from = App.today(-(Number(range) - 1));
        $('#r-from').val(from);
        $('#r-to').val(App.today());
    }

    function params() {
        return App.formData($form);   // disabled (irrelevant) fields are left out
    }

    function validDates() {
        const from = $('#r-from').val(), to = $('#r-to').val();
        if ($('#r-from').prop('disabled') || !from || !to || from <= to) return true;
        App.showErrors($form, { to: 'End date must be on or after the start date.' });
        return false;
    }

    function cell(key, value) {
        if (value === null || value === undefined || value === '') return '—';
        if (moneyCols.includes(key)) return App.money(value);
        if (key === 'movement_type') return App.movementBadge(value);
        if (key === 'status' && ['completed', 'voided'].includes(value)) return `<span class="badge ${value === 'completed' ? 'badge-success' : 'badge-danger'}">${esc(value)}</span>`;
        if (key === 'classification') return `<span class="badge ${value === 'No sales' ? 'badge-danger' : 'badge-warning'}">${esc(value)}</span>`;
        if (key === 'period_start') return App.date(value);
        if (key === 'quantity' && typeof value === 'number' && $('#r-type').val() === 'movements') return App.signed(value);
        return esc(typeof value === 'number' ? App.number(value) : value);
    }

    function render(r) {
        $('#report-title').text(r.title);
        $('#report-meta').text((r.range ? `${App.date(r.range.from)} – ${App.date(r.range.to)} · ` : '') + `Generated ${r.generated_at} · ${r.rows.length} row(s)`);
        const cols = Object.entries(r.columns);
        $('#report-table thead').html('<tr>' + cols.map(([k, label]) => `<th class="${numCols.includes(k) ? 'num' : ''}">${esc(label)}</th>`).join('') + '</tr>');

        let extra = '';
        if (r.type === 'slow_moving') {
            const a = r.analysis;
            extra = `<div class="card-body" style="display:grid;gap:12px">
                <div class="rules-box"><strong>How products are classified.</strong> ${esc(a.rules.description)}
                “Days of stock” = quantity on hand ÷ average daily sales in the period. ${esc(a.disclaimer)}</div>
                <div class="muted">${a.analyzed_count} product(s) had enough history to analyze · ${a.insufficient_data.length} need more history and were not judged.</div>
                ${a.slow_moving.map((p) => `<div class="card insight"><strong>${esc(p.name)}</strong> <span class="mono muted">${esc(p.sku)}</span>
                    <span class="badge ${p.classification === 'no_sales' ? 'badge-danger' : 'badge-warning'}">${p.classification === 'no_sales' ? 'No sales' : 'Slow'}</span>
                    <div class="muted" style="font-size:.85rem">${p.quantity} on hand (${App.money(p.stock_value_at_cost)} at cost) · ${p.sold_in_period} sold ·
                    ${p.days_since_last_sale === null ? 'never sold' : 'last sale ' + App.daysAgo(p.days_since_last_sale)}</div>
                    <ul>${p.recommendations.map((x) => `<li>${esc(x)}</li>`).join('')}</ul></div>`).join('')}
            </div>`;
        }
        if (r.type === 'movements' && r.summary && r.summary.length) {
            extra = `<div class="card-body" style="display:flex;flex-wrap:wrap;gap:10px">${r.summary.map((s) =>
                `<div class="stat" style="min-width:150px"><span class="stat-label">${App.movementBadge(s.movement_type)}</span>
                <span class="stat-value">${App.signed(s.net_quantity)}</span><span class="stat-sub">${s.entries} entries</span></div>`).join('')}</div>`;
        }
        if (r.type === 'sales' && r.totals) {
            extra = `<div class="card-body"><div class="stat-grid">
                <div class="stat"><span class="stat-label">Net sales</span><span class="stat-value">${App.money(r.totals.net_sales)}</span><span class="stat-sub">after ${App.money(r.totals.discounts)} discounts</span></div>
                <div class="stat info"><span class="stat-label">Transactions</span><span class="stat-value">${App.number(r.totals.transactions)}</span><span class="stat-sub">${r.totals.voided_transactions} voided (excluded)</span></div>
                <div class="stat"><span class="stat-label">Units sold</span><span class="stat-value">${App.number(r.totals.units_sold)}</span><span class="stat-sub">&nbsp;</span></div>
            </div></div>`;
        }
        $('#report-extra').html(extra);

        $('#report-table tbody').html(r.rows.length
            ? r.rows.map((row) => '<tr>' + cols.map(([k, label]) => `<td data-label="${esc(label)}" class="${numCols.includes(k) ? 'num' : ''}">${cell(k, row[k])}</td>`).join('') + '</tr>').join('')
            : App.emptyRow(cols.length, 'No records for this report', ' Try a wider date range.'));

        $('#report-table tfoot').html(r.totals && r.rows.length
            ? '<tr>' + cols.map(([k], i) => `<td class="${numCols.includes(k) ? 'num' : ''}">${i === 0 ? 'TOTAL' : (r.totals[k] !== undefined ? cell(k, r.totals[k]) : '')}</td>`).join('') + '</tr>'
            : '');
    }

    function generate() {
        App.clearErrors($form);
        if (!validDates()) return;
        const $btn = $form.find('[type=submit]');
        App.busy($btn, true);
        $('#report-table tbody').html(App.loadingRow(6, 'Generating report…'));
        $('#report-table tfoot').empty();
        App.api('GET', 'api/reports.php', params())
            .done((res) => render(res.data))
            .fail((err) => {
                App.showErrors($form, err.errors);
                $('#report-table tbody').html(App.errorRow(6, err.message));
                App.toast('error', err.message);
            })
            .always(() => App.busy($btn, false));
    }

    $form.on('submit', (e) => { e.preventDefault(); generate(); });
    $('#r-type').on('change', () => { toggleFields(); generate(); });
    $('#r-group, #r-limit, #r-mtype, #r-status').on('change', generate);
    $('#quick-range').on('click', 'button', function () {
        $(this).addClass('active').siblings().removeClass('active');
        setRange(String($(this).data('range')));
        generate();
    });
    $('#r-from, #r-to').on('change', () => $('#quick-range button').removeClass('active'));

    $('#export-csv').on('click', function () {
        App.clearErrors($form);
        if (!validDates()) return;
        App.download('api/reports.php', $.extend(params(), { format: 'csv' }), $(this));
    });

    // Open a specific report from a link, e.g. reports.php?type=slow_moving
    const wanted = new URLSearchParams(window.location.search).get('type');
    if (wanted && $(`#r-type option[value="${wanted.replace(/[^a-z_]/g, '')}"]`).length) $('#r-type').val(wanted);
    setRange('30');
    toggleFields();
    generate();
});
