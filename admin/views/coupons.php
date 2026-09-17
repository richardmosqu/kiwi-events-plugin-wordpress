<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cupones admin view.
 *
 * Vars in scope (set by KE_Admin_Coupons::render):
 *   $wc_active     bool        WooCommerce present
 *   $coupon_box_on bool        the checkout coupon box is enabled in WooCommerce
 *   $notice        array|null  { type, text }
 *   $is_form       bool        show the create/edit form instead of the list
 *   $editing       array|null  the coupon being edited
 *   $form          array       values for the form
 *   $coupons       array       every KiwiEvents coupon
 *   $events        WP_Post[]   events for the picker
 */

$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
$new_url  = admin_url( 'admin.php?page=ke-coupons&action=new' );
$list_url = admin_url( 'admin.php?page=ke-coupons' );

/** "20% off each ticket" / "$5 off the order" in one short phrase. */
$describe = function ( $type, $amount ) use ( $currency ) {
    $amount = (float) $amount;
    switch ( $type ) {
        case 'percent':
            return sprintf( '%s%% off each ticket', rtrim( rtrim( number_format( $amount, 2 ), '0' ), '.' ) );
        case 'fixed_product':
            return sprintf( '%s%s off each ticket', $currency, number_format( $amount, 2 ) );
        case 'fixed_cart':
            return sprintf( '%s%s off the order', $currency, number_format( $amount, 2 ) );
    }
    return '';
};
?>
<div class="wrap ke-wrap ke-coupons-page">

    <div class="ke-section-card ke-section-card--compact">
        <div class="ke-page-header">
            <div class="ke-page-header-left">
                <h1><?php esc_html_e( 'Cupones', 'kiwi-events' ); ?></h1>
                <p><?php esc_html_e( 'Discount codes for a single event. The customer types the code in the coupon box at WooCommerce checkout and the discount comes off the tickets for that event.', 'kiwi-events' ); ?></p>
            </div>
            <?php if ( $wc_active && ! $is_form ) : ?>
                <div class="ke-header-actions">
                    <a href="<?php echo esc_url( $new_url ); ?>" class="ke-btn ke-btn-primary">+ <?php esc_html_e( 'New coupon', 'kiwi-events' ); ?></a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( $notice ) : ?>
        <div class="ke-section-card ke-section-card--compact ke-coupon-notice ke-coupon-notice--<?php echo esc_attr( $notice['type'] ); ?>">
            <?php echo esc_html( $notice['text'] ); ?>
        </div>
    <?php endif; ?>

    <?php if ( ! $wc_active ) : ?>
        <div class="ke-section-card">
            <div class="ke-empty-state">
                <span class="ke-empty-state-icon">🏷️</span>
                <h3><?php esc_html_e( 'WooCommerce is not active', 'kiwi-events' ); ?></h3>
                <p><?php esc_html_e( 'Coupons are applied at the WooCommerce checkout, so WooCommerce has to be active before they can be created.', 'kiwi-events' ); ?></p>
            </div>
        </div>

    <?php elseif ( $is_form ) : ?>

        <?php if ( ! $coupon_box_on ) : ?>
            <div class="ke-section-card ke-section-card--compact ke-coupon-notice ke-coupon-notice--warn">
                <?php
                printf(
                    /* translators: %s: link to the WooCommerce settings screen */
                    esc_html__( 'The coupon box is switched off at checkout, so customers cannot enter a code. Turn on "Enable the use of coupons" in %s.', 'kiwi-events' ),
                    '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings' ) ) . '">' . esc_html__( 'WooCommerce → Settings → General', 'kiwi-events' ) . '</a>'
                );
                ?>
            </div>
        <?php endif; ?>

        <form class="ke-section-card ke-coupon-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="ke_coupon_save">
            <input type="hidden" name="coupon_id" value="<?php echo (int) $form['id']; ?>">
            <?php wp_nonce_field( 'ke_coupon_save' ); ?>

            <h3><?php echo $form['id'] ? esc_html__( 'Edit coupon', 'kiwi-events' ) : esc_html__( 'New coupon', 'kiwi-events' ); ?></h3>

            <div class="ke-coupon-grid">
                <div class="ke-coupon-field">
                    <label for="ke-c-code"><?php esc_html_e( 'Coupon code', 'kiwi-events' ); ?> <span class="ke-req">*</span></label>
                    <input type="text" id="ke-c-code" name="code" class="ke-input" required maxlength="60"
                           value="<?php echo esc_attr( $form['code'] ); ?>" autocomplete="off">
                    <p class="ke-hint"><?php esc_html_e( 'What the customer types at checkout. Case does not matter.', 'kiwi-events' ); ?></p>
                </div>

                <div class="ke-coupon-field">
                    <label for="ke-c-event"><?php esc_html_e( 'Event', 'kiwi-events' ); ?> <span class="ke-req">*</span></label>
                    <select id="ke-c-event" name="event_id" class="ke-select" required>
                        <option value="0"><?php esc_html_e( '— Choose an event —', 'kiwi-events' ); ?></option>
                        <?php foreach ( $events as $event ) :
                            $start = get_post_meta( $event->ID, '_ke_event_date_start', true );
                            $label = $event->post_title;
                            if ( $start ) {
                                $label .= ' · ' . date_i18n( 'M j, Y', strtotime( str_replace( 'T', ' ', $start ) ) );
                            }
                            if ( $event->post_status !== 'publish' ) {
                                $label .= ' (' . $event->post_status . ')';
                            }
                        ?>
                            <option value="<?php echo (int) $event->ID; ?>" <?php selected( (int) $form['event_id'], (int) $event->ID ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="ke-hint"><?php esc_html_e( 'The coupon only discounts tickets for this event, and only when the cart holds nothing else.', 'kiwi-events' ); ?></p>
                </div>

                <div class="ke-coupon-field">
                    <label for="ke-c-type"><?php esc_html_e( 'Discount type', 'kiwi-events' ); ?> <span class="ke-req">*</span></label>
                    <select id="ke-c-type" name="discount_type" class="ke-select" required>
                        <?php foreach ( KE_Coupons::TYPES as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $form['discount_type'], $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ke-coupon-field">
                    <label for="ke-c-amount"><?php esc_html_e( 'Amount', 'kiwi-events' ); ?> <span class="ke-req">*</span></label>
                    <input type="number" id="ke-c-amount" name="amount" class="ke-input" step="0.01" min="0.01" required
                           value="<?php echo esc_attr( $form['amount'] ); ?>">
                    <p class="ke-hint"><?php esc_html_e( 'A percentage (20 = 20%) or an amount in your store currency, depending on the type above.', 'kiwi-events' ); ?></p>
                </div>

                <div class="ke-coupon-field">
                    <label for="ke-c-max"><?php esc_html_e( 'Ticket limit', 'kiwi-events' ); ?></label>
                    <input type="number" id="ke-c-max" name="max_tickets" class="ke-input" min="0" step="1"
                           value="<?php echo esc_attr( (int) $form['max_tickets'] ); ?>">
                    <p class="ke-hint"><?php esc_html_e( 'How many tickets in total this coupon may discount, across every order. 0 means unlimited.', 'kiwi-events' ); ?></p>
                </div>

                <div class="ke-coupon-field">
                    <label for="ke-c-expires"><?php esc_html_e( 'Expires on', 'kiwi-events' ); ?></label>
                    <input type="date" id="ke-c-expires" name="expires" class="ke-input"
                           value="<?php echo esc_attr( $form['expires'] ); ?>">
                    <p class="ke-hint"><?php esc_html_e( 'The coupon works through the whole of this day. Leave empty for no expiry.', 'kiwi-events' ); ?></p>
                </div>

                <div class="ke-coupon-field">
                    <label for="ke-c-peruser"><?php esc_html_e( 'Limit per customer', 'kiwi-events' ); ?></label>
                    <input type="number" id="ke-c-peruser" name="limit_per_user" class="ke-input" min="0" step="1"
                           value="<?php echo esc_attr( (int) $form['limit_per_user'] ); ?>">
                    <p class="ke-hint"><?php esc_html_e( 'How many orders one customer may use it on. 0 means unlimited.', 'kiwi-events' ); ?></p>
                </div>

                <div class="ke-coupon-field">
                    <label for="ke-c-min"><?php esc_html_e( 'Minimum order amount', 'kiwi-events' ); ?></label>
                    <input type="number" id="ke-c-min" name="minimum_amount" class="ke-input" min="0" step="0.01"
                           value="<?php echo esc_attr( $form['minimum_amount'] ? $form['minimum_amount'] : '' ); ?>">
                    <p class="ke-hint"><?php esc_html_e( 'Leave empty for no minimum.', 'kiwi-events' ); ?></p>
                </div>

                <div class="ke-coupon-field ke-coupon-field--wide">
                    <label for="ke-c-desc"><?php esc_html_e( 'Internal note', 'kiwi-events' ); ?></label>
                    <textarea id="ke-c-desc" name="description" class="ke-textarea" rows="2"><?php echo esc_textarea( $form['description'] ); ?></textarea>
                    <p class="ke-hint"><?php esc_html_e( 'Only you see this. Handy for remembering who the code was for.', 'kiwi-events' ); ?></p>
                </div>

                <div class="ke-coupon-field ke-coupon-field--wide">
                    <label class="ke-coupon-toggle">
                        <input type="checkbox" name="active" value="1" <?php checked( ! empty( $form['active'] ) ); ?>>
                        <span><?php esc_html_e( 'Active', 'kiwi-events' ); ?></span>
                    </label>
                    <p class="ke-hint"><?php esc_html_e( 'Uncheck to pause the code without deleting it. A paused coupon cannot be applied.', 'kiwi-events' ); ?></p>
                </div>
            </div>

            <div class="ke-coupon-actions">
                <button type="submit" class="ke-btn ke-btn-primary"><?php echo $form['id'] ? esc_html__( 'Save changes', 'kiwi-events' ) : esc_html__( 'Create coupon', 'kiwi-events' ); ?></button>
                <a href="<?php echo esc_url( $list_url ); ?>" class="ke-btn ke-btn-ghost"><?php esc_html_e( 'Cancel', 'kiwi-events' ); ?></a>
            </div>
        </form>

    <?php else : ?>

        <?php if ( ! $coupon_box_on && ! empty( $coupons ) ) : ?>
            <div class="ke-section-card ke-section-card--compact ke-coupon-notice ke-coupon-notice--warn">
                <?php
                printf(
                    esc_html__( 'The coupon box is switched off at checkout, so these codes cannot be entered. Turn on "Enable the use of coupons" in %s.', 'kiwi-events' ),
                    '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings' ) ) . '">' . esc_html__( 'WooCommerce → Settings → General', 'kiwi-events' ) . '</a>'
                );
                ?>
            </div>
        <?php endif; ?>

        <div class="ke-section-card">
            <?php if ( empty( $coupons ) ) : ?>
                <div class="ke-empty-state">
                    <span class="ke-empty-state-icon">🏷️</span>
                    <h3><?php esc_html_e( 'No coupons yet', 'kiwi-events' ); ?></h3>
                    <p><?php esc_html_e( 'Create a code, point it at an event, and it works in the WooCommerce checkout.', 'kiwi-events' ); ?></p>
                    <p><a href="<?php echo esc_url( $new_url ); ?>" class="ke-btn ke-btn-primary">+ <?php esc_html_e( 'New coupon', 'kiwi-events' ); ?></a></p>
                </div>
            <?php else : ?>
                <div class="ke-coupon-table-wrap">
                    <table class="ke-table ke-coupon-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Code', 'kiwi-events' ); ?></th>
                                <th><?php esc_html_e( 'Event', 'kiwi-events' ); ?></th>
                                <th><?php esc_html_e( 'Discount', 'kiwi-events' ); ?></th>
                                <th class="ke-c-num"><?php esc_html_e( 'Tickets used', 'kiwi-events' ); ?></th>
                                <th><?php esc_html_e( 'Expires', 'kiwi-events' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'kiwi-events' ); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $coupons as $c ) :
                                $edit_url   = admin_url( 'admin.php?page=ke-coupons&action=edit&coupon=' . (int) $c['id'] );
                                $delete_url = wp_nonce_url(
                                    admin_url( 'admin-post.php?action=ke_coupon_delete&coupon=' . (int) $c['id'] ),
                                    'ke_coupon_delete_' . (int) $c['id']
                                );
                                if ( ! $c['active'] )        { $badge = 'draft';     $status = __( 'Paused', 'kiwi-events' ); }
                                elseif ( $c['expired'] )     { $badge = 'cancelled'; $status = __( 'Expired', 'kiwi-events' ); }
                                elseif ( $c['exhausted'] )   { $badge = 'cancelled'; $status = __( 'Used up', 'kiwi-events' ); }
                                else                         { $badge = 'active';    $status = __( 'Active', 'kiwi-events' ); }
                            ?>
                                <tr>
                                    <td><code class="ke-coupon-code"><?php echo esc_html( strtoupper( $c['code'] ) ); ?></code>
                                        <?php if ( $c['description'] !== '' ) : ?>
                                            <div class="ke-muted ke-coupon-note"><?php echo esc_html( $c['description'] ); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( $c['event_id'] ) : ?>
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ke-event-builder&event_id=' . (int) $c['event_id'] ) ); ?>">
                                                <?php echo esc_html( $c['event_title'] !== '' ? $c['event_title'] : '#' . $c['event_id'] ); ?>
                                            </a>
                                        <?php else : ?>
                                            <span class="ke-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $describe( $c['discount_type'], $c['amount'] ) ); ?></td>
                                    <td class="ke-c-num">
                                        <?php echo esc_html( number_format_i18n( $c['used_tickets'] ) ); ?><?php
                                        echo $c['max_tickets'] > 0
                                            ? ' / ' . esc_html( number_format_i18n( $c['max_tickets'] ) )
                                            : ' <span class="ke-muted">/ ∞</span>';
                                        ?>
                                    </td>
                                    <td><?php echo $c['expires'] !== '' ? esc_html( date_i18n( 'M j, Y', strtotime( $c['expires'] ) ) ) : '<span class="ke-muted">—</span>'; ?></td>
                                    <td><span class="ke-badge ke-badge-<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $status ); ?></span></td>
                                    <td class="ke-coupon-row-actions">
                                        <a class="ke-btn ke-btn-ghost ke-btn-small" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'kiwi-events' ); ?></a>
                                        <a class="ke-btn ke-btn-ghost ke-btn-small ke-coupon-delete" href="<?php echo esc_url( $delete_url ); ?>"
                                           data-code="<?php echo esc_attr( strtoupper( $c['code'] ) ); ?>"><?php esc_html_e( 'Delete', 'kiwi-events' ); ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>
<script>
(function () {
    // Deleting is one click from a table row, so confirm before following it.
    document.querySelectorAll('.ke-coupon-delete').forEach(function (a) {
        a.addEventListener('click', function (e) {
            if (!window.confirm('Delete the coupon ' + (a.dataset.code || '') + '? Customers will no longer be able to use it.')) {
                e.preventDefault();
            }
        });
    });
})();
</script>
