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
define( 'KE_VERSION', '1.0.0' );
define( 'KE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'KE_DB_VERSION', '1.1.0' );

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
require_once KE_PLUGIN_DIR . 'includes/class-ke-qr-generator.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-pdf-generator.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-email.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-woocommerce.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-rest-api.php';
require_once KE_PLUGIN_DIR . 'includes/class-ke-scanner.php';
require_once KE_PLUGIN_DIR . 'includes/class-kiwi-events.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin-dashboard.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin-attendees.php';
require_once KE_PLUGIN_DIR . 'admin/class-ke-admin-ticket-types.php';
require_once KE_PLUGIN_DIR . 'public/class-ke-public.php';

// Activation and deactivation hooks
register_activation_hook( __FILE__, array( 'KE_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'KE_Deactivator', 'deactivate' ) );

/**
 * Initialize the plugin
 */
function kiwi_events_init() {
    $plugin = new Kiwi_Events();
    $plugin->run();
}
add_action( 'plugins_loaded', 'kiwi_events_init' );
