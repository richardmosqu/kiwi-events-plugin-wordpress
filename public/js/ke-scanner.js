/**
 * Kiwi Scanner — public client. Sequential 3-state flow with a persistent
 * MediaStream that is paused (not torn down) between scans.
 *
 * Auth model: state 2 trades the organizer password for a 4h session token
 * via POST /scanner/auth. The token is stashed in sessionStorage so a
 * refresh resumes directly to state 3.
 */
(function () {
    'use strict';

    const REST = (window.kePublicScanner && window.kePublicScanner.restUrl) || '/wp-json/ke/v1/';
    const TOKEN_STORAGE_KEY = 'ke_scanner_session';
    const MUTE_STORAGE_KEY  = 'ke_scanner_muted';

    const STATES = { EVENT_SELECT: 1, PASSWORD: 2, SCANNING: 3 };

    // ─── Module state ──────────────────────────────────────────────
    let cameraStream    = null;     // MediaStream — initialized once, never torn between scans
    let scanningPaused  = false;    // gate the QR detection loop
    let sessionToken    = null;
    let tokenExpiresAt  = 0;
    let currentEventId  = 0;
    let currentEventMeta = null;    // { name, organizer, total, checked_in }
    let scanRafId       = 0;
    let video           = null;
    let canvas          = null;
    let ctx             = null;
    let lastScannedCode = '';
    let resumeTimeoutId = 0;
    let audioCtx        = null;
    let muted           = false;

    // ─── DOM helper ────────────────────────────────────────────────
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

    function setPausedClass(paused) {
        document.body.classList.toggle('is-paused', !!paused);
    }

    // ─── Camera lifecycle ─────────────────────────────────────────
    async function initCamera() {
        const loading = $('ke-camera-loading');
        if (loading) loading.classList.remove('is-hidden');

        if (cameraStream) {
            // Stream already live — just make sure the loading veil is hidden.
            if (loading) loading.classList.add('is-hidden');
            return cameraStream;
        }
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            });
        } catch (err) {
            if (loading) loading.classList.add('is-hidden');
            showResult({
                kind: 'invalid',
                status: 'Camera blocked',
                name: 'Allow camera access in Safari settings, then reload.',
            });
            throw err;
        }
        if (!video) video = $('ke-camera-video');
        if (video) {
            video.srcObject = cameraStream;
            try {
                await video.play();
            } catch (_) { /* iOS sometimes resolves on user gesture */ }
        }
        if (!canvas) {
            canvas = document.createElement('canvas');
            ctx = canvas.getContext('2d', { willReadFrequently: true });
        }
        if (loading) loading.classList.add('is-hidden');
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
        if (code.data === lastScannedCode) return;
        lastScannedCode = code.data;

        scanningPaused = true;
        setPausedClass(true);
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
            showResult({ kind: 'invalid', status: 'Network error', name: 'Could not reach the server.' });
            feedbackInvalid();
            scheduleResume(false);
            return;
        }

        if (resp.status === 401 && body && (body.code === 'invalid_token' || body.code === 'rest_forbidden')) {
            sessionExpired();
            return;
        }

        const ticket = (body && body.ticket) || {};
        if (body && body.status === 'valid') {
            bumpCounter(+1);
            showResult({
                kind: 'valid',
                status: 'Valid',
                name: ticket.attendee_name || 'Checked in',
                meta: buildMeta(ticket),
            });
            feedbackValid();
            scheduleResume(true);
            return;
        }
        if (body && body.status === 'already_used') {
            showResult({
                kind: 'used',
                status: 'Already used',
                name: ticket.attendee_name || 'Ticket already scanned',
                meta: buildMeta(ticket),
                timestamp: ticket.checked_in_at ? formatTimestamp(ticket.checked_in_at) : '',
            });
            feedbackUsed();
            scheduleResume(false);
            return;
        }
        showResult({
            kind: 'invalid',
            status: 'Invalid',
            name: (body && body.message) || 'Ticket not recognized.',
            meta: '',
        });
        feedbackInvalid();
        scheduleResume(false);
    }

    function buildMeta(ticket) {
        const parts = [];
        if (ticket.ticket_type) parts.push(ticket.ticket_type);
        if (ticket.code) parts.push('#' + String(ticket.code).slice(0, 8).toUpperCase());
        return parts.join(' · ');
    }

    function formatTimestamp(mysqlDt) {
        // Best-effort, locale-aware time. mysqlDt is "YYYY-MM-DD HH:MM:SS" in site TZ.
        try {
            const iso = String(mysqlDt).replace(' ', 'T');
            const d = new Date(iso);
            if (isNaN(d.getTime())) return mysqlDt;
            return 'Checked in at ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        } catch (_) {
            return String(mysqlDt);
        }
    }

    function showResult(r) {
        const area = $('ke-result-area');
        if (!area) return;
        const kind = r.kind || 'invalid';
        const iconSvg = {
            valid:   '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            used:    '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>',
            invalid: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        }[kind];

        area.innerHTML =
            '<div class="ke-result-card ke-result-card--' + kind + '">' +
                '<div class="ke-result-icon" aria-hidden="true">' + iconSvg + '</div>' +
                '<div class="ke-result-body">' +
                    '<div class="ke-result-status">' + esc(r.status || '') + '</div>' +
                    '<div class="ke-result-name">' + esc(r.name || '') + '</div>' +
                    (r.meta ? '<div class="ke-result-meta">' + esc(r.meta) + '</div>' : '') +
                    (r.timestamp ? '<div class="ke-result-timestamp">' + esc(r.timestamp) + '</div>' : '') +
                '</div>' +
            '</div>';

        const btn = $('ke-scan-another');
        if (btn) {
            const labelEl = btn.querySelector('.ke-btn-label');
            if (kind === 'valid') {
                btn.hidden = true; // auto-resume; no manual button needed
            } else {
                btn.hidden = false;
                btn.classList.add('ke-btn-retry');
                if (labelEl) labelEl.textContent = 'Try Again';
            }
        }
    }

    function clearResult() {
        const area = $('ke-result-area');
        if (area) {
            area.innerHTML =
                '<div class="ke-result-empty">' +
                    '<div class="ke-result-empty-icon" aria-hidden="true">' +
                        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                            '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>' +
                            '<line x1="14" y1="14" x2="14.01" y2="14"/><line x1="20" y1="14" x2="20.01" y2="14"/>' +
                            '<line x1="14" y1="20" x2="14.01" y2="20"/><line x1="20" y1="20" x2="20.01" y2="20"/>' +
                            '<line x1="17" y1="17" x2="17.01" y2="17"/>' +
                        '</svg>' +
                    '</div>' +
                    '<div class="ke-result-empty-text">Point camera at QR code</div>' +
                '</div>';
        }
        const btn = $('ke-scan-another');
        if (btn) {
            btn.hidden = true;
            btn.classList.remove('ke-btn-retry');
            const labelEl = btn.querySelector('.ke-btn-label');
            if (labelEl) labelEl.textContent = 'Scan Another';
        }
        lastScannedCode = '';
        setPausedClass(false);
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
        c.classList.remove('is-pulsing');
        // Restart the animation with a reflow.
        // eslint-disable-next-line no-unused-expressions
        void c.offsetWidth;
        c.classList.add('is-pulsing');
    }

    // ─── Feedback (haptic + audio) ────────────────────────────────
    function ensureAudio() {
        if (audioCtx) return audioCtx;
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) return null;
        try { audioCtx = new Ctor(); } catch (_) { audioCtx = null; }
        return audioCtx;
    }
    function playBeep(freq, duration) {
        if (muted) return;
        const ac = ensureAudio();
        if (!ac) return;
        try { if (ac.state === 'suspended') ac.resume(); } catch (_) {}
        const osc  = ac.createOscillator();
        const gain = ac.createGain();
        osc.connect(gain).connect(ac.destination);
        osc.frequency.value = freq;
        osc.type = 'sine';
        const now = ac.currentTime;
        gain.gain.setValueAtTime(0.15, now);
        gain.gain.exponentialRampToValueAtTime(0.0008, now + duration / 1000);
        osc.start(now);
        osc.stop(now + duration / 1000);
    }
    function feedbackValid()   { navigator.vibrate?.(40);              playBeep(880, 80); }
    function feedbackUsed()    { navigator.vibrate?.([60, 40, 60]);    playBeep(440, 120); }
    function feedbackInvalid() { navigator.vibrate?.([100, 50, 100, 50, 100]); playBeep(220, 200); }

    function applyMuteUI() {
        const btn = $('ke-mute-toggle');
        if (btn) {
            btn.classList.toggle('is-muted', muted);
            btn.setAttribute('aria-pressed', String(muted));
        }
    }
    function loadMute() {
        try { muted = sessionStorage.getItem(MUTE_STORAGE_KEY) === '1'; } catch (_) { muted = false; }
        applyMuteUI();
    }
    function toggleMute() {
        muted = !muted;
        try { sessionStorage.setItem(MUTE_STORAGE_KEY, muted ? '1' : '0'); } catch (_) {}
        applyMuteUI();
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
        setPausedClass(true);
        showState(STATES.PASSWORD);
        const err = $('ke-password-error');
        if (err) err.textContent = 'Session expired. Re-enter the password to keep scanning.';
    }

    // ─── Wire-up ──────────────────────────────────────────────────
    function bindEventList() {
        document.querySelectorAll('.ke-event-option').forEach((btn) => {
            btn.addEventListener('click', () => {
                const evtId    = parseInt(btn.getAttribute('data-event-id'), 10) || 0;
                const evtName  = btn.getAttribute('data-event-name') || '';
                const orgName  = btn.getAttribute('data-organizer') || '';
                const dateLbl  = btn.getAttribute('data-date-label') || '';
                currentEventId = evtId;
                const evNameEl  = $('ke-event-name-display');
                const orgNameEl = $('ke-organizer-name-display');
                if (evNameEl)  evNameEl.textContent  = evtName;
                if (orgNameEl) orgNameEl.textContent = orgName || dateLbl || '—';
                const errEl = $('ke-password-error');
                if (errEl) errEl.textContent = '';
                const pw = $('ke-password-input');
                if (pw) pw.value = '';
                showState(STATES.PASSWORD);
                setTimeout(() => { if (pw) pw.focus(); }, 80);
            });
        });
    }

    function bindPasswordGate() {
        const back = $('ke-back-to-events');
        const sub  = $('ke-password-submit');
        const inp  = $('ke-password-input');
        const err  = $('ke-password-error');
        if (back) back.addEventListener('click', () => {
            if (err) err.textContent = '';
            showState(STATES.EVENT_SELECT);
        });
        const trySubmit = async () => {
            const pwd = inp ? inp.value : '';
            if (!pwd) {
                if (err) err.textContent = 'Enter the password.';
                return;
            }
            if (sub) { sub.disabled = true; sub.querySelector('.ke-btn-label, span')?.replaceChildren(document.createTextNode('Unlocking…')); }
            try {
                const payload = await authenticate(currentEventId, pwd);
                persistSession(payload);
                if (err) err.textContent = '';
                await enterScanningState();
            } catch (e) {
                if (err) err.textContent = e.message || 'Incorrect password.';
            } finally {
                if (sub) {
                    sub.disabled = false;
                    const label = sub.querySelector('.ke-btn-label, span');
                    if (label) label.textContent = 'Unlock Scanner';
                    else sub.textContent = 'Unlock Scanner';
                }
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
            setPausedClass(false);
            showState(STATES.EVENT_SELECT);
        });
        const sa = $('ke-scan-another');
        if (sa) sa.addEventListener('click', onScanAnotherClick);
        const mute = $('ke-mute-toggle');
        if (mute) mute.addEventListener('click', toggleMute);
    }

    async function enterScanningState() {
        showState(STATES.SCANNING);
        if (currentEventMeta) {
            setCounter(currentEventMeta.checked_in || 0, currentEventMeta.total || 0);
            const evNameEl  = $('ke-event-name');
            const orgNameEl = $('ke-organizer-name');
            if (evNameEl)  evNameEl.textContent  = currentEventMeta.name || '';
            if (orgNameEl) orgNameEl.textContent = currentEventMeta.organizer || '';
        }
        clearResult();
        scanningPaused = false;
        setPausedClass(false);
        try { await initCamera(); } catch (_) { /* showResult already surfaced the error */ }
    }

    function tryResumeFromStorage() {
        let saved = null;
        try { saved = JSON.parse(sessionStorage.getItem(TOKEN_STORAGE_KEY) || 'null'); } catch (_) {}
        if (!saved || !saved.token) return false;
        const now = Math.floor(Date.now() / 1000);
        if (saved.expires_at && saved.expires_at <= now + 30) return false;
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
        loadMute();
        bindEventList();
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
            setPausedClass(true);
        } else {
            const area = $('ke-result-area');
            const hasResultCard = area && area.querySelector('.ke-result-card');
            if (cameraStream && !hasResultCard) {
                scanningPaused = false;
                setPausedClass(false);
            }
        }
    });

    window.addEventListener('beforeunload', shutdownCamera);
})();
