/**
 * KiwiEvents — audience analytics beacon for the public event page.
 *
 * Counts, per event and per site-local day, with no visitor identity:
 *   view            one per browser session per event (page visit)
 *   ticket_click    a ticket type was tapped (checkout sheet opened)
 *   reserve_click   "Reserve" was tapped
 *   birthday_click  the Cumpleaños button was tapped
 *   share_click     a share / social pill was tapped
 *
 * The counting happens here, not in PHP, because the event page HTML is
 * edge-cached for anonymous visitors: a server-side counter would only see
 * cache misses. Staff renders never load this file (see
 * KE_Event_Analytics::should_track_request), and automated browsers
 * (navigator.webdriver) are ignored. Every call is fire-and-forget and can
 * never break the page.
 */
(function () {
    'use strict';

    var cfg = window.kePublicAnalytics;
    if (!cfg || !cfg.eventId || !cfg.endpoint) return;
    try { if (navigator.webdriver) return; } catch (_) {}

    var eventId  = parseInt(cfg.eventId, 10) || 0;
    var endpoint = String(cfg.endpoint);
    var lastSent = {};

    function send(metric) {
        var now = Date.now();
        // A double-tap is one intent, not two clicks.
        if (lastSent[metric] && now - lastSent[metric] < 1500) return;
        lastSent[metric] = now;
        var body = JSON.stringify({ event_id: eventId, metric: metric });
        try {
            if (window.fetch) {
                // keepalive lets the request finish even when the tap
                // navigates away (share links, checkout redirects).
                fetch(endpoint, {
                    method: 'POST',
                    keepalive: true,
                    credentials: 'omit',
                    headers: { 'Content-Type': 'application/json' },
                    body: body
                }).catch(function () {});
                return;
            }
            if (navigator.sendBeacon) {
                navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
            }
        } catch (_) {}
    }

    // One visit per browser session per event, so a refresh isn't a new visitor.
    function trackView() {
        var key = 'ke_an_view_' + eventId;
        try {
            if (window.sessionStorage && sessionStorage.getItem(key)) return;
            if (window.sessionStorage) sessionStorage.setItem(key, '1');
        } catch (_) {}
        send('view');
    }

    function metricFor(target) {
        if (!target || !target.closest) return null;
        if (target.closest('.ke-ticket:not([disabled])')) return 'ticket_click';
        if (target.closest('#ke-resv-open-btn'))          return 'reserve_click';
        if (target.closest('.ke-bday-card-link'))         return 'birthday_click';
        if (target.closest('.ke-share-pill, .ke-social-pill')) return 'share_click';
        return null;
    }

    // Capture phase: runs before the sheet/link handlers, so the beacon is
    // queued even when the click opens a new tab.
    document.addEventListener('click', function (e) {
        var m = metricFor(e.target);
        if (m) send(m);
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', trackView);
    } else {
        trackView();
    }
})();
