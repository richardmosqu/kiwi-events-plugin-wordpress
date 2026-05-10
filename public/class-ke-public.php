<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Public-facing functionality — shortcodes and frontend assets
 */
class KE_Public {

    public function init() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Register shortcodes
        add_shortcode( 'kiwi_events', array( $this, 'shortcode_events' ) );
        add_shortcode( 'kiwi_event', array( $this, 'shortcode_single_event' ) );
        add_shortcode( 'kiwi_checkout', array( $this, 'shortcode_checkout' ) );

        // Override the entire template for single ke_event posts
        add_filter( 'single_template', array( $this, 'override_single_template' ) );

        // Serve the standalone ticket view page at /ticket/{code}
        add_action( 'template_redirect', array( $this, 'maybe_render_ticket_view' ) );
    }

    /**
     * Intercept /ticket/{code} requests and serve the ticket view.
     * Validates the ticket exists before including the template so that
     * an unknown code gets a clean 404 rather than a blank page.
     */
    public function maybe_render_ticket_view() {
        $ticket_code = get_query_var( 'ke_ticket_code' );
        if ( ! $ticket_code ) {
            return;
        }

        $tickets_handler = new KE_Tickets();
        $ticket          = $tickets_handler->get_by_code( sanitize_text_field( $ticket_code ) );

        if ( ! $ticket ) {
            wp_die(
                'Ticket not found.',
                'Invalid Ticket',
                array( 'response' => 404 )
            );
        }

        include KE_PLUGIN_DIR . 'public/views/ticket-view.php';
        exit;
    }

    /**
     * Enqueue public CSS and JS
     */
    public function enqueue_assets() {
        // Ensure jQuery is available — some themes deregister WP core jQuery
        wp_enqueue_script( 'jquery' );

        wp_enqueue_style(
            'ke-public-css',
            KE_PLUGIN_URL . 'public/css/ke-public.css',
            array(),
            KE_VERSION
        );

        wp_enqueue_script(
            'ke-checkout-js',
            KE_PLUGIN_URL . 'public/js/ke-checkout.js',
            array( 'jquery' ),
            KE_VERSION,
            true
        );

        // Reservations sheet — depends on kePublic globals from ke-checkout-js
        // (restUrl/nonce/access) so it loads after.
        wp_enqueue_script(
            'ke-reservations-js',
            KE_PLUGIN_URL . 'public/js/ke-reservations.js',
            array( 'jquery', 'ke-checkout-js' ),
            KE_VERSION,
            true
        );

        $ui           = get_option( 'ke_ui_settings', array() );
        $accent_color = ! empty( $ui['accent_color'] ) ? sanitize_hex_color( $ui['accent_color'] ) : '';

        $access  = KE_REST_API::get_access_settings();
        $current = wp_get_current_user();

        $localize = array(
            'restUrl'     => esc_url_raw( rest_url( 'ke/v1/' ) ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'homeUrl'     => home_url(),
            'serviceFee'  => null,
            'accentColor' => $accent_color ?: '#6366f1',
            'access'      => array(
                'requireLogin' => (bool) $access['require_login'],
                'loginUrl'     => $access['login_url'],
                'registerUrl'  => $access['register_url'],
                'message'      => $access['login_required_message'],
            ),
            'user'        => array(
                'loggedIn'    => is_user_logged_in(),
                'displayName' => ( $current && $current->ID ) ? $current->display_name : '',
                'email'       => ( $current && $current->ID ) ? $current->user_email : '',
            ),
        );

        // Inject service fee for single event pages
        if ( is_singular( 'ke_event' ) ) {
            global $post;
            $fee_id = get_post_meta( $post->ID, '_ke_event_service_fee_id', true );
            if ( $fee_id ) {
                $all_fees = get_option( 'ke_service_fees', array() );
                foreach ( $all_fees as $fee ) {
                    if ( $fee['id'] === $fee_id ) {
                        $localize['serviceFee'] = $fee;
                        break;
                    }
                }
            }

            // Per-event extra fields config — drives per-attendee inputs in
            // the checkout sheet. Empty/disabled config is sent as
            // {enabled:false, fields:[]} so the JS branch is identical.
            // Only fields whose visibility is 'tickets' or 'both' belong in
            // the ticket sheet; reservations-only fields are handed to the
            // reservations sheet via window.kePublicResv (single-event view).
            if ( class_exists( 'KE_Event_Extra_Fields' ) ) {
                $cfg = KE_Event_Extra_Fields::get_config( $post->ID );
                $cfg['fields'] = KE_Event_Extra_Fields::get_fields_for( $post->ID, 'tickets' );
                $localize['extraFields'] = $cfg;
            }
        }

        wp_localize_script( 'ke-checkout-js', 'kePublic', $localize );

        // Append custom color overrides AFTER the stylesheet so they always win
        $this->inject_color_vars();
    }

    /**
     * [kiwi_events] — Event listing/archive
     */
    public function shortcode_events( $atts ) {
        $atts = shortcode_atts( array(
            'limit'          => 12,
            'category'       => '',
            'organizer'      => '',
            'columns'        => 3,
            'autoplay'       => 'true',
            'interval'       => 5000,
            'show_organizer' => 'true',
            'show_category'  => 'true',
            'layout'         => 'carousel',
        ), $atts, 'kiwi_events' );

        $limit = max( 1, min( 30, intval( $atts['limit'] ) ) );

        $args = array(
            'post_type'      => 'ke_event',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'meta_value',
            'meta_key'       => '_ke_event_date_start',
            'order'          => 'ASC',
            'meta_query'     => array(
                array(
                    'key'     => '_ke_event_status',
                    'value'   => 'active',
                    'compare' => '=',
                ),
            ),
        );

        $tax_queries = array();

        if ( $atts['category'] ) {
            $tax_queries[] = array(
                'taxonomy' => 'ke_event_category',
                'field'    => 'slug',
                'terms'    => explode( ',', $atts['category'] ),
            );
        }

        if ( $atts['organizer'] ) {
            $tax_queries[] = array(
                'taxonomy' => 'ke_organizer',
                'field'    => 'slug',
                'terms'    => explode( ',', $atts['organizer'] ),
            );
        }

        if ( ! empty( $tax_queries ) ) {
            if ( count( $tax_queries ) > 1 ) {
                $tax_queries['relation'] = 'AND';
            }
            $args['tax_query'] = $tax_queries;
        }

        $query  = new WP_Query( $args );
        $layout = $atts['layout'] === 'grid' ? 'grid' : 'carousel';

        ob_start();

        if ( $layout === 'carousel' ) {
            wp_enqueue_style(
                'ke-events-carousel-css',
                KE_PLUGIN_URL . 'public/css/ke-events-carousel.css',
                array( 'ke-public-css' ),
                KE_VERSION
            );
            wp_enqueue_script(
                'ke-events-carousel-js',
                KE_PLUGIN_URL . 'public/js/ke-events-carousel.js',
                array(),
                KE_VERSION,
                true
            );

            // Preload the first 3 posters for perceived speed.
            if ( $query->have_posts() ) {
                $preload_urls = array();
                foreach ( array_slice( $query->posts, 0, 3 ) as $p ) {
                    $tid = get_post_thumbnail_id( $p->ID );
                    if ( $tid ) {
                        $url = wp_get_attachment_image_url( $tid, 'medium_large' );
                        if ( $url ) $preload_urls[] = $url;
                    }
                }
                if ( ! empty( $preload_urls ) ) {
                    add_action( 'wp_head', function () use ( $preload_urls ) {
                        foreach ( $preload_urls as $u ) {
                            echo '<link rel="preload" as="image" href="' . esc_url( $u ) . '">' . "\n";
                        }
                    }, 5 );
                }
            }

            include KE_PLUGIN_DIR . 'public/views/events-carousel.php';
        } else {
            include KE_PLUGIN_DIR . 'public/views/event-archive.php';
        }

        return ob_get_clean();
    }

    /**
     * [kiwi_event id="X"] — Single event with ticket purchase
     */
    public function shortcode_single_event( $atts ) {
        $atts = shortcode_atts( array(
            'id' => 0,
        ), $atts );

        $event_id = intval( $atts['id'] );
        if ( ! $event_id ) {
            return '<p class="ke-error">Please specify an event ID.</p>';
        }

        $post = get_post( $event_id );
        if ( ! $post || $post->post_type !== 'ke_event' ) {
            return '<p class="ke-error">Event not found.</p>';
        }

        $ticket_types = new KE_Ticket_Types();
        $types = $ticket_types->get_available( $event_id );

        ob_start();
        include KE_PLUGIN_DIR . 'public/views/single-event.php';
        return ob_get_clean();
    }

    /**
     * [kiwi_checkout] — Standalone checkout form
     */
    public function shortcode_checkout( $atts ) {
        $atts = shortcode_atts( array(
            'event_id' => 0,
        ), $atts );

        $event_id = intval( $atts['event_id'] );

        ob_start();
        include KE_PLUGIN_DIR . 'public/views/checkout.php';
        return ob_get_clean();
    }

    /**
     * Override standard single_template for ke_event posts
     */
    public function override_single_template( $single_template ) {
        global $post;
        if ( $post && $post->post_type === 'ke_event' ) {
            return KE_PLUGIN_DIR . 'public/views/single-event.php';
        }
        return $single_template;
    }

    /**
     * Append user color overrides as inline CSS after ke-public.css.
     * Using wp_add_inline_style() guarantees the rules load AFTER the
     * stylesheet, so they correctly override the :root defaults.
     */
    private function inject_color_vars() {
        $ui     = get_option( 'ke_ui_settings', array() );
        $accent = ! empty( $ui['accent_color'] )   ? sanitize_hex_color( $ui['accent_color'] )   : '';
        $sub    = ! empty( $ui['subtitle_color'] ) ? sanitize_hex_color( $ui['subtitle_color'] ) : '';

        if ( ! $accent && ! $sub ) {
            return;
        }

        // Scope to both :root and the plugin wrapper elements.
        // The element-level rules have higher specificity than a theme's :root
        // declarations, so they always win regardless of load order.
        $selectors = ':root, .ke-event-page, .ke-sheet, .ke-sheet-overlay';
        $css = $selectors . ' {';

        if ( $accent ) {
            $r = hexdec( substr( $accent, 1, 2 ) );
            $g = hexdec( substr( $accent, 3, 2 ) );
            $b = hexdec( substr( $accent, 5, 2 ) );

            $css .= '--kep-accent-1:' . $accent . ';';
            $css .= '--kep-accent-2:' . $accent . ';';
            $css .= '--kep-accent-rgb:' . $r . ',' . $g . ',' . $b . ';';
            $css .= '--kep-accent-grad:linear-gradient(135deg,' . $accent . ' 0%,' . $accent . ' 100%);';
            $css .= '--kep-accent-glow:rgba(' . $r . ',' . $g . ',' . $b . ',0.28);';
            $css .= '--kep-accent-soft:rgba(' . $r . ',' . $g . ',' . $b . ',0.08);';
            $css .= '--kep-shadow-accent:0 8px 32px rgba(' . $r . ',' . $g . ',' . $b . ',0.35);';
        }

        if ( $sub ) {
            $css .= '--kep-ink-3:' . $sub . ';';
        }

        $css .= '}';
        wp_add_inline_style( 'ke-public-css', $css );
    }
}
