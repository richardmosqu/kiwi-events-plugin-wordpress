<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Public-facing organizer self-service dashboard.
 *
 * Route: /organizer/{slug}
 *   - Resolves a `ke_organizer` taxonomy term by slug
 *   - 404s if no such organizer or if the organizer has no password set
 *     (the dashboard is exclusively password-protected — there is no public
 *     view of an organizer's sales data)
 *   - Includes public/views/organizer-dashboard.php which handles both the
 *     unauthenticated login state and the authenticated dashboard state
 *
 * Auth uses an HTTP-only cookie session (NOT sessionStorage — the dashboard
 * exposes financial data). Token is bound to a single organizer; a token
 * issued for organizer A cannot read data for organizer B. See
 * issue_session()/get_authenticated_organizer_id()/clear_session().
 */
class KE_Organizer_Dashboard {

    const SESSION_TTL    = 4 * HOUR_IN_SECONDS;
    const SESSION_PREFIX = 'ke_org_session_';
    const COOKIE_NAME    = 'ke_organizer_session';

    public function init() {
        add_action( 'init',              array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars',        array( $this, 'add_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'handle_dashboard_request' ) );
    }

    /* ──────────────────────────────────────────────────────────────
     *  Routing
     * ────────────────────────────────────────────────────────────── */

    public function add_rewrite_rules() {
        add_rewrite_rule( '^organizer/([^/]+)/?$', 'index.php?ke_organizer_slug=$matches[1]', 'top' );
        // Self-flushing: when the version bumps, flush once. Avoids relying on
        // the activator's flush, which fires before init and so wouldn't see
        // this rule. Cheap no-op once the option matches.
        if ( get_option( 'ke_organizer_rewrite_version', '' ) !== '1.0.0' ) {
            flush_rewrite_rules( false );
            update_option( 'ke_organizer_rewrite_version', '1.0.0' );
        }
    }

    public function add_query_vars( $vars ) {
        $vars[] = 'ke_organizer_slug';
        return $vars;
    }

    public function handle_dashboard_request() {
        $slug = get_query_var( 'ke_organizer_slug' );
        if ( ! $slug ) return;

        $term = get_term_by( 'slug', sanitize_title( $slug ), 'ke_organizer' );
        if ( ! $term || is_wp_error( $term ) ) {
            self::send_404();
        }

        // The dashboard requires a password to exist.
        if ( ! KE_Scanner_Password::organizer_has_password( $term->term_id ) ) {
            self::send_404();
        }

        // Expose context to the template.
        $organizer            = $term;
        $organizer_logo_id    = (int) get_term_meta( $term->term_id, 'ke_organizer_logo', true );
        $organizer_logo_url   = $organizer_logo_id ? wp_get_attachment_image_url( $organizer_logo_id, 'medium' ) : '';
        $authenticated_org_id = self::get_authenticated_organizer_id();
        $is_authenticated     = ( $authenticated_org_id === (int) $term->term_id );
        $is_admin_user        = self::is_admin_user();

        // Asset enqueue (the wp_enqueue_scripts hook has already fired by
        // the time template_redirect runs, so call wp_enqueue_* directly
        // and they will be rendered into wp_head/wp_footer via WordPress.)
        $this->enqueue_dashboard_assets( $term );

        include KE_PLUGIN_DIR . 'public/views/organizer-dashboard.php';
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

    private function enqueue_dashboard_assets( $organizer ) {
        // Cache-buster suffix for the dashboard bundle. Bumped when the CSS
        // or JS payload changes shape (button rebrand, new ticket-type
        // breakdown markup, etc.) so production stops serving stale assets
        // without requiring a full plugin-version bump.
        $dash_ver = KE_VERSION . '-d11';

        wp_enqueue_style(
            'ke-organizer-dashboard',
            KE_PLUGIN_URL . 'public/css/ke-organizer-dashboard.css',
            array(),
            $dash_ver
        );

        // Chart.js for daily sales chart (CDN — same library other plugins use).
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            array(),
            '4.4.1',
            true
        );

        wp_enqueue_script(
            'ke-organizer-dashboard',
            KE_PLUGIN_URL . 'public/js/ke-organizer-dashboard.js',
            array( 'chartjs' ),
            $dash_ver,
            true
        );

        $ui     = get_option( 'ke_ui_settings', array() );
        $accent = ! empty( $ui['accent_color'] ) ? sanitize_hex_color( $ui['accent_color'] ) : '#6366f1';

        // The rest of the plugin's CSS uses --kep-accent-1 / --kep-accent-2 /
        // --kep-accent-rgb / --kep-accent-grad as the canonical accent tokens
        // (see public/class-ke-public.php and public/css/ke-public.css). The
        // dashboard's stylesheet matches that convention so the organizer's
        // chosen accent_color flows in unchanged — no auto-lightened second
        // stop, which used to pinken warm accents.
        $rgb_parts  = self::hex_to_rgb_parts( $accent );
        $accent_rgb = $rgb_parts ? implode( ', ', $rgb_parts ) : '99, 102, 241';

        // Initial value of the "last sale" beacon — passing it inline so the
        // first poll doesn't false-trigger a refresh against an unseen value.
        $initial_last_sale = (int) get_transient( 'ke_last_sale_' . (int) $organizer->term_id );

        wp_localize_script( 'ke-organizer-dashboard', 'keOrganizerDashboard', array(
            'restUrl'        => esc_url_raw( rest_url( 'ke/v1/organizer/' ) ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'slug'           => $organizer->slug,
            'organizerName'  => $organizer->name,
            'isAdmin'        => self::is_admin_user(),
            'accent'         => $accent,
            'currency'       => get_option( 'ke_currency', 'USD' ),
            'siteUrl'        => esc_url_raw( home_url( '/' ) ),
            'initialLastSale'=> $initial_last_sale,
        ) );

        // Both gradient stops resolve to the user's chosen accent — flat brand
        // color, identical to ke-public.css behavior. No more synthesized
        // lighter shade that drifts toward pink for warm accents.
        $css = '.ke-org-dash{'
             . '--kep-accent-1:' . $accent . ';'
             . '--kep-accent-2:' . $accent . ';'
             . '--kep-accent-rgb:' . $accent_rgb . ';'
             . '--kep-accent-grad:linear-gradient(135deg,' . $accent . ' 0%,' . $accent . ' 100%);'
             . '}';
        wp_add_inline_style( 'ke-organizer-dashboard', $css );
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

    /* ──────────────────────────────────────────────────────────────
     *  Session helpers (HTTP-only cookie ↔ transient)
     *
     *  Cookie value is an opaque hex token; the transient at
     *  `ke_org_session_{token}` stores the binding to organizer_id +
     *  expiry. A token issued for organizer A cannot read organizer B
     *  because every endpoint compares the token's stored organizer_id
     *  against the requested slug.
     * ────────────────────────────────────────────────────────────── */

    public static function is_admin_user() {
        return current_user_can( 'manage_kiwi_events' ) || current_user_can( 'manage_options' );
    }

    public static function issue_session( $organizer_id, $organizer_slug ) {
        $organizer_id = (int) $organizer_id;
        if ( $organizer_id <= 0 ) return null;

        // 48-char hex token — opaque, cookie-safe, validated via ctype_xdigit on read.
        $token = bin2hex( random_bytes( 24 ) );

        $payload = array(
            'organizer_id'   => $organizer_id,
            'organizer_slug' => (string) $organizer_slug,
            'issued_at'      => time(),
            'expires_at'     => time() + self::SESSION_TTL,
            'user_id'        => get_current_user_id(),
            'ip_hash'        => md5( KE_Scanner_Password::get_request_ip() ),
        );
        set_transient( self::SESSION_PREFIX . $token, $payload, self::SESSION_TTL );

        self::set_session_cookie( $token, $payload['expires_at'] );

        return array(
            'token'      => $token,
            'expires_at' => $payload['expires_at'],
            'ttl'        => self::SESSION_TTL,
        );
    }

    public static function set_session_cookie( $token, $expires_at ) {
        if ( headers_sent() ) return;
        $secure = is_ssl();
        setcookie(
            self::COOKIE_NAME,
            (string) $token,
            array(
                'expires'  => (int) $expires_at,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
        // Also populate $_COOKIE for the rest of this request lifecycle.
        $_COOKIE[ self::COOKIE_NAME ] = $token;
    }

    public static function clear_session_cookie() {
        if ( headers_sent() ) return;
        setcookie(
            self::COOKIE_NAME,
            '',
            array(
                'expires'  => time() - 3600,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
        unset( $_COOKIE[ self::COOKIE_NAME ] );
    }

    public static function read_session_token() {
        return isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( $_COOKIE[ self::COOKIE_NAME ] ) : '';
    }

    public static function get_session_payload( $token = null ) {
        $token = $token ?: self::read_session_token();
        if ( ! is_string( $token ) || $token === '' || ! ctype_xdigit( $token ) ) return null;
        $payload = get_transient( self::SESSION_PREFIX . $token );
        return is_array( $payload ) ? $payload : null;
    }

    public static function get_authenticated_organizer_id() {
        $payload = self::get_session_payload();
        if ( ! $payload ) return 0;
        return (int) ( $payload['organizer_id'] ?? 0 );
    }

    public static function clear_session( $token = null ) {
        $token = $token ?: self::read_session_token();
        if ( $token ) {
            delete_transient( self::SESSION_PREFIX . $token );
        }
        self::clear_session_cookie();
    }

    /**
     * Hard-revoke every dashboard cookie session bound to an organizer. Called
     * after a password reset so any browser still holding a logged-in cookie
     * is forced back to the login screen on its next request.
     */
    public static function purge_organizer_sessions( $organizer_id ) {
        $organizer_id = (int) $organizer_id;
        if ( $organizer_id <= 0 ) return 0;

        global $wpdb;
        $like = $wpdb->esc_like( '_transient_' . self::SESSION_PREFIX ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like
        ) );
        $purged = 0;
        foreach ( $rows as $row ) {
            $payload = maybe_unserialize( $row->option_value );
            if ( ! is_array( $payload ) ) continue;
            if ( (int) ( $payload['organizer_id'] ?? 0 ) !== $organizer_id ) continue;
            $key = preg_replace( '/^_transient_/', '', $row->option_name );
            if ( $key && delete_transient( $key ) ) $purged++;
        }
        return $purged;
    }

    /**
     * Resolve and authorize an organizer slug for an authenticated request.
     * Returns the term object on success or a WP_Error on failure (suitable
     * for direct return from REST callbacks).
     */
    public static function require_session_for_slug( $slug ) {
        $term = get_term_by( 'slug', sanitize_title( $slug ), 'ke_organizer' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'invalid_organizer', __( 'Organizer not found.', 'kiwi-events' ), array( 'status' => 404 ) );
        }
        $auth_id = self::get_authenticated_organizer_id();
        if ( $auth_id !== (int) $term->term_id ) {
            // Allow an admin to read any organizer's data without a session.
            if ( self::is_admin_user() ) {
                return $term;
            }
            return new WP_Error( 'organizer_session_required', __( 'Sign in to view this dashboard.', 'kiwi-events' ), array( 'status' => 401 ) );
        }
        return $term;
    }
}
