<?php
/**
 * Users API (admin only) — there is no public sign-up page.
 *
 * GET  api/users.php
 * POST api/users.php (JSON)
 *      create         { action, full_name, email, role, password }
 *      update         { action, id, full_name, role }
 *      set_active     { action, id, is_active }
 *      reset_password { action, id, password }
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';
api_bootstrap();
$admin = require_role('admin');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query(
        'SELECT id, full_name, email, role, is_active, last_login_at, created_at FROM public.users ORDER BY is_active DESC, lower(full_name)'
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['is_active'] = (bool) $r['is_active'];
        $r['is_me'] = $r['id'] === $admin['id'];
    }
    json_success('Users loaded.', ['items' => $rows]);
}

[$action, $data] = post_action(['create', 'update', 'set_active', 'reset_password']);
$v = new Validator($data);

/** An admin may not demote or deactivate themselves (prevents locking everyone out). */
function guard_self(int $id, array $admin, string $what): void
{
    if ($id === $admin['id']) {
        json_error("You cannot $what your own account.", 409);
    }
}

if ($action === 'create') {
    $name = $v->string('full_name', 2, 120, true, 'Full name');
    $email = $v->string('email', 3, 255);
    $role = $v->enum('role', ['admin', 'staff']);
    $password = $v->string('password', 1, 72);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $v->error('email', 'Enter a valid email address.');
    }
    if ($password !== null && ($p = password_problem((string) $data['password']))) {
        $v->error('password', $p);
    }
    $v->abortIfInvalid();
    try {
        $stmt = $pdo->prepare('INSERT INTO public.users (full_name, email, password_hash, role) VALUES (:n, :e, :h, :r) RETURNING id');
        $stmt->execute(['n' => $name, 'e' => strtolower($email), 'h' => password_hash((string) $data['password'], PASSWORD_DEFAULT), 'r' => $role]);
    } catch (PDOException $e) {
        if (pg_error_code($e) === '23505') {
            json_error('Please correct the highlighted fields.', 422, ['email' => 'This email is already registered.']);
        }
        throw $e;
    }
    json_success('User account created.', ['id' => (int) $stmt->fetchColumn()], 201);
}

$id = $v->id('id', true, 'User');

if ($action === 'update') {
    $name = $v->string('full_name', 2, 120, true, 'Full name');
    $role = $v->enum('role', ['admin', 'staff']);
    $v->abortIfInvalid();
    if ($role !== 'admin') {
        guard_self($id, $admin, 'demote');
    }
    $stmt = $pdo->prepare('UPDATE public.users SET full_name = :n, role = :r WHERE id = :id');
    $stmt->execute(['n' => $name, 'r' => $role, 'id' => $id]);
    $stmt->rowCount() ? json_success('User updated.', ['id' => $id]) : json_error('User not found.', 404);
}

if ($action === 'set_active') {
    $active = $v->bool('is_active', true);
    $v->abortIfInvalid();
    if (!$active) {
        guard_self($id, $admin, 'deactivate');
    }
    $stmt = $pdo->prepare('UPDATE public.users SET is_active = :a WHERE id = :id');
    $stmt->bindValue('a', $active, PDO::PARAM_BOOL);
    $stmt->bindValue('id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $stmt->rowCount() ? json_success($active ? 'User activated.' : 'User deactivated. They can no longer log in.', ['id' => $id]) : json_error('User not found.', 404);
}

// reset_password
$password = $v->string('password', 1, 72);
if ($password !== null && ($p = password_problem((string) $data['password']))) {
    $v->error('password', $p);
}
$v->abortIfInvalid();
$stmt = $pdo->prepare('UPDATE public.users SET password_hash = :h WHERE id = :id');
$stmt->execute(['h' => password_hash((string) $data['password'], PASSWORD_DEFAULT), 'id' => $id]);
$stmt->rowCount() ? json_success('Password updated.', ['id' => $id]) : json_error('User not found.', 404);
