<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Public-facing organizer profile page.
 *
 * Route: /organizers/{slug}   (note: PLURAL — distinct from the singular
 * /organizer/{slug} dashboard, which is private/password-protected)
 *
 *   - Resolves a `ke_organizer` taxonomy term by slug
 *   - 404s if no such organizer
 *   - Includes public/views/organizer-public.php which renders a public
 *     marketing-style profile: hero image + centered logo/name/category
 *     card, with three tabs (Eventos / Galería / Calendario)
 *
 * Unlike the dashboard, this page is fully public — no auth, no financial
 * data, just the organizer's brand presence and event lineup.
 */
class KE_Organizer_Public {

    const REWRITE_VERSION_OPT = 'ke_organizer_public_rewrite_version';
    const REWRITE_VERSION     = '1.0.0';

    public function init() {
        add_action( 'init',              array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars',        array( $this, 'add_query_vars' ) );
        add_action( 'wp',                array( $this, 'tag_query_state' ), 1 );
        add_action( 'wp',                array( $this, 'force_elementor_frontend' ), 2 );
        add_filter( 'body_class',        array( $this, 'add_body_classes' ) );
        add_action( 'wp_head',           array( $this, 'inject_elementor_config_fallback' ), 1 );
        add_action( 'template_redirect', array( $this, 'handle_request' ) );
    }

    /**
     * Make /organizers/{slug} look like a singular request to the rest
     * of WP. The rewrite rule maps this URL to a query var that doesn't
     * touch a post type, so by default WP_Query lands on `is_404 = true`
     * with no queried object. Elementor Pro's display conditions ("All
     * Pages" / "All Singular") then refuse to match, its enqueue cycle
     * skips localizing `elementorFrontendConfig`, and `elementor-frontend.js`
     * crashes with "elementorFrontendConfig is not defined" — which takes
     * the theme's sticky-header JS and the WooCommerce cart drawer's
     * close handler down with it.
     *
     * Setting these flags BEFORE wp_enqueue_scripts fires (the `wp` action
     * runs immediately after WP_Query is built and before template_redirect)
     * gives Elementor the singular context it expects.
     */
    public function tag_query_state() {
        if ( ! get_query_var( 'ke_organizer_public_slug' ) ) return;

        global $wp_query;
        $wp_query->is_404     = false;
        $wp_query->is_home    = false;
        $wp_query->is_archive = false;
        $wp_query->is_singular = true;
        status_header( 200 );
    }

    public function add_body_classes( $classes ) {
        if ( ! get_query_var( 'ke_organizer_public_slug' ) ) return $classes;
        $classes[] = 'singular';
        $classes[] = 'ke-organizer-public';
        // Elementor's frontend module binds its sticky-header / lightbox /
        // cart-drawer handlers off these specific body classes. The page
        // works without them visually, but `elementor-frontend.js` skips
        // its bootstrap path and `elementorFrontendConfig` is never
        // localized — taking the theme's sticky header and the WC cart
        // drawer down with it.
        $classes[] = 'elementor-default';
        $classes[] = 'elementor-page';
        $classes[] = 'elementor-template-full-width';
        return $classes;
    }

    /**
     * Force Elementor's frontend module to register and enqueue its
     * scripts/styles on this virtual URL.
     *
     * `/organizers/{slug}` resolves via a query var, not a real post,
     * so Elementor's document detector can't find an Elementor-rendered
     * post and bails out of its enqueue cycle. That leaves
     * `elementorFrontendConfig` un-localized; `elementor-frontend.js`
     * then crashes on load and takes other site widgets (sticky header,
     * WooCommerce cart drawer close) down with it.
     *
     * Calling the public register/enqueue methods on the `wp` action
     * (priority 2, after we tag the query state) gives Elementor a
     * known-good singular context and forces the localize step to run
     * before `wp_head`.
     */
    public function force_elementor_frontend() {
        if ( ! get_query_var( 'ke_organizer_public_slug' ) ) return;
        if ( ! class_exists( '\Elementor\Plugin' ) ) return;

        $elementor = \Elementor\Plugin::instance();
        if ( ! $elementor || empty( $elementor->frontend ) ) return;

        $frontend = $elementor->frontend;

        if ( method_exists( $frontend, 'register_styles' ) )  $frontend->register_styles();
        if ( method_exists( $frontend, 'register_scripts' ) ) $frontend->register_scripts();
        if ( method_exists( $frontend, 'enqueue_styles' ) )   $frontend->enqueue_styles();
        if ( method_exists( $frontend, 'enqueue_scripts' ) )  $frontend->enqueue_scripts();
    }

    /**
     * Last-resort safety net: if `force_elementor_frontend()` couldn't
     * get Elementor to enqueue itself (API surface changed, plugin not
     * fully loaded, etc.) but `elementor-frontend` script is still
     * registered (e.g. queued by the theme), manually emit
     * `elementorFrontendConfig` so the script doesn't crash on load.
     *
     * Skips when Elementor's own enqueue succeeded — in that case its
     * `wp_localize_script` call will have already injected the real,
     * fully-populated config object.
     */
    public function inject_elementor_config_fallback() {
        if ( ! get_query_var( 'ke_organizer_public_slug' ) ) return;
        if ( ! class_exists( '\Elementor\Plugin' ) ) return;
        if ( wp_script_is( 'elementor-frontend', 'enqueued' ) ) return;

        $elementor = \Elementor\Plugin::instance();
        if ( ! $elementor || empty( $elementor->frontend ) ) return;
        if ( ! method_exists( $elementor->frontend, 'get_settings' ) ) return;

        $config = $elementor->frontend->get_settings();
        if ( empty( $config ) ) return;

        echo '<script>var elementorFrontendConfig = ' . wp_json_encode( $config ) . ';</script>' . "\n";
    }

    public function add_rewrite_rules() {
        add_rewrite_rule( '^organizers/([^/]+)/?$', 'index.php?ke_organizer_public_slug=$matches[1]', 'top' );
        if ( get_option( self::REWRITE_VERSION_OPT, '' ) !== self::REWRITE_VERSION ) {
            flush_rewrite_rules( false );
            update_option( self::REWRITE_VERSION_OPT, self::REWRITE_VERSION );
        }
    }

    public function add_query_vars( $vars ) {
        $vars[] = 'ke_organizer_public_slug';
        return $vars;
    }

    public function handle_request() {
        $slug = get_query_var( 'ke_organizer_public_slug' );
        if ( ! $slug ) return;

        $term = get_term_by( 'slug', sanitize_title( $slug ), 'ke_organizer' );
        if ( ! $term || is_wp_error( $term ) ) {
            self::send_404();
        }

        // Resolve all the bits the template needs up-front so it stays a
        // dumb renderer.
        $organizer            = $term;
        $logo_id              = (int) get_term_meta( $term->term_id, 'ke_organizer_logo', true );
        $logo_url             = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
        $hero_id              = (int) get_term_meta( $term->term_id, 'ke_organizer_hero_image', true );
        $hero_url             = $hero_id ? wp_get_attachment_image_url( $hero_id, 'full' ) : '';
        $category_text        = (string) get_term_meta( $term->term_id, 'ke_organizer_category', true );
        $gallery_ids          = get_term_meta( $term->term_id, 'ke_organizer_gallery_photo_ids', true );
        $gallery_ids          = is_array( $gallery_ids ) ? array_values( array_filter( array_map( 'intval', $gallery_ids ) ) ) : array();

        list( $upcoming_events, $past_events ) = self::query_events_for_organizer( $term->term_id );

        $this->enqueue_assets( $term, $hero_url );

        // SEO / OG tags injected via wp_head.
        $this->register_seo_meta( $term, $logo_url, $hero_url, $category_text );

        include KE_PLUGIN_DIR . 'public/views/organizer-public.php';
        exit;
    }

    private static function send_404() {
        global $wp_query;
        if ( $wp_query ) {
            $wp_query->set_404();
        }
        status_header( 404 );
        nocache_headers();
        $template = get_404_template();
        if ( $template ) {
            include $template;
        }
        exit;
    }

    /**
     * Split this organizer's published events into upcoming / past based on
     * `_ke_event_date_end` (falling back to `_ke_event_date_start` when end
     * is empty, so single-day events still classify correctly).
     *
     * Returns [ upcoming[], past[] ] each as a list of associative arrays
     * the template can render directly without re-touching post meta.
     */
    private static function query_events_for_organizer( $organizer_term_id ) {
        $q = new WP_Query( array(
            'post_type'      => 'ke_event',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'ke_organizer',
                    'field'    => 'term_id',
                    'terms'    => array( (int) $organizer_term_id ),
                ),
            ),
            'meta_query'     => array(
                array(
                    'key'     => '_ke_event_date_start',
                    'compare' => 'EXISTS',
                ),
            ),
            'orderby'        => 'meta_value',
            'meta_key'       => '_ke_event_date_start',
            'order'          => 'ASC',
        ) );

        $now      = time();
        $upcoming = array();
        $past     = array();

        foreach ( $q->posts as $p ) {
            $start         = get_post_meta( $p->ID, '_ke_event_date_start', true );
            $end           = get_post_meta( $p->ID, '_ke_event_date_end', true );
            $timezone_name = (string) get_post_meta( $p->ID, '_ke_event_timezone', true );
            $timezone_name = $timezone_name ?: wp_timezone_string();
            if ( ! $start ) continue;

            try {
                $event_timezone = new DateTimeZone( $timezone_name );
                $start_dt       = new DateTime( $start, $event_timezone );
                $end_dt         = $end ? new DateTime( $end, $event_timezone ) : $start_dt;
                $start_ts       = $start_dt->getTimestamp();
                $end_ts         = $end_dt->getTimestamp();
            } catch ( Exception $e ) {
                $timezone_name = wp_timezone_string();
                $start_ts      = strtotime( $start );
                $end_ts        = $end ? strtotime( $end ) : $start_ts;
            }
            if ( ! $start_ts ) continue;

            $thumb_id  = get_post_thumbnail_id( $p->ID );
            $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium_large' ) : '';
            $commerce  = self::get_event_commerce_state( $p->ID, $now );

            $row = array(
                'id'         => $p->ID,
                'title'      => get_the_title( $p ),
                'permalink'  => get_permalink( $p ),
                'thumb_url'  => $thumb_url,
                'start_ts'   => $start_ts,
                'end_ts'     => $end_ts ?: $start_ts,
                'start_iso'  => date( 'c', $start_ts ),
                'venue'               => (string) get_post_meta( $p->ID, '_ke_event_venue', true ),
                'timezone'            => $timezone_name,
                'tickets_open'         => ! empty( $commerce['tickets_open'] ),
                'reservations_enabled' => ! empty( $commerce['reservations_enabled'] ),
                'reservations_open'    => ! empty( $commerce['reservations_open'] ),
                'reservations_status'  => (string) $commerce['reservations_status'],
            );

            // Treat the event as past once its end date has passed.
            if ( ( $end_ts ?: $start_ts ) < $now ) {
                $past[] = $row;
            } else {
                $upcoming[] = $row;
            }
        }

        // Past list reads better newest-first.
        usort( $past, function( $a, $b ) { return $b['start_ts'] <=> $a['start_ts']; } );

        return array( $upcoming, $past );
    }

    /**
     * Resolve public commercial actions from current inventory and reservation rules.
     */
    private static function get_event_commerce_state( $event_id, $now_ts ) {
        $state = array(
            'tickets_open'         => false,
            'reservations_enabled' => false,
            'reservations_open'    => false,
            'reservations_status'  => 'unavailable',
        );

        // Scheduled sales gate FIRST. An event whose sale has not opened yet
        // has perfectly valid ticket types with stock left, so the loop below
        // would happily report tickets_open and this page would offer a
        // "Comprar entradas" button that the checkout then refuses. The sale
        // schedule is enforced at three choke points in the purchase path;
        // this profile is a fourth surface and has to respect it too.
        if ( class_exists( 'KE_Sales_Schedule' ) && KE_Sales_Schedule::is_pending( $event_id ) ) {
            return $state;
        }

        if ( class_exists( 'KE_Ticket_Types' ) ) {
            $ticket_types = new KE_Ticket_Types();
            foreach ( (array) $ticket_types->get_available( $event_id ) as $type ) {
                $is_unlimited = ( $type->capacity_type ?? 'limited' ) === 'unlimited';
                $remaining    = $is_unlimited ? PHP_INT_MAX : max( 0, (int) $type->quantity_total - (int) $type->quantity_sold );
                $sales_closed = method_exists( 'KE_Ticket_Types', 'is_sales_closed' )
                    ? KE_Ticket_Types::is_sales_closed( $type )
                    : false;

                if ( ! $sales_closed && ( $is_unlimited || $remaining > 0 ) ) {
                    $state['tickets_open'] = true;
                    break;
                }
            }
        }

        if ( ! class_exists( 'KE_Reservations' ) || ! KE_Reservations::is_active( $event_id ) ) {
            return $state;
        }

        $state['reservations_enabled'] = true;
        $cfg      = KE_Reservations::get_config( $event_id );
        $handler  = new KE_Reservations();
        $capacity = $handler->get_capacity_state( $event_id );
        $open_ts  = ! empty( $cfg['reservations_open'] ) ? strtotime( $cfg['reservations_open'] ) : 0;
        $close_ts = ! empty( $cfg['reservations_close'] ) ? strtotime( $cfg['reservations_close'] ) : 0;
        $booking_now = current_time( 'timestamp' );

        if ( (int) ( $capacity['remaining'] ?? 0 ) <= 0 ) {
            $state['reservations_status'] = 'full';
        } elseif ( $open_ts && $booking_now < $open_ts ) {
            $state['reservations_status'] = 'before';
        } elseif ( $close_ts && $booking_now > $close_ts ) {
            $state['reservations_status'] = 'closed';
        } else {
            $state['reservations_open']   = true;
            $state['reservations_status'] = 'open';
        }

        return $state;
    }

    private function enqueue_assets( $organizer, $hero_url ) {
        // Asset cache-bust suffix, bumped on every change to the two files
        // below — KE_VERSION moves too slowly for this page's churn.
        //   -op3:  hardened lightbox hide() so it only restores body overflow
        //          if we acquired the lock (was clobbering other widgets').
        //   -op10: V4 conversion layout, video gallery, commerce-aware CTAs,
        //          tablist keyboard nav + focus ring.
        //   -op11: Poppins + Campus blue, and the WP.com button guard that
        //          stops #c36 repainting every button on hover.
        //   -op12: normalized against .impeccable.md — every colour through
        //          a token, the four blurred surfaces cut, reduced-motion.
        //   -op13: mobile repair — stale V1 translateY was clipping the card
        //          against the hero's overflow:hidden, tabs lost their dead
        //          96/80px margin, tab counts became real chips, and the CTAs
        //          returned to the card (the fixed buy-bar rendered UNDER the
        //          theme's nav.clnav z-index 99990 and phones had no CTA).
        $ver = KE_VERSION . '-op13';

        // Poppins — the Campus Life face. Enqueued here rather than relying
        // on the theme so the profile keeps its typography even if the theme
        // stops loading it. Same URL the site already requests, so it comes
        // straight from cache. null version: Google serves its own.
        wp_enqueue_style(
            'ke-organizer-poppins',
            'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap',
            array(),
            null
        );

        wp_enqueue_style(
            'ke-organizer-public',
            KE_PLUGIN_URL . 'public/css/ke-organizer-public.css',
            array( 'ke-organizer-poppins' ),
            $ver
        );

        wp_enqueue_script(
            'ke-organizer-public',
            KE_PLUGIN_URL . 'public/js/ke-organizer-public.js',
            array(),
            $ver,
            true
        );

        $ui     = get_option( 'ke_ui_settings', array() );
        $accent = ! empty( $ui['accent_color'] ) ? sanitize_hex_color( $ui['accent_color'] ) : '#6366f1';
        $rgb    = self::hex_to_rgb_parts( $accent );
        $rgbStr = $rgb ? implode( ',', $rgb ) : '99,102,241';

        wp_localize_script( 'ke-organizer-public', 'keOrganizerPublic', array(
            'slug'    => $organizer->slug,
            'name'    => $organizer->name,
            'accent'  => $accent,
            'locale'  => get_locale(),
            'i18n'    => array(
                'months_short' => array( 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic' ),
                'months_full'  => array( 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre' ),
                'weekdays'     => array( 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom' ),
                'no_events'    => __( 'No hay eventos.', 'kiwi-events' ),
                'today'        => __( 'Hoy', 'kiwi-events' ),
                'prev'         => __( 'Anterior', 'kiwi-events' ),
                'next'         => __( 'Siguiente', 'kiwi-events' ),
            ),
        ) );

        $css = '.ke-org-public{'
             . '--kep-accent-1:' . $accent . ';'
             . '--kep-accent-2:' . $accent . ';'
             . '--kep-accent-rgb:' . $rgbStr . ';'
             . '--kep-accent-grad:linear-gradient(135deg,' . $accent . ' 0%,' . $accent . ' 100%);'
             . '}';
        wp_add_inline_style( 'ke-organizer-public', $css );

        // Preload hero image for faster LCP.
        if ( $hero_url ) {
            add_action( 'wp_head', function() use ( $hero_url ) {
                echo '<link rel="preload" as="image" href="' . esc_url( $hero_url ) . '">' . "\n";
            }, 5 );
        }
    }

    private function register_seo_meta( $organizer, $logo_url, $hero_url, $category_text ) {
        $title = $organizer->name . ' — ' . get_bloginfo( 'name' );
        $desc  = $category_text
            ? sprintf( '%s · %s', $organizer->name, $category_text )
            : $organizer->name;
        if ( ! empty( $organizer->description ) ) {
            $desc = wp_strip_all_tags( $organizer->description );
            $desc = wp_trim_words( $desc, 30, '…' );
        }
        $url   = home_url( '/organizers/' . $organizer->slug . '/' );
        $image = $hero_url ?: $logo_url;

        add_filter( 'pre_get_document_title', function() use ( $title ) { return $title; }, 99 );

        add_action( 'wp_head', function() use ( $title, $desc, $url, $image, $organizer ) {
            echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
            echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
            echo '<meta property="og:type" content="profile">' . "\n";
            echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
            echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
            echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
            echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
            if ( $image ) {
                echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
            }
            echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
            echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
            echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
            if ( $image ) {
                echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
            }
        }, 5 );
    }

    private static function hex_to_rgb_parts( $hex ) {
        $hex = ltrim( (string) $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) return null;
        return array(
            (int) hexdec( substr( $hex, 0, 2 ) ),
            (int) hexdec( substr( $hex, 2, 2 ) ),
            (int) hexdec( substr( $hex, 4, 2 ) ),
        );
    }
}
