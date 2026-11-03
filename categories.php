<?php
declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';
$user = require_login();
$pageTitle = 'Categories';
$activePage = 'categories';
$pageScripts = ['categories.js'];
require __DIR__ . '/includes/header.php';
?>
<section class="card">
    <div class="card-header">
        <div class="field field-grow" style="flex:1;max-width:360px">
            <label for="cat-search" class="sr-only">Search categories</label>
            <input type="search" id="cat-search" placeholder="Filter categories…">
        </div>
        <?php if (is_admin()): ?>
            <div class="actions"><button type="button" class="btn btn-primary" id="add-category">+ Add category</button></div>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="responsive" id="category-table">
            <thead>
            <tr><th>Name</th><th>Description</th><th class="num">Products</th><th>Created</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</section>

<div class="modal" id="category-modal" role="dialog" aria-modal="true" aria-labelledby="category-modal-title" hidden>
    <div class="modal-dialog modal-sm">
        <form id="category-form" novalidate>
            <div class="modal-header">
                <h2 id="category-modal-title">Add category</h2>
                <button type="button" class="icon-btn" data-modal-close aria-label="Close">&times;</button>
            </div>
            <div class="modal-body" style="display:grid;gap:14px">
                <input type="hidden" name="id">
                <div class="field">
                    <label for="c-name">Name <span class="req">*</span></label>
                    <input id="c-name" name="name" required maxlength="80">
                </div>
                <div class="field">
                    <label for="c-description">Description</label>
                    <textarea id="c-description" name="description" maxlength="500" rows="3"></textarea>
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
