<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sales Dashboard — KPIs, charts, recent orders
 */
class KE_Admin_Dashboard {

    public function render() {
        $orders = new KE_Orders();
        $stats  = $orders->get_stats();
        $recent = $orders->get_recent( 10 );

        // Get active events count
        $active_events = wp_count_posts( 'ke_event' );
        $events_count  = $active_events->publish ?? 0;

        // Get overall check-in rate
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'ke_tickets';
        $total_tickets = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tickets_table} WHERE status != 'cancelled'" );
        $checked_in    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tickets_table} WHERE status = 'used'" );
        $checkin_rate  = $total_tickets > 0 ? round( ( $checked_in / $total_tickets ) * 100, 1 ) : 0;

        // Event filter options
        $events = get_posts( array(
            'post_type'   => 'ke_event',
            'numberposts' => -1,
            'post_status' => 'publish',
            'orderby'     => 'title',
            'order'       => 'ASC',
        ) );

        include KE_PLUGIN_DIR . 'admin/views/dashboard.php';
    }
}
