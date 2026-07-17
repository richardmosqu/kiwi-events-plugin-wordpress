<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Community board — admin moderation queue.
 *
 * Pending submissions are reviewed here: full content inline (no extra
 * clicks), Approve → publish, Reject → draft. Both actions are
 * nonce-protected and gated on manage_kiwi_events. Users can never edit
 * or delete their own board events (explicit product decision) — only
 * admins act on them.
 */
class KE_Admin_Board {

    public function init() {
        add_action( 'admin_post_ke_board_approve', array( $this, 'handle_approve' ) );
        add_action( 'admin_post_ke_board_reject', array( $this, 'handle_reject' ) );
    }

    public function render() {
        require_once KE_PLUGIN_DIR . 'admin/views/board-moderation.php';
    }

    /* ─────────────────────────────────────────────
     *  Approve / Reject
     * ───────────────────────────────────────────── */

    private function validate_action_request( $nonce_action ) {
        if ( ! current_user_can( 'manage_kiwi_events' ) ) {
            wp_die( 'No tienes permisos para esta acción.', 403 );
        }
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], $nonce_action . '_' . $post_id ) ) {
            wp_die( 'Solicitud inválida (nonce).', 403 );
        }
        $post = get_post( $post_id );
        if ( ! $post || KE_Board::POST_TYPE !== $post->post_type ) {
            wp_die( 'Publicación no encontrada.', 404 );
        }
        return $post;
    }

    public function handle_approve() {
        $post = $this->validate_action_request( 'ke_board_approve' );

        wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'publish' ) );
        $this->notify_submitter( $post, 'board_approved' );

        set_transient( 'ke_board_flash_' . get_current_user_id(), 'Actividad aprobada y publicada.', 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ke-board' ) );
        exit;
    }

    public function handle_reject() {
        $post = $this->validate_action_request( 'ke_board_reject' );

        wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ) );
        $this->notify_submitter( $post, 'board_rejected' );

        set_transient( 'ke_board_flash_' . get_current_user_id(), 'Actividad rechazada (guardada como borrador).', 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ke-board' ) );
        exit;
    }

    /**
     * Queue an approved/rejected notification to the submitter via the
     * existing email queue (retries + logging for free). Failure to queue
     * must never block moderation.
     */
    private function notify_submitter( $post, $template ) {
        if ( ! class_exists( 'KE_Email_Queue' ) || ! class_exists( 'KE_Email_Templates' ) ) {
            return;
        }
        $author = get_userdata( (int) $post->post_author );
        if ( ! $author || ! is_email( $author->user_email ) ) {
            return;
        }
        try {
            KE_Email_Queue::enqueue( $template, $author->user_email, array(
                'first_name' => $author->display_name,
                'post_title' => $post->post_title,
                'post_url'   => get_permalink( $post->ID ),
            ) );
        } catch ( \Throwable $e ) {
            error_log( 'KiwiEvents board: could not queue submitter notification — ' . $e->getMessage() );
        }
    }
}
