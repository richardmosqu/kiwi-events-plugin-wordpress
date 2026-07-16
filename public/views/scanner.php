<?php
/**
 * Kiwi Scanner — standalone /kiwi-scanner page.
 *
 * Public route. Self-contained: skips wp_head()/wp_footer() so theme/host
 * footers (e.g. WordPress.com badge) don't bleed into the scanner UI.
 * Security is the per-organizer password gate (state 2) + the session-token
 * check on POST /tickets/validate/{code}.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Scanner uses Kiwi's brand green regardless of admin accent setting.
$accent      = '#badb24';
$accent_attr = esc_attr( $accent );

// Pre-render active events server-side so the picker is usable before any
// JS executes. Same list also available client-side via /scanner/active-events.
$active_events = array();
if ( class_exists( 'KE_REST_API' ) ) {
    $rest = new KE_REST_API();
    if ( method_exists( $rest, 'get_public_active_events' ) ) {
        $req  = new WP_REST_Request( 'GET', '/ke/v1/scanner/active-events' );
        $resp = $rest->get_public_active_events( $req );
        if ( $resp instanceof WP_REST_Response ) {
            $data = $resp->get_data();
            if ( isset( $data['events'] ) && is_array( $data['events'] ) ) {
                $active_events = $data['events'];
            }
        }
    }
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#000000">
    <meta name="robots" content="noindex,nofollow">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>🥝 <?php esc_html_e( 'Kiwi Scanner', 'kiwi-events' ); ?></title>

    <style>
    /*
     * Critical inline state CSS — guarantees mutual exclusion of the three
     * states even before the external stylesheet finishes loading.
     */
    .ke-scanner-state { display: none !important; opacity: 0; }
    .ke-scanner-state.is-active { display: flex !important; opacity: 1; }
    :root {
        --kep-accent-1: <?php echo $accent_attr; ?>;
        --ke-accent:    <?php echo $accent_attr; ?>;
    }
    html, body { margin: 0; padding: 0; background: #000; color: #fff; }
    </style>

    <link rel="stylesheet" href="<?php echo esc_url( KE_PLUGIN_URL . 'public/css/ke-scanner.css?v=' . KE_SCANNER_ASSETS_VER ); ?>">
</head>
<body class="ke-scanner-body">

    <!-- ─── State 1: event select ──────────────────────────────────── -->
    <section id="ke-state-1" class="ke-scanner-state ke-state-event-select is-active" aria-label="<?php esc_attr_e( 'Select event', 'kiwi-events' ); ?>">
        <div class="ke-state-content">
            <div class="ke-brand-mark" aria-hidden="true">🥝</div>
            <h1><?php esc_html_e( 'Kiwi Scanner', 'kiwi-events' ); ?></h1>
            <p class="ke-state-subtitle"><?php esc_html_e( 'Select the event you\'re scanning tickets for tonight.', 'kiwi-events' ); ?></p>

            <?php if ( empty( $active_events ) ) : ?>
                <div class="ke-empty-card">
                    <div class="ke-empty-card-icon" aria-hidden="true">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                    </div>
                    <div class="ke-empty-card-title"><?php esc_html_e( 'No events in the scan window', 'kiwi-events' ); ?></div>
                    <div class="ke-empty-card-detail"><?php esc_html_e( 'Events appear here from 24 hours before start until 12 hours after end.', 'kiwi-events' ); ?></div>
                </div>
            <?php else : ?>
                <div class="ke-event-list" role="list">
                    <?php foreach ( $active_events as $ev ) : ?>
                        <button
                            type="button"
                            class="ke-event-option"
                            role="listitem"
                            data-event-id="<?php echo esc_attr( $ev['id'] ); ?>"
                            data-event-name="<?php echo esc_attr( $ev['name'] ); ?>"
                            data-organizer="<?php echo esc_attr( $ev['organizer_name'] ); ?>"
                            data-date-label="<?php echo esc_attr( $ev['date_label'] ); ?>"
                            data-has-password="<?php echo ! empty( $ev['has_password'] ) ? '1' : '0'; ?>">
                            <div class="ke-event-option-main">
                                <div class="ke-event-option-name"><?php echo esc_html( $ev['name'] ); ?></div>
                                <div class="ke-event-option-meta">
                                    <?php echo esc_html( $ev['date_label'] ); ?>
                                    <?php if ( ! empty( $ev['organizer_name'] ) ) : ?>
                                        · <?php echo esc_html( $ev['organizer_name'] ); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <svg class="ke-event-option-chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M9 18l6-6-6-6"/>
                            </svg>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ─── State 2: organizer password gate ───────────────────────── -->
    <section id="ke-state-2" class="ke-scanner-state ke-state-password" aria-label="<?php esc_attr_e( 'Enter organizer password', 'kiwi-events' ); ?>">
        <button type="button" id="ke-back-to-events" class="ke-back-button" aria-label="<?php esc_attr_e( 'Back to events', 'kiwi-events' ); ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
        </button>

        <div class="ke-state-content">
            <div class="ke-lock-icon" aria-hidden="true">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0110 0v4"/>
                </svg>
            </div>
            <h1><?php esc_html_e( 'Enter password', 'kiwi-events' ); ?></h1>
            <p class="ke-state-subtitle">
                <span id="ke-event-name-display"><?php esc_html_e( 'Event name', 'kiwi-events' ); ?></span><br>
                <span class="ke-subtitle-light"><?php esc_html_e( 'Set by', 'kiwi-events' ); ?></span> <strong id="ke-organizer-name-display"><?php esc_html_e( 'Organizer', 'kiwi-events' ); ?></strong>
            </p>

            <input
                type="password"
                id="ke-password-input"
                class="ke-password-input"
                autocomplete="off"
                inputmode="text"
                placeholder="••••••" />

            <button type="button" id="ke-password-submit" class="ke-btn-primary">
                <?php esc_html_e( 'Unlock Scanner', 'kiwi-events' ); ?>
            </button>

            <div id="ke-password-error" class="ke-error-message"></div>
        </div>
    </section>

    <!-- ─── State 3: scanning ──────────────────────────────────────── -->
    <section id="ke-state-3" class="ke-scanner-state ke-state-scanning" aria-label="<?php esc_attr_e( 'Scanning', 'kiwi-events' ); ?>">
        <header class="ke-scanner-topbar">
            <button type="button" id="ke-switch-event" class="ke-topbar-back" aria-label="<?php esc_attr_e( 'Switch event', 'kiwi-events' ); ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
            </button>
            <div class="ke-topbar-event">
                <div class="ke-topbar-event-name" id="ke-event-name">—</div>
                <div class="ke-topbar-event-meta" id="ke-organizer-name">—</div>
            </div>
            <button type="button" id="ke-mute-toggle" class="ke-topbar-mute" aria-label="<?php esc_attr_e( 'Toggle audio', 'kiwi-events' ); ?>" aria-pressed="false">
                <svg class="ke-mute-icon-on" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                    <path d="M15.54 8.46a5 5 0 010 7.07"/>
                    <path d="M19.07 4.93a10 10 0 010 14.14"/>
                </svg>
                <svg class="ke-mute-icon-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/>
                    <line x1="23" y1="9" x2="17" y2="15"/>
                    <line x1="17" y1="9" x2="23" y2="15"/>
                </svg>
            </button>
            <div class="ke-topbar-counter" aria-live="polite">
                <span class="ke-counter-checked" id="ke-counter-checked">0</span>
                <span class="ke-counter-divider">/</span>
                <span class="ke-counter-total" id="ke-counter-total">0</span>
            </div>
        </header>

        <div class="ke-camera-container">
            <video id="ke-camera-video" playsinline muted autoplay></video>
            <div class="ke-scan-overlay">
                <div class="ke-scan-frame">
                    <span></span><span></span>
                </div>
                <div class="ke-scan-line" id="ke-scan-line"></div>
            </div>
            <div class="ke-camera-loading" id="ke-camera-loading">
                <div class="ke-spinner" aria-hidden="true"></div>
                <div><?php esc_html_e( 'Starting camera…', 'kiwi-events' ); ?></div>
            </div>
        </div>

        <div class="ke-result-area" id="ke-result-area">
            <div class="ke-result-empty">
                <div class="ke-result-empty-icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="7" height="7" rx="1"/>
                        <rect x="14" y="3" width="7" height="7" rx="1"/>
                        <rect x="3" y="14" width="7" height="7" rx="1"/>
                        <line x1="14" y1="14" x2="14.01" y2="14"/>
                        <line x1="20" y1="14" x2="20.01" y2="14"/>
                        <line x1="14" y1="20" x2="14.01" y2="20"/>
                        <line x1="20" y1="20" x2="20.01" y2="20"/>
                        <line x1="17" y1="17" x2="17.01" y2="17"/>
                    </svg>
                </div>
                <div class="ke-result-empty-text"><?php esc_html_e( 'Point camera at QR code', 'kiwi-events' ); ?></div>
            </div>
        </div>

        <div class="ke-scanner-actions">
            <button type="button" id="ke-scan-another" class="ke-btn-primary" hidden>
                <span class="ke-btn-label"><?php esc_html_e( 'Scan Another', 'kiwi-events' ); ?></span>
                <svg class="ke-btn-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 12h14M12 5l7 7-7 7"/>
                </svg>
            </button>
        </div>
    </section>

    <script>
    window.kePublicScanner = {
        restUrl: <?php echo wp_json_encode( esc_url_raw( rest_url( 'ke/v1/' ) ) ); ?>
    };
    </script>
    <script src="<?php echo esc_url( KE_PLUGIN_URL . 'assets/vendor/jsQR.min.js?v=' . KE_SCANNER_ASSETS_VER ); ?>"></script>
    <script src="<?php echo esc_url( KE_PLUGIN_URL . 'public/js/ke-scanner.js?v=' . KE_SCANNER_ASSETS_VER ); ?>"></script>
</body>
</html>
