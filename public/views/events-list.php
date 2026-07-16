<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/**
 * Vertical-stack event list. Reuses the same data fields as the carousel
 * card but stacks single-column at any width. Markup mirrors the archive
 * grid card so theme tokens (.ke-event-card, .ke-event-card-meta, etc.)
 * resolve from the shared ke-public.css stylesheet; the list-specific
 * wrapper + tweaks live in ke-events-list.css.
 */
?>
<?php if ( $query->have_posts() ) : ?>
    <div class="ke-events-list">
        <?php while ( $query->have_posts() ) : $query->the_post();
            $event_id    = get_the_ID();
            $title       = get_the_title();
            $permalink   = get_permalink();
            $date_start  = get_post_meta( $event_id, '_ke_event_date_start', true );
            $venue       = get_post_meta( $event_id, '_ke_event_venue', true );
            $thumbnail   = get_the_post_thumbnail_url( $event_id, 'medium_large' );
            $categories  = wp_get_post_terms( $event_id, 'ke_event_category', array( 'fields' => 'names' ) );
            if ( is_wp_error( $categories ) ) $categories = array();

            $date_pretty = $date_start ? date_i18n( 'D, M j • g:i A', strtotime( $date_start ) ) : '';
            $date_iso    = $date_start ? date( 'c', strtotime( $date_start ) ) : '';
        ?>
            <article class="ke-event-card ke-events-list-card">
                <a class="ke-events-list-card-link" href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php echo esc_attr( sprintf( 'View event: %s', $title ) ); ?>">
                    <div class="ke-events-list-poster-wrap">
                        <?php if ( $thumbnail ) : ?>
                            <img class="ke-events-list-poster" src="<?php echo esc_url( $thumbnail ); ?>" alt="" loading="lazy" decoding="async">
                        <?php else : ?>
                            <div class="ke-events-list-poster ke-events-list-poster-empty" aria-hidden="true">📅</div>
                        <?php endif; ?>
                    </div>

                    <div class="ke-events-list-body">
                        <?php if ( ! empty( $categories ) ) : ?>
                            <div class="ke-events-list-cats">
                                <?php foreach ( array_slice( $categories, 0, 2 ) as $cat_name ) : ?>
                                    <span class="ke-events-list-cat-pill"><?php echo esc_html( $cat_name ); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <h3 class="ke-events-list-title"><?php echo esc_html( $title ); ?></h3>

                        <div class="ke-events-list-meta">
                            <?php if ( $date_start ) : ?>
                                <div class="ke-events-list-meta-row">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                    </svg>
                                    <time datetime="<?php echo esc_attr( $date_iso ); ?>"><?php echo esc_html( $date_pretty ); ?></time>
                                </div>
                            <?php endif; ?>

                            <?php if ( $venue ) : ?>
                                <div class="ke-events-list-meta-row">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                        <circle cx="12" cy="10" r="3"></circle>
                                    </svg>
                                    <span><?php echo esc_html( $venue ); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ke-events-list-cta" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </div>
                </a>
            </article>
        <?php endwhile; wp_reset_postdata(); ?>
    </div>
<?php else : ?>
    <div class="ke-events-list-empty">
        <span class="ke-events-list-empty-icon" aria-hidden="true">📅</span>
        <p>No upcoming events right now. Check back soon!</p>
    </div>
<?php endif; ?>
