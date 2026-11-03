/** categories.js — list, filter, create, edit and safely delete categories via AJAX. */
$(function () {
    const esc = App.esc;
    const $table = $('#category-table tbody');
    const $form = $('#category-form');
    let categories = [];

    function load() {
        $table.html(App.loadingRow(5));
        App.api('GET', 'api/categories.php')
            .done((res) => { categories = res.data.items; render(); })
            .fail((err) => $table.html(App.errorRow(5, err.message)));
    }

    function render() {
        const q = $('#cat-search').val().trim().toLowerCase();
        const rows = categories.filter((c) => !q || c.name.toLowerCase().includes(q) || (c.description || '').toLowerCase().includes(q));
        if (!rows.length) {
            $table.html(App.emptyRow(5, q ? 'No categories match' : 'No categories yet', q ? '' : ' Add one to start organizing products.'));
            return;
        }
        $table.html(rows.map((c) => {
            const actions = App.isAdmin()
                ? `<button type="button" class="btn btn-secondary btn-sm" data-action="edit" data-id="${c.id}">Edit</button>
                   <button type="button" class="btn btn-ghost btn-sm" data-action="delete" data-id="${c.id}"
                       ${c.product_count > 0 ? `disabled title="Used by ${c.product_count} product(s)"` : ''}>Delete</button>`
                : '';
            return `<tr>
                <td data-label="Name"><strong>${esc(c.name)}</strong></td>
                <td data-label="Description">${esc(c.description || '—')}</td>
                <td data-label="Products" class="num"><a href="products.php?category_id=${c.id}">${c.active_product_count}</a>
                    ${c.product_count > c.active_product_count ? `<span class="muted">(+${c.product_count - c.active_product_count} archived)</span>` : ''}</td>
                <td data-label="Created">${App.date(c.created_at)}</td>
                <td data-label="Actions"><div class="row-actions">${actions}</div></td>
            </tr>`;
        }).join(''));
    }

    $('#cat-search').on('input', render);   // instant client-side filter of loaded rows

    function openForm(cat) {
        $form[0].reset();
        App.clearErrors($form);
        $form.find('[name=id]').val(cat ? cat.id : '');
        $form.find('[name=name]').val(cat ? cat.name : '');
        $form.find('[name=description]').val(cat ? cat.description || '' : '');
        $('#category-modal-title').text(cat ? 'Edit category' : 'Add category');
        App.modal.open('#category-modal');
    }

    $('#add-category').on('click', () => openForm(null));

    $form.on('submit', function (e) {
        e.preventDefault();
        const $btn = $form.find('[type=submit]');
        if ($btn.prop('disabled')) return;
        const data = App.formData($form);
        if (data.name.trim().length < 2) return App.showErrors($form, { name: 'Name must be at least 2 characters.' });
        const dup = categories.find((c) => c.name.toLowerCase() === data.name.trim().toLowerCase() && String(c.id) !== data.id);
        if (dup) return App.showErrors($form, { name: 'A category with this name already exists.' });

        data.action = data.id ? 'update' : 'create';
        if (!data.id) delete data.id;
        App.busy($btn, true);
        App.api('POST', 'api/categories.php', data)
            .done((res) => { App.toast('success', res.message); App.modal.close('#category-modal'); load(); })
            .fail((err) => { App.showErrors($form, err.errors); App.toast('error', err.message); })
            .always(() => App.busy($btn, false));
    });

    $table.on('click', '[data-action]', function () {
        const id = $(this).data('id');
        const cat = categories.find((c) => c.id === id);
        if ($(this).data('action') === 'edit') return openForm(cat);

        App.confirm({ title: 'Delete category?', message: `“${cat.name}” will be permanently deleted.`, confirmText: 'Delete' })
            .then((ok) => {
                if (!ok) return;
                App.api('POST', 'api/categories.php', { action: 'delete', id })
                    .done((res) => { App.toast('success', res.message); load(); })
                    .fail((err) => App.toast('error', err.message));
            });
    });

    load();
});
