<?php
/**
 * inventory.php — the business rules for stock, alerts and insights.
 *
 * Every quantity change in the app goes through apply_stock_change(), so
 * the product quantity and the stock_movements audit trail can never
 * drift apart.
 */

declare(strict_types=1);

/** A business-rule error that should be shown to the user (e.g. not enough stock). */
final class AppException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}

/**
 * Change a product's quantity and record the movement.
 * MUST be called inside a transaction ($pdo->beginTransaction()).
 *
 * SELECT ... FOR UPDATE locks the product row until COMMIT/ROLLBACK, so two
 * cashiers selling the last item at the same time cannot both succeed.
 *
 * @param int $delta  signed change: +10 for stock-in, -3 for a sale
 */
function apply_stock_change(PDO $pdo, int $productId, int $delta, string $type, int $userId, ?string $notes = null, ?int $saleId = null, bool $allowArchived = false): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('apply_stock_change() requires an open transaction.');
    }
    if ($delta === 0) {
        throw new AppException('Quantity change cannot be zero.', 422, ['quantity' => 'Quantity change cannot be zero.']);
    }

    $stmt = $pdo->prepare('SELECT id, sku, name, quantity, is_active FROM public.products WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch();
    if ($product === false) {
        throw new AppException('Product not found.', 404);
    }
    if (!$product['is_active'] && !$allowArchived) {
        throw new AppException("{$product['name']} is archived. Restore it before changing its stock.", 409);
    }

    $previous = (int) $product['quantity'];
    $resulting = $previous + $delta;
    if ($resulting < 0) {
        throw new AppException(
            "Not enough stock for {$product['name']} ({$product['sku']}): only $previous available.",
            409,
            ['quantity' => "Only $previous in stock."]
        );
    }

    $pdo->prepare('UPDATE public.products SET quantity = :q WHERE id = :id')
        ->execute(['q' => $resulting, 'id' => $productId]);

    $ins = $pdo->prepare(
        'INSERT INTO public.stock_movements (product_id, user_id, sale_id, movement_type, quantity, previous_quantity, resulting_quantity, notes)
         VALUES (:pid, :uid, :sid, :type, :qty, :prev, :res, :notes)
         RETURNING id, created_at'
    );
    $ins->execute([
        'pid' => $productId, 'uid' => $userId, 'sid' => $saleId, 'type' => $type,
        'qty' => $delta, 'prev' => $previous, 'res' => $resulting, 'notes' => $notes,
    ]);
    $movement = $ins->fetch();

    sync_low_stock_alert($pdo, $productId);

    return [
        'movement_id'        => (int) $movement['id'],
        'product_id'         => $productId,
        'product_name'       => $product['name'],
        'movement_type'      => $type,
        'quantity'           => $delta,
        'previous_quantity'  => $previous,
        'resulting_quantity' => $resulting,
        'created_at'         => $movement['created_at'],
    ];
}

/**
 * Open or resolve the low-stock alert for one product.
 * Low stock = active product with quantity <= reorder_level.
 * The unique index on open alerts means repeated calls never create duplicates.
 */
function sync_low_stock_alert(PDO $pdo, int $productId): void
{
    $stmt = $pdo->prepare('SELECT quantity, reorder_level, is_active FROM public.products WHERE id = :id');
    $stmt->execute(['id' => $productId]);
    $p = $stmt->fetch();
    if ($p === false) {
        return;
    }

    if ($p['is_active'] && (int) $p['quantity'] <= (int) $p['reorder_level']) {
        $pdo->prepare(
            "INSERT INTO public.low_stock_alerts (product_id, quantity_at_alert, reorder_level_at_alert)
             VALUES (:id, :q, :r)
             ON CONFLICT (product_id) WHERE status = 'open' DO NOTHING"
        )->execute(['id' => $productId, 'q' => $p['quantity'], 'r' => $p['reorder_level']]);
    } else {
        $pdo->prepare(
            "UPDATE public.low_stock_alerts SET status = 'resolved', resolved_at = now()
             WHERE product_id = :id AND status = 'open'"
        )->execute(['id' => $productId]);
    }
}

/**
 * Suggested restock quantity for a low-stock product.
 *
 * Rule (documented in docs/BUSINESS_RULES.md):
 *   - With sales in the last 30 days: target = reorder level + 30 days of
 *     average daily sales.
 *   - Without recent sales: target = 2 x reorder level.
 *   - Suggestion = target - current quantity (never below 0).
 */
function suggest_restock(int $quantity, int $reorderLevel, int $soldLast30): array
{
    if ($soldLast30 > 0) {
        // average daily sales x 30 days = units sold in the last 30 days
        $target = $reorderLevel + $soldLast30;
        $basis = "Reorder level + 30 days of recent sales ($soldLast30 sold in the last 30 days)";
    } elseif ($reorderLevel > 0) {
        $target = $reorderLevel * 2;
        $basis = 'No sales in the last 30 days: restock to twice the reorder level';
    } else {
        return ['suggested_quantity' => null, 'basis' => 'Set a reorder level to get a suggestion'];
    }
    return ['suggested_quantity' => max($target - $quantity, 0), 'basis' => $basis];
}

/**
 * Low-stock list with suggestions (used by the dashboard, inventory page and reports).
 */
function low_stock_products(PDO $pdo, ?int $limit = null): array
{
    $sql = "SELECT p.id, p.sku, p.name, c.name AS category, p.quantity, p.reorder_level, p.purchase_price,
                   a.id AS alert_id, a.status AS alert_status, a.created_at AS alert_since,
                   COALESCE(s.sold_30, 0)::int AS sold_last_30_days
            FROM public.products p
            JOIN public.categories c ON c.id = p.category_id
            LEFT JOIN public.low_stock_alerts a ON a.product_id = p.id AND a.status = 'open'
            LEFT JOIN (
                SELECT si.product_id, SUM(si.quantity) AS sold_30
                FROM public.sale_items si
                JOIN public.sales sa ON sa.id = si.sale_id
                WHERE sa.status = 'completed' AND sa.created_at >= now() - interval '30 days'
                GROUP BY si.product_id
            ) s ON s.product_id = p.id
            WHERE p.is_active AND p.quantity <= p.reorder_level
            ORDER BY (p.quantity = 0) DESC, (p.quantity::numeric / NULLIF(p.reorder_level, 0)) ASC NULLS FIRST, p.name";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit);
    }
    $rows = $pdo->query($sql)->fetchAll();

    return array_map(function (array $r): array {
        $suggest = suggest_restock((int) $r['quantity'], (int) $r['reorder_level'], (int) $r['sold_last_30_days']);
        return [
            'id'                 => (int) $r['id'],
            'sku'                => $r['sku'],
            'name'               => $r['name'],
            'category'           => $r['category'],
            'quantity'           => (int) $r['quantity'],
            'reorder_level'      => (int) $r['reorder_level'],
            'sold_last_30_days'  => (int) $r['sold_last_30_days'],
            'status'             => (int) $r['quantity'] === 0 ? 'out_of_stock' : 'low_stock',
            'alert_id'           => $r['alert_id'] !== null ? (int) $r['alert_id'] : null,
            'alert_status'       => $r['alert_status'] ?? 'open',
            'alert_since'        => $r['alert_since'],
            'suggested_quantity' => $suggest['suggested_quantity'],
            'suggestion_basis'   => $suggest['basis'],
            'estimated_cost'     => $suggest['suggested_quantity'] !== null
                ? money($suggest['suggested_quantity'] * (float) $r['purchase_price']) : null,
        ];
    }, $rows);
}

/**
 * Slow-moving product analysis.
 *
 * Business rules (also in docs/BUSINESS_RULES.md):
 *   Only ACTIVE products that still HAVE STOCK are analyzed.
 *   1. "Insufficient data": fewer than $periodDays days of sales tracking
 *      exist for the product. Tracking starts at the LATER of
 *        - the day the product was added, and
 *        - the day the first sale was ever recorded in the system.
 *      (A brand-new system with no sales yet cannot call anything slow.)
 *      Such products are NOT labeled slow.
 *   2. "No sales":  0 units sold in the last $periodDays days.
 *   3. "Slow":      at the current sales pace, the stock on hand would last
 *                   more than $coverageDays days.
 *                   days of stock = quantity / (units sold in period / periodDays)
 *   Anything else is a normal mover and is not listed.
 *
 * Recommendations are suggestions based on recorded sales, not predictions.
 */
function slow_moving_analysis(PDO $pdo, int $periodDays = 30, int $coverageDays = 90): array
{
    $stmt = $pdo->prepare(
        "SELECT p.id, p.sku, p.name, c.name AS category, p.quantity, p.reorder_level,
                p.purchase_price, p.selling_price, p.created_at,
                CASE WHEN fs.first_sale_at IS NULL THEN 0
                     ELSE EXTRACT(DAY FROM now() - GREATEST(p.created_at, fs.first_sale_at))::int END AS days_tracked,
                COALESCE(sp.sold, 0)::int AS sold_in_period,
                ls.last_sale_at,
                CASE WHEN ls.last_sale_at IS NULL THEN NULL
                     ELSE EXTRACT(DAY FROM now() - ls.last_sale_at)::int END AS days_since_last_sale
         FROM public.products p
         JOIN public.categories c ON c.id = p.category_id
         CROSS JOIN (SELECT MIN(created_at) AS first_sale_at FROM public.sales) fs
         LEFT JOIN (
             SELECT si.product_id, SUM(si.quantity) AS sold
             FROM public.sale_items si
             JOIN public.sales s ON s.id = si.sale_id
             WHERE s.status = 'completed' AND s.created_at >= now() - make_interval(days => :period)
             GROUP BY si.product_id
         ) sp ON sp.product_id = p.id
         LEFT JOIN (
             SELECT si.product_id, MAX(s.created_at) AS last_sale_at
             FROM public.sale_items si
             JOIN public.sales s ON s.id = si.sale_id
             WHERE s.status = 'completed'
             GROUP BY si.product_id
         ) ls ON ls.product_id = p.id
         WHERE p.is_active AND p.quantity > 0
         ORDER BY p.name"
    );
    $stmt->execute(['period' => $periodDays]);

    $slow = [];
    $insufficient = [];
    $analyzed = 0;

    foreach ($stmt->fetchAll() as $r) {
        $qty = (int) $r['quantity'];
        $sold = (int) $r['sold_in_period'];
        $base = [
            'id'                   => (int) $r['id'],
            'sku'                  => $r['sku'],
            'name'                 => $r['name'],
            'category'             => $r['category'],
            'quantity'             => $qty,
            'sold_in_period'       => $sold,
            'last_sale_at'         => $r['last_sale_at'],
            'days_since_last_sale' => $r['days_since_last_sale'] !== null ? (int) $r['days_since_last_sale'] : null,
            'days_tracked'         => (int) $r['days_tracked'],
            'stock_value_at_cost'  => money($qty * (float) $r['purchase_price']),
        ];

        if ((int) $r['days_tracked'] < $periodDays) {
            $insufficient[] = $base + ['classification' => 'insufficient_data',
                'reason' => "Only {$r['days_tracked']} day(s) of sales tracking — needs at least $periodDays days of history."];
            continue;
        }
        $analyzed++;

        $daysOfStock = $sold > 0 ? (int) ceil($qty / ($sold / $periodDays)) : null;
        if ($sold === 0) {
            $class = 'no_sales';
        } elseif ($daysOfStock > $coverageDays) {
            $class = 'slow';
        } else {
            continue; // normal mover
        }

        $slow[] = $base + [
            'classification'  => $class,
            'days_of_stock'   => $daysOfStock,
            'margin_percent'  => (float) $r['selling_price'] > 0
                ? round(((float) $r['selling_price'] - (float) $r['purchase_price']) / (float) $r['selling_price'] * 100, 1)
                : null,
            'recommendations' => slow_moving_recommendations($class, $r, $periodDays, $daysOfStock),
        ];
    }

    // Most "stuck" products first: no sales, then by money tied up in stock.
    usort($slow, fn($a, $b) => [$a['classification'] === 'slow', -(float) $a['stock_value_at_cost']]
        <=> [$b['classification'] === 'slow', -(float) $b['stock_value_at_cost']]);

    return [
        'rules' => [
            'period_days'   => $periodDays,
            'coverage_days' => $coverageDays,
            'description'   => "Active products with stock and at least $periodDays days of sales tracking that sold nothing in the last $periodDays days, or that have more than $coverageDays days of stock at their current sales pace.",
        ],
        'analyzed_count'    => $analyzed,
        'slow_moving'       => $slow,
        'insufficient_data' => $insufficient,
        'disclaimer'        => 'Recommendations are suggestions based on recorded sales only, not guaranteed predictions.',
    ];
}

function slow_moving_recommendations(string $class, array $r, int $periodDays, ?int $daysOfStock): array
{
    $recs = [];
    $cost = (float) $r['purchase_price'];
    $price = (float) $r['selling_price'];
    $margin = $price > 0 ? ($price - $cost) / $price : 0;

    if ($class === 'no_sales' && $r['last_sale_at'] === null) {
        $recs[] = 'This product has never been sold. Investigate why: check shelf placement, visibility and customer demand.';
        $recs[] = 'Do not reorder until it starts selling.';
    } elseif ($class === 'no_sales') {
        $recs[] = "No sales in the last $periodDays days (last sale {$r['days_since_last_sale']} days ago). Consider a promotion or bundling it with a fast seller.";
        $recs[] = 'Pause reordering and prioritize selling the existing stock.';
    } else {
        $recs[] = "At the current pace the stock on hand would last about $daysOfStock days. Sell existing stock before reordering.";
        $recs[] = 'Reduce the quantity of the next purchase order.';
    }

    if ($margin >= 0.25) {
        $recs[] = sprintf('The margin is %.0f%%, so there is room for a discount or promo price.', $margin * 100);
    } else {
        $recs[] = sprintf('The margin is only %.0f%%. Review the selling price or supplier cost instead of discounting further.', $margin * 100);
    }
    return $recs;
}
