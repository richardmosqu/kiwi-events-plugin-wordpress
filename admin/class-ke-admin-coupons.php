<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * wp-admin → KiwiEvents → Cupones.
 *
 * List + create/edit form for per-event discount coupons. Writes go through
 * admin-post handlers so the page can redirect after saving (post/redirect/get)
 * instead of re-submitting on refresh; the page render itself is read-only.
 *
 * Notices and the values of a rejected submission travel in a short-lived
 * per-user transient rather than the URL, so an error message can never be
 * shaped by whoever crafts the link.
 */
class KE_Admin_Coupons {

    const NOTICE_PREFIX = 'ke_coupon_notice_';

    public function init() {
        add_action( 'admin_post_ke_coupon_save',   array( $this, 'handle_save' ) );
        add_action( 'admin_post_ke_coupon_delete', array( $this, 'handle_delete' ) );
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Notices
     * ────────────────────────────────────────────────────────────────── */

    private static function notice_key() {
        return self::NOTICE_PREFIX . get_current_user_id();
    }

    private static function set_notice( $type, $text, $form = null ) {
        set_transient( self::notice_key(), array(
            'type' => $type === 'success' ? 'success' : 'error',
            'text' => (string) $text,
            'form' => is_array( $form ) ? $form : null,
        ), 60 );
    }

    private static function take_notice() {
        $key    = self::notice_key();
        $notice = get_transient( $key );
        delete_transient( $key );
        return is_array( $notice ) ? $notice : null;
    }

    private static function redirect_to_list() {
        wp_safe_redirect( admin_url( 'admin.php?page=ke-coupons' ) );
        exit;
    }

    private static function redirect_to_form( $coupon_id = 0 ) {
        $url = admin_url( 'admin.php?page=ke-coupons&action=edit' );
        if ( $coupon_id > 0 ) {
            $url = add_query_arg( 'coupon', (int) $coupon_id, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Write handlers
     * ────────────────────────────────────────────────────────────────── */

    public function handle_save() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to manage coupons.', 'kiwi-events' ), 403 );
        }
        check_admin_referer( 'ke_coupon_save' );

        $data = array(
            'id'             => absint( $_POST['coupon_id'] ?? 0 ),
            'code'           => sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ),
            'event_id'       => absint( $_POST['event_id'] ?? 0 ),
            'discount_type'  => sanitize_key( wp_unslash( $_POST['discount_type'] ?? '' ) ),
            'amount'         => (float) str_replace( ',', '.', (string) ( $_POST['amount'] ?? 0 ) ),
            'expires'        => sanitize_text_field( wp_unslash( $_POST['expires'] ?? '' ) ),
            'max_tickets'    => absint( $_POST['max_tickets'] ?? 0 ),
            'limit_per_user' => absint( $_POST['limit_per_user'] ?? 0 ),
            'minimum_amount' => (float) str_replace( ',', '.', (string) ( $_POST['minimum_amount'] ?? 0 ) ),
            'description'    => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'active'         => ! empty( $_POST['active'] ),
        );

        $result = KE_Coupons::save( $data );
        if ( is_wp_error( $result ) ) {
            // Hand the submitted values back so nothing typed is lost.
            self::set_notice( 'error', $result->get_error_message(), $data );
            self::redirect_to_form( $data['id'] );
        }

        self::set_notice(
            'success',
            $data['id'] > 0
                ? __( 'Coupon updated.', 'kiwi-events' )
                : __( 'Coupon created. Customers enter the code in the coupon box at checkout.', 'kiwi-events' )
        );
        self::redirect_to_list();
    }

    public function handle_delete() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to manage coupons.', 'kiwi-events' ), 403 );
        }
        $coupon_id = absint( $_GET['coupon'] ?? 0 );
        check_admin_referer( 'ke_coupon_delete_' . $coupon_id );

        $result = KE_Coupons::delete( $coupon_id );
        if ( is_wp_error( $result ) ) {
            self::set_notice( 'error', $result->get_error_message() );
        } else {
            self::set_notice( 'success', __( 'Coupon deleted.', 'kiwi-events' ) );
        }
        self::redirect_to_list();
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Render
     * ────────────────────────────────────────────────────────────────── */

    public function render() {
        $wc_active   = class_exists( 'WooCommerce' ) && class_exists( 'WC_Coupon' );
        $notice      = self::take_notice();
        $action      = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
        $coupon_id   = absint( $_GET['coupon'] ?? 0 );
        $is_form     = in_array( $action, array( 'edit', 'new' ), true );

        $coupons = $wc_active ? KE_Coupons::all() : array();

        // Events for the picker: anything that has ever been public, newest first.
        $events = get_posts( array(
            'post_type'      => 'ke_event',
            'post_status'    => array( 'publish', 'private', 'draft' ),
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );

        // Form values: a rejected submission wins, then the stored coupon,
        // then the defaults for a new one.
        $editing = ( $wc_active && $coupon_id > 0 ) ? KE_Coupons::get( $coupon_id ) : null;
        $form    = array(
            'id'             => $editing ? $editing['id'] : 0,
            'code'           => $editing ? $editing['code'] : ( $wc_active ? KE_Coupons::suggest_code() : '' ),
            'event_id'       => $editing ? $editing['event_id'] : 0,
            'discount_type'  => $editing ? $editing['discount_type'] : 'percent',
            'amount'         => $editing ? $editing['amount'] : '',
            'expires'        => $editing ? $editing['expires'] : '',
            'max_tickets'    => $editing ? $editing['max_tickets'] : 0,
            'limit_per_user' => $editing ? $editing['limit_per_user'] : 0,
            'minimum_amount' => $editing ? $editing['minimum_amount'] : 0,
            'description'    => $editing ? $editing['description'] : '',
            'active'         => $editing ? $editing['active'] : true,
        );
        if ( $notice && $notice['type'] === 'error' && ! empty( $notice['form'] ) ) {
            $form = array_merge( $form, $notice['form'] );
            $is_form = true;
        }

        $coupon_box_on = $wc_active && KE_Coupons::checkout_coupons_enabled();

        include KE_PLUGIN_DIR . 'admin/views/coupons.php';
    }
}
