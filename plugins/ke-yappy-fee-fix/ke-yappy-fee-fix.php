<?php
/**
 * Plugin Name: Kiwi Events — Yappy Service Fee Fix
 * Plugin URI:  https://campuslifepa.com
 * Description: Two fixes for the Yappy BG gateway. (1) Adjusts the WC order subtotal it sees to include fees, since Yappy BG reconstructs its outbound amount from get_subtotal() and bypasses get_total() — without this, service fees are silently excluded from the charge. (2) Blocks the gateway from cancelling an order that already has a captured payment: its callback handler cancels unconditionally on a C/R status, so a late or duplicate Banco General callback was cancelling paid orders hours after the fact.
 * Version:     1.3.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      Campus Life Panama
 * Author URI:  https://campuslifepa.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ke-yappy-fee-fix
 *
 * Deployment: upload this folder to wp-content/plugins/ke-yappy-fee-fix/ on
 * production via SFTP, then activate via WP Admin → Plugins. WordPress.com
 * Business loads only Automattic-managed mu-plugins, so a standard plugin
 * (this) is the supported deployment shape on that platform.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Toggle for diagnostic logging. Flip to true only while diagnosing a specific
// order; leave false in steady state to keep debug.log clean.
if ( ! defined( 'KE_FEE_DEBUG' ) ) {
    define( 'KE_FEE_DEBUG', false );
}

/**
 * Visual reminder so the user can't accidentally leave debug logging on.
 * Renders as a yellow admin banner on every admin page while the constant
 * is true; disappears the moment it's flipped back to false.
 */
if ( KE_FEE_DEBUG ) {
    add_action( 'admin_notices', function () {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>Kiwi Events Yappy Fix:</strong> Debug logging is currently ENABLED. ';
        echo 'Remember to disable <code>KE_FEE_DEBUG</code> in <code>ke-yappy-fee-fix.php</code> after testing.';
        echo '</p></div>';
    } );
}

/**
 * Identify whether a payment method id belongs to the Yappy Comercial gateway.
 * The plugin has historically registered itself as `yappy_payment`, but newer
 * builds may use `yappy_comercial` / `yappy_business`. Match on the substring
 * so the override survives a rename.
 */
function ke_yappy_fee_fix_is_yappy( $payment_method ) {
    return is_string( $payment_method ) && stripos( $payment_method, 'yappy' ) !== false;
}

/**
 * Runs after WC has built the order and saved it, BEFORE the gateway's
 * process_payment() is called. This is the last safe moment to guarantee
 * the order carries every fee from the cart and that $order->get_total()
 * reflects them.
 *
 * Priority 5 so we win against any later-priority handler that might mutate
 * the order.
 */
add_action( 'woocommerce_checkout_order_processed', 'ke_yappy_fee_fix_ensure_fees', 5, 3 );
function ke_yappy_fee_fix_ensure_fees( $order_id, $posted_data, $order = null ) {
    if ( ! $order instanceof WC_Order ) {
        $order = wc_get_order( $order_id );
    }
    if ( ! $order instanceof WC_Order ) {
        return;
    }

    $payment_method = $order->get_payment_method();
    if ( ! ke_yappy_fee_fix_is_yappy( $payment_method ) ) {
        return;
    }

    $order_fees = $order->get_fees();
    $cart_fees  = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_fees() : array();

    if ( KE_FEE_DEBUG ) {
        error_log( sprintf(
            '[KE-YAPPY-FIX] order=%d method=%s total_before=%s order_fees=%d cart_fees=%d',
            $order_id,
            $payment_method,
            $order->get_total(),
            count( $order_fees ),
            count( $cart_fees )
        ) );
    }

    // If the order is missing fees that exist in the cart, transfer them.
    // Otherwise just force a recalculation in case totals are stale.
    if ( empty( $order_fees ) && ! empty( $cart_fees ) ) {
        foreach ( $cart_fees as $cart_fee ) {
            $fee_item = new WC_Order_Item_Fee();
            $fee_item->set_name( isset( $cart_fee->name ) ? $cart_fee->name : 'Service Fee' );
            $fee_item->set_amount( isset( $cart_fee->amount ) ? $cart_fee->amount : 0 );
            $fee_item->set_total( isset( $cart_fee->total ) ? $cart_fee->total : ( $cart_fee->amount ?? 0 ) );
            $fee_item->set_tax_status( ! empty( $cart_fee->taxable ) ? 'taxable' : 'none' );
            $order->add_item( $fee_item );

            if ( KE_FEE_DEBUG ) {
                error_log( sprintf(
                    '[KE-YAPPY-FIX] transferred missing cart fee → order %d: %s = %s',
                    $order_id,
                    $fee_item->get_name(),
                    $fee_item->get_total()
                ) );
            }
        }
    }

    $order->calculate_totals();
    $order->save();

    if ( KE_FEE_DEBUG ) {
        error_log( sprintf(
            '[KE-YAPPY-FIX] order=%d total_after=%s fees_on_order=%d',
            $order_id,
            $order->get_total(),
            count( $order->get_fees() )
        ) );
    }
}

/**
 * Returns true only while YappyPayment::process_payment is on the current
 * call stack. Used to scope our subtotal-injection filter so it fires for
 * Yappy's outbound amount calculation and nothing else — admin order screens,
 * customer receipts, account "view order" pages, refund math, REST reads,
 * etc. all see the untouched WC subtotal.
 *
 * debug_backtrace is mildly expensive but we only invoke it when the cheap
 * payment_method check has already matched a Yappy order, so this runs a
 * handful of times per request at most.
 */
function ke_yappy_fee_fix_is_in_yappy_dispatch() {
    $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 );
    foreach ( $trace as $frame ) {
        if ( isset( $frame['class'], $frame['function'] )
            && $frame['class'] === 'YappyPayment'
            && $frame['function'] === 'process_payment' ) {
            return true;
        }
    }
    return false;
}

/**
 * Primary fix — inject fee totals into $order->get_subtotal() for Yappy orders.
 *
 * The Yappy BG plugin (yappy-bg-para-woocommerce) computes its outbound amount
 * in YappyPayment::process_payment() as:
 *     $sub_total = $order->get_subtotal() + $shipping - $discount;
 *     $total     = $sub_total + $taxes;
 * — it never calls $order->get_total(). WC_Order::get_subtotal() sums only
 * line_item rows, so WC_Order_Item_Fee values are silently dropped from the
 * amount sent to Banco General.
 *
 * Yappy exposes no filter on the amount or on its BgFirma constructor, so the
 * only externally safe lever is WC's own woocommerce_order_get_subtotal
 * filter. Scope is gated two ways: payment_method must look like Yappy AND
 * YappyPayment::process_payment must be on the call stack. The second gate
 * keeps admin/receipt/account renders untouched — they read get_subtotal()
 * without process_payment above them, so the filter is inert there.
 */
add_filter( 'woocommerce_order_get_subtotal', 'ke_yappy_fee_fix_inject_fees_into_subtotal', 10, 2 );
function ke_yappy_fee_fix_inject_fees_into_subtotal( $subtotal, $order ) {
    if ( ! $order instanceof WC_Order ) {
        return $subtotal;
    }
    if ( ! ke_yappy_fee_fix_is_yappy( $order->get_payment_method() ) ) {
        return $subtotal;
    }
    if ( ! ke_yappy_fee_fix_is_in_yappy_dispatch() ) {
        return $subtotal;
    }

    $fee_total = 0.0;
    foreach ( $order->get_fees() as $fee ) {
        $fee_total += (float) $fee->get_total();
    }
    if ( $fee_total <= 0 ) {
        return $subtotal;
    }

    if ( KE_FEE_DEBUG ) {
        error_log( sprintf(
            '[KE-YAPPY-FIX] injecting %s in fees into subtotal for order %d (was %s, becomes %s) [context=process_payment]',
            number_format( $fee_total, 2 ),
            $order->get_id(),
            number_format( (float) $subtotal, 2 ),
            number_format( (float) $subtotal + $fee_total, 2 )
        ) );
    }

    return (float) $subtotal + $fee_total;
}

/* ═══════════════════════════════════════════════════════════════════════
 * GUARD: Yappy cancela pedidos que ya estaban pagados
 *
 * YappyPayment::callback_handler() (YappyPayment.php:138) hace, sin ninguna
 * condición:
 *
 *     } elseif ('C' == $status || 'R' == $status) {
 *         $order->update_status('cancelled');
 *
 * No comprueba si el pedido ya fue aprobado con 'E' y no es idempotente. Un
 * callback tardío o duplicado de Banco General — sesión de Yappy abandonada,
 * transacción expirada, reintento del propio banco — cancela así un pedido
 * cobrado horas después. El cliente pagó, recibió su correo, y luego el
 * pedido aparece cancelado sin motivo.
 *
 * El gateway registra su handler en `woocommerce_api_pagosbg` con prioridad
 * 10, así que este se engancha en la 1: mira la petición antes, y si es una
 * cancelación contra un pedido con pago capturado, responde lo mismo que
 * habría respondido el gateway y corta. El handler original nunca corre, así
 * que no hay cambio de estado, no sale el correo de "Pedido cancelado", y
 * nadie pierde sus boletos.
 *
 * Va aquí y no dentro del plugin de Yappy a propósito: ese plugin trae su
 * propio actualizador automático (yahnis-elsts/plugin-update-checker), así
 * que cualquier parche hecho a mano sobre sus archivos se pierde en silencio
 * en la siguiente actualización — como ya pasó con el parche del fee.
 * ═══════════════════════════════════════════════════════════════════════ */

add_action( 'woocommerce_api_pagosbg', 'ke_yappy_block_cancel_of_paid_order', 1 );
function ke_yappy_block_cancel_of_paid_order() {
    if ( ! ke_yappy_cancel_should_be_blocked( $_GET ) ) {
        return; // Que siga hasta el handler del gateway.
    }

    ke_yappy_record_blocked_cancel( $_GET );

    // Misma respuesta que da el gateway, para que Banco General no reintente.
    // A partir de aquí la petición termina: el handler original, registrado en
    // la prioridad 10 de este mismo hook, ya no llega a ejecutarse.
    if ( ! headers_sent() ) {
        header( 'Content-Type: application/json' );
    }
    wp_send_json( array( 'success' => true ) );
}

/**
 * ¿Esta petición es una cancelación que no debemos dejar pasar?
 *
 * Separado del hook a propósito: wp_send_json() termina el proceso con un
 * `die` que no se puede interceptar, así que la decisión vive aquí donde sí
 * se puede comprobar.
 *
 * @param array $req  Los parámetros de la petición ($_GET en producción).
 */
function ke_yappy_cancel_should_be_blocked( $req ) {
    $status   = isset( $req['status'] ) ? sanitize_text_field( wp_unslash( $req['status'] ) ) : '';
    $order_id = isset( $req['orderId'] ) ? absint( $req['orderId'] ) : 0;

    // Solo nos metemos en las cancelaciones. Las aprobaciones ('E') y
    // cualquier otro estado siguen su camino normal hacia el gateway.
    if ( $status !== 'C' && $status !== 'R' ) {
        return false;
    }
    if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
        return false;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return false;
    }

    // ¿Hay dinero capturado? Tres señales independientes: la fecha de pago que
    // escribe WC_Order::payment_complete(), el id de transacción, y el meta
    // `confirmationNumber` que el propio gateway guarda al aprobar.
    $paid = $order->get_date_paid()
         || $order->get_transaction_id()
         || $order->get_meta( 'confirmationNumber' );

    if ( ! $paid ) {
        return false; // Cancelación legítima de un pedido sin pagar.
    }

    // Firma: si no cuadra, no somos nosotros quienes debemos contestar —
    // dejamos que el gateway la rechace como siempre.
    return ke_yappy_callback_signature_is_valid( $req );
}

/** Deja rastro en el pedido y en el log de la cancelación que se ignoró. */
function ke_yappy_record_blocked_cancel( $req ) {
    $status   = isset( $req['status'] ) ? sanitize_text_field( wp_unslash( $req['status'] ) ) : '';
    $order_id = isset( $req['orderId'] ) ? absint( $req['orderId'] ) : 0;
    $order    = $order_id ? wc_get_order( $order_id ) : null;
    if ( ! $order ) {
        return;
    }

    $order->add_order_note( sprintf(
        'Yappy envió una cancelación (status=%s) para un pedido que ya tenía el pago capturado. Se ignoró para no anular una compra pagada. Revisar en Yappy si corresponde un reembolso.',
        $status
    ) );
    $order->update_meta_data( '_ke_yappy_blocked_cancel', current_time( 'mysql' ) );
    $order->save();

    error_log( sprintf(
        '[KE-YAPPY-FIX] BLOQUEADA cancelación de pedido pagado: order=%d status=%s date_paid=%s tx=%s',
        $order_id,
        $status,
        $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d H:i:s' ) : '(sin fecha)',
        $order->get_transaction_id() ?: '(sin tx)'
    ) );
}

/**
 * Reproduce la verificación de firma del gateway:
 *   hash_hmac('sha256', orderId . status . domain, explode('.', base64_decode(secret))[0])
 *
 * El secreto se lee de los ajustes vivos de la pasarela, no de una copia, para
 * que rotarlo no deje este guard desincronizado. Devuelve true también cuando
 * no hay secreto configurado: sin él no podemos juzgar, y en la duda es mejor
 * proteger un pedido pagado que dejarlo caer.
 */
function ke_yappy_callback_signature_is_valid( $req = null ) {
    if ( ! is_array( $req ) ) {
        $req = $_GET;
    }
    $settings = get_option( 'woocommerce_yappy_payment_settings', array() );
    $secret   = is_array( $settings ) && ! empty( $settings['secret'] ) ? (string) $settings['secret'] : '';
    if ( $secret === '' ) {
        return true;
    }

    $decoded = base64_decode( $secret, true );
    if ( $decoded === false ) {
        return true;
    }
    $key = explode( '.', $decoded );
    if ( empty( $key[0] ) ) {
        return true;
    }

    $order_id = isset( $req['orderId'] ) ? (string) wp_unslash( $req['orderId'] ) : '';
    $status   = isset( $req['status'] ) ? (string) wp_unslash( $req['status'] ) : '';
    $domain   = isset( $req['domain'] ) ? (string) wp_unslash( $req['domain'] ) : '';
    $hash     = isset( $req['hash'] ) ? (string) wp_unslash( $req['hash'] ) : '';

    $expected = hash_hmac( 'sha256', $order_id . $status . $domain, $key[0] );

    return hash_equals( $expected, $hash );
}
