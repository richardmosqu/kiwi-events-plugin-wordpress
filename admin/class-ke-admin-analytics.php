<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * wp-admin → KiwiEvents → Analytics.
 *
 * Read-only view over KE_Event_Analytics for the Campus team: pick an
 * organizer (or all), pick a range (today / 7 days / 30 days / all time),
 * and see visits + CTA clicks event by event. Plain GET filters, no JS
 * state — the page is fully rendered by PHP so it is trivially shareable
 * as a URL and works without the REST API.
 */
class KE_Admin_Analytics {

    public function render() {
        $organizer_id = isset( $_GET['organizer_id'] ) ? absint( $_GET['organizer_id'] ) : 0;
        // Opens on all time: the page's job is "how is this event doing",
        // and a short default window hid every day imported from
        // WordPress.com Stats behind an extra click.
        $range        = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'all';
        if ( ! KE_Event_Analytics::is_valid_range( $range ) ) {
            $range = 'all';
        }

        $organizers = get_terms( array(
            'taxonomy'   => 'ke_organizer',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
        if ( is_wp_error( $organizers ) ) {
            $organizers = array();
        }

        $organizer = null;
        if ( $organizer_id > 0 ) {
            $organizer = get_term( $organizer_id, 'ke_organizer' );
            if ( ! $organizer || is_wp_error( $organizer ) ) {
                $organizer    = null;
                $organizer_id = 0;
            }
        }

        $report = $organizer_id > 0
            ? KE_Event_Analytics::report_for_organizer( $organizer_id, $range )
            : KE_Event_Analytics::report_for_all( $range );

        // What the table actually holds — lets an admin see whether any
        // history exists without reading the database.
        $data_state = KE_Event_Analytics::data_state();

        $range_labels = array(
            'day'   => __( 'Today', 'kiwi-events' ),
            'week'  => __( '7 days', 'kiwi-events' ),
            'month' => __( '30 days', 'kiwi-events' ),
            'all'   => __( 'All time', 'kiwi-events' ),
        );

        include KE_PLUGIN_DIR . 'admin/views/analytics.php';
    }
}
