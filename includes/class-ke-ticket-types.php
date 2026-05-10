<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ticket Types CRUD operations
 */
class KE_Ticket_Types {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ke_ticket_types';
    }

    /**
     * Get all live ticket types for an event (archived types excluded so
     * the admin edit form doesn't resurrect rows the admin previously removed).
     */
    public function get_by_event( $event_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE event_id = %d
               AND (is_archived IS NULL OR is_archived = 0)
             ORDER BY price ASC",
            $event_id
        ) );
    }

    /**
     * Get a single ticket type by ID
     */
    public function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ) );
    }

    /**
     * Get all ticket types for an event. Sold-out filtering is handled in the template
     * by comparing quantity_sold vs quantity_total.
     */
    public function get_available( $event_id ) {
        global $wpdb;
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE event_id = %d
               AND (is_archived IS NULL OR is_archived = 0)
             ORDER BY price ASC, id ASC",
            $event_id
        ) );
        return $results ?: array();
    }

    /**
     * Create a new ticket type
     */
    public function create( $data ) {
        global $wpdb;

        $defaults = array(
            'event_id'       => 0,
            'name'           => '',
            'description'    => '',
            'ticket_type'    => 'free',
            'price'          => 0.00,
            'capacity_type'  => 'unlimited',
            'quantity_total' => 0,
            'quantity_sold'  => 0,
            'min_per_order'  => 1,
            'max_per_order'  => 10,
            'show_remaining' => 'yes',
            'status'         => 'active',
        );

        $data = wp_parse_args( $data, $defaults );

        if ( empty( $data['event_id'] ) || empty( $data['name'] ) ) {
            return new WP_Error( 'invalid_data', 'Event ID and name are required.' );
        }

        if ( $data['capacity_type'] === 'limited' && $data['quantity_total'] < 1 ) {
            return new WP_Error( 'invalid_quantity', 'Quantity must be at least 1 for limited capacity.' );
        }

        if ( $data['price'] < 0 ) {
            return new WP_Error( 'invalid_price', 'Price cannot be negative.' );
        }

        $insert_data = array(
            'event_id'       => absint( $data['event_id'] ),
            'name'           => sanitize_text_field( $data['name'] ),
            'description'    => sanitize_textarea_field( $data['description'] ),
            'ticket_type'    => sanitize_text_field( $data['ticket_type'] ),
            'price'          => floatval( $data['price'] ),
            'capacity_type'  => sanitize_text_field( $data['capacity_type'] ),
            'quantity_total' => absint( $data['quantity_total'] ),
            'quantity_sold'  => 0,
            'min_per_order'  => absint( $data['min_per_order'] ),
            'max_per_order'  => absint( $data['max_per_order'] ),
            'show_remaining' => sanitize_text_field( $data['show_remaining'] ),
            'status'         => sanitize_text_field( $data['status'] ),
        );
        $insert_format = array( '%d', '%s', '%s', '%s', '%f', '%s', '%d', '%d', '%d', '%d', '%s', '%s' );

        if ( isset( $data['custom_fields'] ) ) {
            $insert_data['custom_fields'] = $data['custom_fields'];
            $insert_format[]              = '%s';
        }

        $result = $wpdb->insert( $this->table_name, $insert_data, $insert_format );

        if ( $result === false ) {
            return new WP_Error( 'db_error', $wpdb->last_error ?: 'Could not create ticket type.' );
        }

        return $wpdb->insert_id;
    }

    /**
     * Update a ticket type
     */
    public function update( $id, $data ) {
        global $wpdb;

        $allowed = array(
            'name', 'description', 'ticket_type', 'price', 'capacity_type',
            'quantity_total', 'min_per_order', 'max_per_order',
            'show_remaining', 'status', 'custom_fields'
        );
        $update = array();
        $format = array();

        foreach ( $allowed as $field ) {
            if ( isset( $data[ $field ] ) ) {
                switch ( $field ) {
                    case 'price':
                        $update[ $field ] = floatval( $data[ $field ] );
                        $format[]         = '%f';
                        break;
                    case 'quantity_total':
                    case 'min_per_order':
                    case 'max_per_order':
                        $update[ $field ] = absint( $data[ $field ] );
                        $format[]         = '%d';
                        break;
                    case 'description':
                    case 'custom_fields':
                        $update[ $field ] = sanitize_textarea_field( $data[ $field ] );
                        $format[]         = '%s';
                        break;
                    default:
                        $update[ $field ] = sanitize_text_field( $data[ $field ] );
                        $format[]         = '%s';
                        break;
                }
            }
        }

        if ( empty( $update ) ) {
            return new WP_Error( 'no_data', 'No fields to update.' );
        }

        $result = $wpdb->update(
            $this->table_name,
            $update,
            array( 'id' => absint( $id ) ),
            $format,
            array( '%d' )
        );

        return $result !== false;
    }

    /**
     * Delete a ticket type
     */
    public function delete( $id ) {
        global $wpdb;
        return $wpdb->delete(
            $this->table_name,
            array( 'id' => absint( $id ) ),
            array( '%d' )
        );
    }

    /**
     * Increment sold count
     */
    public function increment_sold( $id, $quantity = 1 ) {
        global $wpdb;
        return $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_name} SET quantity_sold = quantity_sold + %d WHERE id = %d",
            absint( $quantity ), absint( $id )
        ) );
    }

    /**
     * Decrement sold count (for cancellations/refunds)
     */
    public function decrement_sold( $id, $quantity = 1 ) {
        global $wpdb;
        return $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_name} SET quantity_sold = GREATEST(0, quantity_sold - %d) WHERE id = %d",
            absint( $quantity ), absint( $id )
        ) );
    }

    /**
     * Check remaining availability
     */
    public function get_remaining( $id ) {
        $ticket_type = $this->get( $id );
        if ( ! $ticket_type ) {
            return 0;
        }
        if ( ( $ticket_type->capacity_type ?? 'limited' ) === 'unlimited' ) {
            return 999999;
        }
        return max( 0, $ticket_type->quantity_total - $ticket_type->quantity_sold );
    }
}
