<?php
/**
 * Inventory API
 *
 * GET  api/inventory.php?search=&movement_type=&product_id=&from=YYYY-MM-DD&to=YYYY-MM-DD&page=1
 *        → stock movement history (audit trail)
 * GET  api/inventory.php?view=low_stock   → low-stock alerts with restock suggestions
 *
 * POST api/inventory.php (JSON)
 *      stock_in   { action, product_id, quantity, notes }                (staff + admin)
 *      stock_out  { action, product_id, quantity, reason, notes }        (staff + admin)
 *      adjustment { action, product_id, counted_quantity, reason, notes } (admin only)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';
$user = api_bootstrap();
$pdo = db();

const STOCK_OUT_REASONS = ['damaged', 'expired', 'returned_to_supplier', 'internal_use', 'lost', 'other'];
const ADJUST_REASONS = ['physical_count', 'data_correction', 'found_stock', 'other'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $v = new Validator($_GET);

    if (($_GET['view'] ?? '') === 'low_stock') {
        $items = low_stock_products($pdo);
        json_success(count($items) . ' product(s) need restocking.', ['items' => $items]);
    }

    $search = $v->string('search', 0, 100, false);
    $type = $v->enum('movement_type', ['stock_in', 'stock_out', 'adjustment', 'sale', 'sale_void', 'initial'], false, null, 'Movement type');
    $productId = $v->id('product_id', false, 'Product');
    [$from, $to] = validate_date_range($v);
    $v->abortIfInvalid();
    [$page, $size, $offset] = pagination($_GET, 15, 100);

    $where = ['TRUE'];
    $params = [];
    if ($search !== null) {
        $where[] = '(p.name ILIKE :search OR p.sku ILIKE :search OR m.notes ILIKE :search)';
        $params['search'] = '%' . addcslashes($search, '%_\\') . '%';
    }
    if ($type !== null) {
        $where[] = 'm.movement_type = :type';
        $params['type'] = $type;
    }
    if ($productId !== null) {
        $where[] = 'm.product_id = :pid';
        $params['pid'] = $productId;
    }
    if ($from !== null) {
        $where[] = 'm.created_at >= CAST(:from AS date)';
        $params['from'] = $from;
    }
    if ($to !== null) {
        $where[] = "m.created_at < CAST(:to AS date) + 1";
        $params['to'] = $to;
    }
    $whereSql = implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) FROM public.stock_movements m JOIN public.products p ON p.id = m.product_id WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT m.id, m.product_id, p.sku, p.name AS product_name, m.movement_type, m.quantity,
                m.previous_quantity, m.resulting_quantity, m.notes, m.created_at,
                u.full_name AS user_name, s.transaction_number, m.sale_id
         FROM public.stock_movements m
         JOIN public.products p ON p.id = m.product_id
         JOIN public.users u ON u.id = m.user_id
         LEFT JOIN public.sales s ON s.id = m.sale_id
         WHERE $whereSql
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $val) {
        $stmt->bindValue($k, $val);
    }
    $stmt->bindValue('limit', $size, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    json_success('Stock movements loaded.', [
        'items'      => $stmt->fetchAll(),
        'pagination' => pagination_meta($total, $page, $size),
    ]);
}

[$action, $data] = post_action(['stock_in', 'stock_out', 'adjustment']);
if ($action === 'adjustment') {
    require_role('admin');
}
$v = new Validator($data);
$productId = $v->id('product_id', true, 'Product');
$notes = $v->string('notes', 0, 400, false, 'Notes');

if ($action === 'stock_in') {
    $qty = $v->int('quantity', 1, 100_000);
    $v->abortIfInvalid();
    $result = transaction(fn(PDO $pdo) => apply_stock_change($pdo, $productId, $qty, 'stock_in', $user['id'], $notes));
    json_success("Added $qty unit(s) to {$result['product_name']}. New stock: {$result['resulting_quantity']}.", $result, 201);
}

if ($action === 'stock_out') {
    $qty = $v->int('quantity', 1, 100_000);
    $reason = $v->enum('reason', STOCK_OUT_REASONS);
    if ($reason === 'other' && $notes === null) {
        $v->error('notes', 'Please describe the reason in the notes.');
    }
    $v->abortIfInvalid();
    $label = ucfirst(str_replace('_', ' ', $reason));
    $result = transaction(fn(PDO $pdo) => apply_stock_change(
        $pdo, $productId, -$qty, 'stock_out', $user['id'], $label . ($notes ? ": $notes" : '')
    ));
    json_success("Removed $qty unit(s) from {$result['product_name']}. New stock: {$result['resulting_quantity']}.", $result, 201);
}

// adjustment: set the quantity to what was physically counted
$counted = $v->int('counted_quantity', 0, 1_000_000, true, 'Counted quantity');
$reason = $v->enum('reason', ADJUST_REASONS);
if ($notes === null) {
    $v->error('notes', 'Adjustments need an explanation in the notes.');
}
$v->abortIfInvalid();

$result = transaction(function (PDO $pdo) use ($productId, $counted, $reason, $notes, $user) {
    $stmt = $pdo->prepare('SELECT quantity FROM public.products WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $productId]);
    $current = $stmt->fetchColumn();
    if ($current === false) {
        throw new AppException('Product not found.', 404);
    }
    $delta = $counted - (int) $current;
    if ($delta === 0) {
        throw new AppException("The counted quantity already matches the system ($current). Nothing to adjust.", 422,
            ['counted_quantity' => 'Same as the current quantity.']);
    }
    $label = ucfirst(str_replace('_', ' ', $reason));
    return apply_stock_change($pdo, $productId, $delta, 'adjustment', $user['id'], "$label: $notes");
});
$sign = $result['quantity'] > 0 ? '+' : '';
json_success("Stock adjusted ({$sign}{$result['quantity']}). {$result['product_name']} now has {$result['resulting_quantity']}.", $result, 201);
