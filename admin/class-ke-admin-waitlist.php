<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ticket-sales waitlist admin page — cross-event listing + CSV export.
 *
 * Mirrors KE_Admin_Reservations in shape (event_id=0 means "All events",
 * filters parsed once and shared by the listing and the export). Read-only on
 * purpose: rows are created by the public form and consumed by
 * KE_Waitlist_Cron, so there is no admin action that could double-send or
 * silently drop someone's notification.
 */
class KE_Admin_Waitlist {

    const PER_PAGE = 50;

    public function render() {
        // Export branch first — it streams its own response and exits.
        if ( isset( $_GET['ke_export_csv'] ) && $_GET['ke_export_csv'] === '1' ) {
            $this->export_csv();
            return;
        }

        $args = $this->parse_filters();

        // Any post status: a draft/paused event can still be collecting
        // signups from a shared link.
        $events = get_posts( array(
            'post_type'   => 'ke_event',
            'numberposts' => -1,
            'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
            'orderby'     => 'title',
            'order'       => 'ASC',
        ) );

        $rows  = KE_Waitlist::get_all( $args );
        $total = KE_Waitlist::count_all( $args );
        $stats = KE_Waitlist::stats( array(
            'event_id' => $args['event_id'],
            'search'   => $args['search'],
        ) );

        // Schedule summary for the single-event view, so the operator can see
        // when this list will actually be released.
        $schedule = ( $args['event_id'] > 0 && class_exists( 'KE_Sales_Schedule' ) )
            ? KE_Sales_Schedule::get_config( $args['event_id'] )
            : null;
        $schedule_labels = ( $args['event_id'] > 0 && class_exists( 'KE_Sales_Schedule' ) )
            ? KE_Sales_Schedule::labels( $args['event_id'], $schedule )
            : array( 'full' => '' );
        $schedule_pending = ( $args['event_id'] > 0 && class_exists( 'KE_Sales_Schedule' ) )
            ? KE_Sales_Schedule::is_pending( $args['event_id'], $schedule )
            : false;

        $page_num    = $args['page'];
        $total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );

        // Hand to the view.
        $event_id      = $args['event_id'];
        $status_filter = $args['status'];
        $search        = $args['search'];
        $sweep_url     = class_exists( 'KE_Waitlist_Cron' ) ? KE_Waitlist_Cron::manual_run_url() : '';

        include KE_PLUGIN_DIR . 'admin/views/waitlist.php';
    }

    /** Parse + validate request filters into one shared shape. */
    private function parse_filters() {
        $page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        $status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        if ( $status !== '' && ! in_array( $status, KE_Waitlist::ALL_STATUSES, true ) ) {
            $status = '';
        }
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

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
     * Export the current filter set as CSV. Capped at 50k rows like the
     * reservations export. Streams headers + body, so it must run on
     * `admin_init` before any admin HTML — see KE_Admin::maybe_export_early().
     */
    public function export_csv() {
        if ( ! current_user_can( 'manage_kiwi_events' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $args           = $this->parse_filters();
        $args['limit']  = 50000;
        $args['offset'] = 0;

        $rows = KE_Waitlist::get_all( $args );

        $name_part = $args['event_id'] > 0
            ? sanitize_file_name( 'waitlist-' . get_the_title( $args['event_id'] ) )
            : 'waitlist-all';
        $filename = $name_part . '-' . date( 'Y-m-d' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $output = fopen( 'php://output', 'w' );
        // UTF-8 BOM so Excel renders accented names correctly.
        fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        fputcsv( $output, array( 'Email', 'Name', 'Event', 'Status', 'Signed up', 'Notified at' ) );

        foreach ( $rows as $r ) {
            fputcsv( $output, array(
                self::csv_cell( $r->email ),
                self::csv_cell( $r->name ?? '' ),
                self::csv_cell( $r->event_title ?? '' ),
                self::csv_cell( $r->status ),
                (string) $r->created_at,
                (string) ( $r->notified_at ?? '' ),
            ) );
        }
        fclose( $output );
        exit;
    }

    /**
     * Neutralise spreadsheet formula injection.
     *
     * The name and email columns come from an unauthenticated public form, so
     * an anonymous visitor controls the first character of a cell an admin
     * later opens in Excel or Sheets — where a leading =, +, -, @ (or a
     * control character) is evaluated as a formula. Prefixing with an
     * apostrophe forces the cell to be read as text; the value itself is
     * unchanged for anything that parses the CSV programmatically.
     */
    private static function csv_cell( $value ) {
        $value = (string) $value;
        if ( $value === '' ) {
            return $value;
        }
        if ( strpos( "=+-@\t\r", $value[0] ) !== false ) {
            return "'" . $value;
        }
        return $value;
    }
}
