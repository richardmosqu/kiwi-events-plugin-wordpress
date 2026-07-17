<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Board moderation queue — pending submissions with full content inline,
 * Approve / Reject actions (nonce + manage_kiwi_events).
 */

$ke_bm_flash = get_transient( 'ke_board_flash_' . get_current_user_id() );
if ( $ke_bm_flash ) {
    delete_transient( 'ke_board_flash_' . get_current_user_id() );
}

$ke_bm_pending = get_posts( array(
    'post_type'      => KE_Board::POST_TYPE,
    'post_status'    => 'pending',
    'posts_per_page' => -1,
    'orderby'        => 'date',
    'order'          => 'ASC',
) );

$ke_bm_published_count = (int) ( wp_count_posts( KE_Board::POST_TYPE )->publish ?? 0 );
?>
<div class="wrap ke-admin-wrap">
    <h1>Board — Moderación</h1>
    <p class="description">
        Las actividades enviadas por la comunidad no aparecen públicamente hasta que las apruebes.
        Publicadas actualmente: <strong><?php echo esc_html( number_format_i18n( $ke_bm_published_count ) ); ?></strong> ·
        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . KE_Board::POST_TYPE ) ); ?>">Ver todas</a>
    </p>

    <?php if ( $ke_bm_flash ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $ke_bm_flash ); ?></p></div>
    <?php endif; ?>

    <?php if ( empty( $ke_bm_pending ) ) : ?>

        <div style="padding:40px;text-align:center;border:2px dashed #ccd0d4;border-radius:12px;margin-top:16px;color:#646970;">
            <p style="font-size:15px;margin:0;">No hay actividades pendientes de revisión. 🎉</p>
        </div>

    <?php else : ?>

        <?php foreach ( $ke_bm_pending as $ke_bm_post ) :
            $ke_bm_id      = $ke_bm_post->ID;
            $ke_bm_thumb   = get_the_post_thumbnail_url( $ke_bm_id, 'medium' );
            $ke_bm_author  = get_userdata( (int) $ke_bm_post->post_author );
            $ke_bm_gallery = get_post_meta( $ke_bm_id, '_ke_board_gallery', true );
            $ke_bm_gallery = is_array( $ke_bm_gallery ) ? $ke_bm_gallery : array();
        ?>
        <div style="display:flex;gap:18px;flex-wrap:wrap;background:#fff;border:1px solid #ccd0d4;border-radius:12px;padding:18px;margin-top:16px;">
            <div style="flex:0 0 180px;">
                <?php if ( $ke_bm_thumb ) : ?>
                    <img src="<?php echo esc_url( $ke_bm_thumb ); ?>" alt="" style="width:180px;height:135px;object-fit:cover;border-radius:8px;">
                <?php else : ?>
                    <div style="width:180px;height:135px;border-radius:8px;background:#f0f0f1;display:flex;align-items:center;justify-content:center;color:#a7aaad;">Sin imagen</div>
                <?php endif; ?>
                <?php if ( ! empty( $ke_bm_gallery ) ) : ?>
                    <div style="display:flex;gap:4px;margin-top:6px;flex-wrap:wrap;">
                        <?php foreach ( $ke_bm_gallery as $ke_bm_gid ) :
                            $ke_bm_gurl = wp_get_attachment_image_url( (int) $ke_bm_gid, 'thumbnail' );
                            if ( ! $ke_bm_gurl ) continue;
                        ?>
                            <img src="<?php echo esc_url( $ke_bm_gurl ); ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:4px;">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div style="flex:1;min-width:260px;">
                <h2 style="margin:0 0 4px;font-size:17px;"><?php echo esc_html( $ke_bm_post->post_title ); ?></h2>
                <p style="margin:0 0 8px;color:#646970;font-size:12.5px;">
                    Enviada por <strong><?php echo esc_html( $ke_bm_author ? $ke_bm_author->display_name : '—' ); ?></strong>
                    (<?php echo esc_html( $ke_bm_author ? $ke_bm_author->user_email : '' ); ?>)
                    · <?php echo esc_html( get_the_date( '', $ke_bm_id ) . ' ' . get_the_time( '', $ke_bm_id ) ); ?>
                </p>

                <table class="widefat striped" style="max-width:640px;margin-bottom:10px;">
                    <tbody>
                        <tr><td style="width:140px;"><strong>Fecha / hora</strong></td>
                            <td><?php echo esc_html( KE_Board::format_datetime( $ke_bm_id ) ); ?></td></tr>
                        <tr><td><strong>Ubicación</strong></td>
                            <td><?php echo esc_html( get_post_meta( $ke_bm_id, '_ke_board_location', true ) ); ?></td></tr>
                        <tr><td><strong>Universidad</strong></td>
                            <td><?php echo esc_html( get_post_meta( $ke_bm_id, '_ke_board_university', true ) ); ?></td></tr>
                        <tr><td><strong>Organiza</strong></td>
                            <td><?php echo esc_html( get_post_meta( $ke_bm_id, '_ke_board_organizer', true ) ); ?></td></tr>
                        <tr><td><strong>Contacto (público)</strong></td>
                            <td><?php echo esc_html( get_post_meta( $ke_bm_id, '_ke_board_contact', true ) ); ?></td></tr>
                        <tr><td><strong>Descripción</strong></td>
                            <td><?php echo nl2br( esc_html( $ke_bm_post->post_content ) ); ?></td></tr>
                    </tbody>
                </table>

                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="ke_board_approve">
                        <input type="hidden" name="post_id" value="<?php echo esc_attr( $ke_bm_id ); ?>">
                        <?php wp_nonce_field( 'ke_board_approve_' . $ke_bm_id ); ?>
                        <button type="submit" class="button button-primary">✓ Aprobar y publicar</button>
                    </form>
                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
                          onsubmit="return confirm('¿Rechazar esta actividad? Se guardará como borrador y no será pública.');">
                        <input type="hidden" name="action" value="ke_board_reject">
                        <input type="hidden" name="post_id" value="<?php echo esc_attr( $ke_bm_id ); ?>">
                        <?php wp_nonce_field( 'ke_board_reject_' . $ke_bm_id ); ?>
                        <button type="submit" class="button">✕ Rechazar</button>
                    </form>
                    <a class="button button-link" href="<?php echo esc_url( get_edit_post_link( $ke_bm_id ) ); ?>">Editar antes de aprobar</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

    <?php endif; ?>
</div>
