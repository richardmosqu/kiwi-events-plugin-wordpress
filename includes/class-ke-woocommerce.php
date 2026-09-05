<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce integration for paid tickets
 */
class KE_WooCommerce {

    /** Per-request guard so log_cart_path() logs once per path, not per line. */
    private $logged_cart_paths = array();

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
        // Cancelled orders used to be ignored entirely: the tickets stayed
        // 'valid' and quantity_sold kept counting them, so every cancellation
        // silently inflated the sold figure and ate capacity that was never
        // sold. WooCommerce cancels unpaid orders on its own schedule (the
        // "Hold stock (minutes)" cron), so this fires on its own in normal
        // operation — it is not only an admin action.
        add_action( 'woocommerce_order_status_cancelled',  array( $this, 'handle_order_cancelled' ) );
        add_action( 'woocommerce_order_status_failed',     array( $this, 'handle_order_cancelled' ) );

        // Cart validation — enforce ticket limits
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 5 );

        // Per-person limit on the classic cart quantity stepper. Without this
        // the "+" button raised the quantity with no re-check — the exact Bug 1
        // bypass. Fires before WooCommerce applies the new quantity.
        add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_cart_update' ), 10, 4 );

        // Give every ticket add-to-cart its own cart line so two identical adds
        // (same event/type/name/email) do NOT merge into one line with a summed
        // quantity but a single frozen attendee blob — which is how a buyer paid
        // for N yet received one QR without ever touching the stepper.
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_line_uid' ), 10, 3 );

        // Second-pass guard: if a ticket type's sale_end cutoff passes while
        // the buyer is sitting on the checkout page, block the order before
        // payment is taken. WooCommerce calls this on cart render and at the
        // start of checkout submission, so a stale cart can't sneak through.
        add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_cutoffs' ) );

        // Whole-cart per-person re-check on the same lifecycle hook: sums every
        // KE line per (event, email) and blocks checkout if any pair exceeds the
        // event limit — the authority for a cart edited outside add-to-cart.
        add_action( 'woocommerce_check_cart_items', array( $this, 'validate_cart_limits' ) );

        // Store API (Blocks cart & checkout) validation. The classic filters
        // above do not fire for the block cart's own quantity controls, so the
        // limit is re-enforced here too. Registered unconditionally — these
        // actions simply never fire on a WooCommerce build without the Store
        // API, and every callback degrades safely if Store API internals are
        // absent (see the methods for the guarded RouteException throw).
        add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'store_api_validate_add_to_cart' ), 10, 2 );
        add_action( 'woocommerce_store_api_cart_errors',          array( $this, 'store_api_cart_errors' ), 10, 1 );

        // ── Phase 4: cart UI ──────────────────────────────────────────────
        // Ticket quantity is decided on the event page (the only place attendee
        // data is collected), so the classic cart shows it as fixed text with a
        // link back to the event instead of an editable stepper — the stepper
        // is what let a buyer's quantity drift away from the frozen attendee
        // blob. Security lives in the validators above; this is UX so the buyer
        // isn't offered a control that would just error.
        add_filter( 'woocommerce_cart_item_quantity', array( $this, 'fixed_cart_item_quantity' ), 10, 3 );

        // Belt for any spot that still renders a quantity input for a ticket
        // product (classic templates): cap min/max to the ticket type's bounds.
        add_filter( 'woocommerce_quantity_input_args', array( $this, 'cap_quantity_input_args' ), 10, 2 );

        // Block cart/checkout stepper bounds via the Store API quantity-limit
        // filters. Registered unconditionally — they only fire when the Store
        // API is active, and no-op for non-ticket products.
        add_filter( 'woocommerce_store_api_product_quantity_maximum', array( $this, 'store_api_quantity_maximum' ), 10, 3 );
        add_filter( 'woocommerce_store_api_product_quantity_minimum', array( $this, 'store_api_quantity_minimum' ), 10, 3 );

        // Minimal, scoped styling for the fixed-quantity text — printed once on
        // the classic cart page (the only place fixed_cart_item_quantity renders).
        add_action( 'woocommerce_before_cart', array( $this, 'print_cart_ticket_styles' ) );

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

        // Event-level scheduled opening. Mirrors the free-checkout guard in
        // KE_Rest_API::_do_checkout so a crafted add-to-cart can't jump the
        // queue while the public page still shows the countdown. The event is
        // read off the ticket-type row, never from the $event_id argument, so
        // the gate holds even if a future caller passes the wrong one.
        $owner_event_id = (int) $ticket_type->event_id;
        if ( class_exists( 'KE_Sales_Schedule' ) && KE_Sales_Schedule::is_pending( $owner_event_id ) ) {
            return new WP_Error( 'sales_not_open', KE_Sales_Schedule::closed_message( $owner_event_id ) );
        }

        // Per-ticket-type sales cutoff. Independent of stock — a ticket type
        // with capacity left but a past sale_end is still closed.
        if ( KE_Ticket_Types::is_sales_closed( $ticket_type ) ) {
            return new WP_Error(
                'sales_closed',
                sprintf( __( 'Las ventas para %s ya cerraron.', 'kiwi-events' ), $ticket_type->name )
            );
        }

        // Per-person limit + per-order bounds, enforced HERE at add time.
        // WC_Cart::add_to_cart() (called below) does NOT fire the
        // woocommerce_add_to_cart_validation filter — that filter only runs
        // from WC's form-handler/AJAX/session-restore/Store-API paths — so this
        // is the only place the plugin's own server-side add can reject a buyer
        // before the item enters the cart. The whole-cart backstop
        // (validate_cart_limits on woocommerce_check_cart_items) and the Store
        // API check still catch anything that reaches cart/checkout, but they
        // fire at render/checkout; enforcing here gives immediate feedback. Uses
        // the event id off the ticket-type row, consistent with the gates above.
        $limit_check = KE_Ticket_Limits::can_user_take( $owner_event_id, $attendee_email, (int) $quantity, 'add' );
        if ( is_wp_error( $limit_check ) ) {
            return $limit_check;
        }
        $bounds_check = KE_Ticket_Limits::check_order_bounds( $ticket_type, (int) $quantity );
        if ( is_wp_error( $bounds_check ) ) {
            return $bounds_check;
        }
        // Aggregate max_per_order across existing cart lines of this type (#4):
        // the new line is not in the cart yet, so no exclusion.
        $type_max_check = KE_Ticket_Limits::check_type_order_max( $ticket_type, (int) $quantity, null );
        if ( is_wp_error( $type_max_check ) ) {
            return $type_max_check;
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
     * Mirror a ticket type's remaining capacity onto its WooCommerce product.
     *
     * The KiwiEvents counter stays the source of truth; WooCommerce stock is a
     * mirror of `quantity_total - quantity_sold`, re-synced (absolutely, never
     * by increments) every time that counter moves. Because the value is
     * absolute it cannot drift out of step with WooCommerce's own stock
     * reduction at payment: both end up describing the same remaining seats.
     *
     * What this buys is the missing piece of the oversell: capacity used to be
     * checked once at add-to-cart against a counter that only advanced at
     * payment, so every buyer in flight during the gateway round trip saw the
     * same free seats. WooCommerce's reservation covers exactly that window.
     *
     * An unlimited ticket type turns stock management back off.
     */
    public static function sync_product_stock( $ticket_type, $event_id = 0 ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return;
        }
        if ( is_numeric( $ticket_type ) ) {
            $handler     = new KE_Ticket_Types();
            $ticket_type = $handler->get( (int) $ticket_type );
        }
        if ( ! $ticket_type || empty( $ticket_type->id ) ) {
            return;
        }
        $event_id = (int) ( $event_id ?: ( $ticket_type->event_id ?? 0 ) );
        if ( $event_id <= 0 ) {
            return;
        }

        $product_id = (int) get_post_meta( $event_id, '_ke_wc_product_' . $ticket_type->id, true );
        if ( $product_id <= 0 ) {
            return; // No product yet — creation will set the stock itself.
        }
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        if ( ( $ticket_type->capacity_type ?? 'limited' ) === 'unlimited' ) {
            if ( $product->get_manage_stock() ) {
                $product->set_manage_stock( false );
                $product->save();
            }
            return;
        }

        $remaining = max( 0, (int) $ticket_type->quantity_total - (int) $ticket_type->quantity_sold );

        // Nothing to write when the product already agrees — this runs on
        // every sale, so avoid a pointless save.
        if ( $product->get_manage_stock()
            && (int) $product->get_stock_quantity() === $remaining
            && $product->get_backorders() === 'no' ) {
            return;
        }

        $product->set_manage_stock( true );
        $product->set_backorders( 'no' );
        $product->set_stock_quantity( $remaining );
        $product->set_stock_status( $remaining > 0 ? 'instock' : 'outofstock' );
        $product->save();
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
            // Products created before stock management was introduced have
            // manage_stock off and would still oversell, so bring them in line
            // here rather than requiring a manual re-save of every event.
            self::sync_product_stock( $ticket_type, $event_id );
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
        // Stock management is what actually stops an oversell. WooCommerce
        // reserves stock for the duration of checkout (wp_wc_reserved_stock,
        // held for the "Hold stock (minutes)" setting) and refuses the buyer
        // who arrives once the seats are spoken for — which is precisely the
        // gap the KE counter cannot cover, because it only moves once payment
        // completes. See sync_product_stock() for the mirroring rule.
        $product->set_backorders( 'no' );
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

        // Seed the stock now that the mapping exists.
        self::sync_product_stock( $ticket_type, $event_id );
        update_post_meta( $product_id, '_ke_event_id', $event_id );
        update_post_meta( $product_id, '_ke_ticket_type_id', $ticket_type->id );

        return $product_id;
    }

    /**
     * Validate adding to cart — enforce ticket limits.
     *
     * NOTE on fire sites (verified against WooCommerce 10.7.0): WooCommerce
     * does NOT apply this filter from WC_Cart::add_to_cart() (the plugin's own
     * add path). In 10.7.0 it is applied only from class-wc-form-handler.php
     * (915/947/997), class-wc-cart-session.php:615 (restore, WITH cart_item_data),
     * and class-wc-ajax.php:519 — not from WC_Cart. (Older WooCommerce did fire
     * it inside WC_Cart::add_to_cart; recheck this list after a WC upgrade.) So
     * the plugin's own server-side add is validated by the checks inside
     * KE_WooCommerce::add_to_cart() itself, not here. This callback still
     * matters for the other paths:
     *  - a raw wc add-to-cart against the hidden virtual ticket product (a
     *    crafted ?add-to-cart URL, block editor) passes no ke_* data → we
     *    resolve the event from product meta and reject it (F), because tickets
     *    would otherwise be charged with no attendee blob to mint them from;
     *  - "order again" (WC_Cart_Session::populate_cart_from_order, which fires
     *    the filter at class-wc-cart-session.php:615 WITH cart_item_data) re-runs
     *    this with the stored ke_* data, so the per-person limit and bounds are
     *    re-asserted when a past order is re-added. (This is not routine
     *    get_cart_from_session() page-load restore, which does not fire it — so
     *    ordinary cart page loads never produce a false limit rejection here.)
     */
    public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $cart_item_data = array() ) {
        $event_id = ! empty( $cart_item_data['ke_event_id'] ) ? (int) $cart_item_data['ke_event_id'] : 0;

        // F — no KE data on the add. Resolve the event from the product itself
        // instead of waving it through: a hidden ticket product added without
        // going through the event form has no attendee data and must be blocked.
        if ( $event_id <= 0 ) {
            $product_event_id = (int) get_post_meta( (int) $product_id, '_ke_event_id', true );
            if ( $product_event_id > 0 ) {
                $event_url = get_permalink( $product_event_id );
                $msg = __( 'Los boletos deben agregarse desde la página del evento.', 'kiwi-events' );
                if ( $event_url ) {
                    $msg .= ' <a href="' . esc_url( $event_url ) . '">' . esc_html__( 'Ir al evento', 'kiwi-events' ) . '</a>';
                }
                wc_add_notice( $msg, 'error' );
                return false;
            }
            // Not a KE ticket product — nothing for us to enforce.
            return $passed;
        }

        $email = isset( $cart_item_data['ke_attendee_email'] ) ? $cart_item_data['ke_attendee_email'] : '';

        // Per-person limit — 'add' context: the new line is not in the cart
        // yet, so this counts existing tickets + other cart lines + $quantity.
        $can = KE_Ticket_Limits::can_user_take( $event_id, $email, $quantity, 'add' );
        if ( is_wp_error( $can ) ) {
            wc_add_notice( $can->get_error_message(), 'error' );
            return false;
        }

        // Per-ticket-type order bounds (D) — server-side, not just the sheet.
        if ( ! empty( $cart_item_data['ke_ticket_type_id'] ) ) {
            $tt = ( new KE_Ticket_Types() )->get( (int) $cart_item_data['ke_ticket_type_id'] );
            if ( $tt ) {
                $bounds = KE_Ticket_Limits::check_order_bounds( $tt, $quantity );
                if ( is_wp_error( $bounds ) ) {
                    wc_add_notice( $bounds->get_error_message(), 'error' );
                    return false;
                }
                // Aggregate max_per_order across cart lines of this type (#4).
                $type_max = KE_Ticket_Limits::check_type_order_max( $tt, $quantity, null );
                if ( is_wp_error( $type_max ) ) {
                    wc_add_notice( $type_max->get_error_message(), 'error' );
                    return false;
                }
                // Sales cutoff guard. The custom add_to_cart() above already
                // checks this for normal REST flows, but WC's filter also runs
                // for raw add-to-cart paths that bypass the custom handler.
                if ( KE_Ticket_Types::is_sales_closed( $tt ) ) {
                    wc_add_notice(
                        sprintf( __( 'Las ventas para %s ya cerraron.', 'kiwi-events' ), $tt->name ),
                        'error'
                    );
                    return false;
                }
            }
        }

        return $passed;
    }

    /**
     * Give each ticket add-to-cart its own unique cart line (B).
     *
     * WooCommerce derives a cart item's key from a hash of product id +
     * cart_item_data, so two adds of the same product with identical ke_* data
     * collapse into one line whose quantity is summed while only the first
     * attendee blob survives — the buyer pays for the summed quantity but
     * generate_for_order() mints count(first blob) tickets. A per-line UUID
     * makes every add hash uniquely, so N adds stay N lines with N blobs.
     *
     * @param array $cart_item_data
     * @param int   $product_id
     * @param int   $variation_id
     * @return array
     */
    public function add_line_uid( $cart_item_data, $product_id, $variation_id ) {
        $is_ticket = ! empty( $cart_item_data['ke_event_id'] )
            || (int) get_post_meta( (int) $product_id, '_ke_event_id', true ) > 0;
        if ( $is_ticket && empty( $cart_item_data['_ke_line_uid'] ) ) {
            $cart_item_data['_ke_line_uid'] = function_exists( 'wp_generate_uuid4' )
                ? wp_generate_uuid4()
                : uniqid( 'ke_', true );
        }
        return $cart_item_data;
    }

    /**
     * Per-person limit on a classic cart quantity change (Bug 1's stepper path).
     *
     * WooCommerce passes the NEW quantity for the edited line; we exclude that
     * line's current quantity from the running total and test existing + other
     * KE lines for the event + the requested new quantity against the limit.
     *
     * @param bool   $passed
     * @param string $cart_item_key
     * @param array  $values      The cart item being updated.
     * @param int    $quantity    The requested new quantity.
     * @return bool
     */
    public function validate_cart_update( $passed, $cart_item_key, $values, $quantity ) {
        if ( empty( $values['ke_event_id'] ) ) {
            return $passed;
        }
        $event_id = (int) $values['ke_event_id'];
        $email    = isset( $values['ke_attendee_email'] ) ? $values['ke_attendee_email'] : '';

        // The per-person limit only needs checking when the quantity goes UP: a
        // reduction can never newly exceed it. Checking reductions too would
        // dead-end a buyer whose cart is already over an (e.g. just-lowered)
        // limit — they could not even shrink the offending line. Only increases
        // are limit-checked; the min/max per-order bounds below still apply in
        // both directions (so a reduction below min_per_order is still blocked).
        $current_qty = isset( $values['quantity'] ) ? (int) $values['quantity'] : 0;
        if ( (int) $quantity > $current_qty ) {
            $can = KE_Ticket_Limits::can_user_take( $event_id, $email, (int) $quantity, 'update', $cart_item_key );
            if ( is_wp_error( $can ) ) {
                wc_add_notice( $can->get_error_message(), 'error' );
                return false;
            }
        }

        if ( ! empty( $values['ke_ticket_type_id'] ) ) {
            $tt = ( new KE_Ticket_Types() )->get( (int) $values['ke_ticket_type_id'] );
            if ( $tt ) {
                $bounds = KE_Ticket_Limits::check_order_bounds( $tt, (int) $quantity );
                if ( is_wp_error( $bounds ) ) {
                    wc_add_notice( $bounds->get_error_message(), 'error' );
                    return false;
                }
                // Aggregate max_per_order across the other cart lines of this
                // type (#4), excluding the line being edited (its new quantity
                // is $quantity).
                $type_max = KE_Ticket_Limits::check_type_order_max( $tt, (int) $quantity, $cart_item_key );
                if ( is_wp_error( $type_max ) ) {
                    wc_add_notice( $type_max->get_error_message(), 'error' );
                    return false;
                }
            }
        }

        return $passed;
    }

    /**
     * Whole-cart per-person re-check (woocommerce_check_cart_items).
     *
     * Groups every KE ticket line by (event, purchaser email) and asserts each
     * pair's total does not exceed that event's limit. This is the backstop for
     * any cart state reached without firing add/update validation (a restored
     * session, a merged line, a Store API edit that slipped through). Adding an
     * error notice is sufficient — WooCommerce halts checkout while any error
     * notice is queued.
     */
    public function validate_cart_limits() {
        foreach ( KE_Ticket_Limits::collect_cart_violations() as $msg ) {
            wc_add_notice( $msg, 'error' );
        }
    }

    /**
     * Store API (Blocks) — block a raw add of a ticket product to the block
     * cart. Ticket products carry no attendee data on a Store API add, so they
     * must go through the event form. Degrades safely: if the Store API's
     * RouteException class is absent (older WooCommerce), this action never
     * fires, so the guarded throw is unreachable.
     *
     * @param mixed $product The product being added (WC_Product).
     * @param mixed $request The Store API request.
     */
    public function store_api_validate_add_to_cart( $product, $request ) {
        $product_id = ( is_object( $product ) && method_exists( $product, 'get_id' ) ) ? (int) $product->get_id() : 0;
        if ( $product_id <= 0 ) {
            return;
        }
        if ( (int) get_post_meta( $product_id, '_ke_event_id', true ) <= 0 ) {
            return; // Not a ticket product.
        }
        $this->throw_store_api_error(
            'ke_ticket_event_page_required',
            __( 'Los boletos deben agregarse desde la página del evento.', 'kiwi-events' )
        );
    }

    /**
     * Store API (Blocks) — whole-cart limit re-check surfaced through the
     * Store API's own error channel so the block cart/checkout shows and blocks
     * on it. $errors is a WP_Error accumulator provided by WooCommerce.
     *
     * @param mixed $errors WP_Error accumulator.
     */
    public function store_api_cart_errors( $errors ) {
        if ( ! is_wp_error( $errors ) ) {
            return;
        }
        $this->log_cart_path( 'store_api' );
        foreach ( KE_Ticket_Limits::collect_cart_violations() as $i => $msg ) {
            $errors->add( 'ke_cart_limit_' . $i, $msg );
        }
    }

    /**
     * Throw a Store API RouteException when available; otherwise no-op (the
     * caller is only reached from a Store API hook, so absence means the block
     * flow isn't active on this WooCommerce build).
     */
    private function throw_store_api_error( $code, $message ) {
        $class = '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException';
        if ( class_exists( $class ) ) {
            throw new $class( $code, $message, 400 );
        }
    }

    /**
     * Render a ticket line's cart quantity as fixed text plus a link back to
     * the event page (Phase 4 / correction A). The number of tickets is chosen
     * on the event form — the only place attendee data is collected — so the
     * cart must not offer an editable stepper that would desync the quantity
     * from the frozen attendee blob. Non-ticket lines keep their normal input.
     *
     * @param string $product_quantity Quantity HTML WooCommerce would render.
     * @param string $cart_item_key
     * @param array  $cart_item
     * @return string
     */
    public function fixed_cart_item_quantity( $product_quantity, $cart_item_key, $cart_item = null ) {
        if ( empty( $cart_item['ke_event_id'] ) ) {
            return $product_quantity;
        }
        $this->log_cart_path( 'classic' );
        $qty       = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;
        $event_id  = (int) $cart_item['ke_event_id'];
        $event_url = get_permalink( $event_id );

        $html = '<span class="ke-cart-qty-fixed">' . esc_html( $qty ) . '</span>';
        if ( $event_url ) {
            $html .= '<a class="ke-cart-qty-change" href="' . esc_url( $event_url ) . '">'
                   . esc_html__( 'Cambiar en el evento', 'kiwi-events' )
                   . '</a>';
        }
        return $html;
    }

    /**
     * Cap a rendered quantity input to the ticket type's per-order bounds
     * wherever one still appears (classic templates). Belt only — the cart
     * shows fixed text and the validators are the security boundary.
     *
     * @param array $args
     * @param mixed $product
     * @return array
     */
    public function cap_quantity_input_args( $args, $product ) {
        $tt = $this->ticket_type_for_product( $product );
        if ( ! $tt ) {
            return $args;
        }
        $min = isset( $tt->min_per_order ) ? (int) $tt->min_per_order : 0;
        $max = isset( $tt->max_per_order ) ? (int) $tt->max_per_order : 0;
        if ( $min > 0 ) {
            $args['min_value'] = $min;
        }
        if ( $max > 0 ) {
            $args['max_value'] = ( isset( $args['max_value'] ) && (int) $args['max_value'] > 0 )
                ? min( (int) $args['max_value'], $max )
                : $max;
        }
        return $args;
    }

    /**
     * Store API block-cart stepper maximum. For an existing ticket LINE this
     * LOCKS the stepper by returning the line's own attendee count (min == max),
     * so a buyer can't change a ticket quantity in the block cart — quantity is
     * decided on the event form, the only place attendee data is collected. This
     * makes the block path independently sufficient: it does not rely on the
     * classic woocommerce_cart_item_quantity filter, which never fires when the
     * Cart page uses the Cart block. Falls back to max_per_order for a
     * product-level query (no specific line). No-op for non-ticket products;
     * only fires under the Store API, so safe on builds without it.
     *
     * @param mixed $value
     * @param mixed $product
     * @param mixed $cart_item
     * @return mixed
     */
    public function store_api_quantity_maximum( $value, $product, $cart_item = null ) {
        $locked = $this->ke_line_locked_qty( $cart_item );
        if ( $locked !== null ) {
            return $locked;
        }
        $tt = $this->ticket_type_for_product( $product );
        if ( ! $tt || empty( $tt->max_per_order ) ) {
            return $value;
        }
        $max = (int) $tt->max_per_order;
        return ( is_numeric( $value ) && (int) $value > 0 ) ? min( (int) $value, $max ) : $max;
    }

    /**
     * Store API block-cart stepper minimum. Locked to the ticket line's own
     * attendee count (see store_api_quantity_maximum); falls back to
     * min_per_order for a product-level query.
     *
     * @param mixed $value
     * @param mixed $product
     * @param mixed $cart_item
     * @return mixed
     */
    public function store_api_quantity_minimum( $value, $product, $cart_item = null ) {
        $locked = $this->ke_line_locked_qty( $cart_item );
        if ( $locked !== null ) {
            return $locked;
        }
        $tt = $this->ticket_type_for_product( $product );
        if ( ! $tt || empty( $tt->min_per_order ) ) {
            return $value;
        }
        return max( is_numeric( $value ) ? (int) $value : 1, (int) $tt->min_per_order );
    }

    /**
     * The locked quantity for a ticket cart LINE = count of its frozen attendee
     * blob (falling back to the line quantity). Returns null when $cart_item is
     * not a KE ticket line (product-level query or non-ticket), so the callers
     * fall through to their per-type bounds. Records that the block/Store API
     * cart path executed for the Phase 7 checklist.
     *
     * @param mixed $cart_item
     * @return int|null
     */
    private function ke_line_locked_qty( $cart_item ) {
        if ( ! is_array( $cart_item ) || empty( $cart_item['ke_event_id'] ) ) {
            return null;
        }
        $this->log_cart_path( 'store_api' );
        if ( ! empty( $cart_item['ke_attendees'] ) && is_array( $cart_item['ke_attendees'] ) ) {
            return count( $cart_item['ke_attendees'] );
        }
        return isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : null;
    }

    /**
     * Log which cart-quantity UI path actually executed — classic template
     * filter vs Store API / Blocks — once per request per path. The Phase 7
     * manual checklist reads this to confirm the ACTIVE path on the live site
     * rather than assuming it (the Cart page could be shortcode or block).
     *
     * @param string $path 'classic' | 'store_api'
     */
    private function log_cart_path( $path ) {
        if ( isset( $this->logged_cart_paths[ $path ] ) ) {
            return;
        }
        $this->logged_cart_paths[ $path ] = true;
        error_log( sprintf(
            'KiwiEvents: cart ticket-quantity UI path = %s (%s).',
            $path,
            $path === 'classic'
                ? 'classic woocommerce_cart_item_quantity filter fired'
                : 'Store API / Blocks quantity limits fired'
        ) );
    }

    /**
     * Resolve the KE ticket type for a WC product via _ke_ticket_type_id meta.
     *
     * @param mixed $product
     * @return object|null
     */
    private function ticket_type_for_product( $product ) {
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            return null;
        }
        $type_id = (int) get_post_meta( (int) $product->get_id(), '_ke_ticket_type_id', true );
        if ( $type_id <= 0 ) {
            return null;
        }
        return ( new KE_Ticket_Types() )->get( $type_id );
    }

    /**
     * Minimal scoped styling for the fixed cart quantity, printed once on the
     * classic cart page. Responsive: the "change" link stacks under the number
     * and never widens the quantity column on a phone.
     */
    public function print_cart_ticket_styles() {
        echo '<style id="ke-cart-ticket-qty">'
           . '.ke-cart-qty-fixed{font-weight:700;}'
           . '.ke-cart-qty-change{display:block;font-size:12px;line-height:1.3;margin-top:4px;white-space:nowrap;}'
           . '@media(max-width:480px){.ke-cart-qty-change{font-size:11px;margin-top:2px;}}'
           . '</style>';
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

        $tt_handler  = null;
        $seen        = array();
        $seen_events = array();
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

            // Same re-check for a scheduled opening: a cart built before the
            // schedule was pushed back must not survive to payment. Deduped
            // per event so a cart with several types of one event only
            // produces one notice.
            $ev_id = $tt ? (int) $tt->event_id : (int) ( $cart_item['ke_event_id'] ?? 0 );
            if ( $ev_id > 0 && ! isset( $seen_events[ $ev_id ] )
                && class_exists( 'KE_Sales_Schedule' ) && KE_Sales_Schedule::is_pending( $ev_id ) ) {
                $seen_events[ $ev_id ] = true;
                wc_add_notice( KE_Sales_Schedule::closed_message( $ev_id ), 'error' );
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
        global $wpdb;
        $order_id = (int) $order_id;

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Prevent duplicate generation. Read through the order object so the
        // guard holds under HPOS (custom order tables) as well as legacy
        // postmeta — get_post_meta() on an HPOS order id would silently miss.
        if ( $order->get_meta( '_ke_tickets_generated' ) ) {
            return;
        }

        // Three hooks point at this handler (payment_complete,
        // status_processing, status_completed) and an async gateway can retry
        // its callback while the first run is still working. The flag alone is
        // a check-then-set: it used to be written at the very END of this
        // method, after ticket generation AND after a synchronous email that
        // can take tens of seconds, so any second entrant in that window
        // generated a whole extra set of tickets and incremented the sold
        // counter again. A per-order MySQL lock closes the window; MySQL frees
        // it automatically if the request dies, so it can never wedge.
        $lock_name = 'ke_tickets_gen_' . $order_id;
        $got_lock  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) );
        if ( $got_lock !== 1 ) {
            // Someone else is generating this order right now.
            return;
        }

        try {
            $this->generate_for_order( $order_id );
        } finally {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }

    /**
     * Ticket generation for one paid WooCommerce order. Always called with the
     * per-order lock held (see on_payment_complete).
     */
    private function generate_for_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Re-check inside the lock: the previous holder may have just finished.
        // HPOS-safe read via the order object (see on_payment_complete).
        if ( $order->get_meta( '_ke_tickets_generated' ) ) {
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
        if ( ! class_exists( 'KE_Ticket_Limits' ) ) {
            require_once KE_PLUGIN_DIR . 'includes/class-ke-ticket-limits.php';
        }

        $orders_handler  = new KE_Orders();
        $tickets_handler = new KE_Tickets();
        $email_handler   = new KE_Email();

        $all_ticket_codes = array();
        $pending_emails   = array();

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

            // ── Reconciliation safety net (A) ────────────────────────────────
            // Paid quantity is authoritative for HOW MANY tickets exist; the
            // event per-person limit is the hard ceiling. Every upstream guard
            // should already keep count($attendees) == paid quantity <= limit,
            // but the buyer has paid, so if anything slipped through we resolve
            // deterministically, never silently, and never leave a paying
            // customer with zero tickets (no cancel/hold). The attendee blob
            // decides only WHO; it is padded when short and trimmed (original
            // preserved) when long.
            $blob_n   = count( $attendees );
            $paid_qty = (int) $quantity;
            $limit    = KE_Ticket_Limits::get_event_limit( (int) $event_id );
            $mint     = KE_Ticket_Limits::reconcile_mint_count( $paid_qty, $limit ); // min(paid, limit)
            $ke_oid   = (int) $order_result['order_id'];
            $review    = array(); // reasons this line needs review — written once below

            // Branch: paid exceeds the event limit. Should be impossible after
            // Phase 3 — if it fires, limit enforcement has a hole. Mint the
            // limit, flag for a partial refund, and make it LOUD.
            if ( $paid_qty > $limit ) {
                error_log( sprintf(
                    'KiwiEvents: PAID QTY %d EXCEEDS event %d per-person limit %d on WC order %d (KE order %d) — minting %d, PARTIAL REFUND NEEDED. Limit enforcement has a hole.',
                    $paid_qty, (int) $event_id, $limit, (int) $order_id, $ke_oid, $mint
                ) );
                $review[] = 'paid_over_limit';
                $order->add_order_note( sprintf(
                    /* translators: 1: paid quantity, 2: limit, 3: minted count. */
                    __( '⚠️ Se pagaron %1$d boletos pero el límite por persona del evento es %2$d. Se emitieron %3$d; se requiere un reembolso parcial. Pedido marcado para revisión.', 'kiwi-events' ),
                    $paid_qty, $limit, $mint
                ) );
            }

            // Branch: blob longer than what we will mint — excess named
            // attendees. Preserve the COMPLETE original blob so an admin can
            // reassign later; mint entries 1..N in blob order (entry 1 is
            // typically the buyer). Never delete the surplus data.
            if ( $blob_n > $mint ) {
                error_log( sprintf(
                    'KiwiEvents: attendee blob (%d) longer than minted count (%d) on WC order %d (KE order %d, event %d, paid %d). Original blob preserved; order flagged.',
                    $blob_n, $mint, (int) $order_id, $ke_oid, (int) $event_id, $paid_qty
                ) );
                $review[] = 'blob_over_minted';
                $order->add_meta_data( '_ke_attendees_original', wp_json_encode( array(
                    'ke_order_id' => $ke_oid, 'event_id' => (int) $event_id,
                    'minted' => $mint, 'attendees' => $attendees,
                ) ) );
                $order->add_order_note( sprintf(
                    /* translators: 1: attendee entries submitted, 2: minted count. */
                    __( 'Se recibieron %1$d asistentes con nombre pero se emitieron %2$d boletos (según lo pagado). Todos los nombres se conservaron para reasignación. Pedido marcado para revisión.', 'kiwi-events' ),
                    $blob_n, $mint
                ) );
            }

            // One review record per problematic line, carrying ALL reasons — so
            // a consumer (the Phase 5 report) reading a single get_meta() gets
            // the complete picture instead of only the first-written reason.
            if ( ! empty( $review ) ) {
                $order->add_meta_data( '_ke_order_needs_review', wp_json_encode( array(
                    'reasons'     => $review,
                    'ke_order_id' => $ke_oid,
                    'event_id'    => (int) $event_id,
                    'blob'        => $blob_n,
                    'paid_qty'    => $paid_qty,
                    'limit'       => $limit,
                    'minted'      => $mint,
                ) ) );
            }

            // Bring the attendee list to exactly $mint entries: pad shortfalls
            // with the buyer's details (warning + note), trim surplus to the
            // first $mint in blob order (the original blob is preserved above).
            if ( $blob_n < $mint ) {
                error_log( sprintf(
                    'KiwiEvents: attendee blob (%d) shorter than minted count (%d) on WC order %d (KE order %d, event %d) — padding %d with buyer data.',
                    $blob_n, $mint, (int) $order_id, $ke_oid, (int) $event_id, $mint - $blob_n
                ) );
                for ( $i = $blob_n; $i < $mint; $i++ ) {
                    $attendees[] = array( 'name' => $buyer_name, 'email' => $buyer_email );
                }
                $order->add_order_note( sprintf(
                    /* translators: %d: number of attendees auto-filled. */
                    _n(
                        'Se autocompletó %d asistente con los datos del comprador.',
                        'Se autocompletaron %d asistentes con los datos del comprador.',
                        $mint - $blob_n,
                        'kiwi-events'
                    ),
                    $mint - $blob_n
                ) );
            } elseif ( $blob_n > $mint ) {
                $attendees = array_slice( $attendees, 0, $mint );
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

            // Queue the confirmation for AFTER the guard flag is written.
            // Sending it here used to be what killed the flag: the mail path
            // builds one PDF per ticket and each PDF fetched its QR over the
            // network, so a slow request could die before the flag was
            // persisted and the next hook would regenerate everything.
            $pending_emails[] = (int) $order_result['order_id'];

            // Collect ticket codes for the thank-you page
            foreach ( $ticket_ids as $ticket_id ) {
                $ticket = $tickets_handler->get( $ticket_id );
                if ( $ticket ) {
                    $all_ticket_codes[] = $ticket->ticket_code;
                }
            }
        }

        // Mark as processed BEFORE the slow part. Everything that can lose
        // money — the KE order rows, the tickets, the sold counter — is
        // already committed at this point, so if the request dies during the
        // email below, a retry must NOT regenerate any of it. Written through
        // the order object (HPOS-safe) together with the codes and any review
        // flag, in a single save().
        $order->update_meta_data( '_ke_tickets_generated', true );
        if ( ! empty( $all_ticket_codes ) ) {
            $order->update_meta_data( '_ke_ticket_codes', $all_ticket_codes );
        }
        $order->save();

        // Now the confirmation emails. send_ticket_email() returns a WP_Error
        // on failure — a WP_Error is NOT a Throwable, so the old try/catch
        // swallowed every failed send without a trace. Record failures on the
        // order so "no me llegó el correo" is answerable and re-sendable
        // instead of invisible.
        $email_failures = false;
        foreach ( $pending_emails as $ke_order_id ) {
            try {
                $sent = $email_handler->send_ticket_email( $ke_order_id );
                if ( is_wp_error( $sent ) ) {
                    $order->add_meta_data( '_ke_ticket_email_failed', $ke_order_id );
                    $email_failures = true;
                    error_log( sprintf(
                        'KiwiEvents: ticket email FAILED for KE order %d (WC order %d): %s',
                        $ke_order_id, $order_id, $sent->get_error_message()
                    ) );
                } else {
                    error_log( 'KiwiEvents: email sent for KE order ' . $ke_order_id . ' (WC order ' . $order_id . ')' );
                }
            } catch ( \Throwable $e ) {
                $order->add_meta_data( '_ke_ticket_email_failed', $ke_order_id );
                $email_failures = true;
                error_log( 'KiwiEvents email error for KE order ' . $ke_order_id . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
            }
        }
        // Persist any failure flags added above (HPOS-safe). The generated-flag
        // save already happened before this loop, so a crash here can never undo
        // it — only the failure breadcrumb is at stake.
        if ( $email_failures ) {
            $order->save();
        }
    }

    /**
     * Roll back a cancelled or failed order: cancel its tickets, which also
     * returns their seats to the sold counter (KE_Tickets::update_status
     * decrements quantity_sold on the valid → cancelled transition).
     *
     * Without this, every cancellation left valid tickets behind and kept
     * counting them as sold — capacity permanently consumed by an order that
     * was never paid.
     */
    public function handle_order_cancelled( $wc_order_id ) {
        global $wpdb;

        // NEVER void the tickets of somebody who actually paid.
        //
        // The Yappy gateway's callback handler cancels unconditionally —
        // `elseif ('C' == $status || 'R' == $status) { $order->update_status('cancelled'); }`
        // with no check for an order it already approved and no idempotency.
        // A late or duplicate C/R callback from Banco General therefore
        // cancels a paid order hours after the fact, which is exactly the
        // "Pedido cancelado" mystery. Rolling tickets back on that signal
        // would turn a bogus status change into a real loss of access for a
        // paying customer, so a captured payment vetoes the rollback and asks
        // a human to look instead.
        $order = wc_get_order( $wc_order_id );
        if ( $order ) {
            $paid = $order->get_date_paid()
                 || $order->get_transaction_id()
                 || $order->get_meta( 'confirmationNumber' );
            if ( $paid ) {
                error_log( sprintf(
                    'KiwiEvents: WC order %d was cancelled but carries a payment — tickets left VALID on purpose. Review this order manually.',
                    (int) $wc_order_id
                ) );
                return;
            }
        }

        $orders_table  = $wpdb->prefix . 'ke_orders';
        $tickets_table = $wpdb->prefix . 'ke_tickets';

        $ke_orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$orders_table} WHERE wc_order_id = %d",
            (int) $wc_order_id
        ) );
        if ( empty( $ke_orders ) ) {
            return;
        }

        $tickets_handler = new KE_Tickets();
        $orders_handler  = new KE_Orders();

        foreach ( $ke_orders as $ke_order ) {
            $tickets = $wpdb->get_results( $wpdb->prepare(
                "SELECT id FROM {$tickets_table} WHERE order_id = %d AND status = 'valid'",
                $ke_order->id
            ) );
            foreach ( $tickets as $ticket ) {
                $tickets_handler->cancel( $ticket->id );
            }
            $orders_handler->update_status( $ke_order->id, 'cancelled' );
        }

        // Let a reinstated order mint tickets again. The guard flag is what
        // makes generation one-shot, so leaving it set would mean an order
        // that is cancelled and then paid (a late gateway confirmation, an
        // admin putting it back to processing) ends up with no valid tickets
        // at all. The tickets just cancelled stay cancelled; a new set is
        // issued, and the counter nets out correctly.
        //
        // HPOS-safe: the flag is written and read via the order object
        // (get_meta/update_meta_data), so it must be cleared the same way — a
        // postmeta delete would miss the wc_orders_meta row under HPOS and the
        // flag would survive, permanently blocking re-minting. Fall back to
        // postmeta only when the order object can't be loaded.
        if ( $order ) {
            $order->delete_meta_data( '_ke_tickets_generated' );
            $order->save();
        } else {
            delete_post_meta( (int) $wc_order_id, '_ke_tickets_generated' );
        }

        error_log( sprintf(
            'KiwiEvents: WC order %d cancelled — released %d KE order(s) and their tickets.',
            (int) $wc_order_id, count( $ke_orders )
        ) );
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

        $qr_generator = new KE_QR_Generator();

        foreach ( $ticket_codes as $code ) {
            $short      = '#' . strtoupper( substr( $code, 0, 8 ) );
            $qr_url     = $qr_generator->get_url( $code );
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
