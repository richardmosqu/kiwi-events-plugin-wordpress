<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>🎟️ Ticket Scanner — KiwiEvents</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f0f1a;
            color: #e0e0e8;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .scanner-header {
            background: linear-gradient(135deg, #181826, #22223a);
            padding: 20px 24px;
            text-align: center;
            border-bottom: 1px solid #2a2a45;
        }

        .scanner-header h1 {
            font-size: 20px;
            font-weight: 700;
            color: #6bcb77;
        }

        .scanner-header p {
            font-size: 13px;
            color: #8888a0;
            margin-top: 4px;
        }

        .scanner-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 24px;
        }

        .video-wrapper {
            position: relative;
            width: 100%;
            aspect-ratio: 4/3;
            background: #181826;
            border-radius: 16px;
            overflow: hidden;
            border: 2px solid #2a2a45;
            margin-bottom: 20px;
        }

        .video-wrapper video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .video-wrapper canvas {
            display: none;
        }

        .scan-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 200px;
            height: 200px;
            pointer-events: none;
        }

        .scan-overlay::before,
        .scan-overlay::after {
            content: '';
            position: absolute;
            width: 30px;
            height: 30px;
            border-color: #6bcb77;
            border-style: solid;
        }

        .scan-overlay::before {
            top: 0; left: 0;
            border-width: 3px 0 0 3px;
            border-radius: 4px 0 0 0;
        }

        .scan-overlay::after {
            top: 0; right: 0;
            border-width: 3px 3px 0 0;
            border-radius: 0 4px 0 0;
        }

        .scan-overlay .bottom-left,
        .scan-overlay .bottom-right {
            content: '';
            position: absolute;
            width: 30px;
            height: 30px;
            border-color: #6bcb77;
            border-style: solid;
        }

        .scan-overlay .bottom-left {
            bottom: 0; left: 0;
            border-width: 0 0 3px 3px;
            border-radius: 0 0 0 4px;
        }

        .scan-overlay .bottom-right {
            bottom: 0; right: 0;
            border-width: 0 3px 3px 0;
            border-radius: 0 0 4px 0;
        }

        .scan-line {
            position: absolute;
            left: 10px;
            right: 10px;
            height: 2px;
            background: linear-gradient(90deg, transparent, #6bcb77, transparent);
            animation: scanMove 2s ease-in-out infinite;
        }

        @keyframes scanMove {
            0%, 100% { top: 10px; }
            50% { top: calc(100% - 10px); }
        }

        .ke-scanner-status {
            text-align: center;
            padding: 12px;
            font-size: 14px;
            color: #8888a0;
            margin-bottom: 16px;
        }

        .ke-scanner-error { color: #ef4444; }

        .scanner-start-btn {
            display: block;
            width: 100%;
            padding: 16px;
            background: #6bcb77;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }

        .scanner-start-btn:hover {
            background: #4aa356;
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(107, 203, 119, 0.3);
        }

        /* Result Display */
        .ke-scanner-result {
            display: none;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            text-align: center;
            animation: resultPop 0.3s ease;
        }

        @keyframes resultPop {
            0% { transform: scale(0.9); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }

        .ke-result-valid {
            background: rgba(107, 203, 119, 0.1);
            border: 2px solid rgba(107, 203, 119, 0.4);
        }

        .ke-result-warning {
            background: rgba(245, 158, 11, 0.1);
            border: 2px solid rgba(245, 158, 11, 0.4);
        }

        .ke-result-invalid {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid rgba(239, 68, 68, 0.4);
        }

        .ke-result-icon {
            font-size: 48px;
            margin-bottom: 12px;
        }

        .ke-result-title {
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .ke-result-valid .ke-result-title { color: #6bcb77; }
        .ke-result-warning .ke-result-title { color: #f59e0b; }
        .ke-result-invalid .ke-result-title { color: #ef4444; }

        .ke-result-message {
            font-size: 14px;
            color: #b0b0c8;
            margin-bottom: 12px;
        }

        .ke-result-details {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 10px;
            padding: 16px;
            margin-top: 12px;
            text-align: left;
        }

        .ke-result-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .ke-result-row:last-child { border-bottom: none; }
        .ke-result-row span { color: #8888a0; }
        .ke-result-row strong { color: #fff; }

        /* Logged in info */
        .scanner-user {
            text-align: center;
            padding: 12px;
            font-size: 12px;
            color: #5a5a70;
            border-top: 1px solid #2a2a45;
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <header class="scanner-header">
        <h1>🥝 KiwiEvents Scanner</h1>
        <p>Scan ticket QR codes to check in attendees</p>
    </header>

    <div class="scanner-container">
        <div class="video-wrapper">
            <video id="ke-scanner-video" playsinline></video>
            <canvas id="ke-scanner-canvas"></canvas>
            <div class="scan-overlay">
                <div class="bottom-left"></div>
                <div class="bottom-right"></div>
                <div class="scan-line"></div>
            </div>
        </div>

        <p id="ke-scanner-status" class="ke-scanner-status">Tap the button below to start scanning.</p>

        <button id="ke-scanner-start" class="scanner-start-btn">📷 Start Scanner</button>

        <div id="ke-scanner-result" class="ke-scanner-result"></div>

        <div class="scanner-user">
            Logged in as: <?php echo esc_html( wp_get_current_user()->display_name ); ?>
            &bull;
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=kiwi-events' ) ); ?>" style="color: #6bcb77;">Back to Dashboard</a>
        </div>
    </div>

    <!-- jsQR Library -->
    <script src="<?php echo esc_url( KE_PLUGIN_URL . 'assets/vendor/jsQR.min.js' ); ?>"></script>

    <!-- Scanner Data -->
    <script>
        var keScannerData = {
            restUrl: '<?php echo esc_url_raw( rest_url( 'ke/v1/' ) ); ?>',
            nonce: '<?php echo wp_create_nonce( 'wp_rest' ); ?>',
        };
    </script>

    <!-- Scanner JS -->
    <script src="<?php echo esc_url( KE_PLUGIN_URL . 'public/js/ke-scanner.js' ); ?>"></script>
</body>
</html>
