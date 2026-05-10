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
define( 'KE_VERSION', '1.5.4' );
define( 'KE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'KE_DB_VERSION', '1.7.0' );
define( 'KE_SCANNER_ASSETS_VER', '0.9.8' );

// Load Composer autoloader
if ( file_exists( KE_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    require_once KE_PLUGIN_DIR . 'vendor/autoload.php';
}

// Load plugin classes
require_once KE_PLUGIN_DIR . 'includes/class-ke-activator.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-deactivator.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-post-types.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-ticket-types.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-orders.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-tickets.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-codes.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-reservations.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-reservations-cron.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-event-extra-fields.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-qr-generator.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-pdf-generator.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-email.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-woocommerce.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-rest-api.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-scanner.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-scanner-password.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-organizer-dashboard.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-organizer-public.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-organizer-stats.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-organizer-report-pdf.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-admin-reservations-pdf.php';
require_once KE_PLUGIN_DIR . 'includes/class-kiwi-events.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin-dashboard.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin-attendees.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin-reservations.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin-ticket-types.php';
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
 * Initialize the plugin
 */
function kiwi_events_init() {
    $plugin = new Kiwi_Events();
    $plugin->run();

    // Boot the reservations no-show cron (registers schedule, cron hook,
    // and admin-gated manual trigger). Safe to instantiate on every load
    // — its constructor only adds filters/actions.
    if ( class_exists( 'KE_Reservations_Cron' ) ) {
        new KE_Reservations_Cron();
    }
}
add_action( 'plugins_loaded', 'kiwi_events_init' );
