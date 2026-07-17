<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * [kiwi_board] — public carousel of approved board activities.
 * Consumes: $events (WP_Post[], already filtered+sliced), $threshold (int),
 * $atts (create_url for the empty-state CTA).
 */
$ke_bc_uid = 'ke-board-track-' . wp_unique_id();
?>
<div class="ke-board">
<?php if ( empty( $events ) ) : ?>

    <div class="ke-board-empty">
        <span class="ke-board-empty__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 9h18M8 13h5M8 16h8"/></svg>
        </span>
        <p>Aún no hay actividades en el board.</p>
        <?php if ( ! empty( $atts['create_url'] ) ) : ?>
            <a class="ke-board-btn ke-board-btn--primary" href="<?php echo esc_url( $atts['create_url'] ); ?>">Publica la primera</a>
        <?php endif; ?>
    </div>

<?php else : ?>

    <div class="ke-board-carousel" data-ke-board-carousel>
        <button type="button" class="ke-board-arrow ke-board-arrow--prev" aria-label="Anterior" aria-controls="<?php echo esc_attr( $ke_bc_uid ); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        </button>
        <button type="button" class="ke-board-arrow ke-board-arrow--next" aria-label="Siguiente" aria-controls="<?php echo esc_attr( $ke_bc_uid ); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
        </button>

        <div class="ke-board-track" id="<?php echo esc_attr( $ke_bc_uid ); ?>" role="group" aria-label="Actividades del board">
            <?php foreach ( $events as $ke_bc_post ) :
                $ke_bc_id    = $ke_bc_post->ID;
                $ke_bc_thumb = get_the_post_thumbnail_url( $ke_bc_id, 'medium_large' );
                $ke_bc_likes = KE_Board::get_like_count( $ke_bc_id );
                $ke_bc_comm  = (int) get_comments_number( $ke_bc_id );
            ?>
            <article class="ke-board-card">
                <a class="ke-board-card__link" href="<?php echo esc_url( get_permalink( $ke_bc_id ) ); ?>" aria-label="<?php echo esc_attr( get_the_title( $ke_bc_id ) ); ?>">
                    <span class="ke-board-card__media">
                        <?php if ( $ke_bc_thumb ) : ?>
                            <img src="<?php echo esc_url( $ke_bc_thumb ); ?>" alt="" loading="lazy">
                        <?php else : ?>
                            <span class="ke-board-card__media-empty" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="m3 15 4.5-4.5a2 2 0 0 1 2.8 0L16 16"/><circle cx="15.5" cy="9.5" r="1.5"/></svg>
                            </span>
                        <?php endif; ?>
                        <?php if ( KE_Board::is_trending( $ke_bc_id ) ) : ?>
                            <span class="ke-board-trending">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 17 6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>
                                Tendencia
                            </span>
                        <?php endif; ?>
                    </span>
                    <span class="ke-board-card__body">
                        <span class="ke-board-card__title"><?php echo esc_html( get_the_title( $ke_bc_id ) ); ?></span>
                        <span class="ke-board-card__meta">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg>
                            <?php echo esc_html( KE_Board::format_datetime( $ke_bc_id ) ); ?>
                        </span>
                        <?php $ke_bc_loc = get_post_meta( $ke_bc_id, '_ke_board_location', true ); ?>
                        <?php if ( $ke_bc_loc ) : ?>
                            <span class="ke-board-card__meta ke-board-card__meta--loc">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.1-7-11a7 7 0 0 1 14 0c0 5.9-7 11-7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                                <span class="ke-board-card__loc-text"><?php echo esc_html( $ke_bc_loc ); ?></span>
                            </span>
                        <?php endif; ?>
                        <span class="ke-board-card__stats">
                            <span class="ke-board-card__stat">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 14c1.5-1.5 3-3.3 3-5.5A4.5 4.5 0 0 0 17.5 4c-1.8 0-3.4 1-4.5 2.5A5.4 5.4 0 0 0 8.5 4 4.5 4.5 0 0 0 4 8.5c0 2.2 1.5 4 3 5.5l5 5Z"/></svg>
                                <?php echo esc_html( number_format_i18n( $ke_bc_likes ) ); ?>
                            </span>
                            <span class="ke-board-card__stat">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5Z"/></svg>
                                <?php echo esc_html( number_format_i18n( $ke_bc_comm ) ); ?>
                            </span>
                        </span>
                    </span>
                </a>
            </article>
            <?php endforeach; ?>
        </div>

        <div class="ke-board-dots" role="tablist" aria-label="Páginas del carrusel"></div>
    </div>

<?php endif; ?>
</div>
