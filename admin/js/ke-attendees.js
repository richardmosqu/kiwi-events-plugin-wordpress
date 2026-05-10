/* KiwiEvents — Attendees admin page
 * Inline status dropdown, view/edit modals, bulk actions, toasts.
 * Data source: keAttendeesData (localized) and data-ticket JSON on each <tr>.
 */
(function () {
    'use strict';

    var data = window.keAttendeesData || {};
    var REST = (data.restUrl || '').replace(/\/+$/, '') + '/';
    var NONCE = data.nonce || '';

    // ── Utilities ────────────────────────────────────────────────
    function $(sel, root)  { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function request(path, opts) {
        opts = opts || {};
        return fetch(REST + path.replace(/^\/+/, ''), {
            method: opts.method || 'GET',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': NONCE
            },
            body: opts.body ? JSON.stringify(opts.body) : undefined
        }).then(function (res) {
            return res.json().then(function (payload) {
                if (!res.ok) {
                    var msg = (payload && payload.message) || 'Request failed';
                    var err = new Error(msg);
                    err.payload = payload;
                    throw err;
                }
                return payload;
            });
        });
    }

    function esc(str) {
        var d = document.createElement('div');
        d.textContent = (str == null ? '' : String(str));
        return d.innerHTML;
    }

    // ── Toasts ───────────────────────────────────────────────────
    var toastHost;
    function toast(message, kind) {
        toastHost = toastHost || $('#ke-toasts');
        if (!toastHost) return;
        var el = document.createElement('div');
        el.className = 'ke-toast ke-toast-' + (kind || 'success');
        el.textContent = message;
        toastHost.appendChild(el);
        setTimeout(function () {
            el.classList.add('is-leaving');
            setTimeout(function () { el.parentNode && el.parentNode.removeChild(el); }, 220);
        }, 2400);
    }

    // ── Row helpers ──────────────────────────────────────────────
    function getRowPayload(row) {
        try { return JSON.parse(row.getAttribute('data-ticket') || '{}'); }
        catch (e) { return {}; }
    }
    function setRowPayload(row, payload) {
        row.setAttribute('data-ticket', JSON.stringify(payload));
    }
    function rowById(id) {
        return $('tr[data-ticket-id="' + id + '"]');
    }
    function formatCheckIn(iso) {
        if (!iso) return '—';
        var d = new Date(iso.replace(' ', 'T'));
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleString(undefined, {
            month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
        });
    }
    function refreshRow(row, payload) {
        setRowPayload(row, payload);

        // Status select — color class + selected value
        var sel = row.querySelector('.ke-status-select');
        if (sel) {
            sel.value = payload.status;
            sel.dataset.original = payload.status;
            sel.className = 'ke-status-select ke-status-' + payload.status;
        }

        // Check-in cell
        var ci = row.querySelector('.ke-checkin-cell');
        if (ci) ci.textContent = formatCheckIn(payload.checked_in_at);

        // Name/email cells
        var nameCell = row.cells[2];
        var emailCell = row.cells[3];
        if (nameCell) nameCell.innerHTML = '<strong>' + esc(payload.attendee_name) + '</strong>';
        if (emailCell) emailCell.textContent = payload.attendee_email || '';
    }

    // ── Inline status dropdown ───────────────────────────────────
    document.addEventListener('change', function (e) {
        var sel = e.target.closest('.ke-status-select');
        if (!sel) return;

        var row = sel.closest('tr');
        var cell = sel.closest('.ke-status-cell');
        var id = row.getAttribute('data-ticket-id');
        var previous = sel.dataset.original;
        var next = sel.value;

        cell.classList.add('is-saving');
        sel.disabled = true;

        request('tickets/' + id + '/update-status', {
            method: 'POST',
            body: { status: next }
        }).then(function (resp) {
            refreshRow(row, mergeRow(row, resp.ticket));
            toast('Status updated', 'success');
        }).catch(function (err) {
            sel.value = previous;
            sel.className = 'ke-status-select ke-status-' + previous;
            toast(err.message || 'Could not update status', 'error');
        }).finally(function () {
            cell.classList.remove('is-saving');
            sel.disabled = false;
        });
    });

    // Merge REST ticket response into the row payload — REST shape is narrower
    // than the row JSON (no event/order descriptive fields), so keep the
    // existing fields and overlay only the ones the endpoint returned.
    function mergeRow(row, ticket) {
        var current = getRowPayload(row);
        Object.keys(ticket || {}).forEach(function (k) { current[k] = ticket[k]; });
        // Keep short_code in sync if ticket_code changed (shouldn't, but be safe).
        if (ticket && ticket.ticket_code) {
            current.short_code = String(ticket.ticket_code).slice(0, 8).toUpperCase();
        }
        return current;
    }

    // ── Bulk selection ───────────────────────────────────────────
    var bulkBar = $('#ke-bulk-bar');
    var bulkCountEl = $('#ke-bulk-count');

    function selectedIds() {
        return $$('.ke-row-check:checked').map(function (c) { return parseInt(c.value, 10); });
    }
    function updateBulkBar() {
        if (!bulkBar) return;
        var ids = selectedIds();
        if (ids.length === 0) {
            bulkBar.hidden = true;
        } else {
            bulkBar.hidden = false;
            bulkCountEl.textContent = ids.length;
        }
        var all = $('.ke-check-all');
        var rows = $$('.ke-row-check');
        if (all) {
            all.checked = rows.length > 0 && ids.length === rows.length;
            all.indeterminate = ids.length > 0 && ids.length < rows.length;
        }
    }

    document.addEventListener('change', function (e) {
        if (e.target.matches('.ke-check-all')) {
            $$('.ke-row-check').forEach(function (c) { c.checked = e.target.checked; });
            updateBulkBar();
        } else if (e.target.matches('.ke-row-check')) {
            updateBulkBar();
        }
    });

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-bulk]');
        if (!btn) return;

        var action = btn.dataset.bulk;
        var ids = selectedIds();
        if (ids.length === 0) return;

        var labels = {
            mark_used: 'mark ' + ids.length + ' ticket(s) as checked in',
            mark_unused: 'mark ' + ids.length + ' ticket(s) as unused',
            resend: 'resend ' + ids.length + ' email(s)',
            delete: 'cancel ' + ids.length + ' ticket(s)'
        };
        if (action === 'delete' && !window.confirm('Are you sure you want to ' + labels[action] + '? Cancelled tickets stay on record but no longer count as sold.')) return;

        $$('.ke-row-check:checked').forEach(function (c) {
            var r = c.closest('tr');
            if (r) r.classList.add('is-busy');
        });

        request('tickets/bulk', {
            method: 'POST',
            body: { ids: ids, action: action }
        }).then(function (resp) {
            toast(resp.ok + ' updated' + (resp.failed && resp.failed.length ? ', ' + resp.failed.length + ' failed' : ''),
                  resp.failed && resp.failed.length ? 'error' : 'success');
            // Remaining UI sync for actions that change rows:
            if (action === 'delete' || action === 'mark_used' || action === 'mark_unused') {
                // Reload is the simplest way to reflect bulk status + check-in changes accurately.
                window.location.reload();
            } else {
                $$('tr.is-busy').forEach(function (r) { r.classList.remove('is-busy'); });
                // Uncheck all after resend
                $$('.ke-row-check').forEach(function (c) { c.checked = false; });
                updateBulkBar();
            }
        }).catch(function (err) {
            toast(err.message || 'Bulk action failed', 'error');
            $$('tr.is-busy').forEach(function (r) { r.classList.remove('is-busy'); });
        });
    });

    // ── View Modal ───────────────────────────────────────────────
    var viewModal = $('#ke-modal-view');
    var editModal = $('#ke-modal-edit');
    var currentId = null;

    function openModal(modal) {
        if (!modal) return;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
    }
    function closeModal(modal) {
        if (!modal) return;
        modal.hidden = true;
        if (!viewModal || viewModal.hidden) {
            if (!editModal || editModal.hidden) {
                document.body.style.overflow = '';
            }
        }
    }
    function closeAllModals() {
        if (viewModal) viewModal.hidden = true;
        if (editModal) editModal.hidden = true;
        document.body.style.overflow = '';
    }

    function paymentLabel(t) {
        if (!t.order_total || t.order_total <= 0) return 'Free';
        var method = (t.payment_method || '').toLowerCase();
        var label = method === 'woocommerce' ? 'WooCommerce'
                  : method === 'stripe'      ? 'Stripe'
                  : method === 'free'        ? 'Free'
                  : method.charAt(0).toUpperCase() + method.slice(1);
        return '$' + Number(t.order_total).toFixed(2) + ' · ' + label;
    }

    function populateViewModal(t) {
        currentId = t.id;
        $('#ke-view-qr').src = t.qr_code_path || '';
        $('#ke-view-name').textContent = t.attendee_name || '—';
        $('#ke-view-email').textContent = t.attendee_email || '—';
        $('#ke-view-code').textContent = t.ticket_code || '—';
        $('#ke-view-type').textContent = t.ticket_type_name || '—';
        $('#ke-view-event').textContent = t.event_name || '—';
        $('#ke-view-date').textContent = t.event_date || '—';
        $('#ke-view-venue').textContent = t.event_venue || '—';
        $('#ke-view-purchased').textContent = t.purchase_date ? formatCheckIn(t.purchase_date) : '—';
        $('#ke-view-payment').textContent = paymentLabel(t);
        $('#ke-view-order').textContent = t.order_number || ('#' + t.order_id);

        var statusEl = $('#ke-view-status');
        statusEl.className = 'ke-badge ke-badge-' + t.status;
        statusEl.textContent = t.status === 'valid' ? 'Valid'
                             : t.status === 'used'  ? 'Checked In'
                             : 'Cancelled';
        $('#ke-view-checkin').textContent = formatCheckIn(t.checked_in_at);
    }

    function populateEditModal(t) {
        currentId = t.id;
        $('#ke-edit-name').value = t.attendee_name || '';
        $('#ke-edit-email').value = t.attendee_email || '';
        $('#ke-edit-error').hidden = true;
        $('#ke-edit-error').textContent = '';
    }

    // Row action icons
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action]');
        if (!btn) return;
        var row = btn.closest('tr[data-ticket-id]');
        if (!row) return;

        var action = btn.dataset.action;
        var t = getRowPayload(row);

        if (action === 'view') {
            populateViewModal(t);
            openModal(viewModal);
        } else if (action === 'edit') {
            populateEditModal(t);
            openModal(editModal);
        } else if (action === 'delete') {
            handleDelete(row, t, e.shiftKey);
        }
    });

    // Modal footer actions
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-modal-action]');
        if (!btn || !currentId) return;
        var row = rowById(currentId);
        if (!row) return;
        var t = getRowPayload(row);

        if (btn.dataset.modalAction === 'edit') {
            closeModal(viewModal);
            populateEditModal(t);
            openModal(editModal);
        } else if (btn.dataset.modalAction === 'resend') {
            btn.disabled = true;
            request('tickets/' + t.id + '/resend-email', { method: 'POST' })
                .then(function () { toast('Email sent', 'success'); })
                .catch(function (err) { toast(err.message || 'Could not send email', 'error'); })
                .finally(function () { btn.disabled = false; });
        } else if (btn.dataset.modalAction === 'delete') {
            closeModal(viewModal);
            handleDelete(row, t, false);
        }
    });

    function handleDelete(row, t, hard) {
        var message = hard
            ? 'Permanently delete this ticket? This cannot be undone and will remove the historical record.'
            : 'Cancel this ticket? It will be marked cancelled and won’t count as sold.';
        if (!window.confirm(message)) return;

        row.classList.add('is-busy');
        request('tickets/' + t.id + (hard ? '?hard=1' : ''), { method: 'DELETE' })
            .then(function (resp) {
                if (hard) {
                    row.classList.add('ke-row-removed');
                    setTimeout(function () { row.parentNode && row.parentNode.removeChild(row); }, 260);
                    toast('Ticket deleted', 'success');
                } else {
                    refreshRow(row, mergeRow(row, resp.ticket));
                    row.classList.remove('is-busy');
                    toast('Ticket cancelled', 'success');
                }
            })
            .catch(function (err) {
                row.classList.remove('is-busy');
                toast(err.message || 'Could not delete ticket', 'error');
            });
    }

    // Edit modal save
    var editSave = $('#ke-edit-save');
    if (editSave) {
        editSave.addEventListener('click', function () {
            if (!currentId) return;
            var name  = $('#ke-edit-name').value.trim();
            var email = $('#ke-edit-email').value.trim();
            var errEl = $('#ke-edit-error');
            errEl.hidden = true;

            if (!name) {
                errEl.textContent = 'Attendee name is required.';
                errEl.hidden = false;
                return;
            }

            editSave.disabled = true;
            request('tickets/' + currentId, {
                method: 'PUT',
                body: { attendee_name: name, attendee_email: email }
            }).then(function (resp) {
                var row = rowById(currentId);
                if (row) refreshRow(row, mergeRow(row, resp.ticket));
                closeModal(editModal);
                toast('Attendee updated', 'success');
            }).catch(function (err) {
                errEl.textContent = err.message || 'Could not save.';
                errEl.hidden = false;
            }).finally(function () {
                editSave.disabled = false;
            });
        });
    }

    // Close modal via backdrop, × button, or ESC
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-close]')) {
            closeAllModals();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllModals();
    });

    // Initial state
    updateBulkBar();
})();
