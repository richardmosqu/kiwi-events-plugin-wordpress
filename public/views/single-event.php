<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
get_header();

$event_id    = $post->ID;

// Fetch tickets
$ticket_types = new KE_Ticket_Types();
$types = $ticket_types->get_available( $event_id );
$date_start  = get_post_meta( $event_id, '_ke_event_date_start', true );
$date_end    = get_post_meta( $event_id, '_ke_event_date_end', true );
$timezone    = get_post_meta( $event_id, '_ke_event_timezone', true ) ?: wp_timezone_string();
$venue       = get_post_meta( $event_id, '_ke_event_venue', true );
$address     = get_post_meta( $event_id, '_ke_event_address', true );
$location_type = get_post_meta( $event_id, '_ke_event_location_type', true ) ?: 'venue';
$virtual_url = get_post_meta( $event_id, '_ke_event_virtual_url', true );
$status      = get_post_meta( $event_id, '_ke_event_status', true ) ?: 'active';
$thumbnail   = get_the_post_thumbnail_url( $event_id, 'large' );
$maps_embed_raw = get_post_meta( $event_id, '_ke_event_maps_embed', true );
// Normalize legacy/shortcode values stored before the save-time fix
if ( $maps_embed_raw && stripos( $maps_embed_raw, '<iframe' ) === false ) {
    if ( preg_match( '/\[googlemaps\s+(https?:\/\/[^\]]+)\]/i', $maps_embed_raw, $_m ) ) {
        $maps_embed_raw = '<iframe src="' . esc_url( trim( $_m[1] ) ) . '" '
                        . 'width="100%" height="450" style="border:0;" '
                        . 'allowfullscreen="" loading="lazy" '
                        . 'referrerpolicy="no-referrer-when-downgrade"></iframe>';
    } elseif ( preg_match( '/^https?:\/\/(www\.)?google\.[a-z.]{2,6}\/maps/i', $maps_embed_raw ) ) {
        $maps_embed_raw = '<iframe src="' . esc_url( $maps_embed_raw ) . '" '
                        . 'width="100%" height="450" style="border:0;" '
                        . 'allowfullscreen="" loading="lazy" '
                        . 'referrerpolicy="no-referrer-when-downgrade"></iframe>';
    } else {
        $maps_embed_raw = ''; // unrecognised format — don't render anything
    }
}
$maps_embed = $maps_embed_raw;

// Get organizer
$organizer_terms = wp_get_post_terms( $event_id, 'ke_organizer' );
$organizer       = ! empty( $organizer_terms ) && ! is_wp_error( $organizer_terms ) ? $organizer_terms[0] : null;
$organizer_name  = $organizer ? $organizer->name : '';
$organizer_logo_id  = $organizer ? get_term_meta( $organizer->term_id, 'ke_organizer_logo', true ) : '';
$organizer_logo_url = $organizer_logo_id ? wp_get_attachment_image_url( $organizer_logo_id, 'thumbnail' ) : '';

// Get categories
$categories = wp_get_post_terms( $event_id, 'ke_event_category', array( 'fields' => 'names' ) );

// Format date
$tz_abbr = '';
if ( $date_start ) {
    try {
        $dt = new DateTime( $date_start, new DateTimeZone( $timezone ) );
        $formatted_day  = $dt->format( 'l, j M' );
        $formatted_time = $dt->format( 'g:i A' );
        $tz_abbr = $dt->format( 'T' );
    } catch ( Exception $e ) {
        $formatted_day  = date( 'l, j M', strtotime( $date_start ) );
        $formatted_time = date( 'g:i A', strtotime( $date_start ) );
    }
} else {
    $formatted_day  = 'Date TBA';
    $formatted_time = '';
}

// Location text
$location_text = $venue ?: 'Location TBA';
$location_sub  = $address ?: '';
if ( $location_type === 'virtual' ) {
    $location_text = 'Online Event';
    $location_sub  = '';
} elseif ( $location_type === 'hybrid' ) {
    $location_sub  = $address ? $address . ' + Online' : 'In-person + Online';
}

// Allowed tags for maps embed output
$allowed_iframe = array(
    'iframe' => array(
        'src'             => true,
        'width'           => true,
        'height'          => true,
        'frameborder'     => true,
        'style'           => true,
        'allowfullscreen' => true,
        'loading'         => true,
        'referrerpolicy'  => true,
        'title'           => true,
    ),
);
?>

<div class="ke-event-page ke-event-page-breakout">

    <!-- ═══════════════ HERO ═══════════════ -->
    <div class="ke-hero"<?php if ( $thumbnail ) echo ' style="--hero-image: url(' . esc_url( $thumbnail ) . ')"'; ?>>

        <div class="ke-hero-bg"></div>

        <div class="ke-hero-body">
            <div class="ke-hero-inner">

                <!-- Left: Poster / Flyer -->
                <div class="ke-hero-poster-wrap">
                    <?php if ( $thumbnail ) : ?>
                        <div class="ke-hero-poster">
                            <img class="ke-hero-poster-img"
                                 src="<?php echo esc_url( $thumbnail ); ?>"
                                 alt="<?php echo esc_attr( $post->post_title ); ?>"
                                 loading="eager">
                        </div>
                    <?php else : ?>
                        <div class="ke-hero-poster ke-hero-poster-placeholder">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="opacity:0.35">
                                <rect x="3" y="3" width="18" height="18" rx="3"/>
                                <circle cx="8.5" cy="8.5" r="1.5"/>
                                <polyline points="21 15 16 10 5 21"/>
                            </svg>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Right: Event info -->
                <div class="ke-hero-info">

                    <!-- Category pills -->
                    <?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
                        <div class="ke-hero-cats">
                            <?php foreach ( $categories as $cat_name ) : ?>
                                <span class="ke-cat-pill"><?php echo esc_html( $cat_name ); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Organizer pill -->
                    <?php if ( $organizer ) : ?>
                        <div class="ke-hero-organizer">
                            <?php if ( $organizer_logo_url ) : ?>
                                <img src="<?php echo esc_url( $organizer_logo_url ); ?>"
                                     alt="<?php echo esc_attr( $organizer_name ); ?>"
                                     class="ke-org-avatar">
                            <?php else : ?>
                                <div class="ke-org-avatar-placeholder">🎪</div>
                            <?php endif; ?>
                            <span class="ke-org-text">
                                By <span class="ke-org-name-strong"><?php echo esc_html( $organizer_name ); ?></span>
                            </span>
                        </div>
                    <?php endif; ?>

                    <!-- Title -->
                    <h1 class="ke-hero-title"><?php echo esc_html( $post->post_title ); ?></h1>

                    <!-- Meta pills -->
                    <div class="ke-hero-meta">
                        <div class="ke-meta-item">
                            <svg class="ke-meta-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="1.5" y="2.5" width="13" height="12" rx="2"/>
                                <path d="M1.5 6.5h13M5.5 1v3M10.5 1v3"/>
                            </svg>
                            <div>
                                <div class="ke-meta-primary"><?php echo esc_html( $formatted_day ); ?></div>
                                <?php if ( $formatted_time ) : ?>
                                    <div class="ke-meta-secondary"><?php echo esc_html( $formatted_time . ' ' . $tz_abbr ); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="ke-meta-item">
                            <?php if ( $location_type === 'virtual' ) : ?>
                                <svg class="ke-meta-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="8" cy="8" r="6.5"/>
                                    <path d="M1.5 8h13M8 1.5c-2 2-3 4-3 6.5s1 4.5 3 6.5M8 1.5c2 2 3 4 3 6.5s-1 4.5-3 6.5"/>
                                </svg>
                            <?php else : ?>
                                <svg class="ke-meta-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M8 1.5C5.515 1.5 3.5 3.515 3.5 6c0 3.5 4.5 8.5 4.5 8.5S12.5 9.5 12.5 6c0-2.485-2.015-4.5-4.5-4.5z"/>
                                    <circle cx="8" cy="6" r="1.5"/>
                                </svg>
                            <?php endif; ?>
                            <div>
                                <div class="ke-meta-primary"><?php echo esc_html( $location_text ); ?></div>
                                <?php if ( $location_sub ) : ?>
                                    <div class="ke-meta-secondary"><?php echo esc_html( $location_sub ); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div><!-- /.ke-hero-info -->

            </div><!-- /.ke-hero-inner -->
        </div><!-- /.ke-hero-body -->

    </div><!-- /.ke-hero -->

    <!-- ═══════════════ CONTENT BODY ═══════════════ -->
    <div class="ke-event-body">

        <!-- TICKETS (first — primary interaction) -->
        <?php if ( ! empty( $types ) && $status === 'active' ) : ?>
            <div class="ke-content-section" id="ke-tickets-section">
                <p class="ke-section-label">Tickets</p>
                <h2 class="ke-section-title">Choose Your Ticket</h2>

                <div class="ke-tickets-list">
                    <?php foreach ( $types as $type ) :
                        $is_unlimited   = ( $type->capacity_type ?? 'limited' ) === 'unlimited';
                        $remaining      = $is_unlimited ? 9999 : max( 0, $type->quantity_total - $type->quantity_sold );
                        $is_sold_out    = ! $is_unlimited && $remaining <= 0;
                        $is_free        = floatval( $type->price ) == 0;
                        $show_remaining = ( $type->show_remaining ?? 'yes' ) === 'yes';
                    ?>
                        <?php
                            $cf_raw = $type->custom_fields ?? '';
                            $cf_arr = $cf_raw ? json_decode( $cf_raw, true ) : array();
                            $cf_json = esc_attr( wp_json_encode( is_array( $cf_arr ) ? $cf_arr : array() ) );
                        ?>
                        <button type="button"
                                class="ke-ticket<?php echo $is_sold_out ? ' ke-ticket--sold-out' : ''; ?>"
                                data-ticket-type-id="<?php echo esc_attr( $type->id ); ?>"
                                data-name="<?php echo esc_attr( $type->name ); ?>"
                                data-price="<?php echo esc_attr( $type->price ); ?>"
                                data-remaining="<?php echo esc_attr( $remaining ); ?>"
                                data-min="<?php echo esc_attr( $type->min_per_order ?? 1 ); ?>"
                                data-max="<?php echo esc_attr( $type->max_per_order ?? 10 ); ?>"
                                data-description="<?php echo esc_attr( $type->description ?? '' ); ?>"
                                data-custom-fields="<?php echo $cf_json; ?>"
                                <?php echo $is_sold_out ? 'disabled' : ''; ?>>

                            <div class="ke-ticket-inner">
                                <div class="ke-ticket-left">
                                    <?php if ( $is_free && ! $is_sold_out ) : ?>
                                        <span class="ke-ticket-badge ke-ticket-badge--free">Free</span>
                                    <?php endif; ?>
                                    <span class="ke-ticket-name"><?php echo esc_html( $type->name ); ?></span>
                                    <?php if ( ! empty( $type->description ) ) : ?>
                                        <span class="ke-ticket-desc"><?php echo esc_html( $type->description ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( ! $is_sold_out && ! $is_unlimited && $show_remaining && $remaining <= 20 ) : ?>
                                        <span class="ke-ticket-scarcity"><?php echo absint( $remaining ); ?> remaining</span>
                                    <?php endif; ?>
                                </div>

                                <div class="ke-ticket-right">
                                    <?php if ( $is_sold_out ) : ?>
                                        <span class="ke-ticket-soldout-label">Sold Out</span>
                                    <?php elseif ( $is_free ) : ?>
                                        <span class="ke-ticket-price-free">Free</span>
                                    <?php else : ?>
                                        <span class="ke-ticket-price">$<?php echo number_format( floatval( $type->price ), 2 ); ?></span>
                                    <?php endif; ?>

                                    <?php if ( ! $is_sold_out ) : ?>
                                        <span class="ke-ticket-arrow">
                                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M2.5 7h9M7.5 3l4 4-4 4"/>
                                            </svg>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php elseif ( $status !== 'active' ) : ?>
            <div class="ke-content-section">
                <div class="ke-status-banner ke-status-<?php echo esc_attr( $status ); ?>">
                    This event is currently <?php echo esc_html( $status ); ?>.
                </div>
            </div>
        <?php endif; ?>

        <!-- ABOUT (below tickets) -->
        <?php if ( $post->post_content ) : ?>
            <div class="ke-content-section ke-section-divider">
                <p class="ke-section-label">About</p>
                <h2 class="ke-section-title">The Event</h2>
                <div class="ke-about-text">
                    <?php echo wp_kses_post( wpautop( $post->post_content ) ); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- GOOGLE MAPS (below About, only if embed is provided) -->
        <?php if ( ! empty( $maps_embed ) ) : ?>
            <div class="ke-content-section ke-map-section">
                <p class="ke-section-label">Location</p>
                <h2 class="ke-section-title">Find Us</h2>
                <div class="ke-map-embed">
                    <?php echo wp_kses( $maps_embed, $allowed_iframe ); ?>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- /.ke-event-body -->

</div><!-- /.ke-event-page -->

<!-- ═══════════════ BOTTOM SHEET CHECKOUT ═══════════════ -->
<div class="ke-sheet-overlay" id="ke-sheet-overlay"></div>
<div class="ke-sheet" id="ke-checkout-sheet" data-event-id="<?php echo esc_attr( $event_id ); ?>">
    <div class="ke-sheet-handle"></div>
    <button type="button" class="ke-sheet-close" id="ke-sheet-close-btn" aria-label="Close">
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <line x1="2" y1="2" x2="12" y2="12"/>
            <line x1="12" y1="2" x2="2" y2="12"/>
        </svg>
    </button>

    <!-- Step 1: Quantity -->
    <div class="ke-sheet-step" id="ke-sheet-step-qty">

        <div class="ke-sheet-header">
            <div class="ke-sheet-title" id="ke-sheet-ticket-name"></div>
            <div class="ke-sheet-subtitle">Select the number of tickets</div>
        </div>

        <div class="ke-sheet-body">
            <div class="ke-sheet-qty-row">
                <div>
                    <div class="ke-sheet-qty-label">Quantity</div>
                </div>
                <div class="ke-stepper">
                    <button type="button" class="ke-stepper-btn" id="ke-sheet-minus">−</button>
                    <span class="ke-stepper-val" id="ke-sheet-qty-val">1</span>
                    <button type="button" class="ke-stepper-btn" id="ke-sheet-plus">+</button>
                </div>
            </div>

            <div class="ke-sheet-breakdown">
                <div class="ke-breakdown-row">
                    <span id="ke-breakdown-label">$0 × 1 ticket</span>
                    <span id="ke-breakdown-subtotal">$0</span>
                </div>
                <div class="ke-breakdown-row" id="ke-fee-row" style="display:none;">
                    <span id="ke-fee-label">Service Fee</span>
                    <span id="ke-fee-amount">$0.00</span>
                </div>
                <div class="ke-breakdown-row">
                    <span class="ke-breakdown-total">Total</span>
                    <strong class="ke-breakdown-total-amount" id="ke-sheet-total-val">$0.00</strong>
                </div>
            </div>
        </div>

        <div class="ke-sheet-footer">
            <button type="button" class="ke-sheet-btn ke-sheet-btn-primary" id="ke-sheet-continue">
                Continue
            </button>
        </div>

    </div><!-- /#ke-sheet-step-qty -->

    <!-- Step 2: Attendee Details -->
    <div class="ke-sheet-step" id="ke-sheet-step-details" style="display:none;">

        <div class="ke-sheet-header">
            <div class="ke-sheet-title">Your Details</div>
            <div class="ke-sheet-subtitle">We'll send your tickets to this email</div>
        </div>

        <div class="ke-sheet-body">
            <div class="ke-sheet-msg" id="ke-sheet-message"></div>
            <form id="ke-checkout-form-sheet">
                <div id="ke-sheet-attendees-container"></div>

                <div class="ke-sheet-breakdown">
                    <div class="ke-breakdown-row" id="ke-fee-row-2" style="display:none;">
                        <span id="ke-fee-label-2">Service Fee</span>
                        <span id="ke-fee-amount-2">$0.00</span>
                    </div>
                    <div class="ke-breakdown-row">
                        <span class="ke-breakdown-total">Total</span>
                        <strong class="ke-breakdown-total-amount" id="ke-sheet-total-val-2">$0.00</strong>
                    </div>
                </div>
            </form>
        </div>

        <div class="ke-sheet-footer">
            <button type="submit" form="ke-checkout-form-sheet" class="ke-sheet-btn ke-sheet-btn-primary" id="ke-sheet-submit">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M1.5 8h13M8.5 3l5 5-5 5"/>
                </svg>
                Get Tickets
            </button>
            <p class="ke-sheet-terms">By continuing you agree to our Terms of Service and Privacy Policy</p>
        </div>

    </div><!-- /#ke-sheet-step-details -->

</div><!-- /#ke-checkout-sheet -->

<?php get_footer(); ?>
