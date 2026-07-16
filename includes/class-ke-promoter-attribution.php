<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Promoter attribution — STRICT URL-ONLY (no session, no cookie, no meta).
 *
 * Decision per pageview: the badge and commission flow ONLY when the current
 * request URL (or, at order-processing time, the referrer URL of the submit)
 * contains a valid ?promo=<slug> AND that slug resolves to:
 *   - an active row in ke_promoters AND
 *   - a row in ke_event_promoters for this specific event_id
 *
 * No persistence layer. Two tabs on the same event with different URLs render
 * independently — the param does not bleed across tabs, sessions, or visits.
 *
 * The URL param propagates through the WC cart→checkout funnel via filters in
 * KE_Promoter_Visible (woocommerce_get_cart_url, _checkout_url, etc.) — those
 * read the same URL ?promo= and append it forward. A visitor who clears the
 * param in their address bar loses attribution from that point on; this is
 * intentional. Commission generation at order_processed time reads HTTP_REFERER
 * to recover the final URL the visitor submitted from.
 *
 * Legacy: pre-URL-only deploys wrote `ke_promo` and `ke_promo_<id>` cookies +
 * WC session keys. `clear_legacy_storage()` expires those server-side, and
 * KE_Promoter_Visible::render_legacy_cookie_purge_js() also expires any
 * non-HttpOnly leftovers in the browser the first time the user hits an event
 * page after this deploy.
 *
 * KE_PROMOTER_DEBUG (defined in kiwi-events.php) gates verbose error_log
 * lines at the attribution checkpoints (capture / order_processed / commission
 * insert) so a single test purchase can pinpoint where the slug is being lost
 * on any given install.
 */
class KE_Promoter_Attribution {

    /**
     * Legacy site-wide cookie/session key. Kept ONLY so clear_legacy_storage()
     * can expire stale values from pre-event-scope deploys. New code never
     * reads or writes these.
     */
    const COOKIE_NAME        = 'ke_promo';
    const WC_SESSION_KEY     = 'ke_promo';

    const QUERY_PARAM        = 'promo';
    const QUERY_PARAM_LEGACY = 'ke_promo';

    /** Event-scoped storage key prefix. Final keys look like `ke_promo_42`. */
    const KEY_PREFIX         = 'ke_promo_';

    public function init() {
        add_action( 'template_redirect', array( $this, 'clear_legacy_storage' ), 0 );
        add_action( 'template_redirect', array( $this, 'capture_from_url' ),     1 );
    }

    private static function dbg( $msg ) {
        if ( defined( 'KE_PROMOTER_DEBUG' ) && KE_PROMOTER_DEBUG ) {
            error_log( '[KE-PROMO] ' . $msg );
        }
    }

    /**
     * Detect ?promo (or legacy ?ke_promo) on inbound traffic. If the slug
     * matches an active promoter AND the current URL is an event permalink,
     * persist to BOTH stores keyed by that event_id, log the click row, and
     * let rendering continue with the query param still in the URL.
     *
     * Non-event URLs with ?promo= are ignored: attribution is always
     * event-scoped now, so there is nowhere to store a "global" click.
     *
     * The previous version 302-redirected to a clean URL. We no longer strip
     * the param: keeping ?promo= visible doubles as (a) a visible trust
     * signal during the buyer flow and (b) a debugging aid when something
     * goes wrong in production.
     */
    public function capture_from_url() {
        $raw = '';
        $src = '';
        if ( ! empty( $_GET[ self::QUERY_PARAM ] ) ) {
            $raw = (string) $_GET[ self::QUERY_PARAM ];
            $src = self::QUERY_PARAM;
        } elseif ( ! empty( $_GET[ self::QUERY_PARAM_LEGACY ] ) ) {
            $raw = (string) $_GET[ self::QUERY_PARAM_LEGACY ];
            $src = self::QUERY_PARAM_LEGACY;
        } else {
            return;
        }

        // Don't fire on REST/AJAX/admin/cron — those aren't "user clicked a
        // link" and persisting from a REST or AJAX call would mis-attribute.
        if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin() ) {
            return;
        }

        $slug = sanitize_title( $raw );
        if ( $slug === '' ) {
            return;
        }

        $event_id = self::guess_event_id_from_request();
        if ( ! $event_id ) {
            // Attribution is event-scoped. A ?promo= on a non-event page
            // (homepage, archive, etc.) has nowhere to land. Ignore.
            self::dbg( sprintf( 'capture: slug=%s param=%s — no event in URL; ignoring', $slug, $src ) );
            return;
        }

        $promoter = self::find_active_by_slug( $slug );
        if ( ! $promoter ) {
            self::dbg( sprintf( 'capture: slug=%s param=%s — no active promoter matches; ignoring', $slug, $src ) );
            return;
        }

        // URL-only model: do NOT persist the slug. The badge and downstream
        // commission flow re-read $_GET['promo'] / HTTP_REFERER themselves.
        // We log the click row for analytics but write nothing else.
        if ( ! self::is_assigned_to_event( (int) $promoter->id, (int) $event_id ) ) {
            self::dbg( sprintf(
                'capture: slug=%s promoter_id=%d event_id=%d — promoter not assigned to this event; ignoring',
                $slug, (int) $promoter->id, (int) $event_id
            ) );
            return;
        }

        self::log_click( (int) $promoter->id );

        self::dbg( sprintf(
            'capture: slug=%s param=%s promoter_id=%d event_id=%d url=%s — click logged (URL-only, no persistence)',
            $slug, $src, (int) $promoter->id, (int) $event_id,
            isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''
        ) );
    }

    /**
     * Resolve the active promoter for a specific event from the CURRENT URL.
     *
     * URL-only: reads $_GET['promo'] (or the legacy ?ke_promo=). No session,
     * no cookie, no post/user meta lookup. If the param is missing or invalid,
     * returns null and the badge does not render.
     *
     * Three checks, all must pass:
     *   1. Slug exists and the promoter's status is 'active'
     *   2. A row exists in ke_event_promoters for (event_id, promoter_id)
     *      — i.e. the promoter is currently assigned to this event
     *
     * Returns the ke_promoters row (object) or null.
     */
    public static function get_active_for_event( $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) return null;

        $url_slug = '';
        if ( ! empty( $_GET[ self::QUERY_PARAM ] ) ) {
            $url_slug = sanitize_title( (string) $_GET[ self::QUERY_PARAM ] );
        } elseif ( ! empty( $_GET[ self::QUERY_PARAM_LEGACY ] ) ) {
            $url_slug = sanitize_title( (string) $_GET[ self::QUERY_PARAM_LEGACY ] );
        }
        if ( $url_slug === '' ) return null;

        $p = self::find_active_by_slug( $url_slug );
        if ( ! $p ) return null;
        if ( ! self::is_assigned_to_event( (int) $p->id, $event_id ) ) return null;

        return $p;
    }

    /**
     * Resolve attribution for an order at order-processed time. The visitor
     * has just submitted; the referrer is the page they submitted from
     * (typically /checkout/?promo=<slug> when the URL filters have been
     * propagating the param through the funnel).
     *
     * Returns the ke_promoters row or null. Empty referrer, missing param,
     * inactive promoter, or unassigned promoter all → null (orphan order).
     */
    public static function resolve_from_referrer( $referrer, $event_id ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 || ! is_string( $referrer ) || $referrer === '' ) return null;

        $parsed = wp_parse_url( $referrer );
        if ( empty( $parsed['query'] ) ) return null;

        parse_str( $parsed['query'], $params );
        $raw = $params[ self::QUERY_PARAM ] ?? ( $params[ self::QUERY_PARAM_LEGACY ] ?? '' );
        $slug = sanitize_title( (string) $raw );
        if ( $slug === '' ) return null;

        $p = self::find_active_by_slug( $slug );
        if ( ! $p ) return null;
        if ( ! self::is_assigned_to_event( (int) $p->id, $event_id ) ) return null;

        return $p;
    }

    /**
     * Is this promoter currently assigned to this event? Assignment lives in
     * ke_event_promoters (one row per pair). Admin unassign DELETEs the row,
     * so "row exists" === "currently assigned" — there is no status column
     * to check.
     *
     * Falls back to a raw $wpdb query if KE_Event_Promoters isn't loaded
     * (defensive, shouldn't happen in normal request lifecycle).
     */
    public static function is_assigned_to_event( $promoter_id, $event_id ) {
        $promoter_id = (int) $promoter_id;
        $event_id    = (int) $event_id;
        if ( $promoter_id <= 0 || $event_id <= 0 ) return false;

        if ( class_exists( 'KE_Event_Promoters' ) ) {
            return (bool) KE_Event_Promoters::get( $event_id, $promoter_id );
        }

        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}ke_event_promoters
              WHERE event_id = %d AND promoter_id = %d LIMIT 1",
            $event_id, $promoter_id
        ) );
        return ! empty( $row );
    }

    /**
     * Convenience wrapper: returns the slug attributed to $event_id, or '' if
     * none. Mirrors the legacy get_current_slug() shape so callers don't have
     * to unwrap an object.
     */
    public static function get_slug_for_event( $event_id ) {
        $p = self::get_active_for_event( $event_id );
        return $p ? (string) $p->slug : '';
    }

    /**
     * Attribution source. URL-only model → only 'url' or ''.
     * Kept as a method so existing commission-insert call sites don't need
     * surgery.
     */
    public static function get_source_for_event( $event_id ) {
        return self::get_active_for_event( (int) $event_id ) ? 'url' : '';
    }

    /**
     * Expire legacy attribution storage: the original site-wide `ke_promo`
     * cookie/session AND any per-event `ke_promo_<id>` cookies/sessions that
     * the pre-URL-only deploy was writing. Idempotent — no-op if not present.
     * Fires once per request at template_redirect priority 0.
     *
     * The accompanying client-side JS purge lives in
     * KE_Promoter_Visible::render_legacy_cookie_purge_js() for cookies that
     * are not visible to PHP on this hostname (e.g. CDN domain differences).
     */
    public function clear_legacy_storage() {
        if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin() ) {
            return;
        }

        $cookie_params = array(
            'expires'  => time() - 3600,
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        );

        // Original site-wide cookie (`ke_promo`).
        if ( isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            if ( ! headers_sent() ) {
                setcookie( self::COOKIE_NAME, '', $cookie_params );
            }
            unset( $_COOKIE[ self::COOKIE_NAME ] );
        }

        // Per-event cookies written by the prior event-scoped deploy
        // (`ke_promo_<event_id>`). Pattern-match the current $_COOKIE bag.
        foreach ( array_keys( (array) $_COOKIE ) as $name ) {
            if ( strpos( (string) $name, self::KEY_PREFIX ) === 0 ) {
                if ( ! headers_sent() ) {
                    setcookie( (string) $name, '', $cookie_params );
                }
                unset( $_COOKIE[ $name ] );
            }
        }

        if ( function_exists( 'WC' ) && WC() && WC()->session ) {
            // Original site-wide WC session key.
            if ( (string) WC()->session->get( self::WC_SESSION_KEY, '' ) !== '' ) {
                WC()->session->set( self::WC_SESSION_KEY, '' );
            }
            // Per-event WC session keys.
            $session_data = method_exists( WC()->session, 'get_session_data' )
                ? (array) WC()->session->get_session_data()
                : array();
            foreach ( array_keys( $session_data ) as $sk ) {
                if ( strpos( (string) $sk, self::KEY_PREFIX ) === 0 ) {
                    WC()->session->set( (string) $sk, '' );
                }
            }
        }
    }

    /**
     * Lookup an active promoter row by slug. Returns the row or null.
     */
    public static function find_active_by_slug( $slug ) {
        global $wpdb;
        $slug = sanitize_title( (string) $slug );
        if ( $slug === '' ) return null;
        $table = $wpdb->prefix . 'ke_promoters';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE slug = %s AND status = 'active' LIMIT 1",
            $slug
        ) );
    }

    /**
     * Lookup any promoter (any status) by slug. Used by attribution-time
     * checks where 'inactive' should reject without erroring.
     */
    public static function find_by_slug( $slug ) {
        global $wpdb;
        $slug = sanitize_title( (string) $slug );
        if ( $slug === '' ) return null;
        $table = $wpdb->prefix . 'ke_promoters';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE slug = %s LIMIT 1",
            $slug
        ) );
    }

    /**
     * Lookup a promoter by linked WP user_id. Returns row or null.
     */
    public static function find_by_user_id( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return null;
        global $wpdb;
        $table = $wpdb->prefix . 'ke_promoters';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d LIMIT 1",
            $user_id
        ) );
    }

    /**
     * Resolve a promoter row + its linked WP user to display name/email.
     * Returns ['name' => ..., 'email' => ...]; empty strings if unlinked
     * (orphaned promoter) or WP user has been deleted.
     */
    public static function display_for( $promoter ) {
        if ( ! $promoter || empty( $promoter->user_id ) ) {
            return array( 'name' => '', 'email' => '' );
        }
        $u = get_userdata( (int) $promoter->user_id );
        if ( ! $u ) return array( 'name' => '', 'email' => '' );
        $name = $u->display_name ?: $u->user_login;
        return array( 'name' => (string) $name, 'email' => (string) $u->user_email );
    }

    /**
     * Insert a row in ke_promoter_clicks for analytics.
     * Best-effort — never throws.
     */
    private static function log_click( $promoter_id ) {
        global $wpdb;
        try {
            $event_id = self::guess_event_id_from_request();
            $ip_raw   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
            $ua_raw   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

            $wpdb->insert(
                $wpdb->prefix . 'ke_promoter_clicks',
                array(
                    'promoter_id' => (int) $promoter_id,
                    'event_id'    => $event_id,
                    'session_id'  => '',
                    'ip_hash'     => $ip_raw ? hash( 'sha256', $ip_raw . wp_salt() ) : '',
                    'user_agent'  => mb_substr( $ua_raw, 0, 255 ),
                    'clicked_at'  => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%s', '%s', '%s', '%s' )
            );
        } catch ( \Throwable $e ) {
            // Click logging is fire-and-forget — never block the user.
        }
    }

    /**
     * If the inbound URL is an event permalink, attach the event_id to
     * the click row for richer analytics. Otherwise null.
     */
    private static function guess_event_id_from_request() {
        global $wp;
        if ( ! $wp || empty( $wp->query_vars ) ) return null;
        if ( isset( $wp->query_vars['post_type'] ) && $wp->query_vars['post_type'] === 'ke_event' ) {
            $slug = $wp->query_vars['ke_event'] ?? $wp->query_vars['name'] ?? '';
            if ( $slug ) {
                $page = get_page_by_path( $slug, OBJECT, 'ke_event' );
                if ( $page ) return (int) $page->ID;
            }
        }
        return null;
    }

    /**
     * Build a promoter tracking URL.
     *
     * Per spec: tracking URL always points at the event permalink
     * (which already contains the event slug in path), with `?promo={slug}`
     * appended. If no event is given, fall back to the homepage.
     */
    public static function build_tracking_url( $promoter_slug, $event_id = null ) {
        $slug = sanitize_title( (string) $promoter_slug );
        if ( $slug === '' ) $slug = '{slug}';

        $base = home_url( '/' );
        if ( $event_id ) {
            $permalink = get_permalink( (int) $event_id );
            if ( $permalink ) {
                $base = $permalink;
            }
        }
        return add_query_arg( self::QUERY_PARAM, $slug, $base );
    }

    /**
     * Resolve the active promoter for $event_id to a display payload, if any.
     * Returns array{slug:string,name:string,id:int} or null.
     *
     * Single entry point for the badge, checkout-summary line, and email
     * paragraph — they all need a name + a "no attribution → bail" check,
     * and they all know the event they're rendering for.
     */
    public static function display_for_event( $event_id ) {
        $promoter = self::get_active_for_event( $event_id );
        if ( ! $promoter ) return null;
        $who  = self::display_for( $promoter );
        $name = $who['name'] !== '' ? $who['name'] : (string) $promoter->slug;
        return array(
            'slug' => (string) $promoter->slug,
            'name' => $name,
            'id'   => (int) $promoter->id,
        );
    }
}
