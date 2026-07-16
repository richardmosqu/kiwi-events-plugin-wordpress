<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Read-only audit for WooCommerce orders paid via the Yappy gateway that
 * silently undercharged service fees.
 *
 * Background: prior to the 2026-05 patch in
 * /wp-content/plugins/yappy-bg-para-woocommerce/YappyPayment.php, the
 * Yappy gateway computed the amount as
 *   $sub_total = $order->get_subtotal() + $shipping - $discount;
 *   $total     = $sub_total + $taxes;
 * — note no $order->get_total_fees(). Paguelofácil read $order->get_total()
 * correctly so its orders were charged in full; Yappy dropped the fee.
 *
 * For any Yappy-paid order that carries one or more WC_Order_Item_Fee rows,
 * the shortfall per order equals the sum of those fee totals (the WC order
 * total was correct, the gateway just charged less).
 *
 * Read-only. Lists affected orders and the per-order shortfall so the
 * operator can decide reach-out / write-off manually. Does NOT charge,
 * refund, or modify any order.
 *
 * Access: admin-only hidden submenu under the KE menu.
 */
class KE_Service_Fee_Audit {

    const PAGE_SLUG = 'ke-service-fee-audit';

    // Yappy gateway's WC payment_method id (set in YappyPayment::__construct()).
    const YAPPY_GATEWAY_ID = 'yappy_payment';

    // Service-fee feature went live on this date; pre-cutoff orders had no
    // fee to drop, so the audit defaults to scanning from here forward.
    const DEFAULT_SINCE = '2026-04-24';

    public function init() {
        add_action( 'admin_menu',                  array( $this, 'register_page' ), 99 );
        add_action( 'admin_post_ke_fee_audit_csv', array( $this, 'export_csv' ) );
    }

    public function register_page() {
        add_submenu_page(
            null, // hidden — reach via admin.php?page=ke-service-fee-audit
            __( 'Yappy Fee Shortfall Audit', 'kiwi-events' ),
            __( 'Yappy Fee Shortfall Audit', 'kiwi-events' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Run the audit. Iterates Yappy-paid WC orders, finds those carrying
     * any fee items, and treats the fee total as the undercharge.
     *
     * @param array $args { 'since' => Y-m-d, 'limit' => int }
     * @return array { rows => array, total_shortfall => float, scanned => int }
     */
    public static function run( array $args = array() ) {
        $rows  = array();
        $total = 0.0;

        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array( 'rows' => $rows, 'total_shortfall' => $total, 'scanned' => 0 );
        }

        $query_args = array(
            'limit'          => (int) ( $args['limit'] ?? 1000 ),
            'status'         => array( 'processing', 'completed', 'on-hold', 'refunded' ),
            'payment_method' => self::YAPPY_GATEWAY_ID,
            'return'         => 'objects',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        if ( ! empty( $args['since'] ) ) {
            $query_args['date_created'] = '>=' . sanitize_text_field( $args['since'] );
        }

        $orders  = wc_get_orders( $query_args );
        $scanned = is_array( $orders ) ? count( $orders ) : 0;

        foreach ( (array) $orders as $order ) {
            if ( ! $order instanceof WC_Order ) continue;

            $fee_lines = array();
            $shortfall = 0.0;
            foreach ( $order->get_items( 'fee' ) as $fee_item ) {
                $amount      = (float) $fee_item->get_total();
                $fee_lines[] = $fee_item->get_name() . ' ($' . number_format( $amount, 2 ) . ')';
                $shortfall  += $amount;
            }
            if ( $shortfall <= 0 ) continue;

            $rows[] = array(
                'order_id'    => $order->get_id(),
                'order_no'    => $order->get_order_number(),
                'date'        => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
                'buyer'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                'email'       => $order->get_billing_email(),
                'phone'       => $order->get_billing_phone(),
                'order_total' => (float) $order->get_total(),
                'fee_lines'   => implode( ' + ', $fee_lines ),
                'shortfall'   => round( $shortfall, 2 ),
                'charged'     => round( (float) $order->get_total() - $shortfall, 2 ),
                'status'      => $order->get_status(),
            );
            $total += $shortfall;
        }

        return array(
            'rows'            => $rows,
            'total_shortfall' => round( $total, 2 ),
            'scanned'         => $scanned,
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $since = isset( $_GET['since'] ) ? sanitize_text_field( $_GET['since'] ) : self::DEFAULT_SINCE;
        $limit = isset( $_GET['limit'] ) ? max( 50, min( 5000, (int) $_GET['limit'] ) ) : 1000;

        echo '<div class="wrap"><h1>Yappy Fee Shortfall Audit</h1>';
        echo '<p style="color:#64748b;max-width:780px;">Lists WooCommerce orders paid via Yappy that carry service-fee line items. Prior to the YappyPayment.php patch, the gateway dropped fee totals when computing the amount sent to Banco General, so each of these orders was charged the order total <em>minus</em> the listed fees. Read-only — this tool does NOT charge buyers, modify orders, or issue refunds. Use the shortfall column to decide manual reach-out.</p>';

        echo '<form method="get" style="margin:14px 0;display:flex;gap:10px;align-items:center;">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
        echo '<label>Since: <input type="date" name="since" value="' . esc_attr( $since ) . '"></label>';
        echo '<label>Scan up to: <input type="number" name="limit" value="' . esc_attr( $limit ) . '" min="50" max="5000" step="50"></label>';
        echo '<button type="submit" class="button button-primary">Run Audit</button>';
        echo '</form>';

        $result = self::run( array( 'since' => $since, 'limit' => $limit ) );

        echo '<div style="display:flex;gap:16px;margin:14px 0;flex-wrap:wrap;">';
        echo '<div style="padding:10px 14px;background:#f1f5f9;border-radius:8px;"><strong>' . (int) $result['scanned'] . '</strong> Yappy orders scanned</div>';
        echo '<div style="padding:10px 14px;background:#fef9c3;border-radius:8px;"><strong>' . count( $result['rows'] ) . '</strong> with shortfall</div>';
        echo '<div style="padding:10px 14px;background:#fecaca;border-radius:8px;"><strong>$' . number_format( $result['total_shortfall'], 2 ) . '</strong> total uncharged fee</div>';
        echo '</div>';

        if ( ! empty( $result['rows'] ) ) {
            $csv_url = wp_nonce_url(
                add_query_arg(
                    array( 'action' => 'ke_fee_audit_csv', 'since' => $since, 'limit' => $limit ),
                    admin_url( 'admin-post.php' )
                ),
                'ke_fee_audit_csv'
            );
            echo '<p><a href="' . esc_url( $csv_url ) . '" class="button">Download CSV</a></p>';

            echo '<table class="widefat striped"><thead><tr>';
            foreach ( array( 'Order #', 'Date', 'Buyer', 'Email', 'Phone', 'Status', 'Order Total', 'Charged (Yappy)', 'Shortfall', 'Fee Lines' ) as $h ) {
                echo '<th>' . esc_html( $h ) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ( $result['rows'] as $r ) {
                $edit_url = admin_url( 'post.php?post=' . (int) $r['order_id'] . '&action=edit' );
                echo '<tr>';
                echo '<td><a href="' . esc_url( $edit_url ) . '">#' . esc_html( $r['order_no'] ) . '</a></td>';
                echo '<td>' . esc_html( $r['date'] ) . '</td>';
                echo '<td>' . esc_html( $r['buyer'] ) . '</td>';
                echo '<td>' . esc_html( $r['email'] ) . '</td>';
                echo '<td>' . esc_html( $r['phone'] ) . '</td>';
                echo '<td>' . esc_html( $r['status'] ) . '</td>';
                echo '<td>$' . esc_html( number_format( (float) $r['order_total'], 2 ) ) . '</td>';
                echo '<td>$' . esc_html( number_format( (float) $r['charged'], 2 ) ) . '</td>';
                echo '<td style="color:#b91c1c;font-weight:600;">$' . esc_html( number_format( (float) $r['shortfall'], 2 ) ) . '</td>';
                echo '<td style="font-size:12px;color:#475569;">' . esc_html( $r['fee_lines'] ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#16a34a;">No Yappy orders with fee shortfalls in the scanned window.</p>';
        }

        echo '</div>';
    }

    public function export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized.' );
        check_admin_referer( 'ke_fee_audit_csv' );

        $since  = isset( $_GET['since'] ) ? sanitize_text_field( $_GET['since'] ) : self::DEFAULT_SINCE;
        $limit  = isset( $_GET['limit'] ) ? max( 50, min( 5000, (int) $_GET['limit'] ) ) : 1000;
        $result = self::run( array( 'since' => $since, 'limit' => $limit ) );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=ke-yappy-shortfall-' . gmdate( 'Ymd-His' ) . '.csv' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'order_id', 'order_number', 'date', 'buyer', 'email', 'phone', 'status', 'order_total', 'charged_yappy', 'shortfall', 'fee_lines' ) );
        foreach ( $result['rows'] as $r ) {
            fputcsv( $out, array(
                $r['order_id'], $r['order_no'], $r['date'], $r['buyer'], $r['email'], $r['phone'],
                $r['status'], $r['order_total'], $r['charged'], $r['shortfall'], $r['fee_lines'],
            ) );
        }
        fclose( $out );
        exit;
    }
}
