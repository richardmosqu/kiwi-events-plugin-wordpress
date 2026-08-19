<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Scheduled ticket sales notice + waitlist form.
 *
 * Included by public/views/single-event.php INSTEAD of the ticket list while
 * KE_Sales_Schedule::is_pending() is true. Self-contained: it only needs
 * $event_id in scope (the single-event template sets it at the top).
 *
 * The gate itself is server-side. This view additionally ships a live
 * countdown that re-checks GET /ke/v1/events/{id}/sale-status when it hits
 * zero, because the surrounding HTML can be served from an edge cache well
 * after the sale opened (see public/js/ke-waitlist.js).
 */

if ( ! isset( $event_id ) ) {
    global $post;
    $event_id = $post ? (int) $post->ID : 0;
}
if ( ! $event_id || ! class_exists( 'KE_Sales_Schedule' ) ) {
    return;
}

// Late enqueue for the [kiwi_event] shortcode path, where
// KE_Public::enqueue_assets() already ran before this event was known.
// Idempotent, so the normal singular path is unaffected.
if ( method_exists( 'KE_Public', 'enqueue_waitlist_assets' ) ) {
    KE_Public::enqueue_waitlist_assets();
}

$ke_sg_cfg    = KE_Sales_Schedule::get_config( $event_id );
$ke_sg_labels = KE_Sales_Schedule::labels( $event_id, $ke_sg_cfg );
$ke_sg_iso    = KE_Sales_Schedule::open_iso( $event_id, $ke_sg_cfg );
$ke_sg_wait   = ! empty( $ke_sg_cfg['waitlist_enabled'] );
$ke_sg_note   = trim( (string) ( $ke_sg_cfg['note'] ?? '' ) );

// Prefill for logged-in visitors. Anonymous page views are edge-cached, so
// this is only ever populated on an uncached (logged-in) render.
$ke_sg_user   = wp_get_current_user();
$ke_sg_email  = ( $ke_sg_user && $ke_sg_user->ID ) ? $ke_sg_user->user_email : '';
?>
<div class="ke-content-section ke-sales-gate"
     id="ke-sales-gate"
     data-event-id="<?php echo esc_attr( $event_id ); ?>"
     data-opens-at="<?php echo esc_attr( $ke_sg_iso ); ?>"
     data-rest="<?php echo esc_attr( esc_url_raw( rest_url( 'ke/v1/' ) ) ); ?>">

    <p class="ke-section-label"><?php echo esc_html__( 'Boletos', 'kiwi-events' ); ?></p>

    <div class="ke-sg-card">
        <span class="ke-sg-icon" aria-hidden="true">🎟️</span>

        <p class="ke-sg-kicker"><?php echo esc_html__( 'Boletos disponibles a partir del', 'kiwi-events' ); ?></p>

        <?php if ( $ke_sg_labels['date'] !== '' ) : ?>
            <p class="ke-sg-day"><?php echo esc_html( $ke_sg_labels['day'] ); ?></p>
            <h2 class="ke-sg-date"><?php echo esc_html( $ke_sg_labels['date'] ); ?></h2>
            <p class="ke-sg-time">
                <?php echo esc_html( $ke_sg_labels['time'] ); ?>
                <?php if ( $ke_sg_labels['tz'] !== '' ) : ?>
                    <span class="ke-sg-tz"><?php echo esc_html( $ke_sg_labels['tz'] ); ?></span>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <!-- Countdown. Hidden until the JS fills it so a no-JS visitor never
             sees an empty shell — the date above is the real information. -->
        <div class="ke-sg-countdown" id="ke-sg-countdown" hidden>
            <div class="ke-sg-unit"><span class="ke-sg-num" data-unit="d">0</span><span class="ke-sg-lbl"><?php echo esc_html__( 'días', 'kiwi-events' ); ?></span></div>
            <div class="ke-sg-unit"><span class="ke-sg-num" data-unit="h">0</span><span class="ke-sg-lbl"><?php echo esc_html__( 'horas', 'kiwi-events' ); ?></span></div>
            <div class="ke-sg-unit"><span class="ke-sg-num" data-unit="m">0</span><span class="ke-sg-lbl"><?php echo esc_html__( 'min', 'kiwi-events' ); ?></span></div>
            <div class="ke-sg-unit"><span class="ke-sg-num" data-unit="s">0</span><span class="ke-sg-lbl"><?php echo esc_html__( 'seg', 'kiwi-events' ); ?></span></div>
        </div>

        <?php if ( $ke_sg_note !== '' ) : ?>
            <p class="ke-sg-note"><?php echo esc_html( $ke_sg_note ); ?></p>
        <?php endif; ?>

        <?php if ( $ke_sg_wait ) : ?>
            <form class="ke-sg-form" id="ke-sg-form" novalidate>
                <p class="ke-sg-form-label"><?php echo esc_html__( '¿Quieres que te avisemos?', 'kiwi-events' ); ?></p>

                <div class="ke-sg-row">
                    <label class="screen-reader-text" for="ke-sg-email"><?php echo esc_html__( 'Correo electrónico', 'kiwi-events' ); ?></label>
                    <input type="email"
                           id="ke-sg-email"
                           class="ke-sg-input"
                           name="email"
                           autocomplete="email"
                           inputmode="email"
                           required
                           value="<?php echo esc_attr( $ke_sg_email ); ?>"
                           placeholder="<?php echo esc_attr__( 'tu@correo.com', 'kiwi-events' ); ?>">
                    <button type="submit" class="ke-sg-btn" id="ke-sg-submit">
                        <?php echo esc_html__( 'Avísame', 'kiwi-events' ); ?>
                    </button>
                </div>

                <!-- Honeypot: real people never see it, bots fill everything. -->
                <div class="ke-sg-hp" aria-hidden="true">
                    <label for="ke-sg-website"><?php echo esc_html__( 'Sitio web', 'kiwi-events' ); ?></label>
                    <input type="text" id="ke-sg-website" name="website" tabindex="-1" autocomplete="off">
                </div>

                <p class="ke-sg-msg" id="ke-sg-msg" role="status" aria-live="polite" hidden></p>

                <p class="ke-sg-privacy">
                    <?php echo esc_html__( 'Te escribiremos una sola vez, cuando abra la venta de este evento.', 'kiwi-events' ); ?>
                </p>
            </form>
        <?php endif; ?>
    </div>
</div>
