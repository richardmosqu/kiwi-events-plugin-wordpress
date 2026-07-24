<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
get_header();

$event_id    = $post->ID;

// Fetch tickets
$ticket_types = new KE_Ticket_Types();
$types = $ticket_types->get_available( $event_id );

// Reservations config — only render the section + sheet when the event
// actually has reservations enabled. Capacity/areas live on the config;
// the live "X seats left" snapshot is computed below for the section UI.
$resv_active = class_exists( 'KE_Reservations' ) && KE_Reservations::is_active( $event_id );
$resv_cfg    = $resv_active ? KE_Reservations::get_config( $event_id ) : null;
$resv_state  = null;
$resv_xfields = array();
if ( $resv_active ) {
    $resv_handler = new KE_Reservations();
    $resv_state   = $resv_handler->get_capacity_state( $event_id );
    if ( class_exists( 'KE_Event_Extra_Fields' ) ) {
        $resv_xfields = KE_Event_Extra_Fields::get_fields_for( $event_id, 'reservations' );
    }
}
$date_start  = get_post_meta( $event_id, '_ke_event_date_start', true );
$date_end    = get_post_meta( $event_id, '_ke_event_date_end', true );
$timezone    = get_post_meta( $event_id, '_ke_event_timezone', true ) ?: wp_timezone_string();
$venue       = get_post_meta( $event_id, '_ke_event_venue', true );
$address     = get_post_meta( $event_id, '_ke_event_address', true );
$location_type = get_post_meta( $event_id, '_ke_event_location_type', true ) ?: 'venue';
$virtual_url = get_post_meta( $event_id, '_ke_event_virtual_url', true );
$status      = get_post_meta( $event_id, '_ke_event_status', true ) ?: 'active';
$thumbnail   = get_the_post_thumbnail_url( $event_id, 'large' );
// Optional per-event hero background image (separate from the poster). When
// set, it renders sharp behind the hero with a fixed darkening gradient; when
// empty the hero keeps its gradient + blurred-poster ambient (no regression).
$hero_bg_id  = (int) get_post_meta( $event_id, '_ke_event_hero_bg_id', true );
$hero_bg_url = $hero_bg_id ? wp_get_attachment_image_url( $hero_bg_id, 'ke_hero_bg' ) : '';
if ( ! $hero_bg_url && $hero_bg_id ) { $hero_bg_url = wp_get_attachment_image_url( $hero_bg_id, 'large' ); }
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

// Social links
$social_instagram = get_post_meta( $event_id, '_ke_social_instagram', true );
$social_whatsapp  = get_post_meta( $event_id, '_ke_social_whatsapp',  true );
$social_website   = get_post_meta( $event_id, '_ke_social_website',   true );
$social_facebook  = get_post_meta( $event_id, '_ke_social_facebook',  true );

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

    <?php
    // Promoter attribution badge — event-scoped: only renders when this
    // specific event has an attributed promoter for the visitor.
    if ( class_exists( 'KE_Promoter_Visible' ) ) {
        echo KE_Promoter_Visible::badge_html( $event_id ); // already-escaped, includes scoped CSS
    }
    ?>

    <!-- ═══════════════ HERO ═══════════════ -->
    <div class="ke-hero<?php echo $hero_bg_url ? ' has-custom-bg' : ''; ?>"<?php
        $ke_hero_style = '';
        if ( $thumbnail )   { $ke_hero_style .= '--hero-image: url(' . esc_url( $thumbnail ) . ');'; }
        if ( $hero_bg_url ) { $ke_hero_style .= '--hero-bg-image: url(' . esc_url( $hero_bg_url ) . ');'; }
        if ( $ke_hero_style ) { echo ' style="' . $ke_hero_style . '"'; }
    ?>>

        <div class="ke-hero-bg"></div>
        <?php if ( $hero_bg_url ) : ?><div class="ke-hero-custombg" aria-hidden="true"></div><?php endif; ?>

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

                    <?php
                    $social_links = array_filter( array(
                        'instagram' => $social_instagram,
                        'whatsapp'  => $social_whatsapp,
                        'website'   => $social_website,
                        'facebook'  => $social_facebook,
                    ) );
                    if ( ! empty( $social_links ) ) : ?>
                    <div class="ke-hero-socials">
                        <?php if ( $social_instagram ) : ?>
                        <a href="<?php echo esc_url( $social_instagram ); ?>" target="_blank" rel="noopener"
                           class="ke-social-pill" aria-label="Instagram">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="2" y="2" width="20" height="20" rx="5" ry="5"/>
                                <circle cx="12" cy="12" r="4"/>
                                <circle cx="17.5" cy="6.5" r="0.5" fill="currentColor" stroke="none"/>
                            </svg>
                            Instagram
                        </a>
                        <?php endif; ?>
                        <?php if ( $social_whatsapp ) : ?>
                        <a href="<?php echo esc_url( $social_whatsapp ); ?>" target="_blank" rel="noopener"
                           class="ke-social-pill" aria-label="WhatsApp">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/>
                            </svg>
                            WhatsApp
                        </a>
                        <?php endif; ?>
                        <?php if ( $social_facebook ) : ?>
                        <a href="<?php echo esc_url( $social_facebook ); ?>" target="_blank" rel="noopener"
                           class="ke-social-pill" aria-label="Facebook">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/>
                            </svg>
                            Facebook
                        </a>
                        <?php endif; ?>
                        <?php if ( $social_website ) : ?>
                        <a href="<?php echo esc_url( $social_website ); ?>" target="_blank" rel="noopener"
                           class="ke-social-pill" aria-label="Website">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M2 12h20M12 2c-2.5 2.5-4 5.7-4 10s1.5 7.5 4 10M12 2c2.5 2.5 4 5.7 4 10s-1.5 7.5-4 10"/>
                            </svg>
                            Website
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php
                    $share_url   = urlencode( get_permalink() );
                    $share_title = urlencode( get_the_title() );
                    $permalink   = get_permalink();
                    ?>
                    <div class="ke-hero-share">
                        <a href="https://wa.me/?text=<?php echo $share_title; ?>%20<?php echo $share_url; ?>"
                           target="_blank" rel="noopener" class="ke-share-pill" aria-label="<?php esc_attr_e( 'Compartir por WhatsApp', 'kiwi-events' ); ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                            </svg>
                        </a>

                        <button type="button" class="ke-share-pill ke-share-native"
                                data-share-url="<?php echo esc_attr( $permalink ); ?>"
                                data-share-title="<?php echo esc_attr( get_the_title() ); ?>"
                                aria-label="<?php esc_attr_e( 'Compartir', 'kiwi-events' ); ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
                            </svg>
                        </button>

                        <span class="ke-share-toast" role="status" aria-live="polite"><?php esc_html_e( 'Enlace copiado ✓', 'kiwi-events' ); ?></span>
                    </div>
                    <script>
                    (function () {
                        var btn = document.querySelector('.ke-share-native');
                        if (!btn) return;
                        var toast = document.querySelector('.ke-share-toast');
                        var url   = btn.getAttribute('data-share-url') || location.href;
                        var title = btn.getAttribute('data-share-title') || document.title;
                        var toastTimer;
                        function showToast() {
                            if (!toast) return;
                            toast.classList.add('is-visible');
                            clearTimeout(toastTimer);
                            toastTimer = setTimeout(function () {
                                toast.classList.remove('is-visible');
                            }, 2200);
                        }
                        function legacyCopy() {
                            try {
                                var ta = document.createElement('textarea');
                                ta.value = url; ta.setAttribute('readonly', '');
                                ta.style.position = 'absolute'; ta.style.left = '-9999px';
                                document.body.appendChild(ta); ta.select();
                                document.execCommand('copy');
                                document.body.removeChild(ta);
                                showToast();
                            } catch (e) {}
                        }
                        function copyFallback() {
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(url).then(showToast).catch(legacyCopy);
                            } else {
                                legacyCopy();
                            }
                        }
                        btn.addEventListener('click', function () {
                            if (navigator.share) {
                                navigator.share({ title: title, text: title, url: url }).catch(function (err) {
                                    // User cancelled → do nothing; real failure → copy fallback.
                                    if (err && err.name !== 'AbortError') { copyFallback(); }
                                });
                            } else {
                                copyFallback();
                            }
                        });
                    })();
                    </script>

                    <?php
                    // ─── Historias Destacadas — resolve enabled highlights ───
                    // Intersect the event's stored selection with the CURRENT
                    // organizer's highlights so an organizer change or a deleted
                    // highlight simply drops out (no empty circle, no error).
                    $ke_hl_cards  = array();
                    $ke_hl_frames = array();
                    $ke_hl_names  = array();
                    if ( class_exists( 'KE_Highlights' ) && get_post_meta( $event_id, '_ke_event_show_highlights', true ) === '1' ) {
                        $ke_org_terms = wp_get_object_terms( $event_id, 'ke_organizer', array( 'fields' => 'ids' ) );
                        $ke_org_id    = ( ! is_wp_error( $ke_org_terms ) && ! empty( $ke_org_terms ) ) ? (int) $ke_org_terms[0] : 0;
                        if ( $ke_org_id ) {
                            $ke_owned_ids = array_map( function ( $p ) { return (int) $p->ID; }, KE_Highlights::get_for_organizer( $ke_org_id ) );
                            $ke_sel       = get_post_meta( $event_id, '_ke_event_highlights', true );
                            if ( $ke_sel === 'all' ) {
                                $ke_show_ids = $ke_owned_ids;
                            } elseif ( is_array( $ke_sel ) ) {
                                $ke_show_ids = array_values( array_intersect( array_map( 'intval', $ke_sel ), $ke_owned_ids ) );
                            } else {
                                $ke_show_ids = array();
                            }
                            foreach ( $ke_show_ids as $ke_hid ) {
                                $ke_card = KE_Highlights::to_card( $ke_hid );
                                if ( ! $ke_card || $ke_card['image_count'] < 1 ) continue;
                                $ke_frames = KE_Highlights::frames_payload( $ke_hid );
                                if ( empty( $ke_frames ) ) continue;
                                $ke_hl_cards[]           = $ke_card;
                                $ke_hl_frames[ $ke_hid ] = $ke_frames;
                                $ke_hl_names[ $ke_hid ]  = $ke_card['name'];
                            }
                        }
                    }
                    ?>
                    <?php if ( ! empty( $ke_hl_cards ) ) : ?>
                    <div class="ke-hl-row" role="list" aria-label="<?php esc_attr_e( 'Historias destacadas', 'kiwi-events' ); ?>">
                        <?php foreach ( $ke_hl_cards as $ke_card ) : ?>
                        <button type="button" class="ke-hl-bubble" role="listitem" data-hl-id="<?php echo (int) $ke_card['id']; ?>" aria-label="<?php echo esc_attr( $ke_card['name'] ); ?>">
                            <span class="ke-hl-bubble-ring">
                                <span class="ke-hl-bubble-img"<?php if ( $ke_card['cover'] ) echo ' style="background-image:url(' . esc_url( $ke_card['cover'] ) . ')"'; ?>></span>
                            </span>
                            <span class="ke-hl-bubble-name"><?php echo esc_html( $ke_card['name'] ); ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <script type="application/json" id="ke-hl-data"><?php
                        // JSON_HEX_TAG escapes < and > so an organizer-submitted
                        // highlight name containing "</script>" can't break out.
                        echo wp_json_encode( array( 'names' => $ke_hl_names, 'frames' => $ke_hl_frames ), JSON_HEX_TAG | JSON_HEX_AMP );
                    ?></script>
                    <script>
                    (function () {
                        var dataEl = document.getElementById('ke-hl-data');
                        if (!dataEl) return;
                        var DATA; try { DATA = JSON.parse(dataEl.textContent || '{}'); } catch (e) { return; }
                        var FRAMES = DATA.frames || {}, NAMES = DATA.names || {};
                        var bubbles = Array.prototype.slice.call(document.querySelectorAll('.ke-hl-bubble'));
                        if (!bubbles.length) return;

                        var overlay, progressWrap, imgEl, nameEl, lastFocus = null;
                        var cur = { frames: [], idx: 0 };

                        function build() {
                            overlay = document.createElement('div');
                            overlay.className = 'ke-hlv';
                            overlay.setAttribute('role', 'dialog');
                            overlay.setAttribute('aria-modal', 'true');
                            overlay.setAttribute('aria-label', 'Historia');
                            overlay.hidden = true;
                            overlay.innerHTML =
                                '<div class="ke-hlv-progress" aria-hidden="true"></div>' +
                                '<div class="ke-hlv-top"><span class="ke-hlv-name"></span>' +
                                '<button type="button" class="ke-hlv-close" aria-label="Cerrar">×</button></div>' +
                                '<div class="ke-hlv-stage">' +
                                    '<img class="ke-hlv-img" alt="">' +
                                    '<button type="button" class="ke-hlv-nav ke-hlv-prev" aria-label="Anterior"></button>' +
                                    '<button type="button" class="ke-hlv-nav ke-hlv-next" aria-label="Siguiente"></button>' +
                                '</div>';
                            document.body.appendChild(overlay);
                            progressWrap = overlay.querySelector('.ke-hlv-progress');
                            imgEl = overlay.querySelector('.ke-hlv-img');
                            nameEl = overlay.querySelector('.ke-hlv-name');
                            overlay.querySelector('.ke-hlv-close').addEventListener('click', close);
                            overlay.querySelector('.ke-hlv-prev').addEventListener('click', function (e) { e.stopPropagation(); prev(); });
                            overlay.querySelector('.ke-hlv-next').addEventListener('click', function (e) { e.stopPropagation(); next(); });
                            var stage = overlay.querySelector('.ke-hlv-stage');
                            var sx = 0, sy = 0, dx = 0, dy = 0, touching = false;
                            stage.addEventListener('touchstart', function (e) { var t = e.changedTouches[0]; sx = t.clientX; sy = t.clientY; dx = dy = 0; touching = true; }, { passive: true });
                            stage.addEventListener('touchmove', function (e) { if (!touching) return; var t = e.changedTouches[0]; dx = t.clientX - sx; dy = t.clientY - sy; }, { passive: true });
                            stage.addEventListener('touchend', function () {
                                if (!touching) return; touching = false;
                                if (Math.abs(dy) > 80 && Math.abs(dy) > Math.abs(dx)) { if (dy > 0) close(); return; }
                                if (Math.abs(dx) > 50) { if (dx < 0) next(); else prev(); }
                            });
                        }

                        function renderProgress() {
                            var h = '';
                            for (var i = 0; i < cur.frames.length; i++) { h += '<span class="ke-hlv-seg' + (i <= cur.idx ? ' is-on' : '') + '"></span>'; }
                            progressWrap.innerHTML = h;
                        }
                        function preload(i) { if (i >= 0 && i < cur.frames.length) { var im = new Image(); im.src = cur.frames[i].url; } }
                        function show() { var f = cur.frames[cur.idx]; if (!f) return; imgEl.src = f.url; renderProgress(); preload(cur.idx + 1); }
                        function next() { if (cur.idx < cur.frames.length - 1) { cur.idx++; show(); } else { close(); } }
                        function prev() { if (cur.idx > 0) { cur.idx--; show(); } }

                        function open(id, trigger) {
                            var frames = FRAMES[id];
                            if (!frames || !frames.length) return;
                            if (!overlay) build();
                            lastFocus = trigger || document.activeElement;
                            cur = { frames: frames, idx: 0 };
                            nameEl.textContent = NAMES[id] || '';
                            overlay.hidden = false;
                            document.body.classList.add('ke-hlv-lock');
                            show();
                            overlay.querySelector('.ke-hlv-close').focus();
                            document.addEventListener('keydown', onKey);
                        }
                        function close() {
                            if (!overlay) return;
                            overlay.hidden = true;
                            imgEl.removeAttribute('src');
                            document.body.classList.remove('ke-hlv-lock');
                            document.removeEventListener('keydown', onKey);
                            if (lastFocus && lastFocus.focus) lastFocus.focus();
                        }
                        function onKey(e) {
                            if (e.key === 'Escape') close();
                            else if (e.key === 'ArrowRight') next();
                            else if (e.key === 'ArrowLeft') prev();
                            else if (e.key === 'Tab') { e.preventDefault(); overlay.querySelector('.ke-hlv-close').focus(); } // trap
                        }
                        bubbles.forEach(function (b) { b.addEventListener('click', function () { open(parseInt(b.getAttribute('data-hl-id'), 10), b); }); });
                    })();
                    </script>
                    <?php endif; ?>

                </div><!-- /.ke-hero-info -->

            </div><!-- /.ke-hero-inner -->
        </div><!-- /.ke-hero-body -->

    </div><!-- /.ke-hero -->

    <!-- ═══════════════ CONTENT BODY ═══════════════ -->
    <div class="ke-event-body">

        <?php
        // Tri-mode booking-area gating.
        //   has_tickets       — at least one published ticket type.
        //   $resv_active      — reservations enabled in event config (set above).
        //   has_booking_area  — anything to show in the tickets/reservations zone.
        // When status !== 'active' we skip both and only render the status
        // banner. When status === 'active' but neither is configured we show
        // a single "not currently accepting bookings" line — this lets organizers
        // publish an info-only event page without the booking area looking
        // like a missing/broken section.
        $has_tickets      = ! empty( $types );
        $has_booking_area = $has_tickets || $resv_active;
        ?>

        <!-- TICKETS (first — primary interaction) -->
        <?php if ( $has_tickets && $status === 'active' ) : ?>
            <div class="ke-content-section" id="ke-tickets-section">
                <p class="ke-section-label">Tickets</p>
                <h2 class="ke-section-title">Choose Your Ticket</h2>

                <div class="ke-tickets-list">
                    <?php foreach ( $types as $type ) :
                        $is_unlimited   = ( $type->capacity_type ?? 'limited' ) === 'unlimited';
                        $remaining      = $is_unlimited ? 9999 : max( 0, $type->quantity_total - $type->quantity_sold );
                        // Treat a passed sale_end cutoff as sold-out so the
                        // existing visual state + disabled handling already in
                        // this template covers cutoff-closed types without
                        // adding a parallel UI path.
                        $is_sales_closed = KE_Ticket_Types::is_sales_closed( $type );
                        $is_sold_out    = ( ! $is_unlimited && $remaining <= 0 ) || $is_sales_closed;
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
        <?php elseif ( ! $has_booking_area ) : ?>
            <div class="ke-content-section">
                <div class="ke-status-banner ke-status-info">
                    This event is not currently accepting bookings.
                </div>
            </div>
        <?php endif; ?>

        <!-- RESERVATIONS (group/table bookings — runs alongside tickets) -->
        <?php if ( $resv_active && $status === 'active' ) :
            $rmode      = $resv_cfg['confirmation_mode'] ?? 'auto';
            $rtotal     = (int) ( $resv_state['total'] ?? 0 );
            $rremaining = (int) ( $resv_state['remaining'] ?? 0 );
            $rfull      = $rremaining <= 0;
            // Booking window: hide the form (but show a hint) when closed.
            $now_ts     = current_time( 'timestamp' );
            $r_open_ts  = ! empty( $resv_cfg['reservations_open'] )  ? strtotime( $resv_cfg['reservations_open'] )  : 0;
            $r_close_ts = ! empty( $resv_cfg['reservations_close'] ) ? strtotime( $resv_cfg['reservations_close'] ) : 0;
            $r_window_state = 'open';
            if ( $r_open_ts && $now_ts < $r_open_ts )       $r_window_state = 'before';
            elseif ( $r_close_ts && $now_ts > $r_close_ts ) $r_window_state = 'after';
            // When tickets render above us, add a top divider so the two
            // booking modes are visually distinct rather than blending.
            $resv_section_classes = 'ke-content-section ke-reservations-section';
            if ( $has_tickets ) {
                $resv_section_classes .= ' ke-section-divider';
            }
        ?>
            <div class="<?php echo esc_attr( $resv_section_classes ); ?>" id="ke-reservations-section">
                <p class="ke-section-label">Reservations</p>
                <h2 class="ke-section-title">Reserve a Table</h2>
                <?php
                $resv_module_desc = isset( $resv_cfg['description'] ) ? trim( (string) $resv_cfg['description'] ) : '';
                $show_total_cap   = ! isset( $resv_cfg['show_total_capacity'] ) || ! empty( $resv_cfg['show_total_capacity'] );
                if ( $resv_module_desc !== '' ) :
                ?>
                    <p class="ke-resv-description"><?php echo esc_html( $resv_module_desc ); ?></p>
                <?php endif; ?>

                <div class="ke-resv-card<?php echo $rfull ? ' ke-resv-card--full' : ''; ?>">

                    <div class="ke-resv-card-row">
                        <div class="ke-resv-card-meta">
                            <?php if ( $rmode === 'manual' ) : ?>
                                <span class="ke-resv-pill ke-resv-pill--manual">Approval required</span>
                            <?php else : ?>
                                <span class="ke-resv-pill ke-resv-pill--auto">Instant confirmation</span>
                            <?php endif; ?>
                            <?php if ( ! empty( $resv_cfg['areas'] ) ) : ?>
                                <span class="ke-resv-pill"><?php echo count( $resv_cfg['areas'] ); ?> area<?php echo count( $resv_cfg['areas'] ) === 1 ? '' : 's'; ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if ( $rtotal > 0 && $show_total_cap ) : ?>
                            <div class="ke-resv-card-capacity" data-resv-remaining-display>
                                <?php if ( $rfull ) : ?>
                                    <span class="ke-resv-capacity-num">Fully booked</span>
                                <?php else : ?>
                                    <span class="ke-resv-capacity-num"><?php echo esc_html( number_format_i18n( $rremaining ) ); ?></span>
                                    <span class="ke-resv-capacity-sub">of <?php echo esc_html( number_format_i18n( $rtotal ) ); ?> spots left</span>
                                <?php endif; ?>
                            </div>
                        <?php elseif ( $rfull ) : ?>
                            <div class="ke-resv-card-capacity" data-resv-remaining-display>
                                <span class="ke-resv-capacity-num">Fully booked</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ( $r_window_state === 'before' ) : ?>
                        <p class="ke-resv-hint">Reservations open <?php echo esc_html( date_i18n( 'l, M j \a\t g:i A', $r_open_ts ) ); ?>.</p>
                    <?php elseif ( $r_window_state === 'after' ) : ?>
                        <p class="ke-resv-hint">Reservations are closed for this event.</p>
                    <?php elseif ( $rfull ) : ?>
                        <p class="ke-resv-hint">All spots are currently held. Check back later — reservations may free up.</p>
                    <?php endif; ?>

                    <button type="button"
                            class="ke-resv-cta"
                            id="ke-resv-open-btn"
                            <?php echo ( $rfull || $r_window_state !== 'open' ) ? 'disabled' : ''; ?>>
                        <?php if ( $r_window_state === 'before' ) : ?>
                            Not yet open
                        <?php elseif ( $r_window_state === 'after' ) : ?>
                            Reservations closed
                        <?php elseif ( $rfull ) : ?>
                            Fully booked
                        <?php else : ?>
                            <?php echo $rmode === 'manual' ? 'Request a Reservation' : 'Reserve Now'; ?>
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M2.5 7h9M7.5 3l4 4-4 4"/>
                            </svg>
                        <?php endif; ?>
                    </button>
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

        <!-- EVENT EXTRAS -->
        <?php
        $extras = get_post_meta( $event_id, '_ke_event_extras', true );
        if ( is_array( $extras ) ) {
            // Testimonials always render last, regardless of stored order.
            usort( $extras, function( $a, $b ) {
                $ta = ( $a['type'] ?? '' ) === 'testimonials' ? 1 : 0;
                $tb = ( $b['type'] ?? '' ) === 'testimonials' ? 1 : 0;
                return $ta - $tb;
            } );
            foreach ( $extras as $extra ) {
                if ( empty( $extra['enabled'] ) || empty( $extra['type'] ) ) continue;
                $type_slug = str_replace( '_', '-', preg_replace( '/[^a-z0-9_]/', '', $extra['type'] ) );
                $template  = KE_PLUGIN_DIR . 'public/views/extras/' . $type_slug . '.php';
                if ( file_exists( $template ) ) {
                    $extra_config = is_array( $extra['config'] ?? null ) ? $extra['config'] : array();
                    include $template; // has access to $extra, $extra_config, $event_id, $types
                }
            }
        }
        ?>

    </div><!-- /.ke-event-body -->

</div><!-- /.ke-event-page -->

<!-- ═══════════════ BOTTOM SHEET CHECKOUT ═══════════════ -->
<div class="ke-sheet-overlay" id="ke-sheet-overlay"></div>
<div class="ke-sheet" id="ke-checkout-sheet" data-event-id="<?php echo esc_attr( $event_id ); ?>">
    <div class="ke-sheet-handle"></div>
    <button type="button" class="ke-sheet-close" id="ke-sheet-close-btn" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <line x1="6" y1="6" x2="18" y2="18"/>
            <line x1="6" y1="18" x2="18" y2="6"/>
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

    <!-- Step: Login Required -->
    <div class="ke-sheet-step ke-sheet-step-blocked" id="ke-sheet-step-blocked" style="display:none;">

        <div class="ke-sheet-header">
            <div class="ke-sheet-title" id="ke-sheet-blocked-title"><?php esc_html_e( 'Sign in to continue', 'kiwi-events' ); ?></div>
            <div class="ke-sheet-subtitle" id="ke-sheet-blocked-ticket"></div>
        </div>

        <div class="ke-sheet-body">
            <div class="ke-sheet-blocked-icon" aria-hidden="true">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </div>
            <p class="ke-sheet-blocked-msg" id="ke-sheet-blocked-msg"></p>
        </div>

        <div class="ke-sheet-footer">
            <a href="#" class="ke-sheet-btn ke-sheet-btn-primary" id="ke-sheet-login-btn">
                <?php esc_html_e( 'Log in', 'kiwi-events' ); ?>
            </a>
            <a href="#" class="ke-sheet-btn ke-sheet-btn-secondary" id="ke-sheet-register-btn">
                <?php esc_html_e( 'Create account', 'kiwi-events' ); ?>
            </a>
        </div>

    </div><!-- /#ke-sheet-step-blocked -->

</div><!-- /#ke-checkout-sheet -->

<?php if ( $resv_active ) :
    // Encode config so the JS can drive the form (areas list, optional
    // fields, default party size, capacity caps). The capacity snapshot
    // is also re-fetched on sheet-open via REST so a stale page render
    // doesn't lock the user out when seats free up.
    $resv_xfields_payload = array();
    foreach ( $resv_xfields as $f ) {
        $resv_xfields_payload[] = array(
            'id'       => $f['id'],
            'label'    => $f['label'],
            'helper'   => $f['helper'],
            'type'     => $f['type'],
            'required' => ! empty( $f['required'] ),
            'options'  => isset( $f['options'] ) ? array_values( (array) $f['options'] ) : array(),
        );
    }
    $resv_js = wp_json_encode( array(
        'eventId'             => (int) $event_id,
        'enabled'             => true,
        'description'         => isset( $resv_cfg['description'] ) ? (string) $resv_cfg['description'] : '',
        'totalCapacity'       => (int) $resv_cfg['total_capacity'],
        'showTotalCapacity'   => ! isset( $resv_cfg['show_total_capacity'] ) || ! empty( $resv_cfg['show_total_capacity'] ),
        'showAreaCapacity'    => ! isset( $resv_cfg['show_area_capacity'] )  || ! empty( $resv_cfg['show_area_capacity'] ),
        'mode'                => $resv_cfg['confirmation_mode'],
        'showEmailField'      => ! empty( $resv_cfg['show_email_field'] ),
        'showNotesField'      => ! empty( $resv_cfg['show_notes_field'] ),
        'areas'               => array_map( function( $a ) {
            $effect = isset( $a['fancy_effect'] ) ? (string) $a['fancy_effect'] : 'none';
            return array(
                'name'        => (string) $a['name'],
                'description' => isset( $a['description'] ) ? (string) $a['description'] : '',
                'capacity'    => (int) $a['capacity'],
                'fancyEffect' => $effect,
            );
        }, $resv_cfg['areas'] ?? array() ),
        'extraFields'         => $resv_xfields_payload,
        'reservationsOpen'    => $resv_cfg['reservations_open'],
        'reservationsClose'   => $resv_cfg['reservations_close'],
    ) );
?>
<!-- ═══════════════ RESERVATIONS BOTTOM SHEET ═══════════════ -->
<script>
window.kePublicResv = <?php echo $resv_js; ?>;
</script>
<div class="ke-sheet-overlay" id="ke-resv-overlay"></div>
<div class="ke-sheet ke-resv-sheet" id="ke-resv-sheet" data-event-id="<?php echo esc_attr( $event_id ); ?>">
    <div class="ke-sheet-handle"></div>
    <button type="button" class="ke-sheet-close" id="ke-resv-close-btn" aria-label="Close">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <line x1="6" y1="6" x2="18" y2="18"/>
            <line x1="6" y1="18" x2="18" y2="6"/>
        </svg>
    </button>

    <!-- Step 1: Party + arrival + area -->
    <div class="ke-sheet-step" id="ke-resv-step-party">
        <div class="ke-sheet-header">
            <div class="ke-sheet-title">Reservation Details</div>
            <div class="ke-sheet-subtitle" id="ke-resv-availability-line">Choose your party size and arrival time</div>
        </div>

        <div class="ke-sheet-body">
            <div class="ke-sheet-msg" id="ke-resv-msg-1"></div>

            <div class="ke-sheet-field">
                <label class="ke-field-label" for="ke-resv-party">How many people?</label>
                <div class="ke-sheet-qty-row">
                    <div>
                        <div class="ke-sheet-qty-label">Party size</div>
                    </div>
                    <div class="ke-stepper">
                        <button type="button" class="ke-stepper-btn" id="ke-resv-party-minus">−</button>
                        <span class="ke-stepper-val" id="ke-resv-party-val">2</span>
                        <button type="button" class="ke-stepper-btn" id="ke-resv-party-plus">+</button>
                    </div>
                </div>
                <input type="hidden" id="ke-resv-party" value="2">
            </div>

            <div class="ke-sheet-field">
                <label class="ke-field-label" for="ke-resv-arrival">When will you arrive?</label>
                <input type="datetime-local" id="ke-resv-arrival" required>
            </div>

            <div class="ke-sheet-field" id="ke-resv-areas-wrap" style="display:none;">
                <label class="ke-field-label">Area</label>
                <div class="ke-resv-areas" id="ke-resv-areas-grid"></div>
            </div>
        </div>

        <div class="ke-sheet-footer">
            <button type="button" class="ke-sheet-btn ke-sheet-btn-primary" id="ke-resv-continue">
                Continue
            </button>
        </div>
    </div><!-- /#ke-resv-step-party -->

    <!-- Step 2: Contact details + extras -->
    <div class="ke-sheet-step" id="ke-resv-step-contact" style="display:none;">
        <div class="ke-sheet-header">
            <div class="ke-sheet-title">Your Details</div>
            <div class="ke-sheet-subtitle">We&rsquo;ll use this to confirm your reservation</div>
        </div>

        <div class="ke-sheet-body">
            <div class="ke-sheet-msg" id="ke-resv-msg-2"></div>
            <form id="ke-resv-form">
                <div class="ke-sheet-attendee">
                    <div class="ke-sheet-attendee-header">Reservation Holder</div>

                    <div class="ke-sheet-field">
                        <label class="ke-field-label" for="ke-resv-name">Full name <span class="ke-required">*</span></label>
                        <input type="text" id="ke-resv-name" required autocomplete="name" placeholder="John Doe">
                    </div>

                    <div class="ke-sheet-field">
                        <label class="ke-field-label" for="ke-resv-phone">Phone <span class="ke-required">*</span></label>
                        <input type="tel" id="ke-resv-phone" required autocomplete="tel" placeholder="+507 6000-0000">
                    </div>

                    <?php if ( ! empty( $resv_cfg['show_email_field'] ) ) : ?>
                    <div class="ke-sheet-field">
                        <label class="ke-field-label" for="ke-resv-email">Email <span class="ke-required">*</span></label>
                        <input type="email" id="ke-resv-email" required autocomplete="email" placeholder="john@example.com">
                    </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $resv_cfg['show_notes_field'] ) ) : ?>
                    <div class="ke-sheet-field">
                        <label class="ke-field-label" for="ke-resv-notes">Special requests <span class="ke-optional">(optional)</span></label>
                        <textarea id="ke-resv-notes" rows="3" placeholder="Birthday, accessibility needs, dietary, etc."></textarea>
                    </div>
                    <?php endif; ?>

                    <div id="ke-resv-extras-container"></div>
                </div>
            </form>
        </div>

        <div class="ke-sheet-footer">
            <button type="submit" form="ke-resv-form" class="ke-sheet-btn ke-sheet-btn-primary" id="ke-resv-submit">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M1.5 8h13M8.5 3l5 5-5 5"/>
                </svg>
                <span class="ke-resv-submit-label"><?php echo $resv_cfg['confirmation_mode'] === 'manual' ? 'Submit Request' : 'Confirm Reservation'; ?></span>
            </button>
            <p class="ke-sheet-terms">By continuing you agree to our Terms of Service and Privacy Policy</p>
        </div>
    </div><!-- /#ke-resv-step-contact -->

    <!-- Step 3: Success -->
    <div class="ke-sheet-step" id="ke-resv-step-success" style="display:none;">
        <div class="ke-sheet-body ke-resv-success">
            <div class="ke-resv-success-icon" aria-hidden="true">
                <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M8 12.5l3 3 5-6"/>
                </svg>
            </div>
            <h3 class="ke-resv-success-title" id="ke-resv-success-title">Reservation confirmed!</h3>
            <p class="ke-resv-success-msg" id="ke-resv-success-msg"></p>
            <div class="ke-resv-success-code">
                <span class="ke-resv-code-label">Reservation code</span>
                <span class="ke-resv-code-value" id="ke-resv-success-code"></span>
            </div>
        </div>
        <div class="ke-sheet-footer">
            <button type="button" class="ke-sheet-btn ke-sheet-btn-primary" id="ke-resv-done-btn">Done</button>
        </div>
    </div><!-- /#ke-resv-step-success -->

</div><!-- /#ke-resv-sheet -->
<?php endif; ?>

<?php get_footer(); ?>
