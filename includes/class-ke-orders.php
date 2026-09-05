<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Order management — creation, guest checkout, ticket limit enforcement
 */
class KE_Orders {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ke_orders';
    }

    /**
     * Create a new order
     */
    public function create( $data ) {
        global $wpdb;

        // Generate unique order number
        $order_number = $this->generate_order_number();

        $result = $wpdb->insert(
            $this->table_name,
            array(
                'order_number'    => $order_number,
                'event_id'        => absint( $data['event_id'] ),
                'user_id'         => isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0,
                'buyer_name'      => sanitize_text_field( $data['buyer_name'] ),
                'buyer_email'     => sanitize_email( $data['buyer_email'] ),
                'total_amount'    => floatval( $data['total_amount'] ),
                'ticket_quantity' => absint( $data['ticket_quantity'] ),
                'payment_method'  => sanitize_text_field( $data['payment_method'] ?? 'free' ),
                'payment_status'  => sanitize_text_field( $data['payment_status'] ?? 'pending' ),
                'wc_order_id'     => isset( $data['wc_order_id'] ) ? absint( $data['wc_order_id'] ) : null,
            ),
            array( '%s', '%d', '%d', '%s', '%s', '%f', '%d', '%s', '%s', '%d' )
        );

        if ( $result === false ) {
            return new WP_Error( 'db_error', 'Could not create order.' );
        }

        return array(
            'order_id'     => $wpdb->insert_id,
            'order_number' => $order_number,
        );
    }

    /**
     * Get order by ID
     */
    public function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ) );
    }

    /**
     * Get order by order number
     */
    public function get_by_number( $order_number ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE order_number = %s",
            $order_number
        ) );
    }

    /**
     * Get orders by event
     */
    public function get_by_event( $event_id, $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'  => '',
            'limit'   => 50,
            'offset'  => 0,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        );
        $args = wp_parse_args( $args, $defaults );

        $where = "WHERE event_id = %d";
        $params = array( $event_id );

        if ( ! empty( $args['status'] ) ) {
            $where .= " AND payment_status = %s";
            $params[] = $args['status'];
        }

        $orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
        if ( ! $orderby ) {
            $orderby = 'created_at DESC';
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} {$where} ORDER BY {$orderby} LIMIT %d OFFSET %d",
            array_merge( $params, array( $args['limit'], $args['offset'] ) )
        );

        return $wpdb->get_results( $sql );
    }

    /**
     * Get all orders with optional filters
     */
    public function get_all( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'event_id' => 0,
            'status'   => '',
            'search'   => '',
            'limit'    => 50,
            'offset'   => 0,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        );
        $args = wp_parse_args( $args, $defaults );

        $where  = "WHERE 1=1";
        $params = array();

        if ( $args['event_id'] ) {
            $where .= " AND event_id = %d";
            $params[] = $args['event_id'];
        }

        if ( ! empty( $args['status'] ) ) {
            $where .= " AND payment_status = %s";
            $params[] = $args['status'];
        }

        if ( ! empty( $args['search'] ) ) {
            $where .= " AND (buyer_name LIKE %s OR buyer_email LIKE %s OR order_number LIKE %s)";
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
        if ( ! $orderby ) {
            $orderby = 'created_at DESC';
        }

        $params[] = $args['limit'];
        $params[] = $args['offset'];

        $sql = $wpdb->prepare(
            "SELECT * FROM {$this->table_name} {$where} ORDER BY {$orderby} LIMIT %d OFFSET %d",
            $params
        );

        return $wpdb->get_results( $sql );
    }

    /**
     * Count orders with optional filters
     */
    public function count( $args = array() ) {
        global $wpdb;

        $where  = "WHERE 1=1";
        $params = array();

        if ( ! empty( $args['event_id'] ) ) {
            $where .= " AND event_id = %d";
            $params[] = $args['event_id'];
        }

        if ( ! empty( $args['status'] ) ) {
            $where .= " AND payment_status = %s";
            $params[] = $args['status'];
        }

        if ( ! empty( $args['search'] ) ) {
            $where .= " AND (buyer_name LIKE %s OR buyer_email LIKE %s OR order_number LIKE %s)";
            $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
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
     * Update order status
     */
    public function update_status( $order_id, $status ) {
        global $wpdb;
        return $wpdb->update(
            $this->table_name,
            array( 'payment_status' => sanitize_text_field( $status ) ),
            array( 'id' => absint( $order_id ) ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Check how many tickets this email has for an event
     */
    public function get_ticket_count_for_email( $event_id, $email ) {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'ke_tickets';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tickets_table}
             WHERE event_id = %d AND attendee_email = %s AND status != 'cancelled'",
            $event_id, $email
        ) );
    }

    /**
     * Enforce the per-person ticket limit for an event.
     *
     * Thin wrapper kept for backward compatibility with existing callers. The
     * rule itself now lives in KE_Ticket_Limits so every enforcement layer
     * (add-to-cart, cart update, whole-cart re-check, Store API, checkout,
     * order-creation guard) evaluates it identically. This 'checkout' context
     * counts issued tickets for the email plus any cart lines for the event.
     *
     * @param int    $event_id
     * @param string $email
     * @param int    $requested_qty
     * @return true|WP_Error  WP_Error 'ticket_limit_exceeded' with a dynamic,
     *                        translatable Spanish message when the limit is hit.
     */
    public function can_purchase( $event_id, $email, $requested_qty ) {
        return KE_Ticket_Limits::can_user_take(
            (int) $event_id,
            $email,
            (int) $requested_qty,
            'checkout'
        );
    }

    /**
     * Get dashboard stats
     */
    public function get_stats( $event_id = 0 ) {
        global $wpdb;

        $where  = "WHERE payment_status = 'completed'";
        $params = array();

        if ( $event_id ) {
            $where .= " AND event_id = %d";
            $params[] = $event_id;
        }

        if ( empty( $params ) ) {
            $row = $wpdb->get_row(
                "SELECT COUNT(*) as total_orders, SUM(total_amount) as total_revenue, SUM(ticket_quantity) as total_tickets FROM {$this->table_name} {$where}"
            );
        } else {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT COUNT(*) as total_orders, SUM(total_amount) as total_revenue, SUM(ticket_quantity) as total_tickets FROM {$this->table_name} {$where}",
                $params
            ) );
        }

        return array(
            'total_orders'  => (int) ( $row->total_orders ?? 0 ),
            'total_revenue' => floatval( $row->total_revenue ?? 0 ),
            'total_tickets' => (int) ( $row->total_tickets ?? 0 ),
        );
    }

    /**
     * Get revenue over time (daily)
     */
    public function get_revenue_chart_data( $days = 30, $event_id = 0 ) {
        global $wpdb;

        $where  = "WHERE payment_status = 'completed' AND created_at >= %s";
        $params = array( date( 'Y-m-d', strtotime( "-{$days} days" ) ) );

        if ( $event_id ) {
            $where .= " AND event_id = %d";
            $params[] = $event_id;
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) as date, SUM(total_amount) as revenue, SUM(ticket_quantity) as tickets
             FROM {$this->table_name} {$where}
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            $params
        ) );
    }

    /**
     * Generate unique order number
     */
    private function generate_order_number() {
        return 'KE-' . strtoupper( wp_generate_password( 8, false, false ) );
    }

    /**
     * Get recent orders
     */
    public function get_recent( $limit = 10 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT o.*, p.post_title as event_name
             FROM {$this->table_name} o
             LEFT JOIN {$wpdb->posts} p ON o.event_id = p.ID
             ORDER BY o.created_at DESC
             LIMIT %d",
            $limit
        ) );
    }
}
