/**
 * Kiwi Events — Board admin: reject-reason modal, trash-confirm modal,
 * comment panel toggles, sort select. Vanilla JS.
 */
(function () {
    'use strict';

    function openModal(modal) {
        modal.removeAttribute('hidden');
        var focusable = modal.querySelector('textarea, button:not([data-modal-close])');
        if (focusable) focusable.focus();
    }

    function closeModal(modal) {
        modal.setAttribute('hidden', '');
    }

    function wireModal(modal) {
        if (!modal) return;
        modal.querySelectorAll('[data-modal-close]').forEach(function (el) {
            el.addEventListener('click', function () { closeModal(modal); });
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.ke-board-modal:not([hidden])').forEach(closeModal);
    });

    /* ── Reject modal — moves the per-post nonce into the form on open ── */
    var rejectModal = document.getElementById('ke-board-reject-modal');
    wireModal(rejectModal);

    document.querySelectorAll('.ke-board-reject-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!rejectModal) return;
            var postId = btn.getAttribute('data-post-id');
            document.getElementById('ke-board-reject-post-id').value = postId;
            document.getElementById('ke-board-reject-subtitle').textContent = btn.getAttribute('data-post-title') || '';
            document.getElementById('ke-board-reject-reason').value = '';

            var slot  = document.getElementById('ke-board-reject-nonce-slot');
            var nonce = document.getElementById('ke-board-reject-nonce-' + postId);
            slot.innerHTML = '';
            if (nonce) slot.innerHTML = nonce.innerHTML;

            openModal(rejectModal);
        });
    });

    /* ── Trash confirm modal ── */
    var trashModal = document.getElementById('ke-board-trash-modal');
    var pendingTrashForm = null;
    wireModal(trashModal);

    document.querySelectorAll('.ke-board-trash-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form._keConfirmed) return; // second pass: let it through
            e.preventDefault();
            pendingTrashForm = form;
            document.getElementById('ke-board-trash-subtitle').textContent = form.getAttribute('data-post-title') || '';
            openModal(trashModal);
        });
    });

    var trashConfirm = document.getElementById('ke-board-trash-confirm');
    if (trashConfirm) {
        trashConfirm.addEventListener('click', function () {
            if (!pendingTrashForm) return;
            pendingTrashForm._keConfirmed = true;
            pendingTrashForm.submit();
        });
    }

    /* ── Comment panel toggles ── */
    document.querySelectorAll('.ke-board-comments-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var panel = document.getElementById(btn.getAttribute('data-target'));
            if (!panel) return;
            var isHidden = panel.hasAttribute('hidden');
            if (isHidden) {
                panel.removeAttribute('hidden');
                btn.textContent = 'Ocultar comentarios';
            } else {
                panel.setAttribute('hidden', '');
                btn.textContent = 'Ver comentarios';
            }
        });
    });

    /* ── Sort select navigates ── */
    var sort = document.getElementById('ke-board-sort');
    if (sort) {
        sort.addEventListener('change', function () {
            var base = sort.getAttribute('data-base-url');
            window.location.href = base + '&orden=' + encodeURIComponent(sort.value);
        });
    }
})();
