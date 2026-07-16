<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Event ↔ Promoter assignment table API.
 *
 * Wraps {prefix}ke_event_promoters. Each row says "this promoter earns
 * a commission on sales for this event, at this rate." Per-event override
 * — if no row exists for (event_id, promoter_id), no commission is generated
 * even if the promoter's tracking link drove the sale (per spec).
 */
class KE_Event_Promoters {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'ke_event_promoters';
    }

    /**
     * All assignments for an event, joined to the promoter row + the linked
     * WP user so the caller has display name/email/slug/status without a
     * second query.
     *
     * Orphaned promoters (status='orphaned' / NULL user_id) are intentionally
     * excluded via INNER JOIN to wp_users — we don't surface them in the
     * event-builder dropdown or active-assignment list.
     *
     * Returns rows shaped like:
     *   { id, event_id, promoter_id, commission_type, commission_value,
     *     assigned_at, name, email, slug, status }
     */
    public static function list_for_event( $event_id ) {
        global $wpdb;
        $event_id = (int) $event_id;
        if ( ! $event_id ) return array();

        $ep = self::table();
        $p  = $wpdb->prefix . 'ke_promoters';
        $u  = $wpdb->users;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT ep.id, ep.event_id, ep.promoter_id,
                    ep.commission_type, ep.commission_value, ep.assigned_at,
                    COALESCE(NULLIF(u.display_name, ''), u.user_login) AS name,
                    u.user_email AS email,
                    p.slug, p.status
               FROM $ep ep
               JOIN $p p ON p.id  = ep.promoter_id
               JOIN $u u ON u.ID  = p.user_id
              WHERE ep.event_id = %d
              ORDER BY name ASC",
            $event_id
        ) );
    }

    /**
     * Single assignment for a specific (event, promoter) pair. Returns
     * the row or null. Used by the commission generator.
     */
    public static function get( $event_id, $promoter_id ) {
        global $wpdb;
        $event_id    = (int) $event_id;
        $promoter_id = (int) $promoter_id;
        if ( ! $event_id || ! $promoter_id ) return null;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
              WHERE event_id = %d AND promoter_id = %d LIMIT 1",
            $event_id, $promoter_id
        ) );
    }

    /**
     * Replace the full assignment set for an event in one shot.
     *
     * @param int   $event_id
     * @param array $assignments Each item: [
     *     'promoter_id'      => int,
     *     'commission_type'  => 'percentage'|'fixed',
     *     'commission_value' => float,
     *   ]
     *
     * Behavior:
     *   - Promoters in the new set that don't exist as rows yet are INSERTed.
     *   - Promoters present in both old + new sets are UPDATEd (rate may have changed).
     *   - Promoters in old but not new are DELETEd.
     *
     * Existing ke_promoter_commissions rows are preserved untouched —
     * a per-event override change should not retroactively rewrite earnings.
     */
    public static function set_for_event( $event_id, array $assignments ) {
        global $wpdb;
        $event_id = (int) $event_id;
        if ( ! $event_id ) return 0;

        $table = self::table();

        // Build the desired state, keyed by promoter_id (dedupe — last one wins).
        $desired = array();
        foreach ( $assignments as $row ) {
            $pid = isset( $row['promoter_id'] ) ? (int) $row['promoter_id'] : 0;
            if ( ! $pid ) continue;

            $type = isset( $row['commission_type'] ) ? (string) $row['commission_type'] : 'percentage';
            if ( ! in_array( $type, array( 'percentage', 'fixed' ), true ) ) {
                $type = 'percentage';
            }
            $value = isset( $row['commission_value'] ) ? round( (float) $row['commission_value'], 2 ) : 0.0;
            if ( $value < 0 ) $value = 0.0;
            // A 100%+ commission is almost certainly a data-entry mistake;
            // clamp so we don't quietly hand the whole sale to the promoter.
            if ( $type === 'percentage' && $value > 100 ) $value = 100.0;

            $desired[ $pid ] = array(
                'commission_type'  => $type,
                'commission_value' => $value,
            );
        }

        // Fetch existing rows.
        $existing_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, promoter_id, commission_type, commission_value
               FROM $table WHERE event_id = %d",
            $event_id
        ) );
        $existing_by_pid = array();
        foreach ( $existing_rows as $r ) {
            $existing_by_pid[ (int) $r->promoter_id ] = $r;
        }

        $touched = 0;

        // Upsert desired set.
        foreach ( $desired as $pid => $cfg ) {
            if ( isset( $existing_by_pid[ $pid ] ) ) {
                $old = $existing_by_pid[ $pid ];
                $changed =
                    (string) $old->commission_type !== $cfg['commission_type'] ||
                    abs( (float) $old->commission_value - $cfg['commission_value'] ) > 0.001;
                if ( $changed ) {
                    $wpdb->update(
                        $table,
                        array(
                            'commission_type'  => $cfg['commission_type'],
                            'commission_value' => $cfg['commission_value'],
                        ),
                        array( 'id' => $old->id ),
                        array( '%s', '%f' ),
                        array( '%d' )
                    );
                    $touched++;
                }
            } else {
                $wpdb->insert(
                    $table,
                    array(
                        'event_id'         => $event_id,
                        'promoter_id'      => $pid,
                        'commission_type'  => $cfg['commission_type'],
                        'commission_value' => $cfg['commission_value'],
                        'assigned_at'      => current_time( 'mysql' ),
                    ),
                    array( '%d', '%d', '%s', '%f', '%s' )
                );
                $touched++;
            }
        }

        // Delete rows no longer in the desired set.
        foreach ( $existing_by_pid as $pid => $old ) {
            if ( ! isset( $desired[ $pid ] ) ) {
                $wpdb->delete( $table, array( 'id' => $old->id ), array( '%d' ) );
                $touched++;
            }
        }

        return $touched;
    }

    /**
     * Remove a single (event, promoter) assignment row. Existing commissions
     * for that pair stay intact — historical earnings are never rewritten.
     * Returns true if a row was deleted, false otherwise.
     */
    public static function unassign( $event_id, $promoter_id ) {
        global $wpdb;
        $event_id    = (int) $event_id;
        $promoter_id = (int) $promoter_id;
        if ( ! $event_id || ! $promoter_id ) return false;

        $deleted = $wpdb->delete(
            self::table(),
            array( 'event_id' => $event_id, 'promoter_id' => $promoter_id ),
            array( '%d', '%d' )
        );
        return (bool) $deleted;
    }

    /**
     * Upcoming events the promoter is assigned to. "Upcoming" = published events
     * whose _ke_event_date_start (or _ke_event_date_end, if set) is today or later.
     * Used by the admin [View active events] drawer and the promoter dashboard.
     */
    public static function list_active_events_for_promoter( $promoter_id ) {
        $all   = self::list_events_for_promoter( $promoter_id );
        $today = current_time( 'Y-m-d' );
        $out   = array();
        foreach ( $all as $row ) {
            $end   = get_post_meta( $row->event_id, '_ke_event_date_end',   true );
            $start = get_post_meta( $row->event_id, '_ke_event_date_start', true );
            $when  = $end ?: $start;
            if ( ! $when ) continue;
            if ( substr( (string) $when, 0, 10 ) < $today ) continue;
            $row->date_start = $start;
            $row->date_end   = $end;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * All events a given promoter is currently assigned to. Used by the
     * promoter portal dashboard for the tracking-URL panel.
     */
    public static function list_events_for_promoter( $promoter_id ) {
        global $wpdb;
        $promoter_id = (int) $promoter_id;
        if ( ! $promoter_id ) return array();

        $ep = self::table();
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT event_id, commission_type, commission_value, assigned_at
               FROM $ep WHERE promoter_id = %d
              ORDER BY assigned_at DESC",
            $promoter_id
        ) );

        // Decorate with event title + permalink for convenience.
        $out = array();
        foreach ( $rows as $r ) {
            $post = get_post( (int) $r->event_id );
            if ( ! $post || $post->post_type !== 'ke_event' ) continue;
            $out[] = (object) array(
                'event_id'         => (int) $r->event_id,
                'title'            => $post->post_title,
                'permalink'        => get_permalink( $post ),
                'status'           => $post->post_status,
                'commission_type'  => $r->commission_type,
                'commission_value' => (float) $r->commission_value,
                'assigned_at'      => $r->assigned_at,
            );
        }
        return $out;
    }
}
