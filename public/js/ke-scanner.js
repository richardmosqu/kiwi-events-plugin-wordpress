/**
 * Kiwi Scanner — public client. Sequential 3-state flow with a persistent
 * MediaStream that is paused (not torn down) between scans, so "Scan another"
 * can never produce a black camera.
 *
 * Auth model: state 2 trades the organizer password for a session token via
 * POST /scanner/auth. The token is stashed in sessionStorage so a refresh
 * resumes directly to state 3 without re-prompting the gate.
 */
(function () {
    'use strict';

    const REST = (window.kePublicScanner && window.kePublicScanner.restUrl) || '/wp-json/ke/v1/';
    const TOKEN_STORAGE_KEY = 'ke_scanner_session';

    const STATES = { EVENT_SELECT: 1, PASSWORD: 2, SCANNING: 3 };

    // ─── Module state ──────────────────────────────────────────────
    let cameraStream    = null;     // MediaStream — initialized once
    let scanningPaused  = false;    // gate the QR detection loop
    let sessionToken    = null;     // active scanner session token
    let tokenExpiresAt  = 0;        // unix seconds; 0 = unknown
    let currentEventId  = 0;
    let currentEventMeta = null;    // { name, organizer, total, checked_in }
    let scanRafId       = 0;
    let video           = null;
    let canvas          = null;
    let ctx             = null;
    let lastScannedCode = '';       // de-dupe the same QR while it lingers in frame
    let resumeTimeoutId = 0;

    // ─── DOM lookup helper ─────────────────────────────────────────
    const $ = (id) => document.getElementById(id);

    // ─── State machine ─────────────────────────────────────────────
    function showState(n) {
        document.querySelectorAll('.ke-scanner-state').forEach((el) => {
            el.classList.remove('is-active');
            el.removeAttribute('hidden');
        });
        const target = $('ke-state-' + n);
        if (target) target.classList.add('is-active');
    }

    // ─── Camera lifecycle ─────────────────────────────────────────
    async function initCamera() {
        if (cameraStream) return cameraStream; // idempotent — never re-grab
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            });
        } catch (err) {
            showResult({
                kind: 'invalid',
                title: 'Camera blocked',
                detail: 'Allow camera access for this page and reload.',
            });
            throw err;
        }
        if (!video) video = $('ke-camera-video');
        if (video) {
            video.srcObject = cameraStream;
            try { await video.play(); } catch (_) { /* iOS sometimes resolves on user gesture */ }
        }
        if (!canvas) {
            canvas = document.createElement('canvas');
            ctx = canvas.getContext('2d', { willReadFrequently: true });
        }
        scheduleScan();
        return cameraStream;
    }

    function shutdownCamera() {
        if (scanRafId) {
            cancelAnimationFrame(scanRafId);
            scanRafId = 0;
        }
        if (cameraStream) {
            cameraStream.getTracks().forEach((t) => t.stop());
            cameraStream = null;
        }
        if (video) {
            try { video.srcObject = null; } catch (_) {}
        }
    }

    // ─── Scan loop ─────────────────────────────────────────────────
    function scheduleScan() {
        if (scanRafId) cancelAnimationFrame(scanRafId);
        scanRafId = requestAnimationFrame(scanTick);
    }

    function scanTick() {
        scanRafId = requestAnimationFrame(scanTick);
        if (scanningPaused) return;
        if (!video || video.readyState !== video.HAVE_ENOUGH_DATA) return;
        if (!window.jsQR) return;

        const w = video.videoWidth;
        const h = video.videoHeight;
        if (!w || !h) return;
        if (canvas.width !== w) canvas.width = w;
        if (canvas.height !== h) canvas.height = h;
        ctx.drawImage(video, 0, 0, w, h);
        const data = ctx.getImageData(0, 0, w, h);
        const code = window.jsQR(data.data, w, h, { inversionAttempts: 'dontInvert' });
        if (!code || !code.data) return;

        if (code.data === lastScannedCode) return; // de-dupe held frame
        lastScannedCode = code.data;

        scanningPaused = true;
        validateAndShow(code.data);
    }

    // ─── Validate + render ────────────────────────────────────────
    async function validateAndShow(rawCode) {
        const code = String(rawCode || '').trim();
        const match = code.match(/([a-f0-9]{8,})/i);
        const tokenCode = match ? match[1].toLowerCase() : code.toLowerCase();

        let resp, body;
        try {
            resp = await fetch(REST + 'tickets/validate/' + encodeURIComponent(tokenCode), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-KE-Scanner-Token': sessionToken || '',
                },
                body: JSON.stringify({}),
            });
            body = await resp.json().catch(() => ({}));
        } catch (err) {
            showResult({ kind: 'invalid', title: 'Network error', detail: 'Could not reach the server.' });
            scheduleResume(false);
            return;
        }

        if (resp.status === 401 && body && (body.code === 'invalid_token' || body.code === 'rest_forbidden')) {
            sessionExpired();
            return;
        }

        if (body && body.status === 'valid') {
            bumpCounter(+1);
            showResult({
                kind: 'valid',
                title: '✓ Valid',
                detail: (body.ticket && body.ticket.attendee_name) || 'Checked in',
                ticketType: body.ticket && body.ticket.ticket_type,
            });
            navigator.vibrate?.(50);
            scheduleResume(true);
            return;
        }
        if (body && body.status === 'already_used') {
            showResult({
                kind: 'used',
                title: '⚠ Already used',
                detail: (body.ticket && body.ticket.attendee_name) || 'This ticket was already scanned.',
                checkedAt: body.ticket && body.ticket.checked_in_at,
            });
            navigator.vibrate?.([100, 50, 100]);
            scheduleResume(false);
            return;
        }
        showResult({
            kind: 'invalid',
            title: '✗ Invalid',
            detail: (body && body.message) || 'Ticket not recognized for this event.',
        });
        navigator.vibrate?.([100, 50, 100]);
        scheduleResume(false);
    }

    function showResult(r) {
        const area = $('ke-result-area');
        if (!area) return;
        const kindClass = 'is-' + (r.kind || 'invalid');
        area.innerHTML = '';
        const card = document.createElement('div');
        card.className = 'ke-result-card ' + kindClass;
        card.innerHTML =
            '<div class="ke-result-title">' + esc(r.title || '') + '</div>' +
            (r.detail ? '<div class="ke-result-detail">' + esc(r.detail) + '</div>' : '') +
            (r.ticketType ? '<div class="ke-result-meta">' + esc(r.ticketType) + '</div>' : '') +
            (r.checkedAt ? '<div class="ke-result-meta">' + esc(r.checkedAt) + '</div>' : '');
        area.appendChild(card);

        const btn = $('ke-scan-another');
        if (btn) btn.hidden = (r.kind === 'valid'); // auto-resume hides; manual shows
    }

    function clearResult() {
        const area = $('ke-result-area');
        if (area) area.innerHTML = '';
        const btn = $('ke-scan-another');
        if (btn) btn.hidden = true;
        lastScannedCode = '';
    }

    function scheduleResume(autoResume) {
        if (resumeTimeoutId) {
            clearTimeout(resumeTimeoutId);
            resumeTimeoutId = 0;
        }
        if (autoResume) {
            resumeTimeoutId = window.setTimeout(() => {
                clearResult();
                scanningPaused = false;
            }, 2500);
        }
    }

    function onScanAnotherClick() {
        if (resumeTimeoutId) { clearTimeout(resumeTimeoutId); resumeTimeoutId = 0; }
        clearResult();
        scanningPaused = false;
        // CRITICAL: never touch cameraStream here.
    }

    // ─── Counter ──────────────────────────────────────────────────
    function setCounter(checked, total) {
        const c = $('ke-counter-checked');
        const t = $('ke-counter-total');
        if (c) c.textContent = String(checked);
        if (t) t.textContent = String(total);
    }
    function bumpCounter(delta) {
        const c = $('ke-counter-checked');
        if (!c) return;
        const next = (parseInt(c.textContent, 10) || 0) + delta;
        c.textContent = String(next);
        c.classList.remove('ke-counter-pulse');
        // Reflow to restart the CSS animation.
        // eslint-disable-next-line no-unused-expressions
        void c.offsetWidth;
        c.classList.add('ke-counter-pulse');
    }

    // ─── Auth ─────────────────────────────────────────────────────
    async function authenticate(eventId, password) {
        const resp = await fetch(REST + 'scanner/auth', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ event_id: eventId, password: password }),
        });
        const body = await resp.json().catch(() => ({}));
        if (!resp.ok || !body || !body.success) {
            const msg = (body && body.message) || 'Authentication failed.';
            const err = new Error(msg);
            err.code = body && body.code;
            err.status = resp.status;
            throw err;
        }
        return body;
    }

    function persistSession(payload) {
        sessionToken   = payload.token;
        tokenExpiresAt = payload.expires_at | 0;
        currentEventId = payload.event_id | 0;
        currentEventMeta = {
            name: payload.event_name || '',
            organizer: payload.organizer_name || '',
            total: payload.total_tickets | 0,
            checked_in: payload.checked_in | 0,
        };
        try {
            sessionStorage.setItem(TOKEN_STORAGE_KEY, JSON.stringify({
                token: sessionToken,
                expires_at: tokenExpiresAt,
                event_id: currentEventId,
                meta: currentEventMeta,
            }));
        } catch (_) {}
    }

    function clearSession() {
        sessionToken = null;
        tokenExpiresAt = 0;
        currentEventId = 0;
        currentEventMeta = null;
        try { sessionStorage.removeItem(TOKEN_STORAGE_KEY); } catch (_) {}
    }

    function sessionExpired() {
        clearSession();
        clearResult();
        scanningPaused = true;
        showState(STATES.PASSWORD);
        const err = $('ke-password-error');
        if (err) {
            err.textContent = 'Session expired. Re-enter the password to keep scanning.';
            err.hidden = false;
        }
    }

    // ─── Wire-up ──────────────────────────────────────────────────
    function bindEventSelect() {
        const sel  = $('ke-event-select');
        const cont = $('ke-event-continue');
        if (!sel || !cont) return;
        sel.addEventListener('change', () => {
            cont.disabled = !sel.value;
        });
        cont.addEventListener('click', () => {
            if (!sel.value) return;
            const opt = sel.options[sel.selectedIndex];
            const evtId    = parseInt(sel.value, 10) || 0;
            const evtName  = opt ? (opt.textContent || '').split(' · ')[0] : '';
            const orgName  = opt ? (opt.dataset.organizer || '') : '';
            currentEventId = evtId;
            const evNameEl  = $('ke-event-name');
            const orgNameEl = $('ke-organizer-name');
            if (evNameEl)  evNameEl.textContent  = evtName;
            if (orgNameEl) orgNameEl.textContent = orgName;
            const errEl = $('ke-password-error');
            if (errEl) { errEl.textContent = ''; errEl.hidden = true; }
            const pw = $('ke-password-input');
            if (pw) pw.value = '';
            showState(STATES.PASSWORD);
            setTimeout(() => { if (pw) pw.focus(); }, 50);
        });
    }

    function bindPasswordGate() {
        const back = $('ke-back-to-events');
        const sub  = $('ke-password-submit');
        const inp  = $('ke-password-input');
        const err  = $('ke-password-error');
        if (back) back.addEventListener('click', () => {
            if (err) { err.textContent = ''; err.hidden = true; }
            showState(STATES.EVENT_SELECT);
        });
        const trySubmit = async () => {
            const pwd = inp ? inp.value : '';
            if (!pwd) {
                if (err) { err.textContent = 'Enter the password.'; err.hidden = false; }
                return;
            }
            if (sub) { sub.disabled = true; sub.textContent = 'Unlocking…'; }
            try {
                const payload = await authenticate(currentEventId, pwd);
                persistSession(payload);
                if (err) { err.textContent = ''; err.hidden = true; }
                await enterScanningState();
            } catch (e) {
                if (err) {
                    err.textContent = e.message || 'Incorrect password.';
                    err.hidden = false;
                }
            } finally {
                if (sub) { sub.disabled = false; sub.textContent = 'Unlock scanner'; }
            }
        };
        if (sub) sub.addEventListener('click', trySubmit);
        if (inp) inp.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); trySubmit(); }
        });
    }

    function bindScanningState() {
        const sw = $('ke-switch-event');
        if (sw) sw.addEventListener('click', () => {
            shutdownCamera();
            clearSession();
            clearResult();
            scanningPaused = false;
            showState(STATES.EVENT_SELECT);
        });
        const sa = $('ke-scan-another');
        if (sa) sa.addEventListener('click', onScanAnotherClick);
    }

    async function enterScanningState() {
        showState(STATES.SCANNING);
        if (currentEventMeta) {
            setCounter(currentEventMeta.checked_in || 0, currentEventMeta.total || 0);
            const evNameEl = $('ke-event-name');
            const orgNameEl = $('ke-organizer-name');
            if (evNameEl)  evNameEl.textContent  = currentEventMeta.name || evNameEl.textContent;
            if (orgNameEl) orgNameEl.textContent = currentEventMeta.organizer || orgNameEl.textContent;
        }
        clearResult();
        scanningPaused = false;
        try { await initCamera(); } catch (_) { /* showResult already surfaced the error */ }
    }

    function tryResumeFromStorage() {
        let saved = null;
        try { saved = JSON.parse(sessionStorage.getItem(TOKEN_STORAGE_KEY) || 'null'); } catch (_) {}
        if (!saved || !saved.token) return false;
        const now = Math.floor(Date.now() / 1000);
        if (saved.expires_at && saved.expires_at <= now + 30) return false; // expired-ish
        sessionToken     = saved.token;
        tokenExpiresAt   = saved.expires_at || 0;
        currentEventId   = saved.event_id || 0;
        currentEventMeta = saved.meta || null;
        return true;
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    document.addEventListener('DOMContentLoaded', () => {
        bindEventSelect();
        bindPasswordGate();
        bindScanningState();

        if (tryResumeFromStorage()) {
            enterScanningState();
        } else {
            showState(STATES.EVENT_SELECT);
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            scanningPaused = true;
        } else {
            const area = $('ke-result-area');
            const hasResult = area && area.children.length > 0;
            if (cameraStream && !hasResult) scanningPaused = false;
        }
    });

    window.addEventListener('beforeunload', shutdownCamera);
})();
