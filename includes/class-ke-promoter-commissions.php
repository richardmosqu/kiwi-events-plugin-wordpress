<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Promoter commission generator.
 *
 * Called at order completion (both free-checkout and WC payment-complete
 * paths) with the promoter slug captured during the session. Looks up
 * (event, promoter) in ke_event_promoters, snapshots the commission terms,
 * and writes one row per ticket into ke_promoter_commissions.
 *
 * Stateless — no boot needed. Phase 2/3 will add the email + refund hooks.
 */
class KE_Promoter_Commissions {

    private static function dbg( $msg ) {
        if ( defined( 'KE_PROMOTER_DEBUG' ) && KE_PROMOTER_DEBUG ) {
            error_log( '[KE-PROMO] ' . $msg );
        }
    }

    /**
     * Generate commission rows for a completed order.
     *
     * @param array $args {
     *   int    $event_id        Required.
     *   int    $order_id        KE order id (ke_orders.id) — required.
     *   int    $wc_order_id     WooCommerce order id, or 0 for free.
     *   array  $ticket_ids      Array of ke_tickets.id values — required.
     *   float  $ticket_base_price  Per-ticket base price (no fee, no tax) — required.
     *   string $promoter_slug   Captured slug from the session — required.
     *   string $buyer_name
     *   string $buyer_email
     * }
     *
     * @return int Number of commission rows written. 0 means "no
     *             attribution" (invalid/inactive promoter, not assigned to
     *             event, etc.) and is a normal success state.
     */
    public static function generate_for_order( array $args ) {
        global $wpdb;

        $event_id          = (int) ( $args['event_id'] ?? 0 );
        $order_id          = (int) ( $args['order_id'] ?? 0 );
        $wc_order_id       = (int) ( $args['wc_order_id'] ?? 0 );
        $ticket_ids        = isset( $args['ticket_ids'] ) && is_array( $args['ticket_ids'] ) ? $args['ticket_ids'] : array();
        $ticket_base_price = round( floatval( $args['ticket_base_price'] ?? 0 ), 2 );
        $promoter_slug     = sanitize_title( (string) ( $args['promoter_slug'] ?? '' ) );
        $buyer_name        = sanitize_text_field( (string) ( $args['buyer_name'] ?? '' ) );
        $buyer_email       = sanitize_email( (string) ( $args['buyer_email'] ?? '' ) );
        // 'session' (default), 'cookie', 'admin' (admin add-attendee), or
        // 'manual' (reconciliation tool). Recorded on each commission row.
        $attribution_method = in_array( ( $args['attribution_method'] ?? '' ), array( 'session', 'cookie', 'admin', 'manual' ), true )
                              ? $args['attribution_method'] : 'session';

        if ( ! $event_id || ! $order_id || empty( $ticket_ids ) || $promoter_slug === '' ) {
            self::dbg( sprintf(
                'generate_for_order: REJECT order=%d event=%d slug="%s" tickets=%d — required field missing',
                $order_id, $event_id, $promoter_slug, count( $ticket_ids )
            ) );
            return 0;
        }

        // 1. Resolve the promoter and ensure they're active.
        $promoter = KE_Promoter_Attribution::find_by_slug( $promoter_slug );
        if ( ! $promoter || $promoter->status !== 'active' ) {
            self::dbg( sprintf(
                'generate_for_order: REJECT order=%d slug=%s — promoter not found or inactive (status=%s)',
                $order_id, $promoter_slug, $promoter ? $promoter->status : '(missing)'
            ) );
            return 0;
        }

        // 2. Check the event has this promoter assigned (and grab commission terms).
        $ep_table = $wpdb->prefix . 'ke_event_promoters';
        $assignment = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $ep_table WHERE event_id = %d AND promoter_id = %d LIMIT 1",
            $event_id,
            (int) $promoter->id
        ) );
        if ( ! $assignment ) {
            self::dbg( sprintf(
                'generate_for_order: REJECT order=%d event=%d promoter_id=%d — not assigned to this event',
                $order_id, $event_id, (int) $promoter->id
            ) );
            return 0;
        }

        // 3. Self-attribution guard. Default ON per spec — can be relaxed by
        //    setting 'ke_promoter_prevent_self_attribution' to '0'. We compare
        //    against the linked WP user's email; orphaned promoters can't
        //    self-attribute by definition.
        $prevent_self = get_option( 'ke_promoter_prevent_self_attribution', '1' );
        if ( $prevent_self === '1' && $buyer_email !== '' && ! empty( $promoter->user_id ) ) {
            $promoter_user = get_userdata( (int) $promoter->user_id );
            if ( $promoter_user && strcasecmp( $buyer_email, (string) $promoter_user->user_email ) === 0 ) {
                return 0;
            }
        }

        // 4. Compute per-ticket commission amount.
        $type  = in_array( $assignment->commission_type, array( 'percentage', 'fixed' ), true )
                   ? $assignment->commission_type : 'percentage';
        $value = round( floatval( $assignment->commission_value ), 2 );

        if ( $type === 'percentage' ) {
            $per_ticket = round( $ticket_base_price * ( $value / 100 ), 2 );
        } else {
            $per_ticket = $value;
        }

        if ( $per_ticket <= 0 ) {
            // Zero-value commissions still get recorded so the organizer
            // sees who drove the sale — but we cap negative just in case.
            $per_ticket = max( 0, $per_ticket );
        }

        // 4b. Drop courtesy ticket ids — courtesy attendees occupy capacity
        //     but are not real sales, so promoters earn no commission on them.
        $ticket_ids = array_values( array_filter( array_map( 'intval', $ticket_ids ) ) );
        if ( $ticket_ids ) {
            $tickets_table = $wpdb->prefix . 'ke_tickets';
            $placeholders  = implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) );
            $real_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM $tickets_table
                  WHERE id IN ($placeholders) AND is_courtesy = 0",
                $ticket_ids
            ) );
            $ticket_ids = array_map( 'intval', (array) $real_ids );
            if ( empty( $ticket_ids ) ) {
                return 0;
            }
        }

        // 5. Write one row per ticket.
        $commissions_table = $wpdb->prefix . 'ke_promoter_commissions';
        $written = 0;

        foreach ( $ticket_ids as $ticket_id ) {
            $ticket_id = (int) $ticket_id;
            if ( ! $ticket_id ) continue;

            $ok = $wpdb->insert(
                $commissions_table,
                array(
                    'promoter_id'        => (int) $promoter->id,
                    'event_id'           => $event_id,
                    'order_id'           => $order_id,
                    'ticket_id'          => $ticket_id,
                    'wc_order_id'        => $wc_order_id ?: null,
                    'buyer_name'         => $buyer_name,
                    'buyer_email'        => $buyer_email,
                    'ticket_base_price'  => $ticket_base_price,
                    'commission_type'    => $type,
                    'commission_value'   => $value,
                    'commission_amount'  => $per_ticket,
                    'status'             => 'earned',
                    'attribution_method' => $attribution_method,
                    'created_at'         => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%f', '%s', '%f', '%f', '%s', '%s', '%s' )
            );

            if ( $ok ) {
                $written++;
                self::dbg( sprintf(
                    'generate_for_order: INSERT order=%d ticket=%d promoter_id=%d amount=%.2f method=%s',
                    $order_id, $ticket_id, (int) $promoter->id, $per_ticket, $attribution_method
                ) );
            } else {
                self::dbg( sprintf(
                    'generate_for_order: INSERT FAILED order=%d ticket=%d — %s',
                    $order_id, $ticket_id, $wpdb->last_error
                ) );
            }
        }

        // 6. Notify the promoter (one summary email per order).
        if ( $written > 0
             && get_option( 'ke_promoter_notify_on_earn', '1' ) === '1' ) {
            self::send_commission_earned_email( $promoter, array(
                'event_id'    => $event_id,
                'tickets'     => $written,
                'total'       => round( $per_ticket * $written, 2 ),
                'buyer_name'  => $buyer_name,
                'buyer_email' => $buyer_email,
            ) );
        }

        return $written;
    }

    /**
     * Send a "you earned a commission" email to a promoter.
     * One summary per order (not per ticket).
     */
    public static function send_commission_earned_email( $promoter, array $details ) {
        $who = KE_Promoter_Attribution::display_for( $promoter );
        if ( $who['email'] === '' ) return;

        $event_id   = (int) ( $details['event_id'] ?? 0 );
        $tickets    = (int) ( $details['tickets']  ?? 0 );
        $total      = (float) ( $details['total']  ?? 0 );
        $buyer_name = (string) ( $details['buyer_name']  ?? '' );

        $event_title = $event_id ? get_the_title( $event_id ) : '';
        if ( ! $event_title ) $event_title = 'an event';

        $site_name = get_bloginfo( 'name' );
        $amount    = '$' . number_format( $total, 2 );
        $portal    = class_exists( 'KE_Promoter_Portal' )
                     ? KE_Promoter_Portal::portal_url( $promoter->slug )
                     : home_url( '/promoter/' . rawurlencode( $promoter->slug ) . '/' );

        $subject = sprintf( '[%s] You earned %s in commission', $site_name, $amount );

        $lines = array(
            'Hi ' . $who['name'] . ',',
            '',
            'A buyer just purchased through your tracking link.',
            '',
            '  Event:        ' . $event_title,
            '  Tickets:      ' . $tickets,
            '  Buyer:        ' . ( $buyer_name !== '' ? $buyer_name : '(name not provided)' ),
            '  Your earnings: ' . $amount,
            '',
            'View your dashboard:',
            $portal,
            '',
            '— ' . $site_name,
        );

        wp_mail(
            $who['email'],
            $subject,
            implode( "\n", $lines ),
            array( 'Content-Type: text/plain; charset=UTF-8' )
        );
    }

    /**
     * Fetch commission rows for a given promoter, ordered newest first.
     */
    public static function list_for_promoter( $promoter_id, array $filters = array(), $limit = 50, $offset = 0 ) {
        global $wpdb;
        $promoter_id = (int) $promoter_id;
        $table = $wpdb->prefix . 'ke_promoter_commissions';

        $where  = array( 'promoter_id = %d' );
        $params = array( $promoter_id );

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = (string) $filters['status'];
        }
        if ( ! empty( $filters['event_id'] ) ) {
            $where[]  = 'event_id = %d';
            $params[] = (int) $filters['event_id'];
        }
        if ( ! empty( $filters['from'] ) ) {
            $where[]  = 'created_at >= %s';
            $params[] = (string) $filters['from'];
        }
        if ( ! empty( $filters['to'] ) ) {
            $where[]  = 'created_at <= %s';
            $params[] = (string) $filters['to'];
        }

        $sql = "SELECT * FROM $table WHERE " . implode( ' AND ', $where )
             . ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
        $params[] = (int) $limit;
        $params[] = (int) $offset;

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Count rows matching filters (used for pagination).
     */
    public static function count_for_promoter( $promoter_id, array $filters = array() ) {
        global $wpdb;
        $promoter_id = (int) $promoter_id;
        $table = $wpdb->prefix . 'ke_promoter_commissions';

        $where  = array( 'promoter_id = %d' );
        $params = array( $promoter_id );

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = (string) $filters['status'];
        }
        if ( ! empty( $filters['event_id'] ) ) {
            $where[]  = 'event_id = %d';
            $params[] = (int) $filters['event_id'];
        }
        if ( ! empty( $filters['from'] ) ) {
            $where[]  = 'created_at >= %s';
            $params[] = (string) $filters['from'];
        }
        if ( ! empty( $filters['to'] ) ) {
            $where[]  = 'created_at <= %s';
            $params[] = (string) $filters['to'];
        }

        $sql = "SELECT COUNT(*) FROM $table WHERE " . implode( ' AND ', $where );
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Apply a refund policy to all commission rows tied to a WC order.
     * Policy: 'keep' → status 'refunded_keep' (organizer still owes promoter);
     *         'void' → status 'voided'  (organizer cancels the commission).
     * Only rows currently in 'earned' are affected — 'paid' rows are out of
     * scope (the organizer already paid out, refund handling is offline).
     *
     * Returns the number of rows updated.
     */
    public static function apply_refund_to_wc_order( $wc_order_id, $policy = null ) {
        global $wpdb;
        $wc_order_id = (int) $wc_order_id;
        if ( ! $wc_order_id ) return 0;

        if ( $policy === null ) {
            $policy = get_option( 'ke_promoter_refund_policy', 'keep' );
        }
        $new_status = ( $policy === 'void' ) ? 'voided' : 'refunded_keep';

        $table = $wpdb->prefix . 'ke_promoter_commissions';
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE $table
                SET status = %s
              WHERE wc_order_id = %d AND status = 'earned'",
            $new_status,
            $wc_order_id
        ) );
    }

    /**
     * Bulk-mark commissions as paid. Only flips rows currently in 'earned'
     * or 'refunded_keep' (the two "owed" states); 'paid' and 'voided' rows
     * are skipped silently so re-runs are idempotent.
     *
     * Returns the number of rows updated.
     */
    public static function mark_paid( array $commission_ids, $promoter_id, $note = '' ) {
        global $wpdb;
        $promoter_id = (int) $promoter_id;
        $ids = array_values( array_filter( array_map( 'intval', $commission_ids ) ) );
        if ( ! $promoter_id || ! $ids ) return 0;

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $table = $wpdb->prefix . 'ke_promoter_commissions';

        $params = array_merge(
            array( current_time( 'mysql' ), sanitize_text_field( $note ), $promoter_id ),
            $ids
        );

        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE $table
                SET status   = 'paid',
                    paid_at  = %s,
                    paid_note = %s
              WHERE promoter_id = %d
                AND status IN ('earned','refunded_keep')
                AND id IN ($placeholders)",
            $params
        ) );
    }

    /**
     * Stream a CSV of commissions for one promoter directly to the browser.
     * Caller is responsible for nonce/auth; this helper just sends headers
     * and writes rows via php://output, then exit()s.
     */
    public static function stream_csv_for_promoter( $promoter, array $filters = array() ) {
        global $wpdb;
        $promoter_id = (int) ( is_object( $promoter ) ? $promoter->id : $promoter );
        if ( ! $promoter_id ) return;

        // Pull all matching rows in chunks to keep memory bounded.
        $table = $wpdb->prefix . 'ke_promoter_commissions';
        $where  = array( 'promoter_id = %d' );
        $params = array( $promoter_id );

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = (string) $filters['status'];
        }
        if ( ! empty( $filters['event_id'] ) ) {
            $where[]  = 'event_id = %d';
            $params[] = (int) $filters['event_id'];
        }
        if ( ! empty( $filters['from'] ) ) {
            $where[]  = 'created_at >= %s';
            $params[] = (string) $filters['from'];
        }
        if ( ! empty( $filters['to'] ) ) {
            $where[]  = 'created_at <= %s';
            $params[] = (string) $filters['to'];
        }
        $where_sql = implode( ' AND ', $where );

        $slug     = is_object( $promoter ) ? (string) $promoter->slug : (string) $promoter_id;
        $filename = 'commissions-' . sanitize_file_name( $slug ) . '-' . date( 'Ymd-His' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $out = fopen( 'php://output', 'w' );
        // UTF-8 BOM so Excel renders accented characters correctly.
        fwrite( $out, "\xEF\xBB\xBF" );

        fputcsv( $out, array(
            'Date', 'Event', 'Buyer name', 'Buyer email',
            'Base price', 'Commission type', 'Commission value',
            'Commission amount', 'Status', 'Paid at', 'Paid note',
            'KE order id', 'WC order id', 'Ticket id',
        ) );

        $batch_size = 500;
        $offset     = 0;
        while ( true ) {
            $sql = "SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
            $batch_params = array_merge( $params, array( $batch_size, $offset ) );
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, $batch_params ) );
            if ( empty( $rows ) ) break;

            foreach ( $rows as $r ) {
                $ev_title = get_the_title( (int) $r->event_id );
                fputcsv( $out, array(
                    $r->created_at,
                    $ev_title ?: '(deleted event)',
                    $r->buyer_name,
                    $r->buyer_email,
                    number_format( (float) $r->ticket_base_price, 2, '.', '' ),
                    $r->commission_type,
                    number_format( (float) $r->commission_value, 2, '.', '' ),
                    number_format( (float) $r->commission_amount, 2, '.', '' ),
                    $r->status,
                    ( $r->paid_at && $r->paid_at !== '0000-00-00 00:00:00' ) ? $r->paid_at : '',
                    (string) $r->paid_note,
                    (int) $r->order_id,
                    (int) $r->wc_order_id,
                    (int) $r->ticket_id,
                ) );
            }

            if ( count( $rows ) < $batch_size ) break;
            $offset += $batch_size;
        }

        fclose( $out );
    }

    /**
     * Stream a CSV of all commission rows for a single event. Mirrors
     * stream_csv_for_promoter() but scoped by event_id and joined with the
     * promoter name so the organizer can see who earned what at a glance.
     */
    public static function stream_csv_for_event( $event_id ) {
        global $wpdb;
        $event_id = (int) $event_id;
        if ( ! $event_id ) return;

        $c = $wpdb->prefix . 'ke_promoter_commissions';
        $p = $wpdb->prefix . 'ke_promoters';
        $u = $wpdb->users;

        $event_title = get_the_title( $event_id );
        $slug        = sanitize_file_name( $event_title ?: ( 'event-' . $event_id ) );
        $filename    = 'event-commissions-' . $slug . '-' . date( 'Ymd-His' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel.

        fputcsv( $out, array(
            'Date', 'Promoter', 'Promoter email', 'Buyer name', 'Buyer email',
            'Base price', 'Commission type', 'Commission value',
            'Commission amount', 'Status', 'Paid at', 'Paid note',
            'KE order id', 'WC order id', 'Ticket id',
        ) );

        $batch_size = 500;
        $offset     = 0;
        while ( true ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT c.*,
                        COALESCE(NULLIF(u.display_name, ''), u.user_login, p.slug) AS promoter_name,
                        COALESCE(u.user_email, '')                                  AS promoter_email
                   FROM $c c
              LEFT JOIN $p p ON p.id = c.promoter_id
              LEFT JOIN $u u ON u.ID = p.user_id
                  WHERE c.event_id = %d
               ORDER BY c.created_at DESC
                  LIMIT %d OFFSET %d",
                $event_id, $batch_size, $offset
            ) );
            if ( empty( $rows ) ) break;

            foreach ( $rows as $r ) {
                fputcsv( $out, array(
                    $r->created_at,
                    $r->promoter_name !== null && $r->promoter_name !== '' ? $r->promoter_name : '(unknown user)',
                    (string) $r->promoter_email,
                    (string) $r->buyer_name,
                    (string) $r->buyer_email,
                    number_format( (float) $r->ticket_base_price, 2, '.', '' ),
                    $r->commission_type,
                    number_format( (float) $r->commission_value, 2, '.', '' ),
                    number_format( (float) $r->commission_amount, 2, '.', '' ),
                    $r->status,
                    ( $r->paid_at && $r->paid_at !== '0000-00-00 00:00:00' ) ? $r->paid_at : '',
                    (string) $r->paid_note,
                    (int) $r->order_id,
                    (int) $r->wc_order_id,
                    (int) $r->ticket_id,
                ) );
            }

            if ( count( $rows ) < $batch_size ) break;
            $offset += $batch_size;
        }

        fclose( $out );
    }

    /**
     * Top promoters by commission amount in a given window.
     * $from / $to are 'Y-m-d H:i:s' strings or empty for unbounded.
     * Returns rows: { promoter_id, name, email, slug, tickets, owed, paid, total }
     */
    /**
     * Per-event aggregates joined with the assignment table — used by the
     * CHANGE 4 per-event promoters dashboard.
     *
     * Returns rows shaped like:
     *   { promoter_id, name, email, slug, status, commission_type, commission_value,
     *     tickets_sold, owed, paid, total }
     */
    public static function event_promoter_performance( $event_id ) {
        global $wpdb;
        $event_id = (int) $event_id;
        if ( ! $event_id ) return array();

        $c  = $wpdb->prefix . 'ke_promoter_commissions';
        $ep = $wpdb->prefix . 'ke_event_promoters';
        $p  = $wpdb->prefix . 'ke_promoters';

        $u = $wpdb->users;

        $sql = "SELECT p.id          AS promoter_id,
                       COALESCE(NULLIF(u.display_name, ''), u.user_login, '(unlinked)') AS name,
                       COALESCE(u.user_email, '') AS email,
                       p.slug, p.status, p.user_id,
                       ep.commission_type, ep.commission_value,
                       COALESCE(agg.tickets, 0) AS tickets_sold,
                       COALESCE(agg.owed,    0) AS owed,
                       COALESCE(agg.paid,    0) AS paid,
                       COALESCE(agg.total,   0) AS total
                  FROM $ep ep
                  JOIN $p  p  ON p.id = ep.promoter_id
             LEFT JOIN $u  u  ON u.ID = p.user_id
             LEFT JOIN (
                       SELECT promoter_id,
                              COUNT(*)                                                              AS tickets,
                              SUM(CASE WHEN status IN ('earned','refunded_keep') THEN commission_amount ELSE 0 END) AS owed,
                              SUM(CASE WHEN status = 'paid'                       THEN commission_amount ELSE 0 END) AS paid,
                              SUM(commission_amount)                                                AS total
                         FROM $c WHERE event_id = %d
                     GROUP BY promoter_id
                  ) agg ON agg.promoter_id = ep.promoter_id
                 WHERE ep.event_id = %d
              ORDER BY total DESC, name ASC";
        return $wpdb->get_results( $wpdb->prepare( $sql, $event_id, $event_id ) );
    }

    /**
     * Totals for one event across all its promoters.
     * Returns { tickets, owed, paid, total, active_promoters }.
     */
    public static function event_totals( $event_id ) {
        global $wpdb;
        $event_id = (int) $event_id;
        if ( ! $event_id ) return array( 'tickets' => 0, 'owed' => 0.0, 'paid' => 0.0, 'total' => 0.0, 'active_promoters' => 0 );

        $c  = $wpdb->prefix . 'ke_promoter_commissions';
        $ep = $wpdb->prefix . 'ke_event_promoters';
        $p  = $wpdb->prefix . 'ke_promoters';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS tickets,
                    COALESCE(SUM(CASE WHEN status IN ('earned','refunded_keep') THEN commission_amount ELSE 0 END), 0) AS owed,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END), 0) AS paid,
                    COALESCE(SUM(commission_amount), 0) AS total
               FROM $c WHERE event_id = %d",
            $event_id
        ) );

        $active = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $ep ep JOIN $p p ON p.id = ep.promoter_id
              WHERE ep.event_id = %d AND p.status = 'active'",
            $event_id
        ) );

        return array(
            'tickets'          => $row ? (int) $row->tickets : 0,
            'owed'             => $row ? (float) $row->owed  : 0.0,
            'paid'             => $row ? (float) $row->paid  : 0.0,
            'total'            => $row ? (float) $row->total : 0.0,
            'active_promoters' => $active,
        );
    }

    /**
     * Recent commission activity for one event, joined with promoter name.
     * Used by the recent-activity feed (30s poll).
     */
    public static function event_recent_activity( $event_id, $limit = 10 ) {
        global $wpdb;
        $event_id = (int) $event_id;
        if ( ! $event_id ) return array();

        $c = $wpdb->prefix . 'ke_promoter_commissions';
        $p = $wpdb->prefix . 'ke_promoters';
        $u = $wpdb->users;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id, c.created_at, c.commission_amount, c.status,
                    c.buyer_name, c.buyer_email, c.attribution_method,
                    COALESCE(NULLIF(u.display_name, ''), u.user_login, p.slug, '(unknown user)') AS promoter_name
               FROM $c c
          LEFT JOIN $p p ON p.id = c.promoter_id
          LEFT JOIN $u u ON u.ID = p.user_id
              WHERE c.event_id = %d
           ORDER BY c.created_at DESC
              LIMIT %d",
            $event_id, max( 1, (int) $limit )
        ) );
    }

    /**
     * Mark every still-owed commission row for one event as paid in one shot.
     * Returns the number of rows updated.
     */
    public static function mark_all_paid_for_event( $event_id, $note = '' ) {
        global $wpdb;
        $event_id = (int) $event_id;
        if ( ! $event_id ) return 0;
        $table = $wpdb->prefix . 'ke_promoter_commissions';
        $now   = current_time( 'mysql' );
        $note  = mb_substr( (string) $note, 0, 1000 );

        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE $table
                SET status = 'paid', paid_at = %s, paid_note = %s
              WHERE event_id = %d AND status IN ('earned','refunded_keep')",
            $now, $note, $event_id
        ) );
    }

    public static function top_promoters( $from = '', $to = '', $limit = 10 ) {
        global $wpdb;
        $c = $wpdb->prefix . 'ke_promoter_commissions';
        $p = $wpdb->prefix . 'ke_promoters';
        $u = $wpdb->users;

        $where  = array( '1=1' );
        $params = array();
        if ( $from !== '' ) {
            $where[]  = 'c.created_at >= %s';
            $params[] = $from;
        }
        if ( $to !== '' ) {
            $where[]  = 'c.created_at <= %s';
            $params[] = $to;
        }
        $where_sql = implode( ' AND ', $where );

        $sql = "SELECT c.promoter_id,
                       COALESCE(NULLIF(u.display_name, ''), u.user_login, p.slug, '(deleted promoter)') AS name,
                       COALESCE(u.user_email, '') AS email,
                       p.slug,
                       COUNT(*)                                                                         AS tickets,
                       COALESCE(SUM(c.commission_amount), 0)                                            AS total,
                       COALESCE(SUM(CASE WHEN c.status IN ('earned','refunded_keep') THEN c.commission_amount ELSE 0 END), 0) AS owed,
                       COALESCE(SUM(CASE WHEN c.status = 'paid' THEN c.commission_amount ELSE 0 END), 0) AS paid
                  FROM $c c
             LEFT JOIN $p p ON p.id   = c.promoter_id
             LEFT JOIN $u u ON u.ID   = p.user_id
                 WHERE $where_sql
              GROUP BY c.promoter_id, name, email, p.slug
              ORDER BY total DESC
                 LIMIT %d";
        $params[] = (int) $limit;
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Aggregate totals across all promoters in a window.
     * Returns: { tickets, total, owed, paid }
     */
    public static function totals_in_window( $from = '', $to = '' ) {
        global $wpdb;
        $c = $wpdb->prefix . 'ke_promoter_commissions';

        $where  = array( '1=1' );
        $params = array();
        if ( $from !== '' ) { $where[] = 'created_at >= %s'; $params[] = $from; }
        if ( $to   !== '' ) { $where[] = 'created_at <= %s'; $params[] = $to;   }
        $sql = "SELECT
                    COUNT(*)                                                                                                 AS tickets,
                    COALESCE(SUM(commission_amount), 0)                                                                       AS total,
                    COALESCE(SUM(CASE WHEN status IN ('earned','refunded_keep') THEN commission_amount ELSE 0 END), 0)        AS owed,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END), 0)                             AS paid
                  FROM $c WHERE " . implode( ' AND ', $where );
        $row = $params
            ? $wpdb->get_row( $wpdb->prepare( $sql, $params ) )
            : $wpdb->get_row( $sql );
        return array(
            'tickets' => $row ? (int) $row->tickets : 0,
            'total'   => $row ? (float) $row->total : 0.0,
            'owed'    => $row ? (float) $row->owed  : 0.0,
            'paid'    => $row ? (float) $row->paid  : 0.0,
        );
    }

    /**
     * Resolve a period key into [from, to, label]. Period keys:
     * this_month, last_month, last_90_days, all_time (default).
     */
    public static function resolve_period( $key ) {
        $tz  = wp_timezone();
        $now = new DateTimeImmutable( 'now', $tz );

        switch ( $key ) {
            case 'this_month':
                $from = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
                $to   = $now->modify( 'first day of next month' )->setTime( 0, 0, 0 );
                return array( $from->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ), 'This month' );

            case 'last_month':
                $from = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
                $to   = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
                return array( $from->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ), 'Last month' );

            case 'last_90_days':
                $from = $now->modify( '-90 days' );
                return array( $from->format( 'Y-m-d H:i:s' ), $now->format( 'Y-m-d H:i:s' ), 'Last 90 days' );

            case 'all_time':
            default:
                return array( '', '', 'All time' );
        }
    }

    /**
     * Find paid KE orders that have no commission row attached.
     *
     * Used by the admin Reconcile tool to surface candidates for retroactive
     * attribution. Excludes free orders (total_amount = 0) by default since
     * the volume there is high and the admin almost always cares about paid.
     *
     * @return array Rows: { id, order_number, event_id, event_title, buyer_name,
     *                       buyer_email, total_amount, created_at, wc_order_id }
     */
    public static function unattributed_orders( array $args = array() ) {
        global $wpdb;
        $orders_table = $wpdb->prefix . 'ke_orders';
        $c_table      = $wpdb->prefix . 'ke_promoter_commissions';

        $limit       = max( 1, min( 500, (int) ( $args['limit']       ?? 100 ) ) );
        $offset      = max( 0,           (int) ( $args['offset']      ?? 0   ) );
        $include_free= ! empty( $args['include_free'] );
        $event_id    = (int) ( $args['event_id'] ?? 0 );

        $where  = array( "o.payment_status = 'completed'" );
        $params = array();
        if ( ! $include_free ) {
            $where[] = 'o.total_amount > 0';
        }
        if ( $event_id ) {
            $where[]  = 'o.event_id = %d';
            $params[] = $event_id;
        }
        $where[] = "NOT EXISTS (SELECT 1 FROM $c_table c WHERE c.order_id = o.id)";

        $sql = "SELECT o.id, o.order_number, o.event_id, o.buyer_name,
                       o.buyer_email, o.total_amount, o.created_at,
                       o.wc_order_id
                  FROM $orders_table o
                 WHERE " . implode( ' AND ', $where ) . "
              ORDER BY o.created_at DESC
                 LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        // Attach event title — cheap because get_the_title() caches.
        foreach ( (array) $rows as $r ) {
            $r->event_title = $r->event_id ? get_the_title( (int) $r->event_id ) : '(no event)';
        }
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Manually attribute a single KE order to a promoter. Reads every ticket
     * on the order and writes commission rows at the (event,promoter)
     * assignment's current terms. Returns the number of rows written, or 0
     * if no work was done.
     *
     * Tagged with attribution_method='manual' so the dashboard can show
     * "manually attributed" badges separately from organic attribution.
     */
    public static function manually_attribute_order( $ke_order_id, $promoter_id ) {
        global $wpdb;
        $ke_order_id = (int) $ke_order_id;
        $promoter_id = (int) $promoter_id;
        if ( ! $ke_order_id || ! $promoter_id ) return 0;

        $orders_table  = $wpdb->prefix . 'ke_orders';
        $tickets_table = $wpdb->prefix . 'ke_tickets';

        $order = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $orders_table WHERE id = %d LIMIT 1", $ke_order_id
        ) );
        if ( ! $order ) return 0;

        $promoter = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ke_promoters WHERE id = %d LIMIT 1", $promoter_id
        ) );
        if ( ! $promoter ) return 0;

        $tickets = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, ticket_type_id FROM $tickets_table WHERE order_id = %d", $ke_order_id
        ) );
        if ( empty( $tickets ) ) return 0;

        // Group ticket ids by ticket_type so we can use the right base_price
        // (one ke_event_promoters assignment per event covers all types).
        $by_type = array();
        foreach ( $tickets as $t ) {
            $by_type[ (int) $t->ticket_type_id ][] = (int) $t->id;
        }

        $written = 0;
        foreach ( $by_type as $tt_id => $ticket_ids ) {
            $tt = $wpdb->get_row( $wpdb->prepare(
                "SELECT price FROM {$wpdb->prefix}ke_ticket_types WHERE id = %d", $tt_id
            ) );
            $base_price = $tt ? floatval( $tt->price ) : 0.0;

            $written += (int) self::generate_for_order( array(
                'event_id'           => (int) $order->event_id,
                'order_id'           => (int) $order->id,
                'wc_order_id'        => (int) $order->wc_order_id,
                'ticket_ids'         => $ticket_ids,
                'ticket_base_price'  => $base_price,
                'promoter_slug'      => $promoter->slug,
                'buyer_name'         => (string) $order->buyer_name,
                'buyer_email'        => (string) $order->buyer_email,
                'attribution_method' => 'manual',
            ) );
        }

        return $written;
    }

    /**
     * Summary totals for a promoter: lifetime tickets sold, earned, paid.
     * "Owed" = earned + refunded_keep, since both are organizer-owes-promoter.
     */
    public static function totals_for_promoter( $promoter_id ) {
        global $wpdb;
        $promoter_id = (int) $promoter_id;
        $table = $wpdb->prefix . 'ke_promoter_commissions';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS tickets,
                COALESCE(SUM(commission_amount), 0) AS total_amount,
                COALESCE(SUM(CASE WHEN status IN ('earned','refunded_keep') THEN commission_amount ELSE 0 END), 0) AS owed,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END), 0) AS paid
             FROM $table WHERE promoter_id = %d",
            $promoter_id
        ) );

        return array(
            'tickets' => $row ? (int) $row->tickets : 0,
            'total'   => $row ? floatval( $row->total_amount ) : 0.0,
            'owed'    => $row ? floatval( $row->owed ) : 0.0,
            'paid'    => $row ? floatval( $row->paid ) : 0.0,
        );
    }
}
