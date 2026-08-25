<?php
/**
 * Public organizer profile.
 *
 * Expected vars (set by KE_Organizer_Public::handle_request):
 *   $organizer        WP_Term
 *   $logo_url         string
 *   $hero_url         string
 *   $category_text    string
 *   $gallery_ids      int[]
 *   $upcoming_events  array<array> (see query_events_for_organizer)
 *   $past_events      array<array>
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

// Resolve gallery media once, outside the loop, for both grid and lightbox.
$gallery = array();
foreach ( (array) ( $gallery_ids ?? array() ) as $gid ) {
    $gid = (int) $gid;
    if ( ! $gid ) continue;

    $mime = (string) get_post_mime_type( $gid );
    if ( str_starts_with( $mime, 'image/' ) ) {
        $thumb = wp_get_attachment_image_url( $gid, 'medium' );
        $full  = wp_get_attachment_image_url( $gid, 'large' );
        if ( ! $thumb || ! $full ) continue;
        $gallery[] = array(
            'type'  => 'image',
            'thumb' => $thumb,
            'full'  => $full,
            'mime'  => $mime,
            'alt'   => get_post_meta( $gid, '_wp_attachment_image_alt', true ),
        );
    } elseif ( str_starts_with( $mime, 'video/' ) ) {
        $full = wp_get_attachment_url( $gid );
        if ( ! $full ) continue;
        $gallery[] = array(
            'type'  => 'video',
            'thumb' => wp_get_attachment_image_url( $gid, 'medium' ) ?: '',
            'full'  => $full,
            'mime'  => sanitize_mime_type( $mime ),
            'alt'   => get_the_title( $gid ),
        );
    }
}

// The CTA and the first upcoming card share this exact event source.
$current_event = ! empty( $upcoming_events ) ? reset( $upcoming_events ) : null;

// Build calendar event payload (subset of fields, JSON-encoded into a data
// attribute the JS reads). Includes both upcoming and past so the calendar
// shows the full lineup.
$cal_events = array();
foreach ( array_merge( (array) $upcoming_events, (array) $past_events ) as $ev ) {
    $event_timezone = ! empty( $ev['timezone'] ) ? new DateTimeZone( $ev['timezone'] ) : wp_timezone();
    $cal_events[] = array(
        'id'        => (int) $ev['id'],
        'title'     => (string) $ev['title'],
        'permalink' => (string) $ev['permalink'],
        'start'     => wp_date( 'Y-m-d', (int) $ev['start_ts'], $event_timezone ),
        'end'       => wp_date( 'Y-m-d', (int) $ev['end_ts'], $event_timezone ),
        'thumb'     => (string) $ev['thumb_url'],
    );
}
?>
<div class="ke-org-public" data-organizer-slug="<?php echo esc_attr( $organizer->slug ); ?>">

    <!-- ── HERO ── -->
    <section class="ke-op-hero<?php echo $hero_url ? '' : ' is-no-hero'; ?><?php echo $current_event ? ' has-current-event' : ''; ?>"
             <?php if ( $hero_url ) : ?>style="background-image: url('<?php echo esc_url( $hero_url ); ?>');"<?php endif; ?>>
        <div class="ke-op-hero-glow" aria-hidden="true"></div>
        <div class="ke-op-hero-inner">
            <div class="ke-op-card<?php echo $current_event ? '' : ' is-identity-only'; ?>">
                <div class="ke-op-identity">
                    <div class="ke-op-card-logo">
                        <?php if ( $logo_url ) : ?>
                            <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $organizer->name ); ?>" loading="eager">
                        <?php else : ?><div class="ke-op-card-logo-fallback" aria-hidden="true">🎪</div><?php endif; ?>
                    </div>
                    <div class="ke-op-identity-copy">
                        <?php if ( $category_text ) : ?><div class="ke-op-card-category"><?php echo esc_html( $category_text ); ?></div><?php endif; ?>
                        <h1 class="ke-op-card-name"><?php echo esc_html( $organizer->name ); ?></h1>
                        <?php if ( ! empty( $organizer->description ) ) : ?><p class="ke-op-card-bio"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $organizer->description ), 34, '…' ) ); ?></p><?php endif; ?>
                    </div>
                </div>

                <?php if ( $current_event ) : ?>
                    <div class="ke-op-current-event" data-current-event-id="<?php echo (int) $current_event['id']; ?>">
                        <?php if ( ! empty( $current_event['thumb_url'] ) ) : ?>
                            <a class="ke-op-current-thumb" href="<?php echo esc_url( $current_event['permalink'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Ir al evento: %s', 'kiwi-events' ), $current_event['title'] ) ); ?>">
                                <img src="<?php echo esc_url( $current_event['thumb_url'] ); ?>" alt="" loading="eager">
                            </a>
                        <?php endif; ?>
                        <div class="ke-op-current-body">
                            <div class="ke-op-current-kicker"><?php esc_html_e( 'Próxima fecha oficial', 'kiwi-events' ); ?></div>
                            <a class="ke-op-current-title ke-op-current-title-link" href="<?php echo esc_url( $current_event['permalink'] ); ?>"><?php echo esc_html( $current_event['title'] ); ?></a>
                            <div class="ke-op-current-date">
                                <span aria-hidden="true">●</span>
                                <?php echo esc_html( wp_date( 'D j M · g:i a', (int) $current_event['start_ts'], new DateTimeZone( $current_event['timezone'] ?: wp_timezone_string() ) ) ); ?>
                                <?php if ( ! empty( $current_event['venue'] ) ) : ?><span class="ke-op-current-venue">· <?php echo esc_html( $current_event['venue'] ); ?></span><?php endif; ?>
                            </div>
                            <div class="ke-op-current-actions">
                                <?php if ( ! empty( $current_event['tickets_open'] ) ) : ?>
                                    <a href="<?php echo esc_url( $current_event['permalink'] . '#ke-tickets-section' ); ?>" class="ke-op-action ke-op-action--primary" data-ke-cta="buy-tickets" data-event-id="<?php echo (int) $current_event['id']; ?>"><?php esc_html_e( 'Descarga tus boletos', 'kiwi-events' ); ?></a>
                                <?php else : ?>
                                    <a href="<?php echo esc_url( $current_event['permalink'] ); ?>" class="ke-op-action ke-op-action--primary" data-ke-cta="view-event" data-event-id="<?php echo (int) $current_event['id']; ?>"><?php esc_html_e( 'Ver evento', 'kiwi-events' ); ?></a>
                                <?php endif; ?>
                                <?php if ( ! empty( $current_event['reservations_open'] ) ) : ?>
                                    <a href="<?php echo esc_url( $current_event['permalink'] . '#ke-reservations-section' ); ?>" class="ke-op-action ke-op-action--secondary" data-ke-cta="reserve-table" data-event-id="<?php echo (int) $current_event['id']; ?>"><?php esc_html_e( 'Reservar mesa', 'kiwi-events' ); ?></a>
                                <?php endif; ?>
                            </div>
                            <?php if ( empty( $current_event['tickets_open'] ) && ! empty( $current_event['reservations_enabled'] ) && empty( $current_event['reservations_open'] ) ) :
                                $reservation_status = (string) ( $current_event['reservations_status'] ?? '' );
                                $reservation_message = array( 'before' => __( 'Las reservas abrirán pronto para esta fecha.', 'kiwi-events' ), 'closed' => __( 'Reservas cerradas para esta fecha.', 'kiwi-events' ), 'full' => __( 'Mesas agotadas para esta fecha.', 'kiwi-events' ) )[ $reservation_status ] ?? '';
                            ?>
                                <?php if ( $reservation_message ) : ?><div class="ke-op-action-status" data-reservation-status="<?php echo esc_attr( $reservation_status ); ?>"><?php echo esc_html( $reservation_message ); ?></div><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ── TABS ── -->
    <nav class="ke-op-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Profile sections', 'kiwi-events' ); ?>">
        <button type="button" id="ke-op-tab-events" class="ke-op-tab is-active" role="tab" aria-selected="true"
                aria-controls="ke-op-panel-events" data-tab="events">
            <span><?php esc_html_e( 'Eventos', 'kiwi-events' ); ?></span>
            <span class="ke-op-tab-count"><?php echo count( $upcoming_events ) + count( $past_events ); ?></span>
        </button>
        <button type="button" id="ke-op-tab-gallery" class="ke-op-tab" role="tab" aria-selected="false"
                aria-controls="ke-op-panel-gallery" data-tab="gallery">
            <span><?php esc_html_e( 'Galería', 'kiwi-events' ); ?></span>
            <?php if ( ! empty( $gallery ) ) : ?><span class="ke-op-tab-count"><?php echo count( $gallery ); ?></span><?php endif; ?>
        </button>
        <button type="button" id="ke-op-tab-calendar" class="ke-op-tab" role="tab" aria-selected="false"
                aria-controls="ke-op-panel-calendar" data-tab="calendar">
            <?php esc_html_e( 'Calendario', 'kiwi-events' ); ?>
        </button>
    </nav>

    <div class="ke-op-content">

        <!-- ── EVENTOS ── -->
        <section id="ke-op-panel-events" class="ke-op-panel is-active" role="tabpanel" aria-labelledby="ke-op-tab-events">
            <div class="ke-op-section-intro"><div><span class="ke-op-section-eyebrow">Agenda oficial</span><h2>Eventos y experiencias</h2></div><p>Entradas y reservas conectadas siempre a la fecha vigente.</p></div>
            <?php
            $has_upcoming = ! empty( $upcoming_events );
            $has_past     = ! empty( $past_events );
            ?>
            <?php if ( $has_upcoming || $has_past ) : ?>
                <div class="ke-op-events-toggle" role="tablist" aria-label="<?php esc_attr_e( 'Filter events', 'kiwi-events' ); ?>">
                    <button type="button" class="ke-op-pill is-active" data-events="upcoming"
                            <?php echo $has_upcoming ? '' : 'disabled'; ?>>
                        <?php esc_html_e( 'Próximos', 'kiwi-events' ); ?>
                        <span class="ke-op-pill-count"><?php echo count( $upcoming_events ); ?></span>
                    </button>
                    <button type="button" class="ke-op-pill" data-events="past"
                            <?php echo $has_past ? '' : 'disabled'; ?>>
                        <?php esc_html_e( 'Pasados', 'kiwi-events' ); ?>
                        <span class="ke-op-pill-count"><?php echo count( $past_events ); ?></span>
                    </button>
                </div>
            <?php endif; ?>

            <div class="ke-op-events-grid" data-state="upcoming">
                <?php foreach ( $upcoming_events as $ev ) : ?>
                    <?php echo ke_op_render_event_card( $ev ); ?>
                <?php endforeach; ?>
                <?php if ( ! $has_upcoming ) : ?>
                    <div class="ke-op-empty">
                        <span class="ke-op-empty-icon">📅</span>
                        <p><?php esc_html_e( 'No hay eventos próximos.', 'kiwi-events' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="ke-op-events-grid" data-state="past" hidden>
                <?php foreach ( $past_events as $ev ) : ?>
                    <?php echo ke_op_render_event_card( $ev, true ); ?>
                <?php endforeach; ?>
                <?php if ( ! $has_past ) : ?>
                    <div class="ke-op-empty">
                        <span class="ke-op-empty-icon">🗓️</span>
                        <p><?php esc_html_e( 'Aún no hay eventos pasados.', 'kiwi-events' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ── GALERÍA ── -->
        <section id="ke-op-panel-gallery" class="ke-op-panel" role="tabpanel" aria-labelledby="ke-op-tab-gallery" hidden>
            <div class="ke-op-section-intro"><div><span class="ke-op-section-eyebrow">Momentos Furia</span><h2>La experiencia en imágenes</h2></div><p>Fotos y videos seleccionados por el organizador.</p></div>
            <?php if ( empty( $gallery ) ) : ?>
                <div class="ke-op-empty">
                    <span class="ke-op-empty-icon">🖼️</span>
                    <p><?php esc_html_e( 'Aún no hay fotos ni videos en la galería.', 'kiwi-events' ); ?></p>
                </div>
            <?php else : ?>
                <div class="ke-op-gallery-grid">
                    <?php foreach ( $gallery as $i => $g ) : ?>
                        <button type="button" class="ke-op-gallery-item"
                                data-type="<?php echo esc_attr( $g['type'] ); ?>"
                                data-full="<?php echo esc_attr( $g['full'] ); ?>"
                                data-mime="<?php echo esc_attr( $g['mime'] ); ?>"
                                data-index="<?php echo $i; ?>"
                                aria-label="<?php echo esc_attr( $g['type'] === 'video' ? __( 'View video', 'kiwi-events' ) : __( 'View photo', 'kiwi-events' ) ); ?>">
                            <?php if ( $g['type'] === 'video' ) : ?>
                                <video src="<?php echo esc_url( $g['full'] ); ?>"
                                       <?php if ( $g['thumb'] ) : ?>poster="<?php echo esc_url( $g['thumb'] ); ?>"<?php endif; ?>
                                       muted playsinline preload="metadata" aria-hidden="true"></video>
                                <span class="ke-op-gallery-play" aria-hidden="true">▶</span>
                            <?php else : ?>
                                <img src="<?php echo esc_url( $g['thumb'] ); ?>"
                                     alt="<?php echo esc_attr( $g['alt'] ?: $organizer->name ); ?>"
                                     loading="lazy">
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- ── CALENDARIO ── -->
        <section id="ke-op-panel-calendar" class="ke-op-panel" role="tabpanel" aria-labelledby="ke-op-tab-calendar" hidden>
            <div class="ke-op-calendar"
                 data-events="<?php echo esc_attr( wp_json_encode( $cal_events ) ); ?>">
                <div class="ke-op-cal-loading"><?php esc_html_e( 'Loading calendar…', 'kiwi-events' ); ?></div>
            </div>
        </section>
    </div>
    <?php
    // No fixed bottom buy-bar on mobile: the theme's mobile nav (nav.clnav,
    // position:fixed, z-index 99990) owns the bottom edge of every page, and
    // the bar rendered invisible underneath it — leaving phones with no CTA
    // at all. The hero card keeps its buttons at every width instead.
    ?>
</div>

<!-- Lightbox (hidden until a gallery item is clicked) -->
<div class="ke-op-lightbox" id="ke-op-lightbox" role="dialog" aria-modal="true" aria-hidden="true" hidden>
    <button type="button" class="ke-op-lightbox-close" id="ke-op-lightbox-close" aria-label="<?php esc_attr_e( 'Close', 'kiwi-events' ); ?>">×</button>
    <button type="button" class="ke-op-lightbox-prev" id="ke-op-lightbox-prev" aria-label="<?php esc_attr_e( 'Previous', 'kiwi-events' ); ?>">‹</button>
    <img class="ke-op-lightbox-img" id="ke-op-lightbox-img" src="" alt="">
    <video class="ke-op-lightbox-video" id="ke-op-lightbox-video" controls playsinline preload="metadata" hidden></video>
    <button type="button" class="ke-op-lightbox-next" id="ke-op-lightbox-next" aria-label="<?php esc_attr_e( 'Next', 'kiwi-events' ); ?>">›</button>
</div>

<?php
get_footer();

/**
 * Renders a single event card. Defined inline so the template stays
 * self-contained — KE_Organizer_Public is the only caller.
 */
function ke_op_render_event_card( $ev, $is_past = false ) {
    $event_timezone = ! empty( $ev['timezone'] ) ? new DateTimeZone( $ev['timezone'] ) : wp_timezone();
    $month         = strtoupper( wp_date( 'M', $ev['start_ts'], $event_timezone ) );
    $day           = wp_date( 'j', $ev['start_ts'], $event_timezone );
    $time          = wp_date( get_option( 'time_format' ), $ev['start_ts'], $event_timezone );

    ob_start();
    ?>
    <a href="<?php echo esc_url( $ev['permalink'] ); ?>"
       class="ke-op-event-card<?php echo $is_past ? ' is-past' : ''; ?>">
        <div class="ke-op-event-thumb">
            <?php if ( $ev['thumb_url'] ) : ?>
                <img src="<?php echo esc_url( $ev['thumb_url'] ); ?>" alt="<?php echo esc_attr( $ev['title'] ); ?>" loading="lazy">
            <?php else : ?>
                <div class="ke-op-event-thumb-fallback" aria-hidden="true">🎟️</div>
            <?php endif; ?>
            <div class="ke-op-event-date">
                <span class="ke-op-event-month"><?php echo esc_html( $month ); ?></span>
                <span class="ke-op-event-day"><?php echo esc_html( $day ); ?></span>
            </div>
        </div>
        <div class="ke-op-event-meta">
            <h3 class="ke-op-event-title"><?php echo esc_html( $ev['title'] ); ?></h3>
            <div class="ke-op-event-sub">
                <span>🕒 <?php echo esc_html( $time ); ?></span>
                <?php if ( $ev['venue'] ) : ?>
                    <span>📍 <?php echo esc_html( $ev['venue'] ); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <?php
    return ob_get_clean();
}
