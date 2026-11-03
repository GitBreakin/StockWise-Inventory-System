/** users.js — admin user management through AJAX + JSON. */
$(function () {
    const esc = App.esc;
    const $tb = $('#user-table tbody');
    const $form = $('#user-form');
    let users = [];

    function load() {
        $tb.html(App.loadingRow(6));
        App.api('GET', 'api/users.php')
            .done((res) => {
                users = res.data.items;
                $tb.html(users.map((u) => `<tr class="${u.is_active ? '' : 'is-archived'}">
                    <td data-label="Name"><strong>${esc(u.full_name)}</strong>${u.is_me ? ' <span class="badge">You</span>' : ''}</td>
                    <td data-label="Email">${esc(u.email)}</td>
                    <td data-label="Role"><span class="badge ${u.role === 'admin' ? 'badge-primary' : ''}">${u.role === 'admin' ? 'Admin' : 'Staff'}</span></td>
                    <td data-label="Status">${u.is_active ? '<span class="badge badge-success">Active</span>' : '<span class="badge">Inactive</span>'}</td>
                    <td data-label="Last login">${App.dateTime(u.last_login_at)}</td>
                    <td data-label="Actions"><div class="row-actions">
                        <button type="button" class="btn btn-secondary btn-sm" data-action="update" data-id="${u.id}">Edit</button>
                        <button type="button" class="btn btn-ghost btn-sm" data-action="reset_password" data-id="${u.id}">Reset password</button>
                        ${u.is_me ? '' : `<button type="button" class="btn btn-ghost btn-sm" data-action="toggle" data-id="${u.id}">${u.is_active ? 'Deactivate' : 'Activate'}</button>`}
                    </div></td>
                </tr>`).join(''));
            })
            .fail((err) => $tb.html(App.errorRow(6, err.message)));
    }

    function openForm(mode, user) {
        $form[0].reset();
        App.clearErrors($form);
        $form.find('[name=mode]').val(mode);
        $form.find('[name=id]').val(user ? user.id : '');
        $form.find('[data-mode]').each(function () {
            const show = $(this).data('mode').split(' ').includes(mode);
            $(this).prop('hidden', !show).find('input,select').prop('disabled', !show);
        });
        if (user) { $('#u-name').val(user.full_name); $('#u-role').val(user.role); }
        $('#user-modal-title').text({ create: 'Add user', update: 'Edit user', reset_password: `Reset password for ${user ? user.full_name : ''}` }[mode]);
        App.modal.open('#user-modal');
    }

    $('#add-user').on('click', () => openForm('create'));

    $tb.on('click', '[data-action]', function () {
        const user = users.find((u) => u.id === $(this).data('id'));
        const action = $(this).data('action');
        if (action !== 'toggle') return openForm(action, user);

        const activate = !user.is_active;
        App.confirm({
            title: activate ? 'Activate user?' : 'Deactivate user?',
            message: activate ? `${user.full_name} will be able to log in again.` : `${user.full_name} will no longer be able to log in. Their past records are kept.`,
            confirmText: activate ? 'Activate' : 'Deactivate', danger: !activate,
        }).then((ok) => {
            if (!ok) return;
            App.api('POST', 'api/users.php', { action: 'set_active', id: user.id, is_active: activate })
                .done((res) => { App.toast('success', res.message); load(); })
                .fail((err) => App.toast('error', err.message));
        });
    });

    $form.on('submit', function (e) {
        e.preventDefault();
        const $btn = $form.find('[type=submit]');
        if ($btn.prop('disabled')) return;
        const data = App.formData($form);
        const mode = data.mode;
        delete data.mode;
        if (!data.id) delete data.id; else data.id = Number(data.id);

        const errors = {};
        if (data.full_name !== undefined && data.full_name.trim().length < 2) errors.full_name = 'Enter the full name.';
        if (data.email !== undefined && !/^\S+@\S+\.\S+$/.test(data.email)) errors.email = 'Enter a valid email address.';
        if (data.password !== undefined && (data.password.length < 8 || !/[A-Za-z]/.test(data.password) || !/\d/.test(data.password))) {
            errors.password = 'At least 8 characters with letters and numbers.';
        }
        if (Object.keys(errors).length) return App.showErrors($form, errors);

        data.action = mode;
        App.busy($btn, true);
        App.api('POST', 'api/users.php', data)
            .done((res) => { App.toast('success', res.message); App.modal.close('#user-modal'); load(); })
            .fail((err) => { App.showErrors($form, err.errors); App.toast('error', err.message); })
            .always(() => App.busy($btn, false));
    });

    load();
});
