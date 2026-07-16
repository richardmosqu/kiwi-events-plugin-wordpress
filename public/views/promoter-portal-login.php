<?php
/**
 * Promoter portal — inline login (modal overlay).
 *
 * Rendered by KE_Promoter_Portal::handle_request() when no WP user is signed
 * in. The page paints a Kiwi-themed shell with a .ke-modal overlay containing
 * the email/password form. Submission posts JSON to /wp-json/ke/v1/promoter-login;
 * on success the page reloads and the handler falls through to the dashboard.
 *
 * Locals provided:
 *   $promoter      : object|null (may be null — do not leak slug existence)
 *   $slug          : string      (sanitized URL slug)
 *   $display_name  : string      (promoter name OR raw slug fallback)
 *   $flash         : array|null  ['type'=>'success|error','message'=>'...']
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$page_title = sprintf( '%s — %s', esc_html( $display_name ), esc_html( get_bloginfo( 'name' ) ) );
$home_url   = esc_url( home_url( '/' ) );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title><?php echo $page_title; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" />
    <?php wp_head(); ?>
</head>
<body class="ke-promo-body ke-promo-body--login">

    <div class="ke-promo-shell ke-promo-shell--blurred" aria-hidden="true">
        <div class="ke-promo-card ke-promo-card--ghost">
            <div class="ke-promo-card-header">
                <div class="ke-promo-logo">📣</div>
                <h1><?php echo esc_html( $display_name ); ?></h1>
                <p class="ke-promo-subtitle"><?php esc_html_e( 'Promoter Portal', 'kiwi-events' ); ?></p>
            </div>
        </div>
    </div>

    <div class="ke-modal" id="ke-promoter-login-modal" role="dialog" aria-modal="true"
         aria-labelledby="ke-promoter-login-title">
        <div class="ke-modal-backdrop" data-ke-login-close></div>
        <div class="ke-modal-dialog ke-modal-dialog-sm">
            <button type="button" class="ke-modal-close" data-ke-login-close
                    aria-label="<?php echo esc_attr__( 'Close', 'kiwi-events' ); ?>">&times;</button>

            <div class="ke-modal-body">
                <h2 id="ke-promoter-login-title" class="ke-modal-title">
                    <?php esc_html_e( 'Promoter sign-in', 'kiwi-events' ); ?>
                </h2>
                <p class="ke-modal-subtitle">
                    <?php
                    printf(
                        /* translators: %s: promoter display name */
                        esc_html__( 'Sign in to view %s\'s dashboard.', 'kiwi-events' ),
                        '<strong>' . esc_html( $display_name ) . '</strong>'
                    );
                    ?>
                </p>

                <?php if ( $flash ) : ?>
                    <div class="ke-promo-flash ke-promo-flash--<?php echo esc_attr( $flash['type'] === 'success' ? 'success' : 'error' ); ?>">
                        <?php echo esc_html( $flash['message'] ); ?>
                    </div>
                <?php endif; ?>

                <div class="ke-modal-error" id="ke-login-error" hidden></div>

                <form id="ke-promoter-login-form" autocomplete="on" novalidate>
                    <input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>" />

                    <label for="ke-login-email"><?php esc_html_e( 'Email', 'kiwi-events' ); ?></label>
                    <input type="email"
                           id="ke-login-email"
                           name="email"
                           required
                           autocomplete="username"
                           autofocus />

                    <label for="ke-login-pw"><?php esc_html_e( 'Password', 'kiwi-events' ); ?></label>
                    <input type="password"
                           id="ke-login-pw"
                           name="password"
                           required
                           autocomplete="current-password" />

                    <div class="ke-modal-footer">
                        <a href="<?php echo $home_url; ?>" class="ke-promo-btn ke-promo-btn--ghost" data-ke-login-close-link>
                            <?php esc_html_e( 'Cancel', 'kiwi-events' ); ?>
                        </a>
                        <button type="submit" class="ke-promo-btn ke-promo-btn--primary" id="ke-login-submit">
                            <?php esc_html_e( 'Sign in', 'kiwi-events' ); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php wp_footer(); ?>
</body>
</html>
