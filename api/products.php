<?php
/**
 * Products API
 *
 * GET  api/products.php?search=&category_id=&stock=all|in_stock|low|out&status=active|archived|all
 *                      &sort=name|sku|quantity|selling_price|updated_at&dir=asc|desc&page=1&per_page=10
 * GET  api/products.php?id=5                → one product + recent movements + sales totals
 * GET  api/products.php?lookup=1&q=cola     → active products for the sales / stock forms
 *
 * POST api/products.php  (JSON, admin)
 *      create  { action, category_id, sku, name, description, purchase_price, selling_price, quantity, reorder_level }
 *      update  { action, id, category_id, sku, name, description, purchase_price, selling_price, reorder_level }
 *               (quantity is NOT editable here — use Inventory so every change is audited)
 *      archive { action, id }   restore { action, id }
 *      delete  { action, id }   → only when the product has no sales and no stock history
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';
$user = api_bootstrap();
$pdo = db();

function product_row(array $r): array
{
    foreach (['id', 'category_id', 'quantity', 'reorder_level'] as $k) {
        if (isset($r[$k])) {
            $r[$k] = (int) $r[$k];
        }
    }
    if (isset($r['is_active'])) {
        $r['is_active'] = (bool) $r['is_active'];
    }
    if (isset($r['quantity'], $r['reorder_level'])) {
        $r['stock_status'] = $r['quantity'] === 0 ? 'out' : ($r['quantity'] <= $r['reorder_level'] ? 'low' : 'in_stock');
    }
    return $r;
}

// ---------------------------------------------------------------------
// GET
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $v = new Validator($_GET);

    // Quick lookup for dropdowns / autocomplete
    if (isset($_GET['lookup'])) {
        $q = $v->string('q', 0, 100, false) ?? '';
        $v->abortIfInvalid();
        $stmt = $pdo->prepare(
            "SELECT id, sku, name, selling_price, quantity, reorder_level
             FROM public.products
             WHERE is_active AND (:q = '' OR name ILIKE :like OR sku ILIKE :like)
             ORDER BY name LIMIT 30"
        );
        $stmt->execute(['q' => $q, 'like' => '%' . addcslashes($q, '%_\\') . '%']);
        json_success('Products loaded.', ['items' => array_map('product_row', $stmt->fetchAll())]);
    }

    // Single product
    if (isset($_GET['id'])) {
        $id = $v->id('id', true, 'Product');
        $v->abortIfInvalid();
        $stmt = $pdo->prepare(
            'SELECT p.*, c.name AS category_name,
                    (p.quantity * p.purchase_price)::numeric(14,2) AS stock_value_at_cost
             FROM public.products p JOIN public.categories c ON c.id = p.category_id
             WHERE p.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $product = $stmt->fetch();
        if ($product === false) {
            json_error('Product not found.', 404);
        }

        $mv = $pdo->prepare(
            'SELECT m.id, m.movement_type, m.quantity, m.previous_quantity, m.resulting_quantity, m.notes, m.created_at,
                    u.full_name AS user_name, s.transaction_number
             FROM public.stock_movements m
             JOIN public.users u ON u.id = m.user_id
             LEFT JOIN public.sales s ON s.id = m.sale_id
             WHERE m.product_id = :id ORDER BY m.created_at DESC, m.id DESC LIMIT 10'
        );
        $mv->execute(['id' => $id]);

        $sales = $pdo->prepare(
            "SELECT COALESCE(SUM(si.quantity), 0)::int AS units_sold,
                    COALESCE(SUM(si.line_total), 0)::numeric(14,2) AS revenue,
                    MAX(s.created_at) AS last_sale_at
             FROM public.sale_items si JOIN public.sales s ON s.id = si.sale_id
             WHERE si.product_id = :id AND s.status = 'completed'"
        );
        $sales->execute(['id' => $id]);

        json_success('Product loaded.', [
            'product'          => product_row($product),
            'recent_movements' => $mv->fetchAll(),
            'sales_summary'    => $sales->fetch(),
        ]);
    }

    // List with search, filters, sorting and pagination
    $search = $v->string('search', 0, 100, false);
    $categoryId = $v->id('category_id', false, 'Category');
    $stock = $v->enum('stock', ['all', 'in_stock', 'low', 'out'], false, 'all', 'Stock filter');
    $status = $v->enum('status', ['active', 'archived', 'all'], false, 'active', 'Status filter');
    $sort = $v->enum('sort', ['name', 'sku', 'quantity', 'selling_price', 'updated_at', 'category'], false, 'name', 'Sort');
    $dir = $v->enum('dir', ['asc', 'desc'], false, 'asc', 'Direction');
    $v->abortIfInvalid();
    [$page, $size, $offset] = pagination($_GET, 10, 100);

    $where = ['TRUE'];
    $params = [];
    if ($search !== null) {
        $where[] = '(p.name ILIKE :search OR p.sku ILIKE :search OR p.description ILIKE :search)';
        $params['search'] = '%' . addcslashes($search, '%_\\') . '%';
    }
    if ($categoryId !== null) {
        $where[] = 'p.category_id = :cat';
        $params['cat'] = $categoryId;
    }
    $where[] = match ($status) {
        'active'   => 'p.is_active',
        'archived' => 'NOT p.is_active',
        default    => 'TRUE',
    };
    $where[] = match ($stock) {
        'in_stock' => 'p.quantity > p.reorder_level',
        'low'      => 'p.quantity <= p.reorder_level AND p.quantity > 0',
        'out'      => 'p.quantity = 0',
        default    => 'TRUE',
    };
    // Column names come from a fixed whitelist above, never from raw input.
    $orderBy = match ($sort) {
        'category' => 'lower(c.name)',
        'name'     => 'lower(p.name)',
        default    => 'p.' . $sort,
    };
    $whereSql = implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) FROM public.products p JOIN public.categories c ON c.id = p.category_id WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT p.id, p.category_id, c.name AS category_name, p.sku, p.name, p.description,
                p.purchase_price, p.selling_price, p.quantity, p.reorder_level, p.is_active, p.updated_at
         FROM public.products p JOIN public.categories c ON c.id = p.category_id
         WHERE $whereSql
         ORDER BY $orderBy " . strtoupper($dir) . ", p.id
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $val) {
        $stmt->bindValue($k, $val);
    }
    $stmt->bindValue('limit', $size, PDO::PARAM_INT);
    $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    json_success('Products loaded.', [
        'items'      => array_map('product_row', $stmt->fetchAll()),
        'pagination' => pagination_meta($total, $page, $size),
    ]);
}

// ---------------------------------------------------------------------
// POST (admin only)
// ---------------------------------------------------------------------
require_role('admin');
[$action, $data] = post_action(['create', 'update', 'archive', 'restore', 'delete']);
$v = new Validator($data);

if ($action === 'create' || $action === 'update') {
    $id = $action === 'update' ? $v->id('id', true, 'Product') : null;
    $categoryId = $v->id('category_id', true, 'Category');
    $sku = $v->string('sku', 2, 40, true, 'SKU');
    $name = $v->string('name', 2, 150);
    $description = $v->string('description', 0, 1000, false);
    $purchase = $v->money('purchase_price');
    $selling = $v->money('selling_price');
    $reorder = $v->int('reorder_level', 0, 100_000, true, 'Reorder level');
    $openingQty = $action === 'create' ? ($v->int('quantity', 0, 1_000_000, false, 'Opening quantity') ?? 0) : 0;

    if ($sku !== null) {
        $sku = strtoupper($sku);
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{1,39}$/', $sku)) {
            $v->error('sku', 'SKU may only contain letters, numbers and dashes (e.g. BEV-001).');
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM public.products WHERE sku = :sku AND id <> COALESCE(:id, 0)');
            $dup->execute(['sku' => $sku, 'id' => $id]);
            if ($dup->fetchColumn()) {
                $v->error('sku', 'This SKU is already used by another product.');
            }
        }
    }
    if ($categoryId !== null) {
        $cat = $pdo->prepare('SELECT 1 FROM public.categories WHERE id = :id');
        $cat->execute(['id' => $categoryId]);
        if (!$cat->fetchColumn()) {
            $v->error('category_id', 'Selected category does not exist.');
        }
    }
    if ($purchase !== null && $selling !== null && (float) $selling < (float) $purchase) {
        $v->error('selling_price', 'Selling price cannot be lower than the purchase price.');
    }
    $v->abortIfInvalid();

    $params = [
        'cat' => $categoryId, 'sku' => $sku, 'name' => $name, 'descr' => $description,
        'pp' => $purchase, 'sp' => $selling, 'rl' => $reorder,
    ];

    try {
        $result = transaction(function (PDO $pdo) use ($action, $id, $params, $openingQty, $user) {
            if ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO public.products (category_id, sku, name, description, purchase_price, selling_price, quantity, reorder_level)
                     VALUES (:cat, :sku, :name, :descr, :pp, :sp, 0, :rl) RETURNING id'
                );
                $stmt->execute($params);
                $newId = (int) $stmt->fetchColumn();
                // Opening stock is recorded as a movement so the audit trail starts complete.
                if ($openingQty > 0) {
                    apply_stock_change($pdo, $newId, $openingQty, 'initial', $user['id'], 'Opening stock when the product was created');
                } else {
                    sync_low_stock_alert($pdo, $newId);
                }
                return $newId;
            }
            $stmt = $pdo->prepare(
                'UPDATE public.products SET category_id = :cat, sku = :sku, name = :name, description = :descr,
                        purchase_price = :pp, selling_price = :sp, reorder_level = :rl
                 WHERE id = :id'
            );
            $stmt->execute($params + ['id' => $id]);
            if ($stmt->rowCount() === 0) {
                throw new AppException('Product not found.', 404);
            }
            sync_low_stock_alert($pdo, $id);   // reorder level may have changed
            return $id;
        });
    } catch (PDOException $e) {
        if (pg_error_code($e) === '23505') {
            json_error('Please correct the highlighted fields.', 422, ['sku' => 'This SKU is already used by another product.']);
        }
        throw $e;
    }

    $action === 'create'
        ? json_success('Product created successfully.', ['id' => $result], 201)
        : json_success('Product updated successfully.', ['id' => $result]);
}

$id = $v->id('id', true, 'Product');
$v->abortIfInvalid();

if ($action === 'archive' || $action === 'restore') {
    $active = $action === 'restore';
    transaction(function (PDO $pdo) use ($id, $active) {
        $stmt = $pdo->prepare('UPDATE public.products SET is_active = :a WHERE id = :id');
        $stmt->bindValue('a', $active, PDO::PARAM_BOOL);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            throw new AppException('Product not found.', 404);
        }
        sync_low_stock_alert($pdo, $id);
    });
    json_success($active ? 'Product restored.' : 'Product archived. Its sales and stock history are kept.', ['id' => $id]);
}

// delete — only for products that were never sold and never had stock changes
// (an "initial" opening-stock entry alone is allowed, e.g. a product added by mistake)
transaction(function (PDO $pdo) use ($id) {
    $stmt = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM public.sale_items WHERE product_id = :id) AS sales,
            (SELECT COUNT(*) FROM public.stock_movements WHERE product_id = :id AND movement_type <> 'initial') AS moves,
            (SELECT COUNT(*) FROM public.products WHERE id = :id) AS exists"
    );
    $stmt->execute(['id' => $id]);
    $r = $stmt->fetch();
    if ((int) $r['exists'] === 0) {
        throw new AppException('Product not found.', 404);
    }
    if ((int) $r['sales'] > 0 || (int) $r['moves'] > 0) {
        throw new AppException('This product has sales or stock history, so it cannot be deleted. Archive it instead to keep the records.', 409);
    }
    $pdo->prepare('DELETE FROM public.low_stock_alerts WHERE product_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM public.stock_movements WHERE product_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM public.products WHERE id = :id')->execute(['id' => $id]);
});
json_success('Product deleted.', ['id' => $id]);
