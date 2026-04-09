<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce integration for paid tickets
 */
class KE_WooCommerce {

    public function __construct() {
        // Force WooCommerce to load cart during REST API requests (WC skips cart on REST by default)
        add_action( 'woocommerce_init', function() {
            if ( function_exists( 'wc_load_cart' ) && WC() && ! WC()->cart ) {
                wc_load_cart();
            }
        });
    }

    public function init() {
        // Generate tickets on payment completion — hooks cover all gateway flows:
        // - payment_complete fires for most gateways (Stripe, PayPal, etc.)
        // - status_processing covers async gateways that land on "processing" first
        // - status_completed covers manual order completion in admin
        add_action( 'woocommerce_payment_complete',          array( $this, 'handle_order_completed' ) );
        add_action( 'woocommerce_order_status_processing',   array( $this, 'handle_order_completed' ) );
        add_action( 'woocommerce_order_status_completed',    array( $this, 'handle_order_completed' ) );
        add_action( 'woocommerce_order_status_refunded',     array( $this, 'handle_order_refunded' ) );

        // Cart validation — enforce ticket limits
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 5 );

        // Display ticket info in cart/checkout
        add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );

        // Save custom data to order item meta
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );

        // Show ticket QR codes on the WooCommerce thank-you page
        add_action( 'woocommerce_thankyou', array( $this, 'render_thankyou_tickets' ), 5 );
    }

    /**
     * Add ticket to WooCommerce cart
     *
     * @param int    $event_id       Event post ID
     * @param int    $ticket_type_id Ticket type ID
     * @param int    $quantity       Number of tickets
     * @param string $attendee_name  Buyer name
     * @param string $attendee_email Buyer email
     * @return bool|WP_Error
     */
    public function add_to_cart( $event_id, $ticket_type_id, $quantity, $attendee_name, $attendee_email ) {
        // Guard 1: WooCommerce must be active
        if ( ! function_exists( 'WC' ) || ! WC() ) {
            return new WP_Error( 'wc_not_available', 'WooCommerce is not available.', array( 'status' => 500 ) );
        }

        // Guard 2: Cart must be initialized
        if ( ! WC()->cart ) {
            if ( function_exists( 'wc_load_cart' ) ) {
                wc_load_cart();
            }
        }

        // Guard 3: Check cart is available after loading
        if ( ! WC()->cart ) {
            return new WP_Error( 'wc_cart_null', 'WooCommerce cart could not be initialized.', array( 'status' => 500 ) );
        }

        $ticket_types = new KE_Ticket_Types();
        $ticket_type  = $ticket_types->get( $ticket_type_id );

        if ( ! $ticket_type ) {
            return new WP_Error( 'invalid_ticket_type', 'Ticket type not found.' );
        }

        // Check availability
        $remaining = $ticket_types->get_remaining( $ticket_type_id );
        if ( $quantity > $remaining ) {
            return new WP_Error( 'sold_out', 'Not enough tickets available.' );
        }

        // Guard 4: Get or create the WC product for this ticket type
        $product_id = $this->get_or_create_product( $ticket_type, $event_id );

        if ( is_wp_error( $product_id ) ) {
            return $product_id;
        }

        if ( ! $product_id || ! wc_get_product( $product_id ) ) {
            return new WP_Error( 'product_not_found', 'Could not find or create WooCommerce product for this ticket.', array( 'status' => 500 ) );
        }

        // Add to cart with custom data
        $cart_item_data = array(
            'ke_event_id'       => $event_id,
            'ke_ticket_type_id' => $ticket_type_id,
            'ke_attendee_name'  => $attendee_name,
            'ke_attendee_email' => $attendee_email,
        );

        $cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, 0, array(), $cart_item_data );

        if ( ! $cart_item_key ) {
            return new WP_Error( 'cart_add_failed', 'Could not add ticket to cart. The product may be out of stock.', array( 'status' => 500 ) );
        }

        return true;
    }

    /**
     * Get or create a virtual WooCommerce product for a ticket type
     */
    private function get_or_create_product( $ticket_type, $event_id ) {
        // Check if product already exists
        $existing_product_id = get_post_meta( $event_id, '_ke_wc_product_' . $ticket_type->id, true );

        if ( $existing_product_id && get_post_status( $existing_product_id ) === 'publish' ) {
            // Update price if changed
            $product = wc_get_product( $existing_product_id );
            if ( $product && floatval( $product->get_price() ) !== floatval( $ticket_type->price ) ) {
                $product->set_price( $ticket_type->price );
                $product->set_regular_price( $ticket_type->price );
                $product->save();
            }
            return $existing_product_id;
        }

        // Create virtual product
        $event_title = get_the_title( $event_id );
        $product = new WC_Product_Simple();
        $product->set_name( $event_title . ' — ' . $ticket_type->name );
        $product->set_status( 'publish' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_price( $ticket_type->price );
        $product->set_regular_price( $ticket_type->price );
        $product->set_virtual( true );
        $product->set_sold_individually( false );
        $product->set_manage_stock( false );
        $product->set_description( 'Ticket for ' . $event_title );
        $product->set_short_description( $ticket_type->name . ' — ' . $event_title );

        $product_id = $product->save();

        if ( ! $product_id ) {
            return new WP_Error( 'product_creation_failed', 'Could not create WooCommerce product.' );
        }

        // Set the event's featured image as the product thumbnail
        $thumbnail_id = get_post_thumbnail_id( $event_id );
        if ( $thumbnail_id ) {
            set_post_thumbnail( $product_id, $thumbnail_id );
        }

        // Store the mapping
        update_post_meta( $event_id, '_ke_wc_product_' . $ticket_type->id, $product_id );
        update_post_meta( $product_id, '_ke_event_id', $event_id );
        update_post_meta( $product_id, '_ke_ticket_type_id', $ticket_type->id );

        return $product_id;
    }

    /**
     * Validate adding to cart — enforce ticket limits
     */
    public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $cart_item_data = array() ) {
        if ( empty( $cart_item_data['ke_event_id'] ) ) {
            return $passed;
        }

        $orders = new KE_Orders();
        $can = $orders->can_purchase(
            $cart_item_data['ke_event_id'],
            $cart_item_data['ke_attendee_email'] ?? '',
            $quantity
        );

        if ( is_wp_error( $can ) ) {
            wc_add_notice( $can->get_error_message(), 'error' );
            return false;
        }

        return $passed;
    }

    /**
     * Display event/ticket info in cart
     */
    public function display_cart_item_data( $item_data, $cart_item_data ) {
        if ( ! empty( $cart_item_data['ke_event_id'] ) ) {
            $event_id = $cart_item_data['ke_event_id'];
            $item_data[] = array(
                'key'   => 'Event',
                'value' => get_the_title( $event_id ),
            );
            $date = get_post_meta( $event_id, '_ke_event_date_start', true );
            if ( $date ) {
                $item_data[] = array(
                    'key'   => 'Date',
                    'value' => date( 'l, F j · g:i A', strtotime( $date ) ),
                );
            }
        }
        if ( ! empty( $cart_item_data['ke_attendee_name'] ) ) {
            $item_data[] = array(
                'key'   => 'Attendee',
                'value' => $cart_item_data['ke_attendee_name'],
            );
        }
        return $item_data;
    }

    /**
     * Save ticket metadata to WC order item
     */
    public function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
        $ke_fields = array( 'ke_event_id', 'ke_ticket_type_id', 'ke_attendee_name', 'ke_attendee_email' );
        foreach ( $ke_fields as $field ) {
            if ( ! empty( $values[ $field ] ) ) {
                $item->add_meta_data( $field, $values[ $field ], true );
            }
        }
    }

    /**
     * Handle completed WooCommerce order — generate tickets
     */
    public function handle_order_completed( $wc_order_id ) {
        $wc_order = wc_get_order( $wc_order_id );

        if ( ! $wc_order ) {
            return;
        }

        // Check if we already processed this order
        if ( $wc_order->get_meta( '_ke_tickets_generated' ) ) {
            return;
        }

        $orders_handler  = new KE_Orders();
        $tickets_handler = new KE_Tickets();
        $email_handler   = new KE_Email();

        $all_ticket_codes = array();

        foreach ( $wc_order->get_items() as $item ) {
            $event_id       = $item->get_meta( 'ke_event_id' );
            $ticket_type_id = $item->get_meta( 'ke_ticket_type_id' );
            $attendee_name  = $item->get_meta( 'ke_attendee_name' );
            $attendee_email = $item->get_meta( 'ke_attendee_email' );

            if ( ! $event_id || ! $ticket_type_id ) {
                continue;
            }

            $quantity        = $item->get_quantity();
            $buyer_name      = $attendee_name  ?: trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() );
            $buyer_email     = $attendee_email ?: $wc_order->get_billing_email();

            // Create KE order
            $order_result = $orders_handler->create( array(
                'event_id'        => $event_id,
                'user_id'         => $wc_order->get_user_id(),
                'buyer_name'      => $buyer_name,
                'buyer_email'     => $buyer_email,
                'total_amount'    => $item->get_total(),
                'ticket_quantity' => $quantity,
                'payment_method'  => 'woocommerce',
                'payment_status'  => 'completed',
                'wc_order_id'     => $wc_order_id,
            ) );

            if ( is_wp_error( $order_result ) ) {
                continue;
            }

            // Generate tickets
            $tickets_handler->generate(
                $order_result['order_id'],
                $event_id,
                $ticket_type_id,
                $quantity,
                $buyer_name,
                $buyer_email
            );

            // Send ticket email (non-fatal)
            try {
                $email_handler->send_ticket_email( $order_result['order_id'] );
            } catch ( \Throwable $e ) {
                error_log( 'KiwiEvents WC email error: ' . $e->getMessage() );
            }

            // Collect ticket codes to store on the WC order for the thank-you page
            $generated = $tickets_handler->get_by_order( $order_result['order_id'] );
            foreach ( $generated as $t ) {
                $all_ticket_codes[] = $t->ticket_code;
            }
        }

        // Store ticket codes and mark as processed
        $wc_order->update_meta_data( '_ke_tickets_generated', true );
        if ( ! empty( $all_ticket_codes ) ) {
            $wc_order->update_meta_data( '_ke_ticket_codes', $all_ticket_codes );
        }
        $wc_order->save();
    }

    /**
     * Render ticket QR codes on the WooCommerce thank-you page
     */
    public function render_thankyou_tickets( $order_id ) {
        $order        = wc_get_order( $order_id );
        $ticket_codes = $order ? $order->get_meta( '_ke_ticket_codes' ) : array();

        if ( empty( $ticket_codes ) ) {
            return;
        }

        $ui_settings  = get_option( 'ke_ui_settings', array() );
        $accent       = ! empty( $ui_settings['accent_color'] )
                      ? sanitize_hex_color( $ui_settings['accent_color'] )
                      : '#6366f1';

        echo '<div style="margin:32px 0;font-family:\'Inter\',Arial,sans-serif;">';
        echo '<h2 style="font-size:22px;font-weight:800;color:#09090b;margin-bottom:16px;">🎟️ Your Tickets</h2>';

        foreach ( $ticket_codes as $code ) {
            $short      = '#' . strtoupper( substr( $code, 0, 8 ) );
            $qr_url     = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&format=png&ecc=H&data=' . urlencode( $code );
            $ticket_url = esc_url( home_url( '/ticket/' . $code ) );

            echo '<div style="background:#f8f8ff;border:1.5px solid #e0e0ff;border-radius:16px;'
               . 'padding:24px;margin-bottom:16px;text-align:center;">';
            echo '<img src="' . esc_url( $qr_url ) . '" width="160" height="160" '
               . 'style="border-radius:10px;display:block;margin:0 auto 12px;border:1px solid #e4e4e7;">';
            echo '<div style="font-weight:700;font-size:16px;color:#09090b;margin-bottom:4px;">'
               . esc_html( $short ) . '</div>';
            echo '<a href="' . $ticket_url . '" '
               . 'style="display:inline-block;margin-top:12px;padding:12px 24px;background:' . esc_attr( $accent ) . ';'
               . 'color:#fff;border-radius:100px;text-decoration:none;font-weight:600;font-size:14px;">'
               . '⬇️ Download Ticket PDF</a>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Handle refunded WooCommerce order — cancel tickets
     */
    public function handle_order_refunded( $wc_order_id ) {
        global $wpdb;

        $orders_table  = $wpdb->prefix . 'ke_orders';
        $tickets_table = $wpdb->prefix . 'ke_tickets';

        // Find KE orders linked to this WC order
        $ke_orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$orders_table} WHERE wc_order_id = %d",
            $wc_order_id
        ) );

        $tickets_handler = new KE_Tickets();
        $orders_handler  = new KE_Orders();

        foreach ( $ke_orders as $ke_order ) {
            // Cancel all tickets
            $tickets = $wpdb->get_results( $wpdb->prepare(
                "SELECT id FROM {$tickets_table} WHERE order_id = %d AND status = 'valid'",
                $ke_order->id
            ) );

            foreach ( $tickets as $ticket ) {
                $tickets_handler->cancel( $ticket->id );
            }

            // Update order status
            $orders_handler->update_status( $ke_order->id, 'refunded' );
        }
    }
}
