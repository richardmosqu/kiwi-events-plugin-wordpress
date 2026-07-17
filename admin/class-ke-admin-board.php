<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Community board — admin module.
 *
 * Moderation queue (Pendientes) + approved-events management (Publicadas):
 * approve/reject with optional reason, unpublish (back to pending), trash,
 * and per-comment moderation via WP's native comment API. Every action is
 * nonce-protected (per-post) and capability-gated. Users can never edit or
 * delete their own board events — moderation is admin-only.
 */
class KE_Admin_Board {

    public function init() {
        add_action( 'admin_post_ke_board_approve', array( $this, 'handle_approve' ) );
        add_action( 'admin_post_ke_board_reject', array( $this, 'handle_reject' ) );
        add_action( 'admin_post_ke_board_unpublish', array( $this, 'handle_unpublish' ) );
        add_action( 'admin_post_ke_board_trash', array( $this, 'handle_trash' ) );
        add_action( 'admin_post_ke_board_delete_comment', array( $this, 'handle_delete_comment' ) );
    }

    public function render() {
        require_once KE_PLUGIN_DIR . 'admin/views/board-moderation.php';
    }

    /* ─────────────────────────────────────────────
     *  Shared guards
     * ───────────────────────────────────────────── */

    private function validate_post_action( $nonce_action ) {
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

    private function redirect_back( $flash, $tab = 'pendientes' ) {
        set_transient( 'ke_board_flash_' . get_current_user_id(), $flash, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=ke-board&tab=' . rawurlencode( $tab ) ) );
        exit;
    }

    /* ─────────────────────────────────────────────
     *  Moderation actions
     * ───────────────────────────────────────────── */

    public function handle_approve() {
        $post = $this->validate_post_action( 'ke_board_approve' );

        $was_published = ( 'publish' === $post->post_status );
        wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'publish' ) );

        // Re-send guard: approving an already-approved post (or re-approving
        // after an unpublish) must not re-email the submitter.
        if ( ! $was_published && ! get_post_meta( $post->ID, '_ke_board_approval_email_sent', true ) ) {
            $this->notify_submitter( $post, 'board_approved' );
            update_post_meta( $post->ID, '_ke_board_approval_email_sent', 1 );
        }

        $this->redirect_back( 'Actividad aprobada y publicada.', 'publicadas' );
    }

    public function handle_reject() {
        $post   = $this->validate_post_action( 'ke_board_reject' );
        $reason = isset( $_POST['reject_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reject_reason'] ) ) : '';

        wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ) );
        if ( '' !== $reason ) {
            update_post_meta( $post->ID, '_ke_board_reject_reason', $reason );
        }
        $this->notify_submitter( $post, 'board_rejected', array( 'reason' => $reason ) );

        $this->redirect_back( 'Actividad rechazada (guardada como borrador).' );
    }

    public function handle_unpublish() {
        $post = $this->validate_post_action( 'ke_board_unpublish' );
        wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'pending' ) );
        // Allow a fresh approval email if it gets re-approved later on.
        delete_post_meta( $post->ID, '_ke_board_approval_email_sent' );
        $this->redirect_back( 'Actividad despublicada — volvió a la cola de revisión.' );
    }

    public function handle_trash() {
        $post = $this->validate_post_action( 'ke_board_trash' );
        wp_trash_post( $post->ID ); // soft delete — recoverable from Trash
        $this->redirect_back( 'Actividad enviada a la papelera.', 'publicadas' );
    }

    /**
     * Trash one comment on a board post. Native WP comment API only —
     * wp_trash_comment (recoverable), never custom SQL or hard deletes.
     */
    public function handle_delete_comment() {
        if ( ! current_user_can( 'moderate_comments' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No tienes permisos para moderar comentarios.', 403 );
        }
        $comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
        if ( ! $comment_id || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'ke_board_delete_comment_' . $comment_id ) ) {
            wp_die( 'Solicitud inválida (nonce).', 403 );
        }
        $comment = get_comment( $comment_id );
        $post    = $comment ? get_post( (int) $comment->comment_post_ID ) : null;
        if ( ! $comment || ! $post || KE_Board::POST_TYPE !== $post->post_type ) {
            wp_die( 'Comentario no encontrado.', 404 );
        }

        wp_trash_comment( $comment_id );
        $this->redirect_back( 'Comentario enviado a la papelera.', 'publicadas' );
    }

    /* ─────────────────────────────────────────────
     *  Notifications (queued; failure never blocks moderation)
     * ───────────────────────────────────────────── */

    private function notify_submitter( $post, $template, $extra_ctx = array() ) {
        $settings = KE_Board::get_settings();
        if ( empty( $settings['notify_user_decision'] ) ) {
            return;
        }
        if ( ! class_exists( 'KE_Email_Queue' ) || ! class_exists( 'KE_Email_Templates' ) ) {
            return;
        }
        $author = get_userdata( (int) $post->post_author );
        if ( ! $author || ! is_email( $author->user_email ) ) {
            return;
        }
        try {
            KE_Email_Queue::enqueue( $template, $author->user_email, array_merge( array(
                'first_name' => $author->display_name,
                'post_title' => $post->post_title,
                'post_url'   => get_permalink( $post->ID ),
            ), $extra_ctx ) );
        } catch ( \Throwable $e ) {
            error_log( 'KiwiEvents board: could not queue submitter notification — ' . $e->getMessage() );
        }
    }
}
