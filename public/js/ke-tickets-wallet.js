/**
 * Kiwi Events — Customer ticket wallet [kiwi_tickets_purchase]
 * Tabs, event-card accordion, and QR modal. Vanilla JS, no dependencies.
 */
(function () {
    'use strict';

    function initWallet(root) {
        var tiles  = root.querySelectorAll('[data-ke-tw-tab]');
        var panels = root.querySelectorAll('[data-ke-tw-panel]');
        var modal  = root.querySelector('[data-ke-tw-modal]');

        /* ── Tabs ── */
        tiles.forEach(function (tile) {
            tile.addEventListener('click', function () {
                var key = tile.getAttribute('data-ke-tw-tab');

                tiles.forEach(function (t) {
                    var active = t === tile;
                    t.classList.toggle('is-active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                });

                panels.forEach(function (p) {
                    var active = p.getAttribute('data-ke-tw-panel') === key;
                    p.classList.toggle('is-active', active);
                    if (active) {
                        p.removeAttribute('hidden');
                    } else {
                        p.setAttribute('hidden', '');
                    }
                });
            });
        });

        /* ── Accordion ── */
        root.querySelectorAll('[data-ke-tw-toggle]').forEach(function (head) {
            head.addEventListener('click', function () {
                var card = head.closest('.ke-tw-card');
                var open = card.classList.toggle('is-open');
                head.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });

        /* ── QR modal ── */
        if (!modal) {
            return;
        }

        var img       = modal.querySelector('[data-ke-tw-modal-img]');
        var typeEl    = modal.querySelector('[data-ke-tw-modal-type]');
        var codeEl    = modal.querySelector('[data-ke-tw-modal-code]');
        var eventEl   = modal.querySelector('[data-ke-tw-modal-event]');
        var dateEl    = modal.querySelector('[data-ke-tw-modal-date]');
        var lastFocus = null;

        function openModal(btn) {
            lastFocus       = btn;
            img.src         = btn.getAttribute('data-ke-tw-qr');
            typeEl.textContent  = btn.getAttribute('data-ke-tw-type') || '';
            codeEl.textContent  = '#' + (btn.getAttribute('data-ke-tw-code') || '');
            eventEl.textContent = btn.getAttribute('data-ke-tw-event') || '';
            dateEl.textContent  = btn.getAttribute('data-ke-tw-date') || '';
            modal.removeAttribute('hidden');
            document.body.style.overflow = 'hidden';
            var close = modal.querySelector('.ke-tw-btn--close');
            if (close) {
                close.focus();
            }
        }

        function closeModal() {
            modal.setAttribute('hidden', '');
            img.removeAttribute('src');
            document.body.style.overflow = '';
            if (lastFocus) {
                lastFocus.focus();
                lastFocus = null;
            }
        }

        root.querySelectorAll('[data-ke-tw-qr]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openModal(btn);
            });
        });

        modal.querySelectorAll('[data-ke-tw-modal-close]').forEach(function (el) {
            el.addEventListener('click', closeModal);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hasAttribute('hidden')) {
                closeModal();
            }
        });
    }

    function boot() {
        document.querySelectorAll('[data-ke-tw]').forEach(initWallet);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
