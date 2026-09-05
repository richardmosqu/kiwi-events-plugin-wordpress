<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Central authority for per-person ticket limits and per-order quantity bounds.
 *
 * Before 2.5.3 the "N tickets per person per event" rule lived only in
 * KE_Orders::can_purchase() and was enforced at exactly two points (free REST
 * checkout, and WooCommerce add-to-cart). The WooCommerce cart quantity stepper,
 * the Store API cart, raw ?add-to-cart URLs, and order creation all bypassed it —
 * so a buyer could raise the quantity after adding and walk out with more tickets
 * than allowed (and, because tickets are minted from the frozen attendee blob,
 * end up with fewer QR codes than units paid). This class is the single rule every
 * layer now calls, so the limit is evaluated identically everywhere.
 *
 * Two distinct limits live here:
 *   1. Per-person, per-event limit N  (_ke_event_max_tickets_per_person, with the
 *      ke_default_ticket_limit option as fallback). Counts tickets already issued
 *      to an email PLUS everything for that event currently sitting in the cart.
 *   2. Per-ticket-type, per-order bounds (min_per_order / max_per_order). Until now
 *      enforced only client-side in the checkout sheet.
 *
 * The arithmetic core (evaluate()) takes plain integers and is side-effect free so
 * it can be unit tested without WordPress; the wrapper methods gather the live
 * counts (DB tickets, cart contents) and build the translatable error.
 */
class KE_Ticket_Limits {

	/** Option holding the site-wide default when an event sets no explicit limit. */
	const DEFAULT_LIMIT_OPTION   = 'ke_default_ticket_limit';

	/** Hard fallback if the option itself is missing (matches the historical default). */
	const DEFAULT_LIMIT_FALLBACK = 10;

	/**
	 * Resolve the configured per-person limit for an event.
	 *
	 * Mirrors the historical rule in KE_Orders::can_purchase(): the event meta
	 * wins when positive, otherwise the site option, otherwise the hard fallback.
	 *
	 * @param int $event_id
	 * @return int  Always >= 1.
	 */
	public static function get_event_limit( $event_id ) {
		$max = (int) get_post_meta( (int) $event_id, '_ke_event_max_tickets_per_person', true );
		if ( $max <= 0 ) {
			$max = (int) get_option( self::DEFAULT_LIMIT_OPTION, self::DEFAULT_LIMIT_FALLBACK );
		}
		if ( $max <= 0 ) {
			$max = self::DEFAULT_LIMIT_FALLBACK;
		}
		return $max;
	}

	/**
	 * Count tickets already issued to an email for an event.
	 *
	 * Excludes only 'cancelled' rows, so 'valid' and 'used' (checked-in) both
	 * count — identical to KE_Orders::get_ticket_count_for_email(). Keyed on
	 * attendee_email exactly as the rest of the plugin resolves buyer identity
	 * (user ID is deliberately NOT used; guests and logged-in buyers are treated
	 * the same, matching existing behavior).
	 *
	 * @param int    $event_id
	 * @param string $email
	 * @return int
	 */
	public static function count_existing_for_email( $event_id, $email ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( $email === '' ) {
			return 0;
		}
		$tickets_table = $wpdb->prefix . 'ke_tickets';
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tickets_table}
			 WHERE event_id = %d AND attendee_email = %s AND status != 'cancelled'",
			(int) $event_id,
			$email
		) );
	}

	/**
	 * Sum ticket units already in the WooCommerce cart for an event.
	 *
	 * Only KE ticket lines are considered (those carrying ke_event_id). Lines
	 * are attributed to $email when it is given: a line matches if its
	 * ke_attendee_email equals $email OR the line carries no email at all (a
	 * raw/legacy add) — so a purchaser is never allowed to dodge their own
	 * running total by leaving the email blank on one line. When $email is empty
	 * every KE line for the event is counted (used by the whole-cart re-check).
	 *
	 * @param int         $event_id
	 * @param string      $email                  Purchaser email, or '' to count all lines.
	 * @param string|null $exclude_cart_item_key  Cart line to skip (the one being edited).
	 * @return int
	 */
	public static function count_in_cart( $event_id, $email = '', $exclude_cart_item_key = null ) {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return 0;
		}
		$event_id = (int) $event_id;
		$email    = sanitize_email( (string) $email );
		$total    = 0;

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( $exclude_cart_item_key !== null && $cart_item_key === $exclude_cart_item_key ) {
				continue;
			}
			if ( empty( $cart_item['ke_event_id'] ) || (int) $cart_item['ke_event_id'] !== $event_id ) {
				continue;
			}
			if ( $email !== '' ) {
				$line_email = isset( $cart_item['ke_attendee_email'] )
					? sanitize_email( (string) $cart_item['ke_attendee_email'] )
					: '';
				if ( $line_email !== '' && $line_email !== $email ) {
					continue;
				}
			}
			$total += isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
		}

		return $total;
	}

	/**
	 * Sum ticket units in the cart for one ticket TYPE (across all lines).
	 *
	 * The _ke_line_uid merge fix makes every add its own cart line, so a
	 * per-order rule keyed on a ticket type (max_per_order) can no longer read a
	 * single line and assume it is the buyer's whole intent — it must aggregate.
	 *
	 * @param int         $ticket_type_id
	 * @param string|null $exclude_cart_item_key
	 * @return int
	 */
	public static function count_in_cart_for_type( $ticket_type_id, $exclude_cart_item_key = null ) {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return 0;
		}
		$ticket_type_id = (int) $ticket_type_id;
		$total = 0;
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( $exclude_cart_item_key !== null && $cart_item_key === $exclude_cart_item_key ) {
				continue;
			}
			if ( empty( $cart_item['ke_ticket_type_id'] ) || (int) $cart_item['ke_ticket_type_id'] !== $ticket_type_id ) {
				continue;
			}
			$total += isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
		}
		return $total;
	}

	/**
	 * Enforce a ticket type's max_per_order as a per-ORDER (whole-cart) rule:
	 * existing cart lines of this type + the requested units must not exceed
	 * max_per_order. Pass $requested = 0 with no exclusion for a whole-cart
	 * re-check. No cap (max_per_order <= 0) always passes.
	 *
	 * @param object      $ticket_type
	 * @param int         $requested
	 * @param string|null $exclude_cart_item_key
	 * @return true|WP_Error
	 */
	public static function check_type_order_max( $ticket_type, $requested, $exclude_cart_item_key = null ) {
		$max = isset( $ticket_type->max_per_order ) ? (int) $ticket_type->max_per_order : 0;
		if ( $max <= 0 ) {
			return true;
		}
		$type_id = (int) ( $ticket_type->id ?? 0 );
		$total   = self::count_in_cart_for_type( $type_id, $exclude_cart_item_key ) + max( 0, (int) $requested );
		if ( $total > $max ) {
			$name = ( isset( $ticket_type->name ) && $ticket_type->name !== '' ) ? $ticket_type->name : __( 'este boleto', 'kiwi-events' );
			return new WP_Error(
				'above_max_per_order',
				sprintf(
					/* translators: 1: maximum per order, 2: ticket type name. */
					_n(
						'Solo puedes comprar %1$d boleto de «%2$s» por pedido.',
						'Solo puedes comprar %1$d boletos de «%2$s» por pedido.',
						$max,
						'kiwi-events'
					),
					$max,
					$name
				),
				array( 'status' => 400, 'max' => $max )
			);
		}
		return true;
	}

	/**
	 * Collect every per-person and per-type limit violation for the CURRENT
	 * cart, as buyer-facing message strings. Shared by the classic
	 * check_cart_items backstop and the Store API cart-errors channel so both
	 * enforce identically. Deduped: one per-person message per event, one
	 * per-type message per ticket type.
	 *
	 * @return string[]
	 */
	public static function collect_cart_violations() {
		$errors = array();
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return $errors;
		}

		$pairs = array();
		$types = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['ke_event_id'] ) ) {
				continue;
			}
			$event_id = (int) $cart_item['ke_event_id'];
			$email    = isset( $cart_item['ke_attendee_email'] ) ? sanitize_email( (string) $cart_item['ke_attendee_email'] ) : '';
			$pairs[ $event_id . '|' . $email ] = array( 'event_id' => $event_id, 'email' => $email );
			$tid = isset( $cart_item['ke_ticket_type_id'] ) ? (int) $cart_item['ke_ticket_type_id'] : 0;
			if ( $tid > 0 ) {
				$types[ $tid ] = true;
			}
		}

		// Per-person, per-event.
		$seen_event = array();
		foreach ( $pairs as $pair ) {
			$can = self::can_user_take( $pair['event_id'], $pair['email'], 0, 'cart' );
			if ( is_wp_error( $can ) && empty( $seen_event[ $pair['event_id'] ] ) ) {
				$seen_event[ $pair['event_id'] ] = true;
				$errors[] = $can->get_error_message();
			}
		}

		// Per-ticket-type max_per_order, aggregated across lines.
		if ( ! empty( $types ) ) {
			$tt_handler = new KE_Ticket_Types();
			foreach ( array_keys( $types ) as $tid ) {
				$tt = $tt_handler->get( $tid );
				if ( ! $tt ) {
					continue;
				}
				$chk = self::check_type_order_max( $tt, 0, null );
				if ( is_wp_error( $chk ) ) {
					$errors[] = $chk->get_error_message();
				}
			}
		}

		return $errors;
	}

	/**
	 * Pure decision core. No WordPress, no side effects — unit-testable.
	 *
	 * @param int $limit      Per-person limit N (>= 1).
	 * @param int $existing   Tickets already issued to this identity.
	 * @param int $in_cart    Ticket units already reserved in the cart for this event.
	 * @param int $requested  Additional units being requested now.
	 * @return array{allowed:bool,limit:int,already:int,requested:int,would_be:int,remaining:int}
	 */
	public static function evaluate( $limit, $existing, $in_cart, $requested ) {
		$limit     = max( 1, (int) $limit );
		$already   = max( 0, (int) $existing ) + max( 0, (int) $in_cart );
		$requested = max( 0, (int) $requested );
		$would_be  = $already + $requested;

		return array(
			'allowed'   => $would_be <= $limit,
			'limit'     => $limit,
			'already'   => $already,
			'requested' => $requested,
			'would_be'  => $would_be,
			'remaining' => max( 0, $limit - $already ),
		);
	}

	/**
	 * THE central rule. Can this identity take $requested_qty more units of this
	 * event, given what it already holds (issued) and what it already has in the
	 * cart for the same event?
	 *
	 * Callers by context:
	 *  - 'add'    add-to-cart: the new line is not in the cart yet, $requested_qty
	 *             is the amount being added, $exclude_cart_item_key is null.
	 *  - 'update' cart quantity update: WooCommerce passes the NEW total for the
	 *             line; pass that as $requested_qty and the line's own key as
	 *             $exclude_cart_item_key so its old quantity is not double-counted.
	 *  - 'cart'   whole-cart re-check (woocommerce_check_cart_items / Store API):
	 *             pass $requested_qty = 0 and no exclusion; the method then just
	 *             asserts existing + everything-in-cart <= N.
	 *  - 'checkout' server-side checkout guard (free path / REST).
	 *
	 * @param int         $event_id
	 * @param string      $email
	 * @param int         $requested_qty
	 * @param string      $context
	 * @param string|null $exclude_cart_item_key
	 * @return true|WP_Error  true when allowed; WP_Error 'ticket_limit_exceeded' otherwise.
	 */
	public static function can_user_take( $event_id, $email, $requested_qty, $context = 'cart', $exclude_cart_item_key = null ) {
		$event_id = (int) $event_id;
		$limit    = self::get_event_limit( $event_id );
		$existing = self::count_existing_for_email( $event_id, $email );
		$in_cart  = self::count_in_cart( $event_id, $email, $exclude_cart_item_key );

		$result = self::evaluate( $limit, $existing, $in_cart, (int) $requested_qty );

		if ( $result['allowed'] ) {
			return true;
		}

		return new WP_Error(
			'ticket_limit_exceeded',
			self::limit_message( $event_id, $result['limit'], $result['already'] ),
			array(
				'status'    => 400,
				'limit'     => $result['limit'],
				'already'   => $result['already'],
				'remaining' => $result['remaining'],
				'context'   => (string) $context,
			)
		);
	}

	/**
	 * Build the buyer-facing limit message. Dynamic, Spanish, translatable,
	 * and states both the limit N and what the buyer already holds/reserves.
	 *
	 * @param int $event_id
	 * @param int $limit
	 * @param int $already
	 * @return string
	 */
	public static function limit_message( $event_id, $limit, $already ) {
		$title = get_the_title( $event_id );
		if ( $title === '' ) {
			$title = __( 'este evento', 'kiwi-events' );
		}

		if ( $already > 0 ) {
			return sprintf(
				/* translators: 1: limit N, 2: event title, 3: how many the buyer already holds or has in the cart. */
				_n(
					'Solo se permite %1$d boleto por persona para el evento «%2$s». Ya tienes %3$d.',
					'Solo se permiten %1$d boletos por persona para el evento «%2$s». Ya tienes %3$d.',
					$limit,
					'kiwi-events'
				),
				$limit,
				$title,
				$already
			);
		}

		return sprintf(
			/* translators: 1: limit N, 2: event title. */
			_n(
				'Solo se permite %1$d boleto por persona para el evento «%2$s».',
				'Solo se permiten %1$d boletos por persona para el evento «%2$s».',
				$limit,
				'kiwi-events'
			),
			$limit,
			$title
		);
	}

	/**
	 * How many tickets to mint for an order line (the generate_for_order safety
	 * net, A). Paid quantity is authoritative for HOW MANY tickets exist; the
	 * event per-person limit is the hard ceiling.
	 *
	 *   mint = min( paid_quantity, event_limit )
	 *
	 * No floor: a zero-quantity line mints zero (closes #6). The attendee blob
	 * decides only WHO — the caller pads it with buyer data when short and trims
	 * (preserving the original) when long. Pure — unit-testable.
	 *
	 * @param int $paid_qty  Quantity the buyer actually paid for.
	 * @param int $limit     Event per-person limit N.
	 * @return int
	 */
	public static function reconcile_mint_count( $paid_qty, $limit ) {
		return min( max( 0, (int) $paid_qty ), max( 0, (int) $limit ) );
	}

	/**
	 * Per-ticket-type, per-order quantity bounds (min_per_order / max_per_order).
	 *
	 * Requirement D: these were enforced only client-side (data-min/data-max on
	 * the sheet stepper), so a crafted REST checkout could pass any quantity.
	 * This is the server-side counterpart, called from every checkout path.
	 *
	 * @param object $ticket_type   Row from wp_ke_ticket_types (must expose ->name, ->min_per_order, ->max_per_order).
	 * @param int    $requested_qty
	 * @return true|WP_Error
	 */
	public static function check_order_bounds( $ticket_type, $requested_qty ) {
		$requested_qty = (int) $requested_qty;
		$name          = isset( $ticket_type->name ) && $ticket_type->name !== ''
			? $ticket_type->name
			: __( 'este boleto', 'kiwi-events' );

		if ( $requested_qty < 1 ) {
			return new WP_Error(
				'invalid_quantity',
				__( 'La cantidad de boletos debe ser al menos 1.', 'kiwi-events' ),
				array( 'status' => 400 )
			);
		}

		$min = isset( $ticket_type->min_per_order ) ? (int) $ticket_type->min_per_order : 1;
		$max = isset( $ticket_type->max_per_order ) ? (int) $ticket_type->max_per_order : 0;

		if ( $min > 0 && $requested_qty < $min ) {
			return new WP_Error(
				'below_min_per_order',
				sprintf(
					/* translators: 1: minimum per order, 2: ticket type name. */
					_n(
						'Debes comprar al menos %1$d boleto de «%2$s».',
						'Debes comprar al menos %1$d boletos de «%2$s».',
						$min,
						'kiwi-events'
					),
					$min,
					$name
				),
				array( 'status' => 400, 'min' => $min )
			);
		}

		if ( $max > 0 && $requested_qty > $max ) {
			return new WP_Error(
				'above_max_per_order',
				sprintf(
					/* translators: 1: maximum per order, 2: ticket type name. */
					_n(
						'Solo puedes comprar %1$d boleto de «%2$s» por pedido.',
						'Solo puedes comprar %1$d boletos de «%2$s» por pedido.',
						$max,
						'kiwi-events'
					),
					$max,
					$name
				),
				array( 'status' => 400, 'max' => $max )
			);
		}

		return true;
	}
}
