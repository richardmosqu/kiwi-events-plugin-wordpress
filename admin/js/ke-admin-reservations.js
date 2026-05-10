/* KiwiEvents — Reservations admin page
 * View modal, inline approve/decline/check-in/cancel actions, toasts.
 * Endpoints: /ke/v1/admin/reservations/{id}/{action}.
 *
 * Data source: keAdminResvData (localized) for REST URL + nonce, and the
 * data-reservation JSON attribute on each row for modal hydration.
 */
(function () {
    'use strict';

    var data = window.keAdminResvData || {};
    var REST = (data.restUrl || '').replace(/\/+$/, '') + '/';
    var NONCE = data.nonce || '';

    var STATUS_LABELS = {
        'pending':            'Pending',
        'confirmed':          'Confirmed',
        'cancelled':          'Cancelled by customer',
        'cancelled_no_show':  'No-show',
        'cancelled_by_venue': 'Cancelled by venue',
        'declined':           'Declined'
    };

    function $(sel, root)  { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function request(path, opts) {
        opts = opts || {};
        return fetch(REST + path.replace(/^\/+/, ''), {
            method: opts.method || 'GET',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
            body: opts.body ? JSON.stringify(opts.body) : undefined
        }).then(function (res) {
            return res.json().then(function (payload) {
                if (!res.ok) {
                    var err = new Error((payload && payload.message) || 'Request failed');
                    err.payload = payload;
                    throw err;
                }
                return payload;
            });
        });
    }

    // Toasts ───────────────────────────────────────────────────────
    var toastHost;
    function toast(message, kind) {
        toastHost = toastHost || $('#ke-toasts');
        if (!toastHost) { window.alert(message); return; }
        var el = document.createElement('div');
        el.className = 'ke-toast ke-toast-' + (kind || 'success');
        el.textContent = message;
        toastHost.appendChild(el);
        setTimeout(function () {
            el.classList.add('is-leaving');
            setTimeout(function () { el.parentNode && el.parentNode.removeChild(el); }, 220);
        }, 2400);
    }

    function rowPayload(row) {
        try { return JSON.parse(row.getAttribute('data-reservation') || '{}'); }
        catch (e) { return {}; }
    }
    function fmt(iso) {
        if (!iso) return '—';
        var d = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    // Modal plumbing ───────────────────────────────────────────────
    function openModal(modal) { if (modal) modal.hidden = false; document.body.style.overflow = 'hidden'; }
    function closeModal(modal) { if (modal) modal.hidden = true; document.body.style.overflow = ''; }
    document.addEventListener('click', function (ev) {
        var closeBtn = ev.target.closest('[data-close]');
        if (closeBtn) {
            var modal = closeBtn.closest('.ke-modal');
            closeModal(modal);
        }
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') {
            $$('.ke-modal:not([hidden])').forEach(closeModal);
        }
    });

    // View modal ───────────────────────────────────────────────────
    function openView(payload) {
        var modal = $('#ke-resv-modal-view');
        if (!modal) return;
        $('#ke-resv-view-code').textContent     = payload.reservation_code || '—';
        var statusEl = $('#ke-resv-view-status');
        if (statusEl) {
            statusEl.textContent = STATUS_LABELS[payload.status] || payload.status || '—';
            statusEl.className   = 'ke-badge ke-resv-status-pill ke-resv-status-' + (payload.status || 'pending');
        }
        $('#ke-resv-view-customer').textContent = payload.customer_name  || '—';
        $('#ke-resv-view-email').textContent    = payload.customer_email || '—';
        $('#ke-resv-view-phone').textContent    = payload.customer_phone || '—';
        $('#ke-resv-view-event').textContent    = payload.event_title    || '—';
        $('#ke-resv-view-party').textContent    = payload.party_size != null ? String(payload.party_size) : '—';
        $('#ke-resv-view-arrival').textContent  = fmt(payload.arrival_time);
        $('#ke-resv-view-area').textContent     = payload.area || '—';
        $('#ke-resv-view-checkin').textContent  = fmt(payload.checked_in_at);
        $('#ke-resv-view-notes').textContent    = payload.notes  || '—';
        $('#ke-resv-view-decline').textContent  = payload.decline_reason || '—';
        $('#ke-resv-view-created').textContent  = fmt(payload.created_at);
        openModal(modal);
    }

    // Decline modal ────────────────────────────────────────────────
    var pendingDeclineId = 0;
    function openDecline(id) {
        pendingDeclineId = id;
        var modal = $('#ke-resv-modal-decline');
        $('#ke-resv-decline-reason').value = '';
        $('#ke-resv-decline-error').hidden = true;
        openModal(modal);
        setTimeout(function () { $('#ke-resv-decline-reason').focus(); }, 60);
    }

    var declineSubmit = $('#ke-resv-decline-submit');
    if (declineSubmit) {
        declineSubmit.addEventListener('click', function () {
            if (!pendingDeclineId) return;
            var reason = ($('#ke-resv-decline-reason').value || '').trim();
            declineSubmit.disabled = true;
            request('admin/reservations/' + pendingDeclineId + '/decline', {
                method: 'POST',
                body: { reason: reason }
            }).then(function () {
                toast('Reservation declined.');
                window.location.reload();
            }).catch(function (err) {
                var box = $('#ke-resv-decline-error');
                box.textContent = err.message || 'Could not decline reservation.';
                box.hidden = false;
                declineSubmit.disabled = false;
            });
        });
    }

    // Per-action handler ──────────────────────────────────────────
    function performAction(id, action, confirmMsg) {
        if (confirmMsg && !window.confirm(confirmMsg)) return;
        request('admin/reservations/' + id + '/' + action, { method: 'POST' })
            .then(function () {
                toast(actionToast(action));
                window.location.reload();
            })
            .catch(function (err) {
                toast(err.message || 'Action failed.', 'error');
            });
    }
    function actionToast(action) {
        switch (action) {
            case 'approve':  return 'Reservation approved.';
            case 'check-in': return 'Reservation checked in.';
            case 'cancel':   return 'Reservation cancelled.';
            default:         return 'Done.';
        }
    }

    // Row click delegation ─────────────────────────────────────────
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('button[data-action]');
        if (!btn) return;
        var row = btn.closest('tr[data-reservation-id]');
        if (!row) return;
        var id = parseInt(row.getAttribute('data-reservation-id'), 10);
        var payload = rowPayload(row);
        var action = btn.getAttribute('data-action');

        switch (action) {
            case 'view':
                openView(payload);
                return;
            case 'approve':
                performAction(id, 'approve', 'Approve this reservation? The customer will be emailed.');
                return;
            case 'decline':
                openDecline(id);
                return;
            case 'check-in':
                performAction(id, 'check-in', 'Mark this reservation as checked in?');
                return;
            case 'cancel':
                performAction(id, 'cancel', 'Cancel this reservation? The customer will be emailed.');
                return;
        }
    });
})();
