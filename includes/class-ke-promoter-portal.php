<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Public promoter portal at /promoter/{slug}.
 *
 * Auth is WordPress-native: the slug resolves to a ke_promoters row whose
 * user_id links to a WP user. The portal compares that to the currently
 * logged-in user.
 *
 *   - Not logged in        → render inline login view with .ke-modal overlay
 *                            (no wp-login.php redirect; modal posts to the
 *                            /ke/v1/promoter-login REST endpoint)
 *   - Logged in, wrong user → access-denied page
 *   - Logged in, matches    → dashboard
 *
 * Admin "preview as promoter" bypasses the user check via
 * KE_Promoter_Admin_Preview (signed token + admin cap).
 */
class KE_Promoter_Portal {

    const REWRITE_OPTION  = 'ke_promoter_portal_rewrite_version';
    const REWRITE_VERSION = '2.0.0';

    public function init() {
        add_action( 'init',              array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars',        array( $this, 'add_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'handle_request' ) );

        // Portal CSV export — authenticated promoter exports their own commissions.
        add_action( 'admin_post_ke_promoter_export_csv', array( $this, 'handle_export_csv' ) );

        // Notification preferences save (toggle on the simplified dashboard).
        add_action( 'admin_post_ke_promoter_save_prefs', array( $this, 'handle_save_prefs' ) );

        // Self-de-assign from an event (Salir de este evento).
        add_action( 'admin_post_ke_promoter_leave_event', array( $this, 'handle_leave_event' ) );

        // Inline-login endpoint — replaces the wp-login.php redirect path.
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Hide the WP admin bar for promoters viewing the portal on their
        // phone — it eats ~46px of viewport on mobile and exposes routes
        // they shouldn't need. Admins keep it so they can navigate back.
        add_action( 'after_setup_theme', array( $this, 'maybe_hide_admin_bar_on_portal' ) );
    }

    /**
     * Suppress the WordPress admin bar on /promoter/{slug}/ for non-admin
     * users. Runs on after_setup_theme (early enough for show_admin_bar()
     * to take effect). No-op if the helper isn't loaded yet, on wp-admin
     * pages, or for users with manage_options.
     */
    public function maybe_hide_admin_bar_on_portal() {
        if ( is_admin() ) return;
        if ( ! function_exists( 'ke_is_promoter_portal_request' ) ) return;
        if ( ! ke_is_promoter_portal_request() ) return;
        if ( ! is_user_logged_in() ) return;
        if ( current_user_can( 'manage_options' ) ) return;
        show_admin_bar( false );
    }

    /* ─── REST: inline promoter login ───────────────────────────────── */

    public function register_rest_routes() {
        register_rest_route( 'ke/v1', '/promoter-login', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_login' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Inline login. Public POST endpoint — does its own rate limiting and
     * promoter-row validation. wp_signon() is the credential check; we then
     * confirm the resulting user owns a promoter row before considering the
     * sign-in valid (so generic site users with valid creds but no promoter
     * association cannot land on the portal).
     */
    public function rest_login( WP_REST_Request $req ) {
        $email = sanitize_email( (string) $req->get_param( 'email' ) );
        $pw    = (string) $req->get_param( 'password' );
        $slug  = sanitize_title( (string) $req->get_param( 'slug' ) );

        if ( $email === '' || $pw === '' ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => __( 'Email and password are required.', 'kiwi-events' ),
            ), 400 );
        }

        // Rate limit: 5 attempts per IP per minute. IP is salted+hashed so the
        // transient key doesn't leak raw addresses.
        $ip      = self::client_ip();
        $rl_key  = 'ke_pl_rl_' . hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
        $count   = (int) get_transient( $rl_key );
        if ( $count >= 5 ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => __( 'Too many attempts. Please wait a minute and try again.', 'kiwi-events' ),
            ), 429 );
        }
        // Pre-increment so a slow wp_signon() can't be hammered concurrently.
        set_transient( $rl_key, $count + 1, MINUTE_IN_SECONDS );

        $user = wp_signon( array(
            'user_login'    => $email,
            'user_password' => $pw,
            'remember'      => true,
        ), is_ssl() );

        if ( is_wp_error( $user ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => __( 'Invalid email or password.', 'kiwi-events' ),
            ), 401 );
        }

        // Confirm the now-logged-in user actually owns a promoter row. If not,
        // log them right back out so the WP session doesn't persist for a
        // non-promoter visitor who happened to authenticate here.
        $promoter = KE_Promoter_Attribution::find_by_user_id( (int) $user->ID );
        if ( ! $promoter ) {
            wp_logout();
            return new WP_REST_Response( array(
                'success' => false,
                'message' => __( 'This account is not associated with a promoter profile.', 'kiwi-events' ),
            ), 403 );
        }

        // Optional slug check: if the URL targets a specific promoter slug and
        // it doesn't match the one this user owns, reject so the modal doesn't
        // silently sign someone into a portal they have no business viewing.
        if ( $slug !== '' && $slug !== (string) $promoter->slug ) {
            wp_logout();
            return new WP_REST_Response( array(
                'success' => false,
                'message' => __( 'This account is not authorized for this portal.', 'kiwi-events' ),
            ), 403 );
        }

        // Successful login — clear the rate-limit counter so the same IP isn't
        // penalized for the rest of the minute.
        delete_transient( $rl_key );

        wp_set_current_user( (int) $user->ID );

        return new WP_REST_Response( array(
            'success'  => true,
            'redirect' => self::portal_url( (string) $promoter->slug ),
        ), 200 );
    }

    /**
     * Best-effort client IP for rate limiting. Trusts only REMOTE_ADDR by
     * default — proxies that need X-Forwarded-For should be wired in via a
     * site-specific filter rather than blindly accepted here.
     */
    private static function client_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return $ip !== '' ? $ip : '0.0.0.0';
    }

    /**
     * Resolve the current WP user to a promoter row, or null.
     */
    private static function current_promoter() {
        if ( ! is_user_logged_in() ) return null;
        return KE_Promoter_Attribution::find_by_user_id( get_current_user_id() );
    }

    /**
     * Save notification preferences for the signed-in promoter.
     */
    public function handle_save_prefs() {
        $promoter = self::current_promoter();
        if ( ! $promoter ) {
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }

        $nonce = isset( $_POST['_wpnonce'] ) ? (string) $_POST['_wpnonce'] : '';
        if ( ! wp_verify_nonce( $nonce, 'ke_promoter_save_prefs_' . $promoter->id ) ) {
            wp_die( 'Security check failed.' );
        }

        $prefs = array(
            'notify_on_earn' => ! empty( $_POST['notify_on_earn'] ),
        );
        update_option( 'ke_promoter_prefs_' . (int) $promoter->id, $prefs, false );

        self::set_flash( 'success', __( 'Preferences saved.', 'kiwi-events' ) );
        wp_safe_redirect( self::portal_url( $promoter->slug ) );
        exit;
    }

    /**
     * Promoter self-de-assigns from an event. Admin-preview mode is not
     * allowed to fire this — the spec is "promoter chooses to leave" and the
     * admin already has full unassign powers in wp-admin.
     *
     * Historical commission rows for this (promoter, event) pair are NOT
     * touched. They keep their earned/paid status so existing payouts and
     * accounting are unaffected.
     */
    public function handle_leave_event() {
        $promoter = self::current_promoter();
        if ( ! $promoter ) {
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }

        $event_id = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;
        $nonce    = isset( $_POST['_wpnonce'] ) ? (string) $_POST['_wpnonce'] : '';

        if ( ! wp_verify_nonce( $nonce, 'ke_promoter_leave_event_' . $promoter->id . '_' . $event_id ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( $event_id <= 0 ) {
            self::set_flash( 'error', __( 'Invalid event.', 'kiwi-events' ) );
            wp_safe_redirect( self::portal_url( $promoter->slug ) );
            exit;
        }

        $event_post = get_post( $event_id );
        $title      = $event_post ? $event_post->post_title : ('#' . $event_id);

        $ok = KE_Event_Promoters::unassign( $event_id, (int) $promoter->id );
        if ( $ok ) {
            self::set_flash(
                'success',
                sprintf( __( 'Dejaste de promover "%s". Tus comisiones anteriores se conservan.', 'kiwi-events' ), $title )
            );
        } else {
            self::set_flash( 'error', __( 'No se pudo desasignar el evento. Inténtalo de nuevo.', 'kiwi-events' ) );
        }

        wp_safe_redirect( self::portal_url( $promoter->slug ) );
        exit;
    }

    /**
     * Portal-side CSV export. The signed-in promoter can only export their own rows.
     */
    public function handle_export_csv() {
        $promoter = self::current_promoter();
        if ( ! $promoter ) {
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ke_promoter_export_csv_' . $promoter->id ) ) {
            wp_die( 'Security check failed.' );
        }

        $status  = isset( $_GET['cstatus'] ) ? sanitize_text_field( $_GET['cstatus'] ) : '';
        $filters = array();
        if ( in_array( $status, array( 'earned', 'paid', 'refunded_keep', 'voided' ), true ) ) {
            $filters['status'] = $status;
        }

        KE_Promoter_Commissions::stream_csv_for_promoter( $promoter, $filters );
        exit;
    }

    /* ─── Routing ─────────────────────────────────────────────────── */

    public function add_rewrite_rules() {
        // /promoter/{slug}
        add_rewrite_rule( '^promoter/([^/]+)/?$',
            'index.php?ke_promoter_slug=$matches[1]', 'top' );

        if ( get_option( self::REWRITE_OPTION, '' ) !== self::REWRITE_VERSION ) {
            flush_rewrite_rules( false );
            update_option( self::REWRITE_OPTION, self::REWRITE_VERSION );
        }
    }

    public function add_query_vars( $vars ) {
        $vars[] = 'ke_promoter_slug';
        return $vars;
    }

    public function handle_request() {
        $slug = get_query_var( 'ke_promoter_slug' );
        if ( ! $slug ) return;

        $slug     = sanitize_title( (string) $slug );
        $promoter = KE_Promoter_Attribution::find_by_slug( $slug );

        $this->enqueue_assets();

        // Admin impersonation preview — gated by WP-admin session + signed
        // token. When valid, render the dashboard for the requested promoter
        // regardless of the current WP user. The view receives
        // $admin_preview = true so it can hide write actions and show a banner.
        $admin_preview = false;
        if ( class_exists( 'KE_Promoter_Admin_Preview' ) ) {
            $preview_pid = KE_Promoter_Admin_Preview::resolve_preview_request();
            if ( $preview_pid > 0 ) {
                global $wpdb;
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ke_promoters WHERE id = %d",
                    $preview_pid
                ) );
                if ( $row ) {
                    $promoter      = $row;
                    $slug          = (string) $row->slug;
                    $admin_preview = true;
                }
            }
        }

        // Direct admin read-only view: a logged-in user with manage_options can
        // view any promoter's dashboard without the signed-token dance. We only
        // engage admin_preview when the admin is NOT the owning promoter — if
        // they happen to also own this slug, render the normal owner flow so
        // they have full access to their own portal.
        if ( ! $admin_preview && $promoter && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            if ( (int) get_current_user_id() !== (int) $promoter->user_id ) {
                $admin_preview = true;
            }
        }

        // No matching promoter row — render access-denied (don't leak whether
        // the slug exists or not, but we have no dashboard to show).
        if ( ! $promoter && ! $admin_preview ) {
            status_header( 404 );
            $flash = $this->consume_flash();
            include KE_PLUGIN_DIR . 'public/views/promoter-portal-denied.php';
            exit;
        }

        // Not logged in → render the inline login view (Kiwi-styled modal
        // overlay). No wp-login.php redirect. The modal submits to the
        // ke/v1/promoter-login REST endpoint; on success it reloads this URL
        // and falls through to the dashboard branch below.
        if ( ! $admin_preview && ! is_user_logged_in() ) {
            $flash         = $this->consume_flash();
            $display_name  = $promoter && ! empty( $promoter->name )
                ? (string) $promoter->name
                : (string) $slug;
            include KE_PLUGIN_DIR . 'public/views/promoter-portal-login.php';
            exit;
        }

        // Logged in but not the owner of this slug → access denied.
        if ( ! $admin_preview && (int) get_current_user_id() !== (int) $promoter->user_id ) {
            status_header( 403 );
            include KE_PLUGIN_DIR . 'public/views/promoter-portal-denied.php';
            exit;
        }

        // Authenticated dashboard.
        $flash = $this->consume_flash();
        include KE_PLUGIN_DIR . 'public/views/promoter-portal-dashboard.php';
        exit;
    }

    /* ─── Assets ──────────────────────────────────────────────────── */

    private function enqueue_assets() {
        $ver = defined( 'KE_PORTAL_ASSETS_VER' ) ? KE_PORTAL_ASSETS_VER : KE_VERSION;
        wp_enqueue_style(
            'ke-promoter-portal',
            KE_PLUGIN_URL . 'public/css/ke-promoter-portal.css',
            array(),
            $ver
        );

        // Only enqueue the login modal JS on the unauthenticated portal hit.
        // Authenticated dashboard renders never include the modal markup.
        if ( ! is_user_logged_in() ) {
            wp_enqueue_script(
                'ke-promoter-login',
                KE_PLUGIN_URL . 'public/js/ke-promoter-login.js',
                array(),
                $ver,
                true
            );
            wp_localize_script( 'ke-promoter-login', 'kePromoterLogin', array(
                'restUrl' => esc_url_raw( rest_url( 'ke/v1/promoter-login' ) ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'home'    => esc_url_raw( home_url( '/' ) ),
                'i18n'    => array(
                    'submitting' => __( 'Signing in…', 'kiwi-events' ),
                    'genericErr' => __( 'Sign-in failed. Please try again.', 'kiwi-events' ),
                ),
            ) );
        }
    }

    /* ─── Flash messages (set before redirect) ───────────────────── */

    public static function set_flash( $type, $message ) {
        $key = self::flash_key();
        if ( ! $key ) return;
        set_transient( $key, array( 'type' => $type, 'message' => $message ), 30 );
    }

    public function consume_flash() {
        $key = self::flash_key();
        if ( ! $key ) return null;
        $flash = get_transient( $key );
        if ( $flash ) delete_transient( $key );
        return $flash ?: null;
    }

    /**
     * Keyed off the WP user id when logged in; otherwise null (no flash for
     * anonymous traffic — they'd never reach a screen that consumes it).
     */
    private static function flash_key() {
        $uid = get_current_user_id();
        if ( $uid <= 0 ) return '';
        return 'ke_promoter_flash_' . $uid;
    }

    /* ─── Helpers ────────────────────────────────────────────────── */

    public static function portal_url( $slug ) {
        return home_url( '/promoter/' . rawurlencode( $slug ) . '/' );
    }
}

/**
 * True when the current request URI looks like the promoter portal
 * (`/promoter/{slug}/`). Path-only check — does not validate the slug
 * against the DB. Used by the admin-bar suppression hook, which fires on
 * after_setup_theme — too early for get_query_var() to be populated.
 */
if ( ! function_exists( 'ke_is_promoter_portal_request' ) ) {
    function ke_is_promoter_portal_request() {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) return false;
        $path = parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
        if ( ! is_string( $path ) || $path === '' ) return false;
        return (bool) preg_match( '#/promoter/[^/]+/?$#', $path );
    }
}
