<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reservations admin page — cross-event listing, CSV + PDF export.
 *
 * Mirrors KE_Admin_Attendees in shape but works against the reservations
 * table. event_id=0 means "All events" (Attendees is event-required; the
 * Reservations page is more useful as an across-the-board view because
 * pending reservations need attention regardless of which event they're on).
 */
class KE_Admin_Reservations {

    const PER_PAGE = 25;

    public function render() {
        // Export branches first — both stream their own response and exit.
        if ( isset( $_GET['ke_export_csv'] ) && $_GET['ke_export_csv'] === '1' ) {
            $this->export_csv();
            return;
        }
        if ( isset( $_GET['ke_export_pdf'] ) && $_GET['ke_export_pdf'] === '1' ) {
            $this->export_pdf();
            return;
        }

        $reservations = new KE_Reservations();

        $args = $this->parse_filters();

        // Events for the filter dropdown (any post status — paused/draft
        // events still have reservations the operator may want to review).
        $events = get_posts( array(
            'post_type'   => 'ke_event',
            'numberposts' => -1,
            'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
            'orderby'     => 'title',
            'order'       => 'ASC',
        ) );

        $rows  = $reservations->get_all( $args );
        $total = $reservations->count_all( $args );
        $stats = $reservations->compute_stats( array(
            'event_id' => $args['event_id'],
            'search'   => $args['search'],
        ) );

        // Per-event reservations config — drives the area dropdown and the
        // "fancy effect" pill colors when the operator has scoped to one event.
        $event_cfg = ( $args['event_id'] > 0 )
            ? KE_Reservations::get_config( $args['event_id'] )
            : array( 'enabled' => false, 'areas' => array() );

        $page_num    = $args['page'];
        $total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );

        // Hand to the view.
        $event_id      = $args['event_id'];
        $status_filter = $args['status'];
        $search        = $args['search'];

        include KE_PLUGIN_DIR . 'admin/views/reservations.php';
    }

    /** Parse + validate request filters into one shared shape. */
    private function parse_filters() {
        $page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        $status   = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
        if ( $status !== '' && ! in_array( $status, KE_Reservations::ALL_STATUSES, true ) ) {
            $status = '';
        }
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

        return array(
            'event_id' => $event_id,
            'status'   => $status,
            'search'   => $search,
            'page'     => $page,
            'limit'    => self::PER_PAGE,
            'offset'   => ( $page - 1 ) * self::PER_PAGE,
        );
    }

    /**
     * Export the current filter set as CSV. Caps at 50k rows so a runaway
     * "all events" request can't exhaust memory — the operator can narrow
     * with filters if they hit that ceiling.
     */
    private function export_csv() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $args = $this->parse_filters();
        $args['limit']  = 50000;
        $args['offset'] = 0;

        $reservations = new KE_Reservations();
        $rows = $reservations->get_all( $args );

        $name_part = $args['event_id'] > 0
            ? sanitize_file_name( 'reservations-' . get_the_title( $args['event_id'] ) )
            : 'reservations-all';
        $filename = $name_part . '-' . date( 'Y-m-d' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $output = fopen( 'php://output', 'w' );
        // UTF-8 BOM so Excel renders accented names correctly.
        fprintf( $output, chr(0xEF) . chr(0xBB) . chr(0xBF) );

        fputcsv( $output, array(
            'Code', 'Event', 'Customer Name', 'Email', 'Phone',
            'Party Size', 'Arrival', 'Area', 'Status',
            'Checked In At', 'No-show Processed', 'Notes',
            'Decline Reason', 'Created', 'Updated',
        ) );

        foreach ( $rows as $r ) {
            fputcsv( $output, array(
                (string) $r->reservation_code,
                (string) ( $r->event_title ?? '' ),
                (string) $r->customer_name,
                (string) $r->customer_email,
                (string) $r->customer_phone,
                (int) $r->party_size,
                (string) $r->arrival_time,
                (string) ( $r->area ?? '' ),
                (string) $r->status,
                (string) ( $r->checked_in_at ?? '' ),
                ! empty( $r->no_show_processed ) ? 'Yes' : 'No',
                (string) ( $r->notes ?? '' ),
                (string) ( $r->decline_reason ?? '' ),
                (string) $r->created_at,
                (string) ( $r->updated_at ?? '' ),
            ) );
        }
        fclose( $output );
        exit;
    }

    /**
     * Export the current filter set as a PDF report (FPDF when available,
     * printable HTML otherwise). Same filter shape as CSV — one report per
     * scope.
     */
    private function export_pdf() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        if ( ! class_exists( 'KE_Admin_Reservations_Report_PDF' ) ) {
            wp_die( 'Reservations report module not available.' );
        }

        $args = $this->parse_filters();
        $args['limit']  = 50000;
        $args['offset'] = 0;

        $report = new KE_Admin_Reservations_Report_PDF( $args );
        $report->stream();
    }
}
