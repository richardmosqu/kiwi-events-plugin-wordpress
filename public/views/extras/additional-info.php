<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * "Additional Information" extra — understated info block.
 * Renders refundable status + disclaimers in a low-emphasis card below the
 * main event content. Bails out entirely if both fields are empty.
 *
 * Available vars: $extra, $extra_config, $event_id
 */

$refundable  = isset( $extra_config['refundable'] )  ? (string) $extra_config['refundable']  : '';
$disclaimers = isset( $extra_config['disclaimers'] ) ? (string) $extra_config['disclaimers'] : '';

$has_refundable  = in_array( $refundable, array( 'yes', 'no' ), true );
$has_disclaimers = ( trim( wp_strip_all_tags( $disclaimers ) ) !== '' );

if ( ! $has_refundable && ! $has_disclaimers ) {
    return;
}
?>
<div class="ke-content-section ke-extra ke-extra-addinfo"
     style="background:#fafafa;border:1px solid rgba(0,0,0,0.06);border-radius:12px;padding:18px 20px;margin-top:24px;">
    <div class="ke-addinfo-header"
         style="font-size:11px;font-weight:600;letter-spacing:0.12em;text-transform:uppercase;color:#999;margin-bottom:10px;">
        <?php echo esc_html__( 'Additional Information', 'kiwi-events' ); ?>
    </div>

    <?php if ( $has_refundable ) : ?>
        <div class="ke-addinfo-refundable"
             style="font-size:14px;color:#666;display:flex;align-items:center;gap:8px;<?php echo $has_disclaimers ? 'margin-bottom:10px;' : ''; ?>">
            <?php if ( $refundable === 'yes' ) : ?>
                <span aria-hidden="true" style="color:#16a34a;font-weight:700;">✓</span>
                <span><?php esc_html_e( 'This event is refundable', 'kiwi-events' ); ?></span>
            <?php else : ?>
                <span aria-hidden="true" style="color:#9ca3af;font-weight:700;">✗</span>
                <span><?php esc_html_e( 'This event is non-refundable', 'kiwi-events' ); ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ( $has_disclaimers ) : ?>
        <div class="ke-addinfo-disclaimers"
             style="font-size:14px;color:#888;line-height:1.55;">
            <?php echo wp_kses_post( wpautop( $disclaimers ) ); ?>
        </div>
    <?php endif; ?>
</div>
