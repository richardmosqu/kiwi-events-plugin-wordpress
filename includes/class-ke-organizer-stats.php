<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Aggregations for the organizer self-service dashboard.
 *
 * All queries are scoped to a single ke_organizer term so a token issued for
 * one organizer can never read another's numbers.
 *
 * Net revenue model
 *   revenue per (event, ticket type) = sold_count × max(0, price − service_fee)
 *   event totals and headline totals roll up from there.
 *
 * The order's stored total_amount is never used as the source of truth for
 * revenue, because it includes the service fee that the customer paid on top
 * — which is not the organizer's revenue. Instead we rebuild revenue from the
 * ticket type's price (in wp_ke_ticket_types) minus the per-ticket fee from
 * the event's assigned ke_service_fees config. Free tickets contribute 0.
 */
class KE_Organizer_Stats {

    public static function get_event_ids_for_organizer( $organizer_id ) {
        $organizer_id = (int) $organizer_id;
        if ( $organizer_id <= 0 ) return array();
        $ids = get_objects_in_term( $organizer_id, 'ke_organizer' );
        if ( is_wp_error( $ids ) || empty( $ids ) ) return array();
        return array_values( array_filter( array_map( 'intval', $ids ) ) );
    }

    /**
     * Per-ticket fee for an event/ticket-type pair, using current config.
     * Free tickets (price <= 0) never carry a fee — applying a fixed-amount
     * fee to them would push the math negative and clamp the row to $0.
     */
    public static function fee_per_ticket( $event_id, $ticket_type_id, $fees_cache = null, $types_cache = null ) {
        $event_id       = (int) $event_id;
        $ticket_type_id = (int) $ticket_type_id;
        if ( $event_id <= 0 || $ticket_type_id <= 0 ) return 0.0;

        $fee_id = get_post_meta( $event_id, '_ke_event_service_fee_id', true );
        if ( ! $fee_id ) return 0.0;

        $fees = is_array( $fees_cache ) ? $fees_cache : get_option( 'ke_service_fees', array() );
        $fee  = null;
        foreach ( $fees as $f ) {
            if ( ( $f['id'] ?? '' ) === $fee_id ) { $fee = $f; break; }
        }
        if ( ! $fee ) return 0.0;

        if ( is_array( $types_cache ) && isset( $types_cache[ $ticket_type_id ] ) ) {
            $price = (float) $types_cache[ $ticket_type_id ];
        } else {
            global $wpdb;
            $price = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT price FROM {$wpdb->prefix}ke_ticket_types WHERE id = %d",
                $ticket_type_id
            ) );
        }

        if ( $price <= 0.0 ) return 0.0;

        if ( ( $fee['type'] ?? '' ) === 'formula' ) {
            return round( ( $price * (float) ( $fee['percentage'] ?? 0 ) / 100 )
                        + (float) ( $fee['fixed_amount'] ?? 0 ), 2 );
        }
        return round( (float) ( $fee['fixed_amount'] ?? 0 ), 2 );
    }

    /**
     * Net revenue contribution for a single ticket of this (event, type).
     * = max(0, price - per-ticket fee). Free tickets always return 0.
     */
    public static function net_per_ticket( $event_id, $ticket_type_id, $fees_cache = null, $types_cache = null ) {
        if ( is_array( $types_cache ) && isset( $types_cache[ (int) $ticket_type_id ] ) ) {
            $price = (float) $types_cache[ (int) $ticket_type_id ];
        } else {
            global $wpdb;
            $price = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT price FROM {$wpdb->prefix}ke_ticket_types WHERE id = %d",
                (int) $ticket_type_id
            ) );
        }
        if ( $price <= 0.0 ) return 0.0;
        $fee = self::fee_per_ticket( $event_id, $ticket_type_id, $fees_cache, $types_cache );
        return max( 0.0, round( $price - $fee, 2 ) );
    }

    /**
     * Window helper: returns ['start' => 'Y-m-d 00:00:00', 'end' => 'Y-m-d H:i:s']
     * for a relative range like 7/30/90/365 days. 'all' returns nulls.
     */
    public static function window_for_range( $range ) {
        if ( $range === 'all' ) return array( 'start' => null, 'end' => null );
        $days  = max( 1, (int) $range );
        $end   = current_time( 'mysql' );
        $start = date( 'Y-m-d 00:00:00', strtotime( "-{$days} days", strtotime( $end ) ) );
        return array( 'start' => $start, 'end' => $end );
    }

    /**
     * Sold counts per (event_id, ticket_type_id) within the window for these
     * events. Returns one row per (event, type) with the live ticket-type
     * name + price plus a snapshot fallback name for archived/deleted types.
     *
     * Single-table query on wp_ke_tickets — no join through wp_ke_orders, so
     * the predicate uses the (event_id, status, created_at) composite index
     * we add in the activator.
     */
    public static function sold_by_type( array $event_ids, $start_sql = null, $end_sql = null ) {
        if ( empty( $event_ids ) ) return array();
        global $wpdb;

        $event_ids    = array_map( 'intval', $event_ids );
        $placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
        $where  = "WHERE t.event_id IN ($placeholders) AND t.status != 'cancelled'";
        $params = $event_ids;
        if ( $start_sql ) { $where .= ' AND t.created_at >= %s'; $params[] = $start_sql; }
        if ( $end_sql )   { $where .= ' AND t.created_at <  %s'; $params[] = $end_sql;   }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT t.event_id,
                    t.ticket_type_id,
                    COUNT(*) AS sold,
                    MAX(t.ticket_type_snapshot) AS snapshot_name,
                    MAX(tt.name)  AS live_name,
                    MAX(tt.price) AS price
             FROM {$wpdb->prefix}ke_tickets t
             LEFT JOIN {$wpdb->prefix}ke_ticket_types tt ON tt.id = t.ticket_type_id
             $where
             GROUP BY t.event_id, t.ticket_type_id",
            $params
        ) );
    }

    /**
     * Annotate a sold_by_type row with computed price/fee/net/revenue fields,
     * sharing the fees+types caches so we don't round-trip the DB per row.
     */
    private static function annotate_row( $row, $fees_cache, $types_cache ) {
        $event_id = (int) $row->event_id;
        $type_id  = (int) $row->ticket_type_id;
        $sold     = (int) $row->sold;
        $price    = (float) ( $row->price ?? 0 );
        $is_free  = ( $price <= 0 );
        $fee      = self::fee_per_ticket( $event_id, $type_id, $fees_cache, $types_cache );
        $net_per  = $is_free ? 0.0 : max( 0.0, $price - $fee );
        $revenue  = round( $net_per * $sold, 2 );
        $name     = $row->live_name ?: ( $row->snapshot_name ?: __( 'Unknown', 'kiwi-events' ) );
        return array(
            'event_id'       => $event_id,
            'ticket_type_id' => $type_id,
            'name'           => (string) $name,
            'sold'           => $sold,
            'price'          => $price,
            'fee_per_ticket' => $fee,
            'net_per_ticket' => $net_per,
            'revenue'        => $revenue,
            'is_free'        => $is_free,
        );
    }

    /**
     * Build fees+types caches for a list of sold_by_type rows.
     */
    private static function build_caches( array $rows ) {
        $fees_cache  = get_option( 'ke_service_fees', array() );
        $types_cache = array();
        foreach ( $rows as $r ) {
            $types_cache[ (int) $r->ticket_type_id ] = (float) ( $r->price ?? 0 );
        }
        return array( $fees_cache, $types_cache );
    }

    /**
     * Headline numbers for the 4 stat cards.
     *
     * Returns:
     *   tickets_sold       — count of paid+free tickets in window
     *   tickets_sold_trend — % vs previous equal-length window (null for 'all')
     *   net_revenue        — sum of sold × (price − fee) across all events
     *   free_tickets       — count of tickets where price == 0
     *   check_in_rate      — % of non-cancelled tickets with status=used
     */
    public static function headline_stats( $organizer_id, $range = '30' ) {
        $event_ids = self::get_event_ids_for_organizer( $organizer_id );
        $blank = array(
            'tickets_sold'        => 0,
            'tickets_sold_trend'  => null,
            'tickets_sold_is_new' => false,
            'net_revenue'         => 0.0,
            'paid_tickets'        => 0,
            'free_tickets'        => 0,
            'free_only'           => true,
            'check_in_rate'       => 0.0,
            'check_in_total'      => 0,
            'check_in_used'       => 0,
            'currency'            => get_option( 'ke_currency', 'USD' ),
            'range'               => $range,
        );
        if ( empty( $event_ids ) ) return $blank;

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
        $win = self::window_for_range( $range );

        // Single grouped query — drives tickets_sold, free/paid split, and
        // net revenue. Avoids three separate scans of the tickets table.
        $rows = self::sold_by_type( $event_ids, $win['start'], $win['end'] );
        list( $fees_cache, $types_cache ) = self::build_caches( $rows );

        $tickets_sold = 0;
        $paid_tickets = 0;
        $free_tickets = 0;
        $net_revenue  = 0.0;
        foreach ( $rows as $r ) {
            $a = self::annotate_row( $r, $fees_cache, $types_cache );
            $tickets_sold += $a['sold'];
            if ( $a['is_free'] ) { $free_tickets += $a['sold']; }
            else                 { $paid_tickets += $a['sold']; $net_revenue += $a['revenue']; }
        }
        $net_revenue = round( $net_revenue, 2 );
        $free_only   = ( $paid_tickets === 0 );

        // Trend vs previous equal-length window (tickets sold count only).
        $trend  = null;
        $is_new = false;
        if ( $win['start'] && $win['end'] ) {
            $span_seconds = strtotime( $win['end'] ) - strtotime( $win['start'] );
            $prev_end     = $win['start'];
            $prev_start   = date( 'Y-m-d H:i:s', strtotime( $win['start'] ) - $span_seconds );

            $prev_params  = $event_ids;
            $prev_params[] = $prev_start;
            $prev_params[] = $prev_end;
            $prev_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ke_tickets
                 WHERE event_id IN ($placeholders) AND status != 'cancelled'
                   AND created_at >= %s AND created_at < %s",
                $prev_params
            ) );
            if ( $prev_count > 0 ) {
                $trend = round( ( ( $tickets_sold - $prev_count ) / $prev_count ) * 100, 1 );
            } elseif ( $tickets_sold > 0 ) {
                $is_new = true;
            } else {
                $trend = 0.0;
            }
        }

        // Lifetime check-in rate (a check-in for an old ticket still counts).
        $checkin_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ke_tickets WHERE event_id IN ($placeholders) AND status != 'cancelled'",
            $event_ids
        ) );
        $checkin_used = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ke_tickets WHERE event_id IN ($placeholders) AND status = 'used'",
            $event_ids
        ) );
        $checkin_rate = $checkin_total > 0 ? round( ( $checkin_used / $checkin_total ) * 100, 1 ) : 0.0;

        return array(
            'tickets_sold'        => $tickets_sold,
            'tickets_sold_trend'  => $trend,
            'tickets_sold_is_new' => $is_new,
            'net_revenue'         => $net_revenue,
            'paid_tickets'        => $paid_tickets,
            'free_tickets'        => $free_tickets,
            'free_only'           => $free_only,
            'check_in_rate'       => $checkin_rate,
            'check_in_total'      => $checkin_total,
            'check_in_used'       => $checkin_used,
            'currency'            => get_option( 'ke_currency', 'USD' ),
            'range'               => $range,
        );
    }

    /**
     * Daily series for the chart: one bucket per day, ascending order.
     * Returns: [ ['date' => 'Y-m-d', 'tickets' => int, 'net_revenue' => float], ... ]
     *
     * Daily revenue uses the same model as headline/breakdown:
     *   sum over (event, type) of qty_on_day × max(0, price − fee)
     */
    public static function daily_series( $organizer_id, $range = '30' ) {
        $event_ids = self::get_event_ids_for_organizer( $organizer_id );
        if ( empty( $event_ids ) ) return array();

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
        $win = self::window_for_range( $range );

        $where  = "WHERE event_id IN ($placeholders) AND status != 'cancelled'";
        $params = $event_ids;
        if ( $win['start'] ) { $where .= ' AND created_at >= %s'; $params[] = $win['start']; }
        if ( $win['end'] )   { $where .= ' AND created_at <  %s'; $params[] = $win['end'];   }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) AS d, event_id, ticket_type_id, COUNT(*) AS qty
             FROM {$wpdb->prefix}ke_tickets
             $where
             GROUP BY DATE(created_at), event_id, ticket_type_id
             ORDER BY d ASC",
            $params
        ) );

        // Cache fee config + ticket-type prices used by net_per_ticket.
        $fees_cache  = get_option( 'ke_service_fees', array() );
        $type_ids    = array_unique( array_map( function ( $r ) { return (int) $r->ticket_type_id; }, $rows ) );
        $types_cache = array();
        if ( ! empty( $type_ids ) ) {
            $tt_ph = implode( ',', array_fill( 0, count( $type_ids ), '%d' ) );
            $tt    = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, price FROM {$wpdb->prefix}ke_ticket_types WHERE id IN ($tt_ph)",
                $type_ids
            ) );
            foreach ( $tt as $row ) { $types_cache[ (int) $row->id ] = (float) $row->price; }
        }

        $by_date = array();
        foreach ( $rows as $r ) {
            $d = (string) $r->d;
            if ( ! isset( $by_date[ $d ] ) ) {
                $by_date[ $d ] = array( 'date' => $d, 'tickets' => 0, 'net_revenue' => 0.0 );
            }
            $qty   = (int) $r->qty;
            $net_p = self::net_per_ticket( (int) $r->event_id, (int) $r->ticket_type_id, $fees_cache, $types_cache );
            $by_date[ $d ]['tickets']     += $qty;
            $by_date[ $d ]['net_revenue'] += $net_p * $qty;
        }

        // Fill missing days inside the window with zeros for a continuous chart.
        if ( $win['start'] && $win['end'] ) {
            $cursor = strtotime( substr( $win['start'], 0, 10 ) );
            $stop   = strtotime( substr( $win['end'],   0, 10 ) );
            while ( $cursor <= $stop ) {
                $d = date( 'Y-m-d', $cursor );
                if ( ! isset( $by_date[ $d ] ) ) {
                    $by_date[ $d ] = array( 'date' => $d, 'tickets' => 0, 'net_revenue' => 0.0 );
                }
                $cursor += DAY_IN_SECONDS;
            }
        }

        ksort( $by_date );
        $out = array();
        foreach ( $by_date as $b ) {
            $out[] = array(
                'date'        => $b['date'],
                'tickets'     => (int) $b['tickets'],
                'net_revenue' => round( max( 0.0, (float) $b['net_revenue'] ), 2 ),
            );
        }
        return $out;
    }

    /**
     * Per-ticket-type breakdown for a single event in the given window.
     * Revenue = sold × max(0, price − fee). Free tickets always 0.
     */
    public static function ticket_type_breakdown( $event_id, $range = 'all' ) {
        $event_id = (int) $event_id;
        if ( $event_id <= 0 ) return array();

        $win  = self::window_for_range( $range );
        $rows = self::sold_by_type( array( $event_id ), $win['start'], $win['end'] );
        if ( empty( $rows ) ) return array();

        list( $fees_cache, $types_cache ) = self::build_caches( $rows );

        $out = array();
        foreach ( $rows as $r ) {
            $a = self::annotate_row( $r, $fees_cache, $types_cache );
            $out[] = array(
                'ticket_type_id' => $a['ticket_type_id'],
                'name'           => $a['name'],
                'sold'           => $a['sold'],
                'revenue'        => $a['revenue'],
                'is_free'        => $a['is_free'],
            );
        }
        usort( $out, function ( $a, $b ) { return $b['sold'] <=> $a['sold']; } );
        return $out;
    }

    /**
     * Per-event breakdown with net revenue, tickets sold, check-in rate.
     * Net revenue is the sum of the per-type breakdown for the same window —
     * same source of truth as the headline card and the dashboard chart.
     */
    public static function events_breakdown( $organizer_id, $range = 'all' ) {
        $event_ids = self::get_event_ids_for_organizer( $organizer_id );
        if ( empty( $event_ids ) ) return array();

        global $wpdb;
        $win = self::window_for_range( $range );

        // One grouped query for every event under this organizer in the window.
        $rows = self::sold_by_type( $event_ids, $win['start'], $win['end'] );
        list( $fees_cache, $types_cache ) = self::build_caches( $rows );

        // Group annotated rows by event for assembly below.
        $by_event = array();
        foreach ( $rows as $r ) {
            $a   = self::annotate_row( $r, $fees_cache, $types_cache );
            $eid = $a['event_id'];
            if ( ! isset( $by_event[ $eid ] ) ) {
                $by_event[ $eid ] = array( 'sold' => 0, 'net' => 0.0, 'types' => array() );
            }
            $by_event[ $eid ]['sold'] += $a['sold'];
            $by_event[ $eid ]['net']  += $a['revenue'];
            $by_event[ $eid ]['types'][] = array(
                'ticket_type_id' => $a['ticket_type_id'],
                'name'           => $a['name'],
                'sold'           => $a['sold'],
                'revenue'        => $a['revenue'],
                'is_free'        => $a['is_free'],
            );
        }

        $out = array();
        foreach ( $event_ids as $event_id ) {
            $post = get_post( $event_id );
            if ( ! $post || $post->post_type !== 'ke_event' ) continue;

            $bucket = $by_event[ $event_id ] ?? array( 'sold' => 0, 'net' => 0.0, 'types' => array() );
            $types  = $bucket['types'];
            usort( $types, function ( $a, $b ) { return $b['sold'] <=> $a['sold']; } );

            // Lifetime check-in counts for the event (matches the headline card).
            $checkin_total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ke_tickets WHERE event_id = %d AND status != 'cancelled'",
                $event_id
            ) );
            $checkin_used = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ke_tickets WHERE event_id = %d AND status = 'used'",
                $event_id
            ) );

            $out[] = array(
                'id'             => (int) $event_id,
                'title'          => $post->post_title,
                'date'           => (string) get_post_meta( $event_id, '_ke_event_date_start', true ),
                'tickets_sold'   => (int) $bucket['sold'],
                'net_revenue'    => round( (float) $bucket['net'], 2 ),
                'check_in_rate'  => $checkin_total > 0 ? round( ( $checkin_used / $checkin_total ) * 100, 1 ) : 0.0,
                'check_in_total' => $checkin_total,
                'check_in_used'  => $checkin_used,
                'ticket_types'   => $types,
            );
        }

        usort( $out, function ( $a, $b ) {
            return strcmp( (string) ( $b['date'] ?? '' ), (string) ( $a['date'] ?? '' ) );
        } );
        return $out;
    }
}
