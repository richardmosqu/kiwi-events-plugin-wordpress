/**
 * KiwiEvents — Analytics admin page: historical visits import.
 *
 * Two explicit steps, both batched (5 events per request) with a progress
 * bar so a site with many events never hits a request timeout:
 *   1. Preview  → read-only plan per event (days, visits, date range).
 *   2. Import   → the primary button turns into "Confirm", a second click
 *                 writes. No dialogs. Idempotent on the server.
 */
(function () {
    'use strict';
    var cfg = window.keAnalyticsHistory;
    var root = document.getElementById('ke-an-history');
    if (!cfg || !root) return;

    var $ = function (id) { return document.getElementById(id); };
    var btnPreview = $('ke-an-h-preview');
    var btnImport  = $('ke-an-h-import');
    var btnReload  = $('ke-an-h-reload');
    var progress   = $('ke-an-h-progress');
    var bar        = $('ke-an-h-bar');
    var progText   = $('ke-an-h-progress-text');
    var result     = $('ke-an-h-result');

    var ids   = Array.isArray(cfg.eventIds) ? cfg.eventIds.map(function (n) { return parseInt(n, 10); }).filter(Boolean) : [];
    var batch = Math.max(1, Math.min(20, parseInt(cfg.batch, 10) || 5));
    var state = { plan: [], totalViews: 0, totalDays: 0, armed: false, busy: false };

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
    function fmt(n) { return (parseInt(n, 10) || 0).toLocaleString(); }
    function fmtDay(ymd) {
        if (!ymd) return '—';
        try { var p = String(ymd).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }); } catch (e) { return String(ymd); }
    }
    function chunk(arr, n) { var out = []; for (var i = 0; i < arr.length; i += n) out.push(arr.slice(i, i + n)); return out; }

    function post(body) {
        return fetch(cfg.restUrl + 'admin/analytics/history', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, data: d }; }); });
    }

    function setProgress(done, total, label) {
        progress.hidden = false;
        var pct = total ? Math.round((done / total) * 100) : 0;
        bar.style.width = pct + '%';
        progText.textContent = label + ' ' + done + ' / ' + total;
    }

    function setBusy(busy) {
        state.busy = busy;
        btnPreview.disabled = busy;
        btnImport.disabled = busy;
    }

    function renderPlan(rows, mode) {
        var html = '';
        var withData = rows.filter(function (r) { return !r.error && (mode === 'import' ? r.inserted_days : r.days) > 0; }).length;
        var errors   = rows.filter(function (r) { return r.error; }).length;
        if (mode === 'preview') {
            html += '<p class="ke-an-history-summary">' +
                (state.totalViews > 0
                    ? '<strong>' + fmt(state.totalViews) + ' visits</strong> across <strong>' + fmt(state.totalDays) + ' event-days</strong> would be imported for ' + withData + ' of ' + rows.length + ' events.' +
                      ' Nothing has been written yet.'
                    : 'Nothing to import: WordPress.com Stats has no additional days for these events.') +
                (errors ? ' <strong>' + errors + '</strong> event' + (errors === 1 ? '' : 's') + ' could not be read.' : '') +
                '</p>';
        } else {
            html += '<p class="ke-an-history-summary">Imported <strong>' + fmt(state.totalViews) + ' visits</strong> across <strong>' + fmt(state.totalDays) + ' event-days</strong> for ' + withData + ' event' + (withData === 1 ? '' : 's') + '.' +
                (errors ? ' <strong>' + errors + '</strong> could not be read.' : '') + ' Reload the page to see them in the numbers above.</p>';
        }
        html += '<div class="ke-an-table-wrap"><table class="ke-table"><thead><tr>' +
            '<th>Event</th><th>From</th><th>To</th><th class="ke-an-num">Days</th><th class="ke-an-num">Visits</th><th class="ke-an-num">Skipped</th></tr></thead><tbody>';
        rows.forEach(function (r) {
            var days  = mode === 'import' ? r.inserted_days : r.days;
            var views = mode === 'import' ? r.inserted_views : r.views;
            var cls   = r.error ? ' class="ke-an-history-row--error"' : ((days || 0) === 0 ? ' class="ke-an-history-row--empty"' : '');
            html += '<tr' + cls + '><td><strong>' + esc(r.title) + '</strong>' + (r.error ? '<div class="ke-muted">' + esc(r.error) + '</div>' : '') + '</td>' +
                '<td>' + (r.error ? '—' : fmtDay(r.from)) + '</td><td>' + (r.error ? '—' : fmtDay(r.to)) + '</td>' +
                '<td class="ke-an-num">' + fmt(days) + '</td><td class="ke-an-num">' + fmt(views) + '</td>' +
                '<td class="ke-an-num" title="Days already counted by the plugin, or today">' + (mode === 'preview' ? fmt(r.skipped_days) : '—') + '</td></tr>';
        });
        html += '</tbody></table></div>';
        result.innerHTML = html;
    }

    function run(mode) {
        if (state.busy) return;
        setBusy(true);
        result.innerHTML = '';
        var batches = chunk(ids, batch);
        var rows = [];
        var done = 0;
        state.totalViews = 0; state.totalDays = 0;
        setProgress(0, ids.length, mode === 'import' ? 'Importing' : 'Reading');

        var p = Promise.resolve();
        batches.forEach(function (b) {
            p = p.then(function () {
                return post({ mode: mode, event_ids: b }).then(function (r) {
                    if (!r.ok || !r.data || !Array.isArray(r.data.events)) {
                        var msg = (r.data && r.data.message) || ('Request failed (' + r.status + ').');
                        b.forEach(function (id) { rows.push({ id: id, title: '#' + id, error: msg, days: 0, views: 0, inserted_days: 0, inserted_views: 0 }); });
                    } else {
                        r.data.events.forEach(function (e) {
                            rows.push(e);
                            if (!e.error) {
                                state.totalViews += parseInt(mode === 'import' ? e.inserted_views : e.views, 10) || 0;
                                state.totalDays  += parseInt(mode === 'import' ? e.inserted_days  : e.days,  10) || 0;
                            }
                        });
                    }
                    done += b.length;
                    setProgress(done, ids.length, mode === 'import' ? 'Importing' : 'Reading');
                });
            });
        });
        p.then(function () {
            setBusy(false);
            progress.hidden = true;
            renderPlan(rows, mode);
            if (mode === 'preview') {
                state.plan = rows;
                state.armed = false;
                if (state.totalViews > 0) {
                    btnImport.hidden = false;
                    btnImport.textContent = 'Import ' + fmt(state.totalViews) + ' visits…';
                } else {
                    btnImport.hidden = true;
                }
                btnReload.hidden = true;
            } else {
                btnImport.hidden = true;
                btnReload.hidden = false;
            }
        }).catch(function (e) {
            setBusy(false);
            progress.hidden = true;
            result.innerHTML = '<p class="ke-an-history-summary is-error">' + esc(e && e.message ? e.message : 'Network error.') + '</p>';
        });
    }

    btnPreview.addEventListener('click', function () { if (!cfg.available) return; run('preview'); });
    btnImport.addEventListener('click', function () {
        if (state.busy) return;
        if (!state.armed) {
            // Second click confirms — an inline confirmation instead of a dialog.
            state.armed = true;
            btnImport.textContent = 'Confirm: import ' + fmt(state.totalViews) + ' visits';
            btnImport.classList.add('ke-btn-danger');
            return;
        }
        btnImport.classList.remove('ke-btn-danger');
        run('import');
    });
})();
