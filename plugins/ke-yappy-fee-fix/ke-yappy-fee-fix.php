<?php
/**
 * Plugin Name: Kiwi Events — Yappy Service Fee Fix
 * Plugin URI:  https://campuslifepa.com
 * Description: Adjusts the WC order subtotal seen by the Yappy BG plugin to include fees, since Yappy BG manually reconstructs its outbound amount from get_subtotal() and bypasses get_total(). Without this fix, service fees (Campus Fee) are silently excluded from Yappy charges.
 * Version:     1.2.0
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
