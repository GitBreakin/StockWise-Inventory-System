<?php
/**
 * Reports API — every report is calculated from database records.
 *
 * GET api/reports.php?type=sales&from=YYYY-MM-DD&to=YYYY-MM-DD&group=day|week|month
 * GET api/reports.php?type=best_sellers&from=&to=&limit=10
 * GET api/reports.php?type=slow_moving&period_days=30&coverage_days=90
 * GET api/reports.php?type=valuation
 * GET api/reports.php?type=movements&from=&to=&movement_type=
 * GET api/reports.php?type=low_stock
 * GET api/reports.php?type=transactions&from=&to=&status=
 *
 * Add &format=csv to any report to download it as a CSV file.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';
$user = api_bootstrap();
$pdo = db();

$v = new Validator($_GET);
$type = $v->enum('type', ['sales', 'best_sellers', 'slow_moving', 'valuation', 'movements', 'low_stock', 'transactions'], true, null, 'Report type');
$format = $v->enum('format', ['json', 'csv'], false, 'json', 'Format');

// Default date range: last 30 days
[$from, $to] = validate_date_range($v);
$from ??= (new DateTimeImmutable('today -29 days'))->format('Y-m-d');
$to ??= (new DateTimeImmutable('today'))->format('Y-m-d');
if ($from > $to) {
    $v->error('to', 'End date must be on or after the start date.');
}
$v->abortIfInvalid();
$dates = ['from' => $from, 'to' => $to];
$dateFilter = 's.created_at >= CAST(:from AS date) AND s.created_at < CAST(:to AS date) + 1';

$report = match ($type) {
    // -----------------------------------------------------------------
    'sales' => (function () use ($pdo, $v, $dates, $dateFilter) {
        $group = $v->enum('group', ['day', 'week', 'month'], false, 'day', 'Grouping');
        $v->abortIfInvalid();
        // $group is from a whitelist, so it is safe inside date_trunc().
        $stmt = $pdo->prepare(
            "SELECT to_char(date_trunc('$group', s.created_at), 'YYYY-MM-DD') AS period_start,
                    COUNT(*)::int AS transactions,
                    SUM(s.subtotal)::numeric(14,2) AS gross_sales,
                    SUM(s.discount)::numeric(14,2) AS discounts,
                    SUM(s.total_amount)::numeric(14,2) AS net_sales,
                    ROUND(AVG(s.total_amount), 2) AS average_sale,
                    SUM((SELECT SUM(quantity) FROM public.sale_items WHERE sale_id = s.id))::int AS units_sold
             FROM public.sales s
             WHERE s.status = 'completed' AND $dateFilter
             GROUP BY 1 ORDER BY 1"
        );
        $stmt->execute($dates);
        $rows = $stmt->fetchAll();

        $totals = ['transactions' => 0, 'gross_sales' => 0, 'discounts' => 0, 'net_sales' => 0, 'units_sold' => 0];
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += (float) $r[$k];
            }
        }
        foreach (['gross_sales', 'discounts', 'net_sales'] as $k) {
            $totals[$k] = money($totals[$k]);
        }
        $totals['transactions'] = (int) $totals['transactions'];
        $totals['units_sold'] = (int) $totals['units_sold'];
        $voided = $pdo->prepare("SELECT COUNT(*)::int FROM public.sales s WHERE s.status = 'voided' AND $dateFilter");
        $voided->execute($dates);
        $totals['voided_transactions'] = (int) $voided->fetchColumn();

        return [
            'title'   => ucfirst($group === 'day' ? 'daily' : $group . 'ly') . ' sales report',
            'columns' => ['period_start' => 'Period start', 'transactions' => 'Transactions', 'units_sold' => 'Units sold',
                'gross_sales' => 'Gross sales', 'discounts' => 'Discounts', 'net_sales' => 'Net sales', 'average_sale' => 'Average sale'],
            'rows'    => $rows,
            'totals'  => $totals,
        ];
    })(),

    // -----------------------------------------------------------------
    'best_sellers' => (function () use ($pdo, $v, $dates, $dateFilter) {
        $limit = $v->int('limit', 1, 100, false, 'Limit') ?? 10;
        $v->abortIfInvalid();
        $stmt = $pdo->prepare(
            "SELECT p.sku, p.name, c.name AS category, SUM(si.quantity)::int AS units_sold,
                    SUM(si.line_total)::numeric(14,2) AS revenue,
                    SUM(si.quantity * p.purchase_price)::numeric(14,2) AS cost_at_current_price,
                    COUNT(DISTINCT s.id)::int AS transactions
             FROM public.sale_items si
             JOIN public.sales s ON s.id = si.sale_id
             JOIN public.products p ON p.id = si.product_id
             JOIN public.categories c ON c.id = p.category_id
             WHERE s.status = 'completed' AND $dateFilter
             GROUP BY p.id, c.name
             ORDER BY units_sold DESC, revenue DESC
             LIMIT :limit"
        );
        $stmt->bindValue('from', $dates['from']);
        $stmt->bindValue('to', $dates['to']);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return [
            'title'   => 'Best-selling products',
            'columns' => ['sku' => 'SKU', 'name' => 'Product', 'category' => 'Category', 'units_sold' => 'Units sold',
                'transactions' => 'Transactions', 'revenue' => 'Revenue', 'cost_at_current_price' => 'Cost (current price)'],
            'rows'    => $stmt->fetchAll(),
        ];
    })(),

    // -----------------------------------------------------------------
    'slow_moving' => (function () use ($pdo, $v) {
        $period = $v->int('period_days', 7, 365, false, 'Analysis period') ?? 30;
        $coverage = $v->int('coverage_days', 14, 730, false, 'Coverage threshold') ?? 90;
        $v->abortIfInvalid();
        $a = slow_moving_analysis($pdo, $period, $coverage);
        $rows = array_map(fn($r) => [
            'sku' => $r['sku'], 'name' => $r['name'], 'category' => $r['category'],
            'classification' => $r['classification'] === 'no_sales' ? 'No sales' : 'Slow',
            'quantity' => $r['quantity'], 'sold_in_period' => $r['sold_in_period'],
            'days_since_last_sale' => $r['days_since_last_sale'] ?? 'Never sold',
            'days_of_stock' => $r['days_of_stock'] ?? '—',
            'stock_value_at_cost' => $r['stock_value_at_cost'],
            'recommendation' => implode(' ', $r['recommendations']),
        ], $a['slow_moving']);
        return [
            'title'   => 'Slow-moving products',
            'columns' => ['sku' => 'SKU', 'name' => 'Product', 'category' => 'Category', 'classification' => 'Status',
                'quantity' => 'On hand', 'sold_in_period' => "Sold (last $period days)", 'days_since_last_sale' => 'Days since last sale',
                'days_of_stock' => 'Days of stock', 'stock_value_at_cost' => 'Value at cost', 'recommendation' => 'Recommendation'],
            'rows'    => $rows,
            'analysis' => $a,
        ];
    })(),

    // -----------------------------------------------------------------
    'valuation' => (function () use ($pdo) {
        $rows = $pdo->query(
            "SELECT c.name AS category, COUNT(p.id)::int AS products, COALESCE(SUM(p.quantity), 0)::int AS units,
                    COALESCE(SUM(p.quantity * p.purchase_price), 0)::numeric(14,2) AS value_at_cost,
                    COALESCE(SUM(p.quantity * p.selling_price), 0)::numeric(14,2) AS value_at_retail,
                    COALESCE(SUM(p.quantity * (p.selling_price - p.purchase_price)), 0)::numeric(14,2) AS potential_profit
             FROM public.categories c
             LEFT JOIN public.products p ON p.category_id = c.id AND p.is_active
             GROUP BY c.id ORDER BY value_at_cost DESC, c.name"
        )->fetchAll();
        $totals = ['products' => 0, 'units' => 0, 'value_at_cost' => 0, 'value_at_retail' => 0, 'potential_profit' => 0];
        foreach ($rows as $r) {
            foreach ($totals as $k => $_) {
                $totals[$k] += (float) $r[$k];
            }
        }
        foreach (['value_at_cost', 'value_at_retail', 'potential_profit'] as $k) {
            $totals[$k] = money($totals[$k]);
        }
        $totals['products'] = (int) $totals['products'];
        $totals['units'] = (int) $totals['units'];
        return [
            'title'   => 'Inventory valuation (active products, as of now)',
            'columns' => ['category' => 'Category', 'products' => 'Products', 'units' => 'Units',
                'value_at_cost' => 'Value at cost', 'value_at_retail' => 'Value at retail', 'potential_profit' => 'Potential profit'],
            'rows'    => $rows,
            'totals'  => $totals,
        ];
    })(),

    // -----------------------------------------------------------------
    'movements' => (function () use ($pdo, $v, $dates) {
        $mtype = $v->enum('movement_type', ['stock_in', 'stock_out', 'adjustment', 'sale', 'sale_void', 'initial'], false, null, 'Movement type');
        $v->abortIfInvalid();
        $sql = "SELECT to_char(m.created_at, 'YYYY-MM-DD HH24:MI') AS date, p.sku, p.name AS product, m.movement_type,
                       m.quantity, m.previous_quantity, m.resulting_quantity, u.full_name AS user_name, m.notes
                FROM public.stock_movements m
                JOIN public.products p ON p.id = m.product_id
                JOIN public.users u ON u.id = m.user_id
                WHERE m.created_at >= CAST(:from AS date) AND m.created_at < CAST(:to AS date) + 1";
        $params = $dates;
        if ($mtype !== null) {
            $sql .= ' AND m.movement_type = :mtype';
            $params['mtype'] = $mtype;
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY m.created_at DESC, m.id DESC LIMIT 5000');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $sum = $pdo->prepare(
            "SELECT movement_type, COUNT(*)::int AS entries, SUM(quantity)::int AS net_quantity
             FROM public.stock_movements
             WHERE created_at >= CAST(:from AS date) AND created_at < CAST(:to AS date) + 1
             GROUP BY movement_type ORDER BY movement_type"
        );
        $sum->execute($dates);
        return [
            'title'   => 'Stock-in / stock-out history',
            'columns' => ['date' => 'Date', 'sku' => 'SKU', 'product' => 'Product', 'movement_type' => 'Type',
                'quantity' => 'Change', 'previous_quantity' => 'Before', 'resulting_quantity' => 'After', 'user_name' => 'By', 'notes' => 'Notes'],
            'rows'    => $rows,
            'summary' => $sum->fetchAll(),
        ];
    })(),

    // -----------------------------------------------------------------
    'low_stock' => (function () use ($pdo) {
        return [
            'title'   => 'Low-stock report',
            'columns' => ['sku' => 'SKU', 'name' => 'Product', 'category' => 'Category', 'quantity' => 'On hand',
                'reorder_level' => 'Reorder level', 'sold_last_30_days' => 'Sold (30 days)',
                'suggested_quantity' => 'Suggested restock', 'estimated_cost' => 'Estimated cost', 'suggestion_basis' => 'Basis'],
            'rows'    => low_stock_products($pdo),
        ];
    })(),

    // -----------------------------------------------------------------
    'transactions' => (function () use ($pdo, $v, $dates, $dateFilter) {
        $status = $v->enum('status', ['completed', 'voided'], false, null, 'Status');
        $v->abortIfInvalid();
        $sql = "SELECT s.transaction_number, to_char(s.created_at, 'YYYY-MM-DD HH24:MI') AS date, u.full_name AS cashier,
                       s.payment_method, s.status, p.sku, p.name AS product, si.quantity, si.unit_price, si.line_total,
                       s.subtotal, s.discount, s.total_amount
                FROM public.sales s
                JOIN public.users u ON u.id = s.user_id
                JOIN public.sale_items si ON si.sale_id = s.id
                JOIN public.products p ON p.id = si.product_id
                WHERE $dateFilter";
        $params = $dates;
        if ($status !== null) {
            $sql .= ' AND s.status = :status';
            $params['status'] = $status;
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY s.created_at DESC, s.id DESC, si.id LIMIT 10000');
        $stmt->execute($params);
        return [
            'title'   => 'Sales transaction details',
            'columns' => ['transaction_number' => 'Transaction', 'date' => 'Date', 'cashier' => 'Cashier', 'payment_method' => 'Payment',
                'status' => 'Status', 'sku' => 'SKU', 'product' => 'Product', 'quantity' => 'Qty', 'unit_price' => 'Unit price',
                'line_total' => 'Line total', 'subtotal' => 'Sale subtotal', 'discount' => 'Sale discount', 'total_amount' => 'Sale total'],
            'rows'    => $stmt->fetchAll(),
        ];
    })(),
};

$report['type'] = $type;
$report['range'] = in_array($type, ['valuation', 'low_stock', 'slow_moving'], true) ? null : $dates;
$report['generated_at'] = date('Y-m-d H:i');

if ($format === 'json') {
    json_success(count($report['rows']) === 0 ? 'No records found for this report.' : 'Report generated.', $report);
}

// ---------------------------------------------------------------------
// CSV download
// ---------------------------------------------------------------------
/** Stop spreadsheet formula injection: cells starting with = + - @ are prefixed. */
function csv_safe(mixed $value): string
{
    $s = (string) ($value ?? '');
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($s)) {
        return "'" . $s;
    }
    return $s;
}

$filename = sprintf('%s-report-%s.csv', str_replace('_', '-', $type), date('Ymd-His'));
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows ₱ and accents correctly
fputcsv($out, [$report['title']]);
if ($report['range']) {
    fputcsv($out, ['From', $report['range']['from'], 'To', $report['range']['to']]);
}
fputcsv($out, ['Generated', $report['generated_at']]);
fputcsv($out, []);
fputcsv($out, array_values($report['columns']));
foreach ($report['rows'] as $row) {
    fputcsv($out, array_map(fn($key) => csv_safe($row[$key] ?? ''), array_keys($report['columns'])));
}
if (!empty($report['totals'])) {
    fputcsv($out, []);
    fputcsv($out, array_map(fn($key) => $key === array_key_first($report['columns']) ? 'TOTAL' : csv_safe($report['totals'][$key] ?? ''), array_keys($report['columns'])));
}
fclose($out);
exit;
