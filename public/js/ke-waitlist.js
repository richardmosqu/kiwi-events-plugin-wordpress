/**
 * KiwiEvents — scheduled ticket sales notice + waitlist form.
 *
 * Rendered by public/views/sales-waitlist.php. Vanilla JS on purpose (no
 * jQuery dependency) so a broken/removed jQuery on a theme can't take the
 * countdown with it, mirroring public/views/extras/countdown.php.
 *
 * Two jobs:
 *   1. Count down to the opening moment. The target is an absolute ISO-8601
 *      instant computed server-side in the event's configured timezone, so a
 *      visitor in another timezone (or with a skewed clock) still sees the
 *      right remaining time relative to their own device.
 *   2. Capture waitlist emails. The POST carries NO nonce on purpose: the
 *      surrounding page is edge-cached for logged-out visitors and a stale
 *      baked nonce would be rejected outright by WordPress. The endpoint is
 *      public, rate-limited per IP and deduped by a UNIQUE index.
 *
 * When the countdown hits zero the page is NOT trusted to be fresh — the
 * server is asked whether sales really opened before reloading.
 */
(function () {
    'use strict';

    var RECHECK_MS = 15000; // how often to re-ask the server after T-0

    function init() {
        var root = document.getElementById('ke-sales-gate');
        if (!root) return;

        var eventId = parseInt(root.getAttribute('data-event-id'), 10) || 0;
        var restUrl = root.getAttribute('data-rest') || '/wp-json/ke/v1/';
        var opensAt = root.getAttribute('data-opens-at') || '';

        if (restUrl.charAt(restUrl.length - 1) !== '/') restUrl += '/';

        startCountdown(root, opensAt, function () {
            verifyOpen(restUrl, eventId);
        });

        bindForm(root, restUrl, eventId);
    }

    /* ── Countdown ──────────────────────────────────────────────────── */

    function startCountdown(root, iso, onExpire) {
        var box = root.querySelector('#ke-sg-countdown');
        if (!box || !iso) return;

        var target = new Date(iso).getTime();
        if (isNaN(target)) return;

        var cells = {};
        ['d', 'h', 'm', 's'].forEach(function (u) {
            cells[u] = box.querySelector('[data-unit="' + u + '"]');
        });

        var expired = false;
        var timer   = null;

        function tick() {
            var diff = target - Date.now();

            if (diff <= 0) {
                setCells(cells, 0, 0, 0, 0);
                if (!expired) {
                    expired = true;
                    if (timer) clearInterval(timer);
                    onExpire();
                    // Keep asking — a slow WP-Cron or a stale cache should not
                    // strand the visitor on a dead countdown.
                    setInterval(onExpire, RECHECK_MS);
                }
                return;
            }

            var s = Math.floor(diff / 1000);
            setCells(
                cells,
                Math.floor(s / 86400),
                Math.floor((s % 86400) / 3600),
                Math.floor((s % 3600) / 60),
                s % 60
            );
        }

        box.hidden = false;
        tick();
        // A page that loads already past the opening moment (a stale
        // edge-cached render — the case this whole block exists for) must not
        // leave a 1s ticker running behind the re-check loop.
        if (!expired) timer = setInterval(tick, 1000);
    }

    function setCells(cells, d, h, m, s) {
        if (cells.d) cells.d.textContent = d;
        if (cells.h) cells.h.textContent = pad(h);
        if (cells.m) cells.m.textContent = pad(m);
        if (cells.s) cells.s.textContent = pad(s);
    }

    function pad(n) {
        return n < 10 ? '0' + n : String(n);
    }

    /* ── Freshness check ────────────────────────────────────────────── */

    function verifyOpen(restUrl, eventId) {
        if (!eventId) return;
        fetch(restUrl + 'events/' + eventId + '/sale-status', {
            method: 'GET',
            cache: 'no-store',
            credentials: 'omit'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.open) reloadFresh();
            })
            .catch(function () { /* offline / blocked — try again on the next tick */ });
    }

    /**
     * Reload past the edge cache. WordPress.com serves anonymous HTML from a
     * CDN, so a plain reload can return the very page that says "not yet".
     * A one-off query param is the reliable way through; if it is already
     * there, the cache has been bypassed and a plain reload is enough.
     */
    function reloadFresh() {
        try {
            var url = new URL(window.location.href);
            if (url.searchParams.has('ke_sale')) {
                window.location.reload();
                return;
            }
            url.searchParams.set('ke_sale', '1');
            window.location.replace(url.toString());
        } catch (e) {
            window.location.reload();
        }
    }

    /* ── Waitlist form ──────────────────────────────────────────────── */

    function bindForm(root, restUrl, eventId) {
        var form = root.querySelector('#ke-sg-form');
        if (!form) return;

        var input  = form.querySelector('#ke-sg-email');
        var hp     = form.querySelector('#ke-sg-trap');
        var button = form.querySelector('#ke-sg-submit');
        var msg    = form.querySelector('#ke-sg-msg');
        var busy   = false;

        // Prefill from the checkout localize payload when the theme/cache
        // stripped the server-rendered value.
        try {
            if (input && !input.value && window.kePublic && window.kePublic.user && window.kePublic.user.email) {
                input.value = window.kePublic.user.email;
            }
        } catch (e) { /* kePublic is optional */ }

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (busy) return;

            var email = (input && input.value ? input.value : '').trim();
            if (!email || email.indexOf('@') < 1 || email.indexOf('.') < 0) {
                showMsg(msg, 'error', 'Escribe un correo electrónico válido.');
                if (input) input.focus();
                return;
            }

            busy = true;
            if (button) {
                button.disabled = true;
                button.dataset.label = button.textContent;
                button.textContent = 'Enviando…';
            }
            hideMsg(msg);

            fetch(restUrl + 'events/' + eventId + '/waitlist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'omit',
                body: JSON.stringify({
                    email: email,
                    ke_hp: hp ? hp.value : ''
                })
            })
                .then(function (r) {
                    return r.json().then(function (data) {
                        return { ok: r.ok, status: r.status, data: data };
                    });
                })
                .then(function (res) {
                    if (res.ok && res.data && res.data.success) {
                        form.classList.add('is-done');
                        showMsg(msg, 'ok', res.data.message || '¡Listo! Te avisaremos por correo.');
                        return;
                    }

                    var code = res.data && res.data.code ? res.data.code : '';
                    var text = (res.data && res.data.message) || 'No pudimos guardarte en la lista. Intenta de nuevo.';

                    if (code === 'sales_already_open') {
                        showMsg(msg, 'ok', text);
                        setTimeout(reloadFresh, 1500);
                        return;
                    }
                    showMsg(msg, 'error', text);
                })
                .catch(function () {
                    showMsg(msg, 'error', 'No pudimos conectar. Revisa tu conexión e intenta de nuevo.');
                })
                .then(function () {
                    busy = false;
                    if (button) {
                        button.disabled = false;
                        if (button.dataset.label) button.textContent = button.dataset.label;
                    }
                });
        });
    }

    function showMsg(el, kind, text) {
        if (!el) return;
        // Unhide first: a live region that is still `hidden` when its text
        // changes is not reliably announced by screen readers.
        el.className = 'ke-sg-msg ke-sg-msg--' + (kind === 'ok' ? 'ok' : 'error');
        el.hidden = false;
        el.textContent = text;
    }

    function hideMsg(el) {
        if (!el) return;
        el.hidden = true;
        el.textContent = '';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
