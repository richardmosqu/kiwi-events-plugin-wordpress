<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * KE_Admin_Color_Mode
 * ---------------------------------------------------------------------------
 * Per-user light/dark mode preference for the Kiwi Events ADMIN ONLY.
 *
 *   • Stored as user_meta('ke_admin_color_mode'): 'light' | 'dark'.
 *     Default is 'light' for any user without the meta (incl. new admins
 *     and incognito sessions). No OS-preference auto-detect.
 *   • Saved via admin-ajax (action: ke_save_color_mode).
 *   • Applied by setting <html data-theme="dark"> at admin_head priority 1,
 *     SCOPED to Kiwi admin pages only. WordPress core pages, the public site,
 *     the promoter portal, and email templates are never affected — they
 *     never receive the attribute.
 *   • The token remap that drives the actual color flip lives in
 *     admin/css/ke-admin-tokens.css under `:root[data-theme="dark"]`.
 *
 * To prevent a flash of light-mode chrome before the stylesheet finishes
 * loading on slow connections, we inline a single CSS rule at admin_head
 * priority 1 that paints <html> + <body> immediately with the dark page bg.
 * That inline rule is removed in admin_footer once DOMContentLoaded fires
 * (the proper token-driven background takes over from there).
 */
class KE_Admin_Color_Mode {

    const META_KEY = 'ke_admin_color_mode';
    const AJAX_ACTION = 'ke_save_color_mode';
    const NONCE_ACTION = 'ke_color_mode_nonce';

    public function init() {
        add_action( 'admin_head', array( $this, 'inject_data_theme' ), 1 );
        add_action( 'admin_footer', array( $this, 'remove_flash_fix' ), 100 );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_ajax_save' ) );
    }

    /**
     * Read the current user's color-mode preference. Returns 'light' for
     * logged-out / no-meta users so new sessions always start light.
     *
     * @return string 'light' | 'dark'
     */
    public static function get_user_mode() {
        if ( ! is_user_logged_in() ) return 'light';
        $mode = get_user_meta( get_current_user_id(), self::META_KEY, true );
        return in_array( $mode, array( 'light', 'dark' ), true ) ? $mode : 'light';
    }

    /**
     * Whether the supplied (or current) screen is a Kiwi Events admin page.
     *
     * Mirrors the scope used by KE_Admin::enqueue_assets so the data-theme
     * attribute is set on exactly the pages whose CSS we own. The check
     * deliberately rejects every WP core screen, the user profile screen,
     * the plugins / settings / posts screens, etc.
     */
    public static function is_kiwi_admin_page( $screen = null ) {
        if ( $screen === null ) {
            if ( ! function_exists( 'get_current_screen' ) ) return false;
            $screen = get_current_screen();
        }
        if ( ! $screen ) return false;

        // Exact list of menu/submenu hooks owned by KE_Admin.
        $plugin_pages = array(
            'toplevel_page_kiwi-events',
            'kiwievents_page_kiwi-events-attendees',
            'kiwievents_page_kiwi-events-reservations',
            'admin_page_ke-events-list',
            'kiwievents_page_ke-event-builder',
            'kiwievents_page_ke-categories',
            'kiwievents_page_ke-organizers',
            'kiwievents_page_ke-promoters',
            'kiwievents_page_ke-settings',
        );
        if ( in_array( $screen->id, $plugin_pages, true ) ) return true;

        // ke_event CPT screens (post list + post edit redirect both fall
        // through this filter before redirect_to_custom_builder fires).
        if ( isset( $screen->post_type ) && $screen->post_type === 'ke_event' ) {
            return true;
        }

        // KE taxonomies (organizers / event categories / tags).
        if ( in_array( $screen->id, array(
            'edit-ke_event_category',
            'edit-ke_event_tag',
            'edit-ke_organizer',
        ), true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Emit the <script> that sets data-theme on <html>, plus a one-shot
     * inline <style> that paints the dark page bg immediately so there is
     * no flash of cream before ke-admin-tokens.css resolves.
     */
    public function inject_data_theme() {
        if ( ! self::is_kiwi_admin_page() ) return;
        if ( self::get_user_mode() !== 'dark' ) return;
        ?>
<script>document.documentElement.setAttribute('data-theme','dark');</script>
<style id="ke-dark-flash-fix">html,body{background:#0f0f10 !important;}</style>
<?php
    }

    /**
     * Clean up the flash-fix <style> once the page has loaded. From this
     * point on, the regular --kiwi-bg token (set on .ke-admin-wrap etc.)
     * drives the page bg.
     */
    public function remove_flash_fix() {
        if ( ! self::is_kiwi_admin_page() ) return;
        if ( self::get_user_mode() !== 'dark' ) return;
        ?>
<script>document.addEventListener('DOMContentLoaded',function(){var n=document.getElementById('ke-dark-flash-fix');if(n)n.remove();});</script>
<?php
    }

    /**
     * Persist a light/dark choice on the current user.
     */
    public function handle_ajax_save() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'reason' => 'not_logged_in' ), 401 );
        }
        if ( ! current_user_can( 'manage_kiwi_events' ) ) {
            wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
        }

        $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'light';
        if ( ! in_array( $mode, array( 'light', 'dark' ), true ) ) {
            wp_send_json_error( array( 'reason' => 'invalid_mode' ), 400 );
        }

        update_user_meta( get_current_user_id(), self::META_KEY, $mode );

        wp_send_json_success( array( 'mode' => $mode ) );
    }
}
