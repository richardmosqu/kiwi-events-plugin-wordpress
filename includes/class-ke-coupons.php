<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Per-event discount coupons.
 *
 * Storage is a real WooCommerce coupon (`shop_coupon`). That is deliberate:
 * WooCommerce already owns the discount maths, the checkout coupon field,
 * the order totals, refunds and reports, and none of that should be
 * reimplemented for money. KiwiEvents layers three things on top, as coupon
 * meta:
 *
 *   _ke_coupon        marks the coupon as ours (so this class never touches
 *                     a store-wide coupon someone made in WooCommerce)
 *   _ke_event_id      the single event whose tickets it discounts
 *   _ke_max_tickets   how many tickets in total may use it (0 = unlimited)
 *   _ke_used_tickets  the running counter behind that cap
 *
 * WooCommerce's own usage_limit counts ORDERS, which is not what an organizer
 * means by "this coupon is good for 50 tickets", hence the ticket counter.
 *
 * Scope rule: a KiwiEvents coupon is valid only when EVERY paid line in the
 * cart belongs to its event. Without that, a "fixed amount off the order"
 * coupon for event A would quietly discount event B's tickets in a mixed
 * cart — an organizer paying for someone else's discount.
 *
 * Nothing here ever throws. A filter that throws can take the checkout down
 * if any caller runs it outside a try/catch; rejections are returned as
 * false and the message is supplied through `woocommerce_coupon_error`.
 */
class KE_Coupons {

    const META_FLAG         = '_ke_coupon';
    const META_EVENT        = '_ke_event_id';
    const META_MAX_TICKETS  = '_ke_max_tickets';
    const META_USED_TICKETS = '_ke_used_tickets';

    /** Written on the WC order so counting is idempotent in both directions. */
    const ORDER_META_COUNTED = '_ke_coupon_tickets_counted';

    /** Discount types offered, mapped to WooCommerce's own. */
    const TYPES = array(
        'percent'       => 'Percentage off each ticket',
        'fixed_product' => 'Fixed amount off each ticket',
        'fixed_cart'    => 'Fixed amount off the order',
    );

    /** Per-request rejection reasons, keyed by coupon code. */
    private $rejections = array();

    public function init() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        // Whole-coupon validity: event scope + ticket cap.
        add_filter( 'woocommerce_coupon_is_valid', array( $this, 'filter_coupon_is_valid' ), 10, 3 );
        // Our own message instead of WooCommerce's generic "Coupon is not valid."
        add_filter( 'woocommerce_coupon_error', array( $this, 'filter_coupon_error' ), 10, 3 );
        // Which lines a percentage / per-ticket discount may touch.
        add_filter( 'woocommerce_coupon_is_valid_for_product', array( $this, 'filter_valid_for_product' ), 10, 4 );

        // Ticket counter. Same hook set the ticket generator uses, so every
        // gateway flow (sync, async, manual completion) is covered.
        add_action( 'woocommerce_payment_complete',        array( $this, 'count_order' ) );
        add_action( 'woocommerce_order_status_processing', array( $this, 'count_order' ) );
        add_action( 'woocommerce_order_status_completed',  array( $this, 'count_order' ) );
        add_action( 'woocommerce_order_status_refunded',   array( $this, 'uncount_order' ) );
        add_action( 'woocommerce_order_status_cancelled',  array( $this, 'uncount_order_if_unpaid' ) );
        add_action( 'woocommerce_order_status_failed',     array( $this, 'uncount_order_if_unpaid' ) );
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Reading a coupon
     * ────────────────────────────────────────────────────────────────── */

    public static function is_ke_coupon( $coupon ) {
        if ( ! $coupon instanceof WC_Coupon ) return false;
        $id = (int) $coupon->get_id();
        if ( $id <= 0 ) return false;
        return get_post_meta( $id, self::META_FLAG, true ) === '1';
    }

    public static function event_id( $coupon ) {
        if ( ! $coupon instanceof WC_Coupon ) return 0;
        return (int) get_post_meta( (int) $coupon->get_id(), self::META_EVENT, true );
    }

    public static function max_tickets( $coupon ) {
        if ( ! $coupon instanceof WC_Coupon ) return 0;
        return max( 0, (int) get_post_meta( (int) $coupon->get_id(), self::META_MAX_TICKETS, true ) );
    }

    public static function used_tickets( $coupon ) {
        if ( ! $coupon instanceof WC_Coupon ) return 0;
        return max( 0, (int) get_post_meta( (int) $coupon->get_id(), self::META_USED_TICKETS, true ) );
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Validation
     * ────────────────────────────────────────────────────────────────── */

    /**
     * @param bool             $valid
     * @param WC_Coupon        $coupon
     * @param WC_Discounts|null $discounts
     */
    public function filter_coupon_is_valid( $valid, $coupon, $discounts = null ) {
        if ( ! $valid || ! self::is_ke_coupon( $coupon ) ) {
            return $valid;
        }
        $reason = $this->rejection_reason( $coupon, $discounts );
        if ( $reason === null ) {
            return true;
        }
        $this->rejections[ strtolower( (string) $coupon->get_code() ) ] = $reason;
        return false;
    }

    /**
     * Replace WooCommerce's generic wording with the real reason.
     *
     * Two codes matter here. 100 is "a filter said no", i.e. our own
     * rejection, whose message we recorded. 109 is WooCommerce's
     * "not applicable to selected products", thrown BEFORE our filter runs
     * when no line in the cart matches the coupon's event — so the reason is
     * known without having recorded anything.
     */
    public function filter_coupon_error( $message, $code, $coupon = null ) {
        if ( ! $coupon instanceof WC_Coupon || ! self::is_ke_coupon( $coupon ) ) {
            return $message;
        }
        $code = (int) $code;
        if ( $code === 100 ) {
            $key = strtolower( (string) $coupon->get_code() );
            return isset( $this->rejections[ $key ] ) ? $this->rejections[ $key ] : $message;
        }
        if ( $code === 109 ) {
            return self::wrong_event_message( $coupon );
        }
        return $message;
    }

    /** "This coupon only works on tickets for Furia Fest." */
    private static function wrong_event_message( $coupon ) {
        $name = get_the_title( self::event_id( $coupon ) );
        if ( $name === '' ) {
            $name = __( 'its event', 'kiwi-events' );
        }
        return sprintf(
            /* translators: %s: event name */
            __( 'This coupon only works on tickets for %s.', 'kiwi-events' ),
            $name
        );
    }

    /** null = the coupon may be applied; a string = why it may not. */
    private function rejection_reason( $coupon, $discounts ) {
        $event_id = self::event_id( $coupon );
        if ( $event_id <= 0 ) {
            return __( 'This coupon is not linked to an event.', 'kiwi-events' );
        }
        $event_name = get_the_title( $event_id );
        if ( $event_name === '' ) {
            $event_name = __( 'its event', 'kiwi-events' );
        }

        $items   = ( $discounts && method_exists( $discounts, 'get_items' ) ) ? (array) $discounts->get_items() : array();
        $tickets = 0;
        $foreign = 0;
        foreach ( $items as $item ) {
            $item_event = self::item_event_id( $item );
            if ( $item_event === $event_id ) {
                $tickets += self::item_ticket_count( $item );
            } else {
                $foreign++;
            }
        }

        if ( $tickets < 1 ) {
            return self::wrong_event_message( $coupon );
        }
        if ( $foreign > 0 ) {
            return sprintf(
                /* translators: %s: event name */
                __( 'This coupon only works on tickets for %s. Remove the other items from your cart to use it.', 'kiwi-events' ),
                $event_name
            );
        }

        $max = self::max_tickets( $coupon );
        if ( $max > 0 ) {
            $left = $max - self::used_tickets( $coupon );
            if ( $left < 1 ) {
                return __( 'This coupon has already been used for all the tickets it covers.', 'kiwi-events' );
            }
            if ( $tickets > $left ) {
                return sprintf(
                    /* translators: %d: number of tickets still covered */
                    _n(
                        'This coupon covers only %d more ticket. Reduce the tickets in your cart to use it.',
                        'This coupon covers only %d more tickets. Reduce the tickets in your cart to use it.',
                        $left,
                        'kiwi-events'
                    ),
                    $left
                );
            }
        }

        return null;
    }

    /**
     * Which lines a percentage / per-ticket discount is allowed to touch.
     * A "fixed amount off the order" coupon does not come through here —
     * WooCommerce treats it as cart-level — which is exactly why
     * rejection_reason() refuses a cart holding anything else.
     */
    public function filter_valid_for_product( $valid, $product, $coupon, $values ) {
        if ( ! self::is_ke_coupon( $coupon ) ) {
            return $valid;
        }
        $event_id = self::event_id( $coupon );
        if ( $event_id <= 0 ) {
            return false;
        }
        $item_event = 0;
        if ( is_array( $values ) && ! empty( $values['ke_event_id'] ) ) {
            $item_event = (int) $values['ke_event_id'];
        }
        if ( $item_event <= 0 && $product instanceof WC_Product ) {
            $item_event = (int) get_post_meta( $product->get_id(), '_ke_event_id', true );
        }
        return $item_event === $event_id;
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Item helpers — a WC_Discounts item wraps either a cart line (array)
     * or an order line (WC_Order_Item_Product).
     * ────────────────────────────────────────────────────────────────── */

    public static function item_event_id( $item ) {
        if ( ! is_object( $item ) ) return 0;
        $object = isset( $item->object ) ? $item->object : null;

        if ( is_array( $object ) && ! empty( $object['ke_event_id'] ) ) {
            return (int) $object['ke_event_id'];
        }
        if ( is_object( $object ) && method_exists( $object, 'get_meta' ) ) {
            $eid = (int) $object->get_meta( '_ke_event_id' );
            if ( $eid > 0 ) return $eid;
        }
        if ( ! empty( $item->product ) && $item->product instanceof WC_Product ) {
            return (int) get_post_meta( $item->product->get_id(), '_ke_event_id', true );
        }
        return 0;
    }

    /**
     * Tickets represented by one line. The attendee blob is authoritative
     * when present — quantity and attendee count drifting apart is the exact
     * shape of the multi-ticket/one-QR bug — and quantity is the fallback.
     */
    public static function item_ticket_count( $item ) {
        if ( ! is_object( $item ) ) return 0;
        $object = isset( $item->object ) ? $item->object : null;
        if ( is_array( $object ) && ! empty( $object['ke_attendees'] ) && is_array( $object['ke_attendees'] ) ) {
            return count( $object['ke_attendees'] );
        }
        return max( 0, (int) ( $item->quantity ?? 0 ) );
    }

    /* ─────────────────────────────────────────────────────────────────────
     * Ticket counter
     * ────────────────────────────────────────────────────────────────── */

    /** Tickets in this order that belong to the coupon's event. */
    private static function order_ticket_count( $order, $event_id ) {
        $n = 0;
        foreach ( $order->get_items() as $item ) {
            if ( (int) $item->get_meta( '_ke_event_id' ) === (int) $event_id ) {
                $n += max( 0, (int) $item->get_quantity() );
            }
        }
        return $n;
    }

    public function count_order( $wc_order_id ) {
        $order = wc_get_order( $wc_order_id );
        if ( ! $order ) return;
        // Already counted for this order — nothing to do. The flag is the
        // record of what was added, so uncounting can reverse it exactly.
        if ( $order->get_meta( self::ORDER_META_COUNTED ) !== '' ) return;

        $counted = array();
        foreach ( (array) $order->get_coupon_codes() as $code ) {
            $coupon = new WC_Coupon( $code );
            if ( ! self::is_ke_coupon( $coupon ) ) continue;
            $event_id = self::event_id( $coupon );
            $tickets  = self::order_ticket_count( $order, $event_id );
            if ( $tickets < 1 ) continue;
            update_post_meta(
                $coupon->get_id(),
                self::META_USED_TICKETS,
                self::used_tickets( $coupon ) + $tickets
            );
            $counted[ (string) $coupon->get_id() ] = $tickets;
        }
        if ( ! empty( $counted ) ) {
            $order->update_meta_data( self::ORDER_META_COUNTED, wp_json_encode( $counted ) );
            $order->save();
        }
    }

    public function uncount_order( $wc_order_id ) {
        $order = wc_get_order( $wc_order_id );
        if ( ! $order ) return;
        $raw = $order->get_meta( self::ORDER_META_COUNTED );
        if ( $raw === '' ) return;

        $counted = json_decode( (string) $raw, true );
        if ( is_array( $counted ) ) {
            foreach ( $counted as $coupon_id => $tickets ) {
                $coupon_id = (int) $coupon_id;
                $current   = max( 0, (int) get_post_meta( $coupon_id, self::META_USED_TICKETS, true ) );
                update_post_meta( $coupon_id, self::META_USED_TICKETS, max( 0, $current - (int) $tickets ) );
            }
        }
        $order->delete_meta_data( self::ORDER_META_COUNTED );
        $order->save();
    }

    /**
     * A cancelled or failed order only gives its tickets back when it was
     * never actually paid. The Yappy gateway cancels already-paid orders on a
     * late callback; handing the coupon budget back there would let those
     * tickets be discounted twice.
     */
    public function uncount_order_if_unpaid( $wc_order_id ) {
        $order = wc_get_order( $wc_order_id );
        if ( ! $order ) return;
        if ( $order->get_date_paid() || $order->get_transaction_id() ) return;
        $this->uncount_order( $wc_order_id );
    }

    /* ─────────────────────────────────────────────────────────────────────
     * CRUD for the admin screen
     * ────────────────────────────────────────────────────────────────── */

    /** Every KiwiEvents coupon, newest first. */
    public static function all() {
        $posts = get_posts( array(
            'post_type'      => 'shop_coupon',
            'post_status'    => array( 'publish', 'draft' ),
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_key'       => self::META_FLAG,
            'meta_value'     => '1',
        ) );
        $out = array();
        foreach ( $posts as $post ) {
            $row = self::get( $post->ID );
            if ( $row ) $out[] = $row;
        }
        return $out;
    }

    /** One coupon as a flat array for the form/list, or null. */
    public static function get( $coupon_id ) {
        $coupon_id = absint( $coupon_id );
        if ( $coupon_id <= 0 ) return null;
        $post = get_post( $coupon_id );
        if ( ! $post || $post->post_type !== 'shop_coupon' ) return null;

        $coupon   = new WC_Coupon( $coupon_id );
        $event_id = (int) get_post_meta( $coupon_id, self::META_EVENT, true );
        $expires  = $coupon->get_date_expires();
        $max      = max( 0, (int) get_post_meta( $coupon_id, self::META_MAX_TICKETS, true ) );
        $used     = max( 0, (int) get_post_meta( $coupon_id, self::META_USED_TICKETS, true ) );

        return array(
            'id'              => $coupon_id,
            'code'            => $coupon->get_code(),
            'description'     => $coupon->get_description(),
            'discount_type'   => $coupon->get_discount_type(),
            'amount'          => (float) $coupon->get_amount(),
            'event_id'        => $event_id,
            'event_title'     => $event_id ? get_the_title( $event_id ) : '',
            'expires'         => $expires ? $expires->date_i18n( 'Y-m-d' ) : '',
            'expired'         => (bool) ( $expires && time() > $expires->getTimestamp() ),
            'max_tickets'     => $max,
            'used_tickets'    => $used,
            'exhausted'       => (bool) ( $max > 0 && $used >= $max ),
            'limit_per_user'  => (int) $coupon->get_usage_limit_per_user(),
            'minimum_amount'  => (float) $coupon->get_minimum_amount(),
            'active'          => ( $post->post_status === 'publish' ),
            'usage_count'     => (int) $coupon->get_usage_count(),
        );
    }

    /**
     * Create or update. Returns the coupon id, or WP_Error with a message
     * fit to show the operator.
     *
     * @param array $data code, event_id, discount_type, amount, expires,
     *                    max_tickets, limit_per_user, minimum_amount,
     *                    description, active, id (0 = create)
     */
    public static function save( array $data ) {
        if ( ! class_exists( 'WC_Coupon' ) ) {
            return new WP_Error( 'wc_missing', __( 'WooCommerce is not active, so coupons cannot be saved.', 'kiwi-events' ) );
        }

        $id   = absint( $data['id'] ?? 0 );
        $code = wc_format_coupon_code( sanitize_text_field( (string) ( $data['code'] ?? '' ) ) );
        if ( $code === '' ) {
            return new WP_Error( 'no_code', __( 'Enter a coupon code.', 'kiwi-events' ) );
        }

        $event_id = absint( $data['event_id'] ?? 0 );
        if ( $event_id <= 0 || get_post_type( $event_id ) !== 'ke_event' ) {
            return new WP_Error( 'no_event', __( 'Choose the event this coupon applies to.', 'kiwi-events' ) );
        }

        $type = sanitize_key( (string) ( $data['discount_type'] ?? '' ) );
        if ( ! isset( self::TYPES[ $type ] ) ) {
            return new WP_Error( 'bad_type', __( 'Choose a discount type.', 'kiwi-events' ) );
        }

        $amount = round( (float) ( $data['amount'] ?? 0 ), 2 );
        if ( $amount <= 0 ) {
            return new WP_Error( 'bad_amount', __( 'The discount must be greater than zero.', 'kiwi-events' ) );
        }
        if ( $type === 'percent' && $amount > 100 ) {
            return new WP_Error( 'bad_percent', __( 'A percentage discount cannot be more than 100.', 'kiwi-events' ) );
        }

        // The code must be free, or already belong to the coupon being edited.
        $existing = wc_get_coupon_id_by_code( $code );
        if ( $existing && (int) $existing !== $id ) {
            return new WP_Error( 'duplicate_code', __( 'That coupon code is already in use.', 'kiwi-events' ) );
        }

        $expires_raw = trim( (string) ( $data['expires'] ?? '' ) );
        if ( $expires_raw !== '' && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires_raw ) ) {
            return new WP_Error( 'bad_date', __( 'The expiry date is not a valid date.', 'kiwi-events' ) );
        }

        $coupon = $id > 0 ? new WC_Coupon( $id ) : new WC_Coupon();
        if ( $id > 0 && (int) $coupon->get_id() !== $id ) {
            return new WP_Error( 'not_found', __( 'That coupon no longer exists.', 'kiwi-events' ) );
        }

        $coupon->set_code( $code );
        $coupon->set_description( sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ) );
        $coupon->set_discount_type( $type );
        $coupon->set_amount( $amount );
        // End of day, so "expires on the 30th" works for the whole 30th.
        // A bare date would make WooCommerce stop it at 00:00 that morning.
        $coupon->set_date_expires( $expires_raw !== '' ? $expires_raw . ' 23:59:59' : null );
        $coupon->set_usage_limit_per_user( max( 0, (int) ( $data['limit_per_user'] ?? 0 ) ) );
        $coupon->set_minimum_amount( max( 0, round( (float) ( $data['minimum_amount'] ?? 0 ), 2 ) ) ?: '' );
        // Paused coupons stay as drafts: wc_get_coupon_id_by_code() only looks
        // at published ones, so a draft simply cannot be applied.
        $coupon->set_status( empty( $data['active'] ) ? 'draft' : 'publish' );
        $coupon->save();

        $coupon_id = (int) $coupon->get_id();
        if ( $coupon_id <= 0 ) {
            return new WP_Error( 'save_failed', __( 'The coupon could not be saved.', 'kiwi-events' ) );
        }

        update_post_meta( $coupon_id, self::META_FLAG, '1' );
        update_post_meta( $coupon_id, self::META_EVENT, $event_id );
        update_post_meta( $coupon_id, self::META_MAX_TICKETS, max( 0, (int) ( $data['max_tickets'] ?? 0 ) ) );
        if ( get_post_meta( $coupon_id, self::META_USED_TICKETS, true ) === '' ) {
            update_post_meta( $coupon_id, self::META_USED_TICKETS, 0 );
        }

        return $coupon_id;
    }

    /** Trash, never hard-delete: the usage history is worth keeping. */
    public static function delete( $coupon_id ) {
        $coupon_id = absint( $coupon_id );
        $post      = $coupon_id ? get_post( $coupon_id ) : null;
        if ( ! $post || $post->post_type !== 'shop_coupon' ) {
            return new WP_Error( 'not_found', __( 'That coupon no longer exists.', 'kiwi-events' ) );
        }
        if ( get_post_meta( $coupon_id, self::META_FLAG, true ) !== '1' ) {
            return new WP_Error( 'not_ours', __( 'That coupon was not created here.', 'kiwi-events' ) );
        }
        return wp_trash_post( $coupon_id ) ? true : new WP_Error( 'delete_failed', __( 'The coupon could not be deleted.', 'kiwi-events' ) );
    }

    /** A suggested, unused code — e.g. FURIA-7K2QMD. */
    public static function suggest_code( $event_id = 0 ) {
        $prefix = '';
        if ( $event_id ) {
            $prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', mb_substr( (string) get_the_title( $event_id ), 0, 6 ) ) );
        }
        if ( $prefix === '' ) $prefix = 'KIWI';
        for ( $i = 0; $i < 8; $i++ ) {
            $code = $prefix . '-' . strtoupper( wp_generate_password( 6, false, false ) );
            if ( ! wc_get_coupon_id_by_code( $code ) ) return $code;
        }
        return $prefix . '-' . strtoupper( wp_generate_password( 10, false, false ) );
    }

    /** True when WooCommerce is set to show the coupon box at checkout. */
    public static function checkout_coupons_enabled() {
        return function_exists( 'wc_coupons_enabled' ) ? (bool) wc_coupons_enabled() : false;
    }
}
