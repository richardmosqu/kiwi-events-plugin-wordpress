<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
// Standalone checkout form — used via [kiwi_checkout event_id="X"]
$post = $event_id ? get_post( $event_id ) : null;
if ( ! $post || $post->post_type !== 'ke_event' ) {
    echo '<p class="ke-error">Please specify a valid event ID: [kiwi_checkout event_id="123"]</p>';
    return;
}

$ticket_types = new KE_Ticket_Types();
$types = $ticket_types->get_available( $event_id );
$event_title = $post->post_title;
?>

<div class="ke-checkout-standalone">
    <h2 style="color: var(--ke-text-primary); margin-bottom: 8px;">🎟️ Tickets for <?php echo esc_html( $event_title ); ?></h2>

    <?php if ( empty( $types ) ) : ?>
        <div class="ke-empty">
            <span class="ke-empty-icon">🎟️</span>
            <p>No tickets available at this time.</p>
        </div>
    <?php else : ?>
        <?php foreach ( $types as $type ) :
            $remaining = max( 0, $type->quantity_total - $type->quantity_sold );
            $is_sold_out = $remaining <= 0;
        ?>
            <div class="ke-ticket-type-card"
                 data-ticket-type-id="<?php echo esc_attr( $type->id ); ?>"
                 data-price="<?php echo esc_attr( $type->price ); ?>"
                 data-remaining="<?php echo esc_attr( $remaining ); ?>">
                <div class="ke-ticket-type-info">
                    <div class="ke-ticket-type-name"><?php echo esc_html( $type->name ); ?></div>
                    <?php if ( $is_sold_out ) : ?>
                        <span class="ke-sold-out">Sold Out</span>
                    <?php endif; ?>
                </div>
                <div class="ke-ticket-type-price">
                    <?php echo $type->price > 0 ? '$' . number_format( $type->price, 2 ) : 'Free'; ?>
                </div>
                <?php if ( ! $is_sold_out ) : ?>
                    <div class="ke-ticket-type-qty">
                        <button type="button" class="ke-qty-btn" data-action="minus">−</button>
                        <span class="ke-qty-value">0</span>
                        <button type="button" class="ke-qty-btn" data-action="plus">+</button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <form id="ke-checkout-form" class="ke-checkout-form" data-event-id="<?php echo esc_attr( $event_id ); ?>" style="max-width:100%;">
            <div class="ke-form-message" style="display:none;"></div>
            <div class="ke-form-field">
                <label for="ke-name">Full Name</label>
                <input type="text" id="ke-name" name="name" placeholder="John Doe" required>
            </div>
            <div class="ke-form-field">
                <label for="ke-email">Email Address</label>
                <input type="email" id="ke-email" name="email" placeholder="john@example.com" required>
            </div>
            <input type="hidden" id="ke-total-qty" value="0">
            <input type="hidden" id="ke-total-price" value="0.00">
            <div class="ke-checkout-summary">
                <span class="ke-checkout-summary-label">Total</span>
                <span class="ke-checkout-summary-value">$0.00</span>
            </div>
            <button type="submit" id="ke-checkout-submit" class="ke-btn ke-btn-lg" style="width:100%; justify-content:center;" disabled>
                🎟️ Get Tickets
            </button>
        </form>
    <?php endif; ?>
</div>
