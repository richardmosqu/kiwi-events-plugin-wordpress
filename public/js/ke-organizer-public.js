/* ════════════════════════════════════════════════════════════════
   Public Organizer Profile — interactive layer
   - Tabs (Eventos / Galería / Calendario)
   - Events upcoming/past pill toggle
   - Gallery lightbox (ESC/backdrop close, prev/next)
   - Vanilla-JS month calendar (no FullCalendar dependency)

   Reads localized data from `keOrganizerPublic` (see PHP enqueuer).
   ════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var L = window.keOrganizerPublic || { i18n: {} };
    var i18n = L.i18n || {};

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initEventsToggle();
        initLightbox();
        initCalendar();
        initAnalytics();
    });

    /* ── TABS ──────────────────────────────────────────────────── */
    function initTabs() {
        var tabs = document.querySelectorAll('.ke-op-tab');
        var panels = document.querySelectorAll('.ke-op-panel');
        if (!tabs.length) return;

        tabs.forEach(function (tab, index) {
            tab.tabIndex = tab.classList.contains('is-active') ? 0 : -1;
            tab.addEventListener('click', function () {
                var key = tab.getAttribute('data-tab');
                tabs.forEach(function (t) {
                    var active = t === tab;
                    t.classList.toggle('is-active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                    t.tabIndex = active ? 0 : -1;
                });
                panels.forEach(function (p) {
                    var active = p.id === 'ke-op-panel-' + key;
                    p.hidden = !active;
                    p.classList.toggle('is-active', active);
                });

                // The calendar needs a render kick when its panel becomes
                // visible — it can't measure cells while display:none.
                if (key === 'calendar') {
                    window.dispatchEvent(new Event('ke-op-calendar-show'));
                }
            });

            tab.addEventListener('keydown', function (event) {
                var targetIndex = index;
                if (event.key === 'ArrowRight') targetIndex = (index + 1) % tabs.length;
                else if (event.key === 'ArrowLeft') targetIndex = (index - 1 + tabs.length) % tabs.length;
                else if (event.key === 'Home') targetIndex = 0;
                else if (event.key === 'End') targetIndex = tabs.length - 1;
                else return;
                event.preventDefault();
                tabs[targetIndex].focus();
                tabs[targetIndex].click();
            });
        });
    }

    /* ── EVENTS UPCOMING / PAST ────────────────────────────────── */
    function initEventsToggle() {
        var pills = document.querySelectorAll('.ke-op-pill[data-events]');
        var grids = document.querySelectorAll('.ke-op-events-grid');
        if (!pills.length) return;

        pills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                if (pill.disabled) return;
                var state = pill.getAttribute('data-events');

                pills.forEach(function (p) {
                    p.classList.toggle('is-active', p === pill);
                });
                grids.forEach(function (g) {
                    g.hidden = (g.getAttribute('data-state') !== state);
                });
            });
        });
    }

    /* ── LIGHTBOX ──────────────────────────────────────────────── */
    // Defensive design after a production incident where a global keydown
    // listener + a body overflow lock combined to break WooCommerce's mini
    // cart drawer:
    //   • The keydown handler is bound only while the lightbox is open and
    //     unbound on close, so it can never compete with WC for Escape.
    //   • The body overflow toggle is paired with try/finally and a sentinel
    //     so it cannot get stuck at 'hidden' if anything throws in show().
    function initLightbox() {
        var items = Array.from(document.querySelectorAll('.ke-op-gallery-item'));
        var box   = document.getElementById('ke-op-lightbox');
        if (!items.length || !box) return;

        var img   = document.getElementById('ke-op-lightbox-img');
        var video = document.getElementById('ke-op-lightbox-video');
        var close = document.getElementById('ke-op-lightbox-close');
        var prev  = document.getElementById('ke-op-lightbox-prev');
        var next  = document.getElementById('ke-op-lightbox-next');
        var idx   = 0;
        var prevBodyOverflow = '';
        var keydownBound = false;

        function onKeydown(e) {
            if (box.hidden) return;
            if (e.key === 'Escape') { hide(); }
            else if (e.key === 'ArrowLeft')  { show(idx - 1); }
            else if (e.key === 'ArrowRight') { show(idx + 1); }
        }

        function show(i) {
            try {
                idx = (i + items.length) % items.length;
                var item = items[idx];
                var type = item.getAttribute('data-type') || 'image';
                var full = item.getAttribute('data-full') || '';

                img.hidden = true;
                img.src = '';
                if (video) {
                    video.pause();
                    video.removeAttribute('src');
                    video.hidden = true;
                    video.load();
                }

                if (type === 'video' && video) {
                    video.src = full;
                    video.hidden = false;
                } else {
                    img.src = full;
                    img.alt = item.querySelector('img') ? item.querySelector('img').alt : '';
                    img.hidden = false;
                }
                box.hidden = false;
                box.setAttribute('aria-hidden', 'false');
                if (!keydownBound) {
                    prevBodyOverflow = document.body.style.overflow || '';
                    document.body.style.overflow = 'hidden';
                    document.addEventListener('keydown', onKeydown);
                    keydownBound = true;
                }
            } catch (err) {
                // If anything goes wrong, force a safe state so we never
                // leave the page with overflow:hidden or a stale listener
                // that could block other widgets (e.g. WC cart drawer).
                hide();
                if (window.console && console.error) console.error('[ke-op] lightbox show failed', err);
            }
        }
        function hide() {
            try {
                box.hidden = true;
                box.setAttribute('aria-hidden', 'true');
                img.hidden = true;
                img.src = '';
                if (video) {
                    video.pause();
                    video.removeAttribute('src');
                    video.hidden = true;
                    video.load();
                }
            } finally {
                // Only restore overflow / unbind keydown if WE actually
                // acquired the lock — `keydownBound` doubles as that
                // sentinel. Restoring unconditionally would clobber an
                // overflow lock set by another widget (e.g. the WC cart
                // drawer) on hide() paths where we never locked.
                if (keydownBound) {
                    document.removeEventListener('keydown', onKeydown);
                    document.body.style.overflow = prevBodyOverflow;
                    keydownBound = false;
                }
            }
        }

        items.forEach(function (it, i) {
            it.addEventListener('click', function () { show(i); });
        });
        close.addEventListener('click', hide);
        prev.addEventListener('click', function (e) { e.stopPropagation(); show(idx - 1); });
        next.addEventListener('click', function (e) { e.stopPropagation(); show(idx + 1); });

        // Backdrop click closes (but ignore clicks that originated on the
        // image or the prev/next/close controls).
        box.addEventListener('click', function (e) {
            if (e.target === box || e.target.closest('#ke-op-lightbox-close')) hide();
        });
    }

    /* ── CALENDAR ──────────────────────────────────────────────── */
    function initCalendar() {
        var root = document.querySelector('.ke-op-calendar');
        if (!root) return;

        var raw;
        try { raw = JSON.parse(root.getAttribute('data-events') || '[]'); }
        catch (e) { raw = []; }

        // Index events by YYYY-MM-DD for O(1) lookup per cell. An event
        // spanning multiple days is mapped onto each day in its range.
        var byDay = {};
        raw.forEach(function (ev) {
            var s = parseISODate(ev.start);
            var e = parseISODate(ev.end || ev.start);
            if (!s || !e) return;
            // Cap span at 90 days as a guard against bad data.
            for (var d = new Date(s), n = 0; d <= e && n < 90; d.setDate(d.getDate() + 1), n++) {
                var key = ymd(d);
                (byDay[key] = byDay[key] || []).push(ev);
            }
        });

        var today = stripTime(new Date());
        var view  = new Date(today.getFullYear(), today.getMonth(), 1);

        function render() {
            root.innerHTML = '';

            // Header
            var header = el('div', 'ke-op-cal-header');
            var title  = el('h3', 'ke-op-cal-title');
            title.textContent = (i18n.months_full ? i18n.months_full[view.getMonth()] : view.toLocaleString(L.locale || 'en', { month: 'long' }))
                              + ' ' + view.getFullYear();
            var nav = el('div', 'ke-op-cal-nav');
            nav.appendChild(navBtn('‹', i18n.prev || 'Prev', function () {
                view.setMonth(view.getMonth() - 1); render();
            }));
            nav.appendChild(navBtn(i18n.today || 'Today', null, function () {
                view = new Date(today.getFullYear(), today.getMonth(), 1); render();
            }, 'ke-op-cal-nav-today'));
            nav.appendChild(navBtn('›', i18n.next || 'Next', function () {
                view.setMonth(view.getMonth() + 1); render();
            }));
            header.appendChild(title);
            header.appendChild(nav);
            root.appendChild(header);

            // Weekday row (desktop)
            var wrow = el('div', 'ke-op-cal-grid ke-op-cal-weekday-row');
            (i18n.weekdays || ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']).forEach(function (w) {
                var c = el('div', 'ke-op-cal-weekday'); c.textContent = w; wrow.appendChild(c);
            });
            root.appendChild(wrow);

            // Month grid (Mon-first)
            var grid  = el('div', 'ke-op-cal-grid');
            var first = new Date(view.getFullYear(), view.getMonth(), 1);
            var dow   = (first.getDay() + 6) % 7; // 0 = Mon
            var start = new Date(first); start.setDate(1 - dow);

            for (var i = 0; i < 42; i++) {
                var d = new Date(start); d.setDate(start.getDate() + i);
                var inMonth = (d.getMonth() === view.getMonth());
                var key = ymd(d);
                var dayEvents = byDay[key] || [];

                var cell = el('div', 'ke-op-cal-cell');
                if (!inMonth) cell.classList.add('is-other-month');
                if (sameDay(d, today)) cell.classList.add('is-today');
                if (dayEvents.length) cell.classList.add('has-events');

                var label = el('div', 'ke-op-cal-day');
                label.textContent = d.getDate();
                cell.appendChild(label);

                if (dayEvents.length) {
                    var box = el('div', 'ke-op-cal-events');
                    dayEvents.slice(0, 2).forEach(function (ev) {
                        var pill = el('a', 'ke-op-cal-event');
                        pill.href = ev.permalink;
                        pill.textContent = ev.title;
                        pill.title = ev.title;
                        box.appendChild(pill);
                    });
                    if (dayEvents.length > 2) {
                        var more = el('div', 'ke-op-cal-event-more');
                        more.textContent = '+' + (dayEvents.length - 2);
                        box.appendChild(more);
                    }
                    cell.appendChild(box);
                }
                grid.appendChild(cell);
            }
            root.appendChild(grid);

            // Mobile agenda — lists events from this month forward,
            // collapsed to one row per event.
            var agenda = el('div', 'ke-op-cal-agenda');
            var monthEnd = new Date(view.getFullYear(), view.getMonth() + 1, 0);
            var future = raw
                .map(function (ev) { return Object.assign({}, ev, { _start: parseISODate(ev.start) }); })
                .filter(function (ev) { return ev._start && ev._start <= monthEnd && ev._start >= new Date(view.getFullYear(), view.getMonth(), 1); })
                .sort(function (a, b) { return a._start - b._start; });

            if (!future.length) {
                var empty = el('div', 'ke-op-cal-agenda-item');
                empty.style.justifyContent = 'center';
                empty.style.color = '#94a3b8';
                empty.style.borderLeftColor = 'transparent';
                empty.textContent = i18n.no_events || 'No events';
                agenda.appendChild(empty);
            } else {
                future.forEach(function (ev) {
                    var item  = el('a', 'ke-op-cal-agenda-item');
                    item.href = ev.permalink;
                    var date  = el('div', 'ke-op-cal-agenda-date');
                    var month = el('div', 'ke-op-cal-agenda-month');
                    month.textContent = (i18n.months_short ? i18n.months_short[ev._start.getMonth()] : '').toUpperCase();
                    var day   = el('div', 'ke-op-cal-agenda-day');
                    day.textContent = ev._start.getDate();
                    date.appendChild(month); date.appendChild(day);
                    var titleEl = el('div', 'ke-op-cal-agenda-title');
                    titleEl.textContent = ev.title;
                    item.appendChild(date);
                    item.appendChild(titleEl);
                    agenda.appendChild(item);
                });
            }
            root.appendChild(agenda);
        }

        render();
        // Re-render when the calendar tab activates (in case fonts/sizes
        // shifted while it was hidden).
        window.addEventListener('ke-op-calendar-show', render);
    }

    /* Privacy-safe commercial measurement */
    function initAnalytics() {
        var root = document.querySelector('.ke-org-public');
        if (!root) return;
        var organizer = root.getAttribute('data-organizer-slug') || '';
        root.addEventListener('click', function (event) {
            var cta = event.target.closest('[data-ke-cta]');
            if (cta) {
                trackEvent('organizer_cta_click', {
                    organizer_slug: organizer,
                    cta_action: cta.getAttribute('data-ke-cta') || '',
                    event_id: cta.getAttribute('data-event-id') || '',
                    destination: cta.getAttribute('href') || '',
                    device_context: window.matchMedia('(max-width: 768px)').matches ? 'mobile' : 'desktop'
                });
                return;
            }
            var tab = event.target.closest('.ke-op-tab[data-tab]');
            if (tab) trackEvent('organizer_profile_tab_view', { organizer_slug: organizer, profile_tab: tab.getAttribute('data-tab') || '' });
        });
    }

    function trackEvent(name, params) {
        if (typeof window.gtag === 'function') window.gtag('event', name, params);
        else {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push(Object.assign({ event: name }, params));
        }
    }

    /* ── helpers ───────────────────────────────────────────────── */
    function el(tag, cls) { var n = document.createElement(tag); if (cls) n.className = cls; return n; }
    function navBtn(text, label, onClick, extraClass) {
        var b = el('button', 'ke-op-cal-nav-btn' + (extraClass ? ' ' + extraClass : ''));
        b.type = 'button';
        b.textContent = text;
        if (label) b.setAttribute('aria-label', label);
        b.addEventListener('click', onClick);
        return b;
    }
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function parseISODate(s) {
        if (!s) return null;
        var parts = String(s).slice(0, 10).split('-').map(Number);
        if (parts.length !== 3 || parts.some(isNaN)) return null;
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }
    function stripTime(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
    function sameDay(a, b) {
        return a.getFullYear() === b.getFullYear()
            && a.getMonth() === b.getMonth()
            && a.getDate() === b.getDate();
    }
})();
