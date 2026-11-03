<?php
/**
 * Categories API
 *
 * GET  api/categories.php                → all categories with product counts
 * POST api/categories.php  (JSON, admin)
 *      { "action": "create", "name": "...", "description": "..." }
 *      { "action": "update", "id": 1, "name": "...", "description": "..." }
 *      { "action": "delete", "id": 1 }     → only when no product uses it
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';
$user = api_bootstrap();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query(
        'SELECT c.id, c.name, c.description, c.created_at, c.updated_at,
                COUNT(p.id)::int AS product_count,
                COUNT(p.id) FILTER (WHERE p.is_active)::int AS active_product_count
         FROM public.categories c
         LEFT JOIN public.products p ON p.category_id = c.id
         GROUP BY c.id
         ORDER BY lower(c.name)'
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
    }
    json_success('Categories loaded.', ['items' => $rows]);
}

require_role('admin');
[$action, $data] = post_action(['create', 'update', 'delete']);
$v = new Validator($data);

/** Friendly duplicate-name check (the unique index is the final guarantee). */
function category_name_taken(PDO $pdo, string $name, ?int $exceptId = null): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM public.categories WHERE lower(name) = lower(:n) AND id <> COALESCE(:id, 0)');
    $stmt->execute(['n' => $name, 'id' => $exceptId]);
    return (bool) $stmt->fetchColumn();
}

if ($action === 'create' || $action === 'update') {
    $id = $action === 'update' ? $v->id('id', true, 'Category') : null;
    $name = $v->string('name', 2, 80);
    $description = $v->string('description', 0, 500, false);
    if ($name !== null && category_name_taken($pdo, $name, $id)) {
        $v->error('name', 'A category with this name already exists.');
    }
    $v->abortIfInvalid();

    try {
        if ($action === 'create') {
            $stmt = $pdo->prepare('INSERT INTO public.categories (name, description) VALUES (:n, :d) RETURNING id');
            $stmt->execute(['n' => $name, 'd' => $description]);
            json_success('Category created successfully.', ['id' => (int) $stmt->fetchColumn()], 201);
        }
        $stmt = $pdo->prepare('UPDATE public.categories SET name = :n, description = :d WHERE id = :id');
        $stmt->execute(['n' => $name, 'd' => $description, 'id' => $id]);
        if ($stmt->rowCount() === 0) {
            json_error('Category not found.', 404);
        }
        json_success('Category updated successfully.', ['id' => $id]);
    } catch (PDOException $e) {
        if (pg_error_code($e) === '23505') {
            json_error('Please correct the highlighted fields.', 422, ['name' => 'A category with this name already exists.']);
        }
        throw $e;
    }
}

// delete
$id = $v->id('id', true, 'Category');
$v->abortIfInvalid();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM public.products WHERE category_id = :id');
$stmt->execute(['id' => $id]);
$used = (int) $stmt->fetchColumn();
if ($used > 0) {
    json_error("This category is used by $used product(s), including archived ones. Move those products first.", 409);
}
$stmt = $pdo->prepare('DELETE FROM public.categories WHERE id = :id');
$stmt->execute(['id' => $id]);
if ($stmt->rowCount() === 0) {
    json_error('Category not found.', 404);
}
json_success('Category deleted.', ['id' => $id]);
