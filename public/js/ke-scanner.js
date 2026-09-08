/**
 * Kiwi Scanner — public client. Sequential 3-state flow with a persistent
 * MediaStream that is paused (not torn down) between scans.
 *
 * Auth model: state 2 trades the organizer password for a 4h session token
 * via POST /scanner/auth. The token is stashed in localStorage (sessionStorage
 * before 0.11) so a refresh — or Safari purging the tab after a long spell in
 * the background — resumes straight into state 3.
 *
 * Counter model (0.11): the "12 / 80" in the top bar is ALWAYS the server's
 * number. Every validate response carries fresh stats, the client polls
 * /events/{id}/checkin-stats while scanning, and it re-syncs the moment the
 * tab returns to the foreground. The client never adds 1 to a local tally —
 * that tally is what reset on every refresh, and why no two phones agreed.
 *
 * Camera model (0.11): iOS ends the camera track when the phone sits in the
 * background or the screen locks. The old client kept the dead stream and
 * showed a frozen frame ("it won't scan"); staff refreshed, and lost the
 * count. Tracks are now watched and re-acquired automatically.
 */
(function () {
    'use strict';

    const REST = (window.kePublicScanner && window.kePublicScanner.restUrl) || '/wp-json/ke/v1/';
    const TOKEN_STORAGE_KEY = 'ke_scanner_session';
    const MUTE_STORAGE_KEY  = 'ke_scanner_muted';

    const STATES = { EVENT_SELECT: 1, PASSWORD: 2, SCANNING: 3 };

    // Server sync cadence. 15s keeps several phones within one round of
    // each other without hammering the API (one COUNT query per poll).
    const STATS_POLL_MS    = 15000;
    // Older than this without a successful sync → counter shows "stale".
    const STATS_STALE_MS   = 45000;
    // Decode cadence + frame size. jsQR on a full 1080p frame every rAF is
    // what made mid-range phones stutter; a ≤800px frame every ~90ms decodes
    // a phone-screen QR reliably and leaves the CPU alone.
    const SCAN_INTERVAL_MS = 90;
    const SCAN_MAX_DIM     = 800;
    // How long a "valid" card stays up before the camera resumes on its own.
    const RESUME_DELAY_MS  = 2200;
    // Automatic retries for transient failures (rate limit, network blip).
    const RETRY_DELAY_MS   = 1400;
    const MAX_AUTO_RETRIES = 2;

    // ─── Module state ──────────────────────────────────────────────
    let cameraStream     = null;    // MediaStream — kept alive between scans
    let scanningPaused   = false;   // gates the QR detection loop
    let sessionToken     = null;
    let tokenExpiresAt   = 0;
    let currentEventId   = 0;
    let currentEventMeta = null;    // { name, organizer }
    let stats            = null;    // { checked_in, total, at }  — server truth
    let statsTimer       = 0;
    let statsInFlight    = false;
    let scanRafId        = 0;
    let lastDecodeTs     = 0;
    let decodeCount      = 0;
    let video            = null;
    let canvas           = null;
    let ctx              = null;
    let lastScannedCode  = '';
    let resumeTimeoutId  = 0;
    let retryTimeoutId   = 0;
    let audioCtx         = null;
    let muted            = false;

    // ─── DOM helper ────────────────────────────────────────────────
    const $ = (id) => document.getElementById(id);

    // ─── Storage (localStorage first, sessionStorage as fallback) ─
    function storageGet(key) {
        try { const v = window.localStorage.getItem(key); if (v != null) return v; } catch (_) {}
        try { return window.sessionStorage.getItem(key); } catch (_) {}
        return null;
    }
    function storageSet(key, value) {
        let ok = false;
        try { window.localStorage.setItem(key, value); ok = true; } catch (_) {}
        if (!ok) { try { window.sessionStorage.setItem(key, value); } catch (_) {} }
    }
    function storageRemove(key) {
        try { window.localStorage.removeItem(key); } catch (_) {}
        try { window.sessionStorage.removeItem(key); } catch (_) {}
    }

    // ─── State machine ─────────────────────────────────────────────
    function showState(n) {
        document.querySelectorAll('.ke-scanner-state').forEach((el) => {
            el.classList.remove('is-active');
            el.removeAttribute('hidden');
        });
        const target = $('ke-state-' + n);
        if (target) target.classList.add('is-active');
        if (n === STATES.SCANNING) startStatsPolling();
        else stopStatsPolling();
    }

    function isScanningStateActive() {
        const el = $('ke-state-3');
        return !!(el && el.classList.contains('is-active'));
    }

    function setPausedClass(paused) {
        document.body.classList.toggle('is-paused', !!paused);
    }

    // ─── Camera lifecycle ─────────────────────────────────────────
    function cameraIsLive() {
        if (!cameraStream) return false;
        const tracks = cameraStream.getVideoTracks();
        if (!tracks.length) return false;
        return tracks.every((t) => t.readyState === 'live');
    }

    function attachTrackWatchers(stream) {
        stream.getVideoTracks().forEach((track) => {
            track.addEventListener('ended', () => {
                // iOS kills the track on background/lock. Forget it so the
                // next initCamera() asks for a fresh one, and do that right
                // away if the scanner is on screen.
                if (cameraStream !== stream) return;
                cameraStream = null;
                if (isScanningStateActive() && !document.hidden) {
                    initCamera().catch(() => {});
                }
            });
        });
    }

    async function initCamera() {
        const loading = $('ke-camera-loading');
        if (!video) video = $('ke-camera-video');

        if (cameraIsLive()) {
            if (loading) loading.classList.add('is-hidden');
            if (video && video.paused) { try { await video.play(); } catch (_) {} }
            scheduleScan();
            return cameraStream;
        }

        // A stream whose tracks have ended is garbage — release it first.
        if (cameraStream) {
            try { cameraStream.getTracks().forEach((t) => t.stop()); } catch (_) {}
            cameraStream = null;
        }

        if (loading) loading.classList.remove('is-hidden');
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                    width:  { ideal: 1280 },
                    height: { ideal: 720 },
                },
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
        attachTrackWatchers(cameraStream);
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

    // Called whenever the page comes back to the foreground: make sure the
    // camera we have is actually producing frames, otherwise re-acquire it.
    function ensureCameraAlive() {
        if (!isScanningStateActive()) return;
        if (!cameraIsLive()) {
            initCamera().catch(() => {});
            return;
        }
        if (video && video.paused) {
            video.play().catch(() => {});
        }
        scheduleScan();
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

    function scanTick(ts) {
        scanRafId = requestAnimationFrame(scanTick);
        if (scanningPaused) return;
        if (!video || video.readyState !== video.HAVE_ENOUGH_DATA) return;
        if (!window.jsQR || !ctx) return;
        if (ts - lastDecodeTs < SCAN_INTERVAL_MS) return;
        lastDecodeTs = ts;

        const vw = video.videoWidth;
        const vh = video.videoHeight;
        if (!vw || !vh) return;
        const scale = Math.min(1, SCAN_MAX_DIM / Math.max(vw, vh));
        const w = Math.max(1, Math.round(vw * scale));
        const h = Math.max(1, Math.round(vh * scale));
        if (canvas.width !== w) canvas.width = w;
        if (canvas.height !== h) canvas.height = h;
        ctx.drawImage(video, 0, 0, w, h);
        const data = ctx.getImageData(0, 0, w, h);
        // Printed and on-screen QRs are dark-on-light; every third pass also
        // tries the inverted reading so a phone in "smart invert" or a
        // light-on-dark ticket design still scans.
        decodeCount++;
        const code = window.jsQR(data.data, w, h, {
            inversionAttempts: (decodeCount % 3 === 0) ? 'attemptBoth' : 'dontInvert',
        });
        if (!code || !code.data) return;
        if (code.data === lastScannedCode) return;
        lastScannedCode = code.data;

        scanningPaused = true;
        setPausedClass(true);
        validateAndShow(code.data, 0);
    }

    // ─── Validate + render ────────────────────────────────────────
    function extractCode(rawCode) {
        const code = String(rawCode || '').trim();
        const match = code.match(/([a-f0-9]{8,})/i);
        return match ? match[1].toLowerCase() : code.toLowerCase();
    }

    async function validateAndShow(rawCode, attempt) {
        const tokenCode = extractCode(rawCode);
        if (retryTimeoutId) { clearTimeout(retryTimeoutId); retryTimeoutId = 0; }

        let resp, body;
        try {
            resp = await fetch(REST + 'tickets/validate/' + encodeURIComponent(tokenCode), {
                method: 'POST',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    'X-KE-Scanner-Token': sessionToken || '',
                },
                body: JSON.stringify({}),
            });
            body = await resp.json().catch(() => ({}));
        } catch (err) {
            if (attempt < MAX_AUTO_RETRIES) {
                showResult({ kind: 'hold', status: 'Reconnecting', name: 'Network hiccup — retrying…' });
                retryTimeoutId = window.setTimeout(() => validateAndShow(rawCode, attempt + 1), RETRY_DELAY_MS);
                return;
            }
            showResult({ kind: 'invalid', status: 'Network error', name: 'Could not reach the server. Tap to try again.' });
            feedbackInvalid();
            markSync('stale');
            scheduleResume(false);
            return;
        }

        if (resp.status === 401 && body && (body.code === 'invalid_token' || body.code === 'rest_forbidden')) {
            sessionExpired();
            return;
        }

        // Rate limiter tripped (a burst from this phone). It clears within
        // the minute; retry quietly instead of calling a real ticket invalid.
        if (resp.status === 429 || (body && body.code === 'rate_limited')) {
            if (attempt < MAX_AUTO_RETRIES) {
                showResult({ kind: 'hold', status: 'One moment', name: 'Scanning very fast — retrying…' });
                retryTimeoutId = window.setTimeout(() => validateAndShow(rawCode, attempt + 1), RETRY_DELAY_MS);
                return;
            }
            showResult({ kind: 'invalid', status: 'Slow down', name: 'Too many scans in a minute. Wait a moment and scan again.' });
            feedbackInvalid();
            scheduleResume(false);
            return;
        }

        // The server's counter rides along on every answer.
        if (body && body.stats) applyStats(body.stats);

        const ticket = (body && body.ticket) || {};
        const status = body && body.status;

        if (status === 'valid') {
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
        if (status === 'already_used') {
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
        if (status === 'wrong_event') {
            showResult({
                kind: 'wrong',
                status: 'Wrong event',
                name: ticket.event_name ? ('Ticket for: ' + ticket.event_name) : 'Ticket for another event',
                meta: [ticket.attendee_name, buildMeta(ticket)].filter(Boolean).join(' · '),
            });
            feedbackInvalid();
            scheduleResume(false);
            return;
        }
        showResult({
            kind: 'invalid',
            status: status === 'error' ? 'Try again' : 'Invalid',
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

    const ICONS = {
        valid:   '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        used:    '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>',
        invalid: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        wrong:   '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 11-5.8-1.6"/></svg>',
        hold:    '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    };

    function showResult(r) {
        const area = $('ke-result-area');
        if (!area) return;
        const kind = r.kind || 'invalid';
        // "wrong" (other event) shares the red palette with "invalid".
        const cardClass = kind === 'wrong' ? 'ke-result-card--invalid ke-result-card--wrong' : 'ke-result-card--' + kind;

        area.innerHTML =
            '<div class="ke-result-card ' + cardClass + '">' +
                '<div class="ke-result-icon" aria-hidden="true">' + (ICONS[kind] || ICONS.invalid) + '</div>' +
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
            if (kind === 'valid' || kind === 'hold') {
                btn.hidden = true; // auto-resume / auto-retry; no manual button needed
            } else {
                btn.hidden = false;
                btn.classList.add('ke-btn-retry');
                if (labelEl) labelEl.textContent = 'Scan next';
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
            }, RESUME_DELAY_MS);
        }
    }

    function onScanAnotherClick() {
        if (resumeTimeoutId) { clearTimeout(resumeTimeoutId); resumeTimeoutId = 0; }
        if (retryTimeoutId)  { clearTimeout(retryTimeoutId);  retryTimeoutId  = 0; }
        clearResult();
        scanningPaused = false;
        ensureCameraAlive();
        // CRITICAL: never tear cameraStream down here.
    }

    // ─── Counter (server truth) ───────────────────────────────────
    function setCounter(checked, total, pulse) {
        const c = $('ke-counter-checked');
        const t = $('ke-counter-total');
        const pill = document.querySelector('.ke-topbar-counter');
        if (c) c.textContent = (checked == null) ? '—' : String(checked);
        if (t) t.textContent = (total == null) ? '—' : String(total);
        if (pill) pill.setAttribute('aria-label', (checked == null ? '—' : checked) + ' of ' + (total == null ? '—' : total) + ' checked in');
        if (pulse && c) {
            c.classList.remove('is-pulsing');
            // Restart the animation with a reflow.
            // eslint-disable-next-line no-unused-expressions
            void c.offsetWidth;
            c.classList.add('is-pulsing');
        }
    }

    function applyStats(next) {
        if (!next || typeof next !== 'object') return;
        const checked = parseInt(next.checked_in, 10);
        const total   = parseInt(next.total, 10);
        if (isNaN(checked) || isNaN(total)) return;
        const grew = !!(stats && checked > stats.checked_in);
        stats = { checked_in: checked, total: total, at: Date.now() };
        setCounter(checked, total, grew);
        markSync('live');
        persistStats();
    }

    function markSync(state) {
        const dot = $('ke-counter-sync');
        if (!dot) return;
        dot.classList.remove('is-live', 'is-syncing', 'is-stale');
        dot.classList.add('is-' + state);
        const pill = document.querySelector('.ke-topbar-counter');
        if (pill) {
            pill.title = state === 'live'    ? 'Live count from the server'
                       : state === 'syncing' ? 'Syncing with the server…'
                       :                       'Count may be out of date — reconnecting';
        }
    }

    async function refreshStats() {
        if (!sessionToken || !currentEventId || statsInFlight) return;
        statsInFlight = true;
        if (!stats || (Date.now() - stats.at) > STATS_STALE_MS) markSync('syncing');
        try {
            const resp = await fetch(REST + 'events/' + currentEventId + '/checkin-stats', {
                method: 'POST',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    'X-KE-Scanner-Token': sessionToken || '',
                },
                body: JSON.stringify({}),
            });
            const body = await resp.json().catch(() => ({}));
            if (resp.status === 401 && body && body.code === 'invalid_token') {
                sessionExpired();
                return;
            }
            if (resp.ok && body && typeof body.checked_in !== 'undefined') {
                applyStats(body);
            } else {
                markSync('stale');
            }
        } catch (_) {
            markSync('stale');
        } finally {
            statsInFlight = false;
        }
    }

    function startStatsPolling() {
        stopStatsPolling();
        statsTimer = window.setInterval(() => {
            if (document.hidden || !isScanningStateActive()) return;
            refreshStats();
        }, STATS_POLL_MS);
    }

    function stopStatsPolling() {
        if (statsTimer) { clearInterval(statsTimer); statsTimer = 0; }
    }

    // ─── Feedback (haptic + audio) ────────────────────────────────
    function ensureAudio() {
        if (audioCtx) return audioCtx;
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) return null;
        try { audioCtx = new Ctor(); } catch (_) { audioCtx = null; }
        return audioCtx;
    }
    // iOS only lets an AudioContext start inside a user gesture. Warm it up
    // on the taps that precede scanning, so the first beep actually sounds.
    function prewarmAudio() {
        const ac = ensureAudio();
        if (!ac) return;
        try { if (ac.state === 'suspended') ac.resume(); } catch (_) {}
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
        muted = storageGet(MUTE_STORAGE_KEY) === '1';
        applyMuteUI();
    }
    function toggleMute() {
        muted = !muted;
        storageSet(MUTE_STORAGE_KEY, muted ? '1' : '0');
        applyMuteUI();
        prewarmAudio();
    }

    // ─── Auth ─────────────────────────────────────────────────────
    async function authenticate(eventId, password) {
        const resp = await fetch(REST + 'scanner/auth', {
            method: 'POST',
            cache: 'no-store',
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
        };
        stats = null;
        const s = payload.stats || {};
        const checked = parseInt(s.checked_in != null ? s.checked_in : payload.checked_in, 10);
        const total   = parseInt(s.total      != null ? s.total      : payload.total_tickets, 10);
        if (!isNaN(checked) && !isNaN(total)) stats = { checked_in: checked, total: total, at: Date.now() };
        writeSession();
    }

    function writeSession() {
        storageSet(TOKEN_STORAGE_KEY, JSON.stringify({
            token: sessionToken,
            expires_at: tokenExpiresAt,
            event_id: currentEventId,
            meta: currentEventMeta,
            stats: stats,
        }));
    }

    // Keep the last known count next to the token so a refresh can paint a
    // number immediately (marked "syncing") instead of a blank pill while
    // the first poll is in flight.
    function persistStats() {
        if (!sessionToken) return;
        writeSession();
    }

    function clearSession(keepEvent) {
        sessionToken = null;
        tokenExpiresAt = 0;
        stats = null;
        if (!keepEvent) {
            currentEventId = 0;
            currentEventMeta = null;
        }
        stopStatsPolling();
        storageRemove(TOKEN_STORAGE_KEY);
    }

    // The token died (4h TTL, password changed, purged on the server). Keep
    // the event so the door only has to re-type the password — the old flow
    // dropped the event id too, and the retry failed with "Invalid event".
    function sessionExpired() {
        clearSession(true);
        clearResult();
        scanningPaused = true;
        setPausedClass(true);
        const evNameEl  = $('ke-event-name-display');
        const orgNameEl = $('ke-organizer-name-display');
        if (evNameEl  && currentEventMeta) evNameEl.textContent  = currentEventMeta.name || '';
        if (orgNameEl && currentEventMeta) orgNameEl.textContent = currentEventMeta.organizer || '—';
        const pw = $('ke-password-input');
        if (pw) pw.value = '';
        showState(currentEventId > 0 ? STATES.PASSWORD : STATES.EVENT_SELECT);
        const err = $('ke-password-error');
        if (err) err.textContent = currentEventId > 0 ? 'Session expired. Re-enter the password to keep scanning.' : '';
        if (currentEventId > 0) setTimeout(() => { if (pw) pw.focus(); }, 80);
    }

    // ─── Wire-up ──────────────────────────────────────────────────
    function bindEventList() {
        document.querySelectorAll('.ke-event-option').forEach((btn) => {
            btn.addEventListener('click', () => {
                prewarmAudio();
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
            prewarmAudio();
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
            if (resumeTimeoutId) { clearTimeout(resumeTimeoutId); resumeTimeoutId = 0; }
            if (retryTimeoutId)  { clearTimeout(retryTimeoutId);  retryTimeoutId  = 0; }
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
            const evNameEl  = $('ke-event-name');
            const orgNameEl = $('ke-organizer-name');
            if (evNameEl)  evNameEl.textContent  = currentEventMeta.name || '';
            if (orgNameEl) orgNameEl.textContent = currentEventMeta.organizer || '';
        }
        // Paint whatever we last knew, flagged as syncing, then ask the
        // server for the truth straight away.
        if (stats) setCounter(stats.checked_in, stats.total, false);
        else setCounter(null, null, false);
        markSync('syncing');
        refreshStats();

        clearResult();
        scanningPaused = false;
        setPausedClass(false);
        try { await initCamera(); } catch (_) { /* showResult already surfaced the error */ }
    }

    function tryResumeFromStorage() {
        let saved = null;
        try { saved = JSON.parse(storageGet(TOKEN_STORAGE_KEY) || 'null'); } catch (_) {}
        if (!saved || !saved.token) return false;
        const now = Math.floor(Date.now() / 1000);
        if (saved.expires_at && saved.expires_at <= now + 30) {
            storageRemove(TOKEN_STORAGE_KEY);
            return false;
        }
        sessionToken     = saved.token;
        tokenExpiresAt   = saved.expires_at || 0;
        currentEventId   = saved.event_id || 0;
        currentEventMeta = saved.meta || null;
        stats = null;
        if (saved.stats && typeof saved.stats.checked_in !== 'undefined') {
            stats = {
                checked_in: parseInt(saved.stats.checked_in, 10) || 0,
                total:      parseInt(saved.stats.total, 10) || 0,
                at:         0, // unknown age → shows as syncing until the first poll lands
            };
        } else if (saved.meta && typeof saved.meta.checked_in !== 'undefined') {
            // Pre-0.11 payload shape.
            stats = {
                checked_in: parseInt(saved.meta.checked_in, 10) || 0,
                total:      parseInt(saved.meta.total, 10) || 0,
                at:         0,
            };
        }
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
            return;
        }
        if (!isScanningStateActive()) return;
        // Back in the foreground: the count may have moved on other phones
        // and iOS may have ended our camera track while we were away.
        refreshStats();
        ensureCameraAlive();
        const area = $('ke-result-area');
        const hasResultCard = area && area.querySelector('.ke-result-card');
        if (!hasResultCard) {
            scanningPaused = false;
            setPausedClass(false);
        }
    });

    // Safari restores the page from the back/forward cache with the old JS
    // state but a dead camera — treat it like a foreground return.
    window.addEventListener('pageshow', (e) => {
        if (e.persisted && isScanningStateActive()) {
            refreshStats();
            ensureCameraAlive();
        }
    });

    window.addEventListener('pagehide', shutdownCamera);
    window.addEventListener('beforeunload', shutdownCamera);
})();
