<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
$autoplay       = ! empty( $atts['autoplay'] ) && $atts['autoplay'] !== 'false' ? 1 : 0;
$interval       = max( 1500, intval( $atts['interval'] ) ?: 5000 );
$show_category  = ! empty( $atts['show_category'] ) && $atts['show_category'] !== 'false';
$show_organizer = ! empty( $atts['show_organizer'] ) && $atts['show_organizer'] !== 'false';
?>
<?php if ( $query->have_posts() ) : ?>
<div class="ke-carousel"
     data-autoplay="<?php echo $autoplay; ?>"
     data-interval="<?php echo $interval; ?>"
     aria-roledescription="carousel">

    <button type="button" class="ke-carousel-arrow ke-carousel-prev" aria-label="Previous" aria-controls="ke-carousel-track">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
    </button>
    <button type="button" class="ke-carousel-arrow ke-carousel-next" aria-label="Next" aria-controls="ke-carousel-track">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
    </button>

    <div class="ke-carousel-track" id="ke-carousel-track" role="group" aria-label="Events">
        <?php $index = 0; while ( $query->have_posts() ) : $query->the_post();
            $index++;
            $event_id     = get_the_ID();
            $title        = get_the_title();
            $permalink    = get_permalink();
            $date_start   = get_post_meta( $event_id, '_ke_event_date_start', true );
            $venue        = get_post_meta( $event_id, '_ke_event_venue', true );
            $is_featured  = (int) get_post_meta( $event_id, '_ke_event_is_featured', true ) === 1;
            $promo_label  = trim( (string) get_post_meta( $event_id, '_ke_event_promo_label', true ) );
            $thumb_id     = get_post_thumbnail_id( $event_id );
            $thumb_url    = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium_large' ) : '';
            $categories   = $show_category ? wp_get_post_terms( $event_id, 'ke_event_category', array( 'fields' => 'names' ) ) : array();
            if ( is_wp_error( $categories ) ) $categories = array();

            $date_pretty = $date_start ? date_i18n( 'D, M j', strtotime( $date_start ) ) : '';
            $date_iso    = $date_start ? date( 'c', strtotime( $date_start ) ) : '';

            $org_name = ''; $org_logo = ''; $org_monogram = '';
            if ( $show_organizer ) {
                $orgs = wp_get_post_terms( $event_id, 'ke_organizer' );
                if ( ! is_wp_error( $orgs ) && ! empty( $orgs ) ) {
                    $org = $orgs[0];
                    $org_name = $org->name;
                    $logo_id  = get_term_meta( $org->term_id, 'ke_organizer_logo', true );
                    if ( $logo_id ) {
                        $org_logo = wp_get_attachment_image_url( $logo_id, 'thumbnail' );
                    }
                    $org_monogram = mb_strtoupper( mb_substr( $org_name, 0, 1 ) );
                }
            }

            $aria_label = sprintf( 'View event: %s%s', $title, $date_pretty ? ' on ' . $date_pretty : '' );
            $preload    = $index <= 3;
            $promo_text = $promo_label !== '' ? $promo_label : 'RECOMENDADO';
        ?>
        <article class="ke-carousel-card" aria-roledescription="slide">
            <a class="ke-carousel-link" href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php echo esc_attr( $aria_label ); ?>">
                <div class="ke-carousel-poster-wrap">
                    <?php if ( $thumb_url ) : ?>
                        <img class="ke-carousel-poster"
                             src="<?php echo esc_url( $thumb_url ); ?>"
                             alt=""
                             <?php echo $preload ? 'fetchpriority="high"' : 'loading="lazy"'; ?>
                             decoding="async">
                    <?php else : ?>
                        <div class="ke-carousel-poster ke-carousel-poster-empty" aria-hidden="true">
                            <span>🎟</span>
                        </div>
                    <?php endif; ?>
                    <div class="ke-carousel-gradient" aria-hidden="true"></div>
                </div>

                <div class="ke-carousel-top">
                    <?php if ( ! empty( $categories ) ) : ?>
                        <div class="ke-carousel-cats">
                            <?php foreach ( array_slice( $categories, 0, 2 ) as $cat_name ) : ?>
                                <span class="ke-cat-pill"><?php echo esc_html( $cat_name ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <span></span>
                    <?php endif; ?>

                    <?php if ( $is_featured ) : ?>
                        <span class="ke-promo-pill"><?php echo esc_html( $promo_text ); ?></span>
                    <?php endif; ?>
                </div>

                <div class="ke-carousel-meta">
                    <?php if ( $date_start ) : ?>
                        <div class="ke-carousel-meta-row">
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
                        <div class="ke-carousel-meta-row">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                            <span class="ke-carousel-venue"><?php echo esc_html( $venue ); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ( $org_name ) : ?>
                        <div class="ke-carousel-meta-row ke-carousel-org">
                            <?php if ( $org_logo ) : ?>
                                <img class="ke-org-avatar" src="<?php echo esc_url( $org_logo ); ?>" alt="" loading="lazy">
                            <?php else : ?>
                                <span class="ke-org-avatar ke-org-avatar-mono"><?php echo esc_html( $org_monogram ); ?></span>
                            <?php endif; ?>
                            <span class="ke-org-name"><?php echo esc_html( $org_name ); ?></span>
                        </div>
                    <?php endif; ?>

                    <h3 class="ke-carousel-title"><?php echo esc_html( $title ); ?></h3>
                </div>
            </a>
        </article>
        <?php endwhile; wp_reset_postdata(); ?>
    </div>

    <div class="ke-carousel-dots" role="tablist" aria-label="Carousel pagination"></div>
</div>
<?php else : ?>
    <div class="ke-carousel-empty">
        <span class="ke-carousel-empty-icon" aria-hidden="true">📅</span>
        <p class="ke-carousel-empty-text">No upcoming events right now. Check back soon!</p>
        <?php
        $archive_url = get_post_type_archive_link( 'ke_event' );
        if ( $archive_url ) : ?>
            <a class="ke-carousel-empty-link" href="<?php echo esc_url( $archive_url ); ?>">Browse all events →</a>
        <?php endif; ?>
    </div>
<?php endif; ?>
