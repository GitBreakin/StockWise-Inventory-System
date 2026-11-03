<?php
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';
$user = require_role('admin');
$pageTitle = 'Users';
$activePage = 'users';
$pageScripts = ['users.js'];
require __DIR__ . '/includes/header.php';
?>
<section class="card">
    <div class="card-header">
        <div>
            <h2>User accounts</h2>
            <small class="muted">Admins manage products, categories, adjustments, voids and users. Staff record sales and stock in/out.</small>
        </div>
        <div class="actions"><button type="button" class="btn btn-primary" id="add-user">+ Add user</button></div>
    </div>
    <div class="table-wrap">
        <table class="responsive" id="user-table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th class="text-right">Actions</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</section>

<div class="modal" id="user-modal" role="dialog" aria-modal="true" aria-labelledby="user-modal-title" hidden>
    <div class="modal-dialog modal-sm">
        <form id="user-form" novalidate>
            <div class="modal-header"><h2 id="user-modal-title">Add user</h2><button type="button" class="icon-btn" data-modal-close aria-label="Close">&times;</button></div>
            <div class="modal-body" style="display:grid;gap:14px">
                <input type="hidden" name="id">
                <input type="hidden" name="mode">
                <div class="field" data-mode="create update">
                    <label for="u-name">Full name <span class="req">*</span></label>
                    <input id="u-name" name="full_name" maxlength="120" required>
                </div>
                <div class="field" data-mode="create">
                    <label for="u-email">Email <span class="req">*</span></label>
                    <input id="u-email" name="email" type="email" maxlength="255" required autocomplete="off">
                </div>
                <div class="field" data-mode="create update">
                    <label for="u-role">Role <span class="req">*</span></label>
                    <select id="u-role" name="role"><option value="staff">Staff</option><option value="admin">Administrator</option></select>
                </div>
                <div class="field" data-mode="create reset_password">
                    <label for="u-password">Password <span class="req">*</span></label>
                    <input id="u-password" name="password" type="password" maxlength="72" autocomplete="new-password">
                    <span class="hint">At least 8 characters with letters and numbers.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
