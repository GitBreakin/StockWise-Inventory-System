/** login.js — AJAX login: sends JSON, reads the JSON reply, redirects on success. */
$(function () {
    const $form = $('#login-form');
    const $error = $('#login-error');

    $form.on('submit', function (e) {
        e.preventDefault();
        App.clearErrors($form);
        $error.prop('hidden', true);

        const data = App.formData($form);
        // Client-side check for quick feedback (the server validates again)
        const errors = {};
        if (!/^\S+@\S+\.\S+$/.test(data.email)) errors.email = 'Enter a valid email address.';
        if (!data.password) errors.password = 'Password is required.';
        if (Object.keys(errors).length) return App.showErrors($form, errors);

        const $btn = $form.find('[type=submit]');
        App.busy($btn, true);
        App.api('POST', 'api/login.php', data)
            .done((res) => {
                App.toast('success', res.message);
                window.location.href = res.data.redirect;
            })
            .fail((err) => {
                App.busy($btn, false);
                if (err.status === 422) App.showErrors($form, err.errors);
                $error.text(err.message).prop('hidden', false);
                $form.find('#password').val('').trigger('focus');
            });
    });
});
