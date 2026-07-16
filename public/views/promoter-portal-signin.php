<?php
/**
 * Promoter portal — sign-in.
 *
 * Locals (set by KE_Promoter_Portal::handle_request):
 *   $promoter : object|null   (promoter row from ke_promoters, may be null if slug doesn't exist)
 *   $slug     : string        (sanitized slug from the URL)
 *   $flash    : array|null    ['type'=>'success|error','message'=>'...']
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Don't leak whether the slug exists. Render the same shell either way.
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
                <div class="ke-promo-logo" aria-hidden="true">📣</div>
                <h1><?php echo esc_html( $display_name ); ?></h1>
                <p class="ke-promo-subtitle"><?php esc_html_e( 'Promoter sign-in', 'kiwi-events' ); ?></p>
            </div>

            <?php if ( $flash ) : ?>
                <div class="ke-promo-flash ke-promo-flash--<?php echo esc_attr( $flash['type'] === 'success' ? 'success' : 'error' ); ?>">
                    <?php echo esc_html( $flash['message'] ); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ke-promo-form" autocomplete="on">
                <input type="hidden" name="action" value="ke_promoter_signin">
                <input type="hidden" name="slug"   value="<?php echo esc_attr( $slug ); ?>">
                <?php wp_nonce_field( 'ke_promoter_signin_' . $slug ); ?>

                <label class="ke-promo-label" for="ke-promo-email"><?php esc_html_e( 'Email', 'kiwi-events' ); ?></label>
                <input type="email"
                       id="ke-promo-email"
                       name="email"
                       class="ke-promo-input"
                       required
                       autocomplete="username" />

                <label class="ke-promo-label" for="ke-promo-pw"><?php esc_html_e( 'Password', 'kiwi-events' ); ?></label>
                <input type="password"
                       id="ke-promo-pw"
                       name="password"
                       class="ke-promo-input"
                       required
                       autocomplete="current-password" />

                <button type="submit" class="ke-promo-btn ke-promo-btn--primary">
                    <?php esc_html_e( 'Sign in', 'kiwi-events' ); ?>
                </button>

                <div class="ke-promo-footer-links">
                    <a href="<?php echo esc_url( KE_Promoter_Portal::portal_url( $slug, 'forgot' ) ); ?>">
                        <?php esc_html_e( 'Forgot your password?', 'kiwi-events' ); ?>
                    </a>
                </div>
            </form>
        </div>

        <p class="ke-promo-page-foot">
            <?php
            printf(
                /* translators: %s: site name */
                esc_html__( '%s · Promoter Portal', 'kiwi-events' ),
                esc_html( get_bloginfo( 'name' ) )
            );
            ?>
        </p>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
