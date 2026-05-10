<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reservations (group/capacity bookings).
 *
 * Sits alongside the ticket system: an event can have tickets, reservations,
 * or both. Tickets count one row per attendee; reservations count one row
 * per contact who is holding `party_size` seats — capacity is enforced by
 * summing party_size across rows whose status is "holding" (pending +
 * confirmed) so a manual venue can keep pending reservations from
 * overbooking until they're approved.
 *
 * Configuration lives in post meta `_ke_event_reservations_config` — see
 * self::default_config() for the shape. Per-reservation rows live in
 * `wp_ke_reservations`.
 *
 * This class is the single source of truth used by:
 *   - Event builder save           (admin → REST update_event)
 *   - Public booking flow          (REST POST /reservations)            [Phase 2]
 *   - Organizer dashboard actions  (approve/decline/check-in/cancel)    [Phase 3]
 *   - Auto no-show cron            (mark expired pending/confirmed)     [Phase 4]
 */
class KE_Reservations {

    const META_KEY      = '_ke_event_reservations_config';
    const CODE_PREFIX   = 'RES';

    /** Statuses that consume capacity (a "holding" reservation). */
    const HOLDING_STATUSES = array( 'pending', 'confirmed' );

    /** All known statuses. Validation rejects anything outside this list. */
    const ALL_STATUSES = array(
        'pending',
        'confirmed',
        'cancelled',
        'cancelled_no_show',
        'cancelled_by_venue',
        'declined',
    );

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ke_reservations';
    }

    /* ─────────────────────────────────────────────────────────────────────
     * CONFIG (post meta)
     * ────────────────────────────────────────────────────────────────── */

    /** Allowed values for an area's optional visual treatment. */
    const FANCY_EFFECTS = array( 'none', 'gold', 'diamond', 'vip', 'crown', 'neon' );

    /** Default config shape — used when meta is empty or malformed. */
    public static function default_config() {
        return array(
            'enabled'              => false,
            'total_capacity'       => 0,
            'reservations_open'    => '',
            'reservations_close'   => '',
            'confirmation_mode'    => 'auto',
            'grace_period_minutes' => 15,
            'auto_cancel_no_show'  => true,
            'show_email_field'     => true,
            'show_notes_field'     => true,
            // Module-level marketing copy shown above the public reservation card.
            'description'          => '',
            // Display toggles — server still enforces capacity regardless of
            // these. They only control what the public UI exposes.
            'show_total_capacity'  => true,
            'show_area_capacity'   => true,
            'areas'                => array(),
        );
    }

    /**
     * Read + normalise the saved configuration. Always returns the full
     * shape so callers don't have to defend against missing keys.
     */
    public static function get_config( $event_id ) {
        $event_id = (int) $event_id;
        $raw = get_post_meta( $event_id, self::META_KEY, true );
        if ( ! is_array( $raw ) ) {
            return self::default_config();
        }
        $defaults = self::default_config();
        $cfg = array(
            'enabled'              => ! empty( $raw['enabled'] ),
            'total_capacity'       => max( 0, (int) ( $raw['total_capacity'] ?? 0 ) ),
            'reservations_open'    => isset( $raw['reservations_open'] ) ? (string) $raw['reservations_open'] : '',
            'reservations_close'   => isset( $raw['reservations_close'] ) ? (string) $raw['reservations_close'] : '',
            'confirmation_mode'    => ( ( $raw['confirmation_mode'] ?? 'auto' ) === 'manual' ) ? 'manual' : 'auto',
            'grace_period_minutes' => max( 0, (int) ( $raw['grace_period_minutes'] ?? $defaults['grace_period_minutes'] ) ),
            'auto_cancel_no_show'  => array_key_exists( 'auto_cancel_no_show', $raw ) ? ! empty( $raw['auto_cancel_no_show'] ) : true,
            'show_email_field'     => array_key_exists( 'show_email_field', $raw ) ? ! empty( $raw['show_email_field'] ) : true,
            'show_notes_field'     => array_key_exists( 'show_notes_field', $raw ) ? ! empty( $raw['show_notes_field'] ) : true,
            'description'          => isset( $raw['description'] ) ? (string) $raw['description'] : '',
            'show_total_capacity'  => array_key_exists( 'show_total_capacity', $raw ) ? ! empty( $raw['show_total_capacity'] ) : true,
            'show_area_capacity'   => array_key_exists( 'show_area_capacity', $raw )  ? ! empty( $raw['show_area_capacity'] )  : true,
            'areas'                => array(),
        );
        if ( ! empty( $raw['areas'] ) && is_array( $raw['areas'] ) ) {
            foreach ( $raw['areas'] as $a ) {
                if ( ! is_array( $a ) ) continue;
                $name = isset( $a['name'] ) ? (string) $a['name'] : '';
                if ( $name === '' ) continue;
                $effect = isset( $a['fancy_effect'] ) ? (string) $a['fancy_effect'] : 'none';
                if ( ! in_array( $effect, self::FANCY_EFFECTS, true ) ) $effect = 'none';
                $cfg['areas'][] = array(
                    'name'         => $name,
                    'description'  => isset( $a['description'] ) ? (string) $a['description'] : '',
                    'capacity'     => max( 0, (int) ( $a['capacity'] ?? 0 ) ),
                    'fancy_effect' => $effect,
                );
            }
        }
        return $cfg;
    }

    /** True when the event has reservations toggled on AND a positive capacity. */
    public static function is_active( $event_id ) {
        $cfg = self::get_config( $event_id );
        return ! empty( $cfg['enabled'] ) && (int) $cfg['total_capacity'] > 0;
    }

    /**
     * Sanitize a posted config blob coming from the event builder. Mirrors
     * KE_Event_Extra_Fields::sanitize_config() in shape: drops unknown keys,
     * coerces types, drops areas with no name. Empty arrays/strings are
     * preserved (admin may legitimately clear a field).
     */
    public static function sanitize_config( $input ) {
        $out = self::default_config();
        if ( ! is_array( $input ) ) return $out;

        $out['enabled']              = ! empty( $input['enabled'] );
        $out['total_capacity']       = max( 0, (int) ( $input['total_capacity'] ?? 0 ) );
        $out['reservations_open']    = self::sanitize_datetime( $input['reservations_open'] ?? '' );
        $out['reservations_close']   = self::sanitize_datetime( $input['reservations_close'] ?? '' );
        $out['confirmation_mode']    = ( ( $input['confirmation_mode'] ?? 'auto' ) === 'manual' ) ? 'manual' : 'auto';
        $out['grace_period_minutes'] = max( 0, min( 240, (int) ( $input['grace_period_minutes'] ?? 15 ) ) );
        $out['auto_cancel_no_show']  = array_key_exists( 'auto_cancel_no_show', $input ) ? ! empty( $input['auto_cancel_no_show'] ) : true;
        $out['show_email_field']     = array_key_exists( 'show_email_field', $input ) ? ! empty( $input['show_email_field'] ) : true;
        $out['show_notes_field']     = array_key_exists( 'show_notes_field', $input ) ? ! empty( $input['show_notes_field'] ) : true;
        $out['description']          = isset( $input['description'] ) ? sanitize_textarea_field( (string) $input['description'] ) : '';
        $out['show_total_capacity']  = array_key_exists( 'show_total_capacity', $input ) ? ! empty( $input['show_total_capacity'] ) : true;
        $out['show_area_capacity']   = array_key_exists( 'show_area_capacity', $input )  ? ! empty( $input['show_area_capacity'] )  : true;

        if ( ! empty( $input['areas'] ) && is_array( $input['areas'] ) ) {
            $seen = array();
            foreach ( $input['areas'] as $a ) {
                if ( ! is_array( $a ) ) continue;
                $name = sanitize_text_field( (string) ( $a['name'] ?? '' ) );
                if ( $name === '' ) continue;
                // De-dupe by case-insensitive name so admins can't create
                // two "VIP" areas that would split capacity tracking.
                $key = mb_strtolower( $name );
                if ( isset( $seen[ $key ] ) ) continue;
                $seen[ $key ] = true;
                $effect = isset( $a['fancy_effect'] ) ? (string) $a['fancy_effect'] : 'none';
                if ( ! in_array( $effect, self::FANCY_EFFECTS, true ) ) $effect = 'none';
                $out['areas'][] = array(
                    'name'         => $name,
                    'description'  => isset( $a['description'] ) ? sanitize_text_field( (string) $a['description'] ) : '',
                    'capacity'     => max( 0, (int) ( $a['capacity'] ?? 0 ) ),
                    'fancy_effect' => $effect,
                );
            }
        }
        return $out;
    }

    /** Accepts "Y-m-d H:i" or "Y-m-d H:i:s" or "Y-m-dTH:i". Returns "Y-m-d H:i:s" or ''. */
    private static function sanitize_datetime( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) return '';
        $value = str_replace( 'T', ' ', $value );
        $ts = strtotime( $value );
        if ( ! $ts ) return '';
        return date( 'Y-m-d H:i:s', $ts );
    }

    /* ─────────────────────────────────────────────────────────────────────
     * CAPACITY ENGINE
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Snapshot of capacity for an event. Pending+confirmed both consume
     * capacity (so a manual venue can't be overbooked while approvals
     * are queued).
     *
     * Returns:
     *   array(
     *     'total'     => int,
     *     'used'      => int,         // sum of party_size across holding rows
     *     'remaining' => int,
     *     'areas'     => array(
     *        array( name, capacity, used, remaining ),
     *        ...
     *     ),
     *   )
     */
    public function get_capacity_state( $event_id ) {
        global $wpdb;
        $cfg = self::get_config( $event_id );

        $holding_in = "'" . implode( "','", array_map( 'esc_sql', self::HOLDING_STATUSES ) ) . "'";

        $used_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(party_size), 0)
             FROM {$this->table_name}
             WHERE event_id = %d AND status IN ({$holding_in})",
            (int) $event_id
        ) );

        $areas_state = array();
        if ( ! empty( $cfg['areas'] ) ) {
            // One query keyed by area name (case-sensitive — sanitize_config
            // already de-duped by lowercased name so this is consistent).
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT area, COALESCE(SUM(party_size), 0) AS used
                 FROM {$this->table_name}
                 WHERE event_id = %d AND status IN ({$holding_in})
                 GROUP BY area",
                (int) $event_id
            ) );
            $by_area = array();
            foreach ( $rows as $r ) {
                if ( $r->area === null || $r->area === '' ) continue;
                $by_area[ $r->area ] = (int) $r->used;
            }
            foreach ( $cfg['areas'] as $a ) {
                $used = isset( $by_area[ $a['name'] ] ) ? $by_area[ $a['name'] ] : 0;
                $areas_state[] = array(
                    'name'      => $a['name'],
                    'capacity'  => (int) $a['capacity'],
                    'used'      => $used,
                    'remaining' => max( 0, (int) $a['capacity'] - $used ),
                );
            }
        }

        $total = (int) $cfg['total_capacity'];
        return array(
            'total'     => $total,
            'used'      => $used_total,
            'remaining' => max( 0, $total - $used_total ),
            'areas'     => $areas_state,
        );
    }

    /**
     * Check whether a new reservation of the given party_size would fit.
     *
     * Returns true on success, or WP_Error with a user-facing message and
     * `available` data attached so the caller (REST endpoint) can pass a
     * specific number back to the customer.
     *
     * NOT a substitute for the row-level locking in create() — call this
     * for upfront UX/error messaging, then re-check inside the transaction
     * to close the race window.
     */
    public function check_can_book( $event_id, $party_size, $area = null ) {
        $party_size = (int) $party_size;
        if ( $party_size < 1 ) {
            return new WP_Error( 'invalid_party_size', 'Party size must be at least 1.', array( 'status' => 400 ) );
        }
        $cfg = self::get_config( $event_id );
        if ( empty( $cfg['enabled'] ) ) {
            return new WP_Error( 'reservations_disabled', 'Reservations are not available for this event.', array( 'status' => 400 ) );
        }

        $state = $this->get_capacity_state( $event_id );
        if ( $state['remaining'] < $party_size ) {
            return new WP_Error(
                'capacity_full',
                sprintf( 'Only %d spot(s) remaining. Reduce your party size or try a different time.', $state['remaining'] ),
                array( 'status' => 409, 'available' => $state['remaining'] )
            );
        }

        if ( $area && ! empty( $cfg['areas'] ) ) {
            $area_match = null;
            foreach ( $state['areas'] as $a ) {
                if ( $a['name'] === $area ) { $area_match = $a; break; }
            }
            if ( ! $area_match ) {
                return new WP_Error( 'invalid_area', 'Selected area is not available.', array( 'status' => 400 ) );
            }
            if ( $area_match['remaining'] < $party_size ) {
                return new WP_Error(
                    'area_full',
                    sprintf( 'Only %d spot(s) left in %s. Try another area or reduce your party.', $area_match['remaining'], $area_match['name'] ),
                    array( 'status' => 409, 'available' => $area_match['remaining'], 'area' => $area_match['name'] )
                );
            }
        }
        return true;
    }

    /* ─────────────────────────────────────────────────────────────────────
     * CRUD
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Create a reservation. Wraps the capacity check + INSERT in a
     * transaction so a flurry of simultaneous requests can't overbook.
     *
     * `$data` keys (all required unless noted):
     *   event_id, customer_name, customer_phone, party_size, arrival_time
     *   customer_email (optional, depending on event config)
     *   area, notes, extra_fields (optional)
     *
     * Returns the full row on success or WP_Error on failure.
     */
    public function create( $data ) {
        global $wpdb;

        $event_id   = (int) ( $data['event_id'] ?? 0 );
        $party_size = (int) ( $data['party_size'] ?? 0 );
        $area       = isset( $data['area'] ) ? (string) $data['area'] : null;

        $cfg = self::get_config( $event_id );
        if ( empty( $cfg['enabled'] ) ) {
            return new WP_Error( 'reservations_disabled', 'Reservations are not available for this event.', array( 'status' => 400 ) );
        }

        // Pre-flight UX check (cheap; gives the customer a friendly error
        // before we lock anything).
        $can = $this->check_can_book( $event_id, $party_size, $area );
        if ( is_wp_error( $can ) ) return $can;

        $arrival = self::sanitize_datetime( $data['arrival_time'] ?? '' );
        if ( ! $arrival ) {
            return new WP_Error( 'invalid_arrival', 'Please pick a valid arrival time.', array( 'status' => 400 ) );
        }

        $name  = sanitize_text_field( (string) ( $data['customer_name']  ?? '' ) );
        $phone = sanitize_text_field( (string) ( $data['customer_phone'] ?? '' ) );
        if ( $name === '' || $phone === '' ) {
            return new WP_Error( 'missing_fields', 'Name and phone are required.', array( 'status' => 400 ) );
        }

        $email = '';
        if ( ! empty( $data['customer_email'] ) ) {
            $email = sanitize_email( (string) $data['customer_email'] );
            if ( $email && ! is_email( $email ) ) {
                return new WP_Error( 'invalid_email', 'Please enter a valid email.', array( 'status' => 400 ) );
            }
        }

        $notes = isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : '';

        // Encode extras as canonical JSON (string-only values, keyed by stable id).
        $ef_data = null;
        if ( ! empty( $data['extra_fields'] ) && is_array( $data['extra_fields'] ) ) {
            $clean = array();
            foreach ( $data['extra_fields'] as $fid => $val ) {
                $clean[ (string) $fid ] = is_scalar( $val ) ? (string) $val : '';
            }
            if ( ! empty( $clean ) ) $ef_data = wp_json_encode( $clean );
        }

        $status = ( $cfg['confirmation_mode'] === 'manual' ) ? 'pending' : 'confirmed';

        // Lock the row range with a transaction so a second concurrent
        // request can't read the same "remaining" snapshot. SELECT ... FOR
        // UPDATE on the existing rows for this event holds the necessary
        // gap until our INSERT commits.
        $wpdb->query( 'START TRANSACTION' );

        $holding_in = "'" . implode( "','", array_map( 'esc_sql', self::HOLDING_STATUSES ) ) . "'";
        $used_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(party_size), 0)
             FROM {$this->table_name}
             WHERE event_id = %d AND status IN ({$holding_in})
             FOR UPDATE",
            $event_id
        ) );
        if ( ( $used_total + $party_size ) > (int) $cfg['total_capacity'] ) {
            $wpdb->query( 'ROLLBACK' );
            $remaining = max( 0, (int) $cfg['total_capacity'] - $used_total );
            return new WP_Error(
                'capacity_full',
                sprintf( 'Only %d spot(s) remaining. Please reduce your party size.', $remaining ),
                array( 'status' => 409, 'available' => $remaining )
            );
        }

        if ( $area && ! empty( $cfg['areas'] ) ) {
            $area_cap = 0;
            foreach ( $cfg['areas'] as $a ) {
                if ( $a['name'] === $area ) { $area_cap = (int) $a['capacity']; break; }
            }
            $used_area = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(party_size), 0)
                 FROM {$this->table_name}
                 WHERE event_id = %d AND area = %s AND status IN ({$holding_in})
                 FOR UPDATE",
                $event_id, $area
            ) );
            if ( ( $used_area + $party_size ) > $area_cap ) {
                $wpdb->query( 'ROLLBACK' );
                $remaining = max( 0, $area_cap - $used_area );
                return new WP_Error(
                    'area_full',
                    sprintf( 'Only %d spot(s) left in %s.', $remaining, $area ),
                    array( 'status' => 409, 'available' => $remaining, 'area' => $area )
                );
            }
        }

        $code = $this->generate_unique_code();
        $now  = current_time( 'mysql' );

        $insert = array(
            'event_id'         => $event_id,
            'reservation_code' => $code,
            'status'           => $status,
            'customer_name'    => $name,
            'customer_email'   => $email,
            'customer_phone'   => $phone,
            'party_size'       => $party_size,
            'arrival_time'     => $arrival,
            'area'             => ( $area && $area !== '' ) ? $area : null,
            'notes'            => $notes !== '' ? $notes : null,
            'extra_fields_data'=> $ef_data,
            'created_at'       => $now,
            'updated_at'       => $now,
        );
        $format = array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );

        $result = $wpdb->insert( $this->table_name, $insert, $format );
        if ( $result === false ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'db_error', 'Could not create reservation.' );
        }
        $id = (int) $wpdb->insert_id;
        $wpdb->query( 'COMMIT' );

        return $this->get( $id );
    }

    public function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, p.post_title AS event_name
             FROM {$this->table_name} r
             LEFT JOIN {$wpdb->posts} p ON r.event_id = p.ID
             WHERE r.id = %d",
            (int) $id
        ) );
    }

    public function get_by_code( $code ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, p.post_title AS event_name
             FROM {$this->table_name} r
             LEFT JOIN {$wpdb->posts} p ON r.event_id = p.ID
             WHERE r.reservation_code = %s",
            (string) $code
        ) );
    }

    public function get_for_event( $event_id, $args = array() ) {
        global $wpdb;
        $defaults = array(
            'status'  => '',  // '' = all
            'search'  => '',
            'limit'   => 100,
            'offset'  => 0,
            'orderby' => 'arrival_time',
            'order'   => 'ASC',
        );
        $args = wp_parse_args( $args, $defaults );

        $where  = "WHERE event_id = %d";
        $params = array( (int) $event_id );

        if ( $args['status'] !== '' && in_array( $args['status'], self::ALL_STATUSES, true ) ) {
            $where .= " AND status = %s";
            $params[] = $args['status'];
        }
        if ( ! empty( $args['search'] ) ) {
            $where .= " AND (customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s OR reservation_code LIKE %s)";
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
        if ( ! $orderby ) $orderby = 'arrival_time ASC';

        $params[] = (int) $args['limit'];
        $params[] = (int) $args['offset'];

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table_name} {$where} ORDER BY {$orderby} LIMIT %d OFFSET %d",
            $params
        ) );
    }

    /** Pair to get_for_event() — returns the unfiltered total for pagination. */
    public function count_for_event( $event_id, $args = array() ) {
        global $wpdb;
        $where  = "WHERE event_id = %d";
        $params = array( (int) $event_id );

        if ( ! empty( $args['status'] ) && in_array( $args['status'], self::ALL_STATUSES, true ) ) {
            $where .= " AND status = %s";
            $params[] = (string) $args['status'];
        }
        if ( ! empty( $args['search'] ) ) {
            $where .= " AND (customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s OR reservation_code LIKE %s)";
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} {$where}",
            $params
        ) );
    }

    /**
     * Cross-event listing for the wp-admin Reservations page. event_id=0
     * means "all events". Otherwise behaves like get_for_event(). Joins
     * wp_posts so the view can show event titles without N+1.
     */
    public function get_all( $args = array() ) {
        global $wpdb;
        $defaults = array(
            'event_id' => 0,
            'status'   => '',
            'search'   => '',
            'limit'    => 25,
            'offset'   => 0,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        );
        $args = wp_parse_args( $args, $defaults );

        $where  = "WHERE 1=1";
        $params = array();

        if ( (int) $args['event_id'] > 0 ) {
            $where   .= " AND r.event_id = %d";
            $params[] = (int) $args['event_id'];
        }
        if ( $args['status'] !== '' && in_array( $args['status'], self::ALL_STATUSES, true ) ) {
            $where   .= " AND r.status = %s";
            $params[] = (string) $args['status'];
        }
        if ( ! empty( $args['search'] ) ) {
            $where .= " AND (r.customer_name LIKE %s OR r.customer_email LIKE %s OR r.customer_phone LIKE %s OR r.reservation_code LIKE %s)";
            $like   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        // Whitelist orderable columns — sanitize_sql_orderby strips anything
        // unexpected, but we want a deterministic fallback.
        $allowed = array( 'created_at', 'arrival_time', 'status', 'party_size', 'customer_name' );
        $col     = in_array( $args['orderby'], $allowed, true ) ? $args['orderby'] : 'created_at';
        $dir     = strtoupper( (string) $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $params[] = (int) $args['limit'];
        $params[] = (int) $args['offset'];

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, p.post_title AS event_title
             FROM {$this->table_name} r
             LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
             {$where}
             ORDER BY r.{$col} {$dir}
             LIMIT %d OFFSET %d",
            $params
        ) );
    }

    /** Pair to get_all() — total matching count for pagination. */
    public function count_all( $args = array() ) {
        global $wpdb;
        $where  = "WHERE 1=1";
        $params = array();

        if ( ! empty( $args['event_id'] ) && (int) $args['event_id'] > 0 ) {
            $where   .= " AND event_id = %d";
            $params[] = (int) $args['event_id'];
        }
        if ( ! empty( $args['status'] ) && in_array( $args['status'], self::ALL_STATUSES, true ) ) {
            $where   .= " AND status = %s";
            $params[] = (string) $args['status'];
        }
        if ( ! empty( $args['search'] ) ) {
            $where .= " AND (customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s OR reservation_code LIKE %s)";
            $like   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ( empty( $params ) ) {
            return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name} {$where}" );
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} {$where}",
            $params
        ) );
    }

    /**
     * Stats roll-up for the admin Reservations page strip. Honours the same
     * event/search filters as get_all() so the strip and table agree, then
     * groups everything else by status. Pending+confirmed party_size is
     * surfaced as "holding seats" so the operator sees committed capacity.
     *
     * Status filter is intentionally NOT applied — the strip shows the
     * distribution across all statuses for the current event/search scope.
     */
    public function compute_stats( $args = array() ) {
        global $wpdb;
        $defaults = array( 'event_id' => 0, 'search' => '' );
        $args = wp_parse_args( $args, $defaults );

        $where  = "WHERE 1=1";
        $params = array();
        if ( (int) $args['event_id'] > 0 ) {
            $where   .= " AND event_id = %d";
            $params[] = (int) $args['event_id'];
        }
        if ( ! empty( $args['search'] ) ) {
            $where .= " AND (customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s OR reservation_code LIKE %s)";
            $like   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $sql = "SELECT status,
                       COUNT(*) AS rows_count,
                       COALESCE(SUM(party_size), 0) AS seats,
                       SUM(CASE WHEN checked_in_at IS NOT NULL THEN 1 ELSE 0 END) AS checked_in
                FROM {$this->table_name} {$where}
                GROUP BY status";
        $rows = empty( $params )
            ? $wpdb->get_results( $sql )
            : $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $by_status = array();
        foreach ( self::ALL_STATUSES as $s ) {
            $by_status[ $s ] = array( 'rows' => 0, 'seats' => 0, 'checked_in' => 0 );
        }
        $total_rows = $total_seats = $checked_in = 0;
        foreach ( $rows as $r ) {
            $s = (string) $r->status;
            if ( ! isset( $by_status[ $s ] ) ) continue; // unknown status — defensive
            $by_status[ $s ]['rows']       = (int) $r->rows_count;
            $by_status[ $s ]['seats']      = (int) $r->seats;
            $by_status[ $s ]['checked_in'] = (int) $r->checked_in;
            $total_rows  += (int) $r->rows_count;
            $total_seats += (int) $r->seats;
            $checked_in  += (int) $r->checked_in;
        }
        $holding_seats = $by_status['pending']['seats'] + $by_status['confirmed']['seats'];
        return array(
            'total_rows'    => $total_rows,
            'total_seats'   => $total_seats,
            'holding_seats' => $holding_seats,
            'checked_in'    => $checked_in,
            'by_status'     => $by_status,
        );
    }

    /**
     * Update status. Stamps `updated_at`, and `checked_in_at` when moving
     * to checked-in. Returns the fresh row or WP_Error.
     */
    public function update_status( $id, $new_status, $extra = array() ) {
        global $wpdb;
        $row = $this->get( $id );
        if ( ! $row ) return new WP_Error( 'not_found', 'Reservation not found.' );
        if ( ! in_array( $new_status, self::ALL_STATUSES, true ) ) {
            return new WP_Error( 'invalid_status', 'Invalid status.' );
        }

        $update = array( 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) );
        $format = array( '%s', '%s' );

        if ( $new_status === 'declined' && isset( $extra['decline_reason'] ) ) {
            $update['decline_reason'] = sanitize_textarea_field( (string) $extra['decline_reason'] );
            $format[] = '%s';
        }
        if ( $new_status === 'cancelled_no_show' ) {
            $update['no_show_processed'] = 1;
            $format[] = '%d';
        }

        $wpdb->update(
            $this->table_name,
            $update,
            array( 'id' => (int) $id ),
            $format,
            array( '%d' )
        );
        return $this->get( $id );
    }

    public function check_in( $id, $by_user_id = 0 ) {
        global $wpdb;
        $row = $this->get( $id );
        if ( ! $row ) return new WP_Error( 'not_found', 'Reservation not found.' );
        if ( $row->status !== 'confirmed' ) {
            return new WP_Error( 'not_confirmed', 'Only confirmed reservations can be checked in.' );
        }
        $wpdb->update(
            $this->table_name,
            array(
                'checked_in_at' => current_time( 'mysql' ),
                'checked_in_by' => (int) $by_user_id,
                'updated_at'    => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $id ),
            array( '%s', '%d', '%s' ),
            array( '%d' )
        );
        return $this->get( $id );
    }

    /* ─────────────────────────────────────────────────────────────────────
     * NO-SHOW SWEEP (cron)
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Cancel confirmed reservations that have passed their arrival time plus
     * the event's grace period without checking in. Idempotent: rows that
     * have already been processed (no_show_processed=1) or checked in
     * (checked_in_at IS NOT NULL) are skipped.
     *
     * Per-event policy is held in post meta, not the row, so the SQL
     * query just returns plausible candidates (status=confirmed, arrival
     * already past) and the per-event grace + auto_cancel_no_show toggle
     * are evaluated in PHP.
     *
     * Returns:
     *   array(
     *     'scanned'    => int,  // candidate rows examined
     *     'cancelled'  => int,  // rows transitioned to cancelled_no_show
     *     'skipped'    => int,  // grace not yet expired, or auto-cancel off
     *     'email_fail' => int,  // notifications that failed (cancel still applied)
     *   )
     *
     * @param array $opts {
     *   @type int  $limit       Max rows to process per call. Default 200.
     *   @type bool $send_emails Default true.
     * }
     */
    public function run_no_show_sweep( $opts = array() ) {
        global $wpdb;
        $limit       = isset( $opts['limit'] ) ? max( 1, (int) $opts['limit'] ) : 200;
        $send_emails = ! array_key_exists( 'send_emails', $opts ) || ! empty( $opts['send_emails'] );

        $now      = current_time( 'mysql' );
        $now_ts   = current_time( 'timestamp' );

        // Candidates: confirmed, not checked in, not yet processed, arrival
        // already in the past. Grace + auto-cancel evaluated below.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE status = %s
               AND no_show_processed = 0
               AND checked_in_at IS NULL
               AND arrival_time < %s
             ORDER BY arrival_time ASC
             LIMIT %d",
            'confirmed', $now, $limit
        ) );

        $stats = array(
            'scanned'    => 0,
            'cancelled'  => 0,
            'skipped'    => 0,
            'email_fail' => 0,
        );
        if ( ! $rows ) return $stats;

        $email_handler = ( $send_emails && class_exists( 'KE_Email' ) ) ? new KE_Email() : null;

        foreach ( $rows as $row ) {
            $stats['scanned']++;
            $cfg = self::get_config( (int) $row->event_id );

            // Venue opted out of auto-cancel — leave alone but still flag
            // the row so we don't keep selecting it forever once the event
            // has passed by more than 24h.
            if ( empty( $cfg['auto_cancel_no_show'] ) ) {
                $arrival_ts = strtotime( $row->arrival_time );
                if ( $arrival_ts && ( $now_ts - $arrival_ts ) > DAY_IN_SECONDS ) {
                    $wpdb->update(
                        $this->table_name,
                        array( 'no_show_processed' => 1, 'updated_at' => $now ),
                        array( 'id' => (int) $row->id ),
                        array( '%d', '%s' ),
                        array( '%d' )
                    );
                }
                $stats['skipped']++;
                continue;
            }

            $grace      = max( 0, (int) ( $cfg['grace_period_minutes'] ?? 15 ) );
            $arrival_ts = strtotime( $row->arrival_time );
            if ( ! $arrival_ts ) {
                // Malformed arrival — flag it so it's not re-scanned.
                $wpdb->update(
                    $this->table_name,
                    array( 'no_show_processed' => 1, 'updated_at' => $now ),
                    array( 'id' => (int) $row->id ),
                    array( '%d', '%s' ),
                    array( '%d' )
                );
                $stats['skipped']++;
                continue;
            }

            // Grace not yet expired — give them more time.
            if ( ( $arrival_ts + ( $grace * MINUTE_IN_SECONDS ) ) > $now_ts ) {
                $stats['skipped']++;
                continue;
            }

            $updated = $this->update_status( (int) $row->id, 'cancelled_no_show' );
            if ( is_wp_error( $updated ) ) {
                $stats['skipped']++;
                continue;
            }
            $stats['cancelled']++;

            if ( $email_handler && method_exists( $email_handler, 'send_reservation_no_show_email' ) ) {
                try {
                    $sent = $email_handler->send_reservation_no_show_email( (int) $row->id );
                    if ( is_wp_error( $sent ) ) $stats['email_fail']++;
                } catch ( \Throwable $e ) {
                    $stats['email_fail']++;
                    error_log( 'KiwiEvents no-show email error for #' . $row->id . ': ' . $e->getMessage() );
                }
            }
        }

        return $stats;
    }

    /** Generate a unique reservation code, retrying on the slim chance of collision. */
    private function generate_unique_code() {
        $table = $this->table_name;
        $exists = function ( $candidate ) use ( $table ) {
            global $wpdb;
            return (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE reservation_code = %s LIMIT 1",
                $candidate
            ) );
        };
        return KE_Codes::generate( self::CODE_PREFIX, $exists );
    }
}
