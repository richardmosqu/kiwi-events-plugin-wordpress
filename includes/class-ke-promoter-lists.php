<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Promoter Lists API.
 *
 * Wraps two tables:
 *   {prefix}ke_promoter_lists         — named groupings (e.g. "Summer crew")
 *   {prefix}ke_promoter_list_members  — (promoter_id, list_id) join rows
 *
 * Used by the admin Lists UI and by the event-builder's "bulk-assign list"
 * action. Lifecycle of a list is independent from event_promoters — a list
 * is a *snapshot input*, not a live binding. Removing a promoter from a list
 * after the bulk-assign has run does NOT also remove them from the event.
 */
class KE_Promoter_Lists {

    public static function table_lists() {
        global $wpdb;
        return $wpdb->prefix . 'ke_promoter_lists';
    }

    public static function table_members() {
        global $wpdb;
        return $wpdb->prefix . 'ke_promoter_list_members';
    }

    /* ── CRUD: lists ─────────────────────────────────────────────── */

    public static function create( $name, $description = '' ) {
        global $wpdb;
        $name = sanitize_text_field( (string) $name );
        if ( $name === '' ) return 0;

        $wpdb->insert(
            self::table_lists(),
            array(
                'name'        => $name,
                'description' => sanitize_textarea_field( (string) $description ),
                'created_at'  => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s' )
        );
        return (int) $wpdb->insert_id;
    }

    public static function update( $id, $name, $description = '' ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! $id ) return false;

        return false !== $wpdb->update(
            self::table_lists(),
            array(
                'name'        => sanitize_text_field( (string) $name ),
                'description' => sanitize_textarea_field( (string) $description ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    public static function delete( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! $id ) return false;

        $wpdb->delete( self::table_members(), array( 'list_id' => $id ), array( '%d' ) );
        return false !== $wpdb->delete( self::table_lists(), array( 'id' => $id ), array( '%d' ) );
    }

    public static function get( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! $id ) return null;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table_lists() . " WHERE id = %d",
            $id
        ) );
    }

    /**
     * All lists, decorated with member count.
     */
    public static function all_with_counts() {
        global $wpdb;
        $lists   = self::table_lists();
        $members = self::table_members();

        return $wpdb->get_results(
            "SELECT l.*, COALESCE(m.member_count, 0) AS member_count
               FROM $lists l
          LEFT JOIN (
                  SELECT list_id, COUNT(*) AS member_count
                    FROM $members
                GROUP BY list_id
               ) m ON m.list_id = l.id
           ORDER BY l.name ASC"
        );
    }

    /* ── Members ─────────────────────────────────────────────────── */

    /**
     * Replace the member set for a list. $promoter_ids may be empty.
     */
    public static function set_members( $list_id, array $promoter_ids ) {
        global $wpdb;
        $list_id = (int) $list_id;
        if ( ! $list_id ) return 0;

        $clean = array_unique( array_values( array_filter( array_map( 'intval', $promoter_ids ) ) ) );

        // Hard reset — simplest correct behavior given the small expected size.
        $wpdb->delete( self::table_members(), array( 'list_id' => $list_id ), array( '%d' ) );

        $written = 0;
        foreach ( $clean as $pid ) {
            $ok = $wpdb->insert(
                self::table_members(),
                array(
                    'list_id'     => $list_id,
                    'promoter_id' => $pid,
                    'added_at'    => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%s' )
            );
            if ( $ok ) $written++;
        }
        return $written;
    }

    /**
     * Members of a list, joined with the linked WP user so callers have
     * display name/email without a second query. LEFT JOIN to wp_users so
     * orphaned members still appear (rendered with a warning badge upstream).
     */
    public static function members( $list_id ) {
        global $wpdb;
        $list_id = (int) $list_id;
        if ( ! $list_id ) return array();

        $members = self::table_members();
        $p       = $wpdb->prefix . 'ke_promoters';
        $u       = $wpdb->users;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id,
                    COALESCE(NULLIF(u.display_name, ''), u.user_login, p.slug) AS name,
                    COALESCE(u.user_email, '') AS email,
                    p.slug, p.status, m.added_at
               FROM $members m
               JOIN $p p ON p.id = m.promoter_id
          LEFT JOIN $u u ON u.ID = p.user_id
              WHERE m.list_id = %d
           ORDER BY name ASC",
            $list_id
        ) );
    }

    /**
     * Bulk-assign every member of a list to an event, with the given
     * commission terms. Skips promoters already assigned to the event
     * (their existing override stays put). Inactive promoters are
     * excluded — adding an inactive promoter is almost certainly a
     * mistake and they wouldn't earn anyway.
     *
     * Returns number of new event-promoter rows written.
     *
     * Tracks the new promoter ids in a static var so callers can fetch
     * via ::last_newly_assigned_ids() without an extra DB query.
     */
    public static $last_newly_assigned_ids = array();
    public static function assign_list_to_event( $list_id, $event_id, $commission_type, $commission_value ) {
        global $wpdb;
        $list_id  = (int) $list_id;
        $event_id = (int) $event_id;
        if ( ! $list_id || ! $event_id ) return 0;

        $type = in_array( $commission_type, array( 'percentage', 'fixed' ), true ) ? $commission_type : 'percentage';
        $val  = max( 0, (float) $commission_value );
        if ( $type === 'percentage' && $val > 100 ) $val = 100;

        $members = self::members( $list_id );
        if ( ! $members ) return 0;

        $existing = $wpdb->get_col( $wpdb->prepare(
            "SELECT promoter_id FROM {$wpdb->prefix}ke_event_promoters WHERE event_id = %d",
            $event_id
        ) );
        $existing = array_map( 'intval', (array) $existing );

        $written = 0;
        $new_ids = array();
        $ep      = $wpdb->prefix . 'ke_event_promoters';
        $now     = current_time( 'mysql' );
        foreach ( $members as $m ) {
            if ( $m->status !== 'active' && $m->status !== 'pending' ) continue;
            $pid = (int) $m->id;
            if ( in_array( $pid, $existing, true ) ) continue;

            $ok = $wpdb->insert(
                $ep,
                array(
                    'event_id'         => $event_id,
                    'promoter_id'      => $pid,
                    'commission_type'  => $type,
                    'commission_value' => $val,
                    'assigned_at'      => $now,
                ),
                array( '%d', '%d', '%s', '%f', '%s' )
            );
            if ( $ok ) { $written++; $new_ids[] = $pid; }
        }
        self::$last_newly_assigned_ids = $new_ids;
        return $written;
    }
}
