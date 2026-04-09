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
    public function generate( $order_id, $event_id, $ticket_type_id, $attendees ) {
        global $wpdb;

        $ticket_ids = array();
        $qr_generator = new KE_QR_Generator();
        $quantity = count( $attendees );

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

            $insert_row = array(
                'ticket_code'     => $ticket_code,
                'order_id'        => absint( $order_id ),
                'ticket_type_id'  => absint( $ticket_type_id ),
                'event_id'        => absint( $event_id ),
                'attendee_name'   => sanitize_text_field( $attendee_name ),
                'attendee_email'  => sanitize_email( $attendee_email ),
                'attendee_number' => $attendee_number,
                'status'          => 'valid',
                'qr_code_path'    => $qr_path,
            );
            $insert_format = array( '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' );

            if ( $cf_data !== null ) {
                $insert_row['custom_fields_data'] = $cf_data;
                $insert_format[]                  = '%s';
            }

            $result = $wpdb->insert( $this->table_name, $insert_row, $insert_format );

            if ( $result === false ) {
                return new WP_Error( 'db_error', 'Failed to create ticket.' );
            }

            $ticket_ids[] = $wpdb->insert_id;
        }

        // Increment sold count on ticket type
        $ticket_types = new KE_Ticket_Types();
        $ticket_types->increment_sold( $ticket_type_id, $quantity );

        return $ticket_ids;
    }

    /**
     * Get ticket by ID
     */
    public function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT t.*, tt.name as ticket_type_name, tt.price as ticket_price, p.post_title as event_name
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
            "SELECT t.*, tt.name as ticket_type_name, tt.price as ticket_price, p.post_title as event_name
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
            "SELECT t.*, tt.name as ticket_type_name, tt.price as ticket_price, p.post_title as event_name
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
            "SELECT t.*, tt.name as ticket_type_name, tt.price as ticket_price,
                    tt.description as ticket_type_description
             FROM {$this->table_name} t
             LEFT JOIN {$wpdb->prefix}ke_ticket_types tt ON t.ticket_type_id = tt.id
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
     * Cancel a ticket
     */
    public function cancel( $ticket_id ) {
        global $wpdb;

        $ticket = $this->get( $ticket_id );
        if ( ! $ticket ) {
            return new WP_Error( 'not_found', 'Ticket not found.' );
        }

        $wpdb->update(
            $this->table_name,
            array( 'status' => 'cancelled' ),
            array( 'id' => absint( $ticket_id ) ),
            array( '%s' ),
            array( '%d' )
        );

        // Decrement sold count
        $ticket_types = new KE_Ticket_Types();
        $ticket_types->decrement_sold( $ticket->ticket_type_id, 1 );

        return true;
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
