<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One-time import of historical event-page visits from WordPress.com Stats
 * (Jetpack) into wp_ke_event_analytics.
 *
 * The plugin's own counter only started the day 2.6.0 was deployed, but
 * WordPress.com has counted the views of every event page since the site
 * went live. This brings those days in, event by event, so the traffic
 * charts have a past. Clicks cannot be recovered: nothing ever recorded them.
 *
 * Rules — a read-only preview first, an explicit confirm, and idempotent:
 *  - Only days strictly BEFORE today are ever written. Today, and any day
 *    that already has an own `view` row for that event, are skipped, so the
 *    two sources never add up on the same day.
 *  - Re-running only fills gaps. Nothing is overwritten or duplicated.
 *  - Definitions differ: WordPress.com counts page views (a repeat open is
 *    two), the beacon counts one visit per browser session. The import is
 *    logged in an option so both UIs can label pre-cutover days.
 *
 * Provider resolution, in order: the `ke_analytics_history_provider` filter
 * (tests / other sources), Jetpack's WPCOM_Stats package, the legacy
 * stats_get_from_restapi() from the Stats module. None present → the tool
 * says so and does nothing.
 */
class KE_Analytics_History {

    const OPTION    = 'ke_analytics_history_log';
    const MAX_BATCH = 20;

    /* ─────────────────────────────────────────────────────────────────────
     * Provider
     * ────────────────────────────────────────────────────────────────── */

    public static function is_available() {
        if ( has_filter( 'ke_analytics_history_provider' ) ) return true;
        if ( self::has_wpcom_stats_class() ) return true;
        if ( function_exists( 'stats_get_from_restapi' ) ) return true;
        return false;
    }

    public static function provider_label() {
        if ( has_filter( 'ke_analytics_history_provider' ) ) return 'custom provider';
        if ( self::has_wpcom_stats_class() || function_exists( 'stats_get_from_restapi' ) ) return 'WordPress.com Stats (Jetpack)';
        return '';
    }

    private static function has_wpcom_stats_class() {
        return class_exists( '\Automattic\Jetpack\Stats\WPCOM_Stats' )
            && method_exists( '\Automattic\Jetpack\Stats\WPCOM_Stats', 'get_post_views' );
    }

    /**
     * Daily page views for one post since it exists.
     *
     * @return array|WP_Error  'Y-m-d' => int (only days with views > 0), sorted.
     */
    public static function fetch_daily_views( $post_id ) {
        $post_id = absint( $post_id );
        if ( $post_id <= 0 ) return new WP_Error( 'invalid_post', 'Invalid event.' );

        // Test hook / alternative source: return array day => views, or WP_Error.
        $custom = apply_filters( 'ke_analytics_history_provider', null, $post_id );
        if ( is_wp_error( $custom ) ) return $custom;
        if ( is_array( $custom ) ) return self::clean_series( $custom );

        $raw = null;
        try {
            if ( self::has_wpcom_stats_class() ) {
                $stats = new \Automattic\Jetpack\Stats\WPCOM_Stats();
                $raw   = $stats->get_post_views( $post_id );
            } elseif ( function_exists( 'stats_get_from_restapi' ) ) {
                $raw = stats_get_from_restapi( array(), 'post/' . $post_id );
            } else {
                return new WP_Error( 'unavailable', 'WordPress.com Stats is not available on this site.' );
            }
        } catch ( \Throwable $e ) {
            return new WP_Error( 'provider_error', $e->getMessage() );
        }

        if ( is_wp_error( $raw ) ) return $raw;
        if ( empty( $raw ) ) return new WP_Error( 'empty_response', 'WordPress.com Stats returned nothing for this event.' );

        // Both providers may hand back objects or arrays; normalise to arrays.
        $data = json_decode( wp_json_encode( $raw ), true );
        if ( ! is_array( $data ) ) return new WP_Error( 'bad_response', 'Unexpected response from WordPress.com Stats.' );
        if ( isset( $data['error'] ) && ! isset( $data['data'] ) ) {
            return new WP_Error( 'api_error', (string) ( $data['message'] ?? $data['error'] ) );
        }
        return self::parse_series( $data );
    }

    /**
     * Accepts the /sites/{id}/stats/post/{post_id} shapes:
     *   data:  [[period, views], …]  or  [{period, views}, …]   (full history, preferred)
     *   weeks: [{days: [{day, count}, …]}, …]                   (recent weeks, fallback)
     */
    public static function parse_series( array $data ) {
        $series = array();
        if ( ! empty( $data['data'] ) && is_array( $data['data'] ) ) {
            foreach ( $data['data'] as $row ) {
                if ( ! is_array( $row ) ) continue;
                if ( isset( $row['period'] ) ) {
                    $day = (string) $row['period']; $views = (int) ( $row['views'] ?? 0 );
                } else {
                    $vals = array_values( $row );
                    if ( count( $vals ) < 2 ) continue;
                    $day = (string) $vals[0]; $views = (int) $vals[1];
                }
                if ( $views > 0 ) $series[ $day ] = ( $series[ $day ] ?? 0 ) + $views;
            }
        }
        if ( empty( $series ) && ! empty( $data['weeks'] ) && is_array( $data['weeks'] ) ) {
            foreach ( $data['weeks'] as $week ) {
                foreach ( (array) ( $week['days'] ?? array() ) as $d ) {
                    if ( ! is_array( $d ) ) continue;
                    $day = (string) ( $d['day'] ?? '' ); $views = (int) ( $d['count'] ?? 0 );
                    if ( $views > 0 ) $series[ $day ] = ( $series[ $day ] ?? 0 ) + $views;
                }
            }
        }
        return self::clean_series( $series );
    }

    private static function clean_series( array $series ) {
        $out = array();
        foreach ( $series as $day => $views ) {
            $day   = substr( (string) $day, 0, 10 );
            $views = (int) $views;
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || $views <= 0 ) continue;
            $out[ $day ] = ( $out[ $day ] ?? 0 ) + $views;
        }
        ksort( $out );
        return $out;
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Plan / preview / import
     * ────────────────────────────────────────────────────────────────── */

    /** Days this event already has an own `view` row for — never touched. */
    private static function own_view_days( $event_id ) {
        global $wpdb;
        $table = KE_Event_Analytics::table();
        $days  = $wpdb->get_col( $wpdb->prepare(
            "SELECT day FROM {$table} WHERE event_id = %d AND metric = 'view'",
            absint( $event_id )
        ) );
        return array_fill_keys( array_map( 'strval', (array) $days ), true );
    }

    /**
     * What an import would write for one event. Never writes.
     */
    public static function plan_for_event( $event_id ) {
        $event_id = absint( $event_id );
        $post     = get_post( $event_id );
        $base     = array(
            'id'            => $event_id,
            'title'         => $post ? $post->post_title : ( '#' . $event_id ),
            'error'         => null,
            'days'          => 0,
            'views'         => 0,
            'from'          => null,
            'to'            => null,
            'skipped_days'  => 0,
            'skipped_views' => 0,
            'series'        => array(),
        );
        if ( ! $post || $post->post_type !== 'ke_event' ) {
            $base['error'] = 'Not an event.';
            return $base;
        }
        $series = self::fetch_daily_views( $event_id );
        if ( is_wp_error( $series ) ) {
            $base['error'] = $series->get_error_message();
            return $base;
        }
        $today = current_time( 'Y-m-d' );
        $own   = self::own_view_days( $event_id );
        foreach ( $series as $day => $views ) {
            // Never today (the beacon is counting it) and never a day the
            // beacon already counted — the two sources must not add up.
            if ( $day >= $today || isset( $own[ $day ] ) ) {
                $base['skipped_days']++;
                $base['skipped_views'] += $views;
                continue;
            }
            $base['series'][ $day ] = $views;
        }
        if ( ! empty( $base['series'] ) ) {
            $base['days']  = count( $base['series'] );
            $base['views'] = array_sum( $base['series'] );
            $base['from']  = array_key_first( $base['series'] );
            $base['to']    = array_key_last( $base['series'] );
        }
        return $base;
    }

    /** Read-only preview for a batch of events (series stripped). */
    public static function preview( array $event_ids ) {
        $out = array();
        foreach ( self::batch( $event_ids ) as $id ) {
            $plan = self::plan_for_event( $id );
            unset( $plan['series'] );
            $out[] = $plan;
        }
        return $out;
    }

    /**
     * Write the planned rows for a batch of events. Idempotent: the plan
     * already excludes days the beacon counted, and the INSERT is a no-op on
     * an existing (event, day, metric) row.
     */
    public static function import( array $event_ids ) {
        global $wpdb;
        $table = KE_Event_Analytics::table();
        $out   = array();
        $log   = self::log() ?: array( 'cutover' => null, 'imported_at' => null, 'provider' => '', 'events' => array(), 'total_views' => 0, 'total_days' => 0 );
        $today = current_time( 'Y-m-d' );

        foreach ( self::batch( $event_ids ) as $id ) {
            $plan = self::plan_for_event( $id );
            $row  = array( 'id' => $plan['id'], 'title' => $plan['title'], 'error' => $plan['error'], 'inserted_days' => 0, 'inserted_views' => 0, 'from' => $plan['from'], 'to' => $plan['to'] );
            if ( $plan['error'] === null && ! empty( $plan['series'] ) ) {
                foreach ( array_chunk( $plan['series'], 200, true ) as $chunk ) {
                    $values = array();
                    foreach ( $chunk as $day => $views ) {
                        $values[] = $wpdb->prepare( '(%d, %s, %s, %d)', $plan['id'], $day, 'view', (int) $views );
                    }
                    $affected = $wpdb->query(
                        "INSERT INTO {$table} (event_id, day, metric, hits) VALUES " . implode( ',', $values )
                        . ' ON DUPLICATE KEY UPDATE hits = hits'
                    );
                    if ( $affected === false ) {
                        $row['error'] = 'Database error while importing.';
                        break;
                    }
                }
                if ( $row['error'] === null ) {
                    $row['inserted_days']  = $plan['days'];
                    $row['inserted_views'] = $plan['views'];
                    $prev = $log['events'][ $plan['id'] ] ?? array( 'days' => 0, 'views' => 0, 'from' => null, 'to' => null );
                    $log['events'][ $plan['id'] ] = array(
                        'title' => $plan['title'],
                        'days'  => (int) $prev['days'] + $plan['days'],
                        'views' => (int) $prev['views'] + $plan['views'],
                        'from'  => ( $prev['from'] && $prev['from'] < $plan['from'] ) ? $prev['from'] : $plan['from'],
                        'to'    => ( $prev['to'] && $prev['to'] > $plan['to'] ) ? $prev['to'] : $plan['to'],
                    );
                    $log['total_views'] += $plan['views'];
                    $log['total_days']  += $plan['days'];
                }
            }
            $out[] = $row;
        }

        // The cutover is the first import day: every imported day is before
        // it, every beacon day is on or after it. Set once, never moved.
        if ( empty( $log['cutover'] ) ) $log['cutover'] = $today;
        $log['imported_at'] = current_time( 'mysql' );
        $log['provider']    = self::provider_label();
        update_option( self::OPTION, $log, false );

        return $out;
    }

    private static function batch( array $event_ids ) {
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );
        return array_slice( $ids, 0, self::MAX_BATCH );
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Log
     * ────────────────────────────────────────────────────────────────── */

    public static function log() {
        $log = get_option( self::OPTION, null );
        return is_array( $log ) ? $log : null;
    }

    /** What the dashboards need to label imported days. Null when nothing was imported. */
    public static function public_summary() {
        $log = self::log();
        if ( ! $log || empty( $log['cutover'] ) || empty( $log['total_views'] ) ) return null;
        return array(
            'cutover'     => (string) $log['cutover'],
            'imported_at' => (string) ( $log['imported_at'] ?? '' ),
            'views'       => (int) $log['total_views'],
            'days'        => (int) $log['total_days'],
            'events'      => count( (array) ( $log['events'] ?? array() ) ),
            'provider'    => (string) ( $log['provider'] ?? '' ),
        );
    }

    /** Every event the import can look at: anything that has ever been public. */
    public static function candidate_event_ids() {
        $ids = get_posts( array(
            'post_type'      => 'ke_event',
            'post_status'    => array( 'publish', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );
        return array_map( 'intval', (array) $ids );
    }
}
