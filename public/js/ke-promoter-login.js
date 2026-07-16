/**
 * Promoter portal login modal — submit handler.
 *
 * Posts JSON to /wp-json/ke/v1/promoter-login. On success, reloads the page
 * (the portal handler will then see is_user_logged_in() === true and render
 * the dashboard). On failure, surfaces the server's error message inline
 * without dismissing the modal. The "Cancel" link / backdrop / X button send
 * the user back home.
 */
(function () {
    'use strict';

    var cfg = window.kePromoterLogin || {};
    if (!cfg.restUrl) return;

    var form    = document.getElementById('ke-promoter-login-form');
    var modal   = document.getElementById('ke-promoter-login-modal');
    var errBox  = document.getElementById('ke-login-error');
    var submit  = document.getElementById('ke-login-submit');
    if (!form || !modal || !submit) return;

    var originalLabel = submit.textContent;

    function showError(msg) {
        if (!errBox) return;
        errBox.textContent = msg;
        errBox.hidden = false;
    }

    function clearError() {
        if (!errBox) return;
        errBox.textContent = '';
        errBox.hidden = true;
    }

    function goHome(ev) {
        if (ev) ev.preventDefault();
        window.location.href = cfg.home || '/';
    }

    Array.prototype.forEach.call(
        modal.querySelectorAll('[data-ke-login-close], [data-ke-login-close-link]'),
        function (el) { el.addEventListener('click', goHome); }
    );

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') goHome(ev);
    });

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        clearError();

        var email = (form.querySelector('[name="email"]') || {}).value || '';
        var pw    = (form.querySelector('[name="password"]') || {}).value || '';
        var slug  = (form.querySelector('[name="slug"]') || {}).value || '';

        if (!email || !pw) {
            showError(cfg.i18n && cfg.i18n.genericErr || 'Sign-in failed.');
            return;
        }

        submit.disabled = true;
        submit.textContent = (cfg.i18n && cfg.i18n.submitting) || 'Signing in…';

        fetch(cfg.restUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce || ''
            },
            body: JSON.stringify({ email: email, password: pw, slug: slug })
        })
        .then(function (res) {
            return res.json().then(function (data) {
                return { status: res.status, data: data };
            });
        })
        .then(function (r) {
            if (r.status >= 200 && r.status < 300 && r.data && r.data.success) {
                window.location.reload();
                return;
            }
            var msg = (r.data && r.data.message) || (cfg.i18n && cfg.i18n.genericErr) || 'Sign-in failed.';
            showError(msg);
            submit.disabled = false;
            submit.textContent = originalLabel;
        })
        .catch(function () {
            showError((cfg.i18n && cfg.i18n.genericErr) || 'Sign-in failed.');
            submit.disabled = false;
            submit.textContent = originalLabel;
        });
    });
})();
