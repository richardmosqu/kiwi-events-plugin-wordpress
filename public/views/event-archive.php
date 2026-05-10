<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php if ( $query->have_posts() ) : ?>
    <div class="ke-events-grid" style="--ke-cols: <?php echo intval( $atts['columns'] ); ?>;">
        <?php while ( $query->have_posts() ) : $query->the_post(); ?>
            <?php
            $event_id    = get_the_ID();
            $date_start  = get_post_meta( $event_id, '_ke_event_date_start', true );
            $venue       = get_post_meta( $event_id, '_ke_event_venue', true );
            $status      = get_post_meta( $event_id, '_ke_event_status', true );
            $categories  = wp_get_post_terms( $event_id, 'ke_event_category', array( 'fields' => 'names' ) );
            $thumbnail   = get_the_post_thumbnail_url( $event_id, 'medium_large' );

            // Get cheapest ticket price
            $tt = new KE_Ticket_Types();
            $available = $tt->get_available( $event_id );
            $min_price = null;
            foreach ( $available as $type ) {
                if ( $min_price === null || $type->price < $min_price ) {
                    $min_price = $type->price;
                }
            }

            // Reservation availability — shown in place of (or alongside)
            // ticket pricing for events that use reservations.
            $card_resv_active = class_exists( 'KE_Reservations' ) && KE_Reservations::is_active( $event_id );
            $card_resv_remaining = null;
            if ( $card_resv_active ) {
                try {
                    $card_resv_state    = ( new KE_Reservations() )->get_capacity_state( $event_id );
                    $card_resv_remaining = isset( $card_resv_state['remaining'] ) ? (int) $card_resv_state['remaining'] : null;
                } catch ( \Throwable $e ) {
                    // If state fetch fails, fall back to a generic label rather
                    // than breaking the whole grid.
                    $card_resv_remaining = null;
                }
            }
            ?>
            <article class="ke-event-card">
                <div style="position: relative;">
                    <?php if ( $thumbnail ) : ?>
                        <img src="<?php echo esc_url( $thumbnail ); ?>" alt="<?php the_title_attribute(); ?>" class="ke-event-card-image">
                    <?php else : ?>
                        <div class="ke-event-card-image-placeholder">📅</div>
                    <?php endif; ?>

                    <?php if ( ! empty( $categories ) ) : ?>
                        <div style="position: absolute; top: 12px; left: 12px; display: flex; gap: 6px; flex-wrap: wrap; z-index: 10;">
                            <?php foreach ( array_slice($categories, 0, 2) as $cat_name ) : ?>
                                <span style="background: rgba(255, 255, 255, 0.25); border: 1px solid rgba(255, 255, 255, 0.4); color: #ffffff; font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 4px 10px; border-radius: 20px; backdrop-filter: blur(16px) saturate(180%); -webkit-backdrop-filter: blur(16px) saturate(180%); box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); letter-spacing: 0.5px; text-shadow: 0 1px 2px rgba(0,0,0,0.2);">
                                    <?php echo esc_html( $cat_name ); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="ke-event-card-body">

                    <h3 class="ke-event-card-title">
                        <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                    </h3>

                    <div class="ke-event-card-meta">
                        <?php if ( $date_start ) : ?>
                            <div class="ke-event-card-meta-item">
                                <span class="ke-event-card-meta-icon">📅</span>
                                <?php echo esc_html( date( 'M j, Y • g:i A', strtotime( $date_start ) ) ); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ( $venue ) : ?>
                            <div class="ke-event-card-meta-item">
                                <span class="ke-event-card-meta-icon">📍</span>
                                <?php echo esc_html( $venue ); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="ke-event-card-footer">
                        <span class="ke-event-card-price">
                            <?php if ( $min_price !== null ) : ?>
                                <?php echo $min_price > 0 ? '$' . number_format( $min_price, 2 ) : 'Free'; ?>
                                <small><?php
                                    if ( $min_price > 0 && $card_resv_active )      echo ' and up · reservations open';
                                    elseif ( $min_price > 0 )                       echo ' and up';
                                    elseif ( $card_resv_active )                    echo ' · reservations open';
                                ?></small>
                            <?php elseif ( $card_resv_active ) : ?>
                                <?php if ( $card_resv_remaining !== null && $card_resv_remaining > 0 ) : ?>
                                    <span class="ke-resv-availability"><?php echo esc_html( number_format_i18n( $card_resv_remaining ) ); ?> spots available</span>
                                <?php elseif ( $card_resv_remaining === 0 ) : ?>
                                    <span class="ke-sold-out">Fully booked</span>
                                <?php else : ?>
                                    <span class="ke-resv-availability">Reservations open</span>
                                <?php endif; ?>
                            <?php elseif ( empty( $available ) ) : ?>
                                <span class="ke-sold-out">Sold Out</span>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </span>
                        <a href="<?php the_permalink(); ?>" class="ke-btn">View Event</a>
                    </div>
                </div>
            </article>
        <?php endwhile; wp_reset_postdata(); ?>
    </div>
<?php else : ?>
    <div class="ke-empty">
        <span class="ke-empty-icon">📅</span>
        <p>No upcoming events found.</p>
    </div>
<?php endif; ?>
