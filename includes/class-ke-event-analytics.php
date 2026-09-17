<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Per-event audience analytics: visits to the event page and clicks on its
 * calls to action, aggregated per site-local day.
 *
 * Storage is one counter row per (event, day, metric) in wp_ke_event_analytics.
 * Nothing about the visitor is ever stored — no IP, no user agent, no id — so
 * the table stays tiny (events × days × 5 metrics) and there is nothing to
 * protect or purge.
 *
 * Counting happens in the browser (public/js/ke-analytics.js) via a beacon to
 * POST /ke/v1/analytics/hit, because the event page HTML is edge-cached for
 * anonymous visitors on WordPress.com: a PHP-side counter would only fire on
 * cache misses and miss most of the traffic.
 *
 * Consumers: the organizer dashboard (/organizer/{slug}, "Event traffic")
 * and wp-admin → KiwiEvents → Analytics.
 */
class KE_Event_Analytics {

    /** Every metric the beacon may report. Anything else is rejected. */
    const METRICS = array( 'view', 'ticket_click', 'reserve_click', 'birthday_click', 'share_click' );

    /** The metrics that together make "clicks". */
    const CLICK_METRICS = array( 'ticket_click', 'reserve_click', 'birthday_click', 'share_click' );

    /** Reporting ranges. 'day' = today, 'week' = last 7 days, 'month' = last 30 days. */
    const RANGES = array( 'day', 'week', 'month', 'all' );

    // Per-IP ceiling on the public beacon. A person can produce maybe a
    // dozen hits per minute; this only exists to bound a script.
    const HIT_RATE_LIMIT  = 120;
    const HIT_RATE_WINDOW = 60;
    const HIT_RATE_PREFIX = 'ke_an_rl_';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'ke_event_analytics';
    }

    public static function is_valid_metric( $metric ) {
        return in_array( (string) $metric, self::METRICS, true );
    }

    public static function is_valid_range( $range ) {
        return in_array( (string) $range, self::RANGES, true );
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Recording
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Add one hit to (event, today, metric). Atomic upsert, so concurrent
     * beacons never lose a count.
     */
    public static function record_hit( $event_id, $metric, $day = null ) {
        global $wpdb;
        $event_id = absint( $event_id );
        if ( $event_id <= 0 || ! self::is_valid_metric( $metric ) ) {
            return false;
        }
        $day = $day ? (string) $day : current_time( 'Y-m-d' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
            return false;
        }
        $table  = self::table();
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (event_id, day, metric, hits)
             VALUES (%d, %s, %s, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1",
            $event_id,
            $day,
            (string) $metric
        ) );
        return $result !== false;
    }

    /**
     * Fixed-window per-IP limit for the public beacon. Same shape as the
     * scanner's limiter: a rejected burst never extends the window.
     */
    public static function check_hit_rate() {
        $ip  = class_exists( 'KE_Scanner_Password' ) ? KE_Scanner_Password::get_request_ip() : ( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
        $key = self::HIT_RATE_PREFIX . md5( $ip . wp_salt( 'nonce' ) );
        $now = time();
        $val = get_transient( $key );
        if ( ! is_array( $val ) || empty( $val['start'] ) || ( $now - (int) $val['start'] ) >= self::HIT_RATE_WINDOW ) {
            $val = array( 'start' => $now, 'count' => 0 );
        }
        if ( (int) $val['count'] >= self::HIT_RATE_LIMIT ) {
            return false;
        }
        $val['count'] = (int) $val['count'] + 1;
        set_transient( $key, $val, max( 1, self::HIT_RATE_WINDOW - ( $now - (int) $val['start'] ) ) );
        return true;
    }

    /**
     * Whether the page being rendered should carry the tracking beacon.
     * Staff previews (anyone who can manage events, edit posts or scan
     * tickets) are excluded so an organizer checking their own page does
     * not inflate the numbers. Anonymous renders — the ones the edge cache
     * serves to real visitors — always track.
     */
    public static function should_track_request() {
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return false;
        }
        if ( function_exists( 'is_preview' ) && is_preview() ) {
            return false;
        }
        if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
            return false;
        }
        if ( is_user_logged_in() && (
            current_user_can( 'manage_kiwi_events' ) || current_user_can( 'edit_posts' ) || current_user_can( 'scan_ke_tickets' )
        ) ) {
            return false;
        }
        return true;
    }

    /**
     * Enqueue the beacon script for one event page. Safe to call from inside
     * a template: WordPress prints footer scripts after the template runs.
     */
    public static function enqueue_assets( $event_id ) {
        $event_id = absint( $event_id );
        if ( $event_id <= 0 ) {
            return;
        }
        $ver = defined( 'KE_ANALYTICS_ASSETS_VER' ) ? KE_ANALYTICS_ASSETS_VER : KE_VERSION;
        wp_enqueue_script(
            'ke-analytics-js',
            KE_PLUGIN_URL . 'public/js/ke-analytics.js',
            array(),
            $ver,
            true
        );
        wp_localize_script( 'ke-analytics-js', 'kePublicAnalytics', array(
            'eventId'  => $event_id,
            'endpoint' => esc_url_raw( rest_url( 'ke/v1/analytics/hit' ) ),
        ) );
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Reporting
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Resolve a range keyword into [from, to] site-local dates (inclusive).
     * 'all' has no lower bound and no per-day series.
     */
    public static function range_bounds( $range ) {
        $range = self::is_valid_range( $range ) ? (string) $range : 'week';
        $today = current_time( 'Y-m-d' );
        $today_ts = strtotime( $today . ' 00:00:00' );
        switch ( $range ) {
            case 'day':
                $from = $today;
                break;
            case 'month':
                $from = date( 'Y-m-d', $today_ts - 29 * DAY_IN_SECONDS );
                break;
            case 'all':
                $from = null;
                break;
            default: // week
                $from = date( 'Y-m-d', $today_ts - 6 * DAY_IN_SECONDS );
        }
        return array( 'range' => $range, 'from' => $from, 'to' => $today );
    }

    /**
     * Aggregate counters for a set of events in a range.
     *
     * Returns:
     *   range, from, to      — as resolved
     *   days                 — list of 'Y-m-d' covered ([] for 'all')
     *   totals               — metric => hits across all events
     *   clicks_total         — sum of CLICK_METRICS
     *   series               — metric => per-day hits across all events (null for 'all')
     *   per_event            — event_id => { totals: {metric => hits}, clicks: int,
     *                            series: { view: [...], clicks: [...] } | null }
     */
    public static function report( array $event_ids, $range ) {
        global $wpdb;

        $bounds    = self::range_bounds( $range );
        $event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );

        $days = array();
        if ( $bounds['from'] ) {
            for ( $ts = strtotime( $bounds['from'] ), $end = strtotime( $bounds['to'] ); $ts <= $end; $ts += DAY_IN_SECONDS ) {
                $days[] = date( 'Y-m-d', $ts );
            }
        }
        $day_index = array_flip( $days );
        $n_days    = count( $days );

        $zero_metrics = array_fill_keys( self::METRICS, 0 );
        $totals       = $zero_metrics;
        $series       = $n_days ? array_fill_keys( self::METRICS, array_fill( 0, $n_days, 0 ) ) : null;
        $per_event    = array();
        foreach ( $event_ids as $eid ) {
            $per_event[ $eid ] = array(
                'totals' => $zero_metrics,
                'clicks' => 0,
                'series' => $n_days ? array( 'view' => array_fill( 0, $n_days, 0 ), 'clicks' => array_fill( 0, $n_days, 0 ) ) : null,
            );
        }

        if ( ! empty( $event_ids ) ) {
            $table = self::table();
            $in    = implode( ',', $event_ids );
            $sql   = "SELECT event_id, day, metric, hits FROM {$table} WHERE event_id IN ({$in})";
            if ( $bounds['from'] ) {
                $sql .= $wpdb->prepare( ' AND day BETWEEN %s AND %s', $bounds['from'], $bounds['to'] );
            }
            $rows = $wpdb->get_results( $sql );
            foreach ( (array) $rows as $r ) {
                $eid    = (int) $r->event_id;
                $metric = (string) $r->metric;
                $hits   = (int) $r->hits;
                if ( ! isset( $per_event[ $eid ] ) || ! isset( $zero_metrics[ $metric ] ) ) {
                    continue;
                }
                $is_click = in_array( $metric, self::CLICK_METRICS, true );
                $totals[ $metric ]                        += $hits;
                $per_event[ $eid ]['totals'][ $metric ]   += $hits;
                if ( $is_click ) {
                    $per_event[ $eid ]['clicks'] += $hits;
                }
                if ( $n_days ) {
                    $day = (string) $r->day;
                    if ( isset( $day_index[ $day ] ) ) {
                        $i = $day_index[ $day ];
                        $series[ $metric ][ $i ] += $hits;
                        if ( $metric === 'view' ) {
                            $per_event[ $eid ]['series']['view'][ $i ] += $hits;
                        } elseif ( $is_click ) {
                            $per_event[ $eid ]['series']['clicks'][ $i ] += $hits;
                        }
                    }
                }
            }
        }

        $clicks_total = 0;
        foreach ( self::CLICK_METRICS as $m ) {
            $clicks_total += $totals[ $m ];
        }

        return array(
            'range'        => $bounds['range'],
            'from'         => $bounds['from'],
            'to'           => $bounds['to'],
            'days'         => $days,
            'totals'       => $totals,
            'clicks_total' => $clicks_total,
            'series'       => $series,
            'per_event'    => $per_event,
        );
    }

    /**
     * Report for every event under one organizer, with the event metadata
     * the dashboards need. Events are ordered by visits (desc), then date.
     */
    public static function report_for_organizer( $organizer_id, $range ) {
        $ids = class_exists( 'KE_Organizer_Stats' )
            ? KE_Organizer_Stats::get_event_ids_for_organizer( $organizer_id )
            : array();
        return self::build_report( $ids, $range, false );
    }

    /**
     * Report across every published event (admin "All organizers" view).
     */
    public static function report_for_all( $range ) {
        $ids = get_posts( array(
            'post_type'      => 'ke_event',
            'post_status'    => array( 'publish', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );
        return self::build_report( array_map( 'intval', (array) $ids ), $range, true );
    }

    private static function build_report( array $event_ids, $range, $with_organizer ) {
        $data   = self::report( $event_ids, $range );
        $events = array();

        foreach ( $event_ids as $eid ) {
            $post = get_post( $eid );
            if ( ! $post || $post->post_type !== 'ke_event' || $post->post_status === 'trash' ) {
                continue;
            }
            $bucket = $data['per_event'][ $eid ] ?? null;
            if ( ! $bucket ) {
                continue;
            }
            $organizer_name = '';
            if ( $with_organizer ) {
                $terms = wp_get_post_terms( $eid, 'ke_organizer' );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    $organizer_name = $terms[0]->name;
                }
            }
            $views  = (int) $bucket['totals']['view'];
            $clicks = (int) $bucket['clicks'];
            $events[] = array(
                'id'         => (int) $eid,
                'title'      => $post->post_title,
                'date'       => (string) get_post_meta( $eid, '_ke_event_date_start', true ),
                'status'     => (string) ( get_post_meta( $eid, '_ke_event_status', true ) ?: 'active' ),
                'post_status'=> $post->post_status,
                'permalink'  => get_permalink( $eid ),
                'organizer'  => $organizer_name,
                'totals'     => $bucket['totals'],
                'clicks'     => $clicks,
                // Ticket click-through: how many of the visits tapped a ticket.
                'ticket_ctr' => $views > 0 ? round( ( (int) $bucket['totals']['ticket_click'] / $views ) * 100, 1 ) : 0.0,
                'series'     => $bucket['series'],
            );
        }

        usort( $events, function ( $a, $b ) {
            $va = (int) $a['totals']['view'];
            $vb = (int) $b['totals']['view'];
            if ( $va !== $vb ) {
                return $vb <=> $va;
            }
            return strcmp( (string) $b['date'], (string) $a['date'] );
        } );

        unset( $data['per_event'] );
        $data['events']  = $events;
        // Lets the dashboards label days that came from the WordPress.com
        // Stats import rather than from the beacon (null = never imported).
        $data['history'] = class_exists( 'KE_Analytics_History' ) ? KE_Analytics_History::public_summary() : null;
        return $data;
    }
}
