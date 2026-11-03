/**
 * app.js — shared jQuery helpers used by every page.
 *
 *  App.api()      AJAX + JSON with CSRF header, loading and error handling
 *  App.toast()    success / error notifications
 *  App.modal      open / close dialogs
 *  App.confirm()  confirmation dialog for destructive actions (returns a Promise)
 *  App.showErrors / clearErrors   field-level validation messages
 *  App.busy()     loading state on buttons (prevents double submits)
 *  App.esc()      escape text before inserting HTML (XSS protection)
 */
(function ($, window) {
    'use strict';

    const csrf = $('meta[name="csrf-token"]').attr('content') || '';

    // Every jQuery AJAX request carries the CSRF token in a header.
    $.ajaxSetup({ headers: { 'X-CSRF-Token': csrf }, cache: false, timeout: 30000 });

    const App = {
        role: $('body').data('role') || null,
        isAdmin() { return this.role === 'admin'; },

        /** Escape text for safe insertion into HTML strings. */
        esc(value) {
            return String(value ?? '').replace(/[&<>"'`]/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;',
            }[c]));
        },

        money(value) {
            const n = Number(value || 0);
            return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },

        number(value) { return Number(value || 0).toLocaleString('en-PH'); },

        /** Parse Postgres timestamps like "2026-11-02 17:16:00.123456+08". */
        parseDate(value) {
            const iso = String(value).trim()
                .replace(' ', 'T')
                .replace(/(\.\d{3})\d+/, '$1')          // keep milliseconds only
                .replace(/([+-]\d{2})$/, '$1:00');        // +08 -> +08:00
            return new Date(iso);
        },

        /** "today", "yesterday" or "N days ago". */
        daysAgo(days) {
            if (days === null || days === undefined) return 'never';
            days = Number(days);
            return days === 0 ? 'today' : days === 1 ? 'yesterday' : `${days} days ago`;
        },

        dateTime(value) {
            if (!value) return '—';
            const d = App.parseDate(value);
            if (isNaN(d)) return App.esc(value);
            return d.toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
        },

        date(value) {
            if (!value) return '—';
            const d = new Date(String(value).slice(0, 10) + 'T00:00:00');
            return isNaN(d) ? App.esc(value) : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
        },

        today(offsetDays = 0) {
            const d = new Date();
            d.setDate(d.getDate() + offsetDays);
            const pad = (n) => String(n).padStart(2, '0');
            return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        },

        debounce(fn, wait = 300) {
            let t;
            return function (...args) {
                clearTimeout(t);
                t = setTimeout(() => fn.apply(this, args), wait);
            };
        },

        /**
         * AJAX request that sends and receives JSON.
         *   GET:  App.api('GET', 'api/products.php', { search: 'cola' })
         *   POST: App.api('POST', 'api/products.php', { action: 'create', ... })
         * Resolves with the parsed JSON envelope; rejects with { message, errors, status }.
         */
        api(method, url, data) {
            const isGet = method.toUpperCase() === 'GET';
            const deferred = $.Deferred();

            $.ajax({
                url,
                method,
                dataType: 'json',
                data: isGet ? data : JSON.stringify(data || {}),   // JSON.stringify = serialize to JSON
                contentType: isGet ? undefined : 'application/json; charset=utf-8',
            })
                .done((res) => {
                    if (res && res.success) {
                        deferred.resolve(res);
                    } else {
                        deferred.reject({ message: (res && res.message) || 'Request failed.', errors: (res && res.errors) || {}, status: 200 });
                    }
                })
                .fail((xhr, textStatus) => {
                    let err = { message: 'Something went wrong. Please try again.', errors: {}, status: xhr.status };
                    if (textStatus === 'timeout') {
                        err.message = 'The server took too long to respond. Check your connection and try again.';
                    } else if (xhr.status === 0) {
                        err.message = 'Network error: cannot reach the server. Check your internet connection.';
                    } else if (xhr.responseJSON && typeof xhr.responseJSON === 'object') {
                        err.message = xhr.responseJSON.message || err.message;
                        err.errors = xhr.responseJSON.errors || {};
                    } else if (textStatus === 'parsererror') {
                        err.message = 'The server sent an invalid response (malformed JSON).';
                    }
                    if (xhr.status === 401) {
                        App.toast('error', err.message);
                        setTimeout(() => { window.location.href = 'login.php'; }, 1200);
                    }
                    deferred.reject(err);
                });

            return deferred.promise();
        },

        /** Download a CSV through AJAX (keeps the session + shows errors as JSON messages). */
        download(url, params, $btn) {
            App.busy($btn, true);
            $.ajax({ url, method: 'GET', data: params, xhrFields: { responseType: 'blob' } })
                .done((blob, _status, xhr) => {
                    const header = xhr.getResponseHeader('Content-Disposition') || '';
                    const match = header.match(/filename="([^"]+)"/);
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = match ? match[1] : 'report.csv';
                    document.body.appendChild(link);
                    link.click();
                    setTimeout(() => { URL.revokeObjectURL(link.href); link.remove(); }, 500);
                    App.toast('success', 'CSV downloaded.');
                })
                .fail((xhr) => {
                    // Error responses are JSON, but arrive as a Blob here.
                    const blob = xhr.response;
                    if (blob && blob.text) {
                        blob.text().then((text) => {
                            try { App.toast('error', JSON.parse(text).message); } catch (e) { App.toast('error', 'Export failed.'); }
                        });
                    } else {
                        App.toast('error', 'Export failed. Check your connection.');
                    }
                })
                .always(() => App.busy($btn, false));
        },

        toast(type, message, timeout = 4000) {
            const $t = $('<div class="toast" role="status"></div>').addClass('toast-' + type);
            $('<div></div>').text(message).appendTo($t);
            $('<button type="button" class="toast-close" aria-label="Dismiss">&times;</button>').appendTo($t);
            $('#toast-stack').append($t.hide().fadeIn(150));
            const remove = () => $t.fadeOut(200, () => $t.remove());
            $t.on('click', '.toast-close', remove);
            if (timeout) setTimeout(remove, type === 'error' ? timeout + 2000 : timeout);
        },

        modal: {
            open(selector) {
                const $m = $(selector);
                $m.removeAttr('hidden').hide().fadeIn(120);
                $('body').addClass('modal-open');
                // Focus the first field — unless the user already clicked into the dialog.
                setTimeout(() => {
                    if (!$.contains($m[0], document.activeElement)) {
                        $m.find('input:not([type=hidden]),select,textarea').filter(':visible').first().trigger('focus');
                    }
                }, 140);
                return $m;
            },
            close(selector) {
                const $m = $(selector);
                $m.fadeOut(120, () => {
                    $m.attr('hidden', true);
                    if (!$('.modal:visible').length) $('body').removeClass('modal-open');
                });
            },
        },

        /** Promise-based confirmation dialog. */
        confirm({ title = 'Are you sure?', message = '', confirmText = 'Confirm', danger = true } = {}) {
            return new Promise((resolve) => {
                const $m = $('#confirm-modal');
                $m.find('#confirm-title').text(title);
                $m.find('#confirm-message').text(message);
                $m.find('[data-confirm="yes"]').text(confirmText).toggleClass('btn-danger', danger).toggleClass('btn-primary', !danger);
                App.modal.open($m);
                $m.off('click.confirm').on('click.confirm', '[data-confirm]', function () {
                    App.modal.close($m);
                    resolve($(this).data('confirm') === 'yes');
                });
            });
        },

        /** Show { field: message } validation errors under matching inputs. */
        showErrors($form, errors) {
            App.clearErrors($form);
            $.each(errors || {}, (field, message) => {
                const $input = $form.find(`[name="${field}"]`);
                if ($input.length) {
                    $input.addClass('is-invalid').attr('aria-invalid', 'true');
                    $('<div class="field-error"></div>').text(message).insertAfter($input);
                }
            });
            $form.find('.is-invalid').first().trigger('focus');
        },

        clearErrors($form) {
            $form.find('.is-invalid').removeClass('is-invalid').removeAttr('aria-invalid');
            $form.find('.field-error').remove();
        },

        /** Loading state for a button: disables it and shows a spinner (stops double submits). */
        busy($btn, on) {
            if (!$btn || !$btn.length) return;
            if (on) {
                $btn.data('label', $btn.html()).prop('disabled', true)
                    .html('<span class="spinner" aria-hidden="true"></span> Please wait…');
            } else {
                $btn.prop('disabled', false).html($btn.data('label') || $btn.html());
            }
        },

        /** Form fields → plain object (for JSON). */
        formData($form) {
            const data = {};
            $.each($form.serializeArray(), (_, f) => { data[f.name] = f.value; });
            return data;
        },

        loadingRow(cols, text = 'Loading…') {
            return `<tr class="loading-row"><td colspan="${cols}"><span class="spinner"></span> ${App.esc(text)}</td></tr>`;
        },

        emptyRow(cols, title, hint = '') {
            return `<tr class="empty-row"><td colspan="${cols}"><div class="empty-state"><strong>${App.esc(title)}</strong>${App.esc(hint)}</div></td></tr>`;
        },

        errorRow(cols, message) {
            return `<tr class="empty-row"><td colspan="${cols}"><div class="alert alert-error">${App.esc(message)}</div></td></tr>`;
        },

        /** Render Previous / Next pagination; calls onPage(page). */
        pagination($el, meta, onPage) {
            if (!meta) { $el.empty(); return; }
            const from = meta.total === 0 ? 0 : (meta.page - 1) * meta.per_page + 1;
            const to = Math.min(meta.page * meta.per_page, meta.total);
            $el.html(`
                <span class="info">Showing ${from}–${to} of ${App.number(meta.total)}</span>
                <button type="button" class="btn btn-secondary btn-sm" data-page="${meta.page - 1}" ${meta.page <= 1 ? 'disabled' : ''}>‹ Previous</button>
                <span class="muted">Page ${meta.page} of ${meta.total_pages}</span>
                <button type="button" class="btn btn-secondary btn-sm" data-page="${meta.page + 1}" ${meta.page >= meta.total_pages ? 'disabled' : ''}>Next ›</button>
            `);
            $el.off('click.page').on('click.page', '[data-page]', function () { onPage(Number($(this).data('page'))); });
        },

        movementBadge(type) {
            const map = {
                stock_in: ['Stock in', 'badge-success'], stock_out: ['Stock out', 'badge-warning'],
                adjustment: ['Adjustment', 'badge-info'], sale: ['Sale', 'badge-primary'],
                sale_void: ['Sale voided', 'badge-danger'], initial: ['Opening stock', ''],
            };
            const [label, cls] = map[type] || [type, ''];
            return `<span class="badge ${cls}">${App.esc(label)}</span>`;
        },

        stockBadge(qty, reorder) {
            qty = Number(qty); reorder = Number(reorder);
            if (qty === 0) return '<span class="badge badge-danger">Out of stock</span>';
            if (qty <= reorder) return '<span class="badge badge-warning">Low stock</span>';
            return '<span class="badge badge-success">In stock</span>';
        },

        signed(n) {
            n = Number(n);
            return (n > 0 ? '+' : '') + App.number(n);
        },
    };

    // ---- Global UI behaviour (event delegation) ----
    $(document)
        .on('click', '[data-modal-close]', function () { App.modal.close($(this).closest('.modal')); })
        .on('click', '.modal', function (e) { if (e.target === this && this.id !== 'confirm-modal') App.modal.close(this); })
        .on('keydown', function (e) {
            if (e.key === 'Escape') {
                const $open = $('.modal:visible').last();
                if ($open.length && $open.attr('id') !== 'confirm-modal') App.modal.close($open);
                $('body').removeClass('sidebar-open');
            }
        })
        .on('click', '[data-sidebar-toggle]', () => $('body').toggleClass('sidebar-open'))
        .on('click', '[data-sidebar-close]', () => $('body').removeClass('sidebar-open'))
        .on('input change', '.is-invalid', function () {
            $(this).removeClass('is-invalid').removeAttr('aria-invalid').next('.field-error').remove();
        });

    // Low-stock count badge in the sidebar (only on logged-in pages).
    if ($('[data-low-stock-badge]').length) {
        App.api('GET', 'api/inventory.php', { view: 'low_stock' }).done((res) => {
            const n = res.data.items.length;
            $('[data-low-stock-badge]').text(n).prop('hidden', n === 0).attr('title', n + ' product(s) need restocking');
        });
    }

    window.App = App;
})(jQuery, window);
