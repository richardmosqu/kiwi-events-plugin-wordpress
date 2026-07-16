<?php
/**
 * Promoter portal — set / reset password.
 *
 * Locals:
 *   $promoter : object|null
 *   $slug     : string
 *   $token    : string   (plaintext token from URL)
 *   $token_v  : bool     (true if token verifies for this promoter)
 *   $flash    : array|null
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$display_name = $promoter ? $promoter->name : __( 'Promoter Portal', 'kiwi-events' );
$page_title   = sprintf( '%s — %s', esc_html( $display_name ), esc_html( get_bloginfo( 'name' ) ) );
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
<body class="ke-promo-body">
    <div class="ke-promo-shell">
        <div class="ke-promo-card">
            <div class="ke-promo-card-header">
                <div class="ke-promo-logo" aria-hidden="true">🔐</div>
                <h1><?php esc_html_e( 'Set your password', 'kiwi-events' ); ?></h1>
                <?php if ( $promoter ) : ?>
                <p class="ke-promo-subtitle">
                    <?php echo esc_html( $promoter->name ); ?> · <?php echo esc_html( $promoter->email ); ?>
                </p>
                <?php endif; ?>
            </div>

            <?php if ( $flash ) : ?>
                <div class="ke-promo-flash ke-promo-flash--<?php echo esc_attr( $flash['type'] === 'success' ? 'success' : 'error' ); ?>">
                    <?php echo esc_html( $flash['message'] ); ?>
                </div>
            <?php endif; ?>

            <?php if ( ! $token_v ) : ?>
                <div class="ke-promo-flash ke-promo-flash--error">
                    <?php esc_html_e( 'This link has expired or is invalid. Request a new one.', 'kiwi-events' ); ?>
                </div>
                <div class="ke-promo-footer-links">
                    <a href="<?php echo esc_url( KE_Promoter_Portal::portal_url( $slug, 'forgot' ) ); ?>">
                        <?php esc_html_e( 'Request a new link →', 'kiwi-events' ); ?>
                    </a>
                </div>
            <?php else : ?>
                <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ke-promo-form" autocomplete="off">
                    <input type="hidden" name="action" value="ke_promoter_set_password">
                    <input type="hidden" name="slug"   value="<?php echo esc_attr( $slug ); ?>">
                    <input type="hidden" name="token"  value="<?php echo esc_attr( $token ); ?>">
                    <?php wp_nonce_field( 'ke_promoter_setpw_' . $slug ); ?>

                    <label class="ke-promo-label" for="ke-promo-newpw"><?php esc_html_e( 'New password', 'kiwi-events' ); ?></label>
                    <input type="password"
                           id="ke-promo-newpw"
                           name="password"
                           class="ke-promo-input"
                           required
                           minlength="8"
                           autocomplete="new-password" />

                    <label class="ke-promo-label" for="ke-promo-newpw2"><?php esc_html_e( 'Confirm password', 'kiwi-events' ); ?></label>
                    <input type="password"
                           id="ke-promo-newpw2"
                           name="password_confirm"
                           class="ke-promo-input"
                           required
                           minlength="8"
                           autocomplete="new-password" />

                    <p class="ke-promo-hint">
                        <?php esc_html_e( 'Must be at least 8 characters.', 'kiwi-events' ); ?>
                    </p>

                    <button type="submit" class="ke-promo-btn ke-promo-btn--primary">
                        <?php esc_html_e( 'Save password & sign in', 'kiwi-events' ); ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
