/**
 * KiwiEvents — QR Ticket Scanner (jsQR + camera)
 */
(function() {
    'use strict';

    const video      = document.getElementById('ke-scanner-video');
    const canvas     = document.getElementById('ke-scanner-canvas');
    const ctx        = canvas ? canvas.getContext('2d', { willReadFrequently: true }) : null;
    const resultBox  = document.getElementById('ke-scanner-result');
    const statusText = document.getElementById('ke-scanner-status');
    const startBtn   = document.getElementById('ke-scanner-start');

    let scanning   = false;
    let stream     = null;
    let lastScanned = '';
    let cooldown    = false;

    // Audio feedback
    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();

    function beep(freq, duration) {
        const osc  = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.frequency.value = freq;
        gain.gain.value = 0.3;
        osc.start();
        gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + duration / 1000);
        osc.stop(audioCtx.currentTime + duration / 1000);
    }

    /**
     * Start the camera
     */
    async function startCamera() {
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }
            });
            video.srcObject = stream;
            video.setAttribute('playsinline', 'true');
            video.play();

            scanning = true;
            startBtn.textContent = '⏸ Stop Scanner';
            statusText.textContent = 'Point camera at a QR code...';
            statusText.className = 'ke-scanner-status';

            requestAnimationFrame(scanFrame);
        } catch (err) {
            statusText.textContent = '❌ Camera access denied. Please allow camera permissions.';
            statusText.className = 'ke-scanner-status ke-scanner-error';
            console.error('Camera error:', err);
        }
    }

    /**
     * Stop the camera
     */
    function stopCamera() {
        scanning = false;
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
        startBtn.textContent = '📷 Start Scanner';
        statusText.textContent = 'Scanner stopped.';
    }

    /**
     * Scan frame for QR code
     */
    function scanFrame() {
        if (!scanning) return;

        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: 'dontInvert',
            });

            if (code && code.data && !cooldown) {
                // Extract ticket code from URL
                const ticketCode = extractTicketCode(code.data);
                if (ticketCode && ticketCode !== lastScanned) {
                    lastScanned = ticketCode;
                    cooldown = true;
                    validateTicket(ticketCode);

                    // Reset cooldown after 3 seconds
                    setTimeout(() => {
                        cooldown = false;
                        lastScanned = '';
                    }, 3000);
                }
            }
        }

        requestAnimationFrame(scanFrame);
    }

    /**
     * Extract ticket code from QR data.
     * QR now encodes only the raw sha256 hex ticket_code.
     * Fallback: also handle legacy /ke-verify/{code} URLs.
     */
    function extractTicketCode(data) {
        if (!data) return null;
        const trimmed = data.trim();

        // Primary: raw hex string (sha256 = 64 chars, but accept 32+)
        if (/^[a-f0-9]{32,}$/i.test(trimmed)) return trimmed.toLowerCase();

        // Fallback: legacy /ke-verify/{code} URL format
        const match = trimmed.match(/ke-verify\/([a-f0-9]{32,})/i);
        if (match) return match[1].toLowerCase();

        return null;
    }

    /**
     * Validate ticket via REST API
     */
    async function validateTicket(ticketCode) {
        statusText.textContent = '🔍 Validating...';
        statusText.className = 'ke-scanner-status';

        try {
            const response = await fetch(keScannerData.restUrl + 'tickets/validate/' + ticketCode, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': keScannerData.nonce,
                    'Content-Type': 'application/json',
                },
            });

            const result = await response.json();

            showResult(result);
        } catch (err) {
            showResult({
                status: 'error',
                message: 'Network error. Please check your connection.',
            });
        }
    }

    /**
     * Show scan result with visual + audio feedback
     */
    function showResult(result) {
        resultBox.style.display = 'block';

        let html = '';
        let cssClass = '';

        switch (result.status) {
            case 'valid':
                cssClass = 'ke-result-valid';
                beep(800, 200);
                setTimeout(() => beep(1200, 300), 250);
                html = `
                    <div class="ke-result-icon">✅</div>
                    <div class="ke-result-title">VALID — Checked In!</div>
                    ${result.ticket ? `
                        <div class="ke-result-details">
                            <div class="ke-result-row"><span>Attendee</span><strong>${escHtml(result.ticket.attendee_name)}</strong></div>
                            <div class="ke-result-row"><span>Event</span><strong>${escHtml(result.ticket.event_name || '')}</strong></div>
                            <div class="ke-result-row"><span>Ticket</span><strong>${escHtml(result.ticket.ticket_type_name || '')}</strong></div>
                            <div class="ke-result-row"><span>Number</span><strong>#${String(result.ticket.attendee_number).padStart(4, '0')}</strong></div>
                        </div>
                    ` : ''}
                `;
                break;

            case 'already_used':
                cssClass = 'ke-result-warning';
                beep(400, 500);
                html = `
                    <div class="ke-result-icon">⚠️</div>
                    <div class="ke-result-title">ALREADY CHECKED IN</div>
                    <div class="ke-result-message">${escHtml(result.message)}</div>
                    ${result.ticket ? `
                        <div class="ke-result-details">
                            <div class="ke-result-row"><span>Attendee</span><strong>${escHtml(result.ticket.attendee_name)}</strong></div>
                            <div class="ke-result-row"><span>Event</span><strong>${escHtml(result.ticket.event_name || '')}</strong></div>
                        </div>
                    ` : ''}
                `;
                break;

            case 'invalid':
            default:
                cssClass = 'ke-result-invalid';
                beep(200, 600);
                html = `
                    <div class="ke-result-icon">❌</div>
                    <div class="ke-result-title">INVALID TICKET</div>
                    <div class="ke-result-message">${escHtml(result.message)}</div>
                `;
                break;
        }

        resultBox.className = 'ke-scanner-result ' + cssClass;
        resultBox.innerHTML = html;

        // Auto-hide after 4 seconds
        setTimeout(() => {
            resultBox.style.display = 'none';
        }, 4000);
    }

    /**
     * Escape HTML
     */
    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ─── Event Listeners ────────────────────────────────
    if (startBtn) {
        startBtn.addEventListener('click', () => {
            if (scanning) {
                stopCamera();
            } else {
                startCamera();
            }
        });
    }

    // Auto-validate if code provided via URL
    const urlParams = new URLSearchParams(window.location.search);
    const autoCode  = urlParams.get('validate');
    if (autoCode) {
        validateTicket(autoCode);
    }

})();
