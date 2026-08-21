<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ticket generation, unique codes, status management
 */
class KE_Tickets {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ke_tickets';
    }

    /**
     * Generate tickets for an order
     *
     * @param int    $order_id       Order ID
     * @param int    $event_id       Event ID
     * @param int    $ticket_type_id Ticket Type ID
     * @param array  $attendees      Array of attendee objects (name, email)
     * @return array|WP_Error Array of ticket IDs or error
     */
    /**
     * SQL fragment every organizer-facing query must carry.
     *
     * "Ticket error" attendees are emergency tickets an administrator issues
     * to repair a botched sale: real and scannable, but never a sale. The
     * organizer must not see them in their dashboard, exports or head counts,
     * so each of those queries appends this. Kept as one constant so a new
     * organizer surface can grep for it instead of rediscovering the rule.
     *
     * @param string $alias Table alias used by the caller ('' for none).
     */
    public static function exclude_error_sql( $alias = 't' ) {
        $prefix = $alias !== '' ? $alias . '.' : '';
        return " AND {$prefix}is_error = 0";
    }

    /** True when the admin-only emergency ticket feature is switched on. */
    public static function error_tickets_enabled() {
        return get_option( 'ke_error_tickets_enabled', '0' ) === '1';
    }

    /**
     * True when the current user may issue one. Deliberately manage_options
     * and not manage_kiwi_events: staff and organizers can hold the latter,
     * and this ticket is invisible to the organizer by design — the person
     * issuing it has to be the site administrator.
     */
    public static function can_issue_error_tickets() {
        return self::error_tickets_enabled() && current_user_can( 'manage_options' );
    }

    public function generate( $order_id, $event_id, $ticket_type_id, $attendees ) {
        global $wpdb;

        $ticket_ids = array();
        $qr_generator = new KE_QR_Generator();
        $quantity       = count( $attendees );
        $sold_quantity  = 0;   // attendees that actually count as sold

        // Snapshot the ticket type name at sale time so the attendees list
        // survives later deletion/archival of the ticket_type row.
        $type_snapshot = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}ke_ticket_types WHERE id = %d",
            $ticket_type_id
        ) );

        // Get the current attendee number for this event for proper numbering
        $current_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE event_id = %d AND status != 'cancelled'",
            $event_id
        ) );

        for ( $i = 0; $i < $quantity; $i++ ) {
            $attendee = $attendees[$i];
            $attendee_name = isset($attendee['name']) ? $attendee['name'] : '';
            $attendee_email = isset($attendee['email']) ? $attendee['email'] : '';

            $ticket_code = $this->generate_unique_code();
            $attendee_number = $current_count + $i + 1;

            // Generate QR code
            $qr_result = $qr_generator->generate( $ticket_code );
            $qr_path = is_wp_error( $qr_result ) ? null : $qr_result;

            // Serialize custom field values for this attendee
            $cf_data = null;
            if ( ! empty( $attendee['custom_fields'] ) && is_array( $attendee['custom_fields'] ) ) {
                $cf_data = wp_json_encode( $attendee['custom_fields'] );
            }

            // Per-event extra fields (university, shirt size, etc.). The REST
            // checkout already validates + sanitizes these before calling us,
            // but we re-encode rather than trust whatever shape it passed in
            // so storage stays canonical: an associative array of id => string.
            $ef_data = null;
            if ( ! empty( $attendee['extra_fields'] ) && is_array( $attendee['extra_fields'] ) ) {
                $clean_ef = array();
                foreach ( $attendee['extra_fields'] as $fid => $val ) {
                    $clean_ef[ (string) $fid ] = is_scalar( $val ) ? (string) $val : '';
                }
                if ( ! empty( $clean_ef ) ) {
                    $ef_data = wp_json_encode( $clean_ef );
                }
            }

            // Courtesy tickets occupy capacity (counted in quantity_sold) but
            // contribute $0 to organizer net revenue. Flag is per-attendee so
            // a single admin "Add attendee" call can mix real + courtesy if
            // needed, even though the UI currently picks one mode at a time.
            $is_courtesy_row = ! empty( $attendee['is_courtesy'] ) ? 1 : 0;

            // Emergency "Ticket error" rows: a real ticket that repairs a
            // botched sale. It never counts as sold (see the increment below)
            // and never reaches an organizer-facing query.
            $is_error_row = ! empty( $attendee['is_error'] ) ? 1 : 0;
            if ( ! $is_error_row ) {
                $sold_quantity++;
            }

            $insert_row = array(
                'ticket_code'          => $ticket_code,
                'order_id'             => absint( $order_id ),
                'ticket_type_id'       => absint( $ticket_type_id ),
                'ticket_type_snapshot' => $type_snapshot ? (string) $type_snapshot : null,
                'event_id'             => absint( $event_id ),
                'attendee_name'        => sanitize_text_field( $attendee_name ),
                'attendee_email'       => sanitize_email( $attendee_email ),
                'attendee_number'      => $attendee_number,
                'status'               => 'valid',
                'is_courtesy'          => $is_courtesy_row,
                'is_error'             => $is_error_row,
                'qr_code_path'         => $qr_path,
            );
            $insert_format = array( '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s' );

            if ( $cf_data !== null ) {
                $insert_row['custom_fields_data'] = $cf_data;
                $insert_format[]                  = '%s';
            }
            if ( $ef_data !== null ) {
                $insert_row['extra_fields_data'] = $ef_data;
                $insert_format[]                 = '%s';
            }

            $result = $wpdb->insert( $this->table_name, $insert_row, $insert_format );

            if ( $result === false ) {
                return new WP_Error( 'db_error', 'Failed to create ticket.' );
            }

            $ticket_ids[] = $wpdb->insert_id;
        }

        // Increment sold count on ticket type — error tickets excluded on
        // purpose. The counter is what the organizer's dashboard reads, and an
        // emergency ticket is a repair, not a sale. Keeping it out here is
        // also what lets one be issued against a sold-out type without
        // inflating anyone's numbers.
        $ticket_types = new KE_Ticket_Types();
        if ( $sold_quantity > 0 ) {
            $ticket_types->increment_sold( $ticket_type_id, $sold_quantity );
        }

        // Real-time sales beacon — single transient bump that the organizer
        // dashboard polls (cheap, transient-only read, no DB on the GET).
        // Covers BOTH free tickets (called from REST checkout) and paid
        // tickets (called from KE_WooCommerce::on_payment_complete) because
        // both flows reach this method.
        // Nothing was sold if every row was an emergency ticket, so the
        // organizer's "new sale" beacon must stay quiet.
        $org_term_ids = $sold_quantity > 0
            ? wp_get_post_terms( $event_id, 'ke_organizer', array( 'fields' => 'ids' ) )
            : array();
        if ( ! is_wp_error( $org_term_ids ) ) {
            foreach ( $org_term_ids as $org_term_id ) {
                $org_term_id = (int) $org_term_id;
                if ( $org_term_id > 0 ) {
                    set_transient( 'ke_last_sale_' . $org_term_id, time(), DAY_IN_SECONDS );
                }
            }
        }

        return $ticket_ids;
    }

    /**
     * Get ticket by ID
     */
    public function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT t.*,
                    COALESCE(tt.name, t.ticket_type_snapshot) as ticket_type_name,
                    tt.price as ticket_price,
                    p.post_title as event_name
             FROM {$this->table_name} t
             LEFT JOIN {$wpdb->prefix}ke_ticket_types tt ON t.ticket_type_id = tt.id
             LEFT JOIN {$wpdb->posts} p ON t.event_id = p.ID
             WHERE t.id = %d",
            $id
        ) );
    }

    /**
     * Get ticket by code (for QR scanning)
     */
    public function get_by_code( $ticket_code ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT t.*,
                    COALESCE(tt.name, t.ticket_type_snapshot) as ticket_type_name,
                    tt.price as ticket_price,
                    p.post_title as event_name
             FROM {$this->table_name} t
             LEFT JOIN {$wpdb->prefix}ke_ticket_types tt ON t.ticket_type_id = tt.id
             LEFT JOIN {$wpdb->posts} p ON t.event_id = p.ID
             WHERE t.ticket_code = %s",
            $ticket_code
        ) );
    }

    /**
     * Get tickets by order
     */
    public function get_by_order( $order_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*,
                    COALESCE(tt.name, t.ticket_type_snapshot) as ticket_type_name,
                    tt.price as ticket_price,
                    p.post_title as event_name
             FROM {$this->table_name} t
             LEFT JOIN {$wpdb->prefix}ke_ticket_types tt ON t.ticket_type_id = tt.id
             LEFT JOIN {$wpdb->posts} p ON t.event_id = p.ID
             WHERE t.order_id = %d
             ORDER BY t.attendee_number ASC",
            $order_id
        ) );
    }

    /**
     * Get attendees for an event with filters
     */
    public function get_attendees( $event_id, $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'         => '',
            'ticket_type_id' => 0,
            'search'         => '',
            'is_courtesy'    => null, // null = no filter; '0'/'1' = filter
            'is_error'       => null, // null = no filter; '0' hides emergency tickets
            'limit'          => 50,
            'offset'         => 0,
            'orderby'        => 'attendee_number',
            'order'          => 'ASC',
        );
        $args = wp_parse_args( $args, $defaults );

        $where  = "WHERE t.event_id = %d";
        $params = array( $event_id );

        if ( ! empty( $args['status'] ) ) {
            $where .= " AND t.status = %s";
            $params[] = $args['status'];
        }

        if ( $args['ticket_type_id'] ) {
            $where .= " AND t.ticket_type_id = %d";
            $params[] = $args['ticket_type_id'];
        }

        if ( $args['is_error'] !== null && $args['is_error'] !== '' ) {
            $where   .= " AND t.is_error = %d";
            $params[] = (int) ( $args['is_error'] ? 1 : 0 );
        }
        if ( $args['is_courtesy'] !== null && $args['is_courtesy'] !== '' ) {
            $where   .= " AND t.is_courtesy = %d";
            $params[] = (int) ( $args['is_courtesy'] ? 1 : 0 );
        }

        if ( ! empty( $args['search'] ) ) {
            $where .= " AND (t.attendee_name LIKE %s OR t.attendee_email LIKE %s OR t.ticket_code LIKE %s)";
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $orderby = sanitize_sql_orderby( 't.' . $args['orderby'] . ' ' . $args['order'] );
        if ( ! $orderby ) {
            $orderby = 't.attendee_number ASC';
        }

        $params[] = $args['limit'];
        $params[] = $args['offset'];

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT t.*,
                    COALESCE(tt.name, t.ticket_type_snapshot) as ticket_type_name,
                    tt.price as ticket_price,
                    tt.description as ticket_type_description,
                    o.order_number as order_number, o.payment_method as payment_method,
                    o.payment_status as payment_status, o.total_amount as order_total
             FROM {$this->table_name} t
             LEFT JOIN {$wpdb->prefix}ke_ticket_types tt ON t.ticket_type_id = tt.id
             LEFT JOIN {$wpdb->prefix}ke_orders o ON t.order_id = o.id
             {$where}
             ORDER BY {$orderby}
             LIMIT %d OFFSET %d",
            $params
        ) );
    }

    /**
     * Count attendees for an event
     */
    public function count_attendees( $event_id, $args = array() ) {
        global $wpdb;

        $where  = "WHERE event_id = %d";
        $params = array( $event_id );

        if ( ! empty( $args['status'] ) ) {
            $where .= " AND status = %s";
            $params[] = $args['status'];
        }

        if ( ! empty( $args['ticket_type_id'] ) ) {
            $where .= " AND ticket_type_id = %d";
            $params[] = $args['ticket_type_id'];
        }

        if ( isset( $args['is_error'] ) && $args['is_error'] !== null && $args['is_error'] !== '' ) {
            $where   .= " AND is_error = %d";
            $params[] = (int) ( $args['is_error'] ? 1 : 0 );
        }
        if ( isset( $args['is_courtesy'] ) && $args['is_courtesy'] !== null && $args['is_courtesy'] !== '' ) {
            $where   .= " AND is_courtesy = %d";
            $params[] = (int) ( $args['is_courtesy'] ? 1 : 0 );
        }

        if ( ! empty( $args['search'] ) ) {
            $where .= " AND (attendee_name LIKE %s OR attendee_email LIKE %s OR ticket_code LIKE %s)";
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} {$where}",
            $params
        ) );
    }

    /**
     * Reconcile the two "how many tickets" numbers this plugin shows, for one
     * event. READ-ONLY: it explains the gap, it never rewrites a counter.
     *
     * The events list card reports SUM(ke_ticket_types.quantity_sold) over
     * non-archived types — a *counter*, moved by +N at sale and -N at
     * cancellation. The attendees page reports COUNT(*) of ke_tickets rows.
     * Those are different quantities and they legitimately disagree:
     *
     *   - a cancelled ticket decrements the counter but its row stays, so it
     *     is still counted here          → rows > counter
     *   - an emergency "Ticket error" row never increments the counter at all
     *                                    → rows > counter
     *   - tickets sold under a ticket type that was later archived or deleted
     *     are excluded from the counter but their rows remain
     *                                    → rows > counter
     *   - and genuine counter drift, e.g. an order whose payment hooks ran
     *     twice before the per-order lock existed
     *                                    → counter > rows
     *
     * Presenting either figure alone as "tickets" is what makes them look
     * like a bug. Callers render this breakdown so the difference is
     * attributable instead of mysterious.
     *
     * @param int $event_id
     * @return array
     */
    public function sold_reconciliation( $event_id ) {
        global $wpdb;

        $event_id = absint( $event_id );
        $types    = $wpdb->prefix . 'ke_ticket_types';

        $rows = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS rows_total,
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS rows_cancelled,
                COALESCE(SUM(CASE WHEN status = 'used'      THEN 1 ELSE 0 END), 0) AS rows_used,
                COALESCE(SUM(CASE WHEN is_courtesy = 1      THEN 1 ELSE 0 END), 0) AS rows_courtesy,
                COALESCE(SUM(CASE WHEN is_error = 1         THEN 1 ELSE 0 END), 0) AS rows_error,
                COALESCE(SUM(
                    CASE WHEN ticket_type_id NOT IN (
                        SELECT id FROM {$types}
                         WHERE event_id = %d AND (is_archived IS NULL OR is_archived = 0)
                    ) THEN 1 ELSE 0 END
                ), 0) AS rows_off_counter_type
             FROM {$this->table_name}
             WHERE event_id = %d",
            $event_id,
            $event_id
        ) );

        $counter = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(quantity_sold), 0) FROM {$types}
              WHERE event_id = %d AND (is_archived IS NULL OR is_archived = 0)",
            $event_id
        ) );

        $out = array(
            'counter'               => $counter,
            'rows_total'           => (int) ( $rows->rows_total            ?? 0 ),
            'rows_cancelled'       => (int) ( $rows->rows_cancelled        ?? 0 ),
            'rows_used'            => (int) ( $rows->rows_used             ?? 0 ),
            'rows_courtesy'        => (int) ( $rows->rows_courtesy         ?? 0 ),
            'rows_error'           => (int) ( $rows->rows_error            ?? 0 ),
            'rows_off_counter_type'=> (int) ( $rows->rows_off_counter_type ?? 0 ),
        );

        // Live = what will actually walk in: not cancelled, not an emergency
        // repair row. This is the number an organizer means by "attendees".
        $out['rows_live'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name}
              WHERE event_id = %d AND status != 'cancelled' AND is_error = 0",
            $event_id
        ) );

        // Whatever the known causes cannot account for is drift.
        //
        // Counted as a UNION, not a sum of the three buckets: a row can be
        // cancelled AND on an archived type at once, and it still explains
        // only one unit of the gap. Adding the buckets double-counts it and
        // reports phantom negative drift.
        $explained = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name}
              WHERE event_id = %d
                AND ( is_error = 1
                   OR status = 'cancelled'
                   OR ticket_type_id NOT IN (
                        SELECT id FROM {$types}
                         WHERE event_id = %d AND (is_archived IS NULL OR is_archived = 0)
                      ) )",
            $event_id,
            $event_id
        ) );

        $out['rows_explained'] = $explained;
        $out['gap']            = $out['rows_total'] - $out['counter'];
        $out['unexplained']    = $out['gap'] - $explained;

        return $out;
    }

    /**
     * Validate and check-in a ticket
     */
    public function validate_and_checkin( $ticket_code, $scanner_user_id = 0 ) {
        $ticket = $this->get_by_code( $ticket_code );

        if ( ! $ticket ) {
            return array(
                'status'  => 'invalid',
                'message' => 'Ticket not found.',
            );
        }

        if ( $ticket->status === 'cancelled' ) {
            return array(
                'status'  => 'invalid',
                'message' => 'This ticket has been cancelled.',
                'ticket'  => $ticket,
            );
        }

        if ( $ticket->status === 'used' ) {
            return array(
                'status'  => 'already_used',
                'message' => sprintf( 'Already checked in at %s', $ticket->checked_in_at ),
                'ticket'  => $ticket,
            );
        }

        // Check in the ticket
        global $wpdb;
        $now = current_time( 'mysql' );

        $wpdb->update(
            $this->table_name,
            array(
                'status'         => 'used',
                'checked_in_at'  => $now,
                'checked_in_by'  => absint( $scanner_user_id ),
            ),
            array( 'id' => $ticket->id ),
            array( '%s', '%s', '%d' ),
            array( '%d' )
        );

        $ticket->status = 'used';
        $ticket->checked_in_at = $now;

        return array(
            'status'  => 'valid',
            'message' => 'Ticket validated successfully!',
            'ticket'  => $ticket,
        );
    }

    /**
     * Cancel a ticket (soft delete).
     */
    public function cancel( $ticket_id ) {
        return $this->update_status( $ticket_id, 'cancelled' );
    }

    /**
     * Update a ticket's status (valid|unused|used|cancelled).
     *
     * Keeps quantity_sold on the ticket type in sync:
     *  - cancelled rows don't count as sold, so transitioning INTO cancelled
     *    decrements, and transitioning OUT of cancelled re-increments.
     *  - Switching to 'used' stamps checked_in_at; switching to 'valid'
     *    clears it so the row reflects real-world state.
     *
     * Accepts 'unused' from the UI as a synonym for 'valid' (pre-check-in).
     */
    public function update_status( $ticket_id, $new_status ) {
        global $wpdb;

        $ticket = $this->get( $ticket_id );
        if ( ! $ticket ) {
            return new WP_Error( 'not_found', 'Ticket not found.' );
        }

        if ( $new_status === 'unused' ) {
            $new_status = 'valid';
        }
        $allowed = array( 'valid', 'used', 'cancelled' );
        if ( ! in_array( $new_status, $allowed, true ) ) {
            return new WP_Error( 'invalid_status', 'Invalid status.' );
        }

        $old_status = $ticket->status;
        if ( $old_status === $new_status ) {
            return $this->get( $ticket_id );
        }

        $update = array( 'status' => $new_status );
        $format = array( '%s' );

        if ( $new_status === 'used' ) {
            $update['checked_in_at'] = current_time( 'mysql' );
            $update['checked_in_by'] = get_current_user_id();
            $format[] = '%s';
            $format[] = '%d';
        } elseif ( $old_status === 'used' ) {
            $update['checked_in_at'] = null;
            $update['checked_in_by'] = null;
            $format[] = '%s';
            $format[] = '%d';
        }

        $wpdb->update(
            $this->table_name,
            $update,
            array( 'id' => absint( $ticket_id ) ),
            $format,
            array( '%d' )
        );

        // An error ticket never incremented the counter, so cancelling or
        // restoring one must not move it either — otherwise the first
        // cancellation would silently hand a seat back that was never taken.
        $ticket_types = new KE_Ticket_Types();
        if ( empty( $ticket->is_error ) ) {
            if ( $new_status === 'cancelled' && $old_status !== 'cancelled' ) {
                $ticket_types->decrement_sold( $ticket->ticket_type_id, 1 );
            } elseif ( $old_status === 'cancelled' && $new_status !== 'cancelled' ) {
                $ticket_types->increment_sold( $ticket->ticket_type_id, 1 );
            }
        }

        return $this->get( $ticket_id );
    }

    /**
     * Permanently delete a ticket row. Decrements quantity_sold if the ticket
     * wasn't already cancelled (cancel already decremented).
     */
    public function hard_delete( $ticket_id ) {
        global $wpdb;

        $ticket = $this->get( $ticket_id );
        if ( ! $ticket ) {
            return new WP_Error( 'not_found', 'Ticket not found.' );
        }

        $result = $wpdb->delete(
            $this->table_name,
            array( 'id' => absint( $ticket_id ) ),
            array( '%d' )
        );
        if ( $result === false ) {
            return new WP_Error( 'db_error', 'Could not delete ticket.' );
        }

        // Same reasoning as update_status(): an error ticket was never
        // counted, so deleting it must not give a seat back.
        if ( $ticket->status !== 'cancelled' && empty( $ticket->is_error ) ) {
            $ticket_types = new KE_Ticket_Types();
            $ticket_types->decrement_sold( $ticket->ticket_type_id, 1 );
        }
        return true;
    }

    /**
     * Update attendee name/email. Ticket code and ticket_type are immutable.
     */
    public function update_attendee( $ticket_id, $fields ) {
        global $wpdb;

        $update = array();
        $format = array();
        if ( isset( $fields['attendee_name'] ) ) {
            $update['attendee_name'] = sanitize_text_field( $fields['attendee_name'] );
            $format[] = '%s';
        }
        if ( isset( $fields['attendee_email'] ) ) {
            $email = sanitize_email( $fields['attendee_email'] );
            if ( $email && ! is_email( $email ) ) {
                return new WP_Error( 'invalid_email', 'Invalid email address.' );
            }
            $update['attendee_email'] = $email;
            $format[] = '%s';
        }
        if ( empty( $update ) ) {
            return new WP_Error( 'no_data', 'Nothing to update.' );
        }

        $wpdb->update(
            $this->table_name,
            $update,
            array( 'id' => absint( $ticket_id ) ),
            $format,
            array( '%d' )
        );

        return $this->get( $ticket_id );
    }

    /**
     * Update PDF path for a ticket
     */
    public function update_pdf_path( $ticket_id, $path ) {
        global $wpdb;
        return $wpdb->update(
            $this->table_name,
            array( 'pdf_path' => $path ),
            array( 'id' => absint( $ticket_id ) ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Get check-in stats for an event
     */
    public function get_checkin_stats( $event_id ) {
        global $wpdb;
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE event_id = %d AND status != 'cancelled'",
            $event_id
        ) );
        $checked_in = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE event_id = %d AND status = 'used'",
            $event_id
        ) );

        return array(
            'total'      => $total,
            'checked_in' => $checked_in,
            'remaining'  => $total - $checked_in,
            'percentage' => $total > 0 ? round( ( $checked_in / $total ) * 100, 1 ) : 0,
        );
    }

    /**
     * Generate unique ticket code
     */
    private function generate_unique_code() {
        return hash( 'sha256', uniqid( 'ke_', true ) . wp_generate_password( 16, true, true ) . microtime() );
    }
}
