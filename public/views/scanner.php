<?php
/**
 * Kiwi Scanner — standalone /kiwi-scanner page.
 *
 * Public route: the page itself is fully open. Security is enforced by the
 * organizer-password gate (state 2) and the session-token check on the
 * /tickets/validate REST endpoint.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$ui     = get_option( 'ke_ui_settings', array() );
$accent = ! empty( $ui['accent_color'] ) ? $ui['accent_color'] : '#6366f1';
$accent_attr = esc_attr( $accent );

// Pre-render the active events list server-side so the picker is usable
// before any JS executes. The same list is also available via the REST
// endpoint /scanner/active-events for any client-side refresh.
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
    <meta name="theme-color" content="#0a0a14">
    <meta name="robots" content="noindex,nofollow">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>🥝 <?php esc_html_e( 'Kiwi Scanner', 'kiwi-events' ); ?></title>

    <style>
    /*
     * Critical inline state CSS — guarantees mutual exclusion of the three
     * scanner states even before the external stylesheet finishes loading.
     * The !important here is load-bearing: it defeats any theme rule that
     * might try to display:flex/block on these containers.
     */
    .ke-scanner-state { display: none !important; }
    .ke-scanner-state.is-active { display: flex !important; }
    :root { --kep-accent-1: <?php echo $accent_attr; ?>; --ke-accent: <?php echo $accent_attr; ?>; }
    html, body { margin: 0; padding: 0; background: #0a0a14; color: #fff; }
    </style>

    <link rel="stylesheet" href="<?php echo esc_url( KE_PLUGIN_URL . 'public/css/ke-scanner.css?v=' . KE_SCANNER_ASSETS_VER ); ?>">
    <?php wp_head(); ?>
</head>
<body class="ke-scanner-body">

    <!-- ─── State 1: event select ──────────────────────────────────── -->
    <section id="ke-state-1" class="ke-scanner-state ke-state-event-select is-active" aria-label="<?php esc_attr_e( 'Select event', 'kiwi-events' ); ?>">
        <div class="ke-scanner-content">
            <header class="ke-scanner-header">
                <h1>🥝 <?php esc_html_e( 'Kiwi Scanner', 'kiwi-events' ); ?></h1>
                <p class="ke-scanner-subtitle"><?php esc_html_e( 'Choose the event you\'re scanning for.', 'kiwi-events' ); ?></p>
            </header>

            <label for="ke-event-select" class="ke-field-label"><?php esc_html_e( 'Active events', 'kiwi-events' ); ?></label>
            <select id="ke-event-select" class="ke-field-select" aria-label="<?php esc_attr_e( 'Active events', 'kiwi-events' ); ?>">
                <option value=""><?php esc_html_e( '— Select an event —', 'kiwi-events' ); ?></option>
                <?php foreach ( $active_events as $ev ) : ?>
                    <option
                        value="<?php echo esc_attr( $ev['id'] ); ?>"
                        data-organizer="<?php echo esc_attr( $ev['organizer_name'] ); ?>"
                        data-has-password="<?php echo ! empty( $ev['has_password'] ) ? '1' : '0'; ?>">
                        <?php echo esc_html( $ev['name'] ); ?> · <?php echo esc_html( $ev['date_label'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ( empty( $active_events ) ) : ?>
                <p class="ke-empty-note">
                    <?php esc_html_e( 'No events are within the scan window right now (24h before start through 12h after end).', 'kiwi-events' ); ?>
                </p>
            <?php endif; ?>

            <button type="button" id="ke-event-continue" class="ke-btn ke-btn-primary" disabled>
                <?php esc_html_e( 'Continue', 'kiwi-events' ); ?>
            </button>
        </div>
    </section>

    <!-- ─── State 2: organizer password gate ───────────────────────── -->
    <section id="ke-state-2" class="ke-scanner-state ke-state-password" aria-label="<?php esc_attr_e( 'Enter organizer password', 'kiwi-events' ); ?>">
        <div class="ke-scanner-content">
            <header class="ke-scanner-header">
                <h1 id="ke-event-name">—</h1>
                <p id="ke-organizer-name" class="ke-scanner-subtitle">—</p>
            </header>

            <label for="ke-password-input" class="ke-field-label"><?php esc_html_e( 'Organizer password', 'kiwi-events' ); ?></label>
            <input
                type="password"
                id="ke-password-input"
                class="ke-field-input"
                autocomplete="off"
                inputmode="text"
                placeholder="<?php esc_attr_e( 'Enter the password', 'kiwi-events' ); ?>" />

            <p id="ke-password-error" class="ke-error-msg" hidden></p>

            <div class="ke-btn-row">
                <button type="button" id="ke-back-to-events" class="ke-btn ke-btn-ghost">
                    <?php esc_html_e( 'Back', 'kiwi-events' ); ?>
                </button>
                <button type="button" id="ke-password-submit" class="ke-btn ke-btn-primary">
                    <?php esc_html_e( 'Unlock scanner', 'kiwi-events' ); ?>
                </button>
            </div>
        </div>
    </section>

    <!-- ─── State 3: scanning ──────────────────────────────────────── -->
    <section id="ke-state-3" class="ke-scanner-state ke-state-scanning" aria-label="<?php esc_attr_e( 'Scanning', 'kiwi-events' ); ?>">
        <header class="ke-scan-topbar">
            <button type="button" id="ke-switch-event" class="ke-btn-icon" aria-label="<?php esc_attr_e( 'Switch event', 'kiwi-events' ); ?>">
                ←
            </button>
            <div class="ke-scan-counter" aria-live="polite">
                <span id="ke-counter-checked">0</span> / <span id="ke-counter-total">0</span>
            </div>
        </header>

        <div class="ke-camera-container">
            <video id="ke-camera-video" playsinline muted autoplay></video>
            <div class="ke-scan-frame" aria-hidden="true"></div>
        </div>

        <div id="ke-result-area" class="ke-result-area" aria-live="polite"></div>

        <button type="button" id="ke-scan-another" class="ke-btn ke-btn-primary ke-btn-scan-another" hidden>
            <?php esc_html_e( 'Scan another', 'kiwi-events' ); ?>
        </button>
    </section>

    <script>
    window.kePublicScanner = {
        restUrl: <?php echo wp_json_encode( esc_url_raw( rest_url( 'ke/v1/' ) ) ); ?>
    };
    </script>
    <script src="<?php echo esc_url( KE_PLUGIN_URL . 'assets/vendor/jsQR.min.js?v=' . KE_SCANNER_ASSETS_VER ); ?>"></script>
    <script src="<?php echo esc_url( KE_PLUGIN_URL . 'public/js/ke-scanner.js?v=' . KE_SCANNER_ASSETS_VER ); ?>"></script>
    <?php wp_footer(); ?>
</body>
</html>
