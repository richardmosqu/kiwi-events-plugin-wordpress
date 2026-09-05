<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ticket count audit — READ ONLY.
 *
 * Finds completed orders whose issued ticket rows do not match the paid
 * quantity, in BOTH directions:
 *   - under-minted (minted < paid): the Bug 2 symptom — a buyer paid for N and
 *     received fewer ticket rows (fewer QRs). Split by whether the event has
 *     already happened (a passed event can't be fixed by re-issue) and whether
 *     the order was later refunded (a refunded buyer is not a re-issue target).
 *   - over-minted (minted > paid): the old count($_ke_attendees) behavior and
 *     REST vector 3 — more tickets than paid, which can push an event past
 *     venue capacity (an oversell). Highest priority.
 *
 * SOURCE OF TRUTH: this reads the plugin's OWN custom tables (wp_ke_orders,
 * wp_ke_tickets), which are independent of WooCommerce order storage — so the
 * audit is unaffected by HPOS (custom order tables vs wp_posts). We still detect
 * and display the WooCommerce storage mode for context, cross-check the KE order
 * count against the live WooCommerce paid-order count so an incomplete source
 * table can't hide behind a clean-looking report, and refuse to render a green
 * "all clear" when the scan found nothing (that is a tooling signal, never a
 * clean bill of health).
 *
 * This tool ISSUES NOTHING, EMAILS NOTHING, and CHANGES NOTHING. The re-issue
 * action is DESIGNED here (reissue_contract()) but deliberately NOT built.
 *
 * Reached at wp-admin/admin.php?page=ke-ticket-audit (hidden submenu).
 */
class KE_Ticket_Audit {

	const PAGE_SLUG = 'ke-ticket-audit';

	/** Default scan window (days back from today) — bounds load on WordPress.com. */
	const DEFAULT_WINDOW_DAYS = 365;

	/** Hard row cap for the affected-rows result set. */
	const MAX_ROWS = 5000;

	public function init() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 99 );
		add_action( 'admin_post_ke_ticket_audit_csv', array( $this, 'export_csv' ) );
	}

	public function register_page() {
		add_submenu_page(
			null, // hidden — reach via admin.php?page=ke-ticket-audit
			__( 'Ticket Count Audit', 'kiwi-events' ),
			__( 'Ticket Count Audit', 'kiwi-events' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Run the audit. Pure read: no writes, no side effects.
	 *
	 * @param array $args { 'since' => Y-m-d|'', 'limit' => int }
	 * @return array See the keys assembled at the end of this method.
	 */
	public static function run( array $args = array() ) {
		global $wpdb;

		$orders_table  = $wpdb->prefix . 'ke_orders';
		$tickets_table = $wpdb->prefix . 'ke_tickets';
		$limit         = (int) ( $args['limit'] ?? self::MAX_ROWS );
		$limit         = max( 50, min( 20000, $limit ) );
		$since         = ! empty( $args['since'] ) ? sanitize_text_field( $args['since'] ) : '';

		// WooCommerce order storage mode — informational only (our query does
		// not touch WC order storage). 'unknown' when OrderUtil is unavailable.
		$storage_mode = 'unknown';
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$storage_mode = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'posts';
		}

		// Zero-guard denominator: total KE order rows of ANY status. If this is
		// 0 the source table is empty — a tooling/config signal, never "clean".
		$total_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders_table}" );

		// Cheap cross-check: how many paid orders does WooCommerce itself hold?
		// A large gap vs the KE order count means the source table is likely
		// incomplete. paginate=true runs a bounded COUNT, not a full fetch.
		$wc_paid_count = null;
		if ( function_exists( 'wc_get_orders' ) ) {
			$res = wc_get_orders( array(
				'limit'    => 1,
				'paginate' => true,
				'status'   => array( 'completed', 'processing' ),
				'return'   => 'ids',
			) );
			if ( is_object( $res ) && isset( $res->total ) ) {
				$wc_paid_count = (int) $res->total;
			}
		}

		// Completed orders in the scan window + their TOTAL ticket-row count
		// (any status — a cancelled row was still minted, so counting all rows
		// measures the minting bug, not later admin cancellations).
		$where  = "o.payment_status = 'completed'";
		$params = array();
		if ( $since !== '' ) {
			$where   .= ' AND o.created_at >= %s';
			$params[] = $since . ' 00:00:00';
		}

		$scanned_sql = "SELECT COUNT(*) FROM {$orders_table} o WHERE {$where}";
		$scanned     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $scanned_sql, $params ) )
			: $wpdb->get_var( $scanned_sql ) );

		$sql = "SELECT o.id, o.event_id, o.wc_order_id, o.order_number,
		               o.buyer_name, o.buyer_email, o.total_amount, o.payment_method,
		               o.payment_status, o.created_at,
		               COALESCE(o.ticket_quantity, 0) AS paid_qty,
		               COUNT(t.id) AS minted
		        FROM {$orders_table} o
		        LEFT JOIN {$tickets_table} t ON t.order_id = o.id
		        WHERE {$where}
		        GROUP BY o.id
		        HAVING minted <> paid_qty AND (paid_qty > 0 OR minted > 0)
		        ORDER BY o.created_at DESC
		        LIMIT %d";
		$q_params   = $params;
		$q_params[] = $limit;
		$affected   = $wpdb->get_results( $wpdb->prepare( $sql, $q_params ) );
		$affected   = is_array( $affected ) ? $affected : array();
		$cap_hit    = ( count( $affected ) >= $limit );

		$now_ts          = current_time( 'timestamp' );
		$rows            = array();
		$under_upcoming  = array();
		$under_passed    = array();
		$over            = array();
		$refunded        = array(); // fully refunded OR balanced by partial refund — not re-issue
		$by_event        = array();
		$event_ids       = array();
		$emails          = array();
		$tickets_missing = 0;
		$tickets_over    = 0;
		$revenue         = 0.0;
		$date_from       = null;
		$date_to         = null;

		foreach ( $affected as $o ) {
			$event_id   = (int) $o->event_id;
			$paid       = (int) $o->paid_qty;
			$minted     = (int) $o->minted;
			$event_date = get_post_meta( $event_id, '_ke_event_date_start', true );
			$event_ts   = $event_date ? (int) strtotime( $event_date ) : 0;
			$passed     = ( $event_ts > 0 && $event_ts < $now_ts );

			// Refund state from the live WC order (ke_orders.payment_status only
			// flips to 'refunded' when the refund hook fired and propagated; a
			// partial refund, or a refund that never reached ke_orders, does
			// not — so we read the authoritative WC order here). Scoped to THIS
			// ke_order's event, because one WC order becomes one ke_order PER
			// line item and a partial refund may touch only some lines.
			$refund    = self::refund_info( (int) $o->wc_order_id, $event_id );
			$line_full = ( $refund['qty'] > 0 && $refund['qty'] >= $paid ); // this line fully refunded
			$kept      = max( 0, $paid - $refund['qty'] );        // units the buyer still paid for
			$eff       = $kept - $minted;                         // correct delta = against what was kept
			$raw       = $paid - $minted;

			$row = array(
				'ke_order_id'    => (int) $o->id,
				'wc_order_id'    => (int) $o->wc_order_id,
				'order_number'   => (string) $o->order_number,
				'order_date'     => (string) $o->created_at,
				'event_id'       => $event_id,
				'event_title'    => get_the_title( $event_id ),
				'event_date'     => $event_date ? date( 'Y-m-d H:i', $event_ts ) : '',
				'event_passed'   => $passed,
				'paid_qty'       => $paid,
				'minted'         => $minted,
				'refunded_qty'   => $refund['qty'],
				'kept_qty'       => $kept,
				'delta'          => $eff,          // effective delta (against kept)
				'raw_delta'      => $raw,          // paid - minted, before refunds
				'direction'      => $eff > 0 ? 'under' : ( $eff < 0 ? 'over' : 'balanced' ),
				'refund_status'  => $refund['status_label'] . ( $refund['approximate'] ? ' (~)' : '' ),
				'refund_approx'  => $refund['approximate'],
				'refunded_amount'=> $refund['amount'],
				'email'          => (string) $o->buyer_email,
				'buyer'          => (string) $o->buyer_name,
				'payment'        => (string) $o->payment_method,
				'status'         => (string) $o->payment_status,
				'amount'         => (float) $o->total_amount,
				'cause'          => self::infer_cause( $refund['order'], $raw ),
			);
			$rows[] = $row;

			if ( $o->buyer_email ) {
				$emails[ strtolower( (string) $o->buyer_email ) ] = true;
			}
			$revenue += (float) $o->total_amount;
			$cts = (int) strtotime( (string) $o->created_at );
			if ( $cts > 0 ) {
				$date_from = ( $date_from === null ) ? $cts : min( $date_from, $cts );
				$date_to   = ( $date_to === null ) ? $cts : max( $date_to, $cts );
			}

			if ( ! isset( $by_event[ $event_id ] ) ) {
				$by_event[ $event_id ] = array(
					'event_id' => $event_id, 'title' => $row['event_title'],
					'orders'   => 0, 'missing' => 0, 'over' => 0,
				);
			}
			$by_event[ $event_id ]['orders']++;
			$event_ids[ $event_id ] = true;

			// Route. Fully refunded (whole order OR this line), or balanced by a
			// partial refund → the refunded bucket (never a re-issue or a
			// refund-owed candidate).
			if ( $refund['full'] || $line_full || $eff === 0 ) {
				$refunded[] = $row;
			} elseif ( $eff > 0 ) {
				$tickets_missing += $eff;
				$by_event[ $event_id ]['missing'] += $eff;
				if ( $passed ) {
					$under_passed[] = $row;
				} else {
					$under_upcoming[] = $row;
				}
			} else {
				$tickets_over += abs( $eff );
				$by_event[ $event_id ]['over'] += abs( $eff );
				$over[] = $row;
			}
		}

		// Oversell + capacity-unknown split. For every affected event, compare
		// the at-door count (valid + used, NOT cancelled) against configured
		// capacity. Events with no resolvable capacity go to their own section —
		// silently omitting them would make "0 oversold" misleading.
		$oversell         = array();
		$capacity_unknown = array();
		foreach ( array_keys( $event_ids ) as $event_id ) {
			$capacity = self::event_capacity( $event_id );
			$door     = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$tickets_table} WHERE event_id = %d AND status <> 'cancelled'",
				$event_id
			) );
			if ( $capacity <= 0 ) {
				$capacity_unknown[] = array(
					'event_id' => $event_id,
					'title'    => get_the_title( $event_id ),
					'door'     => $door,
				);
				continue;
			}
			$oversell[] = array(
				'event_id' => $event_id,
				'title'    => get_the_title( $event_id ),
				'door'     => $door,
				'capacity' => $capacity,
				'exceeded' => ( $door > $capacity ),
				'over_by'  => ( $door > $capacity ) ? ( $door - $capacity ) : 0,
			);
		}
		usort( $oversell, static function ( $a, $b ) {
			return ( $b['exceeded'] <=> $a['exceeded'] ) ?: ( $b['over_by'] <=> $a['over_by'] );
		} );

		// ── Orphan scan: paid WC orders with a ticket product but NO ke_orders
		// row at all (generation never fired / fatal mid-generation / order
		// placed while the plugin was inactive). The most severe case — paid,
		// received nothing, with no row to under-mint from. Read-only: a left
		// anti-join of paid ticket-bearing WC orders against ke_orders.wc_order_id.
		$orphan_capped      = false;
		$orphans            = self::find_orphans( $since, $limit, $now_ts, $orphan_capped );
		$orphans_actionable = array();
		$orphans_refunded   = array();
		$orphan_tickets     = 0;   // tickets paid for and never delivered (actionable only)
		$orphan_revenue     = 0.0;
		foreach ( $orphans as $orow ) {
			if ( $orow['email'] ) {
				$emails[ strtolower( (string) $orow['email'] ) ] = true;
			}
			$octs = $orow['order_date'] ? (int) strtotime( $orow['order_date'] ) : 0;
			if ( $octs > 0 ) {
				$date_from = ( $date_from === null ) ? $octs : min( $date_from, $octs );
				$date_to   = ( $date_to === null ) ? $octs : max( $date_to, $octs );
			}
			if ( $orow['actionable'] ) {
				$orphans_actionable[] = $orow;
				$orphan_tickets      += (int) $orow['paid_qty'];
				$orphan_revenue      += (float) $orow['amount'];
			} else {
				$orphans_refunded[] = $orow;
			}
		}

		return array(
			'storage_mode'     => $storage_mode,
			'total_orders'     => $total_orders,
			'wc_paid_count'    => $wc_paid_count,
			'scanned'          => $scanned,
			'scanned_since'    => $since !== '' ? $since : '(all history)',
			'limit'            => $limit,
			'cap_hit'          => $cap_hit,
			'orphans'          => $orphans,
			'orphans_actionable' => $orphans_actionable,
			'orphans_refunded' => $orphans_refunded,
			'orphan_capped'    => $orphan_capped,
			'rows'             => $rows,
			'under_upcoming'   => $under_upcoming,
			'under_passed'     => $under_passed,
			'over'             => $over,
			'refunded'         => $refunded,
			'by_event'         => array_values( $by_event ),
			'oversell'         => $oversell,
			'capacity_unknown' => $capacity_unknown,
			'totals'           => array(
				'orders'          => count( $rows ),
				'tickets_missing' => $tickets_missing,
				'tickets_over'    => $tickets_over,
				'customers'       => count( $emails ), // union of mismatch + orphan buyers
				'revenue'         => round( $revenue, 2 ),
				'orphan_orders'      => count( $orphans ),
				'orphan_actionable'  => count( $orphans_actionable ),
				'orphan_tickets'     => $orphan_tickets,
				'orphan_revenue'     => round( $orphan_revenue, 2 ),
				'date_from'       => $date_from ? date( 'Y-m-d', $date_from ) : '',
				'date_to'         => $date_to ? date( 'Y-m-d', $date_to ) : '',
			),
		);
	}

	/**
	 * Refund state for a WC order, SCOPED to one event's line item(s). Reads the
	 * authoritative WooCommerce order (CRUD, HPOS-safe).
	 *
	 * One WC order becomes one ke_orders row per line item, and ke_orders stores
	 * no order_item_id — only event_id. So we scope the refunded quantity to the
	 * WC line items whose _ke_event_id matches this ke_order's event, which is
	 * exact for the common multi-EVENT cart (each event on its own line). When a
	 * single WC order has MORE THAN ONE line item for the SAME event (e.g. two
	 * ticket types), event_id alone can't tell them apart, so we sum across those
	 * lines and set `approximate` — the caller flags the row for manual check
	 * rather than trusting the bucket. `full` is order-wide (a whole-order refund
	 * refunds every line); `qty` and the per-line-full test are event-scoped.
	 *
	 * @param int $wc_order_id
	 * @param int $event_id
	 * @return array{order:mixed,amount:float,qty:int,full:bool,status_label:string,approximate:bool}
	 */
	private static function refund_info( $wc_order_id, $event_id ) {
		$out = array( 'order' => null, 'amount' => 0.0, 'qty' => 0, 'full' => false, 'status_label' => 'none', 'approximate' => false );
		if ( $wc_order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return $out;
		}
		$order = wc_get_order( $wc_order_id );
		if ( ! $order ) {
			return $out;
		}
		$out['order']  = $order;
		$total         = (float) $order->get_total();
		$out['amount'] = (float) $order->get_total_refunded();
		$status        = $order->get_status();

		$qty            = 0; // refunded units on THIS event's line(s)
		$matched_lines  = 0; // KE line items for THIS event
		foreach ( $order->get_items() as $item_id => $item ) {
			$item_event = (int) $item->get_meta( '_ke_event_id' );
			if ( $item_event <= 0 || $item_event !== (int) $event_id ) {
				continue;
			}
			$matched_lines++;
			$qty += abs( (int) $order->get_qty_refunded_for_item( $item_id ) );
		}
		$out['qty']         = $qty;
		$out['approximate'] = ( $matched_lines > 1 ); // same event on multiple lines → can't attribute precisely

		$full = ( $status === 'refunded' ) || ( $total > 0 && $out['amount'] >= $total - 0.01 );
		$out['full'] = $full;
		if ( $full ) {
			$out['status_label'] = 'full';
		} elseif ( $qty > 0 || $out['amount'] > 0 ) {
			$out['status_label'] = 'partial';
		}
		return $out;
	}

	/**
	 * Orphan scan: paid WooCommerce orders that carry a ticket product but have
	 * NO corresponding ke_orders row. Read-only left anti-join — the "seen" set
	 * is DISTINCT ke_orders.wc_order_id; any paid ticket-bearing WC order not in
	 * it is an orphan (paid, received nothing). Enumerated via wc_get_orders
	 * (CRUD, HPOS-safe), bounded by the same date window and row cap; $capped is
	 * set when the cap is hit so the caller can warn about truncation.
	 *
	 * @param string $since  Y-m-d or ''.
	 * @param int    $limit
	 * @param int    $now_ts
	 * @param bool   $capped (out)
	 * @return array[] orphan rows
	 */
	private static function find_orphans( $since, $limit, $now_ts, &$capped ) {
		$rows   = array();
		$capped = false;
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $rows;
		}
		global $wpdb;
		$orders_table = $wpdb->prefix . 'ke_orders';

		// "Seen" WC order ids — those that DID create a ke_orders row.
		$seen = array();
		foreach ( (array) $wpdb->get_col( "SELECT DISTINCT wc_order_id FROM {$orders_table} WHERE wc_order_id > 0" ) as $id ) {
			$seen[ (int) $id ] = true;
		}

		$args = array(
			'status'  => array( 'completed', 'processing', 'refunded' ), // paid (refunded = paid-then-refunded)
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		);
		if ( $since !== '' ) {
			$args['date_created'] = '>=' . $since;
		}
		$wc_orders = wc_get_orders( $args );
		if ( ! is_array( $wc_orders ) ) {
			$wc_orders = array();
		}
		$capped = ( count( $wc_orders ) >= $limit );

		foreach ( $wc_orders as $order ) {
			if ( ! ( $order instanceof WC_Order ) ) {
				continue;
			}
			$wc_id = (int) $order->get_id();
			if ( isset( $seen[ $wc_id ] ) ) {
				continue; // has a ke_orders row — handled by the mismatch scan, not an orphan
			}
			// Does it carry a ticket product? Sum paid units across ticket lines.
			$event_id = 0;
			$paid_qty = 0;
			foreach ( $order->get_items() as $item ) {
				$eid = (int) $item->get_meta( '_ke_event_id' );
				if ( $eid <= 0 ) {
					continue;
				}
				if ( $event_id === 0 ) {
					$event_id = $eid;
				}
				$paid_qty += (int) $item->get_quantity();
			}
			if ( $event_id <= 0 ) {
				continue; // not a ticket order
			}

			$total           = (float) $order->get_total();
			$refunded_amount = (float) $order->get_total_refunded();
			$status          = $order->get_status();
			$full            = ( $status === 'refunded' ) || ( $total > 0 && $refunded_amount >= $total - 0.01 );
			$event_date      = get_post_meta( $event_id, '_ke_event_date_start', true );
			$event_ts        = $event_date ? (int) strtotime( $event_date ) : 0;

			$rows[] = array(
				'wc_order_id'   => $wc_id,
				'order_number'  => (string) $order->get_order_number(),
				'order_date'    => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
				'event_id'      => $event_id,
				'event_title'   => get_the_title( $event_id ),
				'event_date'    => $event_ts ? date( 'Y-m-d H:i', $event_ts ) : '',
				'event_passed'  => ( $event_ts > 0 && $event_ts < $now_ts ),
				'email'         => (string) $order->get_billing_email(),
				'status'        => $status,
				'refund_status' => $full ? 'full' : ( $refunded_amount > 0 ? 'partial' : 'none' ),
				'paid_qty'      => $paid_qty,
				'amount'        => $total,
				'actionable'    => ! $full, // fully refunded → made whole → not actionable
			);
		}
		return $rows;
	}

	/**
	 * Best-effort cause, labelled recorded (authoritative) vs inferred (guess).
	 *
	 * @param mixed $wc_order WC order object or null.
	 * @param int   $raw_delta paid - minted (pre-refund).
	 * @return string
	 */
	private static function infer_cause( $wc_order, $raw_delta ) {
		if ( $wc_order && is_object( $wc_order ) && method_exists( $wc_order, 'get_meta' ) ) {
			$recorded = $wc_order->get_meta( '_ke_order_needs_review' );
			if ( $recorded && ! is_array( $recorded ) ) {
				$decoded = json_decode( (string) $recorded, true );
				if ( is_array( $decoded ) && ! empty( $decoded['reasons'] ) ) {
					return 'recorded: ' . implode( ', ', array_map( 'sanitize_text_field', (array) $decoded['reasons'] ) );
				}
				return 'recorded: needs review';
			}
		}
		if ( $raw_delta < 0 ) {
			return 'inferred: over-mint (REST attendee/quantity mismatch)';
		}
		return 'inferred: under-mint (cart merge or stepper adjustment after add)';
	}

	/**
	 * Configured capacity for an event: the event-level cap when set, otherwise
	 * the sum of its limited ticket types' quantity_total. 0 = no resolvable cap
	 * → oversell cannot be assessed (caller routes to the "unknown" section).
	 *
	 * @param int $event_id
	 * @return int
	 */
	private static function event_capacity( $event_id ) {
		$event_cap = (int) get_post_meta( $event_id, '_ke_event_capacity', true );
		if ( $event_cap > 0 ) {
			return $event_cap;
		}
		global $wpdb;
		$types_table = $wpdb->prefix . 'ke_ticket_types';
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(quantity_total), 0) FROM {$types_table}
			 WHERE event_id = %d AND capacity_type = 'limited'
			   AND (is_archived IS NULL OR is_archived = 0)",
			$event_id
		) );
	}

	/**
	 * DESIGN ONLY — the re-issue action is intentionally NOT implemented.
	 * When built it must be idempotent, reversible, capacity-respecting,
	 * silent (no email unless a human triggers it), and scoped to recoverable
	 * cases (under-minted, event upcoming, not refunded).
	 *
	 * @return string
	 */
	public static function reissue_contract() {
		return __( 'La acción de re-emisión aún NO está construida. Cuando se implemente será: idempotente (solo emite hasta cubrir lo pagado y no reembolsado; una segunda ejecución no duplica), reversible (cada boleto re-emitido queda etiquetado para revertirse), respetuosa del aforo (nunca supera la capacidad del evento), silenciosa (no envía correo salvo que un humano lo pida), y limitada a casos recuperables (sub-emitidos, evento futuro, no reembolsados). Los eventos ya pasados son decisión de reembolso/cortesía; los pedidos reembolsados no reciben boletos.', 'kiwi-events' );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$default_since = date( 'Y-m-d', current_time( 'timestamp' ) - self::DEFAULT_WINDOW_DAYS * DAY_IN_SECONDS );
		$since = isset( $_GET['since'] ) ? sanitize_text_field( wp_unslash( $_GET['since'] ) ) : $default_since;
		$limit = isset( $_GET['limit'] ) ? max( 50, min( 20000, (int) $_GET['limit'] ) ) : self::MAX_ROWS;

		$r = self::run( array( 'since' => $since, 'limit' => $limit ) );
		$t = $r['totals'];

		echo '<div class="wrap"><h1>' . esc_html__( 'Ticket Count Audit', 'kiwi-events' ) . '</h1>';
		echo '<p style="color:#64748b;max-width:820px;">' . esc_html__( 'Read-only. Lists completed orders whose issued ticket rows do not match the paid quantity (accounting for refunds), in both directions. This tool issues nothing, emails nothing, and changes nothing.', 'kiwi-events' ) . '</p>';

		// ── Provenance banner: storage mode + scan totals + cross-check ──
		$scan_note = sprintf(
			/* translators: 1: WC storage mode, 2: KE order rows, 3: WC paid orders, 4: scanned count, 5: since */
			esc_html__( 'WooCommerce order storage: %1$s (informational — this audit reads the plugin\'s own ke_orders/ke_tickets tables, which HPOS does not affect). KE order rows (all statuses): %2$s. WooCommerce paid orders: %3$s. Completed orders scanned in window: %4$s. Window: since %5$s.', 'kiwi-events' ),
			'<strong>' . esc_html( strtoupper( $r['storage_mode'] ) ) . '</strong>',
			'<strong>' . (int) $r['total_orders'] . '</strong>',
			$r['wc_paid_count'] === null ? esc_html__( 'unavailable', 'kiwi-events' ) : '<strong>' . (int) $r['wc_paid_count'] . '</strong>',
			'<strong>' . (int) $r['scanned'] . '</strong>',
			'<strong>' . esc_html( $r['scanned_since'] ) . '</strong>'
		);
		echo '<div style="padding:10px 14px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;margin:12px 0;font-size:13px;color:#3730a3;">' . wp_kses_post( $scan_note ) . '</div>';

		// ── Hard guards: never let a silent-zero read as "clean" ──
		if ( (int) $r['total_orders'] === 0 ) {
			echo '<div style="padding:12px 16px;background:#fee2e2;border:1px solid #ef4444;border-radius:8px;color:#7f1d1d;font-weight:600;">'
			   . esc_html__( '⚠️ TOOLING FAILURE: the ke_orders table is empty — the audit found no orders of any kind. This is NOT a clean result. Verify the plugin tables exist and are populated before drawing any conclusion.', 'kiwi-events' )
			   . '</div></div>';
			return;
		}
		if ( $r['wc_paid_count'] !== null && (int) $r['wc_paid_count'] > 0 && (int) $r['total_orders'] < (int) $r['wc_paid_count'] * 0.5 ) {
			echo '<div style="padding:12px 16px;background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;color:#78350f;font-weight:600;margin:8px 0;">'
			   . esc_html( sprintf(
				   /* translators: 1: KE rows, 2: WC paid orders */
				   __( '⚠️ The KE order table (%1$d rows) holds far fewer orders than WooCommerce reports paid (%2$d). The audit source may be incomplete — investigate before trusting these totals.', 'kiwi-events' ),
				   (int) $r['total_orders'], (int) $r['wc_paid_count']
			   ) ) . '</div>';
		}
		if ( (int) $r['scanned'] === 0 ) {
			echo '<div style="padding:12px 16px;background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;color:#78350f;font-weight:600;">'
			   . esc_html__( 'No completed orders fall inside this window. This is not a clean result — widen the "Since" date to cover the incident period.', 'kiwi-events' )
			   . '</div>';
		}

		// ── Controls ──
		echo '<form method="get" style="margin:14px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		echo '<label>' . esc_html__( 'Since', 'kiwi-events' ) . ': <input type="date" name="since" value="' . esc_attr( $since ) . '"></label>';
		echo '<label>' . esc_html__( 'Scan up to', 'kiwi-events' ) . ': <input type="number" name="limit" value="' . esc_attr( $limit ) . '" min="50" max="20000" step="50"></label>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Run Audit', 'kiwi-events' ) . '</button>';
		echo ' <span style="color:#64748b;font-size:12px;">' . esc_html__( 'Query cost ≈ one grouped scan over the "completed orders scanned" count above.', 'kiwi-events' ) . '</span>';
		echo '</form>';

		if ( $r['cap_hit'] ) {
			echo '<div style="padding:10px 14px;background:#fecaca;border:1px solid #ef4444;border-radius:8px;color:#7f1d1d;font-weight:600;">'
			   . esc_html( sprintf(
				   /* translators: %d: row cap */
				   __( '⚠️ Row cap of %d hit — results are TRUNCATED. Narrow the date range or raise the cap and re-run before trusting totals.', 'kiwi-events' ),
				   (int) $r['limit']
			   ) ) . '</div>';
		}

		// ── Totals ──
		$range = ( $t['date_from'] && $t['date_to'] ) ? ( $t['date_from'] . ' → ' . $t['date_to'] ) : '—';
		echo '<div style="display:flex;gap:12px;margin:14px 0;flex-wrap:wrap;">';
		self::stat( (int) $t['orders'] . ' ' . __( 'orders affected', 'kiwi-events' ), '#fef9c3' );
		self::stat( (int) $t['tickets_missing'] . ' ' . __( 'tickets missing', 'kiwi-events' ), '#fee2e2' );
		self::stat( (int) $t['tickets_over'] . ' ' . __( 'tickets over-issued', 'kiwi-events' ), '#fecaca' );
		self::stat( (int) $t['customers'] . ' ' . __( 'customers', 'kiwi-events' ), '#e0e7ff' );
		self::stat( '$' . number_format( (float) $t['revenue'], 2 ) . ' ' . __( 'revenue involved', 'kiwi-events' ), '#dcfce7' );
		self::stat( __( 'Affected range', 'kiwi-events' ) . ': ' . $range, '#f1f5f9' );
		echo '</div>';

		// Orphan totals — the "paid, got nothing" tier, kept as its own tiles so
		// it is never conflated with the ke_orders mismatch numbers above.
		echo '<div style="display:flex;gap:12px;margin:4px 0 14px;flex-wrap:wrap;">';
		self::stat( (int) $t['orphan_orders'] . ' ' . __( 'orphan orders (no ticket record)', 'kiwi-events' ), '#fecaca' );
		self::stat( (int) $t['orphan_actionable'] . ' ' . __( 'orphan orders actionable', 'kiwi-events' ), '#fee2e2' );
		self::stat( (int) $t['orphan_tickets'] . ' ' . __( 'tickets paid, never delivered', 'kiwi-events' ), '#fee2e2' );
		self::stat( '$' . number_format( (float) $t['orphan_revenue'], 2 ) . ' ' . __( 'orphan revenue at risk', 'kiwi-events' ), '#fecaca' );
		echo '</div>';

		if ( $r['orphan_capped'] ) {
			echo '<div style="padding:10px 14px;background:#fecaca;border:1px solid #ef4444;border-radius:8px;color:#7f1d1d;font-weight:600;">'
			   . esc_html( sprintf(
				   /* translators: %d: row cap */
				   __( '⚠️ Orphan scan hit the %d-order cap — the WooCommerce enumeration is TRUNCATED. Narrow the window and combine runs.', 'kiwi-events' ),
				   (int) $r['limit']
			   ) ) . '</div>';
		}

		if ( empty( $r['rows'] ) && empty( $r['orphans'] ) ) {
			echo '<p style="color:#16a34a;font-weight:600;">' . esc_html( sprintf(
				/* translators: %d: scanned count */
				__( 'No mismatched orders among the %d completed orders scanned, and no paid WooCommerce order is missing its ticket record. Every one has exactly as many ticket rows as it paid for.', 'kiwi-events' ),
				(int) $r['scanned']
			) ) . '</p></div>';
			return;
		}

		// CSV
		$csv_url = wp_nonce_url(
			add_query_arg( array( 'action' => 'ke_ticket_audit_csv', 'since' => $since, 'limit' => $limit ), admin_url( 'admin-post.php' ) ),
			'ke_ticket_audit_csv'
		);
		echo '<p><a href="' . esc_url( $csv_url ) . '" class="button">' . esc_html__( 'Download CSV (all affected rows)', 'kiwi-events' ) . '</a></p>';

		// Known limitations — this tool never implies certainty it does not have.
		echo '<details style="margin:10px 0;max-width:900px;"><summary style="cursor:pointer;font-weight:600;color:#7c2d12;">'
		   . esc_html__( 'Known limitations & blind spots (read before drawing conclusions)', 'kiwi-events' )
		   . '</summary><ul style="color:#475569;font-size:13px;line-height:1.6;margin:8px 0 0 18px;">';
		echo '<li>' . esc_html__( 'Refund attribution is exact per event, but a single WooCommerce order with more than one line for the SAME event (e.g. two ticket types) cannot be split precisely (ke_orders stores no line id). Such rows are marked "(~)" in the Refund column — verify those manually.', 'kiwi-events' ) . '</li>';
		echo '<li>' . esc_html__( 'Revenue involved may be inflated for multi-line orders: each ke_orders row stores the full order total (a known, out-of-scope double-count), so it is a loose upper bound, not an exact figure.', 'kiwi-events' ) . '</li>';
		echo '<li>' . esc_html__( 'Oversell is assessed only for events that have at least one mismatched order here. An event oversold while every order minted correctly would not appear — run a capacity report separately if needed.', 'kiwi-events' ) . '</li>';
		echo '<li>' . esc_html__( 'Orders whose ke_orders status already flipped to refunded/cancelled are excluded from the scan (a refunded buyer is owed no re-issue).', 'kiwi-events' ) . '</li>';
		echo '</ul></details>';

		// 0. Orphan orders — HIGHEST severity: paid, received nothing, no row.
		echo '<h2 style="margin-top:26px;color:#7f1d1d;">🚨 ' . esc_html__( 'Paid orders with NO ticket record (paid, received nothing)', 'kiwi-events' ) . '</h2>';
		echo '<p style="color:#64748b;max-width:900px;">' . esc_html__( 'Paid WooCommerce orders containing a ticket product that never created a ke_orders row — generation never fired, failed mid-way, or the order was placed while the plugin was inactive. There is no row to under-mint from; these customers have zero tickets.', 'kiwi-events' ) . '</p>';
		self::orphan_table(
			'✅ ' . __( 'Actionable — paid, no tickets, not refunded', 'kiwi-events' ),
			__( 'The customer paid, has no tickets, and was NOT refunded. Highest-priority reach-out (issue tickets or refund).', 'kiwi-events' ),
			$r['orphans_actionable']
		);
		self::orphan_table(
			'💸 ' . __( 'Refunded orphans — made whole, no action', 'kiwi-events' ),
			__( 'Paid, no tickets, but fully refunded — financially made whole. Listed for completeness; no ticket action.', 'kiwi-events' ),
			$r['orphans_refunded']
		);

		// 1. Oversell (highest priority)
		$flagged = array_filter( $r['oversell'], static function ( $e ) { return ! empty( $e['exceeded'] ); } );
		echo '<h2 style="margin-top:26px;color:#b91c1c;">⛔ ' . esc_html__( 'Oversold events (at-door count past capacity)', 'kiwi-events' ) . '</h2>';
		if ( empty( $flagged ) ) {
			echo '<p style="color:#16a34a;">' . esc_html__( 'No affected event with a known capacity exceeded it.', 'kiwi-events' ) . '</p>';
		} else {
			self::event_table( $flagged, true );
		}

		// Capacity unknown
		if ( ! empty( $r['capacity_unknown'] ) ) {
			echo '<h2 style="margin-top:26px;color:#b45309;">❓ ' . esc_html( sprintf(
				/* translators: %d: count */
				__( 'Capacity unknown — cannot assess oversell (%d events)', 'kiwi-events' ),
				count( $r['capacity_unknown'] )
			) ) . '</h2>';
			echo '<p style="color:#64748b;">' . esc_html__( 'These affected events have no resolvable capacity (no event cap and no limited ticket types), so oversell cannot be judged. "0 oversold" above excludes them.', 'kiwi-events' ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Event', 'kiwi-events' ) . '</th><th>' . esc_html__( 'At-door tickets', 'kiwi-events' ) . '</th></tr></thead><tbody>';
			foreach ( $r['capacity_unknown'] as $e ) {
				echo '<tr><td>' . self::event_link( $e['event_id'], $e['title'] ) . '</td><td>' . (int) $e['door'] . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// 2. Over-minted
		self::section( '🔺 ' . __( 'Over-minted orders (more tickets than kept)', 'kiwi-events' ),
			__( 'More ticket rows than were paid-and-kept. Review for oversell and possible clawback.', 'kiwi-events' ), $r['over'] );

		// 3. Under-minted, passed
		self::section( '🕯️ ' . __( 'Under-minted — event already passed (refund / goodwill)', 'kiwi-events' ),
			__( 'Paid for tickets never received and the event is over — a re-issue cannot help. Refund or goodwill decision.', 'kiwi-events' ), $r['under_passed'] );

		// 4. Under-minted, upcoming
		self::section( '♻️ ' . __( 'Under-minted — event upcoming (re-issue candidates)', 'kiwi-events' ),
			__( 'Recoverable: missing tickets can be re-issued before the event. Refunded orders are excluded (see below).', 'kiwi-events' ), $r['under_upcoming'] );

		// 5. Refunded / balanced — explicitly NOT re-issue candidates
		self::section( '💸 ' . __( 'Refunded or balanced by refund — NOT re-issue candidates', 'kiwi-events' ),
			__( 'These orders were fully refunded, or the shortfall is cancelled out by a partial refund (kept == minted). The customer was made whole financially; do not issue tickets.', 'kiwi-events' ), $r['refunded'] );

		// 6. By event
		echo '<h2 style="margin-top:26px;">' . esc_html__( 'By event', 'kiwi-events' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( 'Event', 'Orders affected', 'Tickets missing', 'Tickets over-issued' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $r['by_event'] as $e ) {
			echo '<tr><td>' . self::event_link( $e['event_id'], $e['title'] ) . '</td><td>' . (int) $e['orders'] . '</td>';
			echo '<td' . ( $e['missing'] > 0 ? ' style="color:#b45309;font-weight:600;"' : '' ) . '>' . (int) $e['missing'] . '</td>';
			echo '<td' . ( $e['over'] > 0 ? ' style="color:#b91c1c;font-weight:600;"' : '' ) . '>' . (int) $e['over'] . '</td></tr>';
		}
		echo '</tbody></table>';

		// Re-issue design note (NOT built)
		echo '<h2 style="margin-top:26px;">' . esc_html__( 'Re-issue action — design only (not built)', 'kiwi-events' ) . '</h2>';
		echo '<div style="max-width:820px;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;color:#334155;">' . esc_html( self::reissue_contract() ) . '</div>';

		echo '</div>';
	}

	private static function orphan_table( $title, $desc, array $rows ) {
		echo '<h3 style="margin-top:18px;">' . esc_html( $title ) . '</h3>';
		echo '<p style="color:#64748b;max-width:900px;">' . esc_html( $desc ) . '</p>';
		if ( empty( $rows ) ) {
			echo '<p style="color:#16a34a;">' . esc_html__( 'None.', 'kiwi-events' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( 'Order #', 'Order date', 'Event', 'Event date', 'Paid units', 'Email', 'Status', 'Refund', 'Passed?' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . self::order_link( array( 'order_number' => $row['order_number'], 'ke_order_id' => 0, 'wc_order_id' => $row['wc_order_id'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['order_date'] ) . '</td>';
			echo '<td>' . self::event_link( $row['event_id'], $row['event_title'] ) . '</td>';
			echo '<td>' . esc_html( $row['event_date'] ?: '—' ) . '</td>';
			echo '<td style="font-weight:700;color:#7f1d1d;">' . (int) $row['paid_qty'] . '</td>';
			echo '<td>' . esc_html( $row['email'] ) . '</td>';
			echo '<td>' . esc_html( $row['status'] ) . '</td>';
			echo '<td>' . esc_html( $row['refund_status'] ) . '</td>';
			echo '<td>' . ( $row['event_passed'] ? esc_html__( 'yes', 'kiwi-events' ) : esc_html__( 'no', 'kiwi-events' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function event_table( array $events, $show_capacity ) {
		echo '<table class="widefat striped"><thead><tr>';
		$cols = $show_capacity ? array( 'Event', 'At-door tickets', 'Capacity', 'Over by' ) : array( 'Event', 'At-door tickets' );
		foreach ( $cols as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $events as $e ) {
			echo '<tr><td>' . self::event_link( $e['event_id'], $e['title'] ) . '</td>';
			echo '<td style="font-weight:700;color:#b91c1c;">' . (int) $e['door'] . '</td>';
			if ( $show_capacity ) {
				echo '<td>' . (int) $e['capacity'] . '</td><td style="font-weight:700;color:#b91c1c;">+' . (int) $e['over_by'] . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function section( $title, $desc, array $rows ) {
		echo '<h2 style="margin-top:26px;">' . esc_html( $title ) . '</h2>';
		echo '<p style="color:#64748b;max-width:820px;">' . esc_html( $desc ) . '</p>';
		if ( empty( $rows ) ) {
			echo '<p style="color:#16a34a;">' . esc_html__( 'None.', 'kiwi-events' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( 'Order #', 'Order date', 'Event', 'Event date', 'Paid', 'Refunded', 'Kept', 'Minted', 'Delta', 'Email', 'Refund', 'Passed?', 'Suspected cause' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$delta = (int) $row['delta'];
			$dcolor = $delta > 0 ? '#b45309' : ( $delta < 0 ? '#b91c1c' : '#16a34a' );
			echo '<tr>';
			echo '<td>' . self::order_link( $row ) . '</td>';
			echo '<td>' . esc_html( $row['order_date'] ) . '</td>';
			echo '<td>' . self::event_link( $row['event_id'], $row['event_title'] ) . '</td>';
			echo '<td>' . esc_html( $row['event_date'] ?: '—' ) . '</td>';
			echo '<td>' . (int) $row['paid_qty'] . '</td>';
			echo '<td>' . (int) $row['refunded_qty'] . '</td>';
			echo '<td>' . (int) $row['kept_qty'] . '</td>';
			echo '<td>' . (int) $row['minted'] . '</td>';
			echo '<td style="font-weight:700;color:' . esc_attr( $dcolor ) . ';">' . ( $delta > 0 ? '+' . $delta : (string) $delta ) . '</td>';
			echo '<td>' . esc_html( $row['email'] ) . '</td>';
			echo '<td>' . esc_html( $row['refund_status'] ) . '</td>';
			echo '<td>' . ( $row['event_passed'] ? esc_html__( 'yes', 'kiwi-events' ) : esc_html__( 'no', 'kiwi-events' ) ) . '</td>';
			echo '<td style="font-size:12px;color:#475569;">' . esc_html( $row['cause'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function stat( $label, $bg ) {
		echo '<div style="padding:10px 14px;background:' . esc_attr( $bg ) . ';border-radius:8px;font-size:13px;">' . esc_html( $label ) . '</div>';
	}

	private static function order_link( $row ) {
		$label = $row['order_number'] !== '' ? '#' . $row['order_number'] : ( 'KE-' . (int) $row['ke_order_id'] );
		if ( (int) $row['wc_order_id'] > 0 ) {
			return '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $row['wc_order_id'] . '&action=edit' ) ) . '">' . esc_html( $label ) . '</a>';
		}
		return esc_html( $label );
	}

	private static function event_link( $event_id, $title ) {
		$title = $title !== '' ? $title : ( '#' . (int) $event_id );
		return '<a href="' . esc_url( admin_url( 'admin.php?page=ke-event-builder&event_id=' . (int) $event_id ) ) . '">' . esc_html( $title ) . '</a>';
	}

	public function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized.' );
		}
		check_admin_referer( 'ke_ticket_audit_csv' );

		$default_since = date( 'Y-m-d', current_time( 'timestamp' ) - self::DEFAULT_WINDOW_DAYS * DAY_IN_SECONDS );
		$since = isset( $_GET['since'] ) ? sanitize_text_field( wp_unslash( $_GET['since'] ) ) : $default_since;
		$limit = isset( $_GET['limit'] ) ? max( 50, min( 20000, (int) $_GET['limit'] ) ) : self::MAX_ROWS;
		$r     = self::run( array( 'since' => $since, 'limit' => $limit ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ke-ticket-audit-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array(
			'record_type', 'ke_order_id', 'wc_order_id', 'order_number', 'order_date', 'event_id', 'event_title',
			'event_date', 'event_passed', 'paid_qty', 'refunded_qty', 'kept_qty', 'minted',
			'delta', 'raw_delta', 'direction', 'refund_status', 'refunded_amount',
			'email', 'buyer', 'payment_method', 'status', 'amount', 'suspected_cause',
		) );
		foreach ( $r['rows'] as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), array(
				'mismatch', $row['ke_order_id'], $row['wc_order_id'], $row['order_number'], $row['order_date'],
				$row['event_id'], $row['event_title'], $row['event_date'], $row['event_passed'] ? 'yes' : 'no',
				$row['paid_qty'], $row['refunded_qty'], $row['kept_qty'], $row['minted'],
				$row['delta'], $row['raw_delta'], $row['direction'], $row['refund_status'], $row['refunded_amount'],
				$row['email'], $row['buyer'], $row['payment'], $row['status'], $row['amount'], $row['cause'],
			) ) );
		}
		// Orphans: paid, no ke_orders row → minted 0, the whole paid_qty is missing.
		foreach ( $r['orphans'] as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), array(
				'orphan', '', $row['wc_order_id'], $row['order_number'], $row['order_date'],
				$row['event_id'], $row['event_title'], $row['event_date'], $row['event_passed'] ? 'yes' : 'no',
				$row['paid_qty'], '', '', 0,
				$row['paid_qty'], $row['paid_qty'], $row['actionable'] ? 'orphan-actionable' : 'orphan-refunded',
				$row['refund_status'], '',
				$row['email'], '', 'woocommerce', $row['status'], $row['amount'], 'no ke_orders row (paid, no ticket record)',
			) ) );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Neutralize CSV formula injection: a cell whose first character is one of
	 * = + - @ (or a leading tab/CR) can execute as a formula when opened in
	 * Excel/Sheets. Buyer name, email and event title are attacker-influenced
	 * yet land in an admin's spreadsheet, so prefix any such value with an
	 * apostrophe. Numeric/plain cells are untouched.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function csv_cell( $value ) {
		$value = (string) $value;
		if ( $value !== '' && strpbrk( $value[0], "=+-@\t\r" ) !== false ) {
			return "'" . $value;
		}
		return $value;
	}
}
