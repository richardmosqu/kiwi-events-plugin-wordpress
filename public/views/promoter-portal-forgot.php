<?php
/**
 * Promoter portal — forgot password form.
 *
 * Locals:
 *   $promoter, $slug, $flash
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
                <div class="ke-promo-logo" aria-hidden="true">🔑</div>
                <h1><?php esc_html_e( 'Reset password', 'kiwi-events' ); ?></h1>
                <p class="ke-promo-subtitle">
                    <?php esc_html_e( 'We\'ll email you a link to choose a new password.', 'kiwi-events' ); ?>
                </p>
            </div>

            <?php if ( $flash ) : ?>
                <div class="ke-promo-flash ke-promo-flash--<?php echo esc_attr( $flash['type'] === 'success' ? 'success' : 'error' ); ?>">
                    <?php echo esc_html( $flash['message'] ); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ke-promo-form" autocomplete="on">
                <input type="hidden" name="action" value="ke_promoter_forgot">
                <input type="hidden" name="slug"   value="<?php echo esc_attr( $slug ); ?>">
                <?php wp_nonce_field( 'ke_promoter_forgot_' . $slug ); ?>

                <label class="ke-promo-label" for="ke-promo-forgot-email"><?php esc_html_e( 'Email', 'kiwi-events' ); ?></label>
                <input type="email"
                       id="ke-promo-forgot-email"
                       name="email"
                       class="ke-promo-input"
                       required
                       autocomplete="email" />

                <button type="submit" class="ke-promo-btn ke-promo-btn--primary">
                    <?php esc_html_e( 'Send reset link', 'kiwi-events' ); ?>
                </button>

                <div class="ke-promo-footer-links">
                    <a href="<?php echo esc_url( KE_Promoter_Portal::portal_url( $slug ) ); ?>">
                        <?php esc_html_e( '← Back to sign-in', 'kiwi-events' ); ?>
                    </a>
                </div>
            </form>
        </div>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
