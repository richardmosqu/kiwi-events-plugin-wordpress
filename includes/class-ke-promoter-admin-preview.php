<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin → promoter dashboard preview (CHANGE 5).
 *
 * An admin with manage_kiwi_events can render any promoter's dashboard in
 * read-only "preview" mode. Entry is via /promoter/{slug}/?as={id}&admin_token={t}
 * — KE_Promoter_Portal::handle_request() consults is_admin_preview_request()
 * before rendering, swaps the promoter row, and renders the dashboard view
 * with $admin_preview = true. The view hides sign-out + write controls and
 * shows a banner identifying the previewing admin.
 *
 * Token format: base64url( HMAC(wp_salt('auth') . admin_user_id, "preview:{pid}:{exp}") )
 * + ":{exp}" — short TTL (default 15 min) so a stale URL can't be replayed.
 * The token binds to a specific admin so a leaked link from one admin's
 * mailbox can't be used by another wp_user with the same cap.
 */
class KE_Promoter_Admin_Preview {

    const TOKEN_TTL = 15 * MINUTE_IN_SECONDS;
    const AUDIT_TABLE = 'ke_promoter_admin_audit';

    /**
     * Generate a token URL the admin can click. Requires WP-admin auth at
     * mint time; the URL is also gated by WP-admin session at click time
     * (we check current_user_can again in handle_request).
     */
    public static function build_preview_url( $promoter_id, $admin_user_id = 0 ) {
        $promoter_id   = (int) $promoter_id;
        $admin_user_id = (int) ( $admin_user_id ?: get_current_user_id() );
        if ( ! $promoter_id || ! $admin_user_id ) return '';

        global $wpdb;
        $promoter = $wpdb->get_row( $wpdb->prepare(
            "SELECT slug FROM {$wpdb->prefix}ke_promoters WHERE id = %d",
            $promoter_id
        ) );
        if ( ! $promoter || empty( $promoter->slug ) ) return '';

        $exp   = time() + self::TOKEN_TTL;
        $token = self::sign( $promoter_id, $admin_user_id, $exp ) . ':' . $exp;

        return add_query_arg(
            array( 'as' => $promoter_id, 'admin_token' => $token ),
            home_url( '/promoter/' . rawurlencode( $promoter->slug ) . '/' )
        );
    }

    /**
     * Is the current request an admin previewing a promoter dashboard?
     * Returns the resolved promoter id (>0) on success, 0 otherwise.
     * Side-effect: writes an audit row on first successful resolution per
     * request (avoids duplicate rows when a view re-checks).
     */
    public static function resolve_preview_request() {
        static $resolved = null;
        if ( $resolved !== null ) return $resolved;

        $resolved = 0;
        $pid      = isset( $_GET['as'] ) ? absint( $_GET['as'] ) : 0;
        $token    = isset( $_GET['admin_token'] ) ? (string) $_GET['admin_token'] : '';
        if ( ! $pid || $token === '' ) return $resolved;

        // Must be a WP admin with cap. We don't expose preview to logged-out
        // users even with a valid token; the token only narrows WHICH promoter
        // the already-authenticated admin may view.
        if ( ! is_user_logged_in() ) return $resolved;
        if ( ! current_user_can( 'manage_kiwi_events' )
            && ! current_user_can( 'manage_options' ) ) return $resolved;

        $admin_user_id = (int) get_current_user_id();
        $parts = explode( ':', $token );
        if ( count( $parts ) !== 2 ) return $resolved;
        list( $sig, $exp ) = $parts;
        $exp = (int) $exp;
        if ( ! $exp || $exp < time() ) return $resolved;

        $expected = self::sign( $pid, $admin_user_id, $exp );
        if ( ! hash_equals( $expected, $sig ) ) return $resolved;

        self::log_audit( $admin_user_id, $pid );
        $resolved = $pid;
        return $resolved;
    }

    private static function sign( $promoter_id, $admin_user_id, $exp ) {
        $payload = sprintf( 'preview:%d:%d:%d', (int) $promoter_id, (int) $admin_user_id, (int) $exp );
        $hmac    = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
        // base64url-shorten so the URL doesn't balloon.
        return rtrim( strtr( base64_encode( hex2bin( $hmac ) ), '+/', '-_' ), '=' );
    }

    /**
     * Append-only audit log. Rate-limited per (admin, promoter) to one row
     * per 60 seconds so a reload doesn't generate a fresh row each time.
     */
    public static function log_audit( $admin_user_id, $promoter_id, $action = 'view_dashboard_preview' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::AUDIT_TABLE;
        $admin_user_id = (int) $admin_user_id;
        $promoter_id   = (int) $promoter_id;
        if ( ! $admin_user_id || ! $promoter_id ) return;

        $dedupe_key = 'ke_admin_preview_audit_' . $admin_user_id . '_' . $promoter_id . '_' . $action;
        if ( get_transient( $dedupe_key ) ) return;
        set_transient( $dedupe_key, 1, 60 );

        $user = get_userdata( $admin_user_id );
        $ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 250 ) : '';

        $wpdb->insert(
            $table,
            array(
                'admin_user_id' => $admin_user_id,
                'admin_login'   => $user ? (string) $user->user_login : '',
                'promoter_id'   => $promoter_id,
                'action'        => sanitize_key( $action ),
                'ip'            => $ip,
                'user_agent'    => $ua,
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
        );
    }
}
