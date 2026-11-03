<?php
/**
 * Sales API
 *
 * GET  api/sales.php?from=&to=&status=completed|voided&search=TXN&page=1   → sales history
 * GET  api/sales.php?id=12                                                → one sale with its items
 *
 * POST api/sales.php (JSON)
 *      create { action, items: [{ product_id, quantity }], discount, payment_method, amount_tendered }
 *             Prices and totals are ALWAYS taken from / calculated by the server.
 *      void   { action, id, reason }   (admin) → returns the items to stock
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';
$user = api_bootstrap();
$pdo = db();

function load_sale(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT s.id, s.transaction_number, s.subtotal, s.discount, s.total_amount, s.payment_method, s.status,
                s.void_reason, s.voided_at, s.created_at, u.full_name AS cashier, vu.full_name AS voided_by_name
         FROM public.sales s
         JOIN public.users u ON u.id = s.user_id
         LEFT JOIN public.users vu ON vu.id = s.voided_by
         WHERE s.id = :id'
    );
    $stmt->execute(['id' => $id]);
    $sale = $stmt->fetch();
    if ($sale === false) {
        return null;
    }
    $items = $pdo->prepare(
        'SELECT si.product_id, p.sku, p.name, si.quantity, si.unit_price, si.line_total
         FROM public.sale_items si JOIN public.products p ON p.id = si.product_id
         WHERE si.sale_id = :id ORDER BY si.id'
    );
    $items->execute(['id' => $id]);
    $sale['id'] = (int) $sale['id'];
    $sale['items'] = $items->fetchAll();
    return $sale;
}

// ---------------------------------------------------------------------
// GET
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $v = new Validator($_GET);

    if (isset($_GET['id'])) {
        $id = $v->id('id', true, 'Sale');
        $v->abortIfInvalid();
        $sale = load_sale($pdo, $id);
        $sale === null ? json_error('Sale not found.', 404) : json_success('Sale loaded.', ['sale' => $sale]);
    }

    [$from, $to] = validate_date_range($v);
    $status = $v->enum('status', ['completed', 'voided'], false, null, 'Status');
    $search = $v->string('search', 0, 40, false);
    $v->abortIfInvalid();
    [$page, $size, $offset] = pagination($_GET, 15, 100);

    $where = ['TRUE'];
    $params = [];
    if ($from !== null) {
        $where[] = 's.created_at >= CAST(:from AS date)';
        $params['from'] = $from;
    }
    if ($to !== null) {
        $where[] = 's.created_at < CAST(:to AS date) + 1';
        $params['to'] = $to;
    }
    if ($status !== null) {
        $where[] = 's.status = :status';
        $params['status'] = $status;
    }
    if ($search !== null) {
        $where[] = 's.transaction_number ILIKE :search';
        $params['search'] = '%' . addcslashes($search, '%_\\') . '%';
    }
    $whereSql = implode(' AND ', $where);

    $summary = $pdo->prepare(
        "SELECT COUNT(*) AS count,
                COALESCE(SUM(total_amount) FILTER (WHERE status = 'completed'), 0)::numeric(14,2) AS completed_total
         FROM public.sales s WHERE $whereSql"
    );
    $summary->execute($params);
    $sum = $summary->fetch();

    $stmt = $pdo->prepare(
        "SELECT s.id, s.transaction_number, s.subtotal, s.discount, s.total_amount, s.payment_method, s.status, s.created_at,
                u.full_name AS cashier,
                (SELECT COALESCE(SUM(quantity), 0) FROM public.sale_items WHERE sale_id = s.id)::int AS item_count
         FROM public.sales s JOIN public.users u ON u.id = s.user_id
         WHERE $whereSql
         ORDER BY s.created_at DESC, s.id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $val) {
        $stmt->bindValue($k, $val);
    }
    $stmt->bindValue('limit', $size, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    json_success('Sales loaded.', [
        'items'           => $stmt->fetchAll(),
        'completed_total' => $sum['completed_total'],
        'pagination'      => pagination_meta((int) $sum['count'], $page, $size),
    ]);
}

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
[$action, $data] = post_action(['create', 'void']);
$v = new Validator($data);

if ($action === 'create') {
    // 1. Validate the shape of the request
    $items = $data['items'] ?? null;
    if (!is_array($items) || $items === [] || !array_is_list($items)) {
        json_error('Add at least one product to the sale.', 422, ['items' => 'The cart is empty.']);
    }
    if (count($items) > 50) {
        json_error('A sale can contain at most 50 different products.', 422, ['items' => 'Too many lines.']);
    }

    // Merge duplicate lines (same product added twice) and validate each line.
    $wanted = [];   // product_id => quantity
    foreach ($items as $i => $line) {
        if (!is_array($line)) {
            $v->error("items.$i", 'Invalid item.');
            continue;
        }
        $lv = new Validator($line);
        $pid = $lv->id('product_id', true, 'Product');
        $qty = $lv->int('quantity', 1, 10_000);
        foreach ($lv->errors() as $field => $msg) {
            $v->error("items.$i.$field", $msg);
        }
        if ($pid !== null && $qty !== null) {
            $wanted[$pid] = ($wanted[$pid] ?? 0) + $qty;
        }
    }
    $discount = $v->money('discount', 0, 9_999_999, false) ?? '0.00';
    $payment = $v->enum('payment_method', ['cash', 'gcash', 'card'], true, null, 'Payment method');
    $tendered = $v->money('amount_tendered', 0, 9_999_999, false, 'Amount tendered');
    $v->abortIfInvalid();

    // 2. Everything below runs in ONE transaction: all or nothing.
    $saleId = transaction(function (PDO $pdo) use ($wanted, $discount, $payment, $tendered, $user) {
        // Lock the products in a fixed order (by id) to avoid deadlocks between cashiers.
        ksort($wanted);
        $ids = array_keys($wanted);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, sku, name, selling_price, quantity, is_active
             FROM public.products WHERE id IN ($placeholders) ORDER BY id FOR UPDATE"
        );
        $stmt->execute($ids);
        $products = [];
        foreach ($stmt->fetchAll() as $p) {
            $products[(int) $p['id']] = $p;
        }

        // 3. Check each product exists, is sellable and has enough stock
        $errors = [];
        $subtotalCents = 0;
        $lines = [];
        foreach ($wanted as $pid => $qty) {
            $p = $products[$pid] ?? null;
            if ($p === null) {
                $errors["product_$pid"] = "Product #$pid no longer exists.";
                continue;
            }
            if (!$p['is_active']) {
                $errors["product_$pid"] = "{$p['name']} is archived and cannot be sold.";
                continue;
            }
            if ((int) $p['quantity'] < $qty) {
                $errors["product_$pid"] = "{$p['name']}: only {$p['quantity']} in stock, $qty requested.";
                continue;
            }
            // 4. The price comes from the database, never from the browser
            $unitCents = to_cents($p['selling_price']);
            $lineCents = $unitCents * $qty;
            $subtotalCents += $lineCents;
            $lines[] = ['pid' => $pid, 'qty' => $qty, 'unit' => from_cents($unitCents), 'line' => from_cents($lineCents)];
        }
        if ($errors) {
            throw new AppException('Some items cannot be sold. Please review the cart.', 409, $errors);
        }

        // 5. Server-side totals
        $discountCents = to_cents($discount);
        if ($discountCents > $subtotalCents) {
            throw new AppException('Discount cannot be greater than the subtotal.', 422, ['discount' => 'Discount is larger than the subtotal (' . from_cents($subtotalCents) . ').']);
        }
        $totalCents = $subtotalCents - $discountCents;
        if ($payment === 'cash' && $tendered !== null && to_cents($tendered) < $totalCents) {
            throw new AppException('Amount tendered is less than the total.', 422, ['amount_tendered' => 'Must be at least ' . from_cents($totalCents) . '.']);
        }

        // 6. Unique transaction number from a database sequence, e.g. TXN-20261103-000042
        $seq = (int) $pdo->query("SELECT nextval('public.sales_txn_seq')")->fetchColumn();
        $txn = 'TXN-' . date('Ymd') . '-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);

        $ins = $pdo->prepare(
            'INSERT INTO public.sales (transaction_number, user_id, subtotal, discount, total_amount, payment_method)
             VALUES (:txn, :uid, :sub, :disc, :total, :pay) RETURNING id'
        );
        $ins->execute([
            'txn' => $txn, 'uid' => $user['id'], 'sub' => from_cents($subtotalCents),
            'disc' => from_cents($discountCents), 'total' => from_cents($totalCents), 'pay' => $payment,
        ]);
        $saleId = (int) $ins->fetchColumn();

        // 7. Sale lines + stock deduction + audit movement for each product
        $item = $pdo->prepare(
            'INSERT INTO public.sale_items (sale_id, product_id, quantity, unit_price, line_total)
             VALUES (:sid, :pid, :qty, :unit, :line)'
        );
        foreach ($lines as $l) {
            $item->execute(['sid' => $saleId, 'pid' => $l['pid'], 'qty' => $l['qty'], 'unit' => $l['unit'], 'line' => $l['line']]);
            apply_stock_change($pdo, $l['pid'], -$l['qty'], 'sale', $user['id'], "Sold in $txn", $saleId);
        }
        return $saleId;
    });

    $sale = load_sale($pdo, $saleId);
    if ($payment === 'cash' && $tendered !== null) {
        $sale['amount_tendered'] = $tendered;
        $sale['change'] = from_cents(to_cents($tendered) - to_cents($sale['total_amount']));
    }
    json_success("Sale {$sale['transaction_number']} completed.", ['sale' => $sale], 201);
}

// void (admin): mark the sale voided and put the items back into stock
require_role('admin');
$id = $v->id('id', true, 'Sale');
$reason = $v->string('reason', 3, 255, true, 'Reason');
$v->abortIfInvalid();

transaction(function (PDO $pdo) use ($id, $reason, $user) {
    $stmt = $pdo->prepare('SELECT id, transaction_number, status FROM public.sales WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $id]);
    $sale = $stmt->fetch();
    if ($sale === false) {
        throw new AppException('Sale not found.', 404);
    }
    if ($sale['status'] !== 'completed') {
        throw new AppException('This sale is already voided.', 409);
    }
    $items = $pdo->prepare('SELECT product_id, quantity FROM public.sale_items WHERE sale_id = :id ORDER BY product_id');
    $items->execute(['id' => $id]);
    foreach ($items->fetchAll() as $it) {
        apply_stock_change($pdo, (int) $it['product_id'], (int) $it['quantity'], 'sale_void', $user['id'],
            "Voided {$sale['transaction_number']}: $reason", $id, true);
    }
    $pdo->prepare("UPDATE public.sales SET status = 'voided', void_reason = :r, voided_by = :u, voided_at = now() WHERE id = :id")
        ->execute(['r' => $reason, 'u' => $user['id'], 'id' => $id]);
});

json_success('Sale voided and items returned to stock.', ['sale' => load_sale($pdo, $id)]);
