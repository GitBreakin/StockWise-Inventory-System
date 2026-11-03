/** dashboard.js — loads live statistics with AJAX and renders them with jQuery. */
$(function () {
    const esc = App.esc;
    const periodNames = { today: 'today', week: 'the last 7 days', month: 'this month', '30d': 'the last 30 days' };

    function stat(label, value, sub, cls = '') {
        return `<div class="stat ${cls}"><span class="stat-label">${esc(label)}</span>
                <span class="stat-value">${value}</span><span class="stat-sub">${sub}</span></div>`;
    }

    function render(d) {
        const s = d.sales, inv = d.inventory;
        let trend = '<span class="muted">No sales in the previous period to compare</span>';
        if (s.change_percent !== null) {
            const up = s.change_percent >= 0;
            trend = `<span class="${up ? 'trend-up' : 'trend-down'}">${up ? '▲' : '▼'} ${Math.abs(s.change_percent)}%</span> vs previous period`;
        }
        $('#period-label').text(`Showing ${periodNames[d.period.key]} (${App.date(d.period.start)} – ${App.date(d.period.end)})`);

        $('#stats').html(
            stat('Sales', App.money(s.total), trend) +
            stat('Transactions', App.number(s.transactions), `${App.number(s.units_sold)} units · avg ${App.money(s.average)}`, 'info') +
            stat('Products', App.number(inv.active_products), `${App.number(inv.units_in_stock)} units in stock${inv.archived_products ? ` · ${inv.archived_products} archived` : ''}`) +
            stat('Categories', App.number(inv.categories), 'Product groups') +
            stat('Inventory value', App.money(inv.value_at_cost), `At cost · ${App.money(inv.value_at_retail)} at retail`, 'info') +
            stat('Low stock', App.number(inv.low_stock + inv.out_of_stock), `${inv.out_of_stock} out of stock · ${inv.low_stock} low`, (inv.low_stock + inv.out_of_stock) ? 'danger' : '')
        );

        // Bar chart (plain HTML bars sized with jQuery)
        const max = Math.max(...d.chart.map((c) => Number(c.total)), 1);
        const total14 = d.chart.reduce((sum, c) => sum + Number(c.total), 0);
        $('#chart-total').text(App.money(total14) + ' total');
        const $chart = $('#sales-chart').empty();
        d.chart.forEach((c) => {
            const h = Math.round((Number(c.total) / max) * 150);
            const label = new Date(c.day + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
            const $col = $('<div class="bar-col"></div>');
            $('<div class="bar"></div>')
                .toggleClass('is-zero', Number(c.total) === 0)
                .attr('data-tip', `${label}: ${App.money(c.total)} (${c.transactions} sales)`)
                .css('height', Math.max(h, 2)).appendTo($col);
            $('<span class="bar-label"></span>').text(label.replace(' ', ' ')).appendTo($col);
            $chart.append($col);
        });

        $('#top-period').text(periodNames[d.period.key]);
        $('#top-sellers').html(d.top_sellers.length
            ? d.top_sellers.map((t, i) => `<li><span class="badge badge-primary">#${i + 1}</span>
                <div class="grow"><div class="title">${esc(t.name)}</div><div class="meta mono">${esc(t.sku)}</div></div>
                <div class="text-right"><strong>${App.number(t.units_sold)}</strong> sold<div class="meta">${App.money(t.revenue)}</div></div></li>`).join('')
            : `<li class="empty-state"><strong>No sales yet in this period</strong>Record a sale to see top sellers.</li>`);

        $('#low-stock').html(d.low_stock.length
            ? d.low_stock.map((p) => `<li>
                <div class="grow"><div class="title">${esc(p.name)}</div>
                <div class="meta"><span class="mono">${esc(p.sku)}</span> · reorder at ${p.reorder_level}${p.suggested_quantity !== null ? ` · suggest +${p.suggested_quantity}` : ''}</div></div>
                <div class="text-right"><strong>${p.quantity}</strong> left<div>${App.stockBadge(p.quantity, p.reorder_level)}</div></div></li>`).join('')
            : `<li class="empty-state"><strong>All stocked up</strong>No product is at or below its reorder level.</li>`);

        const sm = d.slow_moving;
        let slowHtml;
        if (sm.items.length) {
            slowHtml = sm.items.map((p) => `<div class="insight">
                <div style="display:flex;gap:10px;align-items:center">
                    <strong class="grow" style="flex:1">${esc(p.name)}</strong>
                    <span class="badge ${p.classification === 'no_sales' ? 'badge-danger' : 'badge-warning'}">${p.classification === 'no_sales' ? 'No sales' : 'Slow'}</span>
                </div>
                <div class="meta muted" style="font-size:.82rem">${p.quantity} on hand · ${p.sold_in_period} sold in ${sm.rules.period_days} days ·
                    ${p.days_since_last_sale === null ? 'never sold' : 'last sale ' + App.daysAgo(p.days_since_last_sale)}</div>
                <ul><li>${esc(p.recommendations[0])}</li></ul></div>`).join('');
            if (sm.count > sm.items.length) slowHtml += `<div class="card-footer muted">+${sm.count - sm.items.length} more in the full analysis</div>`;
        } else if (sm.insufficient_data > 0) {
            slowHtml = `<div class="empty-state"><strong>Not enough sales history yet</strong>
                ${sm.insufficient_data} product(s) need at least ${sm.rules.period_days} days of recorded sales before they can be judged.</div>`;
        } else {
            slowHtml = `<div class="empty-state"><strong>No slow movers</strong>Every product with stock is selling at a healthy pace.</div>`;
        }
        $('#slow-moving').html(slowHtml);

        $('#recent-sales').html(d.recent_sales.length
            ? d.recent_sales.map((s) => `<li><div class="grow"><div class="title mono">${esc(s.transaction_number)}</div>
                <div class="meta">${App.dateTime(s.created_at)} · ${esc(s.cashier)} · ${esc(s.payment_method)}</div></div>
                <div class="text-right"><strong>${App.money(s.total_amount)}</strong>
                ${s.status === 'voided' ? '<div><span class="badge badge-danger">Voided</span></div>' : ''}</div></li>`).join('')
            : `<li class="empty-state"><strong>No sales recorded yet</strong></li>`);

        $('#recent-moves').html(d.recent_movements.length
            ? d.recent_movements.map((m) => `<li><div class="grow"><div class="title">${esc(m.product_name)}</div>
                <div class="meta">${App.dateTime(m.created_at)} · ${esc(m.user_name)}</div></div>
                <div class="text-right">${App.movementBadge(m.movement_type)}<div class="meta">${App.signed(m.quantity)} → ${m.resulting_quantity}</div></div></li>`).join('')
            : `<li class="empty-state"><strong>No stock movements yet</strong></li>`);
    }

    function load(period) {
        $('#stats, .card').addClass('is-loading');
        App.api('GET', 'api/dashboard.php', { period })
            .done((res) => render(res.data))
            .fail((err) => {
                App.toast('error', err.message);
                $('#period-label').text('Could not load the dashboard. ' + err.message);
            })
            .always(() => $('#stats, .card').removeClass('is-loading'));
    }

    $('#period-filter').on('click', 'button', function () {
        $(this).addClass('active').siblings().removeClass('active');
        load($(this).data('period'));
    });

    load('today');
});
