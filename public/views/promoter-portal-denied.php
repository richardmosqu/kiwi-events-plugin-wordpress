<?php
/**
 * Promoter portal — access-denied screen.
 *
 * Rendered when:
 *   - the URL slug doesn't map to a promoter row, OR
 *   - the logged-in WP user is not the owner of the requested promoter slug.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$site_name   = (string) get_bloginfo( 'name' );
$current_uid = get_current_user_id();
$current_u   = $current_uid ? get_userdata( $current_uid ) : null;
$current_email = $current_u ? (string) $current_u->user_email : '';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title><?php echo esc_html( sprintf( 'Access denied — %s', $site_name ) ); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" />
    <style>
        body { background:#f5f6fa; color:#0f172a; font-family:'Inter',system-ui,-apple-system,sans-serif; margin:0; }
        .kep-denied { max-width:480px; margin:80px auto; padding:32px 28px; background:#fff; border:1px solid #e2e8f0; border-radius:18px; text-align:center; box-shadow:0 10px 30px rgba(15,23,42,0.06); }
        .kep-denied .glyph { font-size:42px; margin-bottom:8px; }
        .kep-denied h1 { margin:0 0 8px; font-size:22px; font-weight:700; }
        .kep-denied p { color:#475569; font-size:14px; line-height:1.55; margin:8px 0; }
        .kep-denied .who { color:#94a3b8; font-size:12px; margin-top:14px; }
        .kep-denied .actions { display:flex; gap:10px; justify-content:center; margin-top:18px; flex-wrap:wrap; }
        .kep-btn { display:inline-block; padding:10px 18px; border-radius:999px; font-size:13px; font-weight:600; text-decoration:none; }
        .kep-btn-primary { background:#6366f1; color:#fff; }
        .kep-btn-ghost { background:#fff; color:#0f172a; border:1px solid #e2e8f0; }
    </style>
    <?php wp_head(); ?>
</head>
<body class="ke-promo-body ke-promo-body--denied">
    <div class="kep-denied">
        <div class="glyph">🚫</div>
        <h1>Access denied</h1>
        <p>This promoter dashboard belongs to a different account. If you think this is a mistake, contact the event organizer.</p>
        <?php if ( $current_email ) : ?>
            <p class="who">You are signed in as <strong><?php echo esc_html( $current_email ); ?></strong>.</p>
        <?php endif; ?>
        <div class="actions">
            <a class="kep-btn kep-btn-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">Back to site</a>
            <?php if ( $current_uid ) : ?>
                <a class="kep-btn kep-btn-ghost" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Sign out</a>
            <?php else : ?>
                <a class="kep-btn kep-btn-ghost" href="<?php echo esc_url( wp_login_url() ); ?>">Sign in</a>
            <?php endif; ?>
        </div>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
