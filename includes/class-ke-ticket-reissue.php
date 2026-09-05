<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Re-issue handler for the ticket-count incident. BUILT BUT DORMANT.
 *
 * Fixes the two "customer is owed tickets" shapes the audit surfaces:
 *   - under-minted ke_orders (paid N, fewer ticket rows), and
 *   - orphan orders (paid WooCommerce order with NO ke_orders row at all).
 *
 * Hard safety contract (every point enforced in code below):
 *   - PER ORDER ONLY. No bulk method, no "fix all", no scheduled/automatic run.
 *     Nothing here enumerates orders; every call names one order.
 *   - PLAN BEFORE WRITE. plan() computes exactly what would be created and
 *     writes nothing. execute() refuses unless it is handed confirm === true.
 *   - IDEMPOTENT. The mint count is recomputed at execute time as (owed −
 *     already issued), so a second run on the same order mints nothing.
 *   - REVERSIBLE. Every batch is recorded (ids of the rows it created) so
 *     reverse() can cancel exactly those rows and nothing else.
 *   - CAPACITY-RESPECTING. Never mints past the event's remaining capacity.
 *   - REFUSES the two populations that are normally the WRONG remedy —
 *     refunded orders and past events — unless the caller passes a deliberate,
 *     separate override for that specific reason on that specific order.
 *   - SILENT BY DEFAULT. No customer email unless the caller opts in with
 *     send_email === true for that order.
 *
 * It is intentionally left dormant until the production audit is reviewed:
 * firing it against the wrong population would issue tickets to already-refunded
 * buyers or to events that already happened.
 */
class KE_Ticket_Reissue {

	/** Non-autoloaded option holding the append-only reissue batch log (audit + reversal). */
	const LOG_OPTION = 'ke_reissue_log';

	/**
	 * Dry run. Resolves a single order and returns exactly what execute() would
	 * do — no writes. $args: [ 'type' => 'ke_order'|'orphan', 'id' => int ].
	 *
	 * @param array $args
	 * @return array plan (see resolve()); on hard error, ['ok'=>false,'error'=>msg].
	 */
	public static function plan( array $args ) {
		return self::resolve( $args, false );
	}

	/**
	 * Perform the re-issue. Writes ONLY when $args['confirm'] === true and every
	 * refusal is cleared (either not triggered, or cleared by an explicit
	 * per-reason override). Silent unless $args['send_email'] === true.
	 *
	 * $args: type, id, confirm(bool), override_refunded(bool), override_passed(bool),
	 *        send_email(bool).
	 *
	 * @param array $args
	 * @return array|WP_Error result on success; WP_Error on refusal/guard.
	 */
	public static function execute( array $args ) {
		if ( empty( $args['confirm'] ) || $args['confirm'] !== true ) {
			return new WP_Error( 'confirm_required', __( 'La re-emisión requiere confirmación explícita (confirm=true) tras revisar el plan.', 'kiwi-events' ), array( 'status' => 400 ) );
		}

		$plan = self::resolve( $args, true );
		if ( empty( $plan['ok'] ) ) {
			return new WP_Error( 'reissue_refused', $plan['error'] ?? __( 'No se puede re-emitir.', 'kiwi-events' ), array( 'status' => 409, 'plan' => $plan ) );
		}
		if ( (int) $plan['will_mint'] <= 0 ) {
			return new WP_Error( 'nothing_to_do', __( 'No hay boletos pendientes por emitir para este pedido (ya está al día).', 'kiwi-events' ), array( 'status' => 409, 'plan' => $plan ) );
		}

		global $wpdb;
		$lock = 'ke_reissue_' . ( $args['type'] === 'orphan' ? 'wc_' : 'ke_' ) . (int) $args['id'];
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) ) !== 1 ) {
			return new WP_Error( 'busy', __( 'Otro proceso está trabajando en este pedido. Intenta de nuevo.', 'kiwi-events' ), array( 'status' => 503 ) );
		}
		try {
			// Re-resolve inside the lock so a racing run can't double-mint.
			$plan = self::resolve( $args, true );
			if ( empty( $plan['ok'] ) || (int) $plan['will_mint'] <= 0 ) {
				return new WP_Error( 'nothing_to_do', __( 'No hay boletos pendientes por emitir (revalidado).', 'kiwi-events' ), array( 'status' => 409, 'plan' => $plan ) );
			}

			if ( ! class_exists( 'KE_Orders' ) )       require_once KE_PLUGIN_DIR . 'includes/class-ke-orders.php';
			if ( ! class_exists( 'KE_Tickets' ) )      require_once KE_PLUGIN_DIR . 'includes/class-ke-tickets.php';
			if ( ! class_exists( 'KE_Ticket_Types' ) ) require_once KE_PLUGIN_DIR . 'includes/class-ke-ticket-types.php';

			$ke_order_id = (int) $plan['ke_order_id'];

			// Orphan: create the missing ke_orders row first (this is what makes
			// the order visible to the rest of the system at all).
			if ( $plan['type'] === 'orphan' ) {
				$orders = new KE_Orders();
				$created = $orders->create( array(
					'event_id'        => (int) $plan['event_id'],
					'user_id'         => (int) $plan['user_id'],
					'buyer_name'      => $plan['buyer_name'],
					'buyer_email'     => $plan['buyer_email'],
					'total_amount'    => (float) $plan['amount'],
					'ticket_quantity' => (int) $plan['paid_qty'],
					'payment_method'  => 'woocommerce',
					'payment_status'  => 'completed',
					'wc_order_id'     => (int) $plan['wc_order_id'],
				) );
				if ( is_wp_error( $created ) || empty( $created['order_id'] ) ) {
					return new WP_Error( 'order_create_failed', __( 'No se pudo crear el registro de pedido.', 'kiwi-events' ), array( 'status' => 500 ) );
				}
				$ke_order_id = (int) $created['order_id'];
			}

			// Mint exactly will_mint tickets, using the plan's attendee slots.
			$tickets = new KE_Tickets();
			$ticket_ids = $tickets->generate(
				$ke_order_id,
				(int) $plan['event_id'],
				(int) $plan['ticket_type_id'],
				$plan['attendees_to_mint']
			);
			if ( is_wp_error( $ticket_ids ) ) {
				return new WP_Error( 'mint_failed', $ticket_ids->get_error_message(), array( 'status' => 500 ) );
			}
			$ticket_ids = array_map( 'intval', (array) $ticket_ids );

			$batch = self::log_batch( array(
				'type'        => $plan['type'],
				'ke_order_id' => $ke_order_id,
				'wc_order_id' => (int) $plan['wc_order_id'],
				'event_id'    => (int) $plan['event_id'],
				'ticket_ids'  => $ticket_ids,
				'minted'      => count( $ticket_ids ),
				'overrode'    => array(
					'refunded' => ! empty( $args['override_refunded'] ),
					'passed'   => ! empty( $args['override_passed'] ),
				),
				'admin_id'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			) );

			$emailed = false;
			if ( ! empty( $args['send_email'] ) && $args['send_email'] === true ) {
				if ( ! class_exists( 'KE_Email' ) ) require_once KE_PLUGIN_DIR . 'includes/class-ke-email.php';
				$sent = ( new KE_Email() )->send_ticket_email( $ke_order_id );
				$emailed = ! is_wp_error( $sent ) && $sent;
			}

			return array(
				'ok'          => true,
				'batch_id'    => $batch,
				'ke_order_id' => $ke_order_id,
				'minted'      => count( $ticket_ids ),
				'ticket_ids'  => $ticket_ids,
				'emailed'     => $emailed,
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Reverse a batch: cancel exactly the rows it created (soft cancel, which
	 * also returns their seats to the sold counter) and mark the batch reversed.
	 *
	 * @param string $batch_id
	 * @return array|WP_Error
	 */
	public static function reverse( $batch_id ) {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) || ! isset( $log[ $batch_id ] ) ) {
			return new WP_Error( 'batch_not_found', __( 'Lote de re-emisión no encontrado.', 'kiwi-events' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $log[ $batch_id ]['reversed_at'] ) ) {
			return new WP_Error( 'already_reversed', __( 'Este lote ya fue revertido.', 'kiwi-events' ), array( 'status' => 409 ) );
		}
		if ( ! class_exists( 'KE_Tickets' ) ) require_once KE_PLUGIN_DIR . 'includes/class-ke-tickets.php';
		$tickets   = new KE_Tickets();
		$cancelled = 0;
		foreach ( (array) $log[ $batch_id ]['ticket_ids'] as $tid ) {
			$r = $tickets->cancel( (int) $tid );
			if ( ! is_wp_error( $r ) ) {
				$cancelled++;
			}
		}
		$log[ $batch_id ]['reversed_at']    = current_time( 'mysql' );
		$log[ $batch_id ]['reversed_count'] = $cancelled;
		update_option( self::LOG_OPTION, $log, false );

		return array( 'ok' => true, 'batch_id' => $batch_id, 'cancelled' => $cancelled );
	}

	/**
	 * Resolve a target order into a full plan: facts, refusals, and exactly what
	 * would be minted. No writes. Shared by plan() and execute().
	 *
	 * @param array $args
	 * @param bool  $apply_overrides Whether caller's overrides clear refusals.
	 * @return array
	 */
	private static function resolve( array $args, $apply_overrides ) {
		$type = isset( $args['type'] ) ? (string) $args['type'] : '';
		$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
		if ( ! in_array( $type, array( 'ke_order', 'orphan' ), true ) || $id <= 0 ) {
			return array( 'ok' => false, 'error' => __( 'Objetivo inválido (type debe ser ke_order u orphan, con id).', 'kiwi-events' ) );
		}
		if ( ! class_exists( 'KE_Orders' ) )       require_once KE_PLUGIN_DIR . 'includes/class-ke-orders.php';
		if ( ! class_exists( 'KE_Tickets' ) )      require_once KE_PLUGIN_DIR . 'includes/class-ke-tickets.php';
		if ( ! class_exists( 'KE_Ticket_Types' ) ) require_once KE_PLUGIN_DIR . 'includes/class-ke-ticket-types.php';

		$now_ts = current_time( 'timestamp' );

		// ── Gather facts for both target types into a common shape. ──
		if ( $type === 'ke_order' ) {
			$orders = new KE_Orders();
			$order  = $orders->get( $id );
			if ( ! $order ) {
				return array( 'ok' => false, 'error' => __( 'Pedido (ke_order) no encontrado.', 'kiwi-events' ) );
			}
			$event_id    = (int) $order->event_id;
			$wc_order_id = (int) ( $order->wc_order_id ?? 0 );
			$paid_qty    = (int) $order->ticket_quantity;
			$buyer_name  = (string) $order->buyer_name;
			$buyer_email = (string) $order->buyer_email;
			$user_id     = (int) ( $order->user_id ?? 0 );
			$amount      = (float) $order->total_amount;
			$ke_order_id = $id;

			// Already-issued rows (non-cancelled) for this order.
			$existing_rows = ( new KE_Tickets() )->get_by_order( $id );
			$existing      = 0;
			$type_from_row = 0;
			foreach ( (array) $existing_rows as $row ) {
				if ( ( $row->status ?? '' ) !== 'cancelled' ) {
					$existing++;
				}
				if ( ! $type_from_row && ! empty( $row->ticket_type_id ) ) {
					$type_from_row = (int) $row->ticket_type_id;
				}
			}
		} else { // orphan
			if ( ! function_exists( 'wc_get_order' ) ) {
				return array( 'ok' => false, 'error' => __( 'WooCommerce no está disponible.', 'kiwi-events' ) );
			}
			$wc = wc_get_order( $id );
			if ( ! $wc ) {
				return array( 'ok' => false, 'error' => __( 'Pedido de WooCommerce no encontrado.', 'kiwi-events' ) );
			}
			// Guard: it must genuinely be an orphan (no ke_orders row yet).
			global $wpdb;
			$existing_ke = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}ke_orders WHERE wc_order_id = %d",
				$id
			) );
			if ( $existing_ke > 0 ) {
				return array( 'ok' => false, 'error' => __( 'Este pedido ya tiene registro (no es huérfano); usa type=ke_order.', 'kiwi-events' ) );
			}
			$wc_order_id = $id;
			$ke_order_id = 0;
			$buyer_name  = trim( $wc->get_billing_first_name() . ' ' . $wc->get_billing_last_name() );
			$buyer_email = (string) $wc->get_billing_email();
			$user_id     = (int) $wc->get_customer_id();
			$amount      = (float) $wc->get_total();
			$event_id    = 0;
			$paid_qty    = 0;
			$type_from_row = 0;
			$existing    = 0;
			foreach ( $wc->get_items() as $item ) {
				$eid = (int) $item->get_meta( '_ke_event_id' );
				if ( $eid <= 0 ) {
					continue;
				}
				if ( ! $event_id ) {
					$event_id = $eid;
				}
				if ( ! $type_from_row ) {
					$type_from_row = (int) $item->get_meta( '_ke_ticket_type_id' );
				}
				$paid_qty += (int) $item->get_quantity();
			}
			if ( $event_id <= 0 ) {
				return array( 'ok' => false, 'error' => __( 'El pedido no contiene ningún boleto (no aplica re-emisión).', 'kiwi-events' ) );
			}
		}

		// ── Ticket type resolution (needed to mint). ──
		$ticket_type_id = $type_from_row;
		if ( $ticket_type_id <= 0 && $wc_order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$wc = wc_get_order( $wc_order_id );
			if ( $wc ) {
				foreach ( $wc->get_items() as $item ) {
					$tt = (int) $item->get_meta( '_ke_ticket_type_id' );
					if ( $tt > 0 && (int) $item->get_meta( '_ke_event_id' ) === $event_id ) {
						$ticket_type_id = $tt;
						break;
					}
				}
			}
		}

		// ── Refund state (kept = paid − refunded). ──
		$refunded_qty = 0;
		$refund_full  = false;
		if ( $wc_order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$wc = wc_get_order( $wc_order_id );
			if ( $wc ) {
				$total       = (float) $wc->get_total();
				$refunded_amt = (float) $wc->get_total_refunded();
				$refund_full = ( $wc->get_status() === 'refunded' ) || ( $total > 0 && $refunded_amt >= $total - 0.01 );
				foreach ( $wc->get_items() as $item ) {
					if ( (int) $item->get_meta( '_ke_event_id' ) === $event_id ) {
						$refunded_qty += abs( (int) $wc->get_qty_refunded_for_item( $item->get_id() ) );
					}
				}
			}
		}
		$kept = max( 0, $paid_qty - $refunded_qty );

		// ── Event facts + capacity. ──
		$event_date = get_post_meta( $event_id, '_ke_event_date_start', true );
		$event_ts   = $event_date ? (int) strtotime( $event_date ) : 0;
		$passed     = ( $event_ts > 0 && $event_ts < $now_ts );
		$remaining  = $ticket_type_id > 0 ? (int) ( new KE_Ticket_Types() )->get_remaining( $ticket_type_id ) : 0;

		// ── Refusals. ──
		$refusals = array();
		if ( $refund_full || $refunded_qty > 0 ) {
			if ( ! ( $apply_overrides && ! empty( $args['override_refunded'] ) ) ) {
				$refusals['refunded'] = __( 'El pedido fue reembolsado (total o parcialmente). Re-emitir normalmente es incorrecto; requiere override_refunded explícito.', 'kiwi-events' );
			}
		}
		if ( $passed ) {
			if ( ! ( $apply_overrides && ! empty( $args['override_passed'] ) ) ) {
				$refusals['passed'] = __( 'El evento ya pasó. Re-emitir normalmente no es el remedio; requiere override_passed explícito.', 'kiwi-events' );
			}
		}
		if ( $ticket_type_id <= 0 ) {
			// Not overridable — we cannot safely mint without a type.
			$refusals['no_ticket_type'] = __( 'No se pudo resolver el tipo de boleto de este pedido; re-emisión automática no es posible.', 'kiwi-events' );
		}

		// ── What would be minted (idempotent, capacity-capped). ──
		$owed          = max( 0, $kept - $existing ); // tickets still owed after refunds and prior issuance
		$capacity_note = '';
		$will_mint     = $owed;
		if ( $ticket_type_id > 0 && $will_mint > $remaining ) {
			$will_mint     = $remaining;
			$capacity_note = sprintf(
				/* translators: 1: owed, 2: remaining capacity */
				__( 'Se deben %1$d pero solo quedan %2$d cupos; se emitirá hasta el aforo.', 'kiwi-events' ),
				$owed, $remaining
			);
		}

		$attendees_to_mint = self::build_attendees( $wc_order_id, $event_id, $existing, $will_mint, $buyer_name, $buyer_email );

		return array(
			'ok'                => empty( $refusals ),
			'type'              => $type,
			'target_id'         => $id,
			'ke_order_id'       => $ke_order_id,
			'wc_order_id'       => $wc_order_id,
			'event_id'          => $event_id,
			'event_title'       => get_the_title( $event_id ),
			'event_passed'      => $passed,
			'ticket_type_id'    => $ticket_type_id,
			'buyer_name'        => $buyer_name,
			'buyer_email'       => $buyer_email,
			'user_id'           => $user_id,
			'amount'            => $amount,
			'paid_qty'          => $paid_qty,
			'refunded_qty'      => $refunded_qty,
			'refund_full'       => $refund_full,
			'kept_qty'          => $kept,
			'already_issued'    => $existing,
			'owed'              => $owed,
			'remaining_capacity'=> $remaining,
			'will_mint'         => $will_mint,
			'attendees_to_mint' => $attendees_to_mint,
			'capacity_note'     => $capacity_note,
			'refusals'          => $refusals,
		);
	}

	/**
	 * Build the attendee slots for the tickets we will mint: prefer the order's
	 * preserved attendee blob (entries beyond what was already issued), fall
	 * back to the buyer's details. Always exactly $count entries.
	 */
	private static function build_attendees( $wc_order_id, $event_id, $already_issued, $count, $buyer_name, $buyer_email ) {
		$slots = array();
		if ( $count <= 0 ) {
			return $slots;
		}
		$blob = array();
		if ( $wc_order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$wc = wc_get_order( $wc_order_id );
			if ( $wc ) {
				// Prefer the Phase-3 preserved original, then the live blob.
				$raw = $wc->get_meta( '_ke_attendees_original' );
				if ( $raw ) {
					$dec = json_decode( is_array( $raw ) ? '' : (string) $raw, true );
					if ( isset( $dec['attendees'] ) && is_array( $dec['attendees'] ) ) {
						$blob = $dec['attendees'];
					}
				}
				if ( empty( $blob ) ) {
					foreach ( $wc->get_items() as $item ) {
						if ( (int) $item->get_meta( '_ke_event_id' ) !== (int) $event_id ) {
							continue;
						}
						$raw2 = $item->get_meta( '_ke_attendees' );
						if ( $raw2 ) {
							$dec2 = json_decode( is_array( $raw2 ) ? '' : (string) $raw2, true );
							if ( is_array( $dec2 ) ) {
								$blob = array_merge( $blob, $dec2 );
							}
						}
					}
				}
			}
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$idx = $already_issued + $i; // continue numbering past what already exists
			$a   = isset( $blob[ $idx ] ) && is_array( $blob[ $idx ] ) ? $blob[ $idx ] : array();
			$slots[] = array(
				'name'         => isset( $a['name'] ) && $a['name'] !== '' ? $a['name'] : $buyer_name,
				'email'        => isset( $a['email'] ) && $a['email'] !== '' ? $a['email'] : $buyer_email,
				'extra_fields' => isset( $a['extra_fields'] ) && is_array( $a['extra_fields'] ) ? $a['extra_fields'] : array(),
			);
		}
		return $slots;
	}

	/**
	 * Append a batch record to the reissue log (non-autoloaded) and return its id.
	 */
	private static function log_batch( array $entry ) {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$batch_id = 'rb_' . gmdate( 'Ymd_His' ) . '_' . substr( md5( wp_json_encode( $entry ) . microtime() ), 0, 8 );
		$entry['created_at'] = current_time( 'mysql' );
		$log[ $batch_id ]    = $entry;
		update_option( self::LOG_OPTION, $log, false );
		return $batch_id;
	}
}
