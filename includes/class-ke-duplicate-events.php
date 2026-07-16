<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Duplicate-event detector + admin cleanup tool.
 *
 * Two responsibilities:
 *
 *   1. find_groups()        — fast GROUP BY check (transient-cached) used by
 *                             the events-list admin notice to flag duplicates
 *                             without scanning the whole table on every page
 *                             render.
 *
 *   2. cleanup_safe()       — admin-gated bulk delete. Within each duplicate
 *                             group keeps the OLDEST post and removes every
 *                             other copy that is provably safe to delete
 *                             (status='draft' AND no ticket rows AND no
 *                             order rows). Anything with attendees/revenue
 *                             is left alone for manual review.
 *
 * Background: production produced 11 copies of the same event during the
 * 2.2.0 promoter migration because a SQL error mid-save corrupted the JSON
 * response, the wizard treated the call as failed, and auto-save retried —
 * each retry inserting another draft. The save_event endpoint now dedupes
 * on the create path; this class cleans up the existing damage and exposes
 * an admin notice so future occurrences surface within a page load.
 */
class KE_Duplicate_Events {

    const NOTICE_TRANSIENT = 'ke_duplicate_events_count';

    public function init() {
        add_action( 'admin_post_ke_cleanup_duplicate_events', array( $this, 'handle_cleanup' ) );
        add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
    }

    /**
     * Returns an array of duplicate groups: each entry has the title,
     * copies count, earliest post_date, and latest post_date.
     *
     * Result cached in a 5-minute transient — events-list reloads should not
     * pay the GROUP BY cost on every render.
     */
    public static function find_groups( $force_refresh = false ) {
        global $wpdb;

        if ( ! $force_refresh ) {
            $cached = get_transient( self::NOTICE_TRANSIENT );
            if ( is_array( $cached ) ) return $cached;
        }

        $rows = $wpdb->get_results(
            "SELECT post_title,
                    COUNT(*)       AS copies,
                    MIN(post_date) AS first_seen,
                    MAX(post_date) AS last_seen
               FROM {$wpdb->posts}
              WHERE post_type   = 'ke_event'
                AND post_status IN ('publish','draft','pending','private')
                AND post_title  <> ''
              GROUP BY post_title
             HAVING copies > 1
              ORDER BY copies DESC, last_seen DESC"
        );

        $out = array();
        foreach ( (array) $rows as $r ) {
            $out[] = array(
                'title'      => (string) $r->post_title,
                'copies'     => (int) $r->copies,
                'first_seen' => (string) $r->first_seen,
                'last_seen'  => (string) $r->last_seen,
            );
        }

        set_transient( self::NOTICE_TRANSIENT, $out, 5 * MINUTE_IN_SECONDS );
        return $out;
    }

    /**
     * Within each duplicate group, identify deletable copies. A copy is
     * deletable when ALL hold:
     *   - it is NOT the oldest post in the group (keep the original)
     *   - post_status = 'draft'
     *   - zero ticket_types rows referencing it
     *   - zero ticket rows referencing it
     *   - zero order rows referencing it
     *
     * Returns array of post arrays { id, title, post_date, post_status }.
     * Does NOT delete — that's cleanup_safe()'s job.
     */
    public static function find_deletable() {
        global $wpdb;
        $groups = self::find_groups( true );
        if ( empty( $groups ) ) return array();

        $titles = array_map( function ( $g ) { return $g['title']; }, $groups );
        if ( empty( $titles ) ) return array();

        $placeholders = implode( ',', array_fill( 0, count( $titles ), '%s' ) );
        $tt = $wpdb->prefix . 'ke_ticket_types';
        $t  = $wpdb->prefix . 'ke_tickets';
        $o  = $wpdb->prefix . 'ke_orders';

        // Per-group: keep MIN(ID) (oldest), evaluate the rest. We materialize
        // the "oldest in group" using a correlated subquery so a single SQL
        // round-trip returns only candidates we'd delete.
        $sql = $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_date, p.post_status,
                    (SELECT COUNT(*) FROM {$tt} WHERE event_id = p.ID) AS tt_rows,
                    (SELECT COUNT(*) FROM {$t}  WHERE event_id = p.ID) AS tk_rows,
                    (SELECT COUNT(*) FROM {$o}  WHERE event_id = p.ID) AS o_rows
               FROM {$wpdb->posts} p
              WHERE p.post_type   = 'ke_event'
                AND p.post_status = 'draft'
                AND p.post_title  IN ($placeholders)
                AND p.ID <> (
                      SELECT MIN(p2.ID) FROM {$wpdb->posts} p2
                       WHERE p2.post_type  = 'ke_event'
                         AND p2.post_title = p.post_title
                         AND p2.post_status IN ('publish','draft','pending','private')
                  )",
            $titles
        );
        $rows = $wpdb->get_results( $sql );

        $out = array();
        foreach ( (array) $rows as $r ) {
            if ( (int) $r->tt_rows > 0 ) continue;
            if ( (int) $r->tk_rows > 0 ) continue;
            if ( (int) $r->o_rows  > 0 ) continue;
            $out[] = array(
                'id'          => (int) $r->ID,
                'title'       => (string) $r->post_title,
                'post_date'   => (string) $r->post_date,
                'post_status' => (string) $r->post_status,
            );
        }
        return $out;
    }

    /**
     * admin-post.php handler. Verifies nonce + capability, deletes the
     * candidates returned by find_deletable(), purges the notice transient,
     * and redirects back to the events list with a flash.
     */
    public function handle_cleanup() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        check_admin_referer( 'ke_cleanup_duplicate_events' );

        $candidates = self::find_deletable();
        $deleted    = 0;
        foreach ( $candidates as $c ) {
            // wp_delete_post( $id, true ) bypasses trash. We intentionally
            // skip trash — these rows are empty drafts the user never knew
            // existed, so a "Restore from Trash" affordance is noise.
            $res = wp_delete_post( (int) $c['id'], true );
            if ( $res ) $deleted++;
        }

        delete_transient( self::NOTICE_TRANSIENT );

        $url = add_query_arg(
            array( 'page' => 'ke-events-list', 'ke_dupes_cleaned' => $deleted ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Admin notice on the events list page. Shown when duplicates exist and
     * the viewer can clean them up. Auto-hides once handle_cleanup() succeeds.
     */
    public function render_admin_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_events_page = $screen && (
            $screen->id === 'admin_page_ke-events-list' ||
            ( isset( $_GET['page'] ) && $_GET['page'] === 'ke-events-list' )
        );
        if ( ! $is_events_page ) return;

        if ( isset( $_GET['ke_dupes_cleaned'] ) ) {
            $n = (int) $_GET['ke_dupes_cleaned'];
            $msg = $n > 0
                ? sprintf( _n( 'Removed %d duplicate event draft.', 'Removed %d duplicate event drafts.', $n, 'kiwi-events' ), $n )
                : __( 'No duplicate events were safe to remove. Review the remaining matches manually.', 'kiwi-events' );
            echo '<div class="notice notice-success is-dismissible"><p><strong>KiwiEvents:</strong> ' . esc_html( $msg ) . '</p></div>';
            return;
        }

        $groups = self::find_groups();
        if ( empty( $groups ) ) return;

        $total_copies = 0;
        foreach ( $groups as $g ) $total_copies += max( 0, $g['copies'] - 1 );
        if ( $total_copies <= 0 ) return;

        $cleanup_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=ke_cleanup_duplicate_events' ),
            'ke_cleanup_duplicate_events'
        );

        echo '<div class="notice notice-warning"><p>';
        printf(
            esc_html(
                _n(
                    '%d event title has duplicate copies.',
                    '%d event titles have duplicate copies.',
                    count( $groups ),
                    'kiwi-events'
                )
            ),
            count( $groups )
        );
        echo ' ';
        echo esc_html( sprintf(
            /* translators: %d is total extra copies across all groups */
            _n( '%d extra copy detected.', '%d extra copies detected.', $total_copies, 'kiwi-events' ),
            $total_copies
        ) );
        echo '</p><p>';
        echo '<a href="' . esc_url( $cleanup_url ) . '" class="button button-primary" onclick="return confirm(\'' . esc_js( __( 'Delete duplicate draft events that have no tickets, attendees, or orders? The oldest copy in each group is kept.', 'kiwi-events' ) ) . '\');">';
        esc_html_e( 'Clean up safe duplicates', 'kiwi-events' );
        echo '</a> ';
        echo '<em style="color:#64748b;">';
        esc_html_e( 'Only deletes drafts with no tickets, attendees, or orders. The oldest copy in each group is preserved.', 'kiwi-events' );
        echo '</em>';
        echo '</p></div>';
    }
}
