<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Attendees admin page — WP List Table, filters, CSV export
 */
class KE_Admin_Attendees {

    public function render() {
        // Handle CSV export
        if ( isset( $_GET['ke_export_csv'] ) && $_GET['ke_export_csv'] === '1' ) {
            $this->export_csv();
            return;
        }

        $tickets_handler = new KE_Tickets();
        $ticket_types    = new KE_Ticket_Types();

        // Filters
        $event_id       = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        $status_filter  = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
        $type_filter    = isset( $_GET['ticket_type_id'] ) ? absint( $_GET['ticket_type_id'] ) : 0;
        $search         = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $page_num       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page       = 25;

        // Get events for filter dropdown
        $events = get_posts( array(
            'post_type'   => 'ke_event',
            'numberposts' => -1,
            'post_status' => 'publish',
            'orderby'     => 'title',
            'order'       => 'ASC',
        ) );

        // Get attendees
        $attendees = array();
        $total     = 0;
        $types     = array();

        if ( $event_id ) {
            $types = $ticket_types->get_by_event( $event_id );

            $args = array(
                'status'         => $status_filter,
                'ticket_type_id' => $type_filter,
                'search'         => $search,
                'limit'          => $per_page,
                'offset'         => ( $page_num - 1 ) * $per_page,
            );

            $attendees = $tickets_handler->get_attendees( $event_id, $args );
            $total     = $tickets_handler->count_attendees( $event_id, $args );
        }

        $total_pages = ceil( $total / $per_page );

        include KE_PLUGIN_DIR . 'admin/views/attendees.php';
    }

    /**
     * Export attendees as CSV
     */
    private function export_csv() {
        if ( ! current_user_can( 'manage_kiwi_events' ) ) {
            wp_die( 'Unauthorized' );
        }

        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_die( 'Please select an event first.' );
        }

        $tickets_handler = new KE_Tickets();
        $attendees = $tickets_handler->get_attendees( $event_id, array(
            'status'         => isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '',
            'ticket_type_id' => isset( $_GET['ticket_type_id'] ) ? absint( $_GET['ticket_type_id'] ) : 0,
            'search'         => isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '',
            'limit'          => 99999,
            'offset'         => 0,
        ) );

        $event_title = get_the_title( $event_id );
        $filename    = sanitize_file_name( 'attendees-' . $event_title . '-' . date( 'Y-m-d' ) ) . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // UTF-8 BOM for Excel
        fprintf( $output, chr(0xEF) . chr(0xBB) . chr(0xBF) );

        // Header row
        fputcsv( $output, array(
            '#', 'Name', 'Email', 'Ticket Type', 'Ticket Code',
            'Status', 'Checked In At', 'Price', 'Created At'
        ) );

        foreach ( $attendees as $a ) {
            fputcsv( $output, array(
                $a->attendee_number,
                $a->attendee_name,
                $a->attendee_email,
                $a->ticket_type_name,
                $a->ticket_code,
                ucfirst( $a->status ),
                $a->checked_in_at ?: '—',
                $a->ticket_price > 0 ? '$' . number_format( $a->ticket_price, 2 ) : 'Free',
                $a->created_at,
            ) );
        }

        fclose( $output );
        exit;
    }
}
