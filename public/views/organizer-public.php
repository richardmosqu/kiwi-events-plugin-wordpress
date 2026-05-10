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

// Resolve gallery image URLs once, outside the loop, for both grid and lightbox.
$gallery = array();
foreach ( (array) ( $gallery_ids ?? array() ) as $gid ) {
    $gid = (int) $gid;
    if ( ! $gid ) continue;
    $thumb = wp_get_attachment_image_url( $gid, 'medium' );
    $full  = wp_get_attachment_image_url( $gid, 'large' );
    if ( ! $thumb || ! $full ) continue;
    $gallery[] = array( 'thumb' => $thumb, 'full' => $full, 'alt' => get_post_meta( $gid, '_wp_attachment_image_alt', true ) );
}

// Build calendar event payload (subset of fields, JSON-encoded into a data
// attribute the JS reads). Includes both upcoming and past so the calendar
// shows the full lineup.
$cal_events = array();
foreach ( array_merge( (array) $upcoming_events, (array) $past_events ) as $ev ) {
    $cal_events[] = array(
        'id'        => (int) $ev['id'],
        'title'     => (string) $ev['title'],
        'permalink' => (string) $ev['permalink'],
        'start'     => date( 'Y-m-d', (int) $ev['start_ts'] ),
        'end'       => date( 'Y-m-d', (int) $ev['end_ts'] ),
        'thumb'     => (string) $ev['thumb_url'],
    );
}
?>
<div class="ke-org-public" data-organizer-slug="<?php echo esc_attr( $organizer->slug ); ?>">

    <!-- ── HERO ── -->
    <section class="ke-op-hero<?php echo $hero_url ? '' : ' is-no-hero'; ?>"
             <?php if ( $hero_url ) : ?>style="background-image: linear-gradient(180deg, rgba(15,23,42,0) 0%, rgba(15,23,42,0.55) 100%), url('<?php echo esc_url( $hero_url ); ?>');"<?php endif; ?>>
        <div class="ke-op-hero-inner">
            <div class="ke-op-card">
                <div class="ke-op-card-logo">
                    <?php if ( $logo_url ) : ?>
                        <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $organizer->name ); ?>" loading="eager">
                    <?php else : ?>
                        <div class="ke-op-card-logo-fallback" aria-hidden="true">🎪</div>
                    <?php endif; ?>
                </div>
                <h1 class="ke-op-card-name"><?php echo esc_html( $organizer->name ); ?></h1>
                <?php if ( $category_text ) : ?>
                    <div class="ke-op-card-category"><?php echo esc_html( $category_text ); ?></div>
                <?php endif; ?>
                <?php if ( ! empty( $organizer->description ) ) : ?>
                    <p class="ke-op-card-bio"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $organizer->description ), 40, '…' ) ); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- ── TABS ── -->
    <nav class="ke-op-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Profile sections', 'kiwi-events' ); ?>">
        <button type="button" class="ke-op-tab is-active" role="tab" aria-selected="true"
                aria-controls="ke-op-panel-events" data-tab="events">
            <?php esc_html_e( 'Eventos', 'kiwi-events' ); ?>
        </button>
        <button type="button" class="ke-op-tab" role="tab" aria-selected="false"
                aria-controls="ke-op-panel-gallery" data-tab="gallery">
            <?php esc_html_e( 'Galería', 'kiwi-events' ); ?>
        </button>
        <button type="button" class="ke-op-tab" role="tab" aria-selected="false"
                aria-controls="ke-op-panel-calendar" data-tab="calendar">
            <?php esc_html_e( 'Calendario', 'kiwi-events' ); ?>
        </button>
    </nav>

    <div class="ke-op-content">

        <!-- ── EVENTOS ── -->
        <section id="ke-op-panel-events" class="ke-op-panel is-active" role="tabpanel" aria-labelledby="events">
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
        <section id="ke-op-panel-gallery" class="ke-op-panel" role="tabpanel" aria-labelledby="gallery" hidden>
            <?php if ( empty( $gallery ) ) : ?>
                <div class="ke-op-empty">
                    <span class="ke-op-empty-icon">🖼️</span>
                    <p><?php esc_html_e( 'Aún no hay fotos en la galería.', 'kiwi-events' ); ?></p>
                </div>
            <?php else : ?>
                <div class="ke-op-gallery-grid">
                    <?php foreach ( $gallery as $i => $g ) : ?>
                        <button type="button" class="ke-op-gallery-item"
                                data-full="<?php echo esc_attr( $g['full'] ); ?>"
                                data-index="<?php echo $i; ?>"
                                aria-label="<?php esc_attr_e( 'View photo', 'kiwi-events' ); ?>">
                            <img src="<?php echo esc_url( $g['thumb'] ); ?>"
                                 alt="<?php echo esc_attr( $g['alt'] ?: $organizer->name ); ?>"
                                 loading="lazy">
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- ── CALENDARIO ── -->
        <section id="ke-op-panel-calendar" class="ke-op-panel" role="tabpanel" aria-labelledby="calendar" hidden>
            <div class="ke-op-calendar"
                 data-events="<?php echo esc_attr( wp_json_encode( $cal_events ) ); ?>">
                <div class="ke-op-cal-loading"><?php esc_html_e( 'Loading calendar…', 'kiwi-events' ); ?></div>
            </div>
        </section>
    </div>
</div>

<!-- Lightbox (hidden until a gallery item is clicked) -->
<div class="ke-op-lightbox" id="ke-op-lightbox" role="dialog" aria-modal="true" aria-hidden="true" hidden>
    <button type="button" class="ke-op-lightbox-close" id="ke-op-lightbox-close" aria-label="<?php esc_attr_e( 'Close', 'kiwi-events' ); ?>">×</button>
    <button type="button" class="ke-op-lightbox-prev" id="ke-op-lightbox-prev" aria-label="<?php esc_attr_e( 'Previous', 'kiwi-events' ); ?>">‹</button>
    <img class="ke-op-lightbox-img" id="ke-op-lightbox-img" src="" alt="">
    <button type="button" class="ke-op-lightbox-next" id="ke-op-lightbox-next" aria-label="<?php esc_attr_e( 'Next', 'kiwi-events' ); ?>">›</button>
</div>

<?php
get_footer();

/**
 * Renders a single event card. Defined inline so the template stays
 * self-contained — KE_Organizer_Public is the only caller.
 */
function ke_op_render_event_card( $ev, $is_past = false ) {
    $month = strtoupper( wp_date( 'M', $ev['start_ts'] ) );
    $day   = wp_date( 'j', $ev['start_ts'] );
    $time  = wp_date( get_option( 'time_format' ), $ev['start_ts'] );

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
