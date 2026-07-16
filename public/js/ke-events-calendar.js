/* KiwiEvents — Calendar shortcode
 *
 * Auto-initializes every .ke-calendar on the page. Each instance is fully
 * independent (its own state, fetch cache, selected date).
 *
 * Server preloads the initial month inline via data-preload-events so first
 * paint has no fetch flash; subsequent month navigations fetch via REST.
 */
(function () {
    'use strict';

    var MONTH_NAMES = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];
    var WEEKDAY_LONG = [
        'Sunday', 'Monday', 'Tuesday', 'Wednesday',
        'Thursday', 'Friday', 'Saturday'
    ];

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function ymKey(year, monthIndex) {
        // monthIndex is 0-based
        return year + '-' + pad(monthIndex + 1);
    }

    function isoDate(year, monthIndex, day) {
        return year + '-' + pad(monthIndex + 1) + '-' + pad(day);
    }

    function parseDateOnly(s) {
        // Accepts "YYYY-MM-DD HH:MM:SS" or "YYYY-MM-DDTHH:MM:SS". Returns
        // {y, m0, d} reading as local wall-clock (the values stored on the
        // server are local-time strings, not UTC).
        if (!s) return null;
        var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!m) return null;
        return {
            y: parseInt(m[1], 10),
            m0: parseInt(m[2], 10) - 1,
            d: parseInt(m[3], 10)
        };
    }

    function parseDateTime(s) {
        // Returns {date, hours, minutes} or null.
        if (!s) return null;
        var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (!m) return null;
        return {
            y: parseInt(m[1], 10),
            m0: parseInt(m[2], 10) - 1,
            d: parseInt(m[3], 10),
            h: parseInt(m[4], 10),
            min: parseInt(m[5], 10)
        };
    }

    function formatTime(dt) {
        if (!dt) return '';
        var h = dt.h;
        var min = dt.min;
        var period = h >= 12 ? 'PM' : 'AM';
        var h12 = h % 12;
        if (h12 === 0) h12 = 12;
        return h12 + (min ? ':' + pad(min) : ':00') + ' ' + period;
    }

    function formatTimeRange(startStr, endStr) {
        var s = parseDateTime(startStr);
        if (!s) return '';
        var e = parseDateTime(endStr);
        if (!e) return formatTime(s);
        var sameDay = s.y === e.y && s.m0 === e.m0 && s.d === e.d;
        if (sameDay) {
            return formatTime(s) + ' – ' + formatTime(e);
        }
        return formatTime(s);
    }

    function formatLongDate(year, monthIndex, day) {
        var dayOfWeek = new Date(year, monthIndex, day).getDay();
        return WEEKDAY_LONG[dayOfWeek] + ', ' +
            MONTH_NAMES[monthIndex] + ' ' + day + ', ' + year;
    }

    function isSameYMD(a, b) {
        return a && b && a.y === b.y && a.m0 === b.m0 && a.d === b.d;
    }

    function todayYMD() {
        var d = new Date();
        return { y: d.getFullYear(), m0: d.getMonth(), d: d.getDate() };
    }

    function Calendar(root) {
        this.root = root;
        this.category = root.dataset.category || '';
        this.restUrl = root.dataset.restUrl || '';
        this.weekStart = parseInt(root.dataset.weekStart || '0', 10);
        if (isNaN(this.weekStart) || this.weekStart < 0 || this.weekStart > 6) {
            this.weekStart = 0;
        }

        var startYear = parseInt(root.dataset.year || '0', 10);
        var startMonth = parseInt(root.dataset.month || '0', 10);
        if (!startYear || !startMonth) {
            var now = new Date();
            startYear = now.getFullYear();
            startMonth = now.getMonth() + 1;
        }
        this.year = startYear;
        this.monthIndex = startMonth - 1; // 0-based internally

        // Per-instance fetch cache keyed by YYYY-MM.
        this.cache = {};

        // Selected day {y, m0, d} or null.
        this.selected = null;

        this.monthLabelEl = root.querySelector('.ke-calendar-month-label');
        this.gridEl = root.querySelector('.ke-calendar-grid');
        this.emptyEl = root.querySelector('.ke-calendar-empty');
        this.panelEl = root.querySelector('.ke-calendar-panel');
        this.panelHeaderEl = root.querySelector('.ke-calendar-panel-header');
        this.panelEventsEl = root.querySelector('.ke-calendar-panel-events');
        this.todayBtn = root.querySelector('.ke-calendar-today');

        // Seed cache with the server-preloaded month.
        var preloadKey = root.dataset.preloadKey;
        var rawPreload = root.dataset.preloadEvents;
        if (preloadKey && rawPreload) {
            try {
                var events = JSON.parse(rawPreload);
                if (Array.isArray(events)) {
                    this.cache[preloadKey] = events;
                }
            } catch (_) {
                /* malformed preload — fall back to fetching */
            }
        }

        this.bindEvents();
        this.render();
    }

    Calendar.prototype.bindEvents = function () {
        var self = this;

        var prevBtn = this.root.querySelector('.ke-calendar-prev');
        var nextBtn = this.root.querySelector('.ke-calendar-next');

        if (prevBtn) {
            prevBtn.addEventListener('click', function () { self.shiftMonth(-1); });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () { self.shiftMonth(1); });
        }
        if (this.todayBtn) {
            this.todayBtn.addEventListener('click', function () { self.goToToday(); });
        }

        // Delegated click / keyboard handler on the grid.
        this.gridEl.addEventListener('click', function (e) {
            var cell = e.target.closest('.ke-calendar-cell');
            if (!cell || !cell.classList.contains('is-current-month')) return;
            self.handleCellActivate(cell);
        });

        this.gridEl.addEventListener('keydown', function (e) {
            var cell = document.activeElement;
            if (!cell || !cell.classList.contains('ke-calendar-cell')) return;

            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (cell.classList.contains('is-current-month')) {
                    self.handleCellActivate(cell);
                }
                return;
            }

            var delta = 0;
            switch (e.key) {
                case 'ArrowLeft':  delta = -1; break;
                case 'ArrowRight': delta = 1;  break;
                case 'ArrowUp':    delta = -7; break;
                case 'ArrowDown':  delta = 7;  break;
                default: return;
            }
            e.preventDefault();
            self.moveFocus(cell, delta);
        });
    };

    Calendar.prototype.moveFocus = function (currentCell, delta) {
        var cells = Array.prototype.slice.call(
            this.gridEl.querySelectorAll('.ke-calendar-cell')
        );
        var idx = cells.indexOf(currentCell);
        if (idx === -1) return;
        var nextIdx = idx + delta;
        // Skip over other-month cells when navigating with arrows.
        while (nextIdx >= 0 && nextIdx < cells.length &&
               !cells[nextIdx].classList.contains('is-current-month')) {
            nextIdx += (delta > 0 ? 1 : -1);
        }
        if (nextIdx < 0 || nextIdx >= cells.length) return;
        cells[nextIdx].focus();
    };

    Calendar.prototype.handleCellActivate = function (cell) {
        var y = parseInt(cell.dataset.y, 10);
        var m0 = parseInt(cell.dataset.m, 10);
        var d = parseInt(cell.dataset.d, 10);
        var ymd = { y: y, m0: m0, d: d };

        // Toggle: re-clicking the same day collapses.
        if (this.selected && isSameYMD(this.selected, ymd)) {
            this.selected = null;
        } else {
            this.selected = ymd;
        }
        this.renderSelection();
        this.renderPanel();
    };

    Calendar.prototype.shiftMonth = function (delta) {
        var m = this.monthIndex + delta;
        var y = this.year;
        while (m < 0)  { m += 12; y--; }
        while (m > 11) { m -= 12; y++; }
        this.year = y;
        this.monthIndex = m;
        this.selected = null;
        this.render();
    };

    Calendar.prototype.goToToday = function () {
        var t = todayYMD();
        this.year = t.y;
        this.monthIndex = t.m0;
        this.selected = null;
        this.render(function () {
            // Auto-select today only if it has events on it.
            var cell = this.gridEl.querySelector('.ke-calendar-cell.is-today');
            if (cell && cell.classList.contains('has-events')) {
                this.selected = t;
                this.renderSelection();
                this.renderPanel();
            }
        }.bind(this));
    };

    Calendar.prototype.updateTodayButtonState = function () {
        if (!this.todayBtn) return;
        var t = todayYMD();
        var away = (this.year !== t.y || this.monthIndex !== t.m0);
        this.todayBtn.classList.toggle('is-away', away);
    };

    Calendar.prototype.updateMonthLabel = function () {
        if (!this.monthLabelEl) return;
        this.monthLabelEl.textContent =
            MONTH_NAMES[this.monthIndex] + ' ' + this.year;
    };

    Calendar.prototype.render = function (afterFetch) {
        this.updateMonthLabel();
        this.updateTodayButtonState();

        var key = ymKey(this.year, this.monthIndex);
        var self = this;

        if (this.cache[key]) {
            this.buildGrid(this.cache[key]);
            this.renderPanel();
            if (typeof afterFetch === 'function') afterFetch();
            return;
        }

        // Show grid skeleton first so layout doesn't jump while we fetch.
        this.buildGrid([]);
        this.root.classList.add('is-loading');

        this.fetchMonth(this.year, this.monthIndex).then(function (events) {
            self.cache[key] = events;
            // Only render if user hasn't navigated away while we fetched.
            if (ymKey(self.year, self.monthIndex) === key) {
                self.buildGrid(events);
                self.renderPanel();
                if (typeof afterFetch === 'function') afterFetch();
            }
        }).catch(function () {
            // Network errors leave the empty grid — better than a stale view.
            self.cache[key] = [];
        }).then(function () {
            self.root.classList.remove('is-loading');
        });
    };

    Calendar.prototype.fetchMonth = function (year, monthIndex) {
        var from = isoDate(year, monthIndex, 1);
        var lastDay = new Date(year, monthIndex + 1, 0).getDate();
        var to = isoDate(year, monthIndex, lastDay);

        var sep = this.restUrl.indexOf('?') === -1 ? '?' : '&';
        var url = this.restUrl + sep
            + 'category=' + encodeURIComponent(this.category)
            + '&from=' + encodeURIComponent(from)
            + '&to=' + encodeURIComponent(to);

        return fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (res) {
            if (!res.ok) return [];
            return res.json();
        }).then(function (data) {
            if (data && Array.isArray(data.events)) return data.events;
            return [];
        });
    };

    Calendar.prototype.buildGrid = function (events) {
        // Group events by local YYYY-MM-DD of start_datetime.
        var byDay = {};
        for (var i = 0; i < events.length; i++) {
            var ev = events[i];
            var d = parseDateOnly(ev.start_datetime);
            if (!d) continue;
            var key = isoDate(d.y, d.m0, d.d);
            if (!byDay[key]) byDay[key] = [];
            byDay[key].push(ev);
        }

        var year = this.year;
        var m0 = this.monthIndex;
        var firstWeekday = new Date(year, m0, 1).getDay();
        var leading = (firstWeekday - this.weekStart + 7) % 7;
        var daysInMonth = new Date(year, m0 + 1, 0).getDate();

        // Render 6 weeks (42 cells) for a stable grid height.
        var totalCells = 42;
        var html = [];
        var today = todayYMD();
        var nowTs = new Date();
        nowTs.setHours(0, 0, 0, 0);

        for (var c = 0; c < totalCells; c++) {
            var dayNum = c - leading + 1;
            var cellYear = year;
            var cellMonth = m0;
            var cellDay = dayNum;
            var isOther = false;

            if (dayNum < 1) {
                // Previous month tail
                var prev = new Date(year, m0, 0); // last day of prev month
                cellYear = prev.getFullYear();
                cellMonth = prev.getMonth();
                cellDay = prev.getDate() + dayNum; // dayNum is <=0
                isOther = true;
            } else if (dayNum > daysInMonth) {
                cellYear = m0 === 11 ? year + 1 : year;
                cellMonth = (m0 + 1) % 12;
                cellDay = dayNum - daysInMonth;
                isOther = true;
            }

            var cellKey = isoDate(cellYear, cellMonth, cellDay);
            var dayEvents = !isOther && byDay[cellKey] ? byDay[cellKey] : [];

            var isToday = (cellYear === today.y && cellMonth === today.m0 && cellDay === today.d);
            var cellDate = new Date(cellYear, cellMonth, cellDay);
            cellDate.setHours(0, 0, 0, 0);
            var isPast = cellDate < nowTs;

            var classes = ['ke-calendar-cell'];
            classes.push(isOther ? 'is-other-month' : 'is-current-month');
            if (isToday) classes.push('is-today');
            if (isPast && !isOther) classes.push('is-past');
            if (!isOther && dayEvents.length) classes.push('has-events');

            var attrs = '';
            if (!isOther) {
                attrs += ' tabindex="0"' +
                    ' data-y="' + cellYear + '"' +
                    ' data-m="' + cellMonth + '"' +
                    ' data-d="' + cellDay + '"';
                if (dayEvents.length) {
                    attrs += ' aria-label="' + escapeHtml(
                        dayEvents.length + ' event' + (dayEvents.length > 1 ? 's' : '') +
                        ' on ' + MONTH_NAMES[cellMonth] + ' ' + cellDay
                    ) + '"';
                } else {
                    attrs += ' aria-label="' + escapeHtml(
                        MONTH_NAMES[cellMonth] + ' ' + cellDay
                    ) + '"';
                }
            } else {
                attrs += ' aria-hidden="true"';
            }

            html.push(
                '<div class="' + classes.join(' ') + '" role="gridcell"' + attrs + '>' +
                    '<span class="ke-calendar-daynum">' + cellDay + '</span>' +
                    renderChips(dayEvents) +
                    renderDots(dayEvents) +
                '</div>'
            );
        }

        this.gridEl.innerHTML = html.join('');

        var totalEvents = events.length;
        if (this.emptyEl) {
            this.emptyEl.hidden = totalEvents !== 0;
        }

        // Re-apply selection styling if a selected day is in this month.
        this.renderSelection();
    };

    function renderChipThumb(ev) {
        if (ev.banner_url) {
            return '<img class="ke-calendar-chip-thumb" src="' +
                escapeAttr(ev.banner_url) + '" alt="" loading="lazy">';
        }
        return '<span class="ke-calendar-chip-thumb ke-calendar-chip-thumb--fallback" aria-hidden="true"></span>';
    }

    function renderChips(dayEvents) {
        if (!dayEvents.length) return '<div class="ke-calendar-chips"></div>';
        var max = 2;
        var html = ['<div class="ke-calendar-chips">'];
        for (var i = 0; i < Math.min(dayEvents.length, max); i++) {
            var ev = dayEvents[i];
            html.push(
                '<span class="ke-calendar-chip">' +
                    renderChipThumb(ev) +
                    '<span class="ke-calendar-chip-title">' +
                    escapeHtml(ev.title || '') +
                    '</span>' +
                '</span>'
            );
        }
        if (dayEvents.length > max) {
            html.push(
                '<span class="ke-calendar-chip-more">+ ' +
                (dayEvents.length - max) + ' more</span>'
            );
        }
        html.push('</div>');
        return html.join('');
    }

    function renderDots(dayEvents) {
        if (!dayEvents.length) return '<div class="ke-calendar-dots"></div>';
        var max = 3;
        var html = ['<div class="ke-calendar-dots" aria-hidden="true">'];
        for (var i = 0; i < Math.min(dayEvents.length, max); i++) {
            var ev = dayEvents[i];
            if (ev.banner_url) {
                html.push(
                    '<span class="ke-calendar-dot ke-calendar-dot--thumb">' +
                        '<img src="' + escapeAttr(ev.banner_url) + '" alt="" loading="lazy">' +
                    '</span>'
                );
            } else {
                html.push('<span class="ke-calendar-dot"></span>');
            }
        }
        if (dayEvents.length > max) {
            html.push('<span class="ke-calendar-dot-more">+' + (dayEvents.length - max) + '</span>');
        }
        html.push('</div>');
        return html.join('');
    }

    Calendar.prototype.renderSelection = function () {
        var cells = this.gridEl.querySelectorAll('.ke-calendar-cell');
        for (var i = 0; i < cells.length; i++) {
            cells[i].classList.remove('is-selected');
        }
        if (!this.selected) return;
        var sel = this.selected;
        for (var j = 0; j < cells.length; j++) {
            var c = cells[j];
            if (parseInt(c.dataset.y, 10) === sel.y &&
                parseInt(c.dataset.m, 10) === sel.m0 &&
                parseInt(c.dataset.d, 10) === sel.d) {
                c.classList.add('is-selected');
                break;
            }
        }
    };

    Calendar.prototype.renderPanel = function () {
        if (!this.panelEl) return;
        if (!this.selected) {
            this.panelEl.hidden = true;
            this.panelHeaderEl.textContent = '';
            this.panelEventsEl.innerHTML = '';
            return;
        }

        var key = ymKey(this.selected.y, this.selected.m0);
        var events = this.cache[key] || [];
        var ymdKey = isoDate(this.selected.y, this.selected.m0, this.selected.d);
        var dayEvents = events.filter(function (ev) {
            var d = parseDateOnly(ev.start_datetime);
            if (!d) return false;
            return isoDate(d.y, d.m0, d.d) === ymdKey;
        });

        if (!dayEvents.length) {
            this.panelEl.hidden = true;
            this.panelHeaderEl.textContent = '';
            this.panelEventsEl.innerHTML = '';
            return;
        }

        this.panelHeaderEl.textContent = formatLongDate(
            this.selected.y, this.selected.m0, this.selected.d
        );

        var html = [];
        for (var i = 0; i < dayEvents.length; i++) {
            var ev = dayEvents[i];
            var thumb = ev.banner_url
                ? '<div class="ke-calendar-event-thumb">' +
                    '<img src="' + escapeAttr(ev.banner_url) + '" alt="" loading="lazy"' +
                    ' style="width:100%;height:100%;object-fit:cover;display:block;">' +
                  '</div>'
                : '<div class="ke-calendar-event-thumb"><div class="ke-calendar-event-thumb-placeholder">📅</div></div>';
            var timeText = formatTimeRange(ev.start_datetime, ev.end_datetime);
            var venue = ev.venue_name || '';
            var metaParts = [];
            if (timeText) metaParts.push(escapeHtml(timeText));
            if (venue) metaParts.push(escapeHtml(venue));

            html.push(
                '<div class="ke-calendar-event-card">' +
                    thumb +
                    '<div class="ke-calendar-event-info">' +
                        '<p class="ke-calendar-event-title">' + escapeHtml(ev.title || '') + '</p>' +
                        '<div class="ke-calendar-event-meta">' + metaParts.join(' · ') + '</div>' +
                    '</div>' +
                    '<a class="ke-calendar-event-view" href="' + escapeAttr(ev.permalink || '#') + '">View</a>' +
                '</div>'
            );
        }
        this.panelEventsEl.innerHTML = html.join('');
        this.panelEl.hidden = false;
    };

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escapeAttr(s) {
        return escapeHtml(s);
    }

    function init() {
        var nodes = document.querySelectorAll('.ke-calendar');
        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i].dataset.keCalInit === '1') continue;
            nodes[i].dataset.keCalInit = '1';
            new Calendar(nodes[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
