<?php
/**
 * Plugin Name: KiwiEvents
 * Plugin URI:  https://kiwievents.com
 * Description: A complete event management and ticketing solution for WordPress. Create events, sell tickets (free & paid via WooCommerce), generate QR code tickets, scan at the door, track attendees, and view sales dashboards.
 * Version:     1.0.0
 * Author:      KiwiEvents
 * Author URI:  https://kiwievents.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: kiwi-events
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'KE_VERSION', '2.1.0' );
define( 'KE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'KE_DB_VERSION', '2.6.0' );

// Diagnostic logging for promoter attribution. Flip to true ONLY while
// debugging a missing-commission report; leaves a banner in wp-admin while
// enabled so it can't be forgotten. Mirrors the pattern used by
// ke-yappy-fee-fix's KE_FEE_DEBUG.
if ( ! defined( 'KE_PROMOTER_DEBUG' ) ) {
    define( 'KE_PROMOTER_DEBUG', false );
}
define( 'KE_SCANNER_ASSETS_VER', '0.10.2' );

// Asset cache-bust for the event-builder admin page. Bump whenever
// admin/css/ke-event-builder.css or admin/js/ke-event-builder.js change.
// KE_VERSION moves slowly; the builder assets churn faster, and stale
// versions on WordPress.com edge cache cause the wizard's step
// indicators to lose their active/accent styling.
define( 'KE_BUILDER_ASSETS_VER', '0.11.1' );

// Admin design-token stylesheet (Kiwi brand: cream + green + glass).
// Loads BEFORE every other KE admin CSS so subsequent rules can reference
// --kiwi-* tokens. Bump when ke-admin-tokens.css changes so WordPress.com
// edge cache picks up the new file.
define( 'KE_TOKENS_ASSETS_VER', '0.9.2' );

// Cache-bust for the general admin stylesheets (ke-admin.css, ke-attendees.css,
// ke-admin-reservations.css). KE_VERSION moves with the plugin code; admin
// styling iterates faster during the Kiwi-brand rollout, so it gets its own
// version line. Bump whenever any of those CSS files change.
define( 'KE_ADMIN_CSS_VER', '0.16.0' );

// Cache-bust for the promoter portal assets (ke-promoter-portal.css,
// ke-promoter-login.js). Bump when either file changes so WordPress.com edge
// cache picks up the new file — KE_VERSION moves slowly and is shared with
// schema/code changes, so portal styling gets its own line.
define( 'KE_PORTAL_ASSETS_VER', '0.6.0' );

// Cache-bust for the general admin JS (currently ke-admin-dashboard.js, which
// reads --kiwi-* tokens at chart-render time). Bump when admin JS changes so
// dashboard chart colors track the dark-mode token bumps without users having
// to hard-reload past the edge cache.
define( 'KE_ADMIN_JS_VER', '0.2.0' );

// Cache-bust for the customer ticket-wallet assets (ke-tickets-wallet.css,
// ke-tickets-wallet.js — the [kiwi_tickets_purchase] shortcode). Bump when
// either file changes so WordPress.com edge cache picks up the new file.
define( 'KE_WALLET_ASSETS_VER', '0.1.1' );

// Cache-bust for the community board assets (ke-board.css, ke-board.js —
// the [kiwi_board] / [kiwi_create_board] shortcodes and the /board/ single).
// Bump when either file changes so WordPress.com edge cache picks them up.
define( 'KE_BOARD_ASSETS_VER', '0.1.1' );

// Rewrite-rules version. register_post_type() only ADDS rules in memory —
// WordPress persists them on an explicit flush, which normally only happens
// on plugin activation. Plugin UPDATES never re-run activation, so a new
// CPT/slug shipped in an update 404s until flushed. Bump this constant
// whenever rewrite rules change (new CPT, changed slug); the init-99 guard
// below flushes exactly once per version — never on every request.
define( 'KE_REWRITE_VERSION', '1.1.0' );

// Load Composer autoloader
if ( file_exists( KE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once KE_PLUGIN_DIR . 'vendor/autoload.php';
}

// Load plugin classes
require_once KE_PLUGIN_DIR . 'includes/class-ke-activator.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-deactivator.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-post-types.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-event-slug.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-shortcodes.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-ticket-types.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-orders.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-tickets.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-codes.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-reservations.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-reservations-cron.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-event-extra-fields.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-qr-generator.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-tickets-wallet.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-board.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-pdf-generator.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-email.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-email-templates.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-email-queue.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-woocommerce.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-rest-api.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-scanner.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-scanner-password.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-organizer-dashboard.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-organizer-public.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-organizer-stats.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-organizer-report-pdf.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-admin-reservations-pdf.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-promoter-attribution.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-promoter-commissions.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-event-promoters.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-promoter-portal.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-promoter-lists.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-promoter-notifications.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-promoter-visible.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-promoter-admin-preview.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-duplicate-events.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-service-fee-audit.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-admin-color-mode.php';
require_once KE_PLUGIN_DIR . 'includes/class-kiwi-events.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin-dashboard.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin-attendees.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin-reservations.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin-ticket-types.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin-promoters.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin-board.php';
require_once KE_PLUGIN_DIR . 'public/class-ke-public.php';

// Activation and deactivation hooks
register_activation_hook( __FILE__, array( 'KE_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'KE_Deactivator', 'deactivate' ) );

/**
 * Load translations.
 */
function kiwi_events_load_textdomain() {
    load_plugin_textdomain( 'kiwi-events', false, dirname( KE_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'plugins_loaded', 'kiwi_events_load_textdomain' );

/**
 * Version-gated one-time rewrite flush. Runs at init 99 so every CPT and
 * custom rewrite (all registered at init ≤ 20) exists before the flush.
 * Activation still flushes for fresh installs; this covers plugin UPDATES,
 * where the activation hook never re-runs.
 */
function kiwi_events_maybe_flush_rewrites() {
    $version_stale = ( get_option( 'ke_rewrite_version' ) !== KE_REWRITE_VERSION );

    // Self-heal: the version option can say "done" while the persisted
    // rules still lack our patterns (permalinks re-saved under old code,
    // stale persistent object cache on managed hosts, option restored from
    // a backup). rewrite_rules is autoloaded, so this check costs nothing.
    $board_rule_missing = false;
    if ( ! $version_stale && post_type_exists( 'ke_board_event' ) ) {
        $rules = get_option( 'rewrite_rules' );
        if ( is_array( $rules ) && ! empty( $rules ) ) {
            $board_rule_missing = true;
            foreach ( $rules as $pattern => $target ) {
                if ( 0 === strpos( $pattern, 'board/' ) ) {
                    $board_rule_missing = false;
                    break;
                }
            }
        }
    }

    if ( ! $version_stale && ! $board_rule_missing ) {
        return;
    }

    // If a previous self-heal attempt couldn't restore the rule, don't
    // degrade into a flush-per-request loop — retry at most hourly.
    if ( $board_rule_missing && get_transient( 'ke_rewrite_selfheal_lock' ) ) {
        return;
    }

    flush_rewrite_rules();
    update_option( 'ke_rewrite_version', KE_REWRITE_VERSION );

    if ( $board_rule_missing ) {
        set_transient( 'ke_rewrite_selfheal_lock', 1, HOUR_IN_SECONDS );
    }
}
add_action( 'init', 'kiwi_events_maybe_flush_rewrites', 99 );

/**
 * Initialize the plugin
 */
function kiwi_events_init() {
    // Auto-run schema upgrades when KE_DB_VERSION bumps so existing installs
    // pick up new columns / migrations without a manual reactivate.
    if ( class_exists( 'KE_Activator' ) ) {
        KE_Activator::maybe_upgrade();
        if ( is_admin() ) {
            add_action( 'admin_notices', array( 'KE_Activator', 'print_schema_warning_notice' ) );
        }
    }

    $plugin = new Kiwi_Events();
    $plugin->run();

    // Boot the reservations no-show cron (registers schedule, cron hook,
    // and admin-gated manual trigger). Safe to instantiate on every load
    // — its constructor only adds filters/actions.
    if ( class_exists( 'KE_Reservations_Cron' ) ) {
        new KE_Reservations_Cron();
    }

    // Duplicate-events detector + admin cleanup tool. Hooks the events-list
    // admin notice and an admin-post handler that purges safe duplicates.
    if ( is_admin() && class_exists( 'KE_Duplicate_Events' ) ) {
        ( new KE_Duplicate_Events() )->init();
    }

    // Service-fee shortfall audit (read-only). Registers a hidden admin
    // submenu page reachable at admin.php?page=ke-service-fee-audit.
    if ( is_admin() && class_exists( 'KE_Service_Fee_Audit' ) ) {
        ( new KE_Service_Fee_Audit() )->init();
    }

    // Visible promoter attribution (URL persistence + badge + checkout
    // summary line + email paragraph + WC admin meta). Hooks only fire when
    // there is an active promoter in session, so a no-op otherwise.
    if ( class_exists( 'KE_Promoter_Visible' ) ) {
        ( new KE_Promoter_Visible() )->init();
    }

    // Admin-only light/dark color mode. Injects <html data-theme="dark">
    // on Kiwi pages when the current user has opted in. Token remap lives
    // in admin/css/ke-admin-tokens.css under :root[data-theme="dark"].
    if ( is_admin() && class_exists( 'KE_Admin_Color_Mode' ) ) {
        ( new KE_Admin_Color_Mode() )->init();
    }
}
add_action( 'plugins_loaded', 'kiwi_events_init' );
