/* global keOrganizerDashboard, Chart */
(function () {
    'use strict';

    var cfg = window.keOrganizerDashboard || {};
    if (!cfg.restUrl) return;

    var $ = function (id) { return document.getElementById(id); };
    function on(el, ev, fn) { if (el) el.addEventListener(ev, fn); }
    function escapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }

    function fmtMoney(n) {
        var num = Number(n) || 0;
        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: (window.keOrganizerDashboard && cfg.currency) || 'USD',
                maximumFractionDigits: 2
            }).format(num);
        } catch (e) {
            return '$' + num.toFixed(2);
        }
    }
    function fmtInt(n) {
        try { return new Intl.NumberFormat().format(Number(n) || 0); }
        catch (e) { return String(n); }
    }
    function fmtPct(n) {
        var num = Number(n) || 0;
        return num.toFixed(1) + '%';
    }
    function fmtDate(iso) {
        if (!iso) return '';
        var d = new Date(iso.length === 10 ? iso + 'T00:00:00' : iso);
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    // Activity-feed time: relative under 24h, absolute "Apr 24 at 10:45 PM" older.
    function fmtRelative(iso) {
        if (!iso) return '';
        var then = new Date(iso.length === 10 ? iso + 'T00:00:00' : iso);
        if (isNaN(then.getTime())) return iso;
        var now  = new Date();
        var diff = Math.max(0, (now.getTime() - then.getTime()) / 1000);
        if (diff < 60)        return 'just now';
        if (diff < 3600)      { var m = Math.floor(diff / 60);   return m + (m === 1 ? ' min ago'  : ' mins ago'); }
        if (diff < 86400)     { var h = Math.floor(diff / 3600); return h + (h === 1 ? ' hour ago' : ' hours ago'); }
        try {
            return then.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
                       .replace(',', ' at');
        } catch (e) { return then.toISOString(); }
    }

    function getJson(url) {
        return fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce || '' }
        }).then(function (res) {
            return res.json().then(function (data) {
                return { ok: res.ok, status: res.status, data: data };
            });
        });
    }
    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   cfg.nonce || ''
            },
            body: JSON.stringify(body || {})
        }).then(function (res) {
            return res.json().then(function (data) {
                return { ok: res.ok, status: res.status, data: data };
            });
        });
    }

    /* ── Login ───────────────────────────────────────────────────── */

    function initLogin() {
        var form = $('keOrgLoginForm');
        if (!form) return;

        var input  = $('keOrgPassword');
        var toggle = $('keOrgPasswordToggle');
        var admin  = $('keOrgLoginAdmin');

        on(toggle, 'click', function () {
            if (!input) return;
            var hidden = input.type === 'password';
            input.type = hidden ? 'text' : 'password';
            toggle.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
            input.focus();
        });

        on(form, 'submit', function (e) {
            e.preventDefault();
            setLoginError('');
            var pw = (input && input.value) || '';
            if (!pw) { setLoginError('Please enter your password.'); return; }
            submit({ password: pw });
        });

        on(admin, 'click', function () {
            setLoginError('');
            submit({ admin_nonce: cfg.nonce || '' });
        });

        function setLoginError(msg) {
            var box = $('keOrgLoginError');
            if (!box) return;
            if (!msg) { box.hidden = true; box.textContent = ''; return; }
            box.hidden = false; box.textContent = msg;
        }
        function setLoginLoading(loading) {
            var btn = $('keOrgLoginSubmit');
            if (!btn) return;
            btn.classList.toggle('is-loading', !!loading);
            btn.disabled = !!loading;
        }

        function submit(payload) {
            setLoginLoading(true);
            payload.slug = cfg.slug;
            postJson(cfg.restUrl + 'auth', payload)
                .then(function (r) {
                    if (r.ok && r.data && r.data.success) {
                        window.location.reload();
                        return;
                    }
                    var msg = (r.data && r.data.message) || 'Sign-in failed. Please try again.';
                    if (r.data && r.data.code === 'rate_limited') {
                        msg = 'Too many failed attempts. Please wait a moment and try again.';
                    }
                    setLoginError(msg);
                    setLoginLoading(false);
                })
                .catch(function () {
                    setLoginError('Network error. Please try again.');
                    setLoginLoading(false);
                });
        }
    }

    /* ── Logout ──────────────────────────────────────────────────── */

    function initLogout() {
        var btn = $('keOrgLogout');
        on(btn, 'click', function () {
            btn.disabled = true;
            postJson(cfg.restUrl + cfg.slug + '/logout', {})
                .then(function () { window.location.reload(); })
                .catch(function () { window.location.reload(); });
        });
    }

    /* ── Dashboard ───────────────────────────────────────────────── */

    var state = {
        range: '30',
        chart: null,
        events: [],
        headlineCurrency: 'USD',
        freeOnly: false,
        filterEventId: 0,    // 0 = all events
        expandedEvents: {},  // { [eventId]: true } — which event rows have the type breakdown open
        attendeePage: 1,
        attendeePerPage: 25,
        attendeeSearch: '',
        searchTimer: null,
        activityTimer: null,
        statsTimer: null,            // 60s stats poll
        beaconTimer: null,           // 8s real-time sales beacon poll
        beaconInflight: false,       // prevent overlapping beacon requests
        lastSaleTs: parseInt(cfg.initialLastSale, 10) || 0, // seeded from PHP
        toastTimer: null,            // auto-dismiss timer for "New sale!" toast
        lastUpdatedTimer: null,      // 5s "Last updated Ns ago" tick
        lastUpdatedAt: 0,            // Date.now() of last successful stats fetch
        consecutiveFailures: 0,      // for retry banner
        prevHeadline: null,          // last headline payload, for counter animation
        statsInflight: false         // prevent overlapping fetches
    };

    // Tween a numeric stat value over ~600ms so jumps don't feel jarring.
    function animateStatValue(el, fromVal, toVal, formatter) {
        if (!el) return;
        var start = Number(fromVal) || 0;
        var end   = Number(toVal)   || 0;
        if (start === end) { el.textContent = formatter(end); return; }
        var t0 = performance.now();
        var dur = 600;
        function frame(now) {
            var p = Math.min(1, (now - t0) / dur);
            // ease-out cubic
            var eased = 1 - Math.pow(1 - p, 3);
            var v = start + (end - start) * eased;
            el.textContent = formatter(v);
            if (p < 1) requestAnimationFrame(frame);
        }
        requestAnimationFrame(frame);
    }

    // Stat-card icon glyphs (kept inline so we don't ship an icon font).
    var ICON_TICKETS  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v2a2 2 0 0 0 0 4v2a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3v-2a2 2 0 0 0 0-4z"/><path d="M13 5v14"/></svg>';
    var ICON_REVENUE  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
    var ICON_FREE     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 1 1 0-4h12.5a1.5 1.5 0 1 1 0 3H6"/><path d="M6 12h14V4"/><path d="M2 12h20v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2z"/></svg>';
    var ICON_CHECKIN  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';

    function statCard(opts) {
        var trend = opts.trend || '';
        return '' +
            '<div class="ke-org-stat">' +
                '<div class="ke-org-stat-icon" aria-hidden="true">' + opts.icon + '</div>' +
                '<p class="ke-org-stat-label">' + opts.label + '</p>' +
                '<p class="ke-org-stat-value' + (opts.accent ? ' ke-org-stat-value--accent' : '') + '">' + opts.value + '</p>' +
                trend +
            '</div>';
    }

    function renderStats(headline) {
        var box = $('keOrgStats');
        if (!box) return;
        cfg.currency = headline.currency || cfg.currency || 'USD';
        state.headlineCurrency = cfg.currency;
        state.freeOnly = !!headline.free_only;

        var cards = [];

        // Tickets sold + trend (or "New" badge).
        var trendHtml = '';
        if (headline.tickets_sold_is_new) {
            trendHtml = '<span class="ke-org-stat-trend ke-org-stat-trend--new">' +
                            '<span class="ke-org-stat-trend-dot"></span> New' +
                        '</span>';
        } else if (headline.tickets_sold_trend !== null && headline.tickets_sold_trend !== undefined) {
            var t = Number(headline.tickets_sold_trend);
            var cls = 'flat'; var arrow = '→';
            if (t > 0) { cls = 'up'; arrow = '↑'; }
            else if (t < 0) { cls = 'down'; arrow = '↓'; }
            trendHtml = '<span class="ke-org-stat-trend ke-org-stat-trend--' + cls + '">'
                      + arrow + ' ' + Math.abs(t).toFixed(1) + '% vs prior period</span>';
        }
        cards.push(statCard({
            icon:  ICON_TICKETS,
            label: 'Tickets sold',
            value: fmtInt(headline.tickets_sold),
            trend: trendHtml
        }));

        // Net revenue is the headline metric — accent-colored value, only shown
        // if the period contains paid tickets. For free-only periods we instead
        // show the paid-tickets card to avoid a redundant "$0.00".
        if (!state.freeOnly) {
            cards.push(statCard({
                icon:   ICON_REVENUE,
                label:  'Net revenue',
                value:  fmtMoney(headline.net_revenue),
                accent: true,
                trend:  '<span class="ke-org-stat-trend ke-org-stat-trend--flat">After service fees</span>'
            }));
        }

        // Free tickets — only when there's free volume to talk about.
        if ((headline.free_tickets || 0) > 0) {
            cards.push(statCard({
                icon:  ICON_FREE,
                label: 'Free tickets issued',
                value: fmtInt(headline.free_tickets)
            }));
        }

        // Check-in rate.
        cards.push(statCard({
            icon:  ICON_CHECKIN,
            label: 'Check-in rate',
            value: fmtPct(headline.check_in_rate),
            trend: '<span class="ke-org-stat-trend ke-org-stat-trend--flat">' +
                       fmtInt(headline.check_in_used) + ' of ' + fmtInt(headline.check_in_total) + ' attended' +
                   '</span>'
        }));

        box.innerHTML = cards.join('');
        box.removeAttribute('aria-busy');
    }

    function setChartTitle(freeOnly) {
        var titleEl    = $('keOrgChartTitle');
        var subtitleEl = $('keOrgChartSubtitle');
        if (titleEl)    titleEl.textContent    = freeOnly ? 'Daily attendance' : 'Daily sales';
        if (subtitleEl) subtitleEl.textContent = freeOnly
            ? 'Tickets issued per day for the selected range.'
            : 'Tickets sold and net revenue per day for the selected range.';
    }

    function renderChart(series) {
        var canvas = $('keOrgChart');
        if (!canvas || typeof Chart === 'undefined') return;

        // Clear any prior error state from a previous failed load.
        canvas.style.display = '';
        if (canvas.parentNode) {
            var prev = canvas.parentNode.querySelector('.ke-org-chart-error');
            if (prev) prev.remove();
        }

        var labels   = series.map(function (r) { return r.date; });
        var tickets  = series.map(function (r) { return Number(r.tickets) || 0; });
        var revenue  = series.map(function (r) { return Number(r.net_revenue) || 0; });
        var accent   = (cfg.accent && /^#[0-9a-f]{6}$/i.test(cfg.accent)) ? cfg.accent : '#6366f1';
        var freeOnly = !!state.freeOnly;

        setChartTitle(freeOnly);

        // Free-only periods: drop the revenue dataset and the secondary axis
        // entirely so Chart.js doesn't render an empty $0 line.
        var datasets = [{
            label: 'Tickets',
            data: tickets,
            backgroundColor: accent + '33',
            borderColor: accent,
            borderWidth: 1,
            borderRadius: 6,
            yAxisID: 'y'
        }];
        if (!freeOnly) {
            datasets.push({
                label: 'Net revenue',
                data: revenue,
                type: 'line',
                borderColor: accent,
                backgroundColor: 'transparent',
                borderWidth: 2,
                tension: 0.3,
                pointRadius: 2,
                yAxisID: 'y1'
            });
        }

        var scales = {
            x: { grid: { display: false } },
            y: {
                beginAtZero: true,
                position: 'left',
                ticks: { precision: 0, stepSize: 1 },
                title: { display: true, text: 'Tickets' },
                grid: { color: 'rgba(0,0,0,0.05)' }
            }
        };
        if (!freeOnly) {
            scales.y1 = {
                beginAtZero: true,
                position:    'right',
                grid:        { drawOnChartArea: false },
                title:       { display: true, text: 'Net revenue' },
                ticks:       { callback: function (v) { return fmtMoney(v); } }
            };
        }

        // If the chart already exists with a different shape (free-only vs not)
        // tear it down so we don't leak the old axis. Otherwise reuse.
        if (state.chart) {
            var sameShape = (state.chart.data.datasets.length === datasets.length);
            if (sameShape) {
                state.chart.data.labels = labels;
                state.chart.data.datasets[0].data = tickets;
                if (!freeOnly && state.chart.data.datasets[1]) {
                    state.chart.data.datasets[1].data = revenue;
                }
                state.chart.options.scales = scales;
                state.chart.update();
                return;
            }
            state.chart.destroy();
            state.chart = null;
        }

        state.chart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: freeOnly ? { display: false } : { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                if (!freeOnly && ctx.datasetIndex === 1) {
                                    return ' ' + ctx.dataset.label + ': ' + fmtMoney(ctx.parsed.y);
                                }
                                return ' ' + ctx.dataset.label + ': ' + fmtInt(ctx.parsed.y);
                            }
                        }
                    }
                },
                scales: scales
            }
        });
    }

    var ICON_PDF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';

    var ICON_CSV = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';

    var ICON_CHEVRON = '<svg class="ke-org-event-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>';

    var ICON_CALENDAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';

    function renderTicketTypeBreakdown(types) {
        if (!types || !types.length) {
            return '<div class="ke-org-event-types-empty">No ticket-type data for this range.</div>';
        }
        var total = 0;
        var totalCourtesy = 0;
        var rows = types.map(function (t) {
            total += (parseInt(t.sold, 10) || 0);
            var courtesy = parseInt(t.courtesy_count, 10) || 0;
            totalCourtesy += courtesy;
            var revCell = t.is_free
                ? '<span class="ke-org-type-badge ke-org-type-badge--free">Free</span>'
                : fmtMoney(t.revenue) + ' net';
            var soldText = fmtInt(t.sold) + ' sold' +
                (courtesy > 0 ? ' (' + fmtInt(courtesy) + ' cortesía)' : '');
            return '<div class="ke-org-type-row">' +
                       '<div class="ke-org-type-name">' + escapeHtml(t.name || 'Unknown') + '</div>' +
                       '<div class="ke-org-type-sold">' + soldText + '</div>' +
                       '<div class="ke-org-type-rev">' + revCell + '</div>' +
                   '</div>';
        }).join('');
        var totalSoldText = fmtInt(total) +
            (totalCourtesy > 0 ? ' (' + fmtInt(totalCourtesy) + ' cortesía)' : '');
        rows += '<div class="ke-org-type-row ke-org-type-row--total">' +
                    '<div class="ke-org-type-name">Total</div>' +
                    '<div class="ke-org-type-sold">' + totalSoldText + '</div>' +
                    '<div class="ke-org-type-rev"></div>' +
                '</div>';
        return rows;
    }

    function renderEvents(events) {
        var box = $('keOrgEvents');
        if (!box) return;
        state.events = events || [];
        if (!state.events.length) {
            box.innerHTML = '<div class="ke-org-empty">No events yet.</div>';
            return;
        }
        var allRow = '<div class="ke-org-event-row is-all-row' + (state.filterEventId === 0 ? ' is-active' : '') + '" data-event-id="0">' +
                         '<div><div class="ke-org-event-name">All events</div>' +
                              '<div class="ke-org-event-date">Click an event to filter the attendee list.</div>' +
                         '</div>' +
                     '</div>';
        var html = state.events.map(function (e) {
            var active   = state.filterEventId === e.id ? ' is-active' : '';
            var expanded = !!state.expandedEvents[e.id];
            var expClass = expanded ? ' is-expanded' : '';
            var hasTypes = !!(e.ticket_types && e.ticket_types.length);
            // Row 1: icon · name/date · chevron (toggle hidden when no breakdown).
            // Row 2: stat chips · PDF · CSV.
            // Stat chips use a label + value so the data reads naturally on
            // mobile when they wrap to two lines.
            var head =
                '<div class="ke-org-event-row' + active + expClass + '" data-event-id="' + e.id + '">' +
                    '<div class="ke-org-event-row-top">' +
                        '<div class="ke-org-event-icon" aria-hidden="true">' + ICON_CALENDAR + '</div>' +
                        '<div class="ke-org-event-row-main">' +
                            '<div class="ke-org-event-name">' + escapeHtml(e.title) + '</div>' +
                            '<div class="ke-org-event-date">' + escapeHtml(fmtDate(e.date)) + '</div>' +
                        '</div>' +
                        (hasTypes
                            ? '<button type="button" class="ke-org-event-toggle" data-event-toggle="' + e.id + '" aria-expanded="' + (expanded ? 'true' : 'false') + '" aria-label="Toggle ticket-type breakdown">' + ICON_CHEVRON + '</button>'
                            : '<span class="ke-org-event-toggle-spacer" aria-hidden="true"></span>'
                        ) +
                    '</div>' +
                    '<div class="ke-org-event-row-bottom">' +
                        '<div class="ke-org-event-chips">' +
                            '<span class="ke-org-event-chip"><span class="ke-org-event-chip-label">Sold</span><span class="ke-org-event-chip-value">' + fmtInt(e.tickets_sold) +
                                ((parseInt(e.courtesy_tickets, 10) || 0) > 0
                                    ? ' <span class="ke-org-event-chip-sub">(' + fmtInt(e.courtesy_tickets) + ' cortesía)</span>'
                                    : '') +
                            '</span></span>' +
                            '<span class="ke-org-event-chip"><span class="ke-org-event-chip-label">Net</span><span class="ke-org-event-chip-value">' + fmtMoney(e.net_revenue) + '</span></span>' +
                            '<span class="ke-org-event-chip"><span class="ke-org-event-chip-label">Check-in</span><span class="ke-org-event-chip-value">' + fmtPct(e.check_in_rate) + '</span></span>' +
                        '</div>' +
                        '<div class="ke-org-event-actions">' +
                            '<button type="button" class="ke-org-event-action" data-event-pdf="' + e.id + '" title="Download PDF report for this event">' +
                                ICON_PDF + '<span class="ke-org-event-action-label">PDF</span>' +
                            '</button>' +
                            '<button type="button" class="ke-org-event-action" data-event-csv="' + e.id + '" title="Download CSV for this event">' +
                                ICON_CSV + '<span class="ke-org-event-action-label">CSV</span>' +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>';
            var details = hasTypes
                ? '<div class="ke-org-event-types' + (expanded ? ' is-open' : '') + '" data-event-types="' + e.id + '">' +
                    renderTicketTypeBreakdown(e.ticket_types) +
                  '</div>'
                : '';
            return head + details;
        }).join('');
        box.innerHTML = allRow + html;

        Array.prototype.forEach.call(box.querySelectorAll('.ke-org-event-row'), function (row) {
            row.style.cursor = 'pointer';
            row.addEventListener('click', function (ev) {
                // Per-event PDF button: handle separately and don't fall through
                // to the row filter, otherwise clicking PDF also changes filter.
                var pdfBtn = ev.target.closest && ev.target.closest('[data-event-pdf]');
                if (pdfBtn) {
                    ev.stopPropagation();
                    var evId = parseInt(pdfBtn.getAttribute('data-event-pdf'), 10) || 0;
                    if (evId > 0) downloadPdf(evId);
                    return;
                }
                // Per-event CSV button: same treatment as the PDF button.
                var csvBtn = ev.target.closest && ev.target.closest('[data-event-csv]');
                if (csvBtn) {
                    ev.stopPropagation();
                    var cEvId = parseInt(csvBtn.getAttribute('data-event-csv'), 10) || 0;
                    if (cEvId > 0) downloadCsv(cEvId);
                    return;
                }
                // Chevron: toggle the breakdown without changing the attendee filter.
                var toggleBtn = ev.target.closest && ev.target.closest('[data-event-toggle]');
                if (toggleBtn) {
                    ev.stopPropagation();
                    var tEvId = parseInt(toggleBtn.getAttribute('data-event-toggle'), 10) || 0;
                    if (tEvId > 0) {
                        state.expandedEvents[tEvId] = !state.expandedEvents[tEvId];
                        renderEvents(state.events);
                    }
                    return;
                }
                state.filterEventId = parseInt(row.getAttribute('data-event-id'), 10) || 0;
                state.attendeePage = 1;
                renderEvents(state.events);
                loadAttendees();
            });
        });
    }

    /* ── Attendees ───────────────────────────────────────────────── */

    function statusPill(status) {
        if (status === 'used')      return '<span class="ke-org-pill ke-org-pill--in">Checked in</span>';
        if (status === 'cancelled') return '<span class="ke-org-pill ke-org-pill--cxl">Cancelled</span>';
        return '<span class="ke-org-pill ke-org-pill--out">Valid</span>';
    }

    function renderAttendees(payload) {
        var box = $('keOrgAttendees');
        if (!box) return;
        var attendees = payload.attendees || [];
        if (!attendees.length) {
            box.innerHTML = '<div class="ke-org-empty">No attendees match your filters.</div>';
            return;
        }
        var rows = attendees.map(function (a) {
            return '<tr>' +
                       '<td data-label="Name">' + escapeHtml(a.name || '—') + '</td>' +
                       '<td data-label="Email">' + escapeHtml(a.email || '—') + '</td>' +
                       '<td data-label="Event">' + escapeHtml(a.event_title || '—') + '</td>' +
                       '<td data-label="Ticket">' + escapeHtml(a.ticket_type || '—') + '</td>' +
                       '<td data-label="Status">' + statusPill(a.status) + '</td>' +
                       '<td data-label="Purchased">' + escapeHtml(a.created_at ? fmtDate(a.created_at) : '—') + '</td>' +
                   '</tr>';
        }).join('');

        var pages   = payload.pages || 1;
        var page    = payload.page || 1;
        var total   = payload.total || 0;
        var start   = (page - 1) * (payload.per_page || state.attendeePerPage) + 1;
        var endIdx  = Math.min(start + attendees.length - 1, total);

        box.innerHTML =
            '<table class="ke-org-table">' +
                '<thead><tr>' +
                    '<th>Name</th><th>Email</th><th>Event</th><th>Ticket</th><th>Status</th><th>Purchased</th>' +
                '</tr></thead>' +
                '<tbody>' + rows + '</tbody>' +
            '</table>' +
            '<div class="ke-org-pagination">' +
                '<span>' + start + '–' + endIdx + ' of ' + fmtInt(total) + '</span>' +
                '<span>' +
                    '<button id="keOrgPrev" ' + (page <= 1 ? 'disabled' : '') + '>Previous</button> ' +
                    '<button id="keOrgNext" ' + (page >= pages ? 'disabled' : '') + '>Next</button>' +
                '</span>' +
            '</div>';

        on($('keOrgPrev'), 'click', function () { if (state.attendeePage > 1) { state.attendeePage--; loadAttendees(); } });
        on($('keOrgNext'), 'click', function () { if (state.attendeePage < pages) { state.attendeePage++; loadAttendees(); } });
    }

    function loadAttendees() {
        var qs = '?page=' + state.attendeePage +
                 '&per_page=' + state.attendeePerPage +
                 '&search=' + encodeURIComponent(state.attendeeSearch) +
                 (state.filterEventId ? '&event_id=' + state.filterEventId : '');
        return getJson(cfg.restUrl + cfg.slug + '/attendees' + qs).then(function (r) {
            if (!r.ok || !r.data) {
                if (r.status === 401) { window.location.reload(); return; }
                return;
            }
            renderAttendees(r.data);
        });
    }

    /* ── Activity feed ───────────────────────────────────────────── */

    function renderActivity(items) {
        var ul = $('keOrgActivity');
        if (!ul) return;
        if (!items || !items.length) {
            ul.innerHTML = '<li class="ke-org-empty" style="border:none;">No recent activity.</li>';
            return;
        }
        ul.innerHTML = items.map(function (i) {
            var initial = (i.buyer_name || '?').trim().charAt(0).toUpperCase() || '?';
            var qty     = i.quantity || 0;
            var qtyTxt  = qty + ' ticket' + (qty === 1 ? '' : 's');
            var name    = i.buyer_name || 'Someone';
            var ticket  = i.ticket_type ? (' · ' + escapeHtml(i.ticket_type)) : '';
            var amount  = Number(i.gross) > 0
                ? '<span class="ke-org-activity-amount">' + escapeHtml(fmtMoney(i.gross)) + '</span>'
                : '<span class="ke-org-activity-amount ke-org-activity-amount--free">Free</span>';

            return '<li>' +
                       '<span class="ke-org-activity-icon">' + escapeHtml(initial) + '</span>' +
                       '<div class="ke-org-activity-body">' +
                           '<p class="ke-org-activity-text">' +
                               '<strong>' + escapeHtml(name) + '</strong> bought ' + escapeHtml(qtyTxt) +
                           '</p>' +
                           '<p class="ke-org-activity-meta">' +
                               escapeHtml(i.event_title || '') + ticket +
                           '</p>' +
                       '</div>' +
                       '<div class="ke-org-activity-right">' +
                           amount +
                           '<span class="ke-org-activity-time">' + escapeHtml(fmtRelative(i.created_at)) + '</span>' +
                       '</div>' +
                   '</li>';
        }).join('');
    }

    function loadActivity() {
        return getJson(cfg.restUrl + cfg.slug + '/activity').then(function (r) {
            if (!r.ok || !r.data) {
                if (r.status === 401) { window.location.reload(); return; }
                return;
            }
            renderActivity(r.data.items || []);
        });
    }

    /* ── Exports ─────────────────────────────────────────────────── */

    function withQuery(base, params) {
        var pairs = [];
        Object.keys(params).forEach(function (k) {
            if (params[k] === '' || params[k] == null) return;
            pairs.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
        });
        if (!pairs.length) return base;
        return base + (base.indexOf('?') === -1 ? '?' : '&') + pairs.join('&');
    }

    // Open the PDF endpoint in a new tab. eventId 0 means the organizer-wide
    // report; >0 scopes the report to a single event. Opens in a new tab so
    // the HTML fallback (when FPDF is unavailable) doesn't replace the
    // dashboard page.
    //
    // Auth note: NO _wpnonce in the URL. The endpoint authenticates via the
    // HTTP-only organizer session cookie (which window.open carries on
    // same-origin requests). If we send a stale wp_rest nonce, WP REST's
    // rest_cookie_check_errors filter rejects the request with 403
    // rest_cookie_invalid_nonce *before* our permission_callback ever runs.
    function downloadPdf(eventId) {
        var url = withQuery(cfg.restUrl + cfg.slug + '/export/pdf', {
            range:    state.range || 'all',
            event_id: (eventId && eventId > 0) ? eventId : ''
        });
        window.open(url, '_blank', 'noopener');
    }

    function downloadCsv(eventId) {
        var url = withQuery(cfg.restUrl + cfg.slug + '/export/csv', {
            event_id: (eventId && eventId > 0) ? eventId : ''
        });
        window.location.href = url;
    }

    function initExports() {
        // Attendee-toolbar CSV — respects the active event filter.
        on($('keOrgExportCsv'), 'click', function () {
            downloadCsv(state.filterEventId || 0);
        });
        // Top-of-dashboard global exports — always organizer-wide.
        on($('keOrgExportCsvTop'), 'click', function () { downloadCsv(0); });
        on($('keOrgExportPdfTop'), 'click', function () { downloadPdf(0); });
    }

    /* ── Search input ────────────────────────────────────────────── */

    function initSearch() {
        var input = $('keOrgAttendeeSearch');
        on(input, 'input', function () {
            clearTimeout(state.searchTimer);
            state.searchTimer = setTimeout(function () {
                state.attendeeSearch = input.value;
                state.attendeePage = 1;
                loadAttendees();
            }, 300);
        });
    }

    function showStatsError(msg) {
        var box = $('keOrgStats');
        if (!box) return;
        box.removeAttribute('aria-busy');
        box.innerHTML = '<div class="ke-org-stats-error">' + escapeHtml(msg) + '</div>';
    }
    function showChartError(msg) {
        var titleEl = $('keOrgChartTitle');
        if (titleEl) titleEl.textContent = 'Chart unavailable';
        var canvas = $('keOrgChart');
        if (!canvas || !canvas.parentNode) return;
        var existing = canvas.parentNode.querySelector('.ke-org-chart-error');
        if (existing) existing.remove();
        canvas.style.display = 'none';
        var div = document.createElement('div');
        div.className = 'ke-org-chart-error';
        div.textContent = msg;
        canvas.parentNode.appendChild(div);
    }
    function showEventsError(msg) {
        var box = $('keOrgEvents');
        if (box) box.innerHTML = '<div class="ke-org-empty">' + escapeHtml(msg) + '</div>';
    }

    // Each widget renders inside its own try/catch so one bad payload
    // (e.g. a Chart.js exception) doesn't block the others — the rest of
    // the dashboard still loads. Errors land in the console for debugging.
    function safeRender(name, fn, onErr) {
        try { fn(); }
        catch (e) {
            try { console.error('[KiwiEvents] ' + name + ' render failed:', e); } catch (_) {}
            try { onErr && onErr(); } catch (_) {}
        }
    }

    function setRetryBanner(message) {
        var el = $('keOrgStatusBanner');
        if (!el) return;
        if (!message) { el.hidden = true; el.textContent = ''; return; }
        el.hidden = false;
        el.textContent = message;
    }

    function tickLastUpdated() {
        var el = $('keOrgLastUpdated');
        if (!el) return;
        var text = el.querySelector('.ke-org-last-updated-text');
        if (!text) return;
        if (!state.lastUpdatedAt) { text.textContent = 'Loading…'; return; }
        var secs = Math.max(0, Math.round((Date.now() - state.lastUpdatedAt) / 1000));
        var label;
        if (secs < 5)        label = 'Updated just now';
        else if (secs < 60)  label = 'Last updated ' + secs + 's ago';
        else if (secs < 3600) {
            var m = Math.floor(secs / 60);
            label = 'Last updated ' + m + (m === 1 ? ' min ago' : ' mins ago');
        } else {
            var h = Math.floor(secs / 3600);
            label = 'Last updated ' + h + (h === 1 ? ' hour ago' : ' hours ago');
        }
        text.textContent = label;
    }

    // In-place merge of a fresh stats payload over the existing DOM. Animates
    // the numeric values in the headline cards rather than re-rendering the
    // whole grid (which would flash and lose focus).
    function applyStats(headline) {
        var box = $('keOrgStats');
        var prev = state.prevHeadline;
        // First load OR shape change (free-only ↔ paid) → full re-render.
        var shapeChanged = !prev
            || (!!prev.free_only !== !!headline.free_only)
            || ((prev.free_tickets > 0) !== (headline.free_tickets > 0));
        if (!box || shapeChanged) {
            renderStats(headline);
            state.prevHeadline = headline;
            return;
        }

        // Same shape → tween the numeric values inside the existing cards so
        // counters smoothly count up/down instead of snapping.
        var values = box.querySelectorAll('.ke-org-stat-value');
        // Card order matches renderStats(): tickets, [revenue?], [free?], check-in.
        var idx = 0;
        if (values[idx]) animateStatValue(values[idx], prev.tickets_sold, headline.tickets_sold, function (v) { return fmtInt(Math.round(v)); });
        idx++;
        if (!headline.free_only) {
            if (values[idx]) animateStatValue(values[idx], prev.net_revenue, headline.net_revenue, function (v) { return fmtMoney(v); });
            idx++;
        }
        if ((headline.free_tickets || 0) > 0) {
            if (values[idx]) animateStatValue(values[idx], prev.free_tickets, headline.free_tickets, function (v) { return fmtInt(Math.round(v)); });
            idx++;
        }
        if (values[idx]) values[idx].textContent = fmtPct(headline.check_in_rate);

        state.prevHeadline = headline;
    }

    // silent: when true, this is a background poll — don't blow away the
    // rendered UI on transient failures. The retry banner is the user-facing
    // signal once we've failed three in a row.
    function fetchDashboardStats(silent) {
        if (state.statsInflight) return Promise.resolve();
        state.statsInflight = true;
        var url = cfg.restUrl + cfg.slug + '/stats?range=' + encodeURIComponent(state.range);
        return getJson(url).then(function (r) {
            state.statsInflight = false;
            if (!r.ok || !r.data) {
                if (r.status === 401) { window.location.reload(); return; }
                state.consecutiveFailures++;
                if (!silent) {
                    var msg = (r.data && r.data.message) || 'Could not load dashboard data. Please refresh or contact support.';
                    showStatsError(msg);
                    showChartError(msg);
                    showEventsError(msg);
                }
                if (state.consecutiveFailures >= 3) {
                    setRetryBanner('Live updates are paused — could not reach the server. We\'ll keep trying.');
                }
                return;
            }
            state.consecutiveFailures = 0;
            setRetryBanner('');
            state.lastUpdatedAt = Date.now();
            tickLastUpdated();
            safeRender('stats',  function () { applyStats(r.data.headline || {}); },
                                 function () { showStatsError('Stats failed to render.'); });
            safeRender('chart',  function () { renderChart(r.data.series || []); },
                                 function () { showChartError('Chart failed to render.'); });
            safeRender('events', function () { renderEvents(r.data.events || []); hydrateResvEventFilter(); },
                                 function () { showEventsError('Events failed to render.'); });
        }).catch(function () {
            state.statsInflight = false;
            state.consecutiveFailures++;
            if (!silent) {
                var msg = 'Network error. Please check your connection and try again.';
                showStatsError(msg);
                showChartError(msg);
                showEventsError(msg);
            }
            if (state.consecutiveFailures >= 3) {
                setRetryBanner('Live updates are paused — could not reach the server. We\'ll keep trying.');
            }
        });
    }

    // Backward-compat shim: a few internal callers still call loadStats()
    // (e.g. range-change handler). Treat those as foreground refreshes.
    function loadStats() { return fetchDashboardStats(false); }

    /* ── Reservations ────────────────────────────────────────────────
     * The reservations panel is its own scoped sub-state. It piggybacks on
     * `state.events` for the event-filter dropdown and on the same nonce/
     * REST plumbing used by the rest of the dashboard.
     * ────────────────────────────────────────────────────────────── */

    var resvState = {
        status: '',        // '' = all
        eventId: 0,
        from: '',
        to: '',
        search: '',
        page: 1,
        perPage: 25,
        searchTimer: null,
        loading: false,
        rows: [],
        counts: {},
        total: 0,
        eventFilterPopulatedFrom: 0,  // tracks the events array length we last hydrated from
        currentRow: null   // when drawer is open
    };

    var RESV_STATUS_LABEL = {
        pending:           'Pending',
        confirmed:         'Confirmed',
        cancelled:         'Cancelled',
        cancelled_no_show: 'No-show',
        cancelled_by_venue:'Venue cancelled',
        declined:          'Declined'
    };

    function fmtArrival(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        try {
            return d.toLocaleString(undefined, {
                weekday: 'short', month: 'short', day: 'numeric',
                hour: 'numeric', minute: '2-digit'
            });
        } catch (e) { return d.toISOString(); }
    }

    // Hydrate the event-filter <select> the first time we have events. We
    // re-run only if state.events grows (a new event was created mid-session)
    // so we don't blow away the user's current selection on every refresh.
    function hydrateResvEventFilter() {
        var sel = $('keOrgResvEventFilter');
        if (!sel || !state.events) return;
        if (resvState.eventFilterPopulatedFrom === state.events.length) return;
        var current = sel.value;
        var html = '<option value="">All events</option>';
        state.events.forEach(function (e) {
            html += '<option value="' + e.id + '">' + escapeHtml(e.title) + '</option>';
        });
        sel.innerHTML = html;
        if (current) sel.value = current;
        resvState.eventFilterPopulatedFrom = state.events.length;
    }

    function setResvCount(key, n) {
        var nodes = document.querySelectorAll('#keOrgResvPills [data-count="' + key + '"]');
        Array.prototype.forEach.call(nodes, function (el) { el.textContent = fmtInt(n); });
    }

    function applyResvCounts(counts) {
        resvState.counts = counts || {};
        Object.keys(RESV_STATUS_LABEL).concat(['all']).forEach(function (k) {
            setResvCount(k, resvState.counts[k] || 0);
        });
    }

    function loadReservations() {
        var box = $('keOrgReservations');
        if (!box) return;
        if (resvState.loading) return;
        resvState.loading = true;

        if (!box.children.length || box.querySelector('.ke-org-resv-loading')) {
            box.innerHTML = '<div class="ke-org-resv-loading">Loading reservations…</div>';
        } else {
            box.classList.add('is-refreshing');
        }

        var url = withQuery(cfg.restUrl + cfg.slug + '/reservations', {
            status:   resvState.status,
            event_id: resvState.eventId || '',
            from:     resvState.from,
            to:       resvState.to,
            search:   resvState.search,
            page:     resvState.page,
            per_page: resvState.perPage
        });
        getJson(url).then(function (r) {
            resvState.loading = false;
            box.classList.remove('is-refreshing');
            if (!r.ok || !r.data) {
                if (r.status === 401) { window.location.reload(); return; }
                box.innerHTML = '<div class="ke-org-resv-error">' +
                    escapeHtml((r.data && r.data.message) || 'Could not load reservations.') +
                    '</div>';
                return;
            }
            resvState.rows  = r.data.reservations || [];
            resvState.total = r.data.total || 0;
            applyResvCounts(r.data.counts || {});
            renderReservations();
        }).catch(function () {
            resvState.loading = false;
            box.classList.remove('is-refreshing');
            box.innerHTML = '<div class="ke-org-resv-error">Network error. Please try again.</div>';
        });
    }

    function renderReservations() {
        var box = $('keOrgReservations');
        if (!box) return;

        if (!resvState.rows.length) {
            box.innerHTML = '<div class="ke-org-empty">No reservations match these filters.</div>';
            return;
        }

        var rowsHtml = resvState.rows.map(function (r) {
            var statusLabel = RESV_STATUS_LABEL[r.status] || r.status;
            var partyLine = fmtInt(r.party_size) + ' guest' + (r.party_size === 1 ? '' : 's');
            var areaLine  = r.area ? ' · ' + escapeHtml(r.area) : '';
            return '<div class="ke-org-resv-row" data-resv-id="' + r.id + '">' +
                       '<div class="ke-org-resv-row-main">' +
                           '<div class="ke-org-resv-row-name">' + escapeHtml(r.customer_name) +
                               ' <span class="ke-org-resv-row-code">#' + escapeHtml(r.reservation_code) + '</span>' +
                           '</div>' +
                           '<div class="ke-org-resv-row-meta">' +
                               escapeHtml(r.event_title || '') + ' · ' + escapeHtml(fmtArrival(r.arrival_time)) +
                           '</div>' +
                           '<div class="ke-org-resv-row-party">' + partyLine + areaLine + '</div>' +
                       '</div>' +
                       '<div class="ke-org-resv-row-side">' +
                           '<span class="ke-org-resv-status ke-org-resv-status--' + escapeHtml(r.status) + '">' +
                               escapeHtml(statusLabel) +
                           '</span>' +
                           '<button type="button" class="ke-org-resv-row-open" data-resv-open="' + r.id + '">View</button>' +
                       '</div>' +
                   '</div>';
        }).join('');

        var pages = Math.max(1, Math.ceil(resvState.total / resvState.perPage));
        var pager = pages > 1
            ? '<div class="ke-org-resv-pager">' +
                  '<button type="button" class="ke-org-resv-page-btn" data-resv-page="prev"' + (resvState.page <= 1 ? ' disabled' : '') + '>Prev</button>' +
                  '<span class="ke-org-resv-page-info">Page ' + resvState.page + ' of ' + pages + '</span>' +
                  '<button type="button" class="ke-org-resv-page-btn" data-resv-page="next"' + (resvState.page >= pages ? ' disabled' : '') + '>Next</button>' +
              '</div>'
            : '';

        box.innerHTML = '<div class="ke-org-resv-list">' + rowsHtml + '</div>' + pager;

        Array.prototype.forEach.call(box.querySelectorAll('[data-resv-open]'), function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.getAttribute('data-resv-open'), 10) || 0;
                var row = resvState.rows.filter(function (r) { return r.id === id; })[0];
                if (row) openResvDrawer(row);
            });
        });
        Array.prototype.forEach.call(box.querySelectorAll('[data-resv-page]'), function (btn) {
            btn.addEventListener('click', function () {
                var dir = btn.getAttribute('data-resv-page');
                if (dir === 'next') resvState.page++;
                else if (dir === 'prev' && resvState.page > 1) resvState.page--;
                loadReservations();
            });
        });
    }

    function openResvDrawer(row) {
        var drawer = $('keOrgResvDrawer');
        var titleEl = $('keOrgResvDrawerTitle');
        var bodyEl  = $('keOrgResvDrawerBody');
        var actEl   = $('keOrgResvDrawerActions');
        if (!drawer || !bodyEl || !actEl) return;
        resvState.currentRow = row;

        if (titleEl) {
            titleEl.textContent = row.customer_name + ' — #' + row.reservation_code;
        }

        var statusLabel = RESV_STATUS_LABEL[row.status] || row.status;
        var detailRows = [
            ['Status', '<span class="ke-org-resv-status ke-org-resv-status--' + escapeHtml(row.status) + '">' + escapeHtml(statusLabel) + '</span>'],
            ['Event', escapeHtml(row.event_title || '')],
            ['Arrival', escapeHtml(fmtArrival(row.arrival_time))],
            ['Party size', fmtInt(row.party_size)],
            ['Area', row.area ? escapeHtml(row.area) : '—'],
            ['Phone', row.customer_phone ? escapeHtml(row.customer_phone) : '—'],
            ['Email', row.customer_email ? escapeHtml(row.customer_email) : '—'],
            ['Notes', row.notes ? escapeHtml(row.notes) : '—'],
            ['Created', escapeHtml(fmtArrival(row.created_at))]
        ];
        if (row.checked_in_at) {
            detailRows.push(['Checked in', escapeHtml(fmtArrival(row.checked_in_at))]);
        }
        if (row.decline_reason) {
            detailRows.push(['Decline reason', escapeHtml(row.decline_reason)]);
        }

        var detailsHtml = detailRows.map(function (r) {
            return '<div class="ke-org-resv-detail-row">' +
                       '<div class="ke-org-resv-detail-label">' + r[0] + '</div>' +
                       '<div class="ke-org-resv-detail-value">' + r[1] + '</div>' +
                   '</div>';
        }).join('');

        var extrasHtml = '';
        if (row.extra_fields && row.extra_fields.length) {
            extrasHtml = '<div class="ke-org-resv-detail-extras">' +
                             '<h4>Submitted info</h4>' +
                             row.extra_fields.map(function (f) {
                                 return '<div class="ke-org-resv-detail-row">' +
                                            '<div class="ke-org-resv-detail-label">' + escapeHtml(f.label || '') + '</div>' +
                                            '<div class="ke-org-resv-detail-value">' + escapeHtml(f.value || '') + '</div>' +
                                        '</div>';
                             }).join('') +
                         '</div>';
        }

        bodyEl.innerHTML = detailsHtml + extrasHtml;
        actEl.innerHTML = renderResvActions(row);

        Array.prototype.forEach.call(actEl.querySelectorAll('[data-resv-action]'), function (btn) {
            btn.addEventListener('click', function () {
                runResvAction(row, btn.getAttribute('data-resv-action'), btn);
            });
        });

        drawer.hidden = false;
        document.body.classList.add('ke-org-resv-drawer-open');
    }

    function closeResvDrawer() {
        var drawer = $('keOrgResvDrawer');
        if (!drawer) return;
        drawer.hidden = true;
        resvState.currentRow = null;
        document.body.classList.remove('ke-org-resv-drawer-open');
    }

    function renderResvActions(row) {
        var btns = [];
        if (row.status === 'pending') {
            btns.push('<button type="button" class="ke-org-resv-btn ke-org-resv-btn--primary" data-resv-action="approve">Approve</button>');
            btns.push('<button type="button" class="ke-org-resv-btn ke-org-resv-btn--danger" data-resv-action="decline">Decline</button>');
        }
        if (row.status === 'confirmed') {
            btns.push('<button type="button" class="ke-org-resv-btn ke-org-resv-btn--primary" data-resv-action="check-in">Check in</button>');
            btns.push('<button type="button" class="ke-org-resv-btn ke-org-resv-btn--danger" data-resv-action="cancel">Cancel</button>');
        }
        if (!btns.length) {
            btns.push('<p class="ke-org-resv-actions-note">No actions available for ' + (RESV_STATUS_LABEL[row.status] || row.status).toLowerCase() + ' reservations.</p>');
        }
        return btns.join('');
    }

    function runResvAction(row, action, btn) {
        if (!row || !action) return;

        // Decline asks for an optional reason — it surfaces back to the
        // customer in the email so the venue can explain (e.g. "fully
        // booked, try Friday").
        var payload = {};
        if (action === 'decline') {
            var reason = window.prompt('Optional: reason to share with the customer.', '');
            if (reason === null) return; // cancelled prompt
            payload.reason = reason || '';
        } else if (action === 'cancel') {
            if (!window.confirm('Cancel this reservation? The customer will be emailed.')) return;
        } else if (action === 'check-in') {
            if (!window.confirm('Mark this reservation as checked in?')) return;
        }

        if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }

        var url = cfg.restUrl + cfg.slug + '/reservations/' + row.id + '/' + action;
        postJson(url, payload).then(function (r) {
            if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
            if (!r.ok || !r.data || !r.data.success) {
                var msg = (r.data && r.data.message) || 'Action failed.';
                window.alert(msg);
                return;
            }
            // Replace the row in our local cache so the list reflects the
            // new status without a full reload, and refresh counts.
            var updated = r.data.reservation;
            if (updated) {
                resvState.rows = resvState.rows.map(function (x) {
                    return x.id === updated.id ? updated : x;
                });
                renderReservations();
                openResvDrawer(updated);
            }
            // Background refresh so counts/headline reflect the change.
            loadReservations();
            fetchDashboardStats(true);
        }).catch(function () {
            if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); }
            window.alert('Network error. Please try again.');
        });
    }

    function initReservations() {
        var section = $('keOrgReservationsSection');
        if (!section) return;

        // Pills (status filter).
        var pills = $('keOrgResvPills');
        if (pills) {
            pills.addEventListener('click', function (e) {
                var btn = e.target.closest && e.target.closest('.ke-org-resv-pill');
                if (!btn) return;
                Array.prototype.forEach.call(pills.querySelectorAll('.ke-org-resv-pill'), function (b) {
                    b.classList.remove('is-active');
                    b.setAttribute('aria-selected', 'false');
                });
                btn.classList.add('is-active');
                btn.setAttribute('aria-selected', 'true');
                resvState.status = btn.getAttribute('data-status') || '';
                resvState.page = 1;
                loadReservations();
            });
        }

        // Search (debounced).
        var search = $('keOrgResvSearch');
        on(search, 'input', function () {
            clearTimeout(resvState.searchTimer);
            resvState.searchTimer = setTimeout(function () {
                resvState.search = search.value;
                resvState.page = 1;
                loadReservations();
            }, 300);
        });

        // Event filter.
        on($('keOrgResvEventFilter'), 'change', function () {
            resvState.eventId = parseInt(this.value, 10) || 0;
            resvState.page = 1;
            loadReservations();
        });

        // Date filters.
        on($('keOrgResvFrom'), 'change', function () {
            resvState.from = this.value || '';
            resvState.page = 1;
            loadReservations();
        });
        on($('keOrgResvTo'), 'change', function () {
            resvState.to = this.value || '';
            resvState.page = 1;
            loadReservations();
        });

        // Drawer close handlers (overlay + close button + Esc).
        var drawer = $('keOrgResvDrawer');
        if (drawer) {
            drawer.addEventListener('click', function (e) {
                if (e.target.getAttribute && e.target.getAttribute('data-resv-close') === '1') {
                    closeResvDrawer();
                }
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && drawer && !drawer.hidden) closeResvDrawer();
        });
    }

    /* ── Real-time sales beacon ──────────────────────────────────────
       Polls /last-sale every 8s while the tab is visible. When the
       timestamp advances we trigger an immediate full refresh and show
       a "New sale!" toast. The /last-sale endpoint is one transient
       read on the server — no DB query — so ~450 reqs/hr per dashboard
       is fine.
    ──────────────────────────────────────────────────────────────── */
    function pollSalesBeacon() {
        if (state.beaconInflight) return;
        state.beaconInflight = true;
        getJson(cfg.restUrl + cfg.slug + '/last-sale').then(function (r) {
            state.beaconInflight = false;
            var ts = parseInt(r && r.last_sale, 10) || 0;
            if (ts > state.lastSaleTs) {
                var first = state.lastSaleTs === 0;
                state.lastSaleTs = ts;
                // Don't toast/refresh on the very first observation — we
                // seed lastSaleTs from PHP but if the seed was 0 (e.g.
                // brand-new organizer) the first non-zero value is "now
                // known", not "new since you opened the page".
                if (!first) {
                    showNewSaleToast();
                    fetchDashboardStats(true);
                    loadActivity();
                }
            }
        }).catch(function () {
            state.beaconInflight = false;
            // Silent — the 60s full poll will catch anything missed.
        });
    }

    function showNewSaleToast() {
        var bar = $('keOrgStatusBar');
        if (!bar) return;
        var toast = $('keOrgNewSaleToast');
        if (!toast) {
            toast = document.createElement('span');
            toast.id = 'keOrgNewSaleToast';
            toast.className = 'ke-org-new-sale-toast';
            toast.setAttribute('role', 'status');
            toast.innerHTML =
                '<span class="ke-org-new-sale-dot" aria-hidden="true"></span>' +
                '<span class="ke-org-new-sale-label">New sale!</span>';
            bar.appendChild(toast);
        }
        // Reset the visible state so re-triggering restarts the animation.
        toast.classList.remove('is-visible');
        // Force reflow so the transition restarts.
        void toast.offsetWidth;
        toast.classList.add('is-visible');
        if (state.toastTimer) clearTimeout(state.toastTimer);
        state.toastTimer = setTimeout(function () {
            toast.classList.remove('is-visible');
            state.toastTimer = null;
        }, 4000);
    }

    function startPolling() {
        if (!state.statsTimer) {
            state.statsTimer = setInterval(function () { fetchDashboardStats(true); }, 60000);
        }
        if (!state.activityTimer) {
            state.activityTimer = setInterval(loadActivity, 30000);
        }
        if (!state.beaconTimer) {
            state.beaconTimer = setInterval(pollSalesBeacon, 8000);
        }
        if (!state.lastUpdatedTimer) {
            state.lastUpdatedTimer = setInterval(tickLastUpdated, 5000);
        }
    }
    function stopPolling() {
        if (state.statsTimer)       { clearInterval(state.statsTimer);       state.statsTimer = null; }
        if (state.activityTimer)    { clearInterval(state.activityTimer);    state.activityTimer = null; }
        if (state.beaconTimer)      { clearInterval(state.beaconTimer);      state.beaconTimer = null; }
        if (state.lastUpdatedTimer) { clearInterval(state.lastUpdatedTimer); state.lastUpdatedTimer = null; }
    }

    function initDashboard() {
        var range = $('keOrgRange');
        if (range) {
            state.range = range.value || '30';
            on(range, 'change', function () {
                state.range = range.value || '30';
                state.prevHeadline = null; // range change → re-render, don't tween
                fetchDashboardStats(false);
            });
        }
        initSearch();
        initExports();
        initReservations();

        fetchDashboardStats(false);
        loadAttendees();
        loadActivity();
        loadReservations();
        tickLastUpdated();

        startPolling();

        // Pause polling when the tab is hidden; resume + immediately refresh
        // on return so the user sees current numbers without waiting 60s.
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stopPolling();
            } else {
                fetchDashboardStats(true);
                loadActivity();
                tickLastUpdated();
                pollSalesBeacon(); // catch up immediately on tab return
                startPolling();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initLogin();
        initLogout();
        if ($('keOrgStats')) {
            initDashboard();
        }
    });
})();
