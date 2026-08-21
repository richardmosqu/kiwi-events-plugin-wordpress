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
        $attendee_type  = isset( $_GET['attendee_type'] ) ? sanitize_text_field( $_GET['attendee_type'] ) : '';
        $search         = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $page_num       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page       = 25;

        // Resolve the attendee-type filter. "Real" means a real sale, so it
        // excludes both courtesy gifts and emergency error tickets.
        $is_courtesy = null;
        $is_error    = null;
        if ( $attendee_type === 'real' )     { $is_courtesy = 0; $is_error = 0; }
        if ( $attendee_type === 'courtesy' ) { $is_courtesy = 1; }
        if ( $attendee_type === 'error' )    { $is_error = 1; }

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
        $recon     = null;

        if ( $event_id ) {
            $types = $ticket_types->get_by_event( $event_id );

            $args = array(
                'status'         => $status_filter,
                'ticket_type_id' => $type_filter,
                'is_courtesy'    => $is_courtesy,
                'is_error'       => $is_error,
                'search'         => $search,
                'limit'          => $per_page,
                'offset'         => ( $page_num - 1 ) * $per_page,
            );

            $attendees = $tickets_handler->get_attendees( $event_id, $args );
            $total     = $tickets_handler->count_attendees( $event_id, $args );

            // The events list card reports SUM(quantity_sold), a counter;
            // $total above is a COUNT of rows. They are different quantities
            // and they legitimately disagree, which reads as a bug when only
            // one of them is on screen. Read-only breakdown of the gap.
            $recon = $tickets_handler->sold_reconciliation( $event_id );
        }

        $total_pages = ceil( $total / $per_page );

        include KE_PLUGIN_DIR . 'admin/views/attendees.php';
    }

    /**
     * Export attendees as CSV.
     *
     * Must run on `admin_init` (before any admin HTML is sent) so the
     * Content-Type/Content-Disposition headers take effect — see
     * KE_Admin::maybe_export_early(). Calling it from render() streams the
     * CSV into the middle of the already-emitted admin page instead.
     */
    public function export_csv() {
        if ( ! current_user_can( 'manage_kiwi_events' ) ) {
            wp_die( 'Unauthorized' );
        }

        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        if ( ! $event_id ) {
            wp_die( 'Please select an event first.' );
        }

        $attendee_type = isset( $_GET['attendee_type'] ) ? sanitize_text_field( $_GET['attendee_type'] ) : '';
        $is_courtesy   = null;
        if ( $attendee_type === 'real' )     $is_courtesy = 0;
        if ( $attendee_type === 'courtesy' ) $is_courtesy = 1;

        $tickets_handler = new KE_Tickets();
        $attendees = $tickets_handler->get_attendees( $event_id, array(
            'status'         => isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '',
            'ticket_type_id' => isset( $_GET['ticket_type_id'] ) ? absint( $_GET['ticket_type_id'] ) : 0,
            'is_courtesy'    => $is_courtesy,
            'search'         => isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '',
            'limit'          => 99999,
            'offset'         => 0,
        ) );

        $event_title = get_the_title( $event_id );
        $filename    = sanitize_file_name( 'attendees-' . $event_title . '-' . date( 'Y-m-d' ) ) . '.csv';

        // Per-event extra fields → one CSV column per field, ordered by config.
        $xf_cfg     = class_exists( 'KE_Event_Extra_Fields' )
                       ? KE_Event_Extra_Fields::get_config( $event_id )
                       : array( 'enabled' => false, 'fields' => array() );
        $xf_active  = ! empty( $xf_cfg['enabled'] ) && ! empty( $xf_cfg['fields'] );
        $xf_columns = $xf_active ? $xf_cfg['fields'] : array();

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // UTF-8 BOM for Excel
        fprintf( $output, chr(0xEF) . chr(0xBB) . chr(0xBF) );

        $header = array( '#', 'Name', 'Email', 'Ticket Type', 'Attendee Type', 'Ticket Code',
            'Status', 'Checked In At', 'Price', 'Created At' );
        foreach ( $xf_columns as $xf ) {
            $header[] = (string) $xf['label'];
        }
        fputcsv( $output, $header );

        foreach ( $attendees as $a ) {
            $row = array(
                $a->attendee_number,
                $a->attendee_name,
                $a->attendee_email,
                $a->ticket_type_name,
                ! empty( $a->is_courtesy ) ? 'Courtesy' : 'Real',
                $a->ticket_code,
                ucfirst( $a->status ),
                $a->checked_in_at ?: '—',
                $a->ticket_price > 0 ? '$' . number_format( $a->ticket_price, 2 ) : 'Free',
                $a->created_at,
            );
            if ( $xf_active ) {
                $decoded = $a->extra_fields_data ? json_decode( (string) $a->extra_fields_data, true ) : array();
                if ( ! is_array( $decoded ) ) $decoded = array();
                foreach ( $xf_columns as $xf ) {
                    $val = $decoded[ $xf['id'] ] ?? '';
                    $row[] = is_scalar( $val ) ? (string) $val : '';
                }
            }
            fputcsv( $output, $row );
        }

        fclose( $output );
        exit;
    }
}
