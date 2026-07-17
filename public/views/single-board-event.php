<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Single board activity — /board/[slug]/. No tickets, no checkout, no fees.
 */
get_header();

$board_id   = $post->ID;
$thumb      = get_the_post_thumbnail_url( $board_id, 'large' );
$location   = get_post_meta( $board_id, '_ke_board_location', true );
$university = get_post_meta( $board_id, '_ke_board_university', true );
$organizer  = get_post_meta( $board_id, '_ke_board_organizer', true );
$contact    = get_post_meta( $board_id, '_ke_board_contact', true );
$gallery    = get_post_meta( $board_id, '_ke_board_gallery', true );
$gallery    = is_array( $gallery ) ? array_filter( array_map( 'intval', $gallery ) ) : array();
$likes      = KE_Board::get_like_count( $board_id );
$has_liked  = is_user_logged_in() && KE_Board::user_has_liked( $board_id, get_current_user_id() );
?>
<div class="ke-board">
    <article class="ke-board-single">

        <?php if ( $thumb ) : ?>
            <div class="ke-board-single__hero">
                <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( get_the_title( $board_id ) ); ?>">
                <?php if ( KE_Board::is_trending( $board_id ) ) : ?>
                    <span class="ke-board-trending">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 17 6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>
                        Tendencia
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <h1 class="ke-board-single__title"><?php echo esc_html( get_the_title( $board_id ) ); ?></h1>

        <div class="ke-board-single__meta">
            <span class="ke-board-single__meta-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg>
                <?php echo esc_html( KE_Board::format_datetime( $board_id ) ); ?>
            </span>
            <?php if ( $location ) : ?>
                <span class="ke-board-single__meta-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-5.1-7-11a7 7 0 0 1 14 0c0 5.9-7 11-7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                    <?php echo esc_html( $location ); ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="ke-board-single__like">
            <?php if ( is_user_logged_in() ) : ?>
                <button type="button"
                        class="ke-board-like<?php echo $has_liked ? ' is-liked' : ''; ?>"
                        data-ke-board-like="<?php echo esc_attr( $board_id ); ?>"
                        aria-pressed="<?php echo $has_liked ? 'true' : 'false'; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 14c1.5-1.5 3-3.3 3-5.5A4.5 4.5 0 0 0 17.5 4c-1.8 0-3.4 1-4.5 2.5A5.4 5.4 0 0 0 8.5 4 4.5 4.5 0 0 0 4 8.5c0 2.2 1.5 4 3 5.5l5 5Z"/></svg>
                    <span data-ke-board-like-label><?php echo $has_liked ? 'Te gusta' : 'Me gusta'; ?></span>
                    <span class="ke-board-like__count" data-ke-board-like-count><?php echo esc_html( number_format_i18n( $likes ) ); ?></span>
                </button>
            <?php else : ?>
                <a class="ke-board-like ke-board-like--login" href="<?php echo esc_url( KE_Board::login_redirect_url( get_permalink() ) ); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 14c1.5-1.5 3-3.3 3-5.5A4.5 4.5 0 0 0 17.5 4c-1.8 0-3.4 1-4.5 2.5A5.4 5.4 0 0 0 8.5 4 4.5 4.5 0 0 0 4 8.5c0 2.2 1.5 4 3 5.5l5 5Z"/></svg>
                    <span>Inicia sesión para dar like</span>
                    <span class="ke-board-like__count"><?php echo esc_html( number_format_i18n( $likes ) ); ?></span>
                </a>
            <?php endif; ?>
        </div>

        <div class="ke-board-single__description">
            <?php echo wp_kses_post( wpautop( esc_html( $post->post_content ) ) ); ?>
        </div>

        <?php if ( ! empty( $gallery ) ) : ?>
            <div class="ke-board-single__gallery">
                <?php foreach ( $gallery as $img_id ) :
                    $full  = wp_get_attachment_image_url( $img_id, 'large' );
                    $thumb_sm = wp_get_attachment_image_url( $img_id, 'medium' );
                    if ( ! $thumb_sm ) continue;
                ?>
                    <a href="<?php echo esc_url( $full ?: $thumb_sm ); ?>" target="_blank" rel="noopener" class="ke-board-single__gallery-item">
                        <img src="<?php echo esc_url( $thumb_sm ); ?>" alt="" loading="lazy">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="ke-board-organiza-card">
            <h2>Organiza</h2>
            <dl>
                <?php if ( $university ) : ?>
                    <div class="ke-board-organiza-card__row"><dt>Universidad</dt><dd><?php echo esc_html( $university ); ?></dd></div>
                <?php endif; ?>
                <?php if ( $organizer ) : ?>
                    <div class="ke-board-organiza-card__row"><dt>Grupo / persona</dt><dd><?php echo esc_html( $organizer ); ?></dd></div>
                <?php endif; ?>
                <?php if ( $contact ) : ?>
                    <div class="ke-board-organiza-card__row"><dt>Contacto</dt>
                        <dd>
                            <?php if ( is_email( $contact ) ) : ?>
                                <a href="mailto:<?php echo esc_attr( $contact ); ?>"><?php echo esc_html( $contact ); ?></a>
                            <?php else : ?>
                                <?php echo esc_html( $contact ); ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>

        <section class="ke-board-comments" id="comentarios">
            <h2>Comentarios <span class="ke-board-comments__count">(<?php echo esc_html( number_format_i18n( get_comments_number( $board_id ) ) ); ?>)</span></h2>

            <?php if ( have_comments() || get_comments_number( $board_id ) > 0 ) : ?>
                <ol class="ke-board-comments__list">
                    <?php
                    wp_list_comments( array(
                        'style'       => 'ol',
                        'avatar_size' => 40,
                        'short_ping'  => true,
                    ), get_comments( array( 'post_id' => $board_id, 'status' => 'approve' ) ) );
                    ?>
                </ol>
            <?php else : ?>
                <p class="ke-board-comments__empty">Sé la primera persona en comentar.</p>
            <?php endif; ?>

            <?php if ( is_user_logged_in() ) : ?>
                <?php
                comment_form( array(
                    'title_reply'        => 'Deja un comentario',
                    'label_submit'       => 'Comentar',
                    'class_container'    => 'ke-board-comment-form',
                    'class_submit'       => 'ke-board-btn ke-board-btn--primary',
                    'comment_notes_before' => '',
                    'logged_in_as'       => '',
                ), $board_id );
                ?>
            <?php else : ?>
                <div class="ke-board-comments__gate">
                    <p>Debes iniciar sesión para comentar.</p>
                    <a class="ke-board-btn ke-board-btn--secondary" href="<?php echo esc_url( KE_Board::login_redirect_url( get_permalink() . '#comentarios' ) ); ?>">Iniciar sesión</a>
                </div>
            <?php endif; ?>
        </section>

    </article>
</div>
<?php get_footer(); ?>
