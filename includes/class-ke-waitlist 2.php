<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ticket-sales waitlist ("avísame cuando abran los boletos").
 *
 * One row per (event, email). Rows are created by the public form rendered
 * under the "Boletos disponibles a partir de …" notice (see
 * public/views/sales-waitlist.php) and drained by KE_Waitlist_Cron once the
 * event's KE_Sales_Schedule opening moment has passed.
 *
 * Status machine:
 *   pending   → signed up, still waiting for the sale to open
 *   notified  → the "ya están a la venta" email has been queued for them
 *   cancelled → the event was deleted or cancelled; row kept for the record
 *               but never mailed and never re-scanned by the sweep
 *
 * The row status IS the state — there is no per-event "already notified"
 * flag. That keeps the sweep idempotent (a claimed row can never be claimed
 * twice) and makes postponements work for free: if an organizer pushes the
 * sale back, new signups come in as `pending` and get the next blast.
 */
class KE_Waitlist {

    const STATUS_PENDING   = 'pending';
    const STATUS_NOTIFIED  = 'notified';
    const STATUS_CANCELLED = 'cancelled';

    const ALL_STATUSES = array( self::STATUS_PENDING, self::STATUS_NOTIFIED, self::STATUS_CANCELLED );

    /** How many rows one sweep tick drains per event. */
    const RELEASE_BATCH = 500;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'ke_waitlist';
    }

    /* ─────────────────────────────────────────────────────────────────────
     * WRITE
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Add an email to an event's waitlist.
     *
     * Idempotent: signing up twice is a success, not an error — the customer
     * should never be told "you already did this" as if it were a failure.
     * A row that was previously notified or cancelled is reset to pending, so
     * a postponed sale re-notifies the people who were already told once.
     *
     * @return array|WP_Error array{ status: 'added'|'already', id: int }
     */
    public static function join( $event_id, $email, $name = '', $ip = '' ) {
        global $wpdb;

        $event_id = (int) $event_id;
        // Lowercased on the way in: the UNIQUE index already dedupes
        // case-insensitively under the default collation, so storing mixed
        // case would only make exports and log lines inconsistent.
        $email    = strtolower( sanitize_email( (string) $email ) );
        $name     = sanitize_text_field( (string) $name );

        if ( $event_id <= 0 ) {
            return new WP_Error( 'invalid_event', __( 'Evento no válido.', 'kiwi-events' ), array( 'status' => 400 ) );
        }
        if ( $email === '' || ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', __( 'Escribe un correo electrónico válido.', 'kiwi-events' ), array( 'status' => 400 ) );
        }
        if ( mb_strlen( $name ) > 120 ) {
            $name = mb_substr( $name, 0, 120 );
        }

        $table   = self::table();
        $now     = current_time( 'mysql' );
        $ip_hash = $ip !== '' ? hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) : '';

        // ON DUPLICATE KEY UPDATE against the (event_id, email) unique index:
        // one atomic statement, so two concurrent submissions from the same
        // person can never create two rows.
        $sql = $wpdb->prepare(
            "INSERT INTO {$table} ( event_id, email, name, status, ip_hash, created_at )
             VALUES ( %d, %s, %s, %s, %s, %s )
             ON DUPLICATE KEY UPDATE
                name        = IF( VALUES(name) = '', name, VALUES(name) ),
                status      = %s,
                notified_at = NULL",
            $event_id,
            $email,
            $name,
            self::STATUS_PENDING,
            $ip_hash,
            $now,
            self::STATUS_PENDING
        );

        $suppress = $wpdb->suppress_errors( true );
        $result   = $wpdb->query( $sql );
        $wpdb->suppress_errors( $suppress );

        if ( $result === false ) {
            return new WP_Error( 'db_error', __( 'No pudimos guardarte en la lista. Intenta de nuevo.', 'kiwi-events' ), array( 'status' => 500 ) );
        }

        // MySQL returns 1 for a fresh insert and 2 for an updated duplicate
        // (0 when the duplicate row already matched exactly).
        return array(
            'status' => ( (int) $result === 1 ) ? 'added' : 'already',
            'id'     => (int) $wpdb->insert_id,
        );
    }

    /**
     * Atomically claim one pending row for notification. Returns true only for
     * the caller that flipped it — concurrent sweeps can never double-send.
     */
    public static function claim( $row_id ) {
        global $wpdb;
        $table = self::table();
        $rows  = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = %s, notified_at = %s WHERE id = %d AND status = %s",
            self::STATUS_NOTIFIED,
            current_time( 'mysql' ),
            (int) $row_id,
            self::STATUS_PENDING
        ) );
        return (int) $rows === 1;
    }

    /**
     * Hand a claimed row back to the pending pool.
     *
     * Used when the mail never actually got queued: the claim already burned
     * the row, and the sweep only ever re-reads pending rows, so without this
     * a transient queue failure would silently drop somebody's notification
     * forever.
     */
    public static function release_claim( $row_id ) {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = %s, notified_at = NULL WHERE id = %d AND status = %s",
            self::STATUS_PENDING,
            (int) $row_id,
            self::STATUS_NOTIFIED
        ) ) === 1;
    }

    /** Park every pending row for an event (deleted / cancelled events). */
    public static function cancel_pending_for_event( $event_id ) {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = %s WHERE event_id = %d AND status = %s",
            self::STATUS_CANCELLED,
            (int) $event_id,
            self::STATUS_PENDING
        ) );
    }

    /* ─────────────────────────────────────────────────────────────────────
     * READ
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Distinct events that still have someone waiting, starting just after
     * $after so the caller can walk the whole set across ticks.
     *
     * The sweep can only afford to look at a bounded number of events per
     * run (each one costs a post + meta read), and most of them are events
     * that are still counting down. Without the cursor, a site with more
     * pre-sale events than the limit would re-scan the same low-id events
     * forever and never reach the tail.
     */
    public static function pending_event_ids( $limit = 100, $after = 0 ) {
        global $wpdb;
        $table = self::table();
        $ids   = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT event_id FROM {$table}
             WHERE status = %s AND event_id > %d
             ORDER BY event_id ASC LIMIT %d",
            self::STATUS_PENDING,
            (int) $after,
            (int) $limit
        ) );
        return array_map( 'intval', (array) $ids );
    }

    /** Pending rows for one event, oldest first. */
    public static function get_pending( $event_id, $limit = self::RELEASE_BATCH ) {
        global $wpdb;
        $table = self::table();
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, event_id, email, name FROM {$table}
             WHERE event_id = %d AND status = %s
             ORDER BY id ASC LIMIT %d",
            (int) $event_id,
            self::STATUS_PENDING,
            (int) $limit
        ) );
    }

    /** Row count for one event, optionally filtered by status. */
    public static function count_for_event( $event_id, $status = self::STATUS_PENDING ) {
        global $wpdb;
        $table = self::table();
        if ( $status === '' ) {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE event_id = %d",
                (int) $event_id
            ) );
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status = %s",
            (int) $event_id,
            $status
        ) );
    }

    /** True when this email is already on the event's list (any status). */
    public static function has_email( $event_id, $email ) {
        global $wpdb;
        $table = self::table();
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE event_id = %d AND email = %s LIMIT 1",
            (int) $event_id,
            strtolower( sanitize_email( (string) $email ) )
        ) );
    }

    /* ─────────────────────────────────────────────────────────────────────
     * ADMIN LISTING
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Filter shape (all optional): event_id, status, search, limit, offset.
     * Joins wp_posts so the listing can show the event title in one query.
     */
    private static function build_where( array $args ) {
        global $wpdb;
        $where  = array( '1=1' );
        $params = array();

        if ( ! empty( $args['event_id'] ) ) {
            $where[]  = 'w.event_id = %d';
            $params[] = (int) $args['event_id'];
        }
        if ( ! empty( $args['status'] ) && in_array( $args['status'], self::ALL_STATUSES, true ) ) {
            $where[]  = 'w.status = %s';
            $params[] = $args['status'];
        }
        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
            $where[]  = '( w.email LIKE %s OR w.name LIKE %s )';
            $params[] = $like;
            $params[] = $like;
        }
        return array( implode( ' AND ', $where ), $params );
    }

    public static function get_all( array $args = array() ) {
        global $wpdb;
        $table  = self::table();
        $limit  = isset( $args['limit'] )  ? max( 1, (int) $args['limit'] )  : 25;
        $offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

        list( $where, $params ) = self::build_where( $args );

        $sql = "SELECT w.*, p.post_title AS event_title
                FROM {$table} w
                LEFT JOIN {$wpdb->posts} p ON p.ID = w.event_id
                WHERE {$where}
                ORDER BY w.created_at DESC, w.id DESC
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    public static function count_all( array $args = array() ) {
        global $wpdb;
        $table = self::table();
        list( $where, $params ) = self::build_where( $args );
        $sql = "SELECT COUNT(*) FROM {$table} w WHERE {$where}";
        return (int) ( $params
            ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) )
            : $wpdb->get_var( $sql ) );
    }

    /** Totals per status for the admin stats strip. */
    public static function stats( array $args = array() ) {
        global $wpdb;
        $table = self::table();
        $scoped = array( 'event_id' => $args['event_id'] ?? 0, 'search' => $args['search'] ?? '' );
        list( $where, $params ) = self::build_where( $scoped );

        $sql  = "SELECT w.status, COUNT(*) AS c FROM {$table} w WHERE {$where} GROUP BY w.status";
        $rows = (array) ( $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ) );

        $out = array( 'total' => 0 );
        foreach ( self::ALL_STATUSES as $s ) {
            $out[ $s ] = 0;
        }
        foreach ( $rows as $r ) {
            $status = (string) $r->status;
            $count  = (int) $r->c;
            if ( isset( $out[ $status ] ) ) {
                $out[ $status ] = $count;
            }
            $out['total'] += $count;
        }
        return $out;
    }
}
