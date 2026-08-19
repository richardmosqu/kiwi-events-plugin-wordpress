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
        // status = 'active' hides ticket types deactivated via the event
        // builder's per-row toggle. Inactive rows are kept in the DB so
        // historical tickets in wp_ke_tickets keep their FK reference.
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE event_id = %d
               AND (is_archived IS NULL OR is_archived = 0)
               AND status = 'active'
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

        // sale_end is nullable. Only include when a real value was supplied so
        // the column defaults to NULL otherwise.
        if ( array_key_exists( 'sale_end', $data ) ) {
            $sanitized = self::sanitize_sale_end( $data['sale_end'] );
            if ( $sanitized !== null ) {
                $insert_data['sale_end'] = $sanitized;
                $insert_format[]         = '%s';
            }
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

        // sale_end is handled outside the $allowed loop so we can explicitly
        // pass SQL NULL when the admin clears the field. array_key_exists
        // (not isset) is required because null is a meaningful "clear it" value
        // here, and isset() returns false for null.
        if ( array_key_exists( 'sale_end', $data ) ) {
            $sanitized = self::sanitize_sale_end( $data['sale_end'] );
            // wpdb interprets a literal null in the data array as SQL NULL
            // regardless of the corresponding format-array entry, so this
            // safely clears the column when the admin empties the input.
            $update['sale_end'] = $sanitized;
            $format[]           = '%s';
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

        // An edited capacity has to reach the WooCommerce product too, or the
        // seats it holds during checkout keep describing the old number.
        self::after_counter_change( $id );

        return $result !== false;
    }

    /**
     * Normalize a sale_end input into the DB's "Y-m-d H:i:s" wall-clock
     * format, interpreted in the site timezone. Accepts both the HTML
     * datetime-local format ("Y-m-d\TH:i" or "Y-m-d\TH:i:s") and the MySQL
     * datetime format. Returns null for empty / unparseable input so the
     * caller can write SQL NULL.
     *
     * Matches the wall-clock-string convention used by _ke_event_date_end
     * (post meta on the event), so comparisons across the two surfaces stay
     * consistent.
     */
    public static function sanitize_sale_end( $value ) {
        if ( $value === null ) return null;
        $value = trim( (string) $value );
        if ( $value === '' ) return null;

        $value = str_replace( 'T', ' ', $value );
        if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})(:\d{2})?$/', $value ) ) {
            return null;
        }
        if ( strlen( $value ) === 16 ) {
            $value .= ':00';
        }
        return $value;
    }

    /**
     * Format a stored sale_end (MySQL "Y-m-d H:i:s") for an HTML
     * <input type="datetime-local">. Returns empty string on null/empty/bad
     * input. Browser inputs only care about minutes — strip seconds.
     */
    public static function format_sale_end_for_input( $raw ) {
        $raw = trim( (string) ( $raw ?? '' ) );
        if ( $raw === '' || strpos( $raw, '0000-00-00' ) === 0 ) return '';
        $raw = str_replace( 'T', ' ', $raw );
        if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/', $raw, $m ) ) return '';
        return $m[1] . 'T' . $m[2];
    }

    /**
     * True when the ticket type's sale_end has already passed in the site
     * timezone. Mirrors KE_Shortcodes::event_is_expired's contract:
     *   - empty / 0000-00-00… / unparseable → treated as no cutoff (false)
     *   - cutoff > now → false (sales still open)
     *   - cutoff <= now → true (sales closed)
     *
     * Accepts either the raw datetime string or a ticket-type row object
     * (so callers don't have to dig the column out first).
     */
    public static function is_sales_closed( $ticket_type_or_raw ) {
        $raw = is_object( $ticket_type_or_raw )
            ? ( $ticket_type_or_raw->sale_end ?? '' )
            : (string) $ticket_type_or_raw;
        $raw = trim( (string) $raw );
        if ( $raw === '' || strpos( $raw, '0000-00-00' ) === 0 ) return false;
        $tz = wp_timezone();
        try {
            $end = new DateTimeImmutable( str_replace( 'T', ' ', $raw ), $tz );
        } catch ( Exception $e ) {
            return false;
        }
        $now = function_exists( 'current_datetime' )
            ? current_datetime()
            : new DateTimeImmutable( 'now', $tz );
        return $end <= $now;
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
        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_name} SET quantity_sold = quantity_sold + %d WHERE id = %d",
            absint( $quantity ), absint( $id )
        ) );
        self::after_counter_change( $id );
        return $result;
    }

    /**
     * Decrement sold count (for cancellations/refunds)
     */
    public function decrement_sold( $id, $quantity = 1 ) {
        global $wpdb;
        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table_name} SET quantity_sold = GREATEST(0, quantity_sold - %d) WHERE id = %d",
            absint( $quantity ), absint( $id )
        ) );
        self::after_counter_change( $id );
        return $result;
    }

    /**
     * Every move of quantity_sold or quantity_total has to be reflected in the
     * WooCommerce product's stock, because that stock — not this counter — is
     * what holds seats during checkout and refuses the buyer who arrives too
     * late. Kept here so no caller can change the capacity and forget.
     *
     * Also the one place that notices the counter passing the capacity. It
     * cannot refuse the sale (the money is already taken by the time tickets
     * are generated), but silence is what let 71 tickets exist against 50
     * seats without anyone finding out until afterwards.
     */
    public static function after_counter_change( $id ) {
        $id = absint( $id );
        if ( ! $id ) {
            return;
        }
        $handler = new self();
        $type    = $handler->get( $id );
        if ( ! $type ) {
            return;
        }

        if ( class_exists( 'KE_WooCommerce' ) ) {
            KE_WooCommerce::sync_product_stock( $type );
        }

        if ( ( $type->capacity_type ?? 'limited' ) !== 'unlimited'
            && (int) $type->quantity_sold > (int) $type->quantity_total ) {
            error_log( sprintf(
                'KiwiEvents OVERSOLD: ticket type %d ("%s") on event %d is at %d sold against a capacity of %d.',
                $id,
                (string) $type->name,
                (int) $type->event_id,
                (int) $type->quantity_sold,
                (int) $type->quantity_total
            ) );
            update_post_meta( (int) $type->event_id, '_ke_oversold_notice', array(
                'ticket_type_id' => $id,
                'name'           => (string) $type->name,
                'sold'           => (int) $type->quantity_sold,
                'capacity'       => (int) $type->quantity_total,
                'seen_at'        => current_time( 'mysql' ),
            ) );
        }
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
