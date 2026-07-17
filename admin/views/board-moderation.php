<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Board admin — Pendientes (moderation queue) + Publicadas (approved list
 * with engagement + comment moderation). Kiwi admin design system: tokens
 * only, .ke-section-card containers, glass ONLY on modals.
 */

$ke_bm_flash = get_transient( 'ke_board_flash_' . get_current_user_id() );
if ( $ke_bm_flash ) {
    delete_transient( 'ke_board_flash_' . get_current_user_id() );
}

$ke_bm_tab = isset( $_GET['tab'] ) && 'publicadas' === $_GET['tab'] ? 'publicadas' : 'pendientes';

/* ── Pendientes ── */
$ke_bm_pending = get_posts( array(
    'post_type'      => KE_Board::POST_TYPE,
    'post_status'    => 'pending',
    'posts_per_page' => -1,
    'orderby'        => 'date',
    'order'          => 'ASC',
) );

/* ── Publicadas — one query, caches primed, sorted in PHP after fetch ── */
$ke_bm_published = get_posts( array(
    'post_type'      => KE_Board::POST_TYPE,
    'post_status'    => 'publish',
    'posts_per_page' => -1,
) );
$ke_bm_all_ids = array_merge( wp_list_pluck( $ke_bm_pending, 'ID' ), wp_list_pluck( $ke_bm_published, 'ID' ) );
if ( ! empty( $ke_bm_all_ids ) ) {
    update_postmeta_cache( $ke_bm_all_ids );
}

$ke_bm_sort = isset( $_GET['orden'] ) ? sanitize_key( $_GET['orden'] ) : 'fecha';
usort( $ke_bm_published, static function ( $a, $b ) use ( $ke_bm_sort ) {
    switch ( $ke_bm_sort ) {
        case 'recientes':
            return strcmp( $b->post_date, $a->post_date );
        case 'likes':
            return KE_Board::get_like_count( $b->ID ) <=> KE_Board::get_like_count( $a->ID );
        case 'comentarios':
            return (int) $b->comment_count <=> (int) $a->comment_count;
        default: // 'fecha' — activity date, soonest first
            return strcmp(
                (string) get_post_meta( $a->ID, '_ke_board_datetime', true ),
                (string) get_post_meta( $b->ID, '_ke_board_datetime', true )
            );
    }
} );

$ke_bm_sorts = array(
    'fecha'       => 'Fecha de actividad',
    'recientes'   => 'Más recientes',
    'likes'       => 'Más likes',
    'comentarios' => 'Más comentados',
);
$ke_bm_base_url = admin_url( 'admin.php?page=ke-board' );
?>
<div class="wrap ke-admin-wrap ke-board-admin">

    <div class="ke-board-admin__header">
        <h1>Board</h1>
        <p class="ke-board-admin__sub">Las actividades enviadas por la comunidad no aparecen públicamente hasta que las apruebes.</p>
    </div>

    <?php if ( $ke_bm_flash ) : ?>
        <div class="ke-board-flash" role="status"><?php echo esc_html( $ke_bm_flash ); ?></div>
    <?php endif; ?>

    <nav class="ke-board-tabs" role="tablist">
        <a class="ke-board-tab<?php echo 'pendientes' === $ke_bm_tab ? ' is-active' : ''; ?>"
           href="<?php echo esc_url( add_query_arg( 'tab', 'pendientes', $ke_bm_base_url ) ); ?>">
            Pendientes
            <?php if ( count( $ke_bm_pending ) > 0 ) : ?>
                <span class="ke-board-tab__count"><?php echo esc_html( number_format_i18n( count( $ke_bm_pending ) ) ); ?></span>
            <?php endif; ?>
        </a>
        <a class="ke-board-tab<?php echo 'publicadas' === $ke_bm_tab ? ' is-active' : ''; ?>"
           href="<?php echo esc_url( add_query_arg( 'tab', 'publicadas', $ke_bm_base_url ) ); ?>">
            Publicadas
            <span class="ke-board-tab__count ke-board-tab__count--muted"><?php echo esc_html( number_format_i18n( count( $ke_bm_published ) ) ); ?></span>
        </a>
    </nav>

    <?php if ( 'pendientes' === $ke_bm_tab ) : ?>

        <?php if ( empty( $ke_bm_pending ) ) : ?>
            <div class="ke-section-card ke-board-empty-card">
                <p>No hay actividades pendientes de revisión. 🎉</p>
            </div>
        <?php else : ?>

            <?php foreach ( $ke_bm_pending as $ke_bm_post ) :
                $ke_bm_id      = $ke_bm_post->ID;
                $ke_bm_thumb   = get_the_post_thumbnail_url( $ke_bm_id, 'medium' );
                $ke_bm_author  = get_userdata( (int) $ke_bm_post->post_author );
                $ke_bm_gallery = get_post_meta( $ke_bm_id, '_ke_board_gallery', true );
                $ke_bm_gallery = is_array( $ke_bm_gallery ) ? $ke_bm_gallery : array();
                $ke_bm_preview = get_preview_post_link( $ke_bm_post );
            ?>
            <div class="ke-section-card ke-board-item" id="board-post-<?php echo esc_attr( $ke_bm_id ); ?>">
                <div class="ke-board-item__media">
                    <?php if ( $ke_bm_thumb ) : ?>
                        <img class="ke-board-item__thumb" src="<?php echo esc_url( $ke_bm_thumb ); ?>" alt="">
                    <?php else : ?>
                        <div class="ke-board-item__thumb ke-board-item__thumb--empty">Sin imagen</div>
                    <?php endif; ?>
                    <?php if ( ! empty( $ke_bm_gallery ) ) : ?>
                        <div class="ke-board-item__gallery">
                            <?php foreach ( $ke_bm_gallery as $ke_bm_gid ) :
                                $ke_bm_gurl = wp_get_attachment_image_url( (int) $ke_bm_gid, 'thumbnail' );
                                if ( ! $ke_bm_gurl ) continue;
                            ?>
                                <img src="<?php echo esc_url( $ke_bm_gurl ); ?>" alt="">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="ke-board-item__body">
                    <h2 class="ke-board-item__title"><?php echo esc_html( $ke_bm_post->post_title ); ?></h2>
                    <p class="ke-board-item__byline">
                        Enviada por <strong><?php echo esc_html( $ke_bm_author ? $ke_bm_author->display_name : '—' ); ?></strong>
                        <span class="ke-board-item__byline-mail"><?php echo esc_html( $ke_bm_author ? $ke_bm_author->user_email : '' ); ?></span>
                        · <?php echo esc_html( get_the_date( '', $ke_bm_id ) . ' ' . get_the_time( '', $ke_bm_id ) ); ?>
                    </p>

                    <dl class="ke-board-item__data">
                        <div><dt>Fecha / hora</dt><dd><?php echo esc_html( KE_Board::format_datetime( $ke_bm_id ) ); ?></dd></div>
                        <div><dt>Ubicación</dt><dd><?php echo esc_html( get_post_meta( $ke_bm_id, '_ke_board_location', true ) ); ?></dd></div>
                        <div><dt>Universidad</dt><dd><?php echo esc_html( get_post_meta( $ke_bm_id, '_ke_board_university', true ) ); ?></dd></div>
                        <div><dt>Organiza</dt><dd><?php echo esc_html( get_post_meta( $ke_bm_id, '_ke_board_organizer', true ) ); ?></dd></div>
                        <div><dt>Contacto (público)</dt><dd><?php echo esc_html( get_post_meta( $ke_bm_id, '_ke_board_contact', true ) ); ?></dd></div>
                        <div class="ke-board-item__data-desc"><dt>Descripción</dt><dd><?php echo nl2br( esc_html( $ke_bm_post->post_content ) ); ?></dd></div>
                    </dl>

                    <div class="ke-board-item__actions">
                        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="ke_board_approve">
                            <input type="hidden" name="post_id" value="<?php echo esc_attr( $ke_bm_id ); ?>">
                            <?php wp_nonce_field( 'ke_board_approve_' . $ke_bm_id ); ?>
                            <button type="submit" class="ke-btn ke-btn-primary">Aprobar y publicar</button>
                        </form>

                        <button type="button" class="ke-btn ke-btn-secondary ke-board-reject-open"
                                data-post-id="<?php echo esc_attr( $ke_bm_id ); ?>"
                                data-post-title="<?php echo esc_attr( $ke_bm_post->post_title ); ?>">Rechazar</button>

                        <?php if ( $ke_bm_preview ) : ?>
                            <a class="ke-btn ke-btn-ghost" href="<?php echo esc_url( $ke_bm_preview ); ?>" target="_blank" rel="noopener">Vista previa</a>
                        <?php endif; ?>
                        <a class="ke-btn ke-btn-ghost" href="<?php echo esc_url( get_edit_post_link( $ke_bm_id ) ); ?>">Editar antes de aprobar</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

        <?php endif; ?>

    <?php else : /* ── Publicadas ── */ ?>

        <div class="ke-board-toolbar">
            <label for="ke-board-sort">Ordenar por</label>
            <select id="ke-board-sort" class="ke-board-sort"
                    data-base-url="<?php echo esc_url( add_query_arg( 'tab', 'publicadas', $ke_bm_base_url ) ); ?>">
                <?php foreach ( $ke_bm_sorts as $ke_bm_key => $ke_bm_label ) : ?>
                    <option value="<?php echo esc_attr( $ke_bm_key ); ?>" <?php selected( $ke_bm_sort, $ke_bm_key ); ?>><?php echo esc_html( $ke_bm_label ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ( empty( $ke_bm_published ) ) : ?>
            <div class="ke-section-card ke-board-empty-card">
                <p>Aún no hay actividades publicadas.</p>
            </div>
        <?php else : ?>

            <?php foreach ( $ke_bm_published as $ke_bm_post ) :
                $ke_bm_id     = $ke_bm_post->ID;
                $ke_bm_thumb  = get_the_post_thumbnail_url( $ke_bm_id, 'thumbnail' );
                $ke_bm_author = get_userdata( (int) $ke_bm_post->post_author );
                $ke_bm_likes  = KE_Board::get_like_count( $ke_bm_id );
                $ke_bm_ncom   = (int) $ke_bm_post->comment_count;
            ?>
            <div class="ke-section-card ke-board-row" id="board-post-<?php echo esc_attr( $ke_bm_id ); ?>">
                <div class="ke-board-row__main">
                    <?php if ( $ke_bm_thumb ) : ?>
                        <img class="ke-board-row__thumb" src="<?php echo esc_url( $ke_bm_thumb ); ?>" alt="">
                    <?php else : ?>
                        <div class="ke-board-row__thumb ke-board-row__thumb--empty"></div>
                    <?php endif; ?>

                    <div class="ke-board-row__info">
                        <div class="ke-board-row__title-line">
                            <a class="ke-board-row__title" href="<?php echo esc_url( get_permalink( $ke_bm_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $ke_bm_post->post_title ); ?></a>
                            <?php if ( KE_Board::is_trending( $ke_bm_id ) ) : ?>
                                <span class="ke-board-badge-trending">Tendencia</span>
                            <?php endif; ?>
                        </div>
                        <p class="ke-board-row__meta">
                            <?php echo esc_html( KE_Board::format_datetime( $ke_bm_id ) ); ?>
                            <?php $ke_bm_loc = get_post_meta( $ke_bm_id, '_ke_board_location', true ); ?>
                            <?php if ( $ke_bm_loc ) : ?> · <?php echo esc_html( $ke_bm_loc ); ?><?php endif; ?>
                            · por <?php echo esc_html( $ke_bm_author ? $ke_bm_author->display_name : '—' ); ?>
                        </p>
                    </div>

                    <div class="ke-board-row__stats">
                        <span class="ke-board-stat" title="Likes">♥ <?php echo esc_html( number_format_i18n( $ke_bm_likes ) ); ?></span>
                        <span class="ke-board-stat" title="Comentarios">💬 <?php echo esc_html( number_format_i18n( $ke_bm_ncom ) ); ?></span>
                    </div>

                    <div class="ke-board-row__actions">
                        <button type="button" class="ke-btn ke-btn-secondary ke-board-comments-toggle"
                                data-target="ke-board-comments-<?php echo esc_attr( $ke_bm_id ); ?>"
                                <?php disabled( 0 === $ke_bm_ncom ); ?>>Ver comentarios</button>

                        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="ke_board_unpublish">
                            <input type="hidden" name="post_id" value="<?php echo esc_attr( $ke_bm_id ); ?>">
                            <?php wp_nonce_field( 'ke_board_unpublish_' . $ke_bm_id ); ?>
                            <button type="submit" class="ke-btn ke-btn-ghost">Despublicar</button>
                        </form>

                        <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ke-board-trash-form"
                              data-post-title="<?php echo esc_attr( $ke_bm_post->post_title ); ?>">
                            <input type="hidden" name="action" value="ke_board_trash">
                            <input type="hidden" name="post_id" value="<?php echo esc_attr( $ke_bm_id ); ?>">
                            <?php wp_nonce_field( 'ke_board_trash_' . $ke_bm_id ); ?>
                            <button type="submit" class="ke-btn ke-btn-danger">Eliminar</button>
                        </form>
                    </div>
                </div>

                <?php if ( $ke_bm_ncom > 0 ) : ?>
                <div class="ke-board-comments-panel" id="ke-board-comments-<?php echo esc_attr( $ke_bm_id ); ?>" hidden>
                    <?php
                    // Native comment API only. Rendered lazily-in-markup but
                    // the panel is hidden until toggled; counts above come
                    // from the posts table (comment_count), not extra queries.
                    $ke_bm_comments = get_comments( array( 'post_id' => $ke_bm_id, 'status' => 'approve' ) );
                    ?>
                    <?php foreach ( $ke_bm_comments as $ke_bm_c ) : ?>
                        <div class="ke-board-comment">
                            <?php echo get_avatar( $ke_bm_c, 32, '', '', array( 'class' => 'ke-board-comment__avatar' ) ); ?>
                            <div class="ke-board-comment__body">
                                <p class="ke-board-comment__head">
                                    <strong><?php echo esc_html( $ke_bm_c->comment_author ); ?></strong>
                                    <span><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ke_bm_c->comment_date ) ); ?></span>
                                </p>
                                <p class="ke-board-comment__text"><?php echo esc_html( $ke_bm_c->comment_content ); ?></p>
                            </div>
                            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ke-board-comment__delete">
                                <input type="hidden" name="action" value="ke_board_delete_comment">
                                <input type="hidden" name="comment_id" value="<?php echo esc_attr( $ke_bm_c->comment_ID ); ?>">
                                <?php wp_nonce_field( 'ke_board_delete_comment_' . $ke_bm_c->comment_ID ); ?>
                                <button type="submit" class="ke-btn ke-btn-ghost ke-board-comment__delete-btn" title="Enviar comentario a la papelera">Eliminar</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    <p class="ke-board-comments-panel__foot">
                        <a href="<?php echo esc_url( admin_url( 'edit-comments.php?p=' . $ke_bm_id ) ); ?>">Moderación completa de comentarios en WordPress →</a>
                    </p>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

        <?php endif; ?>

    <?php endif; ?>

    <!-- Reject modal (glass — overlays only) -->
    <div class="ke-board-modal" id="ke-board-reject-modal" hidden>
        <div class="ke-board-modal__overlay" data-modal-close></div>
        <div class="ke-board-modal__panel" role="dialog" aria-modal="true" aria-labelledby="ke-board-reject-title">
            <h2 id="ke-board-reject-title">Rechazar actividad</h2>
            <p class="ke-board-modal__sub" id="ke-board-reject-subtitle"></p>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ke-board-reject-form">
                <input type="hidden" name="action" value="ke_board_reject">
                <input type="hidden" name="post_id" value="" id="ke-board-reject-post-id">
                <span id="ke-board-reject-nonce-slot"></span>
                <label for="ke-board-reject-reason">Motivo (opcional — se incluirá en el correo al autor)</label>
                <textarea name="reject_reason" id="ke-board-reject-reason" rows="3" placeholder="Ej.: La imagen no corresponde a la actividad."></textarea>
                <div class="ke-board-modal__actions">
                    <button type="button" class="ke-btn ke-btn-secondary" data-modal-close>Cancelar</button>
                    <button type="submit" class="ke-btn ke-btn-danger">Rechazar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hidden per-post reject nonces (moved into the modal form on open) -->
    <?php foreach ( $ke_bm_pending as $ke_bm_post ) : ?>
        <div hidden id="ke-board-reject-nonce-<?php echo esc_attr( $ke_bm_post->ID ); ?>"><?php wp_nonce_field( 'ke_board_reject_' . $ke_bm_post->ID, '_wpnonce', false ); ?></div>
    <?php endforeach; ?>

    <!-- Trash confirm modal -->
    <div class="ke-board-modal" id="ke-board-trash-modal" hidden>
        <div class="ke-board-modal__overlay" data-modal-close></div>
        <div class="ke-board-modal__panel" role="dialog" aria-modal="true" aria-labelledby="ke-board-trash-title">
            <h2 id="ke-board-trash-title">Eliminar actividad</h2>
            <p class="ke-board-modal__sub" id="ke-board-trash-subtitle"></p>
            <p>Se enviará a la papelera de WordPress (recuperable). Dejará de ser visible en el board.</p>
            <div class="ke-board-modal__actions">
                <button type="button" class="ke-btn ke-btn-secondary" data-modal-close>Cancelar</button>
                <button type="button" class="ke-btn ke-btn-danger" id="ke-board-trash-confirm">Eliminar</button>
            </div>
        </div>
    </div>

</div>
