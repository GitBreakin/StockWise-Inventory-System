<?php
/**
 * Dashboard API
 *
 * GET api/dashboard.php?period=today|week|month|30d
 * Every number comes from a live database query — nothing is hard-coded.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';
$user = api_bootstrap();
$pdo = db();

$v = new Validator($_GET);
$period = $v->enum('period', ['today', 'week', 'month', '30d'], false, 'today', 'Period');
$v->abortIfInvalid();

// Period = [start, end) in local dates; previous period has the same length.
$today = new DateTimeImmutable('today');
$end = $today->modify('+1 day');
$start = match ($period) {
    'today' => $today,
    'week'  => $today->modify('-6 days'),
    'month' => $today->modify('first day of this month'),
    '30d'   => $today->modify('-29 days'),
};
$lengthDays = (int) $start->diff($end)->days;
$prevStart = $start->modify("-$lengthDays days");
$range = ['s' => $start->format('Y-m-d'), 'e' => $end->format('Y-m-d')];

// --- Inventory snapshot ---
$inv = $pdo->query(
    "SELECT COUNT(*) FILTER (WHERE is_active)::int                                         AS active_products,
            COUNT(*) FILTER (WHERE NOT is_active)::int                                     AS archived_products,
            COALESCE(SUM(quantity) FILTER (WHERE is_active), 0)::int                       AS units_in_stock,
            COALESCE(SUM(quantity * purchase_price) FILTER (WHERE is_active), 0)::numeric(14,2) AS value_at_cost,
            COALESCE(SUM(quantity * selling_price) FILTER (WHERE is_active), 0)::numeric(14,2)  AS value_at_retail,
            COUNT(*) FILTER (WHERE is_active AND quantity <= reorder_level AND quantity > 0)::int AS low_stock,
            COUNT(*) FILTER (WHERE is_active AND quantity = 0)::int                        AS out_of_stock
     FROM public.products"
)->fetch();
$categories = (int) $pdo->query('SELECT COUNT(*) FROM public.categories')->fetchColumn();
$openAlerts = (int) $pdo->query("SELECT COUNT(*) FROM public.low_stock_alerts WHERE status = 'open'")->fetchColumn();

// --- Sales for the selected and previous period ---
$salesSql = "SELECT COUNT(*)::int AS transactions,
                    COALESCE(SUM(total_amount), 0)::numeric(14,2) AS total,
                    COALESCE(AVG(total_amount), 0)::numeric(14,2) AS average
             FROM public.sales
             WHERE status = 'completed' AND created_at >= CAST(:s AS date) AND created_at < CAST(:e AS date)";
$cur = $pdo->prepare($salesSql);
$cur->execute($range);
$sales = $cur->fetch();
$prev = $pdo->prepare($salesSql);
$prev->execute(['s' => $prevStart->format('Y-m-d'), 'e' => $start->format('Y-m-d')]);
$previous = $prev->fetch();
$change = (float) $previous['total'] > 0
    ? round(((float) $sales['total'] - (float) $previous['total']) / (float) $previous['total'] * 100, 1)
    : null;

$units = $pdo->prepare(
    "SELECT COALESCE(SUM(si.quantity), 0)::int FROM public.sale_items si JOIN public.sales s ON s.id = si.sale_id
     WHERE s.status = 'completed' AND s.created_at >= CAST(:s AS date) AND s.created_at < CAST(:e AS date)"
);
$units->execute($range);

// --- Top sellers in the period ---
$top = $pdo->prepare(
    "SELECT p.id, p.sku, p.name, SUM(si.quantity)::int AS units_sold, SUM(si.line_total)::numeric(14,2) AS revenue
     FROM public.sale_items si
     JOIN public.sales s ON s.id = si.sale_id
     JOIN public.products p ON p.id = si.product_id
     WHERE s.status = 'completed' AND s.created_at >= CAST(:s AS date) AND s.created_at < CAST(:e AS date)
     GROUP BY p.id ORDER BY units_sold DESC, revenue DESC LIMIT 5"
);
$top->execute($range);

// --- Daily sales chart: last 14 days, including days with zero sales ---
$chart = $pdo->query(
    "SELECT to_char(d.day, 'YYYY-MM-DD') AS day,
            COALESCE(SUM(s.total_amount), 0)::numeric(14,2) AS total,
            COUNT(s.id)::int AS transactions
     FROM generate_series(current_date - 13, current_date, interval '1 day') AS d(day)
     LEFT JOIN public.sales s ON s.status = 'completed' AND s.created_at >= d.day AND s.created_at < d.day + interval '1 day'
     GROUP BY d.day ORDER BY d.day"
)->fetchAll();

// --- Recent activity ---
$recentMoves = $pdo->query(
    'SELECT m.id, m.movement_type, m.quantity, m.resulting_quantity, m.created_at, p.name AS product_name, p.sku, u.full_name AS user_name
     FROM public.stock_movements m
     JOIN public.products p ON p.id = m.product_id
     JOIN public.users u ON u.id = m.user_id
     ORDER BY m.created_at DESC, m.id DESC LIMIT 6'
)->fetchAll();
$recentSales = $pdo->query(
    'SELECT s.id, s.transaction_number, s.total_amount, s.status, s.payment_method, s.created_at, u.full_name AS cashier
     FROM public.sales s JOIN public.users u ON u.id = s.user_id
     ORDER BY s.created_at DESC, s.id DESC LIMIT 6'
)->fetchAll();

$slow = slow_moving_analysis($pdo, 30, 90);

json_success('Dashboard loaded.', [
    'period' => [
        'key'        => $period,
        'start'      => $range['s'],
        'end'        => $today->format('Y-m-d'),
        'days'       => $lengthDays,
    ],
    'inventory' => $inv + ['categories' => $categories, 'open_alerts' => $openAlerts],
    'sales' => [
        'transactions'           => (int) $sales['transactions'],
        'total'                  => $sales['total'],
        'average'                => $sales['average'],
        'units_sold'             => (int) $units->fetchColumn(),
        'previous_total'         => $previous['total'],
        'change_percent'         => $change,
    ],
    'top_sellers'     => $top->fetchAll(),
    'low_stock'       => low_stock_products($pdo, 5),
    'slow_moving'     => [
        'count'             => count($slow['slow_moving']),
        'insufficient_data' => count($slow['insufficient_data']),
        'items'             => array_slice($slow['slow_moving'], 0, 5),
        'rules'             => $slow['rules'],
    ],
    'chart'           => $chart,
    'recent_movements' => $recentMoves,
    'recent_sales'    => $recentSales,
]);
