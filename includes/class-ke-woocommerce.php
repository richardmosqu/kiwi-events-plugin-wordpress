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

        // Generate tickets on payment completion — hooks cover all gateway flows:
        // - payment_complete fires for most gateways (Stripe, PayPal, etc.)
        // - status_processing covers async gateways that land on "processing" first
        // - status_completed covers manual order completion in admin
        add_action( 'woocommerce_payment_complete',        array( $this, 'on_payment_complete' ) );
        add_action( 'woocommerce_order_status_processing', array( $this, 'on_payment_complete' ) );
        add_action( 'woocommerce_order_status_completed',  array( $this, 'on_payment_complete' ) );
        add_action( 'woocommerce_order_status_refunded',   array( $this, 'handle_order_refunded' ) );

        // Cart validation — enforce ticket limits
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 5 );

        // Second-pass guard: if a ticket type's sale_end cutoff passes while
        // the buyer is sitting on the checkout page, block the order before
        // payment is taken. WooCommerce calls this on cart render and at the
        // start of checkout submission, so a stale cart can't sneak through.
        add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_cutoffs' ) );

        // Display ticket info in cart/checkout
        add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );

        // Save custom data to order item meta
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );

        // Show ticket QR codes on the WooCommerce thank-you page
        add_action( 'woocommerce_thankyou', array( $this, 'show_tickets_on_thankyou' ), 5 );

        // Service fee — registered once in the constructor so it runs on every
        // cart calculation (add-to-cart, cart view, checkout render, gateway
        // init, order creation, admin order edits, blocks cart/checkout).
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_service_fee' ), 10, 1 );

        // Safeguard: explicitly copy cart fees onto the order at creation
        // so they survive redirect-gateway flows (Yappy, PayPal) even if the
        // cart is cleared before the order is marked paid.
        add_action( 'woocommerce_checkout_create_order', array( $this, 'persist_fee_on_order' ), 10, 2 );

        // URL-only promoter attribution: parse the checkout request's referrer
        // for ?promo= at order-processed time and freeze the slug onto the
        // order. on_payment_complete() reads this meta to drive commissions.
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture_promoter_from_referrer' ), 10, 3 );
    }

    /**
     * Capture the promoter slug from HTTP_REFERER at order-processed time and
     * freeze it onto the order's `_ke_promoter_slug` meta. URL-only model:
     *   - Referrer absent / no ?promo= → no attribution (orphan order)
     *   - Slug present but promoter inactive → no attribution
     *   - Slug present but promoter not assigned to this event → no attribution
     *
     * Limitation accepted by the user: HTTP_REFERER can be empty when the
     * browser strips it (HTTPS→HTTP, strict referrer-policy, some payment-
     * gateway redirects). In those cases commissions are lost — there is no
     * cookie/session fallback. Run the badge as a smoke test: if the badge
     * isn't visible at the moment of "Place Order", the commission won't fire.
     */
    public function capture_promoter_from_referrer( $order_id, $posted_data, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) return;
        }

        if ( ! class_exists( 'KE_Promoter_Attribution' ) ) return;

        // Event id is needed for the assignment check. Pull from the first
        // KE line item — multi-event carts are rare and the visitor's URL
        // (the referrer) corresponds to one event at a time.
        $event_id = 0;
        foreach ( $order->get_items() as $item ) {
            $eid = (int) $item->get_meta( '_ke_event_id' );
            if ( $eid > 0 ) { $event_id = $eid; break; }
        }
        if ( $event_id <= 0 ) return;

        $referrer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
        $promoter = KE_Promoter_Attribution::resolve_from_referrer( $referrer, $event_id );

        if ( defined( 'KE_PROMOTER_DEBUG' ) && KE_PROMOTER_DEBUG ) {
            error_log( sprintf(
                '[KE-PROMO] order_processed: wc_order=%d event=%d referrer=%s → %s',
                (int) $order_id, $event_id,
                $referrer !== '' ? $referrer : '(empty)',
                $promoter ? ('slug=' . (string) $promoter->slug) : 'no-attribution'
            ) );
        }

        if ( ! $promoter ) return;

        $order->update_meta_data( '_ke_promoter_slug',  (string) $promoter->slug );
        $order->update_meta_data( '_ke_promoter_source', 'url' );
        $order->save();
    }

    /**
     * Kept for backward compatibility — all hooks are now registered in __construct().
     */
    public function init() {}

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
    public function add_to_cart( $event_id, $ticket_type_id, $quantity, $attendee_name, $attendee_email, $attendees = array() ) {
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

        // Per-ticket-type sales cutoff. Independent of stock — a ticket type
        // with capacity left but a past sale_end is still closed.
        if ( KE_Ticket_Types::is_sales_closed( $ticket_type ) ) {
            return new WP_Error(
                'sales_closed',
                sprintf( __( 'Las ventas para %s ya cerraron.', 'kiwi-events' ), $ticket_type->name )
            );
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

        // Per-attendee data (incl. validated extra_fields) so they survive the
        // round-trip through WC checkout and reach `KE_Tickets::generate()`.
        if ( is_array( $attendees ) && ! empty( $attendees ) ) {
            $cart_item_data['ke_attendees'] = $attendees;
        }

        // Promoter attribution is determined later at order-processed time by
        // parsing the HTTP_REFERER of the order submit (URL-only model). We do
        // NOT capture it at cart-add — the add_to_cart REST call typically
        // doesn't carry ?promo= in its own URL, and we have nowhere
        // legitimate to read it from. See on_payment_complete().

        $cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, 0, array(), $cart_item_data );

        if ( ! $cart_item_key ) {
            return new WP_Error( 'cart_add_failed', 'Could not add ticket to cart. The product may be out of stock.', array( 'status' => 500 ) );
        }

        return true;
    }

    /**
     * Get or create a virtual WooCommerce product for a ticket type.
     * Product price = base ticket price + service fee so WooCommerce
     * charges the correct total without any double-counting.
     */
    private function get_or_create_product( $ticket_type, $event_id ) {
        // Product price = BASE ticket price only.
        // The service fee is added as a separate cart line via apply_service_fee()
        // so it appears once and is never baked into the product price.
        $base_price = round( floatval( $ticket_type->price ), 2 );

        // Check if product already exists
        $existing_product_id = get_post_meta( $event_id, '_ke_wc_product_' . $ticket_type->id, true );

        if ( $existing_product_id && get_post_status( $existing_product_id ) === 'publish' ) {
            // Revert to base price if a previous version stored base+fee
            $product = wc_get_product( $existing_product_id );
            if ( $product && (float) $product->get_price() !== $base_price ) {
                $product->set_price( $base_price );
                $product->set_regular_price( $base_price );
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
        $product->set_price( $base_price );
        $product->set_regular_price( $base_price );
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

        // Sales cutoff guard. The custom add_to_cart() above already checks
        // this for normal REST flows, but WC's filter also runs for raw
        // wc_get_product() add-to-cart paths (block editor cart, blocks
        // checkout) which bypass the custom handler.
        if ( ! empty( $cart_item_data['ke_ticket_type_id'] ) ) {
            $tt = ( new KE_Ticket_Types() )->get( (int) $cart_item_data['ke_ticket_type_id'] );
            if ( $tt && KE_Ticket_Types::is_sales_closed( $tt ) ) {
                wc_add_notice(
                    sprintf( __( 'Las ventas para %s ya cerraron.', 'kiwi-events' ), $tt->name ),
                    'error'
                );
                return false;
            }
        }

        return $passed;
    }

    /**
     * Re-validate every KE line in the cart against its sale_end cutoff.
     * Bound to `woocommerce_check_cart_items`, which fires on cart render and
     * at the start of checkout submission — so a cart that was valid at
     * add-time but went stale while the buyer lingered on checkout gets
     * blocked before payment is collected. Adding an error notice is
     * sufficient; WooCommerce halts the checkout when any error notice is
     * present on the queue.
     */
    public function validate_cart_cutoffs() {
        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return;
        }

        $tt_handler = null;
        $seen       = array();
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $tt_id = isset( $cart_item['ke_ticket_type_id'] ) ? (int) $cart_item['ke_ticket_type_id'] : 0;
            if ( $tt_id <= 0 || isset( $seen[ $tt_id ] ) ) continue;
            $seen[ $tt_id ] = true;

            if ( $tt_handler === null ) {
                $tt_handler = new KE_Ticket_Types();
            }
            $tt = $tt_handler->get( $tt_id );
            if ( $tt && KE_Ticket_Types::is_sales_closed( $tt ) ) {
                wc_add_notice(
                    sprintf( __( 'Las ventas para %s ya cerraron.', 'kiwi-events' ), $tt->name ),
                    'error'
                );
            }
        }
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
     * Save ticket metadata to WC order item so on_payment_complete() can read it.
     * Keys are underscore-prefixed so they are hidden from the WC order screen.
     */
    public function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( ! empty( $values['ke_event_id'] ) ) {
            $item->add_meta_data( '_ke_event_id', $values['ke_event_id'], true );
        }
        if ( ! empty( $values['ke_ticket_type_id'] ) ) {
            $item->add_meta_data( '_ke_ticket_type_id', $values['ke_ticket_type_id'], true );
        }
        if ( ! empty( $values['ke_attendee_name'] ) ) {
            $item->add_meta_data( '_ke_buyer_name', $values['ke_attendee_name'], true );
        }
        if ( ! empty( $values['ke_attendee_email'] ) ) {
            $item->add_meta_data( '_ke_buyer_email', $values['ke_attendee_email'], true );
        }
        // Per-attendee blob (incl. validated extra_fields). JSON-encode here
        // because WC item meta serializes scalars best — order-status hooks
        // re-read it and decode in `on_payment_complete()`.
        if ( ! empty( $values['ke_attendees'] ) && is_array( $values['ke_attendees'] ) ) {
            $item->add_meta_data( '_ke_attendees', wp_json_encode( $values['ke_attendees'] ), true );
        }
        // URL-only attribution model: the promoter slug is resolved at order-
        // processed time from HTTP_REFERER, not captured at cart-add. The slug
        // (if any) is written to the WC order meta `_ke_promoter_slug` by
        // on_payment_complete() and is the immutable post-purchase source of
        // truth for thank-you/email/admin renderings.
    }

    /**
     * Generate tickets after WooCommerce payment.
     * Fires on payment_complete, order_status_processing, and order_status_completed.
     * The _ke_tickets_generated flag prevents duplicate generation when multiple hooks fire.
     */
    public function on_payment_complete( $order_id ) {
        // Prevent duplicate generation — checked before loading the order object
        if ( get_post_meta( $order_id, '_ke_tickets_generated', true ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Ensure KE classes are loaded — WooCommerce hooks can fire before the
        // plugin's own autoload has run in certain boot sequences (e.g. WC-CLI).
        if ( ! class_exists( 'KE_Orders' ) ) {
            require_once KE_PLUGIN_DIR . 'includes/class-ke-orders.php';
        }
        if ( ! class_exists( 'KE_Tickets' ) ) {
            require_once KE_PLUGIN_DIR . 'includes/class-ke-tickets.php';
        }
        if ( ! class_exists( 'KE_Email' ) ) {
            require_once KE_PLUGIN_DIR . 'includes/class-ke-email.php';
        }

        $orders_handler  = new KE_Orders();
        $tickets_handler = new KE_Tickets();
        $email_handler   = new KE_Email();

        $all_ticket_codes = array();

        foreach ( $order->get_items() as $item ) {
            $event_id       = $item->get_meta( '_ke_event_id' );
            $ticket_type_id = $item->get_meta( '_ke_ticket_type_id' );
            $buyer_name     = $item->get_meta( '_ke_buyer_name' )
                            ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            $buyer_email    = $item->get_meta( '_ke_buyer_email' )
                            ?: $order->get_billing_email();
            $quantity       = $item->get_quantity();

            if ( ! $event_id || ! $ticket_type_id ) {
                continue;
            }

            // Create KE order record
            $order_result = $orders_handler->create( array(
                'event_id'        => absint( $event_id ),
                'user_id'         => $order->get_customer_id(),
                'buyer_name'      => sanitize_text_field( $buyer_name ),
                'buyer_email'     => sanitize_email( $buyer_email ),
                'total_amount'    => $order->get_total(),
                'ticket_quantity' => $quantity,
                'payment_method'  => 'woocommerce',
                'payment_status'  => 'completed',
                'wc_order_id'     => $order_id,
            ) );

            if ( is_wp_error( $order_result ) ) {
                error_log( 'KiwiEvents: order creation failed — ' . $order_result->get_error_message() );
                continue;
            }

            // Build attendees array — one entry per ticket. Prefer the
            // per-attendee blob captured at add-to-cart time (which carries
            // extra_fields and individual names/emails); fall back to the
            // billing details when the cart didn't supply one.
            $attendees   = array();
            $stored_blob = $item->get_meta( '_ke_attendees' );
            if ( is_string( $stored_blob ) && $stored_blob !== '' ) {
                $decoded = json_decode( $stored_blob, true );
                if ( is_array( $decoded ) ) {
                    foreach ( $decoded as $a ) {
                        if ( ! is_array( $a ) ) continue;
                        $attendees[] = array(
                            'name'         => sanitize_text_field( $a['name']  ?? $buyer_name ),
                            'email'        => sanitize_email( $a['email'] ?? $buyer_email ) ?: $buyer_email,
                            'extra_fields' => isset( $a['extra_fields'] ) && is_array( $a['extra_fields'] ) ? $a['extra_fields'] : array(),
                        );
                    }
                }
            }
            if ( empty( $attendees ) ) {
                for ( $i = 0; $i < $quantity; $i++ ) {
                    $attendees[] = array(
                        'name'  => $buyer_name,
                        'email' => $buyer_email,
                    );
                }
            }

            // Generate tickets
            $ticket_ids = $tickets_handler->generate(
                $order_result['order_id'],
                absint( $event_id ),
                absint( $ticket_type_id ),
                $attendees
            );

            if ( is_wp_error( $ticket_ids ) ) {
                error_log( 'KiwiEvents: ticket generation failed — ' . $ticket_ids->get_error_message() );
                continue;
            }

            // Promoter attribution (paid flow). The slug — if any — was
            // captured from the checkout request's HTTP_REFERER by
            // capture_promoter_from_referrer() and stored on the ORDER meta
            // (not the item meta). Read it here to drive commissions.
            $promo_slug   = (string) $order->get_meta( '_ke_promoter_slug' );
            $promo_source = (string) $order->get_meta( '_ke_promoter_source' );
            if ( defined( 'KE_PROMOTER_DEBUG' ) && KE_PROMOTER_DEBUG ) {
                error_log( sprintf(
                    '[KE-PROMO] on_payment_complete: ke_order=%d wc_order=%d slug=%s source=%s',
                    (int) $order_result['order_id'], (int) $order_id,
                    $promo_slug !== '' ? $promo_slug : '(none)',
                    $promo_source !== '' ? $promo_source : '(unknown)'
                ) );
            }
            if ( $promo_slug !== '' && class_exists( 'KE_Promoter_Commissions' ) ) {
                // Use the live ticket type's base price (price WITHOUT
                // service fee or tax) per spec.
                if ( ! class_exists( 'KE_Ticket_Types' ) ) {
                    require_once KE_PLUGIN_DIR . 'includes/class-ke-ticket-types.php';
                }
                $tt_handler = new KE_Ticket_Types();
                $tt         = $tt_handler->get( absint( $ticket_type_id ) );
                $base_price = $tt ? floatval( $tt->price ) : 0.0;

                KE_Promoter_Commissions::generate_for_order( array(
                    'event_id'           => absint( $event_id ),
                    'order_id'           => (int) $order_result['order_id'],
                    'wc_order_id'        => (int) $order_id,
                    'ticket_ids'         => $ticket_ids,
                    'ticket_base_price'  => $base_price,
                    'promoter_slug'      => $promo_slug,
                    'buyer_name'         => $buyer_name,
                    'buyer_email'        => $buyer_email,
                    'attribution_method' => $promo_source !== '' ? $promo_source : 'url',
                ) );
            }

            // Send confirmation email (non-fatal)
            try {
                $email_handler->send_ticket_email( $order_result['order_id'] );
                error_log( 'KiwiEvents: email sent for KE order ' . $order_result['order_id'] . ' (WC order ' . $order_id . ')' );
            } catch ( \Throwable $e ) {
                error_log( 'KiwiEvents email error for KE order ' . $order_result['order_id'] . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            }

            // Collect ticket codes for the thank-you page
            foreach ( $ticket_ids as $ticket_id ) {
                $ticket = $tickets_handler->get( $ticket_id );
                if ( $ticket ) {
                    $all_ticket_codes[] = $ticket->ticket_code;
                }
            }
        }

        // Persist ticket codes and mark as processed
        update_post_meta( $order_id, '_ke_tickets_generated', true );
        if ( ! empty( $all_ticket_codes ) ) {
            $order->update_meta_data( '_ke_ticket_codes', $all_ticket_codes );
            $order->save();
        }
    }

    /**
     * Show ticket QR codes on the WooCommerce thank-you page
     */
    public function show_tickets_on_thankyou( $order_id ) {
        $order        = wc_get_order( $order_id );
        $ticket_codes = $order ? $order->get_meta( '_ke_ticket_codes' ) : array();

        if ( empty( $ticket_codes ) ) {
            return;
        }

        $ui     = get_option( 'ke_ui_settings', array() );
        $accent = ! empty( $ui['accent_color'] )
                ? sanitize_hex_color( $ui['accent_color'] )
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
            echo '<div style="font-weight:700;font-size:16px;color:#09090b;margin-bottom:12px;">'
               . esc_html( $short ) . '</div>';
            echo '<a href="' . $ticket_url . '" '
               . 'style="display:inline-block;padding:12px 24px;background:' . esc_attr( $accent ) . ';'
               . 'color:#fff;border-radius:100px;text-decoration:none;font-weight:600;font-size:14px;">'
               . '⬇️ Download Ticket PDF</a>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Compute the per-fee totals for the current WC()->cart contents.
     * Reads the CURRENT ticket price from wp_ke_ticket_types so the fee
     * is based on the real ticket cost regardless of WC product price state.
     *
     * @return array<string, float> [ fee_name => amount ]
     */
    private function calculate_cart_service_fees() {
        $fee_totals = array();

        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
            return $fee_totals;
        }

        $all_fees     = get_option( 'ke_service_fees', array() );
        $ticket_types = new KE_Ticket_Types();

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['ke_event_id'] ) || empty( $cart_item['ke_ticket_type_id'] ) ) {
                continue;
            }

            $event_id       = (int) $cart_item['ke_event_id'];
            $ticket_type_id = (int) $cart_item['ke_ticket_type_id'];
            $quantity       = (int) $cart_item['quantity'];

            $fee_id = get_post_meta( $event_id, '_ke_event_service_fee_id', true );
            if ( ! $fee_id ) {
                continue;
            }

            $fee = null;
            foreach ( $all_fees as $f ) {
                if ( isset( $f['id'] ) && $f['id'] === $fee_id ) {
                    $fee = $f;
                    break;
                }
            }
            if ( ! $fee ) {
                continue;
            }

            $ticket_type = $ticket_types->get( $ticket_type_id );
            if ( ! $ticket_type ) {
                continue;
            }

            $base_price = floatval( $ticket_type->price );

            if ( ( $fee['type'] ?? '' ) === 'formula' ) {
                if ( $base_price <= 0.0 ) {
                    continue; // percentage-based fees only apply to paid tickets
                }
                $fee_per_ticket = ( $base_price * floatval( $fee['percentage'] ?? 0 ) / 100 )
                                + floatval( $fee['fixed_amount'] ?? 0 );
            } else {
                $fee_per_ticket = floatval( $fee['fixed_amount'] ?? 0 );
            }

            if ( $fee_per_ticket <= 0 ) {
                continue;
            }

            $fee_name = ! empty( $fee['name'] ) ? (string) $fee['name'] : 'Service Fee';
            $fee_totals[ $fee_name ] = ( $fee_totals[ $fee_name ] ?? 0 ) + ( $fee_per_ticket * $quantity );
        }

        foreach ( $fee_totals as $k => $v ) {
            $fee_totals[ $k ] = round( $v, 2 );
        }

        return $fee_totals;
    }

    /**
     * Hooked on woocommerce_cart_calculate_fees — runs on every cart
     * calculation (cart view, checkout render, gateway init, order creation,
     * admin order edit, and blocks flows).
     */
    public function apply_service_fee( $cart = null ) {
        $fee_totals = $this->calculate_cart_service_fees();
        if ( empty( $fee_totals ) ) {
            return;
        }

        $target = ( $cart instanceof WC_Cart ) ? $cart : WC()->cart;
        if ( ! $target ) {
            return;
        }

        foreach ( $fee_totals as $name => $amount ) {
            $target->add_fee( $name, $amount, false );
        }
    }

    /**
     * Hooked on woocommerce_checkout_create_order — persists cart fees
     * onto the newly created order as WC_Order_Item_Fee line items so
     * they survive redirect-gateway flows (Yappy, PayPal) where the cart
     * may be cleared before the order is marked paid.
     */
    public function persist_fee_on_order( $order, $data ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        // Skip if WC already copied matching fees from the cart.
        $existing_names = array();
        foreach ( $order->get_items( 'fee' ) as $existing_fee ) {
            $existing_names[ $existing_fee->get_name() ] = true;
        }

        $fee_totals = $this->calculate_cart_service_fees();

        foreach ( $fee_totals as $name => $amount ) {
            if ( isset( $existing_names[ $name ] ) ) {
                continue;
            }
            $item = new WC_Order_Item_Fee();
            $item->set_name( $name );
            $item->set_amount( $amount );
            $item->set_total( $amount );
            $item->set_tax_status( 'none' );
            $item->set_tax_class( '' );
            $order->add_item( $item );
        }
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
            // Cancel all valid tickets for this order
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

        // Apply the configured refund policy to any commissions attached to
        // this WC order. Default = 'keep' (organizer still owes the promoter).
        if ( class_exists( 'KE_Promoter_Commissions' ) ) {
            KE_Promoter_Commissions::apply_refund_to_wc_order( $wc_order_id );
        }
    }
}
